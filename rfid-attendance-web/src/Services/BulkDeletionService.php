<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Exceptions\DeletionConflictException;
use DomainException;
use Throwable;

/** Each selected record uses its existing deletion rules. Blocked records are never retried. */
final class BulkDeletionService
{
    public const LIMIT = 100;

    public static function ids(mixed $input): array
    {
        if (is_string($input)) $input = explode(',', $input);
        if (!is_array($input) || !$input || count($input) > self::LIMIT) throw new DomainException('Select between 1 and 100 records.');
        $ids = [];
        foreach ($input as $value) {
            if (!is_scalar($value) || !preg_match('/^[1-9][0-9]{0,9}$/D', (string) $value)) throw new DomainException('One of the selected record IDs is invalid.');
            $ids[] = (int) $value;
        }
        return array_values(array_unique($ids));
    }

    public static function inspect(string $type, array $ids, int $actorId): array
    {
        self::authorize($type);
        $items = [];
        foreach (self::ids($ids) as $id) $items[] = self::item($type, $id, $actorId);
        return self::summary($type, $items);
    }

    public static function delete(string $type, array $ids, int $actorId): array
    {
        self::authorize($type);
        $ids = self::ids($ids);
        $deleted = []; $remaining = [];
        $pdo = Database::connection();
        $table = match ($type) {
            'department'=>'departments', 'program'=>'programs', 'section'=>'sections', 'course'=>'courses', 'offering'=>'class_offerings',
            'user'=>'users', 'room'=>'rooms', 'device'=>'devices', 'event'=>'events', 'recollection'=>'recollections', 'role'=>'roles', 'preset'=>'seat_plan_presets',
        };
        foreach ($ids as $id) {
            $item = null;
            try {
                $pdo->beginTransaction();
                // Lock the parent before inspecting dependencies. FK-backed concurrent inserts
                // wait here, including references whose existing FK uses ON DELETE CASCADE.
                $columns = $type === 'user' ? 'id,profile_image_path' : ($type === 'event' ? 'id,banner_image_path' : 'id');
                $lock = $pdo->prepare('SELECT '.$columns.' FROM '.$table.' WHERE id=:id FOR UPDATE'); $lock->execute(['id'=>$id]);
                $record = $lock->fetch();
                $item = self::item($type, $id, $actorId);
                if ($item['blocked']) { $pdo->rollBack(); $remaining[] = $item; continue; }
                $result = match ($type) {
                    'department', 'program', 'section', 'course', 'offering' => AcademicManagementService::delete($type, $id, $actorId),
                    'user' => UserService::delete($id, $actorId, false),
                    'room' => RoomService::deleteRoom($id, $actorId),
                    'device' => RoomService::deleteDevice($id, $actorId),
                    'event' => EventService::delete($id, $actorId, false),
                    'recollection' => RecollectionService::delete($id, $actorId),
                    'role' => self::deleteRole($id, $actorId),
                    'preset' => self::deletePreset($id, $actorId),
                };
                $pdo->commit();
                $deleted[] = $result;
                // Remove files only after the deletion and its audit entry commit.
                UploadService::removeManaged($record['profile_image_path'] ?? $record['banner_image_path'] ?? null);
            } catch (DeletionConflictException $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $remaining[] = ['blocked' => true, 'restriction' => null] + $exception->payload();
            } catch (DomainException $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $item ??= self::item($type, $id, $actorId);
                $item['blocked'] = true; $item['restriction'] = $exception->getMessage(); $remaining[] = $item;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Bulk deletion failed for '.$type.' #'.$id.': '.$exception->getMessage());
                // A concurrent dependency may have appeared since the initial check.
                $item = self::item($type, $id, $actorId);
                if (!$item['blocked']) { $item['blocked'] = true; $item['restriction'] = 'This record could not be deleted. Refresh before trying again.'; }
                $remaining[] = $item;
            }
        }
        $payload = self::summary($type, $remaining);
        $payload['deleted'] = $deleted;
        $payload['ok'] = !$remaining;
        $payload['code'] = $remaining ? 'deletion_conflict' : 'deleted';
        $payload['message'] = count($deleted).' record'.(count($deleted) === 1 ? '' : 's').' deleted. '.count($remaining).' retained.';
        return $payload;
    }

    private static function authorize(string $type): void
    {
        if (!Auth::can(DeletionConflictService::permissionFor($type))) throw new DomainException('You do not have permission to delete these records.');
    }

    private static function item(string $type, int $id, int $actorId): array
    {
        try {
            // Scope checks run before identity or dependency queries, especially personal presets.
            $restriction = DeletionConflictService::restriction($type, $id, $actorId);
            $conflict = DeletionConflictService::inspect($type, $id);
            return ['blocked' => $restriction !== null || $conflict['total'] > 0, 'restriction' => $restriction] + $conflict;
        } catch (DomainException $exception) {
            return ['blocked' => true, 'restriction' => $exception->getMessage(), 'target' => ['type' => $type, 'id' => $id, 'label' => 'Selected record #'.$id], 'groups' => [], 'total' => 0];
        }
    }

    private static function summary(string $type, array $items): array
    {
        $blocked = array_values(array_filter($items, static fn($item) => $item['blocked']));
        $groups = [];
        foreach ($blocked as $item) {
            foreach ($item['groups'] as $group) {
                $group['refresh_url'] = $item['refresh_url'];
                $group['source_key'] = $group['key'];
                $group['target_id'] = $item['target']['id'];
                $group['key'] = $type.'_'.$item['target']['id'].'_'.$group['key'];
                $group['label'] = $item['target']['label'].' — '.$group['label'];
                $groups[] = $group;
            }
        }
        return [
            'ok' => true, 'code' => 'deletion_conflict', 'bulk' => true,
            'target' => ['type' => $type, 'label' => count($items).' selected records'],
            'targets' => $items, 'blocked_count' => count($blocked), 'ready_count' => count($items) - count($blocked),
            'message' => count($blocked).' records are blocked. Resolve the linked records below, or deactivate/cancel records whose history must be preserved.',
            'total' => array_sum(array_column($blocked, 'total')), 'groups' => $groups,
            'refresh_url' => $items ? url('/api/bulk-delete.php?'.http_build_query(['type' => $type, 'ids' => implode(',', array_column(array_column($items, 'target'), 'id'))])) : null,
        ];
    }

    public static function deleteRole(int $id, int $actorId): array
    {
        $restriction = DeletionConflictService::restriction('role', $id, $actorId);
        if ($restriction !== null) throw new DomainException($restriction);
        $conflict = DeletionConflictService::inspect('role', $id);
        DeletionConflictService::assertClear('role', $id);
        try { Database::connection()->prepare('DELETE FROM roles WHERE id=:id')->execute(['id' => $id]); }
        catch (\PDOException $exception) {
            if ((int)($exception->errorInfo[1] ?? 0) === 1451) DeletionConflictService::assertClear('role', $id);
            throw $exception;
        }
        AuditService::log($actorId, 'delete_role', 'role', $id, $conflict['target']['label']);
        return ['id' => $id, 'label' => $conflict['target']['label']];
    }

    private static function deletePreset(int $id, int $actorId): array
    {
        $preset = SeatPlanService::preset($id, $actorId);
        SeatPlanService::deletePreset($id, $actorId);
        return ['id' => $id, 'label' => $preset['preset']['name']];
    }
}
