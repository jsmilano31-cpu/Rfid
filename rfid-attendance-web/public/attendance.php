<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Services\AttendanceService;
use App\Services\LiveSeatPlanService;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function attendance_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

try {
    $user = Auth::user();
    if (!$user) attendance_json(['ok' => false, 'message' => 'Your session has expired. Sign in again.'], 401);
    if (!Auth::can('view_attendance') && !\App\Core\PermissionRegistry::canCorrectAttendance() && !\App\Core\PermissionRegistry::canAssignSeats()) {
        attendance_json(['ok' => false, 'message' => 'You do not have permission to view attendance.'], 403);
    }
    $actorId = (int) $user['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!\App\Core\PermissionRegistry::canCorrectAttendance() && !\App\Core\PermissionRegistry::canAssignSeats() && !Auth::hasRole('Teacher')) attendance_json(['ok' => false, 'message' => 'You do not have permission to view class attendance.'], 403);
        $mode = (string) ($_GET['mode'] ?? 'subjects');
        $query = (string) ($_GET['q'] ?? '');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        if ($mode === 'subjects') attendance_json(['ok' => true] + AttendanceService::subjects($actorId, $query, $page));
        if ($mode === 'policy') {
            if (!Auth::can('manage_attendance_policies')) attendance_json(['ok' => false, 'message' => 'You do not have permission to configure attendance policies.'], 403);
            attendance_json(['ok' => true, 'policy' => AttendanceService::policy((int) ($_GET['offering_id'] ?? 0), $actorId)]);
        }
        if ($mode === 'live_seat_plan') {
            $offeringId = (int) ($_GET['offering_id'] ?? 0);
            attendance_json(['ok' => true] + ($offeringId > 0
                ? LiveSeatPlanService::state($offeringId, $actorId, (string) ($_GET['date'] ?? date('Y-m-d')))
                : LiveSeatPlanService::dashboardState($actorId)));
        }
        if ($mode === 'seat_students') {
            if (!\App\Core\PermissionRegistry::canAssignSeats()) attendance_json(['ok'=>false,'message'=>'You do not have permission to manage seat assignments.'],403);
            attendance_json(['ok' => true, 'students' => LiveSeatPlanService::students(
                (int) ($_GET['offering_id'] ?? 0),
                (int) ($_GET['plan_id'] ?? 0),
                $actorId,
                $query
            )]);
        }
        if ($mode === 'calendar') {
            attendance_json(['ok' => true] + AttendanceService::calendar(
                (int) ($_GET['offering_id'] ?? 0),
                $actorId,
                (string) ($_GET['month'] ?? date('Y-m'))
            ));
        }
        if ($mode === 'roster') {
            attendance_json(['ok' => true] + AttendanceService::roster(
                (int) ($_GET['offering_id'] ?? 0),
                $actorId,
                (string) ($_GET['date'] ?? date('Y-m-d')),
                $query,
                $page
            ));
        }
        if ($mode === 'student_record') {
            attendance_json(['ok' => true, 'student' => AttendanceService::studentRecord(
                (int) ($_GET['offering_id'] ?? 0),
                (int) ($_GET['student_id'] ?? 0),
                (string) ($_GET['date'] ?? date('Y-m-d')),
                $actorId
            )]);
        }
        if ($mode === 'student_details') {
            attendance_json(['ok' => true] + AttendanceService::studentAttendanceProfile((int) ($_GET['student_id'] ?? 0), $actorId));
        }
        throw new DomainException('That attendance view is not available.');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: GET, POST');
        attendance_json(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }
    Csrf::verify();
    $action = (string) ($_POST['action'] ?? '');
    $allowed = match($action) {
        'manual_attendance' => \App\Core\PermissionRegistry::canCorrectAttendance(),
        'assign_class_seat','unassign_class_seat' => \App\Core\PermissionRegistry::canAssignSeats(),
        default => Auth::can('manage_attendance'),
    };
    if (!$allowed) attendance_json(['ok'=>false,'message'=>'You do not have permission to perform this attendance action.'],403);
    $offeringId = (int) ($_POST['class_offering_id'] ?? 0);
    $date = (string) ($_POST['attendance_date'] ?? date('Y-m-d'));

    if ($action === 'manual_attendance' || $action === 'quick_present') {
        $status = $action === 'quick_present' ? 'Present' : (string) ($_POST['status'] ?? 'Present');
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($reason === '' && Auth::can('manage_attendance')) $reason = $action === 'quick_present' ? 'Quick roster entry' : 'Manual roster entry';
        $student = AttendanceService::recordManual(
            $offeringId,
            (int) ($_POST['student_id'] ?? 0),
            $date,
            $status,
            $actorId,
            $reason,
            (string) ($_POST['time_in'] ?? '')
        );
        attendance_json(['ok' => true, 'message' => $action === 'quick_present' ? 'Student marked present.' : 'Attendance saved.', 'student' => $student]);
    }
    if ($action === 'rfid_class_scan') {
        $scan = AttendanceService::recordRfidScan($offeringId, (string) ($_POST['uid'] ?? ''), $actorId, $date, (string) ($_POST['time_in'] ?? ''));
        attendance_json(['ok' => true, 'message' => $scan['user']['first_name'] . ' ' . $scan['user']['last_name'] . ' recorded as ' . $scan['status'] . '.', 'scan' => $scan]);
    }
    if ($action === 'finalize_attendance') {
        $created = AttendanceService::finalize($offeringId, $date, $actorId);
        attendance_json(['ok' => true, 'message' => $created ? "Attendance finalized; {$created} missing student(s) marked absent." : 'Attendance finalized. No missing students remained.', 'created' => $created]);
    }
    if ($action === 'update_attendance_settings') {
        if (!Auth::can('manage_attendance_policies')) attendance_json(['ok' => false, 'message' => 'You do not have permission to configure attendance policies.'], 403);
        $settings = AttendanceService::updateSettings((int) ($_POST['early_check_in_minutes'] ?? 10), (int) ($_POST['grace_period_minutes'] ?? -1), (int) ($_POST['absent_after_minutes'] ?? 0), (int) ($_POST['tardies_per_absence'] ?? 0), $actorId);
        attendance_json(['ok' => true, 'message' => 'Attendance rules updated.', 'settings' => $settings]);
    }
    if ($action === 'update_subject_attendance_policy') {
        if (!Auth::can('manage_attendance_policies')) attendance_json(['ok' => false, 'message' => 'You do not have permission to configure attendance policies.'], 403);
        $policy = AttendanceService::updateSubjectPolicy(
            $offeringId,
            isset($_POST['use_campus_default']) && (string) $_POST['use_campus_default'] === '1',
            (int) ($_POST['early_check_in_minutes'] ?? 10),
            (int) ($_POST['grace_period_minutes'] ?? -1),
            (int) ($_POST['absent_after_minutes'] ?? 0),
            (int) ($_POST['tardies_per_absence'] ?? 0),
            $actorId
        );
        attendance_json(['ok' => true, 'message' => $policy['uses_campus_default'] ? 'Campus attendance defaults restored for this subject.' : 'Subject attendance policy saved.', 'policy' => $policy]);
    }
    if ($action === 'assign_class_seat') {
        if (!\App\Core\PermissionRegistry::canAssignSeats()) attendance_json(['ok'=>false,'message'=>'You do not have permission to manage seat assignments.'],403);
        LiveSeatPlanService::assign(
            $offeringId,
            (int) ($_POST['seat_plan_id'] ?? 0),
            (int) ($_POST['grid_row'] ?? 0),
            (int) ($_POST['grid_column'] ?? 0),
            (int) ($_POST['student_id'] ?? 0),
            $actorId
        );
        attendance_json(['ok' => true, 'message' => 'Student seat assigned.']);
    }
    if ($action === 'unassign_class_seat') {
        if (!\App\Core\PermissionRegistry::canAssignSeats()) attendance_json(['ok'=>false,'message'=>'You do not have permission to manage seat assignments.'],403);
        LiveSeatPlanService::unassign(
            $offeringId,
            (int) ($_POST['seat_plan_id'] ?? 0),
            (int) ($_POST['grid_row'] ?? 0),
            (int) ($_POST['grid_column'] ?? 0),
            $actorId
        );
        attendance_json(['ok' => true, 'message' => 'Seat assignment removed.']);
    }
    throw new DomainException('That attendance action is not available.');
} catch (DomainException $exception) {
    attendance_json(['ok' => false, 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    attendance_json(['ok' => false, 'message' => $exception->getMessage()], 419);
} catch (Throwable $exception) {
    attendance_json(['ok' => false, 'message' => config('app')['debug'] ? $exception->getMessage() : 'The attendance request could not be completed.'], 500);
}
