<?php $canManageRfid = \App\Core\Auth::can('manage_rfid'); ?>
<div class="section-navigation-page" data-section-navigation>
<header class="page-header">
    <div><p class="eyebrow">Identity management</p><h1>Users & RFID</h1><p>Create accounts, control access, and register physical RFID cards.</p></div>
    <div class="page-header-actions">
        <?php if ($canManageRfid): ?><button type="button" class="button button-secondary" data-dialog-open="#rfid-registration-dialog">
            <svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="M5 7.5a7 7 0 0 1 10 0M7.5 10a3.5 3.5 0 0 1 5 0M10 13.5h.01"/></svg>
            Register RFID
        </button><?php endif; ?>
        <button type="button" class="button button-primary" data-dialog-open="#create-user-dialog">
            <svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 4v12M4 10h12"/></svg>
            Create user
        </button>
    </div>
</header>

<div class="section-navigation-layout">
<?php $sectionNavigationLabel = 'Users and RFID page sections'; $sectionNavigation = [
    ['id' => 'users-accounts', 'label' => 'User accounts'],
    ['id' => 'users-role-permissions', 'label' => 'Role permissions'],
]; require BASE_PATH . '/templates/admin/partials/section_navigation.php'; ?>
<div class="section-navigation-content">
<section class="section-scroll-target" id="users-accounts" data-section-target>
<form method="get" class="card inline-form data-filter-form" data-live-filter data-live-url="<?= e(url('/admin/?page=users&partial=accounts')) ?>" data-live-target="#user-results" data-live-partial="accounts" data-live-page-param="user_page">
    <input type="hidden" name="page" value="users">
    <label class="field filter-search"><span>Search users</span><input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Name, school ID, or email"></label>
    <label class="field"><span>Role</span><select name="role_id"><option value="">All roles</option><?php foreach ($roles as $role): ?><option value="<?= e($role['id']) ?>" <?= (int)$filters['role_id'] === (int)$role['id'] ? 'selected' : '' ?>><?= e($role['name']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Department code</span><select name="department_id" data-searchable><option value="">All departments</option><?php foreach ($departments as $department): ?><option value="<?= e($department['id']) ?>" <?= (int)$filters['department_id'] === (int)$department['id'] ? 'selected' : '' ?>><?= e($department['code']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Program</span><select name="program_id" data-searchable><option value="">All programs</option><?php foreach ($programs as $program): ?><option value="<?= e($program['id']) ?>" <?= (int)$filters['program_id'] === (int)$program['id'] ? 'selected' : '' ?>><?= e($program['name']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Section</span><select name="section_id" data-searchable><option value="">All sections</option><?php foreach ($sections as $section): ?><option value="<?= e($section['id']) ?>" <?= (int)$filters['section_id'] === (int)$section['id'] ? 'selected' : '' ?>><?= e($section['name']) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span>Status</span><select name="status"><option value="">All statuses</option><option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select></label>
    <div class="filter-actions"><button class="button button-primary">Apply filters</button><a class="button button-secondary" href="<?= e(url('/admin/?page=users')) ?>" data-live-clear>Clear</a></div><span class="live-filter-status muted" data-live-status aria-live="polite"></span>
</form>

<?php require BASE_PATH . '/templates/admin/partials/user_accounts.php'; ?>
</section>

<?php require BASE_PATH . '/templates/admin/partials/manage_user_dialog.php'; ?>

<dialog id="create-user-dialog" class="app-dialog app-dialog-large" data-modal aria-labelledby="create-user-title">
    <div class="app-dialog-shell">
        <header class="app-dialog-header">
            <div><p class="eyebrow">Identity management</p><h2 id="create-user-title">Create user</h2><p class="muted">Add an account and assign its initial access and academic placement.</p></div>
            <button type="button" class="dialog-close" data-dialog-close aria-label="Close create user dialog"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button>
        </header>
        <div class="app-dialog-body">
        <form method="post" enctype="multipart/form-data">
            <?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="create_user">
            <div class="form-grid">
                <label class="field"><span>School ID *</span><input name="school_id" maxlength="40" required></label>
                <label class="field"><span>Email *</span><input type="email" name="email" maxlength="190" required></label>
                <label class="field"><span>First name *</span><input name="first_name" maxlength="80" required></label>
                <label class="field"><span>Middle name</span><input name="middle_name" maxlength="80"></label>
                <label class="field"><span>Last name *</span><input name="last_name" maxlength="80" required></label>
                <label class="field"><span>Initial password *</span><input type="password" name="password" minlength="8" required></label>
                <label class="field"><span>Primary role *</span><select name="role_id" required><?php foreach ($roles as $role): ?><option value="<?= e($role['id']) ?>"><?= e($role['name']) ?></option><?php endforeach; ?></select><small class="field-help">Controls the user’s default workspace.</small></label>
                <label class="field"><span>Additional roles</span><select name="role_ids[]" multiple data-searchable><?php foreach ($roles as $role): ?><option value="<?= e($role['id']) ?>"><?= e($role['name']) ?></option><?php endforeach; ?></select><small class="field-help">The primary role is included automatically.</small></label>
                <label class="field"><span>Department code</span><select name="department_id"><option value="">None</option><?php foreach ($departments as $department): ?><option value="<?= e($department['id']) ?>"><?= e($department['code']) ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>Program</span><select name="program_id"><option value="">None</option><?php foreach ($programs as $program): ?><option value="<?= e($program['id']) ?>"><?= e($program['name']) ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>Section</span><select name="section_id"><option value="">None</option><?php foreach ($sections as $section): ?><option value="<?= e($section['id']) ?>"><?= e($section['name']) ?></option><?php endforeach; ?></select></label>
                <label class="field form-grid-wide"><span>Profile picture</span><input type="file" name="profile_image" accept="image/jpeg,image/png,image/webp"><small class="field-help">Optional for non-administrator accounts. JPG, PNG, or WebP, up to 2 MB.</small></label>
            </div>
            <div class="form-actions"><button type="button" class="button button-secondary" data-dialog-close>Cancel</button><button class="button button-primary">Create user</button></div>
        </form>
        </div>
    </div>
</dialog>

<?php if ($canManageRfid): ?><dialog id="rfid-registration-dialog" class="app-dialog app-dialog-rfid" data-modal aria-labelledby="rfid-registration-title">
    <div class="app-dialog-shell">
        <header class="app-dialog-header">
            <div><p class="eyebrow">Physical card registration</p><h2 id="rfid-registration-title">Register RFID</h2><p class="muted">Select an account and scanner, then tap the physical card and review the assignment.</p></div>
            <button type="button" class="dialog-close" data-dialog-close aria-label="Close RFID registration dialog"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button>
        </header>
        <div class="app-dialog-body">
        <form method="post" class="stack rfid-registration-form" data-rfid-registration data-endpoint="<?= e(url('/admin/rfid.php')) ?>">
            <?= \App\Core\Csrf::field() ?><input type="hidden" name="request_token" data-rfid-request-token>
            <section class="rfid-scanner" data-rfid-scanner data-state="idle" aria-labelledby="rfid-scanner-status">
                <div class="rfid-scan-card">
                    <div class="rfid-scan-card-top">
                        <span class="rfid-scan-brand"><span class="rfid-scan-brand-mark" aria-hidden="true">RF</span><strong>RFID Attendance</strong></span>
                        <span class="rfid-scan-label">RFID</span>
                    </div>
                    <div class="rfid-scan-card-body">
                        <div class="rfid-scan-photo"><img src="<?= e(url('/assets/images/default-avatar.svg')) ?>" alt="" data-rfid-card-photo></div>
                        <div class="rfid-scan-card-info">
                            <strong data-rfid-card-name>Select a user</strong>
                            <span data-rfid-card-role>Account role</span>
                            <span data-rfid-card-id>School ID</span>
                            <span data-rfid-card-department>Department</span>
                        </div>
                    </div>
                    <div class="rfid-scan-card-footer"><span class="rfid-chip" aria-hidden="true"></span><span class="rfid-barcode" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span></div>
                    <span class="rfid-scan-beam" aria-hidden="true"></span>
                    <span class="rfid-scan-complete" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m6 12.5 4 4L18.5 8"/></svg></span>
                </div>
                <div class="rfid-scan-status" id="rfid-scanner-status" data-rfid-scan-status role="status" aria-live="polite">
                    <span class="rfid-scan-status-symbol" aria-hidden="true"><i></i><svg viewBox="0 0 20 20"><path d="m5 10.5 3 3L15 6.5"/></svg></span>
                    <span><strong data-rfid-status-title>Ready to scan</strong><small data-rfid-status-message>Select a user and physical scanner to begin.</small></span>
                </div>
            </section>
            <div class="field user-lookup" data-user-lookup data-endpoint="<?= e(url('/admin/user.php')) ?>">
                <label for="rfid-user-search">User *</label>
                <input id="rfid-user-search" type="search" autocomplete="off" placeholder="Type a name, school ID, or email" data-user-lookup-input aria-autocomplete="list" aria-controls="rfid-user-results" required>
                <input type="hidden" name="user_id" data-user-lookup-value>
                <div class="user-lookup-results" id="rfid-user-results" data-user-lookup-results role="listbox" hidden></div>
                <small class="field-help">Search begins as you type. Only active accounts are shown.</small>
            </div>
            <label class="field"><span>Scanner *</span><select name="device_id" required data-rfid-device><option value="">Select a configured scanner</option><?php foreach (($rfidDevices ?? []) as $device): ?><option value="<?= e($device['id']) ?>"><?= e($device['device_code']) ?> — <?= e($device['name']) ?><?= $device['room_code'] ? ' · ' . e($device['room_code']) : '' ?></option><?php endforeach; ?></select><small class="field-help">Only active scanners with an API key are available.</small></label>
            <label class="field"><span>Captured RFID UID</span><input id="rfid-uid" maxlength="20" placeholder="Waiting for a physical card tap" readonly data-rfid-uid></label>
            <div class="rfid-review" data-rfid-review hidden><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 10.5 3 3L15 6.5"/></svg><p><strong>Card captured</strong><span data-rfid-review-message>Check the selected user and RFID UID, then confirm the assignment.</span></p></div>
            <div class="alert alert-warning" data-rfid-reassignment hidden><strong>Card already assigned</strong><p data-rfid-reassignment-message></p><label><input type="checkbox" name="allow_reassignment" value="1" data-rfid-reassignment-confirm> I understand that this card will be removed from its current user.</label></div>
            <div class="form-actions">
                <button type="button" class="button button-secondary" data-dialog-close>Cancel</button>
                <button type="button" class="button button-secondary" data-rfid-start-scan><span data-rfid-scan-button-label>Start card scan</span></button>
                <button class="button button-primary" data-rfid-confirm disabled>Confirm assignment</button>
            </div>
        </form>
        </div>
    </div>
</dialog><?php endif; ?>

<?php
$permissionGroups = [
    'Administration & setup' => ['manage_users', 'manage_roles', 'manage_rfid', 'manage_rooms', 'manage_seat_plans', 'manage_academic_structure', 'manage_academic_calendar', 'manage_teacher_assignments'],
    'Campus operations' => ['manage_events', 'manage_recollections', 'manage_attendance', 'manage_attendance_policies', 'manage_teacher_time_off', 'manage_grades', 'manage_grading_settings', 'manage_compliance', 'manage_reports', 'send_messages'],
    'Viewing permissions' => ['view_attendance', 'view_grades', 'view_compliance', 'view_audit_logs'],
];
$categorizedPermissionCodes = array_merge(...array_values($permissionGroups));
$uncategorizedPermissionCodes = array_values(array_diff(array_column($permissions, 'code'), $categorizedPermissionCodes));
if ($uncategorizedPermissionCodes !== []) $permissionGroups['Other permissions'] = $uncategorizedPermissionCodes;
$roleColors = [1 => 'red', 2 => 'blue', 3 => 'green', 4 => 'amber', 5 => 'violet'];
$availableRoleIds = array_map(static fn(array $role): int => (int) $role['id'], $roles);
$requestedRoleId = (int) ($_GET['permission_role_id'] ?? 0);
$defaultRoleId = in_array($requestedRoleId, $availableRoleIds, true)
    ? $requestedRoleId
    : (in_array(2, $availableRoleIds, true) ? 2 : ($availableRoleIds[0] ?? 0));
$canManageRoles = \App\Core\Auth::can('manage_roles');
?>
<section class="card section permission-workspace section-scroll-target" id="users-role-permissions" data-section-target data-permission-workspace>
    <div class="permission-heading">
        <div><p class="eyebrow">Access control</p><h2>Role permissions</h2><p class="muted">Select a role, then explicitly allow or deny each capability.</p></div>
        <svg class="permission-heading-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v5c0 4.6 2.9 8.6 7 10 4.1-1.4 7-5.4 7-10V6l-7-3Z"/><path d="m9.5 12 1.6 1.6 3.7-4"/></svg>
    </div>
    <div class="permission-layout">
        <aside class="role-rail" aria-label="Roles">
            <p class="permission-kicker">Roles</p>
            <div class="role-tabs" role="tablist" aria-orientation="vertical">
                <?php foreach ($roles as $role): ?>
                    <button type="button" class="role-tab <?= (int)$role['id'] === $defaultRoleId ? 'is-active' : '' ?>" role="tab" aria-selected="<?= (int)$role['id'] === $defaultRoleId ? 'true' : 'false' ?>" aria-controls="role-permissions-<?= e($role['id']) ?>" data-role-tab="<?= e($role['id']) ?>">
                        <span class="role-dot role-dot-<?= e($roleColors[(int)$role['id']] ?? 'blue') ?>"></span><span><strong><?= e($role['name']) ?></strong><small><?= (int)$role['id'] === 1 ? 'Full access · locked' : 'Custom access' ?></small></span>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php if ($canManageRoles): ?><button type="button" class="button button-secondary role-create-button" data-dialog-open="#create-role-dialog"><svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 4v12M4 10h12"/></svg>Add another role</button><?php endif; ?>
        </aside>
        <div class="permission-panels">
            <?php foreach ($roles as $role): ?>
                <?php $roleId = (int) $role['id']; $allowedIds = array_map('intval', $rolePermissionMap[$roleId] ?? []); ?>
                <section class="permission-panel" id="role-permissions-<?= e($roleId) ?>" role="tabpanel" data-role-panel="<?= e($roleId) ?>" <?= $roleId === $defaultRoleId ? '' : 'hidden' ?>>
                    <div class="permission-panel-heading"><div><p class="permission-kicker">Permissions</p><h3><?= e($role['name']) ?></h3><?php if (!empty($role['description'])): ?><p class="muted role-description"><?= e($role['description']) ?></p><?php endif; ?></div><div class="table-actions"><span class="badge"><?= e(count($allowedIds)) ?> allowed</span><?php if ($roleId !== 1 && $canManageRoles): ?><button type="button" class="icon-action-button" data-manage-role data-role-id="<?= e($roleId) ?>" data-role-name="<?= e($role['name']) ?>" data-role-description="<?= e($role['description'] ?? '') ?>" aria-label="Edit role <?= e($role['name']) ?>" title="Edit role details"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M11.5 4.5H5.8A1.8 1.8 0 0 0 4 6.3v7.9A1.8 1.8 0 0 0 5.8 16h7.9a1.8 1.8 0 0 0 1.8-1.8V8.5"/><path d="m10 11 1.1-3.1 4.6-4.6a1.4 1.4 0 0 1 2 2l-4.6 4.6L10 11Z"/></svg></button><?php endif; ?></div></div>
                    <?php if ($roleId === 1): ?><div class="alert alert-warning">Administrator permissions are fixed to full access and cannot be changed.</div><?php endif; ?>
                    <form method="post" class="permission-form">
                        <?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="update_role_permissions"><input type="hidden" name="role_id" value="<?= e($roleId) ?>">
                        <?php foreach ($permissionGroups as $groupName => $codes): ?>
                            <div class="permission-group">
                                <h4><?= e($groupName) ?></h4>
                                <?php foreach ($permissions as $permission): ?><?php if (in_array($permission['code'], $codes, true)): ?>
                                    <?php $isAllowed = in_array((int)$permission['id'], $allowedIds, true); ?>
                                    <div class="permission-row">
                                        <div class="permission-copy"><strong><?= e(ucwords(str_replace('_', ' ', $permission['code']))) ?></strong><small><?= e($permission['description']) ?></small></div>
                                        <div class="permission-toggle" role="radiogroup" aria-label="<?= e($permission['code']) ?>">
                                            <label class="permission-choice permission-deny" title="Not allowed"><input type="radio" name="permission_ids[<?= e($permission['id']) ?>]" value="0" <?= !$isAllowed ? 'checked' : '' ?> <?= $roleId === 1 || !$canManageRoles ? 'disabled' : '' ?> aria-label="Not allowed"><span class="permission-state"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></span></label>
                                            <label class="permission-choice permission-allow" title="Allowed"><input type="radio" name="permission_ids[<?= e($permission['id']) ?>]" value="<?= e($permission['id']) ?>" <?= $isAllowed ? 'checked' : '' ?> <?= $roleId === 1 || !$canManageRoles ? 'disabled' : '' ?> aria-label="Allowed"><span class="permission-state"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m4 10 4 4 8-9"/></svg></span></label>
                                        </div>
                                    </div>
                                <?php endif; ?><?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                        <?php if ($roleId !== 1 && $canManageRoles): ?><div class="permission-save"><span class="muted">Changes apply to every user assigned this role.</span><button class="button button-primary">Save <?= e($role['name']) ?> permissions</button></div><?php endif; ?>
                    </form>
                </section>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php if ($canManageRoles): ?>
<dialog id="edit-role-dialog" class="app-dialog" data-modal aria-labelledby="edit-role-title"><div class="app-dialog-shell"><header class="app-dialog-header"><div><p class="eyebrow">Access control</p><h2 id="edit-role-title">Edit role</h2><p class="muted">Rename this role or clarify who should receive it. Existing assignments remain intact.</p></div><button type="button" class="dialog-close" data-dialog-close aria-label="Close role editor"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button></header><div class="app-dialog-body"><form method="post" class="stack" data-role-metadata-form><?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="update_role_metadata"><input type="hidden" name="role_id"><label class="field"><span>Role name *</span><input name="name" minlength="2" maxlength="80" required></label><label class="field"><span>Description</span><textarea name="description" maxlength="255"></textarea></label><div class="form-actions"><button type="button" class="button button-secondary" data-dialog-close>Cancel</button><button class="button button-primary">Save role</button></div></form></div></div></dialog>
<dialog id="create-role-dialog" class="app-dialog" data-modal aria-labelledby="create-role-title">
    <div class="app-dialog-shell">
        <header class="app-dialog-header">
            <div><p class="eyebrow">Access control</p><h2 id="create-role-title">Add another role</h2><p class="muted">Create a role, then configure its permissions from the role panel.</p></div>
            <button type="button" class="dialog-close" data-dialog-close aria-label="Close role dialog"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button>
        </header>
        <div class="app-dialog-body">
            <form method="post" class="stack">
                <?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="create_role">
                <label class="field"><span>Role name *</span><input name="name" minlength="2" maxlength="80" autocomplete="off" placeholder="Example: Department Coordinator" required></label>
                <label class="field"><span>Description</span><textarea name="description" maxlength="255" placeholder="Describe who should receive this role"></textarea></label>
                <div class="alert alert-warning">New roles start with every permission denied. Review and explicitly allow only the capabilities they need.</div>
                <div class="form-actions"><button type="button" class="button button-secondary" data-dialog-close>Cancel</button><button class="button button-primary">Create role</button></div>
            </form>
        </div>
    </div>
</dialog>
<?php endif; ?>
</div>
</div>
</div>
