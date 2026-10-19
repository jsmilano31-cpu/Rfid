CREATE TABLE IF NOT EXISTS campus_holidays (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    holiday_date DATE NOT NULL,
    title VARCHAR(180) NOT NULL,
    holiday_type VARCHAR(32) NOT NULL,
    no_classes TINYINT(1) NOT NULL DEFAULT 1,
    updated_by INT UNSIGNED NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_campus_holiday_date (holiday_date),
    CONSTRAINT fk_campus_holiday_actor FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO permissions(code, description)
VALUES ('manage_academic_calendar', 'Configure academic terms, weekly schedules, and campus no-class holidays');

INSERT IGNORE INTO role_permissions(role_id, permission_id)
SELECT roles.id, permissions.id FROM roles CROSS JOIN permissions
WHERE roles.name = 'Administrator' AND permissions.code = 'manage_academic_calendar';
