<?php $mailCanSend = \App\Core\Auth::can('send_messages'); ?>
<div class="mail-page" data-mailbox data-endpoint="<?= e(url('/api/messages.php')) ?>" data-token="<?= e(\App\Core\Csrf::token()) ?>" data-user-id="<?= (int)\App\Core\Auth::user()['id'] ?>">
    <header class="page-header"><div><p class="eyebrow">Internal communications</p><h1>Messages</h1><p>Your campus inbox, announcements, and sent messages. Times use campus time (UTC<?= e((new \DateTimeImmutable('now', new \DateTimeZone(config('app')['timezone'])))->format('P')) ?>).</p></div>
        <?php if ($mailCanSend): ?><button class="button button-primary" type="button" data-mail-compose-open>Compose message</button><?php endif; ?>
    </header>
    <?php if ($mailCanSend): ?>
    <section id="<?= e($mailComposeId) ?>" class="card mail-compose" hidden aria-labelledby="mail-compose-heading">
        <div class="mail-compose-header"><div><p class="eyebrow">Compose</p><h2 id="mail-compose-heading">New message</h2></div><span class="muted">Your draft stays here until sent</span></div>
        <?php require BASE_PATH . '/templates/partials/message_compose.php'; ?>
    </section>
    <?php endif; ?>
    <?php if (\App\Core\Auth::can('view_messages')): ?><section class="mail-shell" aria-label="Mailbox">
        <nav class="mail-folders" aria-label="Message folders">
            <button type="button" data-mail-folder="inbox" aria-current="page">Inbox <span data-mail-count="unread"></span></button>
            <button type="button" data-mail-folder="starred">Starred <span data-mail-count="starred"></span></button>
            <button type="button" data-mail-folder="sent">Sent</button>
            <button type="button" data-mail-folder="trash">Trash <span data-mail-count="trash"></span></button>
        </nav>
        <div data-mail-list-view>
            <div class="mail-toolbar">
                <label class="mail-select-all"><input type="checkbox" data-mail-select-all aria-label="Select all messages on this page"><span data-mail-selected> Select</span></label>
                <div class="mail-bulk" data-mail-bulk hidden></div>
                <label class="mail-search"><span class="sr-only">Search messages in this folder</span><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/></svg><input type="search" placeholder="Search this folder" data-mail-search maxlength="180"></label>
                <button type="button" class="mail-icon" data-mail-refresh aria-label="Refresh messages" title="Refresh messages"></button>
            </div>
            <p class="mail-trash-note" data-mail-trash-note hidden>Messages in Trash are hidden from your inbox. Restore them at any time; other recipients keep their copies.</p>
            <div class="mail-list" data-mail-list aria-label="Messages" aria-busy="true"></div>
            <footer class="mail-pagination"><span class="muted" data-mail-page-info></span><div><button type="button" class="mail-icon" data-mail-previous aria-label="Previous page" title="Previous page"></button><button type="button" class="mail-icon" data-mail-next aria-label="Next page" title="Next page"></button></div></footer>
        </div>
        <article class="mail-detail" data-mail-detail hidden aria-label="Full message"></article>
    </section>
    <?php endif; ?>
    <p class="mail-feedback" data-mail-feedback role="status" aria-live="polite"></p>
    <noscript><p class="alert alert-warning">Enable JavaScript to open and manage your messages.</p></noscript>
</div>

<dialog class="mail-image-dialog" data-mail-image-dialog aria-labelledby="mail-image-title">
    <header><h2 id="mail-image-title" data-mail-image-title>Image preview</h2><a class="mail-icon" data-mail-image-download download aria-label="Download image" title="Download image"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v12m-4-4 4 4 4-4M4 17v4h16v-4"/></svg></a><button type="button" class="mail-icon" data-mail-image-close aria-label="Close image preview" autofocus><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button></header>
    <div class="mail-image-stage"><img data-mail-image alt=""><p class="muted" data-mail-image-error hidden>Preview unavailable. You can still download this file.</p></div>
    <footer><button type="button" class="mail-icon" data-mail-image-previous aria-label="Previous image"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg></button><span class="muted" data-mail-image-count></span><button type="button" class="mail-icon" data-mail-image-next aria-label="Next image"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></button></footer>
</dialog>
