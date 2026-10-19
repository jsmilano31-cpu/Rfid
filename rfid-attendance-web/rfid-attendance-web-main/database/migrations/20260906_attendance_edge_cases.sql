-- Attendance edge cases: early entry, class-suspending events, event scanners,
-- multiple room scanners, and lecture/laboratory class components.

ALTER TABLE attendance_settings
    ADD COLUMN early_check_in_minutes TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER id;

ALTER TABLE class_attendance_policies
    ADD COLUMN early_check_in_minutes TINYINT UNSIGNED NOT NULL DEFAULT 10 AFTER class_offering_id;

ALTER TABLE devices ADD INDEX idx_devices_room_status (room_id, status);
ALTER TABLE devices DROP INDEX uq_devices_room;

ALTER TABLE class_offerings
    ADD COLUMN component_type ENUM('LEC', 'LAB') NOT NULL DEFAULT 'LEC' AFTER semester_id;
ALTER TABLE class_offerings
    ADD UNIQUE KEY uq_offering_component (course_id, section_id, semester_id, component_type);
ALTER TABLE class_offerings DROP INDEX uq_offering;

ALTER TABLE events
    ADD COLUMN suspends_classes BOOLEAN NOT NULL DEFAULT FALSE AFTER attendance_required;

CREATE TABLE event_scanners (
    event_id INT UNSIGNED NOT NULL,
    device_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, device_id),
    INDEX idx_event_scanners_device (device_id, event_id),
    CONSTRAINT fk_event_scanners_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_scanners_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
