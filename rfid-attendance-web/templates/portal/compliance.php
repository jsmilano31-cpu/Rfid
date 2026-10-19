<?php
$isTeacherCompliance = (bool) ($isTeacherCompliance ?? false);
$isStudentCompliance = (bool) ($isStudentCompliance ?? false);
$subjectComplianceRows = $subjectComplianceRows ?? [];
$studentCompliance = $isStudentCompliance ? ($complianceRows[0] ?? []) : [];
?>
<div class="section-navigation-page" data-section-navigation>
<header class="page-header"><div><p class="eyebrow">Attendance requirements</p><h1>Compliance</h1><p><?= $isTeacherCompliance ? 'Review compliance only for students enrolled in your assigned subjects.' : 'Each deficiency is traced to class, event, or formation attendance.' ?></p></div></header>
<div class="section-navigation-layout">
<?php
$sectionNavigationLabel = 'Compliance page sections';
$sectionNavigation = [['id' => 'portal-compliance-summary', 'label' => 'Compliance summary']];
if ($isStudentCompliance) $sectionNavigation[] = ['id' => 'portal-subject-compliance', 'label' => 'Per-subject compliance'];
require BASE_PATH . '/templates/partials/section_navigation.php';
?>
<div class="section-navigation-content">
<section class="section-scroll-target" id="portal-compliance-summary" data-section-target>
    <div class="section-heading">
        <div>
            <h2>General compliance summary</h2>
            <p class="muted"><?= $isTeacherCompliance ? 'Each subject is listed separately so attendance deficiencies stay tied to the correct class.' : 'Attendance deficiencies calculated using the current campus rules.' ?></p>
        </div>
        <?php if (!$isStudentCompliance && $compliancePagination): ?>
            <span class="muted">Showing <?= e($compliancePagination['from']) ?>–<?= e($compliancePagination['to']) ?> of <?= e($compliancePagination['total']) ?> students</span>
        <?php endif; ?>
    </div>

    <?php if (!$isStudentCompliance): ?>
    <!-- Search & Filters Form -->
    <form method="get" action="<?= e(url('/index/')) ?>" class="inline-form card data-filter-form" style="margin-bottom: 16px;">
        <input type="hidden" name="page" value="compliance">
        <label class="field filter-search" style="flex:1;">
            <span>Search students</span>
            <input type="search" name="q" value="<?= e($filters['q'] ?? $_GET['q'] ?? '') ?>" placeholder="Name or school ID…">
        </label>
        <?php if (!empty($programs)): ?>
        <label class="field">
            <span>Program</span>
            <select name="program_id" data-searchable>
                <option value="">All programs</option>
                <?php foreach ($programs as $prog): ?>
                    <option value="<?= e($prog['id']) ?>" <?= (string)($filters['program_id'] ?? $_GET['program_id'] ?? '') === (string)$prog['id'] ? 'selected' : '' ?>><?= e($prog['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <?php if (!empty($sections)): ?>
        <label class="field">
            <span>Section</span>
            <select name="section_id" data-searchable>
                <option value="">All sections</option>
                <?php foreach ($sections as $sec): ?>
                    <option value="<?= e($sec['id']) ?>" <?= (string)($filters['section_id'] ?? $_GET['section_id'] ?? '') === (string)$sec['id'] ? 'selected' : '' ?>><?= e($sec['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <div class="filter-actions">
            <button class="button button-primary">Apply filters</button>
            <a class="button button-secondary" href="<?= e(url('/index/?page=compliance')) ?>">Clear</a>
        </div>
    </form>
    <?php endif; ?>

    <?php if ($isStudentCompliance): ?>
    <div class="compliance-overview-grid">
        <article class="compliance-overview-card"><span>Class absences</span><strong><?= e($studentCompliance['absences'] ?? 0) ?></strong></article>
        <article class="compliance-overview-card"><span>Tardies</span><strong><?= e($studentCompliance['tardies'] ?? 0) ?></strong></article>
        <article class="compliance-overview-card"><span>Tardy equivalent</span><strong><?= e($studentCompliance['tardy_equivalent'] ?? 0) ?></strong></article>
        <article class="compliance-overview-card"><span>Missed events</span><strong><?= e($studentCompliance['missed_events'] ?? 0) ?></strong></article>
        <article class="compliance-overview-card"><span>Missed recollections</span><strong><?= e($studentCompliance['missed_recollections'] ?? 0) ?></strong></article>
        <article class="compliance-overview-card is-total"><span>Total deficiencies</span><strong><?= e($studentCompliance['total_deficiencies'] ?? 0) ?></strong></article>
    </div>
    <?php else: ?>
    <div class="table-wrap"><table data-no-datatable><thead><tr><th>Student</th><?php if ($isTeacherCompliance): ?><th>Subject code</th><?php endif; ?><th>Section</th><th>Class absences</th><th>Tardies</th><th>Tardy equivalent</th><th>Missed events</th><th>Missed recollections</th><th>Total</th></tr></thead><tbody>
    <?php foreach ($complianceRows as $row): ?><tr>
        <td>
            <a class="entity-user-trigger" data-entity-user data-user-id="<?= e($row['id']) ?>" data-user-name="<?= e($row['student_name']) ?>" data-user-school-id="<?= e($row['school_id']) ?>" data-user-role="Student" href="<?= e(url('/index/?page=directory_profile&id=' . (int) $row['id'])) ?>">
                <strong><?= e($row['student_name']) ?></strong>
            </a>
            <br><span class="muted"><?= e($row['school_id']) ?></span>
        </td>
        <?php if ($isTeacherCompliance): ?><td><a class="entity-link" href="<?= e(url('/index/?page=attendance&offering_id=' . (int) $row['class_offering_id'])) ?>" title="Open <?= e($row['course_title']) ?> attendance"><?= e($row['course_code']) ?></a></td><?php endif; ?>
        <td><?= e($row['section_name']) ?></td><td><?= e($row['absences']) ?></td><td><?= e($row['tardies']) ?></td><td><?= e($row['tardy_equivalent']) ?></td><td><?= e($row['missed_events']) ?></td><td><?= e($row['missed_recollections']) ?></td><td><span class="badge <?= $row['total_deficiencies'] ? 'badge-absent' : 'badge-active' ?>"><?= e($row['total_deficiencies']) ?></span></td>
    </tr><?php endforeach; ?>
    <?php if (!$complianceRows): ?><tr><td colspan="<?= $isTeacherCompliance ? '9' : '8' ?>" class="empty-state"><?= $isTeacherCompliance ? 'No active students are enrolled in your assigned subjects.' : 'No compliance records are available.' ?></td></tr><?php endif; ?>
    </tbody></table></div>
    <?php if ($compliancePagination && $compliancePagination['pages'] > 1): ?>
        <nav class="server-pagination" aria-label="Compliance pages">
            <?php for ($pageNumber = 1; $pageNumber <= $compliancePagination['pages']; $pageNumber++): ?>
                <?php $pageQuery = http_build_query(array_filter(['page' => 'compliance', 'q' => $filters['q'] ?? $_GET['q'] ?? '', 'program_id' => $filters['program_id'] ?? $_GET['program_id'] ?? '', 'section_id' => $filters['section_id'] ?? $_GET['section_id'] ?? '', 'compliance_page' => $pageNumber], static fn($v) => $v !== '' && $v !== 0)); ?>
                <a class="<?= $pageNumber === $compliancePagination['page'] ? 'is-active' : '' ?>" href="<?= e(url('/index/?' . $pageQuery)) ?>" <?= $pageNumber === $compliancePagination['page'] ? 'aria-current="page"' : '' ?>><?= e($pageNumber) ?></a>
            <?php endfor; ?>
        </nav>
    <?php endif; ?>
    <?php endif; ?>
    <p class="compliance-rule-note muted">Tardy equivalents use the current attendance rule. Missed required events and recollections are counted only when an absence or incomplete record exists.</p>
</section>

<?php if ($isStudentCompliance): ?>
<section class="section section-scroll-target" id="portal-subject-compliance" data-section-target>
    <div class="section-heading">
        <div><h2>Per-subject compliance</h2><p class="muted">Review class absences and tardies separately for each enrolled subject.</p></div>
        <?php $recordViewTarget = 'student-subject-compliance'; $recordViewLabel = 'Subject compliance display'; require BASE_PATH . '/templates/partials/record_view_switch.php'; ?>
    </div>
    <div id="student-subject-compliance" class="record-view-container record-view-compliance is-card-view" data-record-view-container data-view-storage="rfid-student-compliance-view">
        <div class="table-wrap"><table><thead><tr><th>Course</th><th>Section</th><th>Absences</th><th>Tardies</th><th>Tardy equivalent</th><th>Deficiencies</th></tr></thead><tbody>
        <?php foreach ($subjectComplianceRows as $subject): ?><tr>
            <td data-card-label="Course"><a class="entity-link" href="<?= e(url('/index/?page=attendance&offering_id=' . (int) $subject['class_offering_id'] . '&view=personal')) ?>"><strong><?= e($subject['course_code']) ?></strong><span><?= e($subject['course_title']) ?></span></a></td>
            <td data-card-label="Section"><?= e($subject['section_name']) ?></td>
            <td data-card-label="Absences"><?= e($subject['absences']) ?></td>
            <td data-card-label="Tardies"><?= e($subject['tardies']) ?></td>
            <td data-card-label="Tardy equivalent"><?= e($subject['tardy_equivalent']) ?></td>
            <td data-card-label="Deficiencies"><span class="badge <?= $subject['class_deficiencies'] ? 'badge-absent' : 'badge-active' ?>"><?= e($subject['class_deficiencies']) ?></span></td>
        </tr><?php endforeach; ?>
        <?php if (!$subjectComplianceRows): ?><tr><td colspan="6" class="empty-state">You do not have any active enrolled subjects yet.</td></tr><?php endif; ?>
        </tbody></table></div>
    </div>
</section>
<?php endif; ?>
</div></div></div>
<?php unset($isTeacherCompliance, $isStudentCompliance, $subjectComplianceRows, $studentCompliance); ?>
