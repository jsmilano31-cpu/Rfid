<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use DomainException;
use PDO;

final class ScheduleService
{
    public static function forViewer(array $viewer): array
    {
        [$where,$params]=self::scope((int)$viewer['id'],$viewer['role_names']??[$viewer['role_name']]);
        return $where ? self::rows(Database::connection(),$where,$params) : [];
    }

    public static function canViewProfile(): bool
    {
        return Auth::can('manage_users') && Auth::canAny(['view_schedule','manage_academic_structure']);
    }

    public static function profile(int $targetId,array $roles,$selected=null): array
    {
        if(!self::canViewProfile())throw new DomainException('You do not have permission to view this profile schedule.');
        [$scope,$params]=self::scope($targetId,$roles);
        if(!$scope)return ['rows'=>[],'terms'=>[],'semester_id'=>null];
        $pdo=Database::connection();
        $q=$pdo->prepare('SELECT DISTINCT semesters.id,semesters.name,semesters.starts_on,semesters.ends_on,school_years.name school_year_name FROM class_offerings JOIN semesters ON semesters.id=class_offerings.semester_id JOIN school_years ON school_years.id=semesters.school_year_id WHERE ('.$scope.') AND class_offerings.status<>"cancelled" ORDER BY semesters.starts_on DESC,semesters.id DESC');
        $q->execute($params);$terms=$q->fetchAll(PDO::FETCH_ASSOC);
        $semester=null;$today=date('Y-m-d');
        if($selected!==null){
            if(!is_scalar($selected) || !ctype_digit((string)$selected) || (int)$selected<1)return ['rows'=>[],'terms'=>$terms,'semester_id'=>null,'error'=>'Select a valid academic term to view this schedule.'];
            foreach($terms as $term)if((int)$term['id']===(int)$selected)$semester=(int)$term['id'];
            if($semester===null)return ['rows'=>[],'terms'=>$terms,'semester_id'=>null,'error'=>'That academic term is not available for this profile. Select one of their assigned terms.'];
        }else{
            foreach($terms as $term)if($term['starts_on']<=$today && $term['ends_on']>=$today){$semester=(int)$term['id'];break;}
            if($semester===null)foreach($terms as $term)if($term['starts_on']<=$today){$semester=(int)$term['id'];break;}
            if($semester===null && $terms)$semester=(int)$terms[array_key_last($terms)]['id'];
        }
        $rows=$semester!==null?self::rows($pdo,'('.$scope.') AND class_offerings.status<>"cancelled" AND class_offerings.semester_id=:semester_id',$params+['semester_id'=>$semester]):[];
        return ['rows'=>$rows,'terms'=>$terms,'semester_id'=>$semester];
    }

    private static function scope(int $id,array $roles): array
    {
        $scopes=[];$params=[];
        if(in_array('Student',$roles,true)){
            $scopes[]='EXISTS(SELECT 1 FROM class_enrollments WHERE class_enrollments.class_offering_id=class_offerings.id AND class_enrollments.student_id=:student_id AND class_enrollments.status="enrolled")';
            $params['student_id']=$id;
        }
        if(in_array('Teacher',$roles,true)){
            $scopes[]='EXISTS(SELECT 1 FROM teacher_assignments WHERE teacher_assignments.class_offering_id=class_offerings.id AND teacher_assignments.teacher_id=:teacher_id)';
            $params['teacher_id']=$id;
        }
        return [implode(' OR ',$scopes),$params];
    }

    private static function rows(PDO $pdo,string $where,array $params): array
    {
        $q=$pdo->prepare('SELECT DISTINCT class_schedules.*, courses.id course_id, courses.course_code, courses.title,
                class_offerings.semester_id, departments.id department_id,
                sections.id section_id, sections.name section_name, sections.year_level, sections.section_code,
                programs.id program_id, programs.code program_code, programs.name program_name,
                departments.code department_code, departments.name department_name,
                COALESCE(class_schedules.room_id, class_offerings.room_id) resolved_room_id,
                rooms.room_code, rooms.room_name, rooms.building room_building, rooms.floor room_floor,
                (SELECT MIN(schedule_teachers.id) FROM teacher_assignments schedule_assignments JOIN users schedule_teachers ON schedule_teachers.id = schedule_assignments.teacher_id WHERE schedule_assignments.class_offering_id = class_offerings.id) teacher_id,
                (SELECT GROUP_CONCAT(CONCAT_WS(" ", schedule_teachers.first_name, schedule_teachers.middle_name, schedule_teachers.last_name) ORDER BY schedule_teachers.last_name SEPARATOR ", ") FROM teacher_assignments schedule_assignments JOIN users schedule_teachers ON schedule_teachers.id = schedule_assignments.teacher_id WHERE schedule_assignments.class_offering_id = class_offerings.id) teacher_names,
                (SELECT CONCAT_WS("|~|", time_off.starts_on, time_off.ends_on,
                            CONCAT_WS(" ", time_off_teacher.first_name, time_off_teacher.middle_name, time_off_teacher.last_name),
                            COALESCE(time_off.reason, ""))
                  FROM teacher_assignments time_off_assignments
                  JOIN teacher_time_off time_off ON time_off.teacher_id = time_off_assignments.teacher_id AND time_off.status = "active"
                  JOIN users time_off_teacher ON time_off_teacher.id = time_off.teacher_id
                  WHERE time_off_assignments.class_offering_id = class_offerings.id AND time_off.ends_on >= CURRENT_DATE
                    AND MOD(FIELD(class_schedules.day_of_week,"Sunday","Monday","Tuesday","Wednesday","Thursday","Friday","Saturday")
                            - DAYOFWEEK(time_off.starts_on) + 7, 7) <= DATEDIFF(time_off.ends_on, time_off.starts_on)
                  ORDER BY time_off.starts_on LIMIT 1) time_off_summary
            FROM class_schedules JOIN class_offerings ON class_offerings.id = class_schedules.class_offering_id
            JOIN courses ON courses.id = class_offerings.course_id JOIN sections ON sections.id = class_offerings.section_id
            JOIN programs ON programs.id = sections.program_id
            LEFT JOIN departments ON departments.id = programs.department_id
            LEFT JOIN rooms ON rooms.id = COALESCE(class_schedules.room_id, class_offerings.room_id)
            WHERE (' . $where . ')
            ORDER BY FIELD(day_of_week,"Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"), start_time');
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }
}
