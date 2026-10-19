<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use DomainException;
use PDO;

final class AnalyticsService
{
    private PDO $pdo;
    private AnalyticsHistory $history;
    private array $filters;
    private array $state;
    private int $viewer;
    public function __construct(array $input=[])
    {
        $this->pdo=Database::connection();$this->viewer=(int)(Auth::user()['id']??0);$this->history=new AnalyticsHistory($this->pdo,$this->global('grades')?'1=1':$this->gradeRelationship('g.class_offering_id','g.student_id'));
        $this->state=$this->pdo->query('SELECT * FROM analytics_state WHERE id=1')->fetch()?:throw new DomainException('Analytics is being prepared. Please retry shortly.');
        $term=$this->pdo->query('SELECT * FROM semesters WHERE starts_on<=CURDATE() AND ends_on>=CURDATE() ORDER BY starts_on DESC LIMIT 1')->fetch();
        $yesterday=date('Y-m-d',strtotime('-1 day'));$from=(string)(($input['from']??'')?:($term['starts_on']??date('Y-m-d',strtotime('-30 days'))));$to=(string)(($input['to']??'')?:$yesterday);
        foreach([$from,$to] as $date){$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d || $d->format('Y-m-d')!==$date)throw new DomainException('Choose valid dates.');}
        if($from>$to || $to>date('Y-m-d') || strtotime($to)-strtotime($from)>366*86400*5)throw new DomainException('Choose a past or current period up to five years.');
        $this->filters=['from'=>$from,'to'=>$to];
        foreach(['semester_id','department_id','program_id','section_id','teacher_id','course_id','offering_id'] as $key){$value=$input[$key]??($key==='semester_id'?($term['id']??0):0);if(!is_scalar($value) || !ctype_digit((string)$value))throw new DomainException('Choose a valid academic filter.');$this->filters[$key]=(int)$value;}
        $this->filters['q']=mb_substr(trim((string)($input['q']??'')),0,100);
    }

    /** Management grants on ordinary Teacher/Student roles stay relationship-scoped. */
    public static function globalGrant(string $permission): bool
    {
        if(!Auth::can($permission))return false;
        $preview=Auth::previewRole();
        if($preview)return !in_array($preview['name'],['Teacher','Student'],true);
        $ids=Auth::user()['role_ids'];
        $q=Database::connection()->prepare('SELECT 1 FROM roles r JOIN role_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id WHERE r.id IN('.implode(',',array_fill(0,count($ids),'?')).') AND r.name NOT IN ("Teacher","Student") AND p.code=? LIMIT 1');$q->execute([...$ids,$permission]);return(bool)$q->fetchColumn();
    }
    public static function sections(): array
    {
        $out=['overview'=>AnalyticsMetrics::SECTIONS['overview']];
        if(Auth::canAny(['view_attendance','manage_attendance','manage_reports']))$out['attendance']=AnalyticsMetrics::SECTIONS['attendance'];
        if(Auth::canAny(['view_grades','manage_grades']))$out['grades']=AnalyticsMetrics::SECTIONS['grades'];
        if(Auth::canAny(['view_events','manage_events','view_recollections','manage_recollections']))$out['activities']=AnalyticsMetrics::SECTIONS['activities'];
        if(Auth::canAny(Auth::STUDENT_COMPLIANCE_PERMISSIONS))$out['compliance']=AnalyticsMetrics::SECTIONS['compliance'];
        if((Auth::hasAnyRole(['Teacher','Student']) && Auth::canAny(['view_schedule','manage_seat_plans'])) || self::globalGrant('manage_rooms') || self::globalGrant('manage_academic_structure'))$out['resources']=AnalyticsMetrics::SECTIONS['resources'];
        if(self::globalGrant('manage_rooms') || self::globalGrant('manage_rfid'))$out['scanners']=AnalyticsMetrics::SECTIONS['scanners'];
        if(Auth::can('send_messages'))$out['communications']=AnalyticsMetrics::SECTIONS['communications'];
        $out['quality']=AnalyticsMetrics::SECTIONS['quality'];return $out;
    }
    private function global(string $domain): bool
    {
        return match($domain){'grades'=>self::globalGrant('manage_grades'),'resources'=>self::globalGrant('manage_academic_structure')||self::globalGrant('manage_rooms'),default=>self::globalGrant('manage_attendance')||self::globalGrant('manage_reports')||self::globalGrant('manage_compliance')};
    }
    private function relationship(string $offering,string $student=''): string
    {
        $parts=[];
        if(Auth::hasRole('Teacher'))$parts[]="EXISTS(SELECT 1 FROM teacher_assignments ta WHERE ta.class_offering_id={$offering} AND ta.teacher_id={$this->viewer})";
        if(Auth::hasRole('Student'))$parts[]=$student!==''?"{$student}={$this->viewer}":"EXISTS(SELECT 1 FROM class_enrollments en WHERE en.class_offering_id={$offering} AND en.student_id={$this->viewer})";
        return $parts?'('.implode(' OR ',$parts).')':'0=1';
    }
    private function gradeRelationship(string $offering,string $student=''): string
    {
        $parts=[];
        if (Auth::hasRole('Teacher')) $parts[]='EXISTS(SELECT 1 FROM class_offerings gr WHERE gr.id='.$offering.' AND '.SharedGradeService::teacherScope('gr',(string)$this->viewer).')';
        if (Auth::hasRole('Student')) $parts[]=$student!==''?$student.'='.$this->viewer:'EXISTS(SELECT 1 FROM class_offerings gr WHERE gr.id='.$offering.' AND '.SharedGradeService::studentScope('gr',(string)$this->viewer).')';
        return $parts?'('.implode(' OR ',$parts).')':'0=1';
    }
    private function offeringWhere(string $domain,string $alias='o'): array
    {
        $where=[$this->global($domain)?'1=1':($domain==='grades'?$this->gradeRelationship($alias.'.id'):$this->relationship($alias.'.id'))];$params=[];
        foreach(['semester_id','course_id','section_id'] as $key)if($this->filters[$key]){$where[]="{$alias}.{$key}=?";$params[]=$this->filters[$key];}
        if($this->filters['offering_id']){$where[]=$domain==='grades'?'EXISTS(SELECT 1 FROM class_offerings selected WHERE selected.id=? AND '.SharedGradeService::same('selected',$alias).')':"{$alias}.id=?";$params[]=$this->filters['offering_id'];}
        if($this->filters['department_id']){$where[]="EXISTS(SELECT 1 FROM sections se JOIN programs pr ON pr.id=se.program_id WHERE se.id={$alias}.section_id AND pr.department_id=?)";$params[]=$this->filters['department_id'];}
        if($this->filters['program_id']){$where[]="EXISTS(SELECT 1 FROM sections se WHERE se.id={$alias}.section_id AND se.program_id=?)";$params[]=$this->filters['program_id'];}
        if($this->filters['teacher_id']){$where[]=$domain==='grades'?SharedGradeService::teacherScope($alias,'?'):"EXISTS(SELECT 1 FROM teacher_assignments ta WHERE ta.class_offering_id={$alias}.id AND ta.teacher_id=?)";$params[]=$this->filters['teacher_id'];}
        return [implode(' AND ',$where),$params];
    }
    private function attendanceWhere(bool $daily=false): array
    {
        $where=['f.attendance_date BETWEEN ? AND ?'];if(!$daily)$where[]='(f.expected IS NULL OR f.expected=1)';$params=[$this->filters['from'],$this->filters['to']];
        if(!$this->global('attendance'))$where[]=$this->relationship('f.class_offering_id',$daily?'':'f.student_id');
        foreach(['semester_id','department_id','program_id','section_id','course_id'] as $key)if($this->filters[$key]){$where[]="f.{$key}=?";$params[]=$this->filters[$key];}
        if($this->filters['offering_id']){$where[]='f.class_offering_id=?';$params[]=$this->filters['offering_id'];}
        if($this->filters['teacher_id']){$where[]='EXISTS(SELECT 1 FROM analytics_occurrences occ WHERE occ.class_offering_id=f.class_offering_id AND occ.attendance_date=f.attendance_date AND JSON_CONTAINS(occ.context_json,?,"$.teacher_ids"))';$params[]=json_encode($this->filters['teacher_id']);}
        return [implode(' AND ',$where),$params];
    }
    private function rows(string $sql,array $params=[]): array{$q=$this->pdo->prepare($sql);$q->execute($params);return $q->fetchAll();}
    private function panel(string $key,string $title,string $description,array $columns,array $rows,string $formula,string $kind='line',array $series=[]): array
    {
        $sample=array_sum(array_column($rows,'sample'));
        $coverage='Coverage: recorded observations in your permitted scope; unavailable measurements are not estimated.';
        if(isset($rows[0]['unknown_history']))$coverage='Coverage: '.array_sum(array_column($rows,'unknown_history')).' observations with unknown historical eligibility; '.array_sum(array_column($rows,'missing')).' expected records missing.';
        elseif(isset($rows[0]['known']))$coverage='Policy coverage: '.array_sum(array_column($rows,'known')).' of '.$sample.' finalized results have a captured passing policy.';
        elseif(array_key_exists('eligible_required',$rows[0]??[]))$coverage='Audience coverage: '.count(array_filter($rows,static fn($r)=>$r['history']==='Captured')).' of '.count($rows).' activities have a captured audience; '.array_sum(array_column($rows,'eligible_required')).' known eligible required participants.';
        elseif(str_starts_with($key,'scan'))$coverage='Coverage: authenticated recorded attempts only; unrecorded failures and uptime are unavailable.';
        elseif($key==='message-reach')$coverage='Open-history coverage: '.array_sum(array_column($rows,'legacy_read')).' legacy read copies have no first-open timestamp.';
        elseif(in_array($key,['booked-hours','schedule-detail','booking-overlaps','resource-gaps'],true))$coverage='Coverage: current permitted bookings and seat assignments; actual occupancy is unavailable.';
        return ['key'=>$key,'title'=>$title,'description'=>$description,'columns'=>$columns,'rows'=>$rows,'formula'=>$formula,'chart'=>['kind'=>$kind,'series'=>$series],'sample_size'=>$sample,'coverage'=>$coverage];
    }
    private static function rate($n,$d): ?float{return AnalyticsMetrics::rate((float)$n,(float)$d);}
    private function classUrl(string $domain,int $offering,?int $student=null,?string $date=null): string
    {
        $admin=$domain==='grades'?self::globalGrant('manage_grades'):self::globalGrant('manage_attendance');
        if($domain==='attendance' && !Auth::canAny(['manage_attendance','view_attendance'])){
            $params=['page'=>'analytics','section'=>'attendance','offering_id'=>$offering,'inspect'=>'1'];if($date){$params['from']=$date;$params['to']=$date;}return url((Auth::usesAdminArea()?'/admin/':'/index/').'?'.http_build_query($params));
        }
        $params=['page'=>$domain,'offering_id'=>$offering];if($student)$params['student_id']=$student;if($date)$params['date']=$date;
        return url(($admin?'/admin/':'/index/').'?'.http_build_query($params));
    }

    public function metadata(string $section): array
    {
        if(!isset(self::sections()[$section]))throw new DomainException('This section is outside your permitted scope.');
        $changed=(int)$this->pdo->query('SELECT COALESCE(MAX(id),0) FROM analytics_changes')->fetchColumn();
        $campus=match($section){'scanners'=>self::globalGrant('manage_rooms')||self::globalGrant('manage_rfid'),'activities'=>(Auth::hasRole('Administrator')&&Auth::can('manage_events'))||self::globalGrant('manage_recollections'),'communications'=>false,default=>$this->global($section)};
        $definitions=array_intersect_key(AnalyticsMetrics::definitions($section),self::sections());
        return ['version'=>AnalyticsMetrics::VERSION,'definitions'=>$definitions,'filters'=>$this->filters,'baseline_at'=>$this->state['baseline_at'],'calculated_at'=>date(DATE_ATOM),'built_at'=>$this->state['built_at'],'stale'=>!$this->state['built_at'] || strtotime($this->state['built_at'])<time()-300 || $changed>(int)$this->state['source_cursor'],'worker_error'=>$this->state['worker_error'],'scope'=>$campus?'Authorized campus scope':($section==='communications'?'Own sent messages':(Auth::hasRole('Teacher')?'Assigned classes and personal records':'Personal or explicitly permitted records')),'timezone'=>config('app')['timezone'],'history_note'=>'Prospective eligibility and policy history starts '.$this->state['baseline_at'].'. Earlier records have unknown historical eligibility or policy. Cohort labels on legacy data reflect current class metadata.'];
    }

    public function response(string $section='overview',bool $refresh=false): array
    {
        $available=self::sections();if(!isset($available[$section]))throw new DomainException('This analytics section is outside your permitted scope.');
        if($this->filters['offering_id']){
            [$where,$params]=$this->offeringWhere($section==='grades'?'grades':($section==='resources'?'resources':'attendance'));
            if(!$this->rows('SELECT o.id FROM class_offerings o WHERE '.$where.' LIMIT 1',$params))throw new DomainException('That class is outside your permitted scope or no longer available.');
        }
        $liveRevision=(int)$this->pdo->query('SELECT COALESCE(MAX(id),0) FROM analytics_changes')->fetchColumn();
        $key=hash('sha256',json_encode([$this->viewer,Auth::user()['role_ids'],Auth::permissions(),$section,$this->filters,$this->state['revision'],$liveRevision,AnalyticsMetrics::VERSION],JSON_THROW_ON_ERROR));
        if(!$refresh){$q=$this->pdo->prepare('SELECT payload FROM analytics_cache WHERE cache_key=? AND expires_at>NOW()');$q->execute([$key]);$cached=$q->fetchColumn();if($cached)return json_decode($cached,true,512,JSON_THROW_ON_ERROR);}
        $sections=$section==='overview'?array_values(array_intersect(['attendance','grades','activities','quality'],array_keys($available))):[$section];
        $panels=[];$cards=[];$insights=[];
        foreach($sections as $domain){$data=match($domain){'attendance'=>$this->attendance(),'grades'=>$this->grades(),'activities'=>$this->activities(),'compliance'=>$this->compliance(),'resources'=>$this->resources(),'scanners'=>$this->scanners(),'communications'=>$this->communications(),'quality'=>$this->quality()};$panels=array_merge($panels,$section==='overview'?array_slice($data['panels'],0,1):$data['panels']);$cards=array_merge($cards,$data['cards']??[]);$insights=array_merge($insights,$data['insights']??[]);}
        $changed=(int)$this->pdo->query('SELECT COALESCE(MAX(id),0) FROM analytics_changes')->fetchColumn();
        $payload=['ok'=>true,'section'=>$section,'sections'=>$available,'filters'=>$this->filters,'options'=>$this->options($section),'panels'=>$panels,'cards'=>array_slice($cards,0,8),'insights'=>$insights,'metadata'=>$this->metadata($section)];
        $q=$this->pdo->prepare('INSERT INTO analytics_cache(cache_key,revision,expires_at,payload) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 60 SECOND),?) ON DUPLICATE KEY UPDATE revision=VALUES(revision),expires_at=VALUES(expires_at),payload=VALUES(payload)');$q->execute([$key,$this->state['revision'],json_encode($payload,JSON_THROW_ON_ERROR)]);return $payload;
    }

    public function exportMetadata(string $section): array
    {
        $metadata=$this->metadata($section);
        $labels=['From'=>AnalyticsExport::date($this->filters['from']),'Through'=>AnalyticsExport::date($this->filters['to'])];
        $options=$this->options($section);
        foreach(['semester_id'=>'Semester','department_id'=>'Department','program_id'=>'Program','section_id'=>'Section','teacher_id'=>'Teacher','course_id'=>'Subject','offering_id'=>'Class offering'] as $key=>$label){
            if(!$this->filters[$key])continue;
            $permitted=array_column($options[$key],'label','id');
            $labels[$label]=$permitted[$this->filters[$key]]??'Unavailable in your permitted scope';
        }
        if($this->filters['q']!=='')$labels['Record search']=$this->filters['q'];
        $metadata['filter_labels']=$labels;
        return $metadata;
    }

    private function options(string $section): array
    {
        $domain=$section==='grades'?'grades':($section==='resources'?'resources':'attendance');$scope=$this->global($domain)?'1=1':$this->relationship('o.id');
        $rows=$this->rows('SELECT o.id,o.component_type,c.course_code,c.title,o.course_id,o.semester_id,o.section_id,s.name section,p.id program_id,p.code program,p.name program_name,p.department_id,d.code department,d.name department_name FROM class_offerings o JOIN courses c ON c.id=o.course_id JOIN sections s ON s.id=o.section_id JOIN programs p ON p.id=s.program_id JOIN departments d ON d.id=p.department_id WHERE '.$scope.' ORDER BY c.course_code,s.name');
        $out=['semester_id'=>[],'department_id'=>[],'program_id'=>[],'section_id'=>[],'course_id'=>[],'offering_id'=>[],'teacher_id'=>[]];$ids=[];
        foreach($rows as $r){
            $ids[]=(int)$r['id'];
            $labels=['department_id'=>$r['department'].' · '.$r['department_name'],'program_id'=>$r['program'].' · '.$r['program_name'],'section_id'=>$r['section'],'course_id'=>$r['course_code'].' · '.$r['title']];
            foreach($labels as $key=>$label)$out[$key][$r[$key]]=['id'=>(int)$r[$key],'label'=>$label];
            $out['offering_id'][$r['id']]=['id'=>(int)$r['id'],'label'=>$r['course_code'].' · '.$r['title'].' · '.$r['section'].' · '.$r['component_type']];
        }
        foreach($this->rows('SELECT id,name,starts_on,ends_on FROM semesters ORDER BY starts_on DESC') as $r)$out['semester_id'][$r['id']]=['id'=>(int)$r['id'],'label'=>$r['name'].' · '.$r['starts_on'],'starts_on'=>$r['starts_on'],'ends_on'=>$r['ends_on']];
        if($ids){$q='SELECT DISTINCT u.id,CONCAT_WS(" ",u.first_name,u.middle_name,u.last_name) label FROM teacher_assignments t JOIN users u ON u.id=t.teacher_id WHERE t.class_offering_id IN ('.implode(',',$ids).') ORDER BY label';foreach($this->rows($q) as $r)$out['teacher_id'][$r['id']]=['id'=>(int)$r['id'],'label'=>$r['label']];}
        return array_map('array_values',$out);
    }

    private function attendance(): array
    {
        $dailyScope=$this->global('attendance')||(Auth::hasRole('Teacher')&&!Auth::hasRole('Student'));$table=$dailyScope?'analytics_daily':'analytics_attendance';
        [$where,$params]=$this->attendanceWhere($dailyScope);
        $counts='SUM(f.finalized=1 AND f.status="Present") present,SUM(f.finalized=1 AND f.status="Late") late,SUM(f.finalized=1 AND f.status="Absent") absent,SUM(f.finalized=1 AND f.status="Excused") excused,SUM(f.status IS NULL) missing,SUM(f.history_known=0) unknown_history,COUNT(*) sample,SUM(f.finalized=0) provisional,AVG(CASE WHEN f.finalized=1 THEN f.late_minutes END) late_minutes,COUNT(DISTINCT CONCAT(f.class_offering_id,"/",f.attendance_date)) sessions,COUNT(DISTINCT CASE WHEN f.finalized=0 THEN CONCAT(f.class_offering_id,"/",f.attendance_date) END) pending_sessions';
        if($dailyScope)$counts='SUM(f.present_count) present,SUM(f.late_count) late,SUM(f.absent_count) absent,SUM(f.excused_count) excused,SUM(f.missing_count) missing,SUM(f.unknown_count) unknown_history,SUM(f.observation_count) sample,SUM(f.provisional_count) provisional,SUM(f.late_minutes_sum)/NULLIF(SUM(f.late_minutes_n),0) late_minutes,COUNT(*) sessions,SUM(f.provisional_count>0) pending_sessions';
        $r=$this->rows('SELECT '.$counts.' FROM '.$table.' f WHERE '.$where,$params)[0];$den=(int)$r['present']+(int)$r['late']+(int)$r['absent'];
        $daily=$this->rows('SELECT f.attendance_date label,'.$counts.' FROM '.$table.' f WHERE '.$where.' GROUP BY f.attendance_date ORDER BY f.attendance_date',$params);
        foreach($daily as &$d){$d['participation']=self::rate($d['present']+$d['late'],$d['present']+$d['late']+$d['absent']);$d['lateness']=self::rate($d['late'],$d['present']+$d['late']);$d['late_minutes']=$d['late_minutes']!==null?round((float)$d['late_minutes'],1):null;}unset($d);
        $byClass=$this->rows('SELECT f.class_offering_id,c.course_code,s.name section,CONCAT(c.course_code," · ",s.name) label,'.$counts.' FROM '.$table.' f JOIN class_offerings o ON o.id=f.class_offering_id JOIN courses c ON c.id=o.course_id JOIN sections s ON s.id=o.section_id WHERE '.$where.' GROUP BY f.class_offering_id,c.course_code,s.name ORDER BY c.course_code,s.name',$params);
        foreach($byClass as &$d){$d['participation']=self::rate($d['present']+$d['late'],$d['present']+$d['late']+$d['absent']);$d['lateness']=self::rate($d['late'],$d['present']+$d['late']);$d['url']=$this->classUrl('attendance',(int)$d['class_offering_id']);}unset($d);
        $week=$this->rows('SELECT DAYNAME(f.attendance_date) label,WEEKDAY(f.attendance_date) day,'.$counts.' FROM '.$table.' f WHERE '.$where.' GROUP BY WEEKDAY(f.attendance_date),DAYNAME(f.attendance_date) ORDER BY day',$params);
        foreach($week as &$d){$d['participation']=self::rate($d['present']+$d['late'],$d['present']+$d['late']+$d['absent']);$d['lateness']=self::rate($d['late'],$d['present']+$d['late']);}unset($d);
        $insights=[];
        if((int)$r['missing'])$insights[]=['severity'=>'warning','title'=>$r['missing'].' expected observations need a record','detail'=>'Missing records are excluded from attendance rates. Review coverage before comparing classes.','url'=>url((Auth::usesAdminArea()?'/admin/':'/index/').'?page=analytics&section=quality')];
        if((int)$r['pending_sessions'])$insights[]=['severity'=>'warning','title'=>$r['pending_sessions'].' sessions remain provisional','detail'=>$this->filters['from'].' through '.$this->filters['to'].': '.$r['provisional'].' observations are excluded from finalized rates. Review after their meetings end.','url'=>url((Auth::usesAdminArea()?'/admin/':'/index/').'?page=analytics&section=quality')];
        $completed=array_values(array_filter($daily,static fn($x)=>$x['participation']!==null));
        if(count($completed)>=10){$previous=array_slice($completed,-10,5);$current=array_slice($completed,-5);$sum=static fn($rows,$keys)=>array_sum(array_map(static fn($x)=>array_sum(array_map(static fn($k)=>(int)$x[$k],$keys)),$rows));$pd=$sum($previous,['present','late','absent']);$cd=$sum($current,['present','late','absent']);$p=self::rate($sum($previous,['present','late']),$pd);$c=self::rate($sum($current,['present','late']),$cd);if($pd>=20 && $cd>=20 && $sum($previous,['unknown_history'])===0 && $sum($current,['unknown_history'])===0 && $p-$c>=5)$insights[]=['severity'=>'warning','title'=>'Participation fell '.round($p-$c,1).' percentage points','detail'=>"Latest five recorded academic days: {$c}% ({$cd} observations); preceding five: {$p}% ({$pd}). Same selected scope; cohort changes can affect comparisons.",'url'=>url((Auth::usesAdminArea()?'/admin/':'/index/').'?page=analytics&section=attendance')];}
        [$todayWhere,$todayParams]=$this->offeringWhere('attendance');if(!$this->global('attendance'))$todayWhere.=' AND '.$this->relationship('o.id','ar.student_id');
        $todayRecords=(int)$this->rows('SELECT COUNT(*) total FROM attendance_records ar JOIN class_offerings o ON o.id=ar.class_offering_id LEFT JOIN attendance_sessions ses ON ses.class_offering_id=ar.class_offering_id AND ses.attendance_date=ar.attendance_date WHERE '.$todayWhere.' AND ar.attendance_date=CURDATE() AND ses.finalized_at IS NULL',$todayParams)[0]['total'];
        return ['cards'=>[['label'=>'Recorded participation','value'=>self::rate($r['present']+$r['late'],$den),'unit'=>'%','detail'=>$den.' finalized non-excused observations'],['label'=>'Late arrivals','value'=>self::rate($r['late'],$r['present']+$r['late']),'unit'=>'%','detail'=>($r['present']+$r['late']).' recorded arrivals'],['label'=>'Unrecorded expected','value'=>(int)$r['missing'],'detail'=>$r['provisional'].' provisional observations; '.$r['excused'].' excused'],['label'=>'Today: provisional','value'=>$todayRecords,'detail'=>'Current-day unfinalized records in the selected academic scope; excluded from completed-period rates']], 'insights'=>$insights,'panels'=>[
            $this->panel('attendance-trend','Is participation holding steady?','Finalized participation over the selected period. Gaps indicate unavailable rates.',['label'=>'Date','participation'=>'Participation %','lateness'=>'Late %','present'=>'Present','late'=>'Late','absent'=>'Absent','excused'=>'Excused','missing'=>'Missing','sample'=>'Observations'],self::bucketDays($daily),AnalyticsMetrics::FORMULAS['participation'],'line',['participation'=>'Participation','lateness'=>'Lateness']),
            $this->panel('attendance-subjects','Which subjects need a closer look?','Rates retain their denominators; current class labels identify each offering.',['label'=>'Subject · section','participation'=>'Participation %','lateness'=>'Late %','absent'=>'Absent','missing'=>'Missing','sample'=>'Observations'],$byClass,AnalyticsMetrics::FORMULAS['participation'],'dot',['participation'=>'Participation','lateness'=>'Lateness']),
            $this->panel('attendance-week','Are particular weekdays more difficult?','A weekday pattern is descriptive; timetable and cohort differences matter.',['label'=>'Weekday','participation'=>'Participation %','lateness'=>'Late %','late_minutes'=>'Minutes late','sample'=>'Observations'],$week,AnalyticsMetrics::FORMULAS['lateness'],'heatmap',['participation'=>'Participation','lateness'=>'Lateness'])]];
    }

    /** Bound SVG points without averaging daily percentages. */
    private static function bucketDays(array $rows): array
    {
        if(count($rows)<=180)return $rows;$out=[];
        foreach($rows as $row){$key=date('Y-m-01',strtotime($row['label']));if(!isset($out[$key]))$out[$key]=['label'=>$key,'through'=>$row['label'],'present'=>0,'late'=>0,'absent'=>0,'excused'=>0,'missing'=>0,'sample'=>0];$out[$key]['through']=$row['label'];foreach(['present','late','absent','excused','missing','sample'] as $field)$out[$key][$field]+=(int)$row[$field];}
        foreach($out as &$r){$r['participation']=self::rate($r['present']+$r['late'],$r['present']+$r['late']+$r['absent']);$r['lateness']=self::rate($r['late'],$r['present']+$r['late']);}unset($r);return array_values($out);
    }

    private function grades(): array
    {
        [$where,$params]=$this->offeringWhere('grades');
        if(!$this->global('grades'))$where.=' AND '.$this->gradeRelationship('o.id','e.student_id');
        $where.=' AND e.enrolled_at<=?';$params[]=$this->filters['to'].' 23:59:59';
        $q=$this->pdo->prepare('SELECT o.id offering_id,o.component_type,c.course_code,c.title,s.name section,e.student_id,e.id enrollment_id,e.enrollment_ids,g.source_offering_id,g.prelim,g.midterm,g.final,g.overall,g.status,g.finalized_at FROM '.SharedGradeService::subjects().' o JOIN courses c ON c.id=o.course_id JOIN sections s ON s.id=o.section_id JOIN '.SharedGradeService::enrollments().' e ON e.class_offering_id=o.id LEFT JOIN '.SharedGradeService::grades().' g ON g.class_offering_id=o.id AND g.student_id=e.student_id WHERE '.$where.' ORDER BY c.course_code,o.id');$q->execute($params);
        $groups=[];$bins=array_fill(0,10,0);$known=0;$passed=0;$finalized=0;$draft=0;$missing=0;$eligibleTotal=0;$completeTotal=0;$eligibilityKnown=$this->filters['to'].' 23:59:59'>=$this->state['baseline_at'];
        foreach($q as $g){$key=$g['offering_id'];if(!isset($groups[$key]))$groups[$key]=['label'=>$g['course_code'].' · '.$g['component_type'].' · '.$g['section'],'offering_id'=>$key,'sum'=>0,'sample'=>0,'expected'=>0,'complete'=>0,'prelim_sum'=>0,'prelim_n'=>0,'midterm_sum'=>0,'midterm_n'=>0,'endterms_sum'=>0,'endterms_n'=>0,'paired_midterm_sum'=>0,'paired_endterms_sum'=>0,'paired_sample'=>0,'known'=>0,'passed'=>0,'url'=>$this->classUrl('grades',(int)$key)];$r=&$groups[$key];$eligible=false;if($eligibilityKnown)foreach(explode(',',$g['enrollment_ids']) as $enrollmentId){$enrollment=$this->history->at('class_enrollments',$enrollmentId,$this->filters['to'].' 23:59:59');if($enrollment && in_array($enrollment['status'],['enrolled','completed'],true)){$eligible=true;break;}}if($eligible){$r['expected']++;$eligibleTotal++;if($g['status']==='finalized' && $g['overall']!==null && $g['finalized_at'] && substr($g['finalized_at'],0,10)<=$this->filters['to']){$r['complete']++;$completeTotal++;}}
            if($g['status']!=='finalized' || !$g['finalized_at'] || substr($g['finalized_at'],0,10)>$this->filters['to']){if($eligible){if($g['status']==='draft')$draft++;else$missing++;}unset($r);continue;}
            if(substr($g['finalized_at'],0,10)<$this->filters['from']){unset($r);continue;}
            if($g['overall']===null){if($eligible)$missing++;unset($r);continue;}
            $r['sum']+=(float)$g['overall'];$r['sample']++;$finalized++;$bins[min(9,(int)floor((float)$g['overall']/10))]++;
            $snapshot=$this->history->finalizedGrade((int)$g['source_offering_id'],(int)$g['student_id'],$g['finalized_at']);$policy=$snapshot['_policy']??null;
            foreach(['prelim'=>'prelim','midterm'=>'midterm','final'=>'endterms'] as $field=>$label)if($g[$field]!==null && (!$policy || $policy['has_'.$field])){$r[$label.'_sum']+=(float)$g[$field];$r[$label.'_n']++;}
            if($g['midterm']!==null && $g['final']!==null && (!$policy || ($policy['has_midterm'] && $policy['has_final']))){$r['paired_midterm_sum']+=(float)$g['midterm'];$r['paired_endterms_sum']+=(float)$g['final'];$r['paired_sample']++;}
            if($policy){$known++;$r['known']++;if((float)$g['overall']>=(float)$policy['passing_grade']){$passed++;$r['passed']++;}}unset($r);
        }
        $rows=[];foreach($groups as $g){foreach(['prelim','midterm','endterms'] as $term)$g[$term]=$g[$term.'_n']?round($g[$term.'_sum']/$g[$term.'_n'],2):null;$g['average']=$g['sample']?round($g['sum']/$g['sample'],2):null;$g['paired_midterm']=$g['paired_sample']?round($g['paired_midterm_sum']/$g['paired_sample'],2):null;$g['paired_endterms']=$g['paired_sample']?round($g['paired_endterms_sum']/$g['paired_sample'],2):null;$g['paired_change']=$g['paired_sample']?round(($g['paired_endterms_sum']-$g['paired_midterm_sum'])/$g['paired_sample'],2):null;$g['passing']=self::rate($g['passed'],$g['known']);$g['completion']=$eligibilityKnown?self::rate($g['complete'],$g['expected']):null;$rows[]=$g;}
        $hist=[];foreach($bins as $index=>$count)$hist[]=['label'=>($index*10).'–'.($index===9?100:$index*10+9.99),'scores'=>$count,'sample'=>$count];
        return ['cards'=>[['label'=>'Finalized roster coverage','value'=>$eligibilityKnown?self::rate($completeTotal,$eligibleTotal):null,'unit'=>'%','detail'=>"{$finalized} finalized · {$draft} drafts · {$missing} missing"],['label'=>'Passing with known policy','value'=>self::rate($passed,$known),'unit'=>'%','detail'=>"{$known} results with captured policy; ".($finalized-$known).' legacy results excluded']], 'insights'=>$missing?[['severity'=>'warning','title'=>$missing.' results remain unavailable','detail'=>'Drafts, unresolved component conflicts, and missing results are excluded from score distributions. Passing is unavailable for legacy policies.','url'=>url((Auth::usesAdminArea()?'/admin/':'/index/').'?page=analytics&section=grades')]]:[],'panels'=>[
            $this->panel('grade-terms','How did Midterms and Endterms change?','Paired Midterms and Endterms averages use the same finalized student results with both enabled scores. Overall averages retain one shared finalized subject grade per student; legacy term values retain their stored labels.',['label'=>'Subject · component · section','prelim'=>'Prelims','paired_midterm'=>'Midterms (paired)','paired_endterms'=>'Endterms (paired)','paired_change'=>'Change (points)','paired_sample'=>'Paired results','average'=>'Overall mean','passing'=>'Passing %','completion'=>'Finalized %','sample'=>'Finalized results'],$rows,AnalyticsMetrics::FORMULAS['grades'],'dot',['paired_midterm'=>'Midterms (paired)','paired_endterms'=>'Endterms (paired)']),
            $this->panel('grade-distribution','Where do finalized scores cluster?','Each student contributes one shared subject grade; no guessed credit weighting.',['label'=>'Score interval','scores'=>'Finalized scores','sample'=>'Results'],$hist,AnalyticsMetrics::FORMULAS['grades'],'histogram',['scores'=>'Finalized scores'])]];
    }

    private function activities(): array
    {
        $rows=[];$total=0;$done=0;$unknown=0;$firstBaseline=$this->state['baseline_at'];
        foreach(['event'=>['events','event_participants','event_attendance','event_id','event_date','check_in_at'],'recollection'=>['recollections','recollection_participants','recollection_attendance','recollection_id','date','time_in']] as $type=>$config){
            if(!Auth::canAny($type==='event'?['view_events','manage_events']:['view_recollections','manage_recollections']))continue;
            [$table,$participants,$attendance,$foreign,$dateField,$arrival]=$config;$global=self::globalGrant($type==='event'?'manage_events':'manage_recollections');
            $where="a.{$dateField}<=? AND COALESCE(a.end_date,a.{$dateField})>=? AND a.status NOT IN ('draft','cancelled')";$params=[$this->filters['to'],$this->filters['from']];
            if($type==='event')$where.=' AND '.EventAccessService::visibleCondition($this->viewer,'a');
            if($type!=='event' && !$global){$where.=" AND (EXISTS(SELECT 1 FROM {$participants} p WHERE p.{$foreign}=a.id AND p.user_id=?) OR EXISTS(SELECT 1 FROM {$attendance} r WHERE r.{$foreign}=a.id AND r.user_id=?))";$params[]=$this->viewer;$params[]=$this->viewer;}
            $academicFilters=array_filter(array_intersect_key($this->filters,array_flip(['department_id','program_id','section_id','teacher_id','course_id','offering_id'])));
            foreach($this->rows("SELECT a.* FROM {$table} a WHERE {$where} ORDER BY a.{$dateField}",$params) as $a){
                $activityGlobal=$type==='event'?EventAccessService::canManage($a,$this->viewer):$global;
                $instant=$a[$dateField].' '.$a['start_time'];$known=$instant>=$firstBaseline;$snap=$known?$this->history->at($table,$a['id'],$instant):null;$known=$known && $snap!==null;
                $required=[];$optional=[];
                if($academicFilters && !$known)continue;
                if($known){foreach($this->history->all($participants,$instant) as $p){if((int)$p[$foreign]!== (int)$a['id'] || (!$activityGlobal && (int)$p['user_id']!==$this->viewer) || !$this->personMatches((int)$p['user_id'],$instant))continue;$isRequired=(bool)$snap['attendance_required'] && ($type!=='event' || $p['is_required']);if($isRequired)$required[(int)$p['user_id']]=true;else$optional[(int)$p['user_id']]=true;}}
                $records=$this->rows("SELECT user_id,status,{$arrival} arrival".($type==='event'?',check_out_at':'')." FROM {$attendance} WHERE {$foreign}=?".($activityGlobal?'':' AND user_id='.$this->viewer),[$a['id']]);
                $completed=0;$excused=0;$incomplete=0;$optionalDone=0;$recorded=0;$delays=[];
                foreach($records as $r){$uid=(int)$r['user_id'];if(!$this->personMatches($uid,$instant))continue;$recorded++;$ok=in_array($r['status'],['Attended','Late'],true);if($type==='event' && ($snap['check_out_required']??$a['check_out_required']) && !$r['check_out_at'])$ok=false;
                    if(isset($required[$uid])){if($r['status']==='Excused')$excused++;elseif($ok)$completed++;elseif($r['status']==='Incomplete' || ($type==='event' && $r['arrival'] && !$r['check_out_at']))$incomplete++;}elseif(isset($optional[$uid]) && $ok)$optionalDone++;
                    if($r['arrival'])$delays[]=max(0,(strtotime($r['arrival'])-strtotime($instant))/60);
                }
                if($academicFilters && !$required && !$optional && !$recorded)continue;
                $den=$known?count($required)-$excused:0;$closed=$a['status']==='closed';$rate=$known && $closed?self::rate($completed,$den):null;
                if(!$known)$unknown++;if($closed && $known){$total+=$den;$done+=$completed;}
                $rows[]=['label'=>$a['title'],'date'=>$a[$dateField],'end_date'=>$a['end_date'] ?: $a[$dateField],'type'=>ucfirst($type),'completion'=>$rate,'completed'=>$known?$completed:null,'eligible_required'=>$known?$den:null,'excused'=>$excused,'incomplete'=>$incomplete,'optional'=>$optionalDone,'arrival_minutes'=>$delays?round(array_sum($delays)/count($delays),1):null,'status'=>$a['status'],'history'=>$known?'Captured':'Unknown legacy audience','sample'=>$recorded,'url'=>$type==='event'?EventAccessService::viewUrl((int)$a['id']):url(($global?'/admin/':'/index/').'?page=recollections&'.($global?'manage_recollection':'focus_recollection').'='.$a['id'])];
            }
        }
        return ['cards'=>[['label'=>'Required completion','value'=>self::rate($done,$total),'unit'=>'%','detail'=>"{$done} completed of {$total} eligible required participants in closed activities"],['label'=>'Unknown activity history','value'=>$unknown,'detail'=>'Audience-based rates withheld']], 'insights'=>[], 'panels'=>[$this->panel('activity-completion','Did activities reach their required audience?','Activities overlapping the selected dates are included. Closed activities have final rates; open activities remain provisional. Activity dates are independent of the academic semester selector. Academic filters apply through participant relationships.',['label'=>'Activity','date'=>'Start date','end_date'=>'End date','type'=>'Type','completion'=>'Required completion %','eligible_required'=>'Eligible required','completed'=>'Completed','incomplete'=>'Incomplete','optional'=>'Optional attended','status'=>'Status','history'=>'History','sample'=>'Recorded attendees'],$rows,AnalyticsMetrics::FORMULAS['activities'],'dot',['completion'=>'Required completion']),$this->panel('activity-arrivals','How close to the start did attendees arrive?','Mean minutes after the activity start among recorded arrivals. Earlier arrivals contribute zero.',['label'=>'Activity','date'=>'Start date','end_date'=>'End date','arrival_minutes'=>'Mean minutes after start','sample'=>'Recorded attendees'],$rows,'Sum of nonnegative minutes after scheduled activity start ÷ valid recorded arrivals.','dot',['arrival_minutes'=>'Arrival minutes'])]];
    }

    /** Activity/disciplinary cohorts use dated public academic placement, never private profile text. */
    private function personMatches(int $user,string $instant): bool
    {
        $filters=array_filter(array_intersect_key($this->filters,array_flip(['department_id','program_id','section_id','teacher_id','course_id','offering_id'])));
        if(!$filters)return true;
        $placement=$this->history->at('users',$user,$instant);if(!$placement)return false;
        foreach(['department_id','program_id','section_id'] as $key)if(isset($filters[$key]) && (int)($placement[$key]??0)!==$filters[$key])return false;
        if(!array_intersect_key($filters,array_flip(['teacher_id','course_id','offering_id'])))return true;
        foreach($this->history->all('class_enrollments',$instant) as $en){
            if((int)$en['student_id']!==$user || $en['status']!=='enrolled')continue;
            $o=$this->history->at('class_offerings',$en['class_offering_id'],$instant);if(!$o)continue;
            if(isset($filters['offering_id']) && (int)$o['id']!==$filters['offering_id'])continue;
            if(isset($filters['course_id']) && (int)$o['course_id']!==$filters['course_id'])continue;
            if(isset($filters['teacher_id'])){$match=false;foreach($this->history->all('teacher_assignments',$instant) as $ta)if((int)$ta['class_offering_id']===(int)$o['id'] && (int)$ta['teacher_id']===$filters['teacher_id'])$match=true;if(!$match)continue;}
            return true;
        }
        return false;
    }

    private function compliance(): array
    {
        [$where,$params]=$this->attendanceWhere();
        $rows=$this->rows('SELECT f.student_id,f.class_offering_id,f.tardies_per_absence,CONCAT_WS(" ",u.first_name,u.middle_name,u.last_name) student,u.school_id,CONCAT(c.course_code," · ",s.name) subject,SUM(f.status="Absent") absent,SUM(f.status="Late") late,COUNT(*) sample FROM analytics_attendance f JOIN users u ON u.id=f.student_id JOIN class_offerings o ON o.id=f.class_offering_id JOIN courses c ON c.id=o.course_id JOIN sections s ON s.id=o.section_id WHERE '.$where.' AND f.finalized=1 GROUP BY f.student_id,f.class_offering_id,f.tardies_per_absence,u.first_name,u.middle_name,u.last_name,u.school_id,c.course_code,s.name HAVING absent>0 OR late>0 ORDER BY absent DESC,late DESC',$params);
        foreach($rows as &$r){$r['label']=$r['student'].' · '.$r['subject'];$r['equivalent']=$r['tardies_per_absence']?intdiv((int)$r['late'],(int)$r['tardies_per_absence']):null;$r['burden']=(int)$r['absent']+($r['equivalent']??0);$r['url']=url((Auth::usesAdminArea()?'/admin/':'/index/').'?page=compliance&student_id='.$r['student_id'].'&q='.rawurlencode($r['school_id']));}unset($r);
        $panels=[$this->panel('compliance-patterns','Where are repeated attendance deficiencies accumulating?','Per student, subject and historical tardy conversion policy. Legacy conversions remain unavailable.',['label'=>'Student · subject','absent'=>'Absences','late'=>'Tardies','tardies_per_absence'=>'Conversion threshold','equivalent'=>'Tardy equivalent','burden'=>'Known burden','sample'=>'Finalized observations'],$rows,AnalyticsMetrics::FORMULAS['compliance'],'dot',['absent'=>'Absences','late'=>'Tardies'])];
        if(self::globalGrant('manage_compliance')){
            $discipline=[];
            foreach($this->rows('SELECT student_id,classification,is_progressive,incident_date,incident_time FROM disciplinary_violations WHERE incident_date BETWEEN ? AND ?',[$this->filters['from'],$this->filters['to']]) as $v){if(!$this->personMatches((int)$v['student_id'],$v['incident_date'].' '.$v['incident_time']))continue;$key=$v['classification'];if(!isset($discipline[$key]))$discipline[$key]=['label'=>$key,'incidents'=>0,'progressive'=>0,'sample'=>0,'students'=>[]];$discipline[$key]['incidents']++;$discipline[$key]['sample']++;$discipline[$key]['progressive']+=(int)$v['is_progressive'];$discipline[$key]['students'][$v['student_id']]=true;}
            foreach($discipline as &$v){$v['affected']=count($v['students']);unset($v['students']);}unset($v);$discipline=array_values($discipline);
            $panels[]=$this->panel('disciplinary-patterns','What recorded incident patterns need review?','Authorized management summary only. Narratives, witnesses and evidence are excluded.',['label'=>'Classification','incidents'=>'Recorded incidents','affected'=>'Distinct affected students','progressive'=>'Progressive records','sample'=>'Records'],$discipline,'Recorded incidents by incident date and classification; one incident per stored violation.','dot',['incidents'=>'Recorded incidents']);
        }
        $a=$this->activities();$panels[]=$a['panels'][0];
        return ['cards'=>[],'panels'=>$panels,'insights'=>array_values(array_map(static fn($r)=>['severity'=>'warning','title'=>$r['subject'].': recurring tardiness','detail'=>$r['late'].' tardies across '.$r['sample'].' observations; conversion threshold '.$r['tardies_per_absence'].'.','url'=>$r['url']],array_slice(array_filter($rows,static fn($r)=>$r['equivalent']!==null && $r['equivalent']>0),0,5)))];
    }

    private function resources(): array
    {
        [$where,$params]=$this->offeringWhere('resources');
        $seatScope=$this->global('resources')?'1=1':$this->relationship('o.id','en.student_id');
        $assignedScope=$this->global('resources')?'1=1':$this->relationship('o.id','sa.student_id');
        $rows=$this->rows('SELECT o.id offering_id,CONCAT(c.course_code," · ",se.name) label,c.course_code,se.name section,sem.starts_on,sem.ends_on,s.id schedule_id,s.day_of_week,s.start_time,s.end_time,s.room_id,r.room_code,(SELECT COUNT(*) FROM class_enrollments en WHERE en.class_offering_id=o.id AND en.status="enrolled" AND '.$seatScope.') enrolled,(SELECT COUNT(DISTINCT sa.student_id) FROM class_seat_assignments sa WHERE sa.class_offering_id=o.id AND EXISTS(SELECT 1 FROM class_enrollments seat_en WHERE seat_en.class_offering_id=o.id AND seat_en.student_id=sa.student_id AND seat_en.status="enrolled") AND '.$assignedScope.') seated FROM class_offerings o JOIN courses c ON c.id=o.course_id JOIN sections se ON se.id=o.section_id LEFT JOIN class_schedules s ON s.class_offering_id=o.id LEFT JOIN rooms r ON r.id=s.room_id JOIN semesters sem ON sem.id=o.semester_id WHERE '.$where.' AND sem.starts_on<=? AND sem.ends_on>=? AND o.status!="cancelled" ORDER BY s.day_of_week,s.start_time',$params= array_merge($params,[$this->filters['to'],$this->filters['from']]));
        $heat=[];$exceptions=[];$hours=0;
        foreach($rows as &$r){$r['minutes']=$r['start_time']?round((strtotime($r['end_time'])-strtotime($r['start_time']))/60,1):null;$r['seat_coverage']=self::rate(min((int)$r['seated'],(int)$r['enrolled']),$r['enrolled']);$r['sample']=1;$r['url']=self::globalGrant('manage_academic_structure')?url('/admin/?page=academics&manage=offering&entity_id='.$r['offering_id']):url('/index/?page=schedule&offering_id='.$r['offering_id']);
            $hours+=($r['minutes']??0)/60;
            if(!$r['schedule_id'] || !$r['room_id'] || (int)$r['seated']<(int)$r['enrolled'])$exceptions[]=$r+['issue'=>!$r['schedule_id']?'No meeting schedule':(!$r['room_id']?'Room unassigned':'Permitted students without seat assignments')];
            if($r['start_time'])for($hour=(int)substr($r['start_time'],0,2);$hour<(int)ceil((strtotime($r['end_time'])-strtotime('00:00:00'))/3600);$hour++){$key=$r['day_of_week'].'/'.$hour;if(!isset($heat[$key]))$heat[$key]=['label'=>$r['day_of_week'],'hour'=>str_pad((string)$hour,2,'0',STR_PAD_LEFT).':00','booked'=>0,'sample'=>0];$heat[$key]['booked']+=max(0,min(strtotime($r['end_time']),strtotime(($hour+1).':00'))-max(strtotime($r['start_time']),strtotime($hour.':00')))/60;$heat[$key]['sample']++;}
        }unset($r);
        $overlaps=[];foreach($rows as $i=>$a)foreach(array_slice($rows,$i+1) as $b){if(!$a['room_id'] || $a['room_id']!==$b['room_id'] || $a['day_of_week']!==$b['day_of_week'] || $a['offering_id']===$b['offering_id'] || max($a['starts_on'],$b['starts_on'])>min($a['ends_on'],$b['ends_on']) || $a['start_time']>=$b['end_time'] || $a['end_time']<=$b['start_time'])continue;$overlaps[]=['label'=>$a['room_code'].' · '.$a['day_of_week'],'first'=>$a['label'],'second'=>$b['label'],'start'=>max($a['start_time'],$b['start_time']),'end'=>min($a['end_time'],$b['end_time']),'sample'=>1,'url'=>$a['url']];}
        return ['cards'=>[['label'=>'Weekly booked hours','value'=>round($hours,1),'detail'=>'Current timetable for the selected offerings; not occupancy'],['label'=>'Visible booking overlaps','value'=>count($overlaps),'detail'=>'Overlapping intervals within your permitted scope']], 'insights'=>$overlaps?[['severity'=>'warning','title'=>count($overlaps).' visible room booking overlaps','detail'=>'Inspect both offerings before changing the timetable.','url'=>url((Auth::usesAdminArea()?'/admin/':'/index/').'?page=analytics&section=resources')]]:[], 'panels'=>[
            $this->panel('booked-hours','When are teaching bookings concentrated?','Current weekly timetable, restricted to selected offerings. Historical booking estimates are not inferred.',['label'=>'Weekday','hour'=>'Hour','booked'=>'Booked minutes','sample'=>'Meetings'],array_values($heat),AnalyticsMetrics::FORMULAS['resources'],'heatmap',['booked'=>'Booked minutes']),
            $this->panel('schedule-detail','How are the selected class schedules arranged?','Follow a row to its class workspace.',['label'=>'Subject · section','day_of_week'=>'Day','start_time'=>'Starts','end_time'=>'Ends','room_code'=>'Room','minutes'=>'Booked minutes','seat_coverage'=>'Permitted seat assignment %','sample'=>'Meeting'],$rows,AnalyticsMetrics::FORMULAS['resources'],'timeline',[]),
            $this->panel('booking-overlaps','Which bookings overlap?','Only accessible offerings are disclosed.',['label'=>'Room · weekday','first'=>'First offering','second'=>'Second offering','start'=>'Overlap begins','end'=>'Overlap ends','sample'=>'Conflict'],$overlaps,AnalyticsMetrics::FORMULAS['resources'],'table'),
            $this->panel('resource-gaps','Which classes need setup work?','Missing rooms, schedules, or student seat assignments.',['label'=>'Subject · section','issue'=>'Issue','enrolled'=>'Enrolled','seated'=>'Assigned seats','sample'=>'Meeting'],$exceptions,'Distinct enrolled students with a seat assignment ÷ currently enrolled students.','table')]];
    }

    private function scanners(): array
    {
        $where='l.created_at>=? AND l.created_at<? AND l.outcome IN ("attendance_recorded","event_attendance_recorded","rejected")';$params=[$this->filters['from'].' 00:00:00',date('Y-m-d',strtotime($this->filters['to'].' +1 day')).' 00:00:00'];
        if(array_filter(array_intersect_key($this->filters,array_flip(['department_id','program_id','section_id','teacher_id','course_id','offering_id'])))){[$ow,$op]=$this->offeringWhere('resources','o');$where.=' AND EXISTS(SELECT 1 FROM class_offerings o WHERE o.id=l.class_offering_id AND '.$ow.')';$params=array_merge($params,$op);}
        $byDevice=$this->rows('SELECT d.id,d.device_code,CONCAT(d.device_code," · ",d.name) label,SUM(l.outcome="rejected") rejected,SUM(l.outcome!="rejected") accepted,COUNT(*) sample FROM device_scan_logs l JOIN devices d ON d.id=l.device_id WHERE '.$where.' GROUP BY d.id,d.device_code,d.name ORDER BY rejected DESC',$params);
        foreach($byDevice as &$r){$r['rejection']=self::rate($r['rejected'],$r['sample']);$r['url']=url('/admin/?page=rooms&manage=device&entity_id='.$r['id']);}unset($r);
        $daily=$this->rows('SELECT DATE(l.created_at) label,SUM(l.outcome="rejected") rejected,SUM(l.outcome!="rejected") accepted,COUNT(*) sample FROM device_scan_logs l WHERE '.$where.' GROUP BY DATE(l.created_at) ORDER BY label',$params);if(count($daily)>180){$months=[];foreach($daily as $r){$key=substr($r['label'],0,7).'-01';if(!isset($months[$key]))$months[$key]=['label'=>$key,'through'=>$r['label'],'rejected'=>0,'accepted'=>0,'sample'=>0];$months[$key]['through']=$r['label'];foreach(['rejected','accepted','sample'] as $field)$months[$key][$field]+=(int)$r[$field];}$daily=array_values($months);}foreach($daily as &$r)$r['rejection']=self::rate($r['rejected'],$r['sample']);unset($r);
        $reasons=$this->rows('SELECT COALESCE(l.reason_code,"legacy_unclassified") label,COUNT(*) rejected,COUNT(*) sample FROM device_scan_logs l WHERE '.$where.' AND l.outcome="rejected" GROUP BY l.reason_code ORDER BY rejected DESC',$params);
        $n=array_sum(array_column($byDevice,'sample'));$rejected=array_sum(array_column($byDevice,'rejected'));
        return ['cards'=>[['label'=>'Recorded rejection rate','value'=>self::rate($rejected,$n),'unit'=>'%','detail'=>"{$rejected} rejected of {$n} attendance scan attempts"]], 'insights'=>$rejected?[['severity'=>'warning','title'=>$rejected.' scans were rejected','detail'=>'Review reason codes and affected scanners. Registration attempts are excluded.','url'=>url('/admin/?page=analytics&section=scanners')]]:[], 'panels'=>[$this->panel('scan-trends','When are rejected scans increasing?','Authenticated scan outcomes; outages and requests rejected before device identification are not measured.',['label'=>'Date','rejection'=>'Rejected %','rejected'=>'Rejected','accepted'=>'Accepted','sample'=>'Attempts'],$daily,AnalyticsMetrics::FORMULAS['scanners'],'line',['rejection'=>'Rejected']),$this->panel('scan-devices','Which scanners need inspection?','Rates show sample sizes and link to device management.',['label'=>'Scanner','rejection'=>'Rejected %','rejected'=>'Rejected','accepted'=>'Accepted','sample'=>'Attempts'],$byDevice,AnalyticsMetrics::FORMULAS['scanners'],'dot',['rejection'=>'Rejected']),$this->panel('scan-reasons','Why were scans rejected?','Legacy records keep their unknown classification; free-text messages are not parsed.',['label'=>'Reason code','rejected'=>'Rejected','sample'=>'Attempts'],$reasons,AnalyticsMetrics::FORMULAS['scanners'],'dot',['rejected'=>'Rejected'])]];
    }

    private function communications(): array
    {
        $until=date('Y-m-d',strtotime($this->filters['to'].' +1 day')).' 00:00:00';
        $rows=$this->rows('SELECT m.id,m.subject label,m.created_at,COUNT(r.id) sample,SUM(r.first_opened_at IS NOT NULL AND r.first_opened_at<?) opened,SUM(r.first_opened_at IS NULL AND r.read_at IS NOT NULL) legacy_read,AVG(CASE WHEN r.first_opened_at IS NOT NULL AND r.first_opened_at<? THEN TIMESTAMPDIFF(SECOND,m.created_at,r.first_opened_at)/60 END) delay_minutes FROM messages m LEFT JOIN message_recipients r ON r.message_id=m.id WHERE m.sender_id=? AND m.created_at>=? AND m.created_at<? GROUP BY m.id,m.subject,m.created_at ORDER BY m.created_at',[$until,$until,$this->viewer,$this->filters['from'].' 00:00:00',$until]);
        foreach($rows as &$r){$r['reach']=$r['created_at']>=$this->state['baseline_at']?self::rate($r['opened'],$r['sample']):null;$r['history']=$r['created_at']<$this->state['baseline_at']?'Legacy first-open history unknown':'Captured delivery period';$r['delay_minutes']=$r['created_at']>=$this->state['baseline_at'] && $r['delay_minutes']!==null?round((float)$r['delay_minutes'],1):null;$r['url']=url((Auth::usesAdminArea()?'/admin/':'/index/').'?page=messages&folder=sent&message_id='.$r['id']);}unset($r);
        $tracked=array_values(array_filter($rows,static fn($r)=>$r['history']==='Captured delivery period'));
        return ['cards'=>[['label'=>'Tracked message reach','value'=>self::rate(array_sum(array_column($tracked,'opened')),array_sum(array_column($tracked,'sample'))),'unit'=>'%','detail'=>'Your prospectively tracked sent messages only; legacy reach is unavailable']], 'insights'=>[], 'panels'=>[$this->panel('message-reach','Are your messages reaching their recipients?','Messages sent in the selected period, with tracked opens through its end. First-open tracking survives read/unread changes. Legacy messages show tracked opens only; their original first-open delays are unavailable. Opening does not confirm comprehension.',['label'=>'Subject','created_at'=>'Sent','reach'=>'Tracked opened %','opened'=>'Tracked opens','legacy_read'=>'Legacy read without first-open history','delay_minutes'=>'Mean first-open minutes','history'=>'Delivery history','sample'=>'Delivered copies'],$rows,AnalyticsMetrics::FORMULAS['communications'],'dot',['reach'=>'Tracked reach'])]];
    }

    private function quality(): array
    {
        $rows=[];$insights=[];
        if(isset(self::sections()['attendance'])){
            $dailyScope=$this->global('attendance')||(Auth::hasRole('Teacher')&&!Auth::hasRole('Student'));[$where,$params]=$this->attendanceWhere($dailyScope);$table=$dailyScope?'analytics_daily':'analytics_attendance';
            $select=$dailyScope?'SUM(f.expected_count) expected,SUM(f.recorded_expected) recorded,SUM(f.unknown_count) unknown_history,SUM(f.missing_count) missing,SUM(f.provisional_count) provisional,SUM(f.observation_count) sample':'SUM(f.expected=1) expected,SUM(f.expected=1 AND f.status IS NOT NULL) recorded,SUM(f.history_known=0) unknown_history,SUM(f.expected=1 AND f.status IS NULL) missing,SUM(f.finalized=0) provisional,COUNT(*) sample';
            $r=$this->rows('SELECT '.$select.' FROM '.$table.' f WHERE '.$where,$params)[0];
            $rows[]=['label'=>'Attendance eligibility coverage','coverage'=>self::rate($r['recorded'],$r['expected']),'missing'=>(int)$r['missing'],'legacy'=>(int)$r['unknown_history'],'sample'=>(int)$r['sample'],'note'=>$r['provisional'].' provisional observations. Legacy eligibility is unknown.','url'=>url((Auth::usesAdminArea()?'/admin/':'/index/').'?page=analytics&section=attendance')];
            if((int)$r['missing'])$insights[]=['severity'=>'warning','title'=>'Complete the attendance record first','detail'=>$r['missing'].' expected observations are unrecorded. Missing records do not count as absences.','url'=>$rows[0]['url']];
            $pendingSelect=$dailyScope?'SUM(f.provisional_count) sample,SUM(f.missing_count) missing':'COUNT(*) sample,SUM(f.status IS NULL) missing';
            $pendingWhere=$dailyScope?'f.provisional_count>0':'f.finalized=0 AND f.expected=1';
            $pending=$this->rows('SELECT f.class_offering_id,f.attendance_date,CONCAT(c.course_code," · ",s.name) label,'.$pendingSelect.' FROM '.$table.' f JOIN class_offerings o ON o.id=f.class_offering_id JOIN courses c ON c.id=o.course_id JOIN sections s ON s.id=o.section_id WHERE '.$where.' AND '.$pendingWhere.' AND f.attendance_date<CURDATE() GROUP BY f.class_offering_id,f.attendance_date,c.course_code,s.name ORDER BY f.attendance_date DESC',$params);foreach($pending as &$p)$p['url']=$this->classUrl('attendance',(int)$p['class_offering_id'],null,$p['attendance_date']);unset($p);
        }else$pending=[];
        if(isset(self::sections()['grades'])){[$where,$params]=$this->offeringWhere('grades');$missingUnits=$this->rows('SELECT o.id,CONCAT(c.course_code," · ",s.name) label,c.units,1 sample FROM class_offerings o JOIN courses c ON c.id=o.course_id JOIN sections s ON s.id=o.section_id WHERE '.$where.' AND (c.units IS NULL OR c.units<=0)',$params);foreach($missingUnits as &$r)$r['url']=$this->classUrl('grades',(int)$r['id']);unset($r);}else$missingUnits=[];
        $panels=[$this->panel('data-coverage','How much of this analysis is supported by complete records?','Historical eligibility begins at the recorded baseline. Coverage applies only to known expected observations.',['label'=>'Measure','coverage'=>'Recorded %','missing'=>'Missing','legacy'=>'Unknown history','note'=>'Context','sample'=>'Observations'],$rows,AnalyticsMetrics::FORMULAS['quality'],'dot',['coverage'=>'Recorded coverage']),$this->panel('pending-sessions','Which completed meeting dates still need finalization?','Past recorded or captured meeting dates without finalized attendance. Legacy schedule context may be unknown.',['label'=>'Subject · section','attendance_date'=>'Meeting date','missing'=>'Missing records','sample'=>'Expected observations'],$pending,'Known ended scheduled occurrences without finalized attendance.','table'),$this->panel('missing-units','Which subjects have no reliable credit weighting?','No default units are substituted; subject-level scores remain available.',['label'=>'Subject · section','units'=>'Recorded units','sample'=>'Offering'],$missingUnits,'Course units NULL or ≤ 0. Combined lecture/laboratory weighting also requires an explicit institutional rule.','table')];
        return ['cards'=>[],'panels'=>$panels,'insights'=>$insights];
    }

    public function records(string $section,int $page,int $limit,string $sort='date',string $direction='desc',string $panelKey=''): array
    {
        if(!isset(self::sections()[$section]))throw new DomainException('This section is outside your permitted scope.');
        $page=max(1,min(100000,$page));$limit=max(1,min(100,$limit));$offset=($page-1)*$limit;$direction=$direction==='asc'?'ASC':'DESC';
        if($section==='scanners'){
            $where='l.created_at>=? AND l.created_at<? AND l.outcome IN ("attendance_recorded","event_attendance_recorded","rejected")';$params=[$this->filters['from'].' 00:00:00',date('Y-m-d',strtotime($this->filters['to'].' +1 day')).' 00:00:00'];
            if(array_filter(array_intersect_key($this->filters,array_flip(['department_id','program_id','section_id','teacher_id','course_id','offering_id'])))){[$ow,$op]=$this->offeringWhere('resources','o');$where.=' AND EXISTS(SELECT 1 FROM class_offerings o WHERE o.id=l.class_offering_id AND '.$ow.')';$params=array_merge($params,$op);}
            if($this->filters['q']!==''){$where.=' AND CONCAT_WS(" ",d.device_code,d.name,l.reason_code,l.outcome) LIKE ?';$params[]='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$this->filters['q']).'%';}
            $from=' FROM device_scan_logs l JOIN devices d ON d.id=l.device_id';$total=(int)$this->rows('SELECT COUNT(*) total'.$from.' WHERE '.$where,$params)[0]['total'];$rows=$this->rows('SELECT d.id device_id,d.device_code scanner,l.created_at,l.outcome,COALESCE(l.reason_code,"legacy_unclassified") reason_code'.$from.' WHERE '.$where." ORDER BY l.created_at {$direction},l.id {$direction} LIMIT {$limit} OFFSET {$offset}",$params);foreach($rows as &$r)$r['url']=url('/admin/?page=rooms&manage=device&entity_id='.$r['device_id']);unset($r);return ['ok'=>true,'rows'=>$rows,'columns'=>['scanner'=>'Scanner','created_at'=>'Server time','outcome'=>'Outcome','reason_code'=>'Reason code'],'total'=>$total,'page'=>$page,'limit'=>$limit,'has_more'=>$offset+count($rows)<$total];
        }
        if(in_array($section,['attendance','compliance','quality'],true) && ($section!=='quality' || ($panelKey==='' || $panelKey==='data-coverage')) && ($section!=='quality' || isset(self::sections()['attendance'])) && !str_starts_with($panelKey,'disciplinary')){
            [$where,$params]=$this->attendanceWhere();$from=' FROM analytics_attendance f JOIN class_offerings o ON o.id=f.class_offering_id JOIN courses c ON c.id=o.course_id JOIN sections s ON s.id=o.section_id JOIN users u ON u.id=f.student_id';
            if($this->filters['q']!==''){$where.=' AND CONCAT_WS(" ",u.first_name,u.middle_name,u.last_name,u.school_id,c.course_code,c.title,s.name) LIKE ?';$params[]='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$this->filters['q']).'%';}
            $columns=['date'=>'f.attendance_date','name'=>'u.last_name','subject'=>'c.course_code','status'=>'f.status'];$order=$columns[$sort]??$columns['date'];$total=(int)$this->rows('SELECT COUNT(*) total'.$from.' WHERE '.$where,$params)[0]['total'];
            $rows=$this->rows('SELECT f.class_offering_id,f.student_id,f.attendance_date,CONCAT_WS(" ",u.first_name,u.middle_name,u.last_name) student,u.school_id,c.course_code,COALESCE(f.status,"Unrecorded") status,f.finalized,f.history_known,f.late_minutes'.$from.' WHERE '.$where." ORDER BY {$order} {$direction},f.id {$direction} LIMIT {$limit} OFFSET {$offset}",$params);
            foreach($rows as &$r){$r['url']=$this->classUrl('attendance',(int)$r['class_offering_id'],(int)$r['student_id'],$r['attendance_date']);$r['finalized']=$r['finalized']?'Finalized':'Provisional';$r['history_known']=$r['history_known']?'Captured':'Unknown';}unset($r);
            return ['ok'=>true,'rows'=>$rows,'columns'=>['student'=>'Student','school_id'=>'School ID','course_code'=>'Subject','attendance_date'=>'Date','status'=>'Status','finalized'=>'Session','history_known'=>'History','late_minutes'=>'Minutes late'],'total'=>$total,'page'=>$page,'limit'=>$limit,'has_more'=>$offset+count($rows)<$total];
        }
        if($section==='grades'){
            [$where,$params]=$this->offeringWhere('grades');if(!$this->global('grades'))$where.=' AND '.$this->gradeRelationship('o.id','g.student_id');$where.=' AND COALESCE(g.finalized_at,g.updated_at)>=? AND COALESCE(g.finalized_at,g.updated_at)<?';$params[]=$this->filters['from'].' 00:00:00';$params[]=date('Y-m-d',strtotime($this->filters['to'].' +1 day')).' 00:00:00';
            $from=' FROM '.SharedGradeService::grades().' g JOIN '.SharedGradeService::subjects().' o ON o.id=g.class_offering_id JOIN courses c ON c.id=o.course_id JOIN users u ON u.id=g.student_id';
            if($this->filters['q']!==''){$where.=' AND CONCAT_WS(" ",u.first_name,u.middle_name,u.last_name,u.school_id,c.course_code) LIKE ?';$params[]='%'.str_replace(['\\','%','_'],['\\\\','\\%','\\_'],$this->filters['q']).'%';}
            $total=(int)$this->rows('SELECT COUNT(*) total'.$from.' WHERE '.$where,$params)[0]['total'];$rows=$this->rows('SELECT g.class_offering_id,g.source_offering_id,g.student_id,CONCAT_WS(" ",u.first_name,u.middle_name,u.last_name) student,u.school_id,c.course_code,g.prelim,g.midterm,g.final endterms,g.overall,g.status,g.finalized_at'.$from.' WHERE '.$where." ORDER BY g.finalized_at {$direction},g.id {$direction} LIMIT {$limit} OFFSET {$offset}",$params);foreach($rows as &$r){$r['url']=$this->classUrl('grades',(int)$r['class_offering_id'],(int)$r['student_id']);$snapshot=$r['status']==='finalized' && $r['finalized_at']?$this->history->finalizedGrade((int)$r['source_offering_id'],(int)$r['student_id'],$r['finalized_at']):null;$policy=$snapshot['_policy']??null;if($policy)foreach(['prelim'=>'prelim','midterm'=>'midterm','final'=>'endterms'] as $term=>$field)if(!$policy['has_'.$term])$r[$field]=null;}unset($r);
            return ['ok'=>true,'rows'=>$rows,'columns'=>['student'=>'Student','school_id'=>'School ID','course_code'=>'Subject','prelim'=>'Prelims','midterm'=>'Midterms','endterms'=>'Endterms','overall'=>'Overall','status'=>'Status','finalized_at'=>'Finalized'],'total'=>$total,'page'=>$page,'limit'=>$limit,'has_more'=>$offset+count($rows)<$total];
        }
        $data=$this->response($section);$selected=array_values(array_filter($data['panels'],static fn($p)=>$p['key']===$panelKey));$panel=$selected[0]??($data['panels'][0]??['rows'=>[],'columns'=>[]]);$rows=$panel['rows'];if($this->filters['q']!=='')$rows=array_values(array_filter($rows,fn($r)=>str_contains(mb_strtolower(json_encode($r)),mb_strtolower($this->filters['q']))));return ['ok'=>true,'rows'=>array_slice($rows,$offset,$limit),'columns'=>$panel['columns'],'total'=>count($rows),'page'=>$page,'limit'=>$limit,'has_more'=>$offset+$limit<count($rows)]+array_intersect_key($panel,array_flip(['title','description','formula','coverage']));
    }
}
