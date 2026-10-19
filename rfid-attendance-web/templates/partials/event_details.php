<dl class="event-detail-list">
    <div>
        <dt><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18"/></svg>Date<?= !empty($event['end_date']) && $event['end_date'] !== $event['event_date'] ? 's' : '' ?></dt>
        <dd><?php require BASE_PATH . '/templates/partials/event_date_range.php'; ?></dd>
    </div>
    <div>
        <dt><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>Time</dt>
        <dd><?= e(format_time($event['start_time'])) ?> &ndash; <?= e(format_time($event['end_time'])) ?></dd>
    </div>
    <div>
        <dt><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s7-6 7-12A7 7 0 1 0 5 9c0 6 7 12 7 12Z"/><circle cx="12" cy="9" r="2"/></svg>Location</dt>
        <dd><?= e(trim((string) ($event['venue'] ?? '')) ?: 'Location to be announced') ?></dd>
    </div>
    <?php if (!empty($event['attendance_required']) && !empty($event['check_in_start']) && !empty($event['check_in_end'])): ?>
        <div>
            <dt><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 4h5v16h-5M3 12h12m-4-4 4 4-4 4"/></svg>Check-in window</dt>
            <dd><?= e(format_time($event['check_in_start'])) ?> &ndash; <?= e(format_time($event['check_in_end'])) ?></dd>
        </div>
    <?php endif; ?>
</dl>
