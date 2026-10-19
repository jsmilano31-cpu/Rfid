<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
ob_start();

use App\Core\Database;
use App\Core\Auth;
use App\Services\UserPreferenceService;

$server = new PDO('mysql:host=127.0.0.1;port=' . env('TEST_DB_PORT','3340') . ';charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'preferences_' . bin2hex(random_bytes(6));
$server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
$server->exec("USE `{$database}`");
(new ReflectionProperty(Database::class, 'connection'))->setValue(null, $server);
$checks = 0;
function preferenceCheck(bool $condition, string $label): void { global $checks; if (!$condition) throw new RuntimeException($label); $checks++; echo "PASS {$label}\n"; }
function preferenceSql(PDO $pdo, string $path): void { $sql=preg_replace('/^\s*--.*$/m','',file_get_contents($path)); foreach(explode(';',$sql) as $statement) if(trim($statement)!=='') $pdo->exec($statement); }
try {
    preferenceSql($server, BASE_PATH . '/database/schema.sql');
    preferenceSql($server, BASE_PATH . '/database/seed.sql');
    $server->prepare('UPDATE users SET password_hash=?')->execute([password_hash('Preference123!', PASSWORD_DEFAULT)]);
    Auth::attempt('2026-0001','Preference123!');
    $defaults = UserPreferenceService::get(5);
    preferenceCheck($defaults['accent_theme']==='blue' && $defaults['theme_mode']==='system', 'existing users receive blue system defaults');
    $saved = UserPreferenceService::update(5, ['start_page'=>'attendance','theme_mode'=>'dark','accent_theme'=>'purple','reduced_motion'=>true,'compact_mode'=>true,'push_enabled'=>false,'notify_attendance'=>false,'notify_events'=>true,'notify_recollections'=>false,'notify_messages'=>true]);
    preferenceCheck($saved['accent_theme']==='purple' && $saved['compact_mode'] && !$saved['push_enabled'], 'appearance and push preferences persist');
    preferenceCheck(!UserPreferenceService::allowsKind(5,'attendance') && !UserPreferenceService::allowsKind(5,'activity','recollection') && UserPreferenceService::allowsKind(5,'activity','event'), 'notification categories filter independently');
    preferenceCheck(UserPreferenceService::landingUrl(5,false)==='/index/?page=attendance', 'default landing page changes portal entry');
    Auth::attempt('ADMIN-001','Preference123!');
    preferenceCheck(UserPreferenceService::landingUrl(5,true)==='/admin/?page=attendance', 'administrator landing page uses valid admin routes');
    echo "{$checks} settings checks passed.\n";
} finally { if (preg_match('/\Apreferences_[a-f0-9]{12}\z/',$database)) $server->exec("DROP DATABASE `{$database}`"); }
