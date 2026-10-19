<?php

use App\Core\Auth;
use App\Core\Csrf;

$currentUser = Auth::user();
$active = $active ?? 'dashboard';
$isAdminArea = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/admin');
$viewAsRoles = Auth::viewableRoles();
$previewRole = Auth::previewRole();
$preferences = \App\Services\UserPreferenceService::get((int) $currentUser['id']);

$visibleNavigation = \App\Services\SearchService::visibleNavigation($isAdminArea);

$sidebarGroups = $isAdminArea ? [
    ['key' => 'academics', 'label' => 'Academics', 'icon' => 'academics', 'items' => ['schedule', 'academics', 'attendance', 'grades']],
    ['key' => 'campus', 'label' => 'Campus', 'icon' => 'rooms', 'items' => ['rooms', 'events', 'recollections']],
    ['key' => 'administration', 'label' => 'Administration', 'icon' => 'users', 'items' => ['users', 'compliance', 'audit-trail', 'analytics']],
] : [
    ['key' => 'learning', 'label' => Auth::hasAnyRole(['Teacher','Student']) ? 'Academics' : 'Workspace', 'icon' => 'academics', 'items' => ['schedule', 'attendance', 'grades', 'analytics', 'seat-plans']],
    ['key' => 'campus', 'label' => 'Campus', 'icon' => 'events', 'items' => ['events', 'recollections', 'people']],
    ['key' => 'account', 'label' => 'My space', 'icon' => 'profile', 'items' => ['messages', 'compliance', 'profile', 'settings']],
];

$sidebarIconPaths = [
    'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1.4"/><rect x="14" y="3" width="7" height="7" rx="1.4"/><rect x="3" y="14" width="7" height="7" rx="1.4"/><rect x="14" y="14" width="7" height="7" rx="1.4"/>',
    'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 20v-2a6 6 0 0 1 12 0v2M17 5a3 3 0 0 1 0 6m1 4a5 5 0 0 1 3 5"/>',
    'academics' => '<path d="m2 8 10-5 10 5-10 5L2 8Zm4 3v6c3.5 3 8.5 3 12 0v-6M22 8v7"/>',
    'rooms' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M9 21v-5h6v5M8 7h2m4 0h2M8 11h2m4 0h2"/>',
    'attendance' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18m-13 5 2 2 5-5"/>',
    'events' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18m9 4 1 2 2 .3-1.5 1.4.4 2-1.9-.9-1.9.9.4-2L9 16.3l2-.3 1-2Z"/>',
    'recollections' => '<path d="M12 21s-8-4.7-8-11a4.5 4.5 0 0 1 8-2.7A4.5 4.5 0 0 1 20 10c0 6.3-8 11-8 11Z"/><path d="M12 9v7m-3.5-3.5h7"/>',
    'compliance' => '<path d="m12 2 8 3v6c0 5-3.3 8.4-8 11-4.7-2.6-8-6-8-11V5l8-3Z"/><path d="m8.5 12 2.2 2.2 4.8-4.8"/>',
    'grades' => '<path d="M4 3h12l4 4v14H4V3Zm12 0v5h4M8 13h8m-8 4h5"/>',
    'messages' => '<path d="M4 4h16v13H8l-4 4V4Z"/><path d="M8 9h8m-8 4h5"/>',
    'analytics' => '<path d="M4 20h16M6 17v-5m4 5V7m4 10v-3m4 3V4"/>',
    'audit-trail' => '<path d="M14 3H5v18h14V8l-5-5Zm0 0v5h5M8 12h4m-4 4h4"/><path d="m15 14 2 2 3-3"/>',
    'settings' => '<circle cx="12" cy="12" r="3"/><path d="M10 2h4l.7 2.3 2 .9 2.1-1.1 2.8 2.8-1.1 2.1.9 2L23 12l-1.6 1-.9 2 1.1 2.1-2.8 2.8-2.1-1.1-2 .9L14 22h-4l-.7-2.3-2-.9-2.1 1.1-2.8-2.8 1.1-2.1-.9-2L1 12l1.6-1 .9-2-1.1-2.1 2.8-2.8 2.1 1.1 2-.9L10 2Z"/>',
    'schedule' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18m-11 4h4m-4 3h6"/>',
    'seat-plans' => '<rect x="3" y="3" width="18" height="18" rx="2"/><rect x="6" y="6" width="4" height="4" rx=".5"/><rect x="14" y="6" width="4" height="4" rx=".5"/><rect x="6" y="14" width="4" height="4" rx=".5"/><rect x="14" y="14" width="4" height="4" rx=".5"/>',
    'people' => '<circle cx="12" cy="8" r="3"/><path d="M5 21v-2a7 7 0 0 1 14 0v2M3 10a2 2 0 0 1 2-2m14 0a2 2 0 0 1 2 2"/>',
    'profile' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
];
$sidebarIcon = static fn (string $key): string => '<svg class="sidebar-icon" viewBox="0 0 24 24" aria-hidden="true">' . ($sidebarIconPaths[$key] ?? $sidebarIconPaths['dashboard']) . '</svg>';
$sidebarStorageKey = 'campustrack.sidebar.' . (int) $currentUser['id'] . '.' . ($isAdminArea ? 'admin' : 'portal') . '.' . (int) ($currentUser['role_id'] ?? 0);
?>
<!doctype html>
<html lang="en" data-theme="<?= e($preferences['theme_mode']) ?>" data-accent="<?= e($preferences['accent_theme']) ?>" data-motion="<?= $preferences['reduced_motion']?'reduced':'full' ?>" data-density="<?= $preferences['compact_mode']?'compact':'comfortable' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> · <?= e(config('app')['name']) ?></title>
    <link rel="icon" href="<?= e(url('/assets/logos/favicons/favicon.ico')) ?>" sizes="any">
    <link rel="apple-touch-icon" href="<?= e(url('/assets/logos/favicons/apple-touch-icon.png')) ?>">
    <link rel="manifest" href="<?= e(url('/assets/logos/web/site.webmanifest')) ?>">
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/choices.js@11.1.0/public/assets/styles/choices.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/simple-datatables@10/dist/style.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">
    <?php if (in_array($active, ['dashboard', 'attendance'], true)): ?><link rel="stylesheet" href="https://cdn.jsdelivr.net/gh/williamtroup/Calendar.js@2.12.6/dist/calendar.js.min.css"><?php endif; ?>
    <link rel="stylesheet" href="<?= e(url('/assets/css/app.css?v=' . (string) filemtime(PUBLIC_PATH . '/assets/css/app.css'))) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/campus-policy.css?v=' . (string) filemtime(PUBLIC_PATH . '/assets/css/campus-policy.css'))) ?>">
    <link rel="stylesheet" href="<?= e(url('/assets/css/bulk-delete.css?v=' . (string) filemtime(PUBLIC_PATH . '/assets/css/bulk-delete.css'))) ?>">
    <?php if ($isAdminArea && in_array($active, ['compliance', 'analytics'], true)): ?><link rel="stylesheet" href="<?= e(url('/assets/css/compliance.css?v=' . (string) filemtime(PUBLIC_PATH . '/assets/css/compliance.css'))) ?>"><?php endif; ?>
<link rel="stylesheet" href="<?= e(url('/assets/css/notifications.css')) ?>">
    <?php if ($isAdminArea && $active === 'audit-trail'): ?><link rel="stylesheet" href="<?= e(url('/assets/css/audit-trail.css?v=' . (string) filemtime(PUBLIC_PATH . '/assets/css/audit-trail.css'))) ?>"><?php endif; ?>
    <?php if ($active === 'messages'): ?><link rel="stylesheet" href="<?= e(url('/assets/vendor/quill/quill.snow.css')) ?>"><?php endif; ?>
    <?php if ($active === 'messages'): ?><link rel="stylesheet" href="<?= e(url('/assets/css/messages.css?v=' . (string) filemtime(PUBLIC_PATH . '/assets/css/messages.css'))) ?>"><?php endif; ?>
    <?php if ($active === 'analytics'): ?><link rel="stylesheet" href="<?= e(url('/assets/css/analytics.css?v=' . (string) filemtime(PUBLIC_PATH . '/assets/css/analytics.css'))) ?>"><?php endif; ?>
    <script>try { const width = Number(localStorage.getItem(<?= json_encode($sidebarStorageKey, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)); if (width >= 72 && width <= 360) { document.documentElement.style.setProperty('--sidebar-width', width + 'px'); if (width <= 112) document.documentElement.dataset.sidebarCompact = 'true'; } } catch (_) {}</script>
</head>
<body data-can-view-people="<?= Auth::canAny(['view_people','manage_users']) ? '1' : '0' ?>" data-can-send-messages="<?= Auth::can('send_messages') ? '1' : '0' ?>" data-can-view-compliance="<?= Auth::canAny(Auth::STUDENT_COMPLIANCE_PERMISSIONS) ? '1' : '0' ?>" data-can-manage-users="<?= Auth::can('manage_users') ? '1' : '0' ?>" data-bulk-endpoint="<?= e(url('/api/bulk-delete.php')) ?>" data-bulk-token="<?= e(Csrf::token()) ?>" class="app-shell" data-admin-area="<?= $isAdminArea ? '1' : '0' ?>" data-sidebar-storage-key="<?= e($sidebarStorageKey) ?>" data-current-page="<?= e($active) ?>">
    <aside class="sidebar" id="sidebar">
        <a class="sidebar-brand" href="<?= e(url($isAdminArea ? '/admin/' : '/index/')) ?>" aria-label="CampusTrack home" title="CampusTrack home">
            <img class="sidebar-brand-icon" src="<?= e(url('/assets/logos/logos/png/campustrack-icon-dark.png')) ?>" alt="">
            <span><strong>CampusTrack</strong><small><?= $isAdminArea ? 'Administration' : 'Campus portal' ?></small></span>
        </a>

        <nav class="sidebar-nav" aria-label="Primary navigation">
            <a class="nav-link sidebar-direct-link <?= $active === 'dashboard' ? 'is-active' : '' ?>" href="<?= e(url($isAdminArea ? '/admin/' : '/index/')) ?>" title="Dashboard" aria-label="Dashboard" <?= $active === 'dashboard' ? 'aria-current="page"' : '' ?>>
                <?= $sidebarIcon('dashboard') ?><span class="nav-link-label">Dashboard</span>
            </a>
            <?php foreach ($sidebarGroups as $group): ?>
                <?php
                    $groupItems = array_values(array_filter(array_map(static fn (string $key) => $visibleNavigation[$key] ?? null, $group['items'])));
                    if ($groupItems === []) continue;
                    $groupActive = in_array($active, array_column($groupItems, 'key'), true);
                    // A detailed class roster belongs to the attendance workspace.
                    if ($active === 'class-roster' && $group['key'] === ($isAdminArea ? 'academics' : 'learning')) $groupActive = true;
                ?>
                <?php if (count($groupItems) === 1): ?>
                    <?php $item = $groupItems[0]; $itemActive = $active === $item['key'] || ($active === 'class-roster' && $item['key'] === 'attendance'); ?>
                    <a class="nav-link sidebar-direct-link <?= $itemActive ? 'is-active' : '' ?>" href="<?= e(url(($isAdminArea ? '/admin/' : '/index/') . '?page=' . $item['key'])) ?>" title="<?= e($item['label']) ?>" aria-label="<?= e($item['label']) ?>" <?= $itemActive ? 'aria-current="page"' : '' ?>>
                        <?= $sidebarIcon($item['key']) ?><span class="nav-link-label"><?= e($item['label']) ?></span>
                    </a>
                    <?php continue; ?>
                <?php endif; ?>
                <details class="sidebar-group <?= $groupActive ? 'has-active-page' : '' ?>" data-sidebar-group="<?= e($group['key']) ?>" <?= $groupActive ? 'open' : '' ?>>
                    <summary class="sidebar-group-summary" title="<?= e($group['label']) ?>" aria-label="Toggle <?= e($group['label']) ?> section">
                        <?= $sidebarIcon($group['icon']) ?><span class="sidebar-group-label"><?= e($group['label']) ?></span>
                        <svg class="sidebar-group-chevron" viewBox="0 0 20 20" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg>
                    </summary>
                    <div class="sidebar-group-links">
                        <?php foreach ($groupItems as $item): ?>
                            <?php $itemActive = $active === $item['key'] || ($active === 'class-roster' && $item['key'] === 'attendance'); ?>
                            <a class="nav-link <?= $itemActive ? 'is-active' : '' ?>"
                               href="<?= e(url(($isAdminArea ? '/admin/' : '/index/') . ($item['key'] === 'dashboard' ? '' : '?page=' . $item['key']))) ?>"
                               title="<?= e($item['label']) ?>" aria-label="<?= e($item['label']) ?>" <?= $itemActive ? 'aria-current="page"' : '' ?>>
                                <?= $sidebarIcon($item['key']) ?><span class="nav-link-label"><?= e($item['label']) ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
            <?php if ($isAdminArea): ?>
                <div class="sidebar-direct-footer">
                    <?php foreach (['messages', 'settings'] as $directKey): ?>
                        <?php if (!isset($visibleNavigation[$directKey])) continue; $item = $visibleNavigation[$directKey]; ?>
                        <a class="nav-link sidebar-direct-link <?= $active === $directKey ? 'is-active' : '' ?>" href="<?= e(url('/admin/?page=' . $directKey)) ?>" title="<?= e($item['label']) ?>" aria-label="<?= e($item['label']) ?>" <?= $active === $directKey ? 'aria-current="page"' : '' ?>>
                            <?= $sidebarIcon($directKey) ?><span class="nav-link-label"><?= e($item['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </nav>

        <?php if ($viewAsRoles): ?>
            <button type="button" class="area-switch view-as-trigger" data-dialog-open="#view-as-dialog" aria-haspopup="dialog" aria-label="View as another role" title="View as another role">
                <?= $sidebarIcon('users') ?><span class="sidebar-action-label">View As: <?= e($previewRole['name'] ?? ($isAdminArea ? 'Admin' : 'User')) ?></span>
                <svg class="sidebar-action-chevron" viewBox="0 0 20 20" aria-hidden="true"><path d="m6 8 4 4 4-4"/></svg>
            </button>
        <?php endif; ?>

        <form action="<?= e(url('/auth/logout.php')) ?>" method="post" class="sidebar-logout">
            <?= Csrf::field() ?>
            <button type="submit" class="button button-ghost button-block sidebar-signout-button" aria-label="Sign out" title="Sign out"><svg class="sidebar-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 4H5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4m6-4 4-4-4-4m4 4H9"/></svg><span class="sidebar-action-label">Sign out</span></button>
        </form>
        <div class="sidebar-resize-handle" data-sidebar-resize role="separator" tabindex="0" aria-orientation="vertical" aria-label="Resize navigation" aria-valuemin="72" aria-valuemax="360" aria-valuenow="250" title="Drag to resize navigation; drag left to show icons only"></div>
        <button type="button" class="sidebar-collapse-button" data-sidebar-collapse aria-label="Collapse navigation" title="Collapse navigation"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m12 4-6 6 6 6"/></svg></button>
    </aside>

    <div class="app-main">
        <header class="topbar">
            <button type="button" class="menu-button" data-sidebar-toggle aria-label="Open navigation">Menu</button>
            <?php if (Auth::can('use_search')): ?><div class="global-search" data-global-search data-endpoint="<?= e(url('/api/search.php')) ?>">
                <script type="application/json" data-search-pages><?= json_encode(\App\Services\SearchService::pageIndex($isAdminArea), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
                <div class="global-search-field">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 5 5"/></svg>
                    <input type="search" data-search-input role="combobox" aria-label="Search CampusTrack" aria-autocomplete="list" aria-expanded="false" aria-controls="global-search-results" autocomplete="off" placeholder="Search pages, people, classes, events…">
                    <button type="button" class="global-search-clear" data-search-clear aria-label="Clear search" hidden>×</button>
                    <kbd class="global-search-shortcut">Ctrl K</kbd>
                </div>
                <div class="global-search-panel" data-search-panel hidden>
                    <div class="global-search-categories" data-search-categories role="group" aria-label="Search categories"></div>
                    <div id="global-search-results" class="global-search-results" data-search-results role="listbox" aria-label="Search results"></div>
                    <p class="global-search-status" data-search-status role="status" aria-live="polite"></p>
                </div>
            </div>
            <?php endif; ?><div class="topbar-actions">
                <?php if (Auth::can('manage_own_notifications')) require BASE_PATH . '/templates/partials/notification_inbox.php'; ?>
                <div class="account-menu" data-account-menu>
                    <button type="button" class="account-menu-trigger" data-account-trigger aria-label="Open account menu" aria-haspopup="menu" aria-expanded="false" title="Account">
                        <img class="user-avatar topbar-avatar" src="<?= e(url($currentUser['profile_image_path'] ?: '/assets/images/default-avatar.svg')) ?>" alt="">
                    </button>
                    <div class="account-menu-panel" data-account-panel role="menu" hidden>
                        <div class="account-menu-identity"><strong><?= e(trim($currentUser['first_name'] . ' ' . $currentUser['last_name'])) ?></strong><small><?= e($currentUser['role_names_label'] ?? $currentUser['role_name']) ?></small></div>
                        <?php if (\App\Core\PermissionRegistry::showOwnProfile()): ?><a role="menuitem" href="<?= e(url('/index/?page=profile')) ?>">Profile</a><?php endif; ?>
                        <?php if (Auth::can('manage_own_settings')): ?><a role="menuitem" href="<?= e(url(($isAdminArea ? '/admin/' : '/index/') . '?page=settings')) ?>">Settings</a><?php endif; ?>
                        <form action="<?= e(url('/auth/logout.php')) ?>" method="post"><?= Csrf::field() ?><button type="submit" role="menuitem">Sign out</button></form>
                    </div>
                </div>
            </div>
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
    <script src="<?= e(url('/assets/js/password-toggle.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/password-toggle.js'))) ?>" defer></script>
    <script src="<?= e(url('/assets/js/bulk-delete.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/bulk-delete.js'))) ?>" defer></script>
    <script id="campus-grading-settings" type="application/json"><?= json_encode(\App\Services\GradeService::settings(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <script src="<?= e(url('/assets/js/grading-policy.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/grading-policy.js'))) ?>" defer></script>
    <?php if (in_array($active, ['dashboard', 'attendance'], true)): ?>
        <?php require BASE_PATH . '/templates/partials/holiday_dialog.php'; ?>
        <script src="<?= e(url('/assets/js/holidays.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/holidays.js'))) ?>" defer></script>
    <?php endif; ?>
    <script src="<?= e(url('/assets/js/app.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/app.js'))) ?>" defer></script>
    <?php if ($active === 'messages' && Auth::can('send_messages')): ?><script src="<?= e(url('/assets/vendor/quill/quill.js')) ?>" defer></script><?php endif; ?>
    <?php if ($active === 'messages' && Auth::can('send_messages')): ?><script src="<?= e(url('/assets/js/message-compose.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/message-compose.js'))) ?>" defer></script><?php endif; ?>
    <?php if ($active === 'messages'): ?><script src="<?= e(url('/assets/js/messages.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/messages.js'))) ?>" defer></script><?php endif; ?>
    <script src="<?= e(url('/assets/js/event-targets.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/event-targets.js'))) ?>" defer></script>
    <script src="<?= e(url('/assets/js/sidebar-navigation.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/sidebar-navigation.js'))) ?>" defer></script>
    <script src="<?= e(url('/assets/js/global-search.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/global-search.js'))) ?>" defer></script>
    <script src="<?= e(url('/assets/js/attendance.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/attendance.js'))) ?>" defer></script>
    <?php if ($isAdminArea && $active === 'attendance'): ?><script src="<?= e(url('/assets/js/admin-roster-explorer.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/admin-roster-explorer.js'))) ?>" defer></script><?php endif; ?>
    <script src="<?= e(url('/assets/js/attendance-policy.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/attendance-policy.js'))) ?>" defer></script>
    <script src="<?= e(url('/assets/js/live-seat-plan.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/live-seat-plan.js'))) ?>" defer></script>
    <script src="<?= e(url('/assets/js/timetable.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/timetable.js'))) ?>" defer></script>
    <script src="<?= e(url('/assets/js/seat-plans.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/seat-plans.js'))) ?>" defer></script>
    <?php if ($active === 'class-roster'): ?><script src="<?= e(url('/assets/js/class-roster.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/class-roster.js'))) ?>" defer></script><?php endif; ?>
    <?php if (!$isAdminArea && $active === 'people'): ?><script src="<?= e(url('/assets/js/people.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/people.js'))) ?>" defer></script><?php endif; ?>
    <?php if ($isAdminArea && $active === 'rooms'): ?><script src="<?= e(url('/assets/js/rooms.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/rooms.js'))) ?>" defer></script><?php endif; ?>
    <?php if ($isAdminArea && $active === 'academics'): ?><script src="<?= e(url('/assets/js/offering-components.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/offering-components.js'))) ?>" defer></script><?php endif; ?>
    <?php if ($isAdminArea && $active === 'academics'): ?><script src="<?= e(url('/assets/js/academic-management.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/academic-management.js'))) ?>" defer></script><?php endif; ?>
    <?php if ($isAdminArea && $active === 'recollections'): ?><script src="<?= e(url('/assets/js/recollection-management.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/recollection-management.js'))) ?>" defer></script><?php endif; ?>
    <?php if ($isAdminArea && $active === 'compliance'): ?><script src="<?= e(url('/assets/js/compliance.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/compliance.js'))) ?>" defer></script><?php endif; ?>
    <?php if ($isAdminArea && $active === 'audit-trail'): ?><script src="<?= e(url('/assets/js/audit-trail.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/audit-trail.js'))) ?>" defer></script><?php endif; ?>
    <?php if ($active === 'analytics'): ?><script src="<?= e(url('/assets/vendor/echarts/campustrack-charts.min.js')) ?>" defer></script><script src="<?= e(url('/assets/js/analytics.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/analytics.js'))) ?>" defer></script><?php endif; ?>
    <script src="<?= e(url('/assets/js/admin-control.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/admin-control.js'))) ?>" defer></script>
<script src="<?= e(url('/assets/js/notifications.js')) ?>" defer></script>
<?php if ($active === 'settings'): ?><script src="<?= e(url('/assets/js/settings.js?v=' . (string) filemtime(PUBLIC_PATH . '/assets/js/settings.js'))) ?>" defer></script><?php endif; ?>
</body>
</html>
