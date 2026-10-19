<div class="section-navigation-page" data-section-navigation>
<header class="page-header"><div><p class="eyebrow">Campus directory</p><h1>People</h1><p>Find students, teachers, and staff, then open the profile and class connections relevant to you.</p></div></header>
<div class="section-navigation-layout">
<?php $sectionNavigationLabel = 'People page sections'; $sectionNavigation = [['id' => 'portal-people-directory', 'label' => 'People directory']]; require BASE_PATH . '/templates/partials/section_navigation.php'; ?>
<div class="section-navigation-content">
<section class="section-scroll-target" id="portal-people-directory" data-section-target data-people-directory data-endpoint="<?= e(url('/lookups/users.php')) ?>">
    <div class="section-heading"><div><h2>People directory</h2><p class="muted">Search begins after two characters and returns 20 people at a time.</p></div><span class="muted" data-people-summary aria-live="polite"></span></div>
    <div class="card people-directory-controls">
        <label class="field people-directory-search"><span>Search people</span><span class="people-search-shell"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5"/><path d="m13 13 4 4"/></svg><input type="search" data-people-search autocomplete="off" placeholder="Name, school ID, or email"></span></label>
        <label class="field"><span>Role</span><select data-people-role><option value="">All roles</option><option>Student</option><option>Teacher</option><option>Staff</option><option>Event Coordinator</option><option>Administrator</option></select></label>
    </div>
    <div class="people-directory-state" data-people-state>Start typing to find someone in the campus directory.</div>
    <div class="people-directory-grid" data-people-results></div>
    <nav class="server-pagination people-directory-pagination" data-people-pagination aria-label="People directory pages"></nav>
</section>
</div></div></div>
