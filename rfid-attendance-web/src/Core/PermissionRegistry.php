<?php
declare(strict_types=1);
namespace App\Core;

use DomainException;

/** Shared page and form authorization. Record ownership/scope checks remain mandatory. */
final class PermissionRegistry
{
    public const SELF_SERVICE = [
        'view_dashboard' => 'Open the overview; each panel also requires its domain permission',
        'view_schedule' => 'View permitted class schedules, rooms, and the campus calendar',
        'view_class_rosters' => 'View student identities in permitted class rosters',
        'view_events' => 'View accessible events, announcements, and event calendar entries',
        'view_recollections' => 'View accessible recollections, retreats, and calendar entries',
        'view_people' => 'Search the people directory and open permitted profiles',
        'view_messages' => 'Read, star, mark read or unread, trash, restore, and download attachments in your own mailbox',
        'use_search' => 'Use universal search; results also require their destination permissions',
        'manage_own_profile' => 'View and update your own contact details, picture, privacy, and password',
        'manage_own_settings' => 'View and change your own appearance, accessibility, and notification preferences',
        'manage_own_notifications' => 'View and mark your own notifications and register or revoke your push device',
    ];

    public const ACTIONS = [
        'create_user'=>['manage_users'],
        'create_role'=>['manage_roles'], 'update_role_metadata'=>['manage_roles'],
        'delete_role'=>['manage_roles'], 'update_role_permissions'=>['manage_roles'],
        'create_course'=>['manage_academic_structure'], 'create_section'=>['manage_academic_structure'],
        'toggle_course'=>['manage_academic_structure'], 'toggle_section'=>['manage_academic_structure'],
        'create_offering'=>['manage_academic_structure','manage_teacher_assignments'],
        'update_department_days_off'=>['manage_academic_calendar','manage_attendance_policies'],
        'update_academic_calendar'=>['manage_academic_calendar','manage_attendance_policies'],
        'create_room'=>['manage_rooms'], 'create_device'=>['manage_rooms'],
        'toggle_room'=>['manage_rooms'], 'toggle_device'=>['manage_rooms'],
        'rfid_class_scan'=>['manage_attendance'], 'manual_attendance'=>['correct_class_attendance'],
        'finalize_attendance'=>['manage_attendance'], 'update_attendance_settings'=>['manage_attendance_policies'],
        'update_grading_settings'=>['manage_grading_settings'],
        'create_event'=>['manage_events'], 'event_scan'=>['manage_events'],
        'manual_event_attendance'=>['manage_events'], 'set_event_status'=>['manage_events'], 'cancel_event'=>['manage_events'],
        'create_recollection'=>['manage_recollections'], 'recollection_scan'=>['manage_recollections'],
        'manual_recollection_attendance'=>['manage_recollections'], 'set_recollection_status'=>['manage_recollections'], 'cancel_recollection'=>['manage_recollections'],
        'save_grade'=>['manage_grades'], 'reopen_grade'=>['manage_grades'],
        'send_message'=>['send_messages'], 'mark_message_read'=>['view_messages'],
        'issue_violation'=>['manage_compliance'], 'update_settings'=>['manage_own_settings'],
        'update_profile'=>['manage_own_profile'], 'change_password'=>['manage_own_profile'],
    ];

    public static function canAll(array $permissions): bool
    {
        foreach ($permissions as $permission) if (!Auth::can($permission)) return false;
        return true;
    }

    public static function canCorrectAttendance(): bool
    {
        return Auth::canAny(['correct_class_attendance','manage_attendance']);
    }

    public static function canAssignSeats(): bool
    {
        return Auth::canAny(['assign_class_seats','manage_seat_plans']);
    }

    public static function showOwnProfile(): bool
    {
        return Auth::can('manage_own_profile') && !Auth::hasRole('Administrator');
    }

    public static function assertAction(string $action): void
    {
        if ($action === 'manual_attendance' && self::canCorrectAttendance()) return;
        if (!isset(self::ACTIONS[$action]) || !self::canAll(self::ACTIONS[$action])) {
            throw new DomainException('You do not have permission to perform this action.');
        }
    }

    public static function canManageCampusCalendar(): bool
    {
        return self::canAll(['manage_academic_calendar', 'manage_attendance_policies']);
    }

    public static function canViewCampusCalendar(): bool
    {
        return Auth::canAny(['view_schedule','view_events','manage_events','view_recollections','manage_recollections','manage_academic_calendar']);
    }

    public static function requireAction(string $action): void
    {
        if (!isset(self::ACTIONS[$action])) throw new DomainException('That action is not available.');
        if ($action === 'manual_attendance') { Auth::requireAnyPermission(['correct_class_attendance','manage_attendance']); return; }
        foreach (self::ACTIONS[$action] as $permission) Auth::requirePermission($permission);
    }

    public static function pagePermissions(string $page, bool $admin, int $profileTarget = 0): array
    {
        return match ($page) {
            'dashboard'=>['view_dashboard'],
            'users'=>['manage_users','manage_roles','manage_rfid'],
            'academics'=>['manage_academic_structure'], 'rooms'=>['manage_rooms'],
            'attendance'=>$admin ? ['manage_attendance','correct_class_attendance','manage_attendance_policies','manage_academic_calendar','manage_grading_settings'] : ['view_attendance','manage_attendance','correct_class_attendance'],
            'grades'=>$admin ? ['manage_grades','manage_grading_settings'] : ['view_grades','manage_grades'],
            'schedule'=>['view_schedule'], 'class-roster'=>['view_class_rosters'],
            'seat-plans'=>['manage_seat_plans','assign_class_seats'],
            'events'=>$admin ? ['manage_events'] : ['view_events','manage_events'],
            'recollections'=>$admin ? ['manage_recollections'] : ['view_recollections','manage_recollections'],
            'people'=>['view_people','manage_users'],
            'directory_profile'=>$profileTarget === (int)(Auth::user()['id']??0) ? ['manage_own_profile'] : ['view_people','manage_users'],
            'profile'=>$profileTarget>0 && $profileTarget!==(int)(Auth::user()['id']??0) ? ['view_people','manage_users'] : ['manage_own_profile'],
            'messages'=>['view_messages','send_messages'],
            'settings'=>['manage_own_settings'],
            'compliance'=>Auth::COMPLIANCE_PERMISSIONS,
            'analytics'=>['view_analytics'], 'audit-trail'=>['view_audit_logs'],
            'reports'=>['manage_reports','view_audit_logs'],
            default=>[],
        };
    }
}
