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
use App\Services\AcademicManagementService;
use App\Services\AuditService;
use App\Services\AuditTrailService;
use App\Services\ClassRosterService;
use App\Services\ComplianceService;
use App\Services\DashboardService;
use App\Services\DeletionConflictService;
use App\Services\DirectoryProfileService;
use App\Services\EventService;
use App\Services\GradeService;
use App\Services\MessageService;
use App\Services\RecollectionService;
use App\Services\RoomService;
use App\Services\UserService;
use App\Services\UserPreferenceService;
use App\Services\ViolationService;
use DomainException;
use App\Exceptions\DeletionConflictException;
use PDO;
use Throwable;

final class AdminController
{
    private const PAGE_PERMISSIONS = [
        'dashboard' => null, 'schedule' => 'view_schedule', 'users' => 'manage_users', 'academics' => 'manage_academic_structure',
        'rooms' => 'manage_rooms', 'attendance' => 'manage_attendance', 'events' => 'manage_events',
        'recollections' => 'manage_recollections', 'compliance' => Auth::COMPLIANCE_PERMISSIONS, 'grades' => 'manage_grades',
        'messages' => 'send_messages', 'audit-trail' => 'view_audit_logs', 'analytics' => 'view_analytics', 'reports' => ['manage_reports', 'view_audit_logs'], 'profile' => null, 'directory_profile' => null, 'class-roster' => null, 'settings' => null,
    ];

    private const PAGE_ACTIONS = [
        'users' => ['create_user', 'create_role', 'update_role_metadata', 'delete_role', 'update_role_permissions'],
        'academics' => ['create_course', 'create_section', 'toggle_course', 'toggle_section', 'create_offering', 'update_department_days_off', 'update_academic_calendar'],
        'rooms' => ['create_room', 'create_device', 'toggle_room', 'toggle_device'],
        'attendance' => ['rfid_class_scan', 'manual_attendance', 'finalize_attendance', 'update_attendance_settings', 'update_grading_settings', 'update_department_days_off', 'update_academic_calendar'],
        'events' => ['create_event', 'event_scan', 'manual_event_attendance', 'set_event_status', 'cancel_event'],
        'recollections' => ['create_recollection', 'recollection_scan', 'manual_recollection_attendance', 'set_recollection_status', 'cancel_recollection'],
        'grades' => ['save_grade', 'reopen_grade', 'update_grading_settings'],
        'messages' => ['send_message'],
        'compliance' => ['issue_violation'],
        'settings' => ['update_settings'],
    ];

    public static function handle(string $page): void
    {
        $user = Auth::requireLogin();
        if (!Auth::usesAdminArea()) {
            // Preserve academic bookmarks without forwarding administrative writes.
            if ($_SERVER['REQUEST_METHOD'] === 'GET' && Auth::hasAnyRole(['Teacher', 'Student']) && in_array($page, ['dashboard','schedule','attendance','grades','events','recollections','compliance','analytics','messages','settings','profile','directory_profile','class-roster'], true)) {
                $query = $_GET;
                $query['page'] = $page;
                redirect('/index/?' . http_build_query($query));
            }
            http_response_code(403);
            require BASE_PATH . '/templates/errors/403.php';
            exit;
        }

        $page = array_key_exists($page, self::PAGE_PERMISSIONS) ? $page : 'dashboard';
        Auth::requireAnyPermission(PermissionRegistry::pagePermissions($page, true, (int)($_GET['id']??$_GET['user_id']??0)));
        if ($page === 'profile' && (int)($_GET['id']??$_GET['user_id']??0) < 1) {
            redirect(PermissionRegistry::showOwnProfile() ? '/index/?page=profile' : '/admin/?page=settings');
        }

        // Preserve old report links without keeping a second reporting workspace.
        if ($page === 'reports') {
            $query = $_GET;
            $query['page'] = 'compliance';
            if (isset($query['report_page'])) {
                $query['compliance_page'] = $query['report_page'];
                unset($query['report_page']);
            }
            if (isset($query['audit_q']) || isset($query['audit_entity']) || isset($query['audit_page'])) $query['tab'] = 'audit';
            redirect('/admin/?' . http_build_query($query));
        }

        if ($page === 'compliance' && ($_GET['export'] ?? '') === 'compliance_csv') {
            Auth::requirePermission('manage_reports');
            ComplianceService::exportCsvStream(
                (int) ($_GET['section_id'] ?? 0) ?: null,
                (int) ($_GET['program_id'] ?? 0) ?: null,
                mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100)
            );
        }
        if ($page === 'compliance' && $_SERVER['REQUEST_METHOD'] === 'GET' && (($_GET['tab'] ?? '') === 'audit' || (!isset($_GET['tab']) && !isset($_GET['partial']) && !Auth::canAny(Auth::STUDENT_COMPLIANCE_PERMISSIONS) && Auth::can('view_audit_logs')))) {
            Auth::requirePermission('view_audit_logs');
            redirect('/admin/?' . http_build_query(['page'=>'audit-trail','q'=>$_GET['audit_q']??'','entity'=>$_GET['audit_entity']??'']));
        }
        if ($page === 'audit-trail' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405); header('Allow: GET'); echo 'Audit history is read-only.'; return;
        }
        if ($page === 'audit-trail') header('Cache-Control: no-store');
        if ($page === 'compliance' && (($_GET['partial'] ?? '') === 'compliance' || $_SERVER['REQUEST_METHOD'] === 'POST')) {
            Auth::requireAnyPermission(Auth::STUDENT_COMPLIANCE_PERMISSIONS);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::handlePost($page, (int) $user['id']);
        }

        // The timetable keeps enrollment/assignment scope in either layout.
        if ($page === 'schedule') {
            PortalController::handle('schedule');
            return;
        }

        // Campus management grants may route teachers/students through /admin/.
        // Their dashboard still uses their personal academic relationships.
        if ($page === 'dashboard' && in_array($user['role_name'], ['Teacher', 'Student'], true)) {
            PortalController::handle('dashboard');
            return;
        }

        $data = self::dataFor($page);
        $data['title'] = $page === 'audit-trail' ? 'Audit Trail' : ucwords(str_replace('_', ' ', $page));
        $data['active'] = $page;
        if ($page === 'users' && ($_GET['partial'] ?? '') === 'accounts') {
            Auth::requirePermission('manage_users');
            header('Content-Type: text/html; charset=UTF-8');
            View::partial('admin/partials/user_accounts', $data);
            return;
        }
        if ($page === 'compliance' && ($_GET['partial'] ?? '') === 'compliance') {
            header('Content-Type: text/html; charset=UTF-8');
            View::partial('admin/partials/compliance_table', $data);
            return;
        }
        View::render('admin/' . $page, $data);
    }

    private static function handlePost(string $page, int $actorId): never
    {
        try {
            Csrf::verify();
            $action = (string) ($_POST['action'] ?? '');
            if (!in_array($action, self::PAGE_ACTIONS[$page] ?? [], true)) {
                throw new DomainException('That action is not available on this page.');
            }
            PermissionRegistry::requireAction($action);
            $pdo = Database::connection();

            switch ($action) {
                case 'create_user':
                    foreach (['school_id', 'first_name', 'last_name', 'email', 'password', 'role_id'] as $field) {
                        if (trim((string) ($_POST[$field] ?? '')) === '') {
                            throw new DomainException('Complete all required user fields.');
                        }
                    }
                    UserService::create($_POST, $actorId, $_FILES['profile_image'] ?? null);
                    flash('success', 'User account created.');
                    break;
                case 'update_role_permissions':
                    if (!Auth::can('manage_roles')) throw new DomainException('You do not have permission to manage roles.');
                    $roleId = (int) $_POST['role_id'];
                    if ($roleId === 1) throw new DomainException('Administrator permissions are fixed to full access.');
                    $rawPermissions = $_POST['permission_ids'] ?? [];
                    if (!is_array($rawPermissions)) throw new DomainException('Invalid permission selection.');
                    $permissionIds = array_values(array_unique(array_filter(array_map('intval', $rawPermissions), static fn(int $id): bool => $id > 0)));
                    $validIds = array_map('intval', $pdo->query('SELECT id FROM permissions')->fetchAll(PDO::FETCH_COLUMN));
                    if (array_diff($permissionIds, $validIds)) throw new DomainException('One of the selected permissions is no longer available. Refresh the page.');
                    $roleCheck = $pdo->prepare('SELECT 1 FROM roles WHERE id=?'); $roleCheck->execute([$roleId]);
                    if (!$roleCheck->fetchColumn()) throw new DomainException('That role is no longer available.');
                    $pdo->beginTransaction();
                    $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :role')->execute(['role' => $roleId]);
                    $insert = $pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (:role, :permission)');
                    foreach ($permissionIds as $permissionId) $insert->execute(['role' => $roleId, 'permission' => $permissionId]);
                    $pdo->commit();
                    AuditService::log($actorId, 'update_role_permissions', 'role', $roleId, implode(',', $permissionIds));
                    flash('success', 'Role permissions updated.');
                    break;
                case 'update_role_metadata':
                    if (!Auth::can('manage_roles')) throw new DomainException('You do not have permission to manage roles.');
                    $roleId = (int) ($_POST['role_id'] ?? 0);
                    $roleName = normalize_title_case($_POST['name'] ?? '');
                    $roleDescription = trim((string) ($_POST['description'] ?? ''));
                    if ($roleId === 1) throw new DomainException('The Administrator role metadata is fixed.');
                    if ($roleId < 1 || mb_strlen($roleName) < 2 || mb_strlen($roleName) > 80 || mb_strlen($roleDescription) > 255) {
                        throw new DomainException('Enter a role name between 2 and 80 characters and a description up to 255 characters.');
                    }
                    $duplicateRole = $pdo->prepare('SELECT 1 FROM roles WHERE LOWER(name) = LOWER(:name) AND id <> :id LIMIT 1');
                    $duplicateRole->execute(['name' => $roleName, 'id' => $roleId]);
                    if ($duplicateRole->fetchColumn()) throw new DomainException('A role with that name already exists.');
                    $roleUpdate = $pdo->prepare('UPDATE roles SET name = :name, description = :description WHERE id = :id');
                    $roleUpdate->execute(['name' => $roleName, 'description' => $roleDescription !== '' ? $roleDescription : null, 'id' => $roleId]);
                    if ($roleUpdate->rowCount() === 0) {
                        $roleExists = $pdo->prepare('SELECT 1 FROM roles WHERE id = :id');
                        $roleExists->execute(['id' => $roleId]);
                        if (!$roleExists->fetchColumn()) throw new DomainException('The selected role could not be found.');
                    }
                    AuditService::log($actorId, 'update_role_metadata', 'role', $roleId, $roleName);
                    flash('success', 'Role details updated.');
                    break;
                case 'delete_role':
                    if (!Auth::can('manage_roles')) throw new DomainException('You do not have permission to delete roles.');
                    $deletedRole = \App\Services\BulkDeletionService::deleteRole((int) ($_POST['role_id'] ?? 0), $actorId);
                    flash('success', 'Role ' . $deletedRole['label'] . ' deleted.');
                    break;
                case 'create_role':
                    if (!Auth::can('manage_roles')) throw new DomainException('You do not have permission to create roles.');
                    $roleName = normalize_title_case($_POST['name'] ?? '');
                    $roleDescription = trim((string) ($_POST['description'] ?? ''));
                    if (mb_strlen($roleName) < 2 || mb_strlen($roleName) > 80) {
                        throw new DomainException('Role name must contain between 2 and 80 characters.');
                    }
                    if (mb_strlen($roleDescription) > 255) {
                        throw new DomainException('Role description cannot exceed 255 characters.');
                    }
                    $duplicateRole = $pdo->prepare('SELECT 1 FROM roles WHERE LOWER(name) = LOWER(:name) LIMIT 1');
                    $duplicateRole->execute(['name' => $roleName]);
                    if ($duplicateRole->fetchColumn()) {
                        throw new DomainException('A role with that name already exists.');
                    }
                    $pdo->prepare('INSERT INTO roles (name, description) VALUES (:name, :description)')
                        ->execute(['name' => $roleName, 'description' => $roleDescription !== '' ? $roleDescription : null]);
                    $newRoleId = (int) $pdo->lastInsertId();
                    AuditService::log($actorId, 'create_role', 'role', $newRoleId, $roleName);
                    flash('success', "Role {$roleName} created. Configure its permissions below.");
                    redirect('/admin/?page=users&permission_role_id=' . $newRoleId . '#users-role-permissions');
                case 'create_room':
                    $pdo->prepare('INSERT INTO rooms (room_code, room_name, building, floor) VALUES (:code, :name, :building, :floor)')
                        ->execute([
                            'code' => strtoupper(trim((string) ($_POST['room_code'] ?? ''))),
                            'name' => normalize_title_case($_POST['room_name'] ?? ''),
                            'building' => normalize_title_case($_POST['building'] ?? ''),
                            'floor' => normalize_title_case($_POST['floor'] ?? ''),
                        ]);
                    AuditService::log($actorId, 'create_room', 'room', $pdo->lastInsertId(), $_POST['room_code']);
                    flash('success', 'Room created.');
                    break;
                case 'create_device':
                    $created = RoomService::createDevice($_POST, $actorId);
                    $_SESSION['_scanner_credentials'] = [
                        'device_code' => $created['device']['device_code'],
                        'room_code' => $created['device']['room_code'] ?? null,
                        'api_key' => $created['api_key'],
                    ];
                    flash('success', 'Scanner created and ready to configure.');
                    break;
                case 'toggle_room':
                    $pdo->prepare('UPDATE rooms SET status = IF(status = "active", "inactive", "active") WHERE id = :id')->execute(['id' => $_POST['room_id']]);
                    AuditService::log($actorId, 'toggle_room', 'room', (int) $_POST['room_id']);
                    flash('success', 'Room status updated.');
                    break;
                case 'toggle_device':
                    $pdo->prepare('UPDATE devices SET status = IF(status = "active", "inactive", "active") WHERE id = :id')->execute(['id' => $_POST['device_id']]);
                    AuditService::log($actorId, 'toggle_device', 'device', (int) $_POST['device_id']);
                    flash('success', 'Device status updated.');
                    break;
                case 'create_course':
                    $pdo->prepare('INSERT INTO courses (department_id, course_code, title, units) VALUES (:department, :code, :title, :units)')
                        ->execute([
                            'department' => $_POST['department_id'],
                            'code' => strtoupper(trim((string) ($_POST['course_code'] ?? ''))),
                            'title' => normalize_title_case($_POST['title'] ?? ''),
                            'units' => $_POST['units'],
                        ]);
                    AuditService::log($actorId, 'create_course', 'course', $pdo->lastInsertId(), $_POST['course_code']);
                    flash('success', 'Course created.');
                    break;
                case 'create_section':
                    $programId = filter_var($_POST['program_id'] ?? null, FILTER_VALIDATE_INT);
                    $yearLevel = filter_var($_POST['year_level'] ?? null, FILTER_VALIDATE_INT);
                    $sectionCode = strtoupper(trim((string) ($_POST['section_code'] ?? '')));
                    if (!$programId || !$yearLevel || $yearLevel < 1 || $yearLevel > 4) {
                        throw new DomainException('Select a program and a year level from 1 to 4.');
                    }
                    if (!preg_match('/^[A-Z][A-Z0-9]{0,9}$/', $sectionCode)) {
                        throw new DomainException('Section must start with a letter and contain only letters or numbers.');
                    }

                    $programStatement = $pdo->prepare('SELECT code FROM programs WHERE id = :id AND status = "active"');
                    $programStatement->execute(['id' => $programId]);
                    $programCode = $programStatement->fetchColumn();
                    if ($programCode === false) {
                        throw new DomainException('Select an active program.');
                    }

                    $sectionName = strtoupper((string) $programCode) . ' ' . $yearLevel . $sectionCode;
                    $duplicateStatement = $pdo->prepare('SELECT 1 FROM sections WHERE program_id = :program AND year_level = :year_level AND section_code = :section_code LIMIT 1');
                    $duplicateStatement->execute(['program' => $programId, 'year_level' => $yearLevel, 'section_code' => $sectionCode]);
                    if ($duplicateStatement->fetchColumn()) {
                        throw new DomainException("Section {$sectionName} already exists.");
                    }

                    $pdo->prepare('INSERT INTO sections (program_id, name, year_level, section_code) VALUES (:program, :name, :year_level, :section_code)')
                        ->execute(['program' => $programId, 'name' => $sectionName, 'year_level' => $yearLevel, 'section_code' => $sectionCode]);
                    AuditService::log($actorId, 'create_section', 'section', $pdo->lastInsertId(), $sectionName);
                    flash('success', "Section {$sectionName} created.");
                    break;
                case 'toggle_course':
                    $pdo->prepare('UPDATE courses SET status = IF(status = "active", "inactive", "active") WHERE id = :id')->execute(['id' => $_POST['course_id']]);
                    flash('success', 'Course status updated.');
                    break;
                case 'toggle_section':
                    $pdo->prepare('UPDATE sections SET status = IF(status = "active", "inactive", "active") WHERE id = :id')->execute(['id' => $_POST['section_id']]);
                    flash('success', 'Section status updated.');
                    break;
                case 'create_offering':
                    Auth::requirePermission('manage_teacher_assignments');
                    $result = AcademicManagementService::createOffering($_POST, $actorId);
                    flash('success', $result['created']
                        ? 'Class offering created with ' . $result['schedule_count'] . ' meeting schedule' . ($result['schedule_count'] === 1 ? '.' : 's.')
                        : 'Added ' . $result['schedule_count'] . ' meeting schedule' . ($result['schedule_count'] === 1 ? ' to the existing offering.' : 's to the existing offering.'));
                    break;
                case 'update_department_days_off':
                    Auth::requirePermission('manage_academic_calendar');
                    AcademicCalendarService::setDaysOff((int) $_POST['department_id'], $_POST['days_off'] ?? [], $actorId);
                    flash('success', 'Department weekly schedule updated.');
                    break;
                case 'update_academic_calendar':
                    Auth::requirePermission('manage_academic_calendar');
                    $schoolYearId = (int) ($_POST['school_year_id'] ?? 0);
                    $schoolYearStart = trim((string) ($_POST['school_year_starts_on'] ?? ''));
                    $schoolYearEnd = trim((string) ($_POST['school_year_ends_on'] ?? ''));
                    $validDate = static function (string $value): bool {
                        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                        return $date !== false && $date->format('Y-m-d') === $value;
                    };
                    if ($schoolYearId < 1 || !$validDate($schoolYearStart) || !$validDate($schoolYearEnd) || $schoolYearEnd < $schoolYearStart) {
                        throw new DomainException('Select a valid school-year start and end date.');
                    }
                    $semesterTypes = [
                        'first' => 'First Semester',
                        'second' => 'Second Semester',
                        'summer' => 'Summer Class',
                    ];
                    $semesterRows = [];
                    foreach ($semesterTypes as $key => $name) {
                        $startsOn = trim((string) ($_POST['semester_starts_on'][$key] ?? ''));
                        $endsOn = trim((string) ($_POST['semester_ends_on'][$key] ?? ''));
                        $status = (string) ($_POST['semester_status'][$key] ?? 'upcoming');
                        if (!$validDate($startsOn) || !$validDate($endsOn)
                            || $endsOn < $startsOn || $startsOn < $schoolYearStart || $endsOn > $schoolYearEnd
                            || !in_array($status, ['upcoming', 'active', 'closed'], true)) {
                            throw new DomainException("Enter a valid date range and status for {$name}. Dates must be inside the school year.");
                        }
                        $semesterRows[] = compact('name', 'startsOn', 'endsOn', 'status');
                    }
                    if ($semesterRows[0]['endsOn'] >= $semesterRows[1]['startsOn'] || $semesterRows[1]['endsOn'] >= $semesterRows[2]['startsOn']) {
                        throw new DomainException('Semester date ranges must be chronological and must not overlap.');
                    }
                    $pdo->beginTransaction();
                    $schoolYearUpdate = $pdo->prepare('UPDATE school_years SET starts_on = :starts, ends_on = :ends WHERE id = :id');
                    $schoolYearUpdate->execute(['starts' => $schoolYearStart, 'ends' => $schoolYearEnd, 'id' => $schoolYearId]);
                    if ($schoolYearUpdate->rowCount() === 0) {
                        $exists = $pdo->prepare('SELECT 1 FROM school_years WHERE id = :id');
                        $exists->execute(['id' => $schoolYearId]);
                        if (!$exists->fetchColumn()) throw new DomainException('The selected school year does not exist.');
                    }
                    $semesterUpsert = $pdo->prepare(
                        'INSERT INTO semesters (school_year_id, name, starts_on, ends_on, status)
                         VALUES (:year, :name, :starts, :ends, :status)
                         ON DUPLICATE KEY UPDATE starts_on = VALUES(starts_on), ends_on = VALUES(ends_on), status = VALUES(status)'
                    );
                    foreach ($semesterRows as $semester) {
                        $semesterUpsert->execute([
                            'year' => $schoolYearId,
                            'name' => $semester['name'],
                            'starts' => $semester['startsOn'],
                            'ends' => $semester['endsOn'],
                            'status' => $semester['status'],
                        ]);
                    }
                    $pdo->commit();
                    AuditService::log($actorId, 'update_academic_calendar', 'school_year', $schoolYearId, "{$schoolYearStart} to {$schoolYearEnd}");
                    flash('success', 'School year and all three academic terms updated.');
                    break;
                case 'rfid_class_scan':
                    $result = AttendanceService::recordRfidScan((int) $_POST['class_offering_id'], $_POST['uid'], $actorId, $_POST['attendance_date'] ?: null, $_POST['time_in'] ?: null);
                    flash('success', $result['user']['first_name'] . ' recorded as ' . $result['status'] . '.');
                    break;
                case 'manual_attendance':
                    AttendanceService::recordManual((int) $_POST['class_offering_id'], (int) $_POST['student_id'], $_POST['attendance_date'], $_POST['status'], $actorId, $_POST['reason'], $_POST['time_in'] ?? null);
                    flash('success', 'Attendance record saved.');
                    break;
                case 'finalize_attendance':
                    $created = AttendanceService::finalize((int) $_POST['class_offering_id'], $_POST['attendance_date'], $actorId);
                    flash('success', "Attendance finalized; {$created} missing students marked absent.");
                    break;
                case 'update_attendance_settings':
                    if (!Auth::can('manage_attendance_policies')) throw new DomainException('You do not have permission to configure attendance policies.');
                    AttendanceService::updateSettings((int) ($_POST['early_check_in_minutes'] ?? 10), (int) $_POST['grace_period_minutes'], (int) $_POST['absent_after_minutes'], (int) $_POST['tardies_per_absence'], $actorId);
                    flash('success', 'Attendance rules updated.');
                    break;
                case 'create_event':
                    $scannerCredentials = null;
                    EventService::create($_POST, $actorId, $_FILES['banner_image'] ?? null, $_FILES['event_images'] ?? null, $scannerCredentials);
                    if ($scannerCredentials) {
                        $_SESSION['_scanner_credentials'] = [
                            'device_code' => $scannerCredentials['device']['device_code'],
                            'room_code' => $scannerCredentials['device']['room_code'] ?? null,
                            'api_key' => $scannerCredentials['api_key'],
                        ];
                    }
                    flash('success', 'Event created and participants assigned.');
                    break;
                case 'event_scan':
                    $user = EventService::recordRfidScan((int) $_POST['event_id'], $_POST['uid'], $actorId);
                    flash('success', $user['first_name'] . ' checked in to the event.');
                    break;
                case 'manual_event_attendance':
                    EventService::recordManual((int) $_POST['event_id'], (int) $_POST['user_id'], $_POST['status'], $actorId, $_POST['reason']);
                    flash('success', 'Event attendance corrected.');
                    break;
                case 'set_event_status':
                    EventService::setStatus((int) $_POST['event_id'], $_POST['status'], $actorId);
                    flash('success', 'Event status updated.');
                    break;
                case 'cancel_event':
                    EventService::setStatus((int) $_POST['event_id'], 'cancelled', $actorId);
                    flash('success', 'Event cancelled.');
                    break;
                case 'create_recollection':
                    RecollectionService::create($_POST, $actorId);
                    flash('success', 'Recollection or retreat scheduled.');
                    break;
                case 'recollection_scan':
                    $user = RecollectionService::recordRfidScan((int) $_POST['recollection_id'], $_POST['uid'], $actorId);
                    flash('success', $user['first_name'] . ' attendance recorded.');
                    break;
                case 'manual_recollection_attendance':
                    RecollectionService::recordManual((int) $_POST['recollection_id'], (int) $_POST['user_id'], $_POST['status'], $actorId, $_POST['reason']);
                    flash('success', 'Activity attendance corrected.');
                    break;
                case 'set_recollection_status':
                    RecollectionService::setStatus((int) $_POST['recollection_id'], $_POST['status'], $actorId);
                    flash('success', 'Activity status updated.');
                    break;
                case 'cancel_recollection':
                    RecollectionService::setStatus((int) $_POST['recollection_id'], 'cancelled', $actorId);
                    flash('success', 'Activity cancelled.');
                    break;
                case 'save_grade':
                    GradeService::save($_POST, $actorId);
                    flash('success', 'Grade saved.');
                    break;
                case 'reopen_grade':
                    GradeService::reopen((int) $_POST['grade_id'], $actorId);
                    flash('success', 'Grade reopened for editing.');
                    break;
                case 'update_grading_settings':
                    Auth::requirePermission('manage_grading_settings');
                    GradeService::updateSettings($_POST, $actorId);
                    flash('success', 'Grading system policies updated successfully.');
                    break;
                case 'send_message':
                    MessageService::send($_POST, $actorId, $_FILES['attachments'] ?? null);
                    flash('success', 'Message sent.');
                    break;
                case 'issue_violation':
                    if (!Auth::can('manage_compliance')) throw new DomainException('You do not have permission to issue violations.');
                    ViolationService::issue($_POST, $actorId, $_FILES['evidence'] ?? null);
                    flash('success', 'Violation issued and added to the student’s compliance record.');
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
        } catch (DomainException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof DeletionConflictException && str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')) {
                http_response_code(409);
                header('Content-Type: application/json; charset=UTF-8');
                echo json_encode($exception->payload(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                exit;
            }
            flash('danger', $exception->getMessage());
        } catch (\PDOException $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Admin database action failed: ' . $exception->getMessage());
            flash('danger', 'The action could not be completed.');
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            flash('danger', config('app')['debug'] ? $exception->getMessage() : 'The action could not be completed.');
        }
        redirect('/admin/' . ($page === 'dashboard' ? '' : '?page=' . $page));
    }

    private static function dataFor(string $page): array
    {
        $pdo = Database::connection();
        return match ($page) {
            'dashboard' => DashboardService::admin(),
            'users' => self::usersData($pdo),
            'academics' => [
                'courses' => $pdo->query('SELECT courses.*, departments.code department_code FROM courses LEFT JOIN departments ON departments.id = courses.department_id ORDER BY course_code')->fetchAll(),
                'offerings' => $pdo->query('SELECT class_offerings.id, class_offerings.status, class_offerings.component_type, courses.course_code, courses.title, sections.name section_name, semesters.name semester_name,
                        GROUP_CONCAT(DISTINCT CONCAT(users.first_name, " ", users.last_name) ORDER BY users.last_name SEPARATOR ", ") teacher_name,
                        GROUP_CONCAT(DISTINCT CONCAT(class_schedules.day_of_week, " ", TIME_FORMAT(class_schedules.start_time, "%h:%i %p"), "–", TIME_FORMAT(class_schedules.end_time, "%h:%i %p")) ORDER BY FIELD(class_schedules.day_of_week,"Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"), class_schedules.start_time SEPARATOR "||") schedule_summary,
                        GROUP_CONCAT(DISTINCT COALESCE(rooms.room_code,"TBA") ORDER BY COALESCE(rooms.room_code,"TBA") SEPARATOR ", ") room_summary
                    FROM class_offerings JOIN courses ON courses.id=class_offerings.course_id JOIN sections ON sections.id=class_offerings.section_id JOIN semesters ON semesters.id=class_offerings.semester_id
                    LEFT JOIN teacher_assignments ON teacher_assignments.class_offering_id=class_offerings.id LEFT JOIN users ON users.id=teacher_assignments.teacher_id
                    LEFT JOIN class_schedules ON class_schedules.class_offering_id=class_offerings.id LEFT JOIN rooms ON rooms.id=COALESCE(class_schedules.room_id,class_offerings.room_id)
                    GROUP BY class_offerings.id,class_offerings.status,class_offerings.component_type,courses.course_code,courses.title,sections.name,semesters.name
                    ORDER BY courses.course_code,class_offerings.component_type')->fetchAll(),
                'departments' => $pdo->query('SELECT * FROM departments ORDER BY code')->fetchAll(),
                'programs' => $pdo->query('SELECT * FROM programs ORDER BY name')->fetchAll(),
                'sections' => $pdo->query('SELECT * FROM sections ORDER BY name')->fetchAll(),
                'semesters' => $pdo->query('SELECT semesters.*, school_years.name school_year FROM semesters JOIN school_years ON school_years.id = semesters.school_year_id ORDER BY starts_on DESC')->fetchAll(),
                'schoolYears' => $pdo->query('SELECT * FROM school_years ORDER BY starts_on DESC')->fetchAll(),
                'rooms' => $pdo->query('SELECT * FROM rooms WHERE status = "active" ORDER BY room_code')->fetchAll(),
                'departmentDayPolicies' => AcademicCalendarService::policies(),
            ],
            'rooms' => [
                'rooms' => $pdo->query('SELECT rooms.*, (SELECT GROUP_CONCAT(devices.device_code ORDER BY devices.device_code SEPARATOR ", ") FROM devices WHERE devices.room_id = rooms.id) device_code, (SELECT MAX(devices.last_seen_at) FROM devices WHERE devices.room_id = rooms.id) last_seen_at FROM rooms ORDER BY rooms.room_code')->fetchAll(),
                'devices' => $pdo->query('SELECT devices.*, rooms.room_code FROM devices LEFT JOIN rooms ON rooms.id = devices.room_id ORDER BY devices.device_code')->fetchAll(),
            ],
            'attendance' => [
                'offerings' => Auth::can('manage_attendance') ? AttendanceService::offeringSelectorRows((int) Auth::user()['id']) : [],
                'records' => [],
                'settings' => $pdo->query('SELECT * FROM attendance_settings WHERE id = 1')->fetch(),
                'departments' => $pdo->query('SELECT * FROM departments ORDER BY code')->fetchAll(),
                'departmentDayPolicies' => AcademicCalendarService::policies(),
                'semesters' => $pdo->query('SELECT semesters.*, school_years.name school_year FROM semesters JOIN school_years ON school_years.id = semesters.school_year_id ORDER BY starts_on DESC')->fetchAll(),
                'schoolYears' => $pdo->query('SELECT * FROM school_years ORDER BY starts_on DESC')->fetchAll(),
            ],
            'events' => [
                'events' => $pdo->query('SELECT events.*,
                    (SELECT target_type FROM event_target_groups WHERE event_id = events.id LIMIT 1) target_type,
                    (SELECT target_id FROM event_target_groups WHERE event_id = events.id LIMIT 1) target_id,
                    (SELECT GROUP_CONCAT(device_id ORDER BY device_id) FROM event_scanners WHERE event_id = events.id) scanner_ids,
                    COUNT(DISTINCT event_participants.id) participant_count,
                    COUNT(DISTINCT CASE WHEN event_attendance.status IN ("Attended", "Late") THEN event_attendance.id END) attended_count
                    FROM events
                    LEFT JOIN event_participants ON event_participants.event_id = events.id
                    LEFT JOIN event_attendance ON event_attendance.event_id = events.id
                    WHERE ' . \App\Services\EventAccessService::manageCondition((int)Auth::user()['id']) . ' GROUP BY events.id ORDER BY event_date DESC')->fetchAll(),
                'sections' => $pdo->query('SELECT * FROM sections WHERE status = "active" ORDER BY name')->fetchAll(),
                'devices' => $pdo->query('SELECT devices.id, devices.device_code, devices.name, devices.room_id, rooms.room_code FROM devices LEFT JOIN rooms ON rooms.id = devices.room_id WHERE devices.status = "active" ORDER BY rooms.room_code, devices.device_code')->fetchAll(),
                'rooms' => $pdo->query('SELECT id, room_code, room_name FROM rooms WHERE status = "active" ORDER BY room_code')->fetchAll(),
            ],
            'recollections' => [
                'recollections' => $pdo->query('SELECT recollections.*, sections.name section_name,
                    COUNT(DISTINCT recollection_participants.id) participant_count,
                    COUNT(DISTINCT CASE WHEN recollection_attendance.status IN ("Attended", "Late") THEN recollection_attendance.id END) attended_count,
                    (SELECT GROUP_CONCAT(roles.name ORDER BY roles.name SEPARATOR ", ") FROM recollection_target_roles JOIN roles ON roles.id = recollection_target_roles.role_id WHERE recollection_target_roles.recollection_id = recollections.id) target_role_names,
                    (SELECT COUNT(*) FROM recollection_target_users WHERE recollection_target_users.recollection_id = recollections.id) target_user_count
                    FROM recollections JOIN sections ON sections.id = recollections.section_id
                    LEFT JOIN recollection_participants ON recollection_participants.recollection_id = recollections.id
                    LEFT JOIN recollection_attendance ON recollection_attendance.recollection_id = recollections.id
                    GROUP BY recollections.id ORDER BY date DESC')->fetchAll(),
                'sections' => $pdo->query('SELECT * FROM sections WHERE status = "active" ORDER BY name')->fetchAll(),
                'roles' => $pdo->query('SELECT id, name FROM roles ORDER BY name')->fetchAll(),
            ],
            'compliance' => self::complianceData($pdo),
            'grades' => [
                'gradeRows' => [],
                'offerings' => Auth::can('manage_grades') ? GradeService::offeringSelectorRows((int) Auth::user()['id']) : [],
                'programs' => $pdo->query('SELECT * FROM programs WHERE status = "active" ORDER BY name')->fetchAll(),
                'sections' => $pdo->query('SELECT * FROM sections WHERE status = "active" ORDER BY name')->fetchAll(),
                'gradingSettings' => GradeService::settings(),
            ],
            'messages' => [
                'messages' => Auth::can('view_messages') ? $pdo->query('SELECT messages.*, CONCAT(users.first_name, " ", users.last_name) sender_name, COUNT(message_recipients.id) recipient_count FROM messages JOIN users ON users.id = messages.sender_id LEFT JOIN message_recipients ON message_recipients.message_id = messages.id WHERE messages.sender_id=' . (int)Auth::user()['id'] . ' GROUP BY messages.id ORDER BY messages.created_at DESC')->fetchAll() : [],
                'roles' => $pdo->query('SELECT * FROM roles ORDER BY name')->fetchAll(),
                'sections' => $pdo->query('SELECT * FROM sections WHERE status = "active" ORDER BY name')->fetchAll(),
            ],
            'settings' => ['settings' => UserPreferenceService::get((int) Auth::user()['id']), 'user' => Auth::user()],
            'analytics' => [],
            'audit-trail' => AuditTrailService::workspace(),
            'profile', 'directory_profile' => [
                'targetId' => (int) ($_GET['id'] ?? $_GET['user_id'] ?? 0),
                'viewedProfile' => ((int) ($_GET['id'] ?? $_GET['user_id'] ?? 0)) > 0
                    ? DirectoryProfileService::profile(Auth::user(), (int) ($_GET['id'] ?? $_GET['user_id'] ?? 0))
                    : null,
                'roles' => $pdo->query('SELECT * FROM roles ORDER BY name')->fetchAll(),
                'departments' => $pdo->query('SELECT * FROM departments WHERE status = "active" ORDER BY code')->fetchAll(),
                'programs' => $pdo->query('SELECT * FROM programs WHERE status = "active" ORDER BY code')->fetchAll(),
                'sections' => $pdo->query('SELECT * FROM sections WHERE status = "active" ORDER BY name')->fetchAll(),
            ],
            'class-roster' => [
                'rosterSubject' => ClassRosterService::subject((int) ($_GET['offering_id'] ?? 0), (int) Auth::user()['id']),
            ],
            default => [],
        };
    }

    private static function offerings(PDO $pdo): array
    {
        return $pdo->query(
            'SELECT class_offerings.id, courses.id course_id, courses.course_code, courses.title course_title,
                    programs.id program_id, programs.code program_code, programs.name program_name,
                    sections.id section_id, sections.name section_name, sections.year_level, sections.section_code,
                    school_years.id school_year_id, school_years.name school_year_name,
                    semesters.id semester_id, semesters.name semester_name
             FROM class_offerings
             JOIN courses ON courses.id = class_offerings.course_id
             JOIN sections ON sections.id = class_offerings.section_id
             JOIN programs ON programs.id = sections.program_id
             JOIN semesters ON semesters.id = class_offerings.semester_id
             JOIN school_years ON school_years.id = semesters.school_year_id
             WHERE class_offerings.status = "active"
             ORDER BY courses.course_code, programs.code, sections.year_level, sections.section_code, school_years.starts_on DESC, semesters.starts_on'
        )->fetchAll();
    }

    private static function usersData(PDO $pdo): array
    {
        $filters = [
            'q' => trim((string) ($_GET['q'] ?? '')),
            'role_id' => (int) ($_GET['role_id'] ?? 0),
            'department_id' => (int) ($_GET['department_id'] ?? 0),
            'program_id' => (int) ($_GET['program_id'] ?? 0),
            'section_id' => (int) ($_GET['section_id'] ?? 0),
            'status' => in_array($_GET['status'] ?? '', ['active', 'inactive'], true) ? $_GET['status'] : '',
            'user_page' => max(1, (int) ($_GET['user_page'] ?? 1)),
        ];
        if ($filters['department_id'] > 0 && $filters['program_id'] > 0) {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM programs WHERE id = :program_id AND department_id = :department_id AND status = "active"');
            $statement->execute(['program_id' => $filters['program_id'], 'department_id' => $filters['department_id']]);
            if (!(bool) $statement->fetchColumn()) {
                $filters['program_id'] = 0;
                $filters['section_id'] = 0;
            }
        }
        if ($filters['program_id'] > 0 && $filters['section_id'] > 0) {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM sections WHERE id = :section_id AND program_id = :program_id AND status = "active"');
            $statement->execute(['section_id' => $filters['section_id'], 'program_id' => $filters['program_id']]);
            if (!(bool) $statement->fetchColumn()) {
                $filters['section_id'] = 0;
            }
        }
        $where = [];
        $params = [];
        if ($filters['q'] !== '') {
            $query = mb_substr($filters['q'], 0, 100) . '%';
            $where[] = '(users.school_id LIKE :query_school_id OR users.email LIKE :query_email OR users.first_name LIKE :query_first_name OR users.last_name LIKE :query_last_name OR CONCAT_WS(" ", users.first_name, users.middle_name, users.last_name) LIKE :query_full_name)';
            $params['query_school_id'] = $query;
            $params['query_email'] = $query;
            $params['query_first_name'] = $query;
            $params['query_last_name'] = $query;
            $params['query_full_name'] = $query;
        }
        if ($filters['role_id'] > 0) {
            $where[] = 'EXISTS(SELECT 1 FROM user_roles filtered_roles WHERE filtered_roles.user_id = users.id AND filtered_roles.role_id = :role_id)';
            $params['role_id'] = $filters['role_id'];
        }
        foreach (['department_id', 'program_id', 'section_id'] as $field) {
            if ($filters[$field] > 0) {
                $where[] = "users.{$field} = :{$field}";
                $params[$field] = $filters[$field];
            }
        }
        if ($filters['status'] !== '') {
            $where[] = 'users.status = :status';
            $params['status'] = $filters['status'];
        }
        $fromSql = ' FROM users
                JOIN roles ON roles.id = users.role_id
                LEFT JOIN departments ON departments.id = users.department_id
                LEFT JOIN programs ON programs.id = users.program_id
                LEFT JOIN sections ON sections.id = users.section_id';
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $countStatement = $pdo->prepare('SELECT COUNT(*)' . $fromSql . $whereSql);
        $countStatement->execute($params);
        $total = (int) $countStatement->fetchColumn();
        $perPage = 20;
        $pages = max(1, (int) ceil($total / $perPage));
        $filters['user_page'] = min($filters['user_page'], $pages);
        $offset = ($filters['user_page'] - 1) * $perPage;

        $sql = 'SELECT users.*, roles.name role_name, departments.name department_name, programs.name program_name,
                       sections.name section_name, rfid_cards.uid,
                       (SELECT GROUP_CONCAT(assigned_roles.name ORDER BY (assigned_user_roles.role_id = users.role_id) DESC, assigned_roles.name SEPARATOR "||")
                        FROM user_roles assigned_user_roles JOIN roles assigned_roles ON assigned_roles.id = assigned_user_roles.role_id
                        WHERE assigned_user_roles.user_id = users.id) role_names_csv
                ' . $fromSql . '
                LEFT JOIN rfid_cards ON rfid_cards.user_id = users.id AND rfid_cards.status = "active"'
             . $whereSql
             . " ORDER BY users.created_at DESC LIMIT {$perPage} OFFSET {$offset}";
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $users = array_map(static function (array $person): array {
            $person['role_names_label'] = str_replace('||', ', ', (string) ($person['role_names_csv'] ?? $person['role_name']));
            unset($person['role_names_csv']);
            return $person;
        }, $statement->fetchAll());

        $result = [
            'users' => $users,
            'filters' => $filters,
            'userPagination' => [
                'total' => $total,
                'page' => $filters['user_page'],
                'pages' => $pages,
                'from' => $total === 0 ? 0 : $offset + 1,
                'to' => min($offset + $perPage, $total),
            ],
        ];
        if (($_GET['partial'] ?? '') === 'accounts') {
            return $result;
        }

        return $result + [
            'roles' => $pdo->query('SELECT * FROM roles ORDER BY name')->fetchAll(),
            'departments' => $pdo->query('SELECT * FROM departments WHERE status = "active" ORDER BY code')->fetchAll(),
            'programs' => $pdo->query('SELECT * FROM programs WHERE status = "active" ORDER BY name')->fetchAll(),
            'sections' => $pdo->query('SELECT * FROM sections WHERE status = "active" ORDER BY name')->fetchAll(),
            'permissions' => $pdo->query('SELECT * FROM permissions ORDER BY code')->fetchAll(),
            'rolePermissionMap' => $pdo->query('SELECT role_id, permission_id FROM role_permissions')->fetchAll(PDO::FETCH_GROUP | PDO::FETCH_COLUMN),
            'rfidDevices' => $pdo->query('SELECT devices.id, devices.device_code, devices.name, rooms.room_code
                FROM devices LEFT JOIN rooms ON rooms.id = devices.room_id
                WHERE devices.status = "active" AND devices.api_key_hash IS NOT NULL
                ORDER BY devices.device_code')->fetchAll(),
        ];
    }

    private static function complianceData(PDO $pdo): array
    {
        $tabs = [];
        if (Auth::canAny(Auth::STUDENT_COMPLIANCE_PERMISSIONS)) $tabs['students'] = 'Student compliance';
        if (Auth::can('manage_reports')) {
            $tabs['classes'] = 'Class attendance';
            $tabs['events'] = 'Event attendance';
        }
        $tab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : (string) array_key_first($tabs);
        $tabPermissions = ['students' => Auth::STUDENT_COMPLIANCE_PERMISSIONS, 'classes' => ['manage_reports'], 'events' => ['manage_reports']];
        if (isset($tabPermissions[$tab])) Auth::requireAnyPermission($tabPermissions[$tab]);
        else $tab = (string) array_key_first($tabs);
        if (($_GET['partial'] ?? '') === 'compliance') $tab = 'students';
        $workspace = ['complianceTabs' => $tabs, 'complianceTab' => $tab];
        if ($tab !== 'students') return $workspace + self::complianceReportData($pdo, $tab);

        $sectionId = (int) ($_GET['section_id'] ?? 0) ?: null;
        $programId = (int) ($_GET['program_id'] ?? 0) ?: null;
        $query = (string) ($_GET['q'] ?? '');
        $total = ComplianceService::count($sectionId, $programId, $query);
        $perPage = 50;
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) ($_GET['compliance_page'] ?? 1)), $pages);
        $offset = ($page - 1) * $perPage;
        $result = [
            'compliance' => ComplianceService::all($sectionId, $programId, $query, $perPage, $offset),
            'compliancePagination' => ['total' => $total, 'page' => $page, 'pages' => $pages, 'from' => $total ? $offset + 1 : 0, 'to' => min($offset + $perPage, $total)],
            'canIssueViolations' => Auth::can('manage_compliance'),
        ];
        if (($_GET['partial'] ?? '') === 'compliance') {
            return $result;
        }
        return $workspace + $result + [
            'sections' => $pdo->query('SELECT * FROM sections WHERE status = "active" ORDER BY name')->fetchAll(),
            'programs' => $pdo->query('SELECT * FROM programs WHERE status = "active" ORDER BY name')->fetchAll(),
            'violationCatalog' => ViolationService::catalog(),
        ];
    }

    private static function complianceReportData(PDO $pdo, string $tab): array
    {
        $data = [
            'attendanceSummary' => [], 'eventSummary' => [], 'canIssueViolations' => false,
        ];

        if ($tab === 'classes') {
            $data['attendanceSummary'] = $pdo->query('SELECT courses.course_code, sections.name section_name, attendance_records.status, COUNT(*) total FROM attendance_records JOIN class_offerings ON class_offerings.id = attendance_records.class_offering_id JOIN courses ON courses.id = class_offerings.course_id JOIN sections ON sections.id = class_offerings.section_id GROUP BY courses.course_code, sections.name, attendance_records.status ORDER BY courses.course_code')->fetchAll();
        }
        if ($tab === 'events') $data['eventSummary'] = $pdo->query('SELECT events.title, events.event_date, event_attendance.status, COUNT(*) total FROM event_attendance JOIN events ON events.id = event_attendance.event_id GROUP BY events.id, event_attendance.status ORDER BY events.event_date DESC')->fetchAll();


        return $data;
    }
}
