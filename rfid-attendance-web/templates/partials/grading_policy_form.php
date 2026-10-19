<?php $gSettings = \App\Services\GradeService::settings(); ?>
<form method="post" class="stack grading-policy-form" data-grading-policy-form data-no-ajax data-endpoint="<?= e(url('/grades.php')) ?>">
    <?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="update_grading_settings">
    <p class="campus-policy-note">Applies to every class and role. Disabled periods are excluded from new grade calculations. Existing scores and finalized records are preserved.</p>
    <div class="grading-policy-grid">
        <?php foreach (['prelim' => ['Prelims', 'Early-period evaluation'], 'midterm' => ['Midterms', 'Mid-period evaluation'], 'final' => ['Endterms', 'End-of-period evaluation']] as $term => [$label, $description]): $enabled = (bool) $gSettings['has_' . $term]; ?>
        <fieldset class="grading-term-card <?= $enabled ? 'is-enabled' : 'is-disabled' ?>" data-term-card="<?= e($term) ?>">
            <legend class="sr-only"><?= e($label) ?></legend>
            <div class="grading-term-heading"><strong><?= e($label) ?></strong><label class="grading-term-switch"><input type="checkbox" role="switch" name="has_<?= e($term) ?>" value="1" data-term-toggle="<?= e($term) ?>" aria-label="Enable <?= e($label) ?> university-wide" <?= $enabled ? 'checked' : '' ?>><span aria-hidden="true"></span></label></div>
            <p class="muted"><?= e($description) ?></p>
            <span class="grading-term-state" data-term-toggle-label="<?= e($term) ?>"><?= $enabled ? 'Enabled' : 'Disabled' ?></span>
            <label class="field"><span><?= e($label) ?> weight (%)</span><input type="number" name="<?= e($term) ?>_weight" data-term-weight="<?= e($term) ?>" min="0.01" max="100" step="0.01" value="<?= e($gSettings[$term . '_weight']) ?>" <?= $enabled ? 'required' : 'disabled' ?>></label>
        </fieldset>
        <?php endforeach; ?>
    </div>
    <div class="grading-policy-formula"><span class="eyebrow">Overall grade calculation</span><strong data-grading-formula-preview></strong><span data-grading-weight-sum></span><span class="muted" data-grading-weight-status aria-live="polite"></span></div>
    <div class="grading-policy-footer"><label class="field"><span>Passing score (%)</span><input type="number" name="passing_grade" min="0" max="100" step="0.01" value="<?= e($gSettings['passing_grade']) ?>" required></label><button type="submit" class="button button-primary" data-save-grading-policy>Save grading rules</button></div>
    <p class="ajax-form-feedback" data-grading-policy-feedback aria-live="polite"></p>
</form>
