<?php
declare(strict_types=1);
require dirname(__DIR__).'/_bootstrap.php';
try {
    $user=Api::user(); $data=Api::write(); Api::rateLimit('notification-device',60,3600);
    $token=Api::text($data,'token',4096);
    \App\Services\NotificationService::device((int)$user['id'],$token,($data['enabled']??true)===true);
    Api::respond(['data'=>['registered'=>true,'account_id'=>(string)$user['id']]]);
} catch (ApiError $e) { Api::respond(['error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status); }
catch (DomainException $e) { Api::respond(['error'=>['code'=>'validation','message'=>$e->getMessage()]],422); }
catch (Throwable) { Api::respond(['error'=>['code'=>'unavailable','message'=>'Could not register this device for push notifications.']],503); }
