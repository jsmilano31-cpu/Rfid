<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Services\AcademicManagementService;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function academic_entity_json(array $payload, int $status = 200): never { http_response_code($status); echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); exit; }

$user=Auth::user();
if(!$user) academic_entity_json(['ok'=>false,'message'=>'Your session has expired. Sign in again.'],401);
if(!Auth::can('manage_academic_structure')) academic_entity_json(['ok'=>false,'message'=>'You do not have permission to manage academic records.'],403);
try {
    $entity=(string)($_REQUEST['entity']??'');
    if($_SERVER['REQUEST_METHOD']==='GET') {
        if(($_GET['mode']??'')==='enrollments') academic_entity_json(['ok'=>true]+AcademicManagementService::enrollmentRoster((int)($_GET['offering_id']??0),(string)($_GET['q']??''),(int)($_GET['page']??1)));
        academic_entity_json(['ok'=>true]+AcademicManagementService::editor($entity,(int)($_GET['id']??0)));
    }
    if($_SERVER['REQUEST_METHOD']!=='POST'){header('Allow: GET, POST');academic_entity_json(['ok'=>false,'message'=>'Method not allowed.'],405);}
    Csrf::verify();
    if(($_POST['action']??'')==='update_offering_enrollment') { AcademicManagementService::setEnrollment((int)($_POST['offering_id']??0),(int)($_POST['student_id']??0),(string)($_POST['status']??''),(int)$user['id']); academic_entity_json(['ok'=>true,'message'=>'Enrollment updated.']); }
    if(($_POST['action']??'')==='delete_academic_entity') { $deleted=AcademicManagementService::delete($entity,(int)($_POST['id']??0),(int)$user['id']); academic_entity_json(['ok'=>true,'message'=>ucfirst($entity).' deleted.','entity'=>$entity,'deleted'=>$deleted]); }
    if(($_POST['action']??'')!=='save_academic_entity') throw new DomainException('That academic management action is not available.');
    if($entity==='offering'&&!Auth::can('manage_teacher_assignments')) academic_entity_json(['ok'=>false,'message'=>'You do not have permission to manage teacher assignments.'],403);
    $record=AcademicManagementService::save($entity,$_POST,(int)$user['id']);
    academic_entity_json(['ok'=>true,'message'=>ucfirst($entity).' details saved.','entity'=>$entity,'record'=>$record]);
} catch(DomainException $exception){academic_entity_json(['ok'=>false,'message'=>$exception->getMessage()],422);}
catch(RuntimeException $exception){academic_entity_json(['ok'=>false,'message'=>$exception->getMessage()],419);}
catch(Throwable $exception){academic_entity_json(['ok'=>false,'message'=>config('app')['debug']?$exception->getMessage():'The academic record could not be saved.'],500);}
