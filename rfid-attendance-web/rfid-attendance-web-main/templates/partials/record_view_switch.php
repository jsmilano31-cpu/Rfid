<?php
$recordViewTarget = $recordViewTarget ?? '';
$recordViewLabel = $recordViewLabel ?? 'Display format';
?>
<div class="view-switch" data-record-view-switcher data-view-target="<?= e($recordViewTarget) ?>" role="group" aria-label="<?= e($recordViewLabel) ?>">
    <button type="button" class="is-active" data-record-view="cards" aria-controls="<?= e($recordViewTarget) ?>" aria-pressed="true" title="Card view">
        <svg viewBox="0 0 20 20" aria-hidden="true"><rect x="3" y="3" width="5.5" height="5.5" rx="1"/><rect x="11.5" y="3" width="5.5" height="5.5" rx="1"/><rect x="3" y="11.5" width="5.5" height="5.5" rx="1"/><rect x="11.5" y="11.5" width="5.5" height="5.5" rx="1"/></svg>
        <span class="sr-only">Card view</span>
    </button>
    <button type="button" data-record-view="list" aria-controls="<?= e($recordViewTarget) ?>" aria-pressed="false" title="List view">
        <svg viewBox="0 0 20 20" aria-hidden="true"><path d="M7 5h10M7 10h10M7 15h10"/><circle cx="3.5" cy="5" r=".7"/><circle cx="3.5" cy="10" r=".7"/><circle cx="3.5" cy="15" r=".7"/></svg>
        <span class="sr-only">List view</span>
    </button>
</div>
<?php unset($recordViewTarget, $recordViewLabel); ?>
