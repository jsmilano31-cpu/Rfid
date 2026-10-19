<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/rfid-attendance-web/src/bootstrap.php';

use App\Core\Database;
use App\Services\AnalyticsSchema;
use App\Services\AnalyticsWorker;
use App\Services\AuditSchema;

$local = __DIR__;
$db = config('database');
if (config('app')['environment'] !== 'local' || $db['host'] !== '127.0.0.1' ||
    $db['port'] !== 3306 || $db['database'] !== 'rfid_attendance' || $db['username'] !== 'campustrack_local') {
    throw new RuntimeException('Migration refused: this command requires the workspace local .env.');
}
$pdo = Database::connection();
$actualData = realpath((string)$pdo->query('SELECT @@datadir')->fetchColumn());
$expectedData = realpath($local . '/data/mysql');
if (!$expectedData || !$actualData || strcasecmp($actualData, $expectedData) !== 0) {
    throw new RuntimeException('Migration refused: database is not this workspace instance.');
}
$ledgerFile = $local . '/state/migrations.json';
$ledger = is_file($ledgerFile) ? json_decode(file_get_contents($ledgerFile), true, 512, JSON_THROW_ON_ERROR) : [];
$files = glob(BASE_PATH . '/database/migrations/*');
sort($files, SORT_STRING);

function alreadyInBase(PDO $pdo, string $sql): bool
{
    if (!preg_match('/\AALTER TABLE\s+`?(\w+)`?/i', $sql, $match)) return false;
    $table = $match[1];
    $columns = array_column($pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(), 'Field');
    $indexes = array_column($pdo->query("SHOW INDEX FROM `{$table}`")->fetchAll(), 'Key_name');
    if (preg_match_all('/ADD COLUMN\s+(?!IF\b)`?(\w+)`?/i', $sql, $added) &&
        !array_diff($added[1], $columns) && !preg_match('/\b(?:MODIFY|DROP)\b/i', $sql)) return true;
    if (preg_match_all('/ADD\s+(?:UNIQUE\s+)?(?:KEY|INDEX)\s+(?!IF\b)`?(\w+)`?/i', $sql, $added) &&
        !array_diff($added[1], $indexes) && !preg_match('/\b(?:COLUMN|CONSTRAINT|MODIFY|DROP)\b/i', $sql)) return true;
    if (preg_match('/DROP INDEX\s+`?(uq_devices_room|uq_offering)`?/i', $sql, $drop) && !in_array($drop[1], $indexes, true)) return true;
    // The old RFID migration's transition ENUM accidentally repeats 'rfid'.
    // The fresh schema already contains its canonical final ENUM. Do not replay
    // that invalid historical intermediate definition over the final schema.
    if (str_contains($sql, "ENUM('rfid', 'rfid', 'manual', 'system')")) {
        $fields = array_column($pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(), null, 'Field');
        if (($fields['source']['Type'] ?? '') !== "enum('rfid','manual','system')") {
            throw new RuntimeException('Unexpected RFID source schema: ' . $table);
        }
        return true;
    }
    return false;
}

foreach ($files as $file) {
    $name = basename($file);
    $hash = hash_file('sha256', $file);
    if (isset($ledger[$name])) {
        if ($ledger[$name]['sha256'] !== $hash) throw new RuntimeException('Previously applied migration changed: ' . $name);
        echo 'Already completed: ' . $name . PHP_EOL;
        continue;
    }
    $applied = 0; $covered = 0;
    if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
        $sql = preg_replace('/^[\t ]*--[^\r\n]*(?:\r?\n|$)/m', "\n", file_get_contents($file));
        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);
            if ($statement === '') continue;
            if (preg_match('/\AUSE\s+/i', $statement) && !preg_match('/\AUSE\s+`?rfid_attendance`?\z/i', $statement)) {
                throw new RuntimeException('Unexpected database selection in ' . $name);
            }
            if (alreadyInBase($pdo, $statement)) { $covered++; continue; }
            $statement = preg_replace('/\ACREATE TABLE (?!IF\b)/i', 'CREATE TABLE IF NOT EXISTS ', $statement);
            try { $pdo->exec($statement); }
            catch (PDOException $error) {
                throw new RuntimeException('Migration failed: ' . $name . ' [SQLSTATE ' . $error->getCode() . '] ' . $error->getMessage(), 0, $error);
            }
            $applied++;
        }
        if ($name === '20261003_analytics.sql') {
            AnalyticsSchema::install($pdo);
            AuditSchema::install($pdo);
            echo 'Analytics history and transactional audit triggers installed.' . PHP_EOL;
        }
    } elseif (in_array($name, ['20261006_event_tracking.php', '20261007_recollection_range.php'], true)) {
        $result = require $file;
        echo json_encode($result, JSON_THROW_ON_ERROR) . PHP_EOL;
        $applied++;
    } else {
        $environment = getenv();
        foreach (['APP_ENV'=>'local', 'DB_HOST'=>'127.0.0.1', 'DB_PORT'=>'3306', 'DB_DATABASE'=>'rfid_attendance',
                  'DB_USERNAME'=>'campustrack_local', 'DB_PASSWORD'=>$db['password']] as $key=>$value) $environment[$key] = $value;
        $process = proc_open([PHP_BINARY, $file], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, BASE_PATH, $environment, ['bypass_shell'=>true]);
        if (!is_resource($process)) throw new RuntimeException('Cannot start PHP migration: ' . $name);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
        $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) throw new RuntimeException('PHP migration failed: ' . $name . PHP_EOL . $errors . $output);
        echo $output;
        $applied++;
    }
    $ledger[$name] = ['sha256'=>$hash,'applied_statements'=>$applied,'covered_by_base'=>$covered,'completed_at'=>date(DATE_ATOM)];
    file_put_contents($ledgerFile, json_encode($ledger, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo 'Completed: ' . $name . ' (applied ' . $applied . ', already in base ' . $covered . ')' . PHP_EOL;
}

$roleMarker = $local . '/state/fresh-role-consolidation.json';
if (is_file($local . '/state/base-imported.json') && !is_file($roleMarker)) {
    // The fresh seed already contains the two new teacher permission codes.
    // Replaying older migrations grants legacy teacher management capabilities,
    // so the newest migration's normal "new codes" detection does not run its
    // one-time reset. On this new demo database, perform that reset exactly once.
    $teacherMigration = BASE_PATH . '/database/migrations/20261005_teacher_portal.sql';
    $teacherSql = preg_replace('/^[\t ]*--[^\r\n]*(?:\r?\n|$)/m', "\n", file_get_contents($teacherMigration));
    $teacherSql = preg_replace('/SET @teacher_portal_install\s*=.*?;/s', 'SET @teacher_portal_install = 1;', $teacherSql, 1);
    $pdo->beginTransaction();
    try {
        foreach (explode(';', $teacherSql) as $statement) if (trim($statement) !== '') $pdo->exec($statement);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
    file_put_contents($roleMarker, json_encode(['migration'=>'20261005_teacher_portal.sql','completed_at'=>date(DATE_ATOM)], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo 'Fresh demo Teacher grants consolidated to the latest portal migration.' . PHP_EOL;
}

// Refresh complete trigger coverage after the final two PHP schema installers,
// then build analytics against the local demo data.
AnalyticsSchema::install($pdo);
$audit = AuditSchema::install($pdo);
$analytics = AnalyticsWorker::run($pdo, true);
$summary = ['database'=>$db['database'], 'migrations'=>count($ledger),
    'tables'=>(int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn(),
    'triggers'=>(int)$pdo->query('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchColumn(),
    'users'=>(int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'courses'=>(int)$pdo->query('SELECT COUNT(*) FROM courses')->fetchColumn(),
    'audit'=>$audit, 'analytics'=>$analytics];
file_put_contents($local . '/state/database-summary.json', json_encode($summary, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo json_encode($summary, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR) . PHP_EOL;
