<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DomainException;
use PDO;
use Throwable;

final class AcademicCalendarService
{
    public const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public static function campusHoliday(string $date): ?string
    {
        $statement = Database::connection()->prepare('SELECT title FROM campus_holidays WHERE holiday_date = ? AND no_classes = 1');
        $statement->execute([$date]);
        $title = $statement->fetchColumn();
        return $title === false ? null : (string) $title;
    }

    public static function scheduleForOfferingDate(int $offeringId, string $date): array
    {
        $dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$dateValue || $dateValue->format('Y-m-d') !== $date) {
            throw new DomainException('Select a valid attendance date.');
        }
        $holidayTitle = self::campusHoliday($date);
        if ($holidayTitle !== null) {
            throw new DomainException("No classes on {$date}: {$holidayTitle}. Students will not be marked absent.");
        }
        $day = $dateValue->format('l');
        $statement = Database::connection()->prepare(
            'SELECT offerings.id AS offering_id, departments.id AS department_id,
                    departments.name AS department_name, schedules.id AS schedule_id,
                    schedules.start_time, schedules.end_time, days_off.day_of_week AS off_day
             FROM class_offerings AS offerings
             JOIN sections ON sections.id = offerings.section_id
             JOIN programs ON programs.id = sections.program_id
             JOIN departments ON departments.id = programs.department_id
             LEFT JOIN class_schedules AS schedules
               ON schedules.class_offering_id = offerings.id AND schedules.day_of_week = :schedule_day
             LEFT JOIN department_days_off AS days_off
               ON days_off.department_id = departments.id AND days_off.day_of_week = :off_day
             WHERE offerings.id = :offering_id
             ORDER BY schedules.start_time
             LIMIT 1'
        );
        $statement->execute(['schedule_day' => $day, 'off_day' => $day, 'offering_id' => $offeringId]);
        $schedule = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$schedule) {
            throw new DomainException('The selected class offering could not be found.');
        }
        if ($schedule['off_day'] !== null) {
            throw new DomainException("{$schedule['department_name']} has {$day} configured as a day off.");
        }
        if ($schedule['schedule_id'] === null) {
            throw new DomainException("This class is not scheduled on {$day}.");
        }
        $timeOff = TeacherTimeOffService::affectingOffering($offeringId, $date);
        if ($timeOff) {
            $reason = trim((string) ($timeOff['reason'] ?? ''));
            $message = "Class is cancelled on {$date} because {$timeOff['teacher_name']} scheduled teaching time off.";
            if ($reason !== '') $message .= " Reason: {$reason}.";
            $message .= ' Students will not be marked absent.';
            throw new DomainException($message);
        }
        return $schedule;
    }

    public static function assertScheduleAllowed(int $sectionId, string $day): void
    {
        if (!in_array($day, self::DAYS, true)) {
            throw new DomainException('Select a valid class day.');
        }
        $statement = Database::connection()->prepare(
            'SELECT departments.name, days_off.day_of_week AS off_day
             FROM sections
             JOIN programs ON programs.id = sections.program_id
             JOIN departments ON departments.id = programs.department_id
             LEFT JOIN department_days_off AS days_off
               ON days_off.department_id = departments.id AND days_off.day_of_week = :day
             WHERE sections.id = :section_id'
        );
        $statement->execute(['day' => $day, 'section_id' => $sectionId]);
        $department = $statement->fetch();
        if (!$department) {
            throw new DomainException('The selected section could not be found.');
        }
        if ($department['off_day'] !== null) {
            throw new DomainException("{$department['name']} has {$day} configured as a day off.");
        }
    }

    public static function policies(): array
    {
        $rows = Database::connection()->query(
            'SELECT departments.id, departments.code, departments.name, departments.color_key, days_off.day_of_week
             FROM departments
             LEFT JOIN department_days_off AS days_off ON days_off.department_id = departments.id
             ORDER BY departments.name,
                      FIELD(days_off.day_of_week, "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday")'
        )->fetchAll();
        $policies = [];
        foreach ($rows as $row) {
            $departmentId = (int) $row['id'];
            $policies[$departmentId] ??= [
                'id' => $departmentId,
                'code' => $row['code'],
                'name' => $row['name'],
                'color_key' => $row['color_key'],
                'days_off' => [],
            ];
            if ($row['day_of_week'] !== null) {
                $policies[$departmentId]['days_off'][] = $row['day_of_week'];
            }
        }
        return array_values($policies);
    }

    public static function setDaysOff(int $departmentId, array $daysOff, int $actorId): void
    {
        \App\Core\PermissionRegistry::assertAction('update_department_days_off');
        $daysOff = array_values(array_unique(array_filter(array_map('strval', $daysOff), static fn(string $day): bool => in_array($day, self::DAYS, true))));
        $pdo = Database::connection();
        $department = $pdo->prepare('SELECT name FROM departments WHERE id = :id');
        $department->execute(['id' => $departmentId]);
        $departmentName = $department->fetchColumn();
        if ($departmentName === false) {
            throw new DomainException('The selected department could not be found.');
        }

        if ($daysOff) {
            $placeholders = implode(',', array_fill(0, count($daysOff), '?'));
            $conflicts = $pdo->prepare(
                "SELECT DISTINCT schedules.day_of_week
                 FROM class_schedules AS schedules
                 JOIN class_offerings AS offerings ON offerings.id = schedules.class_offering_id
                 JOIN sections ON sections.id = offerings.section_id
                 JOIN programs ON programs.id = sections.program_id
                 WHERE programs.department_id = ? AND schedules.day_of_week IN ({$placeholders})"
            );
            $conflicts->execute([$departmentId, ...$daysOff]);
            $conflictingDays = $conflicts->fetchAll(PDO::FETCH_COLUMN);
            if ($conflictingDays) {
                throw new DomainException('Move or remove existing classes scheduled on: ' . implode(', ', $conflictingDays) . '.');
            }
        }

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $pdo->prepare('DELETE FROM department_days_off WHERE department_id = :department_id')
                ->execute(['department_id' => $departmentId]);
            $insert = $pdo->prepare(
                'INSERT INTO department_days_off (department_id, day_of_week, reason)
                 VALUES (:department_id, :day, "Department weekly day off")'
            );
            foreach ($daysOff as $day) {
                $insert->execute(['department_id' => $departmentId, 'day' => $day]);
            }
            AuditService::log($actorId, 'update_department_days_off', 'department', $departmentId, implode(',', $daysOff));
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
}
