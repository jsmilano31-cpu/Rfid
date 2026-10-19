<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateInterval;
use DateTimeImmutable;
use DomainException;
use PDO;
use Throwable;

final class LiveSeatPlanService
{
    public static function dashboardState(int $actorId): array
    {
        $now = new DateTimeImmutable('now');
        $rows = self::teacherSchedules($actorId);
        $active = null;
        $next = null;

        for ($offset = 0; $offset <= 14; $offset++) {
            $day = $now->add(new DateInterval('P' . $offset . 'D'));
            foreach ($rows as $row) {
                if ($row['day_of_week'] !== $day->format('l')) continue;
                if ($day->format('Y-m-d') < $row['term_starts_on'] || $day->format('Y-m-d') > $row['term_ends_on']) continue;
                try {
                    AcademicCalendarService::scheduleForOfferingDate((int) $row['offering_id'], $day->format('Y-m-d'));
                } catch (DomainException) {
                    continue;
                }
                $startsAt = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $row['start_time']);
                $endsAt = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $row['end_time']);
                if ($offset === 0 && $now >= $startsAt && $now < $endsAt) {
                    if ($active === null || $startsAt > $active['starts_at']) $active = ['row' => $row, 'starts_at' => $startsAt, 'ends_at' => $endsAt];
                    continue;
                }
                if ($startsAt > $now && ($next === null || $startsAt < $next['starts_at'])) {
                    $next = ['row' => $row, 'starts_at' => $startsAt, 'ends_at' => $endsAt];
                }
            }
        }

        if ($active !== null) {
            return ['mode' => 'active'] + self::state(
                (int) $active['row']['offering_id'],
                $actorId,
                $now->format('Y-m-d'),
                (int) $active['row']['schedule_id']
            );
        }
        if ($next !== null) {
            return [
                'mode' => 'next',
                'class' => self::classSummary($next['row'], $next['starts_at'], $next['ends_at']),
                'server_time' => $now->format(DATE_ATOM),
            ];
        }
        return [
            'mode' => 'empty',
            'message' => $rows ? 'No class is scheduled in the next two weeks.' : 'No active classes are assigned to you.',
            'server_time' => $now->format(DATE_ATOM),
        ];
    }

    public static function state(int $offeringId, int $actorId, string $date, ?int $scheduleId = null): array
    {
        AttendanceService::assertOfferingAccess($offeringId, $actorId);
        $dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$dateValue || $dateValue->format('Y-m-d') !== $date) throw new DomainException('Select a valid attendance date.');

        $pdo = Database::connection();
        $class = self::offering($offeringId, $scheduleId, $dateValue->format('l'));
        if (!$class) throw new DomainException('The selected class could not be found.');
        $roomId = (int) ($class['schedule_room_id'] ?: $class['offering_room_id']);
        $class['room_id'] = $roomId ?: null;
        $class['room_code'] = $class['schedule_room_code'] ?: $class['offering_room_code'];
        $class['room_name'] = $class['schedule_room_name'] ?: $class['offering_room_name'];
        unset($class['schedule_room_id'], $class['offering_room_id'], $class['schedule_room_code'], $class['offering_room_code'], $class['schedule_room_name'], $class['offering_room_name']);

        if ($roomId < 1) {
            return [
                'date' => $date,
                'class' => self::normalizeClass($class),
                'plan' => null,
                'reason' => 'no_room',
                'message' => 'This class does not have a room assigned yet.',
                'configure_url' => '/admin/?page=academics#class-offerings',
                'server_time' => date(DATE_ATOM),
            ];
        }

        $planStatement = $pdo->prepare('SELECT id, room_id, name, row_count, column_count, updated_at FROM room_seat_plans WHERE room_id = :room LIMIT 1');
        $planStatement->execute(['room' => $roomId]);
        $plan = $planStatement->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            return [
                'date' => $date,
                'class' => self::normalizeClass($class),
                'plan' => null,
                'reason' => 'no_plan',
                'message' => 'This class does not have a seat plan yet.',
                'configure_url' => '/index/?page=seat-plans',
                'server_time' => date(DATE_ATOM),
            ];
        }

        $cells = $pdo->prepare(
            'SELECT cells.grid_row, cells.grid_column, cells.cell_type, cells.label,
                    assignments.student_id, users.school_id, users.first_name, users.last_name, users.profile_image_path,
                    records.status attendance_status, records.time_in, records.updated_at attendance_updated_at
             FROM room_seat_plan_cells cells
             LEFT JOIN class_seat_assignments assignments
               ON assignments.seat_plan_id = cells.seat_plan_id
              AND assignments.class_offering_id = :offering_id
              AND assignments.grid_row = cells.grid_row AND assignments.grid_column = cells.grid_column
             LEFT JOIN users ON users.id = assignments.student_id
             LEFT JOIN attendance_records records
               ON records.class_offering_id = :attendance_offering_id
              AND records.student_id = assignments.student_id AND records.attendance_date = :attendance_date
             WHERE cells.seat_plan_id = :plan_id
             ORDER BY cells.grid_row, cells.grid_column'
        );
        $cells->execute([
            'offering_id' => $offeringId,
            'attendance_offering_id' => $offeringId,
            'attendance_date' => $date,
            'plan_id' => $plan['id'],
        ]);
        $cellRows = array_map(static function (array $cell): array {
            $cell['grid_row'] = (int) $cell['grid_row'];
            $cell['grid_column'] = (int) $cell['grid_column'];
            $cell['student_id'] = $cell['student_id'] !== null ? (int) $cell['student_id'] : null;
            $cell['student_name'] = $cell['student_id'] ? trim($cell['first_name'] . ' ' . $cell['last_name']) : null;
            $cell['checked_in'] = in_array($cell['attendance_status'], ['Present', 'Late'], true);
            unset($cell['first_name'], $cell['last_name']);
            return $cell;
        }, $cells->fetchAll(PDO::FETCH_ASSOC));

        $plan['id'] = (int) $plan['id'];
        $plan['room_id'] = (int) $plan['room_id'];
        $plan['row_count'] = (int) $plan['row_count'];
        $plan['column_count'] = (int) $plan['column_count'];
        $plan['cells'] = $cellRows;
        $plan['seat_count'] = count(array_filter($cellRows, static fn(array $cell): bool => $cell['cell_type'] === 'student_seat'));
        $plan['assigned_count'] = count(array_filter($cellRows, static fn(array $cell): bool => $cell['student_id'] !== null));
        $plan['checked_in_count'] = count(array_filter($cellRows, static fn(array $cell): bool => $cell['checked_in']));

        return [
            'date' => $date,
            'class' => self::normalizeClass($class),
            'plan' => $plan,
            'reason' => null,
            'configure_url' => '/index/?page=seat-plans',
            'server_time' => date(DATE_ATOM),
        ];
    }

    public static function students(int $offeringId, int $planId, int $actorId, string $query = ''): array
    {
        AttendanceService::assertOfferingAccess($offeringId, $actorId);
        self::assertPlanForOffering($offeringId, $planId);
        $query = mb_substr(trim($query), 0, 100);
        $params = ['offering' => $offeringId, 'plan' => $planId];
        $where = '';
        if ($query !== '') {
            $where = ' AND (users.school_id LIKE :school_id OR users.first_name LIKE :first_name OR users.last_name LIKE :last_name OR CONCAT(users.first_name, " ", users.last_name) LIKE :full_name)';
            $prefix = $query . '%';
            $params += ['school_id' => $prefix, 'first_name' => $prefix, 'last_name' => $prefix, 'full_name' => $prefix];
        }
        $statement = Database::connection()->prepare(
            'SELECT users.id, users.school_id, users.first_name, users.last_name, users.profile_image_path,
                    assignments.grid_row, assignments.grid_column
             FROM class_enrollments enrollments
             JOIN users ON users.id = enrollments.student_id AND users.status = "active"
             LEFT JOIN class_seat_assignments assignments
               ON assignments.class_offering_id = enrollments.class_offering_id
              AND assignments.seat_plan_id = :plan AND assignments.student_id = users.id
             WHERE enrollments.class_offering_id = :offering AND enrollments.status = "enrolled"' . $where . '
             ORDER BY users.last_name, users.first_name LIMIT 24'
        );
        $statement->execute($params);
        return array_map(static function (array $student): array {
            $student['id'] = (int) $student['id'];
            $student['grid_row'] = $student['grid_row'] !== null ? (int) $student['grid_row'] : null;
            $student['grid_column'] = $student['grid_column'] !== null ? (int) $student['grid_column'] : null;
            $student['name'] = trim($student['first_name'] . ' ' . $student['last_name']);
            unset($student['first_name'], $student['last_name']);
            return $student;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function assign(int $offeringId, int $planId, int $row, int $column, int $studentId, int $actorId): void
    {
        if (!\App\Core\PermissionRegistry::canAssignSeats()) throw new DomainException('You do not have permission to manage seat assignments.');
        AttendanceService::assertOfferingAccess($offeringId, $actorId);
        self::assertPlanForOffering($offeringId, $planId);
        $pdo = Database::connection();
        $cell = $pdo->prepare('SELECT 1 FROM room_seat_plan_cells WHERE seat_plan_id = :plan AND grid_row = :row AND grid_column = :column AND cell_type = "student_seat"');
        $cell->execute(['plan' => $planId, 'row' => $row, 'column' => $column]);
        if (!$cell->fetchColumn()) throw new DomainException('Choose an active student seat.');
        $enrollment = $pdo->prepare('SELECT 1 FROM class_enrollments WHERE class_offering_id = :offering AND student_id = :student AND status = "enrolled"');
        $enrollment->execute(['offering' => $offeringId, 'student' => $studentId]);
        if (!$enrollment->fetchColumn()) throw new DomainException('That student is not enrolled in this class.');

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM class_seat_assignments WHERE class_offering_id = :offering AND seat_plan_id = :plan AND (student_id = :student OR (grid_row = :row AND grid_column = :column))')
                ->execute(['offering' => $offeringId, 'plan' => $planId, 'student' => $studentId, 'row' => $row, 'column' => $column]);
            $pdo->prepare('INSERT INTO class_seat_assignments (class_offering_id, seat_plan_id, grid_row, grid_column, student_id, assigned_by) VALUES (:offering, :plan, :row, :column, :student, :actor)')
                ->execute(['offering' => $offeringId, 'plan' => $planId, 'row' => $row, 'column' => $column, 'student' => $studentId, 'actor' => $actorId]);
            AuditService::log($actorId, 'assign_class_seat', 'class_offering', $offeringId, "Student {$studentId} assigned to {$row}:{$column}");
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }

    public static function unassign(int $offeringId, int $planId, int $row, int $column, int $actorId): void
    {
        if (!\App\Core\PermissionRegistry::canAssignSeats()) throw new DomainException('You do not have permission to manage seat assignments.');
        AttendanceService::assertOfferingAccess($offeringId, $actorId);
        self::assertPlanForOffering($offeringId, $planId);
        Database::connection()->prepare('DELETE FROM class_seat_assignments WHERE class_offering_id = :offering AND seat_plan_id = :plan AND grid_row = :row AND grid_column = :column')
            ->execute(['offering' => $offeringId, 'plan' => $planId, 'row' => $row, 'column' => $column]);
        AuditService::log($actorId, 'unassign_class_seat', 'class_offering', $offeringId, "Seat {$row}:{$column} cleared");
    }

    private static function teacherSchedules(int $actorId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT schedules.id schedule_id, schedules.class_offering_id offering_id, schedules.day_of_week,
                    schedules.start_time, schedules.end_time, COALESCE(schedules.room_id, offerings.room_id) room_id,
                    courses.course_code, courses.title course_title, programs.code program_code,
                    sections.name section_name, rooms.room_code, rooms.room_name,
                    semesters.starts_on term_starts_on, semesters.ends_on term_ends_on
             FROM teacher_assignments assignments
             JOIN class_offerings offerings ON offerings.id = assignments.class_offering_id AND offerings.status = "active"
             JOIN class_schedules schedules ON schedules.class_offering_id = offerings.id
             JOIN courses ON courses.id = offerings.course_id
             JOIN sections ON sections.id = offerings.section_id
             JOIN programs ON programs.id = sections.program_id
             JOIN semesters ON semesters.id = offerings.semester_id
             LEFT JOIN rooms ON rooms.id = COALESCE(schedules.room_id, offerings.room_id)
             WHERE assignments.teacher_id = :actor
             ORDER BY schedules.start_time'
        );
        $statement->execute(['actor' => $actorId]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function offering(int $offeringId, ?int $scheduleId, string $day): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT offerings.id offering_id, courses.course_code, courses.title course_title,
                    programs.code program_code, sections.name section_name,
                    schedules.id schedule_id, schedules.day_of_week, schedules.start_time, schedules.end_time,
                    schedules.room_id schedule_room_id, schedule_rooms.room_code schedule_room_code, schedule_rooms.room_name schedule_room_name,
                    offerings.room_id offering_room_id, offering_rooms.room_code offering_room_code, offering_rooms.room_name offering_room_name
             FROM class_offerings offerings
             JOIN courses ON courses.id = offerings.course_id
             JOIN sections ON sections.id = offerings.section_id
             JOIN programs ON programs.id = sections.program_id
             LEFT JOIN class_schedules schedules ON schedules.class_offering_id = offerings.id
               AND ((:schedule_gate > 0 AND schedules.id = :schedule_match) OR (:schedule_fallback = 0 AND schedules.day_of_week = :day))
             LEFT JOIN rooms schedule_rooms ON schedule_rooms.id = schedules.room_id
             LEFT JOIN rooms offering_rooms ON offering_rooms.id = offerings.room_id
             WHERE offerings.id = :offering LIMIT 1'
        );
        $statement->execute(['schedule_gate' => $scheduleId ?? 0, 'schedule_match' => $scheduleId ?? 0, 'schedule_fallback' => $scheduleId ?? 0, 'day' => $day, 'offering' => $offeringId]);
        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function assertPlanForOffering(int $offeringId, int $planId): void
    {
        if ($planId < 1) throw new DomainException('This class does not have a seat plan yet.');
        $statement = Database::connection()->prepare(
            'SELECT 1 FROM room_seat_plans plans
             JOIN class_offerings offerings ON offerings.id = :offering
             WHERE plans.id = :plan AND (plans.room_id = offerings.room_id OR EXISTS(
                 SELECT 1 FROM class_schedules schedules WHERE schedules.class_offering_id = offerings.id AND schedules.room_id = plans.room_id
             )) LIMIT 1'
        );
        $statement->execute(['offering' => $offeringId, 'plan' => $planId]);
        if (!$statement->fetchColumn()) throw new DomainException('That seat plan is not assigned to this class room.');
    }

    private static function normalizeClass(array $class): array
    {
        foreach (['offering_id', 'schedule_id', 'room_id'] as $field) $class[$field] = isset($class[$field]) && $class[$field] !== null ? (int) $class[$field] : null;
        return $class;
    }

    private static function classSummary(array $row, DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): array
    {
        return [
            'offering_id' => (int) $row['offering_id'],
            'schedule_id' => (int) $row['schedule_id'],
            'course_code' => $row['course_code'],
            'course_title' => $row['course_title'],
            'program_code' => $row['program_code'],
            'section_name' => $row['section_name'],
            'room_id' => $row['room_id'] ? (int) $row['room_id'] : null,
            'room_code' => $row['room_code'],
            'room_name' => $row['room_name'],
            'day_of_week' => $row['day_of_week'],
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'starts_at' => $startsAt->format(DATE_ATOM),
            'ends_at' => $endsAt->format(DATE_ATOM),
        ];
    }
}
