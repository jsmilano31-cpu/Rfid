<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Services\BulkDeletionService;
use App\Services\DeletionConflictService;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function bulk_json(array $payload, int $status = 200): never { http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); exit; }
$user = Auth::user();
if (!$user) bulk_json(['ok'=>false, 'message'=>'Your session has expired. Sign in again.'], 401);
try {
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, ['GET', 'POST'], true)) { header('Allow: GET, POST'); bulk_json(['ok'=>false,'message'=>'Method not allowed.'],405); }
    $input = $method === 'GET' ? $_GET : $_POST;
    $type = (string)($input['type'] ?? '');
    if (!Auth::can(DeletionConflictService::permissionFor($type))) bulk_json(['ok'=>false,'message'=>'You do not have permission to delete these records.'],403);
    $ids = BulkDeletionService::ids($input['ids'] ?? []);
    if ($method === 'GET') bulk_json(BulkDeletionService::inspect($type,$ids,(int)$user['id']));
    try { Csrf::verify(); } catch (RuntimeException $exception) { bulk_json(['ok'=>false,'message'=>'The form session expired. Refresh the page before trying again.'],419); }
    $payload = BulkDeletionService::delete($type,$ids,(int)$user['id']);
    bulk_json($payload, $payload['ok'] ? 200 : 409);
} catch (DomainException $exception) { bulk_json(['ok'=>false,'message'=>$exception->getMessage()],422); }
catch (Throwable $exception) { error_log('Bulk deletion request failed: '.$exception->getMessage()); bulk_json(['ok'=>false,'message'=>'The selected records could not be processed.'],500); }
