SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS teacher_time_off (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT UNSIGNED NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    reason VARCHAR(255) NULL,
    status ENUM('active', 'cancelled') NOT NULL DEFAULT 'active',
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_teacher_time_off_lookup (teacher_id, status, starts_on, ends_on),
    CONSTRAINT chk_teacher_time_off_dates CHECK (ends_on >= starts_on),
    CONSTRAINT fk_teacher_time_off_teacher FOREIGN KEY (teacher_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_teacher_time_off_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

INSERT INTO permissions (id, code, description)
VALUES (19, 'manage_teacher_time_off', 'Schedule personal teaching time off and class suspensions')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id FROM roles CROSS JOIN permissions
WHERE roles.name IN ('Administrator', 'Teacher') AND permissions.code = 'manage_teacher_time_off';
