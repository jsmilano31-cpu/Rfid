<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Services\CalendarService;
use App\Services\EventService;
use App\Services\RecollectionService;
use App\Services\TeacherTimeOffService;
use App\Exceptions\DeletionConflictException;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function calendar_json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

$currentUser = Auth::user();
if (!$currentUser) {
    calendar_json_response(['ok' => false, 'message' => 'Your session has expired. Sign in again.'], 401);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (($_GET['mode'] ?? '') === 'event_details') {
            if (!Auth::can('manage_events')) calendar_json_response(['ok'=>false,'message'=>'You do not have permission to manage events.'],403);
            $eventId = max(0, (int)($_GET['event_id'] ?? 0));
            \App\Services\EventAccessService::requireManage($eventId, (int)$currentUser['id']);
            calendar_json_response(['ok'=>true,'event'=>CalendarService::eventById($eventId)]);
        }
        if (!\App\Core\PermissionRegistry::canViewCampusCalendar()) calendar_json_response(['ok'=>false,'message'=>'You do not have permission to view the calendar.'],403);
        $adminScope = ($_GET['scope'] ?? '') === 'admin' && Auth::usesAdminArea();
        $events = $adminScope
            ? CalendarService::entries()
            : CalendarService::entriesForUser((int) $currentUser['id']);
        calendar_json_response(['ok' => true, 'events' => $events]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: GET, POST');
        calendar_json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }
    Csrf::verify();
    $action = (string) ($_POST['action'] ?? '');
    $timeOffActions = ['save_teacher_time_off', 'cancel_teacher_time_off'];
    if (in_array($action, $timeOffActions, true)) {
        if (!Auth::can('manage_teacher_time_off') || !Auth::hasRole('Teacher')) {
            calendar_json_response(['ok' => false, 'message' => 'You do not have permission to schedule teaching time off.'], 403);
        }
        if ($action === 'save_teacher_time_off') {
            $timeOffId = TeacherTimeOffService::save($_POST, (int) $currentUser['id']);
            calendar_json_response([
                'ok' => true,
                'message' => 'Teaching time off scheduled. Affected classes will not record absences.',
                'event' => CalendarService::teacherTimeOffById($timeOffId, (int) $currentUser['id']),
            ], empty($_POST['time_off_id']) ? 201 : 200);
        }
        $timeOffId = (int) ($_POST['time_off_id'] ?? 0);
        TeacherTimeOffService::cancel($timeOffId, (int) $currentUser['id']);
        calendar_json_response(['ok' => true, 'message' => 'Teaching time off removed.', 'event_id' => 'teacher-time-off-' . $timeOffId]);
    }
    if (!Auth::usesAdminArea() && !in_array($action, ['create_event','update_event','cancel_event','delete_event'], true)) {
        calendar_json_response(['ok' => false, 'message' => 'You do not have permission to manage the campus calendar.'], 403);
    }
    $eventActions = ['create_event', 'update_event', 'cancel_event', 'delete_event'];
    $recollectionActions = ['update_recollection', 'cancel_recollection', 'delete_recollection'];
    if (in_array($action, $eventActions, true) && !Auth::can('manage_events')) {
        calendar_json_response(['ok' => false, 'message' => 'You do not have permission to manage events.'], 403);
    }
    if (in_array($action, $recollectionActions, true) && !Auth::can('manage_recollections')) {
        calendar_json_response(['ok' => false, 'message' => 'You do not have permission to manage recollections and retreats.'], 403);
    }
    if ($action === 'create_event') {
        $scannerCredentials = null;
        $eventId = EventService::create($_POST, (int) $currentUser['id'], $_FILES['banner_image'] ?? null, $_FILES['event_images'] ?? null, $scannerCredentials);
        calendar_json_response([
            'ok' => true,
            'message' => 'Event created and added to the calendar.',
            'event' => CalendarService::eventById($eventId),
            'scanner_credentials' => $scannerCredentials,
        ], 201);
    }
    $eventId = (int) ($_POST['event_id'] ?? 0);
    if ($action === 'update_event') {
        $scannerCredentials = null;
        EventService::update($eventId, $_POST, (int) $currentUser['id'], $_FILES['banner_image'] ?? null, $_FILES['event_images'] ?? null, $scannerCredentials);
        calendar_json_response(['ok' => true, 'message' => 'Event updated.', 'event' => CalendarService::eventById($eventId), 'scanner_credentials' => $scannerCredentials]);
    }
    if ($action === 'cancel_event') {
        EventService::setStatus($eventId, 'cancelled', (int) $currentUser['id']);
        calendar_json_response(['ok' => true, 'message' => 'Event cancelled.', 'event_id' => 'event-' . $eventId]);
    }
    if ($action === 'delete_event') {
        calendar_json_response(['ok' => true, 'message' => 'Event deleted.', 'deleted' => EventService::delete($eventId, (int) $currentUser['id'])]);
    }
    $recollectionId = (int) ($_POST['recollection_id'] ?? 0);
    if ($action === 'update_recollection') {
        RecollectionService::update($recollectionId, $_POST, (int) $currentUser['id']);
        calendar_json_response([
            'ok' => true,
            'message' => 'Recollection or retreat updated.',
            'event' => CalendarService::recollectionById($recollectionId),
        ]);
    }
    if ($action === 'cancel_recollection') {
        RecollectionService::setStatus($recollectionId, 'cancelled', (int) $currentUser['id']);
        calendar_json_response([
            'ok' => true,
            'message' => 'Recollection or retreat cancelled.',
            'event_id' => 'recollection-' . $recollectionId,
        ]);
    }
    if ($action === 'delete_recollection') {
        calendar_json_response(['ok' => true, 'message' => 'Activity deleted.', 'deleted' => RecollectionService::delete($recollectionId, (int) $currentUser['id'])]);
    }
    throw new \DomainException('That calendar action is not available.');
} catch (DeletionConflictException $exception) {
    calendar_json_response($exception->payload(), 409);
} catch (\DomainException $exception) {
    calendar_json_response(['ok' => false, 'message' => $exception->getMessage()], 422);
} catch (\RuntimeException $exception) {
    calendar_json_response(['ok' => false, 'message' => $exception->getMessage()], 419);
} catch (\PDOException $exception) {
    error_log('Calendar deletion database failure: ' . $exception->getMessage());
    calendar_json_response(['ok' => false, 'message' => 'The calendar request could not be completed.'], 500);
} catch (\Throwable $exception) {
    $message = config('app')['debug'] ? $exception->getMessage() : 'The calendar request could not be completed.';
    calendar_json_response(['ok' => false, 'message' => $message], 500);
}
