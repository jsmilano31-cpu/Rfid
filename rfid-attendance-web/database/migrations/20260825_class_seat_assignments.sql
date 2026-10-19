CREATE TABLE IF NOT EXISTS class_seat_assignments (
    class_offering_id INT UNSIGNED NOT NULL,
    seat_plan_id INT UNSIGNED NOT NULL,
    grid_row TINYINT UNSIGNED NOT NULL,
    grid_column TINYINT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    assigned_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (class_offering_id, seat_plan_id, grid_row, grid_column),
    UNIQUE KEY uq_class_seat_student (class_offering_id, seat_plan_id, student_id),
    INDEX idx_class_seat_student (student_id),
    CONSTRAINT fk_class_seat_offering FOREIGN KEY (class_offering_id) REFERENCES class_offerings(id) ON DELETE CASCADE,
    CONSTRAINT fk_class_seat_plan FOREIGN KEY (seat_plan_id) REFERENCES room_seat_plans(id) ON DELETE CASCADE,
    CONSTRAINT fk_class_seat_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_class_seat_actor FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
