<?php
declare(strict_types=1);

final class StudentExtras
{
    public static function run(string $action, array $user): never
    {
        $id = (int)$user['id'];
        if ($action === 'photo') {
            Api::csrf(); Api::rateLimit('photo', 20, 3600);
            try { \App\Services\UserService::updateProfileImage($id, $_FILES['profile_image'] ?? null, $id); }
            catch (DomainException $error) { throw new ApiError(422, 'invalid_image', $error->getMessage()); }
            Api::respond(['data'=>StudentApi::profile($id)]);
        }
        Api::method('GET');
        if ($action === 'feed') {
            $items = \App\Services\EventService::feed($id, null, 50);
            foreach ($items as &$item) {
                $item['images'] = array_map([Api::class, 'asset'], $item['images']);
                $item['poster']['avatar'] = Api::asset($item['poster']['avatar']);
            } unset($item);
            Api::respond(['data'=>['items'=>$items]]);
        }
        $page = max(1, min(1000000, (int)($_GET['page'] ?? 1)));
        $query = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
        if ($action === 'people') {
            if (mb_strlen($query) < 2) Api::respond(['data'=>['items'=>[], 'page'=>1, 'pages'=>1, 'total'=>0]]);
            $where = 'u.status="active" AND (u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT_WS(" ",u.first_name,u.middle_name,u.last_name) LIKE ? OR u.school_id LIKE ? OR u.email LIKE ?)';
            $params = array_fill(0, 5, $query.'%');
            $role = (string)($_GET['role'] ?? '');
            if ($role !== '') { $where .= ' AND EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=u.id AND r.name=?)'; $params[]=$role; }
            $total=(int)Api::rows('SELECT COUNT(*) n FROM users u WHERE '.$where,$params)[0]['n'];
            $pages=max(1,(int)ceil($total/20)); $page=min($page,$pages); $offset=($page-1)*20;
            $items=Api::rows('SELECT u.id,u.school_id,CONCAT_WS(" ",u.first_name,u.middle_name,u.last_name) name,r.name role,u.profile_image_path FROM users u JOIN roles r ON r.id=u.role_id WHERE '.$where.' ORDER BY u.last_name,u.first_name,u.id LIMIT 20 OFFSET '.$offset,$params);
            foreach($items as &$item) { $item['photo_url']=Api::asset($item['profile_image_path']); unset($item['profile_image_path']); } unset($item);
            Api::respond(['data'=>compact('items','page','pages','total')]);
        }
        if ($action === 'directory-profile') {
            $target=(int)($_GET['id'] ?? 0);
            $profile=\App\Services\DirectoryProfileService::profile(\App\Core\Auth::user(),$target);
            if (!$profile) throw new ApiError(404,'not_found','Profile not found.');
            if (!empty($profile['is_private'])) Api::respond(['data'=>$profile]);
            // The web hides this field in its template. JSON must enforce that boundary itself.
            if (!$profile['can_view_contact']) unset($profile['profile']['email']);
            $profile['profile']['photo_url']=Api::asset($profile['profile']['profile_image_path']);
            unset($profile['profile']['profile_image_path']);
            Api::respond(['data'=>$profile]);
        }
        if ($action === 'roster') {
            $offering=(int)($_GET['offering_id'] ?? 0);
            if (!Api::rows('SELECT 1 FROM class_enrollments WHERE student_id=? AND class_offering_id=? AND status="enrolled"',[$id,$offering])) throw new ApiError(403,'forbidden','This class is not in your enrolled subjects.');
            $result=\App\Services\ClassRosterService::roster($offering,$id,$query,$page,24);
            foreach($result['students'] as &$student) {
                unset($student['email'],$student['profile_url']);
                $student['photo_url']=Api::asset($student['profile_image_path']); unset($student['profile_image_path']);
            } unset($student);
            Api::respond(['data'=>$result]);
        }
        if ($action === 'calendar') {
            Api::respond(['data'=>['entries'=>\App\Services\CalendarService::entriesForUser($id),'policies'=>\App\Services\AcademicCalendarService::policies()]]);
        }
        if ($action === 'sent') {
            $total=(int)Api::rows('SELECT COUNT(*) n FROM messages WHERE sender_id=?',[$id])[0]['n'];
            $pages=max(1,(int)ceil($total/30));$page=min($page,$pages);$offset=($page-1)*30;
            $items=Api::rows('SELECT m.id,m.subject,m.body,m.created_at,(SELECT COUNT(*) FROM message_recipients mr WHERE mr.message_id=m.id) recipient_count FROM messages m WHERE sender_id=? ORDER BY created_at DESC,id DESC LIMIT 30 OFFSET '.$offset,[$id]);
            Api::respond(['data'=>compact('items','page','pages','total')]);
        }
        throw new ApiError(404,'not_found','Endpoint not found.');
    }
}
