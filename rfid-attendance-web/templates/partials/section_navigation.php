<?php
$sectionNavigation = $sectionNavigation ?? [];
$sectionNavigationLabel = $sectionNavigationLabel ?? 'Page sections';
?>
<aside class="section-navigation-panel" aria-label="<?= e($sectionNavigationLabel) ?>">
    <p class="section-navigation-kicker">On this page</p>
    <nav class="section-navigation-links">
        <?php foreach ($sectionNavigation as $index => $item): ?>
            <a class="section-navigation-link <?= $index === 0 ? 'is-active' : '' ?>"
               href="#<?= e($item['id']) ?>"
               <?= $index === 0 ? 'aria-current="location"' : '' ?>><?= e($item['label']) ?></a>
        <?php endforeach; ?>
    </nav>
</aside>
