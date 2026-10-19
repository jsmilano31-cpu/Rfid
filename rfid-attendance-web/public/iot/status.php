<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Services\RfidDeviceService;
use App\Services\EventService;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['ok' => false, 'code' => 'method_not_allowed', 'message' => 'GET is required.']);
    exit;
}

try {
    $device = RfidDeviceService::authenticate((string) ($_SERVER['HTTP_X_DEVICE_CODE'] ?? ''), (string) ($_SERVER['HTTP_X_DEVICE_KEY'] ?? ''));
    $event = EventService::activeForDevice((int) $device['id'], date('Y-m-d'), date('H:i:s'));
    $schedule = ['current_class' => null, 'next_class' => null];
    $scheduleMessage = null;
    if (!$device['room_id']) {
        $scheduleMessage = 'This scanner is not assigned to a room.';
    } elseif ($device['room_status'] !== 'active') {
        $scheduleMessage = 'This scanner is assigned to an inactive room.';
    } else {
        try {
            $schedule = RfidDeviceService::scheduleStatus($device, date('Y-m-d'), date('H:i:s'));
        } catch (DomainException $exception) {
            $scheduleMessage = $exception->getMessage();
        }
    }
    echo json_encode(['ok' => true, 'device' => [
        'code' => $device['device_code'],
        'status' => $device['status'],
        'room_assigned' => $device['room_id'] !== null,
        'room_code' => $device['room_code'],
    ], 'current_event' => $event ? ['id' => (int) $event['id'], 'title' => $event['title'], 'start_time' => substr($event['start_time'], 0, 5), 'end_time' => substr($event['end_time'], 0, 5)] : null,
       'current_class' => $event ? null : $schedule['current_class'], 'next_class' => $event ? null : $schedule['next_class'],
       'schedule_message' => $scheduleMessage, 'server_time' => date(DATE_ATOM)], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (DomainException $exception) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'code' => 'unauthorized', 'message' => 'Scanner authentication failed.']);
}
