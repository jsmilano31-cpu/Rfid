<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/src/bootstrap.php';
use App\Core\Auth;
use App\Services\DeletionConflictService;
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function deletion_conflict_json(array $payload,int $status=200):never{http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
try{
    $user=Auth::user();if(!$user)deletion_conflict_json(['ok'=>false,'message'=>'Your session has expired. Sign in again.'],401);
    if($_SERVER['REQUEST_METHOD']!=='GET')deletion_conflict_json(['ok'=>false,'message'=>'Method not allowed.'],405);
    $type=(string)($_GET['type']??'');
    if(!Auth::can(DeletionConflictService::permissionFor($type)))deletion_conflict_json(['ok'=>false,'message'=>'You do not have permission to inspect these linked records.'],403);
    DeletionConflictService::restriction($type,(int)($_GET['id']??0),(int)$user['id']);
    deletion_conflict_json(['ok'=>true]+DeletionConflictService::inspect($type,(int)($_GET['id']??0),isset($_GET['group'])?(string)$_GET['group']:null,(int)($_GET['page']??1)));
}catch(DomainException $exception){deletion_conflict_json(['ok'=>false,'message'=>$exception->getMessage()],422);}
catch(Throwable $exception){error_log('Deletion conflict lookup failed: '.$exception->getMessage());deletion_conflict_json(['ok'=>false,'message'=>'The linked records could not be loaded.'],500);}
