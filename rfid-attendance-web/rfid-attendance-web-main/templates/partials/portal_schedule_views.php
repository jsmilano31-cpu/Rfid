<?php
$scheduleRows = $scheduleRows ?? [];
$scheduleViewId = $scheduleViewId ?? 'portal-schedule';
$scheduleHeadingMode = $scheduleHeadingMode ?? 'section';
$scheduleTitle = $scheduleTitle ?? 'Schedule';
$scheduleDescription = $scheduleDescription ?? 'Your weekly class schedule.';
$weekDays = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$courseColorCount = 8;
$schedulePersonalMode = \App\Core\Auth::hasRole('Student') && ($user['role_name'] ?? '') === 'Student';
$canConfigureSchedulePolicy = !$schedulePersonalMode && \App\Core\Auth::can('manage_attendance_policies');
$canManageScheduleSeatPlans = !$schedulePersonalMode && \App\Core\Auth::can('manage_seat_plans');
$canManageScheduleSections = \App\Core\Auth::can('manage_academic_structure');
$canManageScheduleRooms = \App\Core\Auth::can('manage_rooms');
$scheduleEntityDialogId = $scheduleViewId . '-entity-details';
$attendancePolicyDialogId = $scheduleViewId . '-attendance-policy';
$timeToMinutes = static function (?string $time): int {
    $parts = array_map('intval', explode(':', (string) $time));
    return (($parts[0] ?? 0) * 60) + ($parts[1] ?? 0);
};
$scheduleStarts = array_map(static fn(array $item): int => $timeToMinutes($item['start_time']), $scheduleRows);
$scheduleEnds = array_map(static fn(array $item): int => $timeToMinutes($item['end_time']), $scheduleRows);
$timelineStart = $scheduleStarts ? min(7 * 60, (int) floor(min($scheduleStarts) / 60) * 60) : 7 * 60;
$timelineEnd = $scheduleEnds ? max(18 * 60, (int) ceil(max($scheduleEnds) / 60) * 60) : 18 * 60;
$timelineEnd = max($timelineStart + 60, min(24 * 60, $timelineEnd));
$pixelsPerMinute = 1.1;
$timetableHeight = (int) (($timelineEnd - $timelineStart) * $pixelsPerMinute);
$scheduleTimeOffNotices = [];
foreach ($scheduleRows as &$scheduleRow) {
    $parts = !empty($scheduleRow['time_off_summary']) ? explode('|~|', $scheduleRow['time_off_summary'], 4) : [];
    $scheduleRow['time_off'] = $parts ? ['starts_on' => $parts[0], 'ends_on' => $parts[1], 'teacher_name' => $parts[2], 'reason' => $parts[3] ?? ''] : null;
    if ($scheduleRow['time_off']) {
        $scheduleTimeOffNotices[$scheduleRow['class_offering_id'] . ':' . $parts[0] . ':' . $parts[1]] = $scheduleRow['time_off'] + ['course_code' => $scheduleRow['course_code']];
    }
}
unset($scheduleRow);
$scheduleContext = static function (array $item) use ($schedulePersonalMode, $canConfigureSchedulePolicy, $canManageScheduleSeatPlans, $canManageScheduleSections, $canManageScheduleRooms, $attendancePolicyDialogId, $scheduleEntityDialogId): array {
    $offeringId = (int) $item['class_offering_id'];
    $roomId = (int) ($item['resolved_room_id'] ?? 0);
    $sectionId = (int) ($item['section_id'] ?? 0);
    $personalSuffix = $schedulePersonalMode ? '&view=personal' : '';
    return [
        'offering-id' => $offeringId,
        'subject-code' => $item['course_code'],
        'subject-title' => $item['title'],
        'user-mode' => $schedulePersonalMode ? 'student' : 'manager',
        'grades-url' => url('/index/?page=grades&offering_id=' . $offeringId . $personalSuffix),
        'attendance-url' => url('/index/?page=attendance&offering_id=' . $offeringId . $personalSuffix),
        'roster-url' => url('/index/?page=class-roster&offering_id=' . $offeringId),
        'seat-plan-url' => $canManageScheduleSeatPlans && $roomId ? url('/index/?page=seat-plans&room_id=' . $roomId . '&offering_id=' . $offeringId) : '',
        'policy-dialog' => $canConfigureSchedulePolicy ? '#' . $attendancePolicyDialogId : '',
        'teacher-profile-url' => !empty($item['teacher_id']) ? url('/index/?page=profile&user_id=' . $item['teacher_id']) : '',
        'teacher-name' => $item['teacher_names'] ?? '',
        'section-id' => $sectionId,
        'section-name' => $item['section_name'],
        'section-year' => $item['year_level'] ?? '',
        'section-code' => $item['section_code'] ?? '',
        'program-code' => $item['program_code'] ?? '',
        'program-name' => $item['program_name'] ?? '',
        'department-code' => $item['department_code'] ?? '',
        'department-name' => $item['department_name'] ?? '',
        'section-manage-url' => $canManageScheduleSections && $sectionId ? url('/admin/?page=academics&manage=section&entity_id=' . $sectionId) : '',
        'room-id' => $roomId,
        'room-code' => $item['room_code'] ?? '',
        'room-name' => $item['room_name'] ?? '',
        'room-building' => $item['room_building'] ?? '',
        'room-floor' => $item['room_floor'] ?? '',
        'room-manage-url' => $canManageScheduleRooms && $roomId ? url('/admin/?page=rooms&manage=room&entity_id=' . $roomId) : '',
        'entity-dialog' => '#' . $scheduleEntityDialogId,
    ];
};
$printContext = static function (array $attributes): void {
    foreach ($attributes as $name => $value) echo ' data-' . $name . '="' . e((string) $value) . '"';
};
?>
<?php if ($scheduleHeadingMode === 'page'): ?>
<header class="page-header schedule-page-header"><div><p class="eyebrow">Weekly plan</p><h1><?= e($scheduleTitle) ?></h1><p><?= e($scheduleDescription) ?></p></div>
<?php else: ?>
<div class="section-heading schedule-section-heading"><div><h2><?= e($scheduleTitle) ?></h2><p class="muted"><?= e($scheduleDescription) ?></p></div>
<?php endif; ?>
    <div class="schedule-view-switcher" data-schedule-view-switcher role="group" aria-label="Schedule view">
        <button type="button" class="schedule-view-button is-active" data-schedule-view="timetable" aria-controls="<?= e($scheduleViewId) ?>-timetable" aria-pressed="true"><svg viewBox="0 0 20 20" aria-hidden="true"><rect x="2.5" y="3.5" width="15" height="13" rx="2"/><path d="M2.5 7.5h15M7.5 7.5v9M12.5 7.5v9"/></svg>Timetable</button>
        <button type="button" class="schedule-view-button" data-schedule-view="list" aria-controls="<?= e($scheduleViewId) ?>-list" aria-pressed="false"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M7 5h10M7 10h10M7 15h10"/><circle cx="3.5" cy="5" r=".8"/><circle cx="3.5" cy="10" r=".8"/><circle cx="3.5" cy="15" r=".8"/></svg>List</button>
    </div>
<?php if ($scheduleHeadingMode === 'page'): ?></header><?php else: ?></div><?php endif; ?>
<?php if ($scheduleTimeOffNotices): ?><div class="schedule-time-off-notices" aria-label="Upcoming class suspensions"><?php foreach ($scheduleTimeOffNotices as $notice): ?><div class="inline-notice is-warning"><strong><?= e($notice['course_code']) ?> · Class suspended</strong><span><?= e(date('M j', strtotime($notice['starts_on'])) . ($notice['ends_on'] !== $notice['starts_on'] ? '–' . date('M j', strtotime($notice['ends_on'])) : '') . ' · ' . $notice['teacher_name']) ?><?= $notice['reason'] ? ' · ' . e($notice['reason']) : '' ?></span></div><?php endforeach; ?></div><?php endif; ?>

<section id="<?= e($scheduleViewId) ?>-timetable" class="schedule-view-panel" data-schedule-panel="timetable">
<?php if (!$scheduleRows): ?>
    <div class="card schedule-empty-state"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 9h18"/></svg><h2>No classes scheduled</h2><p>Your weekly timetable will appear here after a class is assigned.</p></div>
<?php else: ?>
    <div class="card timetable-card">
        <div class="timetable-summary"><div><strong>Weekly timetable</strong><span><?= e(count($scheduleRows)) ?> scheduled <?= count($scheduleRows) === 1 ? 'class' : 'classes' ?></span></div><span class="timetable-scroll-hint" data-timetable-instruction>Right-click a class for related actions</span></div>
        <div class="timetable-scroll" tabindex="0" aria-label="Weekly class timetable"><div class="weekly-timetable" data-weekly-timetable data-timetable-start="<?= e($timelineStart) ?>" data-pixels-per-minute="<?= e($pixelsPerMinute) ?>">
            <div class="timetable-header" aria-hidden="true"><div class="timetable-corner">Time</div><?php foreach ($weekDays as $day): ?><div class="timetable-day-heading <?= $day === date('l') ? 'is-today' : '' ?>"><span><?= e(substr($day, 0, 3)) ?></span><strong><?= e($day) ?></strong></div><?php endforeach; ?></div>
            <div class="timetable-body" style="--timetable-height: <?= e($timetableHeight) ?>px">
                <div class="timetable-time-axis" aria-hidden="true"><?php for ($minute = $timelineStart; $minute <= $timelineEnd; $minute += 60): ?><span class="timetable-time-label" style="top: <?= e((int) (($minute - $timelineStart) * $pixelsPerMinute)) ?>px"><?= e(format_time(sprintf('%02d:%02d:00', intdiv($minute % 1440, 60), $minute % 60))) ?></span><?php endfor; ?></div>
                <?php foreach ($weekDays as $day): ?><div class="timetable-day-column <?= $day === date('l') ? 'is-today' : '' ?>" data-timetable-day="<?= e($day) ?>">
                    <?php foreach ($scheduleRows as $item): ?><?php if ($item['day_of_week'] !== $day) continue; $context = $scheduleContext($item); ?>
                        <?php $colorIndex = (int) sprintf('%u', crc32((string) $item['course_code'])) % $courseColorCount; ?>
                        <article class="timetable-event timetable-event-color-<?= e($colorIndex) ?>" data-timetable-event data-timetable-context-target<?php $printContext($context); ?> data-start-minute="<?= e($timeToMinutes($item['start_time'])) ?>" data-end-minute="<?= e($timeToMinutes($item['end_time'])) ?>" tabindex="0" title="<?= e($item['course_code'] . ' - ' . $item['title'] . (!empty($item['teacher_names']) ? ' - ' . $item['teacher_names'] : '')) ?>" aria-label="<?= e($item['course_code'] . ', ' . $item['title'] . ', ' . format_time($item['start_time']) . ' to ' . format_time($item['end_time']) . '. Open the context menu for related actions.') ?>">
                            <div class="timetable-event-header"><a class="timetable-event-subject-link" href="<?= e($context['grades-url']) ?>"><?= e($item['course_code']) ?></a><time><?= e(format_time($item['start_time']) . '-' . format_time($item['end_time'])) ?></time></div>
                            <small class="timetable-event-title"><?= e($item['title']) ?></small>
                            <small class="timetable-event-meta"><button type="button" data-timetable-inline-entity="room"><?= e($item['room_code'] ?? 'TBA') ?></button><i aria-hidden="true">·</i><button type="button" data-timetable-inline-entity="section"><?= e($item['section_name']) ?></button></small>
                            <?php if ($item['time_off']): ?><span class="timetable-time-off-badge" title="<?= e($item['time_off']['teacher_name'] . ' scheduled time off') ?>">Class suspended <?= e(date('M j', strtotime($item['time_off']['starts_on']))) ?></span><?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div><?php endforeach; ?>
            </div>
        </div></div>
    </div>
<?php endif; ?>
</section>
<section id="<?= e($scheduleViewId) ?>-list" class="schedule-view-panel" data-schedule-panel="list" hidden>
<div class="table-wrap"><table><thead><tr><th>Day</th><th>Time</th><th>Course</th><th>Teacher</th><th>Section</th><th>Room</th><th>Availability</th></tr></thead><tbody>
<?php foreach ($scheduleRows as $item): $context = $scheduleContext($item); ?><tr class="schedule-context-row" data-timetable-context-target<?php $printContext($context); ?> tabindex="0">
<td><?= e($item['day_of_week']) ?></td><td><?= e(format_time($item['start_time']) . '-' . format_time($item['end_time'])) ?></td>
<td><a class="entity-link" href="<?= e($context['roster-url']) ?>"><strong><?= e($item['course_code']) ?></strong><span><?= e($item['title']) ?></span></a></td>
<td><?php if (!empty($item['teacher_id'])): ?><a href="<?= e($context['teacher-profile-url']) ?>"><?= e($item['teacher_names']) ?></a><?php else: ?>Not assigned<?php endif; ?></td>
<td><a class="table-entity-link" href="<?= e($context['roster-url']) ?>"><?= e($item['section_name']) ?></a></td>
<td><?php if ($context['seat-plan-url']): ?><a class="table-entity-link" href="<?= e($context['seat-plan-url']) ?>"><?= e($item['room_code'] ?? 'TBA') ?></a><?php else: ?><span><?= e($item['room_code'] ?? 'TBA') ?></span><?php endif; ?></td>
<td><?php if ($item['time_off']): ?><span class="badge badge-late" title="<?= e($item['time_off']['teacher_name']) ?>">Suspended <?= e(date('M j', strtotime($item['time_off']['starts_on']))) ?></span><?php else: ?><span class="badge badge-active">Scheduled</span><?php endif; ?></td>
</tr><?php endforeach; ?></tbody></table></div></section>

<dialog id="<?= e($scheduleEntityDialogId) ?>" class="app-dialog" data-modal data-schedule-entity-dialog aria-labelledby="<?= e($scheduleEntityDialogId) ?>-title"><div class="app-dialog-shell"><header class="app-dialog-header"><div><p class="eyebrow" data-schedule-entity-kind>Schedule entity</p><h2 id="<?= e($scheduleEntityDialogId) ?>-title" data-schedule-entity-title>Details</h2><p class="muted" data-schedule-entity-subtitle></p></div><button type="button" class="dialog-close" data-dialog-close aria-label="Close details"><svg viewBox="0 0 20 20"><path d="m5 5 10 10M15 5 5 15"/></svg></button></header><div class="app-dialog-body"><dl class="schedule-entity-facts" data-schedule-entity-facts></dl><div class="form-actions"><a class="button button-secondary" data-schedule-entity-seat-plan hidden>Open seat plan</a><a class="button button-primary" data-schedule-entity-manage hidden>Manage record</a></div></div></div></dialog>
<?php if ($canConfigureSchedulePolicy): ?><?php require BASE_PATH . '/templates/partials/attendance_policy_dialog.php'; ?><?php endif; ?>
<?php unset($schedulePersonalMode, $canConfigureSchedulePolicy, $canManageScheduleSeatPlans, $canManageScheduleSections, $canManageScheduleRooms, $scheduleEntityDialogId, $scheduleContext, $printContext); ?>
