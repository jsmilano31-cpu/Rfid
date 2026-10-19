<?php
$liveSeatPlanContext = $liveSeatPlanContext ?? 'shared';
$liveSeatPlanMode = $liveSeatPlanMode ?? 'dashboard';
$liveSeatPlanDialogId = 'live-seat-assignment-' . $liveSeatPlanContext;
$liveSeatPlanConfigureUrl = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/admin') && \App\Core\Auth::can('manage_rooms')
    ? url('/admin/?page=rooms#rooms-seat-plans')
    : url('/index/?page=seat-plans');
if (!\App\Core\Auth::can('manage_seat_plans')) $liveSeatPlanConfigureUrl = '';
$liveSeatPlanRoomUrl = str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/admin') && \App\Core\PermissionRegistry::canAll(['manage_academic_structure','manage_teacher_assignments'])
    ? url('/admin/?page=academics#class-offerings')
    : '';
?>
<section class="card live-seat-plan" data-live-seat-plan data-live-seat-plan-mode="<?= e($liveSeatPlanMode) ?>" data-offering-id="<?= (int)($liveSeatPlanOfferingId ?? 0) ?>" data-attendance-date="<?= e(date('Y-m-d')) ?>" data-endpoint="<?= e(url('/attendance.php')) ?>" data-configure-url="<?= e($liveSeatPlanConfigureUrl) ?>" data-room-url="<?= e($liveSeatPlanRoomUrl) ?>">
    <header class="live-seat-plan-header">
        <div class="live-seat-plan-heading"><span class="live-seat-plan-live-dot" aria-hidden="true"></span><div><p class="eyebrow" data-live-seat-eyebrow><?= $liveSeatPlanMode === 'dashboard' ? 'Teaching now' : ($liveSeatPlanMode === 'subject' ? 'Prepared classroom' : 'Live classroom') ?></p><h2 data-live-seat-title><?= $liveSeatPlanMode === 'dashboard' ? 'Checking your schedule…' : ($liveSeatPlanMode === 'subject' ? 'Loading prepared seat plan?' : 'Select a subject below') ?></h2><p class="muted" data-live-seat-description><?= $liveSeatPlanMode === 'dashboard' ? 'Finding your current or next assigned class.' : ($liveSeatPlanMode === 'subject' ? 'Review the room layout and assign enrolled students to seats.' : 'Open a subject to view its room and live seat occupancy.') ?></p></div></div>
        <div class="live-seat-plan-actions" data-live-seat-actions hidden><span class="live-seat-plan-count" data-live-seat-count></span><button type="button" class="button button-secondary button-small" data-live-seat-configure-assignments <?= \App\Core\PermissionRegistry::canAssignSeats() ? '' : 'hidden disabled' ?>>Assign seats</button></div>
    </header>
    <div class="live-seat-plan-body" data-live-seat-body>
        <div class="live-seat-plan-loading" data-live-seat-loading><span></span><p>Loading classroom…</p></div>
    </div>
    <footer class="live-seat-plan-footer" data-live-seat-footer hidden><span><i class="is-checked-in"></i>Checked in</span><span><i class="is-late"></i>Late</span><span><i class="is-waiting"></i>Awaiting scan</span><span><i class="is-unassigned"></i>Unassigned seat</span><span data-live-seat-updated></span></footer>
</section>

<dialog id="<?= e($liveSeatPlanDialogId) ?>" class="app-dialog" data-modal data-live-seat-assignment-dialog>
    <div class="app-dialog-shell"><header class="app-dialog-header"><div><p class="eyebrow">Class seating</p><h2>Assign a student</h2><p class="muted" data-live-seat-dialog-seat>Select an enrolled student for this seat.</p></div><button type="button" class="dialog-close" data-dialog-close aria-label="Close seat assignment"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button></header>
        <div class="app-dialog-body"><form class="stack" data-live-seat-assignment-form><?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="assign_class_seat"><input type="hidden" name="class_offering_id"><input type="hidden" name="seat_plan_id"><input type="hidden" name="grid_row"><input type="hidden" name="grid_column"><input type="hidden" name="student_id">
            <label class="field"><span>Find an enrolled student</span><span class="live-seat-student-search"><svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5"/><path d="m13 13 4 4"/></svg><input type="search" data-live-seat-student-search autocomplete="off" placeholder="Type a school ID or name"></span><small class="field-help">Results are limited to this class roster and load in small batches.</small></label>
            <div class="live-seat-student-results" data-live-seat-student-results><p class="empty-state">Start typing or choose from the first enrolled students.</p></div>
            <div class="ajax-form-footer"><span class="ajax-form-status" data-live-seat-feedback></span><div class="form-actions"><button type="button" class="button button-danger" data-live-seat-unassign hidden>Clear seat</button><button type="button" class="button button-secondary" data-dialog-close>Cancel</button><button type="submit" class="button button-primary" data-live-seat-assign disabled>Assign student</button></div></div>
        </form></div>
    </div>
</dialog>
<?php unset($liveSeatPlanContext, $liveSeatPlanMode, $liveSeatPlanOfferingId, $liveSeatPlanDialogId, $liveSeatPlanConfigureUrl, $liveSeatPlanRoomUrl); ?>
