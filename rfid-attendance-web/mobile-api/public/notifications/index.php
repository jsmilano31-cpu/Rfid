<?php
declare(strict_types=1);
require dirname(__DIR__).'/_bootstrap.php';
use App\Services\NotificationService;
try {
    Api::method('GET'); $user=Api::user();
    NotificationService::syncUser((int)$user['id']);
    NotificationService::dispatchAfterResponse();
    Api::respond(['data'=>NotificationService::inbox((int)$user['id'],max(0,(int)($_GET['before']??0)))]);
} catch (ApiError $e) { Api::respond(['error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status); }
catch (Throwable $e) { error_log('Mobile notifications: '.get_class($e)); Api::respond(['error'=>['code'=>'unavailable','message'=>'Notifications are temporarily unavailable.']],503); }
