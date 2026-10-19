<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Services\AttendanceService;
use App\Services\GradeService;
use App\Services\SeatPlanService;
use App\Services\LiveSeatPlanService;
use App\Services\TeacherTimeOffService;
use App\Services\MessageService;

final class TeacherApi
{
    private static function permission(string $permission): void
    {
        if (!Auth::can($permission)) throw new ApiError(403,'forbidden','Your account does not have permission for this action.');
    }
    private static function assigned(int $offering, int $actor): void
    {
        if (!Api::rows('SELECT 1 FROM teacher_assignments WHERE teacher_id=? AND class_offering_id=?',[$actor,$offering])) throw new ApiError(403,'unassigned_class','This class is not assigned to you.');
    }
    private static function assignedRoom(int $room,int $actor): void
    {
        if (!Api::rows('SELECT 1 FROM teacher_assignments a JOIN class_offerings o ON o.id=a.class_offering_id LEFT JOIN class_schedules s ON s.class_offering_id=o.id WHERE a.teacher_id=? AND o.status="active" AND (o.room_id=? OR s.room_id=?) LIMIT 1',[$actor,$room,$room])) throw new ApiError(403,'unassigned_room','This room is not assigned to one of your active classes.');
    }
    public static function run(string $area): never
    {
        $user=Api::user('Teacher');$id=(int)$user['id'];
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
                $input=Api::write($area==='seats' ? 262144 : 16384);
                Api::rateLimit('teacher-write',120,60);
                $result=self::write($area,$input,$id);
            } else {
                Api::method('GET');$result=self::read($area,$id);
            }
            Api::respond(['data'=>$result]);
        } catch (DomainException $error) { throw new ApiError(422,'validation',$error->getMessage()); }
    }
    private static function read(string $area,int $id): array
    {
        $offering=(int)($_GET['offering_id'] ?? 0);$query=mb_substr((string)($_GET['q'] ?? ''),0,100);$page=max(1,(int)($_GET['page'] ?? 1));
        $mode=(string)($_GET['mode'] ?? 'roster');$date=(string)($_GET['date'] ?? date('Y-m-d'));
        if($area==='portal') {
            $data=StudentApi::portal($id,true);
            $data['permissions']=Auth::permissions();
            $data['time_off']=TeacherTimeOffService::entriesForUser($id);
            return $data;
        }
        if($area==='recipients') {
            self::permission('send_messages');
            return ['roles'=>Api::rows('SELECT id,name FROM roles ORDER BY name'),'sections'=>Api::rows('SELECT id,name FROM sections WHERE status="active" ORDER BY name')];
        }
        if($area==='compliance') { self::permission('view_compliance'); return ['items'=>\App\Services\ComplianceService::forTeacher($id,null,$query,50,($page-1)*50),'total'=>\App\Services\ComplianceService::countForTeacher($id,null,$query),'page'=>$page]; }
        if($area==='roster') { self::assigned($offering,$id);return \App\Services\ClassRosterService::roster($offering,$id,$query,$page); }
        if($area==='attendance') {
            self::permission('manage_attendance');self::assigned($offering,$id);
            return match($mode) {
                'calendar'=>AttendanceService::calendar($offering,$id,(string)($_GET['month'] ?? date('Y-m'))),
                'policy'=>self::policy($offering,$id),
                'live'=>LiveSeatPlanService::state($offering,$id,$date),
                'seat_students'=>['students'=>LiveSeatPlanService::students($offering,(int)($_GET['plan_id'] ?? 0),$id,$query)],
                default=>AttendanceService::roster($offering,$id,$date,$query,$page)
            };
        }
        if($area==='grades') { self::permission('manage_grades');self::assigned($offering,$id);return GradeService::roster($offering,$id,$query,$page); }
        if($area==='seats') {
            self::permission('manage_seat_plans');
            if($mode==='room') self::assignedRoom((int)($_GET['room_id'] ?? 0),$id);
            return match($mode) {
                'room'=>SeatPlanService::roomPlan((int)($_GET['room_id'] ?? 0),$id),
                'preset'=>SeatPlanService::preset((int)($_GET['preset_id'] ?? 0),$id),
                default=>SeatPlanService::bootstrap($id)
            };
        }
        if($area==='time-off') { self::permission('manage_teacher_time_off'); return ['items'=>TeacherTimeOffService::entriesForUser($id)]; }
        throw new ApiError(404,'not_found','Teacher endpoint not found.');
    }
    private static function policy(int $offering,int $id): array { self::permission('manage_attendance_policies');return ['policy'=>AttendanceService::policy($offering,$id)]; }
    private static function write(string $area,array $input,int $id): array
    {
        $action=Api::text($input,'action',60);$offering=(int)($input['class_offering_id'] ?? 0);
        if($area==='attendance') {
            self::permission('manage_attendance');self::assigned($offering,$id);
            $date=Api::text($input,'attendance_date',10,false) ?: date('Y-m-d');
            if($action==='manual') { AttendanceService::recordManual($offering,(int)($input['student_id'] ?? 0),$date,Api::text($input,'status',20),$id,Api::text($input,'reason',255),Api::text($input,'time_in',20,false));return ['message'=>'Attendance saved.']; }
            if($action==='finalize') return ['message'=>'Attendance finalized.','created'=>AttendanceService::finalize($offering,$date,$id)];
            if($action==='policy') { self::permission('manage_attendance_policies');return ['message'=>'Class attendance policy saved.','policy'=>AttendanceService::updateSubjectPolicy($offering,($input['use_campus_default'] ?? false)===true,(int)($input['early_check_in_minutes'] ?? 10),(int)($input['grace_period_minutes'] ?? -1),(int)($input['absent_after_minutes'] ?? 0),(int)($input['tardies_per_absence'] ?? 0),$id)]; }
            if($action==='assign') { LiveSeatPlanService::assign($offering,(int)($input['seat_plan_id'] ?? 0),(int)($input['grid_row'] ?? 0),(int)($input['grid_column'] ?? 0),(int)($input['student_id'] ?? 0),$id);return ['message'=>'Seat assigned.']; }
            if($action==='unassign') { LiveSeatPlanService::unassign($offering,(int)($input['seat_plan_id'] ?? 0),(int)($input['grid_row'] ?? 0),(int)($input['grid_column'] ?? 0),$id);return ['message'=>'Seat assignment removed.']; }
        }
        if($area==='grades') {
            self::permission('manage_grades');
            if($action==='save') { self::assigned($offering,$id);GradeService::save($input,$id);return ['message'=>'Grades saved.']; }
            if($action==='reopen') { GradeService::reopen((int)($input['grade_id'] ?? 0),$id);return ['message'=>'Grade reopened.']; }
        }
        if($area==='time-off') {
            self::permission('manage_teacher_time_off');
            if($action==='save') return ['message'=>'Teaching time off saved.','id'=>TeacherTimeOffService::save($input,$id)];
            if($action==='cancel') { TeacherTimeOffService::cancel((int)($input['time_off_id'] ?? 0),$id);return ['message'=>'Time off cancelled.']; }
        }
        if($area==='messages' && $action==='send') {
            self::permission('send_messages');
            $input['subject']=Api::text($input,'subject',180);$input['body']=Api::text($input,'body',12000);
            if(!($input['is_announcement'] ?? false)) unset($input['is_announcement']);
            return ['message'=>'Message sent.','id'=>MessageService::send($input,$id)];
        }
        if($area==='seats') {
            self::permission('manage_seat_plans');
            if($action==='save_room') self::assignedRoom((int)($input['room_id'] ?? 0),$id);
            if(isset($input['cells']) && is_array($input['cells'])) $input['cells']=json_encode($input['cells'],JSON_THROW_ON_ERROR);
            if($action==='save_room') return ['message'=>'Room layout saved.']+SeatPlanService::saveRoomPlan($input,$id);
            if($action==='save_preset') return ['message'=>'Preset saved.']+SeatPlanService::savePreset($input,$id);
            if($action==='update_preset') return ['message'=>'Preset updated.']+SeatPlanService::updatePreset($input,$id);
            if($action==='delete_preset') { SeatPlanService::deletePreset((int)($input['preset_id'] ?? 0),$id);return ['message'=>'Preset deleted.']; }
        }
        throw new ApiError(422,'unknown_action','This action is not available.');
    }
}
