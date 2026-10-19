<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/** Install transactional row tracking from the actual database schema, not a stale table list. */
final class AuditSchema
{
    public const VERSION = '20261003-v1';
    private const COLUMNS = [
        'source' => "VARCHAR(16) NOT NULL DEFAULT 'activity'", 'operation' => 'VARCHAR(8) NULL',
        'actor_id' => 'INT UNSIGNED NULL', 'actor_name' => 'VARCHAR(240) NULL', 'actor_school_id' => 'VARCHAR(80) NULL', 'actor_role' => 'VARCHAR(160) NULL',
        'request_id' => 'VARCHAR(32) NULL', 'before_values' => 'LONGTEXT NULL', 'after_values' => 'LONGTEXT NULL',
        'search_text' => 'LONGTEXT NULL', 'changed_fields' => 'LONGTEXT NULL',
    ];

    public static function privateField(string $table, string $field): bool
    {
        return (bool) preg_match('/password|hash|token|secret|api_key|^session($|_)|credential|private_key|storage_name|evidence|^uid$|^body$|body_html|^payload$|^witnesses$|incident_description/i', $field)
            || (in_array($table, ['messages','message_targets','notifications','notification_outbox'], true) && in_array($field,['subject','title','label','details','content'],true));
    }

    public static function install(PDO $pdo): array
    {
        $tables = self::tables($pdo);
        $foreignKeys = self::foreignKeys($pdo);
        $plans=[];
        foreach ($tables as $table=>$fields) foreach (['INSERT','UPDATE','DELETE'] as $operation) $plans[$table][$operation]=self::body($table,$fields,$operation,$tables,$foreignKeys);
        $columns = array_column($pdo->query('SHOW COLUMNS FROM audit_logs')->fetchAll(), 'Field');
        foreach (self::COLUMNS as $name => $definition) if (!in_array($name,$columns,true)) $pdo->exec("ALTER TABLE audit_logs ADD COLUMN `{$name}` {$definition}");
        $indexes = array_unique(array_column($pdo->query('SHOW INDEX FROM audit_logs')->fetchAll(), 'Key_name'));
        foreach (['created_at','user_id','actor_id','action','entity_type','entity_id','source','operation','actor_name','actor_school_id','request_id'] as $field) {
            $name = 'idx_audit_' . $field;
            if (!in_array($name,$indexes,true)) $pdo->exec("ALTER TABLE audit_logs ADD INDEX `{$name}` (`{$field}`,id)");
        }
        // Keep existing logs searchable, without inventing historical before/after values.
        $pdo->exec('UPDATE audit_logs SET actor_id=user_id WHERE actor_id IS NULL AND user_id IS NOT NULL');
        $pdo->exec('UPDATE audit_logs a JOIN users u ON u.id=a.actor_id SET a.actor_name=LEFT(CONCAT_WS(" ",u.first_name,u.middle_name,u.last_name),240),a.actor_school_id=u.school_id,a.actor_role="Legacy history (profile at setup)" WHERE a.actor_name IS NULL AND a.source="activity"');
        $pdo->exec('UPDATE audit_logs a LEFT JOIN users u ON u.id=a.user_id SET a.search_text=CONCAT_WS(" ",a.id,a.action,REPLACE(a.action,"_"," "),a.entity_type,REPLACE(a.entity_type,"_"," "),a.entity_id,CASE WHEN a.entity_type LIKE "%message%" OR a.action LIKE "%rfid%" THEN NULL ELSE LEFT(a.details,16000) END,u.school_id,u.first_name,u.middle_name,u.last_name) WHERE a.search_text IS NULL');
        if (!in_array('ft_audit_search',$indexes,true)) $pdo->exec('ALTER TABLE audit_logs ADD FULLTEXT INDEX ft_audit_search(search_text)');
        $pdo->exec('CREATE TABLE IF NOT EXISTS audit_tracking_tables (table_name VARCHAR(64) PRIMARY KEY,signature CHAR(64) NOT NULL,version VARCHAR(32) NOT NULL,installed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
        $triggers = $pdo->query('SELECT TRIGGER_NAME,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_KEY_PAIR);
        $installed = 0;
        foreach ($tables as $table => $fields) $installed+=self::trackTable($pdo,$table,$fields,$foreignKeys,$plans[$table],$triggers);
        return ['tables'=>count($tables),'triggers'=>count($tables)*3,'installed_triggers'=>$installed,'version'=>self::VERSION];
    }

    /** Refresh only the altered table, retaining schema-aware deletion and redaction rules. */
    public static function refreshTable(PDO $pdo,string $table): array
    {
        $tables=self::tables($pdo);$foreignKeys=self::foreignKeys($pdo);
        if (!isset($tables[$table])) throw new RuntimeException('Unknown audited business table.');
        $plans=[];
        foreach (['INSERT','UPDATE','DELETE'] as $operation) $plans[$operation]=self::body($table,$tables[$table],$operation,$tables,$foreignKeys);
        $q=$pdo->prepare('SELECT TRIGGER_NAME,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=?');$q->execute([$table]);
        $installed=self::trackTable($pdo,$table,$tables[$table],$foreignKeys,$plans,$q->fetchAll(PDO::FETCH_KEY_PAIR));
        return ['tables'=>1,'triggers'=>3,'installed_triggers'=>$installed,'version'=>self::VERSION];
    }

    private static function trackTable(PDO $pdo,string $table,array $fields,array $foreignKeys,array $plans,array $triggers): int
    {
        $installed=0;
        foreach (['INSERT','UPDATE','DELETE'] as $operation) {
            $name = 'ct_audit_' . substr(hash('sha256',$table),0,18) . '_' . strtolower($operation[0]);
            $body = $plans[$operation];
            if (isset($triggers[$name]) && trim($triggers[$name]) === trim($body)) continue;
            $pdo->exec("DROP TRIGGER IF EXISTS `{$name}`");
            $timing = $operation === 'DELETE' ? 'BEFORE' : 'AFTER';
            $pdo->exec("CREATE TRIGGER `{$name}` {$timing} {$operation} ON `{$table}` FOR EACH ROW {$body}");
            $installed++;
        }
        $signature = hash('sha256',json_encode([$fields,$foreignKeys,self::VERSION],JSON_THROW_ON_ERROR));
        $pdo->prepare('INSERT INTO audit_tracking_tables(table_name,signature,version) VALUES(?,?,?) ON DUPLICATE KEY UPDATE signature=VALUES(signature),version=VALUES(version)')->execute([$table,$signature,self::VERSION]);
        return $installed;
    }

    public static function tables(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT c.TABLE_NAME,c.COLUMN_NAME,c.COLUMN_KEY,c.DATA_TYPE,t.ENGINE FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME WHERE c.TABLE_SCHEMA=DATABASE() AND t.TABLE_TYPE="BASE TABLE" ORDER BY c.TABLE_NAME,c.ORDINAL_POSITION')->fetchAll();
        $tables = [];
        foreach ($rows as $row) {
            $table = $row['TABLE_NAME'];
            if (in_array($table,array_merge(['audit_logs','audit_tracking_tables'],AnalyticsSchema::DERIVED),true)) continue;
            if (strcasecmp($row['ENGINE'],'InnoDB')!==0) throw new RuntimeException('Transactional audit tracking requires InnoDB: ' . $table);
            if (!preg_match('/\A[a-zA-Z0-9_]+\z/',$table . $row['COLUMN_NAME'])) throw new RuntimeException('Unsupported audit schema identifier.');
            $tables[$table][] = ['name'=>$row['COLUMN_NAME'],'primary'=>$row['COLUMN_KEY']==='PRI','type'=>$row['DATA_TYPE']];
        }
        foreach ($tables as $table=>$fields) if (!array_filter($fields,static fn($f)=>$f['primary'])) throw new RuntimeException('Audited tables require a stable primary key: ' . $table);
        return $tables;
    }

    private static function foreignKeys(PDO $pdo): array
    {
        $rows = $pdo->query('SELECT k.CONSTRAINT_NAME,k.TABLE_NAME child,k.COLUMN_NAME child_column,k.REFERENCED_TABLE_NAME parent,k.REFERENCED_COLUMN_NAME parent_column,r.DELETE_RULE,r.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION')->fetchAll();
        $constraints=[];
        foreach ($rows as $row) {
            if ($row['UPDATE_RULE'] === 'CASCADE' || $row['UPDATE_RULE'] === 'SET NULL') throw new RuntimeException('Install an explicit audit policy for cascading primary-key updates before enabling this schema.');
            $key=$row['child'].'.'.$row['CONSTRAINT_NAME'];
            if (isset($constraints[$key])) throw new RuntimeException('Install an explicit audit policy for composite foreign keys before enabling this schema.');
            $constraints[$key]=true;
        }
        return $rows;
    }

    private static function json(string $table, array $fields, string $alias, array $nullConditions = []): string
    {
        $parts = [];
        foreach ($fields as $field) {
            $name = $field['name']; $parts[] = "'{$name}'";
            $value = "{$alias}.`{$name}`";
            if (isset($nullConditions[$name])) $value = 'IF(' . implode(' OR ',$nullConditions[$name]) . ',NULL,' . $value . ')';
            if (self::privateField($table,$name)) $value = "IF({$value} IS NULL,NULL,'[redacted]')";
            elseif (in_array($field['type'],['blob','binary','varbinary','mediumblob','longblob','tinyblob'],true)) $value = "IF({$value} IS NULL,NULL,'[binary data]')";
            $parts[] = $value;
        }
        return 'JSON_OBJECT(' . implode(',',$parts) . ')';
    }

    private static function identity(array $fields,string $alias): string
    {
        $primary = array_values(array_filter($fields,static fn($f)=>$f['primary']));
        if (count($primary) === 1) return "CAST({$alias}.`{$primary[0]['name']}` AS CHAR)";
        return 'LEFT(CONCAT_WS(\'/\',' . implode(',',array_map(static fn($f)=>"{$alias}.`{$f['name']}`",$primary)) . '),80)';
    }

    private static function insert(string $table,array $fields,string $operation,string $alias,string $before,string $after,?string $selectWhere = null,string $suffix = '',string $changedFields = 'NULL'): string
    {
        $id = self::identity($fields,$alias);
        $details = "CONCAT('{$operation} {$table} #',{$id},'{$suffix}')";
        $name = "COALESCE(@ct_actor_name,CONCAT('Database connection ',SUBSTRING_INDEX(USER(),'@',1)))";
        $search = "CONCAT_WS(' ',{$details},REPLACE('{$table}','_',' '),{$name},@ct_actor_school_id,LEFT({$before},16000),LEFT({$after},16000))";
        $values = "@ct_actor_id,'db_" . strtolower($operation) . "','{$table}',{$id},{$details},@ct_ip,'database','{$operation}',@ct_actor_id,{$name},@ct_actor_school_id,@ct_actor_role,@ct_request_id,{$before},{$after},{$search},{$changedFields}";
        $sql = 'INSERT INTO audit_logs(user_id,action,entity_type,entity_id,details,ip_address,source,operation,actor_id,actor_name,actor_school_id,actor_role,request_id,before_values,after_values,search_text,changed_fields) ';
        return $selectWhere === null ? $sql . 'VALUES(' . $values . ');' : $sql . 'SELECT ' . $values . " FROM `{$table}` {$alias} WHERE {$selectWhere};";
    }

    private static function body(string $table,array $fields,string $operation,array $tables,array $foreignKeys): string
    {
        $before = $operation === 'INSERT' ? 'NULL' : self::json($table,$fields,'OLD');
        $after = $operation === 'DELETE' ? 'NULL' : self::json($table,$fields,'NEW');
        $log = self::insert($table,$fields,$operation,$operation === 'DELETE'?'OLD':'NEW',$before,$after);
        if ($operation === 'UPDATE') {
            $changed = implode(' OR ',array_map(static fn($f)=>"NOT(OLD.`{$f['name']}` <=> NEW.`{$f['name']}`)",$fields));
            $changedFields = 'JSON_ARRAY(' . implode(',',array_map(static fn($f)=>"IF(NOT(OLD.`{$f['name']}` <=> NEW.`{$f['name']}`),'{$f['name']}',NULL)",$fields)) . ')';
            $log = self::insert($table,$fields,$operation,'NEW',$before,$after,null,'',$changedFields);
            return "BEGIN IF {$changed} THEN {$log} END IF; END";
        }
        if ($operation !== 'DELETE') return 'BEGIN ' . $log . ' END';
        // InnoDB does not fire child triggers for FK cascades. Capture descendants
        // while they still exist; a failed parent statement rolls all these logs back.
        $deleted = [$table=>['ROOT']]; $pending = [[$table,[],[$table]]]; $nulls = [];
        while ($pending) {
            [$parent,$path,$visited] = array_shift($pending);
            foreach ($foreignKeys as $fk) {
                if ($fk['parent'] !== $parent || !isset($tables[$fk['child']]) || !in_array($fk['DELETE_RULE'],['CASCADE','SET NULL'],true)) continue;
                $child = $fk['child']; $next = array_merge($path,[$fk]);
                $condition = self::pathCondition($next,'r');
                if ($fk['DELETE_RULE'] === 'SET NULL') $nulls[$child][$fk['child_column']][] = $condition;
                else {
                    $deleted[$child][] = $condition;
                    if (!in_array($child,$visited,true)) $pending[] = [$child,$next,array_merge($visited,[$child])];
                    else throw new RuntimeException('Cyclic audit cascade policy requires explicit handling.');
                }
            }
        }
        foreach ($deleted as $child=>$conditions) {
            if ($child === $table) continue;
            $log .= self::insert($child,$tables[$child],'DELETE','r',self::json($child,$tables[$child],'r'),'NULL','(' . implode(' OR ',$conditions) . ')',' · cascade');
        }
        foreach ($nulls as $child=>$columns) {
            $conditions = array_merge(...array_values($columns));
            $where = '(' . implode(' OR ',$conditions) . ')';
            if (isset($deleted[$child])) $where .= ' AND NOT(' . implode(' OR ',$deleted[$child]) . ')';
            $log .= self::insert($child,$tables[$child],'UPDATE','r',self::json($child,$tables[$child],'r'),self::json($child,$tables[$child],'r',$columns),$where,' · unlink');
        }
        return 'BEGIN ' . $log . ' END';
    }

    private static function pathCondition(array $path,string $alias): string
    {
        $last = array_pop($path);
        if (!$path) return "{$alias}.`{$last['child_column']}`=OLD.`{$last['parent_column']}`";
        $parentAlias = $alias . 'p';
        return "EXISTS(SELECT 1 FROM `{$last['parent']}` {$parentAlias} WHERE {$alias}.`{$last['child_column']}`={$parentAlias}.`{$last['parent_column']}` AND " . self::pathCondition($path,$parentAlias) . ')';
    }
}
