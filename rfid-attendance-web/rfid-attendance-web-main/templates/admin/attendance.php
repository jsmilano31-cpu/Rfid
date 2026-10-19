<?php
$canManageAttendancePolicies = \App\Core\Auth::can('manage_attendance_policies');
$canManageAcademicCalendar = \App\Core\Auth::can('manage_academic_calendar');
?>
<div class="section-navigation-page" data-section-navigation>
<header class="page-header">
    <div>
        <p class="eyebrow">Administration</p>
        <h1>Attendance Policies</h1>
        <p>Direct manual correction, universal student attendance lookup, department weekly schedules, and academic term boundaries.</p>
    </div>
    <div class="page-header-actions">
        <?php if ($canManageAttendancePolicies): ?><button type="button" class="button button-secondary" data-dialog-open="#admin-attendance-rules-dialog">
            <svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="M4 5h12M4 10h12M4 15h12M7 3v4M13 8v4M8 13v4"/></svg>
            Campus policy rules
        </button><?php endif; ?>
    </div>
</header>

<div class="section-navigation-layout">
<?php $sectionNavigationLabel = 'Attendance page sections'; $sectionNavigation = [
    ['id' => 'admin-attendance-correction', 'label' => 'Manual correction & lookup'],
    ['id' => 'admin-attendance-explorer', 'label' => 'Campus class explorer'],
];
if ($canManageAcademicCalendar) {
    $sectionNavigation[] = ['id' => 'admin-attendance-weekly-schedules', 'label' => 'Department weekly schedules'];
    $sectionNavigation[] = ['id' => 'admin-attendance-school-year', 'label' => 'School year & academic terms'];
}
require BASE_PATH . '/templates/admin/partials/section_navigation.php'; ?>

<div class="section-navigation-content">

<section class="card section-scroll-target" id="admin-attendance-correction" data-section-target data-admin-attendance-console data-endpoint="<?= e(url('/attendance.php')) ?>">
    <div class="section-heading">
        <div>
            <h2>Manual attendance correction</h2>
            <p class="muted">Search any student by name or school ID to inspect profile records, enrolled subjects, and apply corrections.</p>
        </div>
    </div>

    <div class="admin-attendance-grid">
        <!-- Left: Student Lookup & Profile Card -->
        <div class="stack">
            <div class="card attendance-lookup-box">
                <label class="field">
                    <span>Search student</span>
                    <span class="people-search-shell">
                        <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5"/><path d="m13 13 4 4"/></svg>
                        <input type="search" data-admin-student-search value="<?= e($_GET['student_q'] ?? $_GET['q'] ?? '') ?>" autocomplete="off" placeholder="Type student name, school ID, or email…">
                    </span>
                </label>
                <div class="student-search-results" data-admin-student-results hidden></div>
            </div>

            <!-- Autofilled Student Profile Banner -->
            <div class="card student-autofill-card" data-admin-student-card hidden>
                <div class="student-autofill-header">
                    <img src="<?= e(url('/assets/images/default-avatar.svg')) ?>" alt="" class="student-autofill-avatar" data-student-avatar>
                    <div>
                        <h3 data-student-name class="entity-user-trigger" data-entity-user data-user-id="" data-user-name="" style="margin:0 0 2px; cursor:pointer;" title="Click to view student profile in admin">—</h3>
                        <span class="muted" data-student-id-display>—</span>
                    </div>
                    <button type="button" class="button button-secondary button-small" data-student-profile-btn>
                        <svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="7" r="3.5"/><path d="M4 17.5a6 6 0 0 1 12 0"/></svg>
                        View profile
                    </button>
                </div>
                <div class="student-autofill-details">
                    <div><span>Department:</span> <strong data-student-department>—</strong></div>
                    <div><span>Program:</span> <strong data-student-program>—</strong></div>
                    <div><span>Section:</span> <strong data-student-section>—</strong></div>
                    <div><span>RFID Card:</span> <span class="badge" data-student-rfid>—</span></div>
                    <div><span>Deficiencies:</span> <span class="badge" data-student-deficiencies>0</span></div>
                </div>

                <!-- Enrolled Subjects Breakdown -->
                <div class="student-enrolled-subjects" style="margin-top: 14px; border-top: 1px solid var(--line); padding-top: 12px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                        <strong style="font-size: 13px;">Enrolled subjects</strong>
                        <small class="muted">Click a subject to load into correction form</small>
                    </div>
                    <div class="student-subjects-list" data-student-subjects-list>
                        <p class="muted" style="font-size:12px; margin:0;">No student selected.</p>
                    </div>
                    <div class="student-subjects-pagination" data-student-subjects-pagination hidden>
                        <span class="muted" data-student-subjects-page-info>Page 1 of 1</span>
                        <div style="display:flex; gap:6px;">
                            <button type="button" class="button button-secondary button-small" data-student-subjects-prev disabled>&larr; Prev</button>
                            <button type="button" class="button button-secondary button-small" data-student-subjects-next disabled>Next &rarr;</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right: Correction Form -->
        <div class="card">
            <form class="stack" data-admin-correction-form>
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="action" value="manual_attendance">
                <input type="hidden" name="student_id" data-form-student-id>

                <h3 style="margin-top:0;">Attendance adjustment</h3>

                <div class="form-grid">
                    <label class="field">
                        <span>Student name *</span>
                        <input type="text" data-form-student-name placeholder="Select a student from lookup" readonly required>
                    </label>
                    <label class="field">
                        <span>School ID</span>
                        <input type="text" data-form-school-id placeholder="—" readonly>
                    </label>
                </div>

                <label class="field">
                    <span>Class offering / Subject *</span>
                    <select name="class_offering_id" data-form-offering-select required>
                        <option value="">Select or search subject…</option>
                        <?php foreach ($offerings as $offering): ?>
                            <option value="<?= e($offering['id']) ?>">
                                <?= e($offering['course_code']) ?> — <?= e($offering['course_title']) ?> (<?= e($offering['section_name']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <div class="form-grid">
                    <label class="field">
                        <span>Attendance date *</span>
                        <input type="date" name="attendance_date" data-picker-type="date" value="<?= e(date('Y-m-d')) ?>" required>
                    </label>
                    <label class="field">
                        <span>Status *</span>
                        <select name="status" data-form-status required>
                            <option value="Present">Present</option>
                            <option value="Late">Late</option>
                            <option value="Absent">Absent</option>
                            <option value="Excused">Excused</option>
                        </select>
                    </label>
                </div>

                <div class="form-grid">
                    <label class="field">
                        <span>Time in <small>(optional)</small></span>
                        <input type="time" name="time_in" data-picker-type="time" value="<?= e(date('H:i')) ?>">
                    </label>
                    <label class="field">
                        <span>Time out <small>(optional)</small></span>
                        <input type="time" name="time_out" data-picker-type="time">
                    </label>
                </div>

                <label class="field">
                    <span>Correction reason / Authorization note *</span>
                    <input type="text" name="reason" maxlength="255" placeholder="e.g. Medical excuse slip, RFID scanner retry, Dean approved exception" required>
                </label>

                <div class="ajax-form-footer">
                    <span class="ajax-form-status" data-correction-status aria-live="polite"></span>
                    <div class="table-actions">
                        <button type="submit" class="button button-primary">
                            Apply attendance correction
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</section>

<!-- Campus Class Offering Explorer -->
<section class="card section section-scroll-target" id="admin-attendance-explorer" data-section-target style="margin-top: 24px;">
    <div class="section-heading">
        <div>
            <h2>Campus class offering roster explorer</h2>
            <p class="muted">Search any subject across the campus to inspect its dated attendance sheets and student presence records.</p>
        </div>
    </div>

    <div data-admin-roster-explorer>
        <div class="grade-search-row">
            <label class="grade-search-box">
                <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5"/><path d="m13 13 4 4"/></svg>
                <span class="sr-only">Search campus classes</span>
                <input type="search" data-admin-class-search autocomplete="off" placeholder="Search course code, subject title, program, or section…">
            </label>
            <span class="grade-result-summary" data-admin-class-summary aria-live="polite"></span>
        </div>

        <div class="admin-class-results-grid" data-admin-class-results style="margin-top: 14px;"></div>
        <nav class="server-pagination grade-pagination" data-admin-class-pagination aria-label="Class offering pages"></nav>

        <!-- Dated Class Roster Container -->
        <div class="admin-class-roster-view" data-admin-roster-view hidden style="margin-top: 20px; border-top: 1px solid var(--line); padding-top: 16px;">
            <div class="attendance-roster-header" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;">
                <button type="button" class="button button-secondary button-small" data-admin-roster-back>
                    <svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="m12.5 5-5 5 5 5"/></svg>
                    Back to subject list
                </button>
                <div class="attendance-roster-subject">
                    <strong data-admin-roster-subject-name style="font-size: 16px;"></strong>
                    <span class="muted" data-admin-roster-subject-detail style="display:block; font-size:12px;"></span>
                </div>
                <label class="field attendance-date-field" style="margin:0;">
                    <span>Date</span>
                    <input type="date" data-picker-type="date" data-admin-roster-date value="<?= e(date('Y-m-d')) ?>">
                </label>
                <button type="button" class="button button-primary button-small" data-admin-roster-finalize>Finalize session</button>
            </div>

            <div class="attendance-summary" data-admin-roster-summary style="margin: 14px 0;"></div>

            <div class="table-wrap grade-roster-table">
                <table data-no-datatable>
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Status</th>
                            <th>Time in</th>
                            <th>Source</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody data-admin-roster-tbody></tbody>
                </table>
            </div>
            <nav class="server-pagination grade-pagination" data-admin-roster-pagination aria-label="Roster pages"></nav>
        </div>
    </div>
</section>

<?php if ($canManageAcademicCalendar): ?>
<?php $departmentDayMap = array_column($departmentDayPolicies, 'days_off', 'id'); ?>
<section class="card section department-days-card section-scroll-target" id="admin-attendance-weekly-schedules" data-section-target style="margin-top:24px;">
    <div class="section-heading"><div><h2>Department weekly schedules</h2><p class="muted">Configure regular days off. Classes and attendance are allowed on every other scheduled day, including Saturday.</p></div></div>
    <form method="post" class="stack" data-department-days-form data-policies="<?= e(json_encode($departmentDayMap, JSON_THROW_ON_ERROR)) ?>">
        <?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="update_department_days_off">
        <div class="form-grid"><label class="field"><span>Department code</span><select name="department_id" data-linked-detail="weekly-department-name" required><?php foreach ($departments as $department): ?><option value="<?= e($department['id']) ?>" data-detail="<?= e($department['name']) ?>"><?= e($department['code']) ?></option><?php endforeach; ?></select></label><label class="field"><span>Department name</span><input id="weekly-department-name" value="<?= e($departments[0]['name'] ?? '') ?>" readonly></label></div>
        <fieldset class="weekday-selector">
            <legend>Regular days off</legend>
            <?php foreach (\App\Services\AcademicCalendarService::DAYS as $day): ?>
                <label class="weekday-option"><input type="checkbox" name="days_off[]" value="<?= e($day) ?>"><span><?= e(substr($day, 0, 3)) ?></span><small><?= e($day) ?></small></label>
            <?php endforeach; ?>
        </fieldset>
        <div class="form-actions"><span class="muted">Existing classes must be moved before their weekday can be marked off.</span><button class="button button-primary">Save weekly schedule</button></div>
    </form>
</section>

<?php
$academicCalendar = [];
foreach ($schoolYears as $schoolYear) {
    $academicCalendar[(int) $schoolYear['id']] = [
        'startsOn' => $schoolYear['starts_on'],
        'endsOn' => $schoolYear['ends_on'],
        'semesters' => [],
    ];
}
foreach ($semesters as $semester) {
    $academicCalendar[(int) $semester['school_year_id']]['semesters'][$semester['name']] = [
        'startsOn' => $semester['starts_on'],
        'endsOn' => $semester['ends_on'],
        'status' => $semester['status'],
    ];
}
$selectedSchoolYear = $schoolYears[0] ?? null;
foreach ($schoolYears as $schoolYear) {
    if ($schoolYear['status'] === 'active') $selectedSchoolYear = $schoolYear;
}
?>
<section class="card section section-scroll-target" id="admin-attendance-school-year" data-section-target style="margin-top:24px;">
    <div class="section-heading"><div><h2>School year & academic terms</h2><p class="muted">Edit the school-year boundary and maintain First Semester, Second Semester, and Summer Class as separate terms.</p></div></div>
    <?php if ($selectedSchoolYear): ?>
    <form method="post" class="stack" data-academic-calendar-form data-calendar-records="<?= e(json_encode($academicCalendar, JSON_THROW_ON_ERROR)) ?>">
        <?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="update_academic_calendar">
        <label class="field"><span>School year</span><select name="school_year_id" data-no-search required><?php foreach ($schoolYears as $schoolYear): ?><option value="<?= e($schoolYear['id']) ?>" <?= (int) $schoolYear['id'] === (int) $selectedSchoolYear['id'] ? 'selected' : '' ?>><?= e($schoolYear['name']) ?></option><?php endforeach; ?></select></label>
        <div class="form-grid">
            <label class="field"><span>School year starts</span><input type="date" name="school_year_starts_on" value="<?= e($selectedSchoolYear['starts_on']) ?>" required></label>
            <label class="field"><span>School year ends</span><input type="date" name="school_year_ends_on" value="<?= e($selectedSchoolYear['ends_on']) ?>" required></label>
        </div>
        <div class="academic-term-grid">
        <?php foreach (['first' => 'First Semester', 'second' => 'Second Semester', 'summer' => 'Summer Class'] as $termKey => $termName): $term = $academicCalendar[(int) $selectedSchoolYear['id']]['semesters'][$termName] ?? []; ?>
            <fieldset class="academic-term-card" data-academic-term="<?= e($termName) ?>">
                <legend><?= e($termName) ?></legend>
                <label class="field"><span>Starts</span><input type="date" name="semester_starts_on[<?= e($termKey) ?>]" value="<?= e($term['startsOn'] ?? '') ?>" required></label>
                <label class="field"><span>Ends</span><input type="date" name="semester_ends_on[<?= e($termKey) ?>]" value="<?= e($term['endsOn'] ?? '') ?>" required></label>
                <label class="field"><span>Status</span><select name="semester_status[<?= e($termKey) ?>]" data-no-search><?php foreach (['upcoming' => 'Upcoming', 'active' => 'Active', 'closed' => 'Closed'] as $value => $label): ?><option value="<?= e($value) ?>" <?= ($term['status'] ?? 'upcoming') === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
            </fieldset>
        <?php endforeach; ?>
        </div>
        <div class="form-actions"><span class="muted">Term dates must be chronological and inside the school-year range.</span><button class="button button-primary">Save academic calendar</button></div>
    </form>
    <?php else: ?><p class="empty-state">Create a school year before configuring academic terms.</p><?php endif; ?>
</section>
<?php endif; ?>

</div>
</div>
</div>

<!-- Campus Attendance Policy Settings Dialog -->
<?php if ($canManageAttendancePolicies): ?><dialog id="admin-attendance-rules-dialog" class="app-dialog" data-modal>
    <div class="app-dialog-shell">
        <header class="app-dialog-header">
            <div>
                <p class="eyebrow">Campus attendance policy</p>
                <h2>Default attendance rules</h2>
                <p class="muted">Campus-wide thresholds for grace period, tardies, and absences.</p>
            </div>
            <button type="button" class="dialog-close" data-dialog-close aria-label="Close dialog"><svg viewBox="0 0 20 20"><path d="m5 5 10 10M15 5 5 15"/></svg></button>
        </header>
        <div class="app-dialog-body">
            <form class="stack attendance-policy-form" data-attendance-rules-form data-endpoint="<?= e(url('/attendance.php')) ?>">
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="action" value="update_attendance_settings">
                <div class="attendance-policy-controls">
                    <label class="policy-range-field">
                        <span><strong>Early check-in</strong><output data-policy-output="early_check_in_minutes"><?= e($settings['early_check_in_minutes'] ?? 10) ?> minutes</output></span>
                        <input type="range" name="early_check_in_minutes" min="0" max="60" value="<?= e($settings['early_check_in_minutes'] ?? 10) ?>" required>
                        <small>Once the previous class is finalized, the next class can scan inside this window.</small>
                    </label>
                    <label class="policy-range-field">
                        <span><strong>Present grace period</strong><output data-policy-output="grace_period_minutes"><?= e($settings['grace_period_minutes'] ?? 15) ?> minutes</output></span>
                        <input type="range" name="grace_period_minutes" min="0" max="120" value="<?= e($settings['grace_period_minutes'] ?? 15) ?>" required>
                        <small>Scans inside this window are marked Present.</small>
                    </label>
                    <label class="policy-range-field">
                        <span><strong>Mark absent at</strong><output data-policy-output="absent_after_minutes"><?= e($settings['absent_after_minutes'] ?? 30) ?> minutes</output></span>
                        <input type="range" name="absent_after_minutes" min="1" max="240" value="<?= e($settings['absent_after_minutes'] ?? 30) ?>" required>
                        <small>Scans after the grace period but before this point are Late.</small>
                    </label>
                    <label class="policy-range-field">
                        <span><strong>Tardies per absence</strong><output data-policy-output="tardies_per_absence"><?= e($settings['tardies_per_absence'] ?? 3) ?> tardies</output></span>
                        <input type="range" name="tardies_per_absence" min="1" max="20" value="<?= e($settings['tardies_per_absence'] ?? 3) ?>" required>
                        <small>Controls compliance conversion.</small>
                    </label>
                </div>
                <div class="policy-timeline"><span class="is-present">Present</span><span class="is-late">Late</span><span class="is-absent">Absent</span></div>
                <div class="ajax-form-footer">
                    <span class="ajax-form-status" data-attendance-feedback></span>
                    <button class="button button-primary">Update campus defaults</button>
                </div>
            </form>
        </div>
    </div>
</dialog><?php endif; ?>
<!-- Admin In-Page Student Profile Inspector Dialog -->
<dialog id="admin-student-profile-dialog" class="app-dialog app-dialog-large" data-modal aria-labelledby="admin-student-modal-title">
    <div class="app-dialog-shell">
        <header class="app-dialog-header">
            <div>
                <p class="eyebrow">Administrative student inspection</p>
                <h2 id="admin-student-modal-title" data-admin-modal-student-name>Student Profile</h2>
                <p class="muted">Live records, class enrollments, and compliance breakdown.</p>
            </div>
            <button type="button" class="dialog-close" data-dialog-close aria-label="Close dialog"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button>
        </header>
        <div class="app-dialog-body">
            <div class="directory-profile-hero" style="margin-bottom: 20px;">
                <img class="directory-profile-avatar" src="<?= e(url('/assets/images/default-avatar.svg')) ?>" alt="" data-admin-modal-avatar>
                <div class="directory-profile-identity">
                    <p class="eyebrow" data-admin-modal-school-id>—</p>
                    <h2 data-admin-modal-name style="margin:2px 0 6px;">—</h2>
                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-bottom:8px;">
                        <span class="badge badge-active" data-admin-modal-role>Student</span>
                        <span class="badge" data-admin-modal-rfid>RFID: —</span>
                        <span class="badge badge-active" data-admin-modal-status>Active</span>
                    </div>
                    <p class="muted" data-admin-modal-program-line style="margin:0;">—</p>
                </div>
                <dl class="directory-profile-facts">
                    <div><dt>Department</dt><dd data-admin-modal-dept>—</dd></div>
                    <div><dt>Program</dt><dd data-admin-modal-program>—</dd></div>
                    <div><dt>Section</dt><dd data-admin-modal-section>—</dd></div>
                    <div><dt>School Email</dt><dd><a href="#" data-admin-modal-email>—</a></dd></div>
                </dl>
            </div>

            <!-- Attendance & Deficiency Stats Strip -->
            <div style="display:grid; grid-template-columns:repeat(5, minmax(0, 1fr)); gap:10px; margin-bottom:20px;">
                <div class="card stat-card stat-card-compact" style="border-color:#b2ccff; background:#f5f8ff;">
                    <span>Present</span>
                    <strong style="color:var(--success);" data-admin-modal-stat-p>0</strong>
                </div>
                <div class="card stat-card stat-card-compact">
                    <span>Late</span>
                    <strong style="color:var(--warning);" data-admin-modal-stat-l>0</strong>
                </div>
                <div class="card stat-card stat-card-compact">
                    <span>Absent</span>
                    <strong style="color:var(--danger);" data-admin-modal-stat-a>0</strong>
                </div>
                <div class="card stat-card stat-card-compact">
                    <span>Excused</span>
                    <strong data-admin-modal-stat-e>0</strong>
                </div>
                <div class="card stat-card stat-card-compact" style="border-color:#fecdca; background:#fffafa;">
                    <span>Total Deficiencies</span>
                    <strong style="color:var(--danger);" data-admin-modal-stat-def>0</strong>
                </div>
            </div>

            <!-- Enrolled Class Offerings List -->
            <div style="margin-bottom: 20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <h3 style="margin:0; font-size:16px;">Enrolled class offerings</h3>
                    <span class="muted" style="font-size:12px;" data-admin-modal-classes-count></span>
                </div>
                <div class="student-subjects-list" data-admin-modal-classes-list></div>
            </div>

            <div class="ajax-form-footer" style="border-top:1px solid var(--line); padding-top:16px; margin-top:16px;">
                <div style="display:flex; gap:10px; margin-left:auto;">
                    <a href="#" class="button button-secondary" data-admin-modal-manage-link>
                        <svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                        Manage user in Admin
                    </a>
                    <button type="button" class="button button-primary" data-dialog-close>Close</button>
                </div>
            </div>
        </div>
    </div>
</dialog>
