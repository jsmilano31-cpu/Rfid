<?php if (!$viewedProfile): ?>
<div class="section-navigation-page" data-section-navigation>
<header class="page-header">
    <div>
        <p class="eyebrow">Administration</p>
        <h1>User Profile Unavailable</h1>
        <p>This user account does not exist or has been removed from campus records.</p>
    </div>
    <div class="page-header-actions">
        <a class="button button-secondary" href="<?= e(url('/admin/?page=users')) ?>">&larr; Back to Users & RFID</a>
    </div>
</header>
<div class="section-navigation-layout">
    <?php $sectionNavigationLabel = 'Profile sections'; $sectionNavigation = [['id' => 'admin-profile-unavailable', 'label' => 'Profile unavailable']]; require BASE_PATH . '/templates/admin/partials/section_navigation.php'; ?>
    <div class="section-navigation-content">
        <section class="card empty-state section-scroll-target" id="admin-profile-unavailable" data-section-target>
            <h2>User not found</h2>
            <p>Return to the Users & RFID directory to search for another account.</p>
        </section>
    </div>
</div>
</div>
<?php else: ?>
<?php
$directory = $viewedProfile;
$person = $directory['profile'];
$personName = trim($person['first_name'] . ' ' . ($person['middle_name'] ? $person['middle_name'] . ' ' : '') . $person['last_name']);
$sectionNavigation = [['id' => 'admin-profile-overview', 'label' => 'Profile overview']];
$sectionNavigation[] = ['id' => 'admin-profile-classes', 'label' => 'Class enrollments'];
if (!empty($directory['grades'])) $sectionNavigation[] = ['id' => 'admin-profile-grades', 'label' => 'Grades record'];
if (!empty($directory['attendance'])) $sectionNavigation[] = ['id' => 'admin-profile-attendance', 'label' => 'Attendance logs'];
?>
<div class="section-navigation-page directory-profile-page" data-section-navigation>
<header class="page-header">
    <div>
        <p class="eyebrow">Administrative User Record</p>
        <h1><?= e($personName) ?></h1>
        <p>Full identity details, class enrollments, grades, and campus attendance logs.</p>
    </div>
    <div class="page-header-actions">
        <a class="button button-secondary" href="<?= e(url('/admin/?page=users')) ?>">&larr; Back to Users</a>
        <button type="button" class="button button-primary" data-manage-user data-user-id="<?= e($person['id']) ?>">
            <svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="M11.5 4.5H5.8A1.8 1.8 0 0 0 4 6.3v7.9A1.8 1.8 0 0 0 5.8 16h7.9a1.8 1.8 0 0 0 1.8-1.8V8.5"/><path d="m10 11 1.1-3.1 4.6-4.6a1.4 1.4 0 0 1 2 2l-4.6 4.6L10 11Z"/></svg>
            Edit user account
        </button>
    </div>
</header>

<div class="section-navigation-layout">
<?php $sectionNavigationLabel = $personName . ' sections'; require BASE_PATH . '/templates/admin/partials/section_navigation.php'; ?>
<div class="section-navigation-content">

<!-- Hero Card -->
<section class="card directory-profile-hero section-scroll-target" id="admin-profile-overview" data-section-target>
    <img class="directory-profile-avatar" src="<?= e(url($person['profile_image_path'] ?: '/assets/images/default-avatar.svg')) ?>" alt="<?= e($personName) ?> profile picture">
    <div class="directory-profile-identity">
        <p class="eyebrow"><?= e($person['school_id']) ?></p>
        <h2><?= e($personName) ?></h2>
        <div class="directory-role-list" style="margin-bottom:8px;">
            <?php foreach ($person['role_names'] as $role): ?>
                <span class="badge badge-active"><?= e($role) ?></span>
            <?php endforeach; ?>
            <?php if ($person['status'] !== 'active'): ?>
                <span class="badge badge-inactive"><?= e($person['status']) ?></span>
            <?php endif; ?>
        </div>
        <p class="muted" style="margin:0;"><?= e($person['program_code'] && $person['section_name'] ? $person['program_code'] . ' · ' . $person['section_name'] : ($person['department_name'] ?: 'Campus member')) ?></p>
    </div>
    <dl class="directory-profile-facts">
        <div><dt>Department</dt><dd><?= e($person['department_code'] ? $person['department_code'] . ' — ' . $person['department_name'] : '—') ?></dd></div>
        <div><dt>Program</dt><dd><?= e($person['program_code'] ? $person['program_code'] . ' — ' . $person['program_name'] : '—') ?></dd></div>
        <div><dt>Year & section</dt><dd><?= e($person['section_name'] ?: '—') ?></dd></div>
        <div><dt>School email</dt><dd><a href="mailto:<?= e($person['email']) ?>"><?= e($person['email']) ?></a></dd></div>
    </dl>
</section>

<!-- Quick Action Strip -->
<div style="display:flex; gap:10px; flex-wrap:wrap; margin:16px 0;">
    <a class="button button-secondary button-small" href="<?= e(url('/admin/?page=messages&recipient_id=' . $person['id'])) ?>">
        <svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="M3 5h14a1 1 0 0 1 1 1v8a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Z"/><path d="m3 6 7 5 7-5"/></svg>
        Send message
    </a>
    <a class="button button-secondary button-small" href="<?= e(url('/admin/?page=compliance&q=' . urlencode($person['school_id']))) ?>">
        <svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="m10 3 8 14H2L10 3Z"/><path d="M10 8.5v4M10 14.5h.01"/></svg>
        View compliance record
    </a>
    <a class="button button-secondary button-small" href="<?= e(url('/admin/?page=attendance&student_id=' . $person['id'] . '&student_q=' . urlencode($person['school_id']) . '#admin-attendance-correction')) ?>">
        <svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7"/><path d="M10 6v4l2.5 2.5"/></svg>
        Manual attendance correction
    </a>
    <a class="button button-secondary button-small" href="<?= e(url('/admin/?page=grades&student_id=' . $person['id'] . '&student_q=' . urlencode($person['school_id']) . '#admin-grades-students')) ?>">
        <svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="M4 3h12v14H4zM4 7h12M8 3v14"/></svg>
        Grade overrides
    </a>
</div>

<!-- Class Offerings Section -->
<section class="section section-scroll-target" id="admin-profile-classes" data-section-target>
    <div class="section-heading">
        <div>
            <h2>Campus Class Enrollments</h2>
            <p class="muted">All scheduled subject offerings and assigned sections for this user.</p>
        </div>
        <span class="muted"><?= e(count($directory['classes'])) ?> <?= count($directory['classes']) === 1 ? 'subject' : 'subjects' ?></span>
    </div>
    <?php if (!$directory['classes']): ?>
        <article class="card empty-state"><p>No class enrollments or teaching assignments found.</p></article>
    <?php else: ?>
        <div class="directory-class-grid">
            <?php foreach ($directory['classes'] as $class): ?>
                <article class="card directory-class-card">
                    <header>
                        <div>
                            <span class="badge badge-active"><?= e($class['relationship']) ?></span>
                            <h3><a href="<?= e(url('/admin/?page=class-roster&offering_id=' . $class['class_offering_id'])) ?>" title="View enrolled students in this class"><?= e($class['course_code']) ?></a></h3>
                            <p><?= e($class['course_title']) ?></p>
                        </div>
                        <span class="directory-class-section"><?= e($class['section_name']) ?></span>
                    </header>
                    <dl>
                        <div><dt>Term</dt><dd><?= e($class['school_year_name'] . ' · ' . $class['semester_name']) ?></dd></div>
                        <div><dt>Room</dt><dd><?= e($class['room_code'] ?: 'TBA') ?></dd></div>
                    </dl>
                    <?php if ($class['schedules']): ?>
                        <div class="directory-class-schedules">
                            <?php foreach ($class['schedules'] as $schedule): ?>
                                <span><?= e($schedule['day'] . ' · ' . format_time($schedule['start_time']) . '–' . format_time($schedule['end_time'])) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($class['teachers']): ?>
                        <div class="directory-class-people">
                            <span>Teacher<?= count($class['teachers']) === 1 ? '' : 's' ?></span>
                            <?php foreach ($class['teachers'] as $teacher): ?>
                                <a href="<?= e(url('/admin/?page=profile&id=' . $teacher['id'])) ?>"><?= e($teacher['name']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <footer>
                        <a href="<?= e(url('/admin/?page=class-roster&offering_id=' . $class['class_offering_id'])) ?>">Class roster</a>
                        <a href="<?= e(url('/admin/?page=grades&offering_id=' . $class['class_offering_id'])) ?>">Grades console</a>
                        <a href="<?= e(url('/admin/?page=attendance&offering_id=' . $class['class_offering_id'])) ?>">Attendance logs</a>
                    </footer>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Grades Section -->
<?php if (!empty($directory['grades'])): ?>
<section class="section section-scroll-target" id="admin-profile-grades" data-section-target>
    <div class="section-heading">
        <div>
            <h2>Grades Overview</h2>
            <p class="muted">Master academic marks recorded across enrolled offerings.</p>
        </div>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Subject</th>
                    <th>Section</th>
                    <th>Prelim</th>
                    <th>Midterm</th>
                    <th>Final</th>
                    <th>Overall</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($directory['grades'] as $grade): ?>
                    <tr>
                        <td>
                            <a class="entity-link" href="<?= e(url('/admin/?page=grades&offering_id=' . $grade['class_offering_id'])) ?>">
                                <strong><?= e($grade['course_code']) ?></strong>
                                <span><?= e($grade['course_title']) ?></span>
                            </a>
                        </td>
                        <td><?= e($grade['section_name']) ?></td>
                        <td><?= e($grade['prelim'] ?? '—') ?></td>
                        <td><?= e($grade['midterm'] ?? '—') ?></td>
                        <td><?= e($grade['final'] ?? '—') ?></td>
                        <td><strong><?= e($grade['overall'] ?? '—') ?></strong></td>
                        <td><span class="badge badge-<?= e($grade['status']) ?>"><?= e($grade['status']) ?></span></td>
                        <td>
                            <a class="button button-secondary button-small" href="<?= e(url('/admin/?page=grades&offering_id=' . $grade['class_offering_id'])) ?>">
                                Edit in grades
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

<!-- Attendance Logs Section -->
<?php if (!empty($directory['attendance'])): ?>
<section class="section section-scroll-target" id="admin-profile-attendance" data-section-target>
    <div class="section-heading">
        <div>
            <h2>Attendance Activity Logs</h2>
            <p class="muted">Recent scan history and manual corrections across campus classes.</p>
        </div>
    </div>
    <div class="directory-attendance-summary">
        <?php foreach (['Present', 'Late', 'Absent', 'Excused'] as $status): ?>
            <span class="is-<?= e(strtolower($status)) ?>">
                <small><?= e($status) ?></small>
                <strong><?= e($directory['attendance_summary'][$status] ?? 0) ?></strong>
            </span>
        <?php endforeach; ?>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Subject</th>
                    <th>Section</th>
                    <th>Status</th>
                    <th>Time in</th>
                    <th>Source</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($directory['attendance'] as $record): ?>
                    <tr>
                        <td><?= e($record['attendance_date']) ?></td>
                        <td>
                            <a class="entity-link" href="<?= e(url('/admin/?page=attendance&offering_id=' . $record['class_offering_id'])) ?>">
                                <strong><?= e($record['course_code']) ?></strong>
                                <span><?= e($record['course_title']) ?></span>
                            </a>
                        </td>
                        <td><?= e($record['section_name']) ?></td>
                        <td><span class="attendance-status is-<?= e(strtolower($record['status'])) ?>"><?= e($record['status']) ?></span></td>
                        <td><?= e(format_time($record['time_in'])) ?></td>
                        <td><?= e(str_replace('_', ' ', $record['source'])) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>

</div>
</div>
</div>

<?php require BASE_PATH . '/templates/admin/partials/manage_user_dialog.php'; ?>
<?php endif; ?>
