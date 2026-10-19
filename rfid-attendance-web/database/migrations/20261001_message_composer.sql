-- Existing message bodies remain plain text; existing recipients remain To recipients.
ALTER TABLE messages ADD COLUMN body_html TEXT NULL AFTER body;
ALTER TABLE message_recipients ADD COLUMN recipient_kind ENUM('to','cc') NOT NULL DEFAULT 'to' AFTER user_id;

CREATE TABLE message_targets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    recipient_kind ENUM('to','cc') NOT NULL DEFAULT 'to',
    target_type ENUM('user','section','role','all') NOT NULL,
    target_id INT UNSIGNED NULL,
    label VARCHAR(190) NOT NULL,
    CONSTRAINT fk_message_targets_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE message_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    storage_name VARCHAR(80) NOT NULL,
    original_name VARCHAR(200) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    CONSTRAINT fk_message_attachments_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE
) ENGINE=InnoDB;
