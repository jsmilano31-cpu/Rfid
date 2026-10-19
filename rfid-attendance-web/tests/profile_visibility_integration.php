<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Services\DirectoryProfileService;

$server = new PDO('mysql:host=127.0.0.1;port=3338;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'profile_visibility_' . bin2hex(random_bytes(6));
$server->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4");
$server->exec("USE `{$database}`");
(new ReflectionProperty(Database::class, 'connection'))->setValue(null, $server);
$checks = 0;
function profileCheck(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
    echo "PASS {$label}\n";
}
function profileSql(PDO $pdo, string $path): void
{
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents($path));
    foreach (explode(';', $sql) as $statement) if (trim($statement) !== '') $pdo->exec($statement);
}
function become(int $id): array
{
    $_SESSION['user_id'] = $id;
    foreach (['user', 'actualUser', 'permissions'] as $property) (new ReflectionProperty(Auth::class, $property))->setValue(null, null);
    return Auth::user() ?? throw new RuntimeException('Test user missing');
}
try {
    profileSql($server, BASE_PATH . '/database/schema.sql');
    profileSql($server, BASE_PATH . '/database/seed.sql');
    profileSql($server, BASE_PATH . '/database/migrations/20260908_profile_visibility.sql');
    profileSql($server, BASE_PATH . '/database/migrations/20260908_profile_visibility.sql');
    $server->exec("UPDATE users SET profile_visibility='private' WHERE id=6");

    $private = DirectoryProfileService::profile(become(5), 6);
    profileCheck(($private['is_private'] ?? false) === true, 'private profile is blocked for another student');
    profileCheck(($private['profile']['first_name'] ?? '') === 'Ana' && !isset($private['profile']['email'], $private['profile']['school_id'], $private['profile']['profile_image_path']), 'private response exposes only display identity');
    profileCheck((int) $server->query('SELECT COUNT(*) FROM class_enrollments WHERE student_id=6')->fetchColumn() > 0, 'privacy does not remove core class enrollment');

    $self = DirectoryProfileService::profile(become(6), 6);
    profileCheck(!$self['is_private'] && isset($self['profile']['email']), 'profile owner retains full access');

    $admin = DirectoryProfileService::profile(become(1), 6);
    profileCheck(!$admin['is_private'] && isset($admin['profile']['email']), 'authorized administrator retains management access');
    echo "{$checks} profile visibility checks passed.\n";
} finally {
    unset($_SESSION['user_id']);
    if (preg_match('/\Aprofile_visibility_[a-f0-9]{12}\z/', $database)) $server->exec("DROP DATABASE `{$database}`");
}
