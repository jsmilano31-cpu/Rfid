CREATE TABLE IF NOT EXISTS user_preferences (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    start_page VARCHAR(24) NOT NULL DEFAULT 'home',
    theme_mode ENUM('system','light','dark') NOT NULL DEFAULT 'system',
    accent_theme ENUM('blue','green','purple','orange') NOT NULL DEFAULT 'blue',
    reduced_motion TINYINT(1) NOT NULL DEFAULT 0,
    compact_mode TINYINT(1) NOT NULL DEFAULT 0,
    push_enabled TINYINT(1) NOT NULL DEFAULT 1,
    notify_attendance TINYINT(1) NOT NULL DEFAULT 1,
    notify_events TINYINT(1) NOT NULL DEFAULT 1,
    notify_recollections TINYINT(1) NOT NULL DEFAULT 1,
    notify_messages TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
