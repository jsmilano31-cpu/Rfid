<?php
$canManageGrades = \App\Core\Auth::can('manage_grades');
$isStudentPrimary = $user['role_name'] === 'Student';
$selectedGradeSubject = $selectedGradeSubject ?? null;
$personalGradeView = $isStudentPrimary && ($_GET['view'] ?? '') === 'personal';
?>
<div class="section-navigation-page" data-section-navigation>
<header class="page-header"><div><p class="eyebrow">Academic performance</p><h1>Grades</h1><p><?= $canManageGrades ? 'Open an assigned subject and manage its student grades.' : 'Review your recorded grades by subject.' ?></p></div></header>
<div class="section-navigation-layout">
<?php
$sectionNavigationLabel = 'Grades page sections';
$sectionNavigation = [];
if ($canManageGrades && !$personalGradeView) $sectionNavigation[] = ['id' => 'portal-class-grades', 'label' => 'Class grades'];
if ($isStudentPrimary) $sectionNavigation[] = ['id' => 'portal-my-grades', 'label' => 'My grades'];
require BASE_PATH . '/templates/partials/section_navigation.php';
?>
<div class="section-navigation-content">
<?php if ($canManageGrades && !$personalGradeView): ?><section class="section-scroll-target" id="portal-class-grades" data-section-target><?php $gradeManagerContext = 'portal'; require BASE_PATH . '/templates/partials/grade_management.php'; ?></section><?php endif; ?>
<?php if ($isStudentPrimary): ?>
<section class="section section-scroll-target" id="portal-my-grades" data-section-target>
    <div class="section-heading">
        <div><h2><?= $selectedGradeSubject ? e($selectedGradeSubject['course_code'] . ' - My grades') : 'My grades' ?></h2><p><?= $selectedGradeSubject ? e($selectedGradeSubject['course_title'] . ' - ' . $selectedGradeSubject['section_name']) : 'Your own academic record is kept separate from classes you may manage.' ?></p></div>
        <div class="personal-record-actions">
            <?php if ($selectedGradeSubject): ?><a class="button button-secondary" href="<?= e(url('/index/?page=grades&view=personal')) ?>">All subjects</a><?php endif; ?>
            <?php $recordViewTarget = 'student-grade-records'; $recordViewLabel = 'Grade display'; require BASE_PATH . '/templates/partials/record_view_switch.php'; ?>
        </div>
    </div>
    <div id="student-grade-records" class="record-view-container record-view-grades is-card-view" data-record-view-container data-view-storage="rfid-student-grades-view">
        <div class="table-wrap"><table><thead><tr><th>Course</th><th>Prelim</th><th>Midterm</th><th>Final</th><th>Overall</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($gradeRows as $grade): $gradeStatus = $grade['status'] ?? 'unentered'; ?><tr>
            <td data-card-label="Course"><a class="entity-link" href="<?= e(url('/index/?page=grades&offering_id=' . (int) $grade['class_offering_id'] . '&view=personal')) ?>"><strong><?= e($grade['course_code']) ?></strong><span><?= e($grade['title']) ?> · <?= e($grade['section_name']) ?></span></a></td>
            <td data-card-label="Prelim"><?= e($grade['prelim'] ?? '—') ?></td>
            <td data-card-label="Midterm"><?= e($grade['midterm'] ?? '—') ?></td>
            <td data-card-label="Final"><?= e($grade['final'] ?? '—') ?></td>
            <td data-card-label="Overall"><strong><?= e($grade['overall'] ?? '—') ?></strong></td>
            <td data-card-label="Status"><span class="grade-status is-<?= e($gradeStatus) ?>"><?= e($gradeStatus === 'unentered' ? 'Not entered' : ucfirst($gradeStatus)) ?></span></td>
        </tr><?php endforeach; ?>
        <?php if (!$gradeRows): ?><tr><td colspan="6" class="empty-state">You do not have any enrolled subjects yet.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
</section>
<?php endif; ?>
</div></div></div>
