INSERT IGNORE INTO permissions (code, description)
VALUES ('manage_seat_plans', 'Create room seat plans and reusable presets');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles CROSS JOIN permissions
WHERE roles.name IN ('Administrator', 'Teacher')
  AND permissions.code = 'manage_seat_plans';

CREATE TABLE IF NOT EXISTS seat_plan_presets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    row_count TINYINT UNSIGNED NOT NULL DEFAULT 10,
    column_count TINYINT UNSIGNED NOT NULL DEFAULT 10,
    visibility ENUM('campus', 'personal') NOT NULL DEFAULT 'personal',
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_seat_plan_preset_owner_name (created_by, name),
    INDEX idx_seat_plan_presets_visibility (visibility, created_by),
    CONSTRAINT chk_seat_plan_preset_rows CHECK (row_count BETWEEN 1 AND 20),
    CONSTRAINT chk_seat_plan_preset_columns CHECK (column_count BETWEEN 1 AND 20),
    CONSTRAINT fk_seat_plan_presets_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS seat_plan_preset_cells (
    preset_id INT UNSIGNED NOT NULL,
    grid_row TINYINT UNSIGNED NOT NULL,
    grid_column TINYINT UNSIGNED NOT NULL,
    cell_type ENUM('student_seat', 'teacher_table', 'label') NOT NULL DEFAULT 'student_seat',
    label VARCHAR(80) NOT NULL DEFAULT 'Student Seat',
    PRIMARY KEY (preset_id, grid_row, grid_column),
    CONSTRAINT fk_seat_plan_preset_cells_preset FOREIGN KEY (preset_id) REFERENCES seat_plan_presets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS room_seat_plans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id INT UNSIGNED NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    row_count TINYINT UNSIGNED NOT NULL DEFAULT 10,
    column_count TINYINT UNSIGNED NOT NULL DEFAULT 10,
    based_on_preset_id INT UNSIGNED NULL,
    updated_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_room_seat_plan_rows CHECK (row_count BETWEEN 1 AND 20),
    CONSTRAINT chk_room_seat_plan_columns CHECK (column_count BETWEEN 1 AND 20),
    CONSTRAINT fk_room_seat_plans_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    CONSTRAINT fk_room_seat_plans_preset FOREIGN KEY (based_on_preset_id) REFERENCES seat_plan_presets(id) ON DELETE SET NULL,
    CONSTRAINT fk_room_seat_plans_updater FOREIGN KEY (updated_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS room_seat_plan_cells (
    seat_plan_id INT UNSIGNED NOT NULL,
    grid_row TINYINT UNSIGNED NOT NULL,
    grid_column TINYINT UNSIGNED NOT NULL,
    cell_type ENUM('student_seat', 'teacher_table', 'label') NOT NULL DEFAULT 'student_seat',
    label VARCHAR(80) NOT NULL DEFAULT 'Student Seat',
    assigned_student_id INT UNSIGNED NULL,
    occupied_at DATETIME NULL,
    PRIMARY KEY (seat_plan_id, grid_row, grid_column),
    INDEX idx_room_seat_plan_student (assigned_student_id),
    CONSTRAINT fk_room_seat_plan_cells_plan FOREIGN KEY (seat_plan_id) REFERENCES room_seat_plans(id) ON DELETE CASCADE,
    CONSTRAINT fk_room_seat_plan_cells_student FOREIGN KEY (assigned_student_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
