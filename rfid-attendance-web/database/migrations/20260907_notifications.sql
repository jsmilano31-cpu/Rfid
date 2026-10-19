-- Additive; safe to rerun. Existing attendance is not mass-notified on installation.
SET time_zone = '+08:00';
CREATE TABLE IF NOT EXISTS notification_settings (
 id TINYINT UNSIGNED PRIMARY KEY,
 enabled_at DATETIME NOT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO notification_settings VALUES (1, NOW());

CREATE TABLE IF NOT EXISTS notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 event_key VARCHAR(120) NOT NULL,
 fingerprint CHAR(64) NOT NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 kind VARCHAR(40) NOT NULL,
 title VARCHAR(180) NOT NULL,
 body TEXT NOT NULL,
 target_type VARCHAR(30) NOT NULL,
 target_id BIGINT UNSIGNED NOT NULL,
 expires_at DATETIME NULL,
 read_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_notification_user_event (user_id,event_key),
 INDEX idx_notification_inbox (user_id,updated_at,id),
 CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notification_devices (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE,
 token TEXT NOT NULL,
 session_hash CHAR(64) NOT NULL,
 active BOOLEAN NOT NULL DEFAULT 1,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_notification_device_user (user_id,active),
 CONSTRAINT fk_notification_device_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notification_outbox (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 notification_id BIGINT UNSIGNED NOT NULL,
 revision INT UNSIGNED NOT NULL,
 device_id BIGINT UNSIGNED NOT NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 status VARCHAR(20) NOT NULL DEFAULT 'pending',
 last_error VARCHAR(100) NULL,
 UNIQUE KEY uq_notification_delivery (notification_id,revision,device_id),
 INDEX idx_notification_outbox_due (status,available_at),
 CONSTRAINT fk_notification_outbox_item FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
 CONSTRAINT fk_notification_outbox_device FOREIGN KEY (device_id) REFERENCES notification_devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
