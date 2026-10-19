<?php if (!$viewedProfile): ?>
<div class="section-navigation-page" data-section-navigation>
<header class="page-header"><div><p class="eyebrow">Campus directory</p><h1>Profile unavailable</h1><p>This person does not exist or is no longer visible in the campus directory.</p></div><a class="button button-secondary" href="<?= e(url('/index/?page=people')) ?>">Back to people</a></header>
<div class="section-navigation-layout"><?php $sectionNavigationLabel = 'Profile page sections'; $sectionNavigation = [['id' => 'directory-profile-unavailable', 'label' => 'Profile unavailable']]; require BASE_PATH . '/templates/partials/section_navigation.php'; ?><div class="section-navigation-content"><section class="card empty-state section-scroll-target" id="directory-profile-unavailable" data-section-target><h2>We couldn’t open this profile</h2><p>Try finding the person again from the People directory.</p></section></div></div></div>
<?php else: ?>
<?php
$directory = $viewedProfile;
$person = $directory['profile'];
$personName = trim($person['first_name'] . ' ' . ($person['middle_name'] ? $person['middle_name'] . ' ' : '') . $person['last_name']);
if (!empty($directory['is_private'])):
?>
<div class="section-navigation-page directory-profile-page" data-section-navigation>
<header class="page-header"><div><p class="eyebrow">Campus profile</p><h1><?= e($personName) ?></h1><p>This campus member controls who can open their profile.</p></div><a class="button button-secondary" href="<?= e(url('/index/?page=people')) ?>">People directory</a></header>
<div class="section-navigation-layout">
<?php $sectionNavigationLabel = 'Private profile'; $sectionNavigation = [['id' => 'directory-profile-private', 'label' => 'Private profile']]; require BASE_PATH . '/templates/partials/section_navigation.php'; ?>
<div class="section-navigation-content"><section class="card empty-state section-scroll-target" id="directory-profile-private" data-section-target>
    <div class="empty-state-icon" aria-hidden="true">&#128274;</div>
    <h2><?= e($personName) ?> has set their profile to private</h2>
    <p>Their account still appears where it is needed for classes, attendance, messaging, and other campus functions.</p>
</section></div></div></div>
<?php return; endif;
$sectionNavigation = [['id' => 'directory-profile-overview', 'label' => 'Profile overview']];
if (!empty($directory['can_view_schedule'])) $sectionNavigation[] = ['id' => 'directory-profile-schedule', 'label' => 'Class schedule'];
$sectionNavigation[] = ['id' => 'directory-profile-classes', 'label' => $directory['relationship_heading']];
if ($directory['can_view_grades']) $sectionNavigation[] = ['id' => 'directory-profile-grades', 'label' => 'Grades'];
if ($directory['can_view_attendance']) $sectionNavigation[] = ['id' => 'directory-profile-attendance', 'label' => 'Attendance logs'];
?>
<div class="section-navigation-page directory-profile-page" data-section-navigation>
<header class="page-header"><div><p class="eyebrow">Campus profile</p><h1><?= e($personName) ?></h1><p>Identity, roles, and academic connections relevant to you.</p></div><div class="page-header-actions"><?php if (\App\Core\Auth::usesAdminArea()): ?><a class="button button-secondary" href="<?= e(url('/admin/')) ?>">&larr; Return to Admin</a><a class="button button-primary" href="<?= e(url('/admin/?page=users&q=' . urlencode($person['school_id']))) ?>">Manage in Admin</a><?php else: ?><a class="button button-secondary" href="<?= e(url('/index/?page=people')) ?>">People directory</a><?php endif; ?><?php if ($directory['is_self']): ?><a class="button button-primary" href="<?= e(url('/index/?page=profile')) ?>">Edit my profile</a><?php endif; ?></div></header>
<div class="section-navigation-layout">
<?php $sectionNavigationLabel = $personName . ' profile sections'; require BASE_PATH . '/templates/partials/section_navigation.php'; ?>
<div class="section-navigation-content">
<section class="card directory-profile-hero section-scroll-target" id="directory-profile-overview" data-section-target>
    <img class="directory-profile-avatar" src="<?= e(url($person['profile_image_path'] ?: '/assets/images/default-avatar.svg')) ?>" alt="<?= e($personName) ?> profile picture">
    <div class="directory-profile-identity"><p class="eyebrow"><?= e($person['school_id']) ?></p><h2><?= e($personName) ?></h2><div class="directory-role-list"><?php foreach ($person['role_names'] as $role): ?><span class="badge"><?= e($role) ?></span><?php endforeach; ?><?php if ($person['status'] !== 'active'): ?><span class="badge badge-inactive"><?= e($person['status']) ?></span><?php endif; ?></div><p><?= e($person['program_code'] && $person['section_name'] ? $person['program_code'] . ' · ' . $person['section_name'] : ($person['department_name'] ?: 'Campus member')) ?></p></div>
    <dl class="directory-profile-facts">
        <div><dt>Department</dt><dd><?= e($person['department_code'] ? $person['department_code'] . ' — ' . $person['department_name'] : '—') ?></dd></div>
        <div><dt>Program</dt><dd><?= e($person['program_code'] ? $person['program_code'] . ' — ' . $person['program_name'] : '—') ?></dd></div>
        <div><dt>Year & section</dt><dd><?= e($person['section_name'] ?: '—') ?></dd></div>
        <?php if ($directory['can_view_contact']): ?><div><dt>School email</dt><dd><a href="mailto:<?= e($person['email']) ?>"><?= e($person['email']) ?></a></dd></div><?php endif; ?>
    </dl>
</section>

<?php if (!empty($directory['can_view_schedule'])): ?>
<?php $profileScheduleId = 'directory-profile-schedule'; require BASE_PATH . '/templates/partials/profile_schedule.php'; ?>
<?php endif; ?>
<section class="section section-scroll-target" id="directory-profile-classes" data-section-target>
    <div class="section-heading"><div><h2><?= e($directory['relationship_heading']) ?></h2><p class="muted"><?= e($directory['relationship_copy']) ?></p></div><span class="muted"><?= e(count($directory['classes'])) ?> <?= count($directory['classes']) === 1 ? 'subject' : 'subjects' ?></span></div>
    <?php if (!$directory['classes']): ?><article class="card empty-state"><p>No shared or visible class connections were found.</p></article><?php else: ?><div class="directory-class-grid">
    <?php foreach ($directory['classes'] as $class): ?>
        <article class="card directory-class-card">
            <header><div><span class="badge"><?= e($class['relationship']) ?></span><h3><a href="<?= e(url('/index/?page=attendance&offering_id=' . $class['class_offering_id'])) ?>"><?= e($class['course_code']) ?></a></h3><p><?= e($class['course_title']) ?></p></div><span class="directory-class-section"><?= e($class['section_name']) ?></span></header>
            <dl><div><dt>Term</dt><dd><?= e($class['school_year_name'] . ' · ' . $class['semester_name']) ?></dd></div><div><dt>Room</dt><dd><?= e($class['room_code'] ?: 'TBA') ?></dd></div></dl>
            <?php if ($class['schedules']): ?><div class="directory-class-schedules"><?php foreach ($class['schedules'] as $schedule): ?><span><?= e($schedule['day'] . ' · ' . format_time($schedule['start_time']) . '–' . format_time($schedule['end_time'])) ?></span><?php endforeach; ?></div><?php endif; ?>
            <?php if ($class['teachers']): ?><div class="directory-class-people"><span>Teacher<?= count($class['teachers']) === 1 ? '' : 's' ?></span><?php foreach ($class['teachers'] as $teacher): ?><a href="<?= e(url('/index/?page=profile&user_id=' . $teacher['id'])) ?>"><?= e($teacher['name']) ?></a><?php endforeach; ?></div><?php endif; ?>
            <footer><a href="<?= e(url('/index/?page=grades&offering_id=' . $class['class_offering_id'] . ($directory['is_self'] ? '&view=personal' : ''))) ?>">Grades</a><a href="<?= e(url('/index/?page=attendance&offering_id=' . $class['class_offering_id'] . ($directory['is_self'] ? '&view=personal' : ''))) ?>">Attendance</a></footer>
        </article>
    <?php endforeach; ?>
    </div><?php endif; ?>
</section>

<?php if ($directory['can_view_grades']): ?>
<section class="section section-scroll-target" id="directory-profile-grades" data-section-target><div class="section-heading"><div><h2>Grades</h2><p class="muted"><?= $directory['is_self'] ? 'Your recorded grades.' : 'Only grades within your authorized class scope are shown.' ?></p></div></div><div class="table-wrap"><table><thead><tr><th>Subject</th><th>Section</th><th data-grade-term="prelim" <?= \App\Services\GradeService::settings()['has_prelim'] ? '' : 'hidden' ?>>Prelims</th><th data-grade-term="midterm" <?= \App\Services\GradeService::settings()['has_midterm'] ? '' : 'hidden' ?>>Midterms</th><th data-grade-term="final" <?= \App\Services\GradeService::settings()['has_final'] ? '' : 'hidden' ?>>Endterms</th><th>Overall</th><th>Status</th></tr></thead><tbody><?php foreach ($directory['grades'] as $grade): ?><tr><td><a class="entity-link" href="<?= e(url('/index/?page=grades&offering_id=' . $grade['class_offering_id'])) ?>"><strong><?= e($grade['course_code']) ?></strong><span><?= e($grade['course_title']) ?></span></a></td><td><?= e($grade['section_name']) ?></td><td data-grade-term="prelim" <?= \App\Services\GradeService::settings()['has_prelim'] ? '' : 'hidden' ?>><?= e($grade['prelim'] ?? '—') ?></td><td data-grade-term="midterm" <?= \App\Services\GradeService::settings()['has_midterm'] ? '' : 'hidden' ?>><?= e($grade['midterm'] ?? '—') ?></td><td data-grade-term="final" <?= \App\Services\GradeService::settings()['has_final'] ? '' : 'hidden' ?>><?= e($grade['final'] ?? '—') ?></td><td><strong><?= e($grade['overall'] ?? '—') ?></strong></td><td><span class="badge badge-<?= e($grade['status']) ?>"><?= e($grade['status'] === 'conflict' ? 'Needs review' : $grade['status']) ?></span></td></tr><?php endforeach; ?><?php if (!$directory['grades']): ?><tr><td colspan="7" class="empty-state">No grades are recorded in the visible class scope.</td></tr><?php endif; ?></tbody></table></div></section>
<?php endif; ?>

<?php if ($directory['can_view_attendance']): ?>
<section class="section section-scroll-target" id="directory-profile-attendance" data-section-target><div class="section-heading"><div><h2>Attendance logs</h2><p class="muted"><?= $directory['is_self'] ? 'Your latest attendance records.' : 'The latest 100 records from classes assigned to you or your authorized scope.' ?></p></div></div><div class="directory-attendance-summary"><?php foreach (['Present', 'Late', 'Absent', 'Excused'] as $status): ?><span class="is-<?= e(strtolower($status)) ?>"><small><?= e($status) ?></small><strong><?= e($directory['attendance_summary'][$status] ?? 0) ?></strong></span><?php endforeach; ?></div><div class="table-wrap"><table><thead><tr><th>Date</th><th>Subject</th><th>Section</th><th>Status</th><th>Time in</th><th>Source</th></tr></thead><tbody><?php foreach ($directory['attendance'] as $record): ?><tr><td><?= e($record['attendance_date']) ?></td><td><a class="entity-link" href="<?= e(url('/index/?page=attendance&offering_id=' . $record['class_offering_id'])) ?>"><strong><?= e($record['course_code']) ?></strong><span><?= e($record['course_title']) ?></span></a></td><td><?= e($record['section_name']) ?></td><td><span class="attendance-status is-<?= e(strtolower($record['status'])) ?>"><?= e($record['status']) ?></span></td><td><?= e(format_time($record['time_in'])) ?></td><td><?= e(str_replace('_', ' ', $record['source'])) ?></td></tr><?php endforeach; ?><?php if (!$directory['attendance']): ?><tr><td colspan="6" class="empty-state">No attendance records exist in the visible class scope.</td></tr><?php endif; ?></tbody></table></div></section>
<?php endif; ?>
</div></div></div>
<?php endif; ?>
