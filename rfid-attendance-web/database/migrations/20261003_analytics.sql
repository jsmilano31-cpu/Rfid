CREATE TABLE IF NOT EXISTS analytics_state (
 id TINYINT PRIMARY KEY, baseline_at DATETIME(6) NOT NULL, built_at DATETIME NULL,
 source_cursor BIGINT UNSIGNED NOT NULL DEFAULT 0, revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
 worker_error VARCHAR(240) NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS analytics_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_table VARCHAR(64) NOT NULL,
 record_key VARCHAR(100) NOT NULL, valid_from DATETIME(6) NOT NULL, valid_to DATETIME(6) NULL,
 operation VARCHAR(12) NOT NULL, payload LONGTEXT NOT NULL,
 INDEX idx_analytics_history_point(source_table,record_key,valid_from,id),
 INDEX idx_analytics_history_period(source_table,valid_from,valid_to)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS analytics_changes (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, source_table VARCHAR(64) NOT NULL,
 record_key VARCHAR(100) NOT NULL, occurs_on DATE NULL, changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS analytics_occurrences (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, class_offering_id INT UNSIGNED NOT NULL,
 attendance_date DATE NOT NULL, context_json LONGTEXT NOT NULL, eligible_count INT UNSIGNED NOT NULL,
 UNIQUE KEY uq_analytics_occurrence(class_offering_id,attendance_date)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS analytics_attendance (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, class_offering_id INT UNSIGNED NOT NULL,
 student_id INT UNSIGNED NOT NULL, attendance_date DATE NOT NULL, record_id BIGINT UNSIGNED NULL,
 semester_id INT UNSIGNED NULL, department_id INT UNSIGNED NULL, program_id INT UNSIGNED NULL,
 section_id INT UNSIGNED NULL, course_id INT UNSIGNED NULL, room_id INT UNSIGNED NULL,
 status VARCHAR(12) NULL, finalized TINYINT NOT NULL DEFAULT 0,
 expected TINYINT NULL, history_known TINYINT NOT NULL DEFAULT 0,
 late_minutes DECIMAL(8,2) NULL, tardies_per_absence INT UNSIGNED NULL,
 INDEX idx_analytics_attendance_period(attendance_date,finalized,class_offering_id),
 INDEX idx_analytics_attendance_student(student_id,attendance_date),
 INDEX idx_analytics_attendance_class(class_offering_id,attendance_date),
 INDEX idx_analytics_attendance_cohort(semester_id,department_id,program_id,section_id,attendance_date),
 UNIQUE KEY uq_analytics_attendance(class_offering_id,student_id,attendance_date)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS analytics_cache (
 cache_key CHAR(64) PRIMARY KEY, revision BIGINT UNSIGNED NOT NULL, expires_at DATETIME NOT NULL,
 payload LONGTEXT NOT NULL, INDEX idx_analytics_cache_expiry(expires_at)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS analytics_daily (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, class_offering_id INT UNSIGNED NOT NULL,
 attendance_date DATE NOT NULL, semester_id INT UNSIGNED NULL, department_id INT UNSIGNED NULL,
 program_id INT UNSIGNED NULL, section_id INT UNSIGNED NULL, course_id INT UNSIGNED NULL, room_id INT UNSIGNED NULL,
 present_count INT UNSIGNED NOT NULL, late_count INT UNSIGNED NOT NULL, absent_count INT UNSIGNED NOT NULL,
 excused_count INT UNSIGNED NOT NULL, missing_count INT UNSIGNED NOT NULL, provisional_count INT UNSIGNED NOT NULL,
 unknown_count INT UNSIGNED NOT NULL, expected_count INT UNSIGNED NOT NULL, recorded_expected INT UNSIGNED NOT NULL,
 observation_count INT UNSIGNED NOT NULL, late_minutes_sum DECIMAL(16,2) NOT NULL, late_minutes_n INT UNSIGNED NOT NULL,
 UNIQUE KEY uq_analytics_daily(class_offering_id,attendance_date),
 INDEX idx_analytics_daily_period(attendance_date,class_offering_id),
 INDEX idx_analytics_daily_cohort(semester_id,department_id,program_id,section_id,attendance_date)
) ENGINE=InnoDB;
