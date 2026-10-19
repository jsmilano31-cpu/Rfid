<?php
declare(strict_types=1);

final class StudentApi
{
    public static function run(string $action): never
    {
        if ($action === 'health') { Api::method('GET'); Api::respond(['data' => ['service' => 'campustrack-student', 'version' => 1]]); }
        if ($action === 'session') {
            Api::method('GET');
            Api::respond(['data' => ['authenticated' => !empty($_SESSION['user_id']), 'csrf_token' => Api::token(), 'roles' => !empty($_SESSION['user_id']) ? array_column(Api::rows('SELECT r.name FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=?', [Api::user()['id']]), 'name') : []]]);
        }
        if ($action === 'login') {
            $input = Api::write(); Api::rateLimit('login', 15, 900);
            $identifier = Api::text($input, 'identifier', 190);
            $password = $input['password'] ?? '';
            if (!is_string($password) || strlen($password) > 72) throw new ApiError(422, 'validation', 'Enter your password.');
            $user = Api::rows('SELECT * FROM users WHERE school_id=? OR email=? LIMIT 1', [$identifier, $identifier])[0] ?? null;
            $valid = password_verify($password, $user['password_hash'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.');
            if (!$user || !$valid) throw new ApiError(401, 'invalid_credentials', 'School ID/email or password is incorrect.');
            if ($user['status'] !== 'active') throw new ApiError(403, 'account_inactive', 'Your account is awaiting school approval or is inactive. Please contact the school office.');
            $roles = Api::rows('SELECT roles.name FROM user_roles JOIN roles ON roles.id=user_roles.role_id WHERE user_id=?', [$user['id']]);
            if (!array_intersect(['Student', 'Teacher'], array_column($roles, 'name'))) throw new ApiError(403, 'portal_role_required', 'Use the web portal for this account.');
            session_regenerate_id(true);
            $_SESSION = ['user_id' => (int) $user['id'], 'password_version' => hash('sha256', $user['password_hash']), 'expires_at' => time() + 28800];
            Api::respond(['data' => ['csrf_token' => Api::token()]]);
        }
        if ($action === 'register') {
            $input = Api::write(); Api::rateLimit('registration', 5, 3600);
            $fields = [];
            foreach (['school_id' => 40, 'first_name' => 80, 'last_name' => 80, 'email' => 190] as $key => $max) $fields[$key] = Api::text($input, $key, $max);
            $fields['middle_name'] = Api::text($input, 'middle_name', 80, false) ?: null;
            if (!filter_var($fields['email'], FILTER_VALIDATE_EMAIL)) throw new ApiError(422, 'validation', 'Enter a valid email address.');
            $password = Api::password($input, 'password');
            if (($input['password_confirmation'] ?? null) !== $password) throw new ApiError(422, 'validation', 'The passwords do not match.');
            $role = Api::rows('SELECT id FROM roles WHERE name=?', ['Student'])[0]['id'] ?? null;
            if (!$role) throw new RuntimeException('Student role unavailable');
            $db = Api::db(); $db->beginTransaction();
            try {
                $query = $db->prepare('INSERT INTO users (school_id,first_name,middle_name,last_name,email,password_hash,role_id,status,profile_image_path) VALUES (?,?,?,?,?,?,?,"inactive","/assets/images/default-avatar.svg")');
                $query->execute([$fields['school_id'], $fields['first_name'], $fields['middle_name'], $fields['last_name'], strtolower($fields['email']), password_hash($password, PASSWORD_DEFAULT), $role]);
                $id = (int) $db->lastInsertId();
                $db->prepare('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)')->execute([$id, $role]);
                $db->prepare('INSERT INTO audit_logs (user_id,action,entity_type,entity_id,details,ip_address) VALUES (NULL,"mobile_registration","user",?,"Student self-registration; pending administrator activation",?)')->execute([(string) $id, $_SERVER['REMOTE_ADDR'] ?? null]);
                $db->commit();
            } catch (Throwable $error) {
                if ($db->inTransaction()) $db->rollBack();
                if ($error instanceof PDOException && (int) ($error->errorInfo[1] ?? 0) === 1062) throw new ApiError(409, 'account_exists', 'The school ID or email is already registered. Sign in or contact the school office.');
                throw $error;
            }
            Api::respond(['data' => ['status' => 'pending_approval', 'message' => 'Registration received. The school must activate your account and assign your academic details before you can sign in.']], 201);
        }
        if ($action === 'logout') {
            Api::write();
            try { \App\Services\NotificationService::revokeSession(); } catch (\Throwable) { error_log('Could not revoke push session'); }
            $_SESSION = []; session_regenerate_id(true);
            Api::respond(['data' => ['csrf_token' => Api::token()]]);
        }
        $user = Api::user(); $id = (int) $user['id'];
        if (in_array($action, ['people','directory-profile','roster','calendar','sent','photo','feed'], true)) StudentExtras::run($action, $user);
        if ($action === 'message-read') {
            $input = Api::write(); $messageId = filter_var($input['message_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$messageId || $messageId < 1) throw new ApiError(422, 'validation', 'Select a valid message.');
            if (!Api::rows('SELECT id FROM message_recipients WHERE message_id=? AND user_id=?', [$messageId, $id])) throw new ApiError(404, 'not_found', 'Message not found.');
            Api::db()->prepare('UPDATE message_recipients SET read_at=COALESCE(read_at,NOW()) WHERE message_id=? AND user_id=?')->execute([$messageId, $id]);
            Api::respond(['data' => ['message_id' => $messageId, 'read' => true]]);
        }
        if ($action === 'profile-update') {
            $input = Api::write(); $email = strtolower(Api::text($input, 'email', 190));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiError(422, 'validation', 'Enter a valid email address.');
            try { Api::db()->prepare('UPDATE users SET email=? WHERE id=?')->execute([$email, $id]); }
            catch (PDOException $error) { if ((int) ($error->errorInfo[1] ?? 0) === 1062) throw new ApiError(409, 'email_exists', 'This email is already in use.'); throw $error; }
            Api::respond(['data' => self::profile($id)]);
        }
        if ($action === 'profile-visibility') {
            $input = Api::write();
            if (!array_key_exists('is_profile_public', $input) || !is_bool($input['is_profile_public'])) {
                throw new ApiError(422, 'validation', 'Choose whether your profile is public or private.');
            }
            $visibility = $input['is_profile_public'] ? 'public' : 'private';
            Api::db()->prepare('UPDATE users SET profile_visibility=? WHERE id=?')->execute([$visibility, $id]);
            \App\Services\AuditService::log($id, 'update_profile_visibility', 'user', $id, 'Set profile visibility to ' . $visibility);
            Api::respond(['data' => ['is_profile_public' => $input['is_profile_public']]]);
        }
        if ($action === 'settings') {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $input = Api::write();
                $settings = \App\Services\UserPreferenceService::update($id, $input);
                if (array_key_exists('profile_public', $input) && is_bool($input['profile_public'])) {
                    Api::db()->prepare('UPDATE users SET profile_visibility=? WHERE id=?')->execute([$input['profile_public'] ? 'public' : 'private', $id]);
                }
                Api::respond(['data'=>['settings'=>$settings,'profile_public'=>(bool)(StudentApi::profile($id)['is_profile_public'] ?? true)]]);
            }
            Api::method('GET');
            Api::respond(['data'=>['settings'=>\App\Services\UserPreferenceService::get($id),'profile_public'=>(bool)(StudentApi::profile($id)['is_profile_public'] ?? true)]]);
        }
        if ($action === 'password') {
            $input = Api::write(); Api::rateLimit('password', 10, 900);
            $current = $input['current_password'] ?? '';
            if (!is_string($current) || !password_verify($current, $user['password_hash'])) throw new ApiError(422, 'validation', 'Your current password is incorrect.');
            $password = Api::password($input, 'password');
            if (($input['password_confirmation'] ?? null) !== $password) throw new ApiError(422, 'validation', 'The new passwords do not match.');
            $hash = password_hash($password, PASSWORD_DEFAULT);
            Api::db()->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $id]);
            session_regenerate_id(true); $_SESSION['password_version'] = hash('sha256', $hash); unset($_SESSION['mobile_csrf']);
            Api::respond(['data' => ['csrf_token' => Api::token(), 'message' => 'Password updated. Other mobile sessions will need to sign in again.']]);
        }
        Api::method('GET');
        if ($action === 'profile') Api::respond(['data' => self::profile($id)]);
        if ($action === 'portal') { Api::user('Student'); Api::respond(['data' => self::portal($id)]); }
        throw new ApiError(404, 'not_found', 'Endpoint not found.');
    }

    public static function profile(int $id): array
    {
        $profile = Api::rows('SELECT u.id,u.school_id,u.first_name,u.middle_name,u.last_name,u.email,u.status,u.profile_image_path,u.profile_visibility,r.name AS primary_role,d.name AS department,p.name AS program,s.name AS section FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN departments d ON d.id=u.department_id LEFT JOIN programs p ON p.id=u.program_id LEFT JOIN sections s ON s.id=u.section_id WHERE u.id=?', [$id])[0];
        $profile['id'] = (int) $profile['id'];
        $profile['roles'] = array_column(Api::rows('SELECT roles.name FROM user_roles JOIN roles ON roles.id=user_roles.role_id WHERE user_id=? ORDER BY roles.name', [$id]), 'name');
        $profile['is_profile_public'] = $profile['profile_visibility'] === 'public'; unset($profile['profile_visibility']);
        $profile['profile_image_url'] = Api::asset($profile['profile_image_path']); unset($profile['profile_image_path']);
        $card = Api::rows('SELECT uid,status FROM rfid_cards WHERE user_id=? LIMIT 1', [$id])[0] ?? null;
        $profile['rfid'] = $card ? ['masked_uid' => '•••• ' . substr($card['uid'], -4), 'status' => $card['status']] : null;
        return $profile;
    }

    public static function portal(int $id, bool $teacher = false): array
    {
        $courseSql = 'SELECT o.id,o.course_id,o.component_type,c.course_code,c.title,s.name AS section,sem.name AS semester,o.status,g.prelim,g.midterm,g.final,g.overall,g.status AS grade_status,g.updated_at AS grade_updated_at FROM class_enrollments e JOIN class_offerings o ON o.id=e.class_offering_id JOIN courses c ON c.id=o.course_id JOIN sections s ON s.id=o.section_id JOIN semesters sem ON sem.id=o.semester_id LEFT JOIN grades g ON g.class_offering_id=o.id AND g.student_id=e.student_id WHERE e.student_id=? AND e.status="enrolled" ORDER BY c.course_code,o.component_type';
        if ($teacher) $courseSql = str_replace(['class_enrollments e', 'g.student_id=e.student_id', 'e.student_id=? AND e.status="enrolled"'], ['teacher_assignments e', 'g.student_id=0', 'e.teacher_id=?'], $courseSql);
        $courses = Api::rows($courseSql, [$id]);
        foreach ($courses as &$course) {
            $course['id'] = (int) $course['id']; $course['course_id'] = (int) $course['course_id'];
            foreach (['prelim','midterm','final','overall'] as $term) $course[$term] = $course[$term] === null ? null : (float) $course[$term];
            $course['time_off'] = Api::rows('SELECT t.starts_on,t.ends_on,t.reason,CONCAT_WS(" ",u.first_name,u.last_name) teacher FROM teacher_time_off t JOIN teacher_assignments a ON a.teacher_id=t.teacher_id JOIN users u ON u.id=t.teacher_id WHERE a.class_offering_id=? AND t.status="active" ORDER BY t.starts_on', [$course['id']]);
            $course['days_off'] = array_column(Api::rows('SELECT d.day_of_week FROM department_days_off d JOIN programs p ON p.department_id=d.department_id JOIN sections s ON s.program_id=p.id JOIN class_offerings o ON o.section_id=s.id WHERE o.id=?', [$course['id']]), 'day_of_week');
            $course['teachers'] = Api::rows('SELECT u.id,CONCAT_WS(" ",u.first_name,u.middle_name,u.last_name) AS name FROM teacher_assignments t JOIN users u ON u.id=t.teacher_id WHERE t.class_offering_id=? ORDER BY u.last_name', [$course['id']]);
            $course['meetings'] = Api::rows('SELECT cs.id,cs.day_of_week,cs.start_time,cs.end_time,r.room_name AS room,r.building,r.floor,r.room_code FROM class_schedules cs JOIN class_offerings o ON o.id=cs.class_offering_id LEFT JOIN rooms r ON r.id=COALESCE(cs.room_id,o.room_id) WHERE cs.class_offering_id=? ORDER BY FIELD(cs.day_of_week,"Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"),cs.start_time', [$course['id']]);
        }
        unset($course);
        $attendance = Api::rows('SELECT a.id,a.class_offering_id,o.component_type,c.course_code,c.title,a.attendance_date,a.time_in,a.time_out,a.status,a.source FROM attendance_records a JOIN class_offerings o ON o.id=a.class_offering_id JOIN courses c ON c.id=o.course_id WHERE a.student_id=? ORDER BY a.attendance_date DESC,a.id DESC', [$id]);
        $counts = Api::rows('SELECT status,COUNT(*) AS total FROM attendance_records WHERE student_id=? GROUP BY status', [$id]);
        $events = Api::rows('SELECT e.*,ea.status AS attendance_status,ep.is_required FROM event_participants ep JOIN events e ON e.id=ep.event_id LEFT JOIN event_attendance ea ON ea.event_id=e.id AND ea.user_id=ep.user_id WHERE ep.user_id=? ORDER BY e.event_date DESC', [$id]);
        if ($teacher) $events = Api::rows('SELECT e.*,ea.status AS attendance_status,e.attendance_required AS is_required FROM events e LEFT JOIN event_attendance ea ON ea.event_id=e.id AND ea.user_id=? ORDER BY e.event_date DESC', [$id]);
        $activities = [];
        foreach ($events as $event) {
            $images = array_map(static fn($r) => Api::asset($r['image_path']), Api::rows('SELECT image_path FROM event_images WHERE event_id=? ORDER BY display_order,id', [$event['id']]));
            $activities[] = ['id' => 'event-' . $event['id'], 'title' => $event['title'], 'kind' => $event['is_announcement'] ? 'Announcement' : 'Event', 'date' => $event['event_date'], 'end_date' => $event['end_date'], 'start_time' => $event['start_time'], 'end_time' => $event['end_time'], 'venue' => $event['venue'], 'description' => $event['description'] ?? '', 'attendance' => $event['attendance_status'], 'status' => $event['status'], 'required' => (bool) $event['is_required'], 'image_url' => Api::asset($event['banner_image_path']), 'images' => $images];
        }
        $formation = Api::rows('SELECT recollections.*,ra.status AS attendance_status FROM recollections LEFT JOIN recollection_attendance ra ON ra.recollection_id=recollections.id AND ra.user_id=? WHERE ' . \App\Services\RecollectionService::involvementCondition($id) . ' ORDER BY recollections.date DESC', [$id]);
        foreach ($formation as $item) $activities[] = ['id' => 'formation-' . $item['id'], 'title' => $item['title'], 'kind' => $item['type'], 'date' => $item['date'], 'end_date' => null, 'start_time' => $item['start_time'], 'end_time' => $item['end_time'], 'venue' => $item['venue'], 'description' => ($item['facilitator'] ? 'Facilitator: ' . $item['facilitator'] : '') . '\nSection: ' . (Api::rows('SELECT name FROM sections WHERE id=?', [$item['section_id']])[0]['name'] ?? ''), 'attendance' => $item['attendance_status'], 'status' => $item['status'], 'required' => (bool) $item['attendance_required'], 'image_url' => null, 'images' => []];
        $messages = Api::rows('SELECT m.id,m.sender_id,m.subject,m.body,m.created_at,mr.read_at,CONCAT_WS(" ",u.first_name,u.middle_name,u.last_name) AS sender FROM message_recipients mr JOIN messages m ON m.id=mr.message_id JOIN users u ON u.id=m.sender_id WHERE mr.user_id=? ORDER BY m.created_at DESC', [$id]);
        $grading = \App\Services\GradeService::settings(); unset($grading['updated_by']);
        return ['student' => self::profile($id), 'courses' => $courses, 'attendance' => $attendance, 'attendance_counts' => $counts, 'attendance_window_days' => 0, 'activities' => $activities, 'messages' => $messages, 'grading' => $grading, 'compliance' => \App\Services\ComplianceService::forStudent($id), 'subject_compliance' => \App\Services\ComplianceService::bySubjectForStudent($id), 'timezone' => config('app')['timezone'], 'limits' => ['attendance' => null, 'activities_per_kind' => null, 'messages' => null]];
    }
}
