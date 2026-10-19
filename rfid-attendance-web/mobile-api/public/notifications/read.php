<?php
declare(strict_types=1);
require dirname(__DIR__).'/_bootstrap.php';
try {
    $user=Api::user(); $data=Api::write(); $items=$data['items']??[];
    if (!is_array($items)) throw new DomainException('Invalid notifications.');
    \App\Services\NotificationService::read((int)$user['id'],$items);
    Api::respond(['data'=>['saved'=>true]]);
} catch (ApiError $e) { Api::respond(['error'=>['code'=>$e->errorCode,'message'=>$e->getMessage()]],$e->status); }
catch (DomainException $e) { Api::respond(['error'=>['code'=>'validation','message'=>$e->getMessage()]],422); }
catch (Throwable) { Api::respond(['error'=>['code'=>'unavailable','message'=>'Could not mark notifications as read.']],503); }
