<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DomainException;
use PDO;

final class AttendanceService
{
    public static function hasGlobalPolicyAccess(int $actorId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT 1 FROM user_roles
             JOIN roles ON roles.id = user_roles.role_id
             JOIN role_permissions ON role_permissions.role_id = roles.id
             JOIN permissions ON permissions.id = role_permissions.permission_id
             WHERE user_roles.user_id = :user_id
               AND permissions.code = "manage_attendance_policies"
               AND roles.name NOT IN ("Teacher", "Student")
             LIMIT 1'
        );
        $statement->execute(['user_id' => $actorId]);
        return (bool) $statement->fetchColumn();
    }

    public static function hasGlobalAccess(int $actorId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT 1 FROM user_roles
             JOIN roles ON roles.id = user_roles.role_id
             JOIN role_permissions ON role_permissions.role_id = roles.id
             JOIN permissions ON permissions.id = role_permissions.permission_id
             WHERE user_roles.user_id = :user_id
               AND permissions.code = "manage_attendance"
               AND roles.name NOT IN ("Teacher", "Student")
             LIMIT 1'
        );
        $statement->execute(['user_id' => $actorId]);
        return (bool) $statement->fetchColumn();
    }

    public static function subjects(int $actorId, string $query = '', int $page = 1, int $perPage = 12): array
    {
        $pdo = Database::connection();
        $query = mb_substr(trim($query), 0, 100);
        $page = max(1, $page);
        $perPage = max(6, min(24, $perPage));
        [$accessSql, $accessParams] = self::accessScope($actorId);
        $where = ['class_offerings.status = "active"', $accessSql];
        $params = $accessParams;
        if ($query !== '') {
            $where[] = '(courses.course_code LIKE :course_code OR courses.title LIKE :course_title OR programs.code LIKE :program_code OR sections.name LIKE :section_name)';
            $prefix = $query . '%';
            $params += ['course_code' => $prefix, 'course_title' => $prefix, 'program_code' => $prefix, 'section_name' => $prefix];
        }
        $fromSql = ' FROM class_offerings
            JOIN courses ON courses.id = class_offerings.course_id
            JOIN sections ON sections.id = class_offerings.section_id
            JOIN programs ON programs.id = sections.program_id
            JOIN semesters ON semesters.id = class_offerings.semester_id
            JOIN school_years ON school_years.id = semesters.school_year_id
            LEFT JOIN rooms ON rooms.id = class_offerings.room_id
            WHERE ' . implode(' AND ', $where);
        $count = $pdo->prepare('SELECT COUNT(DISTINCT class_offerings.id)' . $fromSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $statement = $pdo->prepare(
            'SELECT class_offerings.id, class_offerings.component_type, courses.course_code, courses.title course_title,
                    programs.code program_code, programs.name program_name,
                    sections.name section_name, sections.year_level, sections.section_code,
                    school_years.name school_year_name, semesters.name semester_name, rooms.room_code,
                    (SELECT GROUP_CONCAT(CONCAT(users.first_name, " ", users.last_name) ORDER BY users.last_name SEPARATOR ", ")
                     FROM teacher_assignments JOIN users ON users.id = teacher_assignments.teacher_id
                     WHERE teacher_assignments.class_offering_id = class_offerings.id) teacher_names,
                    (SELECT GROUP_CONCAT(CONCAT(class_schedules.day_of_week, " ", TIME_FORMAT(class_schedules.start_time, "%h:%i %p"), "–", TIME_FORMAT(class_schedules.end_time, "%h:%i %p"))
                            ORDER BY FIELD(class_schedules.day_of_week,"Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"), class_schedules.start_time SEPARATOR "||")
                     FROM class_schedules WHERE class_schedules.class_offering_id = class_offerings.id) schedule_csv,
                    (SELECT COUNT(*) FROM class_enrollments WHERE class_enrollments.class_offering_id = class_offerings.id AND class_enrollments.status = "enrolled") student_count,
                    (SELECT COUNT(*) FROM attendance_sessions WHERE attendance_sessions.class_offering_id = class_offerings.id) session_count,
                    (SELECT MAX(attendance_sessions.attendance_date) FROM attendance_sessions WHERE attendance_sessions.class_offering_id = class_offerings.id) latest_session_date,
                    (SELECT MAX(attendance_sessions.finalized_at) FROM attendance_sessions WHERE attendance_sessions.class_offering_id = class_offerings.id) latest_finalized_at'
            . $fromSql .
            " ORDER BY courses.course_code, programs.code, sections.year_level, sections.section_code LIMIT {$perPage} OFFSET {$offset}"
        );
        $statement->execute($params);
        $subjects = array_map(static function (array $subject): array {
            $subject['schedule'] = $subject['schedule_csv'] ? explode('||', $subject['schedule_csv']) : [];
            unset($subject['schedule_csv']);
            foreach (['id', 'year_level', 'student_count', 'session_count'] as $field) $subject[$field] = (int) $subject[$field];
            return $subject;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
        return ['subjects' => $subjects, 'pagination' => compact('total', 'page', 'pages', 'perPage')];
    }

    public static function roster(int $offeringId, int $actorId, string $date, string $query = '', int $page = 1, int $perPage = 25): array
    {
        self::assertOfferingAccess($offeringId, $actorId);
        $dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$dateValue || $dateValue->format('Y-m-d') !== $date) throw new DomainException('Select a valid attendance date.');
        $pdo = Database::connection();
        $query = mb_substr(trim($query), 0, 100);
        $page = max(1, $page);
        $perPage = max(10, min(50, $perPage));
        $subjectStatement = $pdo->prepare(
            'SELECT class_offerings.id, class_offerings.component_type, courses.course_code, courses.title course_title,
                    programs.code program_code, programs.name program_name, sections.name section_name,
                    school_years.name school_year_name, semesters.name semester_name
             FROM class_offerings
             JOIN courses ON courses.id = class_offerings.course_id
             JOIN sections ON sections.id = class_offerings.section_id
             JOIN programs ON programs.id = sections.program_id
             JOIN semesters ON semesters.id = class_offerings.semester_id
             JOIN school_years ON school_years.id = semesters.school_year_id
             WHERE class_offerings.id = :id'
        );
        $subjectStatement->execute(['id' => $offeringId]);
        $subject = $subjectStatement->fetch(PDO::FETCH_ASSOC);
        if (!$subject) throw new DomainException('The selected class could not be found.');

        $scheduled = true;
        $scheduleMessage = '';
        $schedule = null;
        try {
            $schedule = AcademicCalendarService::scheduleForOfferingDate($offeringId, $date);
        } catch (DomainException $exception) {
            $scheduled = false;
            $scheduleMessage = $exception->getMessage();
        }
        $sessionStatement = $pdo->prepare('SELECT id, finalized_at FROM attendance_sessions WHERE class_offering_id = :offering AND attendance_date = :date');
        $sessionStatement->execute(['offering' => $offeringId, 'date' => $date]);
        $session = $sessionStatement->fetch(PDO::FETCH_ASSOC) ?: null;

        $baseWhere = ['class_enrollments.class_offering_id = :offering_id', 'class_enrollments.status = "enrolled"', 'users.status = "active"'];
        $baseParams = ['offering_id' => $offeringId, 'attendance_date' => $date];
        $where = $baseWhere;
        $params = $baseParams;
        if ($query !== '') {
            $where[] = '(users.school_id LIKE :school_id OR users.first_name LIKE :first_name OR users.last_name LIKE :last_name OR CONCAT_WS(" ", users.first_name, users.middle_name, users.last_name) LIKE :full_name)';
            $prefix = $query . '%';
            $params += ['school_id' => $prefix, 'first_name' => $prefix, 'last_name' => $prefix, 'full_name' => $prefix];
        }
        $fromSql = ' FROM class_enrollments JOIN users ON users.id = class_enrollments.student_id
                     LEFT JOIN attendance_records ON attendance_records.class_offering_id = class_enrollments.class_offering_id
                        AND attendance_records.student_id = users.id AND attendance_records.attendance_date = :attendance_date
                     WHERE ' . implode(' AND ', $where);
        $count = $pdo->prepare('SELECT COUNT(DISTINCT users.id)' . $fromSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $statement = $pdo->prepare(
            'SELECT users.id student_id, users.school_id, users.first_name, users.middle_name, users.last_name,
                    users.profile_image_path, attendance_records.id record_id, attendance_records.time_in,
                    attendance_records.time_out, COALESCE(attendance_records.status, "Unrecorded") status,
                    attendance_records.source, attendance_records.correction_reason, attendance_records.updated_at'
            . $fromSql . " ORDER BY users.last_name, users.first_name LIMIT {$perPage} OFFSET {$offset}"
        );
        $statement->execute($params);
        $students = array_map([self::class, 'normalizeAttendanceStudent'], $statement->fetchAll(PDO::FETCH_ASSOC));

        $summaryFromSql = ' FROM class_enrollments JOIN users ON users.id = class_enrollments.student_id
                     LEFT JOIN attendance_records ON attendance_records.class_offering_id = class_enrollments.class_offering_id
                        AND attendance_records.student_id = users.id AND attendance_records.attendance_date = :attendance_date
                     WHERE ' . implode(' AND ', $baseWhere);
        $summaryStatement = $pdo->prepare(
            'SELECT COALESCE(attendance_records.status, "Unrecorded") status, COUNT(*) total'
            . $summaryFromSql . ' GROUP BY COALESCE(attendance_records.status, "Unrecorded")'
        );
        $summaryStatement->execute($baseParams);
        $summary = array_fill_keys(['Present', 'Late', 'Absent', 'Excused', 'Unrecorded'], 0);
        foreach ($summaryStatement->fetchAll(PDO::FETCH_ASSOC) as $row) $summary[$row['status']] = (int) $row['total'];
        return [
            'subject' => $subject,
            'students' => $students,
            'summary' => $summary,
            'pagination' => compact('total', 'page', 'pages', 'perPage'),
            'session' => $session,
            'date' => $date,
            'scheduled' => $scheduled,
            'schedule_message' => $scheduleMessage,
            'schedule' => $schedule,
        ];
    }

    public static function recordRfidScan(int $offeringId, string $uid, ?int $actorId = null, ?string $date = null, ?string $time = null, ?int $deviceId = null): array
    {
        if ($actorId !== null) self::assertOfferingAccess($offeringId, $actorId);
        $pdo = Database::connection();
        $user = RfidService::userForUid($uid);
        $date ??= date('Y-m-d');
        $time = normalize_time($time ?? date('H:i:s'));

        $statement = $pdo->prepare(
            'SELECT class_enrollments.id
             FROM class_enrollments
             WHERE class_offering_id = :offering_id AND student_id = :student_id AND status = "enrolled"'
        );
        $statement->execute(['offering_id' => $offeringId, 'student_id' => $user['id']]);
        if (!$statement->fetchColumn()) {
            throw new DomainException('The scanned user is not enrolled in this class.');
        }

        $schedule = AcademicCalendarService::scheduleForOfferingDate($offeringId, $date);
        $startTime = $schedule['start_time'];
        $settings = self::resolvedPolicy($offeringId);
        $classStarts = new DateTimeImmutable("{$date} {$startTime}");
        $scanTime = new DateTimeImmutable("{$date} {$time}");
        $earlyCutoff = $classStarts->modify('-' . (int) $settings['early_check_in_minutes'] . ' minutes');
        if ($scanTime < $earlyCutoff) {
            throw new DomainException("Attendance opens {$settings['early_check_in_minutes']} minutes before class at " . $classStarts->format('g:i A') . '.');
        }
        $presentCutoff = $classStarts->modify('+' . (int) $settings['grace_period_minutes'] . ' minutes');
        $absentCutoff = $classStarts->modify('+' . (int) $settings['absent_after_minutes'] . ' minutes');
        $status = $scanTime <= $presentCutoff ? 'Present' : ($scanTime < $absentCutoff ? 'Late' : 'Absent');

        $existingSession = $pdo->prepare('SELECT id, finalized_at FROM attendance_sessions WHERE class_offering_id = :offering AND attendance_date = :date LIMIT 1');
        $existingSession->execute(['offering' => $offeringId, 'date' => $date]);
        $session = $existingSession->fetch(PDO::FETCH_ASSOC);
        if ($session && $session['finalized_at'] !== null) throw new DomainException('Attendance for this class has already been finalized.');
        $sessionId = $session ? (int) $session['id'] : self::ensureSession($offeringId, $date);
        $note = $deviceId === null ? 'RFID scan entered by an authorized user' : "RFID scanner {$deviceId}";
        $statement = $pdo->prepare(
            'INSERT INTO attendance_records
                (attendance_session_id, class_offering_id, student_id, attendance_date, time_in, status, source, recorded_by, notes)
             VALUES (:session_id, :offering_id, :student_id, :attendance_date, :time_in, :status, "rfid", :actor_id, :notes)
             ON DUPLICATE KEY UPDATE
                status = IF(source = "manual", status, IF(source = "rfid", status, VALUES(status))),
                time_in = IF(source = "manual", time_in, COALESCE(time_in, VALUES(time_in))),
                source = IF(source = "manual", source, "rfid"),
                recorded_by = IF(source = "manual", recorded_by, VALUES(recorded_by)),
                notes = IF(source = "manual", notes, VALUES(notes))'
        );
        $statement->execute([
            'session_id' => $sessionId,
            'offering_id' => $offeringId,
            'student_id' => $user['id'],
            'attendance_date' => $date,
            'time_in' => $time,
            'status' => $status,
            'actor_id' => $actorId,
            'notes' => $note,
        ]);
        $saved = $pdo->prepare('SELECT status, time_in FROM attendance_records WHERE class_offering_id = :offering AND student_id = :student AND attendance_date = :date');
        $saved->execute(['offering' => $offeringId, 'student' => $user['id'], 'date' => $date]);
        $record = $saved->fetch(PDO::FETCH_ASSOC);
        $savedStatus = (string) ($record['status'] ?? $status);
        NotificationService::afterAttendance($offeringId, (int)$user['id']);
        if ($actorId !== null) AuditService::log($actorId, 'rfid_class_scan', 'class_offering', $offeringId, "User {$user['id']} recorded {$savedStatus}");
        return ['user' => $user, 'status' => $savedStatus, 'date' => $date, 'time' => (string) ($record['time_in'] ?? $time)];
    }

    public static function recordManual(int $offeringId, int $studentId, string $date, string $status, int $actorId, string $reason, ?string $timeIn = null): array
    {
        self::assertOfferingAccess($offeringId, $actorId);
        if (!in_array($status, ['Present', 'Late', 'Absent', 'Excused'], true)) {
            throw new DomainException('Invalid attendance status.');
        }
        if (trim($reason) === '') {
            throw new DomainException('A correction reason is required.');
        }
        $timeIn = in_array($status, ['Present', 'Late'], true) && trim((string) $timeIn) !== ''
            ? normalize_time((string) $timeIn)
            : null;
        $enrollment = Database::connection()->prepare('SELECT id FROM class_enrollments WHERE class_offering_id = :offering AND student_id = :student AND status = "enrolled"');
        $enrollment->execute(['offering' => $offeringId, 'student' => $studentId]);
        if (!$enrollment->fetchColumn()) throw new DomainException('The selected student is not enrolled in that class.');
        AcademicCalendarService::scheduleForOfferingDate($offeringId, $date);
        $sessionId = self::ensureSession($offeringId, $date);
        $statement = Database::connection()->prepare(
            'INSERT INTO attendance_records
                (attendance_session_id, class_offering_id, student_id, attendance_date, time_in, status, source, recorded_by, correction_reason)
             VALUES (:session_id, :offering_id, :student_id, :attendance_date, :time_in, :status, "manual", :actor_id, :reason)
             ON DUPLICATE KEY UPDATE time_in = VALUES(time_in), status = VALUES(status), source = "manual",
                                     recorded_by = VALUES(recorded_by), correction_reason = VALUES(correction_reason)'
        );
        $statement->execute([
            'session_id' => $sessionId,
            'offering_id' => $offeringId,
            'student_id' => $studentId,
            'attendance_date' => $date,
            'time_in' => $timeIn,
            'status' => $status,
            'actor_id' => $actorId,
            'reason' => $reason,
        ]);
        AuditService::log($actorId, 'correct_attendance', 'class_offering', $offeringId, "Student {$studentId}: {$status}; {$reason}");
        NotificationService::afterAttendance($offeringId, $studentId);
        return self::studentAttendance($offeringId, $studentId, $date, $actorId);
    }

    public static function finalize(int $offeringId, string $date, int $actorId): int
    {
        self::assertOfferingAccess($offeringId, $actorId);
        return self::finalizeSession($offeringId, $date, $actorId);
    }

    public static function autoFinalizeEndedForRoom(int $roomId, string $date, string $time): int
    {
        if ($roomId < 1) return 0;
        $day = (new DateTimeImmutable($date))->format('l');
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'SELECT DISTINCT offerings.id
             FROM class_offerings offerings
             JOIN semesters ON semesters.id = offerings.semester_id
             JOIN class_schedules schedules ON schedules.class_offering_id = offerings.id
             LEFT JOIN attendance_sessions sessions ON sessions.class_offering_id = offerings.id AND sessions.attendance_date = :session_date
             WHERE offerings.status = "active" AND :academic_date BETWEEN semesters.starts_on AND semesters.ends_on
               AND schedules.day_of_week = :day
               AND COALESCE(schedules.room_id, offerings.room_id) = :room
               AND schedules.end_time <= :current_time
               AND sessions.finalized_at IS NULL'
        );
        $statement->execute(['session_date' => $date, 'academic_date' => $date, 'day' => $day, 'room' => $roomId, 'current_time' => $time]);
        $finalized = 0;
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $offeringId) {
            try {
                self::finalizeSession((int) $offeringId, $date, null);
                $finalized++;
            } catch (DomainException) {
                // Days off, cancelled meetings, and invalid calendar dates do not create absences.
            }
        }
        return $finalized;
    }

    private static function finalizeSession(int $offeringId, string $date, ?int $actorId): int
    {
        $pdo = Database::connection();
        $schedule = AcademicCalendarService::scheduleForOfferingDate($offeringId, $date);
        $sessionId = self::ensureSession($offeringId, $date);
        $statement = $pdo->prepare(
            'INSERT INTO attendance_records
                (attendance_session_id, class_offering_id, student_id, attendance_date, status, source, recorded_by, notes)
             SELECT :session_id, enrollments.class_offering_id, enrollments.student_id, :attendance_date,
                    "Absent", "system", :actor_id, "Marked absent when attendance was finalized"
             FROM class_enrollments AS enrollments
             LEFT JOIN attendance_records AS records
               ON records.class_offering_id = enrollments.class_offering_id
              AND records.student_id = enrollments.student_id
              AND records.attendance_date = :record_date
             WHERE enrollments.class_offering_id = :offering_id
               AND enrollments.status = "enrolled"
               AND records.id IS NULL
               AND NOT EXISTS (
                    SELECT 1
                    FROM events
                    JOIN event_participants ON event_participants.event_id = events.id
                    WHERE event_participants.user_id = enrollments.student_id
                      AND events.suspends_classes = 1
                      AND events.status <> "cancelled"
                      AND :suspension_date BETWEEN events.event_date AND COALESCE(events.end_date, events.event_date)
                      AND (
                        events.event_date <> COALESCE(events.end_date, events.event_date)
                        OR (events.start_time < :class_end AND events.end_time > :class_start)
                      )
               )'
        );
        $statement->execute([
            'session_id' => $sessionId,
            'attendance_date' => $date,
            'actor_id' => $actorId,
            'record_date' => $date,
            'offering_id' => $offeringId,
            'suspension_date' => $date,
            'class_start' => $schedule['start_time'],
            'class_end' => $schedule['end_time'],
        ]);
        $created = $statement->rowCount();
        $pdo->prepare('UPDATE attendance_sessions SET finalized_at = NOW(), finalized_by = :actor WHERE id = :id')
            ->execute(['actor' => $actorId, 'id' => $sessionId]);
        NotificationService::afterAttendance($offeringId);
        if ($actorId !== null) {
            AuditService::log($actorId, 'finalize_attendance', 'attendance_session', $sessionId, "Created {$created} absence records");
        }
        return $created;
    }

    public static function calendar(int $offeringId, int $actorId, string $month): array
    {
        self::assertOfferingAccess($offeringId, $actorId);
        $monthDate = DateTimeImmutable::createFromFormat('!Y-m', $month);
        if (!$monthDate || $monthDate->format('Y-m') !== $month) throw new DomainException('Select a valid calendar month.');
        $startsOn = $monthDate->format('Y-m-01');
        $endsBefore = $monthDate->modify('first day of next month')->format('Y-m-d');
        $statement = Database::connection()->prepare(
            'SELECT attendance_sessions.attendance_date, attendance_sessions.finalized_at,
                    SUM(attendance_records.status = "Present") present_count,
                    SUM(attendance_records.status = "Late") late_count,
                    SUM(attendance_records.status = "Absent") absent_count,
                    SUM(attendance_records.status = "Excused") excused_count,
                    COUNT(attendance_records.id) recorded_count
             FROM attendance_sessions
             LEFT JOIN attendance_records ON attendance_records.attendance_session_id = attendance_sessions.id
             WHERE attendance_sessions.class_offering_id = :offering_id
               AND attendance_sessions.attendance_date >= :starts_on
               AND attendance_sessions.attendance_date < :ends_before
             GROUP BY attendance_sessions.id, attendance_sessions.attendance_date, attendance_sessions.finalized_at
             ORDER BY attendance_sessions.attendance_date'
        );
        $statement->execute(['offering_id' => $offeringId, 'starts_on' => $startsOn, 'ends_before' => $endsBefore]);
        $sessions = array_map(static function (array $session): array {
            foreach (['present_count', 'late_count', 'absent_count', 'excused_count', 'recorded_count'] as $field) {
                $session[$field] = (int) $session[$field];
            }
            $session['finalized'] = $session['finalized_at'] !== null;
            return $session;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
        return ['month' => $month, 'sessions' => $sessions];
    }

    public static function updateSettings(int $earlyMinutes, int $graceMinutes, int $absentAfterMinutes, int $tardiesPerAbsence, int $actorId): array
    {
        if (!self::hasGlobalPolicyAccess($actorId)) throw new DomainException('Only an authorized campus role can change the default attendance policy.');
        self::validatePolicy($earlyMinutes, $graceMinutes, $absentAfterMinutes, $tardiesPerAbsence);
        Database::connection()->prepare(
            'UPDATE attendance_settings
             SET early_check_in_minutes = :early, grace_period_minutes = :grace, absent_after_minutes = :absent_after,
                 tardies_per_absence = :tardies, updated_by = :actor
             WHERE id = 1'
        )->execute(['early' => $earlyMinutes, 'grace' => $graceMinutes, 'absent_after' => $absentAfterMinutes, 'tardies' => $tardiesPerAbsence, 'actor' => $actorId]);
        AuditService::log($actorId, 'update_attendance_settings', 'attendance_settings', 1, "Opens {$earlyMinutes}m early; present through {$graceMinutes}m; absent at {$absentAfterMinutes}m; {$tardiesPerAbsence} tardies per absence");
        return ['early_check_in_minutes' => $earlyMinutes, 'grace_period_minutes' => $graceMinutes, 'absent_after_minutes' => $absentAfterMinutes, 'tardies_per_absence' => $tardiesPerAbsence];
    }

    public static function policy(int $offeringId, int $actorId): array
    {
        self::assertPolicyAccess($offeringId, $actorId);
        $statement = Database::connection()->prepare(
            'SELECT class_offerings.id class_offering_id, class_offerings.component_type, courses.course_code, courses.title course_title,
                    sections.name section_name,
                    class_attendance_policies.early_check_in_minutes override_early_check_in_minutes,
                    class_attendance_policies.grace_period_minutes override_grace_period_minutes,
                    class_attendance_policies.absent_after_minutes override_absent_after_minutes,
                    class_attendance_policies.tardies_per_absence override_tardies_per_absence,
                    class_attendance_policies.updated_at override_updated_at,
                    CONCAT_WS(" ", users.first_name, users.middle_name, users.last_name) override_updated_by
             FROM class_offerings
             JOIN courses ON courses.id = class_offerings.course_id
             JOIN sections ON sections.id = class_offerings.section_id
             LEFT JOIN class_attendance_policies ON class_attendance_policies.class_offering_id = class_offerings.id
             LEFT JOIN users ON users.id = class_attendance_policies.updated_by
             WHERE class_offerings.id = :offering_id LIMIT 1'
        );
        $statement->execute(['offering_id' => $offeringId]);
        $subject = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$subject) throw new DomainException('The selected subject could not be found.');
        $global = Database::connection()->query('SELECT early_check_in_minutes, grace_period_minutes, absent_after_minutes, tardies_per_absence FROM attendance_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        $hasOverride = $subject['override_grace_period_minutes'] !== null;
        $effective = $hasOverride ? [
            'early_check_in_minutes' => (int) $subject['override_early_check_in_minutes'],
            'grace_period_minutes' => (int) $subject['override_grace_period_minutes'],
            'absent_after_minutes' => (int) $subject['override_absent_after_minutes'],
            'tardies_per_absence' => (int) $subject['override_tardies_per_absence'],
        ] : array_map('intval', $global);
        return [
            'subject' => [
                'id' => (int) $subject['class_offering_id'],
                'course_code' => $subject['course_code'],
                'course_title' => $subject['course_title'],
                'section_name' => $subject['section_name'],
            ],
            'uses_campus_default' => !$hasOverride,
            'campus' => array_map('intval', $global),
            'effective' => $effective,
            'updated_at' => $subject['override_updated_at'],
            'updated_by' => $subject['override_updated_by'],
        ];
    }

    public static function updateSubjectPolicy(int $offeringId, bool $useCampusDefault, int $earlyMinutes, int $graceMinutes, int $absentAfterMinutes, int $tardiesPerAbsence, int $actorId): array
    {
        self::assertPolicyAccess($offeringId, $actorId);
        $pdo = Database::connection();
        if ($useCampusDefault) {
            $pdo->prepare('DELETE FROM class_attendance_policies WHERE class_offering_id = :offering_id')->execute(['offering_id' => $offeringId]);
            AuditService::log($actorId, 'reset_class_attendance_policy', 'class_offering', $offeringId, 'Restored campus defaults');
        } else {
            self::validatePolicy($earlyMinutes, $graceMinutes, $absentAfterMinutes, $tardiesPerAbsence);
            $pdo->prepare(
                'INSERT INTO class_attendance_policies
                    (class_offering_id, early_check_in_minutes, grace_period_minutes, absent_after_minutes, tardies_per_absence, updated_by)
                 VALUES (:offering_id, :early, :grace, :absent_after, :tardies, :actor)
                 ON DUPLICATE KEY UPDATE early_check_in_minutes = VALUES(early_check_in_minutes), grace_period_minutes = VALUES(grace_period_minutes),
                    absent_after_minutes = VALUES(absent_after_minutes), tardies_per_absence = VALUES(tardies_per_absence),
                    updated_by = VALUES(updated_by)'
            )->execute(['offering_id' => $offeringId, 'early' => $earlyMinutes, 'grace' => $graceMinutes, 'absent_after' => $absentAfterMinutes, 'tardies' => $tardiesPerAbsence, 'actor' => $actorId]);
            AuditService::log($actorId, 'update_class_attendance_policy', 'class_offering', $offeringId, "Opens {$earlyMinutes}m early; present through {$graceMinutes}m; absent at {$absentAfterMinutes}m; {$tardiesPerAbsence} tardies per absence");
        }
        return self::policy($offeringId, $actorId);
    }

    public static function offeringSelectorRows(int $actorId): array
    {
        [$accessSql, $params] = self::accessScope($actorId);
        $statement = Database::connection()->prepare(
            'SELECT class_offerings.id, courses.id course_id, courses.course_code, courses.title course_title,
                    programs.id program_id, programs.code program_code, programs.name program_name,
                    sections.id section_id, sections.name section_name, sections.year_level, sections.section_code,
                    school_years.id school_year_id, school_years.name school_year_name,
                    semesters.id semester_id, semesters.name semester_name
             FROM class_offerings
             JOIN courses ON courses.id = class_offerings.course_id
             JOIN sections ON sections.id = class_offerings.section_id
             JOIN programs ON programs.id = sections.program_id
             JOIN semesters ON semesters.id = class_offerings.semester_id
             JOIN school_years ON school_years.id = semesters.school_year_id
             WHERE class_offerings.status = "active" AND ' . $accessSql . '
             ORDER BY courses.course_code, programs.code, sections.year_level, sections.section_code, school_years.starts_on DESC, semesters.starts_on'
        );
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function studentAttendance(int $offeringId, int $studentId, string $date, int $actorId): array
    {
        self::assertOfferingAccess($offeringId, $actorId);
        $statement = Database::connection()->prepare(
            'SELECT users.id student_id, users.school_id, users.first_name, users.middle_name, users.last_name,
                    users.profile_image_path, attendance_records.id record_id, attendance_records.time_in,
                    attendance_records.time_out, COALESCE(attendance_records.status, "Unrecorded") status,
                    attendance_records.source, attendance_records.correction_reason, attendance_records.updated_at
             FROM users
             JOIN class_enrollments ON class_enrollments.student_id = users.id AND class_enrollments.class_offering_id = :offering_id
             LEFT JOIN attendance_records ON attendance_records.class_offering_id = class_enrollments.class_offering_id
                AND attendance_records.student_id = users.id AND attendance_records.attendance_date = :attendance_date
             WHERE users.id = :student_id AND class_enrollments.status = "enrolled"'
        );
        $statement->execute(['offering_id' => $offeringId, 'student_id' => $studentId, 'attendance_date' => $date]);
        $student = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$student) throw new DomainException('The selected student is not enrolled in that class.');
        return self::normalizeAttendanceStudent($student);
    }

    private static function normalizeAttendanceStudent(array $student): array
    {
        $student['student_id'] = (int) $student['student_id'];
        $student['record_id'] = $student['record_id'] !== null ? (int) $student['record_id'] : null;
        $student['student_name'] = trim(implode(' ', array_filter([$student['first_name'], $student['middle_name'] ?? null, $student['last_name']])));
        return $student;
    }

    public static function assertOfferingAccess(int $offeringId, int $actorId): void
    {
        if ($offeringId < 1) throw new DomainException('Select a valid subject.');
        [$accessSql, $params] = self::accessScope($actorId);
        $params['offering_id'] = $offeringId;
        $statement = Database::connection()->prepare(
            'SELECT class_offerings.id FROM class_offerings
             WHERE class_offerings.id = :offering_id AND ' . $accessSql . ' LIMIT 1'
        );
        $statement->execute($params);
        if (!$statement->fetchColumn()) throw new DomainException('You do not have access to manage attendance for that subject.');
    }

    public static function assertPolicyAccess(int $offeringId, int $actorId): void
    {
        if ($offeringId < 1) throw new DomainException('Select a valid subject.');
        $global = self::hasGlobalPolicyAccess($actorId);
        $statement = Database::connection()->prepare(
            'SELECT class_offerings.id FROM class_offerings
             WHERE class_offerings.id = :offering_id
               AND (:global_access = 1 OR EXISTS(
                    SELECT 1 FROM teacher_assignments
                    WHERE teacher_assignments.class_offering_id = class_offerings.id
                      AND teacher_assignments.teacher_id = :actor_id
               )) LIMIT 1'
        );
        $statement->execute(['offering_id' => $offeringId, 'global_access' => $global ? 1 : 0, 'actor_id' => $actorId]);
        if (!$statement->fetchColumn()) throw new DomainException('You cannot configure attendance rules for that subject.');
    }

    public static function resolvedPolicy(int $offeringId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT COALESCE(class_attendance_policies.early_check_in_minutes, attendance_settings.early_check_in_minutes) early_check_in_minutes,
                    COALESCE(class_attendance_policies.grace_period_minutes, attendance_settings.grace_period_minutes) grace_period_minutes,
                    COALESCE(class_attendance_policies.absent_after_minutes, attendance_settings.absent_after_minutes) absent_after_minutes,
                    COALESCE(class_attendance_policies.tardies_per_absence, attendance_settings.tardies_per_absence) tardies_per_absence
             FROM attendance_settings
             LEFT JOIN class_attendance_policies ON class_attendance_policies.class_offering_id = :offering_id
             WHERE attendance_settings.id = 1'
        );
        $statement->execute(['offering_id' => $offeringId]);
        $policy = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$policy) throw new DomainException('Attendance policy settings are unavailable.');
        return array_map('intval', $policy);
    }

    private static function validatePolicy(int $earlyMinutes, int $graceMinutes, int $absentAfterMinutes, int $tardiesPerAbsence): void
    {
        if ($earlyMinutes < 0 || $earlyMinutes > 60 || $graceMinutes < 0 || $graceMinutes > 120 || $absentAfterMinutes < 1 || $absentAfterMinutes > 240 || $absentAfterMinutes <= $graceMinutes || $tardiesPerAbsence < 1 || $tardiesPerAbsence > 20) {
            throw new DomainException('Absent-after minutes must be greater than the present grace period. Check all attendance rule values.');
        }
    }

    private static function accessScope(int $actorId): array
    {
        if (self::hasGlobalAccess($actorId)) return ['1 = 1', []];
        return [
            'EXISTS(SELECT 1 FROM teacher_assignments attendance_scope WHERE attendance_scope.class_offering_id = class_offerings.id AND attendance_scope.teacher_id = :attendance_actor_id)',
            ['attendance_actor_id' => $actorId],
        ];
    }

    private static function ensureSession(int $offeringId, string $date): int
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'INSERT INTO attendance_sessions (class_offering_id, attendance_date)
             VALUES (:offering_id, :attendance_date)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)'
        );
        $statement->execute(['offering_id' => $offeringId, 'attendance_date' => $date]);
        return (int) $pdo->lastInsertId();
    }

    public static function studentAttendanceProfile(int $studentId, int $actorId): array
    {
        $pdo = Database::connection();
        $studentStmt = $pdo->prepare(
            'SELECT users.id, users.school_id, users.first_name, users.middle_name, users.last_name, users.email,
                    users.profile_image_path, users.status, roles.name role_name,
                    departments.id department_id, departments.code department_code, departments.name department_name,
                    programs.id program_id, programs.code program_code, programs.name program_name,
                    sections.id section_id, sections.name section_name, sections.year_level,
                    rfid_cards.uid rfid_uid, rfid_cards.status rfid_status
             FROM users
             JOIN roles ON roles.id = users.role_id
             LEFT JOIN departments ON departments.id = users.department_id
             LEFT JOIN programs ON programs.id = users.program_id
             LEFT JOIN sections ON sections.id = users.section_id
             LEFT JOIN rfid_cards ON rfid_cards.user_id = users.id
             WHERE users.id = :id LIMIT 1'
        );
        $studentStmt->execute(['id' => $studentId]);
        $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$student) {
            throw new DomainException('Student not found.');
        }

        $classesStmt = $pdo->prepare(
            'SELECT class_offerings.id class_offering_id, courses.course_code, courses.title course_title,
                    sections.name section_name, rooms.room_code,
                    (SELECT GROUP_CONCAT(CONCAT(users.first_name, " ", users.last_name) SEPARATOR ", ")
                     FROM teacher_assignments JOIN users ON users.id = teacher_assignments.teacher_id
                     WHERE teacher_assignments.class_offering_id = class_offerings.id) teacher_names,
                    (SELECT COUNT(*) FROM attendance_records WHERE attendance_records.class_offering_id = class_offerings.id AND attendance_records.student_id = class_enrollments.student_id AND attendance_records.status = "Present") present_count,
                    (SELECT COUNT(*) FROM attendance_records WHERE attendance_records.class_offering_id = class_offerings.id AND attendance_records.student_id = class_enrollments.student_id AND attendance_records.status = "Late") late_count,
                    (SELECT COUNT(*) FROM attendance_records WHERE attendance_records.class_offering_id = class_offerings.id AND attendance_records.student_id = class_enrollments.student_id AND attendance_records.status = "Absent") absent_count,
                    (SELECT COUNT(*) FROM attendance_records WHERE attendance_records.class_offering_id = class_offerings.id AND attendance_records.student_id = class_enrollments.student_id AND attendance_records.status = "Excused") excused_count
             FROM class_enrollments
             JOIN class_offerings ON class_offerings.id = class_enrollments.class_offering_id
             JOIN courses ON courses.id = class_offerings.course_id
             JOIN sections ON sections.id = class_offerings.section_id
             LEFT JOIN rooms ON rooms.id = class_offerings.room_id
             WHERE class_enrollments.student_id = :student_id AND class_enrollments.status = "enrolled" AND class_offerings.status = "active"
             ORDER BY courses.course_code'
        );
        $classesStmt->execute(['student_id' => $studentId]);
        $enrolledClasses = $classesStmt->fetchAll(PDO::FETCH_ASSOC);

        $recentStmt = $pdo->prepare(
            'SELECT attendance_records.id, attendance_records.class_offering_id, courses.course_code, courses.title course_title,
                    attendance_records.attendance_date, attendance_records.time_in, attendance_records.time_out,
                    attendance_records.status, attendance_records.source, attendance_records.notes, attendance_records.correction_reason
             FROM attendance_records
             JOIN class_offerings ON class_offerings.id = attendance_records.class_offering_id
             JOIN courses ON courses.id = class_offerings.course_id
             WHERE attendance_records.student_id = :student_id
             ORDER BY attendance_records.attendance_date DESC, attendance_records.created_at DESC
             LIMIT 10'
        );
        $recentStmt->execute(['student_id' => $studentId]);
        $recentRecords = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

        $compliance = ComplianceService::forStudent($studentId);

        return [
            'student' => $student,
            'classes' => $enrolledClasses,
            'recent_records' => $recentRecords,
            'compliance' => $compliance,
        ];
    }
}
