<?php
declare(strict_types=1);
namespace App\Services;

use PDO;
use RuntimeException;

/** A prospective, transactional history; intentionally independent of audit redaction. */
final class AnalyticsSchema
{
    public const VERSION = '20261003-v1';
    public const SOURCES = [
        'users'=>['id','department_id','program_id','section_id','status'],
        'class_enrollments'=>['id','class_offering_id','student_id','status','enrolled_at'],
        'teacher_assignments'=>['id','class_offering_id','teacher_id'],
        'class_offerings'=>['id','course_id','section_id','semester_id','room_id','component_type','status'],
        'class_schedules'=>['id','class_offering_id','room_id','day_of_week','start_time','end_time'],
        'courses'=>['id','department_id','course_code','title','units'],
        'sections'=>['id','program_id','name'], 'programs'=>['id','department_id','code'],
        'semesters'=>['id','school_year_id','name','starts_on','ends_on'],
        'department_days_off'=>['id','department_id','day_of_week'],
        'teacher_time_off'=>['id','teacher_id','starts_on','ends_on','status'],
        'campus_holidays'=>['id','holiday_date','title','holiday_type','no_classes'],
        'attendance_settings'=>['id','early_check_in_minutes','grace_period_minutes','absent_after_minutes','tardies_per_absence'],
        'class_attendance_policies'=>['class_offering_id','early_check_in_minutes','grace_period_minutes','absent_after_minutes','tardies_per_absence'],
        'grading_settings'=>['id','has_prelim','has_midterm','has_final','prelim_weight','midterm_weight','final_weight','passing_grade'],
        'grades'=>['id','class_offering_id','student_id','prelim','midterm','final','overall','status','finalized_at'],
        'events'=>['id','title','event_date','end_date','start_time','end_time','attendance_required','check_out_required','suspends_classes','status'],
        'event_participants'=>['id','event_id','user_id','is_required'],
        'recollections'=>['id','title','section_id','date','end_date','start_time','end_time','attendance_required','status'],
        'recollection_participants'=>['id','recollection_id','user_id'],
    ];
    public const DERIVED = ['analytics_state','analytics_changes','analytics_occurrences','analytics_attendance','analytics_daily','analytics_cache'];

    public static function install(PDO $pdo, bool $seedPermissions = true): array
    {
        foreach (['20261003_campus_holidays.sql','20261003_analytics.sql'] as $migration) {
            $sql = file_get_contents(BASE_PATH.'/database/migrations/'.$migration);
            foreach (explode(';', $sql) as $statement) if (trim($statement)!=='') $pdo->exec($statement);
        }
        foreach (['message_recipients'=>['first_opened_at'=>'DATETIME NULL'], 'device_scan_logs'=>['reason_code'=>'VARCHAR(48) NULL']] as $table=>$fields) {
            $columns=array_column($pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(),'Field');
            foreach($fields as $field=>$definition) if(!in_array($field,$columns,true)) $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$field}` {$definition}");
        }
        if ($seedPermissions) {
            $pdo->exec('INSERT IGNORE INTO permissions(code,description) VALUES("view_analytics","View analytics within permitted academic and operational scope")');
            $pdo->exec('INSERT IGNORE INTO role_permissions(role_id,permission_id) SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE p.code="view_analytics" AND (r.name IN ("Administrator","Teacher","Student","Staff","Event Coordinator") OR EXISTS(SELECT 1 FROM role_permissions rp JOIN permissions x ON x.id=rp.permission_id WHERE rp.role_id=r.id AND x.code="manage_reports"))');
        }
        $pdo->exec('INSERT IGNORE INTO analytics_state(id,baseline_at) VALUES(1,NOW(6))');
        $baseline=$pdo->query('SELECT baseline_at FROM analytics_state WHERE id=1')->fetchColumn();
        $covered=[];
        foreach(self::SOURCES as $table=>$wanted) {
            self::refreshSource($pdo,$table,$baseline);
            $covered[]=$table;
        }
        foreach(['attendance_records'=>'attendance_date','attendance_sessions'=>'attendance_date','event_attendance'=>null,'recollection_attendance'=>null,'device_scan_logs'=>null,'message_recipients'=>null,'class_seat_assignments'=>null,'room_seat_plan_cells'=>null] as $table=>$date) {
            foreach(['INSERT','UPDATE','DELETE'] as $op) {
                $alias=$op==='DELETE'?'OLD':'NEW';$day=$date?"{$alias}.`{$date}`":'NULL';
                $body="INSERT INTO analytics_changes(source_table,record_key,occurs_on) VALUES('{$table}','',{$day});";
                if($op==='UPDATE' && $date) $body.="IF NOT(OLD.`{$date}` <=> NEW.`{$date}`) THEN INSERT INTO analytics_changes(source_table,record_key,occurs_on) VALUES('{$table}','',OLD.`{$date}`); END IF;";
                self::trigger($pdo,$table,$op,'AFTER',"BEGIN {$body} END");
            }
        }
        // MySQL does not execute child triggers on FK cascades. Close child history
        // before a cascading parent delete; failed restrictive deletes roll this back.
        $cascades=$pdo->query('SELECT k.TABLE_NAME child,k.COLUMN_NAME child_column,k.REFERENCED_TABLE_NAME parent,k.REFERENCED_COLUMN_NAME parent_column FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND r.DELETE_RULE="CASCADE"')->fetchAll();
        $parents=[];
        foreach($cascades as $fk){if(!isset(self::SOURCES[$fk['child']]))continue;$child=$fk['child'];$fields=$pdo->query("SHOW COLUMNS FROM `{$child}`")->fetchAll();$keys=array_column(array_filter($fields,static fn($f)=>$f['Key']==='PRI'),'Field');$identity='CONCAT_WS("/",'.implode(',',array_map(static fn($k)=>"r.`{$k}`",$keys)).')';$condition="r.`{$fk['child_column']}`=OLD.`{$fk['parent_column']}`";
            $parents[$fk['parent']][]="INSERT INTO analytics_history(source_table,record_key,valid_from,operation,payload) SELECT '{$child}',h.record_key,NOW(6),'DELETE',h.payload FROM `{$child}` r JOIN analytics_history h ON h.source_table='{$child}' AND h.record_key={$identity} AND h.valid_to IS NULL AND h.operation!='DELETE' WHERE {$condition}; UPDATE analytics_history h JOIN `{$child}` r ON h.source_table='{$child}' AND h.record_key={$identity} SET h.valid_to=NOW(6) WHERE {$condition} AND h.valid_to IS NULL AND h.operation!='DELETE'; INSERT INTO analytics_changes(source_table,record_key) VALUES('{$child}','');";
        }
        foreach($parents as $parent=>$bodies)self::trigger($pdo,$parent,'DELETE','BEFORE','BEGIN '.implode(' ',$bodies).' END','cascade');
        // Read/unread changes must never erase the first server-side open instant.
        self::trigger($pdo,'message_recipients','UPDATE','BEFORE','BEGIN IF OLD.first_opened_at IS NOT NULL THEN SET NEW.first_opened_at=OLD.first_opened_at; END IF; END','opened');
        $indexes=['attendance_records'=>['idx_analytics_date_offering'=>'(attendance_date,class_offering_id,student_id)'],'attendance_sessions'=>['idx_analytics_session_date'=>'(attendance_date,class_offering_id)'],'grades'=>['idx_analytics_grade_scope'=>'(class_offering_id,status,student_id)'],'device_scan_logs'=>['idx_analytics_scan_date'=>'(created_at,device_id,outcome)'],'messages'=>['idx_analytics_sender_date'=>'(sender_id,created_at,id)']];
        foreach($indexes as $table=>$list) {$existing=array_column($pdo->query("SHOW INDEX FROM `{$table}`")->fetchAll(),'Key_name');foreach($list as $name=>$definition) if(!in_array($name,$existing,true))$pdo->exec("ALTER TABLE `{$table}` ADD INDEX `{$name}` {$definition}");}
        return ['version'=>self::VERSION,'baseline_at'=>$baseline,'history_tables'=>$covered];
    }

    /** Refresh one changed business schema without reseeding permissions or unrelated history. */
    public static function refreshSource(PDO $pdo,string $table,?string $baseline=null): array
    {
        if (!isset(self::SOURCES[$table])) throw new RuntimeException('Unknown analytics history source.');
        $wanted=self::SOURCES[$table];
        $baseline ??= $pdo->query('SELECT baseline_at FROM analytics_state WHERE id=1')->fetchColumn() ?: throw new RuntimeException('Install Analytics first.');
        $fields=$pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll();
        $columns=array_column($fields,'Field');$names=array_values(array_intersect($wanted,$columns));
        $keys=array_column(array_filter($fields,static fn($f)=>$f['Key']==='PRI'),'Field');
        if(!$keys) throw new RuntimeException('History requires a primary key: '.$table);
        $identity=static fn($alias)=>'CONCAT_WS("/",'.implode(',',array_map(static fn($k)=>"{$alias}.`{$k}`",$keys)).')';
        $json=static function($alias,$preservePolicy=false)use($names,$table,$identity){
            $pairs=implode(',',array_map(static fn($f)=>"'{$f}',{$alias}.`{$f}`",$names));
            if($table==='grades'){
                $policy="(SELECT JSON_OBJECT('has_prelim',has_prelim,'has_midterm',has_midterm,'has_final',has_final,'prelim_weight',prelim_weight,'midterm_weight',midterm_weight,'final_weight',final_weight,'passing_grade',passing_grade) FROM grading_settings WHERE id=1)";
                if($preservePolicy)$policy="IF(OLD.status='finalized' AND NEW.status='finalized' AND (OLD.finalized_at <=> NEW.finalized_at),(SELECT IF(h.operation='BASELINE',NULL,JSON_UNQUOTE(JSON_EXTRACT(h.payload,'$._policy'))) FROM analytics_history h WHERE h.source_table='grades' AND h.record_key={$identity('NEW')} ORDER BY h.id DESC LIMIT 1),{$policy})";
                $pairs.=",'_policy',{$policy},'_units',(SELECT c.units FROM class_offerings o JOIN courses c ON c.id=o.course_id WHERE o.id={$alias}.class_offering_id)";
            }
            return 'JSON_OBJECT('.$pairs.')';
        };
        $q=$pdo->prepare("INSERT INTO analytics_history(source_table,record_key,valid_from,operation,payload) SELECT ?,{$identity('r')},?, 'BASELINE',{$json('r')} FROM `{$table}` r WHERE NOT EXISTS(SELECT 1 FROM analytics_history h WHERE h.source_table=? AND h.record_key={$identity('r')})");
        $q->execute([$table,$baseline,$table]);
        foreach(['INSERT','UPDATE','DELETE'] as $op) {
            $alias=$op==='DELETE'?'OLD':'NEW';$key=$identity($alias);
            $changed=implode(' OR ',array_map(static fn($f)=>"NOT(OLD.`{$f}` <=> NEW.`{$f}`)",$names));
            $body="UPDATE analytics_history SET valid_to=NOW(6) WHERE source_table='{$table}' AND record_key={$key} AND valid_to IS NULL; INSERT INTO analytics_history(source_table,record_key,valid_from,operation,payload) VALUES('{$table}',{$key},NOW(6),'{$op}',{$json($alias,$op==='UPDATE')}); INSERT INTO analytics_changes(source_table,record_key) VALUES('{$table}',{$key});";
            if($table==='campus_holidays') $body.="INSERT INTO analytics_changes(source_table,record_key,occurs_on) VALUES('campus_holidays',{$key},{$alias}.holiday_date);";
            if($op==='UPDATE') $body="IF {$changed} THEN {$body} END IF;";
            self::trigger($pdo,$table,$op,'AFTER',"BEGIN {$body} END");
        }
        return ['source'=>$table,'baseline_at'=>$baseline,'version'=>self::VERSION];
    }

    private static function trigger(PDO $pdo,string $table,string $op,string $timing,string $body,string $suffix='history'): void
    {
        $name='ct_an_'.substr(hash('sha256',$table),0,14).'_'.strtolower($op[0]).'_'.$suffix;
        $q=$pdo->prepare('SELECT ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?');$q->execute([$name]);
        if(trim((string)$q->fetchColumn())===trim($body))return;
        $pdo->exec("DROP TRIGGER IF EXISTS `{$name}`");$pdo->exec("CREATE TRIGGER `{$name}` {$timing} {$op} ON `{$table}` FOR EACH ROW {$body}");
    }
}
