ALTER TABLE event_target_groups
    ADD COLUMN role_filter ENUM('any', 'student', 'teacher', 'staff') NOT NULL DEFAULT 'any' AFTER target_id;
