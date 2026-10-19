<?php $selected = $selectedSeatPlanSubject ?? null; ?>
<div class="section-navigation-page" data-section-navigation>
<header class="page-header"><div><p class="eyebrow">Classroom tools</p><h1>Seat Plans</h1><p><?= $selected ? 'Configure the classroom layout used by this subject.' : 'Choose a subject before opening its classroom layout.' ?></p></div><?php if ($selected): ?><a class="button button-secondary" href="<?= e(url('/index/?page=seat-plans')) ?>">&larr; All subjects</a><?php endif; ?></header>
<div class="section-navigation-layout">
<?php $sectionNavigationLabel = 'Seat plans page sections'; $sectionNavigation = [['id' => $selected ? 'portal-seat-plan-workspace' : 'portal-seat-plan-subjects', 'label' => $selected ? 'Seat-plan workspace' : 'Assigned subjects']]; require BASE_PATH . '/templates/partials/section_navigation.php'; ?>
<div class="section-navigation-content">
<?php if (!$selected): ?>
<section class="section-scroll-target" id="portal-seat-plan-subjects" data-section-target data-seat-plan-subject-browser>
    <div class="section-heading seat-plan-subject-heading"><div><h2>Assigned subjects</h2><p class="muted">Open one subject to review or configure the seat plan for its assigned room.</p></div><div class="schedule-view-switcher" role="group" aria-label="Subject layout"><button type="button" class="schedule-view-button is-active" data-seat-plan-subject-view="cards" aria-pressed="true">Cards</button><button type="button" class="schedule-view-button" data-seat-plan-subject-view="list" aria-pressed="false">List</button></div></div>
    <label class="field seat-plan-subject-search"><span>Search subjects</span><input type="search" placeholder="Course, section, or room" data-seat-plan-subject-search autocomplete="off"></label>
    <div class="seat-plan-subject-grid" data-seat-plan-subject-results>
    <?php foreach (($seatPlanSubjects ?? []) as $subject): $search = strtolower(implode(' ', [$subject['course_code'], $subject['course_title'], $subject['program_code'], $subject['section_name'], $subject['room_code'] ?? '', $subject['room_name'] ?? ''])); ?>
        <article class="card seat-plan-subject-card" data-seat-plan-subject data-search="<?= e($search) ?>">
            <div class="seat-plan-subject-card-head"><div><span class="eyebrow"><?= e($subject['program_code'] . ' · ' . $subject['section_name']) ?></span><h3><?= e($subject['course_code']) ?> — <?= e($subject['course_title']) ?></h3></div><?php if (!$subject['room_id']): ?><span class="badge badge-late">Room required</span><?php elseif (!$subject['seat_plan_id']): ?><span class="badge badge-late">Seat plan required</span><?php else: ?><span class="badge badge-active">Ready</span><?php endif; ?></div>
            <dl class="seat-plan-subject-facts"><div><dt>Room</dt><dd><?= e($subject['room_code'] ? $subject['room_code'] . ' — ' . $subject['room_name'] : 'No room assigned') ?></dd></div><div><dt>Layout</dt><dd><?= $subject['seat_plan_id'] ? e($subject['seat_plan_name'] . ' · ' . $subject['seat_count'] . ' cells') : 'Not configured' ?></dd></div></dl>
            <?php if ($subject['schedules']): ?><p class="seat-plan-subject-schedule"><?= e(implode(' · ', $subject['schedules'])) ?></p><?php endif; ?>
            <?php if ($subject['room_id']): ?><a class="button button-primary button-block" href="<?= e(url('/index/?page=seat-plans&offering_id=' . $subject['id'] . '&room_id=' . $subject['room_id'])) ?>">Open seat plan</a><?php else: ?><p class="inline-notice is-warning">Assign a room to this subject before creating its seat plan.</p><?php endif; ?>
        </article>
    <?php endforeach; ?>
    <?php if (empty($seatPlanSubjects)): ?><div class="card empty-state"><h3>No assigned subjects</h3><p>Your subjects will appear here after an administrator assigns them to you.</p></div><?php endif; ?>
    </div>
    <p class="muted seat-plan-subject-empty" data-seat-plan-subject-empty hidden>No subjects match that search.</p>
</section>
<?php else: ?>
<section class="section-scroll-target" id="portal-seat-plan-workspace" data-section-target>
    <div class="seat-plan-selected-subject card"><div><span class="eyebrow"><?= e($selected['program_code'] . ' · ' . $selected['section_name']) ?></span><h2><?= e($selected['course_code'] . ' — ' . $selected['course_title']) ?></h2><p><?= e($selected['room_code'] . ' — ' . $selected['room_name']) ?></p></div><?php if (!$selected['seat_plan_id']): ?><span class="badge badge-late">No seat plan yet</span><?php else: ?><span class="badge badge-active"><?= e($selected['seat_plan_name']) ?></span><?php endif; ?></div>
    <?php $seatPlanContext = 'teacher'; require BASE_PATH . '/templates/partials/seat_plan_editor.php'; ?>
</section>
<?php endif; ?>
</div>
</div></div>
