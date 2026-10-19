<?php
$seatPlanContext = $seatPlanContext ?? 'shared';
$seatPlanDialogId = 'seat-plan-preset-' . $seatPlanContext;
$seatPlanManageDialogId = 'seat-plan-manage-preset-' . $seatPlanContext;
?>
<section class="card seat-plan-workspace" data-seat-plan-editor data-endpoint="<?= e(url('/seat-plans.php')) ?>">
    <div class="seat-plan-heading">
        <div><p class="eyebrow">Interactive room layout</p><h2>Seat Plans</h2><p class="muted">Paint seats and room fixtures onto a reusable grid. Transparent cells remain open floor space.</p></div>
        <div class="seat-plan-heading-actions"><button type="button" class="button button-secondary" data-seat-plan-new><svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="M10 3v14M3 10h14"/></svg>New blank</button><button type="button" class="button button-primary" data-seat-plan-save><svg class="button-icon" viewBox="0 0 20 20" aria-hidden="true"><path d="M4 3h10l2 2v12H4zM7 3v5h6V3M7 13h6"/></svg>Save plan</button></div>
    </div>
    <div class="seat-plan-status" data-seat-plan-status role="status">Loading rooms and presets…</div>
    <div class="seat-plan-layout" data-seat-plan-content hidden>
        <aside class="seat-plan-controls">
            <div class="seat-plan-control-group"><h3>Plan setup</h3>
                <label class="field"><span>Room</span><select data-seat-plan-room><option value="">Select a room</option></select></label>
                <label class="field"><span>Plan name</span><input data-seat-plan-name maxlength="120" placeholder="Room seat plan"></label>
                <div class="form-grid"><label class="field"><span>Rows</span><input type="number" min="1" max="20" value="10" data-seat-plan-rows></label><label class="field"><span>Columns</span><input type="number" min="1" max="20" value="10" data-seat-plan-columns></label></div>
            </div>
            <div class="seat-plan-control-group"><h3>Preset</h3>
                <label class="field"><span>Available presets</span><select data-seat-plan-preset><option value="">Choose a preset</option></select></label>
                <div class="form-actions"><button type="button" class="button button-secondary button-block" data-seat-plan-apply-preset disabled>Apply preset</button><button type="button" class="button button-secondary button-block" data-seat-plan-manage-preset disabled>Manage preset</button><button type="button" class="button button-ghost button-block" data-dialog-open="#<?= e($seatPlanDialogId) ?>">Save current as preset</button></div>
            </div>
            <div class="seat-plan-control-group"><h3>Drawing tool</h3>
                <div class="seat-plan-tools" role="group" aria-label="Seat plan drawing tool">
                    <button type="button" class="is-active" data-seat-plan-tool="student_seat" aria-pressed="true"><span class="seat-tool-swatch is-student"></span>Student seat</button>
                    <button type="button" data-seat-plan-tool="teacher_table" aria-pressed="false"><span class="seat-tool-swatch is-teacher"></span>Teacher table</button>
                    <button type="button" data-seat-plan-tool="label" aria-pressed="false"><span class="seat-tool-swatch is-label"></span>Label</button>
                </div>
                <label class="field"><span>Cell label</span><input data-seat-plan-label maxlength="80" value="Student Seat"><small class="field-help">Used for newly activated cells. Existing cells can be removed by clicking or dragging over them.</small></label>
            </div>
            <div class="seat-plan-legend"><span><i class="is-student"></i>Student seat</span><span><i class="is-teacher"></i>Teacher table</span><span><i class="is-label"></i>Custom label</span><span><i></i>Open space</span></div>
        </aside>
        <div class="seat-plan-canvas-card">
            <div class="seat-plan-front"><span>Front of room</span><svg viewBox="0 0 24 16" aria-hidden="true"><path d="M2 2h20v10H2zM8 15h8M12 12v3"/></svg></div>
            <div class="seat-plan-scroll"><div class="seat-plan-grid" data-seat-plan-grid role="grid" aria-label="Interactive classroom seat plan"></div></div>
            <div class="seat-plan-canvas-footer"><span data-seat-plan-summary>0 active cells · 10 × 10 grid</span><span>Click or drag to toggle cells. Use Shift + arrow keys to move across the grid.</span></div>
        </div>
    </div>
</section>

<dialog id="<?= e($seatPlanDialogId) ?>" class="app-dialog" data-modal data-seat-plan-preset-dialog aria-labelledby="<?= e($seatPlanDialogId) ?>-title">
    <div class="app-dialog-shell"><header class="app-dialog-header"><div><p class="eyebrow">Reusable layout</p><h2 id="<?= e($seatPlanDialogId) ?>-title">Create seat-plan preset</h2><p class="muted">Save the current grid so it can be applied to another classroom.</p></div><button type="button" class="dialog-close" data-dialog-close aria-label="Close"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button></header>
        <div class="app-dialog-body"><form class="stack" data-seat-plan-preset-form><?= \App\Core\Csrf::field() ?><label class="field"><span>Preset name</span><input name="preset_name" maxlength="120" required placeholder="e.g. Standard lecture room"></label><label class="field" data-seat-plan-visibility-field><span>Who can use it?</span><select name="visibility"><option value="campus">Everyone on campus</option><option value="personal">Only me</option></select></label><p class="field-help" data-seat-plan-personal-note hidden>Teacher-created presets are personal. Campus presets are managed by administrators.</p><div class="ajax-form-footer"><span class="ajax-form-status" data-seat-plan-preset-feedback aria-live="polite"></span><div class="form-actions"><button type="button" class="button button-secondary" data-dialog-close>Cancel</button><button type="submit" class="button button-primary">Create preset</button></div></div></form></div>
    </div>
</dialog>
<dialog id="<?= e($seatPlanManageDialogId) ?>" class="app-dialog" data-modal data-seat-plan-manage-preset-dialog aria-labelledby="<?= e($seatPlanManageDialogId) ?>-title"><div class="app-dialog-shell"><header class="app-dialog-header"><div><p class="eyebrow">Reusable layout</p><h2 id="<?= e($seatPlanManageDialogId) ?>-title">Manage preset</h2><p class="muted">Rename, share, or remove this reusable layout. Existing room plans remain saved.</p></div><button type="button" class="dialog-close" data-dialog-close aria-label="Close"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button></header><div class="app-dialog-body"><form class="stack" data-seat-plan-manage-preset-form><?= \App\Core\Csrf::field() ?><input type="hidden" name="preset_id"><label class="field"><span>Preset name</span><input name="name" maxlength="120" required></label><label class="field" data-seat-plan-manage-visibility><span>Who can use it?</span><select name="visibility"><option value="campus">Everyone on campus</option><option value="personal">Only me</option></select></label><p class="field-help" data-seat-plan-manage-owner></p><div class="ajax-form-footer"><span class="ajax-form-status" data-seat-plan-manage-feedback></span><div class="form-actions"><button type="button" class="button button-danger" data-seat-plan-delete-preset>Delete preset</button><button type="button" class="button button-secondary" data-dialog-close>Cancel</button><button type="submit" class="button button-primary">Save preset</button></div></div></form></div></div></dialog>
<?php unset($seatPlanContext, $seatPlanDialogId, $seatPlanManageDialogId); ?>
