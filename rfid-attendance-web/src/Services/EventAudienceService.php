<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DomainException;
use JsonException;
use PDO;

final class EventAudienceService
{
    private const BROAD = ['everyone', 'all_students', 'all_teachers', 'all_staff'];
    private const COHORTS = ['department' => 'departments', 'program' => 'programs', 'section' => 'sections'];
    private const FILTERS = ['any', 'student', 'teacher', 'staff'];

    public static function options(): array
    {
        static $options = null;
        if ($options !== null) return $options;
        $pdo = Database::connection();
        return $options = [
            'departments' => $pdo->query('SELECT id, code, name FROM departments WHERE status="active" ORDER BY code')->fetchAll(PDO::FETCH_ASSOC),
            'programs' => $pdo->query('SELECT id, code, name FROM programs WHERE status="active" ORDER BY code')->fetchAll(PDO::FETCH_ASSOC),
            'sections' => $pdo->query('SELECT sections.id, sections.name, programs.code program_code FROM sections JOIN programs ON programs.id=sections.program_id WHERE sections.status="active" AND programs.status="active" ORDER BY programs.code, sections.year_level, sections.section_code')->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    public static function normalize(array $data): array
    {
        if (array_key_exists('targets_json', $data)) {
            try { $raw = json_decode((string) $data['targets_json'], true, 512, JSON_THROW_ON_ERROR); }
            catch (JsonException) { throw new DomainException('Choose valid event recipients.'); }
        } else {
            $raw = [['type' => $data['audience'] ?? 'all_students', 'id' => $data['target_id'] ?? null]];
        }
        if (!is_array($raw) || !array_is_list($raw) || count($raw) < 1 || count($raw) > 40) {
            throw new DomainException('Choose between 1 and 40 event recipient groups or people.');
        }
        $pdo = Database::connection();
        $targets = [];
        foreach ($raw as $candidate) {
            if (!is_array($candidate)) throw new DomainException('Choose valid event recipients.');
            $type = (string) ($candidate['type'] ?? '');
            $id = filter_var($candidate['id'] ?? null, FILTER_VALIDATE_INT);
            $filter = (string) ($candidate['roleFilter'] ?? 'any');
            if (in_array($type, self::BROAD, true)) {
                $id = null;
                $filter = 'any';
            } elseif (isset(self::COHORTS[$type])) {
                if ($id === false || $id < 1 || !in_array($filter, self::FILTERS, true)) throw new DomainException('Choose a valid department, program, or section and role filter.');
                $statement = $pdo->prepare('SELECT 1 FROM ' . self::COHORTS[$type] . ' WHERE id=:id AND status="active" LIMIT 1');
                $statement->execute(['id' => $id]);
                if (!$statement->fetchColumn()) throw new DomainException('One selected event group is unavailable.');
            } elseif ($type === 'user') {
                if ($id === false || $id < 1) throw new DomainException('Choose an active person.');
                $statement = $pdo->prepare('SELECT 1 FROM users WHERE id=:id AND status="active" LIMIT 1');
                $statement->execute(['id' => $id]);
                if (!$statement->fetchColumn()) throw new DomainException('One selected person is unavailable.');
                $filter = 'any';
            } else {
                throw new DomainException('Choose a valid event recipient type.');
            }
            $key = $type . ':' . ($id ?? 0) . ':' . $filter;
            $targets[$key] = ['type' => $type, 'id' => $id, 'roleFilter' => $filter];
        }
        if (isset($targets['everyone:0:any'])) return [$targets['everyone:0:any']];
        return array_values($targets);
    }

    public static function save(PDO $pdo, int $eventId, array $targets, bool $required, bool $replace): void
    {
        if ($replace) {
            $pdo->prepare('DELETE FROM event_target_groups WHERE event_id=:event')->execute(['event' => $eventId]);
            $pdo->prepare('DELETE FROM event_participants WHERE event_id=:event AND user_id NOT IN (SELECT user_id FROM event_attendance WHERE event_id=:history_event)')
                ->execute(['event' => $eventId, 'history_event' => $eventId]);
        }
        $insert = $pdo->prepare('INSERT INTO event_target_groups (event_id,target_type,target_id,role_filter) VALUES (:event,:type,:target,:role_filter)');
        foreach ($targets as $target) {
            $insert->execute(['event' => $eventId, 'type' => $target['type'], 'target' => $target['id'], 'role_filter' => $target['roleFilter']]);
            [$condition, $params] = self::condition($target);
            $sql = 'INSERT INTO event_participants (event_id,user_id,is_required) SELECT :event, users.id, :required FROM users WHERE users.status="active" AND ' . $condition . ' ON DUPLICATE KEY UPDATE is_required=VALUES(is_required)';
            $pdo->prepare($sql)->execute(['event' => $eventId, 'required' => $required ? 1 : 0] + $params);
        }
    }

    private static function condition(array $target): array
    {
        $type = $target['type'];
        $id = $target['id'];
        $params = [];
        if ($type === 'everyone') $condition = '1=1';
        elseif ($type === 'all_students') $condition = self::roleCondition('Student');
        elseif ($type === 'all_teachers') $condition = self::roleCondition('Teacher');
        elseif ($type === 'all_staff') $condition = self::roleCondition('Staff', 'Event Coordinator');
        elseif ($type === 'user') { $condition = 'users.id=:person_id'; $params['person_id'] = $id; }
        elseif ($type === 'section') {
            $condition = '(users.section_id=:section_id OR EXISTS(SELECT 1 FROM teacher_assignments ta JOIN class_offerings o ON o.id=ta.class_offering_id WHERE ta.teacher_id=users.id AND o.section_id=:taught_section_id))';
            $params += ['section_id' => $id, 'taught_section_id' => $id];
        } elseif ($type === 'program') {
            $condition = '(users.program_id=:program_id OR EXISTS(SELECT 1 FROM sections s WHERE s.id=users.section_id AND s.program_id=:section_program_id) OR EXISTS(SELECT 1 FROM teacher_assignments ta JOIN class_offerings o ON o.id=ta.class_offering_id JOIN sections s ON s.id=o.section_id WHERE ta.teacher_id=users.id AND s.program_id=:taught_program_id))';
            $params += ['program_id' => $id, 'section_program_id' => $id, 'taught_program_id' => $id];
        } else {
            $condition = '(users.department_id=:department_id OR EXISTS(SELECT 1 FROM programs p WHERE p.id=users.program_id AND p.department_id=:program_department_id) OR EXISTS(SELECT 1 FROM teacher_assignments ta JOIN class_offerings o ON o.id=ta.class_offering_id JOIN sections s ON s.id=o.section_id JOIN programs p ON p.id=s.program_id WHERE ta.teacher_id=users.id AND p.department_id=:taught_department_id))';
            $params += ['department_id' => $id, 'program_department_id' => $id, 'taught_department_id' => $id];
        }
        if ($target['roleFilter'] !== 'any') {
            $roles = match ($target['roleFilter']) {
                'student' => ['Student'], 'teacher' => ['Teacher'], default => ['Staff', 'Event Coordinator'],
            };
            $condition .= ' AND ' . self::roleCondition(...$roles);
        }
        return [$condition, $params];
    }

    private static function roleCondition(string ...$names): string
    {
        $quoted = implode(',', array_map(static fn(string $name): string => '"' . $name . '"', $names));
        return 'EXISTS(SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id WHERE ur.user_id=users.id AND r.name IN (' . $quoted . '))';
    }

    public static function targetsByEventIds(array $eventIds): array
    {
        if (!$eventIds) return [];
        $eventIds = array_values(array_unique(array_map('intval', $eventIds)));
        $placeholders = implode(',', array_fill(0, count($eventIds), '?'));
        $statement = Database::connection()->prepare('SELECT t.event_id,t.target_type,t.target_id,t.role_filter,
            d.code department_code,d.name department_name,p.code program_code,p.name program_name,
            s.name section_name,u.school_id,u.first_name,u.middle_name,u.last_name
            FROM event_target_groups t
            LEFT JOIN departments d ON d.id=t.target_id AND t.target_type="department"
            LEFT JOIN programs p ON p.id=t.target_id AND t.target_type="program"
            LEFT JOIN sections s ON s.id=t.target_id AND t.target_type="section"
            LEFT JOIN users u ON u.id=t.target_id AND t.target_type="user"
            WHERE t.event_id IN (' . $placeholders . ') ORDER BY t.event_id,t.id');
        $statement->execute($eventIds);
        $byEvent = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $type = $row['target_type'];
            $label = match ($type) {
                'everyone' => 'Everyone', 'all_students' => 'All students', 'all_teachers' => 'All teachers', 'all_staff' => 'All staff',
                'department' => trim(($row['department_code'] ?? '') . ' · ' . ($row['department_name'] ?? '')),
                'program' => trim(($row['program_code'] ?? '') . ' · ' . ($row['program_name'] ?? '')),
                'section' => $row['section_name'] ?: 'Section',
                'user' => trim(implode(' ', array_filter([$row['first_name'], $row['middle_name'], $row['last_name']]))) . ' · ' . $row['school_id'],
                default => 'Group',
            };
            $filter = $row['role_filter'] ?: 'any';
            if ($filter !== 'any') $label .= ' · ' . ucfirst($filter) . ($filter === 'staff' ? '' : 's');
            $byEvent[(int) $row['event_id']][] = ['type' => $type, 'id' => $row['target_id'] === null ? null : (int) $row['target_id'], 'roleFilter' => $filter, 'label' => $label];
        }
        return $byEvent;
    }
}
