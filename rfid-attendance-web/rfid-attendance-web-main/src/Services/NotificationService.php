<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use PDO;

final class NotificationService
{
    private static function rows(string $sql, array $args = []): array
    {
        $q = Database::connection()->prepare($sql); $q->execute($args); return $q->fetchAll();
    }

    public static function enabled(): bool
    {
        static $enabled;
        if ($enabled !== null) return $enabled;
        try {
            Database::connection()->exec("SET time_zone = '+08:00'");
            return $enabled = (bool) self::rows('SELECT id FROM notification_settings WHERE id=1');
        }
        catch (\PDOException $e) { if ($e->getCode() === '42S02') return $enabled = false; throw $e; }
    }

    /** Every mutation and its outbox entry commit together; duplicate producers are harmless. */
    public static function put(int $user, string $key, string $signature, string $kind, string $title, string $body, string $type, int $target, ?string $expires = null): void
    {
        $pdo = Database::connection(); $owns = !$pdo->inTransaction();
        if ($owns) $pdo->beginTransaction();
        try {
            $q = $pdo->prepare('INSERT INTO notifications (user_id,event_key,fingerprint,kind,title,body,target_type,target_id,expires_at) VALUES (?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
            $fingerprint = hash('sha256', $signature);
            $q->execute([$user,$key,$fingerprint,$kind,$title,$body,$type,$target,$expires]);
            $id = (int) $pdo->lastInsertId(); $inserted = $q->rowCount() === 1;
            $row = self::rows('SELECT * FROM notifications WHERE id=? FOR UPDATE', [$id])[0];
            if (!$inserted && $row['fingerprint'] !== $fingerprint) {
                $pdo->prepare('UPDATE notifications SET fingerprint=?,revision=revision+1,kind=?,title=?,body=?,expires_at=?,read_at=NULL,updated_at=NOW() WHERE id=?')
                    ->execute([$fingerprint,$kind,$title,$body,$expires,$id]);
                $row['revision']++; $inserted = true;
            } elseif (!$inserted && ($row['body'] !== $body || $row['title'] !== $title)) {
                // Recalculate historical late counts after corrections without resending warnings.
                $pdo->prepare('UPDATE notifications SET title=?,body=? WHERE id=?')->execute([$title,$body,$id]);
            }
            if ($inserted) {
                $pdo->prepare('INSERT IGNORE INTO notification_outbox (notification_id,revision,device_id) SELECT ?,?,id FROM notification_devices WHERE user_id=? AND active=1 AND updated_at>DATE_SUB(NOW(),INTERVAL 30 DAY)')
                    ->execute([$id,$row['revision'],$user]);
            }
            if ($owns) $pdo->commit();
        } catch (\Throwable $e) { if ($owns && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    public static function ordinal(int $n): string
    {
        return $n . (in_array($n % 100, [11,12,13], true) ? 'th' : match($n % 10) { 1=>'st',2=>'nd',3=>'rd',default=>'th' });
    }

    public static function attendance(int $offering, ?int $user = null): void
    {
        if (!self::enabled()) return;
        $args = [$offering]; $where = '';
        if ($user !== null) { $where = ' AND a.student_id=?'; $args[] = $user; }
        $rows = self::rows('SELECT a.*,c.course_code,o.component_type FROM attendance_records a JOIN class_offerings o ON o.id=a.class_offering_id JOIN courses c ON c.id=o.course_id JOIN users u ON u.id=a.student_id WHERE a.class_offering_id=? AND u.status="active" AND a.updated_at >= (SELECT enabled_at FROM notification_settings WHERE id=1)' . $where, $args);
        foreach ($rows as $a) {
            $key = 'attendance:' . $a['id'];
            $existing = self::rows('SELECT id FROM notifications WHERE user_id=? AND event_key=?', [$a['student_id'],$key]);
            if (!in_array($a['status'], ['Late','Absent'], true) && !$existing) continue;
            $subject = $a['course_code'] . ' ' . $a['component_type'];
            if ($a['status'] === 'Late') {
                $count = (int) self::rows('SELECT COUNT(*) total FROM attendance_records WHERE student_id=? AND class_offering_id=? AND status="Late" AND attendance_date<=?', [$a['student_id'],$offering,$a['attendance_date']])[0]['total'];
                $title = 'Late attendance warning';
                $body = 'Warning: this is your ' . self::ordinal($count) . " late occurrence in {$subject} ({$a['attendance_date']}).";
            } elseif ($a['status'] === 'Absent') {
                $title = 'Absence recorded'; $body = "You are marked absent in {$subject} on {$a['attendance_date']}. Open attendance to review the record.";
            } else {
                $title = 'Attendance corrected'; $body = "Your attendance in {$subject} on {$a['attendance_date']} is now {$a['status']}. This replaces the earlier warning.";
            }
            self::put((int)$a['student_id'],$key,$a['status'], 'attendance',$title,$body,'course',$offering);
        }
    }

    /** Called after writes; cron reconciles if generation fails. Never fail an RFID scan for push. */
    public static function afterAttendance(int $offering, ?int $user = null): void
    {
        try { self::attendance($offering,$user); self::dispatchAfterResponse(); }
        catch (\Throwable $e) { error_log('Notification generation failed: ' . get_class($e)); }
    }

    public static function afterActivity(string $type,int $id): void
    {
        try {
            if (!self::enabled() || !in_array($type,['event','recollection'],true)) return;
            $table=$type==='event'?'event_participants':'recollection_participants';
            $foreign=$type.'_id';
            $users=self::rows("SELECT user_id FROM {$table} WHERE {$foreign}=? UNION SELECT user_id FROM notifications WHERE target_type=? AND target_id=?",[$id,$type,$id]);
            foreach ($users as $user) {
                if (self::rows('SELECT id FROM users WHERE id=? AND status="active"',[$user['user_id']])) self::syncUser((int)$user['user_id']);
            }
            self::dispatchAfterResponse();
        } catch (\Throwable $e) { error_log('Activity notification generation failed: '.get_class($e)); }
    }

    public static function syncUser(int $user): void
    {
        if (!self::enabled()) return;
        foreach (self::rows('SELECT DISTINCT class_offering_id FROM attendance_records WHERE student_id=? AND updated_at >= (SELECT enabled_at FROM notification_settings WHERE id=1)', [$user]) as $row) {
            self::attendance((int)$row['class_offering_id'], $user);
        }
        foreach (['event','recollection'] as $type) {
            $table = $type === 'event' ? 'events' : 'recollections';
            $participants = $type === 'event' ? 'event_participants' : 'recollection_participants';
            $foreign = $type . '_id'; $date = $type === 'event' ? 'event_date' : 'date';
            $activities = self::rows("SELECT a.* FROM {$table} a JOIN {$participants} p ON p.{$foreign}=a.id WHERE p.user_id=? AND a.{$date} BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 2 DAY)", [$user]);
            foreach ($activities as $a) {
                if (!in_array($a['status'], ['scheduled','open'], true)) continue;
                $start = $a[$date] . ' ' . $a['start_time']; $seconds = strtotime($start) - time();
                if ($seconds <= 0 || $seconds > 86400) continue;
                // One appropriate reminder after downtime; never send both windows together.
                $window = $seconds <= 3600 ? '1h' : '24h';
                $label = $type === 'event' ? 'Event' : $a['type'];
                $body = "{$a['title']} starts " . date('M j, g:i A', strtotime($start)) . " at {$a['venue']}.";
                if ($a['attendance_required']) $body .= ' Attendance is required.';
                $scheduleKey=substr(hash('sha256',$start),0,12);
                self::put($user,"{$type}:{$a['id']}:{$scheduleKey}:{$window}", $start . '|' . $a['venue'] . '|' . $a['title'], 'activity', "Upcoming {$label}", $body,$type,(int)$a['id'],$start);
            }
            // Withdraw or correct reminders already issued after cancellation, edits, or removal.
            $prior = self::rows("SELECT n.*,a.title activity_title,a.status activity_status,a.{$date} activity_date,a.start_time,a.venue,p.user_id participant FROM notifications n LEFT JOIN {$table} a ON a.id=n.target_id LEFT JOIN {$participants} p ON p.{$foreign}=a.id AND p.user_id=n.user_id WHERE n.user_id=? AND n.target_type=? AND n.created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY)",[$user,$type]);
            foreach ($prior as $n) {
                $cancelled = !$n['participant'] || !$n['activity_status'] || in_array($n['activity_status'],['draft','cancelled'],true);
                if ($cancelled) {
                    self::put($user,$n['event_key'],'withdrawn','activity_update','Activity reminder withdrawn','This activity was cancelled or is no longer assigned to you. Check the campus calendar for current activities.',$type,(int)$n['target_id']);
                } elseif (in_array($n['activity_status'],['scheduled','open'],true)) {
                    $start = $n['activity_date'] . ' ' . $n['start_time'];
                    $signature = $start . '|' . $n['venue'] . '|' . $n['activity_title'];
                    if ($n['fingerprint'] !== hash('sha256',$signature) && strtotime($start) > time()) {
                        self::put($user,$n['event_key'],$signature,'activity_update','Activity schedule updated',"{$n['activity_title']} is scheduled for " . date('M j, g:i A',strtotime($start)) . " at {$n['venue']}.",$type,(int)$n['target_id'],$start);
                    }
                }
            }
        }
    }

    public static function inbox(int $user, int $before = 0): array
    {
        if (!self::enabled()) throw new \RuntimeException('Notification database migration is required.');
        $args = [$user]; $where = '';
        if ($before > 0) { $where = ' AND id<?'; $args[] = $before; }
        $items = self::rows('SELECT id,revision,kind,title,body,target_type,target_id,read_at,created_at,updated_at FROM notifications WHERE user_id=?' . $where . ' ORDER BY id DESC LIMIT 100',$args);
        $items = array_values(array_filter($items, static fn(array $item): bool => UserPreferenceService::allowsKind($user, (string)$item['kind'], (string)$item['target_type'])));
        $items = array_slice($items, 0, 50);
        $unread = (int) count(array_filter(self::rows('SELECT kind,target_type FROM notifications WHERE user_id=? AND read_at IS NULL',[$user]), static fn(array $item): bool => UserPreferenceService::allowsKind($user, (string)$item['kind'], (string)$item['target_type'])));
        return ['items'=>$items,'unread'=>$unread, 'next_before'=>count($items)===50?(int)end($items)['id']:null];
    }

    public static function read(int $user, array $items): void
    {
        if (count($items)>50) throw new \DomainException('Select up to 50 notifications.');
        $q=Database::connection()->prepare('UPDATE notifications SET read_at=COALESCE(read_at,NOW()) WHERE user_id=? AND id=? AND revision=?');
        foreach ($items as $item) {
            if (!is_array($item) || !isset($item['id'],$item['revision']) || !ctype_digit((string)$item['id']) || !ctype_digit((string)$item['revision'])) throw new \DomainException('Invalid notification selection.');
            $q->execute([$user,(int)$item['id'],(int)$item['revision']]);
        }
    }

    public static function device(int $user,string $token,bool $enabled): void
    {
        if (!preg_match('/\A[A-Za-z0-9_:\-]{20,4096}\z/',$token)) throw new \DomainException('Invalid device token.');
        $pdo=Database::connection();
        if ($enabled && !UserPreferenceService::get($user)['push_enabled']) throw new \DomainException('Push notifications are disabled in Settings.');
        if (!$enabled) { $pdo->prepare('UPDATE notification_devices SET active=0 WHERE user_id=? AND token_hash=?')->execute([$user,hash('sha256',$token)]); return; }
        $pdo->prepare('INSERT INTO notification_devices (user_id,token_hash,token,session_hash) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),token=VALUES(token),session_hash=VALUES(session_hash),active=1,updated_at=NOW()')
            ->execute([$user,hash('sha256',$token),$token,hash('sha256',session_id())]);
    }

    public static function revokeSession(): void
    {
        if (self::enabled()) Database::connection()->prepare('UPDATE notification_devices SET active=0 WHERE session_hash=?')->execute([hash('sha256',session_id())]);
    }

    public static function dispatchAfterResponse(): void
    {
        static $registered=false;
        if ($registered || !FcmService::configured()) return;
        $registered=true;
        register_shutdown_function(static function (): void {
            if (Database::connection()->inTransaction()) return;
            if (session_status()===PHP_SESSION_ACTIVE) session_write_close();
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            try { FcmService::dispatch(10); } catch (\Throwable $e) { error_log('Push deferred to worker: '.get_class($e)); }
        });
    }
}
