SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS notification_outbox;
DROP TABLE IF EXISTS notification_devices;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS notification_settings;
DROP TABLE IF EXISTS user_preferences;
DROP TABLE IF EXISTS disciplinary_violations;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS message_recipients;
DROP TABLE IF EXISTS messages;
DROP TABLE IF EXISTS grades;
DROP TABLE IF EXISTS recollection_attendance;
DROP TABLE IF EXISTS recollection_participants;
DROP TABLE IF EXISTS recollection_target_roles;
DROP TABLE IF EXISTS recollection_target_users;
DROP TABLE IF EXISTS recollections;
DROP TABLE IF EXISTS event_attendance;
DROP TABLE IF EXISTS event_participants;
DROP TABLE IF EXISTS event_target_groups;
DROP TABLE IF EXISTS event_images;
DROP TABLE IF EXISTS events;
DROP TABLE IF EXISTS attendance_records;
DROP TABLE IF EXISTS attendance_sessions;
DROP TABLE IF EXISTS teacher_time_off;
DROP TABLE IF EXISTS class_attendance_policies;
DROP TABLE IF EXISTS attendance_settings;
DROP TABLE IF EXISTS class_seat_assignments;
DROP TABLE IF EXISTS class_schedules;
DROP TABLE IF EXISTS teacher_assignments;
DROP TABLE IF EXISTS class_enrollments;
DROP TABLE IF EXISTS class_offerings;
DROP TABLE IF EXISTS courses;
DROP TABLE IF EXISTS semesters;
DROP TABLE IF EXISTS school_years;
DROP TABLE IF EXISTS room_seat_plan_cells;
DROP TABLE IF EXISTS room_seat_plans;
DROP TABLE IF EXISTS seat_plan_preset_cells;
DROP TABLE IF EXISTS seat_plan_presets;
DROP TABLE IF EXISTS devices;
DROP TABLE IF EXISTS rooms;
DROP TABLE IF EXISTS device_scan_logs;
DROP TABLE IF EXISTS rfid_registration_requests;
DROP TABLE IF EXISTS rfid_assignment_history;
DROP TABLE IF EXISTS rfid_cards;
DROP TABLE IF EXISTS user_roles;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS sections;
DROP TABLE IF EXISTS programs;
DROP TABLE IF EXISTS department_days_off;
DROP TABLE IF EXISTS departments;
DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS permissions;
DROP TABLE IF EXISTS roles;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(80) NOT NULL UNIQUE,
    description VARCHAR(255) NULL
) ENGINE=InnoDB;

CREATE TABLE role_permissions (
    role_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE departments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    color_key ENUM('green', 'yellow', 'grey', 'blue', 'orange', 'red', 'maroon') NOT NULL DEFAULT 'grey',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE department_days_off (
    department_id INT UNSIGNED NOT NULL,
    day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL,
    reason VARCHAR(150) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (department_id, day_of_week),
    INDEX idx_department_days_off_day (day_of_week),
    CONSTRAINT fk_department_days_off_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE programs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id INT UNSIGNED NOT NULL,
    code VARCHAR(30) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_programs_department FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB;

CREATE TABLE sections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    program_id INT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    year_level TINYINT UNSIGNED NOT NULL,
    section_code VARCHAR(10) NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sections_program_name (program_id, name),
    UNIQUE KEY uq_sections_program_year_code (program_id, year_level, section_code),
    CONSTRAINT chk_sections_year_level CHECK (year_level BETWEEN 1 AND 4),
    CONSTRAINT fk_sections_program FOREIGN KEY (program_id) REFERENCES programs(id)
) ENGINE=InnoDB;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_id VARCHAR(40) NOT NULL UNIQUE,
    first_name VARCHAR(80) NOT NULL,
    middle_name VARCHAR(80) NULL,
    last_name VARCHAR(80) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NULL,
    program_id INT UNSIGNED NULL,
    section_id INT UNSIGNED NULL,
    profile_image_path VARCHAR(255) NULL,
    profile_visibility ENUM('public', 'private') NOT NULL DEFAULT 'public',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_role (role_id),
    INDEX idx_users_section (section_id),
    INDEX idx_users_first_name (first_name),
    INDEX idx_users_name (last_name, first_name),
    INDEX idx_users_directory_filters (status, role_id, department_id, program_id, section_id),
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id),
    CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
    CONSTRAINT fk_users_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL,
    CONSTRAINT fk_users_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE user_preferences (
    user_id INT UNSIGNED NOT NULL PRIMARY KEY,
    start_page VARCHAR(24) NOT NULL DEFAULT 'home',
    theme_mode ENUM('system','light','dark') NOT NULL DEFAULT 'system',
    accent_theme ENUM('blue','green','purple','orange') NOT NULL DEFAULT 'blue',
    reduced_motion TINYINT(1) NOT NULL DEFAULT 0,
    compact_mode TINYINT(1) NOT NULL DEFAULT 0,
    push_enabled TINYINT(1) NOT NULL DEFAULT 1,
    notify_attendance TINYINT(1) NOT NULL DEFAULT 1,
    notify_events TINYINT(1) NOT NULL DEFAULT 1,
    notify_recollections TINYINT(1) NOT NULL DEFAULT 1,
    notify_messages TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_preferences_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE disciplinary_violations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    issued_by INT UNSIGNED NOT NULL,
    violation_group VARCHAR(120) NOT NULL,
    specific_violation VARCHAR(500) NOT NULL,
    classification ENUM('Category A','Category B','Category C') NOT NULL,
    is_progressive BOOLEAN NOT NULL DEFAULT FALSE,
    previous_infractions TINYINT UNSIGNED NULL,
    current_occurrence TINYINT UNSIGNED NULL,
    incident_date DATE NOT NULL,
    incident_time TIME NOT NULL,
    location VARCHAR(180) NOT NULL,
    incident_description TEXT NOT NULL,
    witnesses TEXT NULL,
    evidence_path VARCHAR(255) NULL,
    evidence_original_name VARCHAR(255) NULL,
    evidence_mime VARCHAR(100) NULL,
    evidence_size INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_disciplinary_student_date (student_id, incident_date),
    INDEX idx_disciplinary_classification (classification, incident_date),
    CONSTRAINT fk_disciplinary_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE RESTRICT,
    CONSTRAINT fk_disciplinary_issuer FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE user_roles (
    user_id INT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    INDEX idx_user_roles_role_user (role_id, user_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE rfid_cards (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uid VARCHAR(32) NOT NULL UNIQUE,
    user_id INT UNSIGNED NULL UNIQUE,
    status ENUM('active', 'inactive', 'replaced') NOT NULL DEFAULT 'active',
    assigned_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_rfid_cards_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE rfid_assignment_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rfid_card_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    action ENUM('assigned', 'replaced', 'deactivated') NOT NULL,
    performed_by INT UNSIGNED NOT NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rfid_history_card FOREIGN KEY (rfid_card_id) REFERENCES rfid_cards(id),
    CONSTRAINT fk_rfid_history_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_rfid_history_actor FOREIGN KEY (performed_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE rooms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_code VARCHAR(40) NOT NULL UNIQUE,
    room_name VARCHAR(120) NOT NULL,
    building VARCHAR(120) NULL,
    floor VARCHAR(30) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE seat_plan_presets (
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

CREATE TABLE seat_plan_preset_cells (
    preset_id INT UNSIGNED NOT NULL,
    grid_row TINYINT UNSIGNED NOT NULL,
    grid_column TINYINT UNSIGNED NOT NULL,
    cell_type ENUM('student_seat', 'teacher_table', 'label') NOT NULL DEFAULT 'student_seat',
    label VARCHAR(80) NOT NULL DEFAULT 'Student Seat',
    PRIMARY KEY (preset_id, grid_row, grid_column),
    CONSTRAINT fk_seat_plan_preset_cells_preset FOREIGN KEY (preset_id) REFERENCES seat_plan_presets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE room_seat_plans (
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

CREATE TABLE room_seat_plan_cells (
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

CREATE TABLE devices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_code VARCHAR(60) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    room_id INT UNSIGNED NULL,
    api_key_hash VARCHAR(255) NULL,
    status ENUM('active', 'inactive', 'maintenance') NOT NULL DEFAULT 'active',
    last_seen_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_devices_room_status (room_id, status),
    CONSTRAINT fk_devices_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE rfid_registration_requests (
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

CREATE TABLE school_years (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(30) NOT NULL UNIQUE,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    status ENUM('upcoming', 'active', 'closed') NOT NULL DEFAULT 'upcoming'
) ENGINE=InnoDB;

CREATE TABLE semesters (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    school_year_id INT UNSIGNED NOT NULL,
    name VARCHAR(60) NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    status ENUM('upcoming', 'active', 'closed') NOT NULL DEFAULT 'upcoming',
    UNIQUE KEY uq_semesters_year_name (school_year_id, name),
    CONSTRAINT fk_semesters_year FOREIGN KEY (school_year_id) REFERENCES school_years(id)
) ENGINE=InnoDB;

CREATE TABLE courses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id INT UNSIGNED NULL,
    course_code VARCHAR(30) NOT NULL UNIQUE,
    title VARCHAR(150) NOT NULL,
    units DECIMAL(3,1) NULL DEFAULT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_courses_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE class_offerings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id INT UNSIGNED NOT NULL,
    section_id INT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    component_type ENUM('LEC', 'LAB') NOT NULL DEFAULT 'LEC',
    room_id INT UNSIGNED NULL,
    status ENUM('active', 'completed', 'cancelled') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_offering_component (course_id, section_id, semester_id, component_type),
    CONSTRAINT fk_offerings_course FOREIGN KEY (course_id) REFERENCES courses(id),
    CONSTRAINT fk_offerings_section FOREIGN KEY (section_id) REFERENCES sections(id),
    CONSTRAINT fk_offerings_semester FOREIGN KEY (semester_id) REFERENCES semesters(id),
    CONSTRAINT fk_offerings_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE class_enrollments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_offering_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    status ENUM('enrolled', 'dropped', 'completed') NOT NULL DEFAULT 'enrolled',
    enrolled_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_class_enrollment (class_offering_id, student_id),
    CONSTRAINT fk_enrollments_offering FOREIGN KEY (class_offering_id) REFERENCES class_offerings(id) ON DELETE CASCADE,
    CONSTRAINT fk_enrollments_student FOREIGN KEY (student_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE teacher_assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_offering_id INT UNSIGNED NOT NULL,
    teacher_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_teacher_assignment (class_offering_id, teacher_id),
    CONSTRAINT fk_teacher_assignments_offering FOREIGN KEY (class_offering_id) REFERENCES class_offerings(id) ON DELETE CASCADE,
    CONSTRAINT fk_teacher_assignments_teacher FOREIGN KEY (teacher_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE teacher_time_off (
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

CREATE TABLE class_seat_assignments (
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

CREATE TABLE class_schedules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_offering_id INT UNSIGNED NOT NULL,
    room_id INT UNSIGNED NULL,
    day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    CONSTRAINT fk_schedules_offering FOREIGN KEY (class_offering_id) REFERENCES class_offerings(id) ON DELETE CASCADE,
    CONSTRAINT fk_schedules_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE attendance_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    early_check_in_minutes TINYINT UNSIGNED NOT NULL DEFAULT 10,
    grace_period_minutes TINYINT UNSIGNED NOT NULL DEFAULT 15,
    absent_after_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    tardies_per_absence TINYINT UNSIGNED NOT NULL DEFAULT 3,
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_attendance_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE class_attendance_policies (
    class_offering_id INT UNSIGNED PRIMARY KEY,
    early_check_in_minutes TINYINT UNSIGNED NOT NULL DEFAULT 10,
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

CREATE TABLE attendance_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_offering_id INT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    finalized_at DATETIME NULL,
    finalized_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_session (class_offering_id, attendance_date),
    CONSTRAINT fk_attendance_sessions_offering FOREIGN KEY (class_offering_id) REFERENCES class_offerings(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_sessions_user FOREIGN KEY (finalized_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE attendance_records (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attendance_session_id BIGINT UNSIGNED NULL,
    class_offering_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    time_in TIME NULL,
    time_out TIME NULL,
    status ENUM('Present', 'Late', 'Absent', 'Excused') NOT NULL,
    source ENUM('rfid', 'manual', 'system') NOT NULL,
    recorded_by INT UNSIGNED NULL,
    notes VARCHAR(255) NULL,
    correction_reason VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_attendance_record (class_offering_id, student_id, attendance_date),
    INDEX idx_attendance_student_date (student_id, attendance_date),
    CONSTRAINT fk_attendance_record_session FOREIGN KEY (attendance_session_id) REFERENCES attendance_sessions(id) ON DELETE SET NULL,
    CONSTRAINT fk_attendance_record_offering FOREIGN KEY (class_offering_id) REFERENCES class_offerings(id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_record_student FOREIGN KEY (student_id) REFERENCES users(id),
    CONSTRAINT fk_attendance_record_actor FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE device_scan_logs (
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

CREATE TABLE events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    event_date DATE NOT NULL,
    end_date DATE NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    venue VARCHAR(180) NOT NULL,
    banner_image_path VARCHAR(255) NULL,
    attendance_required BOOLEAN NOT NULL DEFAULT TRUE,
    suspends_classes BOOLEAN NOT NULL DEFAULT FALSE,
    check_in_start TIME NULL,
    check_in_end TIME NULL,
    check_out_required BOOLEAN NOT NULL DEFAULT FALSE,
    status ENUM('draft', 'scheduled', 'open', 'closed', 'cancelled') NOT NULL DEFAULT 'draft',
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_events_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE event_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    caption VARCHAR(255) NULL,
    display_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_event_images_order (event_id, display_order, id),
    CONSTRAINT fk_event_images_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE event_scanners (
    event_id INT UNSIGNED NOT NULL,
    device_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (event_id, device_id),
    INDEX idx_event_scanners_device (device_id, event_id),
    CONSTRAINT fk_event_scanners_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_scanners_device FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE event_target_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    target_type ENUM('everyone', 'all_students', 'all_teachers', 'all_staff', 'department', 'program', 'section', 'user') NOT NULL,
    target_id INT UNSIGNED NULL,
    CONSTRAINT fk_event_targets_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE event_participants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    is_required BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE KEY uq_event_participant (event_id, user_id),
    CONSTRAINT fk_event_participants_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_participants_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE event_attendance (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    check_in_at DATETIME NULL,
    check_out_at DATETIME NULL,
    status ENUM('Attended', 'Late', 'Absent', 'Excused', 'Incomplete') NOT NULL,
    source ENUM('rfid', 'manual', 'system') NOT NULL,
    recorded_by INT UNSIGNED NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_event_attendance (event_id, user_id),
    CONSTRAINT fk_event_attendance_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
    CONSTRAINT fk_event_attendance_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_event_attendance_actor FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE recollections (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    type ENUM('Recollection', 'Retreat') NOT NULL,
    section_id INT UNSIGNED NOT NULL,
    date DATE NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    venue VARCHAR(180) NOT NULL,
    facilitator VARCHAR(180) NULL,
    attendance_required BOOLEAN NOT NULL DEFAULT TRUE,
    status ENUM('draft', 'scheduled', 'open', 'closed', 'cancelled') NOT NULL DEFAULT 'scheduled',
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_recollections_section FOREIGN KEY (section_id) REFERENCES sections(id),
    CONSTRAINT fk_recollections_creator FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE recollection_target_users (
    recollection_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (recollection_id, user_id),
    INDEX idx_recollection_target_users_user (user_id, recollection_id),
    CONSTRAINT fk_recollection_target_users_recollection FOREIGN KEY (recollection_id) REFERENCES recollections(id) ON DELETE CASCADE,
    CONSTRAINT fk_recollection_target_users_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE recollection_target_roles (
    recollection_id INT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (recollection_id, role_id),
    INDEX idx_recollection_target_roles_role (role_id, recollection_id),
    CONSTRAINT fk_recollection_target_roles_recollection FOREIGN KEY (recollection_id) REFERENCES recollections(id) ON DELETE CASCADE,
    CONSTRAINT fk_recollection_target_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE recollection_participants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recollection_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_recollection_participant (recollection_id, user_id),
    CONSTRAINT fk_recollection_participants_recollection FOREIGN KEY (recollection_id) REFERENCES recollections(id) ON DELETE CASCADE,
    CONSTRAINT fk_recollection_participants_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE recollection_attendance (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recollection_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    time_in DATETIME NULL,
    time_out DATETIME NULL,
    status ENUM('Attended', 'Late', 'Absent', 'Excused') NOT NULL,
    source ENUM('rfid', 'manual', 'system') NOT NULL,
    recorded_by INT UNSIGNED NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_recollection_attendance (recollection_id, user_id),
    CONSTRAINT fk_recollection_attendance_recollection FOREIGN KEY (recollection_id) REFERENCES recollections(id) ON DELETE CASCADE,
    CONSTRAINT fk_recollection_attendance_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_recollection_attendance_actor FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE grades (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    class_offering_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    prelim DECIMAL(5,2) NULL,
    midterm DECIMAL(5,2) NULL,
    final DECIMAL(5,2) NULL,
    overall DECIMAL(5,2) NULL,
    status ENUM('draft', 'finalized') NOT NULL DEFAULT 'draft',
    entered_by INT UNSIGNED NOT NULL,
    finalized_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_grade (class_offering_id, student_id),
    CONSTRAINT fk_grades_offering FOREIGN KEY (class_offering_id) REFERENCES class_offerings(id) ON DELETE CASCADE,
    CONSTRAINT fk_grades_student FOREIGN KEY (student_id) REFERENCES users(id),
    CONSTRAINT fk_grades_actor FOREIGN KEY (entered_by) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sender_id INT UNSIGNED NOT NULL,
    subject VARCHAR(180) NOT NULL,
    body TEXT NOT NULL,
    is_announcement BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE message_recipients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    read_at DATETIME NULL,
    UNIQUE KEY uq_message_recipient (message_id, user_id),
    CONSTRAINT fk_message_recipients_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
    CONSTRAINT fk_message_recipients_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    entity_id VARCHAR(80) NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_entity (entity_type, entity_id),
    CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;


-- Additive; safe to rerun. Existing attendance is not mass-notified on installation.
SET time_zone = '+08:00';
CREATE TABLE IF NOT EXISTS notification_settings (
 id TINYINT UNSIGNED PRIMARY KEY,
 enabled_at DATETIME NOT NULL
) ENGINE=InnoDB;
INSERT IGNORE INTO notification_settings VALUES (1, NOW());

CREATE TABLE IF NOT EXISTS notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 event_key VARCHAR(120) NOT NULL,
 fingerprint CHAR(64) NOT NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 kind VARCHAR(40) NOT NULL,
 title VARCHAR(180) NOT NULL,
 body TEXT NOT NULL,
 target_type VARCHAR(30) NOT NULL,
 target_id BIGINT UNSIGNED NOT NULL,
 expires_at DATETIME NULL,
 read_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_notification_user_event (user_id,event_key),
 INDEX idx_notification_inbox (user_id,updated_at,id),
 CONSTRAINT fk_notification_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notification_devices (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 token_hash CHAR(64) NOT NULL UNIQUE,
 token TEXT NOT NULL,
 session_hash CHAR(64) NOT NULL,
 active BOOLEAN NOT NULL DEFAULT 1,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_notification_device_user (user_id,active),
 CONSTRAINT fk_notification_device_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notification_outbox (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 notification_id BIGINT UNSIGNED NOT NULL,
 revision INT UNSIGNED NOT NULL,
 device_id BIGINT UNSIGNED NOT NULL,
 attempts INT UNSIGNED NOT NULL DEFAULT 0,
 available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 status VARCHAR(20) NOT NULL DEFAULT 'pending',
 last_error VARCHAR(100) NULL,
 UNIQUE KEY uq_notification_delivery (notification_id,revision,device_id),
 INDEX idx_notification_outbox_due (status,available_at),
 CONSTRAINT fk_notification_outbox_item FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
 CONSTRAINT fk_notification_outbox_device FOREIGN KEY (device_id) REFERENCES notification_devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
