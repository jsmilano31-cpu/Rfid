<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__).'/src/bootstrap.php';
session_write_close();
use App\Core\Database;
use App\Services\NotificationService;
use App\Services\FcmService;

$pdo=Database::connection();
// The existing every-minute cron also schedules Analytics; no public refresh runner.
if ($pdo->query('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="analytics_state"')->fetchColumn()) {
    $analyticsBuilt=$pdo->query('SELECT built_at FROM analytics_state WHERE id=1')->fetchColumn();
    if (!$analyticsBuilt || strtotime($analyticsBuilt)<time()-240) {
        try { \App\Services\AnalyticsWorker::run($pdo); }
        catch (Throwable $error) { error_log('Analytics worker: '.$error->getMessage()); }
        $analyticsBuilt=$pdo->query('SELECT built_at FROM analytics_state WHERE id=1')->fetchColumn();
    }
}
if (!NotificationService::enabled()) { fwrite(STDERR,"Run 20260907_notifications.sql first.\n"); exit(1); }
if (!$pdo->query("SELECT GET_LOCK('campus_notification_generate',0)")->fetchColumn()) exit;
try {
    $after=0;
    do {
        $q=$pdo->prepare('SELECT id FROM users WHERE status="active" AND id>? ORDER BY id LIMIT 100'); $q->execute([$after]); $users=$q->fetchAll();
        foreach ($users as $user) { $after=(int)$user['id']; NotificationService::syncUser($after); }
    } while ($users);
} finally { $pdo->query("SELECT RELEASE_LOCK('campus_notification_generate')"); }
$sent=FcmService::dispatch(100);
echo 'Sent: '.$sent.PHP_EOL;
if (!FcmService::configured()) echo "In-app notifications generated; FCM is not configured.\n";
// Private completion marker lets operators verify cron without exposing a public runner.
$directory=BASE_PATH.'/storage/private';
if (!is_dir($directory)) mkdir($directory,0700,true);
if (file_put_contents($directory.'/notification-worker-status.json',json_encode([
    'completed_at'=>date(DATE_ATOM),'sent'=>$sent,'push_configured'=>FcmService::configured(),
    'analytics_built_at'=>$analyticsBuilt ?? null,
],JSON_THROW_ON_ERROR),LOCK_EX)===false) {
    fwrite(STDERR,"Could not write worker completion status.\n");
    exit(1);
}
