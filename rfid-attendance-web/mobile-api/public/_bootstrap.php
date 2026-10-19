<?php
declare(strict_types=1);
$private = dirname(__DIR__) . '/src/bootstrap.php';
if (!is_file($private)) $private = dirname(__DIR__, 3) . '/mobile-api/src/bootstrap.php';
require $private;
