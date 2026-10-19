<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DomainException;
use PDO;

final class GradeService
{
    public static function hasGlobalAccess(int $actorId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT 1
             FROM user_roles
             JOIN roles ON roles.id = user_roles.role_id
             JOIN role_permissions ON role_permissions.role_id = roles.id
             JOIN permissions ON permissions.id = role_permissions.permission_id
             WHERE user_roles.user_id = :user_id
               AND permissions.code = "manage_grades"
               AND roles.name NOT IN ("Teacher", "Student")
             LIMIT 1'
        );
        $statement->execute(['user_id' => $actorId]);
        return (bool) $statement->fetchColumn();
    }

    public static function settings(): array
    {
        $pdo = Database::connection();
        $settings = $pdo->query('SELECT * FROM grading_settings WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
        if (!$settings) {
            return [
                'id' => 1,
                'has_prelim' => 0,
                'has_midterm' => 1,
                'has_final' => 1,
                'prelim_weight' => 0.0,
                'midterm_weight' => 40.0,
                'final_weight' => 60.0,
                'passing_grade' => 75.0,
            ];
        }
        $settings['has_prelim'] = (int) $settings['has_prelim'];
        $settings['has_midterm'] = (int) $settings['has_midterm'];
        $settings['has_final'] = (int) $settings['has_final'];
        $settings['prelim_weight'] = (float) $settings['prelim_weight'];
        $settings['midterm_weight'] = (float) $settings['midterm_weight'];
        $settings['final_weight'] = (float) $settings['final_weight'];
        $settings['passing_grade'] = (float) $settings['passing_grade'];
        return $settings;
    }

    public static function updateSettings(array $data, int $actorId): void
    {
        if (!self::hasGlobalAccess($actorId)) {
            throw new DomainException('Only authorized administrators can modify grading system policies.');
        }
        $hasPrelim = !empty($data['has_prelim']) ? 1 : 0;
        $hasMidterm = !empty($data['has_midterm']) ? 1 : 0;
        $hasFinal = !empty($data['has_final']) ? 1 : 0;

        if ($hasPrelim === 0 && $hasMidterm === 0 && $hasFinal === 0) {
            throw new DomainException('At least one evaluation term (Prelim, Midterm, or Endterm) must be enabled.');
        }

        $prelimWeight = $hasPrelim ? round((float) ($data['prelim_weight'] ?? 0), 2) : 0.0;
        $midtermWeight = $hasMidterm ? round((float) ($data['midterm_weight'] ?? 0), 2) : 0.0;
        $finalWeight = $hasFinal ? round((float) ($data['final_weight'] ?? 0), 2) : 0.0;
        $passingGrade = round((float) ($data['passing_grade'] ?? 75.0), 2);

        $totalWeight = round($prelimWeight + $midtermWeight + $finalWeight, 2);
        if (abs($totalWeight - 100.0) > 0.01) {
            throw new DomainException("The percentage weights of enabled terms must equal 100%. (Current sum: {$totalWeight}%)");
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO grading_settings (id, has_prelim, has_midterm, has_final, prelim_weight, midterm_weight, final_weight, passing_grade, updated_by)
             VALUES (1, :has_prelim, :has_midterm, :has_final, :prelim_weight, :midterm_weight, :final_weight, :passing_grade, :actor_id)
             ON DUPLICATE KEY UPDATE has_prelim = VALUES(has_prelim), has_midterm = VALUES(has_midterm), has_final = VALUES(has_final),
                                     prelim_weight = VALUES(prelim_weight), midterm_weight = VALUES(midterm_weight), final_weight = VALUES(final_weight),
                                     passing_grade = VALUES(passing_grade), updated_by = VALUES(updated_by)'
        );
        $stmt->execute([
            'has_prelim' => $hasPrelim,
            'has_midterm' => $hasMidterm,
            'has_final' => $hasFinal,
            'prelim_weight' => $prelimWeight,
            'midterm_weight' => $midtermWeight,
            'final_weight' => $finalWeight,
            'passing_grade' => $passingGrade,
            'actor_id' => $actorId,
        ]);

        AuditService::log($actorId, 'update_grading_settings', 'grading_settings', 1, "Prelim: {$hasPrelim} ({$prelimWeight}%), Midterm: {$hasMidterm} ({$midtermWeight}%), Final: {$hasFinal} ({$finalWeight}%), Passing: {$passingGrade}");
    }

    public static function save(array $data, int $actorId): array
    {
        $offeringId = (int) ($data['class_offering_id'] ?? 0);
        $studentId = (int) ($data['student_id'] ?? 0);
        self::assertOfferingAccess($offeringId, $actorId);

        $pdo = Database::connection();
        $enrollment = $pdo->prepare(
            'SELECT id FROM class_enrollments
             WHERE class_offering_id = :offering AND student_id = :student AND status = "enrolled"'
        );
        $enrollment->execute(['offering' => $offeringId, 'student' => $studentId]);
        if (!$enrollment->fetchColumn()) {
            throw new DomainException('The selected student is not enrolled in that class.');
        }

        $settings = self::settings();
        $values = ['prelim' => null, 'midterm' => null, 'final' => null];
        $terms = [];
        if ($settings['has_prelim']) $terms[] = 'prelim';
        if ($settings['has_midterm']) $terms[] = 'midterm';
        if ($settings['has_final']) $terms[] = 'final';

        foreach ($terms as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || !is_numeric($data[$field])) {
                throw new DomainException("Enter a valid score for the " . ucfirst($field) . " evaluation.");
            }
            $value = round((float) $data[$field], 2);
            if ($value < 0 || $value > 100) {
                throw new DomainException('Grade values must be between 0 and 100.');
            }
            $values[$field] = $value;
        }

        // Allow unenabled terms to preserve existing value if passed, otherwise null
        foreach (['prelim', 'midterm', 'final'] as $field) {
            if (!in_array($field, $terms, true) && isset($data[$field]) && is_numeric($data[$field])) {
                $values[$field] = round((float) $data[$field], 2);
            }
        }

        // Calculate weighted overall
        $overall = 0.0;
        if ($settings['has_prelim'] && $values['prelim'] !== null) {
            $overall += $values['prelim'] * ($settings['prelim_weight'] / 100.0);
        }
        if ($settings['has_midterm'] && $values['midterm'] !== null) {
            $overall += $values['midterm'] * ($settings['midterm_weight'] / 100.0);
        }
        if ($settings['has_final'] && $values['final'] !== null) {
            $overall += $values['final'] * ($settings['final_weight'] / 100.0);
        }
        $overall = round($overall, 2);
        $status = isset($data['finalize']) && (string) $data['finalize'] !== '' ? 'finalized' : 'draft';

        $existing = $pdo->prepare(
            'SELECT status FROM grades WHERE class_offering_id = :offering_id AND student_id = :student_id'
        );
        $existing->execute(['offering_id' => $offeringId, 'student_id' => $studentId]);
        if ($existing->fetchColumn() === 'finalized') {
            throw new DomainException('This grade is finalized. Reopen it before making changes.');
        }

        $statement = $pdo->prepare(
            'INSERT INTO grades (class_offering_id, student_id, prelim, midterm, final, overall, status, entered_by, finalized_at)
             VALUES (:offering_id, :student_id, :prelim, :midterm, :final, :overall, :status, :actor_id, :finalized_at)
             ON DUPLICATE KEY UPDATE prelim = VALUES(prelim), midterm = VALUES(midterm), final = VALUES(final),
                                     overall = VALUES(overall), status = VALUES(status), entered_by = VALUES(entered_by), finalized_at = VALUES(finalized_at)'
        );
        $statement->execute([
            'offering_id' => $offeringId,
            'student_id' => $studentId,
            'prelim' => $values['prelim'],
            'midterm' => $values['midterm'],
            'final' => $values['final'],
            'overall' => $overall,
            'status' => $status,
            'actor_id' => $actorId,
            'finalized_at' => $status === 'finalized' ? date('Y-m-d H:i:s') : null,
        ]);
        AuditService::log($actorId, 'save_grade', 'class_offering', $offeringId, "Student {$studentId}; {$status}");
        return self::studentGrade($offeringId, $studentId, $actorId);
    }

    public static function reopen(int $gradeId, int $actorId): array
    {
        if (!self::hasGlobalAccess($actorId)) {
            throw new DomainException('Only an authorized academic records role can reopen finalized grades.');
        }
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT class_offering_id, student_id, status FROM grades WHERE id = :id');
        $statement->execute(['id' => $gradeId]);
        $grade = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$grade) throw new DomainException('The selected grade could not be found.');
        if ($grade['status'] !== 'finalized') throw new DomainException('That grade is already editable.');

        $pdo->prepare('UPDATE grades SET status = "draft", finalized_at = NULL WHERE id = :id')->execute(['id' => $gradeId]);
        AuditService::log($actorId, 'reopen_grade', 'grade', $gradeId);
        return self::studentGrade((int) $grade['class_offering_id'], (int) $grade['student_id'], $actorId);
    }

    public static function subjects(int $actorId, string $query = '', int $page = 1, int $perPage = 12, ?int $programId = null, ?int $sectionId = null, ?string $status = null, ?string $sort = null): array
    {
        $pdo = Database::connection();
        $query = mb_substr(trim($query), 0, 100);
        $page = max(1, $page);
        $perPage = max(6, min(50, $perPage));
        [$accessSql, $accessParams] = self::accessScope($actorId);
        $where = ['class_offerings.status = "active"', $accessSql];
        $params = $accessParams;
        if ($query !== '') {
            $where[] = '(courses.course_code LIKE :course_code OR courses.title LIKE :course_title OR programs.code LIKE :program_code OR sections.name LIKE :section_name)';
            $prefix = $query . '%';
            $params += ['course_code' => $prefix, 'course_title' => $prefix, 'program_code' => $prefix, 'section_name' => $prefix];
        }
        if ($programId && $programId > 0) {
            $where[] = 'programs.id = :filter_program_id';
            $params['filter_program_id'] = $programId;
        }
        if ($sectionId && $sectionId > 0) {
            $where[] = 'sections.id = :filter_section_id';
            $params['filter_section_id'] = $sectionId;
        }

        $havingSql = '';
        if ($status === 'finalized') {
            $havingSql = ' HAVING student_count > 0 AND finalized_count = student_count';
        } elseif ($status === 'draft') {
            $havingSql = ' HAVING graded_count > 0 AND (finalized_count < student_count OR student_count = 0)';
        } elseif ($status === 'ungraded') {
            $havingSql = ' HAVING graded_count = 0';
        }

        $orderSql = match ($sort) {
            'code_desc' => 'courses.course_code DESC',
            'title_asc' => 'courses.title ASC',
            'section' => 'sections.name ASC, courses.course_code ASC',
            'students_desc' => 'student_count DESC',
            'average_desc' => 'class_average DESC',
            'average_asc' => 'class_average ASC',
            default => 'courses.course_code ASC, programs.code ASC, sections.year_level ASC, sections.section_code ASC',
        };

        $fromSql = ' FROM class_offerings
            JOIN courses ON courses.id = class_offerings.course_id
            JOIN sections ON sections.id = class_offerings.section_id
            JOIN programs ON programs.id = sections.program_id
            JOIN semesters ON semesters.id = class_offerings.semester_id
            JOIN school_years ON school_years.id = semesters.school_year_id
            LEFT JOIN rooms ON rooms.id = class_offerings.room_id
            WHERE ' . implode(' AND ', $where);

        $baseSelect = 'SELECT class_offerings.id, courses.course_code, courses.title course_title,
                    programs.code program_code, programs.name program_name,
                    sections.name section_name, sections.year_level, sections.section_code,
                    school_years.name school_year_name, semesters.name semester_name, rooms.room_code,
                    (SELECT GROUP_CONCAT(CONCAT(users.first_name, " ", users.last_name) ORDER BY users.last_name SEPARATOR ", ")
                     FROM teacher_assignments JOIN users ON users.id = teacher_assignments.teacher_id
                     WHERE teacher_assignments.class_offering_id = class_offerings.id) teacher_names,
                    (SELECT GROUP_CONCAT(CONCAT(class_schedules.day_of_week, " ", TIME_FORMAT(class_schedules.start_time, "%h:%i %p"), "–", TIME_FORMAT(class_schedules.end_time, "%h:%i %p"))
                            ORDER BY FIELD(class_schedules.day_of_week,"Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"), class_schedules.start_time SEPARATOR "||")
                     FROM class_schedules WHERE class_schedules.class_offering_id = class_offerings.id) schedule_csv,
                    (SELECT COUNT(*) FROM class_enrollments WHERE class_enrollments.class_offering_id = class_offerings.id AND class_enrollments.status = "enrolled") student_count,
                    (SELECT COUNT(*) FROM grades WHERE grades.class_offering_id = class_offerings.id) graded_count,
                    (SELECT COUNT(*) FROM grades WHERE grades.class_offering_id = class_offerings.id AND grades.status = "finalized") finalized_count,
                    (SELECT ROUND(AVG(grades.overall), 2) FROM grades WHERE grades.class_offering_id = class_offerings.id) class_average'
            . $fromSql;

        if ($havingSql !== '') {
            $countStmt = $pdo->prepare("SELECT COUNT(*) FROM ({$baseSelect} GROUP BY class_offerings.id {$havingSql}) filtered_offerings");
            $countStmt->execute($params);
            $total = (int) $countStmt->fetchColumn();
            $pages = max(1, (int) ceil($total / $perPage));
            $page = min($page, $pages);
            $offset = ($page - 1) * $perPage;

            $statement = $pdo->prepare("{$baseSelect} GROUP BY class_offerings.id {$havingSql} ORDER BY {$orderSql} LIMIT {$perPage} OFFSET {$offset}");
        } else {
            $count = $pdo->prepare('SELECT COUNT(DISTINCT class_offerings.id)' . $fromSql);
            $count->execute($params);
            $total = (int) $count->fetchColumn();
            $pages = max(1, (int) ceil($total / $perPage));
            $page = min($page, $pages);
            $offset = ($page - 1) * $perPage;

            $statement = $pdo->prepare("{$baseSelect} ORDER BY {$orderSql} LIMIT {$perPage} OFFSET {$offset}");
        }

        $statement->execute($params);
        $subjects = array_map(static function (array $subject): array {
            $subject['schedule'] = $subject['schedule_csv'] ? explode('||', $subject['schedule_csv']) : [];
            unset($subject['schedule_csv']);
            foreach (['id', 'year_level', 'student_count', 'graded_count', 'finalized_count'] as $field) $subject[$field] = (int) $subject[$field];
            return $subject;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
        return ['subjects' => $subjects, 'pagination' => compact('total', 'page', 'pages', 'perPage')];
    }

    public static function studentGradesOverview(int $studentId, int $actorId): array
    {
        $pdo = Database::connection();
        $studentStmt = $pdo->prepare(
            'SELECT users.id, users.school_id, users.first_name, users.middle_name, users.last_name, users.email,
                    users.profile_image_path, roles.name role_name,
                    departments.code department_code, programs.code program_code, programs.name program_name,
                    sections.name section_name
             FROM users
             JOIN roles ON roles.id = users.role_id
             LEFT JOIN departments ON departments.id = users.department_id
             LEFT JOIN programs ON programs.id = users.program_id
             LEFT JOIN sections ON sections.id = users.section_id
             WHERE users.id = :id LIMIT 1'
        );
        $studentStmt->execute(['id' => $studentId]);
        $student = $studentStmt->fetch(PDO::FETCH_ASSOC);
        if (!$student) {
            throw new DomainException('Student not found.');
        }

        $gradesStmt = $pdo->prepare(
            'SELECT class_offerings.id class_offering_id, courses.course_code, courses.title course_title,
                    courses.units, sections.name section_name,
                    grades.id grade_id, grades.prelim, grades.midterm, grades.final, grades.overall,
                    COALESCE(grades.status, "unentered") status, grades.updated_at,
                    (SELECT GROUP_CONCAT(CONCAT(users.first_name, " ", users.last_name) SEPARATOR ", ")
                     FROM teacher_assignments JOIN users ON users.id = teacher_assignments.teacher_id
                     WHERE teacher_assignments.class_offering_id = class_offerings.id) teacher_names
             FROM class_enrollments
             JOIN class_offerings ON class_offerings.id = class_enrollments.class_offering_id
             JOIN courses ON courses.id = class_offerings.course_id
             JOIN sections ON sections.id = class_offerings.section_id
             LEFT JOIN grades ON grades.class_offering_id = class_offerings.id AND grades.student_id = class_enrollments.student_id
             WHERE class_enrollments.student_id = :student_id AND class_enrollments.status = "enrolled" AND class_offerings.status = "active"
             ORDER BY courses.course_code'
        );
        $gradesStmt->execute(['student_id' => $studentId]);
        $grades = $gradesStmt->fetchAll(PDO::FETCH_ASSOC);

        $totalUnits = 0.0;
        $weightedSum = 0.0;
        $gradedCount = 0;
        foreach ($grades as &$grade) {
            $grade['prelim'] = $grade['prelim'] !== null ? (float) $grade['prelim'] : null;
            $grade['midterm'] = $grade['midterm'] !== null ? (float) $grade['midterm'] : null;
            $grade['final'] = $grade['final'] !== null ? (float) $grade['final'] : null;
            $grade['overall'] = $grade['overall'] !== null ? (float) $grade['overall'] : null;
            $units = (float) ($grade['units'] ?? 3.0);
            if ($grade['overall'] !== null) {
                $weightedSum += $grade['overall'] * $units;
                $totalUnits += $units;
                $gradedCount++;
            }
        }
        unset($grade);

        $gpa = $totalUnits > 0 ? round($weightedSum / $totalUnits, 2) : null;

        return [
            'student' => $student,
            'grades' => $grades,
            'gpa' => $gpa,
            'graded_count' => $gradedCount,
            'total_subjects' => count($grades),
            'settings' => self::settings(),
        ];
    }


    public static function roster(int $offeringId, int $actorId, string $query = '', int $page = 1, int $perPage = 25): array
    {
        self::assertOfferingAccess($offeringId, $actorId);
        $pdo = Database::connection();
        $query = mb_substr(trim($query), 0, 100);
        $page = max(1, $page);
        $perPage = max(10, min(50, $perPage));
        $subjectStatement = $pdo->prepare(
            'SELECT class_offerings.id, courses.course_code, courses.title course_title,
                    programs.code program_code, programs.name program_name, sections.name section_name,
                    school_years.name school_year_name, semesters.name semester_name
             FROM class_offerings
             JOIN courses ON courses.id = class_offerings.course_id
             JOIN sections ON sections.id = class_offerings.section_id
             JOIN programs ON programs.id = sections.program_id
             JOIN semesters ON semesters.id = class_offerings.semester_id
             JOIN school_years ON school_years.id = semesters.school_year_id
             WHERE class_offerings.id = :id'
        );
        $subjectStatement->execute(['id' => $offeringId]);
        $subject = $subjectStatement->fetch(PDO::FETCH_ASSOC);
        if (!$subject) throw new DomainException('The selected subject could not be found.');

        $where = ['class_enrollments.class_offering_id = :offering_id', 'class_enrollments.status = "enrolled"', 'users.status = "active"'];
        $params = ['offering_id' => $offeringId];
        if ($query !== '') {
            $where[] = '(users.school_id LIKE :school_id OR users.first_name LIKE :first_name OR users.last_name LIKE :last_name OR CONCAT_WS(" ", users.first_name, users.middle_name, users.last_name) LIKE :full_name)';
            $prefix = $query . '%';
            $params += ['school_id' => $prefix, 'first_name' => $prefix, 'last_name' => $prefix, 'full_name' => $prefix];
        }
        $fromSql = ' FROM class_enrollments JOIN users ON users.id = class_enrollments.student_id
                     LEFT JOIN grades ON grades.class_offering_id = class_enrollments.class_offering_id AND grades.student_id = users.id
                     WHERE ' . implode(' AND ', $where);
        $count = $pdo->prepare('SELECT COUNT(DISTINCT users.id)' . $fromSql);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $statement = $pdo->prepare(
            'SELECT users.id student_id, users.school_id, users.first_name, users.middle_name, users.last_name,
                    users.profile_image_path, grades.id grade_id, grades.prelim, grades.midterm, grades.final,
                    grades.overall, COALESCE(grades.status, "unentered") status, grades.updated_at'
            . $fromSql . " ORDER BY users.last_name, users.first_name LIMIT {$perPage} OFFSET {$offset}"
        );
        $statement->execute($params);
        $students = array_map([self::class, 'normalizeStudentGrade'], $statement->fetchAll(PDO::FETCH_ASSOC));
        $pagination = compact('total', 'page', 'pages', 'perPage');
        return ['subject' => $subject, 'students' => $students, 'pagination' => $pagination, 'can_reopen' => self::hasGlobalAccess($actorId), 'settings' => self::settings()];
    }

    public static function offeringSelectorRows(int $actorId): array
    {
        [$accessSql, $params] = self::accessScope($actorId);
        $statement = Database::connection()->prepare(
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
             WHERE class_offerings.status = "active" AND ' . $accessSql . '
             ORDER BY courses.course_code, programs.code, sections.year_level, sections.section_code, school_years.starts_on DESC, semesters.starts_on'
        );
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function studentGrade(int $offeringId, int $studentId, int $actorId): array
    {
        self::assertOfferingAccess($offeringId, $actorId);
        $statement = Database::connection()->prepare(
            'SELECT users.id student_id, users.school_id, users.first_name, users.middle_name, users.last_name,
                    users.profile_image_path, grades.id grade_id, grades.prelim, grades.midterm, grades.final,
                    grades.overall, COALESCE(grades.status, "unentered") status, grades.updated_at
             FROM users
             JOIN class_enrollments ON class_enrollments.student_id = users.id AND class_enrollments.class_offering_id = :offering_id
             LEFT JOIN grades ON grades.class_offering_id = class_enrollments.class_offering_id AND grades.student_id = users.id
             WHERE users.id = :student_id AND class_enrollments.status = "enrolled"'
        );
        $statement->execute(['offering_id' => $offeringId, 'student_id' => $studentId]);
        $student = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$student) throw new DomainException('The selected student is not enrolled in that class.');
        return self::normalizeStudentGrade($student);
    }

    private static function normalizeStudentGrade(array $student): array
    {
        $student['student_id'] = (int) $student['student_id'];
        $student['grade_id'] = $student['grade_id'] !== null ? (int) $student['grade_id'] : null;
        $student['student_name'] = trim(implode(' ', array_filter([$student['first_name'], $student['middle_name'] ?? null, $student['last_name']])));
        return $student;
    }

    private static function assertOfferingAccess(int $offeringId, int $actorId): void
    {
        if ($offeringId < 1) throw new DomainException('Select a valid subject.');
        [$accessSql, $params] = self::accessScope($actorId);
        $params['offering_id'] = $offeringId;
        $statement = Database::connection()->prepare(
            'SELECT class_offerings.id FROM class_offerings
             WHERE class_offerings.id = :offering_id AND ' . $accessSql . ' LIMIT 1'
        );
        $statement->execute($params);
        if (!$statement->fetchColumn()) throw new DomainException('You do not have access to manage grades for that subject.');
    }

    private static function accessScope(int $actorId): array
    {
        if (self::hasGlobalAccess($actorId)) return ['1 = 1', []];
        return [
            'EXISTS(SELECT 1 FROM teacher_assignments grade_scope WHERE grade_scope.class_offering_id = class_offerings.id AND grade_scope.teacher_id = :grade_actor_id)',
            ['grade_actor_id' => $actorId],
        ];
    }
}
