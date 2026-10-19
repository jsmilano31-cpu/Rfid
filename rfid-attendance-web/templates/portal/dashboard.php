<?php
$today = date('Y-m-d');
$isStudentPrimary = $user['role_name'] === 'Student';
$isTeacher = \App\Core\Auth::hasRole('Teacher');
$canViewSchedule = \App\Core\Auth::can('view_schedule');
$canViewEvents = \App\Core\Auth::canAny(['view_events','manage_events']);
$canViewActivities = $canViewEvents || \App\Core\Auth::canAny(['view_recollections','manage_recollections']);
$upcomingActivities = [];
foreach ($events as $event) {
    $endDate = $event['end_date'] ?: $event['event_date'];
    if ($endDate < $today || $event['status'] === 'cancelled') continue;
    $upcomingActivities[] = [
        'kind' => 'Event',
        'title' => $event['title'],
        'description' => $event['description'] ?? '',
        'start_date' => $event['event_date'],
        'end_date' => $event['end_date'] ?: null,
        'start_time' => $event['start_time'],
        'end_time' => $event['end_time'],
        'venue' => $event['venue'],
        'banner' => $event['banner_image_path'] ?: '/assets/images/default-event-banner.svg',
        'href' => url('/index/?page=events'),
        'status' => $event['status'],
    ];
}
foreach ($recollections as $item) {
    if (($item['end_date'] ?: $item['date']) < $today || $item['status'] === 'cancelled') continue;
    $upcomingActivities[] = [
        'kind' => $item['type'],
        'title' => $item['title'],
        'description' => ($item['section_name'] ?? '') !== '' ? 'For ' . $item['section_name'] : 'Formation activity',
        'start_date' => $item['date'],
        'end_date' => $item['end_date'] ?: $item['date'],
        'start_time' => $item['start_time'],
        'end_time' => $item['end_time'],
        'venue' => $item['venue'],
        'banner' => '/assets/images/default-event-banner.svg',
        'href' => url('/index/?page=recollections'),
        'status' => $item['status'],
    ];
}
usort($upcomingActivities, static fn(array $first, array $second): int => [
    $first['start_date'], $first['start_time'], $first['title'],
] <=> [
    $second['start_date'], $second['start_time'], $second['title'],
]);
$nextFormationActivity = null;
foreach ($recollections as $item) {
    if (($item['end_date'] ?: $item['date']) < $today || $item['status'] === 'cancelled') continue;
    if ($nextFormationActivity === null || [$item['date'], $item['start_time']] < [$nextFormationActivity['date'], $nextFormationActivity['start_time']]) {
        $nextFormationActivity = $item;
    }
}
$formatActivityDate = static function (string $startDate, ?string $endDate): string {
    $start = new DateTimeImmutable($startDate);
    if (!$endDate || $endDate === $startDate) return $start->format('M j, Y');
    $end = new DateTimeImmutable($endDate);
    return $start->format('M j') . '–' . $end->format($start->format('Y-m') === $end->format('Y-m') ? 'j, Y' : 'M j, Y');
};
?>

<div class="section-navigation-page personal-dashboard" data-section-navigation>
<header class="page-header"><div><p class="eyebrow">Personal workspace</p><h1>Hello, <?= e($user['first_name']) ?></h1><p>Your role-aware overview for today.</p></div><?php if (\App\Core\Auth::usesAdminArea() && !str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/admin')): ?><a class="button button-primary" href="<?= e(url('/admin/')) ?>">Open administration</a><?php endif; ?></header>

<div class="section-navigation-layout">
<?php
$sectionNavigationLabel = 'Dashboard page sections';
$sectionNavigation = [];
$sectionNavigation[] = ['id' => 'portal-dashboard-overview', 'label' => 'Today at a glance'];
if ($nextFormationActivity) $sectionNavigation[] = ['id' => 'portal-dashboard-formation', 'label' => 'Upcoming formation'];
if (\App\Core\PermissionRegistry::canViewCampusCalendar()) $sectionNavigation[] = ['id' => 'dashboard-campus-calendar', 'label' => 'Campus calendar'];
if ($canViewEvents) $sectionNavigation[] = ['id' => 'dashboard-events-feed', 'label' => 'Events & Announcements'];
if (($isStudentPrimary || $isTeacher) && $canViewSchedule) $sectionNavigation[] = ['id' => 'portal-dashboard-schedule', 'label' => 'Class schedule'];
if ($canViewActivities) $sectionNavigation[] = ['id' => 'portal-dashboard-activities', 'label' => 'Upcoming activities'];
require BASE_PATH . '/templates/partials/section_navigation.php';
?>
<div class="section-navigation-content">

<?php if ($isStudentPrimary): ?>
<section class="grid grid-4 section-scroll-target" id="portal-dashboard-overview" data-section-target>
    <?php $summary = array_column($attendanceSummary, 'total', 'status'); ?>
    <?php if (\App\Core\Auth::can('view_attendance')): ?><article class="card stat-card"><span>Present</span><strong><?= e($summary['Present'] ?? 0) ?></strong></article>
    <article class="card stat-card"><span>Late</span><strong><?= e($summary['Late'] ?? 0) ?></strong></article>
    <article class="card stat-card"><span>Absent</span><strong><?= e($summary['Absent'] ?? 0) ?></strong></article><?php endif; ?>
    <?php if (\App\Core\Auth::can('view_compliance')): ?><article class="card stat-card"><span>Active deficiencies</span><strong><?= e($compliance['total_deficiencies']) ?></strong></article><?php endif; ?>
</section>
<section class="grid grid-2 section">
    <?php if (\App\Core\Auth::can('view_grades')): ?><article class="card"><h2>Latest grades</h2><?php if (!$latestGrades): ?><p class="empty-state">No grades available.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Course</th><th>Overall</th><th>Status</th></tr></thead><tbody><?php foreach ($latestGrades as $grade): ?><tr><td><?= e($grade['course_code']) ?><br><span class="muted"><?= e($grade['title']) ?></span></td><td><strong><?= e($grade['overall'] ?? '—') ?></strong></td><td><?= e($grade['status'] === 'conflict' ? 'Needs review' : $grade['status']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></article><?php endif; ?>
    <?php if (\App\Core\Auth::can('view_compliance')): ?><article class="card"><h2>Compliance summary</h2><div class="grid grid-2"><div><span class="muted">Class absences</span><h3><?= e($compliance['absences']) ?></h3></div><div><span class="muted">Tardies</span><h3><?= e($compliance['tardies']) ?></h3></div><div><span class="muted">Missed events</span><h3><?= e($compliance['missed_events']) ?></h3></div><div><span class="muted">Missed recollections</span><h3><?= e($compliance['missed_recollections']) ?></h3></div></div></article><?php endif; ?>
</section>
<?php else: ?>
<section class="section-scroll-target" id="portal-dashboard-overview" data-section-target>
    <div class="grid grid-3"><?php if ($canViewSchedule): ?><article class="card stat-card"><span>Assigned classes</span><strong><?= e(count($assignedClasses ?? [])) ?></strong></article><?php endif; ?><?php if (\App\Core\Auth::can('view_events')): ?><article class="card stat-card"><span>Upcoming events</span><strong><?= e(count(array_filter($events, fn($event) => ($event['end_date'] ?: $event['event_date']) >= $today))) ?></strong></article><?php endif; ?><?php if (\App\Core\Auth::can('view_messages')): ?><article class="card stat-card"><span>Unread messages</span><strong><?= e($unread) ?></strong></article><?php endif; ?></div>
    <?php if ($isTeacher && \App\Core\Auth::canAny(['view_attendance','correct_class_attendance','assign_class_seats','manage_attendance'])): ?><section class="section" id="portal-dashboard-live-class"><?php $liveSeatPlanContext = 'portal-dashboard'; $liveSeatPlanMode = 'dashboard'; require BASE_PATH . '/templates/partials/live_seat_plan.php'; ?></section><?php endif; ?>
</section>
<?php endif; ?>

<?php if ($nextFormationActivity): ?>
<a class="formation-notice section-scroll-target" id="portal-dashboard-formation" data-section-target href="<?= e(url('/index/?page=recollections')) ?>">
    <span class="formation-notice-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 3v3m0 12v3M3 12h3m12 0h3M5.6 5.6l2.1 2.1m8.6 8.6 2.1 2.1m0-12.8-2.1 2.1m-8.6 8.6-2.1 2.1"/><circle cx="12" cy="12" r="4"/></svg></span>
    <span><small>Upcoming <?= e(strtolower($nextFormationActivity['type'])) ?></small><strong><?= e($nextFormationActivity['title']) ?></strong><span><?= e($formatActivityDate($nextFormationActivity['date'], $nextFormationActivity['end_date'])) ?> at <?= e(format_time($nextFormationActivity['start_time'])) ?> · <?= e($nextFormationActivity['section_name']) ?></span></span>
    <svg class="formation-notice-arrow" viewBox="0 0 20 20" aria-hidden="true"><path d="m7 4 6 6-6 6"/></svg>
</a>
<?php endif; ?>

<?php require BASE_PATH . '/templates/partials/dashboard_calendar.php'; ?>

<?php require BASE_PATH . '/templates/partials/events_social_feed.php'; ?>

<?php if (($isStudentPrimary || $isTeacher) && $canViewSchedule): ?>
<section class="section dashboard-schedule-section section-scroll-target" id="portal-dashboard-schedule" data-section-target>
    <?php
    $scheduleRows = $schedule;
    $scheduleViewId = $isStudentPrimary ? 'dashboard-schedule' : 'teacher-dashboard-schedule';
    $scheduleHeadingMode = 'section';
    $scheduleTitle = 'Class schedule';
    $scheduleDescription = $isStudentPrimary
        ? 'Your classes for the week, available as a timetable or searchable list.'
        : 'Your assigned classes for the week, available as a timetable or searchable list.';
    require BASE_PATH . '/templates/partials/portal_schedule_views.php';
    ?>
</section>
<?php endif; ?>

<?php if ($canViewActivities): ?><section class="section section-scroll-target" id="portal-dashboard-activities" data-section-target>
    <article class="card activity-carousel-card" data-activity-carousel>
        <div class="activity-carousel-heading">
            <div><h2>Upcoming activities</h2><p><?= e(count($upcomingActivities)) ?> upcoming <?= count($upcomingActivities) === 1 ? 'activity' : 'activities' ?>, ordered by date.</p></div>
            <?php if (count($upcomingActivities) > 1): ?><div class="activity-carousel-controls"><button type="button" data-carousel-previous aria-label="Previous activities" title="Previous"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m12.5 5-5 5 5 5"/></svg></button><button type="button" data-carousel-next aria-label="Next activities" title="Next"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m7.5 5 5 5-5 5"/></svg></button></div><?php endif; ?>
        </div>
        <?php if (!$upcomingActivities): ?>
            <div class="activity-carousel-empty"><p>No upcoming events or formation activities.</p></div>
        <?php else: ?>
            <div class="activity-carousel-viewport" data-carousel-viewport tabindex="0" aria-label="Upcoming activities carousel">
                <div class="activity-carousel-track">
                    <?php foreach ($upcomingActivities as $index => $activity): ?>
                        <a class="activity-card" href="<?= e($activity['href']) ?>" data-carousel-slide>
                            <span class="activity-card-media"><img src="<?= e(url($activity['banner'])) ?>" alt="<?= e($activity['title']) ?> banner" <?= $index === 0 ? 'fetchpriority="high"' : 'loading="lazy"' ?>><span class="activity-kind <?= $activity['kind'] === 'Event' ? '' : 'is-formation' ?>"><?= e($activity['kind']) ?></span></span>
                            <span class="activity-card-body">
                                <time datetime="<?= e($activity['start_date']) ?>"><?= e($formatActivityDate($activity['start_date'], $activity['end_date'])) ?></time>
                                <strong><?= e($activity['title']) ?></strong>
                                <?php if ($activity['description'] !== ''): ?><span class="activity-card-description"><?= e(mb_strimwidth($activity['description'], 0, 96, '…')) ?></span><?php endif; ?>
                                <span class="activity-card-meta"><span><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 17s5-4.4 5-9a5 5 0 1 0-10 0c0 4.6 5 9 5 9Z"/><circle cx="10" cy="8" r="1.6"/></svg><?= e($activity['venue']) ?></span><span><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7"/><path d="M10 6v4l2.8 1.8"/></svg><?= e(format_time($activity['start_time']) . '–' . format_time($activity['end_time'])) ?></span></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </article>
</section>
<?php endif; ?></div></div></div>
