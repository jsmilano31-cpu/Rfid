<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Services\SearchService;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['ok'=>false,'message'=>'Method not allowed.']);
    exit;
}
try {
    if (!Auth::user()) {
        http_response_code(401);
        echo json_encode(['ok'=>false,'message'=>'Your session has expired. Sign in again.']);
        exit;
    }
    if (!Auth::can('use_search')) { http_response_code(403); echo json_encode(['ok'=>false,'message'=>'You do not have permission to use universal search.']); exit; }
    echo json_encode(SearchService::search((string)($_GET['q'] ?? ''), (string)($_GET['context'] ?? ''), isset($_GET['category']) ? (string)$_GET['category'] : null, (int)($_GET['page'] ?? 1)), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    error_log('Universal search failed: '.$error->getMessage());
    http_response_code(500);
    echo json_encode(['ok'=>false,'message'=>'Search is temporarily unavailable. Please try again.']);
}
