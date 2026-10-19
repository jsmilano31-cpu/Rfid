<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DomainException;
use PDO;

final class AuditTrailService
{
    private const SORTS = ['time'=>'created_at','actor'=>'actor_name','action'=>'action','entity'=>'entity_type','record'=>'entity_id','source'=>'source'];

    public static function filters(array $input): array
    {
        $text = static fn(string $name,int $limit=100): string => mb_substr(trim(is_scalar($input[$name]??null)?(string)$input[$name]:''),0,$limit);
        $filters = ['q'=>$text('q'),'entity'=>$text('entity'),'action'=>$text('action'),'actor'=>$text('actor',80),'record'=>$text('record',80),
            'source'=>$text('source',16),'operation'=>$text('operation',8),'from'=>$text('from',10),'to'=>$text('to',10),
            'sort'=>$text('sort',16) ?: 'time','direction'=>$text('direction',4) ?: 'desc','limit'=>(int)($input['limit']??50)];
        if (!isset(self::SORTS[$filters['sort']])) $filters['sort']='time';
        if (!in_array($filters['direction'],['asc','desc'],true)) $filters['direction']='desc';
        if (!in_array($filters['limit'],[25,50,100],true)) $filters['limit']=50;
        if (!in_array($filters['source'],['','activity','database'],true)) $filters['source']='';
        if (!in_array($filters['operation'],['','INSERT','UPDATE','DELETE'],true)) $filters['operation']='';
        foreach (['from','to'] as $field) {
            if ($filters[$field] === '') continue;
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d',$filters[$field]);
            if (!$date || $date->format('Y-m-d') !== $filters[$field]) throw new DomainException('Choose valid date filters.');
        }
        if ($filters['from'] && $filters['to'] && $filters['from'] > $filters['to']) throw new DomainException('The start date must be on or before the end date.');
        return $filters;
    }

    public static function listing(array $input): array
    {
        $pdo=Database::connection(); $filters=self::filters($input); $params=[]; $where=['1=1'];
        foreach (['entity'=>'entity_type','action'=>'action','source'=>'source','operation'=>'operation','record'=>'entity_id'] as $key=>$column) if ($filters[$key] !== '') { $where[]="a.{$column}=:{$key}"; $params[$key]=$filters[$key]; }
        if ($filters['actor'] !== '') { $where[]='a.actor_id=:actor'; $params['actor']=$filters['actor']==='system'?null:(int)$filters['actor']; if ($filters['actor']==='system') {array_pop($where);$where[]='a.actor_id IS NULL';unset($params['actor']);} }
        if ($filters['from']) { $where[]='a.created_at>=:starts';$params['starts']=$filters['from'].' 00:00:00'; }
        if ($filters['to']) { $where[]='a.created_at<:ends';$params['ends']=(new \DateTimeImmutable($filters['to']))->modify('+1 day')->format('Y-m-d'); }
        $join='';
        if ($filters['q'] !== '') {
            $q=$filters['q']; $parts=[];
            $words=preg_split('/[^\pL\pN]+/u',$q,-1,PREG_SPLIT_NO_EMPTY) ?: [];
            $tokens=array_values(array_filter(array_slice($words,0,8),static fn($word)=>mb_strlen($word)>=3));
            if ($tokens) { $parts[]='SELECT id FROM audit_logs WHERE MATCH(search_text) AGAINST(:terms IN BOOLEAN MODE)';$params['terms']=implode(' ',array_map(static fn($word)=>'+'.$word.'*',$tokens)); }
            foreach (['entity_id','actor_school_id','request_id'] as $column) { $parts[]="SELECT id FROM audit_logs WHERE {$column}=:q_{$column}";$params['q_'.$column]=$q; }
            if (ctype_digit($q) && strlen($q)<19) {$parts[]='SELECT id FROM audit_logs WHERE id=:q_id';$params['q_id']=$q;}
            // Short labels use indexed prefixes rather than a leading-wildcard scan.
            if (!$tokens) foreach (['actor_name','action','entity_type'] as $column) {$parts[]="SELECT id FROM audit_logs WHERE {$column} LIKE :q_{$column} ESCAPE '!'";$params['q_'.$column]=strtr($q,['!'=>'!!','%'=>'!%','_'=>'!_']).'%';}
            $join=' JOIN ('.implode(' UNION ',$parts).') matches ON matches.id=a.id';
        }
        $sort=self::SORTS[$filters['sort']]; $direction=strtoupper($filters['direction']); $reverse=false;
        $fingerprint=substr(hash('sha256',json_encode($filters,JSON_THROW_ON_ERROR)),0,16);
        $cursor = null;
        if (is_string($input['cursor']??null) && strlen($input['cursor'])<700) {
            $decoded=json_decode(base64_decode(strtr($input['cursor'],'-_','+/'),true) ?: '',true);
            if (is_array($decoded) && ($decoded['f']??'')===$fingerprint && isset($decoded['id']) && array_key_exists('value',$decoded) && ($decoded['value']===null || is_scalar($decoded['value'])) && ctype_digit((string)$decoded['id'])) $cursor=$decoded;
        }
        if ($cursor) {
            $reverse=($cursor['way']??'')==='prev';
            $operator=($direction==='ASC') !== $reverse ? '>' : '<';
            if ($cursor['value']===null) {
                $where[]=$operator==='>' ? "(a.{$sort} IS NOT NULL OR (a.{$sort} IS NULL AND a.id > :cursor_id))" : "(a.{$sort} IS NULL AND a.id < :cursor_id)";
            } else {
                $where[]="(a.{$sort} {$operator} :cursor_value OR (a.{$sort}=:cursor_equal AND a.id {$operator} :cursor_id)" . ($operator==='<'?" OR a.{$sort} IS NULL":'') . ')';
                $params+=['cursor_value'=>$cursor['value'],'cursor_equal'=>$cursor['value']];
            }
            $params['cursor_id']=$cursor['id'];
        }
        $order=$reverse?($direction==='ASC'?'DESC':'ASC'):$direction; $limit=$filters['limit']+1;
        $query='SELECT a.id,a.user_id,a.actor_id,a.action,a.entity_type,a.entity_id,a.source,a.operation,a.created_at,a.actor_name,a.actor_school_id,a.actor_role,a.details,a.'.$sort.' cursor_value FROM audit_logs a'.$join.' WHERE '.implode(' AND ',$where)." ORDER BY a.{$sort} {$order},a.id {$order} LIMIT {$limit}";
        $statement=$pdo->prepare($query);$statement->execute($params);$rows=$statement->fetchAll();$more=count($rows)>$filters['limit'];$rows=array_slice($rows,0,$filters['limit']);
        if ($reverse) $rows=array_reverse($rows);
        $cursorFor=static fn(array $row,string $way):string=>rtrim(strtr(base64_encode(json_encode(['f'=>$fingerprint,'id'=>(string)$row['id'],'value'=>$row['cursor_value'],'way'=>$way],JSON_THROW_ON_ERROR)),'+/','-_'),'=');
        $first=$rows[0]??null;$last=$rows?end($rows):null;
        return ['ok'=>true,'filters'=>$filters,'items'=>array_map([self::class,'present'],$rows),
            'previous'=>$first && ($reverse?$more:$cursor!==null)?$cursorFor($first,'prev'):null,
            'next'=>$last && ($reverse?$cursor!==null:$more)?$cursorFor($last,'next'):null];
    }

    private static function present(array $row): array
    {
        $row['actor_name']=$row['actor_name'] ?: ($row['actor_id']?'Historical user #'.$row['actor_id']:'System / historical actor');
        $row['title']=ucwords(str_replace('_',' ',$row['action']));
        $row['entity_label']=ucwords(str_replace('_',' ',$row['entity_type']));
        $row['time']=format_datetime($row['created_at']);
        $row['iso']=(new \DateTimeImmutable($row['created_at']))->format(DATE_ATOM);
        if (str_contains($row['entity_type'],'message') || str_contains($row['action'],'rfid')) $row['details']='Private content or credentials are not displayed in the audit trail.';
        unset($row['cursor_value']); return $row;
    }

    public static function detail(int $id): array
    {
        $statement=Database::connection()->prepare('SELECT * FROM audit_logs WHERE id=?');$statement->execute([$id]);$row=$statement->fetch();
        if (!$row) throw new DomainException('This audit entry could not be found.');
        $row=self::present($row);
        foreach (['before_values','after_values'] as $field) $row[$field]=json_decode($row[$field]??'null',true);
        $row['changes']=[];
        $changedFields=array_filter(json_decode($row['changed_fields']??'null',true)??[]);
        foreach (array_unique(array_merge(array_keys($row['before_values']??[]),array_keys($row['after_values']??[]))) as $field) {
            $before=$row['before_values'][$field]??null;$after=$row['after_values'][$field]??null;
            if ($row['operation']==='UPDATE' && $before===$after && !in_array($field,$changedFields,true)) continue;
            $row['changes'][]=['field'=>$field,'before'=>$before,'after'=>$after];
        }
        unset($row['search_text']); return ['ok'=>true,'entry'=>$row];
    }

    public static function workspace(): array
    {
        $pdo=Database::connection();
        $ready=(bool)$pdo->query('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="audit_tracking_tables"')->fetchColumn();
        if (!$ready) return ['auditReady'=>false];
        $tracked=(int)$pdo->query('SELECT COUNT(*) FROM audit_tracking_tables')->fetchColumn();
        $recent=$pdo->query('SELECT COUNT(*) total,SUM(operation="INSERT") inserts,SUM(operation="UPDATE") updates,SUM(operation="DELETE") deletes FROM audit_logs WHERE created_at>=CURRENT_TIMESTAMP-INTERVAL 1 DAY')->fetch();
        $actors=$pdo->query('SELECT a.actor_id id,COALESCE(NULLIF(a.actor_name,""),CONCAT("Historical user #",a.actor_id)) name FROM audit_logs a JOIN (SELECT MAX(id) id FROM audit_logs WHERE actor_id IS NOT NULL GROUP BY actor_id) latest ON latest.id=a.id ORDER BY name LIMIT 500')->fetchAll();
        $selectedActor=(int)($_GET['actor']??0);
        if ($selectedActor>0 && !in_array($selectedActor,array_column($actors,'id'))) {
            $statement=$pdo->prepare('SELECT actor_id id,COALESCE(NULLIF(actor_name,""),CONCAT("Historical user #",actor_id)) name FROM audit_logs WHERE actor_id=? ORDER BY id DESC LIMIT 1');$statement->execute([$selectedActor]);
            $actors[]=$statement->fetch() ?: ['id'=>$selectedActor,'name'=>'User #'.$selectedActor];
        }
        return ['auditReady'=>true,'auditTracked'=>$tracked,'auditRecent'=>$recent,
            'auditEntities'=>$pdo->query('SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type LIMIT 200')->fetchAll(PDO::FETCH_COLUMN),
            'auditActions'=>$pdo->query('SELECT DISTINCT action FROM audit_logs ORDER BY action LIMIT 250')->fetchAll(PDO::FETCH_COLUMN),
            'auditActors'=>$actors];
    }
}
