<?php
$formationStart=new DateTimeImmutable($item['date']);
$formationEnd=new DateTimeImmutable($item['end_date'] ?? $item['date']);
$formationRange=$formationStart->format('D, M j, Y');
if ($formationEnd->format('Y-m-d')!==$formationStart->format('Y-m-d')) {
    $formationRange=$formationStart->format($formationStart->format('Y')===$formationEnd->format('Y')?'D, M j':'D, M j, Y').' – '.$formationEnd->format('D, M j, Y');
}
?>
<div class="activity-schedule"><strong><?= e($formationRange) ?></strong><span><?= e(format_time($item['start_time'])) ?> &ndash; <?= e(format_time($item['end_time'])) ?></span></div>
<?php unset($formationStart,$formationEnd,$formationRange); ?>
