ALTER TABLE users
    ADD INDEX IF NOT EXISTS idx_users_first_name (first_name);
