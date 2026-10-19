<?php
$attendancePolicyDialogId = $attendancePolicyDialogId ?? 'attendance-policy-dialog';
?>
<dialog id="<?= e($attendancePolicyDialogId) ?>" class="app-dialog" data-modal data-attendance-policy-dialog data-endpoint="<?= e(url('/attendance.php')) ?>" aria-labelledby="<?= e($attendancePolicyDialogId) ?>-title">
    <div class="app-dialog-shell">
        <header class="app-dialog-header">
            <div><p class="eyebrow">Subject attendance policy</p><h2 id="<?= e($attendancePolicyDialogId) ?>-title" data-policy-title>Attendance rules</h2><p class="muted" data-policy-subtitle>Loading the effective rules...</p></div>
            <button type="button" class="dialog-close" data-dialog-close aria-label="Close attendance policy"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button>
        </header>
        <div class="app-dialog-body">
            <form class="stack attendance-policy-form" data-attendance-policy-form>
                <?= \App\Core\Csrf::field() ?>
                <input type="hidden" name="action" value="update_subject_attendance_policy">
                <input type="hidden" name="class_offering_id">
                <label class="policy-default-toggle"><input type="checkbox" name="use_campus_default" value="1" data-policy-default> <span><strong>Use campus defaults</strong><small data-policy-campus-summary></small></span></label>
                <div class="attendance-policy-controls" data-policy-controls>
                    <label class="policy-range-field"><span><strong>Early check-in</strong><output data-policy-output="early_check_in_minutes">10 minutes</output></span><input type="range" name="early_check_in_minutes" min="0" max="60" step="1" value="10"><small>Once the prior class is finalized, this subject can scan before its scheduled start.</small></label>
                    <label class="policy-range-field"><span><strong>Present grace period</strong><output data-policy-output="grace_period_minutes">15 minutes</output></span><input type="range" name="grace_period_minutes" min="0" max="120" step="1" value="15"><small>Scans through this many minutes after class starts are Present.</small></label>
                    <label class="policy-range-field"><span><strong>Mark absent at</strong><output data-policy-output="absent_after_minutes">30 minutes</output></span><input type="range" name="absent_after_minutes" min="1" max="240" step="1" value="30"><small>After the grace period and before this point is Late. At this point it becomes Absent.</small></label>
                    <label class="policy-range-field"><span><strong>Tardies per absence</strong><output data-policy-output="tardies_per_absence">3 tardies</output></span><input type="range" name="tardies_per_absence" min="1" max="20" step="1" value="3"><small>Used when calculating compliance deficiencies for this subject.</small></label>
                </div>
                <div class="policy-timeline" aria-live="polite"><span class="is-present" data-policy-present-copy>Present</span><span class="is-late" data-policy-late-copy>Late</span><span class="is-absent" data-policy-absent-copy>Absent</span></div>
                <div class="ajax-form-footer"><span class="ajax-form-status" data-policy-feedback aria-live="polite"></span><div class="form-actions"><button type="button" class="button button-secondary" data-dialog-close>Cancel</button><button class="button button-primary">Save policy</button></div></div>
            </form>
        </div>
    </div>
</dialog>
<?php unset($attendancePolicyDialogId); ?>
