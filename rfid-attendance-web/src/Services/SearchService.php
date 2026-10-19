<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;

final class SearchService
{
    private static int $fetchLimit = 60;
    public const CATEGORIES = ['pages', 'people', 'academics', 'facilities', 'activities', 'seat_plans'];
    private const LABELS = ['pages' => 'Pages & actions', 'people' => 'People', 'academics' => 'Academics', 'facilities' => 'Rooms & devices', 'activities' => 'Campus activities', 'seat_plans' => 'Seat plans'];

    public static function navigation(bool $admin): array
    {
        return $admin ? [
            ['key'=>'dashboard','label'=>'Dashboard'],
            ['key'=>'schedule','label'=>'Schedule','roles'=>['Teacher','Student'],'aliases'=>'timetable classes subjects'],
            ['key'=>'users','label'=>'Users & RFID','permission'=>'manage_users','aliases'=>'people accounts rfid'],
            ['key'=>'academics','label'=>'Academics','permission'=>'manage_academic_structure','aliases'=>'subjects courses sections programs departments semesters school years'],
            ['key'=>'rooms','label'=>'Rooms & Devices','permission'=>'manage_rooms','aliases'=>'scanners facilities seat plans'],
            ['key'=>'attendance','label'=>'Attendance Policies','permission'=>'manage_attendance','aliases'=>'attendance register roster'],
            ['key'=>'events','label'=>'Events','permission'=>'manage_events','aliases'=>'announcements'],
            ['key'=>'recollections','label'=>'Recollections','permission'=>'manage_recollections','aliases'=>'retreats'],
            ['key'=>'compliance','label'=>'Compliance','permissions'=>Auth::STUDENT_COMPLIANCE_PERMISSIONS,'aliases'=>'disciplinary violations deficiencies reports attendance summaries exports'],
            ['key'=>'audit-trail','label'=>'Audit Trail','permission'=>'view_audit_logs','aliases'=>'audit logs history changes database activity tracking'],
            ['key'=>'grades','label'=>'Grades','permission'=>'manage_grades','aliases'=>'transcript marks'],
            ['key'=>'messages','label'=>'Messages','permission'=>'send_messages','aliases'=>'inbox compose'],
            ['key'=>'analytics','label'=>'Analytics','permission'=>'view_analytics','aliases'=>'analytics trends insights participation outcomes'],
            ['key'=>'settings','label'=>'Settings','aliases'=>'preferences appearance notifications account'],
        ] : [
            ['key'=>'dashboard','label'=>'Dashboard'],
            ['key'=>'attendance','label'=>'Attendance','permission'=>'view_attendance','aliases'=>'presence roster'],
            ['key'=>'grades','label'=>'Grades','permission'=>'view_grades','aliases'=>'transcript marks'],
            ['key'=>'schedule','label'=>'Schedule','aliases'=>'timetable classes subjects'],
            ['key'=>'seat-plans','label'=>'Seat Plans','permission'=>'manage_seat_plans','aliases'=>'classroom seating'],
            ['key'=>'events','label'=>'Events','aliases'=>'announcements'],
            ['key'=>'recollections','label'=>'Recollections','aliases'=>'retreats'],
            ['key'=>'compliance','label'=>'Compliance','permission'=>'view_compliance'],
            ['key'=>'analytics','label'=>'Analytics','permission'=>'view_analytics','aliases'=>'analytics trends insights participation outcomes'],
            ['key'=>'messages','label'=>'Messages','aliases'=>'inbox compose'],
            ['key'=>'people','label'=>'People','aliases'=>'directory profiles'],
            ['key'=>'profile','label'=>'Profile','aliases'=>'my account'],
            ['key'=>'settings','label'=>'Settings','aliases'=>'preferences appearance notifications account'],
        ];
    }

    public static function visibleNavigation(bool $admin): array
    {
        $visible = [];
        foreach (self::navigation($admin) as $item) {
            if ($item['key'] === 'profile' && !\App\Core\PermissionRegistry::showOwnProfile()) continue;
            if (isset($item['roles']) && !Auth::hasAnyRole($item['roles'])) continue;
            $permissions = \App\Core\PermissionRegistry::pagePermissions($item['key'], $admin);
            if ($permissions && Auth::canAny($permissions)) $visible[$item['key']] = $item;
        }
        return $visible;
    }

    private static function actions(bool $admin): array
    {
        return $admin ? [
            ['label'=>'Add user','key'=>'add-user','page'=>'users','permission'=>'manage_users','target'=>'#create-user-dialog'],
            ['label'=>'Create role','key'=>'create-role','page'=>'users','permission'=>'manage_roles','target'=>'#create-role-dialog'],
            ['label'=>'Add course','key'=>'add-course','page'=>'academics','permission'=>'manage_academic_structure','target'=>'#academics-course-catalog'],
            ['label'=>'Create class offering','key'=>'create-offering','page'=>'academics','permission'=>'manage_academic_structure','target'=>'#academics-class-offerings'],
            ['label'=>'Add room','key'=>'add-room','page'=>'rooms','permission'=>'manage_rooms','target'=>'#rooms-device-setup'],
            ['label'=>'Create event','key'=>'create-event','page'=>'events','permission'=>'manage_events','target'=>'#events-setup'],
            ['label'=>'Create recollection','key'=>'create-recollection','page'=>'recollections','permission'=>'manage_recollections','target'=>'#recollections-setup'],
            ['label'=>'Student transcript lookup','key'=>'student-transcript','page'=>'grades','permission'=>'manage_grades','target'=>'#admin-grades-students'],
        ] : [
            ['label'=>'Correct class attendance','key'=>'correct-attendance','page'=>'attendance','permission'=>'correct_class_attendance','target'=>'#portal-class-attendance'],
            ['label'=>'Enter grades','key'=>'enter-grades','page'=>'grades','permission'=>'manage_grades','target'=>'#portal-class-grades'],
            ['label'=>'Assign student seats','key'=>'open-seat-plans','page'=>'seat-plans','permission'=>'assign_class_seats'],
            ['label'=>'Compose message','key'=>'compose-message','page'=>'messages','permission'=>'send_messages','target'=>'#portal-message-compose'],
        ];
    }

    public static function search(string $query, string $context, ?string $category, int $page): array
    {
        $query = mb_substr(trim(preg_replace('/\s+/u', ' ', $query) ?? ''), 0, 100);
        $category = in_array($category, self::CATEGORIES, true) ? $category : null;
        $page = min(50, max(1, $page));
        self::$fetchLimit = max(60, $page * 5 + 1);
        $admin = Auth::usesAdminArea();
        $user = Auth::user();
        $viewerId = (int) $user['id'];
        $context = preg_match('/^[a-z-]{1,25}$/', $context) ? $context : '';
        $groups = [];
        foreach (self::CATEGORIES as $name) {
            if ($category !== null && $name !== $category) continue;
            $records = match ($name) {
                'pages' => self::pageResults($admin, $query, $context),
                'people' => mb_strlen($query) >= 2 ? self::people($query, $admin, $viewerId) : [],
                'academics' => mb_strlen($query) >= 2 ? self::academics($query, $admin, $viewerId, $context) : [],
                'facilities' => mb_strlen($query) >= 2 ? self::facilities($query, $admin, $viewerId) : [],
                'activities' => mb_strlen($query) >= 2 ? self::activities($query, $admin, $viewerId) : [],
                'seat_plans' => mb_strlen($query) >= 2 ? self::seatPlans($query, $admin, $viewerId) : [],
            };
            if ($records === [] && $query === '' && $name !== 'pages') continue;
            $perPage = $query === '' ? 8 : 5;
            $offset = ($page - 1) * $perPage;
            $slice = array_slice($records, $offset, $perPage + 1);
            $groups[] = ['key'=>$name, 'label'=>self::LABELS[$name], 'items'=>array_slice($slice, 0, $perPage), 'has_more'=>count($slice) > $perPage, 'next_page'=>count($slice) > $perPage ? $page + 1 : null];
        }
        return ['ok'=>true, 'query'=>$query, 'groups'=>$groups];
    }

    public static function pageIndex(bool $admin): array
    {
        return self::pageResults($admin, '', '', true);
    }

    private static function pageResults(bool $admin, string $query, string $context, bool $forIndex = false): array
    {
        $base = $admin ? '/admin/' : '/index/';
        $items = [];
        foreach (self::visibleNavigation($admin) as $item) {
            $key = $item['key'];
            $result = self::item('page:' . $key, 'page', $item['label'], 'Page', url($base . ($key === 'dashboard' ? '' : '?page=' . $key)), $key, self::rank($query, $item['label'], $item['aliases'] ?? '', $key === $context));
            $result['keywords'] = $item['aliases'] ?? '';
            $items[] = $result;
        }
        foreach (self::actions($admin) as $action) {
            $required = match ($action['permission']) {
                'correct_class_attendance' => ['correct_class_attendance', 'manage_attendance'],
                'assign_class_seats' => ['assign_class_seats', 'manage_seat_plans'],
                default => [$action['permission']],
            };
            if (!Auth::canAny($required) || !isset(self::visibleNavigation($admin)[$action['page']])) continue;
            if ($action['key'] === 'create-offering' && !Auth::can('manage_teacher_assignments')) continue;
            $page = $action['page'];
            $result = self::item('action:' . $action['key'], 'action', $action['label'], 'Open ' . ucfirst(str_replace('-', ' ', $page)), url($base . '?page=' . $page . ($action['target'] ?? '')), $page, self::rank($query, $action['label'], '', $page === $context));
            $result['keywords'] = '';
            $items[] = $result;
        }
        if ($query !== '') $items = array_values(array_filter($items, static fn($row) => $row['score'] > 0));
        $defaults = Auth::hasRole('Student') ? ['schedule','attendance','grades','events','recollections','people','profile','settings'] : (Auth::hasRole('Teacher') ? ['schedule','attendance','grades','seat-plans','events','messages','profile','settings'] : []);
        usort($items, static function($a, $b) use ($query, $defaults) {
            if ($query === '' && $defaults) {
                $order = array_flip(array_map(static fn($key) => 'page:' . $key, $defaults));
                return ($order[$a['key']] ?? 99) <=> ($order[$b['key']] ?? 99);
            }
            return $b['score'] <=> $a['score'] ?: strcmp($a['title'], $b['title']);
        });
        if ($query === '' && $defaults && !$forIndex) $items = array_slice($items, 0, 8);
        return $items;
    }

    private static function people(string $q, bool $admin, int $viewerId): array
    {
        if (!Auth::canAny(['view_people','manage_users'])) return [];
        $canManage = $admin && Auth::can('manage_users');
        $params = ['term'=>'%' . $q . '%', 'prefix'=>$q . '%', 'last_prefix'=>$q . '%', 'identifier'=>$q . '%'];
        $tokens = array_slice(explode(' ', $q), 0, 4);
        $nameClauses = [];
        foreach ($tokens as $index=>$token) {
            $nameClauses[] = '(users.first_name LIKE :first'.$index.' OR users.middle_name LIKE :middle'.$index.' OR users.last_name LIKE :last'.$index.')';
            foreach (['first','middle','last'] as $part) $params[$part.$index] = $token.'%';
        }
        $where = $canManage ? '1=1' : 'users.status = "active"';
        $identifier = $canManage ? ' OR users.school_id LIKE :identifier' : ' OR ((users.profile_visibility = "public" OR users.id = ' . $viewerId . ') AND users.school_id LIKE :identifier)';
        $visibleId = $canManage ? 'users.school_id' : 'CASE WHEN users.profile_visibility = "public" OR users.id = ' . $viewerId . ' THEN users.school_id ELSE NULL END';
        $sql = 'SELECT users.id, ' . $visibleId . ' school_id, users.first_name, users.middle_name, users.last_name, users.profile_visibility, users.status, roles.name role_name, sections.name section_name
                FROM users JOIN roles ON roles.id = users.role_id LEFT JOIN sections ON sections.id = users.section_id
                WHERE ' . $where . ' AND (CONCAT_WS(" ",users.first_name,users.middle_name,users.last_name) LIKE :term OR users.first_name LIKE :prefix OR users.last_name LIKE :last_prefix OR (' . implode(' AND ', $nameClauses) . ')' . $identifier . ')
                ORDER BY users.last_name, users.first_name LIMIT ' . self::$fetchLimit;
        $rows = self::rows($sql, $params);
        $items = [];
        foreach ($rows as $row) {
            $private = $row['profile_visibility'] === 'private' && !$canManage && (int)$row['id'] !== $viewerId;
            $title = trim($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']);
            $subtitle = $private ? $row['role_name'] . ' · Private profile' : $row['school_id'] . ' · ' . ($row['section_name'] ?: $row['role_name']);
            $score = self::rank($q, $title, $private ? '' : (string) $row['school_id']);
            if (!$score && count($tokens) > 1) $score = 10;
            $items[] = self::item('person:'.$row['id'], 'person', $title, $subtitle, url(($admin ? '/admin/' : '/index/') . '?page=profile&user_id=' . $row['id']), 'people', $score);
        }
        return self::sorted($items);
    }

    private static function academics(string $q, bool $admin, int $viewerId, string $context): array
    {
        $manager = $admin && Auth::can('manage_academic_structure');
        if (!$manager && !Auth::can('view_schedule')) return [];
        $scope = self::offeringScope($manager);
        $scopeParams = $manager ? [] : ['viewer_teacher'=>$viewerId,'viewer_student'=>$viewerId];
        $items = [];
        $like = '%' . $q . '%';
        $definitions = [
            'department'=>['departments d','d','d.code','d.name','EXISTS(SELECT 1 FROM programs p JOIN sections s ON s.program_id=p.id JOIN class_offerings o ON o.section_id=s.id WHERE p.department_id=d.id AND '.$scope.')'],
            'program'=>['programs p','p','p.code','p.name','EXISTS(SELECT 1 FROM sections s JOIN class_offerings o ON o.section_id=s.id WHERE s.program_id=p.id AND '.$scope.')'],
            'course'=>['courses c','c','c.course_code','c.title','EXISTS(SELECT 1 FROM class_offerings o WHERE o.course_id=c.id AND '.$scope.')'],
            'section'=>['sections s','s','s.name','s.name','EXISTS(SELECT 1 FROM class_offerings o WHERE o.section_id=s.id AND '.$scope.')'],
            'semester'=>['semesters s JOIN school_years y ON y.id=s.school_year_id','s','s.name','y.name','EXISTS(SELECT 1 FROM class_offerings o WHERE o.semester_id=s.id AND '.$scope.')'],
        ];
        foreach ($definitions as $kind=>$def) {
            [$table,$alias,$code,$name,$access] = $def;
            $rows = self::rows("SELECT DISTINCT {$alias}.id, {$code} code, {$name} name FROM {$table} WHERE ({$code} LIKE :code OR {$name} LIKE :name) AND " . ($manager ? '1=1' : $access) . ' LIMIT ' . self::$fetchLimit, ['code'=>$like,'name'=>$like] + $scopeParams);
            foreach ($rows as $row) {
                $url = $manager ? ($kind === 'semester' ? '/admin/?page=academics#academics-class-offerings' : '/admin/?page=academics&manage='.$kind.'&entity_id='.$row['id']) : '/index/?page=schedule&focus_type='.$kind.'&focus_id='.$row['id'];
                $items[] = self::item($kind.':'.$row['id'], $kind, (string)$row['code'], (string)$row['name'], url($url), 'academics', max(10, self::rank($q, (string)$row['code'], (string)$row['name'], $context === 'academics' || $context === 'schedule')));
            }
        }
        $rows = self::rows('SELECT o.id, c.course_code, c.title, s.name section_name, sem.name semester_name FROM class_offerings o JOIN courses c ON c.id=o.course_id JOIN sections s ON s.id=o.section_id JOIN semesters sem ON sem.id=o.semester_id WHERE (c.course_code LIKE :code OR c.title LIKE :title OR s.name LIKE :section OR sem.name LIKE :semester) AND '. $scope .' LIMIT ' . self::$fetchLimit, ['code'=>$like,'title'=>$like,'section'=>$like,'semester'=>$like] + $scopeParams);
        foreach ($rows as $row) {
            $url = $manager ? '/admin/?page=academics&manage=offering&entity_id='.$row['id'] : (Auth::can('view_class_rosters') ? '/index/?page=class-roster&offering_id='.$row['id'] : '/index/?page=schedule&offering_id='.$row['id']);
            if (in_array($context, ['attendance','grades'], true) && Auth::canAny($context === 'attendance' ? ['view_attendance','manage_attendance'] : ['view_grades','manage_grades'])) $url = ($admin ? '/admin/' : '/index/') . '?page='.$context.'&offering_id='.$row['id'];
            $items[] = self::item('offering:'.$row['id'], 'offering', $row['course_code'].' · '.$row['title'], $row['section_name'].' · '.$row['semester_name'], url($url), 'academics', self::rank($q, $row['course_code'], $row['title'].' '.$row['section_name'], $context === 'academics' || $context === 'schedule'));
        }
        return self::sorted($items);
    }

    private static function offeringScope(bool $manager): string
    {
        if ($manager) return '1=1';
        return '(EXISTS(SELECT 1 FROM teacher_assignments ta WHERE ta.class_offering_id=o.id AND ta.teacher_id=:viewer_teacher) OR EXISTS(SELECT 1 FROM class_enrollments ce WHERE ce.class_offering_id=o.id AND ce.student_id=:viewer_student AND ce.status="enrolled"))';
    }

    private static function facilities(string $q, bool $admin, int $viewerId): array
    {
        $items = [];
        $like = '%' . $q . '%';
        if ($admin && Auth::can('manage_rooms')) {
            foreach (self::rows('SELECT id, room_code code, room_name name, building detail FROM rooms WHERE room_code LIKE :code OR room_name LIKE :name LIMIT ' . self::$fetchLimit, ['code'=>$like,'name'=>$like]) as $row) $items[] = self::item('room:'.$row['id'],'room',$row['code'],$row['name'].' · '.($row['detail'] ?: 'Campus room'),url('/admin/?page=rooms&manage=room&entity_id='.$row['id']),'facilities',self::rank($q,$row['code'],$row['name']));
            foreach (self::rows('SELECT id, device_code code, name, status detail FROM devices WHERE device_code LIKE :code OR name LIKE :name LIMIT ' . self::$fetchLimit, ['code'=>$like,'name'=>$like]) as $row) $items[] = self::item('scanner:'.$row['id'],'scanner',$row['code'],$row['name'].' · '.$row['detail'],url('/admin/?page=rooms&manage=device&entity_id='.$row['id']),'facilities',self::rank($q,$row['code'],$row['name']));
        } else {
            if (!Auth::can('view_schedule')) return [];
            $scope = self::offeringScope(false);
            $rows = self::rows('SELECT DISTINCT r.id, r.room_code code, r.room_name name, r.building detail FROM class_schedules cs JOIN class_offerings o ON o.id=cs.class_offering_id JOIN rooms r ON r.id=COALESCE(cs.room_id,o.room_id) WHERE (r.room_code LIKE :code OR r.room_name LIKE :name) AND '.$scope.' LIMIT ' . self::$fetchLimit, ['code'=>$like,'name'=>$like,'viewer_teacher'=>$viewerId,'viewer_student'=>$viewerId]);
            foreach ($rows as $row) $items[] = self::item('room:'.$row['id'],'room',$row['code'],$row['name'].' · '.($row['detail'] ?: 'Campus room'),url('/index/?page=schedule&focus_type=room&focus_id='.$row['id']),'facilities',self::rank($q,$row['code'],$row['name']));
        }
        return self::sorted($items);
    }

    private static function activities(string $q, bool $admin, int $viewerId): array
    {
        $items = [];
        $like = '%' . $q . '%';
        $role = Auth::user()['role_name'] ?? '';
        $canManageEvents = $admin && Auth::can('manage_events');
        $eventScope = EventAccessService::visibleCondition($viewerId, 'e');
        $params = ['title'=>$like,'venue'=>$like];
        foreach (($canManageEvents || Auth::can('view_events')) ? self::rows('SELECT e.id,e.title,e.event_date date,e.venue FROM events e WHERE (e.title LIKE :title OR e.venue LIKE :venue) AND '.$eventScope.' LIMIT ' . self::$fetchLimit, $params) : [] as $row) {
            $url = (Auth::usesAdminArea()?'/admin/event.php?id=':'/event.php?id=').$row['id'];
            $items[] = self::item('event:'.$row['id'],'event',$row['title'],$row['date'].' · '.$row['venue'],url($url),'activities',self::rank($q,$row['title'],$row['venue']));
        }
        $canManageRecollections = $admin && Auth::can('manage_recollections');
        $recollectionScope = $canManageRecollections ? '1=1' : RecollectionService::involvementCondition($viewerId, 'r');
        foreach (($canManageRecollections || Auth::can('view_recollections')) ? self::rows('SELECT r.id,r.title,r.date,r.end_date,r.venue FROM recollections r WHERE (r.title LIKE :title OR r.venue LIKE :venue) AND '.$recollectionScope.' LIMIT ' . self::$fetchLimit, ['title'=>$like,'venue'=>$like]) : [] as $row) {
            $url = $canManageRecollections ? '/admin/?page=recollections&manage_recollection='.$row['id'] : '/index/?page=recollections&focus_recollection='.$row['id'];
            $items[] = self::item('recollection:'.$row['id'],'recollection',$row['title'],$row['date'].(!empty($row['end_date']) && $row['end_date']!==$row['date'] ? ' – '.$row['end_date'] : '').' · '.$row['venue'],url($url),'activities',self::rank($q,$row['title'],$row['venue']));
        }
        return self::sorted($items);
    }

    private static function seatPlans(string $q, bool $admin, int $viewerId): array
    {
        if (!\App\Core\PermissionRegistry::canAssignSeats()) return [];
        $items = [];
        $like = '%' . $q . '%';
        $workspace = null;
        if (!$admin || !Auth::can('manage_rooms')) {
            foreach (SeatPlanService::subjects($viewerId) as $subject) {
                if (!empty($subject['room_id'])) { $workspace = $subject; break; }
            }
            if (!$workspace) return [];
        }
        if (!Auth::can('manage_seat_plans')) {
            foreach (SeatPlanService::subjects($viewerId) as $subject) {
                if (empty($subject['seat_plan_id'])) continue;
                $subtitle = $subject['course_code'] . ' · ' . $subject['section_name'] . ' · ' . $subject['room_code'];
                $score = self::rank($q, $subject['seat_plan_name'], $subtitle);
                if ($score > 0) $items[] = self::item('class-plan:'.$subject['id'], 'seat_plan', $subject['seat_plan_name'], $subtitle, url('/index/?page=seat-plans&offering_id='.$subject['id']), 'seat_plans', $score);
            }
            return self::sorted($items);
        }
        foreach (self::rows('SELECT id,name,visibility FROM seat_plan_presets WHERE name LIKE :name AND (visibility="campus" OR created_by=:viewer) LIMIT ' . self::$fetchLimit, ['name'=>$like,'viewer'=>$viewerId]) as $row) {
            $url = $admin && Auth::can('manage_rooms') ? '/admin/?page=rooms&focus_preset='.$row['id'] : '/index/?page=seat-plans&offering_id='.$workspace['id'].'&room_id='.$workspace['room_id'].'&focus_preset='.$row['id'];
            $items[] = self::item('preset:'.$row['id'],'seat_plan',$row['name'],ucfirst($row['visibility']).' seat-plan preset',url($url),'seat_plans',self::rank($q,$row['name']));
        }
        return self::sorted($items);
    }

    private static function rows(string $sql, array $params): array
    {
        $statement = Database::connection()->prepare($sql);
        $statement->execute($params);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function item(string $key, string $type, string $title, string $subtitle, string $destination, string $category, int $score): array
    {
        return ['key'=>$key,'type'=>$type,'title'=>$title,'subtitle'=>$subtitle,'url'=>$destination,'category'=>$category,'icon'=>$type,'score'=>$score];
    }

    private static function rank(string $q, string $title, string $other = '', bool $context = false): int
    {
        if ($q === '') return $context ? 5 : 1;
        $q = mb_strtolower($q);
        $title = mb_strtolower($title);
        $other = mb_strtolower($other);
        $match = $title === $q ? 100 : (str_starts_with($title, $q) ? 70 : (str_contains($title, $q) ? 45 : (str_contains($other, $q) ? 25 : 0)));
        return $match > 0 ? $match + ($context ? 4 : 0) : 0;
    }

    private static function sorted(array $items): array
    {
        usort($items, static fn($a,$b) => $b['score'] <=> $a['score'] ?: strcmp($a['title'], $b['title']));
        return $items;
    }
}
