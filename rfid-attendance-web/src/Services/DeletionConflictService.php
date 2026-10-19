<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Auth;
use App\Exceptions\DeletionConflictException;
use DomainException;
use PDO;

final class DeletionConflictService
{
    public const PER_PAGE = 20;

    /** Rule-only restrictions contain explanations, never invented dependencies. */
    public static function restriction(string $type, int $id, int $actorId): ?string
    {
        $pdo = Database::connection();
        if ($type === 'event') EventAccessService::requireManage($id, $actorId);
        if ($type === 'role' && $id <= 5) return 'Built-in system roles cannot be deleted. You can change permissions for non-administrator roles instead.';
        if ($type === 'user') {
            if ($id === $actorId) return 'You cannot delete your own signed-in account.';
            $statement = $pdo->prepare('SELECT 1 FROM users WHERE id=:id AND (role_id=1 OR EXISTS(SELECT 1 FROM user_roles WHERE user_id=users.id AND role_id=1))');
            $statement->execute(['id'=>$id]);
            if ($statement->fetchColumn()) return 'Administrator accounts cannot be permanently deleted. Deactivate the account if it should no longer be used.';
        }
        if (in_array($type, ['event', 'recollection'], true)) {
            $table = $type === 'event' ? 'events' : 'recollections';
            $statement = $pdo->prepare('SELECT status FROM '.$table.' WHERE id=:id'); $statement->execute(['id'=>$id]);
            $status = $statement->fetchColumn();
            if ($status !== false && !in_array($status, ['draft','scheduled','cancelled'], true)) return 'Open or finalized attendance is protected. Cancel this activity to preserve its history.';
        }
        if ($type === 'preset') {
            $statement = $pdo->prepare('SELECT 1 FROM seat_plan_presets WHERE id=:id AND (created_by=:actor OR (visibility="campus" AND :manager=1))');
            $statement->execute(['id'=>$id,'actor'=>$actorId,'manager'=>Auth::can('manage_rooms') ? 1 : 0]);
            if (!$statement->fetchColumn()) throw new DomainException('This preset is unavailable or belongs to another user.');
        }
        return null;
    }

    public static function assertClear(string $type, int $id): void
    {
        $conflict = self::inspect($type, $id);
        if ($conflict['total'] > 0) throw new DeletionConflictException($conflict);
    }

    public static function inspect(string $type, int $id, ?string $requestedGroup = null, int $page = 1): array
    {
        if ($id < 1) throw new DomainException('Select a valid record.');
        if ($type === 'event' && \App\Core\Auth::user()) EventAccessService::requireManage($id, (int)\App\Core\Auth::user()['id']);
        $definitions = self::definitions($type, $id);
        if ($requestedGroup !== null && !array_key_exists($requestedGroup, $definitions)) {
            throw new DomainException('That linked-record group is unavailable.');
        }
        $target = self::target($type, $id);
        if (!$target) throw new DomainException('The selected record could not be found.');
        $page = max(1, $page);
        $groups = [];
        $total = 0;
        foreach ($definitions as $key => $definition) {
            if ($requestedGroup !== null && $requestedGroup !== $key) continue;
            $count = self::count($definition['sql'], $definition['params']);
            if ($count < 1) continue;
            $total += $count;
            $pages = max(1, (int) ceil($count / self::PER_PAGE));
            $currentPage = min($page, $pages);
            $rows = self::rows($definition['sql'], $definition['params'], $currentPage);
            $groups[] = [
                'key' => $key,
                'label' => $definition['label'],
                'resolution' => in_array($key, ['activity','event_activity','attendance_sessions','attendance_records','event_attendance','activity_attendance','grades','attendance','messages','rfid_history','violations','time_off'], true)
                    ? 'These historical records are protected. Keep the selected record and use its deactivate, complete, or cancel action where available.'
                    : 'Open these records to reassign their link or remove them if their own deletion rules allow it.',
                'total' => $count,
                'page' => $currentPage,
                'pages' => $pages,
                'records' => array_map($definition['map'], $rows),
            ];
        }
        return [
            'message' => self::message($type, $total),
            'target' => ['type' => $type, 'id' => $id, 'label' => $target],
            'total' => $total,
            'groups' => $groups,
            'refresh_url' => url('/admin/deletion-conflicts.php?' . http_build_query(['type' => $type, 'id' => $id])),
        ];
    }

    public static function permissionFor(string $type): string
    {
        return match ($type) {
            'department', 'program', 'course', 'section', 'offering' => 'manage_academic_structure',
            'room', 'device' => 'manage_rooms',
            'user' => 'manage_users',
            'role' => 'manage_roles',
            'event' => 'manage_events',
            'recollection' => 'manage_recollections',
            'preset' => 'manage_seat_plans',
            default => throw new DomainException('That deletion-conflict type is unavailable.'),
        };
    }

    private static function definitions(string $type, int $id): array
    {
        $guard = static function(string $permission, array $record): array {
            if (Auth::can($permission)) return $record;
            return [
                'id' => (int) $record['id'],
                'title' => 'Restricted linked record',
                'subtitle' => 'You do not have permission to view this record.',
                'url' => null,
            ];
        };
        $academic = static fn(string $entity, array $row): array => [
            'id' => (int) $row['id'], 'title' => $row['title'], 'subtitle' => $row['subtitle'] ?? '',
            'url' => url('/admin/?page=academics&manage=' . $entity . '&entity_id=' . $row['id']),
        ];
        $user = static fn(array $row): array => $guard('manage_users',['id'=>(int)$row['id'],'title'=>$row['title'],'subtitle'=>$row['subtitle'] ?? '','url'=>url('/admin/?page=users&manage_user=' . $row['id'])]);
        $offering = static fn(array $row): array => $guard('manage_academic_structure',['id'=>(int)$row['id'],'title'=>$row['title'],'subtitle'=>$row['subtitle'] ?? '','url'=>url('/admin/?page=academics&manage=offering&entity_id=' . $row['id'])]);
        $history = static function(string $page,array $row): array {
            $permission=match($row['record_type']??''){'message'=>'send_messages','rfid_history'=>'view_audit_logs','preset'=>'manage_seat_plans','room_plan'=>'manage_rooms','violation'=>'view_compliance','teacher_time_off'=>'manage_teacher_time_off',default=>match($page){'attendance'=>'manage_attendance','events'=>'manage_events','recollections'=>'manage_recollections','grades'=>'manage_grades','messages'=>'send_messages','rooms'=>'manage_rooms','compliance'=>'view_compliance','reports'=>'view_audit_logs',default=>null}};
            $allowed=$permission===null||Auth::can($permission)||(($row['record_type']??'')==='violation'&&Auth::can('manage_compliance'));
            return ['id'=>(int)$row['id'],'title'=>$allowed?$row['title']:'Restricted linked record','subtitle'=>$allowed?($row['subtitle']??''):'You do not have permission to view this record.','url'=>$allowed?url('/admin/deletion-record.php?'.http_build_query(['type'=>$row['record_type']??'','id'=>$row['id']])):null];
        };
        $spec = static function(string $label,string $sql,callable $map) use($id): array {
            $params=[];$index=0;
            $sql=preg_replace_callback('/:id\b/',static function() use(&$params,&$index,$id):string{$key='dependency_id_'.$index++;$params[$key]=$id;return ':'.$key;},$sql);
            return ['label'=>$label,'sql'=>$sql,'params'=>$params,'map'=>$map];
        };

        return match ($type) {
            'department' => [
                'programs'=>$spec('Programs','SELECT id, CONCAT(code," — ",name) title, "Academic program" subtitle FROM programs WHERE department_id=:id',fn($r)=>$academic('program',$r)),
                'courses'=>$spec('Courses','SELECT id, CONCAT(course_code," — ",title) title, "Course catalog" subtitle FROM courses WHERE department_id=:id',fn($r)=>$academic('course',$r)),
                'users'=>$spec('Users','SELECT id, CONCAT(first_name," ",last_name) title, CONCAT(school_id," · ",email) subtitle FROM users WHERE department_id=:id',$user),
            ],
            'program' => [
                'sections'=>$spec('Sections','SELECT id, name title, CONCAT("Year ",year_level," · Section ",section_code) subtitle FROM sections WHERE program_id=:id',fn($r)=>$academic('section',$r)),
                'users'=>$spec('Users','SELECT id, CONCAT(first_name," ",last_name) title, CONCAT(school_id," · ",email) subtitle FROM users WHERE program_id=:id',$user),
            ],
            'course' => ['offerings'=>$spec('Class offerings',self::offeringSql('class_offerings.course_id=:id'),$offering)],
            'section' => [
                'users'=>$spec('Users','SELECT id, CONCAT(first_name," ",last_name) title, CONCAT(school_id," · ",email) subtitle FROM users WHERE section_id=:id',$user),
                'offerings'=>$spec('Class offerings',self::offeringSql('class_offerings.section_id=:id'),$offering),
                'activities'=>$spec('Recollections and retreats','SELECT id,title,CONCAT(type," · ",date) subtitle FROM recollections WHERE section_id=:id',fn($r)=>$guard('manage_recollections',['id'=>(int)$r['id'],'title'=>$r['title'],'subtitle'=>$r['subtitle'],'url'=>url('/admin/?page=recollections&manage_recollection='.$r['id'])])),
            ],
            'offering' => [
                'attendance_sessions'=>$spec('Attendance sessions','SELECT id, CONCAT("Attendance · ",attendance_date) title, IF(finalized_at IS NULL,"Open session","Finalized session") subtitle, attendance_date FROM attendance_sessions WHERE class_offering_id=:id',fn($r)=>$guard('manage_attendance',['id'=>(int)$r['id'],'title'=>$r['title'],'subtitle'=>$r['subtitle'],'url'=>url('/admin/?page=attendance&offering_id='.$id.'&date='.$r['attendance_date'])])),
                'attendance_records'=>$spec('Attendance records','SELECT attendance_records.id, CONCAT(users.first_name," ",users.last_name) title, CONCAT(users.school_id," · ",attendance_records.attendance_date," · ",attendance_records.status) subtitle, attendance_records.attendance_date,attendance_records.student_id FROM attendance_records JOIN users ON users.id=attendance_records.student_id WHERE attendance_records.class_offering_id=:id',fn($r)=>$guard('manage_attendance',['id'=>(int)$r['id'],'title'=>$r['title'],'subtitle'=>$r['subtitle'],'url'=>url('/admin/?page=attendance&offering_id='.$id.'&date='.$r['attendance_date'].'&student_id='.$r['student_id'])])),
                'grades'=>$spec('Grade records','SELECT grades.id, CONCAT(users.first_name," ",users.last_name) title, CONCAT(users.school_id," · Grade record") subtitle, users.id student_id FROM grades JOIN users ON users.id=grades.student_id WHERE grades.class_offering_id=:id',fn($r)=>$guard('manage_grades',['id'=>(int)$r['id'],'title'=>$r['title'],'subtitle'=>$r['subtitle'],'url'=>url('/admin/?page=grades&offering_id='.$id.'&student_id='.$r['student_id'])])),
            ],
            'room' => [
                'scanners'=>$spec('RFID scanners','SELECT id,device_code title,CONCAT(name," · ",status) subtitle FROM devices WHERE room_id=:id',fn($r)=>['id'=>(int)$r['id'],'title'=>$r['title'],'subtitle'=>$r['subtitle'],'url'=>url('/admin/?page=rooms&manage=device&entity_id='.$r['id'])]),
                'offerings'=>$spec('Class offerings',self::offeringSql('class_offerings.room_id=:id'),$offering),
                'schedules'=>$spec('Class schedules','SELECT class_schedules.id, CONCAT(courses.course_code," · ",sections.name) title, CONCAT(class_schedules.day_of_week," · ",TIME_FORMAT(class_schedules.start_time,"%h:%i %p"),"–",TIME_FORMAT(class_schedules.end_time,"%h:%i %p")) subtitle,class_schedules.class_offering_id offering_id FROM class_schedules JOIN class_offerings ON class_offerings.id=class_schedules.class_offering_id JOIN courses ON courses.id=class_offerings.course_id JOIN sections ON sections.id=class_offerings.section_id WHERE class_schedules.room_id=:id',fn($r)=>$guard('manage_academic_structure',['id'=>(int)$r['id'],'title'=>$r['title'],'subtitle'=>$r['subtitle'],'url'=>url('/admin/?page=academics&manage=offering&entity_id='.$r['offering_id'].'&focus_schedule='.$r['id'])])),
            ],
            'user' => self::userDefinitions($id,$spec,$offering,$history,$guard),
            'role' => ['users'=>$spec('Assigned users','SELECT DISTINCT users.id,CONCAT(users.first_name," ",users.last_name) title,CONCAT(users.school_id," · ",users.email) subtitle FROM users LEFT JOIN user_roles ON user_roles.user_id=users.id WHERE users.role_id=:id OR user_roles.role_id=:id',$user)],
            'event' => ['activity'=>$spec('Event activity history','SELECT id,action title,occurred_at subtitle FROM event_activity_logs WHERE event_id=:id',fn($r)=>['id'=>(int)$r['id'],'title'=>$r['title'],'subtitle'=>$r['subtitle'],'url'=>EventAccessService::viewUrl($id)]),'attendance'=>$spec('Event attendance','SELECT event_attendance.id,CONCAT(users.first_name," ",users.last_name) title,CONCAT(users.school_id," · ",event_attendance.status) subtitle FROM event_attendance JOIN users ON users.id=event_attendance.user_id WHERE event_attendance.event_id=:id',fn($r)=>['id'=>(int)$r['id'],'title'=>$r['title'],'subtitle'=>$r['subtitle'],'url'=>url('/admin/?page=events&manage_event='.$id)])],
            'recollection' => ['attendance'=>$spec('Activity attendance','SELECT recollection_attendance.id,CONCAT(users.first_name," ",users.last_name) title,CONCAT(users.school_id," · ",recollection_attendance.status) subtitle FROM recollection_attendance JOIN users ON users.id=recollection_attendance.user_id WHERE recollection_attendance.recollection_id=:id',fn($r)=>['id'=>(int)$r['id'],'title'=>$r['title'],'subtitle'=>$r['subtitle'],'url'=>url('/admin/?page=recollections&manage_recollection='.$id)])],
            'device', 'preset' => [],
            default => throw new DomainException('That deletion-conflict type is unavailable.'),
        };
    }

    private static function userDefinitions(int $id, callable $spec, callable $offering, callable $history, callable $guard): array
    {
        $defs=[];
        $defs['enrollments']=$spec('Class enrollments',self::offeringSql('class_enrollments.student_id=:id','JOIN class_enrollments ON class_enrollments.class_offering_id=class_offerings.id'),$offering);
        $defs['teaching_assignments']=$spec('Teaching assignments',self::offeringSql('teacher_assignments.teacher_id=:id','JOIN teacher_assignments ON teacher_assignments.class_offering_id=class_offerings.id'),$offering);
        $defs['attendance_records']=$spec(
            'Attendance records',
            'SELECT attendance_records.id,CONCAT(courses.course_code," · ",attendance_records.attendance_date) title,attendance_records.status subtitle,attendance_records.class_offering_id,attendance_records.student_id,attendance_records.attendance_date FROM attendance_records JOIN class_offerings ON class_offerings.id=attendance_records.class_offering_id JOIN courses ON courses.id=class_offerings.course_id WHERE attendance_records.student_id=:id',
            fn($r)=>$guard('manage_attendance',['id'=>(int)$r['id'],'title'=>$r['title'],'subtitle'=>$r['subtitle'],'url'=>url('/admin/?page=attendance&offering_id='.$r['class_offering_id'].'&date='.$r['attendance_date'].'&student_id='.$r['student_id'])])
        );
        $defs['event_activity']=$spec('Event activity history','SELECT l.id,e.id event_id,e.title,l.action subtitle FROM event_activity_logs l JOIN events e ON e.id=l.event_id WHERE l.user_id=:id',fn($r)=>$guard('manage_events',['id'=>(int)$r['id'],'title'=>$r['title'],'subtitle'=>$r['subtitle'],'url'=>EventAccessService::viewUrl((int)$r['event_id'])]));
        $defs['event_attendance']=$spec(
            'Event attendance',
            'SELECT event_attendance.id,events.title,event_attendance.status subtitle,event_attendance.event_id FROM event_attendance JOIN events ON events.id=event_attendance.event_id WHERE event_attendance.user_id=:id',
            fn($r)=>$guard('manage_events',['id'=>(int)$r['id'],'title'=>$r['title'],'subtitle'=>$r['subtitle'],'url'=>url('/admin/?page=events&manage_event='.$r['event_id'])])
        );
        $defs['activity_attendance']=$spec(
            'Activity attendance',
            'SELECT recollection_attendance.id,recollections.title,recollection_attendance.status subtitle,recollection_attendance.recollection_id FROM recollection_attendance JOIN recollections ON recollections.id=recollection_attendance.recollection_id WHERE recollection_attendance.user_id=:id',
            fn($r)=>$guard('manage_recollections',['id'=>(int)$r['id'],'title'=>$r['title'],'subtitle'=>$r['subtitle'],'url'=>url('/admin/?page=recollections&manage_recollection='.$r['recollection_id'])])
        );
        $defs['grades']=$spec(
            'Grade records',
            'SELECT grades.id,courses.course_code title,CONCAT("Student grade · ",semesters.name) subtitle,grades.class_offering_id,grades.student_id FROM grades JOIN class_offerings ON class_offerings.id=grades.class_offering_id JOIN courses ON courses.id=class_offerings.course_id JOIN semesters ON semesters.id=class_offerings.semester_id WHERE grades.student_id=:id OR grades.entered_by=:id',
            fn($r)=>$guard('manage_grades',['id'=>(int)$r['id'],'title'=>$r['title'],'subtitle'=>$r['subtitle'],'url'=>url('/admin/?page=grades&offering_id='.$r['class_offering_id'].'&student_id='.$r['student_id'])])
        );
        $defs['created_events']=$spec('Created events','SELECT id,title,CONCAT(event_date," · ",status) subtitle FROM events WHERE created_by=:id',fn($r)=>$guard('manage_events',['id'=>(int)$r['id'],'title'=>$r['title'],'subtitle'=>$r['subtitle'],'url'=>url('/admin/?page=events&manage_event='.$r['id'])]));
        $defs['created_activities']=$spec('Created activities','SELECT id,title,CONCAT(date," · ",status) subtitle FROM recollections WHERE created_by=:id',fn($r)=>$guard('manage_recollections',['id'=>(int)$r['id'],'title'=>$r['title'],'subtitle'=>$r['subtitle'],'url'=>url('/admin/?page=recollections&manage_recollection='.$r['id'])]));
        $tables=[
            'messages'=>['Sent messages','SELECT id,subject title,created_at subtitle,"message" record_type FROM messages WHERE sender_id=:id','messages'],
            'rfid_history'=>['RFID history','SELECT id,CONCAT("RFID action #",id) title,created_at subtitle,"rfid_history" record_type FROM rfid_assignment_history WHERE user_id=:id OR performed_by=:id','reports'],
            'presets'=>['Seat-plan presets','SELECT id,name title,"Reusable seat-plan preset" subtitle,"preset" record_type FROM seat_plan_presets WHERE created_by=:id','rooms'],
            'room_plans'=>['Room seat-plan updates','SELECT room_seat_plans.id,rooms.room_code title,room_seat_plans.updated_at subtitle,"room_plan" record_type FROM room_seat_plans JOIN rooms ON rooms.id=room_seat_plans.room_id WHERE room_seat_plans.updated_by=:id','rooms'],
            'violations'=>['Disciplinary violations','SELECT disciplinary_violations.id,CONCAT("Violation #",disciplinary_violations.id) title,CONCAT(disciplinary_violations.incident_date," · ",disciplinary_violations.classification) subtitle,"violation" record_type FROM disciplinary_violations WHERE disciplinary_violations.student_id=:id OR disciplinary_violations.issued_by=:id','compliance'],
            'time_off'=>['Teacher time-off records','SELECT teacher_time_off.id,CONCAT("Time off · ",teacher_time_off.starts_on) title,teacher_time_off.reason subtitle,"teacher_time_off" record_type FROM teacher_time_off WHERE teacher_time_off.created_by=:id','reports'],
        ];
        foreach($tables as $key=>[$label,$sql,$page]) $defs[$key]=$spec($label,$sql,fn($r)=>$history($page,$r));
        return $defs;
    }

    public static function linkedRecord(string $type, int $id): array
    {
        if ($id < 1) throw new DomainException('Select a valid linked record.');
        $definition = match ($type) {
            'message' => [
                'permission' => 'send_messages',
                'sql' => 'SELECT subject title, created_at subtitle, body `Message`, IF(is_announcement=1,"Yes","No") `Announcement`, created_at `Sent at` FROM messages WHERE id=:id',
                'explanation' => 'This sent message is protected institutional communication history.',
            ],
            'rfid_history' => [
                'permission' => 'view_audit_logs',
                'sql' => 'SELECT CONCAT("RFID action #",rfid_assignment_history.id) title,rfid_assignment_history.created_at subtitle,rfid_cards.uid `Card UID`,rfid_assignment_history.action `Action`,CONCAT_WS(" ",users.first_name,users.middle_name,users.last_name) `Account`,rfid_assignment_history.notes `Notes`,rfid_assignment_history.created_at `Recorded at` FROM rfid_assignment_history JOIN rfid_cards ON rfid_cards.id=rfid_assignment_history.rfid_card_id JOIN users ON users.id=rfid_assignment_history.user_id WHERE rfid_assignment_history.id=:id',
                'explanation' => 'RFID assignment history is retained for auditability.',
            ],
            'preset' => [
                'permission' => 'manage_seat_plans',
                'sql' => 'SELECT name title,updated_at subtitle,row_count `Rows`,column_count `Columns`,visibility `Visibility`,created_at `Created at`,updated_at `Updated at` FROM seat_plan_presets WHERE id=:id',
                'explanation' => 'This reusable seat-plan preset must be reassigned before its creator can be removed.',
            ],
            'room_plan' => [
                'permission' => 'manage_rooms',
                'sql' => 'SELECT room_seat_plans.name title,room_seat_plans.updated_at subtitle,rooms.room_code `Room`,room_seat_plans.row_count `Rows`,room_seat_plans.column_count `Columns`,room_seat_plans.updated_at `Updated at` FROM room_seat_plans JOIN rooms ON rooms.id=room_seat_plans.room_id WHERE room_seat_plans.id=:id',
                'explanation' => 'This room seat plan records its last editor.',
            ],
            'violation' => [
                'permission' => 'view_compliance',
                'sql' => 'SELECT CONCAT("Violation #",disciplinary_violations.id) title,CONCAT(disciplinary_violations.incident_date," · ",disciplinary_violations.classification) subtitle,disciplinary_violations.violation_group `Violation`,disciplinary_violations.specific_violation `Specific violation`,disciplinary_violations.classification `Classification`,disciplinary_violations.incident_date `Incident date`,disciplinary_violations.incident_time `Incident time`,disciplinary_violations.location `Location`,disciplinary_violations.incident_description `Description` FROM disciplinary_violations WHERE disciplinary_violations.id=:id',
                'explanation' => 'Disciplinary records are protected institutional history and cannot be removed with an account.',
            ],
            'teacher_time_off' => [
                'permission' => 'manage_teacher_time_off',
                'sql' => 'SELECT CONCAT("Teaching time off · ",teacher_time_off.starts_on) title,teacher_time_off.status subtitle,CONCAT_WS(" ",users.first_name,users.middle_name,users.last_name) `Teacher`,teacher_time_off.starts_on `Starts`,teacher_time_off.ends_on `Ends`,teacher_time_off.reason `Reason`,teacher_time_off.status `Status`,teacher_time_off.created_at `Created at` FROM teacher_time_off JOIN users ON users.id=teacher_time_off.teacher_id WHERE teacher_time_off.id=:id',
                'explanation' => 'This class-suspension record identifies the account that created it.',
            ],
            default => throw new DomainException('That linked-record type is unavailable.'),
        };
        if (!Auth::can($definition['permission']) && !($type === 'violation' && Auth::can('manage_compliance'))) {
            throw new DomainException('You do not have permission to view this linked record.');
        }
        $statement = Database::connection()->prepare($definition['sql']);
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new DomainException('The linked record no longer exists. Refresh the deletion conflicts.');
        $title = (string) array_shift($row);
        $subtitle = (string) array_shift($row);
        return ['type'=>$type,'id'=>$id,'title'=>$title,'subtitle'=>$subtitle,'explanation'=>$definition['explanation'],'details'=>$row];
    }

    private static function offeringSql(string $where, string $extraJoin=''): string
    {
        return 'SELECT DISTINCT class_offerings.id,CONCAT(courses.course_code," — ",sections.name) title,CONCAT(programs.code," · ",semesters.name," · ",class_offerings.component_type) subtitle FROM class_offerings '.$extraJoin.' JOIN courses ON courses.id=class_offerings.course_id JOIN sections ON sections.id=class_offerings.section_id JOIN programs ON programs.id=sections.program_id JOIN semesters ON semesters.id=class_offerings.semester_id WHERE '.$where;
    }

    private static function target(string $type, int $id): ?string
    {
        [$sql,$params]=match($type){
            'department'=>['SELECT CONCAT(code," — ",name) FROM departments WHERE id=:id',['id'=>$id]],
            'program'=>['SELECT CONCAT(code," — ",name) FROM programs WHERE id=:id',['id'=>$id]],
            'course'=>['SELECT CONCAT(course_code," — ",title) FROM courses WHERE id=:id',['id'=>$id]],
            'section'=>['SELECT name FROM sections WHERE id=:id',['id'=>$id]],
            'offering'=>['SELECT CONCAT(courses.course_code," · ",class_offerings.component_type," — ",sections.name," · ",school_years.name," · ",semesters.name) FROM class_offerings JOIN courses ON courses.id=class_offerings.course_id JOIN sections ON sections.id=class_offerings.section_id JOIN semesters ON semesters.id=class_offerings.semester_id JOIN school_years ON school_years.id=semesters.school_year_id WHERE class_offerings.id=:id',['id'=>$id]],
            'room'=>['SELECT CONCAT(room_code," — ",room_name) FROM rooms WHERE id=:id',['id'=>$id]],
            'device'=>['SELECT CONCAT(device_code," — ",name) FROM devices WHERE id=:id',['id'=>$id]],
            'user'=>['SELECT CONCAT(first_name," ",last_name," (",school_id,")") FROM users WHERE id=:id',['id'=>$id]],
            'role'=>['SELECT name FROM roles WHERE id=:id',['id'=>$id]],
            'event'=>['SELECT title FROM events WHERE id=:id',['id'=>$id]],
            'recollection'=>['SELECT title FROM recollections WHERE id=:id',['id'=>$id]],
            'preset'=>['SELECT name FROM seat_plan_presets WHERE id=:id',['id'=>$id]],
            default=>throw new DomainException('That deletion-conflict type is unavailable.'),
        };
        $statement=Database::connection()->prepare($sql);$statement->execute($params);$value=$statement->fetchColumn();return $value!==false?(string)$value:null;
    }

    private static function count(string $sql,array $params): int { $s=Database::connection()->prepare('SELECT COUNT(*) FROM ('.$sql.') deletion_conflicts');$s->execute($params);return (int)$s->fetchColumn(); }
    private static function rows(string $sql,array $params,int $page): array { $offset=($page-1)*self::PER_PAGE;$s=Database::connection()->prepare('SELECT * FROM ('.$sql.') deletion_conflict_rows ORDER BY id LIMIT '.self::PER_PAGE.' OFFSET '.$offset);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC); }
    private static function message(string $type,int $total): string { $name=str_replace(['offering','recollection','preset'],['class offering','activity','seat-plan preset'],$type);return $total>0?"This {$name} cannot be deleted because {$total} linked record".($total===1?'':'s').' must be reassigned, removed, or preserved first.':"This {$name} has no blocking linked records."; }
}
