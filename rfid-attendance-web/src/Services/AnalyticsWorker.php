<?php
declare(strict_types=1);
namespace App\Services;
use PDO;
use Throwable;

/** Rebuildable facts. History remains authoritative and is never rewritten here. */
final class AnalyticsWorker
{
    public static function run(PDO $pdo,bool $force=false): array
    {
        if(!$pdo->query("SELECT GET_LOCK('campustrack_analytics',0)")->fetchColumn())return ['busy'=>true];
        try {
            $pdo->beginTransaction();
            $state=$pdo->query('SELECT * FROM analytics_state WHERE id=1')->fetch();
            if(!$state)throw new \RuntimeException('Install Analytics first.');
            $cursor=(int)$pdo->query('SELECT COALESCE(MAX(id),0) FROM analytics_changes')->fetchColumn();
            $today=date('Y-m-d');$yesterday=date('Y-m-d',strtotime('-1 day'));
            if(!$force && $cursor===(int)$state['source_cursor'] && substr((string)$state['built_at'],0,10)===$today){$pdo->exec('UPDATE analytics_state SET built_at=NOW(),worker_error=NULL WHERE id=1');$pdo->commit();return ['unchanged'=>true];}
            $dates=[];
            if($force || !$state['built_at'])foreach($pdo->query('SELECT DISTINCT attendance_date FROM attendance_records')->fetchAll(PDO::FETCH_COLUMN) as $d)$dates[$d]=true;
            $q=$pdo->prepare('SELECT DISTINCT occurs_on FROM analytics_changes WHERE id>? AND id<=? AND occurs_on IS NOT NULL');$q->execute([$state['source_cursor'],$cursor]);foreach($q->fetchAll(PDO::FETCH_COLUMN) as $d)$dates[$d]=true;
            // Catch cascaded deletions too: a missing source row cannot survive as a fact.
            $pdo->exec('DELETE f FROM analytics_attendance f LEFT JOIN attendance_records r ON r.id=f.record_id WHERE f.record_id IS NOT NULL AND r.id IS NULL AND f.expected IS NULL');
            $start=substr($state['baseline_at'],0,10);
            if(!$force && $state['built_at'])$start=max($start,date('Y-m-d',strtotime(substr($state['built_at'],0,10).' -1 day')));
            for($d=$start;$d<=$yesterday;$d=date('Y-m-d',strtotime($d.' +1 day')))$dates[$d]=true;
            $history=new AnalyticsHistory($pdo);$count=0;
            foreach(array_keys($dates) as $date){self::buildDate($pdo,$history,$state['baseline_at'],$date);$count++;}
            $q=$pdo->prepare('UPDATE analytics_state SET built_at=NOW(),source_cursor=?,revision=revision+1,worker_error=NULL WHERE id=1');$q->execute([$cursor]);
            $pdo->exec('DELETE FROM analytics_cache WHERE expires_at<NOW()');$pdo->commit();
            return ['dates'=>$count,'cursor'=>$cursor,'built_at'=>date(DATE_ATOM)];
        } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$pdo->prepare('UPDATE analytics_state SET worker_error=? WHERE id=1')->execute(['Refresh failed; showing the last complete calculation.']);throw $e;}
        finally{$pdo->query("SELECT RELEASE_LOCK('campustrack_analytics')");}
    }

    private static function buildDate(PDO $pdo,AnalyticsHistory $h,string $baseline,string $date): void
    {
        $contexts=[];$expectations=[];$cancelledOfferings=[];$now=date('Y-m-d H:i:s');$day=date('l',strtotime($date));
        // Future occurrences are never inferred; an occurrence must have ended.
        if($date.' 23:59:59'>=$baseline)foreach($h->during('class_schedules',$date.' 00:00:00',$date.' 23:59:59.999999') as $s){
            if($s['day_of_week']!==$day)continue;$instant=$date.' '.$s['start_time'];$oid=(int)$s['class_offering_id'];
            if($instant<$baseline || $date.' '.$s['end_time']>$now)continue;
            $candidate=$s;$s=$h->at('class_schedules',$s['id'],$instant);if(!$s || $s['day_of_week']!==$day || $s['start_time']!==$candidate['start_time'])continue;
            // The recording model has one observation per offering/date and uses
            // the earliest meeting, just like AcademicCalendarService.
            if(isset($contexts[$oid]) && $contexts[$oid]['start_time']<=$s['start_time'])continue;
            $o=$h->at('class_offerings',$oid,$instant);if(!$o || $o['status']!=='active')continue;
            $term=$h->at('semesters',$o['semester_id'],$instant);if(!$term || $date<$term['starts_on'] || $date>$term['ends_on'])continue;
            $section=$h->at('sections',$o['section_id'],$instant);$program=$section?$h->at('programs',$section['program_id'],$instant):null;
            $course=$h->at('courses',$o['course_id'],$instant);$cancelled=false;
            foreach($h->all('campus_holidays',$instant) as $holiday)if($holiday['holiday_date']===$date && $holiday['no_classes'])$cancelled=true;
            foreach($h->all('department_days_off',$instant) as $off)if((int)$off['department_id']===(int)($program['department_id']??0) && $off['day_of_week']===$day)$cancelled=true;
            $teachers=[];foreach($h->all('teacher_assignments',$instant) as $a)if((int)$a['class_offering_id']===$oid)$teachers[]=(int)$a['teacher_id'];
            foreach($h->all('teacher_time_off',$instant) as $off)if(in_array((int)$off['teacher_id'],$teachers,true) && $off['status']==='active' && $date>=$off['starts_on'] && $date<=$off['ends_on'])$cancelled=true;
            if($cancelled){$cancelledOfferings[$oid]=true;continue;}
            $policy=$h->at('class_attendance_policies',$oid,$instant)??$h->at('attendance_settings',1,$instant);
            $context=['semester_id'=>(int)$o['semester_id'],'department_id'=>$program['department_id']??null,'program_id'=>$program['id']??null,'section_id'=>$o['section_id'],'course_id'=>$o['course_id'],'room_id'=>$s['room_id']??$o['room_id'],'teacher_ids'=>$teachers,'start_time'=>$s['start_time'],'end_time'=>$s['end_time'],'course_title'=>$course['title']??null,'course_code'=>$course['course_code']??null,'section_name'=>$section['name']??null,'policy'=>$policy];
            $contexts[$oid]=$context;$eligible=[];
            foreach($h->all('class_enrollments',$instant) as $e){
                if((int)$e['class_offering_id']!==$oid || $e['status']!=='enrolled' || ($e['enrolled_at']??$instant)>$instant)continue;
                $student=(int)$e['student_id'];$suspended=false;
                foreach($h->all('events',$instant) as $event){
                    if(!$event['suspends_classes'] || in_array($event['status'],['draft','cancelled'],true) || $date<$event['event_date'] || $date>($event['end_date']??$event['event_date']) || $event['start_time']>=$s['end_time'] || $event['end_time']<=$s['start_time'])continue;
                    foreach($h->all('event_participants',$instant) as $participant)if((int)$participant['event_id']===(int)$event['id'] && (int)$participant['user_id']===$student)$suspended=true;
                }
                if(!$suspended)$eligible[$student]=true;
            }
            $expectations[$oid]=$eligible;
        }
        try {
            $q=$pdo->prepare('DELETE FROM analytics_attendance WHERE attendance_date=?');$q->execute([$date]);
            $q=$pdo->prepare('DELETE FROM analytics_occurrences WHERE attendance_date=?');$q->execute([$date]);
            $q=$pdo->prepare('INSERT INTO analytics_occurrences(class_offering_id,attendance_date,context_json,eligible_count) VALUES(?,?,?,?)');
            foreach($contexts as $oid=>$context)$q->execute([$oid,$date,json_encode($context,JSON_THROW_ON_ERROR),count($expectations[$oid])]);
            $q=$pdo->prepare('SELECT r.*,o.semester_id,o.course_id,o.section_id,o.room_id,p.id program_id,p.department_id,s.finalized_at FROM attendance_records r JOIN class_offerings o ON o.id=r.class_offering_id JOIN sections sec ON sec.id=o.section_id JOIN programs p ON p.id=sec.program_id LEFT JOIN attendance_sessions s ON s.class_offering_id=r.class_offering_id AND s.attendance_date=r.attendance_date WHERE r.attendance_date=?');$q->execute([$date]);
            $insert=$pdo->prepare('INSERT INTO analytics_attendance(class_offering_id,student_id,attendance_date,record_id,semester_id,department_id,program_id,section_id,course_id,room_id,status,finalized,expected,history_known,late_minutes,tardies_per_absence) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $seen=[];$final=[];
            foreach($q as $r){
                $oid=(int)$r['class_offering_id'];$sid=(int)$r['student_id'];$context=$contexts[$oid]??null;$source=$context??$r;$known=$context!==null;$minutes=null;
                if($known && $r['status']==='Late' && $r['time_in'])$minutes=max(0,(strtotime($date.' '.$r['time_in'])-strtotime($date.' '.$context['start_time']))/60);
                $cancelled=isset($cancelledOfferings[$oid]);
                $insert->execute([$oid,$sid,$date,$r['id'],$source['semester_id'],$source['department_id'],$source['program_id'],$source['section_id'],$source['course_id'],$source['room_id'],$r['status'],$r['finalized_at']?1:0,$cancelled?0:($known?(isset($expectations[$oid][$sid])?1:0):null),$known||$cancelled?1:0,$minutes,$context['policy']['tardies_per_absence']??null]);$seen[$oid][$sid]=true;
            }
            $q=$pdo->prepare('SELECT class_offering_id,finalized_at FROM attendance_sessions WHERE attendance_date=?');$q->execute([$date]);foreach($q as $row)$final[$row['class_offering_id']]=$row['finalized_at']?1:0;
            foreach($expectations as $oid=>$students){$c=$contexts[$oid];foreach($students as $sid=>$yes){if(isset($seen[$oid][$sid]))continue;$insert->execute([$oid,$sid,$date,null,$c['semester_id'],$c['department_id'],$c['program_id'],$c['section_id'],$c['course_id'],$c['room_id'],null,$final[$oid]??0,1,1,null,$c['policy']['tardies_per_absence']??null]);}}
            self::summarize($pdo,$date);
        }catch(Throwable $e){throw $e;}
    }
    public static function summarize(PDO $pdo,string $date): void
    {
        $pdo->prepare('DELETE FROM analytics_daily WHERE attendance_date=?')->execute([$date]);
        $pdo->prepare('INSERT INTO analytics_daily(class_offering_id,attendance_date,semester_id,department_id,program_id,section_id,course_id,room_id,present_count,late_count,absent_count,excused_count,missing_count,provisional_count,unknown_count,expected_count,recorded_expected,observation_count,late_minutes_sum,late_minutes_n)
            SELECT class_offering_id,attendance_date,MAX(semester_id),MAX(department_id),MAX(program_id),MAX(section_id),MAX(course_id),MAX(room_id),
            COALESCE(SUM(finalized=1 AND status="Present"),0),COALESCE(SUM(finalized=1 AND status="Late"),0),COALESCE(SUM(finalized=1 AND status="Absent"),0),COALESCE(SUM(finalized=1 AND status="Excused"),0),
            SUM(status IS NULL),SUM(finalized=0),SUM(history_known=0),COALESCE(SUM(expected=1),0),COALESCE(SUM(expected=1 AND status IS NOT NULL),0),COUNT(*),COALESCE(SUM(CASE WHEN finalized=1 THEN late_minutes END),0),COUNT(CASE WHEN finalized=1 THEN late_minutes END)
            FROM analytics_attendance WHERE attendance_date=? AND (expected IS NULL OR expected=1)
            GROUP BY class_offering_id,attendance_date')->execute([$date]);
    }
}
