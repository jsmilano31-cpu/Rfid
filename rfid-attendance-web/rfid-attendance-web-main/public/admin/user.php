<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Services\RfidService;
use App\Services\UserService;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

$currentUser = Auth::user();
if (!$currentUser) {
    json_response(['ok' => false, 'message' => 'Your session has expired. Sign in again.'], 401);
}
if (!Auth::can('manage_users')) {
    json_response(['ok' => false, 'message' => 'You do not have permission to manage users.'], 403);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (array_key_exists('q', $_GET)) {
            $users = UserService::searchForSelection((string) $_GET['q']);
            json_response(['ok' => true, 'users' => $users]);
        }
        $userId = (int) ($_GET['id'] ?? 0);
        $managedUser = UserService::findForManagement($userId);
        if (!$managedUser) {
            throw new \DomainException('The selected user could not be found.');
        }
        json_response(['ok' => true, 'user' => $managedUser]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: GET, POST');
        json_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }

    Csrf::verify();
    $action = (string) ($_POST['action'] ?? '');
    $userId = (int) ($_POST['user_id'] ?? 0);

    switch ($action) {
        case 'update_user':
            $managedUser = UserService::update($_POST, (int) $currentUser['id']);
            json_response(['ok' => true, 'message' => 'User details updated.', 'user' => $managedUser]);

        case 'update_user_profile_image':
            $managedUser = UserService::updateProfileImage($userId, $_FILES['profile_image'] ?? null, (int) $currentUser['id']);
            json_response(['ok' => true, 'message' => 'Profile picture updated.', 'user' => $managedUser]);

        case 'update_user_rfid':
            if (!Auth::can('manage_rfid')) {
                json_response(['ok' => false, 'message' => 'You do not have permission to manage RFID assignments.'], 403);
            }
            $uid = trim((string) ($_POST['rfid_uid'] ?? ''));
            if ($uid === '') {
                RfidService::unassign($userId, (int) $currentUser['id']);
                $message = 'RFID assignment removed.';
            } else {
                $uid = RfidService::assign($userId, $uid, (int) $currentUser['id']);
                $message = "RFID {$uid} assigned.";
            }
            $managedUser = UserService::findForManagement($userId);
            if (!$managedUser) {
                throw new \DomainException('The selected user could not be found.');
            }
            json_response(['ok' => true, 'message' => $message, 'user' => $managedUser]);

        case 'reset_user_password':
            $newPassword = (string) ($_POST['new_password'] ?? '');
            if (!hash_equals($newPassword, (string) ($_POST['confirm_password'] ?? ''))) {
                throw new \DomainException('The password confirmation does not match.');
            }
            UserService::resetPassword($userId, $newPassword, (int) $currentUser['id']);
            json_response(['ok' => true, 'message' => 'User password updated.']);

        case 'delete_user':
            json_response(['ok' => true, 'message' => 'User account deleted.', 'deleted' => UserService::delete($userId, (int) $currentUser['id'])]);

        default:
            throw new \DomainException('That user management action is not available.');
    }
} catch (\DomainException $exception) {
    json_response(['ok' => false, 'message' => $exception->getMessage()], 422);
} catch (\RuntimeException $exception) {
    json_response(['ok' => false, 'message' => $exception->getMessage()], 419);
} catch (\Throwable $exception) {
    $message = config('app')['debug'] ? $exception->getMessage() : 'The user could not be updated.';
    json_response(['ok' => false, 'message' => $message], 500);
}
