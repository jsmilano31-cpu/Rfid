ALTER TABLE courses
    DROP FOREIGN KEY fk_courses_department;

ALTER TABLE courses
    MODIFY COLUMN department_id INT UNSIGNED NULL,
    MODIFY COLUMN units DECIMAL(3,1) NULL DEFAULT NULL,
    ADD CONSTRAINT fk_courses_department
        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL;
