<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Services\ClassRosterService;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    $user = Auth::user();
    if (!$user) throw new RuntimeException('Your session has expired. Sign in again.');
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'message' => 'Method not allowed.'], JSON_THROW_ON_ERROR);
        exit;
    }
    echo json_encode(['ok' => true] + ClassRosterService::roster(
        (int) ($_GET['offering_id'] ?? 0),
        (int) $user['id'],
        (string) ($_GET['q'] ?? ''),
        max(1, (int) ($_GET['page'] ?? 1))
    ), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (DomainException $exception) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR);
} catch (RuntimeException $exception) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => config('app')['debug'] ? $exception->getMessage() : 'The class roster could not be loaded.'], JSON_THROW_ON_ERROR);
}
