<section class="page-header">
    <div>
        <p class="eyebrow">Deletion dependency</p>
        <h1><?= e($record['title'] ?? 'Linked record unavailable') ?></h1>
        <p><?= e($record['subtitle'] ?? 'This linked record cannot be opened.') ?></p>
    </div>
    <button type="button" class="button button-secondary" onclick="window.close()">Close tab</button>
</section>

<?php if (isset($error)): ?>
    <div class="alert alert-danger" role="alert"><?= e($error) ?></div>
<?php else: ?>
    <section class="card stack deletion-record-view">
        <div>
            <p class="eyebrow">Why this is listed</p>
            <p><?= e($record['explanation']) ?></p>
        </div>
        <dl class="deletion-record-details">
            <?php foreach ($record['details'] as $label => $value): ?>
                <div>
                    <dt><?= e((string) $label) ?></dt>
                    <dd><?= $value === null || $value === '' ? '—' : nl2br(e((string) $value)) ?></dd>
                </div>
            <?php endforeach; ?>
        </dl>
    </section>
<?php endif; ?>
