<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Core\Auth;
use App\Core\Csrf;
use App\Services\GradeService;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function grade_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function grade_verify_csrf(): void
{
    try { Csrf::verify(); }
    catch (RuntimeException $exception) {
        grade_json(['ok' => false, 'message' => 'Your session verification failed. Refresh the page and try again.'], 419);
    }
}

$user = Auth::user();
if (!$user) grade_json(['ok' => false, 'message' => 'Your session has expired. Sign in again.'], 401);

try {
    $actorId = (int) $user['id'];
    $settingsRequest = ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['mode'] ?? '') === 'settings')
        || ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_grading_settings');
    if ($settingsRequest) {
        if (!Auth::can('manage_grading_settings')) grade_json(['ok' => false, 'message' => 'You do not have permission to configure grading policies.'], 403);
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            grade_verify_csrf();
            GradeService::updateSettings($_POST, $actorId);
        }
        grade_json(['ok' => true, 'message' => 'University-wide grading rules saved.', 'settings' => GradeService::settings()]);
    }
    if (!Auth::can('view_grades') && !Auth::can('manage_grades')) grade_json(['ok' => false, 'message' => 'You do not have permission to view grades.'], 403);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!Auth::can('manage_grades')) {
            grade_json(['ok' => false, 'message' => 'You do not have permission to manage grades.'], 403);
        }
        $mode = (string) ($_GET['mode'] ?? 'subjects');
        $query = (string) ($_GET['q'] ?? '');
        $page = max(1, (int) ($_GET['page'] ?? 1));
        if ($mode === 'subjects') {
            $programId = (int) ($_GET['program_id'] ?? 0) ?: null;
            $sectionId = (int) ($_GET['section_id'] ?? 0) ?: null;
            $status = (string) ($_GET['status'] ?? '');
            $sort = (string) ($_GET['sort'] ?? '');
            grade_json(['ok' => true] + GradeService::subjects($actorId, $query, $page, 12, $programId, $sectionId, $status ?: null, $sort ?: null));
        }
        if ($mode === 'student_grades') {
            $studentId = (int) ($_GET['student_id'] ?? 0);
            grade_json(['ok' => true] + GradeService::studentGradesOverview($studentId, $actorId));
        }
        if ($mode === 'student_search') {
            grade_json(['ok' => true] + GradeService::searchStudents($actorId, $query, $page));
        }
        if ($mode === 'roster') {
            grade_json(['ok' => true] + GradeService::roster((int) ($_GET['offering_id'] ?? 0), $actorId, $query, $page));
        }
        if ($mode === 'student_record') {
            grade_json(['ok' => true, 'student' => GradeService::studentRecord(
                (int) ($_GET['offering_id'] ?? 0),
                (int) ($_GET['student_id'] ?? 0),
                $actorId
            )]);
        }
        throw new DomainException('That grade view is not available.');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: GET, POST');
        grade_json(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }
    if (!Auth::can('manage_grades')) grade_json(['ok' => false, 'message' => 'You do not have permission to manage grades.'], 403);
    grade_verify_csrf();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_grade') {
        $student = GradeService::save($_POST, $actorId);
        grade_json([
            'ok' => true,
            'message' => $student['status'] === 'finalized' ? 'Grade finalized.' : 'Draft grade saved.',
            'student' => $student,
        ]);
    }
    if ($action === 'resolve_shared_grade') {
        $student=GradeService::resolveShared((int)($_POST['class_offering_id']??0),(int)($_POST['student_id']??0),(int)($_POST['source_grade_id']??0),$actorId);
        grade_json(['ok'=>true,'message'=>'Component scores reconciled as a shared draft. Review and finalize the subject grade.','student'=>$student]);
    }
    if ($action === 'reopen_grade') {
        $student = GradeService::reopen((int) ($_POST['grade_id'] ?? 0), $actorId);
        grade_json(['ok' => true, 'message' => 'Grade reopened for editing.', 'student' => $student]);
    }
    throw new DomainException('That grade action is not available.');
} catch (DomainException $exception) {
    grade_json(['ok' => false, 'message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    grade_json(['ok' => false, 'message' => 'The grade request could not be completed. Please try again.'], 500);
}
