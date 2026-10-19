<?php
if (!\App\Core\PermissionRegistry::canViewCampusCalendar()) return;
$canManageEvents = \App\Core\Auth::usesAdminArea() && \App\Core\Auth::can('manage_events');
$canManageRecollections = \App\Core\Auth::usesAdminArea() && \App\Core\Auth::can('manage_recollections');
$canManageTimeOff = \App\Core\Auth::hasRole('Teacher') && \App\Core\Auth::can('manage_teacher_time_off');
$calendarAdminScope = str_starts_with((string) (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''), '/admin/');
$calendarEndpoint = url('/admin/calendar-events.php' . ($calendarAdminScope ? '?scope=admin' : ''));
$calendar_devices = $calendar_devices ?? [];
$calendar_rooms = $calendar_rooms ?? [];
?>
<section class="section dashboard-calendar-section section-scroll-target" id="dashboard-campus-calendar" data-section-target>
    <div class="section-heading dashboard-calendar-heading">
        <div><h2>Campus calendar</h2><p class="muted">Philippine holidays, campus activities, and scheduling decisions.</p></div>
        <div class="calendar-heading-actions"><?php if ($canManageTimeOff): ?><button type="button" class="button button-secondary" data-calendar-create-time-off><svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="M4 6.5h12v10H4zM6.5 3.5v5M13.5 3.5v5M4 9.5h12"/></svg>Schedule time off</button><?php endif; ?><?php if ($canManageEvents): ?><button type="button" class="button button-primary" data-calendar-create-event><svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 4v12M4 10h12"/></svg>Create event</button><?php endif; ?></div>
    </div>
    <div class="dashboard-calendar-layout">
        <article class="card calendar-card">
            <div class="calendar-toolbar" aria-label="Calendar navigation">
                <div class="calendar-navigation">
                    <button type="button" class="calendar-nav-button" data-calendar-previous aria-label="Previous month"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m12.5 5-5 5 5 5"/></svg></button>
                    <button type="button" class="calendar-today-button" data-calendar-today>Today</button>
                    <button type="button" class="calendar-nav-button" data-calendar-next aria-label="Next month"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m7.5 5 5 5-5 5"/></svg></button>
                </div>
                <strong class="calendar-month-label" data-calendar-month-label>Calendar</strong>
                <div class="calendar-legend calendar-toolbar-legend" aria-label="Calendar categories"><span><i class="legend-dot legend-event"></i>Events</span><span><i class="legend-dot legend-holiday"></i>Holidays</span><span><i class="legend-dot legend-recollection"></i>Formation</span><?php if ($canManageTimeOff || \App\Core\Auth::hasRole('Student')): ?><span><i class="legend-dot legend-time-off"></i>Time off</span><?php endif; ?></div>
            </div>
            <div id="campus-calendar" data-dashboard-calendar data-endpoint="<?= e($calendarEndpoint) ?>" data-can-manage-events="<?= $canManageEvents ? '1' : '0' ?>" data-can-manage-recollections="<?= $canManageRecollections ? '1' : '0' ?>" data-can-manage-time-off="<?= $canManageTimeOff ? '1' : '0' ?>"></div>
            <div class="calendar-feedback muted" data-calendar-feedback aria-live="polite"></div>
        </article>
        <aside class="card calendar-agenda">
            <div class="calendar-agenda-heading"><p class="eyebrow">Day agenda</p><h3 data-calendar-agenda-date>Select a date</h3></div>
            <div class="calendar-agenda-list" data-calendar-agenda-list><p class="calendar-agenda-empty">Loading schedule…</p></div>
            <?php if ($canManageEvents): ?><button type="button" class="button button-secondary button-block" data-calendar-create-selected>Create event on this date</button><?php endif; ?>
            <details class="calendar-policy-details">
                <summary>Weekly scheduling rules</summary>
                <?php if ($canManageTimeOff): ?><p>Right-click a date to mark that teaching day as time off. Use “Schedule time off” for a date range.</p><?php elseif ($canManageEvents): ?><p>Double-click a date to create an event quickly. Click any calendar entry to view or manage it.</p><?php else: ?><p>Click any calendar entry to review its details.</p><?php endif; ?>
                <div class="department-policy-list">
                    <?php foreach ($department_day_policies as $policy): ?>
                        <div class="department-policy-item"><strong><span class="department-color-dot department-color-<?= e($policy['color_key']) ?>" aria-hidden="true"></span><?= e($policy['code']) ?></strong><span><?= $policy['days_off'] ? e(implode(', ', $policy['days_off'])) . ' off' : 'No regular days off' ?></span></div>
                    <?php endforeach; ?>
                </div>
                <p class="calendar-note">Saturday is treated as a valid class day whenever a class is scheduled and the department has not marked it off.</p>
            </details>

            <?php if (count(array_filter([$students ?? null, $teachers ?? null, $staff ?? null, $rfid_cards ?? null, $rooms ?? null, $upcoming_events ?? null, $upcoming_recollections ?? null, $deficient_students ?? null], static fn($value): bool => $value !== null)) > 0): ?>
                <div class="calendar-snapshot-section" id="dashboard-campus-snapshot">
                    <div class="calendar-snapshot-header">
                        <p class="eyebrow" style="margin:0;">Campus snapshot</p>
                        <span class="muted" style="font-size:11px; font-weight:600;">Live totals</span>
                    </div>
                    <div class="calendar-snapshot-grid">
                        <?php if (isset($students)): ?><article class="snapshot-card-mini" style="--icon-bg:#eff4ff; --icon-color:#175cd3;">
                            <div class="snapshot-mini-icon">
                                <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 2 2 6l8 4 8-4-8-4Z"/><path d="m4 8.5v5c0 2 2.7 3.5 6 3.5s6-1.5 6-3.5v-5"/><path d="M18 7v6"/></svg>
                            </div>
                            <div class="snapshot-mini-info">
                                <span>Students</span>
                                <strong><?= e($students) ?></strong>
                            </div>
                        </article><?php endif; ?>
                        <?php if (isset($teachers)): ?><article class="snapshot-card-mini" style="--icon-bg:#edfbf2; --icon-color:#067647;">
                            <div class="snapshot-mini-icon">
                                <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="6" r="3.5"/><path d="M4 17.5a6 6 0 0 1 12 0"/><path d="M15 3h3v5h-3"/></svg>
                            </div>
                            <div class="snapshot-mini-info">
                                <span>Teachers</span>
                                <strong><?= e($teachers) ?></strong>
                            </div>
                        </article><?php endif; ?>
                        <?php if (isset($staff)): ?><article class="snapshot-card-mini" style="--icon-bg:#f2f4f7; --icon-color:#475467;">
                            <div class="snapshot-mini-icon">
                                <svg viewBox="0 0 20 20" aria-hidden="true"><rect x="3" y="6" width="14" height="11" rx="2"/><path d="M7 6V4a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2"/><path d="M3 11h14"/></svg>
                            </div>
                            <div class="snapshot-mini-info">
                                <span>Staff</span>
                                <strong><?= e($staff) ?></strong>
                            </div>
                        </article><?php endif; ?>
                        <?php if (isset($rfid_cards)): ?><article class="snapshot-card-mini" style="--icon-bg:#ecfeff; --icon-color:#0891b2;">
                            <div class="snapshot-mini-icon">
                                <svg viewBox="0 0 20 20" aria-hidden="true"><rect x="2.5" y="4.5" width="15" height="11" rx="2"/><path d="M2.5 8.5h15"/><path d="M6 12.5h3"/></svg>
                            </div>
                            <div class="snapshot-mini-info">
                                <span>RFID cards</span>
                                <strong><?= e($rfid_cards) ?></strong>
                            </div>
                        </article><?php endif; ?>
                        <?php if (isset($rooms)): ?><article class="snapshot-card-mini" style="--icon-bg:#f4f3ff; --icon-color:#7c3aed;">
                            <div class="snapshot-mini-icon">
                                <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 18V4a1 1 0 0 1 1-1h12a1 1 0 0 1 1 1v14"/><path d="M8 18V9h4v9"/><circle cx="11" cy="13" r="0.75"/></svg>
                            </div>
                            <div class="snapshot-mini-info">
                                <span>Rooms</span>
                                <strong><?= e($rooms) ?></strong>
                            </div>
                        </article><?php endif; ?>
                        <?php if (isset($upcoming_events)): ?><article class="snapshot-card-mini" style="--icon-bg:#fffbeb; --icon-color:#d97706;">
                            <div class="snapshot-mini-icon">
                                <svg viewBox="0 0 20 20" aria-hidden="true"><rect x="3" y="4" width="14" height="13" rx="2"/><path d="M3 8.5h14M7 2.5v3M13 2.5v3"/></svg>
                            </div>
                            <div class="snapshot-mini-info">
                                <span>Events</span>
                                <strong><?= e($upcoming_events) ?></strong>
                            </div>
                        </article><?php endif; ?>
                        <?php if (isset($upcoming_recollections)): ?><article class="snapshot-card-mini" style="--icon-bg:#fff7ed; --icon-color:#ea580c;">
                            <div class="snapshot-mini-icon">
                                <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M10 3.5a4.5 4.5 0 0 0-4.5 4.5c0 3.5 4.5 8.5 4.5 8.5s4.5-5 4.5-8.5A4.5 4.5 0 0 0 10 3.5Z"/><circle cx="10" cy="8" r="2"/></svg>
                            </div>
                            <div class="snapshot-mini-info">
                                <span>Recollections</span>
                                <strong><?= e($upcoming_recollections) ?></strong>
                            </div>
                        </article><?php endif; ?>
                        <?php if (isset($deficient_students)): ?><article class="snapshot-card-mini" style="--icon-bg:#fef3f2; --icon-color:#b42318;">
                            <div class="snapshot-mini-icon">
                                <svg viewBox="0 0 20 20" aria-hidden="true"><path d="m10 3 8 14H2L10 3Z"/><path d="M10 8.5v4M10 14.5h.01"/></svg>
                            </div>
                            <div class="snapshot-mini-info">
                                <span>Deficiencies</span>
                                <strong style="color:<?= (int)$deficient_students > 0 ? 'var(--danger)' : 'inherit' ?>;"><?= e($deficient_students) ?></strong>
                            </div>
                        </article><?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </aside>
    </div>
</section>

<dialog id="calendar-event-dialog" class="app-dialog app-dialog-large" data-modal aria-labelledby="calendar-event-title">
    <div class="app-dialog-shell">
        <header class="app-dialog-header">
            <div><p class="eyebrow">Campus calendar</p><h2 id="calendar-event-title" data-calendar-dialog-title>Create event</h2><p class="muted" data-calendar-dialog-subtitle>Schedule an activity and assign its attendance audience.</p></div>
            <button type="button" class="dialog-close" data-dialog-close aria-label="Close event dialog"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button>
        </header>
        <div class="app-dialog-body">
            <form method="post" enctype="multipart/form-data" class="stack" data-calendar-event-form data-endpoint="<?= e($calendarEndpoint) ?>" data-can-manage="<?= $canManageEvents ? '1' : '0' ?>">
                <?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="create_event"><input type="hidden" name="event_id">
                <div class="form-grid">
                    <label class="field"><span>Title *</span><input name="title" maxlength="180" required></label>
                    <label class="field"><span>Venue *</span><input name="venue" maxlength="180" required></label>
                    <label class="field"><span>Start date *</span><input type="date" name="event_date" required></label>
                    <label class="field"><span>End date <small>(optional)</small></span><input type="date" name="end_date"><small class="field-help">Leave blank for a one-day event.</small></label>
                    <label class="field"><span>Starts *</span><input type="time" name="start_time" value="09:00" required></label>
                    <label class="field"><span>Ends *</span><input type="time" name="end_time" value="11:00" required></label>
                    <label class="field"><span>Check-in opens</span><input type="time" name="check_in_start" value="08:30"></label>
                    <label class="field"><span>Check-in closes</span><input type="time" name="check_in_end" value="09:30"></label>
                </div>
                <div hidden data-event-target-legacy><select name="audience"><option value="everyone">Everyone</option><option value="all_students">All students</option><option value="all_teachers">All teachers</option><option value="all_staff">All staff</option><option value="section">Selected section</option></select><select name="target_id"><option value="">Select a section</option><?php foreach ($calendar_sections as $section): ?><option value="<?= e($section['id']) ?>"><?= e($section['name']) ?></option><?php endforeach; ?></select></div>
                <?php require BASE_PATH . '/templates/partials/event_target_builder.php'; ?>
                <label class="field"><span>Description</span><textarea name="description" maxlength="2000" placeholder="Purpose, reminders, or additional details"></textarea></label>
                <div class="event-banner-editor">
                    <div class="event-banner-previews-container" data-multiple-previews-container hidden></div>
                    <img src="" alt="Current event banner" data-event-banner-preview hidden>
                    <label class="field"><span>Event images & photos (multiple)</span><input type="file" name="event_images[]" multiple accept="image/jpeg,image/png,image/webp" data-multiple-image-preview-input><small class="field-help">Optional JPG, PNG, or WebP up to 2 MB each. The first photo is the main featured cover.</small></label>
                </div>
                <div class="event-setting-row">
                    <label class="form-switch">
                        <input type="checkbox" name="is_announcement" value="1" data-announcement-toggle>
                        <span class="switch-slider"></span>
                        <span class="switch-text">Announcement <small class="muted">(Always visible)</small></span>
                    </label>
                    <label class="form-switch">
                        <input type="checkbox" name="attendance_required" checked data-event-attendance-toggle>
                        <span class="switch-slider"></span>
                        <span class="switch-text">Attendance required</span>
                    </label>
                    <label class="form-switch">
                        <input type="checkbox" name="check_out_required">
                        <span class="switch-slider"></span>
                        <span class="switch-text">Require check-out</span>
                    </label>
                    <label class="form-switch"><input type="checkbox" name="suspends_classes" value="1"><span class="switch-slider"></span><span class="switch-text">Suspend overlapping classes</span></label>
                </div>
                <fieldset class="event-scanner-panel" data-event-scanner-panel><legend>Event scanners</legend>
                    <label class="field"><span>Use existing scanners</span><select name="scanner_ids[]" multiple data-searchable><?php foreach ($calendar_devices as $device): ?><option value="<?= e($device['id']) ?>"><?= e($device['device_code']) ?> — <?= e($device['name']) ?><?= $device['room_code'] ? ' · ' . e($device['room_code']) : ' · Unassigned' ?></option><?php endforeach; ?></select><small class="field-help">Choose multiple scanners for high-volume entry.</small></label>
                    <details><summary>Add a new scanner</summary><div class="form-grid"><label class="field"><span>Device code</span><input name="new_scanner_device_code" maxlength="60"></label><label class="field"><span>Name</span><input name="new_scanner_name" maxlength="120"></label><label class="field"><span>Room</span><select name="new_scanner_room_id" data-searchable><option value="">Unassigned</option><?php foreach ($calendar_rooms as $room): ?><option value="<?= e($room['id']) ?>"><?= e($room['room_code']) ?> — <?= e($room['room_name']) ?></option><?php endforeach; ?></select></label></div></details>
                </fieldset>
                <div class="ajax-form-footer"><span class="ajax-form-status" data-calendar-form-status aria-live="polite"></span><div class="table-actions"><button type="button" class="button button-danger" data-calendar-cancel-event hidden>Cancel event</button><button type="button" class="button button-secondary" data-dialog-close>Close</button><button type="submit" class="button button-primary">Create event</button></div></div>
            </form>
        </div>
    </div>
</dialog>

<dialog id="calendar-time-off-dialog" class="app-dialog" data-modal aria-labelledby="calendar-time-off-title">
    <div class="app-dialog-shell">
        <header class="app-dialog-header">
            <div><p class="eyebrow">Teacher availability</p><h2 id="calendar-time-off-title" data-time-off-dialog-title>Schedule time off</h2><p class="muted" data-time-off-dialog-subtitle>Classes you teach in this range will be suspended, and students will not be marked absent.</p></div>
            <button type="button" class="dialog-close" data-dialog-close aria-label="Close time-off dialog"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button>
        </header>
        <div class="app-dialog-body">
            <form method="post" class="stack" data-calendar-time-off-form data-endpoint="<?= e($calendarEndpoint) ?>" data-can-manage="<?= $canManageTimeOff ? '1' : '0' ?>">
                <?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="save_teacher_time_off"><input type="hidden" name="time_off_id">
                <div class="form-grid">
                    <label class="field"><span>Starts on *</span><input type="date" name="starts_on" required></label>
                    <label class="field"><span>Ends on <small>(optional)</small></span><input type="date" name="ends_on"><small class="field-help">Leave blank for one teaching day.</small></label>
                </div>
                <label class="field"><span>Reason <small>(optional)</small></span><textarea name="reason" maxlength="255" placeholder="Conference, medical leave, official business, or another note"></textarea></label>
                <div class="inline-notice is-warning"><strong>Attendance protection</strong><span>Scheduled classes in this period cannot accept scans or be finalized as absent.</span></div>
                <div class="ajax-form-footer"><span class="ajax-form-status" data-time-off-form-status aria-live="polite"></span><div class="table-actions"><button type="button" class="button button-danger" data-calendar-cancel-time-off hidden>Remove time off</button><button type="button" class="button button-secondary" data-dialog-close>Close</button><button type="submit" class="button button-primary">Schedule time off</button></div></div>
            </form>
        </div>
    </div>
</dialog>

<dialog id="calendar-recollection-dialog" class="app-dialog app-dialog-large" data-modal aria-labelledby="calendar-recollection-title">
    <div class="app-dialog-shell">
        <header class="app-dialog-header">
            <div><p class="eyebrow">Formation activity</p><h2 id="calendar-recollection-title" data-recollection-dialog-title>Recollection details</h2><p class="muted" data-recollection-dialog-subtitle>Review or update this recollection or retreat.</p></div>
            <button type="button" class="dialog-close" data-dialog-close aria-label="Close activity dialog"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button>
        </header>
        <div class="app-dialog-body">
            <form method="post" class="stack" data-calendar-recollection-form data-endpoint="<?= e($calendarEndpoint) ?>" data-can-manage="<?= $canManageRecollections ? '1' : '0' ?>">
                <?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="update_recollection"><input type="hidden" name="recollection_id">
                <div class="form-grid">
                    <label class="field"><span>Title *</span><input name="title" maxlength="180" required></label>
                    <label class="field"><span>Type *</span><select name="type" data-no-search required><option>Recollection</option><option>Retreat</option></select></label>
                    <label class="field"><span>Section *</span><select name="section_id" required><option value="">Select a section</option><?php foreach ($calendar_sections as $section): ?><option value="<?= e($section['id']) ?>"><?= e($section['name']) ?></option><?php endforeach; ?></select></label>
                    <label class="field"><span>Facilitator</span><input name="facilitator" maxlength="180"></label>
                </div>
                <?php require BASE_PATH . '/templates/partials/recollection_schedule_fields.php'; ?>
                <div class="event-setting-row"><label><input type="checkbox" name="attendance_required"> Attendance required</label></div>
                <div class="ajax-form-footer"><span class="ajax-form-status" data-recollection-form-status aria-live="polite"></span><div class="table-actions"><button type="button" class="button button-danger" data-calendar-cancel-recollection hidden>Cancel activity</button><button type="button" class="button button-secondary" data-dialog-close>Close</button><button type="submit" class="button button-primary">Save changes</button></div></div>
            </form>
        </div>
    </div>
</dialog>
