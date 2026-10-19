<?php $offeringId = (int) $rosterSubject['id']; ?>
<div class="section-navigation-page" data-section-navigation>
    <header class="page-header">
        <div>
            <p class="eyebrow">Class Directory & Enrollment</p>
            <h1><?= e($rosterSubject['course_code']) ?> — <?= e($rosterSubject['course_title']) ?></h1>
            <p><?= e($rosterSubject['program_code']) ?> · <?= e($rosterSubject['section_name']) ?> · <?= e($rosterSubject['semester_name']) ?> (<?= e($rosterSubject['school_year_name']) ?>)</p>
        </div>
        <div class="page-header-actions">
            <a class="button button-secondary" href="<?= e(url('/admin/?page=academics#academics-class-offerings')) ?>">&larr; Back to Offerings</a>
            <a class="button button-secondary" href="<?= e(url('/admin/?page=grades&offering_id=' . $offeringId)) ?>">
                <svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="M4 3h12v14H4zM4 7h12M8 3v14"/></svg>
                Grades Console
            </a>
            <a class="button button-secondary" href="<?= e(url('/admin/?page=attendance&offering_id=' . $offeringId)) ?>">
                <svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7"/><path d="M10 6v4l2.5 2.5"/></svg>
                Attendance Explorer
            </a>
        </div>
    </header>
    <div class="section-navigation-layout">
        <?php $sectionNavigationLabel = 'Class roster sections'; $sectionNavigation = [['id' => 'class-roster-students', 'label' => 'Enrolled Students']]; require BASE_PATH . '/templates/admin/partials/section_navigation.php'; ?>
        <div class="section-navigation-content">
            <section class="card section-scroll-target class-roster-shell" id="class-roster-students" data-section-target data-class-roster data-endpoint="<?= e(url('/class-roster.php')) ?>" data-offering-id="<?= e($offeringId) ?>">
                <div class="grade-manager-heading">
                    <div>
                        <p class="eyebrow"><?= e($rosterSubject['department_code'] ?: 'Campus') ?> · <?= e($rosterSubject['program_code']) ?> · <?= e($rosterSubject['section_name']) ?></p>
                        <h2>Enrolled students (<?= e($rosterSubject['course_code']) ?>)</h2>
                        <p class="muted"><?= e($rosterSubject['teacher_names'] ?: 'No teacher assigned') ?><?php if ($rosterSubject['room_code']): ?> · Room <?= e($rosterSubject['room_code']) ?><?php endif; ?></p>
                    </div>
                    <div class="view-switch" role="group" aria-label="Roster view">
                        <button type="button" class="is-active" data-roster-view="cards" aria-pressed="true" title="Card view"><svg viewBox="0 0 20 20"><rect x="3" y="3" width="5.5" height="5.5" rx="1"/><rect x="11.5" y="3" width="5.5" height="5.5" rx="1"/><rect x="3" y="11.5" width="5.5" height="5.5" rx="1"/><rect x="11.5" y="11.5" width="5.5" height="5.5" rx="1"/></svg><span class="sr-only">Card view</span></button>
                        <button type="button" data-roster-view="list" aria-pressed="false" title="List view"><svg viewBox="0 0 20 20"><path d="M7 5h10M7 10h10M7 15h10"/><circle cx="3.5" cy="5" r=".7"/><circle cx="3.5" cy="10" r=".7"/><circle cx="3.5" cy="15" r=".7"/></svg><span class="sr-only">List view</span></button>
                    </div>
                </div>
                <div class="grade-search-row">
                    <label class="grade-search-box">
                        <svg viewBox="0 0 20 20"><circle cx="8.5" cy="8.5" r="5.5"/><path d="m13 13 4 4"/></svg>
                        <span class="sr-only">Search students</span>
                        <input type="search" data-roster-search autocomplete="off" placeholder="Search school ID or student name">
                    </label>
                    <span class="grade-result-summary" data-roster-summary aria-live="polite"></span>
                </div>
                <div class="class-roster-results" data-roster-results></div>
                <div class="grade-loading" data-roster-loading>Loading class roster…</div>
                <nav class="server-pagination grade-pagination" data-roster-pagination aria-label="Class roster pages"></nav>
            </section>
        </div>
    </div>
</div>
