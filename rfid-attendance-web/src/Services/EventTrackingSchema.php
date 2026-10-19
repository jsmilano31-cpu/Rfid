<?php
declare(strict_types=1);
namespace App\Services;
use PDO;

/** Additive, repeatable installation. Legacy rows contain only actually saved timestamps. */
final class EventTrackingSchema
{
    public const VERSION='20261006-event-tracking-v1';
    public static function install(PDO $pdo): array
    {
        $fields=array_column($pdo->query('SHOW COLUMNS FROM events')->fetchAll(),'Field');
        if (!in_array('management_role_id',$fields,true)) {
            $pdo->exec('ALTER TABLE events ADD COLUMN management_role_id INT UNSIGNED NULL, ADD INDEX idx_event_management(created_by,management_role_id,event_date), ADD CONSTRAINT fk_event_management_role FOREIGN KEY(management_role_id) REFERENCES roles(id) ON DELETE SET NULL');
            // Establish ownership from the primary role at rollout; never infer past activity.
            $pdo->exec('UPDATE events e JOIN users u ON u.id=e.created_by SET e.management_role_id=u.role_id');
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS event_tracking_state(id TINYINT UNSIGNED PRIMARY KEY,version VARCHAR(64) NOT NULL,installed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        $pdo->exec('CREATE TABLE IF NOT EXISTS event_activity_logs(
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,event_id INT UNSIGNED NOT NULL,user_id INT UNSIGNED NOT NULL,
            action VARCHAR(24) NOT NULL,occurred_at DATETIME NOT NULL,source VARCHAR(16) NOT NULL,
            status VARCHAR(24) NOT NULL,recorded_by INT UNSIGNED NULL,legacy BOOLEAN NOT NULL DEFAULT FALSE,
            INDEX idx_event_activity_cursor(event_id,id), INDEX idx_event_activity_date(event_id,occurred_at,id),
            INDEX idx_event_activity_person(event_id,user_id,id), INDEX idx_event_activity_filter(event_id,action,source,id),
            CONSTRAINT fk_event_activity_event FOREIGN KEY(event_id) REFERENCES events(id),
            CONSTRAINT fk_event_activity_person FOREIGN KEY(user_id) REFERENCES users(id),
            CONSTRAINT fk_event_activity_actor FOREIGN KEY(recorded_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB');
        foreach(['INSERT','UPDATE'] as $op) {
            $body='BEGIN ';
            foreach(['check_in'=>'check_in_at','check_out'=>'check_out_at'] as $action=>$field) {
                $condition="NEW.{$field} IS NOT NULL".($op==='UPDATE'?" AND NOT(OLD.{$field} <=> NEW.{$field})":'');
                $body.="IF {$condition} THEN INSERT INTO event_activity_logs(event_id,user_id,action,occurred_at,source,status,recorded_by) VALUES(NEW.event_id,NEW.user_id,'{$action}',NEW.{$field},NEW.source,NEW.status,COALESCE(@ct_actor_id,NEW.recorded_by)); END IF; ";
            }
            $condition=$op==='INSERT'?'NEW.check_in_at IS NULL AND NEW.check_out_at IS NULL':'(NOT(OLD.status <=> NEW.status) OR NOT(OLD.notes <=> NEW.notes)) AND (OLD.check_in_at <=> NEW.check_in_at) AND (OLD.check_out_at <=> NEW.check_out_at)';
            $body.="IF {$condition} THEN INSERT INTO event_activity_logs(event_id,user_id,action,occurred_at,source,status,recorded_by) VALUES(NEW.event_id,NEW.user_id,'status_change',NOW(),NEW.source,NEW.status,COALESCE(@ct_actor_id,NEW.recorded_by)); END IF; END";
            $name='ct_event_activity_'.strtolower($op);
            $q=$pdo->prepare('SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$q->execute([$name]);
            if(trim((string)$q->fetchColumn())!==trim($body)) {$pdo->exec("DROP TRIGGER IF EXISTS {$name}");$pdo->exec("CREATE TRIGGER {$name} AFTER {$op} ON event_attendance FOR EACH ROW {$body}");}
        }
        if (!$pdo->query('SELECT 1 FROM event_tracking_state WHERE id=1')->fetchColumn()) {
            $pdo->beginTransaction();
            try {
                foreach(['check_in'=>'check_in_at','check_out'=>'check_out_at'] as $action=>$field) $pdo->exec("INSERT INTO event_activity_logs(event_id,user_id,action,occurred_at,source,status,recorded_by,legacy) SELECT event_id,user_id,'{$action}',{$field},source,status,recorded_by,1 FROM event_attendance WHERE {$field} IS NOT NULL AND NOT EXISTS(SELECT 1 FROM event_activity_logs l WHERE l.event_id=event_attendance.event_id AND l.user_id=event_attendance.user_id AND l.action='{$action}' AND l.occurred_at=event_attendance.{$field})");
                $pdo->exec("INSERT INTO event_activity_logs(event_id,user_id,action,occurred_at,source,status,recorded_by,legacy) SELECT event_id,user_id,'status_change',updated_at,source,status,recorded_by,1 FROM event_attendance WHERE check_in_at IS NULL AND check_out_at IS NULL AND NOT EXISTS(SELECT 1 FROM event_activity_logs l WHERE l.event_id=event_attendance.event_id AND l.user_id=event_attendance.user_id)");
                $pdo->prepare('INSERT INTO event_tracking_state(id,version) VALUES(1,?)')->execute([self::VERSION]);
                $pdo->commit();
            } catch (\Throwable $e) {$pdo->rollBack();throw $e;}
        }
        $audit=AuditSchema::install($pdo);
        return ['version'=>self::VERSION,'legacy_rows'=>(int)$pdo->query('SELECT COUNT(*) FROM event_activity_logs WHERE legacy=1')->fetchColumn(),'audit'=>$audit];
    }
}
