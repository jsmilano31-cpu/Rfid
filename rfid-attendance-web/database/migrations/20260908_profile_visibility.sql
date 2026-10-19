ALTER TABLE users
    ADD COLUMN IF NOT EXISTS profile_visibility ENUM('public', 'private') NOT NULL DEFAULT 'public' AFTER profile_image_path;
