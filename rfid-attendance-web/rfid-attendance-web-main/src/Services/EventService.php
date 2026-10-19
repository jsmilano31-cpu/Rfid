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
        $audience = $data['audience'] ?? 'all_students';
        if (!in_array($audience, ['everyone', 'all_students', 'all_teachers', 'all_staff', 'section'], true)) {
            throw new DomainException('Invalid event audience.');
        }
        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $eventDate = (string) ($data['event_date'] ?? '');
        $endDate = trim((string) ($data['end_date'] ?? '')) ?: null;
        $startTime = normalize_time($data['start_time'] ?? '');
        $endTime = normalize_time($data['end_time'] ?? '');
        $checkInStart = normalize_time($data['check_in_start'] ?? '', true);
        $checkInEnd = normalize_time($data['check_in_end'] ?? '', true);
        $venue = trim((string) ($data['venue'] ?? ''));
        $dateValue = DateTimeImmutable::createFromFormat('!Y-m-d', $eventDate);
        $endDateValue = $endDate === null ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $endDate);
        if ($title === '' || $venue === '') {
            throw new DomainException('Enter an event title and venue.');
        }
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
        $targetId = !empty($data['target_id']) ? (int) $data['target_id'] : null;
        if ($audience === 'section' && $targetId === null) {
            throw new DomainException('Select a section for this event.');
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
                    (title, description, event_date, end_date, start_time, end_time, venue, banner_image_path, attendance_required, suspends_classes, check_in_start, check_in_end, check_out_required, is_announcement, status, created_by)
                 VALUES (:title, :description, :event_date, :end_date, :start_time, :end_time, :venue, :banner_image_path, :required, :suspends_classes, :check_in_start, :check_in_end, :checkout_required, :is_announcement, "scheduled", :created_by)'
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

            $pdo->prepare('INSERT INTO event_target_groups (event_id, target_type, target_id) VALUES (:event_id, :type, :target_id)')
                ->execute(['event_id' => $eventId, 'type' => $audience, 'target_id' => $targetId]);

            [$condition, $value] = match ($audience) {
                'section' => ['users.section_id = :target_id', $targetId],
                'all_teachers' => ['EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id = 2)', null],
                'all_staff' => ['EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id IN (4, 5))', null],
                'everyone' => ['1 = 1', null],
                default => ['EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id = 3)', null],
            };
            $sql = "INSERT INTO event_participants (event_id, user_id, is_required) SELECT :event_id, users.id, :required FROM users WHERE users.status = 'active' AND {$condition}";
            $participants = $pdo->prepare($sql);
            $params = ['event_id' => $eventId, 'required' => isset($data['attendance_required']) ? 1 : 0];
            if ($value !== null) {
                $params['target_id'] = $value;
            }
            $participants->execute($params);
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

        $audience = $data['audience'] ?? 'all_students';
        if (!in_array($audience, ['everyone', 'all_students', 'all_teachers', 'all_staff', 'section'], true)) {
            throw new DomainException('Invalid event audience.');
        }
        $title = trim((string) ($data['title'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $eventDate = (string) ($data['event_date'] ?? '');
        $endDate = trim((string) ($data['end_date'] ?? '')) ?: null;
        $startTime = normalize_time($data['start_time'] ?? '');
        $endTime = normalize_time($data['end_time'] ?? '');
        $checkInStart = normalize_time($data['check_in_start'] ?? '', true);
        $checkInEnd = normalize_time($data['check_in_end'] ?? '', true);
        $venue = trim((string) ($data['venue'] ?? ''));
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
        $targetId = !empty($data['target_id']) ? (int) $data['target_id'] : null;
        if ($audience === 'section' && $targetId === null) {
            throw new DomainException('Select a section for this event.');
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

            $pdo->prepare('DELETE FROM event_target_groups WHERE event_id = :event')->execute(['event' => $eventId]);
            $pdo->prepare('INSERT INTO event_target_groups (event_id, target_type, target_id) VALUES (:event, :type, :target)')
                ->execute(['event' => $eventId, 'type' => $audience, 'target' => $targetId]);
            $pdo->prepare('DELETE FROM event_participants WHERE event_id = :event AND user_id NOT IN (SELECT user_id FROM event_attendance WHERE event_id = :event_attendance)')
                ->execute(['event' => $eventId, 'event_attendance' => $eventId]);
            [$condition, $value] = match ($audience) {
                'section' => ['users.section_id = :target_id', $targetId],
                'all_teachers' => ['EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id = 2)', null],
                'all_staff' => ['EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id IN (4, 5))', null],
                'everyone' => ['1 = 1', null],
                default => ['EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id = 3)', null],
            };
            $participants = $pdo->prepare("INSERT INTO event_participants (event_id, user_id, is_required) SELECT :event_id, users.id, :required FROM users WHERE users.status = 'active' AND {$condition} ON DUPLICATE KEY UPDATE is_required = VALUES(is_required)");
            $parameters = ['event_id' => $eventId, 'required' => isset($data['attendance_required']) ? 1 : 0];
            if ($value !== null) {
                $parameters['target_id'] = $value;
            }
            $participants->execute($parameters);
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
            'SELECT users.id, users.section_id,
                    (SELECT GROUP_CONCAT(roles.name) FROM user_roles JOIN roles ON roles.id = user_roles.role_id WHERE user_roles.user_id = users.id) role_names
             FROM users WHERE users.id = :id'
        );
        $user->execute(['id' => $actorId]);
        $currentUser = $user->fetch(PDO::FETCH_ASSOC);
        if (!$currentUser) return [];

        $roleNames = explode(',', (string) ($currentUser['role_names'] ?? ''));
        $isAdmin = in_array('Administrator', $roleNames, true) || in_array('Super Administrator', $roleNames, true) || in_array('Campus Coordinator', $roleNames, true);
        $isTeacher = in_array('Teacher', $roleNames, true);
        $isStudent = in_array('Student', $roleNames, true);
        $isStaff = in_array('Staff', $roleNames, true) || in_array('Officer', $roleNames, true);
        $userSectionId = (int) ($currentUser['section_id'] ?? 0);

        $where = [];
        $params = [];

        if (!$isAdmin) {
            $where[] = 'events.status != "cancelled"';
            $where[] = '(
                event_target_groups.target_type = "everyone"
                OR (event_target_groups.target_type = "all_students" AND :is_student = 1)
                OR (event_target_groups.target_type = "all_teachers" AND :is_teacher = 1)
                OR (event_target_groups.target_type = "all_staff" AND :is_staff = 1)
                OR (event_target_groups.target_type = "section" AND event_target_groups.target_id = :user_section_id)
                OR EXISTS(SELECT 1 FROM event_participants WHERE event_participants.event_id = events.id AND event_participants.user_id = :actor_id)
            )';
            $params['is_student'] = $isStudent ? 1 : 0;
            $params['is_teacher'] = $isTeacher ? 1 : 0;
            $params['is_staff'] = $isStaff ? 1 : 0;
            $params['user_section_id'] = $userSectionId;
            $params['actor_id'] = $actorId;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "SELECT events.*,
                       users.first_name poster_first_name, users.last_name poster_last_name,
                       users.profile_image_path poster_avatar, users.school_id poster_school_id,
                       (SELECT roles.name FROM user_roles JOIN roles ON roles.id = user_roles.role_id WHERE user_roles.user_id = users.id ORDER BY roles.id ASC LIMIT 1) poster_role,
                       event_target_groups.target_type, event_target_groups.target_id,
                       sections.name target_section_name,
                       (SELECT COUNT(*) FROM event_participants WHERE event_participants.event_id = events.id) participant_count,
                       (SELECT COUNT(*) FROM event_attendance WHERE event_attendance.event_id = events.id AND event_attendance.status = 'Attended') attended_count,
                       (SELECT status FROM event_attendance WHERE event_attendance.event_id = events.id AND event_attendance.user_id = :my_user_id LIMIT 1) my_attendance_status
                FROM events
                JOIN users ON users.id = events.created_by
                LEFT JOIN event_target_groups ON event_target_groups.event_id = events.id
                LEFT JOIN sections ON sections.id = event_target_groups.target_id AND event_target_groups.target_type = 'section'
                {$whereSql}
                GROUP BY events.id
                ORDER BY events.event_date DESC, events.created_at DESC
                LIMIT " . (int) $limit;

        $params['my_user_id'] = $actorId;
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $events = $statement->fetchAll(PDO::FETCH_ASSOC);

        if (!$events) return [];

        $eventIds = array_column($events, 'id');
        $inPlaceholders = implode(',', array_fill(0, count($eventIds), '?'));
        $imagesStmt = $pdo->prepare("SELECT event_id, image_path, display_order FROM event_images WHERE event_id IN ({$inPlaceholders}) ORDER BY display_order ASC, id ASC");
        $imagesStmt->execute($eventIds);
        $allImages = $imagesStmt->fetchAll(PDO::FETCH_ASSOC);

        $imagesByEvent = [];
        foreach ($allImages as $img) {
            $imagesByEvent[$img['event_id']][] = $img['image_path'];
        }

        return array_map(function (array $event) use ($imagesByEvent, $isAdmin, $actorId): array {
            $images = $imagesByEvent[$event['id']] ?? [];
            if (empty($images) && !empty($event['banner_image_path'])) {
                $images[] = $event['banner_image_path'];
            }

            $audienceLabel = match ($event['target_type'] ?? 'everyone') {
                'everyone' => 'University-wide',
                'all_students' => 'All Students',
                'all_teachers' => 'All Faculty & Teachers',
                'all_staff' => 'Campus Staff',
                'section' => !empty($event['target_section_name']) ? 'Section ' . $event['target_section_name'] : 'Target Section',
                default => 'Campus Community',
            };

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
                'can_manage' => $isAdmin || (int) $event['created_by'] === $actorId,
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
               AND :scan_time_end <= COALESCE(events.check_in_end, events.end_time)
             ORDER BY events.start_time LIMIT 2'
        );
        $statement->execute(['device' => $deviceId, 'event_date' => $date, 'scan_time_start' => $time, 'scan_time_end' => $time]);
        $events = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($events) > 1) throw new DomainException('This scanner is assigned to overlapping events. Correct the event schedule before scanning.');
        return $events[0] ?? null;
    }

    public static function recordRfidScan(int $eventId, string $uid, ?int $actorId = null): array
    {
        $user = RfidService::userForUid($uid);
        $pdo = Database::connection();
        self::assertOpen($eventId, true);
        $statement = $pdo->prepare('SELECT * FROM event_participants WHERE event_id = :event_id AND user_id = :user_id');
        $statement->execute(['event_id' => $eventId, 'user_id' => $user['id']]);
        if (!$statement->fetch()) {
            throw new DomainException('The scanned user is not an eligible event participant.');
        }
        $pdo->prepare(
            'INSERT INTO event_attendance (event_id, user_id, check_in_at, status, source, recorded_by)
             VALUES (:event_id, :user_id, NOW(), "Attended", "rfid", :actor_id)
             ON DUPLICATE KEY UPDATE check_out_at = IF(check_in_at IS NULL, NULL, NOW()),
                                     check_in_at = COALESCE(check_in_at, NOW()), status = "Attended", source = "rfid", recorded_by = VALUES(recorded_by)'
        )->execute(['event_id' => $eventId, 'user_id' => $user['id'], 'actor_id' => $actorId]);
        if ($actorId !== null) AuditService::log($actorId, 'rfid_event_scan', 'event', $eventId, "User {$user['id']}");
        return $user;
    }

    public static function recordManual(int $eventId, int $userId, string $status, int $actorId, string $reason): void
    {
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
        if (!in_array($status, ['scheduled', 'open', 'closed', 'cancelled'], true)) throw new DomainException('Invalid event status.');
        $pdo = Database::connection();
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
    }

    public static function delete(int $eventId, int $actorId): array
    {
        $pdo = Database::connection();
        $statement = $pdo->prepare('SELECT id, title, status, banner_image_path FROM events WHERE id = :id');
        $statement->execute(['id' => $eventId]);
        $event = $statement->fetch();
        if (!$event) throw new DomainException('The selected event could not be found.');
        if (!in_array($event['status'], ['draft', 'scheduled', 'cancelled'], true)) {
            throw new DomainException('Events with open or finalized attendance cannot be deleted. Cancel the event to preserve its history.');
        }
        $attendance = $pdo->prepare('SELECT COUNT(*) FROM event_attendance WHERE event_id = :id');
        $attendance->execute(['id' => $eventId]);
        if ((int) $attendance->fetchColumn() > 0) {
            throw new DomainException('This event has attendance history and cannot be permanently deleted. Cancel it instead.');
        }
        $pdo->prepare('DELETE FROM events WHERE id = :id')->execute(['id' => $eventId]);
        AuditService::log($actorId, 'delete_event', 'event', $eventId, (string) $event['title']);
        NotificationService::afterActivity('event', $eventId);
        UploadService::removeManaged($event['banner_image_path'] ?? null);
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
