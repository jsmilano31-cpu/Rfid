<?php
declare(strict_types=1);
// Isolated local database only. Never uses application database credentials.
require dirname(__DIR__).'/src/bootstrap.php';
use App\Core\Database;
use App\Services\NotificationService as N;
use App\Services\FcmService;
$pdo=new PDO('mysql:host=127.0.0.1;port=3338;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$name='notification_test_'.bin2hex(random_bytes(6));
$pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4"); $pdo->exec("USE `{$name}`");
(new ReflectionProperty(Database::class,'connection'))->setValue(null,$pdo);
$checks=0;
function check(bool $ok,string $label):void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; echo "PASS {$label}\n"; }
function sqlFile(PDO $pdo,string $path):void {
    $text=preg_replace('/^\s*--.*$/m','',file_get_contents($path));
    foreach (explode(';',$text) as $sql) if (trim($sql)!=='') $pdo->exec($sql);
}
try {
    sqlFile($pdo,BASE_PATH.'/database/schema.sql'); sqlFile($pdo,BASE_PATH.'/database/seed.sql');
    $pdo->exec('UPDATE attendance_records SET updated_at=DATE_SUB(NOW(),INTERVAL 2 DAY)');
    sqlFile($pdo,BASE_PATH.'/database/migrations/20260907_notifications.sql');
    sqlFile($pdo,BASE_PATH.'/database/migrations/20260907_notifications.sql');
    N::attendance(1,5); check(N::inbox(5)['unread']===0,'migration does not notify historical attendance');
    $pdo->exec('DELETE FROM attendance_records');
    N::device(5,str_repeat('a',120),true);
    $q=$pdo->prepare('INSERT INTO attendance_records (class_offering_id,student_id,attendance_date,status,source) VALUES (1,5,?,?,"rfid")');
    for ($i=2;$i>=0;$i--) $q->execute([date('Y-m-d',time()-$i*86400),'Late']);
    N::attendance(1,5); $inbox=N::inbox(5);
    check(count($inbox['items'])===3,'one notification per late occurrence');
    check(str_contains($inbox['items'][0]['body'],'3rd late occurrence'),'third late occurrence wording');
    check((int)$pdo->query('SELECT COUNT(*) FROM notification_outbox')->fetchColumn()===3,'transactional outbox for registered device');
    N::attendance(1,5); check(N::inbox(5)['unread']===3,'repeated scan sync does not duplicate');
    $item=$inbox['items'][0]; N::read(6,[['id'=>$item['id'],'revision'=>1]]);
    check(N::inbox(5)['unread']===3,'another user cannot mark a notification read');
    N::read(5,[['id'=>$item['id'],'revision'=>1]]); check(N::inbox(5)['unread']===2,'read status persists');
    $pdo->exec('UPDATE attendance_records SET status="Absent" WHERE attendance_date=CURDATE()'); N::attendance(1,5);
    check(N::inbox(5)['items'][0]['revision']==2,'changed status updates notification revision');
    N::read(5,[['id'=>$item['id'],'revision'=>1]]); check(N::inbox(5)['unread']===3,'stale read cannot hide a corrected notification');
    $pdo->exec('UPDATE attendance_records SET status="Present" WHERE attendance_date=CURDATE()'); N::attendance(1,5);
    check(N::inbox(5)['items'][0]['title']==='Attendance corrected','warning is replaced after correction');
    $pdo->exec('UPDATE events SET status="closed"'); $pdo->exec('UPDATE recollections SET status="closed"');
    $start=time()+7200;
    $q=$pdo->prepare('INSERT INTO events (title,event_date,start_time,end_time,venue,status,created_by) VALUES (?,?,?,?,?,"scheduled",1)');
    $q->execute(['Test event',date('Y-m-d',$start),date('H:i:s',$start),date('H:i:s',$start+1800),'Main Hall']); $event=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO event_participants (event_id,user_id) VALUES (?,5)')->execute([$event]);
    N::syncUser(5); N::syncUser(5); $eventRows=$pdo->query("SELECT * FROM notifications WHERE target_type='event' AND target_id={$event}")->fetchAll();
    check(count($eventRows)===1 && str_contains($eventRows[0]['event_key'],'24h'),'24-hour reminder is deduplicated');
    N::syncUser(6); check(N::inbox(6)['unread']===0,'unrelated users do not receive activity reminders');
    $start=time()+1800; $pdo->prepare('UPDATE events SET event_date=?,start_time=? WHERE id=?')->execute([date('Y-m-d',$start),date('H:i:s',$start),$event]);
    N::syncUser(5); check((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE target_type='event' AND target_id={$event}")->fetchColumn()===2,'one-hour reminder supported');
    $pdo->prepare('UPDATE events SET status="cancelled" WHERE id=?')->execute([$event]); N::syncUser(5);
    check((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE target_type='event' AND target_id={$event} AND title='Activity reminder withdrawn'")->fetchColumn()===2,'cancelled reminders are withdrawn');
    $q=$pdo->prepare('INSERT INTO recollections (title,type,section_id,date,start_time,end_time,venue,status,created_by) VALUES (?,"Retreat",1,?,?,?,"Chapel","scheduled",1)');
    $q->execute(['Test retreat',date('Y-m-d',$start),date('H:i:s',$start),date('H:i:s',$start+1800)]); $retreat=(int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO recollection_participants (recollection_id,user_id) VALUES (?,5)')->execute([$retreat]); N::syncUser(5);
    check((int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE target_type='recollection' AND target_id={$retreat}")->fetchColumn()===1,'retreat reminder follows participation');
    N::device(6,str_repeat('a',120),true); check((int)$pdo->query('SELECT user_id FROM notification_devices')->fetchColumn()===6,'device token rebinds on account change');
    N::revokeSession(); check((int)$pdo->query('SELECT active FROM notification_devices')->fetchColumn()===0,'logout revokes device binding');
    $_ENV['FCM_SERVICE_ACCOUNT_FILE']=''; check(FcmService::dispatch()===0,'missing Firebase configuration preserves pending queue');
    check(N::ordinal(11)==='11th' && N::ordinal(22)==='22nd','ordinal edge cases');
    echo "{$checks} integration checks passed.\n";
} finally {
    // Name is generated above, never supplied or read from an application config.
    if (preg_match('/\Anotification_test_[a-f0-9]{12}\z/',$name)) $pdo->exec("DROP DATABASE `{$name}`");
}
