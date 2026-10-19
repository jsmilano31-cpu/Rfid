<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class DashboardService
{
    public static function admin(): array
    {
        $pdo = Database::connection();
        $scalar = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
        return [
            'students' => $scalar('SELECT COUNT(DISTINCT users.id) FROM users JOIN user_roles ON user_roles.user_id = users.id AND user_roles.role_id = 3 WHERE users.status = "active"'),
            'teachers' => $scalar('SELECT COUNT(DISTINCT users.id) FROM users JOIN user_roles ON user_roles.user_id = users.id AND user_roles.role_id = 2 WHERE users.status = "active"'),
            'staff' => $scalar('SELECT COUNT(DISTINCT users.id) FROM users JOIN user_roles ON user_roles.user_id = users.id AND user_roles.role_id IN (4, 5) WHERE users.status = "active"'),
            'rfid_cards' => $scalar('SELECT COUNT(*) FROM rfid_cards WHERE status = "active"'),
            'rooms' => $scalar('SELECT COUNT(*) FROM rooms WHERE status = "active"'),
            'upcoming_events' => $scalar('SELECT COUNT(*) FROM events WHERE COALESCE(end_date, event_date) >= CURRENT_DATE AND status NOT IN ("cancelled", "closed")'),
            'upcoming_recollections' => $scalar('SELECT COUNT(*) FROM recollections WHERE date >= CURRENT_DATE AND status NOT IN ("cancelled", "closed")'),
            'deficient_students' => ComplianceService::countDeficient(),
            'recent_attendance' => $pdo->query(
                'SELECT attendance_records.*, CONCAT(users.first_name, " ", users.last_name) student_name, courses.course_code
                 FROM attendance_records JOIN users ON users.id = attendance_records.student_id
                 JOIN class_offerings ON class_offerings.id = attendance_records.class_offering_id
                 JOIN courses ON courses.id = class_offerings.course_id
                 ORDER BY attendance_records.updated_at DESC LIMIT 8'
            )->fetchAll(),
            'calendar_sections' => $pdo->query('SELECT id, name FROM sections WHERE status = "active" ORDER BY name')->fetchAll(),
            'calendar_devices' => $pdo->query('SELECT devices.id, devices.device_code, devices.name, devices.room_id, rooms.room_code FROM devices LEFT JOIN rooms ON rooms.id = devices.room_id WHERE devices.status = "active" ORDER BY rooms.room_code, devices.device_code')->fetchAll(),
            'calendar_rooms' => $pdo->query('SELECT id, room_code, room_name FROM rooms WHERE status = "active" ORDER BY room_code')->fetchAll(),
            'department_day_policies' => AcademicCalendarService::policies(),
            'eventFeed' => EventService::feed((int) (\App\Core\Auth::user()['id'] ?? 1), null, 50),
            'categorizedEvents' => EventService::categorized((int) (\App\Core\Auth::user()['id'] ?? 1)),
            'sections' => $pdo->query('SELECT id, name FROM sections WHERE status = "active" ORDER BY name')->fetchAll(),
        ];
    }
}
