<?php
$statusIconValue = strtolower(trim((string) ($statusIconValue ?? 'unknown')));
$statusIconLabel = ucfirst(str_replace('_', ' ', $statusIconValue));
$statusIconPositive = in_array($statusIconValue, ['active', 'open', 'scheduled'], true);
?>
<span class="status-symbol status-symbol-<?= $statusIconPositive ? 'positive' : 'negative' ?>" role="img" aria-label="Status: <?= e($statusIconLabel) ?>" title="<?= e($statusIconLabel) ?>">
    <?php if ($statusIconPositive): ?>
        <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7.5"/><path d="m6.5 10 2.2 2.3 4.8-5"/></svg>
    <?php else: ?>
        <svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7.5"/><path d="m7.2 7.2 5.6 5.6M12.8 7.2l-5.6 5.6"/></svg>
    <?php endif; ?>
</span>
<?php unset($statusIconValue, $statusIconLabel, $statusIconPositive); ?>
