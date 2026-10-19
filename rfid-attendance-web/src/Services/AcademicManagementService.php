<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DomainException;
use PDO;
use PDOException;
use Throwable;

final class AcademicManagementService
{
    private const ENTITIES = ['department', 'program', 'course', 'section', 'offering'];

    public static function editor(string $entity, int $id = 0): array
    {
        self::assertEntity($entity);
        $pdo = Database::connection();
        $record = $id > 0 ? self::find($entity, $id) : self::blank($entity);
        if (!$record) throw new DomainException('The selected record could not be found.');

        $options = match ($entity) {
            'program', 'course' => ['departments' => self::rows($pdo, 'SELECT id, code, name FROM departments WHERE status = "active" OR id = ' . (int) ($record['department_id'] ?? 0) . ' ORDER BY code')],
            'section' => ['programs' => self::rows($pdo, 'SELECT id, code, name FROM programs WHERE status = "active" OR id = ' . (int) ($record['program_id'] ?? 0) . ' ORDER BY code')],
            'offering' => [
                'courses' => self::rows($pdo, 'SELECT id, course_code code, title name FROM courses WHERE status = "active" OR id = ' . (int) ($record['course_id'] ?? 0) . ' ORDER BY course_code'),
                'programs' => self::rows($pdo, 'SELECT id, code, name FROM programs WHERE status = "active" ORDER BY code'),
                'sections' => self::rows($pdo, 'SELECT id, program_id, year_level, section_code, name FROM sections WHERE status = "active" OR id = ' . (int) ($record['section_id'] ?? 0) . ' ORDER BY name'),
                'school_years' => self::rows($pdo, 'SELECT id, name FROM school_years ORDER BY starts_on DESC'),
                'semesters' => self::rows($pdo, 'SELECT id, school_year_id, name FROM semesters ORDER BY starts_on DESC'),
                'rooms' => self::rows($pdo, 'SELECT id, room_code code, room_name name FROM rooms WHERE status = "active" OR id = ' . (int) ($record['room_id'] ?? 0) . ' ORDER BY room_code'),
            ],
            default => [],
        };
        return ['entity' => $entity, 'record' => $record, 'options' => $options];
    }

    public static function save(string $entity, array $data, int $actorId): array
    {
        self::assertEntity($entity);
        return match ($entity) {
            'department' => self::saveDepartment($data, $actorId),
            'program' => self::saveProgram($data, $actorId),
            'course' => self::saveCourse($data, $actorId),
            'section' => self::saveSection($data, $actorId),
            'offering' => self::saveOffering($data, $actorId),
        };
    }

    public static function createOffering(array $data, int $actorId): array
    {
        $courseId = (int) ($data['course_id'] ?? 0);
        $programId = (int) ($data['offering_program_id'] ?? $data['program_id'] ?? 0);
        $sectionId = (int) ($data['section_id'] ?? 0);
        $schoolYearId = (int) ($data['offering_school_year_id'] ?? $data['school_year_id'] ?? 0);
        $semesterId = (int) ($data['semester_id'] ?? 0);
        $teacherId = (int) ($data['teacher_id'] ?? 0);
        $componentType = strtoupper(trim((string) ($data['component_type'] ?? 'LEC')));
        self::validateOfferingReferences($courseId, $programId, $sectionId, $schoolYearId, $semesterId, $teacherId, $componentType);

        $pdo = Database::connection();
        $existing = $pdo->prepare('SELECT id,status FROM class_offerings WHERE course_id=:course AND section_id=:section AND semester_id=:semester AND component_type=:component LIMIT 1');
        $existing->execute(['course'=>$courseId,'section'=>$sectionId,'semester'=>$semesterId,'component'=>$componentType]);
        $existingOffering = $existing->fetch();
        $offeringId = (int) ($existingOffering['id'] ?? 0);
        if ($offeringId > 0) throw new DomainException('That component already exists for this course, section, and semester. Edit the existing offering to change its meetings.');
        $schedules = self::validatedSchedules($data, $sectionId, $semesterId, $teacherId, $offeringId);
        $created = $offeringId < 1;

        $pdo->beginTransaction();
        try {
            if ($created) {
                $pdo->prepare('INSERT INTO class_offerings (course_id,section_id,semester_id,component_type,room_id) VALUES (:course,:section,:semester,:component,:room)')
                    ->execute(['course'=>$courseId,'section'=>$sectionId,'semester'=>$semesterId,'component'=>$componentType,'room'=>$schedules[0]['room_id']]);
                $offeringId = (int) $pdo->lastInsertId();
                $pdo->prepare('INSERT INTO teacher_assignments (class_offering_id,teacher_id) VALUES (:offering,:teacher)')->execute(['offering'=>$offeringId,'teacher'=>$teacherId]);
                $pdo->prepare('INSERT INTO class_enrollments (class_offering_id,student_id)
                               SELECT :offering,users.id FROM users
                               WHERE users.section_id=:section AND users.status="active"
                                 AND EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id=users.id AND user_roles.role_id=3)')
                    ->execute(['offering'=>$offeringId,'section'=>$sectionId]);
            }

            $inserted = self::insertSchedules($pdo, $offeringId, $schedules);
            AuditService::log($actorId, $created ? 'create_class_offering' : 'add_class_schedules', 'class_offering', $offeringId, json_encode(['schedule_count'=>$inserted], JSON_THROW_ON_ERROR));
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($exception instanceof PDOException) self::duplicate($exception, 'That course, section, semester, and component already exists. Refresh the component choices.');
            throw $exception;
        }
        return ['record'=>self::find('offering',$offeringId) ?? throw new DomainException('Unable to reload the offering.'),'created'=>$created,'schedule_count'=>$inserted];
    }

    public static function componentAvailability(int $course,int $section,int $semester,int $exclude=0): array
    {
        if (min($course,$section,$semester)<1) throw new DomainException('Choose a course, section, and semester first.');
        $q=Database::connection()->prepare('SELECT id,component_type,status FROM class_offerings WHERE course_id=? AND section_id=? AND semester_id=? AND id<>? ORDER BY component_type');
        $q->execute([$course,$section,$semester,$exclude]);
        return ['components'=>array_map(static fn($r)=>['id'=>(int)$r['id'],'component'=>$r['component_type'],'status'=>$r['status'],'url'=>url('/admin/?page=academics&manage=offering&entity_id='.(int)$r['id'])],$q->fetchAll())];
    }

    public static function delete(string $entity, int $id, int $actorId): array
    {
        self::assertEntity($entity);
        $record = self::find($entity, $id);
        if (!$record) throw new DomainException('The selected record could not be found.');

        $pdo = Database::connection();
        DeletionConflictService::assertClear($entity, $id);

        $table = match ($entity) {
            'department' => 'departments', 'program' => 'programs', 'course' => 'courses',
            'section' => 'sections', 'offering' => 'class_offerings',
        };
        $label = match ($entity) {
            'department', 'program' => $record['code'] . ' — ' . $record['name'],
            'course' => $record['course_code'] . ' — ' . $record['title'],
            'section' => $record['name'],
            'offering' => $record['course_code'] . ' — ' . $record['program_code'] . ' ' . $record['section_name'],
        };
        try {
            $pdo->prepare("DELETE FROM {$table} WHERE id = :id")->execute(['id' => $id]);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1451) {
                DeletionConflictService::assertClear($entity, $id);
                throw new DomainException('This record was linked elsewhere while deletion was in progress. Refresh and try again.');
            }
            throw $exception;
        }
        AuditService::log($actorId, 'delete_' . ($entity === 'offering' ? 'class_offering' : $entity), $entity === 'offering' ? 'class_offering' : $entity, $id, $label);
        return ['id' => $id, 'label' => $label];
    }

    public static function enrollmentRoster(int $offeringId, string $query = '', int $page = 1): array
    {
        self::requireExisting('class_offerings', $offeringId);
        $query = mb_substr(trim($query), 0, 100);
        $page = max(1, $page); $perPage = 20; $params = ['offering' => $offeringId];
        $where = 'class_enrollments.class_offering_id = :offering';
        if ($query !== '') {
            $where .= ' AND (users.school_id LIKE :school OR users.first_name LIKE :first OR users.last_name LIKE :last OR CONCAT_WS(" ",users.first_name,users.middle_name,users.last_name) LIKE :full)';
            $prefix=$query.'%'; $params += ['school'=>$prefix,'first'=>$prefix,'last'=>$prefix,'full'=>$prefix];
        }
        $pdo=Database::connection(); $count=$pdo->prepare('SELECT COUNT(*) FROM class_enrollments JOIN users ON users.id=class_enrollments.student_id WHERE '.$where);$count->execute($params);$total=(int)$count->fetchColumn();$pages=max(1,(int)ceil($total/$perPage));$page=min($page,$pages);$offset=($page-1)*$perPage;
        $statement=$pdo->prepare('SELECT class_enrollments.student_id, class_enrollments.status, users.school_id, CONCAT_WS(" ",users.first_name,users.middle_name,users.last_name) student_name FROM class_enrollments JOIN users ON users.id=class_enrollments.student_id WHERE '.$where.' ORDER BY users.last_name,users.first_name LIMIT '.$perPage.' OFFSET '.$offset);$statement->execute($params);
        return ['students'=>$statement->fetchAll(),'pagination'=>['page'=>$page,'pages'=>$pages,'total'=>$total,'from'=>$total?$offset+1:0,'to'=>min($offset+$perPage,$total)]];
    }

    public static function setEnrollment(int $offeringId, int $studentId, string $status, int $actorId): void
    {
        if (!in_array($status,['enrolled','dropped','completed'],true)) throw new DomainException('Select a valid enrollment status.');
        self::requireExisting('class_offerings',$offeringId);
        $student=Database::connection()->prepare('SELECT 1 FROM users WHERE id=:id AND status="active" AND EXISTS(SELECT 1 FROM user_roles WHERE user_id=users.id AND role_id=3)');$student->execute(['id'=>$studentId]);if(!$student->fetchColumn())throw new DomainException('Select an active student.');
        Database::connection()->prepare('INSERT INTO class_enrollments (class_offering_id,student_id,status) VALUES (:offering,:student,:status) ON DUPLICATE KEY UPDATE status=VALUES(status)')->execute(['offering'=>$offeringId,'student'=>$studentId,'status'=>$status]);
        AuditService::log($actorId,'update_class_enrollment','class_offering',$offeringId,"Student {$studentId}: {$status}");
    }

    public static function find(string $entity, int $id): ?array
    {
        self::assertEntity($entity);
        $pdo = Database::connection();
        $sql = match ($entity) {
            'department' => 'SELECT * FROM departments WHERE id = :id',
            'program' => 'SELECT programs.*, departments.code department_code, departments.name department_name FROM programs JOIN departments ON departments.id = programs.department_id WHERE programs.id = :id',
            'course' => 'SELECT courses.*, departments.code department_code, departments.name department_name FROM courses LEFT JOIN departments ON departments.id = courses.department_id WHERE courses.id = :id',
            'section' => 'SELECT sections.*, programs.code program_code, programs.name program_name FROM sections JOIN programs ON programs.id = sections.program_id WHERE sections.id = :id',
            'offering' => 'SELECT class_offerings.*, sections.program_id, sections.name section_name, programs.code program_code, programs.name program_name,
                    semesters.school_year_id, courses.course_code, courses.title course_title,
                    rooms.room_code, rooms.room_name,
                    assignments.teacher_id, CONCAT(users.first_name, " ", users.last_name) teacher_name, users.school_id teacher_school_id
                FROM class_offerings
                JOIN courses ON courses.id = class_offerings.course_id
                JOIN sections ON sections.id = class_offerings.section_id
                JOIN programs ON programs.id = sections.program_id
                JOIN semesters ON semesters.id = class_offerings.semester_id
                LEFT JOIN rooms ON rooms.id = class_offerings.room_id
                LEFT JOIN teacher_assignments assignments ON assignments.class_offering_id = class_offerings.id
                LEFT JOIN users ON users.id = assignments.teacher_id
                WHERE class_offerings.id = :id ORDER BY assignments.id LIMIT 1',
        };
        $statement = $pdo->prepare($sql);
        $statement->execute(['id' => $id]);
        $record = $statement->fetch() ?: null;
        if ($record && $entity === 'offering') {
            $schedules = $pdo->prepare('SELECT id,room_id,day_of_week,start_time,end_time FROM class_schedules WHERE class_offering_id=:id ORDER BY FIELD(day_of_week,"Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"),start_time,id');
            $schedules->execute(['id'=>$id]);
            $record['schedules'] = $schedules->fetchAll();
        }
        return $record;
    }

    private static function saveDepartment(array $data, int $actorId): array
    {
        $id = (int) ($data['id'] ?? 0);
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $name = normalize_title_case($data['name'] ?? '');
        $color = (string) ($data['color_key'] ?? 'grey');
        $status = self::status($data);
        if ($code === '' || $name === '') throw new DomainException('Enter a department code and name.');
        self::length('Department code', $code, 30); self::length('Department name', $name, 150);
        if (!in_array($color, ['green', 'yellow', 'grey', 'blue', 'orange', 'red', 'maroon'], true)) throw new DomainException('Select a valid department color.');
        $pdo = Database::connection();
        try {
            if ($id > 0) {
                self::requireExisting('departments', $id);
                $pdo->prepare('UPDATE departments SET code=:code,name=:name,color_key=:color,status=:status WHERE id=:id')->execute(compact('code', 'name', 'color', 'status', 'id'));
            } else {
                $pdo->prepare('INSERT INTO departments (code,name,color_key,status) VALUES (:code,:name,:color,:status)')->execute(compact('code', 'name', 'color', 'status'));
                $id = (int) $pdo->lastInsertId();
            }
        } catch (PDOException $exception) { self::duplicate($exception, 'That department code is already in use.'); }
        AuditService::log($actorId, $data['id'] ?? 0 ? 'update_department' : 'create_department', 'department', $id, $code);
        return self::find('department', $id) ?? throw new DomainException('Unable to reload the department.');
    }

    private static function saveProgram(array $data, int $actorId): array
    {
        $id = (int) ($data['id'] ?? 0); $departmentId = (int) ($data['department_id'] ?? 0);
        $code = strtoupper(trim((string) ($data['code'] ?? ''))); $name = normalize_title_case($data['name'] ?? ''); $status = self::status($data);
        if ($departmentId < 1 || $code === '' || $name === '') throw new DomainException('Select a department and enter the program code and name.');
        self::requireActive('departments', $departmentId, 'Select an active department.');
        self::length('Program code', $code, 30); self::length('Program name', $name, 150);
        $pdo = Database::connection();
        try {
            if ($id > 0) { self::requireExisting('programs', $id); $pdo->prepare('UPDATE programs SET department_id=:department,code=:code,name=:name,status=:status WHERE id=:id')->execute(['department'=>$departmentId,'code'=>$code,'name'=>$name,'status'=>$status,'id'=>$id]); }
            else { $pdo->prepare('INSERT INTO programs (department_id,code,name,status) VALUES (:department,:code,:name,:status)')->execute(['department'=>$departmentId,'code'=>$code,'name'=>$name,'status'=>$status]); $id=(int)$pdo->lastInsertId(); }
        } catch (PDOException $exception) { self::duplicate($exception, 'That program code is already in use.'); }
        AuditService::log($actorId, $data['id'] ?? 0 ? 'update_program' : 'create_program', 'program', $id, $code);
        return self::find('program', $id) ?? throw new DomainException('Unable to reload the program.');
    }

    private static function saveCourse(array $data, int $actorId): array
    {
        $id=(int)($data['id']??0); $departmentId=(int)($data['department_id']??0) ?: null; $code=strtoupper(trim((string)($data['course_code']??''))); $title=normalize_title_case($data['title']??''); $units=(float)($data['units']??0); $status=self::status($data);
        if ($id < 1 || $code==='' || $title==='' || $units < .5 || $units > 9) throw new DomainException('Enter valid course details and units.');
        if ($departmentId) self::requireActive('departments',$departmentId,'Select an active department.');
        self::length('Course code',$code,30); self::length('Course title',$title,150); self::requireExisting('courses',$id);
        try { Database::connection()->prepare('UPDATE courses SET department_id=:department,course_code=:code,title=:title,units=:units,status=:status WHERE id=:id')->execute(['department'=>$departmentId,'code'=>$code,'title'=>$title,'units'=>$units,'status'=>$status,'id'=>$id]); }
        catch (PDOException $exception) { self::duplicate($exception,'That course code is already in use.'); }
        AuditService::log($actorId,'update_course','course',$id,$code); return self::find('course',$id) ?? throw new DomainException('Unable to reload the course.');
    }

    private static function saveSection(array $data, int $actorId): array
    {
        $id=(int)($data['id']??0); $programId=(int)($data['program_id']??0); $year=(int)($data['year_level']??0); $code=strtoupper(trim((string)($data['section_code']??''))); $status=self::status($data);
        if ($id<1 || $programId<1 || $year<1 || $year>4 || !preg_match('/^[A-Z][A-Z0-9]{0,9}$/',$code)) throw new DomainException('Select a program and year, then enter a valid section code.');
        self::requireActive('programs',$programId,'Select an active program.'); self::requireExisting('sections',$id);
        $statement=Database::connection()->prepare('SELECT code FROM programs WHERE id=:id'); $statement->execute(['id'=>$programId]); $name=(string)$statement->fetchColumn().' '.$year.$code;
        try { Database::connection()->prepare('UPDATE sections SET program_id=:program,name=:name,year_level=:year,section_code=:code,status=:status WHERE id=:id')->execute(['program'=>$programId,'name'=>$name,'year'=>$year,'code'=>$code,'status'=>$status,'id'=>$id]); }
        catch (PDOException $exception) { self::duplicate($exception,'That year and section already exists in the selected program.'); }
        AuditService::log($actorId,'update_section','section',$id,$name); return self::find('section',$id) ?? throw new DomainException('Unable to reload the section.');
    }

    private static function saveOffering(array $data, int $actorId): array
    {
        $id=(int)($data['id']??0); $courseId=(int)($data['course_id']??0); $programId=(int)($data['program_id']??0); $sectionId=(int)($data['section_id']??0); $schoolYearId=(int)($data['school_year_id']??0); $semesterId=(int)($data['semester_id']??0); $teacherId=(int)($data['teacher_id']??0); $status=(string)($data['status']??'active'); $componentType=strtoupper((string)($data['component_type']??'LEC'));
        if ($id<1 || min($courseId,$programId,$sectionId,$schoolYearId,$semesterId,$teacherId)<1) throw new DomainException('Complete all required offering fields.');
        if (!in_array($status,['active','completed','cancelled'],true)) throw new DomainException('Select a valid offering status.');
        $pdo=Database::connection(); self::requireExisting('class_offerings',$id);
        self::validateOfferingReferences($courseId,$programId,$sectionId,$schoolYearId,$semesterId,$teacherId,$componentType);
        $schedules=self::validatedSchedules($data,$sectionId,$semesterId,$teacherId,$id);
        $owns=!$pdo->inTransaction(); if($owns)$pdo->beginTransaction();
        try {
            SharedGradeService::lock($id);
            $current=self::find('offering',$id);
            if ((int)$current['course_id']!==$courseId || (int)$current['section_id']!==$sectionId || (int)$current['semester_id']!==$semesterId) {
                $linked=$pdo->prepare('SELECT 1 FROM grades g JOIN class_offerings o ON o.id=g.class_offering_id JOIN class_offerings r ON '.SharedGradeService::same('o','r').' WHERE r.id=? LIMIT 1');$linked->execute([$id]);
                if ($linked->fetchColumn()) throw new DomainException('This subject has shared grades. Keep its course, section, and semester unchanged; create a new offering for a different subject group.');
            }
            $pdo->prepare('UPDATE class_offerings SET course_id=:course,section_id=:section,semester_id=:semester,component_type=:component,room_id=:room,status=:status WHERE id=:id')->execute(['course'=>$courseId,'section'=>$sectionId,'semester'=>$semesterId,'component'=>$componentType,'room'=>$schedules[0]['room_id'],'status'=>$status,'id'=>$id]);
            $pdo->prepare('DELETE FROM teacher_assignments WHERE class_offering_id=:id')->execute(['id'=>$id]);
            $pdo->prepare('INSERT INTO teacher_assignments (class_offering_id,teacher_id) VALUES (:id,:teacher)')->execute(['id'=>$id,'teacher'=>$teacherId]);
            $pdo->prepare('DELETE FROM class_schedules WHERE class_offering_id=:id')->execute(['id'=>$id]);
            self::insertSchedules($pdo,$id,$schedules);
            if(isset($data['sync_section_students'])) $pdo->prepare('INSERT INTO class_enrollments (class_offering_id,student_id,status) SELECT :offering,users.id,"enrolled" FROM users WHERE users.section_id=:section AND users.status="active" AND EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id=users.id AND user_roles.role_id=3) ON DUPLICATE KEY UPDATE status=IF(class_enrollments.status="dropped",class_enrollments.status,"enrolled")')->execute(['offering'=>$id,'section'=>$sectionId]);
            AuditService::log($actorId,'update_class_offering','class_offering',$id,json_encode(['course'=>$courseId,'section'=>$sectionId,'teacher'=>$teacherId],JSON_THROW_ON_ERROR)); if($owns)$pdo->commit();
        } catch(PDOException $exception) { if($owns&&$pdo->inTransaction())$pdo->rollBack(); self::duplicate($exception,'That course, section, semester, and component offering already exists.'); }
        catch(Throwable $exception) { if($owns&&$pdo->inTransaction())$pdo->rollBack(); throw $exception; }
        return self::find('offering',$id) ?? throw new DomainException('Unable to reload the offering.');
    }

    private static function validateOfferingReferences(int $courseId,int $programId,int $sectionId,int $schoolYearId,int $semesterId,int $teacherId,string $componentType): void
    {
        if(min($courseId,$programId,$sectionId,$schoolYearId,$semesterId,$teacherId)<1) throw new DomainException('Complete all required offering fields.');
        if(!in_array($componentType,['LEC','LAB'],true)) throw new DomainException('Select Lecture or Laboratory as the class component.');
        $pdo=Database::connection(); self::requireActive('courses',$courseId,'Select an active course.');
        $link=$pdo->prepare('SELECT 1 FROM sections WHERE id=:section AND program_id=:program');$link->execute(['section'=>$sectionId,'program'=>$programId]);if(!$link->fetchColumn())throw new DomainException('The selected section does not belong to that program.');
        $term=$pdo->prepare('SELECT 1 FROM semesters WHERE id=:semester AND school_year_id=:year');$term->execute(['semester'=>$semesterId,'year'=>$schoolYearId]);if(!$term->fetchColumn())throw new DomainException('The selected semester does not belong to that school year.');
        $teacher=$pdo->prepare('SELECT 1 FROM users WHERE id=:id AND status="active" AND EXISTS(SELECT 1 FROM user_roles WHERE user_id=users.id AND role_id=2)');$teacher->execute(['id'=>$teacherId]);if(!$teacher->fetchColumn())throw new DomainException('Select an active teacher.');
    }

    private static function validatedSchedules(array $data,int $sectionId,int $semesterId,int $teacherId,int $excludeOfferingId=0): array
    {
        $raw=$data['schedules']??null;
        if(!is_array($raw))$raw=[['room_id'=>$data['room_id']??'','day_of_week'=>$data['day_of_week']??'','start_time'=>$data['start_time']??'','end_time'=>$data['end_time']??'']];
        if(!$raw||count($raw)>14)throw new DomainException('Add between 1 and 14 meeting schedules.');
        $schedules=[];
        foreach(array_values($raw) as $index=>$row){
            if(!is_array($row))throw new DomainException('Enter valid meeting schedule details.');
            $day=(string)($row['day_of_week']??'');$roomId=(int)($row['room_id']??0)?:null;
            if(!in_array($day,AcademicCalendarService::DAYS,true))throw new DomainException('Select a valid day for meeting '.($index+1).'.');
            if($roomId)self::requireActive('rooms',$roomId,'Select an active room for meeting '.($index+1).'.');
            AcademicCalendarService::assertScheduleAllowed($sectionId,$day);
            $start=normalize_time($row['start_time']??'');$end=normalize_time($row['end_time']??'');
            if($end<=$start)throw new DomainException('Meeting '.($index+1).' must end after it starts.');
            foreach($schedules as $existing)if($existing['day_of_week']===$day)throw new DomainException("Add only one meeting schedule for {$day}.");
            self::assertNoScheduleConflict($semesterId,$sectionId,$teacherId,$roomId,$day,$start,$end,$excludeOfferingId);
            $schedules[]=['room_id'=>$roomId,'day_of_week'=>$day,'start_time'=>$start,'end_time'=>$end];
        }
        return $schedules;
    }

    private static function assertNoScheduleConflict(int $semesterId,int $sectionId,int $teacherId,?int $roomId,string $day,string $start,string $end,int $excludeOfferingId): void
    {
        $statement=Database::connection()->prepare('SELECT offerings.section_id,courses.course_code,sections.name section_name,
                EXISTS(SELECT 1 FROM teacher_assignments conflict_teacher WHERE conflict_teacher.class_offering_id=offerings.id AND conflict_teacher.teacher_id=:teacher) teacher_conflict,
                COALESCE(schedules.room_id,offerings.room_id) conflict_room
            FROM class_schedules schedules
            JOIN class_offerings offerings ON offerings.id=schedules.class_offering_id
            JOIN courses ON courses.id=offerings.course_id JOIN sections ON sections.id=offerings.section_id
            WHERE offerings.semester_id=:semester AND offerings.id<>:exclude AND offerings.status="active"
              AND schedules.day_of_week=:day AND schedules.start_time<:ends AND schedules.end_time>:starts
              AND (offerings.section_id=:section OR EXISTS(SELECT 1 FROM teacher_assignments conflict_assignment WHERE conflict_assignment.class_offering_id=offerings.id AND conflict_assignment.teacher_id=:teacher_filter)
                   OR (:room_gate=1 AND COALESCE(schedules.room_id,offerings.room_id)=:room)) LIMIT 1');
        $statement->execute(['teacher'=>$teacherId,'semester'=>$semesterId,'exclude'=>$excludeOfferingId,'day'=>$day,'ends'=>$end,'starts'=>$start,'section'=>$sectionId,'teacher_filter'=>$teacherId,'room_gate'=>$roomId?1:0,'room'=>$roomId]);
        $conflict=$statement->fetch();if(!$conflict)return;
        $reasons=[];if((int)$conflict['section_id']===$sectionId)$reasons[]='section';if((bool)$conflict['teacher_conflict'])$reasons[]='teacher';if($roomId&&$conflict['conflict_room']!==null&&(int)$conflict['conflict_room']===$roomId)$reasons[]='room';
        throw new DomainException('The '.implode(', ',$reasons).' already has an overlapping class on '.$day.' ('.$conflict['course_code'].' · '.$conflict['section_name'].').');
    }

    private static function insertSchedules(PDO $pdo,int $offeringId,array $schedules): int
    {
        $insert=$pdo->prepare('INSERT INTO class_schedules (class_offering_id,room_id,day_of_week,start_time,end_time) VALUES (:offering,:room,:day,:start,:end)');$count=0;
        foreach($schedules as $schedule){$insert->execute(['offering'=>$offeringId,'room'=>$schedule['room_id'],'day'=>$schedule['day_of_week'],'start'=>$schedule['start_time'],'end'=>$schedule['end_time']]);$count+=$insert->rowCount();}
        return $count;
    }

    private static function blank(string $entity): array { return ['id'=>0,'status'=>'active'] + ($entity==='department'?['color_key'=>'grey']:[]); }
    private static function assertEntity(string $entity): void { if(!in_array($entity,self::ENTITIES,true)) throw new DomainException('That academic entity is not manageable.'); }
    private static function status(array $data): string { $status=(string)($data['status']??'active'); if(!in_array($status,['active','inactive'],true)) throw new DomainException('Select a valid status.'); return $status; }
    private static function length(string $label,string $value,int $max): void { if(mb_strlen($value)>$max) throw new DomainException("{$label} cannot exceed {$max} characters."); }
    private static function rows(PDO $pdo,string $sql): array { return $pdo->query($sql)->fetchAll(); }
    private static function requireExisting(string $table,int $id): void { $statement=Database::connection()->prepare("SELECT 1 FROM {$table} WHERE id=:id"); $statement->execute(['id'=>$id]); if(!$statement->fetchColumn()) throw new DomainException('The selected record could not be found.'); }
    private static function requireActive(string $table,int $id,string $message): void { $statement=Database::connection()->prepare("SELECT 1 FROM {$table} WHERE id=:id AND status=\"active\""); $statement->execute(['id'=>$id]); if(!$statement->fetchColumn()) throw new DomainException($message); }
    private static function duplicate(PDOException $exception,string $message): never { if((int)($exception->errorInfo[1]??0)===1062) throw new DomainException($message); throw $exception; }
}
