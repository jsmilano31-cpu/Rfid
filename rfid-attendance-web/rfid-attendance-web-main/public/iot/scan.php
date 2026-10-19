<?php

declare(strict_types=1);

if (!defined('BASE_PATH')) require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Database;
use App\Services\AttendanceService;
use App\Services\EventService;
use App\Services\RfidDeviceService;
use App\Services\RfidRegistrationService;
use App\Services\RfidService;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function device_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function device_rate_limit(): void
{
    $directory = BASE_PATH . '/storage/device-rate-limits';
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('Rate-limit storage unavailable.');
    $path = $directory . '/' . hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown')) . '.json';
    $file = fopen($path, 'c+');
    if (!$file || !flock($file, LOCK_EX)) throw new RuntimeException('Rate-limit storage unavailable.');
    try {
        $state = json_decode(stream_get_contents($file), true) ?: ['started' => time(), 'count' => 0];
        if (time() - (int) $state['started'] >= 60) $state = ['started' => time(), 'count' => 0];
        if ((int) $state['count'] >= 120) device_json(['ok' => false, 'code' => 'rate_limited', 'message' => 'Too many scanner requests.'], 429);
        $state['count']++; rewind($file); ftruncate($file, 0); fwrite($file, json_encode($state));
    } finally {
        flock($file, LOCK_UN); fclose($file);
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    device_json(['ok' => false, 'code' => 'method_not_allowed', 'message' => 'POST is required.'], 405);
}
device_rate_limit();
if (!str_starts_with(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    device_json(['ok' => false, 'code' => 'json_required', 'message' => 'Send a JSON request.'], 415);
}
$raw = file_get_contents('php://input', false, null, 0, 2049);
if (strlen($raw) > 2048) device_json(['ok' => false, 'code' => 'request_too_large', 'message' => 'Request is too large.'], 413);
try {
    $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($input)) throw new JsonException('Object required');
} catch (JsonException) {
    device_json(['ok' => false, 'code' => 'invalid_json', 'message' => 'Request could not be read.'], 400);
}

$device = null;
$uid = null;
try {
    $device = RfidDeviceService::authenticate((string) ($_SERVER['HTTP_X_DEVICE_CODE'] ?? ''), (string) ($_SERVER['HTTP_X_DEVICE_KEY'] ?? ''));
    $uid = RfidService::normalizeUid((string) ($input['uid'] ?? ''));
    $pdo = Database::connection();
    $pdo->beginTransaction();
    $registration = RfidRegistrationService::capture((int) $device['id'], $uid);
    if ($registration) {
        RfidDeviceService::log((int) $device['id'], $uid, 'registration_captured', $registration['message']);
        $pdo->commit();
        device_json(['ok' => true] + $registration);
    }
    $date = date('Y-m-d');
    $time = date('H:i:s');
    $event = EventService::activeForDevice((int) $device['id'], $date, $time);
    if ($event) {
        $user = EventService::recordRfidScan((int) $event['id'], $uid, null);
        $message = $user['first_name'] . ' ' . $user['last_name'] . ' — ' . $event['title'];
        RfidDeviceService::log((int) $device['id'], $uid, 'event_attendance_recorded', $message, null, (int) $user['id']);
        $pdo->commit();
        device_json(['ok' => true, 'mode' => 'event_attendance', 'message' => $message, 'student' => [
            'name' => $user['first_name'] . ' ' . $user['last_name'], 'school_id' => $user['school_id'],
        ], 'event' => ['id' => (int) $event['id'], 'title' => $event['title']], 'status' => 'Attended', 'date' => $date, 'time' => $time]);
    }
    $offeringId = RfidDeviceService::currentOffering($device, $date, $time);
    $scan = AttendanceService::recordRfidScan($offeringId, $uid, null, $date, $time, (int) $device['id']);
    $message = $scan['user']['first_name'] . ' ' . $scan['user']['last_name'] . ' — ' . $scan['status'];
    RfidDeviceService::log((int) $device['id'], $uid, 'attendance_recorded', $message, $offeringId, (int) $scan['user']['id']);
    $pdo->commit();
    device_json(['ok' => true, 'mode' => 'attendance', 'message' => $message, 'student' => [
        'name' => $scan['user']['first_name'] . ' ' . $scan['user']['last_name'],
        'school_id' => $scan['user']['school_id'],
    ], 'status' => $scan['status'], 'date' => $scan['date'], 'time' => $scan['time']]);
} catch (DomainException $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    if ($device) {
        try { RfidDeviceService::log((int) $device['id'], $uid, 'rejected', $exception->getMessage()); } catch (Throwable) {}
    }
    $authenticationFailure = $exception->getMessage() === 'Scanner authentication failed.';
    device_json(['ok' => false, 'code' => $authenticationFailure ? 'unauthorized' : 'scan_rejected', 'message' => $exception->getMessage()], $authenticationFailure ? 401 : 422);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('RFID device scan: ' . $exception->getMessage());
    device_json(['ok' => false, 'code' => 'service_unavailable', 'message' => 'The scanner service is temporarily unavailable.'], 503);
}
