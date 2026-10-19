<div class="section-navigation-page" data-section-navigation>
<header class="page-header"><div><p class="eyebrow">Weekly plan</p><h1>Schedule</h1><p><?= e($user['role_name'] === 'Student' ? 'Your enrolled class schedule.' : 'Your assigned teaching schedule.') ?></p></div></header>
<div class="section-navigation-layout">
<?php $sectionNavigationLabel = 'Schedule page sections'; $sectionNavigation = [['id' => 'portal-weekly-schedule', 'label' => 'Weekly schedule']]; require BASE_PATH . '/templates/partials/section_navigation.php'; ?>
<div class="section-navigation-content"><section class="section-scroll-target" id="portal-weekly-schedule" data-section-target>
<?php
$scheduleRows = $schedules;
$scheduleViewId = 'schedule-page';
$scheduleHeadingMode = 'section';
$scheduleTitle = 'Schedule';
$scheduleDescription = $user['role_name'] === 'Student' ? 'Your enrolled class schedule.' : 'Your assigned teaching schedule.';
require BASE_PATH . '/templates/partials/portal_schedule_views.php';
?>
</section></div></div></div>
