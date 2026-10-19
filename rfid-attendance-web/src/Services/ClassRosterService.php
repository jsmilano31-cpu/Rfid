<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use DomainException;
use PDO;

final class ClassRosterService
{
    public static function subject(int $offeringId, int $actorId): array
    {
        self::assertAccess($offeringId, $actorId);
        $statement = Database::connection()->prepare(
            'SELECT class_offerings.id, courses.course_code, courses.title course_title,
                    sections.name section_name, sections.year_level, sections.section_code,
                    programs.code program_code, programs.name program_name,
                    departments.code department_code, departments.name department_name,
                    school_years.name school_year_name, semesters.name semester_name,
                    COALESCE(class_schedules.room_id, class_offerings.room_id) room_id,
                    rooms.room_code, rooms.room_name,
                    GROUP_CONCAT(DISTINCT CONCAT_WS(" ", teachers.first_name, teachers.middle_name, teachers.last_name) ORDER BY teachers.last_name SEPARATOR ", ") teacher_names
             FROM class_offerings
             JOIN courses ON courses.id = class_offerings.course_id
             JOIN sections ON sections.id = class_offerings.section_id
             JOIN programs ON programs.id = sections.program_id
             JOIN departments ON departments.id = programs.department_id
             JOIN semesters ON semesters.id = class_offerings.semester_id
             JOIN school_years ON school_years.id = semesters.school_year_id
             LEFT JOIN class_schedules ON class_schedules.class_offering_id = class_offerings.id
             LEFT JOIN rooms ON rooms.id = COALESCE(class_schedules.room_id, class_offerings.room_id)
             LEFT JOIN teacher_assignments ON teacher_assignments.class_offering_id = class_offerings.id
             LEFT JOIN users teachers ON teachers.id = teacher_assignments.teacher_id
             WHERE class_offerings.id = :offering_id
             GROUP BY class_offerings.id, courses.course_code, courses.title, sections.name, sections.year_level,
                      sections.section_code, programs.code, programs.name, departments.code, departments.name,
                      school_years.name, semesters.name, COALESCE(class_schedules.room_id, class_offerings.room_id), rooms.room_code, rooms.room_name
             LIMIT 1'
        );
        $statement->execute(['offering_id' => $offeringId]);
        $subject = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$subject) throw new DomainException('The selected subject could not be found.');
        $subject['id'] = (int) $subject['id'];
        $subject['room_id'] = $subject['room_id'] !== null ? (int) $subject['room_id'] : null;
        return $subject;
    }

    public static function roster(int $offeringId, int $actorId, string $query = '', int $page = 1, int $perPage = 24): array
    {
        $subject = self::subject($offeringId, $actorId);
        $query = mb_substr(trim($query), 0, 100);
        $page = max(1, $page);
        $perPage = max(12, min(48, $perPage));
        $where = ['class_enrollments.class_offering_id = :offering_id', 'class_enrollments.status = "enrolled"', 'users.status = "active"'];
        $params = ['offering_id' => $offeringId];
        if ($query !== '') {
            $where[] = '(users.school_id LIKE :school_id OR users.first_name LIKE :first_name OR users.last_name LIKE :last_name OR CONCAT_WS(" ", users.first_name, users.middle_name, users.last_name) LIKE :full_name)';
            $prefix = $query . '%';
            $params += ['school_id' => $prefix, 'first_name' => $prefix, 'last_name' => $prefix, 'full_name' => $prefix];
        }
        $from = ' FROM class_enrollments JOIN users ON users.id = class_enrollments.student_id WHERE ' . implode(' AND ', $where);
        $count = Database::connection()->prepare('SELECT COUNT(*)' . $from);
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $offset = ($page - 1) * $perPage;
        $statement = Database::connection()->prepare(
            'SELECT users.id, users.school_id, users.first_name, users.middle_name, users.last_name,
                    users.email, users.profile_image_path' . $from . " ORDER BY users.last_name, users.first_name LIMIT {$perPage} OFFSET {$offset}"
        );
        $statement->execute($params);
        $isAdmin = Auth::can('manage_users') || Auth::can('manage_academic_structure');
        $students = array_map(static function (array $student) use ($isAdmin): array {
            $student['id'] = (int) $student['id'];
            $student['name'] = trim(implode(' ', array_filter([$student['first_name'], $student['middle_name'] ?? null, $student['last_name']])));
            $student['profile_url'] = url(($isAdmin ? '/admin/?page=profile&id=' : '/index/?page=directory_profile&id=') . $student['id']);
            $student['profile_image_url'] = url($student['profile_image_path'] ?: '/assets/images/default-avatar.svg');
            return $student;
        }, $statement->fetchAll(PDO::FETCH_ASSOC));
        return ['subject' => $subject, 'students' => $students, 'pagination' => compact('total', 'page', 'pages', 'perPage')];
    }

    private static function assertAccess(int $offeringId, int $actorId): void
    {
        if ($offeringId < 1) throw new DomainException('Select a valid subject.');
        $broad = Auth::can('manage_academic_structure') || AttendanceService::hasGlobalAccess($actorId);
        $statement = Database::connection()->prepare(
            'SELECT 1 FROM class_offerings
             WHERE class_offerings.id = :offering_id AND (
                :broad_access = 1
                OR EXISTS(SELECT 1 FROM teacher_assignments WHERE teacher_assignments.class_offering_id = class_offerings.id AND teacher_assignments.teacher_id = :teacher_id)
                OR EXISTS(SELECT 1 FROM class_enrollments WHERE class_enrollments.class_offering_id = class_offerings.id AND class_enrollments.student_id = :student_id AND class_enrollments.status = "enrolled")
             ) LIMIT 1'
        );
        $statement->execute(['offering_id' => $offeringId, 'broad_access' => $broad ? 1 : 0, 'teacher_id' => $actorId, 'student_id' => $actorId]);
        if (!$statement->fetchColumn()) throw new DomainException('You do not have access to this class roster.');
    }
}
