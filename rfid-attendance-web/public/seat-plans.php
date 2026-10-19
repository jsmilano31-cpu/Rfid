<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Services\SeatPlanService;
use App\Exceptions\DeletionConflictException;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function seat_plan_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

try {
    $user = Auth::user();
    if (!$user) seat_plan_json(['ok' => false, 'message' => 'Your session has expired. Sign in again.'], 401);
    if (!\App\Core\PermissionRegistry::canAssignSeats()) seat_plan_json(['ok' => false, 'message' => 'You do not have permission to view seat plans.'], 403);
    $actorId = (int) $user['id'];

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $mode = (string) ($_GET['mode'] ?? 'bootstrap');
        if ($mode === 'bootstrap') seat_plan_json(['ok' => true] + SeatPlanService::bootstrap($actorId));
        if ($mode === 'room') seat_plan_json(['ok' => true] + SeatPlanService::roomPlan((int) ($_GET['room_id'] ?? 0), $actorId));
        if ($mode === 'preset') seat_plan_json(['ok' => true] + SeatPlanService::preset((int) ($_GET['preset_id'] ?? 0), $actorId));
        throw new DomainException('That seat-plan view is not available.');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: GET, POST');
        seat_plan_json(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }
    Csrf::verify();
    if (!Auth::can('manage_seat_plans')) seat_plan_json(['ok'=>false,'message'=>'Prepared layouts and presets can be edited only by a seat-plan manager.'],403);
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_room_plan') {
        seat_plan_json(['ok' => true, 'message' => 'Room seat plan saved.'] + SeatPlanService::saveRoomPlan($_POST, $actorId));
    }
    if ($action === 'save_preset') {
        seat_plan_json(['ok' => true, 'message' => 'Seat-plan preset created.'] + SeatPlanService::savePreset($_POST, $actorId), 201);
    }
    if ($action === 'update_preset') {
        seat_plan_json(['ok' => true, 'message' => 'Seat-plan preset updated.'] + SeatPlanService::updatePreset($_POST, $actorId));
    }
    if ($action === 'delete_preset') {
        $presetId = (int) ($_POST['preset_id'] ?? 0);
        SeatPlanService::deletePreset($presetId, $actorId);
        seat_plan_json(['ok' => true, 'message' => 'Seat-plan preset deleted.', 'preset_id' => $presetId]);
    }
    throw new DomainException('That seat-plan action is not available.');
} catch (DeletionConflictException $exception) {
    seat_plan_json($exception->payload(), 409);
} catch (DomainException $exception) {
    seat_plan_json(['ok' => false, 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    seat_plan_json(['ok' => false, 'message' => $exception->getMessage()], 419);
} catch (PDOException $exception) {
    error_log('Seat-plan deletion database failure: ' . $exception->getMessage());
    seat_plan_json(['ok' => false, 'message' => 'The seat-plan request could not be completed.'], 500);
} catch (Throwable $exception) {
    seat_plan_json(['ok' => false, 'message' => config('app')['debug'] ? $exception->getMessage() : 'The seat-plan request could not be completed.'], 500);
}
