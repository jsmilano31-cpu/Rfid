<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Services\RoomService;
use App\Services\RfidDeviceService;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function facility_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

$currentUser = Auth::user();
if (!$currentUser) facility_json(['ok' => false, 'message' => 'Your session has expired. Sign in again.'], 401);
if (!Auth::can('manage_rooms')) facility_json(['ok' => false, 'message' => 'You do not have permission to manage rooms and devices.'], 403);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $entity = (string) ($_GET['entity'] ?? 'room');
        $id = (int) ($_GET['id'] ?? 0);
        $record = $entity === 'device' ? RoomService::findDevice($id) : RoomService::findRoom($id);
        if (!$record) throw new \DomainException($entity === 'device' ? 'The selected scanner could not be found.' : 'The selected room could not be found.');
        facility_json(['ok' => true, $entity === 'device' ? 'device' : 'room' => $record]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: GET, POST');
        facility_json(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }

    Csrf::verify();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create_device') {
        $created = RoomService::createDevice($_POST, (int) $currentUser['id']);
        facility_json([
            'ok' => true,
            'message' => 'Scanner created and ready to configure.',
            'device' => $created['device'],
            'credentials' => [
                'device_code' => $created['device']['device_code'],
                'api_key' => $created['api_key'],
            ],
        ], 201);
    }
    if ($action === 'update_room') {
        facility_json(['ok' => true, 'message' => 'Room details updated.', 'room' => RoomService::updateRoom($_POST, (int) $currentUser['id'])]);
    }
    if ($action === 'update_device') {
        facility_json(['ok' => true, 'message' => 'Scanner details updated.', 'device' => RoomService::updateDevice($_POST, (int) $currentUser['id'])]);
    }
    if ($action === 'rotate_device_key') {
        $rotated = RfidDeviceService::rotateKey((int) ($_POST['device_id'] ?? 0), (int) $currentUser['id']);
        facility_json([
            'ok' => true,
            'message' => 'Scanner API key replaced.',
            'device' => $rotated['device'],
            'credentials' => [
                'device_code' => $rotated['device']['device_code'],
                'api_key' => $rotated['api_key'],
            ],
        ]);
    }
    if ($action === 'toggle_room') {
        $room = RoomService::toggleRoom((int) ($_POST['room_id'] ?? 0), (int) $currentUser['id']);
        facility_json([
            'ok' => true,
            'message' => $room['status'] === 'active' ? 'Room activated.' : 'Room deactivated.',
            'room' => $room,
        ]);
    }
    if ($action === 'toggle_device') {
        $device = RoomService::toggleDevice((int) ($_POST['device_id'] ?? 0), (int) $currentUser['id']);
        facility_json([
            'ok' => true,
            'message' => $device['status'] === 'active' ? 'Scanner activated.' : 'Scanner deactivated.',
            'device' => $device,
        ]);
    }
    if ($action === 'delete_room') {
        facility_json(['ok' => true, 'message' => 'Room deleted.', 'deleted' => RoomService::deleteRoom((int) ($_POST['room_id'] ?? 0), (int) $currentUser['id'])]);
    }
    if ($action === 'delete_device') {
        facility_json(['ok' => true, 'message' => 'Scanner deleted.', 'deleted' => RoomService::deleteDevice((int) ($_POST['device_id'] ?? 0), (int) $currentUser['id'])]);
    }
    throw new \DomainException('That facility management action is not available.');
} catch (\DomainException $exception) {
    facility_json(['ok' => false, 'message' => $exception->getMessage()], 422);
} catch (\RuntimeException $exception) {
    facility_json(['ok' => false, 'message' => $exception->getMessage()], 419);
} catch (\Throwable $exception) {
    $message = config('app')['debug'] ? $exception->getMessage() : 'The facility could not be updated.';
    facility_json(['ok' => false, 'message' => $message], 500);
}
