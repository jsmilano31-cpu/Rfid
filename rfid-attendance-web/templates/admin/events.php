<?php require BASE_PATH.'/templates/partials/event_workspace_assets.php'; ?>
<?php $pendingEventScannerCredentials = $_SESSION['_scanner_credentials'] ?? null; unset($_SESSION['_scanner_credentials']); ?>
<?php $eventTargetsById = \App\Services\EventAudienceService::targetsByEventIds(array_column($events, 'id')); ?>
<?php if ($pendingEventScannerCredentials): ?><span hidden data-pending-scanner-credentials="<?= e(json_encode($pendingEventScannerCredentials, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?>"></span><?php endif; ?>
<div class="section-navigation-page" data-section-navigation>
<header class="page-header"><div><p class="eyebrow">Institutional activities</p><h1>School events</h1><p>Create required events, assign audiences, and record RFID attendance.</p></div><button type="button" class="button button-primary" data-event-create>Create event</button></header>
<div class="section-navigation-layout">
<?php $sectionNavigationLabel = 'School events page sections'; $sectionNavigation = [
    ['id' => 'events-setup', 'label' => 'Event setup & scanning'],
    ['id' => 'events-attendance-management', 'label' => 'Attendance management'],
    ['id' => 'events-directory', 'label' => 'Events'],
]; require BASE_PATH . '/templates/admin/partials/section_navigation.php'; ?>
<div class="section-navigation-content">
<section class="section-scroll-target" id="events-setup" data-section-target>
<div class="section-heading"><div><h2>Event setup & scanning</h2><p class="muted">Create an event or record attendance using an RFID UID.</p></div></div>
<div class="grid grid-2">
    <article class="card"><p class="eyebrow">Plan an activity</p><h2>Create an event</h2><p class="muted">Use the event editor to prepare the schedule, participants, banner, and connected scanners together.</p><button type="button" class="button button-primary" data-event-create>Create event</button><p class="field-help">Open any event below for its activity history and live tracking display.</p></article>
    <article class="card"><h2>RFID event entry</h2><form method="post" class="stack"><?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="event_scan"><div class="form-grid"><label class="field"><span>Event</span><select name="event_id" data-linked-detail="scan-event-date"><?php foreach ($events as $event): ?><option value="<?= e($event['id']) ?>" data-detail="<?= e($event['event_date']) ?>"><?= e($event['title']) ?></option><?php endforeach; ?></select></label><label class="field"><span>Start date</span><input id="scan-event-date" value="<?= e($events[0]['event_date'] ?? '') ?>" readonly></label></div><label class="field"><span>RFID UID</span><input id="event-uid" name="uid" required pattern="[A-Fa-f0-9]{8,20}"></label><button class="button button-primary">Record check-in / check-out</button></form></article>
</div>
</section>
<section class="section section-scroll-target" id="events-attendance-management" data-section-target>
<div class="section-heading"><div><h2>Attendance management</h2><p class="muted">Correct participant records and control event attendance windows.</p></div></div>
<div class="grid grid-2">
    <article class="card"><h2>Manual attendance correction</h2><form method="post" class="stack" data-event-participant-correction><?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="manual_event_attendance">
        <div class="form-grid"><label class="field"><span>Event</span><select name="event_id" data-searchable data-linked-detail="correction-event-date" required><option value="">Select an event</option><?php foreach ($events as $event): ?><option value="<?= e($event['id']) ?>" data-detail="<?= e($event['event_date']) ?>"><?= e($event['title']) ?></option><?php endforeach; ?></select></label><label class="field"><span>Start date</span><input id="correction-event-date" readonly></label></div>
        <label class="field"><span>Section</span><select name="section_id" data-event-participant-section data-searchable required disabled><option value="">Select an event first</option></select></label>
        <?php $lookupName = 'user_id'; $lookupLabel = 'Student or participant'; $lookupPlaceholder = 'Type a school ID or participant name'; $lookupContext = 'event_participants'; $lookupParentName = 'event_id'; $lookupSectionName = 'section_id'; require BASE_PATH . '/templates/partials/remote_user_lookup.php'; unset($lookupName, $lookupLabel, $lookupPlaceholder, $lookupContext, $lookupParentName, $lookupSectionName); ?>
        <label class="field"><span>Status</span><select name="status"><option>Attended</option><option>Late</option><option>Absent</option><option>Excused</option><option>Incomplete</option></select></label><label class="field"><span>Reason</span><input name="reason" required></label><button class="button button-secondary">Save correction</button>
    </form></article>
    <article class="card"><h2>Attendance window</h2><form method="post" class="stack"><?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="set_event_status"><div class="form-grid"><label class="field"><span>Event</span><select name="event_id" data-linked-detail="event-current-status"><?php foreach ($events as $event): ?><option value="<?= e($event['id']) ?>" data-detail="<?= e(ucfirst($event['status'])) ?>"><?= e($event['title']) ?></option><?php endforeach; ?></select></label><label class="field"><span>Current status</span><input id="event-current-status" value="<?= e(isset($events[0]) ? ucfirst($events[0]['status']) : '') ?>" readonly></label></div><label class="field"><span>New status</span><select name="status"><option value="scheduled">Scheduled</option><option value="open">Open attendance</option><option value="closed">Close and finalize attendance</option><option value="cancelled">Cancelled</option></select></label><button class="button button-primary" data-confirm="Update this event status? Closing will mark missing required participants absent.">Update status</button></form></article>
</div>
</section>
<section class="section section-scroll-target" id="events-directory" data-section-target>
    <div class="section-heading">
        <div><h2>Events</h2><p class="muted">Review event details and edit upcoming activities.</p></div>
        <span class="ajax-form-status" data-event-directory-status aria-live="polite"></span>
    </div>
    <div class="table-wrap" data-event-directory>
        <table data-bulk-type="event">
            <thead><tr><th>Event</th><th>Schedule</th><th>Venue</th><th>Attendance</th><th>Status</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($events as $event): ?>
                <?php
                $eventDetails = [
                    'id' => (int) $event['id'],
                    'title' => $event['title'],
                    'description' => $event['description'] ?? '',
                    'eventDate' => $event['event_date'],
                    'endDate' => $event['end_date'] ?? null,
                    'startTime' => $event['start_time'],
                    'endTime' => $event['end_time'],
                    'venue' => $event['venue'],
                    'bannerImagePath' => $event['banner_image_path'] ?? null,
                    'attendanceRequired' => (bool) $event['attendance_required'],
                    'suspendsClasses' => (bool) ($event['suspends_classes'] ?? false),
                    'scannerIds' => array_values(array_filter(array_map('intval', explode(',', (string) ($event['scanner_ids'] ?? ''))))),
                    'checkInStart' => $event['check_in_start'],
                    'checkInEnd' => $event['check_in_end'],
                    'checkOutRequired' => (bool) $event['check_out_required'],
                    'status' => $event['status'],
                    'audience' => $event['target_type'] ?? 'all_students',
                    'targetId' => isset($event['target_id']) ? (int) $event['target_id'] : null,
                    'targets' => $eventTargetsById[(int) $event['id']] ?? [],
                ];
                $eventEditable = in_array($event['status'], ['draft', 'scheduled'], true);
                ?>
                <tr data-event-row="<?= e($event['id']) ?>">
                    <td><div class="event-table-title"><span class="event-table-banner event-table-placeholder" data-event-row-placeholder aria-hidden="true"<?= !empty($event['banner_image_path']) ? ' hidden' : '' ?>><img src="<?= e(url('/assets/images/default-event-banner.svg')) ?>" alt="" loading="lazy" decoding="async"></span><?php if (!empty($event['banner_image_path'])): ?><img class="event-table-banner" src="<?= e(url($event['banner_image_path'])) ?>" alt="" loading="lazy" data-event-row-banner><?php endif; ?><span class="event-table-copy"><a href="<?= e(\App\Services\EventAccessService::viewUrl((int)$event['id'])) ?>"><strong data-event-row-title><?= e($event['title']) ?></strong></a><span class="muted" data-event-row-description<?= empty($event['description']) ? ' hidden' : '' ?>><?= e($event['description']) ?></span></span></div></td>
                    <td data-event-row-schedule data-order="<?= e(str_replace(['-', ':'], '', $event['event_date'] . substr($event['start_time'], 0, 5))) ?>"><strong><?php require BASE_PATH . '/templates/partials/event_date_range.php'; ?></strong><span class="event-table-time"><?= e(format_time($event['start_time'])) ?> &ndash; <?= e(format_time($event['end_time'])) ?></span></td>
                    <td data-event-row-venue><?= e($event['venue']) ?></td>
                    <td><?= e($event['attended_count'] . ' / ' . $event['participant_count']) ?></td>
                    <td><span class="badge badge-<?= e($event['status']) ?>"><?= e(ucfirst($event['status'])) ?></span></td>
                    <td><div class="table-actions event-table-actions"><a class="button button-small button-secondary" href="<?= e(\App\Services\EventAccessService::viewUrl((int)$event['id'])) ?>">Full event view</a>
                        <button type="button" class="button button-small button-secondary" data-manage-event data-event-id="<?= e($event['id']) ?>" data-event-details="<?= e(json_encode($eventDetails, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) ?>"><?= $eventEditable ? 'Edit details' : 'View details' ?></button>
                        <?php if ($event['status'] !== 'cancelled'): ?><form method="post"><?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="cancel_event"><input type="hidden" name="event_id" value="<?= e($event['id']) ?>"><button class="button button-small button-danger" data-confirm="Cancel this event?">Cancel</button></form><?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
</div>
</div>
</div>

<dialog id="event-details-dialog" class="app-dialog app-dialog-large" data-modal aria-labelledby="event-details-title">
    <div class="app-dialog-shell">
        <header class="app-dialog-header">
            <div><p class="eyebrow">School event</p><h2 id="event-details-title" data-event-dialog-title>Edit event details</h2><p class="muted" data-event-dialog-subtitle>Update the schedule, audience, attendance settings, or banner.</p></div>
            <button type="button" class="dialog-close" data-dialog-close aria-label="Close event details"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button>
        </header>
        <div class="app-dialog-body">
            <form method="post" enctype="multipart/form-data" class="stack" data-event-edit-form data-endpoint="<?= e(url('/admin/calendar-events.php')) ?>">
                <?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="update_event"><input type="hidden" name="event_id">
                <div class="form-grid">
                    <label class="field"><span>Title *</span><input name="title" maxlength="180" required></label>
                    <label class="field"><span>Venue *</span><input name="venue" maxlength="180" required></label>
                    <label class="field"><span>Start date *</span><input type="date" name="event_date" required></label>
                    <label class="field"><span>End date <small>(optional)</small></span><input type="date" name="end_date"><small class="field-help">Leave blank for a one-day event.</small></label>
                    <label class="field"><span>Starts *</span><input type="time" name="start_time" required></label>
                    <label class="field"><span>Ends *</span><input type="time" name="end_time" required></label>
                    <label class="field"><span>Check-in opens</span><input type="time" name="check_in_start"></label>
                    <label class="field"><span>Check-in closes</span><input type="time" name="check_in_end"></label>
                </div>
                <div hidden data-event-target-legacy><select name="audience"><option value="everyone">Everyone</option><option value="all_students">All students</option><option value="all_teachers">All teachers</option><option value="all_staff">All staff</option><option value="section">Selected section</option></select><select name="target_id"><option value="">Select a section</option><?php foreach ($sections as $section): ?><option value="<?= e($section['id']) ?>"><?= e($section['name']) ?></option><?php endforeach; ?></select></div>
                <?php require BASE_PATH . '/templates/partials/event_target_builder.php'; ?>
                <label class="field"><span>Description</span><textarea name="description" maxlength="2000" placeholder="Purpose, reminders, or additional details"></textarea></label>
                <div class="event-banner-editor">
                    <div class="event-banner-previews-container" data-multiple-previews-container hidden></div>
                    <img src="" alt="Current event banner" data-event-banner-preview hidden>
                    <label class="field"><span>Event images & photos (multiple)</span><input type="file" name="event_images[]" multiple accept="image/jpeg,image/png,image/webp" data-multiple-image-preview-input><small class="field-help">Optional JPG, PNG, or WebP up to 2 MB each. A new upload replaces the current photos.</small></label>
                </div>
                <div class="event-setting-row">
                    <label class="form-switch">
                        <input type="checkbox" name="is_announcement" value="1">
                        <span class="switch-slider"></span>
                        <span class="switch-text">Announcement <small class="muted">(Always visible)</small></span>
                    </label>
                    <label class="form-switch">
                        <input type="checkbox" name="attendance_required" data-event-attendance-toggle>
                        <span class="switch-slider"></span>
                        <span class="switch-text">Attendance required</span>
                    </label>
                    <label class="form-switch">
                        <input type="checkbox" name="check_out_required">
                        <span class="switch-slider"></span>
                        <span class="switch-text">Require check-out</span>
                    </label>
                    <label class="form-switch">
                        <input type="checkbox" name="suspends_classes" value="1">
                        <span class="switch-slider"></span>
                        <span class="switch-text">Suspend overlapping classes</span>
                    </label>
                </div>
                <fieldset class="event-scanner-panel" data-event-scanner-panel><legend>Event scanners</legend>
                    <label class="field"><span>Use existing scanners</span><select name="scanner_ids[]" multiple data-searchable><?php foreach ($devices as $device): ?><option value="<?= e($device['id']) ?>"><?= e($device['device_code']) ?> — <?= e($device['name']) ?><?= $device['room_code'] ? ' · ' . e($device['room_code']) : ' · Unassigned' ?></option><?php endforeach; ?></select></label>
                    <details><summary>Add another scanner</summary><div class="form-grid"><label class="field"><span>Device code</span><input name="new_scanner_device_code" maxlength="60"></label><label class="field"><span>Scanner name</span><input name="new_scanner_name" maxlength="120"></label><label class="field"><span>Room</span><select name="new_scanner_room_id" data-searchable><option value="">Unassigned</option><?php foreach ($rooms as $room): ?><option value="<?= e($room['id']) ?>"><?= e($room['room_code']) ?> — <?= e($room['room_name']) ?></option><?php endforeach; ?></select></label></div></details>
                </fieldset>
                <div class="ajax-form-footer"><span class="ajax-form-status" data-event-edit-status aria-live="polite"></span><div class="table-actions"><button type="button" class="button button-secondary" data-dialog-close>Close</button><button type="submit" class="button button-primary">Save changes</button></div></div>
            </form>
        </div>
    </div>
</dialog>

<?php require BASE_PATH.'/templates/partials/event_editor.php'; ?>
