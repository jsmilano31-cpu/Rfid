<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;

final class DirectoryProfileService
{
    public static function profile(array $viewer, int $targetId): ?array
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'SELECT users.id, users.school_id, users.first_name, users.middle_name, users.last_name,
                    users.email, users.profile_image_path, users.profile_visibility, users.status,
                    roles.name primary_role_name,
                    departments.code department_code, departments.name department_name,
                    programs.code program_code, programs.name program_name,
                    sections.name section_name, sections.year_level, sections.section_code,
                    (SELECT GROUP_CONCAT(assigned_roles.name ORDER BY (assigned_user_roles.role_id = users.role_id) DESC, assigned_roles.name SEPARATOR "||")
                     FROM user_roles assigned_user_roles
                     JOIN roles assigned_roles ON assigned_roles.id = assigned_user_roles.role_id
                     WHERE assigned_user_roles.user_id = users.id) role_names_csv
             FROM users
             JOIN roles ON roles.id = users.role_id
             LEFT JOIN departments ON departments.id = users.department_id
             LEFT JOIN programs ON programs.id = users.program_id
             LEFT JOIN sections ON sections.id = users.section_id
             WHERE users.id = :id AND (users.status = "active" OR :can_manage = 1)
             LIMIT 1'
        );
        $statement->execute(['id' => $targetId, 'can_manage' => Auth::can('manage_users') ? 1 : 0]);
        $profile = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$profile) return null;

        $viewerId = (int) $viewer['id'];
        $roleNames = array_values(array_filter(explode('||', (string) ($profile['role_names_csv'] ?: $profile['primary_role_name']))));
        $profile['id'] = (int) $profile['id'];
        $profile['year_level'] = $profile['year_level'] !== null ? (int) $profile['year_level'] : null;
        $profile['role_names'] = $roleNames;
        $profile['role_names_label'] = implode(', ', $roleNames);
        unset($profile['role_names_csv']);
        $isStudent = in_array('Student', $roleNames, true);
        $isTeacher = in_array('Teacher', $roleNames, true);
        $isSelf = $viewerId === $targetId;
        $isPrivate = $profile['profile_visibility'] === 'private';
        $profile['is_profile_public'] = !$isPrivate;
        unset($profile['profile_visibility'], $profile['email'], $profile['profile_image_path'], $profile['school_id'],
            $profile['department_code'], $profile['department_name'], $profile['program_code'], $profile['program_name'],
            $profile['section_name'], $profile['section_code'], $profile['year_level']);
        if ($isPrivate && !$isSelf && !Auth::can('manage_users')) {
            return [
                'profile' => $profile,
                'is_self' => false,
                'is_private' => true,
                'is_student' => $isStudent,
                'is_teacher' => $isTeacher,
                'can_view_contact' => false,
                'classes' => [],
                'can_view_grades' => false,
                'grades' => [],
                'can_view_attendance' => false,
                'attendance' => [],
                'attendance_summary' => [],
            ];
        }
        // Restore full profile fields for the owner and authorized account managers.
        $fullStatement = $pdo->prepare(
            'SELECT users.school_id, users.email, users.profile_image_path,
                    departments.code department_code, departments.name department_name,
                    programs.code program_code, programs.name program_name,
                    sections.name section_name, sections.year_level, sections.section_code
             FROM users
             LEFT JOIN departments ON departments.id = users.department_id
             LEFT JOIN programs ON programs.id = users.program_id
             LEFT JOIN sections ON sections.id = users.section_id
             WHERE users.id = :id'
        );
        $fullStatement->execute(['id' => $targetId]);
        $profile += $fullStatement->fetch(PDO::FETCH_ASSOC) ?: [];
        $globalAcademicAccess = Auth::can('manage_users') || AttendanceService::hasGlobalAccess($viewerId) || GradeService::hasGlobalAccess($viewerId);

        $classes = [];
        if ($isStudent) {
            foreach (self::studentClasses($pdo, $viewer, $targetId, $isSelf || $globalAcademicAccess) as $class) {
                $classes[(int) $class['class_offering_id']] = self::normalizeClass($class, 'Enrolled');
            }
        }
        if ($isTeacher) {
            foreach (self::teacherClasses($pdo, $viewer, $targetId, $isSelf || $globalAcademicAccess) as $class) {
                $id = (int) $class['class_offering_id'];
                if (isset($classes[$id])) $classes[$id]['relationship'] = 'Enrolled · Teaching';
                else $classes[$id] = self::normalizeClass($class, 'Teaching');
            }
        }
        $classes = array_values($classes);

        $viewerIsTeacher = self::viewerHasRole($viewer, 'Teacher');
        $canViewContact = $isSelf || Auth::can('manage_users') || ($isStudent && $viewerIsTeacher && $classes !== []);
        $gradeScope = self::privateScope('grades', $viewer, $targetId, $isSelf);
        $attendanceScope = self::privateScope('attendance', $viewer, $targetId, $isSelf);

        $grades = $isStudent && $gradeScope !== null ? self::grades($pdo, $targetId, $gradeScope) : [];
        $attendance = $isStudent && $attendanceScope !== null ? self::attendance($pdo, $targetId, $attendanceScope) : [];
        $attendanceSummary = $isStudent && $attendanceScope !== null ? self::attendanceSummary($pdo, $targetId, $attendanceScope) : [];

        return [
            'profile' => $profile,
            'is_self' => $isSelf,
            'is_private' => false,
            'is_student' => $isStudent,
            'is_teacher' => $isTeacher,
            'can_view_contact' => $canViewContact,
            'classes' => $classes,
            'relationship_heading' => self::relationshipHeading($viewer, $profile, $isSelf, $isStudent, $isTeacher),
            'relationship_copy' => self::relationshipCopy($viewer, $profile, $isSelf, $isStudent, $isTeacher),
            'can_view_grades' => $isStudent && $gradeScope !== null,
            'grades' => $grades,
            'can_view_attendance' => $isStudent && $attendanceScope !== null,
            'attendance' => $attendance,
            'attendance_summary' => $attendanceSummary,
        ];
    }

    private static function studentClasses(PDO $pdo, array $viewer, int $targetId, bool $unrestricted): array
    {
        $params = ['target_id' => $targetId];
        $scope = [];
        if (!$unrestricted) {
            if (self::viewerHasRole($viewer, 'Teacher')) {
                $scope[] = 'EXISTS(SELECT 1 FROM teacher_assignments viewer_teaching WHERE viewer_teaching.class_offering_id = class_offerings.id AND viewer_teaching.teacher_id = :viewer_teacher_id)';
                $params['viewer_teacher_id'] = (int) $viewer['id'];
            }
            if (self::viewerHasRole($viewer, 'Student')) {
                $scope[] = 'EXISTS(SELECT 1 FROM class_enrollments viewer_enrollment WHERE viewer_enrollment.class_offering_id = class_offerings.id AND viewer_enrollment.student_id = :viewer_student_id AND viewer_enrollment.status = "enrolled")';
                $params['viewer_student_id'] = (int) $viewer['id'];
            }
            if ($scope === []) return [];
        }
        return self::classRows($pdo,
            'JOIN class_enrollments target_enrollment ON target_enrollment.class_offering_id = class_offerings.id
             AND target_enrollment.student_id = :target_id AND target_enrollment.status = "enrolled"',
            $scope, $params
        );
    }

    private static function teacherClasses(PDO $pdo, array $viewer, int $targetId, bool $unrestricted): array
    {
        $params = ['target_id' => $targetId];
        $scope = [];
        if (!$unrestricted) {
            if (self::viewerHasRole($viewer, 'Student')) {
                $scope[] = 'EXISTS(SELECT 1 FROM class_enrollments viewer_enrollment WHERE viewer_enrollment.class_offering_id = class_offerings.id AND viewer_enrollment.student_id = :viewer_student_id AND viewer_enrollment.status = "enrolled")';
                $params['viewer_student_id'] = (int) $viewer['id'];
            }
            if (self::viewerHasRole($viewer, 'Teacher')) {
                $scope[] = 'EXISTS(SELECT 1 FROM teacher_assignments viewer_teaching WHERE viewer_teaching.class_offering_id = class_offerings.id AND viewer_teaching.teacher_id = :viewer_teacher_id)';
                $params['viewer_teacher_id'] = (int) $viewer['id'];
            }
            if ($scope === []) return [];
        }
        return self::classRows($pdo,
            'JOIN teacher_assignments target_teaching ON target_teaching.class_offering_id = class_offerings.id
             AND target_teaching.teacher_id = :target_id',
            $scope, $params
        );
    }

    private static function classRows(PDO $pdo, string $targetJoin, array $scope, array $params): array
    {
        $where = ['class_offerings.status <> "cancelled"'];
        if ($scope !== []) $where[] = '(' . implode(' OR ', $scope) . ')';
        $statement = $pdo->prepare(
            'SELECT DISTINCT class_offerings.id class_offering_id, courses.id course_id,
                    courses.course_code, courses.title course_title,
                    sections.id section_id, sections.name section_name,
                    programs.code program_code, programs.name program_name,
                    school_years.name school_year_name, semesters.name semester_name,
                    rooms.id room_id, rooms.room_code,
                    (SELECT GROUP_CONCAT(CONCAT(teachers.id, "::", CONCAT_WS(" ", teachers.first_name, teachers.middle_name, teachers.last_name)) ORDER BY teachers.last_name SEPARATOR "||")
                     FROM teacher_assignments all_assignments
                     JOIN users teachers ON teachers.id = all_assignments.teacher_id
                     WHERE all_assignments.class_offering_id = class_offerings.id) teachers_csv,
                    (SELECT GROUP_CONCAT(CONCAT(class_schedules.day_of_week, "::", TIME_FORMAT(class_schedules.start_time, "%H:%i"), "::", TIME_FORMAT(class_schedules.end_time, "%H:%i"))
                     ORDER BY FIELD(class_schedules.day_of_week,"Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"), class_schedules.start_time SEPARATOR "||")
                     FROM class_schedules WHERE class_schedules.class_offering_id = class_offerings.id) schedules_csv
             FROM class_offerings
             ' . $targetJoin . '
             JOIN courses ON courses.id = class_offerings.course_id
             JOIN sections ON sections.id = class_offerings.section_id
             JOIN programs ON programs.id = sections.program_id
             JOIN semesters ON semesters.id = class_offerings.semester_id
             JOIN school_years ON school_years.id = semesters.school_year_id
             LEFT JOIN rooms ON rooms.id = class_offerings.room_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY school_years.starts_on DESC, semesters.starts_on DESC, courses.course_code
             LIMIT 100'
        );
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function normalizeClass(array $class, string $relationship): array
    {
        foreach (['class_offering_id', 'course_id', 'section_id'] as $field) $class[$field] = (int) $class[$field];
        $class['room_id'] = $class['room_id'] !== null ? (int) $class['room_id'] : null;
        $class['relationship'] = $relationship;
        $class['teachers'] = [];
        foreach (array_filter(explode('||', (string) $class['teachers_csv'])) as $teacher) {
            [$id, $name] = array_pad(explode('::', $teacher, 2), 2, '');
            $class['teachers'][] = ['id' => (int) $id, 'name' => $name];
        }
        $class['schedules'] = [];
        foreach (array_filter(explode('||', (string) $class['schedules_csv'])) as $schedule) {
            [$day, $start, $end] = array_pad(explode('::', $schedule, 3), 3, '');
            $class['schedules'][] = ['day' => $day, 'start_time' => $start, 'end_time' => $end];
        }
        unset($class['teachers_csv'], $class['schedules_csv']);
        return $class;
    }

    private static function privateScope(string $type, array $viewer, int $targetId, bool $isSelf): ?array
    {
        if ($isSelf) return ['sql' => '1 = 1', 'params' => []];
        $viewerId = (int) $viewer['id'];
        $permission = $type === 'grades' ? 'manage_grades' : 'manage_attendance';
        if (!Auth::can($permission)) return null;
        $global = $type === 'grades' ? GradeService::hasGlobalAccess($viewerId) : AttendanceService::hasGlobalAccess($viewerId);
        if ($global || Auth::can('manage_users')) return ['sql' => '1 = 1', 'params' => []];
        if (!self::viewerHasRole($viewer, 'Teacher')) return null;
        return [
            'sql' => 'EXISTS(SELECT 1 FROM teacher_assignments private_scope WHERE private_scope.class_offering_id = class_offerings.id AND private_scope.teacher_id = :private_viewer_id)',
            'params' => ['private_viewer_id' => $viewerId],
        ];
    }

    private static function grades(PDO $pdo, int $studentId, array $scope): array
    {
        $statement = $pdo->prepare(
            'SELECT grades.id, grades.prelim, grades.midterm, grades.final, grades.overall, grades.status,
                    grades.updated_at, class_offerings.id class_offering_id,
                    courses.course_code, courses.title course_title, sections.name section_name,
                    school_years.name school_year_name, semesters.name semester_name
             FROM grades
             JOIN class_offerings ON class_offerings.id = grades.class_offering_id
             JOIN courses ON courses.id = class_offerings.course_id
             JOIN sections ON sections.id = class_offerings.section_id
             JOIN semesters ON semesters.id = class_offerings.semester_id
             JOIN school_years ON school_years.id = semesters.school_year_id
             WHERE grades.student_id = :student_id AND ' . $scope['sql'] . '
             ORDER BY school_years.starts_on DESC, semesters.starts_on DESC, courses.course_code
             LIMIT 100'
        );
        $statement->execute(['student_id' => $studentId] + $scope['params']);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function attendance(PDO $pdo, int $studentId, array $scope): array
    {
        $statement = $pdo->prepare(
            'SELECT attendance_records.id, attendance_records.attendance_date, attendance_records.time_in,
                    attendance_records.time_out, attendance_records.status, attendance_records.source,
                    class_offerings.id class_offering_id, courses.course_code, courses.title course_title,
                    sections.name section_name
             FROM attendance_records
             JOIN class_offerings ON class_offerings.id = attendance_records.class_offering_id
             JOIN courses ON courses.id = class_offerings.course_id
             JOIN sections ON sections.id = class_offerings.section_id
             WHERE attendance_records.student_id = :student_id AND ' . $scope['sql'] . '
             ORDER BY attendance_records.attendance_date DESC, attendance_records.time_in DESC
             LIMIT 100'
        );
        $statement->execute(['student_id' => $studentId] + $scope['params']);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function attendanceSummary(PDO $pdo, int $studentId, array $scope): array
    {
        $statement = $pdo->prepare(
            'SELECT attendance_records.status, COUNT(*) total
             FROM attendance_records
             JOIN class_offerings ON class_offerings.id = attendance_records.class_offering_id
             WHERE attendance_records.student_id = :student_id AND ' . $scope['sql'] . '
             GROUP BY attendance_records.status'
        );
        $statement->execute(['student_id' => $studentId] + $scope['params']);
        $summary = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) $summary[$row['status']] = (int) $row['total'];
        return $summary;
    }

    private static function relationshipHeading(array $viewer, array $profile, bool $isSelf, bool $isStudent, bool $isTeacher): string
    {
        if ($isSelf) return 'My classes';
        $firstName = $profile['first_name'];
        if (self::viewerHasRole($viewer, 'Teacher') && $isStudent) return "Classes {$firstName} takes with you";
        if (self::viewerHasRole($viewer, 'Student') && $isTeacher) return "Classes you take with {$firstName}";
        if (self::viewerHasRole($viewer, 'Student') && $isStudent) return 'Classes you share';
        return $isTeacher && !$isStudent ? 'Teaching assignments' : 'Class connections';
    }

    private static function relationshipCopy(array $viewer, array $profile, bool $isSelf, bool $isStudent, bool $isTeacher): string
    {
        if ($isSelf) return 'Your current enrolled and teaching assignments are combined without duplicates.';
        if (self::viewerHasRole($viewer, 'Teacher') && $isStudent) return 'Only subjects assigned to you are shown here.';
        if (self::viewerHasRole($viewer, 'Student') && $isTeacher) return 'Only subjects in which this teacher teaches you are shown.';
        if (self::viewerHasRole($viewer, 'Student') && $isStudent) return 'This student is your classmate in the subjects listed below.';
        return 'Academic relationships visible to your current roles and permissions.';
    }

    private static function viewerHasRole(array $viewer, string $role): bool
    {
        return in_array($role, $viewer['role_names'] ?? [$viewer['role_name']], true);
    }
}
