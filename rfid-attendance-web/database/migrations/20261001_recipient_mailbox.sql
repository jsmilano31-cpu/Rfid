-- Recipient-owned mailbox state. Shared messages and historical delivery records are retained.
ALTER TABLE message_recipients
    ADD COLUMN starred_at DATETIME NULL AFTER read_at,
    ADD COLUMN deleted_at DATETIME NULL AFTER starred_at,
    ADD KEY idx_recipient_mailbox (user_id, deleted_at, message_id);
