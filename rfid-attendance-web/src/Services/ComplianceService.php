<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class ComplianceService
{
    public static function forTeacher(int $teacherId, ?int $sectionId = null, ?string $query = null, ?int $limit = null, int $offset = 0): array
    {
        $where = [
            'teacher_assignments.teacher_id = :teacher_id',
            'class_enrollments.status = "enrolled"',
            'class_offerings.status = "active"',
            'users.status = "active"',
        ];
        $params = ['teacher_id' => $teacherId];

        if ($sectionId && $sectionId > 0) {
            $where[] = 'class_offerings.section_id = :section_id';
            $params['section_id'] = $sectionId;
        }

        if (trim((string) $query) !== '') {
            $where[] = '(users.school_id LIKE :query_school_id OR users.first_name LIKE :query_first_name OR users.last_name LIKE :query_last_name OR CONCAT_WS(" ", users.first_name, users.last_name) LIKE :query_full_name OR courses.course_code LIKE :query_course)';
            $value = mb_substr(trim((string) $query), 0, 100) . '%';
            $params['query_school_id'] = $value;
            $params['query_first_name'] = $value;
            $params['query_last_name'] = $value;
            $params['query_full_name'] = $value;
            $params['query_course'] = $value;
        }

        $sql = 'SELECT users.id, users.school_id,
                       CONCAT(users.first_name, " ", users.last_name) AS student_name,
                       sections.name AS section_name,
                       class_offerings.id AS class_offering_id,
                       courses.course_code,
                       courses.title AS course_title,
                       COALESCE(class_attendance_policies.tardies_per_absence, attendance_settings.tardies_per_absence) AS effective_tardies_per_absence,
                       SUM(CASE WHEN attendance_records.status = "Absent" THEN 1 ELSE 0 END) AS absences,
                       SUM(CASE WHEN attendance_records.status = "Late" THEN 1 ELSE 0 END) AS tardies,
                       (SELECT COUNT(*) FROM event_attendance ea JOIN event_participants ep ON ep.event_id = ea.event_id AND ep.user_id = ea.user_id WHERE ea.user_id = users.id AND ep.is_required = 1 AND ea.status IN ("Absent", "Incomplete")) AS missed_events,
                       (SELECT COUNT(*) FROM recollection_attendance ra WHERE ra.user_id = users.id AND ra.status = "Absent") AS missed_recollections
                FROM teacher_assignments
                JOIN class_offerings ON class_offerings.id = teacher_assignments.class_offering_id
                JOIN courses ON courses.id = class_offerings.course_id
                JOIN class_enrollments ON class_enrollments.class_offering_id = class_offerings.id
                JOIN users ON users.id = class_enrollments.student_id
                JOIN sections ON sections.id = class_offerings.section_id
                JOIN attendance_settings ON attendance_settings.id = 1
                LEFT JOIN class_attendance_policies ON class_attendance_policies.class_offering_id = class_offerings.id
                LEFT JOIN attendance_records
                  ON attendance_records.class_offering_id = class_offerings.id
                 AND attendance_records.student_id = users.id
                WHERE ' . implode(' AND ', $where) . '
                GROUP BY users.id, users.school_id, users.first_name, users.last_name,
                         sections.name, class_offerings.id, courses.course_code, courses.title,
                         class_attendance_policies.tardies_per_absence, attendance_settings.tardies_per_absence
                ORDER BY courses.course_code, sections.name, student_name';
        if ($limit !== null) {
            $limit = max(1, min(500, $limit));
            $offset = max(0, $offset);
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }
        $statement = Database::connection()->prepare($sql);
        $statement->execute($params);
        return self::withTotals($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public static function countForTeacher(int $teacherId, ?int $sectionId = null, ?string $query = null): int
    {
        $where = [
            'teacher_assignments.teacher_id = :teacher_id',
            'class_enrollments.status = "enrolled"',
            'class_offerings.status = "active"',
            'users.status = "active"',
        ];
        $params = ['teacher_id' => $teacherId];

        if ($sectionId && $sectionId > 0) {
            $where[] = 'class_offerings.section_id = :section_id';
            $params['section_id'] = $sectionId;
        }

        if (trim((string) $query) !== '') {
            $where[] = '(users.school_id LIKE :query_school_id OR users.first_name LIKE :query_first_name OR users.last_name LIKE :query_last_name OR CONCAT_WS(" ", users.first_name, users.last_name) LIKE :query_full_name OR courses.course_code LIKE :query_course)';
            $value = mb_substr(trim((string) $query), 0, 100) . '%';
            $params['query_school_id'] = $value;
            $params['query_first_name'] = $value;
            $params['query_last_name'] = $value;
            $params['query_full_name'] = $value;
            $params['query_course'] = $value;
        }

        $statement = Database::connection()->prepare(
            'SELECT COUNT(*)
             FROM teacher_assignments
             JOIN class_offerings ON class_offerings.id = teacher_assignments.class_offering_id
             JOIN courses ON courses.id = class_offerings.course_id
             JOIN class_enrollments ON class_enrollments.class_offering_id = class_offerings.id
             JOIN users ON users.id = class_enrollments.student_id
             WHERE ' . implode(' AND ', $where)
        );
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    public static function bySubjectForStudent(int $studentId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT class_offerings.id AS class_offering_id,
                    courses.course_code, courses.title AS course_title,
                    sections.name AS section_name,
                    SUM(CASE WHEN attendance_records.status = "Absent" THEN 1 ELSE 0 END) AS absences,
                    SUM(CASE WHEN attendance_records.status = "Late" THEN 1 ELSE 0 END) AS tardies,
                    COALESCE(class_attendance_policies.tardies_per_absence, attendance_settings.tardies_per_absence) AS effective_tardies_per_absence
             FROM class_enrollments
             JOIN class_offerings ON class_offerings.id = class_enrollments.class_offering_id
             JOIN courses ON courses.id = class_offerings.course_id
             JOIN sections ON sections.id = class_offerings.section_id
             JOIN attendance_settings ON attendance_settings.id = 1
             LEFT JOIN class_attendance_policies ON class_attendance_policies.class_offering_id = class_offerings.id
             LEFT JOIN attendance_records
               ON attendance_records.class_offering_id = class_offerings.id
              AND attendance_records.student_id = class_enrollments.student_id
             WHERE class_enrollments.student_id = :student_id
               AND class_enrollments.status = "enrolled"
               AND class_offerings.status = "active"
             GROUP BY class_offerings.id, courses.course_code, courses.title, sections.name,
                      class_attendance_policies.tardies_per_absence, attendance_settings.tardies_per_absence
             ORDER BY courses.course_code, sections.name'
        );
        $statement->execute(['student_id' => $studentId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $threshold = max(1, (int) $row['effective_tardies_per_absence']);
            $row['tardy_equivalent'] = intdiv((int) $row['tardies'], $threshold);
            $row['class_deficiencies'] = (int) $row['absences'] + $row['tardy_equivalent'];
        }
        unset($row);
        return $rows;
    }

    public static function all(?int $sectionId = null, ?int $programId = null, ?string $query = null, ?int $limit = null, int $offset = 0, ?int $studentId = null): array
    {
        $pdo = Database::connection();
        $sql = 'SELECT users.id, users.school_id, users.first_name, users.last_name,
                       CONCAT(users.first_name, " ", users.last_name) AS student_name,
                       sections.name AS section_name
                FROM users
                LEFT JOIN sections ON sections.id = users.section_id
                WHERE EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id = 3)
                  AND users.status = "active"';
        $params = [];
        if ($studentId) {
            $sql .= ' AND users.id = :student_id';
            $params['student_id'] = $studentId;
        }
        if ($sectionId) {
            $sql .= ' AND users.section_id = :section_id';
            $params['section_id'] = $sectionId;
        }
        if ($programId) {
            $sql .= ' AND users.program_id = :program_id';
            $params['program_id'] = $programId;
        }
        if (trim((string) $query) !== '') {
            $sql .= ' AND (users.school_id LIKE :query_school_id OR users.first_name LIKE :query_first_name OR users.last_name LIKE :query_last_name OR CONCAT_WS(" ", users.first_name, users.last_name) LIKE :query_full_name)';
            $value = mb_substr(trim((string) $query), 0, 100) . '%';
            $params['query_school_id'] = $value;
            $params['query_first_name'] = $value;
            $params['query_last_name'] = $value;
            $params['query_full_name'] = $value;
        }
        $sql .= ' ORDER BY users.last_name, users.first_name';
        if ($limit !== null) {
            $limit = max(1, min(500, $limit));
            $offset = max(0, $offset);
            $sql .= " LIMIT {$limit} OFFSET {$offset}";
        }

        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $students = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (!$students) {
            return [];
        }

        $studentIds = array_map(static fn($s) => (int) $s['id'], $students);
        $placeholders = implode(',', array_fill(0, count($studentIds), '?'));

        // Batch fetch class attendance
        $attStmt = $pdo->prepare(
            "SELECT student_id,
                    SUM(CASE WHEN status = 'Absent' THEN 1 ELSE 0 END) AS absences,
                    SUM(CASE WHEN status = 'Late' THEN 1 ELSE 0 END) AS tardies
             FROM attendance_records
             WHERE student_id IN ({$placeholders})
             GROUP BY student_id"
        );
        $attStmt->execute($studentIds);
        $attMap = [];
        foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $attMap[(int) $r['student_id']] = $r;
        }

        // Batch fetch event absences
        $evtStmt = $pdo->prepare(
            "SELECT ea.user_id, COUNT(*) AS missed_events
             FROM event_attendance ea
             JOIN event_participants ep ON ep.event_id = ea.event_id AND ep.user_id = ea.user_id
             WHERE ea.user_id IN ({$placeholders}) AND ep.is_required = 1 AND ea.status IN ('Absent', 'Incomplete')
             GROUP BY ea.user_id"
        );
        $evtStmt->execute($studentIds);
        $evtMap = [];
        foreach ($evtStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $evtMap[(int) $r['user_id']] = (int) $r['missed_events'];
        }

        // Batch fetch recollection absences
        $recStmt = $pdo->prepare(
            "SELECT user_id, COUNT(*) AS missed_recollections
             FROM recollection_attendance
             WHERE user_id IN ({$placeholders}) AND status = 'Absent'
             GROUP BY user_id"
        );
        $recStmt->execute($studentIds);
        $recMap = [];
        foreach ($recStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $recMap[(int) $r['user_id']] = (int) $r['missed_recollections'];
        }

        foreach ($students as &$student) {
            $sid = (int) $student['id'];
            $student['absences'] = (int) ($attMap[$sid]['absences'] ?? 0);
            $student['tardies'] = (int) ($attMap[$sid]['tardies'] ?? 0);
            $student['missed_events'] = (int) ($evtMap[$sid] ?? 0);
            $student['missed_recollections'] = (int) ($recMap[$sid] ?? 0);
        }
        unset($student);

        return self::withTotals($students);
    }

    public static function exportCsvStream(?int $sectionId = null, ?int $programId = null, ?string $query = null): void
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="compliance_report_' . date('Y-m-d_His') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'wb');
        // Add UTF-8 BOM for Excel compatibility
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Student ID', 'Student Name', 'Section', 'Absences', 'Tardies', 'Tardy Equivalent', 'Missed Events', 'Missed Recollections', 'Total Deficiencies'], ',', '"', '');

        $chunkSize = 500;
        $offset = 0;
        while (true) {
            $chunk = self::all($sectionId, $programId, $query, $chunkSize, $offset);
            if (!$chunk) {
                break;
            }
            foreach ($chunk as $row) {
                fputcsv($out, [
                    $row['school_id'],
                    $row['student_name'],
                    $row['section_name'] ?? 'Unassigned',
                    $row['absences'],
                    $row['tardies'],
                    $row['tardy_equivalent'],
                    $row['missed_events'],
                    $row['missed_recollections'],
                    $row['total_deficiencies'],
                ], ',', '"', '');
            }
            $offset += $chunkSize;
            if (count($chunk) < $chunkSize) {
                break;
            }
            if (function_exists('ob_flush')) {
                @ob_flush();
            }
            flush();
        }
        fclose($out);
        exit;
    }

    private static function withTotals(array $rows): array
    {
        $threshold = (int) Database::connection()->query('SELECT tardies_per_absence FROM attendance_settings WHERE id = 1')->fetchColumn();
        foreach ($rows as &$row) {
            $rowThreshold = max(1, (int) ($row['effective_tardies_per_absence'] ?? $threshold));
            $row['tardy_equivalent'] = intdiv((int) $row['tardies'], $rowThreshold);
            $row['total_deficiencies'] = (int) $row['absences'] + $row['tardy_equivalent'] + (int) $row['missed_events'] + (int) $row['missed_recollections'];
        }
        unset($row);
        return $rows;
    }

    public static function forStudent(int $studentId): array
    {
        return self::all(null, null, null, 1, 0, $studentId)[0]
            ?? ['absences' => 0, 'tardies' => 0, 'tardy_equivalent' => 0, 'missed_events' => 0, 'missed_recollections' => 0, 'total_deficiencies' => 0];
    }

    public static function count(?int $sectionId = null, ?int $programId = null, ?string $query = null): int
    {
        $sql = 'SELECT COUNT(*) FROM users
                WHERE EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id = 3)
                  AND users.status = "active"';
        $params = [];
        if ($sectionId) {
            $sql .= ' AND section_id = :section_id';
            $params['section_id'] = $sectionId;
        }
        if ($programId) {
            $sql .= ' AND program_id = :program_id';
            $params['program_id'] = $programId;
        }
        if (trim((string) $query) !== '') {
            $sql .= ' AND (school_id LIKE :query_school_id OR first_name LIKE :query_first_name OR last_name LIKE :query_last_name OR CONCAT_WS(" ", first_name, last_name) LIKE :query_full_name)';
            $value = mb_substr(trim((string) $query), 0, 100) . '%';
            foreach (['query_school_id', 'query_first_name', 'query_last_name', 'query_full_name'] as $field) $params[$field] = $value;
        }
        $statement = Database::connection()->prepare($sql);
        $statement->execute($params);
        return (int) $statement->fetchColumn();
    }

    public static function countDeficient(): int
    {
        $threshold = max(1, (int) Database::connection()->query('SELECT tardies_per_absence FROM attendance_settings WHERE id = 1')->fetchColumn());
        $sql = 'SELECT COUNT(*) FROM (
                    SELECT users.id
                    FROM users
                    LEFT JOIN attendance_records ON attendance_records.student_id = users.id
                    WHERE EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id = 3)
                      AND users.status = "active"
                    GROUP BY users.id
                    HAVING SUM(CASE WHEN attendance_records.status = "Absent" THEN 1 ELSE 0 END)
                        + FLOOR(SUM(CASE WHEN attendance_records.status = "Late" THEN 1 ELSE 0 END) / ' . $threshold . ')
                        + (SELECT COUNT(*) FROM event_attendance ea JOIN event_participants ep ON ep.event_id = ea.event_id AND ep.user_id = ea.user_id WHERE ea.user_id = users.id AND ep.is_required = 1 AND ea.status IN ("Absent", "Incomplete"))
                        + (SELECT COUNT(*) FROM recollection_attendance ra WHERE ra.user_id = users.id AND ra.status = "Absent") > 0
                ) deficient';
        return (int) Database::connection()->query($sql)->fetchColumn();
    }
}
