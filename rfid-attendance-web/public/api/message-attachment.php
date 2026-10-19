<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/src/bootstrap.php';
use App\Core\Auth;
use App\Services\MessageAttachmentService;

header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
try {
    if ($_SERVER['REQUEST_METHOD']!=='GET') { header('Allow: GET'); http_response_code(405); exit; }
    $user=Auth::user();
    if (!$user) { http_response_code(401); exit('Sign in to access this attachment.'); }
    if (!Auth::can('view_messages')) { http_response_code(403); exit('You do not have permission to access message attachments.'); }
    $record=MessageAttachmentService::find((int)$user['id'],(int)($_GET['id'] ?? 0));
    $path=MessageAttachmentService::path($record['storage_name']);
    $image=in_array($record['mime_type'],['image/jpeg','image/png','image/webp','image/gif'],true);
    $inline=$image && ($_GET['inline'] ?? '')==='1';
    header('Content-Type: '.($inline?$record['mime_type']:'application/octet-stream'));
    header("Content-Security-Policy: default-src 'none'; sandbox");
    $fallback=preg_replace('/[^a-zA-Z0-9._-]/','_',$record['original_name']);
    header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="'.$fallback.'"; filename*=UTF-8\'\''.rawurlencode($record['original_name']));
    header('Content-Length: '.filesize($path));
    session_write_close(); readfile($path);
} catch (DomainException) { http_response_code(404); header('Content-Type: text/plain; charset=UTF-8'); echo 'This attachment is unavailable or you do not have access to it.'; }
catch (Throwable $error) { error_log('Message attachment: '.get_class($error)); http_response_code(503); header('Content-Type: text/plain; charset=UTF-8'); echo 'The attachment is temporarily unavailable.'; }
