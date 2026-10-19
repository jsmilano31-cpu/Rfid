<?php
$gradeManagerContext = $gradeManagerContext ?? 'portal';
$gradeManagerId = 'grade-manager-' . $gradeManagerContext;
$gradeEditDialogId = $gradeManagerId . '-edit';
$gradeAdvancedDialogId = $gradeManagerId . '-advanced';
?>
<section class="card grade-manager" id="<?= e($gradeManagerId) ?>" data-grade-manager data-endpoint="<?= e(url('/grades.php')) ?>">
    <div class="grade-manager-heading">
        <div>
            <p class="eyebrow">Grade workspace</p>
            <h2 data-grade-workspace-title>Assigned subjects</h2>
            <p class="muted" data-grade-workspace-description>Choose a subject to open its class roster and manage student grades.</p>
        </div>
        <div class="grade-manager-actions">
            <button type="button" class="button button-secondary" data-dialog-open="#<?= e($gradeAdvancedDialogId) ?>">
                <svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5"/><path d="m13 13 4 4M6 6h5M6 9h3"/></svg>
                Advanced search
            </button>
            <div class="view-switch" role="group" aria-label="Subject display">
                <button type="button" class="is-active" data-grade-view="cards" aria-pressed="true" title="Card view"><svg viewBox="0 0 20 20" aria-hidden="true"><rect x="3" y="3" width="5.5" height="5.5" rx="1"/><rect x="11.5" y="3" width="5.5" height="5.5" rx="1"/><rect x="3" y="11.5" width="5.5" height="5.5" rx="1"/><rect x="11.5" y="11.5" width="5.5" height="5.5" rx="1"/></svg><span class="sr-only">Card view</span></button>
                <button type="button" data-grade-view="list" aria-pressed="false" title="List view"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M7 5h10M7 10h10M7 15h10"/><circle cx="3.5" cy="5" r=".7"/><circle cx="3.5" cy="10" r=".7"/><circle cx="3.5" cy="15" r=".7"/></svg><span class="sr-only">List view</span></button>
            </div>
        </div>
    </div>

    <div data-grade-subject-browser>
        <div class="grade-search-row">
            <label class="grade-search-box"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5"/><path d="m13 13 4 4"/></svg><span class="sr-only">Search subjects</span><input type="search" data-grade-subject-search autocomplete="off" placeholder="Search course code, title, program, or section"></label>
            <span class="grade-result-summary" data-grade-subject-summary aria-live="polite"></span>
        </div>
        <div class="grade-loading" data-grade-subject-loading role="status">Loading assigned subjects…</div>
        <div data-grade-subject-results></div>
        <nav class="server-pagination grade-pagination" data-grade-subject-pagination aria-label="Subject pages"></nav>
    </div>

    <div data-grade-roster-browser hidden>
        <div class="grade-roster-header">
            <button type="button" class="button button-secondary button-small" data-grade-back><svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="m12.5 5-5 5 5 5"/></svg>Subjects</button>
            <div><strong data-grade-subject-name></strong><span class="muted" data-grade-subject-detail></span></div>
        </div>
        <div class="grade-search-row">
            <label class="grade-search-box"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5"/><path d="m13 13 4 4"/></svg><span class="sr-only">Search students</span><input type="search" data-grade-student-search autocomplete="off" placeholder="Search school ID or student name"></label>
            <span class="grade-result-summary" data-grade-roster-summary aria-live="polite"></span>
        </div>
        <div class="table-wrap grade-roster-table"><table data-no-datatable><thead><tr><th>Student</th><th>Prelim</th><th>Midterm</th><th>Final</th><th>Overall</th><th>Status</th><th><span class="sr-only">Action</span></th></tr></thead><tbody data-grade-roster-results></tbody></table></div>
        <div class="grade-loading" data-grade-roster-loading role="status" hidden>Loading class roster…</div>
        <nav class="server-pagination grade-pagination" data-grade-roster-pagination aria-label="Roster pages"></nav>
    </div>
</section>

<dialog id="<?= e($gradeEditDialogId) ?>" class="app-dialog" data-modal data-grade-edit-dialog aria-labelledby="<?= e($gradeEditDialogId) ?>-title">
    <div class="app-dialog-shell">
        <header class="app-dialog-header"><div><p class="eyebrow">Student grade</p><h2 id="<?= e($gradeEditDialogId) ?>-title">Edit grade</h2><p class="muted" data-grade-edit-subject></p></div><button type="button" class="dialog-close" data-dialog-close aria-label="Close grade editor"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button></header>
        <div class="app-dialog-body">
            <form class="stack" data-grade-edit-form>
                <?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="save_grade"><input type="hidden" name="class_offering_id"><input type="hidden" name="student_id"><input type="hidden" name="grade_id">
                <div class="grade-student-editor-heading"><img src="<?= e(url('/assets/images/default-avatar.svg')) ?>" alt="" data-grade-student-avatar><div><strong data-grade-student-name></strong><span data-grade-student-id></span></div><span class="grade-status" data-grade-edit-status></span></div>
                <div class="form-grid grade-input-grid">
                    <label class="field"><span>Prelim</span><input type="number" name="prelim" min="0" max="100" step=".01" inputmode="decimal" required></label>
                    <label class="field"><span>Midterm</span><input type="number" name="midterm" min="0" max="100" step=".01" inputmode="decimal" required></label>
                    <label class="field"><span>Final</span><input type="number" name="final" min="0" max="100" step=".01" inputmode="decimal" required></label>
                    <div class="grade-overall-preview"><span>Overall</span><strong data-grade-overall-preview>—</strong></div>
                </div>
                <div class="alert alert-warning" data-grade-finalized-note hidden>This grade is finalized. Reopen it before making changes.</div>
                <div class="ajax-form-footer"><span class="ajax-form-status" data-grade-edit-feedback aria-live="polite"></span><div class="form-actions"><button type="button" class="button button-secondary" data-grade-reopen hidden>Reopen grade</button><button type="submit" class="button button-secondary" data-grade-save-draft>Save draft</button><button type="submit" class="button button-primary" data-grade-finalize>Finalize grade</button></div></div>
            </form>
        </div>
    </div>
</dialog>

<dialog id="<?= e($gradeAdvancedDialogId) ?>" class="app-dialog app-dialog-large" data-modal aria-labelledby="<?= e($gradeAdvancedDialogId) ?>-title">
    <div class="app-dialog-shell">
        <header class="app-dialog-header"><div><p class="eyebrow">Advanced search</p><h2 id="<?= e($gradeAdvancedDialogId) ?>-title">Find a specific grade</h2><p class="muted">Narrow the class first, then search its enrolled students by ID or name.</p></div><button type="button" class="dialog-close" data-dialog-close aria-label="Close advanced grade search"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button></header>
        <div class="app-dialog-body">
            <form class="stack" data-grade-advanced-form>
                <?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="save_grade">
                <?php require BASE_PATH . '/templates/partials/class_offering_selector.php'; ?>
                <?php $lookupName = 'student_id'; $lookupLabel = 'Student'; $lookupContext = 'grade_enrollments'; $lookupParentName = 'class_offering_id'; require BASE_PATH . '/templates/partials/remote_user_lookup.php'; unset($lookupName, $lookupLabel, $lookupContext, $lookupParentName); ?>
                <div class="form-grid grade-input-grid"><label class="field"><span>Prelim</span><input type="number" name="prelim" min="0" max="100" step=".01" required></label><label class="field"><span>Midterm</span><input type="number" name="midterm" min="0" max="100" step=".01" required></label><label class="field"><span>Final</span><input type="number" name="final" min="0" max="100" step=".01" required></label></div>
                <div class="ajax-form-footer"><span class="ajax-form-status" data-grade-advanced-feedback aria-live="polite"></span><div class="form-actions"><button type="submit" class="button button-secondary" data-grade-save-draft>Save draft</button><button type="submit" class="button button-primary" data-grade-finalize>Finalize grade</button></div></div>
            </form>
        </div>
    </div>
</dialog>
<?php unset($gradeManagerContext, $gradeManagerId, $gradeEditDialogId, $gradeAdvancedDialogId); ?>
