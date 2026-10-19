<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use DateTimeImmutable;
use DomainException;
use Throwable;

final class RecollectionService
{
    private const ATTENDANCE_STATUSES = ['Attended', 'Late', 'Absent', 'Excused'];

    public static function findForManagement(int $recollectionId): ?array
    {
        if ($recollectionId < 1) return null;
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT * FROM recollections WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $recollectionId]);
        $record = $statement->fetch();
        if (!$record) return null;
        $roles = $pdo->prepare('SELECT role_id FROM recollection_target_roles WHERE recollection_id = :id ORDER BY role_id');
        $roles->execute(['id' => $recollectionId]);
        $users = $pdo->prepare(
            'SELECT users.id, users.school_id identifier, CONCAT_WS(" ", users.first_name, users.middle_name, users.last_name) label
             FROM recollection_target_users JOIN users ON users.id = recollection_target_users.user_id
             WHERE recollection_target_users.recollection_id = :id ORDER BY users.last_name, users.first_name'
        );
        $users->execute(['id' => $recollectionId]);
        $record['target_role_ids'] = array_map('intval', $roles->fetchAll(\PDO::FETCH_COLUMN));
        $record['target_users'] = $users->fetchAll();
        return $record;
    }

    public static function create(array $data, int $actorId): int
    {
        if (!in_array($data['type'] ?? '', ['Recollection', 'Retreat'], true)) throw new DomainException('Invalid activity type.');
        $schedule = self::schedule($data);
        $startTime = $schedule['start_time'];
        $endTime = $schedule['end_time'];
        $title = normalize_title_case($data['title'] ?? '');
        $venue = normalize_title_case($data['venue'] ?? '');
        $facilitator = normalize_person_name($data['facilitator'] ?? '');
        if ($title === '' || $venue === '' || (int)($data['section_id'] ?? 0) < 1) throw new DomainException('Enter a title and venue, then select a section.');
        if (mb_strlen($title) > 180 || mb_strlen($venue) > 180 || mb_strlen($facilitator) > 180) throw new DomainException('One or more activity details are too long.');
        $pdo = Database::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'INSERT INTO recollections
                    (title, type, section_id, date, end_date, start_time, end_time, venue, facilitator, attendance_required, status, created_by)
                 VALUES (:title, :type, :section_id, :date, :end_date, :start_time, :end_time, :venue, :facilitator, :required, "scheduled", :created_by)'
            );
            $statement->execute([
                'title' => $title, 'type' => $data['type'], 'section_id' => (int) $data['section_id'],
                'date' => $schedule['date'], 'end_date' => $schedule['end_date'], 'start_time' => $startTime, 'end_time' => $endTime,
                'venue' => $venue, 'facilitator' => $facilitator,
                'required' => isset($data['attendance_required']) ? 1 : 0, 'created_by' => $actorId,
            ]);
            $id = (int) $pdo->lastInsertId();
            self::replaceTargets($id, $data);
            self::syncParticipants($id);
            AuditService::log($actorId, 'create_recollection', 'recollection', $id, $data['title']);
            NotificationService::afterActivity('recollection', $id);
            if ($ownsTransaction) $pdo->commit();
            return $id;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    public static function update(int $recollectionId, array $data, int $actorId): void
    {
        if ($recollectionId < 1) throw new DomainException('Select a valid recollection or retreat.');
        if (!in_array($data['type'] ?? '', ['Recollection', 'Retreat'], true)) throw new DomainException('Invalid activity type.');

        // Older clients submit only date; do not collapse an existing multi-day activity.
        if (!array_key_exists('end_date', $data)) {
            $previous = Database::connection()->prepare('SELECT date,end_date FROM recollections WHERE id=?');
            $previous->execute([$recollectionId]);
            $previous = $previous->fetch();
            if ($previous && $previous['end_date'] && $previous['end_date'] !== $previous['date']) $data['end_date'] = $previous['end_date'];
        }
        $title = normalize_title_case($data['title'] ?? '');
        $schedule = self::schedule($data);
        $date = $schedule['date'];
        $venue = normalize_title_case($data['venue'] ?? '');
        $facilitator = normalize_person_name($data['facilitator'] ?? '');
        $sectionId = (int) ($data['section_id'] ?? 0);
        $startTime = $schedule['start_time'];
        $endTime = $schedule['end_time'];
        if ($title === '' || $venue === '' || $sectionId < 1) {
            throw new DomainException('Enter a title and venue, then select a section.');
        }
        if (mb_strlen($title) > 180 || mb_strlen($venue) > 180 || mb_strlen($facilitator) > 180) {
            throw new DomainException('One or more activity details are too long.');
        }
        $pdo = Database::connection();
        $currentStatement = $pdo->prepare('SELECT status FROM recollections WHERE id = :id');
        $currentStatement->execute(['id' => $recollectionId]);
        $status = $currentStatement->fetchColumn();
        if ($status === false) throw new DomainException('The recollection or retreat could not be found.');
        if (!in_array($status, ['draft', 'scheduled'], true)) {
            throw new DomainException('Only draft or scheduled activities can be edited.');
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE recollections SET title = :title, type = :type, section_id = :section_id, date = :date, end_date = :end_date,
                    start_time = :start_time, end_time = :end_time, venue = :venue, facilitator = :facilitator,
                    attendance_required = :required WHERE id = :id'
            )->execute([
                'title' => $title,
                'type' => $data['type'],
                'section_id' => $sectionId,
                'date' => $date,
                'end_date' => $schedule['end_date'],
                'start_time' => $startTime,
                'end_time' => $endTime,
                'venue' => $venue,
                'facilitator' => $facilitator !== '' ? $facilitator : null,
                'required' => isset($data['attendance_required']) ? 1 : 0,
                'id' => $recollectionId,
            ]);
            if (isset($data['targets_submitted'])) self::replaceTargets($recollectionId, $data);
            self::syncParticipants($recollectionId);
            AuditService::log($actorId, 'update_recollection', 'recollection', $recollectionId, $title);
            NotificationService::afterActivity('recollection', $recollectionId);
            if ($ownsTransaction) $pdo->commit();
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    public static function recordRfidScan(int $recollectionId, string $uid, int $actorId): array
    {
        $user = RfidService::userForUid($uid);
        $pdo = Database::connection();
        self::assertOpen($recollectionId);
        self::syncParticipants($recollectionId);
        $statement = $pdo->prepare('SELECT id FROM recollection_participants WHERE recollection_id = :id AND user_id = :user_id');
        $statement->execute(['id' => $recollectionId, 'user_id' => $user['id']]);
        if (!$statement->fetchColumn()) {
            throw new DomainException('The scanned user is not assigned to this recollection or retreat.');
        }
        $pdo->prepare(
            'INSERT INTO recollection_attendance (recollection_id, user_id, time_in, status, source, recorded_by)
             VALUES (:id, :user_id, NOW(), "Attended", "rfid", :actor_id)
             ON DUPLICATE KEY UPDATE time_out = IF(time_in IS NULL, NULL, NOW()), time_in = COALESCE(time_in, NOW()),
                                     status = "Attended", source = "rfid", recorded_by = VALUES(recorded_by)'
        )->execute(['id' => $recollectionId, 'user_id' => $user['id'], 'actor_id' => $actorId]);
        AuditService::log($actorId, 'rfid_recollection_scan', 'recollection', $recollectionId, "User {$user['id']}");
        return $user;
    }

    public static function recordManual(int $recollectionId, int $userId, string $status, int $actorId, string $reason): void
    {
        if (!in_array($status, self::ATTENDANCE_STATUSES, true)) throw new DomainException('Invalid activity attendance status.');
        if (trim($reason) === '') throw new DomainException('A correction reason is required.');
        $pdo = Database::connection();
        self::syncParticipants($recollectionId);
        $participant = $pdo->prepare('SELECT id FROM recollection_participants WHERE recollection_id = :activity AND user_id = :user');
        $participant->execute(['activity' => $recollectionId, 'user' => $userId]);
        if (!$participant->fetchColumn()) throw new DomainException('The selected student is not assigned to this activity.');
        $timeIn = in_array($status, ['Attended', 'Late'], true) ? date('Y-m-d H:i:s') : null;
        $pdo->prepare(
            'INSERT INTO recollection_attendance (recollection_id, user_id, time_in, status, source, recorded_by, notes)
             VALUES (:activity, :user, :time_in, :status, "manual", :actor, :reason)
             ON DUPLICATE KEY UPDATE time_in = COALESCE(time_in, VALUES(time_in)), status = VALUES(status),
                                     source = "manual", recorded_by = VALUES(recorded_by), notes = VALUES(notes)'
        )->execute(['activity' => $recollectionId, 'user' => $userId, 'time_in' => $timeIn, 'status' => $status, 'actor' => $actorId, 'reason' => trim($reason)]);
        AuditService::log($actorId, 'correct_recollection_attendance', 'recollection', $recollectionId, "User {$userId}: {$status}; {$reason}");
    }

    public static function setStatus(int $recollectionId, string $status, int $actorId): void
    {
        if (!in_array($status, ['scheduled', 'open', 'closed', 'cancelled'], true)) throw new DomainException('Invalid activity status.');
        $pdo = Database::connection();
        self::syncParticipants($recollectionId);
        if ($status === 'closed') {
            $pdo->prepare(
                'INSERT INTO recollection_attendance (recollection_id, user_id, status, source, recorded_by, notes)
                 SELECT participants.recollection_id, participants.user_id, "Absent", "system", :actor, "Missing when activity attendance closed"
                 FROM recollection_participants participants
                 JOIN recollections ON recollections.id = participants.recollection_id
                 LEFT JOIN recollection_attendance attendance ON attendance.recollection_id = participants.recollection_id AND attendance.user_id = participants.user_id
                 WHERE participants.recollection_id = :activity AND recollections.attendance_required = 1 AND attendance.id IS NULL'
            )->execute(['actor' => $actorId, 'activity' => $recollectionId]);
        }
        $statement = $pdo->prepare('UPDATE recollections SET status = :status WHERE id = :id');
        $statement->execute(['status' => $status, 'id' => $recollectionId]);
        if ($statement->rowCount() === 0) throw new DomainException('Activity not found or status unchanged.');
        AuditService::log($actorId, 'set_recollection_status', 'recollection', $recollectionId, $status);
        NotificationService::afterActivity('recollection', $recollectionId);
    }

    public static function delete(int $recollectionId, int $actorId): array
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT id, title, status FROM recollections WHERE id = :id');
        $statement->execute(['id' => $recollectionId]);
        $activity = $statement->fetch();
        if (!$activity) throw new DomainException('The selected activity could not be found.');
        DeletionConflictService::assertClear('recollection', $recollectionId);
        if (!in_array($activity['status'], ['draft', 'scheduled', 'cancelled'], true)) {
            throw new DomainException('Activities with open or finalized attendance cannot be deleted. Cancel the activity to preserve its history.');
        }
        try {
            $pdo->prepare('DELETE FROM recollections WHERE id = :id')->execute(['id' => $recollectionId]);
        } catch (\PDOException $exception) {
            if ((int)($exception->errorInfo[1] ?? 0) === 1451) {
                DeletionConflictService::assertClear('recollection', $recollectionId);
                throw new DomainException('This activity gained protected history while deletion was in progress. Refresh and try again.');
            }
            throw $exception;
        }
        AuditService::log($actorId, 'delete_recollection', 'recollection', $recollectionId, (string) $activity['title']);
        NotificationService::afterActivity('recollection', $recollectionId);
        return ['id' => $recollectionId, 'label' => $activity['title']];
    }

    /**
     * SQL predicate shared by portal lists and calendars. A person is involved when they are an
     * expected participant, were explicitly added, belong to a targeted role, teach a class in
     * the section, are enrolled in one of that section's classes, or created the activity.
     */
    public static function involvementCondition(int $userId, string $alias = 'recollections'): string
    {
        if ($userId < 1 || !preg_match('/^[a-z_][a-z0-9_]*$/i', $alias)) {
            throw new DomainException('Invalid recollection visibility query.');
        }
        $viewer = (string) $userId;
        $preview = Auth::previewRole();
        $effectiveViewer = Auth::user();
        $roleMatch = $preview && (int) ($effectiveViewer['id'] ?? 0) === $userId
            ? 'visibility_roles.role_id = ' . (int) $preview['id']
            : 'visibility_roles.role_id IN (SELECT visibility_user_roles.role_id FROM user_roles visibility_user_roles WHERE visibility_user_roles.user_id = ' . $viewer . ')';

        return '(' . $alias . '.created_by = ' . $viewer . '
            OR EXISTS (SELECT 1 FROM recollection_participants visibility_participants
                       WHERE visibility_participants.recollection_id = ' . $alias . '.id
                         AND visibility_participants.user_id = ' . $viewer . ')
            OR EXISTS (SELECT 1 FROM recollection_target_users visibility_users
                       WHERE visibility_users.recollection_id = ' . $alias . '.id
                         AND visibility_users.user_id = ' . $viewer . ')
            OR EXISTS (SELECT 1 FROM recollection_target_roles visibility_roles
                       WHERE visibility_roles.recollection_id = ' . $alias . '.id
                         AND ' . $roleMatch . ')
            OR EXISTS (SELECT 1 FROM class_offerings visibility_offerings
                       JOIN teacher_assignments visibility_teachers ON visibility_teachers.class_offering_id = visibility_offerings.id
                       WHERE visibility_offerings.section_id = ' . $alias . '.section_id
                         AND visibility_offerings.status = "active"
                         AND visibility_teachers.teacher_id = ' . $viewer . ')
            OR EXISTS (SELECT 1 FROM class_offerings visibility_offerings
                       JOIN class_enrollments visibility_enrollments ON visibility_enrollments.class_offering_id = visibility_offerings.id
                       WHERE visibility_offerings.section_id = ' . $alias . '.section_id
                         AND visibility_offerings.status = "active"
                         AND visibility_enrollments.status = "enrolled"
                         AND visibility_enrollments.student_id = ' . $viewer . '))';
    }

    private static function replaceTargets(int $recollectionId, array $data): void
    {
        $pdo = Database::connection();
        $userIds = self::positiveIds($data['other_user_ids'] ?? []);
        $roleIds = self::positiveIds($data['other_role_ids'] ?? []);

        $pdo->prepare('DELETE FROM recollection_target_users WHERE recollection_id = :id')->execute(['id' => $recollectionId]);
        $pdo->prepare('DELETE FROM recollection_target_roles WHERE recollection_id = :id')->execute(['id' => $recollectionId]);

        $userStatement = $pdo->prepare(
            'INSERT IGNORE INTO recollection_target_users (recollection_id, user_id)
             SELECT :activity, users.id FROM users WHERE users.id = :target AND users.status = "active"'
        );
        foreach ($userIds as $userId) $userStatement->execute(['activity' => $recollectionId, 'target' => $userId]);

        $roleStatement = $pdo->prepare(
            'INSERT IGNORE INTO recollection_target_roles (recollection_id, role_id)
             SELECT :activity, roles.id FROM roles WHERE roles.id = :target'
        );
        foreach ($roleIds as $roleId) $roleStatement->execute(['activity' => $recollectionId, 'target' => $roleId]);
    }

    private static function positiveIds(mixed $values): array
    {
        if (!is_array($values)) $values = [$values];
        return array_values(array_unique(array_filter(array_map('intval', $values), static fn(int $id): bool => $id > 0)));
    }

    private static function syncParticipants(int $recollectionId): void
    {
        if ($recollectionId < 1) return;
        $pdo = Database::connection();
        $pdo->prepare(
            'DELETE participants FROM recollection_participants participants
             LEFT JOIN recollection_attendance attendance
               ON attendance.recollection_id = participants.recollection_id AND attendance.user_id = participants.user_id
             WHERE participants.recollection_id = :activity AND attendance.id IS NULL'
        )->execute(['activity' => $recollectionId]);

        $pdo->prepare(
            'INSERT IGNORE INTO recollection_participants (recollection_id, user_id)
             SELECT DISTINCT recollections.id, enrollments.student_id
             FROM recollections
             JOIN class_offerings ON class_offerings.section_id = recollections.section_id AND class_offerings.status = "active"
             JOIN class_enrollments enrollments ON enrollments.class_offering_id = class_offerings.id AND enrollments.status = "enrolled"
             JOIN users ON users.id = enrollments.student_id AND users.status = "active"
             WHERE recollections.id = :activity'
        )->execute(['activity' => $recollectionId]);

        $pdo->prepare(
            'INSERT IGNORE INTO recollection_participants (recollection_id, user_id)
             SELECT targets.recollection_id, targets.user_id
             FROM recollection_target_users targets
             JOIN users ON users.id = targets.user_id AND users.status = "active"
             WHERE targets.recollection_id = :activity'
        )->execute(['activity' => $recollectionId]);

        $pdo->prepare(
            'INSERT IGNORE INTO recollection_participants (recollection_id, user_id)
             SELECT targets.recollection_id, user_roles.user_id
             FROM recollection_target_roles targets
             JOIN user_roles ON user_roles.role_id = targets.role_id
             JOIN users ON users.id = user_roles.user_id AND users.status = "active"
             WHERE targets.recollection_id = :activity'
        )->execute(['activity' => $recollectionId]);
    }

    private static function schedule(array $data): array
    {
        $date=trim((string)($data['date'] ?? $data['start_date'] ?? ''));
        $endDate=trim((string)($data['end_date'] ?? '')) ?: $date;
        foreach (['start'=>$date,'end'=>$endDate] as $label=>$value) {
            $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
            if (!$parsed || $parsed->format('Y-m-d')!==$value) throw new DomainException('Select a valid activity '.$label.' date.');
        }
        if ($endDate<$date) throw new DomainException('The end date must be on or after the start date.');
        $startTime=normalize_time($data['start_time'] ?? '');
        $endTime=normalize_time($data['end_time'] ?? '');
        if ($endDate.' '.$endTime <= $date.' '.$startTime) throw new DomainException('The activity must end after its start date and time.');
        return ['date'=>$date,'end_date'=>$endDate,'start_time'=>$startTime,'end_time'=>$endTime];
    }

    private static function assertOpen(int $recollectionId): void
    {
        $statement = Database::connection()->prepare('SELECT status FROM recollections WHERE id = :id');
        $statement->execute(['id' => $recollectionId]);
        if ($statement->fetchColumn() !== 'open') throw new DomainException('Open activity attendance before recording scans.');
    }
}
