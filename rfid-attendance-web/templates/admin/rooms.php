<?php
$pendingScannerCredentials = $_SESSION['_scanner_credentials'] ?? null;
unset($_SESSION['_scanner_credentials']);
?>
<div class="section-navigation-page" data-section-navigation>
<header class="page-header"><div><p class="eyebrow">Facilities</p><h1>Rooms & devices</h1><p>Register campus rooms and connect as many authenticated scanners as each room needs.</p></div></header>
<div class="section-navigation-layout">
<?php $sectionNavigationLabel = 'Rooms and devices page sections'; $sectionNavigation = [
    ['id' => 'rooms-device-setup', 'label' => 'Room & device setup'],
    ['id' => 'rooms-directory', 'label' => 'Registered rooms'],
    ['id' => 'rooms-scanners', 'label' => 'RFID scanners'],
]; if (\App\Core\Auth::can('manage_seat_plans')) array_splice($sectionNavigation, 1, 0, [['id'=>'rooms-seat-plans','label'=>'Seat Plans']]); require BASE_PATH . '/templates/admin/partials/section_navigation.php'; ?>
<div class="section-navigation-content">
<section class="section-scroll-target" id="rooms-device-setup" data-section-target>
    <div class="section-heading"><div><h2>Room & device setup</h2><p class="muted">Register facilities and connect authenticated RFID scanner devices.</p></div></div>
<div class="grid grid-2">
    <article class="card"><h2>Add room</h2><form method="post" class="stack">
        <?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="create_room">
        <div class="form-grid"><label class="field"><span>Room code</span><input name="room_code" required></label><label class="field"><span>Room name</span><input name="room_name" required></label><label class="field"><span>Building</span><input name="building"></label><label class="field"><span>Floor</span><input name="floor"></label></div>
        <div class="form-actions"><button class="button button-primary">Add room</button></div>
    </form></article>
    <article class="card"><h2>Add RFID scanner</h2><form method="post" class="stack" data-device-create-form data-endpoint="<?= e(url('/admin/facility.php')) ?>">
        <?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="create_device">
        <label class="field"><span>Device code</span><input name="device_code" required></label><label class="field"><span>Name</span><input name="name" required></label>
        <div class="form-grid"><label class="field"><span>Assigned room code</span><select name="room_id" data-linked-detail="device-room-name"><option value="" data-detail="No room assigned">Unassigned</option><?php foreach ($rooms as $room): ?><option value="<?= e($room['id']) ?>" data-detail="<?= e($room['room_name']) ?>"><?= e($room['room_code']) ?></option><?php endforeach; ?></select></label><label class="field"><span>Room name</span><input id="device-room-name" value="No room assigned" readonly></label></div>
        <div class="alert alert-danger" data-device-create-error role="alert" hidden></div>
        <div class="form-actions"><span class="ajax-form-status" data-device-create-status aria-live="polite"></span><button class="button button-primary">Add scanner</button></div>
    </form></article>
</div>
</section>
<?php if (\App\Core\Auth::can('manage_seat_plans')): ?><section class="section section-scroll-target" id="rooms-seat-plans" data-section-target>
    <?php $seatPlanContext = 'admin'; require BASE_PATH . '/templates/partials/seat_plan_editor.php'; ?>
</section><?php endif; ?>
<section class="section section-scroll-target" id="rooms-directory" data-section-target><div class="section-heading"><div><h2>Registered rooms</h2><p class="muted">Edit room identity and location, or control whether it can be used.</p></div></div><div class="table-wrap"><table data-bulk-type="room" data-default-sort-column="0" data-default-sort-direction="asc"><thead><tr><th>Room</th><th>Location</th><th>Scanner</th><th>Status</th><th data-sortable="false">Action</th></tr></thead><tbody>
<?php foreach ($rooms as $room): ?><tr data-room-row="<?= e($room['id']) ?>"><td data-room-identity><strong><?= e($room['room_code']) ?></strong><br><?= e($room['room_name']) ?></td><td data-room-location><?= e(trim(($room['building'] ?? '') . ' ' . ($room['floor'] ?? '')) ?: 'Not specified') ?></td><td data-room-scanner><?= e($room['device_code'] ?? 'Unassigned') ?></td><td><div class="status-action-cell"><?php $statusIconValue = $room['status']; require BASE_PATH . '/templates/admin/partials/status_icon.php'; ?><form method="post" data-facility-status-form><?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="toggle_room"><input type="hidden" name="room_id" value="<?= e($room['id']) ?>"><button class="icon-action-button" aria-label="<?= $room['status'] === 'active' ? 'Deactivate' : 'Activate' ?> room <?= e($room['room_code']) ?>" title="<?= $room['status'] === 'active' ? 'Deactivate room' : 'Activate room' ?>"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 2.7v6.1"/><path d="M5.7 5.2a7 7 0 1 0 8.6 0"/></svg></button></form></div></td><td><button type="button" class="icon-action-button" data-manage-room data-room-id="<?= e($room['id']) ?>" aria-haspopup="dialog" aria-label="Edit room <?= e($room['room_code']) ?>" title="Edit room details"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M11.5 4.5H5.8A1.8 1.8 0 0 0 4 6.3v7.9A1.8 1.8 0 0 0 5.8 16h7.9a1.8 1.8 0 0 0 1.8-1.8V8.5"/><path d="m10 11 1.1-3.1 4.6-4.6a1.4 1.4 0 0 1 2 2l-4.6 4.6L10 11Z"/></svg></button></td></tr><?php endforeach; ?>
</tbody></table></div></section>
<section class="section section-scroll-target" id="rooms-scanners" data-section-target><div class="section-heading"><div><h2>RFID scanners</h2><p class="muted">Update scanner identity, room assignment, status, or device credentials.</p></div></div><div class="table-wrap"><table data-bulk-type="device" data-default-sort-column="0" data-default-sort-direction="asc"><thead><tr><th>Device</th><th>Room</th><th>Last seen</th><th>Status</th><th data-sortable="false">Action</th></tr></thead><tbody>
<?php foreach ($devices as $device): ?><tr data-device-row="<?= e($device['id']) ?>" data-device-room-id="<?= e($device['room_id'] ?? '') ?>"><td data-device-identity><strong><?= e($device['device_code']) ?></strong><br><?= e($device['name']) ?></td><td data-device-room><?= e($device['room_code'] ?? 'Unassigned') ?></td><td><?= e(format_datetime($device['last_seen_at'] ?? null, 'Never')) ?></td><td><div class="status-action-cell"><?php $statusIconValue = $device['status']; require BASE_PATH . '/templates/admin/partials/status_icon.php'; ?><form method="post" data-facility-status-form><?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="toggle_device"><input type="hidden" name="device_id" value="<?= e($device['id']) ?>"><button class="icon-action-button" aria-label="<?= $device['status'] === 'active' ? 'Deactivate' : 'Activate' ?> scanner <?= e($device['device_code']) ?>" title="<?= $device['status'] === 'active' ? 'Deactivate scanner' : 'Activate scanner' ?>"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 2.7v6.1"/><path d="M5.7 5.2a7 7 0 1 0 8.6 0"/></svg></button></form></div></td><td><button type="button" class="icon-action-button" data-manage-device data-device-id="<?= e($device['id']) ?>" aria-haspopup="dialog" aria-label="Edit scanner <?= e($device['device_code']) ?>" title="Edit scanner details"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="M11.5 4.5H5.8A1.8 1.8 0 0 0 4 6.3v7.9A1.8 1.8 0 0 0 5.8 16h7.9a1.8 1.8 0 0 0 1.8-1.8V8.5"/><path d="m10 11 1.1-3.1 4.6-4.6a1.4 1.4 0 0 1 2 2l-4.6 4.6L10 11Z"/></svg></button></td></tr><?php endforeach; ?>
</tbody></table></div></section>
</div>
</div>
</div>

<dialog id="manage-room-dialog" class="app-dialog" data-modal data-facility-dialog="room" data-endpoint="<?= e(url('/admin/facility.php')) ?>" aria-labelledby="manage-room-title">
    <div class="app-dialog-shell">
        <header class="app-dialog-header"><div><p class="eyebrow">Facility management</p><h2 id="manage-room-title">Edit room</h2><p class="muted" data-facility-subtitle>Update the room code, name, and campus location.</p></div><button type="button" class="dialog-close" data-dialog-close aria-label="Close room editor"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button></header>
        <div class="app-dialog-body">
            <div class="dialog-loading" data-facility-loading role="status" hidden>Loading room details…</div>
            <div class="alert alert-danger" data-facility-error role="alert" hidden></div>
            <form method="post" class="stack" data-facility-form>
                <?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="update_room"><input type="hidden" name="room_id">
                <div class="form-grid"><label class="field"><span>Room code *</span><input name="room_code" maxlength="40" required></label><label class="field"><span>Room name *</span><input name="room_name" maxlength="120" required></label><label class="field"><span>Building</span><input name="building" maxlength="120"></label><label class="field"><span>Floor</span><input name="floor" maxlength="30"></label></div>
                <div class="room-seat-plan-summary"><div><p class="eyebrow">Linked seat plan</p><strong data-room-seat-plan-name>No saved seat plan</strong><p class="muted" data-room-seat-plan-meta>Create a layout for this room or manage its existing grid.</p></div><button type="button" class="button button-secondary" data-open-room-seat-plan <?= \App\Core\Auth::can('manage_seat_plans') ? '' : 'hidden disabled' ?>>Open seat plan</button></div>
                <div class="ajax-form-footer"><span class="ajax-form-status" data-facility-feedback aria-live="polite"></span><div class="form-actions"><button type="button" class="button button-danger" data-delete-facility="room">Delete room</button><button type="button" class="button button-secondary" data-dialog-close>Cancel</button><button type="submit" class="button button-primary">Save room</button></div></div>
            </form>
        </div>
    </div>
</dialog>

<dialog id="manage-device-dialog" class="app-dialog" data-modal data-facility-dialog="device" data-endpoint="<?= e(url('/admin/facility.php')) ?>" aria-labelledby="manage-device-title">
    <div class="app-dialog-shell">
        <header class="app-dialog-header"><div><p class="eyebrow">Device management</p><h2 id="manage-device-title">Edit scanner</h2><p class="muted">Update its identity or assign it to another active room.</p></div><button type="button" class="dialog-close" data-dialog-close aria-label="Close scanner editor"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button></header>
        <div class="app-dialog-body">
            <div class="dialog-loading" data-facility-loading role="status" hidden>Loading scanner details…</div>
            <div class="alert alert-danger" data-facility-error role="alert" hidden></div>
            <form method="post" class="stack" data-facility-form>
                <?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="update_device"><input type="hidden" name="device_id">
                <div class="form-grid"><label class="field"><span>Device code *</span><input name="device_code" maxlength="60" required></label><label class="field"><span>Name *</span><input name="name" maxlength="120" required></label></div>
                <label class="field"><span>Assigned room</span><select name="room_id"><option value="">Unassigned</option><?php foreach ($rooms as $room): ?><?php if ($room['status'] === 'active'): ?><option value="<?= e($room['id']) ?>"><?= e($room['room_code']) ?> — <?= e($room['room_name']) ?></option><?php endif; ?><?php endforeach; ?></select><small class="field-help">Multiple scanners may share a room to keep high-volume entry lines moving.</small></label>
                <button type="button" class="button button-secondary" data-rotate-device-key>Generate new API key</button>
                <div class="ajax-form-footer"><span class="ajax-form-status" data-facility-feedback aria-live="polite"></span><div class="form-actions"><button type="button" class="button button-danger" data-delete-facility="device">Delete scanner</button><button type="button" class="button button-secondary" data-dialog-close>Cancel</button><button type="submit" class="button button-primary">Save scanner</button></div></div>
            </form>
        </div>
    </div>
</dialog>

<dialog id="scanner-credentials-dialog" class="app-dialog scanner-credentials-dialog" data-modal data-scanner-credentials-dialog<?= $pendingScannerCredentials ? ' data-pending="1"' : '' ?> aria-labelledby="scanner-credentials-title">
    <div class="app-dialog-shell">
        <header class="app-dialog-header"><div><p class="eyebrow">Scanner ready</p><h2 id="scanner-credentials-title">Save the API key</h2><p class="muted">Add these credentials to the scanner before closing this window.</p></div><button type="button" class="dialog-close" data-dialog-close aria-label="Close scanner credentials"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button></header>
        <div class="app-dialog-body stack">
            <div class="scanner-credential-success" aria-hidden="true"><span>✓</span></div>
            <div class="scanner-credential-device"><span>Device code</span><strong data-scanner-credential-device-code><?= e($pendingScannerCredentials['device_code'] ?? '') ?></strong><small data-scanner-credential-room><?= e(!empty($pendingScannerCredentials['room_code']) ? 'Assigned to ' . $pendingScannerCredentials['room_code'] : ($pendingScannerCredentials ? 'Not assigned to a room' : '')) ?></small></div>
            <div class="scanner-secret-panel">
                <div><span class="field-label">API key</span><small>Shown once. Store it in the scanner's local secrets file.</small></div>
                <div class="scanner-secret-row"><code data-scanner-credential-key><?= e($pendingScannerCredentials['api_key'] ?? '') ?></code><button type="button" class="button button-secondary scanner-copy-button" data-copy-scanner-key>Copy key</button></div>
            </div>
            <div class="alert alert-warning scanner-key-warning"><strong>Keep this key private.</strong> Closing this window permanently hides it. You can generate a replacement later from the scanner editor.</div>
            <div class="form-actions"><span class="ajax-form-status" data-copy-scanner-status aria-live="polite"></span><button type="button" class="button button-primary" data-dialog-close>I've saved the key</button></div>
        </div>
    </div>
</dialog>
