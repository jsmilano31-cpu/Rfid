<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DomainException;

final class CalendarService
{
    private const EVENT_COLORS = [
        '#175CD3',
        '#6941C6',
        '#0E7090',
        '#C11574',
        '#B54708',
        '#3538CD',
        '#067647',
        '#B42318',
    ];

    public static function entries(): array
    {
        $pdo = Database::connection();
        $events = \App\Core\Auth::can('manage_events') ? $pdo->query(
            'SELECT events.id, events.title, events.description, events.event_date, events.end_date, events.start_time, events.end_time,
                    events.venue, events.banner_image_path, events.attendance_required, events.suspends_classes, events.check_in_start,
                    events.check_in_end, events.check_out_required, events.is_announcement, events.status,
                    (SELECT GROUP_CONCAT(device_id ORDER BY device_id) FROM event_scanners WHERE event_id = events.id) scanner_ids,
                    (SELECT target_type FROM event_target_groups WHERE event_id=events.id ORDER BY id LIMIT 1) target_type,
                    (SELECT target_id FROM event_target_groups WHERE event_id=events.id ORDER BY id LIMIT 1) target_id
             FROM events
             WHERE events.status <> "cancelled" AND ' . EventAccessService::manageCondition((int)\App\Core\Auth::user()['id']) . ' ORDER BY events.event_date, events.start_time'
        )->fetchAll() : [];
        $recollections = \App\Core\Auth::can('manage_recollections') ? $pdo->query(
            'SELECT recollections.id, recollections.title, recollections.type, recollections.date, recollections.end_date,
                    recollections.start_time, recollections.end_time, recollections.venue,
                    recollections.facilitator, recollections.attendance_required, recollections.status,
                    recollections.section_id, sections.name AS section_name
             FROM recollections
             JOIN sections ON sections.id = recollections.section_id
             WHERE recollections.status <> "cancelled"
             ORDER BY recollections.date, recollections.start_time'
        )->fetchAll() : [];

        $targets = EventAudienceService::targetsByEventIds(array_column($events, 'id'));
        return [
            ...array_map(static fn(array $event): array => self::eventEntry($event, $targets[(int) $event['id']] ?? []), $events),
            ...array_map([self::class, 'recollectionEntry'], $recollections),
            ...(\App\Core\PermissionRegistry::canManageCampusCalendar() ? TeacherTimeOffService::allEntries() : (\App\Core\Auth::can('view_schedule') ? TeacherTimeOffService::entriesForUser((int)\App\Core\Auth::user()['id']) : [])),
        ];
    }

    public static function entriesForUser(int $userId): array
    {
        $pdo = Database::connection();
        $eventStatement = $pdo->prepare(
            'SELECT events.id, events.title, events.description, events.event_date, events.end_date, events.start_time, events.end_time,
                    events.venue, events.banner_image_path, events.attendance_required, events.suspends_classes, events.check_in_start,
                    events.check_in_end, events.check_out_required, events.is_announcement, events.status,
                    (SELECT GROUP_CONCAT(device_id ORDER BY device_id) FROM event_scanners WHERE event_id = events.id) scanner_ids,
                    (SELECT target_type FROM event_target_groups WHERE event_id=events.id ORDER BY id LIMIT 1) target_type,
                    (SELECT target_id FROM event_target_groups WHERE event_id=events.id ORDER BY id LIMIT 1) target_id
             FROM events
             WHERE ' . EventAccessService::visibleCondition($userId) . ' AND events.status <> "cancelled"
             ORDER BY events.event_date, events.start_time'
        );
        $events = [];
        if (\App\Core\Auth::canAny(['view_events','manage_events'])) {
            $eventStatement->execute();
            $events = $eventStatement->fetchAll();
        }

        $recollectionStatement = $pdo->prepare(
            'SELECT recollections.id, recollections.title, recollections.type, recollections.date, recollections.end_date,
                    recollections.start_time, recollections.end_time, recollections.venue,
                    recollections.facilitator, recollections.attendance_required, recollections.status,
                    recollections.section_id, sections.name AS section_name
             FROM recollections
             JOIN sections ON sections.id = recollections.section_id
             WHERE ' . RecollectionService::involvementCondition($userId) . ' AND recollections.status <> "cancelled"
             ORDER BY recollections.date, recollections.start_time'
        );
        $recollections = [];
        if (\App\Core\Auth::canAny(['view_recollections','manage_recollections'])) {
            $recollectionStatement->execute();
            $recollections = $recollectionStatement->fetchAll();
        }

        $targets = EventAudienceService::targetsByEventIds(array_column($events, 'id'));
        return [
            ...array_map(static fn(array $event): array => self::eventEntry($event, $targets[(int) $event['id']] ?? []), $events),
            ...array_map([self::class, 'recollectionEntry'], $recollections),
            ...(\App\Core\Auth::can('view_schedule') ? TeacherTimeOffService::entriesForUser($userId) : []),
        ];
    }

    public static function teacherTimeOffById(int $id, int $viewerId): array
    {
        return TeacherTimeOffService::entryById($id, $viewerId);
    }

    public static function eventById(int $eventId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT events.id, events.title, events.description, events.event_date, events.end_date, events.start_time, events.end_time,
                    events.venue, events.banner_image_path, events.attendance_required, events.suspends_classes, events.check_in_start,
                    events.check_in_end, events.check_out_required, events.is_announcement, events.status,
                    (SELECT GROUP_CONCAT(device_id ORDER BY device_id) FROM event_scanners WHERE event_id = events.id) scanner_ids,
                    (SELECT target_type FROM event_target_groups WHERE event_id=events.id ORDER BY id LIMIT 1) target_type,
                    (SELECT target_id FROM event_target_groups WHERE event_id=events.id ORDER BY id LIMIT 1) target_id
             FROM events
             WHERE events.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $eventId]);
        $event = $statement->fetch();
        if (!$event) {
            throw new DomainException('The event could not be found.');
        }
        $targets = EventAudienceService::targetsByEventIds([$eventId]);
        return self::eventEntry($event, $targets[$eventId] ?? []);
    }

    public static function recollectionById(int $recollectionId): array
    {
        $statement = Database::connection()->prepare(
            'SELECT recollections.id, recollections.title, recollections.type, recollections.date, recollections.end_date,
                    recollections.start_time, recollections.end_time, recollections.venue,
                    recollections.facilitator, recollections.attendance_required, recollections.status,
                    recollections.section_id, sections.name AS section_name
             FROM recollections
             JOIN sections ON sections.id = recollections.section_id
             WHERE recollections.id = :id LIMIT 1'
        );
        $statement->execute(['id' => $recollectionId]);
        $recollection = $statement->fetch();
        if (!$recollection) {
            throw new DomainException('The recollection or retreat could not be found.');
        }
        return self::recollectionEntry($recollection);
    }

    private static function eventEntry(array $event, array $targets = []): array
    {
        return [
            'id' => 'event-' . $event['id'],
            'source_id' => (int) $event['id'],
            'kind' => 'event',
            'view_url' => EventAccessService::viewUrl((int)$event['id']),
            'title' => $event['title'],
            'description' => $event['description'] ?? '',
            'from' => $event['event_date'] . 'T' . $event['start_time'],
            'to' => ($event['end_date'] ?: $event['event_date']) . 'T' . $event['end_time'],
            'location' => $event['venue'],
            'group' => 'School events',
            'color' => self::eventColor((int) $event['id']),
            'colorText' => '#ffffff',
            'customTags' => [
                'kind' => 'event',
                'status' => $event['status'],
                'sourceId' => (int) $event['id'],
                'viewUrl' => EventAccessService::viewUrl((int)$event['id']),
                'endDate' => $event['end_date'] ?: null,
                'bannerImagePath' => $event['banner_image_path'] ?? null,
                'audience' => $event['target_type'] ?? 'all_students',
                'targetId' => isset($event['target_id']) ? (int) $event['target_id'] : null,
                'targets' => $targets,
                'attendanceRequired' => (bool) ($event['attendance_required'] ?? true),
                'suspendsClasses' => (bool) ($event['suspends_classes'] ?? false),
                'scannerIds' => array_values(array_filter(array_map('intval', explode(',', (string) ($event['scanner_ids'] ?? ''))))),
                'checkOutRequired' => (bool) ($event['check_out_required'] ?? false),
                'isAnnouncement' => (bool) ($event['is_announcement'] ?? false),
                'checkInStart' => $event['check_in_start'] ?? null,
                'checkInEnd' => $event['check_in_end'] ?? null,
            ],
        ];
    }

    private static function eventColor(int $eventId): string
    {
        return self::EVENT_COLORS[max(0, $eventId - 1) % count(self::EVENT_COLORS)];
    }

    private static function recollectionEntry(array $item): array
    {
        return [
            'id' => 'recollection-' . $item['id'],
            'source_id' => (int) $item['id'],
            'kind' => 'recollection',
            'title' => $item['title'],
            'description' => $item['type'] . ' for ' . $item['section_name'],
            'from' => $item['date'] . 'T' . $item['start_time'],
            'to' => ($item['end_date'] ?: $item['date']) . 'T' . $item['end_time'],
            'location' => $item['venue'],
            'group' => 'Recollections & retreats',
            'color' => '#f79009',
            'colorText' => '#ffffff',
            'customTags' => [
                'kind' => 'recollection',
                'status' => $item['status'],
                'sourceId' => (int) $item['id'],
                'type' => $item['type'],
                'endDate' => $item['end_date'] ?: $item['date'],
                'sectionId' => (int) $item['section_id'],
                'sectionName' => $item['section_name'],
                'facilitator' => $item['facilitator'] ?? '',
                'attendanceRequired' => (bool) ($item['attendance_required'] ?? true),
            ],
        ];
    }
}
