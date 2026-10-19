<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/src/bootstrap.php';
use App\Core\Auth;
use App\Core\Csrf;
use App\Services\EventWorkspaceService;
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function event_json(array $data,int $status=200): never {http_response_code($status);echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
try {
    $user=Auth::user();if(!$user)event_json(['ok'=>false,'message'=>'Your session has expired. Sign in again.'],401);
    if(!Auth::canAny(['view_events','manage_events']))event_json(['ok'=>false,'message'=>'You do not have permission to view events.'],403);
    $viewer=(int)$user['id'];$id=(int)($_GET['event_id']??$_POST['event_id']??0);
    if($_SERVER['REQUEST_METHOD']==='GET') {
        $mode=(string)($_GET['mode']??'details');
        if($mode==='details')event_json(['ok'=>true,'event'=>EventWorkspaceService::event($id,$viewer)]);
        if($mode==='activity')event_json(['ok'=>true]+EventWorkspaceService::activity($id,$viewer,$_GET));
        if($mode==='live')event_json(['ok'=>true]+EventWorkspaceService::live($id,$viewer,isset($_GET['after'])?(int)$_GET['after']:null));
        throw new DomainException('Select a valid event view.');
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');event_json(['ok'=>false,'message'=>'Method not allowed.'],405);}
    Csrf::verify();
    if(($_POST['action']??'')==='manual_id')event_json(['ok'=>true,'scan'=>EventWorkspaceService::manualId($id,$viewer,(string)($_POST['school_id']??''),(string)($_POST['attendance_action']??''))]);
    if(($_POST['action']??'')==='set_status') {\App\Services\EventService::setStatus($id,(string)($_POST['status']??''),$viewer);event_json(['ok'=>true,'message'=>'Event attendance status updated.']);}
    throw new DomainException('That event action is not available.');
} catch(DomainException $e){event_json(['ok'=>false,'message'=>$e->getMessage()],422);}
catch(PDOException $e){error_log('Event workspace database: '.$e->getMessage());event_json(['ok'=>false,'message'=>'Event activity could not be loaded. Please retry.'],500);}
catch(RuntimeException $e){event_json(['ok'=>false,'message'=>'Your request could not be verified. Refresh and try again.'],419);}
catch(Throwable $e){error_log('Event workspace: '.$e->getMessage());event_json(['ok'=>false,'message'=>'The event request could not be completed. Please retry.'],500);}
