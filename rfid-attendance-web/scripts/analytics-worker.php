<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/src/bootstrap.php';
session_write_close();
echo json_encode(\App\Services\AnalyticsWorker::run(\App\Core\Database::connection(),in_array('--rebuild',$argv,true)),JSON_THROW_ON_ERROR).PHP_EOL;
