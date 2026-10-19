<?php

use App\Core\Auth;
use App\Core\Csrf;

$currentUser = Auth::user();
$active = $active ?? 'dashboard';
$isAdminArea = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/admin');
$viewAsRoles = Auth::viewableRoles();
$previewRole = Auth::previewRole();
$preferences = \App\Services\UserPreferenceService::get((int) $currentUser['id']);

$adminNavigation = [
    ['key' => 'dashboard', 'label' => 'Dashboard'],
    ['key' => 'users', 'label' => 'Users & RFID', 'permission' => 'manage_users'],
    ['key' => 'academics', 'label' => 'Academics', 'permission' => 'manage_academic_structure'],
    ['key' => 'rooms', 'label' => 'Rooms & Devices', 'permission' => 'manage_rooms'],
    ['key' => 'attendance', 'label' => 'Attendance Policies', 'permission' => 'manage_attendance'],
    ['key' => 'events', 'label' => 'Events', 'permission' => 'manage_events'],
    ['key' => 'recollections', 'label' => 'Recollections', 'permission' => 'manage_recollections'],
    ['key' => 'compliance', 'label' => 'Compliance', 'permission' => 'view_compliance'],
    ['key' => 'grades', 'label' => 'Grades', 'permission' => 'manage_grades'],
    ['key' => 'messages', 'label' => 'Messages', 'permission' => 'send_messages'],
    ['key' => 'reports', 'label' => 'Reports', 'permissions' => ['manage_reports', 'view_audit_logs']],
    ['key' => 'settings', 'label' => 'Settings'],
];

$portalNavigation = [
    ['key' => 'dashboard', 'label' => 'Dashboard'],
    ['key' => 'attendance', 'label' => 'Attendance', 'permission' => 'view_attendance'],
    ['key' => 'grades', 'label' => 'Grades', 'permission' => 'view_grades'],
    ['key' => 'schedule', 'label' => 'Schedule'],
    ['key' => 'seat-plans', 'label' => 'Seat Plans', 'permission' => 'manage_seat_plans'],
    ['key' => 'events', 'label' => 'Events'],
    ['key' => 'recollections', 'label' => 'Recollections'],
    ['key' => 'compliance', 'label' => 'Compliance', 'permission' => 'view_compliance'],
    ['key' => 'messages', 'label' => 'Messages'],
    ['key' => 'people', 'label' => 'People'],
    ['key' => 'profile', 'label' => 'Profile'],
    ['key' => 'settings', 'label' => 'Settings'],
];
$navigation = $isAdminArea ? $adminNavigation : $portalNavigation;
?>
<!doctype html>
<html lang="en" data-theme="<?= e($preferences['theme_mode']) ?>" data-accent="<?= e($preferences['accent_theme']) ?>" data-motion="<?= $preferences['reduced_motion']?'reduced':'full' ?>" data-density="<?= $preferences['compact_mode']?'compact':'comfortable' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · <?= e(config('app')['name']) ?></title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js@11.1.0/public/assets/styles/choices.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/simple-datatables@10/dist/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
    <?php if (in_array($active, ['dashboard', 'attendance'], true)): ?><link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/williamtroup/Calendar.js@2.12.6/dist/calendar.js.min.css"><?php endif; ?>
    <link rel="stylesheet" href="<?= e(url('/assets/css/app.css?v=' . (string) filemtime(PUBLIC_PATH . '/assets/css/app.css'))) ?>">
<link rel="stylesheet" href="<?= e(url('/assets/css/notifications.css')) ?>">
</head>
<body class="app-shell" data-admin-area="<?= $isAdminArea ? '1' : '0' ?>">
    <aside class="sidebar" id="sidebar">
        <a class="sidebar-brand" href="<?= e(url($isAdminArea ? '/admin/' : '/index/')) ?>">
            <span class="brand-mark">RF</span>
            <span><strong>RFID Attendance</strong><small><?= $isAdminArea ? 'Administration' : 'Campus portal' ?></small></span>
        </a>

        <nav class="sidebar-nav" aria-label="Primary navigation">
            <?php foreach ($navigation as $item): ?>
                <?php $itemPermissions = $item['permissions'] ?? (isset($item['permission']) ? [$item['permission']] : []); ?>
                <?php if ($itemPermissions === [] || Auth::canAny($itemPermissions)): ?>
                    <a class="nav-link <?= $active === $item['key'] ? 'is-active' : '' ?>"
                       href="<?= e(url(($isAdminArea ? '/admin/' : '/index/') . ($item['key'] === 'dashboard' ? '' : '?page=' . $item['key']))) ?>">
                        <?= e($item['label']) ?>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <?php if ($viewAsRoles): ?>
            <button type="button" class="area-switch view-as-trigger" data-dialog-open="#view-as-dialog" aria-haspopup="dialog">
                <span>View As: <?= e($previewRole['name'] ?? ($isAdminArea ? 'Admin' : 'User')) ?></span>
                <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg>
            </button>
        <?php endif; ?>

        <form action="<?= e(url('/auth/logout.php')) ?>" method="post" class="sidebar-logout">
            <?= Csrf::field() ?>
            <button type="submit" class="button button-ghost button-block">Sign out</button>
        </form>
    </aside>

    <div class="app-main">
        <header class="topbar">
            <button type="button" class="menu-button" data-sidebar-toggle aria-label="Open navigation">Menu</button>
            <?php if (!empty($currentUser['profile_image_path'])): ?><img class="user-avatar topbar-avatar" src="<?= e(url($currentUser['profile_image_path'])) ?>" alt=""><?php endif; ?>
            <div>
                <p class="eyebrow"><?= e($currentUser['role_names_label'] ?? $currentUser['role_name']) ?></p>
                <strong><?= e($currentUser['first_name'] . ' ' . $currentUser['last_name']) ?></strong>
            </div>
            <?php require BASE_PATH . '/templates/partials/notification_inbox.php'; ?>
        </header>

        <main class="page-content" data-page-content>
            <div class="page-loading-skeleton" data-page-loading-skeleton aria-hidden="true">
                <div class="page-skeleton-header">
                    <div class="page-skeleton-heading">
                        <span class="page-skeleton-block page-skeleton-kicker"></span>
                        <span class="page-skeleton-block page-skeleton-title"></span>
                        <span class="page-skeleton-block page-skeleton-subtitle"></span>
                    </div>
                    <span class="page-skeleton-block page-skeleton-action"></span>
                </div>

                <div class="page-skeleton-stats">
                    <?php for ($index = 0; $index < 4; $index++): ?>
                        <div class="page-skeleton-stat">
                            <span class="page-skeleton-block page-skeleton-label"></span>
                            <span class="page-skeleton-block page-skeleton-value"></span>
                        </div>
                    <?php endfor; ?>
                </div>

                <div class="page-skeleton-grid">
                    <div class="page-skeleton-panel page-skeleton-panel-main">
                        <div class="page-skeleton-toolbar">
                            <span class="page-skeleton-block page-skeleton-section-title"></span>
                            <span class="page-skeleton-block page-skeleton-control"></span>
                        </div>
                        <div class="page-skeleton-rows">
                            <?php for ($index = 0; $index < 6; $index++): ?>
                                <div class="page-skeleton-row">
                                    <span class="page-skeleton-block page-skeleton-avatar"></span>
                                    <span class="page-skeleton-block page-skeleton-row-primary"></span>
                                    <span class="page-skeleton-block page-skeleton-row-secondary"></span>
                                    <span class="page-skeleton-block page-skeleton-row-action"></span>
                                </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <div class="page-skeleton-panel page-skeleton-panel-side">
                        <span class="page-skeleton-block page-skeleton-section-title"></span>
                        <span class="page-skeleton-block page-skeleton-chart"></span>
                        <span class="page-skeleton-block page-skeleton-side-line"></span>
                        <span class="page-skeleton-block page-skeleton-side-line page-skeleton-side-line-short"></span>
                    </div>
                </div>
            </div>

            <div class="page-content-inner" data-page-content-inner>
                <?php foreach (pull_flashes() as $message): ?>
                    <div class="alert alert-<?= e($message['type']) ?>" role="status"><?= e($message['message']) ?></div>
                <?php endforeach; ?>
                <?= $content ?>
            </div>
        </main>
    </div>
    <?php if ($viewAsRoles): ?>
    <dialog id="view-as-dialog" class="app-dialog view-as-dialog" data-modal aria-labelledby="view-as-title">
        <div class="app-dialog-shell">
            <header class="app-dialog-header"><div><p class="eyebrow">Role preview</p><h2 id="view-as-title">View the portal as</h2><p class="muted">Preview navigation and screens for a role while staying signed in as administrator.</p></div><button type="button" class="dialog-close" data-dialog-close aria-label="Close role selection"><svg viewBox="0 0 20 20"><path d="m5 5 10 10M15 5 5 15"/></svg></button></header>
            <div class="app-dialog-body"><div class="view-as-role-list">
                <?php foreach ($viewAsRoles as $role): ?>
                <?php $roleIsActive = $previewRole ? (int) $previewRole['id'] === (int) $role['id'] : (int) $role['id'] === 1; ?>
                <form action="<?= e(url('/auth/view-as.php')) ?>" method="post" data-no-page-loader>
                    <?= Csrf::field() ?><input type="hidden" name="role_id" value="<?= e($role['id']) ?>">
                    <button type="submit" class="view-as-role <?= $roleIsActive ? 'is-active' : '' ?>">
                        <span class="view-as-role-icon"><?= e(mb_strtoupper(mb_substr($role['name'], 0, 1))) ?></span>
                        <span><strong><?= e((int) $role['id'] === 1 ? 'Admin' : $role['name']) ?></strong><small><?= e($role['description'] ?: 'Preview this role’s portal') ?></small></span>
                        <?php if ($roleIsActive): ?><svg class="view-as-check" viewBox="0 0 20 20" aria-hidden="true"><path d="m5 10 3 3 7-7"/></svg><?php endif; ?>
                    </button>
                </form>
                <?php endforeach; ?>
            </div></div>
        </div>
    </dialog>
    <?php endif; ?>
    <div class="toast-region" data-toast-region aria-live="polite" aria-atomic="false"></div>
    <script src="https://cdn.jsdelivr.net/npm/choices.js@11.1.0/public/assets/scripts/choices.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/simple-datatables@10" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js" defer></script>
    <?php if (in_array($active, ['dashboard', 'attendance'], true)): ?><script src="https://cdn.jsdelivr.net/gh/williamtroup/Calendar.js@2.12.6/dist/calendar.min.js" defer></script><?php endif; ?>
    <script src="<?= e(url('/assets/js/app.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/app.js'))) ?>" defer></script>
    <script src="<?= e(url('/assets/js/attendance.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/attendance.js'))) ?>" defer></script>
    <script src="<?= e(url('/assets/js/attendance-policy.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/attendance-policy.js'))) ?>" defer></script>
    <script src="<?= e(url('/assets/js/live-seat-plan.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/live-seat-plan.js'))) ?>" defer></script>
    <script src="<?= e(url('/assets/js/timetable.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/timetable.js'))) ?>" defer></script>
    <script src="<?= e(url('/assets/js/seat-plans.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/seat-plans.js'))) ?>" defer></script>
    <?php if ($active === 'class-roster'): ?><script src="<?= e(url('/assets/js/class-roster.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/class-roster.js'))) ?>" defer></script><?php endif; ?>
    <?php if (!$isAdminArea && $active === 'people'): ?><script src="<?= e(url('/assets/js/people.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/people.js'))) ?>" defer></script><?php endif; ?>
    <?php if ($isAdminArea && $active === 'rooms'): ?><script src="<?= e(url('/assets/js/rooms.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/rooms.js'))) ?>" defer></script><?php endif; ?>
    <?php if ($isAdminArea && $active === 'academics'): ?><script src="<?= e(url('/assets/js/academic-management.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/academic-management.js'))) ?>" defer></script><?php endif; ?>
    <?php if ($isAdminArea && $active === 'recollections'): ?><script src="<?= e(url('/assets/js/recollection-management.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/recollection-management.js'))) ?>" defer></script><?php endif; ?>
    <?php if ($isAdminArea && $active === 'compliance'): ?><script src="<?= e(url('/assets/js/compliance.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/compliance.js'))) ?>" defer></script><?php endif; ?>
    <script src="<?= e(url('/assets/js/admin-control.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/admin-control.js'))) ?>" defer></script>
<script src="<?= e(url('/assets/js/notifications.js')) ?>" defer></script>
<?php if ($active === 'settings'): ?><script src="<?= e(url('/assets/js/settings.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/settings.js'))) ?>" defer></script><?php endif; ?>
</body>
</html>
