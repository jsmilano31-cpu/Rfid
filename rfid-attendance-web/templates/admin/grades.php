<?php $canManageGradingSettings = \App\Core\Auth::can('manage_grading_settings'); ?>
<div class="section-navigation-page" data-section-navigation>
<header class="page-header">
    <div>
        <p class="eyebrow">Academic records</p>
        <h1>Academic grade explorer & management</h1>
        <p>Search campus subjects, configure grading policies, review student transcripts, and manage grade finalizations.</p>
    </div>
</header>

<div class="section-navigation-layout">
<?php
$sectionNavigationLabel = 'Grade page sections';
$sectionNavigation = [];
if ($canManageGradingSettings) $sectionNavigation[] = ['id' => 'admin-grades-policies', 'label' => 'Grading system policies'];
if (\App\Core\Auth::can('manage_grades')) {
    $sectionNavigation[] = ['id' => 'admin-grades-subjects', 'label' => 'Subject grade sheets'];
    $sectionNavigation[] = ['id' => 'admin-grades-students', 'label' => 'Student transcript lookup'];
}
require BASE_PATH . '/templates/admin/partials/section_navigation.php';
?>

<div class="section-navigation-content">

<?php if ($canManageGradingSettings): ?>
<?php
$gSettings = $gradingSettings ?? \App\Services\GradeService::settings();
?>
<!-- Section 0: Grading System Policies -->
<section class="card section section-scroll-target" id="admin-grades-policies" data-section-target data-grading-policy-container style="margin-bottom: 24px;">
    <div class="section-heading">
        <div>
            <p class="eyebrow">Academic Governance</p>
            <h2>Grading System Policies</h2>
            <p class="muted">Configure active evaluation terms (Prelims, Midterms, Endterms), term percentage weights, and institutional passing criteria.</p>
        </div>
    </div>

    <?php require BASE_PATH . '/templates/partials/grading_policy_form.php'; ?>
</section>

<?php endif; ?>

<!-- Section 1: Subject Grade Sheets Explorer -->
<?php if (\App\Core\Auth::can('manage_grades')): ?>
<section class="card section-scroll-target" id="admin-grades-subjects" data-section-target data-admin-grade-manager data-endpoint="<?= e(url('/grades.php')) ?>">
    <div class="grade-manager-heading">
        <div>
            <h2>Campus subject grade sheets</h2>
            <p class="muted">Search and filter across all active subjects, review submitted grades, and open class rosters.</p>
        </div>
        <div class="grade-manager-actions">
            <div class="view-switch" role="group" aria-label="Subject display">
                <button type="button" class="is-active" data-admin-grade-view="cards" aria-pressed="true" title="Card view">
                    <svg viewBox="0 0 20 20" aria-hidden="true"><rect x="3" y="3" width="5.5" height="5.5" rx="1"/><rect x="11.5" y="3" width="5.5" height="5.5" rx="1"/><rect x="3" y="11.5" width="5.5" height="5.5" rx="1"/><rect x="11.5" y="11.5" width="5.5" height="5.5" rx="1"/></svg>
                    <span class="sr-only">Card view</span>
                </button>
                <button type="button" data-admin-grade-view="list" aria-pressed="false" title="List view">
                    <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M7 5h10M7 10h10M7 15h10"/><circle cx="3.5" cy="5" r=".7"/><circle cx="3.5" cy="10" r=".7"/><circle cx="3.5" cy="15" r=".7"/></svg>
                    <span class="sr-only">List view</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Search & Advanced Filter Controls -->
    <div class="card grade-explorer-filters" style="margin-bottom:18px; padding:16px;">
        <div class="grade-search-row" style="margin-bottom:12px;">
            <label class="grade-search-box" style="flex:1;">
                <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5"/><path d="m13 13 4 4"/></svg>
                <span class="sr-only">Search subjects</span>
                <input type="search" data-admin-grade-search autocomplete="off" placeholder="Search course code, subject title, teacher, program, or section…">
            </label>
            <span class="grade-result-summary" data-admin-grade-summary aria-live="polite"></span>
        </div>

        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:10px; align-items:flex-end;">
            <label class="field" style="margin:0;">
                <span>Program</span>
                <select data-admin-grade-program data-searchable>
                    <option value="">All programs</option>
                    <?php foreach ($programs as $prog): ?>
                        <option value="<?= e($prog['id']) ?>"><?= e($prog['code']) ?> — <?= e($prog['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field" style="margin:0;">
                <span>Section</span>
                <select data-admin-grade-section data-searchable>
                    <option value="">All sections</option>
                    <?php foreach ($sections as $sec): ?>
                        <option value="<?= e($sec['id']) ?>"><?= e($sec['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field" style="margin:0;">
                <span>Status</span>
                <select data-admin-grade-status data-no-search>
                    <option value="">All status</option>
                    <option value="finalized">Finalized</option>
                    <option value="draft">In progress (Draft)</option>
                    <option value="ungraded">Ungraded</option>
                </select>
            </label>
            <label class="field" style="margin:0;">
                <span>Sort by</span>
                <select data-admin-grade-sort data-no-search>
                    <option value="code_asc">Course code (A–Z)</option>
                    <option value="code_desc">Course code (Z–A)</option>
                    <option value="title_asc">Subject title</option>
                    <option value="section">Section</option>
                    <option value="students_desc">Student count</option>
                    <option value="average_desc">Highest class average</option>
                    <option value="average_asc">Lowest class average</option>
                </select>
            </label>
        </div>
    </div>

    <!-- Subjects Browser Content -->
    <div data-admin-grade-subject-browser>
        <div class="grade-loading" data-admin-grade-subject-loading role="status">Loading campus subjects…</div>
        <div data-admin-grade-subject-results></div>
        <nav class="server-pagination grade-pagination" data-admin-grade-subject-pagination aria-label="Subject pages"></nav>
    </div>

    <!-- Subject Roster Sheet (When subject selected) -->
    <div data-admin-grade-roster-browser hidden style="border-top:1px solid var(--line); padding-top:18px; margin-top:20px;">
        <div class="grade-roster-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:14px;">
            <button type="button" class="button button-secondary button-small" data-admin-grade-back>
                <svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="m12.5 5-5 5 5 5"/></svg>
                Back to subject list
            </button>
            <div>
                <strong data-admin-grade-subject-name style="font-size:16px;"></strong>
                <span class="muted" data-admin-grade-subject-detail style="display:block; font-size:12px;"></span>
            </div>
            <div style="margin-left:auto;">
                <label class="grade-search-box" style="width:240px;">
                    <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5"/><path d="m13 13 4 4"/></svg>
                    <input type="search" data-admin-grade-student-search autocomplete="off" placeholder="Search student…">
                </label>
            </div>
        </div>

        <div class="table-wrap grade-roster-table">
            <table data-no-datatable>
                <thead>
                    <tr>
                        <th>Student</th>
                        <th data-grade-term="prelim" <?= \App\Services\GradeService::settings()['has_prelim'] ? '' : 'hidden' ?>>Prelims</th>
                        <th data-grade-term="midterm" <?= \App\Services\GradeService::settings()['has_midterm'] ? '' : 'hidden' ?>>Midterms</th>
                        <th data-grade-term="final" <?= \App\Services\GradeService::settings()['has_final'] ? '' : 'hidden' ?>>Endterms</th>
                        <th>Overall</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody data-admin-grade-roster-results></tbody>
            </table>
        </div>
        <div class="grade-loading" data-admin-grade-roster-loading role="status" hidden>Loading class roster…</div>
        <nav class="server-pagination grade-pagination" data-admin-grade-roster-pagination aria-label="Roster pages"></nav>
    </div>
</section>

<!-- Section 2: Student Grade Lookup -->
<section class="card section section-scroll-target" id="admin-grades-students" data-section-target style="margin-top: 24px;">
    <div class="section-heading">
        <div>
            <h2>Student transcript & grade lookup</h2>
            <p class="muted">Find students within your grade access to inspect their enrolled subjects, evaluations, and overall GPA.</p>
        </div>
    </div>

    <div class="student-grade-search" data-admin-student-grade-search>
        <div class="grade-search-row">
            <label class="grade-search-box">
                <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5"/><path d="m13 13 4 4"/></svg>
                <input type="search" data-admin-student-grade-lookup value="<?= e($_GET['student_q'] ?? $_GET['q'] ?? '') ?>" autocomplete="off" placeholder="Search by student name or school ID" aria-controls="student-grade-search-matches" aria-describedby="student-grade-search-status" aria-expanded="false">
            </label>
            <span class="grade-result-summary" id="student-grade-search-status" data-admin-student-grade-summary aria-live="polite"></span>
        </div>
        <div class="student-grade-search-matches" id="student-grade-search-matches" data-admin-student-grade-matches role="region" aria-label="Matching students" hidden></div>
    </div>

    <div class="student-grade-lookup-results" data-admin-student-grade-results hidden>
        <!-- Student Info Header -->
        <div class="card student-grade-lookup-card" style="margin-bottom:16px; padding:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:14px;">
            <div style="display:flex; align-items:center; gap:14px;">
                <img src="<?= e(url('/assets/images/default-avatar.svg')) ?>" alt="" style="width:48px; height:48px; border-radius:50%; object-fit:cover;" data-lookup-student-avatar>
                <div>
                    <h3 style="margin:0 0 2px;"><a href="#" class="student-grade-lookup-link entity-user-trigger" data-lookup-student-name data-entity-user data-user-id="" data-user-name="">—</a></h3>
                    <a href="#" class="student-grade-lookup-link student-grade-lookup-id" data-lookup-student-id>—</a>
                    <div style="font-size:12px; color:var(--muted); margin-top:3px;" data-lookup-student-details><span data-lookup-student-program>—</span> · <span data-lookup-student-section>—</span> · <span data-lookup-student-department>—</span></div>
                </div>
            </div>
            <div style="text-align:right;">
                <span class="eyebrow">Calculated GPA</span>
                <strong style="font-size:26px; display:block; color:var(--brand-dark);" data-lookup-student-gpa>—</strong>
                <small class="muted" data-lookup-student-progress>0 / 0 subjects completed</small>
            </div>
        </div>

        <!-- Student Enrolled Classes & Grades Table -->
        <div class="table-wrap">
            <table data-no-datatable>
                <thead>
                    <tr>
                        <th>Subject</th>
                        <th>Section</th>
                        <th>Teacher</th>
                        <th>Units</th>
                        <th data-grade-term="prelim" <?= \App\Services\GradeService::settings()['has_prelim'] ? '' : 'hidden' ?>>Prelims</th>
                        <th data-grade-term="midterm" <?= \App\Services\GradeService::settings()['has_midterm'] ? '' : 'hidden' ?>>Midterms</th>
                        <th data-grade-term="final" <?= \App\Services\GradeService::settings()['has_final'] ? '' : 'hidden' ?>>Endterms</th>
                        <th>Overall</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody data-lookup-student-grades-tbody></tbody>
            </table>
        </div>
    </div>
</section>

<?php endif; ?>
</div>
</div>
</div>

<!-- Admin Grade Edit / Override Dialog -->
<?php if (\App\Core\Auth::can('manage_grades')): ?>
<dialog id="admin-grade-edit-dialog" class="app-dialog" data-modal aria-labelledby="admin-grade-edit-title">
    <div class="app-dialog-shell">
        <header class="app-dialog-header">
            <div>
                <p class="eyebrow">Student grade override</p>
                <h2 id="admin-grade-edit-title">Edit student grade</h2>
                <p class="muted" data-admin-grade-edit-subject></p>
            </div>
            <button type="button" class="dialog-close" data-dialog-close aria-label="Close dialog"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button>
        </header>
        <div class="app-dialog-body">
            <form class="stack" data-admin-grade-edit-form data-endpoint="<?= e(url('/grades.php')) ?>">
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="action" value="save_grade">
                <input type="hidden" name="class_offering_id">
                <input type="hidden" name="student_id">
                <input type="hidden" name="grade_id">

                <div class="grade-student-editor-heading">
                    <img src="<?= e(url('/assets/images/default-avatar.svg')) ?>" alt="" data-admin-grade-student-avatar>
                    <div>
                        <strong data-admin-grade-student-name></strong>
                        <span data-admin-grade-student-id></span>
                    </div>
                    <span class="grade-status" data-admin-grade-edit-status></span>
                </div>

                <div class="form-grid grade-input-grid">
                    <label class="field" data-grade-term="prelim"><span>Prelims</span><input type="number" name="prelim" min="0" max="100" step=".01" inputmode="decimal" required></label>
                    <label class="field" data-grade-term="midterm"><span>Midterms</span><input type="number" name="midterm" min="0" max="100" step=".01" inputmode="decimal" required></label>
                    <label class="field" data-grade-term="final"><span>Endterms</span><input type="number" name="final" min="0" max="100" step=".01" inputmode="decimal" required></label>
                </div>

                <div class="grade-computed-row">
                    <span>Computed overall</span>
                    <strong data-admin-grade-edit-overall>—</strong>
                </div>

                <section class="shared-grade-review" data-shared-grade-review hidden aria-label="Review component grade differences">
                    <h3>Component grades need review</h3>
                    <p class="muted">The stored lecture and lab grades disagree. No shared score is shown until an academic records manager reviews them. Original changes remain in the audit trail.</p>
                    <div class="shared-grade-sources" data-shared-grade-sources></div>
                    <p class="field-help" data-shared-grade-access></p>
                    <button type="button" class="button button-secondary" data-shared-grade-resolve hidden>Use selected scores as shared draft</button>
                </section>
                <label class="grade-finalize-check">
                    <input type="checkbox" name="finalize" value="1" data-admin-grade-finalize-check>
                    <span>Finalize grade sheet entry for this student</span>
                </label>

                <div class="ajax-form-footer">
                    <span class="ajax-form-status" data-admin-grade-edit-feedback aria-live="polite"></span>
                    <div class="table-actions">
                        <button type="button" class="button button-danger" data-admin-grade-reopen-btn hidden>Reopen for editing</button>
                        <button type="button" class="button button-secondary" data-dialog-close>Cancel</button>
                        <button type="submit" class="button button-primary">Save grade</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</dialog>
<?php endif; ?>
