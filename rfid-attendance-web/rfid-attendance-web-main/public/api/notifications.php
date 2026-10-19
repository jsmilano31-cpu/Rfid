<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/src/bootstrap.php';
use App\Core\Auth;
use App\Core\Csrf;
use App\Services\NotificationService;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $user=Auth::user();
    if (!$user) { http_response_code(401); echo json_encode(['error'=>'Sign in to view notifications.']); exit; }
    if ($_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['push_config'])) {
        echo json_encode(['data'=>['config'=>\App\Services\FcmService::webConfig(),'account_id'=>(string)$user['id']]]); exit;
    }
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        try { Csrf::verify(); } catch (RuntimeException) { http_response_code(419); echo json_encode(['error'=>'Your form session expired. Refresh the page.']); exit; }
        if (($_POST['action']??'')==='device') {
            NotificationService::device((int)$user['id'],(string)($_POST['token']??''),($_POST['enabled']??'1')==='1');
            echo json_encode(['data'=>['registered'=>true,'account_id'=>(string)$user['id']]]); exit;
        }
        $items=json_decode((string)($_POST['items']??'[]'),true,8,JSON_THROW_ON_ERROR);
        if (!is_array($items)) throw new DomainException('Invalid notification selection.');
        NotificationService::read((int)$user['id'],$items);
    } elseif ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); header('Allow: GET, POST'); exit; }
    NotificationService::syncUser((int)$user['id']);
    NotificationService::dispatchAfterResponse();
    echo json_encode(['data'=>NotificationService::inbox((int)$user['id'],max(0,(int)($_GET['before']??0)))],JSON_THROW_ON_ERROR);
} catch (DomainException|JsonException $e) { http_response_code(422); echo json_encode(['error'=>$e->getMessage()]); }
catch (Throwable $e) { error_log('Notification endpoint: '.get_class($e)); http_response_code(503); echo json_encode(['error'=>'Notifications are temporarily unavailable. Please try again.']); }
