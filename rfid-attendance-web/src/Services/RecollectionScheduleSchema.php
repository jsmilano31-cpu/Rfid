<?php
declare(strict_types=1);
namespace App\Services;
use PDO;

final class RecollectionScheduleSchema
{
    public const VERSION='20261007-recollection-range-v1';
    public static function install(PDO $pdo): array
    {
        $columns=array_column($pdo->query('SHOW COLUMNS FROM recollections')->fetchAll(),null,'Field');
        if (!isset($columns['end_date'])) $pdo->exec('ALTER TABLE recollections ADD COLUMN end_date DATE NULL AFTER date');
        elseif ($columns['end_date']['Type']!=='date') throw new \RuntimeException('Recollection end-date schema drift.');
        $indexes=array_column($pdo->query('SHOW INDEX FROM recollections')->fetchAll(),'Key_name');
        if (!in_array('idx_recollection_dates',$indexes,true)) $pdo->exec('ALTER TABLE recollections ADD INDEX idx_recollection_dates(date,end_date)');
        $constraint=$pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='recollections' AND CONSTRAINT_NAME='chk_recollection_dates'")->fetchColumn();
        if (!$constraint) $pdo->exec('ALTER TABLE recollections ADD CONSTRAINT chk_recollection_dates CHECK(end_date IS NULL OR end_date>=date)');
        // The previous model explicitly represented a single-day activity.
        $pdo->exec('UPDATE recollections SET end_date=date,updated_at=updated_at WHERE end_date IS NULL');
        $tables=$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $history=in_array('analytics_state',$tables,true) ? AnalyticsSchema::refreshSource($pdo,'recollections') : AnalyticsSchema::install($pdo, false);
        $audit=in_array('audit_tracking_tables',$tables,true) ? AuditSchema::refreshTable($pdo,'recollections') : AuditSchema::install($pdo);
        // Preserve each snapshot's own date when extending the old single-day model.
        $pdo->exec("UPDATE analytics_history SET payload=JSON_SET(payload,'$.end_date',JSON_EXTRACT(payload,'$.date')) WHERE source_table='recollections' AND JSON_CONTAINS_PATH(payload,'one','$.end_date')=0");
        $pdo->prepare('INSERT INTO audit_logs(action,entity_type,details,actor_name,actor_role,search_text) SELECT "install_recollection_schedule","recollection",?,"Schedule upgrade","System",? WHERE NOT EXISTS(SELECT 1 FROM audit_logs WHERE action="install_recollection_schedule" AND details=?)')->execute([self::VERSION,self::VERSION,self::VERSION]);
        return ['version'=>self::VERSION,'history'=>$history,'audit'=>$audit];
    }
}
