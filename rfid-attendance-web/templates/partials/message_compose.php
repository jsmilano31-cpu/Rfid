<?php $attachmentLimits = \App\Services\MessageAttachmentService::limits(); ?>
<form method="post" enctype="multipart/form-data" class="mail-compose-form" data-recipient-form data-mail-compose data-no-ajax data-upload-limits="<?= e(json_encode($attachmentLimits)) ?>">
    <?= \App\Core\Csrf::field() ?>
    <input type="hidden" name="action" value="send_message"><input type="hidden" name="target_id">
    <input type="hidden" name="recipients_json" value="[]"><input type="hidden" name="body"><input type="hidden" name="body_html"><input type="hidden" name="forward_attachment_ids" value="[]">
    <div class="mail-address-row"><span class="mail-address-label">To</span><div class="mail-recipient-chips" data-mail-recipients="to"><span class="muted">Add people, sections, or roles below</span></div><button type="button" class="mail-text-button" data-mail-cc-toggle aria-expanded="false">Add CC</button></div>
    <div class="mail-address-row" data-mail-cc-row hidden><span class="mail-address-label">CC</span><div class="mail-recipient-chips" data-mail-recipients="cc"><span class="muted">People or groups receiving a copy</span></div></div>
    <div class="mail-recipient-picker">
        <label class="field"><span>Recipient type</span><select name="recipient_type"><option value="user">Individual user</option><option value="section">Section</option><option value="role">Role</option><option value="all">All users</option></select></label>
        <div data-recipient-group="user"><?php $lookupName='recipient_user_id'; $lookupLabel='Find a person'; $lookupContext='users'; $lookupRequired=false; require BASE_PATH . '/templates/partials/remote_user_lookup.php'; unset($lookupName,$lookupLabel,$lookupContext,$lookupRequired); ?></div>
        <label class="field" data-recipient-group="section" hidden><span>Section</span><select data-recipient-target data-searchable disabled><option value="">Search for a section</option><?php foreach ($sections as $section): ?><option value="<?= e($section['id']) ?>"><?= e($section['name']) ?></option><?php endforeach; ?></select></label>
        <label class="field" data-recipient-group="role" hidden><span>Role</span><select data-recipient-target data-searchable disabled><option value="">Select a role</option><?php foreach ($roles as $role): ?><option value="<?= e($role['id']) ?>"><?= e($role['name']) ?></option><?php endforeach; ?></select></label>
        <p class="muted mail-all-hint" data-mail-all-hint hidden>All active accounts will receive this message.</p>
        <label class="field"><span>Add to</span><select data-mail-recipient-kind><option value="to">To</option><option value="cc">CC</option></select></label>
        <button type="button" class="button button-secondary" data-mail-recipient-add>Add recipient</button>
    </div>
    <label class="mail-subject-field"><span>Subject</span><input name="subject" maxlength="180" placeholder="What is this message about?" required></label>
    <div class="mail-rich-editor"><div data-mail-editor aria-label="Message body"></div></div>
    <div class="mail-attachment-zone" data-mail-drop-zone>
        <div class="mail-attachment-heading"><button type="button" class="button button-secondary button-small" data-mail-attach><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m8 13 7-7a3 3 0 0 1 4 4l-9 9a5 5 0 0 1-7-7l10-10"/></svg>Attach files</button><span class="muted" data-mail-upload-help></span></div>
        <input type="file" name="attachments[]" multiple accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.txt,.csv,.md,.zip,.doc,.docx,.xls,.xlsx,.ppt,.pptx" data-mail-files hidden>
        <div class="mail-attachments" data-mail-staged-attachments></div>
    </div>
    <footer class="mail-compose-footer"><div class="mail-compose-actions"><button class="button button-primary" type="submit"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 9-18 9 3-9-3-9Zm3 9h15"/></svg>Send message</button><button type="button" class="button button-secondary" data-mail-compose-close>Close</button></div><label class="mail-announcement-toggle"><input type="checkbox" name="is_announcement">Announcement</label></footer>
    <p class="mail-compose-status" data-mail-compose-status role="status" aria-live="polite"></p>
</form>
