<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Services\RecollectionService;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function recollection_json(array $payload,int $status=200):never{http_response_code($status);echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
$user=Auth::user();
if(!$user)recollection_json(['ok'=>false,'message'=>'Your session has expired. Sign in again.'],401);
if(!Auth::can('manage_recollections'))recollection_json(['ok'=>false,'message'=>'You do not have permission to manage recollections and retreats.'],403);
try{
    $id=(int)($_REQUEST['recollection_id']??0);
    if($_SERVER['REQUEST_METHOD']==='GET'){$record=RecollectionService::findForManagement($id);if(!$record)throw new DomainException('The selected activity could not be found.');recollection_json(['ok'=>true,'recollection'=>$record]);}
    if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');recollection_json(['ok'=>false,'message'=>'Method not allowed.'],405);}
    Csrf::verify();if(($_POST['action']??'')==='delete_recollection'){recollection_json(['ok'=>true,'message'=>'Activity deleted.','deleted'=>RecollectionService::delete($id,(int)$user['id'])]);}if(($_POST['action']??'')!=='update_recollection')throw new DomainException('That activity action is not available.');
    RecollectionService::update($id,$_POST,(int)$user['id']);recollection_json(['ok'=>true,'message'=>'Activity details and involvement updated.','recollection'=>RecollectionService::findForManagement($id)]);
}catch(DomainException $exception){recollection_json(['ok'=>false,'message'=>$exception->getMessage()],422);}catch(RuntimeException $exception){recollection_json(['ok'=>false,'message'=>$exception->getMessage()],419);}catch(Throwable $exception){recollection_json(['ok'=>false,'message'=>config('app')['debug']?$exception->getMessage():'The activity could not be updated.'],500);}
