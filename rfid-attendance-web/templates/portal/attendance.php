<?php
$isStudent = in_array('Student', $user['role_names'] ?? [$user['role_name']], true);
$isStudentPrimary = $user['role_name'] === 'Student';
$canManageAttendance = \App\Core\PermissionRegistry::canCorrectAttendance();
$selectedAttendanceSubject = $selectedAttendanceSubject ?? null;
$personalAttendanceView = $isStudentPrimary && ($_GET['view'] ?? '') === 'personal';
?>
<div class="section-navigation-page" data-section-navigation>
<header class="page-header"><div><p class="eyebrow">Class records</p><h1>Attendance</h1><p><?= $canManageAttendance && !$isStudentPrimary ? 'Open an assigned subject and manage its dated class roster.' : 'Review your attendance history across enrolled courses.' ?></p></div></header>
<div class="section-navigation-layout">
<?php
$sectionNavigationLabel = 'Attendance page sections';
$sectionNavigation = [];
if ($canManageAttendance && !$personalAttendanceView) $sectionNavigation[] = ['id' => 'portal-class-attendance', 'label' => 'Class attendance'];
if ($isStudent) $sectionNavigation[] = ['id' => 'portal-my-attendance', 'label' => 'My attendance'];
require BASE_PATH . '/templates/partials/section_navigation.php';
?>
<div class="section-navigation-content">
<?php if ($canManageAttendance && !$personalAttendanceView): ?><section class="section-scroll-target" id="portal-class-attendance" data-section-target><?php $attendanceManagerContext = 'portal'; $showAttendanceRules = false; require BASE_PATH . '/templates/partials/attendance_management.php'; ?></section><?php endif; ?>

<?php if ($isStudent): ?>
<section class="section student-attendance-history section-scroll-target" id="portal-my-attendance" data-section-target>
    <div class="section-heading">
        <div><h2><?= $selectedAttendanceSubject ? e($selectedAttendanceSubject['course_code'] . ' - My attendance') : 'My attendance' ?></h2><p class="muted"><?= $selectedAttendanceSubject ? e($selectedAttendanceSubject['course_title'] . ' - ' . $selectedAttendanceSubject['section_name']) : 'Choose a subject from your timetable to open its attendance calendar.' ?></p></div>
        <div class="personal-record-actions">
            <?php if ($selectedAttendanceSubject): ?><a class="button button-secondary" href="<?= e(url('/index/?page=attendance&view=personal')) ?>">All subjects</a><?php endif; ?>
            <?php $recordViewTarget = 'student-attendance-records'; $recordViewLabel = 'Attendance display'; require BASE_PATH . '/templates/partials/record_view_switch.php'; ?>
        </div>
    </div>
    <?php if ($selectedAttendanceSubject): ?>
    <article class="card calendar-card student-attendance-calendar-card">
        <div class="attendance-calendar-toolbar"><div class="calendar-navigation"><button type="button" class="calendar-nav-button" data-student-calendar-previous aria-label="Previous month"><svg viewBox="0 0 20 20"><path d="m12.5 5-5 5 5 5"/></svg></button><button type="button" class="calendar-today-button" data-student-calendar-today>Today</button><button type="button" class="calendar-nav-button" data-student-calendar-next aria-label="Next month"><svg viewBox="0 0 20 20"><path d="m7.5 5 5 5-5 5"/></svg></button></div><strong data-student-calendar-month>Attendance calendar</strong><div class="student-attendance-legend"><span><i class="is-present"></i>Present</span><span><i class="is-late"></i>Late</span><span><i class="is-absent"></i>Absent</span><span><i class="is-excused"></i>Excused</span></div></div>
        <div id="student-attendance-calendar" data-student-attendance-calendar data-records="<?= e(json_encode($records, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?>"></div>
    </article>
    <?php endif; ?>
    <div id="student-attendance-records" class="record-view-container record-view-attendance is-card-view" data-record-view-container data-view-storage="rfid-student-attendance-view">
        <div class="table-wrap"><table><thead><tr><th>Date</th><th>Course</th><th>Status</th><th>Time in</th><th>Source</th></tr></thead><tbody>
        <?php foreach ($records as $record): ?><tr data-attendance-history-date="<?= e($record['attendance_date']) ?>">
            <td data-card-label="Date"><time datetime="<?= e($record['attendance_date']) ?>"><?= e($record['attendance_date']) ?></time></td>
            <td data-card-label="Course"><a class="entity-link" href="<?= e(url('/index/?page=attendance&offering_id=' . (int) $record['class_offering_id'] . '&view=personal')) ?>"><strong><?= e($record['course_code']) ?></strong><span><?= e($record['title']) ?></span></a></td>
            <td data-card-label="Status"><span class="attendance-status is-<?= e(strtolower($record['status'])) ?>"><?= e($record['status']) ?></span></td>
            <td data-card-label="Time in"><?= e(format_time($record['time_in'] ?? null)) ?></td>
            <td data-card-label="Source" class="attendance-source"><?= e(str_replace('_', ' ', $record['source'])) ?></td>
        </tr><?php endforeach; ?>
        <?php if (!$records): ?><tr><td colspan="5" class="empty-state">No attendance records have been recorded for this subject yet.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
</section>
<?php endif; ?>
</div></div></div>
<?php unset($isStudent, $isStudentPrimary, $canManageAttendance, $selectedAttendanceSubject, $personalAttendanceView); ?>
