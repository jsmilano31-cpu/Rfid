<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DomainException;
use PDO;

final class TeacherTimeOffService
{
    public static function save(array $data, int $actorId): int
    {
        self::assertTeacher($actorId);
        $id = max(0, (int) ($data['time_off_id'] ?? 0));
        $startsOn = self::date((string) ($data['starts_on'] ?? ''), 'Select a valid start date.');
        $endsOn = self::date((string) ($data['ends_on'] ?? '') ?: $startsOn, 'Select a valid end date.');
        if ($endsOn < $startsOn) throw new DomainException('The end date cannot be before the start date.');
        if ($startsOn < date('Y-m-d')) throw new DomainException('Past teaching dates cannot be changed to time off.');
        if ((new DateTimeImmutable($startsOn))->diff(new DateTimeImmutable($endsOn))->days > 90) {
            throw new DomainException('A single time-off request can cover up to 90 days.');
        }
        $reason = trim((string) ($data['reason'] ?? ''));
        if (mb_strlen($reason) > 255) throw new DomainException('The reason can contain up to 255 characters.');

        $pdo = Database::connection();
        if ($id > 0) self::owned($id, $actorId);
        $overlap = $pdo->prepare(
            'SELECT id FROM teacher_time_off
             WHERE teacher_id = :teacher AND status = "active" AND id <> :id
               AND starts_on <= :ends_on AND ends_on >= :starts_on LIMIT 1'
        );
        $overlap->execute(['teacher' => $actorId, 'id' => $id, 'starts_on' => $startsOn, 'ends_on' => $endsOn]);
        if ($overlap->fetchColumn()) throw new DomainException('Those dates overlap an existing time-off entry. Edit the existing entry instead.');

        $attendanceConflict = $pdo->prepare(
            'SELECT 1 FROM teacher_assignments
             JOIN attendance_sessions ON attendance_sessions.class_offering_id = teacher_assignments.class_offering_id
             LEFT JOIN attendance_records ON attendance_records.attendance_session_id = attendance_sessions.id
             WHERE teacher_assignments.teacher_id = :teacher
               AND attendance_sessions.attendance_date BETWEEN :starts_on AND :ends_on
               AND (attendance_sessions.finalized_at IS NOT NULL OR attendance_records.id IS NOT NULL) LIMIT 1'
        );
        $attendanceConflict->execute(['teacher' => $actorId, 'starts_on' => $startsOn, 'ends_on' => $endsOn]);
        if ($attendanceConflict->fetchColumn()) {
            throw new DomainException('Attendance already exists for a class in this range. Correct that attendance before scheduling time off.');
        }

        if ($id > 0) {
            $pdo->prepare('UPDATE teacher_time_off SET starts_on = :starts_on, ends_on = :ends_on, reason = :reason, status = "active" WHERE id = :id')
                ->execute(['starts_on' => $startsOn, 'ends_on' => $endsOn, 'reason' => $reason ?: null, 'id' => $id]);
            AuditService::log($actorId, 'update_teacher_time_off', 'teacher_time_off', $id, "{$startsOn} through {$endsOn}: {$reason}");
            return $id;
        }
        $pdo->prepare(
            'INSERT INTO teacher_time_off (teacher_id, starts_on, ends_on, reason, created_by)
             VALUES (:teacher, :starts_on, :ends_on, :reason, :created_by)'
        )->execute(['teacher' => $actorId, 'starts_on' => $startsOn, 'ends_on' => $endsOn, 'reason' => $reason ?: null, 'created_by' => $actorId]);
        $id = (int) $pdo->lastInsertId();
        AuditService::log($actorId, 'create_teacher_time_off', 'teacher_time_off', $id, "{$startsOn} through {$endsOn}: {$reason}");
        return $id;
    }

    public static function cancel(int $id, int $actorId): void
    {
        $entry = self::owned($id, $actorId);
        Database::connection()->prepare('UPDATE teacher_time_off SET status = "cancelled" WHERE id = :id')->execute(['id' => $id]);
        AuditService::log($actorId, 'cancel_teacher_time_off', 'teacher_time_off', $id, $entry['starts_on'] . ' through ' . $entry['ends_on']);
    }

    public static function entryById(int $id, ?int $viewerId = null): array
    {
        $statement = Database::connection()->prepare(self::selectSql() . ' WHERE teacher_time_off.id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $entry = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$entry) throw new DomainException('The scheduled time off could not be found.');
        if ($viewerId !== null && !self::isVisibleTo($entry, $viewerId)) throw new DomainException('You cannot view this time-off entry.');
        return self::calendarEntry($entry, $viewerId);
    }

    public static function entriesForUser(int $userId): array
    {
        $statement = Database::connection()->prepare(
            self::selectSql() . '
             WHERE teacher_time_off.status = "active" AND (
                teacher_time_off.teacher_id = :viewer
                OR EXISTS (
                    SELECT 1 FROM teacher_assignments visibility_assignments
                    JOIN class_enrollments visibility_enrollments
                      ON visibility_enrollments.class_offering_id = visibility_assignments.class_offering_id
                     AND visibility_enrollments.status = "enrolled"
                    JOIN class_schedules visibility_schedules
                      ON visibility_schedules.class_offering_id = visibility_assignments.class_offering_id
                    WHERE visibility_assignments.teacher_id = teacher_time_off.teacher_id
                      AND visibility_enrollments.student_id = :student_viewer
                      AND MOD(FIELD(visibility_schedules.day_of_week,"Sunday","Monday","Tuesday","Wednesday","Thursday","Friday","Saturday")
                              - DAYOFWEEK(teacher_time_off.starts_on) + 7, 7) <= DATEDIFF(teacher_time_off.ends_on, teacher_time_off.starts_on)
                )
             ) ORDER BY teacher_time_off.starts_on'
        );
        $statement->execute(['viewer' => $userId, 'student_viewer' => $userId]);
        return array_map(static fn(array $entry): array => self::calendarEntry($entry, $userId), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function allEntries(): array
    {
        $entries = Database::connection()->query(self::selectSql() . ' WHERE teacher_time_off.status = "active" ORDER BY teacher_time_off.starts_on')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $entry): array => self::calendarEntry($entry, null), $entries);
    }

    public static function affectingOffering(int $offeringId, string $date): ?array
    {
        $statement = Database::connection()->prepare(
            self::selectSql() . '
             JOIN teacher_assignments ON teacher_assignments.teacher_id = teacher_time_off.teacher_id
             WHERE teacher_assignments.class_offering_id = :offering AND teacher_time_off.status = "active"
               AND :attendance_date BETWEEN teacher_time_off.starts_on AND teacher_time_off.ends_on
             ORDER BY teacher_time_off.starts_on LIMIT 1'
        );
        $statement->execute(['offering' => $offeringId, 'attendance_date' => $date]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function selectSql(): string
    {
        return 'SELECT teacher_time_off.*, CONCAT_WS(" ", users.first_name, users.middle_name, users.last_name) teacher_name
                FROM teacher_time_off JOIN users ON users.id = teacher_time_off.teacher_id';
    }

    private static function calendarEntry(array $entry, ?int $viewerId): array
    {
        $own = $viewerId !== null && (int) $entry['teacher_id'] === $viewerId;
        return [
            'id' => 'teacher-time-off-' . $entry['id'], 'source_id' => (int) $entry['id'], 'kind' => 'teacher_time_off',
            'title' => $own ? 'Scheduled time off' : $entry['teacher_name'] . ' — no class',
            'description' => $entry['reason'] ?: 'The teacher will not hold classes during this period.',
            'from' => $entry['starts_on'] . 'T00:00:00', 'to' => $entry['ends_on'] . 'T23:59:59',
            'location' => '', 'group' => 'Teacher availability', 'color' => '#667085', 'colorText' => '#ffffff',
            'customTags' => ['kind' => 'teacher_time_off', 'sourceId' => (int) $entry['id'], 'teacherId' => (int) $entry['teacher_id'],
                'teacherName' => $entry['teacher_name'], 'startsOn' => $entry['starts_on'], 'endsOn' => $entry['ends_on'],
                'reason' => $entry['reason'] ?? '', 'status' => $entry['status'], 'editable' => $own],
        ];
    }

    private static function assertTeacher(int $userId): void
    {
        $statement = Database::connection()->prepare(
            'SELECT 1 FROM user_roles JOIN roles ON roles.id = user_roles.role_id
             WHERE user_roles.user_id = :user AND roles.name = "Teacher" LIMIT 1'
        );
        $statement->execute(['user' => $userId]);
        if (!$statement->fetchColumn()) throw new DomainException('Only users assigned the Teacher role can schedule teaching time off.');
    }

    private static function owned(int $id, int $actorId): array
    {
        self::assertTeacher($actorId);
        $statement = Database::connection()->prepare('SELECT * FROM teacher_time_off WHERE id = :id AND teacher_id = :teacher LIMIT 1');
        $statement->execute(['id' => $id, 'teacher' => $actorId]);
        $entry = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$entry) throw new DomainException('You can manage only your own scheduled time off.');
        return $entry;
    }

    private static function date(string $value, string $message): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) throw new DomainException($message);
        return $value;
    }

    private static function isVisibleTo(array $entry, int $viewerId): bool
    {
        if ((int) $entry['teacher_id'] === $viewerId) return true;
        $statement = Database::connection()->prepare(
            'SELECT 1 FROM teacher_assignments JOIN class_enrollments
               ON class_enrollments.class_offering_id = teacher_assignments.class_offering_id AND class_enrollments.status = "enrolled"
             JOIN class_schedules ON class_schedules.class_offering_id = teacher_assignments.class_offering_id
             WHERE teacher_assignments.teacher_id = :teacher AND class_enrollments.student_id = :viewer
               AND MOD(FIELD(class_schedules.day_of_week,"Sunday","Monday","Tuesday","Wednesday","Thursday","Friday","Saturday")
                       - DAYOFWEEK(:starts_on) + 7, 7) <= DATEDIFF(:ends_on, :range_starts_on)
             LIMIT 1'
        );
        $statement->execute(['teacher' => $entry['teacher_id'], 'viewer' => $viewerId,
            'starts_on' => $entry['starts_on'], 'ends_on' => $entry['ends_on'], 'range_starts_on' => $entry['starts_on']]);
        return (bool) $statement->fetchColumn();
    }
}
