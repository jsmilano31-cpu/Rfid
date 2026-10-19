<?php
$eventStartDate = new DateTimeImmutable($event['event_date']);
$eventEndDate = !empty($event['end_date']) && $event['end_date'] !== $event['event_date']
    ? new DateTimeImmutable($event['end_date']) : null;
$eventStartFormat = $eventEndDate && $eventStartDate->format('Y') === $eventEndDate->format('Y')
    ? 'D, M j' : 'D, M j, Y';
?>
<time datetime="<?= e($event['event_date']) ?>"><?= e($eventStartDate->format($eventStartFormat)) ?></time><?php if ($eventEndDate): ?> &ndash; <time datetime="<?= e($event['end_date']) ?>"><?= e($eventEndDate->format('D, M j, Y')) ?></time><?php endif; ?>
