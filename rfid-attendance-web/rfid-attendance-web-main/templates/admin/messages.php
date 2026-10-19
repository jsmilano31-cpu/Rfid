<div class="section-navigation-page" data-section-navigation>
<header class="page-header">
    <div><p class="eyebrow">Communications</p><h1>Messages & announcements</h1><p>Send individual, section, role, or campus-wide messages.</p></div>
</header>

<div class="section-navigation-layout">
<?php $sectionNavigationLabel = 'Messages page sections'; $sectionNavigation = [
    ['id' => 'messages-compose', 'label' => 'Compose'],
    ['id' => 'messages-sent', 'label' => 'Sent messages'],
]; require BASE_PATH . '/templates/admin/partials/section_navigation.php'; ?>
<div class="section-navigation-content">
<section class="grid grid-2 section-scroll-target" id="messages-compose" data-section-target>
    <article class="card">
        <h2>Compose</h2>
        <form method="post" class="stack" data-recipient-form>
            <?= \App\Core\Csrf::field() ?><input type="hidden" name="action" value="send_message"><input type="hidden" name="target_id">
            <label class="field"><span>Subject</span><input name="subject" required></label>
            <label class="field"><span>Message</span><textarea name="body" required></textarea></label>
            <label class="field"><span>Recipient type</span><select name="recipient_type"><option value="user">Individual user</option><option value="section">Section</option><option value="role">Role</option><option value="all">All users</option></select></label>
            <div data-recipient-group="user"><?php $lookupName = 'recipient_user_id'; $lookupLabel = 'User'; $lookupContext = 'users'; require BASE_PATH . '/templates/partials/remote_user_lookup.php'; unset($lookupName, $lookupLabel, $lookupContext); ?></div>
            <label class="field" data-recipient-group="section" hidden><span>Section</span><select data-recipient-target data-searchable disabled><option value="">Search for a section</option><?php foreach ($sections as $section): ?><option value="<?= e($section['id']) ?>"><?= e($section['name']) ?></option><?php endforeach; ?></select></label>
            <label class="field" data-recipient-group="role" hidden><span>Role</span><select data-recipient-target disabled><option value="">Select a role</option><?php foreach ($roles as $role): ?><option value="<?= e($role['id']) ?>"><?= e($role['name']) ?></option><?php endforeach; ?></select></label>
            <label><input type="checkbox" name="is_announcement"> Announcement</label>
            <button class="button button-primary">Send message</button>
        </form>
    </article>
    <article class="card"><h2>Recipient search</h2><p>Choose a recipient type, then search by school ID, name, section, or role. Selecting “All users” does not require a target.</p><p class="muted">Searchable controls remain responsive even when the directory contains many records.</p></article>
</section>

<section class="section section-scroll-target" id="messages-sent" data-section-target><div class="section-heading"><h2>Sent messages</h2></div><div class="table-wrap"><table><thead><tr><th>Subject</th><th>Sender</th><th>Recipients</th><th>Type</th><th>Sent</th></tr></thead><tbody><?php foreach ($messages as $message): ?><tr><td><strong><?= e($message['subject']) ?></strong><br><span class="muted"><?= e(mb_strimwidth($message['body'], 0, 90, '…')) ?></span></td><td><?= e($message['sender_name']) ?></td><td><?= e($message['recipient_count']) ?></td><td><?= $message['is_announcement'] ? 'Announcement' : 'Message' ?></td><td><?= e(format_datetime($message['created_at'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
</div>
</div>
</div>
