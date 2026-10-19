<?php require BASE_PATH.'/templates/partials/event_workspace_assets.php'; ?>
<div class="section-navigation-page" data-section-navigation>
<header class="page-header"><div><p class="eyebrow">Campus activities</p><h1>Events</h1><p>Upcoming requirements and your recorded event attendance.</p></div><?php if (\App\Core\Auth::can('manage_events')): ?><button type="button" class="button button-primary" data-event-create>Create event</button><?php endif; ?></header>
<div class="section-navigation-layout">
<?php $sectionNavigationLabel = 'Events page sections'; $sectionNavigation = [['id' => 'portal-event-filters', 'label' => 'Find events'], ['id' => 'portal-event-directory', 'label' => 'Events']]; require BASE_PATH . '/templates/partials/section_navigation.php'; ?>
<div class="section-navigation-content">
<section class="section-scroll-target" id="portal-event-filters" data-section-target><div class="section-heading"><div><h2>Find events</h2><p class="muted">Search your assigned campus activities and filter them by status.</p></div></div><div class="card inline-form data-filter-form" data-collection-controls data-target="#event-collection"><label class="field filter-search"><span>Search events</span><input type="search" data-collection-search placeholder="Title, venue, or date"></label><label class="field"><span>Status</span><select data-collection-filter="status"><option value="">All statuses</option><option value="scheduled">Scheduled</option><option value="open">Open</option><option value="closed">Closed</option><option value="cancelled">Cancelled</option></select></label></div></section>
<section class="section events-section section-scroll-target" id="portal-event-directory" data-section-target>
    <div class="section-heading"><div><h2>Events</h2><p class="muted">Activities that include you, your class, or one of your assigned roles.</p></div></div>
    <div class="campus-event-grid" id="event-collection">
    <?php foreach ($events as $event):
        $eventBanner = trim((string) ($event['banner_image_path'] ?? '')) ?: '/assets/images/default-event-banner.svg';
        $eventStartDate = new DateTimeImmutable($event['event_date']);
        $eventHasPersonalRequirement = isset($event['is_required']);
        $eventRequired = $eventHasPersonalRequirement ? (bool) $event['is_required'] : (bool) $event['attendance_required'];
        $eventAttendance = $event['attendance_status'] ?? null;
    ?>
        <article class="card campus-event-card" data-search-item data-search="<?= e($event['event_date'] . ' ' . ($event['end_date'] ?? '')) ?>" data-universal-record="event:<?= (int) $event['id'] ?>" data-status="<?= e($event['status']) ?>">
            <a class="campus-event-media" href="<?= e(\App\Services\EventAccessService::viewUrl((int)$event['id'])) ?>" aria-label="<?= e('View full event: '.$event['title']) ?>">
                <img src="<?= e(url($eventBanner)) ?>" alt="" width="1200" height="480" loading="lazy" decoding="async" data-event-banner>
                <div class="campus-event-labels"><span class="badge event-status-<?= e($event['status']) ?>"><?= e(ucfirst($event['status'])) ?></span><span class="badge"><?= $eventRequired ? 'Required' : 'Optional' ?></span></div>
                <div class="campus-event-date-stamp" aria-hidden="true"><span><?= e($eventStartDate->format('M')) ?></span><strong><?= e($eventStartDate->format('j')) ?></strong></div>
            </a>
            <div class="campus-event-body">
                <h3><a href="<?= e(\App\Services\EventAccessService::viewUrl((int)$event['id'])) ?>"><?= e($event['title']) ?></a></h3>
                <?php if (trim((string) ($event['description'] ?? '')) !== ''): ?>
                    <div class="event-card-desc campus-event-description" data-expandable-description>
                        <p class="event-description-text" id="portal-event-description-<?= (int) $event['id'] ?>"><?= nl2br(e($event['description'])) ?></p>
                        <button type="button" class="event-see-more-btn" data-see-more-btn aria-expanded="false" aria-controls="portal-event-description-<?= (int) $event['id'] ?>" hidden>See more</button>
                    </div>
                <?php endif; ?>
                <?php require BASE_PATH . '/templates/partials/event_details.php'; ?>
                <div class="event-card-actions"><a class="button button-secondary" href="<?= e(\App\Services\EventAccessService::viewUrl((int)$event['id'])) ?>">View full event</a><?php if(\App\Services\EventAccessService::canManage($event,(int)\App\Core\Auth::user()['id'])): ?><button type="button" class="button button-secondary" data-event-edit="<?= (int)$event['id'] ?>">Edit</button><button type="button" class="button button-danger" data-event-delete="<?= (int)$event['id'] ?>">Delete</button><?php endif; ?></div>
            </div>
            <footer class="campus-event-footer">
                <div><span class="campus-event-footer-label"><?= $eventHasPersonalRequirement ? 'Your attendance' : 'Attendance' ?></span>
                    <?php if ($eventAttendance !== null): ?><span class="badge badge-<?= e(strtolower((string) $eventAttendance)) ?>"><?= e($eventAttendance) ?></span>
                    <?php elseif ($eventHasPersonalRequirement): ?><span class="campus-event-pending"><?= $event['status'] === 'cancelled' ? 'Event cancelled' : 'No attendance recorded' ?></span>
                    <?php else: ?><span class="campus-event-pending"><?= $eventRequired ? 'Required for assigned participants' : 'Optional participation' ?></span><?php endif; ?>
                </div>
                <?php if (!empty($event['check_out_required'])): ?><span class="campus-event-checkout">Check-out required</span><?php endif; ?>
            </footer>
        </article>
    <?php endforeach; ?>
        <div class="card campus-event-empty" data-collection-empty role="status"<?= $events ? ' hidden' : '' ?>><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4m10-4v4M3 10h18m5 4h5"/></svg><h3>No events to show</h3><p>Assigned activities will appear here. Try a different search or status if you are filtering.</p></div>
    </div>
</section>
</div></div></div>

<?php require BASE_PATH.'/templates/partials/event_editor.php'; ?>
