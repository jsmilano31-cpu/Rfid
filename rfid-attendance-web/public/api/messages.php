<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Services\MessageService;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
try {
    $user = Auth::user();
    if (!$user) { http_response_code(401); echo json_encode(['ok'=>false,'message'=>'Your session expired. Sign in again.']); exit; }
    $userId = (int)$user['id'];
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!Auth::can('view_messages')) { http_response_code(403); echo json_encode(['ok'=>false,'message'=>'You do not have permission to view messages.']); exit; }
        $data = isset($_GET['message_id'])
            ? MessageService::message($userId, (int)$_GET['message_id'])
            : MessageService::mailbox($userId, (string)($_GET['folder'] ?? 'inbox'), (string)($_GET['q'] ?? ''), (int)($_GET['page'] ?? 1));
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0)>0) { http_response_code(413); echo json_encode(['ok'=>false,'message'=>'The upload exceeds the server limit. Remove some attachments and try again.']); exit; }
        try { Csrf::verify(); } catch (RuntimeException $error) { http_response_code(419); echo json_encode(['ok'=>false,'message'=>'Your form session expired. Refresh this page.']); exit; }
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'send_message') {
            if (!Auth::can('send_messages')) { http_response_code(403); echo json_encode(['ok'=>false,'message'=>'You do not have permission to send messages.']); exit; }
            $data = ['id'=>MessageService::send($_POST, $userId, $_FILES['attachments'] ?? null)];
        } else {
            if (!Auth::can('view_messages')) { http_response_code(403); echo json_encode(['ok'=>false,'message'=>'You do not have permission to manage your mailbox.']); exit; }
            $ids = json_decode((string)($_POST['ids'] ?? '[]'), true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($ids)) throw new DomainException('Invalid message selection.');
            MessageService::change($userId, $ids, $action);
            $data = ['updated'=>count($ids)];
        }
    } else { header('Allow: GET, POST'); http_response_code(405); echo json_encode(['ok'=>false,'message'=>'Method not allowed.']); exit; }
    echo json_encode(['ok'=>true,'time_zone'=>config('app')['timezone'],'data'=>$data], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (DomainException|JsonException $error) {
    http_response_code(422); echo json_encode(['ok'=>false,'message'=>$error->getMessage()]);
} catch (Throwable $error) {
    error_log('Mailbox endpoint: ' . get_class($error));
    http_response_code(503); echo json_encode(['ok'=>false,'message'=>'Messages are temporarily unavailable. Please try again.']);
}
