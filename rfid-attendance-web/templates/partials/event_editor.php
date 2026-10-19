<?php
if (!\App\Core\Auth::can('manage_events')) return;
$eventEditorDevices=\App\Core\Database::connection()->query('SELECT id,device_code,name FROM devices WHERE status="active" ORDER BY name,device_code')->fetchAll();
?>
<dialog class="event-editor-dialog" id="event-workspace-editor" aria-labelledby="event-editor-title">
    <form class="event-editor-form" data-event-workspace-editor enctype="multipart/form-data" data-endpoint="<?= e(url('/admin/calendar-events.php')) ?>">
        <?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="create_event"><input type="hidden" name="event_id">
        <header class="event-dialog-heading"><div><p class="eyebrow">Campus activities</p><h2 id="event-editor-title">Create event</h2><p class="muted">Plan the activity, choose participants, and connect your scanners.</p></div><button type="button" class="button button-secondary" data-event-editor-close aria-label="Close event editor">Close</button></header>
        <div class="event-editor-content">
            <section><h3>Event details</h3><div class="form-grid">
                <label class="field"><span>Event title</span><input name="title" maxlength="180" required placeholder="e.g. Department assembly"></label>
                <label class="field"><span>Venue</span><input name="venue" maxlength="180" required placeholder="Building, room, or campus location"></label>
            </div><label class="field"><span>Description</span><textarea name="description" maxlength="2000" rows="3" placeholder="Purpose, programme, and reminders for participants"></textarea></label>
            <div class="event-editor-banner"><img src="<?= e(url('/assets/images/default-event-banner.svg')) ?>" alt="Event image preview" data-workspace-image-preview><label class="field"><span>Banner and photos <small>(optional)</small></span><input type="file" name="event_images[]" multiple accept="image/jpeg,image/png,image/webp" data-workspace-images><small class="field-help">JPG, PNG, or WebP, up to 2 MB each. The first image is the banner. New uploads replace existing images.</small></label></div></section>
            <section><h3>Dates and times</h3><p class="muted">All times use <?= e(config('app')['timezone']) ?>. Multi-day events use this daily attendance window.</p><div class="form-grid">
                <label class="field"><span>Start date</span><input type="date" name="event_date" required></label><label class="field"><span>End date <small>(optional)</small></span><input type="date" name="end_date"><small class="field-help">Leave blank for a single-day event.</small></label>
                <label class="field"><span>Starts at</span><input type="time" name="start_time" required></label><label class="field"><span>Ends at</span><input type="time" name="end_time" required></label>
                <label class="field"><span>Check-in opens <small>(optional)</small></span><input type="time" name="check_in_start"></label><label class="field"><span>Check-in closes <small>(optional)</small></span><input type="time" name="check_in_end"><small class="field-help">Set both or leave both blank to use event times. Checkout remains available until the event ends.</small></label>
            </div></section>
            <section><?php require BASE_PATH.'/templates/partials/event_target_builder.php'; ?></section>
            <section><h3>Attendance and visibility</h3><div class="event-editor-settings">
                <label class="form-switch"><input type="checkbox" name="attendance_required" checked><span class="switch-slider"></span><span class="switch-text">Required attendance<small class="field-help">Selected participants will have a required event.</small></span></label>
                <label class="form-switch"><input type="checkbox" name="check_out_required"><span class="switch-slider"></span><span class="switch-text">Require checkout<small class="field-help">Incomplete checkouts stay visible in attendance.</small></span></label>
                <label class="form-switch"><input type="checkbox" name="is_announcement" value="1"><span class="switch-slider"></span><span class="switch-text">Keep in announcements<small class="field-help">Visible to the selected audience.</small></span></label>
                <?php if (\App\Core\Auth::can('manage_academic_calendar')): ?><label class="form-switch"><input type="checkbox" name="suspends_classes" value="1"><span class="switch-slider"></span><span class="switch-text">Suspend overlapping classes<small class="field-help">Changes the campus attendance calendar.</small></span></label><?php endif; ?>
            </div><label class="field"><span>Connected event scanners</span><select name="scanner_ids[]" multiple data-searchable data-search-placeholder="Search scanners…"><?php foreach($eventEditorDevices as $scanner): ?><option value="<?= (int)$scanner['id'] ?>"><?= e($scanner['name'].' · '.$scanner['device_code']) ?></option><?php endforeach; ?></select><small class="field-help">Use prepared scanners. Overlapping event assignments are checked when you save. A manual school-ID fallback is available in tracking.</small></label></section>
        </div>
        <footer class="event-editor-footer"><p data-event-editor-status role="status" aria-live="polite"></p><div class="table-actions"><button type="button" class="button button-secondary" data-event-editor-close>Cancel</button><button class="button button-primary" type="submit" data-event-editor-submit>Create event</button></div></footer>
    </form>
</dialog>
<?php unset($eventEditorDevices); ?>
