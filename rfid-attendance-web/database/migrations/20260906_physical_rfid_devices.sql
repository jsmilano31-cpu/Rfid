-- Physical RFID scanner authentication, registration capture and audit history.

ALTER TABLE attendance_records
    MODIFY source ENUM('rfid', 'rfid', 'manual', 'system') NOT NULL;
UPDATE attendance_records SET source = 'rfid' WHERE source = 'rfid';
ALTER TABLE attendance_records
    MODIFY source ENUM('rfid', 'manual', 'system') NOT NULL;

ALTER TABLE event_attendance
    MODIFY source ENUM('rfid', 'rfid', 'manual', 'system') NOT NULL;
UPDATE event_attendance SET source = 'rfid' WHERE source = 'rfid';
ALTER TABLE event_attendance
    MODIFY source ENUM('rfid', 'manual', 'system') NOT NULL;

ALTER TABLE recollection_attendance
    MODIFY source ENUM('rfid', 'rfid', 'manual', 'system') NOT NULL;
UPDATE recollection_attendance SET source = 'rfid' WHERE source = 'rfid';
ALTER TABLE recollection_attendance
    MODIFY source ENUM('rfid', 'manual', 'system') NOT NULL;

CREATE TABLE IF NOT EXISTS rfid_registration_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_token CHAR(64) NOT NULL UNIQUE,
    user_id INT UNSIGNED NOT NULL,
    device_id INT UNSIGNED NOT NULL,
    requested_by INT UNSIGNED NOT NULL,
    uid VARCHAR(32) NULL,
    status ENUM('pending', 'captured', 'completed', 'cancelled', 'expired') NOT NULL DEFAULT 'pending',
    expires_at DATETIME NOT NULL,
    captured_at DATETIME NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rfid_registration_device (device_id, status, expires_at),
    CONSTRAINT fk_rfid_registration_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_rfid_registration_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
    CONSTRAINT fk_rfid_registration_actor FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS device_scan_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id INT UNSIGNED NOT NULL,
    uid VARCHAR(32) NULL,
    outcome VARCHAR(40) NOT NULL,
    message VARCHAR(255) NULL,
    class_offering_id INT UNSIGNED NULL,
    student_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_device_scan_created (device_id, created_at),
    CONSTRAINT fk_device_scan_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
    CONSTRAINT fk_device_scan_offering FOREIGN KEY (class_offering_id) REFERENCES class_offerings(id) ON DELETE SET NULL,
    CONSTRAINT fk_device_scan_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
