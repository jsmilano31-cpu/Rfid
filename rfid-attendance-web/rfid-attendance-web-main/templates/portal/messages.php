<div class="section-navigation-page" data-section-navigation>
<header class="page-header"><div><p class="eyebrow">Internal communications</p><h1>Messages</h1><p>Inbox, announcements, and sent items.</p></div></header>
<div class="section-navigation-layout">
<?php $sectionNavigationLabel = 'Messages page sections'; $sectionNavigation = []; if (\App\Core\Auth::can('send_messages')) $sectionNavigation[] = ['id' => 'portal-message-compose', 'label' => 'Compose']; $sectionNavigation[] = ['id' => 'portal-message-inbox', 'label' => 'Inbox']; $sectionNavigation[] = ['id' => 'portal-message-sent', 'label' => 'Sent']; require BASE_PATH . '/templates/partials/section_navigation.php'; ?>
<div class="section-navigation-content">

<?php if (\App\Core\Auth::can('send_messages')): ?>
<section class="card section-scroll-target" id="portal-message-compose" data-section-target>
    <h2>Compose</h2>
    <form method="post" class="stack" data-recipient-form>
        <?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="send_message"><input type="hidden" name="target_id">
        <label class="field"><span>Subject</span><input name="subject" required></label>
        <label class="field"><span>Message</span><textarea name="body" required></textarea></label>
        <div class="form-grid">
            <label class="field"><span>Recipient type</span><select name="recipient_type"><option value="user">Individual user</option><option value="section">Section</option><option value="role">Role</option><option value="all">All users</option></select></label>
            <div data-recipient-group="user"><?php $lookupName = 'recipient_user_id'; $lookupLabel = 'User'; $lookupContext = 'users'; require BASE_PATH . '/templates/partials/remote_user_lookup.php'; unset($lookupName, $lookupLabel, $lookupContext); ?></div>
            <label class="field" data-recipient-group="section" hidden><span>Section</span><select data-recipient-target data-searchable disabled><option value="">Search for a section</option><?php foreach ($sections as $section): ?><option value="<?= e($section['id']) ?>"><?= e($section['name']) ?></option><?php endforeach; ?></select></label>
            <label class="field" data-recipient-group="role" hidden><span>Role</span><select data-recipient-target disabled><option value="">Select a role</option><?php foreach ($roles as $role): ?><option value="<?= e($role['id']) ?>"><?= e($role['name']) ?></option><?php endforeach; ?></select></label>
        </div>
        <label><input type="checkbox" name="is_announcement"> Announcement</label><button class="button button-primary">Send</button>
    </form>
</section>
<?php endif; ?>

<section class="section section-scroll-target" id="portal-message-inbox" data-section-target><div class="section-heading"><h2>Inbox</h2></div><div class="table-wrap"><table><thead><tr><th>Message</th><th>Sender</th><th>Received</th><th>Status</th></tr></thead><tbody><?php foreach ($inbox as $message): ?><tr><td><strong><?= e($message['subject']) ?></strong><br><span class="muted"><?= e(mb_strimwidth($message['body'], 0, 120, '…')) ?></span></td><td><a href="<?= e(url('/index/?page=profile&user_id=' . $message['sender_id'])) ?>"><?= e($message['sender_name']) ?></a></td><td><?= e(format_datetime($message['created_at'])) ?></td><td><?php if (!$message['read_at']): ?><span class="badge badge-late">Unread</span><form method="post"><?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="mark_message_read"><input type="hidden" name="message_id" value="<?= e($message['id']) ?>"><button class="button button-small button-secondary">Mark read</button></form><?php else: ?><span class="badge badge-active">Read</span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="section section-scroll-target" id="portal-message-sent" data-section-target><div class="section-heading"><h2>Sent</h2></div><div class="table-wrap"><table><thead><tr><th>Subject</th><th>Recipients</th><th>Sent</th></tr></thead><tbody><?php foreach ($sent as $message): ?><tr><td><?= e($message['subject']) ?></td><td><?= e($message['recipient_count']) ?></td><td><?= e(format_datetime($message['created_at'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</div></div></div>
