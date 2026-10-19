<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\View;
use App\Core\PermissionRegistry;
use App\Services\AttendanceService;
use App\Services\AcademicCalendarService;
use App\Services\AuditService;
use App\Services\ComplianceService;
use App\Services\ClassRosterService;
use App\Services\DirectoryProfileService;
use App\Services\GradeService;
use App\Services\SharedGradeService;
use App\Services\MessageService;
use App\Services\RecollectionService;
use App\Services\UserService;
use App\Services\UserPreferenceService;
use DomainException;
use PDO;
use Throwable;

final class PortalController
{
    private const PAGES = ['dashboard', 'attendance', 'grades', 'schedule', 'class-roster', 'seat-plans', 'events', 'recollections', 'compliance', 'messages', 'people', 'profile', 'directory_profile', 'settings', 'analytics'];
    private const PAGE_ACTIONS = [
        'attendance' => ['rfid_class_scan', 'manual_attendance', 'finalize_attendance'],
        'grades' => ['save_grade'],
        'messages' => ['send_message', 'mark_message_read'],
        'profile' => ['update_profile', 'change_password'],
        'settings' => ['update_settings'],
    ];

    public static function handle(string $page): void
    {
        $user = Auth::requireLogin();
        $page = in_array($page, self::PAGES, true) ? $page : 'dashboard';
        if ($page === 'profile' && $_SERVER['REQUEST_METHOD'] === 'GET'
            && (int)($_GET['id']??$_GET['user_id']??$user['id']) === (int)$user['id']
            && Auth::hasRole('Administrator')) {
            redirect(Auth::usesAdminArea() ? '/admin/?page=settings' : '/index/?page=settings');
        }
        if ($page === 'compliance' && Auth::usesAdminArea()) {
            // Old portal bookmarks and forms use the same authorized workspace.
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                AdminController::handle('compliance');
                return;
            }
            redirect('/admin/?' . http_build_query(array_replace($_GET, ['page' => 'compliance'])));
        }
        Auth::requireAnyPermission(PermissionRegistry::pagePermissions($page, false, (int)($_GET['id']??$_GET['user_id']??0)));

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::handlePost($page, $user);
        }

        $data = self::dataFor($page, $user);
        $data['title'] = $page === 'directory_profile' ? 'Profile' : ucwords($page);
        $data['active'] = $page === 'directory_profile' ? 'profile' : $page;
        $data['user'] = $user;
        $viewName = $page === 'directory_profile' ? 'profile' : $page;
        View::render('portal/' . $viewName, $data);
    }

    private static function handlePost(string $page, array $user): never
    {
        try {
            Csrf::verify();
            $action = (string) ($_POST['action'] ?? '');
            if (!in_array($action, self::PAGE_ACTIONS[$page] ?? [], true)) {
                http_response_code(403);
                require BASE_PATH . '/templates/errors/403.php';
                exit;
            }
            PermissionRegistry::requireAction($action);
            $actorId = (int) $user['id'];
            $pdo = Database::connection();
            switch ($action) {
                case 'rfid_class_scan':
                    if (!Auth::can('manage_attendance')) throw new DomainException('You do not have permission to manage attendance.');
                    $result = AttendanceService::recordRfidScan((int) $_POST['class_offering_id'], $_POST['uid'], $actorId, $_POST['attendance_date'] ?: null, $_POST['time_in'] ?: null);
                    flash('success', $result['user']['first_name'] . ' recorded as ' . $result['status'] . '.');
                    break;
                case 'manual_attendance':
                    if (!PermissionRegistry::canCorrectAttendance()) throw new DomainException('You do not have permission to correct attendance.');
                    AttendanceService::recordManual((int) $_POST['class_offering_id'], (int) $_POST['student_id'], $_POST['attendance_date'], $_POST['status'], $actorId, $_POST['reason'], $_POST['time_in'] ?? null);
                    flash('success', 'Attendance corrected.');
                    break;
                case 'finalize_attendance':
                    if (!Auth::can('manage_attendance')) throw new DomainException('You do not have permission to manage attendance.');
                    $created = AttendanceService::finalize((int) $_POST['class_offering_id'], $_POST['attendance_date'], $actorId);
                    flash('success', "Attendance finalized; {$created} absence records created.");
                    break;
                case 'save_grade':
                    if (!Auth::can('manage_grades')) throw new DomainException('You do not have permission to manage grades.');
                    GradeService::save($_POST, $actorId);
                    flash('success', 'Grade saved.');
                    break;
                case 'send_message':
                    if (!Auth::can('send_messages')) {
                        throw new DomainException('You do not have permission to send messages.');
                    }
                    MessageService::send($_POST, $actorId, $_FILES['attachments'] ?? null);
                    flash('success', 'Message sent.');
                    break;
                case 'mark_message_read':
                    MessageService::change($actorId, [$_POST['message_id'] ?? ''], 'read');
                    flash('success', 'Message marked as read.');
                    break;
                case 'change_password':
                    if (!password_verify((string) $_POST['current_password'], $user['password_hash'])) {
                        throw new DomainException('Current password is incorrect.');
                    }
                    if (strlen((string) $_POST['new_password']) < 8 || $_POST['new_password'] !== $_POST['confirm_password']) {
                        throw new DomainException('New passwords must match and contain at least 8 characters.');
                    }
                    $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id')->execute(['hash' => password_hash($_POST['new_password'], PASSWORD_DEFAULT), 'id' => $actorId]);
                    AuditService::log($actorId, 'change_password', 'user', $actorId);
                    flash('success', 'Password changed.');
                    break;
                case 'update_profile':
                    $email = trim((string) ($_POST['email'] ?? ''));
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        throw new DomainException('Enter a valid email address.');
                    }
                    $statement = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id <> :id');
                    $statement->execute(['email' => $email, 'id' => $actorId]);
                    if ($statement->fetchColumn()) {
                        throw new DomainException('That email address is already in use.');
                    }
                    $visibility = ($_POST['profile_visibility'] ?? 'public') === 'private' ? 'private' : 'public';
                    $pdo->prepare('UPDATE users SET email = :email, profile_visibility = :visibility WHERE id = :id')
                        ->execute(['email' => $email, 'visibility' => $visibility, 'id' => $actorId]);
                    if (isset($_FILES['profile_image']) && (int) ($_FILES['profile_image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                        UserService::updateProfileImage($actorId, $_FILES['profile_image'], $actorId);
                    }
                    AuditService::log($actorId, 'update_profile', 'user', $actorId, 'Updated contact information and profile visibility');
                    flash('success', 'Profile settings updated.');
                    break;
                case 'update_settings':
                    UserPreferenceService::update($actorId, $_POST);
                    $visibility = ($_POST['profile_public'] ?? '0') === '1' ? 'public' : 'private';
                    $pdo->prepare('UPDATE users SET profile_visibility=? WHERE id=?')->execute([$visibility,$actorId]);
                    flash('success', 'Settings saved.');
                    break;
                default:
                    throw new DomainException('Unknown form action.');
            }
        } catch (Throwable $exception) {
            flash('danger', config('app')['debug'] ? $exception->getMessage() : 'The action could not be completed.');
        }
        redirect('/index/' . ($page === 'dashboard' ? '' : '?page=' . $page));
    }

    private static function dataFor(string $page, array $user): array
    {
        $pdo = Database::connection();
        $id = (int) $user['id'];
        return match ($page) {
            'dashboard' => self::dashboard($pdo, $user),
            'attendance' => self::attendance($pdo, $user),
            'grades' => self::grades($pdo, $user),
            'schedule' => ['schedules' => self::scheduleRows($pdo, $user)],
            'class-roster' => ['rosterSubject' => ClassRosterService::subject((int) ($_GET['offering_id'] ?? 0), $id)],
            'seat-plans' => self::seatPlans($user),
            'events' => ['events' => self::events($pdo, $user)],
            'recollections' => ['recollections' => self::recollections($pdo, $user)],
            'compliance' => self::compliance($user),
            'messages' => [
                'roles' => $pdo->query('SELECT * FROM roles ORDER BY name')->fetchAll(),
                'sections' => $pdo->query('SELECT * FROM sections WHERE status = "active" ORDER BY name')->fetchAll(),
            ],
            'people' => [],
            'settings' => ['settings' => UserPreferenceService::get($id)],
            'directory_profile', 'profile' => [
                'viewedProfile' => ((isset($_GET['user_id']) && (int) $_GET['user_id'] > 0) || (isset($_GET['id']) && (int) $_GET['id'] > 0))
                    ? DirectoryProfileService::profile($user, (int) ($_GET['user_id'] ?? $_GET['id'] ?? 0))
                    : null,
                'rfid' => self::single($pdo, 'SELECT uid, status, assigned_at FROM rfid_cards WHERE user_id = :id LIMIT 1', ['id' => $id]),
            ],
            default => [],
        };
    }

    private static function seatPlans(array $user): array
    {
        $subjects = \App\Services\SeatPlanService::subjects((int) $user['id']);
        $selectedId = (int) ($_GET['offering_id'] ?? 0);
        $selectedSubject = null;
        if ($selectedId > 0) {
            foreach ($subjects as $subject) {
                if ((int) $subject['id'] === $selectedId) {
                    $selectedSubject = $subject;
                    break;
                }
            }
            if (!$selectedSubject) throw new DomainException('That subject is not assigned to you.');
        }
        return ['seatPlanSubjects' => $subjects, 'selectedSeatPlanSubject' => $selectedSubject];
    }

    private static function dashboard(PDO $pdo, array $user): array
    {
        $id = (int) $user['id'];
        $data = [
            'schedule' => Auth::can('view_schedule') ? self::scheduleRows($pdo, $user) : [], 'events' => Auth::canAny(['view_events','manage_events']) ? self::events($pdo, $user) : [],
            'recollections' => Auth::canAny(['view_recollections','manage_recollections']) ? self::recollections($pdo, $user) : [], 'unread' => Auth::can('view_messages') ? (int) self::single($pdo, 'SELECT COUNT(*) total FROM message_recipients WHERE user_id=:id AND deleted_at IS NULL AND read_at IS NULL', ['id'=>$id])['total'] : null,
            'calendar_sections' => $pdo->query('SELECT id, name FROM sections WHERE status = "active" ORDER BY name')->fetchAll(),
            'department_day_policies' => AcademicCalendarService::policies(),
            'eventFeed' => Auth::canAny(['view_events','manage_events']) ? \App\Services\EventService::feed($id, null, 50) : [],
            'categorizedEvents' => Auth::canAny(['view_events','manage_events']) ? \App\Services\EventService::categorized($id) : [],
        ];
        if ($user['role_name'] === 'Student') {
            $data['attendanceSummary'] = Auth::can('view_attendance') ? self::all($pdo, 'SELECT status, COUNT(*) total FROM attendance_records WHERE student_id = :id GROUP BY status', ['id' => $id]) : [];
            $data['latestGrades'] = Auth::can('view_grades') ? self::all($pdo, 'SELECT grades.overall, grades.status, courses.course_code, courses.title FROM ' . SharedGradeService::grades() . ' grades JOIN ' . SharedGradeService::subjects() . ' class_offerings ON class_offerings.id = grades.class_offering_id JOIN courses ON courses.id = class_offerings.course_id WHERE grades.student_id = :id ORDER BY grades.updated_at DESC LIMIT 5', ['id' => $id]) : [];
            $data['compliance'] = Auth::can('view_compliance') ? ComplianceService::forStudent($id) : ['total_deficiencies'=>null,'absences'=>null,'tardies'=>null,'missed_events'=>null,'missed_recollections'=>null];
        } else {
            $data['assignedClasses'] = Auth::can('view_schedule') ? self::all($pdo, 'SELECT courses.course_code, sections.name section_name FROM teacher_assignments JOIN class_offerings ON class_offerings.id = teacher_assignments.class_offering_id JOIN courses ON courses.id = class_offerings.course_id JOIN sections ON sections.id = class_offerings.section_id WHERE teacher_assignments.teacher_id = :id', ['id' => $id]) : [];
        }
        return $data;
    }

    private static function attendance(PDO $pdo, array $user): array
    {
        $id = (int) $user['id'];
        $offeringId = max(0, (int) ($_GET['offering_id'] ?? 0));
        $selectedSubject = self::hasRole($user, 'Student') && $offeringId
            ? self::personalSubject($pdo, $id, $offeringId)
            : null;
        $recordSql = 'SELECT attendance_records.*, courses.course_code, courses.title
                      FROM attendance_records
                      JOIN class_offerings ON class_offerings.id = attendance_records.class_offering_id
                      JOIN courses ON courses.id = class_offerings.course_id
                      WHERE student_id = :id';
        $recordParams = ['id' => $id];
        if ($selectedSubject) {
            $recordSql .= ' AND attendance_records.class_offering_id = :offering_id';
            $recordParams['offering_id'] = $offeringId;
        }
        $recordSql .= ' ORDER BY attendance_date DESC';
        return [
            'offerings' => PermissionRegistry::canCorrectAttendance() ? AttendanceService::offeringSelectorRows($id) : [],
            'records' => self::hasRole($user, 'Student')
                ? self::all($pdo, $recordSql, $recordParams)
                : [],
            'selectedAttendanceSubject' => $selectedSubject,
        ];
    }

    private static function grades(PDO $pdo, array $user): array
    {
        $id = (int) $user['id'];
        if (self::hasRole($user, 'Student')) {
            $offeringId = max(0, (int) ($_GET['offering_id'] ?? 0));
            $selectedSubject = null;
            if ($offeringId) {
                $lookup = $pdo->prepare('SELECT s.id,c.course_code,c.title course_title,se.name section_name FROM class_offerings o JOIN ' . SharedGradeService::subjects() . ' s ON ' . SharedGradeService::same('o','s') . ' JOIN courses c ON c.id=s.course_id JOIN sections se ON se.id=s.section_id WHERE o.id=? AND ' . SharedGradeService::studentScope('s','?'));
                $lookup->execute([$offeringId,$id]);$selectedSubject=$lookup->fetch() ?: null;
                if (!$selectedSubject) throw new \DomainException('You do not have access to that subject.');
                $offeringId=(int)$selectedSubject['id'];
            }
            $gradeSql = 'SELECT grades.id, grades.prelim, grades.midterm, grades.final, grades.overall, grades.status,
                                class_offerings.id class_offering_id, class_offerings.component_type, courses.course_code, courses.title,
                                sections.name section_name, semesters.name semester_name, school_years.name school_year_name,
                                subject_teachers.teacher_names
                         FROM ' . SharedGradeService::enrollments() . ' class_enrollments
                         JOIN ' . SharedGradeService::subjects() . ' class_offerings ON class_offerings.id = class_enrollments.class_offering_id
                         JOIN courses ON courses.id = class_offerings.course_id
                         JOIN sections ON sections.id = class_offerings.section_id
                         JOIN semesters ON semesters.id=class_offerings.semester_id
                         JOIN school_years ON school_years.id=semesters.school_year_id
                         LEFT JOIN (
                             SELECT teacher_offerings.course_id, teacher_offerings.section_id, teacher_offerings.semester_id,
                                    GROUP_CONCAT(DISTINCT CONCAT_WS(" ", teachers.first_name, NULLIF(teachers.middle_name,""), teachers.last_name)
                                        ORDER BY teachers.last_name, teachers.first_name, teachers.middle_name SEPARATOR ", ") teacher_names
                             FROM teacher_assignments
                             JOIN class_offerings teacher_offerings ON teacher_offerings.id=teacher_assignments.class_offering_id
                             JOIN users teachers ON teachers.id=teacher_assignments.teacher_id
                             GROUP BY teacher_offerings.course_id, teacher_offerings.section_id, teacher_offerings.semester_id
                         ) subject_teachers ON ' . SharedGradeService::same('subject_teachers','class_offerings') . '
                         LEFT JOIN ' . SharedGradeService::grades() . ' grades ON grades.class_offering_id = class_offerings.id AND grades.student_id = class_enrollments.student_id
                         WHERE class_enrollments.student_id = :id AND class_enrollments.status = "enrolled"';
            $gradeParams = ['id' => $id];
            if ($selectedSubject) {
                $gradeSql .= ' AND class_offerings.id = :offering_id';
                $gradeParams['offering_id'] = $offeringId;
            }
            $gradeSql .= ' ORDER BY courses.course_code';
            return [
                'gradeRows' => self::all($pdo, $gradeSql, $gradeParams),
                'offerings' => Auth::can('manage_grades') ? GradeService::offeringSelectorRows($id) : [],
                'selectedGradeSubject' => $selectedSubject,
            ];
        }
        return [
            'gradeRows' => [],
            'offerings' => Auth::can('manage_grades') ? GradeService::offeringSelectorRows($id) : [],
            'selectedGradeSubject' => null,
        ];
    }

    private static function personalSubject(PDO $pdo, int $studentId, int $offeringId): ?array
    {
        return self::single($pdo,
            'SELECT class_offerings.id, courses.course_code, courses.title course_title,
                    programs.code program_code, programs.name program_name, sections.name section_name,
                    school_years.name school_year_name, semesters.name semester_name,
                    semesters.starts_on term_starts_on, semesters.ends_on term_ends_on
             FROM class_enrollments
             JOIN class_offerings ON class_offerings.id = class_enrollments.class_offering_id
             JOIN courses ON courses.id = class_offerings.course_id
             JOIN sections ON sections.id = class_offerings.section_id
             JOIN programs ON programs.id = sections.program_id
             JOIN semesters ON semesters.id = class_offerings.semester_id
             JOIN school_years ON school_years.id = semesters.school_year_id
             WHERE class_enrollments.student_id = :student_id AND class_enrollments.class_offering_id = :offering_id
               AND class_enrollments.status = "enrolled" LIMIT 1',
            ['student_id' => $studentId, 'offering_id' => $offeringId]
        );
    }

    private static function scheduleRows(PDO $pdo, array $user): array
    {
        return \App\Services\ScheduleService::forViewer($user);
    }

    private static function events(PDO $pdo, array $user): array
    {
        return self::all($pdo, 'SELECT events.*, ep.is_required, ea.status attendance_status FROM events LEFT JOIN event_participants ep ON ep.event_id=events.id AND ep.user_id=:participant LEFT JOIN event_attendance ea ON ea.event_id=events.id AND ea.user_id=:attendance WHERE '.\App\Services\EventAccessService::visibleCondition((int)$user['id']).' ORDER BY event_date DESC', ['participant'=>$user['id'],'attendance'=>$user['id']]);
    }

    private static function recollections(PDO $pdo, array $user): array
    {
        $userId = (int) $user['id'];
        return self::all($pdo, 'SELECT DISTINCT recollections.*, sections.name section_name,
                recollection_attendance.status attendance_status
            FROM recollections
            JOIN sections ON sections.id = recollections.section_id
            LEFT JOIN recollection_attendance
              ON recollection_attendance.recollection_id = recollections.id
             AND recollection_attendance.user_id = :attendance_user_id
            WHERE ' . RecollectionService::involvementCondition($userId) . '
            ORDER BY recollections.date DESC, recollections.start_time DESC', ['attendance_user_id' => $userId]);
    }

    private static function compliance(array $user): array
    {
        $pdo = Database::connection();
        $userId = (int) $user['id'];
        $isTeacherView = self::hasRole($user, 'Teacher');
        if (self::hasRole($user, 'Student') && !$isTeacherView) {
            return [
                'complianceRows' => [ComplianceService::forStudent($userId)],
                'subjectComplianceRows' => ComplianceService::bySubjectForStudent($userId),
                'compliancePagination' => null,
                'isTeacherCompliance' => false,
                'isStudentCompliance' => true,
                'programs' => [],
                'sections' => [],
                'filters' => ['q' => '', 'program_id' => 0, 'section_id' => 0],
            ];
        }

        $query = trim((string) ($_GET['q'] ?? ''));
        $sectionId = (int) ($_GET['section_id'] ?? 0) ?: null;
        $programId = (int) ($_GET['program_id'] ?? 0) ?: null;

        $total = $isTeacherView
            ? ComplianceService::countForTeacher($userId, $sectionId, $query)
            : ComplianceService::count($sectionId, $programId, $query);
        $perPage = 50;
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) ($_GET['compliance_page'] ?? 1)), $pages);
        $offset = ($page - 1) * $perPage;

        return [
            'complianceRows' => $isTeacherView
                ? ComplianceService::forTeacher($userId, $sectionId, $query, $perPage, $offset)
                : ComplianceService::all($sectionId, $programId, $query, $perPage, $offset),
            'compliancePagination' => [
                'total' => $total,
                'page' => $page,
                'pages' => $pages,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => min($offset + $perPage, $total),
            ],
            'isTeacherCompliance' => $isTeacherView,
            'isStudentCompliance' => false,
            'programs' => $pdo->query('SELECT * FROM programs WHERE status = "active" ORDER BY name')->fetchAll(),
            'sections' => $isTeacherView
                ? $pdo->query("SELECT DISTINCT sections.* FROM teacher_assignments JOIN class_offerings ON class_offerings.id = teacher_assignments.class_offering_id JOIN sections ON sections.id = class_offerings.section_id WHERE teacher_assignments.teacher_id = {$userId} AND sections.status = 'active' ORDER BY sections.name")->fetchAll()
                : $pdo->query('SELECT * FROM sections WHERE status = "active" ORDER BY name')->fetchAll(),
            'filters' => [
                'q' => $query,
                'program_id' => $programId ?? 0,
                'section_id' => $sectionId ?? 0,
            ],
        ];
    }

    private static function all(PDO $pdo, string $sql, array $params = []): array
    {
        $statement = $pdo->prepare($sql); $statement->execute($params); return $statement->fetchAll();
    }

    private static function single(PDO $pdo, string $sql, array $params = []): ?array
    {
        $statement = $pdo->prepare($sql); $statement->execute($params); return $statement->fetch() ?: null;
    }

    private static function hasRole(array $user, string $role): bool
    {
        return in_array($role, $user['role_names'] ?? [$user['role_name']], true);
    }
}
