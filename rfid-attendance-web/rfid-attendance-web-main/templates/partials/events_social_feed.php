<?php
$currentUser = \App\Core\Auth::user();
$actorId = (int) ($currentUser['id'] ?? 1);
$canCreateEvents = \App\Core\Auth::usesAdminArea() && \App\Core\Auth::can('manage_events');

$categorized = $categorizedEvents ?? \App\Services\EventService::categorized($actorId);
$announcements = $categorized['announcements'] ?? [];
$activeEvents = $categorized['active'] ?? [];
$inactiveEvents = $categorized['inactive'] ?? [];
$totalEventsCount = count($announcements) + count($activeEvents) + count($inactiveEvents);

/**
 * Helper to render an individual minimal event card
 */
$renderEventCard = function (array $event): void {
    $imgCount = $event['image_count'];
    $images = $event['images'];
    $imagesJson = htmlspecialchars(json_encode($images, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
    $eventDataJson = htmlspecialchars(json_encode([
        'id' => $event['id'],
        'title' => $event['title'],
        'description' => $event['description'] ?? '',
        'event_date' => $event['event_date'],
        'end_date' => $event['end_date'] ?? '',
        'start_time' => $event['start_time'],
        'end_time' => $event['end_time'],
        'check_in_start' => $event['check_in_start'] ?? '08:30',
        'check_in_end' => $event['check_in_end'] ?? '09:30',
        'venue' => $event['venue'],
        'status' => $event['status'] ?? 'scheduled',
        'audience' => $event['audience_type'] ?? 'everyone',
        'target_id' => $event['target_id'] ?? '',
        'attendance_required' => (bool) ($event['attendance_required'] ?? false),
        'suspends_classes' => (bool) ($event['suspends_classes'] ?? false),
        'check_out_required' => (bool) ($event['check_out_required'] ?? false),
        'is_announcement' => (bool) ($event['is_announcement'] ?? false),
        'banner_image_path' => $event['banner_image_path'] ?? ($images[0] ?? ''),
        'images' => $images,
    ], JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
    ?>
    <article class="card event-card"
             data-event-id="<?= e($event['id']) ?>"
             data-category="<?= e($event['category'] ?? 'active') ?>"
             data-images="<?= $imagesJson ?>"
             data-event-data="<?= $eventDataJson ?>">

        <!-- Header: Poster Name, Timestamp & 3-Dots Menu -->
        <header class="event-card-header">
            <img class="event-card-avatar" src="<?= e($event['poster']['avatar']) ?>" alt="<?= e($event['poster']['name']) ?>" loading="lazy">
            <div class="event-card-author-meta">
                <div class="event-card-author-name"><?= e($event['poster']['name']) ?></div>
                <div class="event-card-time-meta"><?= e($event['formatted_created_at']) ?> · <?= e($event['audience_label']) ?></div>
            </div>
            <?php if ($event['can_manage']): ?>
                <div class="event-card-menu-wrap" data-card-menu-wrap>
                    <button type="button" class="event-card-menu-btn" data-card-menu-btn aria-label="Event options" title="Options">
                        <svg viewBox="0 0 20 20" width="16" height="16" fill="currentColor"><circle cx="10" cy="4" r="2"/><circle cx="10" cy="10" r="2"/><circle cx="10" cy="16" r="2"/></svg>
                    </button>
                    <div class="event-card-dropdown" data-card-dropdown hidden>
                        <button type="button" class="event-card-dropdown-item" data-event-action="edit" data-event-id="<?= e($event['id']) ?>">
                            Edit Event
                        </button>
                        <button type="button" class="event-card-dropdown-item text-danger" data-event-action="delete" data-event-id="<?= e($event['id']) ?>" data-event-title="<?= e($event['title']) ?>">
                            Delete Event
                        </button>
                    </div>
                </div>
            <?php endif; ?>
        </header>

        <!-- Body: Title & Description -->
        <div class="event-card-body">
            <h3 class="event-card-title"><?= e($event['title']) ?></h3>

            <?php if (!empty($event['description'])): ?>
                <div class="event-card-desc" data-expandable-description>
                    <p class="event-description-text"><?= nl2br(e($event['description'])) ?></p>
                    <button type="button" class="event-see-more-btn" data-see-more-btn hidden>See more</button>
                </div>
            <?php endif; ?>

            <div class="event-card-schedule">
                <?= e($event['formatted_date']) ?> · <?= e($event['formatted_time']) ?> · <?= e($event['venue']) ?>
                <?php if ($event['attendance_required']): ?>
                    · <span>Attendance required</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Images Collage -->
        <?php if ($imgCount > 0): ?>
            <div class="event-card-gallery">
                <?php if ($imgCount === 1): ?>
                    <div class="gallery-layout gallery-layout-1">
                        <div class="gallery-cell" data-open-lightbox="0">
                            <img src="<?= e($images[0]) ?>" alt="<?= e($event['title']) ?>" loading="lazy">
                        </div>
                    </div>
                <?php elseif ($imgCount === 2): ?>
                    <div class="gallery-layout gallery-layout-2">
                        <?php foreach (array_slice($images, 0, 2) as $idx => $img): ?>
                            <div class="gallery-cell" data-open-lightbox="<?= $idx ?>">
                                <img src="<?= e($img) ?>" alt="Photo <?= $idx + 1 ?>" loading="lazy">
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($imgCount === 3): ?>
                    <div class="gallery-layout gallery-layout-3">
                        <div class="gallery-cell gallery-cell-featured" data-open-lightbox="0">
                            <img src="<?= e($images[0]) ?>" alt="Cover photo" loading="lazy">
                        </div>
                        <div class="gallery-col">
                            <?php foreach (array_slice($images, 1, 2) as $idx => $img): ?>
                                <div class="gallery-cell" data-open-lightbox="<?= $idx + 1 ?>">
                                    <img src="<?= e($img) ?>" alt="Photo <?= $idx + 2 ?>" loading="lazy">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php elseif ($imgCount === 4): ?>
                    <div class="gallery-layout gallery-layout-4">
                        <div class="gallery-cell gallery-cell-featured" data-open-lightbox="0">
                            <img src="<?= e($images[0]) ?>" alt="Cover photo" loading="lazy">
                        </div>
                        <div class="gallery-col">
                            <?php foreach (array_slice($images, 1, 3) as $idx => $img): ?>
                                <div class="gallery-cell" data-open-lightbox="<?= $idx + 1 ?>">
                                    <img src="<?= e($img) ?>" alt="Photo <?= $idx + 2 ?>" loading="lazy">
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="gallery-layout gallery-layout-5">
                        <div class="gallery-col">
                            <div class="gallery-cell" data-open-lightbox="0">
                                <img src="<?= e($images[0]) ?>" alt="Photo 1" loading="lazy">
                            </div>
                            <div class="gallery-cell" data-open-lightbox="1">
                                <img src="<?= e($images[1]) ?>" alt="Photo 2" loading="lazy">
                            </div>
                        </div>
                        <div class="gallery-col">
                            <div class="gallery-cell" data-open-lightbox="2">
                                <img src="<?= e($images[2]) ?>" alt="Photo 3" loading="lazy">
                            </div>
                            <div class="gallery-cell" data-open-lightbox="3">
                                <img src="<?= e($images[3]) ?>" alt="Photo 4" loading="lazy">
                            </div>
                            <div class="gallery-cell gallery-cell-more" data-open-lightbox="4">
                                <img src="<?= e($images[4]) ?>" alt="Photo 5" loading="lazy">
                                <div class="gallery-more-overlay">+<?= $imgCount - 4 ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </article>
    <?php
};
?>

<section class="section events-section section-scroll-target" id="dashboard-events-feed" data-section-target>
    <div class="section-heading events-section-heading">
        <div>
            <h2>Events & Announcements</h2>
            <p class="muted">Campus notices, active events, and past activities.</p>
        </div>
        <?php if ($canCreateEvents): ?>
            <button type="button" class="button button-secondary" data-calendar-create-event>
                Create Event
            </button>
        <?php endif; ?>
    </div>

    <!-- Minimal Category Tabs Navigation -->
    <div class="events-tabs-bar" data-category-tabs>
        <button type="button" class="events-tab is-active" data-tab-filter="all">
            All (<?= $totalEventsCount ?>)
        </button>
        <button type="button" class="events-tab" data-tab-filter="announcements">
            Announcements (<?= count($announcements) ?>)
        </button>
        <button type="button" class="events-tab" data-tab-filter="active">
            Active Events (<?= count($activeEvents) ?>)
        </button>
        <button type="button" class="events-tab" data-tab-filter="inactive">
            Inactive Events (<?= count($inactiveEvents) ?>)
        </button>
    </div>

    <!-- SECTION 1: ANNOUNCEMENTS -->
    <div class="events-category-group" id="events-section-announcements" data-category-section="announcements">
        <div class="events-group-header">
            <div class="events-group-title-wrap">
                <h3 class="events-group-title">Announcements</h3>
                <span class="events-group-count"><?= count($announcements) ?></span>
            </div>

            <?php if (!empty($announcements)): ?>
                <div class="events-pagination" data-carousel-controls="announcements">
                    <button type="button" class="events-pag-btn" data-carousel-prev aria-label="Previous">‹</button>
                    <span class="events-pag-text" data-carousel-indicator>1 / <?= max(1, (int) ceil(count($announcements) / 5)) ?></span>
                    <button type="button" class="events-pag-btn" data-carousel-next aria-label="Next">›</button>
                </div>
            <?php endif; ?>
        </div>

        <div class="events-horizontal-track-wrap">
            <div class="events-horizontal-track" data-carousel-track="announcements" data-page-size="5">
                <?php if (empty($announcements)): ?>
                    <div class="events-empty-card">
                        <p class="muted">No announcements posted.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($announcements as $event) $renderEventCard($event); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- SECTION 2: ACTIVE EVENTS -->
    <div class="events-category-group" id="events-section-active" data-category-section="active">
        <div class="events-group-header">
            <div class="events-group-title-wrap">
                <h3 class="events-group-title">Active Events</h3>
                <span class="events-group-count"><?= count($activeEvents) ?></span>
            </div>

            <?php if (!empty($activeEvents)): ?>
                <div class="events-pagination" data-carousel-controls="active">
                    <button type="button" class="events-pag-btn" data-carousel-prev aria-label="Previous">‹</button>
                    <span class="events-pag-text" data-carousel-indicator>1 / <?= max(1, (int) ceil(count($activeEvents) / 5)) ?></span>
                    <button type="button" class="events-pag-btn" data-carousel-next aria-label="Next">›</button>
                </div>
            <?php endif; ?>
        </div>

        <div class="events-horizontal-track-wrap">
            <div class="events-horizontal-track" data-carousel-track="active" data-page-size="5">
                <?php if (empty($activeEvents)): ?>
                    <div class="events-empty-card">
                        <p class="muted">No active events for today.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($activeEvents as $event) $renderEventCard($event); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- SECTION 3: INACTIVE EVENTS -->
    <div class="events-category-group" id="events-section-inactive" data-category-section="inactive">
        <div class="events-group-header">
            <div class="events-group-title-wrap">
                <h3 class="events-group-title">Inactive Events</h3>
                <span class="events-group-count"><?= count($inactiveEvents) ?></span>
            </div>

            <?php if (!empty($inactiveEvents)): ?>
                <div class="events-pagination" data-carousel-controls="inactive">
                    <button type="button" class="events-pag-btn" data-carousel-prev aria-label="Previous">‹</button>
                    <span class="events-pag-text" data-carousel-indicator>1 / <?= max(1, (int) ceil(count($inactiveEvents) / 5)) ?></span>
                    <button type="button" class="events-pag-btn" data-carousel-next aria-label="Next">›</button>
                </div>
            <?php endif; ?>
        </div>

        <div class="events-horizontal-track-wrap">
            <div class="events-horizontal-track" data-carousel-track="inactive" data-page-size="5">
                <?php if (empty($inactiveEvents)): ?>
                    <div class="events-empty-card">
                        <p class="muted">No past events.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($inactiveEvents as $event) $renderEventCard($event); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- Fullscreen Photo Lightbox Modal -->
<dialog id="event-lightbox-dialog" class="app-dialog event-lightbox-dialog" aria-label="Photo Viewer">
    <div class="lightbox-dialog-inner">
        <header class="lightbox-dialog-header">
            <span class="lightbox-counter" data-lightbox-counter>1 / 1</span>
            <button type="button" class="lightbox-close-btn" data-dialog-close aria-label="Close">✕</button>
        </header>

        <div class="lightbox-viewer-area">
            <button type="button" class="lightbox-nav-btn lightbox-prev" data-lightbox-prev aria-label="Previous">‹</button>
            <div class="lightbox-image-frame">
                <img src="" alt="Photo" data-lightbox-image>
            </div>
            <button type="button" class="lightbox-nav-btn lightbox-next" data-lightbox-next aria-label="Next">›</button>
        </div>
    </div>
</dialog>
