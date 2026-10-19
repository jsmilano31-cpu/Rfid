<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DomainException;
use PDO;
use Throwable;

final class EventService
{
    private const ATTENDANCE_STATUSES = ['Attended', 'Late', 'Absent', 'Excused', 'Incomplete'];

    public static function create(array $data, int $actorId, ?array $bannerImage = null, ?array $eventImages = null, ?array &$scannerCredentials = null): int
    {
        $managementRole = EventAccessService::creationRole($actorId);
        self::validateManagementSettings($data, $actorId);
        $targets = EventAudienceService::normalize($data);
        $title = normalize_title_case($data['title'] ?? '');
        $description = trim((string) ($data['description'] ?? ''));
        $eventDate = (string) ($data['event_date'] ?? '');
        $endDate = trim((string) ($data['end_date'] ?? '')) ?: null;
        $startTime = normalize_time($data['start_time'] ?? '');
        $endTime = normalize_time($data['end_time'] ?? '');
        $checkInStart = normalize_time($data['check_in_start'] ?? '', true);
        $checkInEnd = normalize_time($data['check_in_end'] ?? '', true);
        $venue = normalize_title_case($data['venue'] ?? '');
        $dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate);
        $endDateValue = $endDate === null ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
        if ($title === '' || $venue === '') {
            throw new DomainException('Enter an event title and venue.');
        }
        if (mb_strlen($description) > 2000) throw new DomainException('Keep the event description within 2,000 characters.');
        if (mb_strlen($title) > 180 || mb_strlen($venue) > 180) {
            throw new DomainException('The event title or venue is too long.');
        }
        if (!$dateValue || $dateValue->format('Y-m-d') !== $eventDate) {
            throw new DomainException('Select a valid event start date.');
        }
        if ($endDate !== null && (!$endDateValue || $endDateValue->format('Y-m-d') !== $endDate || $endDate < $eventDate)) {
            throw new DomainException('The optional end date must be on or after the start date.');
        }
        if (($endDate === null || $endDate === $eventDate) && $endTime <= $startTime) {
            throw new DomainException('Select a valid event time range.');
        }
        if (($checkInStart === null) !== ($checkInEnd === null) || ($checkInStart !== null && $checkInEnd <= $checkInStart)) {
            throw new DomainException('Select both check-in times and make sure closing is after opening.');
        }

        // Store multiple images if uploaded, fallback to single bannerImage
        $uploadedImages = [];
        if ($eventImages && !empty($eventImages['name'])) {
            $uploadedImages = UploadService::storeMultipleImages($eventImages, 'event_banner');
        } elseif ($bannerImage && !empty($bannerImage['name'])) {
            $single = UploadService::storeImage($bannerImage, 'event_banner');
            if ($single) $uploadedImages[] = $single;
        }
        $bannerImagePath = $uploadedImages[0] ?? null;

        $pdo = Database::connection();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $statement = $pdo->prepare(
                'INSERT INTO events
                    (title, description, event_date, end_date, start_time, end_time, venue, banner_image_path, attendance_required, suspends_classes, check_in_start, check_in_end, check_out_required, is_announcement, status, created_by, management_role_id)
                 VALUES (:title, :description, :event_date, :end_date, :start_time, :end_time, :venue, :banner_image_path, :required, :suspends_classes, :check_in_start, :check_in_end, :checkout_required, :is_announcement, "scheduled", :created_by, :management_role_id)'
            );
            $statement->execute([
                'title' => $title, 'description' => $description,
                'event_date' => $eventDate, 'end_date' => $endDate, 'start_time' => $startTime, 'end_time' => $endTime,
                'venue' => $venue, 'required' => isset($data['attendance_required']) ? 1 : 0,
                'suspends_classes' => isset($data['suspends_classes']) ? 1 : 0,
                'banner_image_path' => $bannerImagePath,
                'check_in_start' => $checkInStart,
                'check_in_end' => $checkInEnd,
                'checkout_required' => isset($data['check_out_required']) ? 1 : 0,
                'is_announcement' => !empty($data['is_announcement']) ? 1 : 0,
                'created_by' => $actorId,
                'management_role_id' => $managementRole,
            ]);
            $eventId = (int) $pdo->lastInsertId();
            self::saveScanners($eventId, $data, $actorId, $scannerCredentials);

            // Insert into event_images table
            if (!empty($uploadedImages)) {
                $imgInsert = $pdo->prepare('INSERT INTO event_images (event_id, image_path, display_order) VALUES (:event_id, :path, :order)');
                foreach ($uploadedImages as $idx => $imgPath) {
                    $imgInsert->execute(['event_id' => $eventId, 'path' => $imgPath, 'order' => $idx]);
                }
            }

            EventAudienceService::save($pdo, $eventId, $targets, isset($data['attendance_required']), false);
            AuditService::log($actorId, 'create_event', 'event', $eventId, $title);
            NotificationService::afterActivity('event', $eventId);
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $eventId;
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($uploadedImages as $img) {
                UploadService::removeManaged($img);
            }
            throw $exception;
        }
    }

    public static function update(int $eventId, array $data, int $actorId, ?array $bannerImage = null, ?array $eventImages = null, ?array &$scannerCredentials = null): void
    {
        $existing = EventAccessService::requireManage($eventId, $actorId);
        if (!in_array($existing['status'], ['draft','scheduled'], true)) throw new DomainException('Events with active or finalized attendance cannot be edited. Cancel the event to preserve its history.');
        self::validateManagementSettings($data, $actorId);
        if (!in_array('manage_academic_calendar', \App\Core\Auth::permissionsForUser($actorId), true) && $existing['suspends_classes']) $data['suspends_classes'] = '1';
        if ($eventId < 1) {
            throw new DomainException('Select a valid event.');
        }
        $pdo = Database::connection();
        $currentStatement = $pdo->prepare('SELECT id, status, banner_image_path FROM events WHERE id = :id');
        $currentStatement->execute(['id' => $eventId]);
        $current = $currentStatement->fetch();
        if (!$current) {
            throw new DomainException('The event could not be found.');
        }
        if ($current['status'] === 'cancelled') {
            throw new DomainException('Cancelled events cannot be edited.');
        }

        $targets = EventAudienceService::normalize($data);
        $title = normalize_title_case($data['title'] ?? '');
        $description = trim((string) ($data['description'] ?? ''));
        $eventDate = (string) ($data['event_date'] ?? '');
        $endDate = trim((string) ($data['end_date'] ?? '')) ?: null;
        $startTime = normalize_time($data['start_time'] ?? '');
        $endTime = normalize_time($data['end_time'] ?? '');
        $checkInStart = normalize_time($data['check_in_start'] ?? '', true);
        $checkInEnd = normalize_time($data['check_in_end'] ?? '', true);
        $venue = normalize_title_case($data['venue'] ?? '');
        $dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate);
        $endDateValue = $endDate === null ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
        if ($title === '' || $venue === '') {
            throw new DomainException('Enter an event title and venue.');
        }
        if (mb_strlen($title) > 180 || mb_strlen($venue) > 180 || mb_strlen($description) > 2000) {
            throw new DomainException('One or more event details are too long.');
        }
        if (!$dateValue || $dateValue->format('Y-m-d') !== $eventDate) {
            throw new DomainException('Select a valid event date and time range.');
        }
        if ($endDate !== null && (!$endDateValue || $endDateValue->format('Y-m-d') !== $endDate || $endDate < $eventDate)) {
            throw new DomainException('The optional end date must be on or after the start date.');
        }
        if (($endDate === null || $endDate === $eventDate) && $endTime <= $startTime) {
            throw new DomainException('Select a valid event time range.');
        }
        if (($checkInStart === null) !== ($checkInEnd === null) || ($checkInStart !== null && $checkInEnd <= $checkInStart)) {
            throw new DomainException('Select both check-in times and make sure closing is after opening.');
        }

        $uploadedImages = [];
        if ($eventImages && !empty($eventImages['name'])) {
            $uploadedImages = UploadService::storeMultipleImages($eventImages, 'event_banner');
        } elseif ($bannerImage && !empty($bannerImage['name'])) {
            $single = UploadService::storeImage($bannerImage, 'event_banner');
            if ($single) $uploadedImages[] = $single;
        }

        $bannerPath = !empty($uploadedImages) ? $uploadedImages[0] : $current['banner_image_path'];

        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $pdo->beginTransaction();
        }
        try {
            $locked=$pdo->prepare('SELECT status FROM events WHERE id=? FOR UPDATE');
            $locked->execute([$eventId]);
            if (!in_array($locked->fetchColumn(), ['draft','scheduled'], true)) throw new DomainException('Attendance has started for this event. Refresh to view its protected history.');
            $pdo->prepare(
                'UPDATE events SET title = :title, description = :description, event_date = :event_date, end_date = :end_date,
                    start_time = :start_time, end_time = :end_time, venue = :venue,
                    banner_image_path = :banner_image_path, attendance_required = :required, suspends_classes = :suspends_classes,
                    check_in_start = :check_in_start, check_in_end = :check_in_end,
                    check_out_required = :checkout_required, is_announcement = :is_announcement WHERE id = :id'
            )->execute([
                'title' => $title, 'description' => $description, 'event_date' => $eventDate, 'end_date' => $endDate,
                'start_time' => $startTime, 'end_time' => $endTime, 'venue' => $venue,
                'banner_image_path' => $bannerPath, 'required' => isset($data['attendance_required']) ? 1 : 0,
                'suspends_classes' => isset($data['suspends_classes']) ? 1 : 0,
                'check_in_start' => $checkInStart, 'check_in_end' => $checkInEnd,
                'checkout_required' => isset($data['check_out_required']) ? 1 : 0,
                'is_announcement' => !empty($data['is_announcement']) ? 1 : 0,
                'id' => $eventId,
            ]);
            self::saveScanners($eventId, $data, $actorId, $scannerCredentials);

            if (!empty($uploadedImages)) {
                $pdo->prepare('DELETE FROM event_images WHERE event_id = :event_id')->execute(['event_id' => $eventId]);
                $imgInsert = $pdo->prepare('INSERT INTO event_images (event_id, image_path, display_order) VALUES (:event_id, :path, :order)');
                foreach ($uploadedImages as $idx => $imgPath) {
                    $imgInsert->execute(['event_id' => $eventId, 'path' => $imgPath, 'order' => $idx]);
                }
            }

            EventAudienceService::save($pdo, $eventId, $targets, isset($data['attendance_required']), true);
            AuditService::log($actorId, 'update_event', 'event', $eventId, $title);
            NotificationService::afterActivity('event', $eventId);
            if ($ownsTransaction) {
                $pdo->commit();
            }
        } catch (Throwable $exception) {
            if ($ownsTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            foreach ($uploadedImages as $img) {
                UploadService::removeManaged($img);
            }
            throw $exception;
        }
    }

    public static function feed(int $actorId, ?string $filter = null, int $limit = 30): array
    {
        $pdo = Database::connection();
        $user = $pdo->prepare(
            'SELECT id FROM users WHERE id = :id'
        );
        $user->execute(['id' => $actorId]);
        $currentUser = $user->fetch(PDO::FETCH_ASSOC);
        if (!$currentUser) return [];

        $isAdmin = (int)(\App\Core\Auth::user()['id'] ?? 0) === $actorId && \App\Core\Auth::can('manage_events');

        $where = [];
        $params = [];

        $where[] = EventAccessService::visibleCondition($actorId);
        if (!$isAdmin) $where[] = 'events.status != "cancelled"';

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT events.*,
                       users.first_name poster_first_name, users.last_name poster_last_name,
                       users.profile_image_path poster_avatar, users.school_id poster_school_id,
                       (SELECT roles.name FROM user_roles JOIN roles ON roles.id = user_roles.role_id WHERE user_roles.user_id = users.id ORDER BY roles.id ASC LIMIT 1) poster_role,
                       (SELECT target_type FROM event_target_groups WHERE event_id=events.id ORDER BY id LIMIT 1) target_type,
                       (SELECT target_id FROM event_target_groups WHERE event_id=events.id ORDER BY id LIMIT 1) target_id,
                       (SELECT COUNT(*) FROM event_participants WHERE event_participants.event_id = events.id) participant_count,
                       (SELECT COUNT(*) FROM event_attendance WHERE event_attendance.event_id = events.id AND event_attendance.status = 'Attended') attended_count,
                       (SELECT status FROM event_attendance WHERE event_attendance.event_id = events.id AND event_attendance.user_id = :my_user_id LIMIT 1) my_attendance_status
                FROM events
                JOIN users ON users.id = events.created_by
                {$whereSql}
                ORDER BY events.event_date DESC, events.created_at DESC
                LIMIT " . (int) $limit;

        $params['my_user_id'] = $actorId;
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $events = $statement->fetchAll(PDO::FETCH_ASSOC);

        if (!$events) return [];

        $eventIds = array_column($events, 'id');
        $targetsByEvent = EventAudienceService::targetsByEventIds($eventIds);
        $inPlaceholders = implode(',', array_fill(0, count($eventIds), '?'));
        $imagesStmt = $pdo->prepare("SELECT event_id, image_path, display_order FROM event_images WHERE event_id IN ({$inPlaceholders}) ORDER BY display_order ASC, id ASC");
        $imagesStmt->execute($eventIds);
        $allImages = $imagesStmt->fetchAll(PDO::FETCH_ASSOC);

        $imagesByEvent = [];
        foreach ($allImages as $img) {
            $imagesByEvent[$img['event_id']][] = $img['image_path'];
        }

        return array_map(function (array $event) use ($imagesByEvent, $targetsByEvent, $isAdmin, $actorId): array {
            $images = $imagesByEvent[$event['id']] ?? [];
            if (empty($images) && !empty($event['banner_image_path'])) {
                $images[] = $event['banner_image_path'];
            }

            $audienceLabel = match ($event['target_type'] ?? 'everyone') {
                'everyone' => 'University-wide',
                'all_students' => 'All Students',
                'all_teachers' => 'All Faculty & Teachers',
                'all_staff' => 'Campus Staff',
                'section' => 'Selected section',
                default => 'Campus Community',
            };
            $targetLabels = array_column($targetsByEvent[(int) $event['id']] ?? [], 'label');
            if ($targetLabels) $audienceLabel = implode(', ', array_slice($targetLabels, 0, 3)) . (count($targetLabels) > 3 ? ' +' . (count($targetLabels) - 3) . ' more' : '');

            $today = date('Y-m-d');
            $isAnnouncement = !empty($event['is_announcement']);
            $eventDateStr = $event['event_date'];
            $endDateStr = !empty($event['end_date']) ? $event['end_date'] : $eventDateStr;

            // Inactive events: Past events or closed
            $isInactive = !$isAnnouncement && ($endDateStr < $today || in_array($event['status'], ['closed', 'cancelled'], true));
            // Active events: Happens on current date or scheduled
            $isActive = !$isAnnouncement && !$isInactive;

            $category = $isAnnouncement ? 'announcements' : ($isActive ? 'active' : 'inactive');

            $createdAt = new DateTimeImmutable($event['created_at']);
            $eventDate = new DateTimeImmutable($event['event_date']);
            $endDate = !empty($event['end_date']) ? new DateTimeImmutable($event['end_date']) : null;

            return [
                'id' => (int) $event['id'],
                'title' => $event['title'],
                'description' => $event['description'] ?? '',
                'event_date' => $event['event_date'],
                'end_date' => $event['end_date'] ?? null,
                'start_time' => $event['start_time'],
                'end_time' => $event['end_time'],
                'check_in_start' => $event['check_in_start'] ?? null,
                'check_in_end' => $event['check_in_end'] ?? null,
                'venue' => $event['venue'],
                'status' => $event['status'],
                'attendance_required' => (bool) $event['attendance_required'],
                'suspends_classes' => (bool) ($event['suspends_classes'] ?? false),
                'check_out_required' => (bool) $event['check_out_required'],
                'is_announcement' => $isAnnouncement,
                'category' => $category,
                'audience_type' => $event['target_type'] ?? 'everyone',
                'target_id' => $event['target_id'] ?? null,
                'audience_label' => $audienceLabel,
                'targets' => $targetsByEvent[(int) $event['id']] ?? [],
                'participant_count' => (int) $event['participant_count'],
                'attended_count' => (int) $event['attended_count'],
                'my_attendance_status' => $event['my_attendance_status'] ?? null,
                'images' => $images,
                'image_count' => count($images),
                'banner_image_path' => $event['banner_image_path'] ?? ($images[0] ?? null),
                'poster' => [
                    'id' => (int) $event['created_by'],
                    'name' => trim($event['poster_first_name'] . ' ' . $event['poster_last_name']),
                    'avatar' => $event['poster_avatar'] ?: '/assets/images/default-avatar.svg',
                    'role' => $event['poster_role'] ?: 'Campus Organizer',
                    'school_id' => $event['poster_school_id'],
                ],
                'formatted_date' => $endDate && $endDate->format('Y-m-d') !== $eventDate->format('Y-m-d')
                    ? $eventDate->format('M j') . ' – ' . $endDate->format('M j, Y')
                    : $eventDate->format('F j, Y'),
                'formatted_time' => format_time($event['start_time']) . ' – ' . format_time($event['end_time']),
                'formatted_created_at' => $createdAt->format('M j, Y \a\t g:i A'),
                'can_manage' => EventAccessService::canManage($event, $actorId),
            ];
        }, $events);
    }

    public static function categorized(int $actorId): array
    {
        $all = self::feed($actorId, null, 100);
        $sections = [
            'announcements' => [],
            'active' => [],
            'inactive' => [],
        ];
        foreach ($all as $item) {
            $cat = $item['category'] ?? 'active';
            if (isset($sections[$cat])) {
                $sections[$cat][] = $item;
            } else {
                $sections['active'][] = $item;
            }
        }
        return $sections;
    }

    public static function activeForDevice(int $deviceId, string $date, string $time): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT events.id, events.title, events.event_date, events.end_date, events.start_time, events.end_time,
                    events.check_in_start, events.check_in_end, events.check_out_required
             FROM event_scanners
             JOIN events ON events.id = event_scanners.event_id
             WHERE event_scanners.device_id = :device
               AND events.attendance_required = 1
               AND events.status IN ("scheduled", "open")
               AND :event_date BETWEEN events.event_date AND COALESCE(events.end_date, events.event_date)
               AND :scan_time_start >= COALESCE(events.check_in_start, events.start_time)
               AND :scan_time_end <= GREATEST(COALESCE(events.check_in_end, events.end_time),events.end_time)
             ORDER BY events.start_time LIMIT 2'
        );
        $statement->execute(['device' => $deviceId, 'event_date' => $date, 'scan_time_start' => $time, 'scan_time_end' => $time]);
        $events = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($events) > 1) throw new DomainException('This scanner is assigned to overlapping events. Correct the event schedule before scanning.');
        return $events[0] ?? null;
    }

    public static function recordRfidScan(int $eventId, string $uid, ?int $actorId = null): array
    {
        if ($actorId !== null) EventAccessService::requireManage($eventId, $actorId);
        $user = RfidService::userForUid($uid);
        self::recordArrival($eventId, (int)$user['id'], 'rfid', $actorId);
        return $user;
    }

    public static function recordManual(int $eventId, int $userId, string $status, int $actorId, string $reason): void
    {
        EventAccessService::requireManage($eventId, $actorId);
        if (!in_array($status, self::ATTENDANCE_STATUSES, true)) throw new DomainException('Invalid event attendance status.');
        if (trim($reason) === '') throw new DomainException('A correction reason is required.');
        $pdo = Database::connection();
        $participant = $pdo->prepare('SELECT id FROM event_participants WHERE event_id = :event AND user_id = :user');
        $participant->execute(['event' => $eventId, 'user' => $userId]);
        if (!$participant->fetchColumn()) throw new DomainException('The selected user is not an event participant.');
        $checkIn = in_array($status, ['Attended', 'Late', 'Incomplete'], true) ? date('Y-m-d H:i:s') : null;
        $pdo->prepare(
            'INSERT INTO event_attendance (event_id, user_id, check_in_at, status, source, recorded_by, notes)
             VALUES (:event, :user, :check_in, :status, "manual", :actor, :reason)
             ON DUPLICATE KEY UPDATE check_in_at = COALESCE(check_in_at, VALUES(check_in_at)), status = VALUES(status),
                                     source = "manual", recorded_by = VALUES(recorded_by), notes = VALUES(notes)'
        )->execute(['event' => $eventId, 'user' => $userId, 'check_in' => $checkIn, 'status' => $status, 'actor' => $actorId, 'reason' => trim($reason)]);
        AuditService::log($actorId, 'correct_event_attendance', 'event', $eventId, "User {$userId}: {$status}; {$reason}");
    }

    public static function setStatus(int $eventId, string $status, int $actorId): void
    {
        EventAccessService::requireManage($eventId, $actorId);
        if (!in_array($status, ['scheduled', 'open', 'closed', 'cancelled'], true)) throw new DomainException('Invalid event status.');
        $pdo = Database::connection();
        $ownsTransaction=!$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
        $pdo->prepare('SELECT id FROM events WHERE id=? FOR UPDATE')->execute([$eventId]);
        if ($status === 'closed') {
            $pdo->prepare(
                'UPDATE event_attendance JOIN events ON events.id = event_attendance.event_id
                 SET event_attendance.status = "Incomplete"
                 WHERE event_attendance.event_id = :event AND events.check_out_required = 1
                   AND event_attendance.check_in_at IS NOT NULL AND event_attendance.check_out_at IS NULL'
            )->execute(['event' => $eventId]);
            $pdo->prepare(
                'INSERT INTO event_attendance (event_id, user_id, status, source, recorded_by, notes)
                 SELECT participants.event_id, participants.user_id, "Absent", "system", :actor, "Missing when event attendance closed"
                 FROM event_participants participants
                 LEFT JOIN event_attendance attendance ON attendance.event_id = participants.event_id AND attendance.user_id = participants.user_id
                 WHERE participants.event_id = :event AND participants.is_required = 1 AND attendance.id IS NULL'
            )->execute(['actor' => $actorId, 'event' => $eventId]);
        }
        $statement = $pdo->prepare('UPDATE events SET status = :status WHERE id = :id');
        $statement->execute(['status' => $status, 'id' => $eventId]);
        if ($statement->rowCount() === 0) throw new DomainException('Event not found or status unchanged.');
        AuditService::log($actorId, 'set_event_status', 'event', $eventId, $status);
        NotificationService::afterActivity('event', $eventId);
        if ($ownsTransaction) $pdo->commit();
        } catch (Throwable $e) { if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    }

    public static function delete(int $eventId, int $actorId, bool $removeImage = true): array
    {
        EventAccessService::requireManage($eventId, $actorId);
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT id, title, status, banner_image_path FROM events WHERE id = :id');
        $statement->execute(['id' => $eventId]);
        $event = $statement->fetch();
        if (!$event) throw new DomainException('The selected event could not be found.');
        DeletionConflictService::assertClear('event', $eventId);
        if (!in_array($event['status'], ['draft', 'scheduled', 'cancelled'], true)) {
            throw new DomainException('Events with open or finalized attendance cannot be deleted. Cancel the event to preserve its history.');
        }
        try {
            $pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $eventId]);
        } catch (\PDOException $exception) {
            if ((int)($exception->errorInfo[1] ?? 0) === 1451) {
                DeletionConflictService::assertClear('event', $eventId);
                throw new DomainException('This event gained protected history while deletion was in progress. Refresh and try again.');
            }
            throw $exception;
        }
        AuditService::log($actorId, 'delete_event', 'event', $eventId, (string) $event['title']);
        NotificationService::afterActivity('event', $eventId);
        if ($removeImage) UploadService::removeManaged($event['banner_image_path'] ?? null);
        return ['id' => $eventId, 'label' => $event['title']];
    }

    private static function assertOpen(int $eventId, bool $openScheduled = false): void
    {
        $statement = Database::connection()->prepare('SELECT status FROM events WHERE id = :id FOR UPDATE');
        $statement->execute(['id' => $eventId]);
        $status = $statement->fetchColumn();
        if ($status === 'scheduled' && $openScheduled) {
            Database::connection()->prepare('UPDATE events SET status = "open" WHERE id = :id')->execute(['id' => $eventId]);
            return;
        }
        if ($status !== 'open') throw new DomainException('Open event attendance before recording scans.');
    }

    private static function validateManagementSettings(array $data, int $actorId): void
    {
        $permissions = \App\Core\Auth::permissionsForUser($actorId);
        if (isset($data['suspends_classes']) && !in_array('manage_academic_calendar', $permissions, true)) throw new DomainException('Class suspension requires Manage Academic Calendar permission.');
        if ((trim((string)($data['new_scanner_device_code'] ?? '')) !== '' || trim((string)($data['new_scanner_name'] ?? '')) !== '') && !in_array('manage_rooms', $permissions, true)) throw new DomainException('Creating a scanner requires Manage Rooms permission.');
    }

    /** Both scanners and the school-ID fallback write the same server-timed, locked observation. */
    public static function recordArrival(int $eventId, int $userId, string $source, ?int $actorId, string $action='auto'): array
    {
        if ($actorId !== null) EventAccessService::requireManage($eventId, $actorId);
        if (!in_array($source, ['rfid','manual'], true) || !in_array($action, ['auto','check_in','check_out'], true)) throw new DomainException('Select a valid attendance action.');
        $pdo=Database::connection(); $ownsTransaction=!$pdo->inTransaction();
        if ($ownsTransaction) $pdo->beginTransaction();
        try {
            $q=$pdo->prepare('SELECT * FROM events WHERE id=? FOR UPDATE');$q->execute([$eventId]);$event=$q->fetch();
            if (!$event || !in_array($event['status'],['scheduled','open'],true) || !$event['attendance_required']) throw new DomainException('This event is not accepting attendance.');
            $now=(string)$pdo->query('SELECT NOW()')->fetchColumn();$date=substr($now,0,10);$time=substr($now,11);
            if ($date<$event['event_date'] || $date>($event['end_date']?:$event['event_date'])) throw new DomainException('Attendance is available only on the event dates.');
            $q=$pdo->prepare('SELECT u.id,u.school_id,u.first_name,u.middle_name,u.last_name,u.profile_image_path FROM event_participants p JOIN users u ON u.id=p.user_id WHERE p.event_id=? AND p.user_id=? AND u.status="active"');$q->execute([$eventId,$userId]);$person=$q->fetch();
            if (!$person) throw new DomainException('This ID is not an eligible active event participant.');
            $q=$pdo->prepare('SELECT * FROM event_attendance WHERE event_id=? AND user_id=? FOR UPDATE');$q->execute([$eventId,$userId]);$row=$q->fetch();
            $sameDay=$row && substr((string)$row['check_in_at'],0,10)===$date;
            $selected=$action==='auto'?($sameDay?'check_out':'check_in'):$action;
            if ($sameDay && $row['check_out_at'] && substr($row['check_out_at'],0,10)===$date) throw new DomainException('Attendance is already complete for this participant today.');
            if ($selected==='check_out') {
                if (!$sameDay) throw new DomainException('Record a check-in for today before checking out.');
                if (!$event['check_out_required']) throw new DomainException('Check-out is not required for this event.');
                if (strtotime($now)-strtotime($row['check_in_at'])<30) throw new DomainException('Check-in was just recorded. Wait before checking out to avoid a duplicate scan.');
                if ($time>max($event['end_time'],$event['check_in_end']?:$event['end_time'])) throw new DomainException('The event attendance window has ended.');
                $pdo->prepare('UPDATE event_attendance SET check_out_at=?,status="Attended",source=?,recorded_by=? WHERE id=?')->execute([$now,$source,$actorId,$row['id']]);
            } else {
                if ($sameDay) throw new DomainException('This participant is already checked in today.');
                if ($time<($event['check_in_start']?:$event['start_time']) || $time>($event['check_in_end']?:$event['end_time'])) throw new DomainException('The check-in window is not open.');
                $pdo->prepare('INSERT INTO event_attendance(event_id,user_id,check_in_at,status,source,recorded_by) VALUES(?,?,?,"Attended",?,?) ON DUPLICATE KEY UPDATE check_in_at=VALUES(check_in_at),check_out_at=NULL,status="Attended",source=VALUES(source),recorded_by=VALUES(recorded_by)')->execute([$eventId,$userId,$now,$source,$actorId]);
            }
            if ($event['status']==='scheduled') $pdo->prepare('UPDATE events SET status="open" WHERE id=?')->execute([$eventId]);
            if ($actorId!==null) AuditService::log($actorId,'event_'.$selected,'event',$eventId,'Manual ID entry for user '.$userId);
            $q=$pdo->prepare('SELECT id FROM event_activity_logs WHERE event_id=? AND user_id=? AND action=? AND occurred_at=? ORDER BY id DESC LIMIT 1');
            $q->execute([$eventId,$userId,$selected,$now]);$activityId=(int)$q->fetchColumn();
            if ($ownsTransaction) $pdo->commit();
            return ['id'=>$activityId,'person'=>$person,'action'=>$selected,'occurred_at'=>$now];
        } catch (Throwable $e) {if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();throw $e;}
    }

    private static function saveScanners(int $eventId, array $data, int $actorId, ?array &$scannerCredentials): void
    {
        $pdo = Database::connection();
        $scannerIds = array_values(array_unique(array_filter(array_map('intval', (array) ($data['scanner_ids'] ?? [])))));
        $newCode = trim((string) ($data['new_scanner_device_code'] ?? ''));
        $newName = trim((string) ($data['new_scanner_name'] ?? ''));
        if ($newCode !== '' || $newName !== '') {
            $created = RoomService::createDevice([
                'device_code' => $newCode,
                'name' => $newName,
                'room_id' => (int) ($data['new_scanner_room_id'] ?? 0),
            ], $actorId);
            $scannerIds[] = (int) $created['device']['id'];
            $scannerCredentials = $created;
        }
        if (!isset($data['attendance_required'])) $scannerIds = [];
        $pdo->prepare('DELETE FROM event_scanners WHERE event_id = :event')->execute(['event' => $eventId]);
        if (!$scannerIds) return;
        $check = $pdo->prepare('SELECT id FROM devices WHERE id = :id AND status = "active"');
        $insert = $pdo->prepare('INSERT INTO event_scanners (event_id, device_id) VALUES (:event, :device)');
        $conflict = $pdo->prepare(
            'SELECT existing.title
             FROM event_scanners assigned
             JOIN events existing ON existing.id = assigned.event_id
             JOIN events current_event ON current_event.id = :current_event
             WHERE assigned.device_id = :device AND existing.id <> :other_event
               AND existing.status <> "cancelled"
               AND current_event.event_date <= COALESCE(existing.end_date, existing.event_date)
               AND existing.event_date <= COALESCE(current_event.end_date, current_event.event_date)
               AND (existing.event_date <> COALESCE(existing.end_date, existing.event_date)
                    OR current_event.event_date <> COALESCE(current_event.end_date, current_event.event_date)
                    OR (current_event.start_time < existing.end_time AND current_event.end_time > existing.start_time))
             LIMIT 1'
        );
        foreach ($scannerIds as $deviceId) {
            $check->execute(['id' => $deviceId]);
            if (!$check->fetchColumn()) throw new DomainException('Select only active scanners for event attendance.');
            $conflict->execute(['current_event' => $eventId, 'device' => $deviceId, 'other_event' => $eventId]);
            $conflictingTitle = $conflict->fetchColumn();
            if ($conflictingTitle) throw new DomainException("That scanner is already assigned to the overlapping event “{$conflictingTitle}”. Choose another scanner or adjust the schedule.");
            $insert->execute(['event' => $eventId, 'device' => $deviceId]);
        }
    }
}
