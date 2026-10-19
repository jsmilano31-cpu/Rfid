<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DomainException;
use PDO;

final class RfidDeviceService
{
    public static function rotateKey(int $deviceId, int $actorId): array
    {
        $device = RoomService::findDevice($deviceId);
        if (!$device) throw new DomainException('The selected scanner could not be found.');
        $key = bin2hex(random_bytes(32));
        Database::connection()->prepare('UPDATE devices SET api_key_hash = :hash WHERE id = :id')
            ->execute(['hash' => password_hash($key, PASSWORD_DEFAULT), 'id' => $deviceId]);
        AuditService::log($actorId, 'rotate_scanner_key', 'device', $deviceId, (string) $device['device_code']);
        return ['device' => RoomService::findDevice($deviceId), 'api_key' => $key];
    }

    public static function authenticate(string $deviceCode, string $apiKey): array
    {
        $statement = Database::connection()->prepare(
            'SELECT devices.*, rooms.room_code, rooms.room_name, rooms.status room_status
             FROM devices LEFT JOIN rooms ON rooms.id = devices.room_id
             WHERE devices.device_code = :code LIMIT 1'
        );
        $statement->execute(['code' => strtoupper(trim($deviceCode))]);
        $device = $statement->fetch(PDO::FETCH_ASSOC);
        $valid = $device && $device['api_key_hash'] && password_verify($apiKey, $device['api_key_hash']);
        if (!$valid) throw new DomainException('Scanner authentication failed.');
        if ($device['status'] !== 'active') throw new DomainException('This scanner is not active.');
        \App\Core\AuditContext::configure(Database::connection(), 0, 'Scanner ' . $device['device_code']);
        Database::connection()->prepare('UPDATE devices SET last_seen_at = NOW() WHERE id = :id')->execute(['id' => $device['id']]);
        return $device;
    }

    public static function currentOffering(array $device, string $date, string $time): int
    {
        $schedule = self::scheduleStatus($device, $date, $time);
        if ($schedule['current_class']) {
            return (int) $schedule['current_class']['class_offering_id'];
        }
        if ($schedule['next_class']) {
            $next = $schedule['next_class'];
            throw new \App\Exceptions\ScanRejection("Attendance is not open yet. {$next['course_code']} starts at {$next['start_time']}.", 'window_not_open');
        }
        throw new \App\Exceptions\ScanRejection('No class is scheduled in this room right now.', 'no_scheduled_class');
    }

    public static function scheduleStatus(array $device, string $date, string $time): array
    {
        if (empty($device['room_id']) || ($device['room_status'] ?? null) !== 'active') throw new \App\Exceptions\ScanRejection('This scanner is not assigned to an active room.', 'room_unavailable');
        $holiday = AcademicCalendarService::campusHoliday($date);
        if ($holiday !== null) throw new \App\Exceptions\ScanRejection("No classes on {$date}: {$holiday}.", 'campus_holiday');
        AttendanceService::autoFinalizeEndedForRoom((int) $device['room_id'], $date, $time);
        $day = (new \DateTimeImmutable($date))->format('l');
        $pdo = Database::connection();
        $baseQuery =
            ' FROM class_offerings offerings
             JOIN courses ON courses.id = offerings.course_id
             JOIN semesters ON semesters.id = offerings.semester_id
             JOIN school_years ON school_years.id = semesters.school_year_id
             JOIN class_schedules schedules ON schedules.class_offering_id = offerings.id
             WHERE offerings.status = "active" AND :date BETWEEN semesters.starts_on AND semesters.ends_on
               AND :date_year BETWEEN school_years.starts_on AND school_years.ends_on
               AND schedules.day_of_week = :day
               AND COALESCE(schedules.room_id, offerings.room_id) = :room';
        $parameters = ['date' => $date, 'date_year' => $date, 'day' => $day, 'room' => (int) $device['room_id']];
        $statement = $pdo->prepare(
            'SELECT DISTINCT offerings.id class_offering_id, offerings.component_type, courses.course_code,
                    schedules.day_of_week, schedules.start_time, schedules.end_time,
                    (SELECT sessions.finalized_at FROM attendance_sessions sessions
                     WHERE sessions.class_offering_id = offerings.id AND sessions.attendance_date = :session_date LIMIT 1) finalized_at' . $baseQuery .
            ' AND :scan_time_start >= schedules.start_time AND :scan_time_end < schedules.end_time
              ORDER BY schedules.start_time LIMIT 2'
        );
        $statement->execute($parameters + ['session_date' => $date, 'scan_time_start' => $time, 'scan_time_end' => $time]);
        $current = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($current) > 1) throw new \App\Exceptions\ScanRejection('Multiple classes are scheduled in this room. Correct the timetable before scanning.', 'schedule_overlap');

        $nextStatement = $pdo->prepare(
            'SELECT DISTINCT offerings.id class_offering_id, offerings.component_type, courses.course_code,
                    schedules.day_of_week, schedules.start_time, schedules.end_time' . $baseQuery .
            ' AND schedules.start_time > :next_scan_time
              ORDER BY schedules.start_time LIMIT 1'
        );
        $nextStatement->execute($parameters + ['next_scan_time' => $time]);
        $next = $nextStatement->fetch(PDO::FETCH_ASSOC) ?: null;

        $selected = $current[0] ?? null;
        if ($selected && $selected['finalized_at'] !== null) $selected = null;
        if (!$selected && $next) {
            $policy = AttendanceService::resolvedPolicy((int) $next['class_offering_id']);
            $opensAt = (new \DateTimeImmutable("{$date} {$next['start_time']}"))->modify('-' . (int) ($policy['early_check_in_minutes'] ?? 10) . ' minutes');
            if (new \DateTimeImmutable("{$date} {$time}") >= $opensAt) {
                $next['attendance_phase'] = 'early';
                $selected = $next;
                $next = null;
            }
        }
        if ($selected && !isset($selected['attendance_phase'])) $selected['attendance_phase'] = 'active';

        return [
            'current_class' => $selected ? self::formatClassSchedule($selected) : null,
            'next_class' => $next ? self::formatClassSchedule($next) : null,
        ];
    }

    private static function formatClassSchedule(array $schedule): array
    {
        return [
            'class_offering_id' => (int) $schedule['class_offering_id'],
            'course_code' => (string) $schedule['course_code'],
            'component_type' => (string) ($schedule['component_type'] ?? 'LEC'),
            'attendance_phase' => (string) ($schedule['attendance_phase'] ?? 'scheduled'),
            'day' => (string) $schedule['day_of_week'],
            'start_time' => substr((string) $schedule['start_time'], 0, 5),
            'end_time' => substr((string) $schedule['end_time'], 0, 5),
        ];
    }

    public static function log(int $deviceId, ?string $uid, string $outcome, string $message, ?int $offeringId = null, ?int $studentId = null, ?string $reasonCode = null): void
    {
        if ($reasonCode !== null) {
            static $codes = null;
            $codes ??= (bool) Database::connection()->query('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="device_scan_logs" AND COLUMN_NAME="reason_code"')->fetchColumn();
            if ($codes) {
                Database::connection()->prepare('INSERT INTO device_scan_logs(device_id,uid,outcome,message,class_offering_id,student_id,reason_code) VALUES(?,?,?,?,?,?,?)')->execute([$deviceId,$uid,$outcome,mb_substr($message,0,255),$offeringId,$studentId,$reasonCode]);
                return;
            }
        }
        Database::connection()->prepare(
            'INSERT INTO device_scan_logs (device_id, uid, outcome, message, class_offering_id, student_id)
             VALUES (:device, :uid, :outcome, :message, :offering, :student)'
        )->execute(['device' => $deviceId, 'uid' => $uid, 'outcome' => $outcome, 'message' => mb_substr($message, 0, 255), 'offering' => $offeringId, 'student' => $studentId]);
    }
}
