<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Services\RfidRegistrationService;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function rfid_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

$user = Auth::user();
if (!$user) rfid_json(['ok' => false, 'message' => 'Your session has expired. Sign in again.'], 401);
if (!Auth::can('manage_rfid')) rfid_json(['ok' => false, 'message' => 'You do not have permission to manage RFID assignments.'], 403);

try {
    $actorId = (int) $user['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        rfid_json(['ok' => true, 'registration' => RfidRegistrationService::status((string) ($_GET['token'] ?? ''), $actorId)]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: GET, POST');
        rfid_json(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }
    Csrf::verify();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'start') {
        rfid_json(['ok' => true, 'registration' => RfidRegistrationService::start((int) ($_POST['user_id'] ?? 0), (int) ($_POST['device_id'] ?? 0), $actorId)]);
    }
    if ($action === 'complete') {
        rfid_json(['ok' => true, 'message' => 'RFID assignment saved.', 'registration' => RfidRegistrationService::complete(
            (string) ($_POST['request_token'] ?? ''),
            $actorId,
            (string) ($_POST['allow_reassignment'] ?? '') === '1'
        )]);
    }
    if ($action === 'cancel') {
        RfidRegistrationService::cancel((string) ($_POST['request_token'] ?? ''), $actorId);
        rfid_json(['ok' => true, 'message' => 'Registration cancelled.']);
    }
    throw new DomainException('That RFID registration action is not available.');
} catch (DomainException $exception) {
    rfid_json(['ok' => false, 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    rfid_json(['ok' => false, 'message' => $exception->getMessage()], 419);
} catch (Throwable $exception) {
    error_log('RFID registration: ' . $exception->getMessage());
    rfid_json(['ok' => false, 'message' => config('app')['debug'] ? $exception->getMessage() : 'The RFID request could not be completed.'], 500);
}
