<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
use App\Core\Auth;
use App\Core\View;
use App\Services\EventWorkspaceService;
$user=Auth::requireLogin();
Auth::requireAnyPermission(['view_events','manage_events']);
header('Cache-Control: no-store');
if (str_starts_with($_SERVER['REQUEST_URI']??'', '/admin/') !== Auth::usesAdminArea()) {
    redirect((Auth::usesAdminArea()?'/admin/event.php?id=':'/event.php?id=').(int)($_GET['id']??0));
}
try {
    $event=EventWorkspaceService::event((int)($_GET['id']??0),(int)$user['id']);
    View::render('shared/event',['title'=>$event['title'].' · Events','active'=>'events','event'=>$event]);
} catch(DomainException $e) {http_response_code(404);View::render('shared/event-unavailable',['title'=>'Event unavailable','active'=>'events']);}
