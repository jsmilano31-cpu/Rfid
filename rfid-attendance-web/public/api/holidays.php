<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/src/bootstrap.php';
use App\Core\Auth;
use App\Core\Csrf;
use App\Services\PhilippineHolidayService;
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function holiday_json(array $data,int $status=200): never { http_response_code($status);echo json_encode($data,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit; }
$user=Auth::user();if(!$user)holiday_json(['ok'=>false,'message'=>'Your session has expired. Sign in again.'],401);
session_write_close();
try {
    if ($_SERVER['REQUEST_METHOD']==='GET') {
        if (!Auth::canAny(['view_schedule','view_events','manage_events','view_recollections','manage_recollections','manage_academic_calendar'])) holiday_json(['ok'=>false,'message'=>'You do not have permission to view the campus calendar.'],403);
        holiday_json(PhilippineHolidayService::calendar((int)($_GET['year']??date('Y'))));
    }
    if ($_SERVER['REQUEST_METHOD']!=='POST') {header('Allow: GET, POST');holiday_json(['ok'=>false,'message'=>'Method not allowed.'],405);}
    if (!PhilippineHolidayService::canManage()) holiday_json(['ok'=>false,'message'=>'You do not have permission to change the campus academic calendar.'],403);
    Csrf::verify();
    PhilippineHolidayService::save($_POST,(int)$user['id']);
    holiday_json(PhilippineHolidayService::calendar((int)substr((string)$_POST['date'],0,4))+['message'=>(($_POST['no_classes']??'')==='1'?'Campus no-class holiday saved. Students will not be marked absent on this date.':'Campus holiday override removed. Normal scheduling rules apply.')]);
} catch (DomainException $e) { holiday_json(['ok'=>false,'message'=>$e->getMessage()],422);
} catch (RuntimeException $e) { holiday_json(['ok'=>false,'message'=>'The request could not be verified. Refresh the page and try again.'],419);
} catch (Throwable $e) { error_log('Campus holiday request failed: '.$e->getMessage());holiday_json(['ok'=>false,'message'=>'The holiday request could not be completed. Please try again.'],500); }
