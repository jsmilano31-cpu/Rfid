<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';
echo json_encode(\App\Services\AuditSchema::install(\App\Core\Database::connection()), JSON_THROW_ON_ERROR) . PHP_EOL;
