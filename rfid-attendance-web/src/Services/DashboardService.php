<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Auth;

final class DashboardService
{
    public static function admin(): array
    {
        $pdo = Database::connection();
        $scalar = static fn (string $sql): int => (int) $pdo->query($sql)->fetchColumn();
        $canManageUsers = Auth::can('manage_users');
        $canViewEvents = Auth::canAny(['view_events','manage_events']);
        $viewerId = (int)Auth::user()['id'];
        return [
            'students' => $canManageUsers ? $scalar('SELECT COUNT(DISTINCT users.id) FROM users JOIN user_roles ON user_roles.user_id = users.id AND user_roles.role_id = 3 WHERE users.status = "active"') : null,
            'teachers' => $canManageUsers ? $scalar('SELECT COUNT(DISTINCT users.id) FROM users JOIN user_roles ON user_roles.user_id = users.id AND user_roles.role_id = 2 WHERE users.status = "active"') : null,
            'staff' => $canManageUsers ? $scalar('SELECT COUNT(DISTINCT users.id) FROM users JOIN user_roles ON user_roles.user_id = users.id AND user_roles.role_id IN (4, 5) WHERE users.status = "active"') : null,
            'rfid_cards' => Auth::can('manage_rfid') ? $scalar('SELECT COUNT(*) FROM rfid_cards WHERE status = "active"') : null,
            'rooms' => Auth::can('manage_rooms') ? $scalar('SELECT COUNT(*) FROM rooms WHERE status = "active"') : null,
            'upcoming_events' => Auth::can('manage_events') ? $scalar('SELECT COUNT(*) FROM events WHERE COALESCE(end_date, event_date) >= CURRENT_DATE AND status NOT IN ("cancelled", "closed")') : null,
            'upcoming_recollections' => Auth::can('manage_recollections') ? $scalar('SELECT COUNT(*) FROM recollections WHERE COALESCE(end_date,date) >= CURRENT_DATE AND status NOT IN ("cancelled", "closed")') : null,
            'deficient_students' => Auth::canAny(Auth::STUDENT_COMPLIANCE_PERMISSIONS) ? ComplianceService::countDeficient() : null,
            'recent_attendance' => AttendanceService::hasGlobalAccess($viewerId) ? $pdo->query(
                'SELECT attendance_records.*, CONCAT(users.first_name, " ", users.last_name) student_name, courses.course_code
                 FROM attendance_records JOIN users ON users.id = attendance_records.student_id
                 JOIN class_offerings ON class_offerings.id = attendance_records.class_offering_id
                 JOIN courses ON courses.id = class_offerings.course_id
                 ORDER BY attendance_records.updated_at DESC LIMIT 8'
            )->fetchAll() : [],
            'calendar_sections' => Auth::can('manage_events') ? $pdo->query('SELECT id, name FROM sections WHERE status = "active" ORDER BY name')->fetchAll() : [],
            'calendar_devices' => Auth::can('manage_events') ? $pdo->query('SELECT devices.id, devices.device_code, devices.name, devices.room_id, rooms.room_code FROM devices LEFT JOIN rooms ON rooms.id = devices.room_id WHERE devices.status = "active" ORDER BY rooms.room_code, devices.device_code')->fetchAll() : [],
            'calendar_rooms' => Auth::can('manage_events') ? $pdo->query('SELECT id, room_code, room_name FROM rooms WHERE status = "active" ORDER BY room_code')->fetchAll() : [],
            'department_day_policies' => AcademicCalendarService::policies(),
            'eventFeed' => $canViewEvents ? EventService::feed($viewerId, null, 50) : [],
            'categorizedEvents' => $canViewEvents ? EventService::categorized($viewerId) : [],
            'sections' => Auth::can('manage_events') ? $pdo->query('SELECT id, name FROM sections WHERE status = "active" ORDER BY name')->fetchAll() : [],
        ];
    }
}
