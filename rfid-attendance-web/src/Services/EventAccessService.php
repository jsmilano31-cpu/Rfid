<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use DomainException;
use PDO;

/** One scope for event pages, calendars, lookups, mutations and live tracking. */
final class EventAccessService
{
    private static array $areas = [];
    private static array $recipientContexts = [];
    public static function viewUrl(int $id): string
    {
        $user=Auth::user();
        $key=(int)($user['id']??0).':'.implode(',', $user['role_ids']??[]);
        self::$areas[$key] ??= Auth::usesAdminArea();
        return url((self::$areas[$key]?'/admin/event.php?id=':'/event.php?id=').$id);
    }
    public static function context(int $viewer): array
    {
        if ((int)(Auth::user()['id'] ?? 0) === $viewer) {
            $user = Auth::user();
            return ['roles'=>$user['role_ids'], 'admin'=>Auth::hasRole('Administrator'), 'manage'=>Auth::can('manage_events'), 'view'=>Auth::canAny(['view_events','manage_events'])];
        }
        if (isset(self::$recipientContexts[$viewer])) return self::$recipientContexts[$viewer];
        $q = Database::connection()->prepare('SELECT DISTINCT r.id,r.name FROM roles r JOIN users u ON u.id=? AND u.status="active" WHERE r.id=u.role_id OR EXISTS(SELECT 1 FROM user_roles ur WHERE ur.user_id=u.id AND ur.role_id=r.id)');
        $q->execute([$viewer]); $roles=$q->fetchAll(); $permissions=Auth::permissionsForUser($viewer);
        return self::$recipientContexts[$viewer]=['roles'=>array_map('intval',array_column($roles,'id')), 'admin'=>in_array('Administrator',array_column($roles,'name'),true), 'manage'=>in_array('manage_events',$permissions,true), 'view'=>array_intersect(['view_events','manage_events'],$permissions)!==[]];
    }

    public static function manageCondition(int $viewer, string $alias='events'): string
    {
        $c=self::context($viewer);
        if (!$c['manage']) return '0=1';
        if ($c['admin']) return '1=1';
        $roles=implode(',',array_map('intval',$c['roles'])) ?: '0';
        return "({$alias}.created_by=".(int)$viewer." AND {$alias}.management_role_id IN ({$roles}))";
    }

    public static function visibleCondition(int $viewer, string $alias='events'): string
    {
        if (!self::context($viewer)['view']) return '0=1';
        return '('.self::manageCondition($viewer,$alias)." OR EXISTS(SELECT 1 FROM event_participants visible_ep WHERE visible_ep.event_id={$alias}.id AND visible_ep.user_id=".(int)$viewer.'))';
    }

    public static function canManage(array $event, int $viewer): bool
    {
        $c=self::context($viewer);
        return $c['manage'] && ($c['admin'] || ((int)$event['created_by']===$viewer && in_array((int)($event['management_role_id']??0),array_map('intval',$c['roles']),true)));
    }

    public static function requireManage(int $eventId, int $viewer): array
    {
        $q=Database::connection()->prepare('SELECT * FROM events WHERE id=? AND '.self::manageCondition($viewer));
        $q->execute([$eventId]);
        if (!$event=$q->fetch()) throw new DomainException('This event is unavailable or outside your event management scope.');
        return $event;
    }

    public static function creationRole(int $viewer): int
    {
        $c=self::context($viewer);
        if (!$c['manage']) throw new DomainException('You do not have permission to manage events.');
        $ids=implode(',',array_map('intval',$c['roles'])) ?: '0';
        $q=Database::connection()->query('SELECT r.id FROM roles r JOIN role_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id WHERE r.id IN ('.$ids.') AND p.code="manage_events" ORDER BY r.name="Administrator" DESC,r.id LIMIT 1');
        return (int)$q->fetchColumn();
    }
}
