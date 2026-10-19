<?php
declare(strict_types=1);
namespace App\Services;

use App\Core\Database;
use DomainException;
use PDOException;

final class UserPreferenceService
{
    public const DEFAULTS = [
        'start_page' => 'home', 'theme_mode' => 'system', 'accent_theme' => 'blue',
        'reduced_motion' => false, 'compact_mode' => false, 'push_enabled' => true,
        'notify_attendance' => true, 'notify_events' => true,
        'notify_recollections' => true, 'notify_messages' => true,
    ];

    public static function get(int $userId): array
    {
        try {
            $query = Database::connection()->prepare('SELECT * FROM user_preferences WHERE user_id=?');
            $query->execute([$userId]);
            $row = $query->fetch();
        } catch (PDOException $error) {
            if ($error->getCode() === '42S02') return self::DEFAULTS;
            throw $error;
        }
        if (!$row) return self::DEFAULTS;
        $result = self::DEFAULTS;
        foreach (array_keys($result) as $key) {
            if (is_bool($result[$key])) $result[$key] = (bool) $row[$key];
            else $result[$key] = (string) $row[$key];
        }
        return $result;
    }

    public static function update(int $userId, array $input): array
    {
        \App\Core\PermissionRegistry::assertAction('update_settings');
        if ((int)(\App\Core\Auth::user()['id'] ?? 0) !== $userId) throw new DomainException('You can change only your own settings.');
        $current = self::get($userId);
        $value = static fn(string $key): mixed => array_key_exists($key, $input) ? $input[$key] : $current[$key];
        $settings = [
            'start_page' => (string) $value('start_page'),
            'theme_mode' => (string) $value('theme_mode'),
            'accent_theme' => (string) $value('accent_theme'),
            'reduced_motion' => self::flag($value('reduced_motion')),
            'compact_mode' => self::flag($value('compact_mode')),
            'push_enabled' => self::flag($value('push_enabled')),
            'notify_attendance' => self::flag($value('notify_attendance')),
            'notify_events' => self::flag($value('notify_events')),
            'notify_recollections' => self::flag($value('notify_recollections')),
            'notify_messages' => self::flag($value('notify_messages')),
        ];
        if (!in_array($settings['start_page'], ['home','attendance','schedule','messages','more'], true)) throw new DomainException('Choose a valid start page.');
        if (!in_array($settings['theme_mode'], ['system','light','dark'], true)) throw new DomainException('Choose a valid display mode.');
        if (!in_array($settings['accent_theme'], ['blue','green','purple','orange'], true)) throw new DomainException('Choose a valid accent color.');
        $sql = 'INSERT INTO user_preferences (user_id,start_page,theme_mode,accent_theme,reduced_motion,compact_mode,push_enabled,notify_attendance,notify_events,notify_recollections,notify_messages)
                VALUES (?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE start_page=VALUES(start_page),theme_mode=VALUES(theme_mode),accent_theme=VALUES(accent_theme),reduced_motion=VALUES(reduced_motion),compact_mode=VALUES(compact_mode),push_enabled=VALUES(push_enabled),notify_attendance=VALUES(notify_attendance),notify_events=VALUES(notify_events),notify_recollections=VALUES(notify_recollections),notify_messages=VALUES(notify_messages)';
        Database::connection()->prepare($sql)->execute([$userId,$settings['start_page'],$settings['theme_mode'],$settings['accent_theme'],(int)$settings['reduced_motion'],(int)$settings['compact_mode'],(int)$settings['push_enabled'],(int)$settings['notify_attendance'],(int)$settings['notify_events'],(int)$settings['notify_recollections'],(int)$settings['notify_messages']]);
        if (!$settings['push_enabled']) Database::connection()->prepare('UPDATE notification_devices SET active=0 WHERE user_id=?')->execute([$userId]);
        return $settings;
    }

    public static function allowsKind(int $userId, string $kind, string $targetType = ''): bool
    {
        $grants = \App\Core\Auth::permissionsForUser($userId);
        if (!in_array('manage_own_notifications', $grants, true)) return false;
        $required = match ($kind) {
            'attendance' => ['view_attendance','manage_attendance'],
            'message' => ['view_messages'],
            'activity','activity_update' => $targetType === 'recollection' ? ['view_recollections','manage_recollections'] : ['view_events','manage_events'],
            default => [],
        };
        if ($required && !array_intersect($required, $grants)) return false;
        $settings = self::get($userId);
        return match ($kind) {
            'attendance' => $settings['notify_attendance'],
            'activity', 'activity_update' => $targetType === 'recollection' ? $settings['notify_recollections'] : $settings['notify_events'],
            'message' => $settings['notify_messages'],
            default => true,
        };
    }

    public static function landingUrl(int $userId, bool $admin): string
    {
        $page = self::get($userId)['start_page'];
        $page = in_array($page, ['attendance','schedule','messages'], true) ? $page : 'dashboard';
        // A role change may make a previously saved portal landing page unavailable.
        $navigation = SearchService::visibleNavigation($admin);
        if (!isset($navigation[$page])) $page = array_key_first($navigation) ?? 'dashboard';
        $base = $admin ? '/admin/' : '/index/';
        return $page === 'dashboard' ? $base : $base . '?page=' . $page;
    }

    private static function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
    }
}
