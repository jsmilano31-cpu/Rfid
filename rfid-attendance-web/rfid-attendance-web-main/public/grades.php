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

$user = Auth::user();
if (!$user) grade_json(['ok' => false, 'message' => 'Your session has expired. Sign in again.'], 401);
if (!Auth::can('view_grades') && !Auth::can('manage_grades')) grade_json(['ok' => false, 'message' => 'You do not have permission to view grades.'], 403);

try {
    $actorId = (int) $user['id'];
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
        if ($mode === 'roster') {
            grade_json(['ok' => true] + GradeService::roster((int) ($_GET['offering_id'] ?? 0), $actorId, $query, $page));
        }
        if ($mode === 'settings') {
            grade_json(['ok' => true, 'settings' => GradeService::settings()]);
        }
        throw new DomainException('That grade view is not available.');
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: GET, POST');
        grade_json(['ok' => false, 'message' => 'Method not allowed.'], 405);
    }
    if (!Auth::can('manage_grades')) grade_json(['ok' => false, 'message' => 'You do not have permission to manage grades.'], 403);
    Csrf::verify();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'save_grade') {
        $student = GradeService::save($_POST, $actorId);
        grade_json([
            'ok' => true,
            'message' => $student['status'] === 'finalized' ? 'Grade finalized.' : 'Draft grade saved.',
            'student' => $student,
        ]);
    }
    if ($action === 'reopen_grade') {
        $student = GradeService::reopen((int) ($_POST['grade_id'] ?? 0), $actorId);
        grade_json(['ok' => true, 'message' => 'Grade reopened for editing.', 'student' => $student]);
    }
    if ($action === 'update_grading_settings') {
        if (!Auth::can('manage_grading_settings')) grade_json(['ok' => false, 'message' => 'You do not have permission to configure grading policies.'], 403);
        GradeService::updateSettings($_POST, $actorId);
        grade_json([
            'ok' => true,
            'message' => 'Grading system policies updated successfully.',
            'settings' => GradeService::settings(),
        ]);
    }
    throw new DomainException('That grade action is not available.');
} catch (DomainException $exception) {
    grade_json(['ok' => false, 'message' => $exception->getMessage()], 422);
} catch (RuntimeException $exception) {
    grade_json(['ok' => false, 'message' => $exception->getMessage()], 419);
} catch (Throwable $exception) {
    grade_json(['ok' => false, 'message' => config('app')['debug'] ? $exception->getMessage() : 'The grade request could not be completed.'], 500);
}
