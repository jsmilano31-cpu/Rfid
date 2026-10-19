<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DomainException;
use PDOException;

final class RoomService
{
    public static function createDevice(array $data, int $actorId): array
    {
        $deviceCode = strtoupper(trim((string) ($data['device_code'] ?? '')));
        $name = normalize_title_case($data['name'] ?? '');
        $roomId = (int) ($data['room_id'] ?? 0) ?: null;
        if ($deviceCode === '' || $name === '') throw new DomainException('Enter a device code and name.');
        self::assertLength('Device code', $deviceCode, 60);
        self::assertLength('Device name', $name, 120);
        if ($roomId !== null) {
            $room = Database::connection()->prepare('SELECT 1 FROM rooms WHERE id = :id AND status = "active"');
            $room->execute(['id' => $roomId]);
            if (!$room->fetchColumn()) throw new DomainException('Select an active room.');
        }
        self::assertDeviceAssignmentAvailable($deviceCode, $roomId);
        $key = bin2hex(random_bytes(32));
        try {
            $pdo = Database::connection();
            $pdo->prepare('INSERT INTO devices (device_code, name, room_id, api_key_hash, status) VALUES (:code, :name, :room, :hash, "active")')
                ->execute(['code' => $deviceCode, 'name' => $name, 'room' => $roomId, 'hash' => password_hash($key, PASSWORD_DEFAULT)]);
            $id = (int) $pdo->lastInsertId();
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) throw new DomainException('That device code was assigned by another request. Refresh the page and enter a unique code.');
            throw $exception;
        }
        AuditService::log($actorId, 'create_device', 'device', $id, $deviceCode);
        return ['device' => self::findDevice($id), 'api_key' => $key];
    }

    public static function findRoom(int $roomId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT rooms.id, rooms.room_code, rooms.room_name, rooms.building, rooms.floor, rooms.status,
                    (SELECT GROUP_CONCAT(room_devices.device_code ORDER BY room_devices.device_code SEPARATOR ", ") FROM devices room_devices WHERE room_devices.room_id = rooms.id) device_code,
                    plans.id seat_plan_id, plans.name seat_plan_name,
                    plans.row_count seat_plan_rows, plans.column_count seat_plan_columns, plans.updated_at seat_plan_updated_at,
                    (SELECT COUNT(*) FROM room_seat_plan_cells WHERE room_seat_plan_cells.seat_plan_id = plans.id) seat_plan_cell_count
             FROM rooms
             LEFT JOIN room_seat_plans plans ON plans.room_id = rooms.id
             WHERE rooms.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $roomId]);
        return $statement->fetch() ?: null;
    }

    public static function updateRoom(array $data, int $actorId): array
    {
        $roomId = (int) ($data['room_id'] ?? 0);
        $roomCode = strtoupper(trim((string) ($data['room_code'] ?? '')));
        $roomName = normalize_title_case($data['room_name'] ?? '');
        $building = normalize_title_case($data['building'] ?? '');
        $floor = normalize_title_case($data['floor'] ?? '');

        if ($roomId < 1 || $roomCode === '' || $roomName === '') {
            throw new DomainException('Enter a room code and room name.');
        }
        self::assertLength('Room code', $roomCode, 40);
        self::assertLength('Room name', $roomName, 120);
        self::assertLength('Building', $building, 120);
        self::assertLength('Floor', $floor, 30);

        $pdo = Database::connection();
        if (!self::findRoom($roomId)) {
            throw new DomainException('The selected room could not be found.');
        }

        try {
            $pdo->prepare(
                'UPDATE rooms SET room_code = :code, room_name = :name, building = :building, floor = :floor
                 WHERE id = :id'
            )->execute([
                'code' => $roomCode,
                'name' => $roomName,
                'building' => $building !== '' ? $building : null,
                'floor' => $floor !== '' ? $floor : null,
                'id' => $roomId,
            ]);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new DomainException('That room code is already in use.');
            }
            throw $exception;
        }

        AuditService::log($actorId, 'update_room', 'room', $roomId, $roomCode);
        return self::findRoom($roomId) ?? throw new DomainException('Unable to reload the updated room.');
    }

    public static function toggleRoom(int $roomId, int $actorId): array
    {
        if ($roomId < 1 || !self::findRoom($roomId)) {
            throw new DomainException('The selected room could not be found.');
        }

        Database::connection()->prepare(
            'UPDATE rooms SET status = IF(status = "active", "inactive", "active") WHERE id = :id'
        )->execute(['id' => $roomId]);

        $room = self::findRoom($roomId) ?? throw new DomainException('Unable to reload the updated room.');
        AuditService::log($actorId, 'toggle_room', 'room', $roomId, (string) $room['status']);
        return $room;
    }

    public static function deleteRoom(int $roomId, int $actorId): array
    {
        $room = self::findRoom($roomId);
        if (!$room) {
            throw new DomainException('The selected room could not be found.');
        }

        $pdo = Database::connection();
        DeletionConflictService::assertClear('room', $roomId);

        $seatPlanCount = self::countReferences('room_seat_plans', 'room_id', $roomId);
        try {
            $pdo->prepare('DELETE FROM rooms WHERE id = :id')->execute(['id' => $roomId]);
        } catch (PDOException $exception) {
            if ((int)($exception->errorInfo[1] ?? 0) === 1451) {
                DeletionConflictService::assertClear('room', $roomId);
                throw new DomainException('This room gained a linked record while deletion was in progress. Refresh and try again.');
            }
            throw $exception;
        }
        AuditService::log($actorId, 'delete_room', 'room', $roomId, json_encode([
            'room' => $room['room_code'],
            'deleted_seat_plans' => $seatPlanCount,
        ], JSON_THROW_ON_ERROR));
        return ['id' => $roomId, 'label' => $room['room_code'] . ' — ' . $room['room_name']];
    }

    public static function findDevice(int $deviceId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT devices.id, devices.device_code, devices.name, devices.room_id, devices.status,
                    (devices.api_key_hash IS NOT NULL) has_api_key,
                    devices.last_seen_at, rooms.room_code
             FROM devices LEFT JOIN rooms ON rooms.id = devices.room_id
             WHERE devices.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $deviceId]);
        return $statement->fetch() ?: null;
    }

    public static function updateDevice(array $data, int $actorId): array
    {
        $deviceId = (int) ($data['device_id'] ?? 0);
        $deviceCode = strtoupper(trim((string) ($data['device_code'] ?? '')));
        $name = normalize_title_case($data['name'] ?? '');
        $roomId = (int) ($data['room_id'] ?? 0) ?: null;

        if ($deviceId < 1 || $deviceCode === '' || $name === '') {
            throw new DomainException('Enter a device code and name.');
        }
        self::assertLength('Device code', $deviceCode, 60);
        self::assertLength('Device name', $name, 120);

        $pdo = Database::connection();
        if (!self::findDevice($deviceId)) {
            throw new DomainException('The selected scanner could not be found.');
        }
        if ($roomId !== null) {
            $room = $pdo->prepare('SELECT 1 FROM rooms WHERE id = :id AND status = "active"');
            $room->execute(['id' => $roomId]);
            if (!$room->fetchColumn()) {
                throw new DomainException('Select an active room.');
            }
        }
        self::assertDeviceAssignmentAvailable($deviceCode, $roomId, $deviceId);

        try {
            $pdo->prepare(
                'UPDATE devices SET device_code = :code, name = :name, room_id = :room WHERE id = :id'
            )->execute(['code' => $deviceCode, 'name' => $name, 'room' => $roomId, 'id' => $deviceId]);
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new DomainException('That device code was assigned by another request. Refresh the page and enter a unique code.');
            }
            throw $exception;
        }

        AuditService::log($actorId, 'update_device', 'device', $deviceId, $deviceCode);
        return self::findDevice($deviceId) ?? throw new DomainException('Unable to reload the updated scanner.');
    }

    public static function toggleDevice(int $deviceId, int $actorId): array
    {
        if ($deviceId < 1 || !self::findDevice($deviceId)) {
            throw new DomainException('The selected scanner could not be found.');
        }

        Database::connection()->prepare(
            'UPDATE devices SET status = IF(status = "active", "inactive", "active") WHERE id = :id'
        )->execute(['id' => $deviceId]);

        $device = self::findDevice($deviceId) ?? throw new DomainException('Unable to reload the updated scanner.');
        AuditService::log($actorId, 'toggle_device', 'device', $deviceId, (string) $device['status']);
        return $device;
    }

    public static function deleteDevice(int $deviceId, int $actorId): array
    {
        $device = self::findDevice($deviceId);
        if (!$device) {
            throw new DomainException('The selected scanner could not be found.');
        }

        DeletionConflictService::assertClear('device', $deviceId);
        Database::connection()->prepare('DELETE FROM devices WHERE id = :id')->execute(['id' => $deviceId]);
        AuditService::log($actorId, 'delete_device', 'device', $deviceId, (string) $device['device_code']);
        return ['id' => $deviceId, 'label' => $device['device_code'] . ' — ' . $device['name']];
    }

    private static function countReferences(string $table, string $column, int $id): int
    {
        $allowed = [
            'devices.room_id', 'class_offerings.room_id', 'class_schedules.room_id', 'room_seat_plans.room_id',
        ];
        if (!in_array("{$table}.{$column}", $allowed, true)) {
            throw new DomainException('Invalid facility dependency check.');
        }
        $statement = Database::connection()->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} = :id");
        $statement->execute(['id' => $id]);
        return (int) $statement->fetchColumn();
    }

    private static function assertDeviceAssignmentAvailable(string $deviceCode, ?int $roomId, ?int $exceptDeviceId = null): void
    {
        $pdo = Database::connection();
        $codeQuery = 'SELECT device_code, name FROM devices WHERE device_code = :code';
        $codeParameters = ['code' => $deviceCode];
        if ($exceptDeviceId !== null) {
            $codeQuery .= ' AND id <> :except_id';
            $codeParameters['except_id'] = $exceptDeviceId;
        }
        $codeQuery .= ' LIMIT 1';
        $codeStatement = $pdo->prepare($codeQuery);
        $codeStatement->execute($codeParameters);
        $existingDevice = $codeStatement->fetch();
        if ($existingDevice) {
            throw new DomainException("A scanner with device code {$deviceCode} already exists. Edit that scanner or enter a unique device code.");
        }

        // Multiple scanners may serve one room to reduce queues. Device codes remain unique.
    }

    private static function assertLength(string $label, string $value, int $maximum): void
    {
        if (mb_strlen($value) > $maximum) {
            throw new DomainException("{$label} cannot exceed {$maximum} characters.");
        }
    }
}
