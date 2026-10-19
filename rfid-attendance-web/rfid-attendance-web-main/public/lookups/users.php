<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Services\GradeService;
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function lookup_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function require_lookup_permission(string $permission): void
{
    if (!Auth::can($permission)) {
        throw new DomainException('You do not have permission to search this directory.');
    }
}

try {
    $currentUser = Auth::user();
    if (!$currentUser) {
        lookup_response(['ok' => false, 'message' => 'Your session has expired. Sign in again.'], 401);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        header('Allow: GET');
        lookup_response(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }

    $context = (string) ($_GET['context'] ?? '');
    $pdo = Database::connection();

    if ($context === 'event_sections') {
        require_lookup_permission('manage_events');
        $eventId = max(0, (int) ($_GET['event_id'] ?? 0));
        if ($eventId < 1) {
            throw new DomainException('Select an event first.');
        }
        $statement = $pdo->prepare(
            'SELECT sections.id, sections.name, COUNT(*) participant_count
             FROM event_participants
             JOIN users ON users.id = event_participants.user_id
             JOIN sections ON sections.id = users.section_id
             WHERE event_participants.event_id = :event AND users.status = "active"
             GROUP BY sections.id, sections.name ORDER BY sections.name'
        );
        $statement->execute(['event' => $eventId]);
        $options = array_map(static fn(array $row): array => [
            'value' => (string) $row['id'],
            'label' => $row['name'] . ' (' . $row['participant_count'] . ')',
        ], $statement->fetchAll());
        $unassigned = $pdo->prepare(
            'SELECT COUNT(*) FROM event_participants
             JOIN users ON users.id = event_participants.user_id
             WHERE event_participants.event_id = :event AND users.status = "active" AND users.section_id IS NULL'
        );
        $unassigned->execute(['event' => $eventId]);
        $unassignedCount = (int) $unassigned->fetchColumn();
        if ($unassignedCount > 0) {
            $options[] = ['value' => 'none', 'label' => "No assigned section ({$unassignedCount})"];
        }
        lookup_response(['ok' => true, 'options' => $options]);
    }

    $query = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);
    if (mb_strlen($query) < 2) {
        lookup_response(['ok' => true, 'items' => [], 'pagination' => ['page' => 1, 'pages' => 1, 'total' => 0], 'message' => 'Type at least 2 characters.']);
    }
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = 20;
    $params = [];
    $joins = ' JOIN roles ON roles.id = users.role_id
               LEFT JOIN departments ON departments.id = users.department_id
               LEFT JOIN programs ON programs.id = users.program_id
               LEFT JOIN sections ON sections.id = users.section_id';
    $where = ['users.status = "active"'];

    switch ($context) {
        case 'event_participants':
            require_lookup_permission('manage_events');
            $eventId = max(0, (int) ($_GET['event_id'] ?? 0));
            $sectionId = (string) ($_GET['section_id'] ?? '');
            if ($eventId < 1 || ($sectionId !== 'none' && (int) $sectionId < 1)) {
                throw new DomainException('Select an event and section first.');
            }
            $joins .= ' JOIN event_participants scope ON scope.user_id = users.id';
            $where[] = 'scope.event_id = :scope_id';
            $params['scope_id'] = $eventId;
            if ($sectionId === 'none') {
                $where[] = 'users.section_id IS NULL';
            } else {
                $where[] = 'users.section_id = :section_id';
                $params['section_id'] = (int) $sectionId;
            }
            break;
        case 'recollection_participants':
            require_lookup_permission('manage_recollections');
            $scopeId = max(0, (int) ($_GET['recollection_id'] ?? 0));
            if ($scopeId < 1) throw new DomainException('Select an activity first.');
            $joins .= ' JOIN recollection_participants scope ON scope.user_id = users.id';
            $where[] = 'scope.recollection_id = :scope_id';
            $params['scope_id'] = $scopeId;
            break;
        case 'recollection_invitees':
            require_lookup_permission('manage_recollections');
            break;
        case 'class_enrollments':
        case 'grade_enrollments':
            require_lookup_permission($context === 'grade_enrollments' ? 'manage_grades' : 'manage_attendance');
            $scopeId = max(0, (int) ($_GET['class_offering_id'] ?? 0));
            if ($scopeId < 1) throw new DomainException('Select a class first.');
            if (Auth::hasRole('Teacher') && ($context !== 'grade_enrollments' || !GradeService::hasGlobalAccess((int) $currentUser['id']))) {
                $access = $pdo->prepare('SELECT 1 FROM teacher_assignments WHERE class_offering_id = :offering AND teacher_id = :teacher LIMIT 1');
                $access->execute(['offering' => $scopeId, 'teacher' => $currentUser['id']]);
                if (!$access->fetchColumn()) throw new DomainException('You can search only your assigned classes.');
            }
            $joins .= ' JOIN class_enrollments scope ON scope.student_id = users.id';
            $where[] = 'scope.class_offering_id = :scope_id';
            $where[] = 'scope.status = "enrolled"';
            $params['scope_id'] = $scopeId;
            break;
        case 'users':
            require_lookup_permission('send_messages');
            $where[] = 'users.id <> :current_user_id';
            $params['current_user_id'] = (int) $currentUser['id'];
            break;
        case 'directory':
            $directoryRole = mb_substr(trim((string) ($_GET['role'] ?? '')), 0, 80);
            if ($directoryRole !== '') {
                $where[] = 'EXISTS(SELECT 1 FROM user_roles directory_user_roles JOIN roles directory_roles ON directory_roles.id = directory_user_roles.role_id WHERE directory_user_roles.user_id = users.id AND directory_roles.name = :directory_role)';
                $params['directory_role'] = $directoryRole;
            }
            break;
        case 'teachers':
            require_lookup_permission('manage_academic_structure');
            $where[] = 'EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id = 2)';
            break;
        case 'academic_students':
            require_lookup_permission('manage_academic_structure');
            $where[] = 'EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id = 3)';
            break;
        default:
            throw new DomainException('That lookup is not available.');
    }

    $prefix = $query . '%';
    $where[] = '(users.school_id LIKE :school_id OR users.email LIKE :email OR users.first_name LIKE :first_name OR users.last_name LIKE :last_name OR CONCAT_WS(" ", users.first_name, users.middle_name, users.last_name) LIKE :full_name)';
    foreach (['school_id', 'email', 'first_name', 'last_name', 'full_name'] as $field) {
        $params[$field] = $prefix;
    }
    $fromSql = ' FROM users' . $joins . ' WHERE ' . implode(' AND ', $where);
    $count = $pdo->prepare('SELECT COUNT(DISTINCT users.id)' . $fromSql);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $statement = $pdo->prepare(
        'SELECT DISTINCT users.id, users.school_id, users.first_name, users.middle_name, users.last_name, users.email,
                users.profile_image_path, roles.name role_name,
                departments.code department_code, programs.code program_code, sections.name section_name,
                (SELECT GROUP_CONCAT(assigned_roles.name ORDER BY (assigned_user_roles.role_id = users.role_id) DESC, assigned_roles.name SEPARATOR "||")
                 FROM user_roles assigned_user_roles JOIN roles assigned_roles ON assigned_roles.id = assigned_user_roles.role_id
                 WHERE assigned_user_roles.user_id = users.id) role_names_csv'
        . $fromSql . " ORDER BY users.last_name, users.first_name LIMIT {$perPage} OFFSET {$offset}"
    );
    $statement->execute($params);
    $items = array_map(static fn(array $row): array => [
        'id' => (int) $row['id'],
        'label' => trim($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']),
        'identifier' => $row['school_id'],
        'role' => str_replace('||', ', ', (string) ($row['role_names_csv'] ?? $row['role_name'])),
        'email' => $row['email'],
        'profile_image_path' => $row['profile_image_path'],
        'department_code' => $row['department_code'],
        'program_code' => $row['program_code'],
        'section_name' => $row['section_name'],
        'profile_url' => url('/index/?page=profile&user_id=' . $row['id']),
    ], $statement->fetchAll());

    lookup_response([
        'ok' => true,
        'items' => $items,
        'pagination' => ['page' => $page, 'pages' => $pages, 'total' => $total],
    ]);
} catch (DomainException $exception) {
    lookup_response(['ok' => false, 'message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    $message = config('app')['debug'] ? $exception->getMessage() : 'The directory search could not be completed.';
    lookup_response(['ok' => false, 'message' => $message], 500);
}
