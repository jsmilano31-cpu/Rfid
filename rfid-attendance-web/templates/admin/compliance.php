<?php
$canManageReports = \App\Core\Auth::can('manage_reports');
$studentFilters = array_filter(['q' => $_GET['q'] ?? '', 'program_id' => (int) ($_GET['program_id'] ?? 0), 'section_id' => (int) ($_GET['section_id'] ?? 0)], static fn($value): bool => $value !== '' && $value !== 0);
$complianceTabUrl = static fn(string $tab): string => url('/admin/?' . http_build_query(['page' => 'compliance', 'tab' => $tab] + $studentFilters));
?>
<div class="section-navigation-page compliance-page" data-section-navigation data-compliance-workspace data-current-tab="<?= e($complianceTab) ?>">
<header class="page-header"><div><p class="eyebrow">Attendance outcomes &amp; records</p><h1>Compliance</h1><p>Student deficiencies, attendance summaries, and exports.</p></div>
<?php if ($canManageReports): ?><div class="page-header-actions">
<?php if ($complianceTab === 'students'): ?><a class="button button-secondary" data-compliance-export data-no-page-loader href="<?= e(url('/admin/?' . http_build_query(['page' => 'compliance', 'export' => 'compliance_csv'] + $studentFilters))) ?>"><svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="M4 14v2h12v-2M10 3v9M6 8l4 4 4-4"/></svg>Export compliance CSV</a><?php endif; ?>
<button type="button" class="button button-primary" onclick="window.print()"><svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="M5 6V3h10v3M5 14H3a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v5a1 1 0 0 1-1 1h-2M5 11h10v6H5z"/></svg>Print current view</button>
</div><?php endif; ?></header>
<nav class="compliance-tabs" aria-label="Compliance sections"><?php foreach ($complianceTabs as $tab => $label): ?><a href="<?= e($complianceTabUrl($tab)) ?>" data-compliance-tab="<?= e($tab) ?>" <?= $tab === $complianceTab ? 'aria-current="page"' : '' ?>><?= e($label) ?></a><?php endforeach; ?></nav>
<div class="section-navigation-layout">
<?php
$sectionNavigationLabel = 'Compliance page sections';
$sectionNavigation = match ($complianceTab) {
    'students' => [['id' => 'compliance-filters', 'label' => 'Compliance filters'], ['id' => 'compliance-results', 'label' => 'Student compliance']],
    'classes' => [['id' => 'compliance-class-attendance', 'label' => 'Class attendance']],
    'events' => [['id' => 'compliance-event-attendance', 'label' => 'Event attendance']],
}; require BASE_PATH . '/templates/admin/partials/section_navigation.php'; ?>
<div class="section-navigation-content">
<?php if ($complianceTab === 'students'): ?>
<section class="section-scroll-target" id="compliance-filters" data-section-target>
<form method="get" class="inline-form card data-filter-form" data-live-filter data-live-url="<?= e(url('/admin/?page=compliance&partial=compliance')) ?>" data-live-target="#compliance-results" data-live-partial="compliance" data-live-page-param="compliance_page"><input type="hidden" name="page" value="compliance"><input type="hidden" name="tab" value="students"><label class="field filter-search"><span>Search students</span><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Name or school ID"></label><label class="field"><span>Program</span><select name="program_id" data-searchable><option value="">All programs</option><?php foreach ($programs as $program): ?><option value="<?= e($program['id']) ?>" title="<?= e($program['code'] . ' — ' . $program['name']) ?>" <?= (string)($_GET['program_id'] ?? '') === (string)$program['id'] ? 'selected' : '' ?>><?= e($program['code']) ?> — <?= e($program['name']) ?></option><?php endforeach; ?></select></label><label class="field"><span>Section</span><select name="section_id" data-searchable><option value="">All sections</option><?php foreach ($sections as $section): ?><option value="<?= e($section['id']) ?>" <?= (string)($_GET['section_id'] ?? '') === (string)$section['id'] ? 'selected' : '' ?>><?= e($section['name']) ?></option><?php endforeach; ?></select></label><div class="filter-actions"><button class="button button-primary">Apply filters</button><a class="button button-secondary" href="<?= e(url('/admin/?page=compliance')) ?>" data-live-clear>Clear</a></div><span class="live-filter-status muted" data-live-status aria-live="polite"></span></form>
</section>
<?php require BASE_PATH . '/templates/admin/partials/compliance_table.php'; ?>
<?php else: require BASE_PATH . '/templates/admin/partials/compliance_reports.php'; endif; ?>
</div>
</div>
</div>
<?php if ($canIssueViolations): ?>
<dialog id="issue-violation-dialog" class="app-dialog app-dialog-large violation-dialog" data-modal data-violation-catalog="<?= e(json_encode($violationCatalog, JSON_THROW_ON_ERROR)) ?>" aria-labelledby="issue-violation-title">
<div class="app-dialog-shell"><header class="app-dialog-header"><div><p class="eyebrow">Student conduct</p><h2 id="issue-violation-title">Issue Violation</h2><p class="muted">Record a documented incident in the selected student’s compliance history.</p></div><button type="button" class="dialog-close" data-dialog-close aria-label="Close violation form"><svg viewBox="0 0 20 20"><path d="m5 5 10 10M15 5 5 15"/></svg></button></header>
<div class="app-dialog-body"><form method="post" enctype="multipart/form-data" class="stack violation-form" data-violation-form>
<?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="issue_violation"><input type="hidden" name="student_id">
<div class="violation-student-summary"><span class="violation-student-avatar" data-violation-initial>—</span><span><small>Issuing violation to</small><strong data-violation-student>Student</strong><em data-violation-school-id></em></span></div>
<div class="form-grid">
<label class="field"><span>Violation *</span><select name="violation_group" required data-violation-group data-searchable><option value="">Select a violation group</option><?php foreach ($violationCatalog as $group => $details): ?><option value="<?= e($group) ?>"><?= e($group) ?></option><?php endforeach; ?></select></label>
<label class="field"><span>Specific Violation *</span><select name="specific_violation" required data-specific-violation data-searchable disabled><option value="">Select a violation first</option></select></label>
<label class="field form-grid-wide" data-other-violation hidden><span>Other specific violation *</span><input name="other_violation" maxlength="500" placeholder="Describe the specific violation"></label>
<label class="field"><span>Classification *</span><select name="classification" required data-classification data-no-search><option value="">Select classification</option><option>Category A</option><option>Category B</option><option>Category C</option></select></label>
<label class="form-switch violation-progressive-toggle"><input type="checkbox" name="is_progressive" value="1" data-progressive-toggle><span class="switch-slider" aria-hidden="true"></span><span class="switch-text"><strong>Progressive Violation</strong><small>Track this incident in a sequence of repeated infractions.</small></span></label>
<label class="field" data-progressive-field hidden><span>Previous Infractions *</span><select name="previous_infractions" data-no-search><?php for ($i=0;$i<=20;$i++): ?><option value="<?= $i ?>"><?= $i ?></option><?php endfor; ?></select></label>
<label class="field" data-progressive-field hidden><span>Current Occurrence *</span><select name="current_occurrence" data-no-search><?php for ($i=1;$i<=21;$i++): ?><option value="<?= $i ?>"><?= $i ?></option><?php endfor; ?></select></label>
</div>
<div class="violation-section-heading"><span>Incident details</span></div>
<div class="form-grid">
<label class="field"><span>Incident Date *</span><input type="date" name="incident_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>" required></label>
<label class="field"><span>Incident Time *</span><input type="time" name="incident_time" value="<?= e(date('H:') . str_pad((string) (intdiv((int) date('i'), 5) * 5), 2, '0', STR_PAD_LEFT)) ?>" required></label>
<label class="field form-grid-wide"><span>Location *</span><input name="location" maxlength="180" required placeholder="Building, room, or campus area"></label>
<label class="field form-grid-wide"><span>Incident Description *</span><textarea name="incident_description" rows="5" minlength="10" maxlength="5000" required placeholder="Describe what happened using clear, factual details."></textarea><small class="field-help">Include observable facts and avoid assumptions.</small></label>
<label class="field"><span>Evidence</span><input type="file" name="evidence" accept="application/pdf,image/jpeg,image/png,image/webp"><small class="field-help">Optional PDF, JPG, PNG, or WebP up to 5 MB.</small></label>
<label class="field"><span>Witnesses</span><textarea name="witnesses" rows="3" maxlength="2000" placeholder="Names or school IDs, one per line"></textarea><small class="field-help">Optional. Separate multiple witnesses with a new line.</small></label>
</div>
<div class="alert alert-warning violation-review-note"><strong>Review before submitting.</strong> This creates an official disciplinary record and audit entry.</div>
<div class="form-actions"><button type="button" class="button button-secondary" data-dialog-close>Cancel</button><button type="submit" class="button button-danger">Issue Violation</button></div>
</form></div></div></dialog>
<?php endif; ?>
