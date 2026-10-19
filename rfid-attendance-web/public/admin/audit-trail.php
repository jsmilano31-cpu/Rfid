<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/src/bootstrap.php';
use App\Core\Auth;
use App\Services\AuditTrailService;
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if ($_SERVER['REQUEST_METHOD']!=='GET') {http_response_code(405);header('Allow: GET');echo json_encode(['ok'=>false,'message'=>'This history is read-only.']);exit;}
if (!Auth::user()) {http_response_code(401);echo json_encode(['ok'=>false,'message'=>'Your session has expired. Sign in again.']);exit;}
if (!Auth::usesAdminArea() || !Auth::can('view_audit_logs')) {http_response_code(403);echo json_encode(['ok'=>false,'message'=>'You do not have permission to view the audit trail.']);exit;}
try {
    $payload=isset($_GET['id'])?AuditTrailService::detail((int)$_GET['id']):AuditTrailService::listing($_GET);
    echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
} catch (DomainException $error) {http_response_code(isset($_GET['id'])?404:422);echo json_encode(['ok'=>false,'message'=>$error->getMessage()]);}
catch (Throwable $error) {error_log('Audit trail read failed: '.$error->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'message'=>'Audit history could not be loaded. Please retry.']);}
