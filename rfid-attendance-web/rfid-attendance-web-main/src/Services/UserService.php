<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DomainException;
use PDO;
use PDOException;
use Throwable;

final class UserService
{
    public static function findForManagement(int $userId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT users.id, users.school_id, users.first_name, users.middle_name, users.last_name,
                    users.email, users.role_id, users.department_id, users.program_id, users.section_id,
                    users.profile_image_path,
                    users.status, roles.name AS role_name, departments.name AS department_name,
                    programs.name AS program_name, sections.name AS section_name, rfid_cards.uid AS rfid_uid,
                    (SELECT GROUP_CONCAT(user_roles.role_id ORDER BY (user_roles.role_id = users.role_id) DESC, assigned_roles.name SEPARATOR ",")
                     FROM user_roles JOIN roles assigned_roles ON assigned_roles.id = user_roles.role_id
                     WHERE user_roles.user_id = users.id) AS role_ids_csv,
                    (SELECT GROUP_CONCAT(assigned_roles.name ORDER BY (user_roles.role_id = users.role_id) DESC, assigned_roles.name SEPARATOR "||")
                     FROM user_roles JOIN roles assigned_roles ON assigned_roles.id = user_roles.role_id
                     WHERE user_roles.user_id = users.id) AS role_names_csv
             FROM users
             JOIN roles ON roles.id = users.role_id
             LEFT JOIN departments ON departments.id = users.department_id
             LEFT JOIN programs ON programs.id = users.program_id
             LEFT JOIN sections ON sections.id = users.section_id
             LEFT JOIN rfid_cards ON rfid_cards.user_id = users.id AND rfid_cards.status = "active"
             WHERE users.id = :id
             LIMIT 1'
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch(PDO::FETCH_ASSOC) ?: null;
        return $user ? self::expandRoleColumns($user) : null;
    }

    public static function searchForSelection(string $query, int $limit = 20): array
    {
        $query = mb_substr(trim($query), 0, 100);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min($limit, 50));
        $prefix = $query . '%';
        $statement = Database::connection()->prepare(
            'SELECT users.id, users.school_id, users.first_name, users.last_name, users.email,
                    users.profile_image_path, roles.name AS role_name,
                    departments.name AS department_name, rfid_cards.uid AS rfid_uid,
                    (SELECT GROUP_CONCAT(assigned_roles.name ORDER BY (user_roles.role_id = users.role_id) DESC, assigned_roles.name SEPARATOR "||")
                     FROM user_roles JOIN roles assigned_roles ON assigned_roles.id = user_roles.role_id
                     WHERE user_roles.user_id = users.id) AS role_names_csv
             FROM users
             JOIN roles ON roles.id = users.role_id
             LEFT JOIN departments ON departments.id = users.department_id
             LEFT JOIN rfid_cards ON rfid_cards.user_id = users.id AND rfid_cards.status = "active"
             WHERE users.status = "active"
               AND (users.school_id LIKE :school_id OR users.email LIKE :email
                    OR users.first_name LIKE :first_name OR users.last_name LIKE :last_name
                    OR CONCAT_WS(" ", users.first_name, users.middle_name, users.last_name) LIKE :full_name)
             ORDER BY
                CASE WHEN users.school_id = :exact_school_id OR users.email = :exact_email THEN 0 ELSE 1 END,
                users.last_name, users.first_name
             LIMIT ' . $limit
        );
        $statement->execute([
            'school_id' => $prefix,
            'email' => $prefix,
            'first_name' => $prefix,
            'last_name' => $prefix,
            'full_name' => $prefix,
            'exact_school_id' => $query,
            'exact_email' => $query,
        ]);
        return array_map([self::class, 'expandRoleColumns'], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function create(array $data, int $actorId, ?array $profileImage = null): int
    {
        if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Enter a valid email address.');
        }
        if (strlen((string) ($data['password'] ?? '')) < 8) {
            throw new DomainException('Passwords must contain at least 8 characters.');
        }

        $roleId = (int) ($data['role_id'] ?? 0);
        $roleIds = self::roleIdsFromData($data);
        $pdo = Database::connection();
        self::assertRolesExist($pdo, $roleIds);
        $isAdministrator = in_array(1, $roleIds, true);
        if ($isAdministrator && $profileImage && (int) ($profileImage['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            throw new DomainException('Administrator accounts do not use profile pictures.');
        }
        $profileImagePath = $isAdministrator ? null : UploadService::storeImage($profileImage, 'profile');
        $profileImagePath ??= $isAdministrator ? null : '/assets/images/default-avatar.svg';

        $statement = $pdo->prepare(
            'INSERT INTO users
                (school_id, first_name, middle_name, last_name, email, password_hash, role_id, department_id, program_id, section_id, profile_image_path, status)
             VALUES (:school_id, :first_name, :middle_name, :last_name, :email, :password_hash, :role_id, :department_id, :program_id, :section_id, :profile_image_path, "active")'
        );
        $pdo->beginTransaction();
        try {
            $statement->execute([
                'school_id' => trim($data['school_id']), 'first_name' => trim($data['first_name']),
                'middle_name' => trim($data['middle_name'] ?? '') ?: null, 'last_name' => trim($data['last_name']),
                'email' => strtolower(trim($data['email'])), 'password_hash' => password_hash($data['password'], PASSWORD_DEFAULT),
                'role_id' => $roleId, 'department_id' => $data['department_id'] ?: null,
                'program_id' => $data['program_id'] ?: null, 'section_id' => $data['section_id'] ?: null,
                'profile_image_path' => $profileImagePath,
            ]);
            $id = (int) $pdo->lastInsertId();
            self::syncRoles($pdo, $id, $roleIds);
            AuditService::log($actorId, 'create_user', 'user', $id, json_encode([
                'school_id' => $data['school_id'],
                'role_ids' => $roleIds,
            ], JSON_THROW_ON_ERROR));
            $pdo->commit();
            return $id;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            UploadService::removeManaged($profileImagePath);
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new DomainException('The school ID or email is already in use.');
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            UploadService::removeManaged($profileImagePath);
            throw $exception;
        }
    }

    public static function updateProfileImage(int $userId, ?array $profileImage, int $actorId): array
    {
        if ($userId < 1) {
            throw new DomainException('Select a valid user.');
        }
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'SELECT users.profile_image_path,
                    EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id = 1) AS is_administrator
             FROM users WHERE users.id = :id'
        );
        $statement->execute(['id' => $userId]);
        $current = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$current) {
            throw new DomainException('The selected user could not be found.');
        }
        if ((bool) $current['is_administrator']) {
            throw new DomainException('Administrator accounts do not use profile pictures.');
        }

        $newPath = UploadService::storeImage($profileImage, 'profile');
        if ($newPath === null) {
            throw new DomainException('Select a JPG, PNG, or WebP profile picture.');
        }
        try {
            $pdo->prepare('UPDATE users SET profile_image_path = :path WHERE id = :id')
                ->execute(['path' => $newPath, 'id' => $userId]);
            AuditService::log($actorId, 'update_profile_image', 'user', $userId);
        } catch (Throwable $exception) {
            UploadService::removeManaged($newPath);
            throw $exception;
        }
        UploadService::removeManaged($current['profile_image_path'] ?? null);

        return self::findForManagement($userId) ?? throw new DomainException('Unable to reload the updated user.');
    }

    public static function update(array $data, int $actorId): array
    {
        $userId = (int) ($data['user_id'] ?? 0);
        $schoolId = trim((string) ($data['school_id'] ?? ''));
        $firstName = trim((string) ($data['first_name'] ?? ''));
        $middleName = trim((string) ($data['middle_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $roleId = (int) ($data['role_id'] ?? 0);
        $roleIds = self::roleIdsFromData($data);
        $departmentId = (int) ($data['department_id'] ?? 0) ?: null;
        $programId = (int) ($data['program_id'] ?? 0) ?: null;
        $sectionId = (int) ($data['section_id'] ?? 0) ?: null;
        $status = (string) ($data['status'] ?? '');

        if ($userId < 1 || $schoolId === '' || $firstName === '' || $lastName === '' || $email === '' || $roleId < 1) {
            throw new DomainException('Complete all required account fields.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('Enter a valid email address.');
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            throw new DomainException('Select a valid account status.');
        }
        foreach ([
            'School ID' => [$schoolId, 40],
            'First name' => [$firstName, 80],
            'Middle name' => [$middleName, 80],
            'Last name' => [$lastName, 80],
            'Email' => [$email, 190],
        ] as $label => [$value, $maximum]) {
            if (mb_strlen($value) > $maximum) {
                throw new DomainException("{$label} is too long.");
            }
        }

        $pdo = Database::connection();
        $currentStatement = $pdo->prepare('SELECT id, role_id, status, profile_image_path FROM users WHERE id = :id');
        $currentStatement->execute(['id' => $userId]);
        $current = $currentStatement->fetch();
        if (!$current) {
            throw new DomainException('The selected user no longer exists.');
        }
        $currentRoleIds = self::userRoleIds($pdo, $userId);
        if ($userId === $actorId && ($status !== 'active' || $roleIds !== $currentRoleIds || $roleId !== (int) $current['role_id'])) {
            throw new DomainException('You cannot deactivate your own account or change your own roles.');
        }

        self::assertRolesExist($pdo, $roleIds);
        if ($departmentId !== null) {
            self::assertReferenceExists($pdo, 'departments', $departmentId, 'Select a valid department.');
        }
        if ($programId !== null) {
            $statement = $pdo->prepare('SELECT department_id FROM programs WHERE id = :id');
            $statement->execute(['id' => $programId]);
            $programDepartmentId = $statement->fetchColumn();
            if ($programDepartmentId === false || $departmentId === null || (int) $programDepartmentId !== $departmentId) {
                throw new DomainException('The selected program does not belong to the selected department.');
            }
        }
        if ($sectionId !== null) {
            $statement = $pdo->prepare('SELECT program_id FROM sections WHERE id = :id');
            $statement->execute(['id' => $sectionId]);
            $sectionProgramId = $statement->fetchColumn();
            if ($sectionProgramId === false || $programId === null || (int) $sectionProgramId !== $programId) {
                throw new DomainException('The selected section does not belong to the selected program.');
            }
        }

        $wasAdministrator = in_array(1, $currentRoleIds, true);
        $isAdministrator = in_array(1, $roleIds, true);
        if ($wasAdministrator && $current['status'] === 'active' && (!$isAdministrator || $status !== 'active')) {
            $activeAdministrators = (int) $pdo->query(
                'SELECT COUNT(DISTINCT users.id) FROM users
                 JOIN user_roles ON user_roles.user_id = users.id AND user_roles.role_id = 1
                 WHERE users.status = "active"'
            )->fetchColumn();
            if ($activeAdministrators <= 1) {
                throw new DomainException('At least one active administrator account must remain.');
            }
        }

        $ownsTransaction = !$pdo->inTransaction();
        $profileImagePath = $isAdministrator
            ? null
            : ($current['profile_image_path'] ?: '/assets/images/default-avatar.svg');
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $statement = $pdo->prepare(
                'UPDATE users
                 SET school_id = :school_id, first_name = :first_name, middle_name = :middle_name,
                     last_name = :last_name, email = :email, role_id = :role_id,
                     department_id = :department_id, program_id = :program_id,
                     section_id = :section_id, profile_image_path = :profile_image_path, status = :status
                 WHERE id = :id'
            );
            $statement->execute([
                'school_id' => $schoolId,
                'first_name' => $firstName,
                'middle_name' => $middleName !== '' ? $middleName : null,
                'last_name' => $lastName,
                'email' => $email,
                'role_id' => $roleId,
                'department_id' => $departmentId,
                'program_id' => $programId,
                'section_id' => $sectionId,
                'profile_image_path' => $profileImagePath,
                'status' => $status,
                'id' => $userId,
            ]);
            self::syncRoles($pdo, $userId, $roleIds);
            AuditService::log($actorId, 'update_user', 'user', $userId, json_encode([
                'primary_role_id' => $roleId,
                'role_ids' => $roleIds,
                'department_id' => $departmentId,
                'program_id' => $programId,
                'section_id' => $sectionId,
                'status' => $status,
            ], JSON_THROW_ON_ERROR));
            if ($ownsTransaction) {
                $pdo->commit();
            }
            if ($isAdministrator) {
                UploadService::removeManaged($current['profile_image_path'] ?? null);
            }
        } catch (PDOException $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new DomainException('The school ID or email is already in use.');
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return self::findForManagement($userId) ?? throw new DomainException('Unable to reload the updated user.');
    }

    public static function resetPassword(int $userId, string $newPassword, int $actorId): void
    {
        if ($userId < 1) {
            throw new DomainException('Select a valid user.');
        }
        if (strlen($newPassword) < 8) {
            throw new DomainException('The new password must contain at least 8 characters.');
        }
        if (strlen($newPassword) > 255) {
            throw new DomainException('The new password is too long.');
        }

        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT id FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);
        if (!$statement->fetchColumn()) {
            throw new DomainException('The selected user no longer exists.');
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')
                ->execute(['hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $userId]);
            AuditService::log($actorId, 'reset_password', 'user', $userId);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function delete(int $userId, int $actorId): array
    {
        if ($userId < 1 || $userId === $actorId) {
            throw new DomainException($userId === $actorId ? 'You cannot delete your own signed-in account.' : 'Select a valid user.');
        }
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT id, school_id, first_name, last_name, profile_image_path, role_id FROM users WHERE id = :id');
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();
        if (!$user) throw new DomainException('The selected user could not be found.');
        $administrator = $pdo->prepare('SELECT 1 FROM user_roles WHERE user_id = :id AND role_id = 1');
        $administrator->execute(['id' => $userId]);
        if ((int) $user['role_id'] === 1 || $administrator->fetchColumn()) {
            throw new DomainException('Administrator accounts cannot be permanently deleted. Deactivate the account if it should no longer be used.');
        }

        $checks = [
            ['class_enrollments', 'student_id', 'class enrollment'],
            ['teacher_assignments', 'teacher_id', 'teaching assignment'],
            ['attendance_records', 'student_id', 'attendance record'],
            ['event_attendance', 'user_id', 'event attendance record'],
            ['recollection_attendance', 'user_id', 'activity attendance record'],
            ['grades', 'student_id', 'grade record'],
            ['grades', 'entered_by', 'entered grade record'],
            ['events', 'created_by', 'created event'],
            ['recollections', 'created_by', 'created activity'],
            ['messages', 'sender_id', 'sent message'],
            ['rfid_assignment_history', 'user_id', 'RFID history record'],
            ['rfid_assignment_history', 'performed_by', 'RFID audit action'],
            ['seat_plan_presets', 'created_by', 'seat-plan preset'],
            ['room_seat_plans', 'updated_by', 'room seat-plan update'],
        ];
        $blocking = [];
        foreach ($checks as [$table, $column, $label]) {
            $countStatement = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = :id");
            $countStatement->execute(['id' => $userId]);
            $count = (int) $countStatement->fetchColumn();
            if ($count > 0) $blocking[] = "{$count} {$label}" . ($count === 1 ? '' : 's');
        }
        if ($blocking) {
            throw new DomainException('This account has protected institutional history (' . implode(', ', $blocking) . '). Deactivate it instead of deleting it.');
        }

        $label = trim($user['first_name'] . ' ' . $user['last_name']) . ' (' . $user['school_id'] . ')';
        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
        AuditService::log($actorId, 'delete_user', 'user', $userId, $label);
        UploadService::removeManaged($user['profile_image_path'] ?? null);
        return ['id' => $userId, 'label' => $label];
    }

    private static function roleIdsFromData(array $data): array
    {
        $primaryRoleId = (int) ($data['role_id'] ?? 0);
        if ($primaryRoleId < 1) {
            throw new DomainException('Select a valid primary role.');
        }
        $submitted = $data['role_ids'] ?? [];
        if (!is_array($submitted)) $submitted = [$submitted];
        $roleIds = array_values(array_unique(array_filter(
            array_map('intval', [$primaryRoleId, ...$submitted]),
            static fn(int $roleId): bool => $roleId > 0
        )));
        $roleIds = array_values(array_diff($roleIds, [$primaryRoleId]));
        sort($roleIds, SORT_NUMERIC);
        array_unshift($roleIds, $primaryRoleId);
        return $roleIds;
    }

    private static function assertRolesExist(PDO $pdo, array $roleIds): void
    {
        if ($roleIds === []) throw new DomainException('Assign at least one role.');
        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        $statement = $pdo->prepare("SELECT COUNT(*) FROM roles WHERE id IN ({$placeholders})");
        $statement->execute($roleIds);
        if ((int) $statement->fetchColumn() !== count($roleIds)) {
            throw new DomainException('One or more selected roles no longer exist.');
        }
    }

    private static function userRoleIds(PDO $pdo, int $userId): array
    {
        $statement = $pdo->prepare(
            'SELECT user_roles.role_id FROM user_roles
             JOIN users ON users.id = user_roles.user_id
             WHERE user_roles.user_id = :user_id
             ORDER BY (user_roles.role_id = users.role_id) DESC, user_roles.role_id'
        );
        $statement->execute(['user_id' => $userId]);
        return array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function syncRoles(PDO $pdo, int $userId, array $roleIds): void
    {
        $pdo->prepare('DELETE FROM user_roles WHERE user_id = :user_id')->execute(['user_id' => $userId]);
        $statement = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)');
        foreach ($roleIds as $roleId) {
            $statement->execute(['user_id' => $userId, 'role_id' => $roleId]);
        }
    }

    private static function expandRoleColumns(array $user): array
    {
        if (array_key_exists('role_ids_csv', $user)) {
            $user['role_ids'] = $user['role_ids_csv'] !== null && $user['role_ids_csv'] !== ''
                ? array_map('intval', explode(',', $user['role_ids_csv']))
                : [(int) $user['role_id']];
            unset($user['role_ids_csv']);
        }
        $user['role_names'] = isset($user['role_names_csv']) && $user['role_names_csv'] !== ''
            ? explode('||', $user['role_names_csv'])
            : [$user['role_name']];
        $user['role_names_label'] = implode(', ', $user['role_names']);
        unset($user['role_names_csv']);
        return $user;
    }

    private static function assertReferenceExists(PDO $pdo, string $table, int $id, string $message): void
    {
        $allowedTables = ['roles', 'departments'];
        if (!in_array($table, $allowedTables, true)) {
            throw new DomainException('Invalid account reference.');
        }
        $statement = $pdo->prepare("SELECT id FROM {$table} WHERE id = :id");
        $statement->execute(['id' => $id]);
        if (!$statement->fetchColumn()) {
            throw new DomainException($message);
        }
    }
}
