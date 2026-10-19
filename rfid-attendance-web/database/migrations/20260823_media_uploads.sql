ALTER TABLE users
    ADD COLUMN IF NOT EXISTS profile_image_path VARCHAR(255) NULL AFTER section_id;

ALTER TABLE events
    ADD COLUMN IF NOT EXISTS banner_image_path VARCHAR(255) NULL AFTER venue;

UPDATE users
SET profile_image_path = '/assets/images/default-avatar.svg'
WHERE role_id <> 1 AND profile_image_path IS NULL;

UPDATE events
SET banner_image_path = '/assets/images/default-event-banner.svg'
WHERE id = 1 AND banner_image_path IS NULL;
