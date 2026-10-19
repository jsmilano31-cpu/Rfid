<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use DateTimeImmutable;
use DomainException;
use Throwable;

/** Read-only upstream integration. National holiday status never silently changes campus policy. */
final class PhilippineHolidayService
{
    public const LABELS = ['regular'=>'Regular holiday (non-working)', 'special_non_working'=>'Special non-working holiday', 'special_working'=>'Special working holiday', 'unclassified'=>'Holiday · classification unavailable'];

    public static function canManage(): bool { return \App\Core\PermissionRegistry::canManageCampusCalendar(); }

    public static function normalize(array $rows, int $year): array
    {
        $result=[];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $date=trim((string)($row['date']??''));
            $day=DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            $title=trim(strip_tags((string)($row['event']??$row['title']??'')));
            if (!$day || $day->format('Y-m-d')!==$date || (int)$day->format('Y')!==$year || $title==='' || mb_strlen($title)>180) continue;
            $type=(string)($row['type']??'unclassified');
            if (!isset(self::LABELS[$type])) $type='unclassified';
            $key=$date.'-'.substr(hash('sha256',mb_strtolower($title)),0,16);
            $result[$key]=['key'=>$key,'date'=>$date,'title'=>$title,'type'=>$type];
        }
        uasort($result,static fn($a,$b)=>[$a['date'],$a['title']]<=>[$b['date'],$b['title']]);
        return array_values($result);
    }

    public static function feed(int $year): array
    {
        if ($year<2000 || $year>2100) throw new DomainException('Select a calendar year between 2000 and 2100.');
        $path=BASE_PATH.'/storage/private/holidays/'.$year.'.json';
        $cached=is_file($path)?json_decode((string)file_get_contents($path),true):null;
        $rows=$cached['holidays']??[];
        $available=(bool)($cached['available']??false);
        $checked=(int)($cached['checked_at']??0);
        if (time()-$checked>($available?86400:300)) {
            $raw=false;
            // Only trusted server configuration can change the upstream. The browser cannot supply a URL.
            $base=rtrim((string)env('PHILIPPINE_HOLIDAY_API_URL','https://philippineholidayapi.onrender.com'),'/');
            if (preg_match('~\Ahttps://[^/?#]+(?:/[^?#]*)?\z~',$base) && function_exists('curl_init')) {
                $curl=curl_init($base.'/holiday/'.$year);
                curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>6,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_FOLLOWLOCATION=>false]);
                $raw=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);curl_close($curl);
                if ($status!==200) $raw=false;
            }
            $payload=$raw?json_decode($raw,true):null;
            $fresh=($payload['success']??false)?self::normalize($payload['data']['holidays']??[],$year):[];
            $available=(bool)$fresh;
            if ($available) $rows=$fresh;
            $checked=time();
            if (!is_dir(dirname($path))) @mkdir(dirname($path),0700,true);
            $tmp=$path.'.'.bin2hex(random_bytes(4)).'.tmp';
            if (@file_put_contents($tmp,json_encode(['checked_at'=>$checked,'available'=>$available,'holidays'=>$rows],JSON_UNESCAPED_SLASHES),LOCK_EX)!==false) { @rename($tmp,$path); if (is_file($tmp)) @unlink($tmp); }
        }
        $file=BASE_PATH.'/database/data/philippine-holidays-'.$year.'.json';
        $verified=is_file($file)?json_decode((string)file_get_contents($file),true):null;
        $official=self::normalize($verified['holidays']??[],$year);
        // The upstream currently supplies date/name/day only. Dated proclamation data supplies
        // verified types and fills missing declared holidays; unknown types remain explicit.
        $byDate=[];foreach($official as $row) $byDate[$row['date']][]=$row;
        $merged=[];
        foreach($rows as $row) {
            if (!isset($row['key'],$row['date'],$row['title'],$row['type'])) continue;
            $known=$byDate[$row['date']]??[];
            if (count($known)===1) { $row=$known[0]; unset($byDate[$row['date']]); }
            $merged[$row['key']]=$row;
        }
        foreach($byDate as $entries)foreach($entries as $row)$merged[$row['key']]=$row;
        $holidays=array_values($merged);usort($holidays,static fn($a,$b)=>[$a['date'],$a['title']]<=>[$b['date'],$b['title']]);
        $notice=$available?'':($rows?'Holiday feed unavailable. Showing the last saved list; classifications may be incomplete.':($official?'Holiday feed unavailable. Showing verified '.$year.' declarations; later announcements may be missing.':'Philippine holidays are not available for '.$year.'. The feed has no usable list yet.'));
        return ['holidays'=>$holidays,'notice'=>$notice,'source'=>'PhilippineHolidayAPI','checked_at'=>date(DATE_ATOM,$checked),'verified_on'=>$verified['verified_on']??null];
    }

    public static function calendar(int $year): array
    {
        $feed=self::feed($year);$pdo=Database::connection();
        $q=$pdo->prepare('SELECT * FROM campus_holidays WHERE holiday_date>=? AND holiday_date<?');$q->execute([$year.'-01-01',($year+1).'-01-01']);
        $policies=[];foreach($q->fetchAll() as $row)$policies[$row['holiday_date']]=$row;
        $events=[];$dates=[];
        foreach($feed['holidays'] as $holiday) {
            $dates[$holiday['date']]=true;$policy=$policies[$holiday['date']]??null;
            $events[]=self::entry($holiday,$policy);
        }
        // Existing campus decisions remain visible even if the upstream removes a holiday.
        foreach($policies as $date=>$policy)if(!isset($dates[$date]) && $policy['no_classes'])$events[]=self::entry(['key'=>$date.'-campus','date'=>$date,'title'=>$policy['title'],'type'=>$policy['holiday_type']],$policy);
        unset($feed['holidays']);
        return $feed+['ok'=>true,'events'=>$events,'can_manage'=>self::canManage()];
    }

    private static function entry(array $holiday,?array $policy): array
    {
        $noClasses=(bool)($policy['no_classes']??false);$type=$holiday['type'];$date=$holiday['date'];
        return ['id'=>'holiday-'.$holiday['key'],'title'=>$holiday['title'],'from'=>$date.'T00:00:00','to'=>$date.'T23:59:59','isAllDay'=>true,'group'=>'Philippine holidays','color'=>'#475467','colorText'=>'#ffffff',
            'customTags'=>['kind'=>'holiday','holidayKey'=>$holiday['key'],'date'=>$date,'type'=>$type,'typeLabel'=>self::LABELS[$type]??self::LABELS['unclassified'],'noClasses'=>$noClasses,'canManage'=>self::canManage(),'editable'=>self::canManage() && $date>=date('Y-m-d')]];
    }

    public static function save(array $data,int $actorId): void
    {
        if (!self::canManage()) throw new DomainException('You do not have permission to change the campus academic calendar.');
        $date=(string)($data['date']??'');$day=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$day || $day->format('Y-m-d')!==$date) throw new DomainException('Select a valid holiday date.');
        if ($date<date('Y-m-d')) throw new DomainException('Past class dates are read-only to preserve attendance history.');
        $key=(string)($data['holiday_key']??'');$holiday=null;
        foreach(self::feed((int)$day->format('Y'))['holidays'] as $row)if($row['date']===$date && $row['key']===$key)$holiday=$row;
        $pdo=Database::connection();
        if (!$holiday) {
            $q=$pdo->prepare('SELECT title,holiday_type FROM campus_holidays WHERE holiday_date=? AND no_classes=1');$q->execute([$date]);$row=$q->fetch();
            if (!$row || $key!==$date.'-campus') throw new DomainException('That holiday is no longer available. Refresh the calendar.');
            $holiday=['title'=>$row['title'],'type'=>$row['holiday_type']];
        }
        if (!in_array((string)($data['no_classes']??''),['0','1'],true)) throw new DomainException('Choose whether classes are suspended.');
        $noClasses=(int)$data['no_classes'];
        $pdo->beginTransaction();
        try {
            // Never rewrite existing attendance when a policy is changed.
            if ($noClasses) {
                $q=$pdo->prepare('SELECT COUNT(*) FROM attendance_records WHERE attendance_date=?');$q->execute([$date]);
                $count=(int)$q->fetchColumn();
                if ($count) throw new DomainException("This date already has {$count} attendance records and cannot be marked as no class. Existing attendance history has been preserved.");
                $q=$pdo->prepare('SELECT COUNT(*) FROM attendance_sessions WHERE attendance_date=? AND finalized_at IS NOT NULL');$q->execute([$date]);
                if ($q->fetchColumn()) throw new DomainException('This date already has finalized attendance sessions. Existing attendance history has been preserved.');
            }
            $q=$pdo->prepare('INSERT INTO campus_holidays(holiday_date,title,holiday_type,no_classes,updated_by) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),holiday_type=VALUES(holiday_type),no_classes=VALUES(no_classes),updated_by=VALUES(updated_by)');
            $q->execute([$date,$holiday['title'],$holiday['type'],$noClasses,$actorId]);
            AuditService::log($actorId,'set_campus_holiday','campus_holiday',(int)$pdo->lastInsertId(),$date.($noClasses?' · No classes':' · Follow normal schedule'));
            $pdo->commit();
        } catch(Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }
}
