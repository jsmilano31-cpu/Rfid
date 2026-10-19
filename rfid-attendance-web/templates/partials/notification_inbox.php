<div class="notification-center" data-notification-center data-endpoint="<?= e(url('/api/notifications.php')) ?>" data-token="<?= e(\App\Core\Csrf::token()) ?>">
    <button class="notification-bell" type="button" aria-label="Notifications" aria-haspopup="dialog" data-notification-open>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg><span class="notification-count" data-notification-count hidden></span>
    </button>
    <dialog class="notification-dialog" data-notification-dialog aria-labelledby="notification-heading">
        <header><div><p class="eyebrow">YOUR CAMPUS</p><h2 id="notification-heading">Notifications</h2></div><button type="button" class="notification-bell" data-notification-close aria-label="Close notifications"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button></header>
        <div class="notification-toolbar"><button type="button" class="button button-secondary" data-notification-filter aria-pressed="false">Unread only</button><button type="button" class="button button-ghost" data-notification-read>Mark shown as read</button></div>
        <div class="notification-toolbar"><span data-notification-push-status>Browser push is optional.</span><button type="button" class="button button-secondary" data-notification-push>Enable push</button></div>
        <p class="notification-status" data-notification-status role="status"></p>
        <div class="notification-list" data-notification-list aria-label="Notification list"></div>
        <footer><button type="button" class="button button-secondary" data-notification-retry>Refresh</button><button type="button" class="button button-secondary" data-notification-more hidden>Load older</button></footer>
    </dialog>
</div>
