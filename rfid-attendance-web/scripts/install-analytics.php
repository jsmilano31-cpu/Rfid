<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';
$pdo=\App\Core\Database::connection();
$schema=\App\Services\AnalyticsSchema::install($pdo);
$audit=\App\Services\AuditSchema::install($pdo);
$built=\App\Services\AnalyticsWorker::run($pdo,true);
echo json_encode(['schema'=>$schema,'audit'=>$audit,'build'=>$built],JSON_THROW_ON_ERROR).PHP_EOL;
