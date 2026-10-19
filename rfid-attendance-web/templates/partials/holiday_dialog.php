<dialog id="campus-holiday-dialog" class="app-dialog" data-modal aria-labelledby="campus-holiday-title">
    <div class="app-dialog-shell">
        <header class="app-dialog-header">
            <div><p class="eyebrow">Philippine holiday</p><h2 id="campus-holiday-title">Holiday details</h2><p class="muted" data-holiday-date></p></div>
            <button type="button" class="dialog-close" data-dialog-close aria-label="Close holiday details"><svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 5 10 10M15 5 5 15"/></svg></button>
        </header>
        <div class="app-dialog-body">
            <form class="stack" data-holiday-form data-no-ajax>
                <?= \App\Core\Csrf::field() ?><input type="hidden" name="date"><input type="hidden" name="holiday_key">
                <div class="holiday-summary"><span class="badge" data-holiday-type></span><strong data-holiday-policy></strong></div>
                <p class="muted">A national holiday does not automatically change the campus schedule. A saved no-class decision applies to all departments for this date.</p>
                <label class="form-switch" data-holiday-choice><input type="checkbox" name="no_classes" value="1"><span class="switch-slider"></span><span class="switch-text">Campus non-working / no-class day<small>No attendance scans or absence finalization for classes.</small></span></label>
                <p class="inline-notice" data-holiday-access></p>
                <div class="ajax-form-footer"><span class="ajax-form-status" data-holiday-status role="status" aria-live="polite"></span><div class="table-actions"><button type="button" class="button button-secondary" data-dialog-close>Close</button><button type="submit" class="button button-primary" data-holiday-save>Save campus decision</button></div></div>
            </form>
        </div>
    </div>
</dialog>
