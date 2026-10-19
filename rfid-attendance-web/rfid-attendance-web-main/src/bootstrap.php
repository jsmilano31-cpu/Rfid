<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/src/helpers.php';

load_env(BASE_PATH . '/.env');

$publicDirectory = (string) env('PUBLIC_DIRECTORY', 'public');
if (!preg_match('/\A[a-zA-Z0-9._-]+\z/', $publicDirectory)) {
    throw new RuntimeException('PUBLIC_DIRECTORY must be a directory name relative to the application root.');
}
define('PUBLIC_PATH', BASE_PATH . DIRECTORY_SEPARATOR . $publicDirectory);

$appConfig = require BASE_PATH . '/config/app.php';
date_default_timezone_set($appConfig['timezone']);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $path = BASE_PATH . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_name('rfid_attendance_session');
    session_start();
}

if ($appConfig['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
}
