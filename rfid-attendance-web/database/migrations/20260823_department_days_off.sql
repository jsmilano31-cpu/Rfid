USE rfid_attendance;

CREATE TABLE IF NOT EXISTS department_days_off (
    department_id INT UNSIGNED NOT NULL,
    day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL,
    reason VARCHAR(150) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (department_id, day_of_week),
    INDEX idx_department_days_off_day (day_of_week),
    CONSTRAINT fk_department_days_off_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO department_days_off (department_id, day_of_week, reason)
SELECT id, 'Sunday', 'Campus weekly day off' FROM departments;

INSERT IGNORE INTO department_days_off (department_id, day_of_week, reason)
SELECT id, 'Friday', 'Department weekly day off' FROM departments WHERE code = 'CCS';

INSERT IGNORE INTO department_days_off (department_id, day_of_week, reason)
SELECT id, 'Monday', 'Department weekly day off' FROM departments WHERE code = 'CBA';

UPDATE class_schedules AS schedules
JOIN class_offerings AS offerings ON offerings.id = schedules.class_offering_id
JOIN sections ON sections.id = offerings.section_id
JOIN programs ON programs.id = sections.program_id
JOIN departments ON departments.id = programs.department_id
SET schedules.day_of_week = 'Saturday'
WHERE departments.code = 'CBA' AND schedules.day_of_week = 'Monday';
