<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;
use DomainException;
use PDO;

final class EventWorkspaceService
{
    public static function event(int $id, int $viewer): array
    {
        $q=Database::connection()->prepare('SELECT events.* FROM events WHERE id=? AND '.EventAccessService::visibleCondition($viewer));
        $q->execute([$id]); $event=$q->fetch();
        if (!$event) throw new DomainException('This event is unavailable or you do not have access to it.');
        $event['can_manage']=EventAccessService::canManage($event,$viewer);
        $q=Database::connection()->prepare('SELECT image_path FROM event_images WHERE event_id=? ORDER BY display_order,id');$q->execute([$id]);$event['images']=$q->fetchAll(PDO::FETCH_COLUMN);
        if ($event['can_manage']) {
            $event['targets']=EventAudienceService::targetsByEventIds([$id])[$id]??[];
            $q=Database::connection()->prepare('SELECT d.id,d.device_code,d.name,d.last_seen_at FROM event_scanners s JOIN devices d ON d.id=s.device_id WHERE s.event_id=? ORDER BY d.name');$q->execute([$id]);$event['scanners']=$q->fetchAll();
        }
        return $event;
    }

    public static function activity(int $id, int $viewer, array $filters): array
    {
        $event=self::event($id,$viewer);$pdo=Database::connection();
        $where=['l.event_id=?'];$params=[$id];
        if (!$event['can_manage']) {$where[]='l.user_id=?';$params[]=$viewer;}
        $search=mb_substr(trim((string)($filters['q']??'')),0,120);
        if ($search!=='') {$where[]='(u.school_id LIKE ? OR CONCAT_WS(" ",u.first_name,u.middle_name,u.last_name) LIKE ?)';$params[]='%'.$search.'%';$params[]='%'.$search.'%';}
        foreach(['action'=>['check_in','check_out','status_change'],'source'=>['rfid','manual','system'],'status'=>['Attended','Late','Absent','Excused','Incomplete']] as $field=>$allowed) {
            $value=(string)($filters[$field]??'');
            if ($value!=='') {if(!in_array($value,$allowed,true))throw new DomainException('Select a valid activity filter.');$where[]="l.{$field}=?";$params[]=$value;}
        }
        foreach(['from'=>'>=','through'=>'<'] as $field=>$operator) {
            $value=(string)($filters[$field]??'');
            if($value==='')continue;
            $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
            if(!$date || $date->format('Y-m-d')!==$value)throw new DomainException('Select a valid activity date.');
            $where[]="l.occurred_at {$operator} ?";$params[]=($field==='through'?$date->modify('+1 day')->format('Y-m-d'):$value).' 00:00:00';
        }
        if (!empty($filters['from']) && !empty($filters['through']) && $filters['from']>$filters['through']) throw new DomainException('The activity end date must be on or after the start date.');
        $base=' FROM event_activity_logs l JOIN users u ON u.id=l.user_id WHERE '.implode(' AND ',$where);
        $q=$pdo->prepare('SELECT COUNT(*)'.$base);$q->execute($params);$total=(int)$q->fetchColumn();
        $page=min(max(1,(int)($filters['page']??1)),max(1,(int)ceil($total/25)));$offset=($page-1)*25;
        $sorts=['newest'=>'l.occurred_at DESC,l.id DESC','oldest'=>'l.occurred_at,l.id','name'=>'u.last_name,u.first_name,l.id DESC'];$sort=(string)($filters['sort']??'newest');
        if(!isset($sorts[$sort]))throw new DomainException('Select a valid activity sort.');
        $q=$pdo->prepare('SELECT l.id,l.action,l.occurred_at,l.source,l.status,l.legacy,u.school_id,u.first_name,u.last_name,u.profile_image_path'.$base.' ORDER BY '.$sorts[$sort].' LIMIT 25 OFFSET '.$offset);$q->execute($params);
        return ['records'=>$q->fetchAll(),'total'=>$total,'page'=>$page,'pages'=>max(1,(int)ceil($total/25)),'server_time'=>$pdo->query('SELECT NOW()')->fetchColumn(),'timezone'=>config('app')['timezone']];
    }

    public static function live(int $id, int $viewer, ?int $after): array
    {
        $event=EventAccessService::requireManage($id,$viewer);$pdo=Database::connection();
        $q=$pdo->prepare('SELECT COALESCE(MAX(id),0) FROM event_activity_logs WHERE event_id=?');$q->execute([$id]);$latest=(int)$q->fetchColumn();
        // Initial load establishes a cursor: old scans never masquerade as live arrivals.
        $records=[];$cursor=$latest;
        if($after!==null) {
            $q=$pdo->prepare('SELECT l.id,l.action,l.occurred_at,l.source,l.status,u.school_id,u.first_name,u.last_name,u.profile_image_path FROM event_activity_logs l JOIN users u ON u.id=l.user_id WHERE l.event_id=? AND l.id>? AND l.legacy=0 ORDER BY l.id LIMIT 50');$q->execute([$id,max(0,$after)]);$records=$q->fetchAll();$cursor=$records?(int)end($records)['id']:max($after,$latest);
        }
        return ['records'=>$records,'cursor'=>$cursor,'status'=>$event['status'],'accepting'=>in_array($event['status'],['scheduled','open'],true),'server_time'=>$pdo->query('SELECT NOW()')->fetchColumn(),'timezone'=>config('app')['timezone']];
    }

    public static function manualId(int $id, int $viewer, string $schoolId, string $action): array
    {
        EventAccessService::requireManage($id,$viewer);
        $schoolId=trim($schoolId);
        if($schoolId==='' || mb_strlen($schoolId)>80)throw new DomainException('Enter the participant’s school ID number.');
        $q=Database::connection()->prepare('SELECT u.id FROM users u JOIN event_participants p ON p.user_id=u.id WHERE p.event_id=? AND u.school_id=? AND u.status="active"');$q->execute([$id,$schoolId]);$userId=(int)$q->fetchColumn();
        if(!$userId)throw new DomainException('No eligible active participant matches this school ID.');
        return EventService::recordArrival($id,$userId,'manual',$viewer,$action);
    }
}
