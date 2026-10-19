<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Auth
{
    public const STUDENT_COMPLIANCE_PERMISSIONS = ['view_compliance', 'manage_compliance', 'manage_reports'];
    public const COMPLIANCE_PERMISSIONS = [...self::STUDENT_COMPLIANCE_PERMISSIONS, 'view_audit_logs'];

    private static ?array $user = null;
    private static ?array $actualUser = null;
    private static ?array $permissions = null;

    public static function attempt(string $identifier, string $password): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT users.*, roles.name AS role_name
             FROM users
             JOIN roles ON roles.id = users.role_id
             WHERE (users.school_id = :school_id OR users.email = :email)
               AND users.status = "active"
             LIMIT 1'
        );
        $statement->execute(['school_id' => $identifier, 'email' => $identifier]);
        $user = $statement->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        $user = self::attachRoles($user);
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        AuditContext::configure(Database::connection(), (int) $user['id']);
        self::$user = $user;
        self::$actualUser = $user;
        self::$permissions = null;
        return true;
    }

    public static function user(): ?array
    {
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        if (self::$user !== null) {
            return self::$user;
        }

        $user = self::actualUser();
        if (!$user) return self::$user = null;
        $preview = self::previewRole();
        if ($preview) {
            $user['role_id'] = $preview['id'];
            $user['role_name'] = $preview['name'];
            $user['roles'] = [$preview];
            $user['role_ids'] = [$preview['id']];
            $user['role_names'] = [$preview['name']];
            $user['role_names_label'] = $preview['name'] . ' preview';
        }
        return self::$user = $user;
    }

    private static function actualUser(): ?array
    {
        if (!isset($_SESSION['user_id'])) return null;
        if (self::$actualUser !== null) return self::$actualUser;
        $statement = Database::connection()->prepare(
            'SELECT users.*, roles.name AS role_name,
                    departments.name AS department_name, programs.name AS program_name,
                    sections.name AS section_name
             FROM users
             JOIN roles ON roles.id = users.role_id
             LEFT JOIN departments ON departments.id = users.department_id
             LEFT JOIN programs ON programs.id = users.program_id
             LEFT JOIN sections ON sections.id = users.section_id
             WHERE users.id = :id AND users.status = "active" LIMIT 1'
        );
        $statement->execute(['id' => $_SESSION['user_id']]);
        $user = $statement->fetch() ?: null;
        return self::$actualUser = $user ? self::attachRoles($user) : null;
    }

    public static function permissions(): array
    {
        if (self::$permissions !== null) {
            return self::$permissions;
        }
        $user = self::user();
        if (!$user) {
            return self::$permissions = [];
        }

        $statement = Database::connection()->prepare(
            'SELECT DISTINCT permissions.code
             FROM role_permissions
             JOIN permissions ON permissions.id = role_permissions.permission_id
             WHERE role_permissions.role_id IN (' . implode(',', array_fill(0, count($user['role_ids']), '?')) . ')'
        );
        $statement->execute($user['role_ids']);
        return self::$permissions = $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function can(string $permission): bool
    {
        return in_array($permission, self::permissions(), true);
    }

    /** Background delivery uses the recipient's roles; interactive delivery honors View As. */
    public static function permissionsForUser(int $userId): array
    {
        if ((int)(self::user()['id'] ?? 0) === $userId) return self::permissions();
        $query = Database::connection()->prepare(
            'SELECT DISTINCT p.code FROM permissions p JOIN role_permissions rp ON rp.permission_id=p.id
             JOIN users u ON u.id=? AND u.status="active"
             WHERE rp.role_id=u.role_id OR EXISTS(SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id AND ur.role_id=rp.role_id)'
        );
        $query->execute([$userId]);
        return $query->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function canAny(array $permissions): bool
    {
        return array_intersect(self::permissions(), $permissions) !== [];
    }

    public static function hasRole(string $role): bool
    {
        $user = self::user();
        return $user !== null && in_array($role, $user['role_names'] ?? [$user['role_name']], true);
    }

    public static function hasAnyRole(array $roles): bool
    {
        $user = self::user();
        return $user !== null && array_intersect($roles, $user['role_names'] ?? [$user['role_name']]) !== [];
    }

    public static function usesAdminArea(): bool
    {
        // Academic roles use the portal. Explicit additional administrative roles
        // remain additive, and View As evaluates only the selected role.
        $preview = self::previewRole();
        if ($preview) {
            if (in_array($preview['name'], ['Administrator', 'Staff', 'Event Coordinator'], true)) return true;
            // A preview has only this role, matching the non-preview role exclusion below.
            if (in_array($preview['name'], ['Teacher', 'Student'], true)) return false;
            $adminPermissions = [
                'manage_users', 'manage_roles', 'manage_rfid', 'manage_academic_structure',
                'manage_teacher_assignments', 'manage_rooms', 'manage_events', 'manage_recollections',
                'view_compliance', 'manage_compliance', 'manage_grades', 'manage_attendance', 'manage_reports',
                'manage_attendance_policies', 'manage_academic_calendar', 'manage_grading_settings', 'view_audit_logs',
            ];
            return array_intersect(self::permissions(), $adminPermissions) !== [];
        }
        $user = self::actualUser();
        if (!$user) return false;
        if (array_intersect($user['role_names'] ?? [], ['Administrator', 'Staff', 'Event Coordinator']) !== []) return true;

        $adminPermissions = [
            'manage_users', 'manage_roles', 'manage_rfid', 'manage_academic_structure',
            'manage_teacher_assignments', 'manage_rooms', 'manage_events',
            'manage_recollections', 'view_compliance', 'manage_compliance', 'manage_grades', 'manage_attendance', 'manage_reports',
            'manage_attendance_policies', 'manage_academic_calendar', 'manage_grading_settings', 'view_audit_logs',
        ];
        $placeholders = implode(',', array_fill(0, count($adminPermissions), '?'));
        $rolePlaceholders = implode(',', array_fill(0, count($user['role_ids']), '?'));
        $statement = Database::connection()->prepare(
            "SELECT 1 FROM roles
             JOIN role_permissions ON role_permissions.role_id = roles.id
             JOIN permissions ON permissions.id = role_permissions.permission_id
             WHERE roles.id IN ({$rolePlaceholders})
               AND roles.name NOT IN ('Teacher', 'Student')
               AND permissions.code IN ({$placeholders}) LIMIT 1"
        );
        $statement->execute([...$user['role_ids'], ...$adminPermissions]);
        return (bool) $statement->fetchColumn();
    }

    public static function previewRole(): ?array
    {
        $roleId = (int) ($_SESSION['admin_preview_role_id'] ?? 0);
        $actual = self::actualUser();
        if ($roleId < 1 || !$actual || !in_array('Administrator', $actual['role_names'] ?? [], true)) return null;
        $statement = Database::connection()->prepare('SELECT id, name FROM roles WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $roleId]);
        $role = $statement->fetch(PDO::FETCH_ASSOC);
        return $role ? ['id' => (int) $role['id'], 'name' => (string) $role['name']] : null;
    }

    public static function viewableRoles(): array
    {
        $actual = self::actualUser();
        if (!$actual || !in_array('Administrator', $actual['role_names'] ?? [], true)) return [];
        return Database::connection()->query('SELECT id, name, description FROM roles ORDER BY id = 1 DESC, name')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function setPreviewRole(?int $roleId): void
    {
        $actual = self::actualUser();
        if (!$actual || !in_array('Administrator', $actual['role_names'] ?? [], true)) throw new \DomainException('Only administrators can preview another role.');
        if ($roleId === null || $roleId === 1) unset($_SESSION['admin_preview_role_id']);
        else {
            $statement = Database::connection()->prepare('SELECT 1 FROM roles WHERE id = :id');
            $statement->execute(['id' => $roleId]);
            if (!$statement->fetchColumn()) throw new \DomainException('That role is no longer available.');
            $_SESSION['admin_preview_role_id'] = $roleId;
        }
        self::$user = null;
        self::$permissions = null;
    }

    private static function attachRoles(array $user): array
    {
        $statement = Database::connection()->prepare(
            'SELECT roles.id, roles.name
             FROM user_roles
             JOIN roles ON roles.id = user_roles.role_id
             WHERE user_roles.user_id = :user_id
             ORDER BY (roles.id = :primary_role_id) DESC, roles.name'
        );
        $statement->execute(['user_id' => $user['id'], 'primary_role_id' => $user['role_id']]);
        $roles = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!in_array((int)$user['role_id'], array_map('intval', array_column($roles, 'id')), true)) {
            array_unshift($roles, ['id' => (int)$user['role_id'], 'name' => $user['role_name']]);
        }
        $user['roles'] = $roles;
        $user['role_ids'] = array_map(static fn(array $role): int => (int) $role['id'], $roles);
        $user['role_names'] = array_column($roles, 'name');
        $user['role_names_label'] = implode(', ', $user['role_names']);
        return $user;
    }

    public static function requireLogin(): array
    {
        $user = self::user();
        if (!$user) {
            flash('warning', 'Please sign in to continue.');
            redirect('/auth/login.php');
        }
        return $user;
    }

    public static function requirePermission(string $permission): void
    {
        self::requireLogin();
        if (!self::can($permission)) {
            http_response_code(403);
            require BASE_PATH . '/templates/errors/403.php';
            exit;
        }
    }

    public static function requireAnyPermission(array $permissions): void
    {
        self::requireLogin();
        if (!self::canAny($permissions)) {
            http_response_code(403);
            require BASE_PATH . '/templates/errors/403.php';
            exit;
        }
    }

    public static function logout(): void
    {
        try { \App\Services\NotificationService::revokeSession(); } catch (\Throwable) { error_log('Could not revoke push session'); }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        self::$user = null;
        self::$actualUser = null;
        self::$permissions = null;
    }
}
