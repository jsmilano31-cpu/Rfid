<?php
declare(strict_types=1);
namespace App\Services;
use App\Core\Database;
use DomainException;

/** A subject is precisely course + section + semester; schedules, rooms and teachers are component-specific. */
final class SharedGradeService
{
    public static function same(string $a,string $b): string { return "{$a}.course_id={$b}.course_id AND {$a}.section_id={$b}.section_id AND {$a}.semester_id={$b}.semester_id"; }
    public static function subjects(): string
    {
        return '(SELECT MIN(id) id,course_id,section_id,semester_id,MIN(room_id) room_id,
            IF(MAX(status="active"),"active",MIN(status)) status,
            GROUP_CONCAT(DISTINCT component_type ORDER BY FIELD(component_type,"LEC","LAB") SEPARATOR " + ") component_type,
            GROUP_CONCAT(id ORDER BY id) component_ids
            FROM class_offerings GROUP BY course_id,section_id,semester_id)';
    }
    public static function enrollments(): string
    {
        return '(SELECT MIN(e.id) id,s.id class_offering_id,e.student_id,MIN(e.enrolled_at) enrolled_at,
            IF(MAX(e.status="enrolled"),"enrolled",IF(MAX(e.status="completed"),"completed","dropped")) status,
            GROUP_CONCAT(e.id ORDER BY e.id) enrollment_ids
            FROM class_enrollments e JOIN class_offerings o ON o.id=e.class_offering_id
            JOIN '.self::subjects().' s ON '.self::same('o','s').' GROUP BY s.id,e.student_id)';
    }
    public static function grades(): string
    {
        // Existing source rows and their IDs remain intact. Disagreement yields no guessed score.
        return '(SELECT g.id,q.class_offering_id,q.student_id,g.class_offering_id source_offering_id,
            IF(q.versions>1,NULL,g.prelim) prelim,IF(q.versions>1,NULL,g.midterm) midterm,
            IF(q.versions>1,NULL,g.final) final,IF(q.versions>1,NULL,g.overall) overall,
            IF(q.versions>1,"conflict",g.status) status,IF(q.versions>1,NULL,g.finalized_at) finalized_at,
            q.updated_at,g.entered_by,q.grade_count
            FROM (SELECT s.id class_offering_id,g.student_id,MIN(g.id) grade_id,COUNT(*) grade_count,
                COUNT(DISTINCT JSON_ARRAY(g.prelim,g.midterm,g.final,g.overall,g.status)) versions,MAX(g.updated_at) updated_at
                FROM grades g JOIN class_offerings o ON o.id=g.class_offering_id
                JOIN '.self::subjects().' s ON '.self::same('o','s').' GROUP BY s.id,g.student_id) q
            JOIN grades g ON g.id=q.grade_id)';
    }
    public static function subject(int $offeringId): array
    {
        $q=Database::connection()->prepare('SELECT s.* FROM class_offerings o JOIN '.self::subjects().' s ON '.self::same('o','s').' WHERE o.id=?');$q->execute([$offeringId]);
        return $q->fetch() ?: throw new DomainException('The selected subject could not be found.');
    }
    public static function canonical(int $offeringId): int { return (int)self::subject($offeringId)['id']; }
    public static function teacherScope(string $alias,string $placeholder): string
    {
        return 'EXISTS(SELECT 1 FROM teacher_assignments ta JOIN class_offerings gc ON gc.id=ta.class_offering_id WHERE '.self::same('gc',$alias).' AND ta.teacher_id='.$placeholder.')';
    }
    public static function studentScope(string $alias,string $student): string
    {
        return 'EXISTS(SELECT 1 FROM class_enrollments ge JOIN class_offerings gc ON gc.id=ge.class_offering_id WHERE '.self::same('gc',$alias).' AND ge.student_id='.$student.' AND ge.status IN ("enrolled","completed"))';
    }
    public static function lock(int $offering): void
    {
        $q=Database::connection()->prepare('SELECT o.id FROM class_offerings o JOIN class_offerings r ON '.self::same('o','r').' WHERE r.id=? ORDER BY o.id FOR UPDATE');$q->execute([$offering]);$q->fetchAll();
    }
    public static function originals(int $offering,int $student): array
    {
        $q=Database::connection()->prepare('SELECT g.*,o.component_type FROM grades g JOIN class_offerings o ON o.id=g.class_offering_id JOIN class_offerings r ON '.self::same('o','r').' WHERE r.id=? AND g.student_id=? ORDER BY g.id');$q->execute([$offering,$student]);return $q->fetchAll();
    }
    public static function conflicting(array $rows): bool
    {
        $signatures=[];foreach($rows as $row)$signatures[]=json_encode(array_intersect_key($row,array_flip(['prelim','midterm','final','overall','status'])));
        return count(array_unique($signatures))>1;
    }
}
