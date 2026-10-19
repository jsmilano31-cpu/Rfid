<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/src/bootstrap.php';
use App\Core\Auth;
use App\Services\AnalyticsService;
use App\Services\AnalyticsExport;
header('Content-Type: application/json; charset=UTF-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if($_SERVER['REQUEST_METHOD']!=='GET'){http_response_code(405);header('Allow: GET');echo json_encode(['ok'=>false,'message'=>'Analytics is read-only.']);exit;}
if(!Auth::user()){http_response_code(401);echo json_encode(['ok'=>false,'message'=>'Your session has expired. Sign in again.']);exit;}
if(!Auth::can('view_analytics')){http_response_code(403);echo json_encode(['ok'=>false,'message'=>'You do not have permission to view Analytics.']);exit;}
try{
 foreach($_GET as $v)if(!is_scalar($v))throw new DomainException('Choose valid scalar filters.');
 $service=new AnalyticsService($_GET);$section=(string)($_GET['section']??'overview');$mode=(string)($_GET['mode']??'summary');
 if(!in_array($mode,['summary','records','export'],true))throw new DomainException('Choose a valid Analytics view.');
 if($mode==='export'){
  // Validate scope and fields before starting a streamed response.
  $panel=(string)($_GET['panel']??'');if($section==='resources' && $panel==='')$panel='schedule-detail';
  $sort=(string)($_GET['sort']??'date');$direction=(string)($_GET['direction']??'desc');
  $first=$service->records($section,1,100,$sort,$direction,$panel);$metadata=$service->exportMetadata($section);session_write_close();header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="campustrack-analytics-'.preg_replace('/[^a-z]/','',$section).'.csv"');
  $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");AnalyticsExport::heading($out,$section,$metadata,$first);
  $page=1;do{$data=$page===1?$first:$service->records($section,$page,100,$sort,$direction,$panel);foreach($data['rows'] as $r)AnalyticsExport::row($out,AnalyticsExport::record($data['columns'],$r));$page++;}while($data['has_more']);fclose($out);exit;
 }
 $payload=$mode==='records'?$service->records($section,(int)($_GET['record_page']??1),(int)($_GET['limit']??25),(string)($_GET['sort']??'date'),(string)($_GET['direction']??'desc'),(string)($_GET['panel']??'')):$service->response($section,($_GET['refresh']??'')==='1');if($mode==='records')$payload['metadata']=$service->metadata($section);session_write_close();echo json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}catch(DomainException $e){$restricted=str_contains($e->getMessage(),'scope');http_response_code($restricted?403:422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);}
catch(Throwable $e){error_log('Analytics: '.$e->getMessage());http_response_code(503);echo json_encode(['ok'=>false,'message'=>'Analytics could not be loaded. Please retry.']);}
