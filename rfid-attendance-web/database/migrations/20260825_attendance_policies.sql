INSERT IGNORE INTO permissions (code, description)
VALUES ('manage_attendance_policies', 'Configure campus and assigned-subject attendance rules');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles CROSS JOIN permissions
WHERE roles.name IN ('Administrator', 'Teacher')
  AND permissions.code = 'manage_attendance_policies';

ALTER TABLE attendance_settings
    ADD COLUMN IF NOT EXISTS absent_after_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30 AFTER grace_period_minutes;

CREATE TABLE IF NOT EXISTS class_attendance_policies (
    class_offering_id INT UNSIGNED PRIMARY KEY,
    grace_period_minutes TINYINT UNSIGNED NOT NULL DEFAULT 15,
    absent_after_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    tardies_per_absence TINYINT UNSIGNED NOT NULL DEFAULT 3,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_class_policy_window CHECK (absent_after_minutes > grace_period_minutes),
    CONSTRAINT fk_class_policy_offering FOREIGN KEY (class_offering_id) REFERENCES class_offerings(id) ON DELETE CASCADE,
    CONSTRAINT fk_class_policy_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
