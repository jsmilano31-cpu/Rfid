<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use DomainException;
use PDO;
use Throwable;

final class SeatPlanService
{
    private const CELL_TYPES = ['student_seat', 'teacher_table', 'label'];

    public static function subjects(int $actorId): array
    {
        $hasGlobalAccess = Auth::can('manage_seat_plans') && (Auth::usesAdminArea() || Auth::hasRole('Administrator') || Auth::can('manage_rooms') || Auth::can('manage_academic_structure'));
        $joinType = $hasGlobalAccess ? 'LEFT JOIN' : 'JOIN';
        $whereSql = $hasGlobalAccess ? 'class_offerings.status = "active"' : 'teacher_assignments.teacher_id = :actor AND class_offerings.status = "active"';
        $params = $hasGlobalAccess ? [] : ['actor' => $actorId];

        $statement = Database::connection()->prepare(
            "SELECT class_offerings.id, courses.course_code, courses.title course_title,
                    programs.code program_code, sections.name section_name,
                    rooms.id room_id, rooms.room_code, rooms.room_name,
                    room_seat_plans.id seat_plan_id, room_seat_plans.name seat_plan_name,
                    COUNT(DISTINCT CONCAT(room_seat_plan_cells.grid_row, ':', room_seat_plan_cells.grid_column)) seat_count,
                    GROUP_CONCAT(DISTINCT CONCAT(class_schedules.day_of_week, ' ',
                        TIME_FORMAT(class_schedules.start_time, '%h:%i %p'), '-',
                        TIME_FORMAT(class_schedules.end_time, '%h:%i %p'))
                        ORDER BY FIELD(class_schedules.day_of_week,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), class_schedules.start_time
                        SEPARATOR '|~|') schedule_summary
             FROM class_offerings
             {$joinType} teacher_assignments ON teacher_assignments.class_offering_id = class_offerings.id
             JOIN courses ON courses.id = class_offerings.course_id
             JOIN sections ON sections.id = class_offerings.section_id
             JOIN programs ON programs.id = sections.program_id
             LEFT JOIN class_schedules ON class_schedules.class_offering_id = class_offerings.id
             LEFT JOIN rooms ON rooms.id = COALESCE(class_offerings.room_id,
                (SELECT selected_schedule.room_id FROM class_schedules selected_schedule
                 WHERE selected_schedule.class_offering_id = class_offerings.id AND selected_schedule.room_id IS NOT NULL
                 ORDER BY selected_schedule.id LIMIT 1))
             LEFT JOIN room_seat_plans ON room_seat_plans.room_id = rooms.id
             LEFT JOIN room_seat_plan_cells ON room_seat_plan_cells.seat_plan_id = room_seat_plans.id
             WHERE {$whereSql}
             GROUP BY class_offerings.id, courses.course_code, courses.title, programs.code, sections.name,
                      rooms.id, rooms.room_code, rooms.room_name, room_seat_plans.id, room_seat_plans.name
             ORDER BY courses.course_code, sections.name"
        );
        $statement->execute($params);
        return array_map(static function (array $row): array {
            $row['id'] = (int) $row['id'];
            $row['room_id'] = $row['room_id'] === null ? null : (int) $row['room_id'];
            $row['seat_plan_id'] = $row['seat_plan_id'] === null ? null : (int) $row['seat_plan_id'];
            $row['seat_count'] = (int) $row['seat_count'];
            $row['schedules'] = $row['schedule_summary'] ? explode('|~|', $row['schedule_summary']) : [];
            unset($row['schedule_summary']);
            return $row;
        }, $statement->fetchAll());
    }

    public static function bootstrap(int $actorId): array
    {
        $pdo = Database::connection();
        $rooms = Auth::can('manage_rooms')
            ? $pdo->query('SELECT id, room_code, room_name, building, floor FROM rooms WHERE status = "active" ORDER BY room_code')->fetchAll()
            : self::teacherRooms($actorId);
        $presetStatement = $pdo->prepare(
            'SELECT presets.id, presets.name, presets.row_count, presets.column_count, presets.visibility,
                    presets.created_by, CONCAT(users.first_name, " ", users.last_name) creator_name,
                    COUNT(cells.preset_id) cell_count
             FROM seat_plan_presets presets
             JOIN users ON users.id = presets.created_by
             LEFT JOIN seat_plan_preset_cells cells ON cells.preset_id = presets.id
             WHERE presets.visibility = "campus" OR presets.created_by = :actor
             GROUP BY presets.id ORDER BY presets.visibility, presets.name'
        );
        $presetStatement->execute(['actor' => $actorId]);

        return [
            'rooms' => array_map([self::class, 'roomSummary'], $rooms),
            'presets' => $presetStatement->fetchAll(),
            'can_create_campus_presets' => Auth::can('manage_rooms'),
            'actor_id' => $actorId,
        ];
    }

    public static function roomPlan(int $roomId, int $actorId): array
    {
        $room = self::accessibleRoom($roomId, $actorId);
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'SELECT plans.id, plans.room_id, plans.name, plans.row_count, plans.column_count,
                    plans.based_on_preset_id, plans.updated_at,
                    CONCAT(users.first_name, " ", users.last_name) updated_by_name
             FROM room_seat_plans plans
             JOIN users ON users.id = plans.updated_by
             WHERE plans.room_id = :room LIMIT 1'
        );
        $statement->execute(['room' => $roomId]);
        $plan = $statement->fetch();
        $cells = [];
        if ($plan) {
            $cellStatement = $pdo->prepare(
                'SELECT grid_row, grid_column, cell_type, label, assigned_student_id, occupied_at
                 FROM room_seat_plan_cells WHERE seat_plan_id = :plan ORDER BY grid_row, grid_column'
            );
            $cellStatement->execute(['plan' => $plan['id']]);
            $cells = $cellStatement->fetchAll();
        }

        return [
            'room' => self::roomSummary($room),
            'plan' => $plan ?: [
                'id' => null,
                'room_id' => $roomId,
                'name' => $room['room_code'] . ' Seat Plan',
                'row_count' => 10,
                'column_count' => 10,
                'based_on_preset_id' => null,
                'updated_at' => null,
                'updated_by_name' => null,
            ],
            'cells' => $cells,
        ];
    }

    public static function preset(int $presetId, int $actorId): array
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare(
            'SELECT presets.id, presets.name, presets.row_count, presets.column_count, presets.visibility,
                    presets.created_by, CONCAT(users.first_name, " ", users.last_name) creator_name
             FROM seat_plan_presets presets JOIN users ON users.id = presets.created_by
             WHERE presets.id = :id AND (presets.visibility = "campus" OR presets.created_by = :actor) LIMIT 1'
        );
        $statement->execute(['id' => $presetId, 'actor' => $actorId]);
        $preset = $statement->fetch();
        if (!$preset) throw new DomainException('That seat-plan preset is not available to you.');
        $cells = $pdo->prepare(
            'SELECT grid_row, grid_column, cell_type, label
             FROM seat_plan_preset_cells WHERE preset_id = :id ORDER BY grid_row, grid_column'
        );
        $cells->execute(['id' => $presetId]);
        return ['preset' => $preset, 'cells' => $cells->fetchAll()];
    }

    public static function saveRoomPlan(array $data, int $actorId): array
    {
        $roomId = (int) ($data['room_id'] ?? 0);
        $room = self::accessibleRoom($roomId, $actorId);
        [$rows, $columns] = self::dimensions($data);
        $name = self::name($data['name'] ?? '', $room['room_code'] . ' Seat Plan');
        $cells = self::cells($data['cells'] ?? '[]', $rows, $columns);
        $presetId = max(0, (int) ($data['based_on_preset_id'] ?? 0));
        if ($presetId > 0) self::preset($presetId, $actorId);

        $pdo = Database::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $existing = $pdo->prepare('SELECT id FROM room_seat_plans WHERE room_id = :room FOR UPDATE');
            $existing->execute(['room' => $roomId]);
            $planId = (int) $existing->fetchColumn();
            if ($planId > 0) {
                $pdo->prepare(
                    'UPDATE room_seat_plans SET name = :name, row_count = :rows, column_count = :columns,
                        based_on_preset_id = :preset, updated_by = :actor WHERE id = :id'
                )->execute(['name' => $name, 'rows' => $rows, 'columns' => $columns, 'preset' => $presetId ?: null, 'actor' => $actorId, 'id' => $planId]);
            } else {
                $pdo->prepare(
                    'INSERT INTO room_seat_plans (room_id, name, row_count, column_count, based_on_preset_id, updated_by)
                     VALUES (:room, :name, :rows, :columns, :preset, :actor)'
                )->execute(['room' => $roomId, 'name' => $name, 'rows' => $rows, 'columns' => $columns, 'preset' => $presetId ?: null, 'actor' => $actorId]);
                $planId = (int) $pdo->lastInsertId();
            }
            $pdo->prepare('DELETE FROM room_seat_plan_cells WHERE seat_plan_id = :plan')->execute(['plan' => $planId]);
            self::insertCells($pdo, 'room_seat_plan_cells', 'seat_plan_id', $planId, $cells);
            AuditService::log($actorId, 'save_room_seat_plan', 'room_seat_plan', $planId, $name);
            if ($ownsTransaction) $pdo->commit();
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }

        return self::roomPlan($roomId, $actorId);
    }

    public static function savePreset(array $data, int $actorId): array
    {
        [$rows, $columns] = self::dimensions($data);
        $name = self::name($data['name'] ?? '', 'Untitled Seat Plan');
        $cells = self::cells($data['cells'] ?? '[]', $rows, $columns);
        $visibility = ($data['visibility'] ?? '') === 'campus' && Auth::can('manage_rooms') ? 'campus' : 'personal';
        $pdo = Database::connection();
        $duplicate = $pdo->prepare('SELECT 1 FROM seat_plan_presets WHERE created_by = :actor AND name = :name');
        $duplicate->execute(['actor' => $actorId, 'name' => $name]);
        if ($duplicate->fetchColumn()) throw new DomainException('You already have a preset with that name.');

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO seat_plan_presets (name, row_count, column_count, visibility, created_by)
                 VALUES (:name, :rows, :columns, :visibility, :actor)'
            )->execute(['name' => $name, 'rows' => $rows, 'columns' => $columns, 'visibility' => $visibility, 'actor' => $actorId]);
            $presetId = (int) $pdo->lastInsertId();
            self::insertCells($pdo, 'seat_plan_preset_cells', 'preset_id', $presetId, $cells);
            AuditService::log($actorId, 'create_seat_plan_preset', 'seat_plan_preset', $presetId, $name);
            if ($ownsTransaction) $pdo->commit();
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
        return self::preset($presetId, $actorId);
    }

    public static function updatePreset(array $data, int $actorId): array
    {
        $presetId = (int) ($data['preset_id'] ?? 0);
        $preset = self::manageablePreset($presetId, $actorId);
        $name = self::name($data['name'] ?? '', $preset['name']);
        $visibility = ($data['visibility'] ?? '') === 'campus' && Auth::can('manage_rooms') ? 'campus' : 'personal';
        try {
            Database::connection()->prepare('UPDATE seat_plan_presets SET name = :name, visibility = :visibility WHERE id = :id')
                ->execute(['name' => $name, 'visibility' => $visibility, 'id' => $presetId]);
        } catch (\PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) throw new DomainException('You already have a preset with that name.');
            throw $exception;
        }
        AuditService::log($actorId, 'update_seat_plan_preset', 'seat_plan_preset', $presetId, $name);
        return self::preset($presetId, $actorId);
    }

    public static function deletePreset(int $presetId, int $actorId): void
    {
        $preset = self::manageablePreset($presetId, $actorId);
        Database::connection()->prepare('DELETE FROM seat_plan_presets WHERE id = :id')->execute(['id' => $presetId]);
        AuditService::log($actorId, 'delete_seat_plan_preset', 'seat_plan_preset', $presetId, $preset['name']);
    }

    private static function manageablePreset(int $presetId, int $actorId): array
    {
        if ($presetId < 1) throw new DomainException('Select a preset first.');
        $statement = Database::connection()->prepare(
            'SELECT * FROM seat_plan_presets WHERE id = :id
             AND (created_by = :actor OR (visibility = "campus" AND :can_manage_campus = 1)) LIMIT 1'
        );
        $statement->execute(['id' => $presetId, 'actor' => $actorId, 'can_manage_campus' => Auth::can('manage_rooms') ? 1 : 0]);
        $preset = $statement->fetch();
        if (!$preset) throw new DomainException('You can manage only your own presets or campus presets.');
        return $preset;
    }

    private static function teacherRooms(int $actorId): array
    {
        if (Auth::can('manage_rooms') || Auth::usesAdminArea() || Auth::hasRole('Administrator') || Auth::can('manage_seat_plans')) {
            return Database::connection()->query('SELECT DISTINCT id, room_code, room_name, building, floor FROM rooms WHERE status = "active" ORDER BY room_code')->fetchAll();
        }
        $statement = Database::connection()->query(
            'SELECT DISTINCT rooms.id, rooms.room_code, rooms.room_name, rooms.building, rooms.floor
             FROM rooms
             JOIN class_offerings ON class_offerings.status = "active"
             JOIN teacher_assignments ON teacher_assignments.class_offering_id = class_offerings.id
             LEFT JOIN class_schedules ON class_schedules.class_offering_id = class_offerings.id
             WHERE rooms.status = "active" AND teacher_assignments.teacher_id = ' . max(1, $actorId) . '
               AND (class_offerings.room_id = rooms.id OR class_schedules.room_id = rooms.id)
             ORDER BY rooms.room_code'
        );
        return $statement->fetchAll();
    }

    private static function accessibleRoom(int $roomId, int $actorId): array
    {
        if ($roomId < 1) throw new DomainException('Select a room first.');
        $pdo = Database::connection();
        if (Auth::can('manage_rooms') || Auth::usesAdminArea() || Auth::hasRole('Administrator') || Auth::can('manage_seat_plans')) {
            $statement = $pdo->prepare('SELECT id, room_code, room_name, building, floor FROM rooms WHERE id = :id AND status = "active"');
            $statement->execute(['id' => $roomId]);
        } else {
            $statement = $pdo->prepare(
                'SELECT DISTINCT rooms.id, rooms.room_code, rooms.room_name, rooms.building, rooms.floor
                 FROM rooms JOIN class_offerings ON class_offerings.status = "active"
                 JOIN teacher_assignments ON teacher_assignments.class_offering_id = class_offerings.id
                 LEFT JOIN class_schedules ON class_schedules.class_offering_id = class_offerings.id
                 WHERE rooms.id = :room AND rooms.status = "active" AND teacher_assignments.teacher_id = :actor
                   AND (class_offerings.room_id = rooms.id OR class_schedules.room_id = rooms.id) LIMIT 1'
            );
            $statement->execute(['room' => $roomId, 'actor' => $actorId]);
        }
        $room = $statement->fetch();
        if (!$room) throw new DomainException('You can manage seat plans only for rooms assigned to your classes.');
        return $room;
    }

    private static function roomSummary(array $room): array
    {
        $location = trim(implode(' ', array_filter([$room['building'] ?? '', $room['floor'] ?? ''])));
        return [
            'id' => (int) $room['id'],
            'code' => $room['room_code'],
            'name' => $room['room_name'],
            'location' => $location,
            'label' => $room['room_code'] . ' — ' . $room['room_name'],
        ];
    }

    private static function dimensions(array $data): array
    {
        $rows = (int) ($data['row_count'] ?? 10);
        $columns = (int) ($data['column_count'] ?? 10);
        if ($rows < 1 || $rows > 20 || $columns < 1 || $columns > 20) {
            throw new DomainException('Seat plans can contain between 1 and 20 rows and columns.');
        }
        return [$rows, $columns];
    }

    private static function name(mixed $value, string $fallback): string
    {
        $name = trim((string) $value) ?: $fallback;
        if (mb_strlen($name) > 120) throw new DomainException('Seat-plan names can contain up to 120 characters.');
        return $name;
    }

    private static function cells(mixed $json, int $rows, int $columns): array
    {
        try {
            $decoded = is_array($json) ? $json : json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new DomainException('The seat layout could not be read.');
        }
        if (!is_array($decoded)) throw new DomainException('The seat layout could not be read.');
        $cells = [];
        $defaults = ['student_seat' => 'Student Seat', 'teacher_table' => 'Teacher Table', 'label' => 'Label'];
        foreach ($decoded as $cell) {
            if (!is_array($cell)) continue;
            $row = (int) ($cell['row'] ?? $cell['row_number'] ?? 0);
            $column = (int) ($cell['column'] ?? $cell['column_number'] ?? 0);
            $type = (string) ($cell['type'] ?? $cell['cell_type'] ?? '');
            if ($row < 1 || $row > $rows || $column < 1 || $column > $columns || !in_array($type, self::CELL_TYPES, true)) continue;
            $label = trim((string) ($cell['label'] ?? '')) ?: $defaults[$type];
            if (mb_strlen($label) > 80) $label = mb_substr($label, 0, 80);
            $cells[$row . ':' . $column] = ['row' => $row, 'column' => $column, 'type' => $type, 'label' => $label];
        }
        return array_values($cells);
    }

    private static function insertCells(PDO $pdo, string $table, string $parentColumn, int $parentId, array $cells): void
    {
        $statement = $pdo->prepare(
            "INSERT INTO {$table} ({$parentColumn}, grid_row, grid_column, cell_type, label)
             VALUES (:parent, :row, :column, :type, :label)"
        );
        foreach ($cells as $cell) {
            $statement->execute(['parent' => $parentId, 'row' => $cell['row'], 'column' => $cell['column'], 'type' => $cell['type'], 'label' => $cell['label']]);
        }
    }
}
