<div class="section-navigation-page" data-section-navigation>
<header class="page-header">
    <div><p class="eyebrow">Administration</p><h1>Operations overview</h1><p>Campus calendar and real-time operational summary.</p></div>
</header>

<div class="section-navigation-layout">
<?php $sectionNavigationLabel = 'Dashboard page sections'; $sectionNavigation = [
    ['id' => 'dashboard-campus-calendar', 'label' => 'Campus calendar & snapshot'],
    ['id' => 'dashboard-events-feed', 'label' => 'Events & Announcements'],
];
$sectionNavigation = array_values(array_filter($sectionNavigation, static fn(array $section): bool => $section['id'] === 'dashboard-campus-calendar' ? \App\Core\PermissionRegistry::canViewCampusCalendar() : \App\Core\Auth::canAny(['view_events','manage_events'])));
require BASE_PATH . '/templates/admin/partials/section_navigation.php'; ?>
<div class="section-navigation-content">

<?php require BASE_PATH . '/templates/partials/dashboard_calendar.php'; ?>

<?php require BASE_PATH . '/templates/partials/events_social_feed.php'; ?>

</div>
</div>
</div>
