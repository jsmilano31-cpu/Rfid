ALTER TABLE event_target_groups
    MODIFY COLUMN target_type ENUM('everyone', 'all_students', 'all_teachers', 'all_staff', 'department', 'program', 'section', 'user') NOT NULL;
