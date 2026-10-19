<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
ini_set('display_errors', '0');
require_once __DIR__ . '/Api.php';
set_exception_handler(static function (Throwable $error): void {
    if ($error instanceof ApiError) Api::respond(['error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]], $error->status);
    error_log('CampusTrack API: ' . get_class($error) . ' ' . $error->getMessage());
    Api::respond(['error' => ['code' => 'service_unavailable', 'message' => 'The campus service is temporarily unavailable. Please try again.']], 503);
});

$local = PHP_SAPI === 'cli-server';
if (!$local && empty($_SERVER['HTTPS']) && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== 'https') {
    throw new ApiError(400, 'https_required', 'Use the secure campus service URL.');
}
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.gc_maxlifetime', '28800');
session_name('campustrack_mobile');
session_set_cookie_params(['lifetime' => 28800, 'path' => $local ? '/' : '/rfid-attendance-web/public/', 'secure' => !$local, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
if (isset($_SESSION['expires_at']) && $_SESSION['expires_at'] < time()) {
    $_SESSION = [];
    session_regenerate_id(true);
}
require_once dirname(__DIR__, 2) . '/src/bootstrap.php';
// Production JSON responses never inherit display_errors from a local app configuration.
ini_set('display_errors', '0');
require_once __DIR__ . '/StudentApi.php';


require_once __DIR__ . '/StudentExtras.php';

require_once __DIR__ . '/TeacherApi.php';
