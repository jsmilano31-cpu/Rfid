CREATE TABLE `attendance_records` (

  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `attendance_session_id` bigint(20) unsigned DEFAULT NULL,
  `class_offering_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `attendance_date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `status` enum('Present','Late','Absent','Excused') NOT NULL,
  `source` enum('rfid','manual','system') NOT NULL,
  `recorded_by` int(10) unsigned DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `correction_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_record` (`class_offering_id`,`student_id`,`attendance_date`),
  KEY `idx_attendance_student_date` (`student_id`,`attendance_date`),
  KEY `fk_attendance_record_session` (`attendance_session_id`),
  KEY `fk_attendance_record_actor` (`recorded_by`),
  CONSTRAINT `fk_attendance_record_actor` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_attendance_record_offering` FOREIGN KEY (`class_offering_id`) REFERENCES `class_offerings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_record_session` FOREIGN KEY (`attendance_session_id`) REFERENCES `attendance_sessions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_attendance_record_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`)

);

CREATE TABLE `attendance_sessions` (

  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `class_offering_id` int(10) unsigned NOT NULL,
  `attendance_date` date NOT NULL,
  `finalized_at` datetime DEFAULT NULL,
  `finalized_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_attendance_session` (`class_offering_id`,`attendance_date`),
  KEY `fk_attendance_sessions_user` (`finalized_by`),
  CONSTRAINT `fk_attendance_sessions_offering` FOREIGN KEY (`class_offering_id`) REFERENCES `class_offerings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_attendance_sessions_user` FOREIGN KEY (`finalized_by`) REFERENCES `users` (`id`) ON DELETE SET NULL

);

CREATE TABLE `attendance_settings` (

  `id` tinyint(3) unsigned NOT NULL,
  `early_check_in_minutes` tinyint(3) unsigned NOT NULL DEFAULT 10,
  `grace_period_minutes` tinyint(3) unsigned NOT NULL DEFAULT 15,
  `absent_after_minutes` smallint(5) unsigned NOT NULL DEFAULT 30,
  `tardies_per_absence` tinyint(3) unsigned NOT NULL DEFAULT 3,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_attendance_settings_user` (`updated_by`),
  CONSTRAINT `fk_attendance_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL

);

CREATE TABLE `audit_logs` (

  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(100) NOT NULL,
  `entity_id` varchar(80) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  KEY `fk_audit_logs_user` (`user_id`),
  CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL

);

CREATE TABLE `class_attendance_policies` (

  `class_offering_id` int(10) unsigned NOT NULL,
  `grace_period_minutes` tinyint(3) unsigned NOT NULL DEFAULT 15,
  `absent_after_minutes` smallint(5) unsigned NOT NULL DEFAULT 30,
  `tardies_per_absence` tinyint(3) unsigned NOT NULL DEFAULT 3,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`class_offering_id`),
  KEY `fk_class_policy_user` (`updated_by`),
  CONSTRAINT `fk_class_policy_offering` FOREIGN KEY (`class_offering_id`) REFERENCES `class_offerings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_class_policy_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_class_policy_window` CHECK (`absent_after_minutes` > `grace_period_minutes`)

);

CREATE TABLE `class_enrollments` (

  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `class_offering_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `status` enum('enrolled','dropped','completed') NOT NULL DEFAULT 'enrolled',
  `enrolled_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_class_enrollment` (`class_offering_id`,`student_id`),
  KEY `fk_enrollments_student` (`student_id`),
  CONSTRAINT `fk_enrollments_offering` FOREIGN KEY (`class_offering_id`) REFERENCES `class_offerings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_enrollments_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`)

);

CREATE TABLE `class_offerings` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `course_id` int(10) unsigned NOT NULL,
  `section_id` int(10) unsigned NOT NULL,
  `semester_id` int(10) unsigned NOT NULL,
  `component_type` enum('LEC','LAB') NOT NULL DEFAULT 'LEC',
  `room_id` int(10) unsigned DEFAULT NULL,
  `status` enum('active','completed','cancelled') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_offering_component` (`course_id`,`section_id`,`semester_id`,`component_type`),
  KEY `fk_offerings_section` (`section_id`),
  KEY `fk_offerings_semester` (`semester_id`),
  KEY `fk_offerings_room` (`room_id`),
  CONSTRAINT `fk_offerings_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`),
  CONSTRAINT `fk_offerings_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_offerings_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`),
  CONSTRAINT `fk_offerings_semester` FOREIGN KEY (`semester_id`) REFERENCES `semesters` (`id`)

);

CREATE TABLE `class_schedules` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_offering_id` int(10) unsigned NOT NULL,
  `room_id` int(10) unsigned DEFAULT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_schedules_offering` (`class_offering_id`),
  KEY `fk_schedules_room` (`room_id`),
  CONSTRAINT `fk_schedules_offering` FOREIGN KEY (`class_offering_id`) REFERENCES `class_offerings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_schedules_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL

);

CREATE TABLE `class_seat_assignments` (

  `class_offering_id` int(10) unsigned NOT NULL,
  `seat_plan_id` int(10) unsigned NOT NULL,
  `grid_row` tinyint(3) unsigned NOT NULL,
  `grid_column` tinyint(3) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `assigned_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`class_offering_id`,`seat_plan_id`,`grid_row`,`grid_column`),
  UNIQUE KEY `uq_class_seat_student` (`class_offering_id`,`seat_plan_id`,`student_id`),
  KEY `idx_class_seat_student` (`student_id`),
  KEY `fk_class_seat_plan` (`seat_plan_id`),
  KEY `fk_class_seat_actor` (`assigned_by`),
  CONSTRAINT `fk_class_seat_actor` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_class_seat_offering` FOREIGN KEY (`class_offering_id`) REFERENCES `class_offerings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_class_seat_plan` FOREIGN KEY (`seat_plan_id`) REFERENCES `room_seat_plans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_class_seat_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE CASCADE

);

CREATE TABLE `courses` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `department_id` int(10) unsigned DEFAULT NULL,
  `course_code` varchar(30) NOT NULL,
  `title` varchar(150) NOT NULL,
  `units` decimal(3,1) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `course_code` (`course_code`),
  KEY `fk_courses_department` (`department_id`),
  CONSTRAINT `fk_courses_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL

);

CREATE TABLE `department_days_off` (

  `department_id` int(10) unsigned NOT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `reason` varchar(150) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`department_id`,`day_of_week`),
  KEY `idx_department_days_off_day` (`day_of_week`),
  CONSTRAINT `fk_department_days_off_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE

);

CREATE TABLE `departments` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(30) NOT NULL,
  `name` varchar(150) NOT NULL,
  `color_key` enum('green','yellow','grey','blue','orange','red','maroon') NOT NULL DEFAULT 'grey',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)

);

CREATE TABLE `devices` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `device_code` varchar(60) NOT NULL,
  `name` varchar(120) NOT NULL,
  `room_id` int(10) unsigned DEFAULT NULL,
  `api_key_hash` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive','maintenance') NOT NULL DEFAULT 'active',
  `last_seen_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_code` (`device_code`),
  KEY `idx_devices_room_status` (`room_id`,`status`),
  CONSTRAINT `fk_devices_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL

);

CREATE TABLE `event_attendance` (

  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `check_in_at` datetime DEFAULT NULL,
  `check_out_at` datetime DEFAULT NULL,
  `status` enum('Attended','Late','Absent','Excused','Incomplete') NOT NULL,
  `source` enum('rfid','manual','system') NOT NULL,
  `recorded_by` int(10) unsigned DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_attendance` (`event_id`,`user_id`),
  KEY `fk_event_attendance_user` (`user_id`),
  KEY `fk_event_attendance_actor` (`recorded_by`),
  CONSTRAINT `fk_event_attendance_actor` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_event_attendance_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_event_attendance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)

);

CREATE TABLE `event_images` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int(10) unsigned NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `caption` varchar(255) DEFAULT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_event_images_event` (`event_id`),
  CONSTRAINT `fk_event_images_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE

);

CREATE TABLE `event_participants` (

  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_event_participant` (`event_id`,`user_id`),
  KEY `fk_event_participants_user` (`user_id`),
  CONSTRAINT `fk_event_participants_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_event_participants_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE

);

CREATE TABLE `event_target_groups` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` int(10) unsigned NOT NULL,
  `target_type` enum('everyone','all_students','all_teachers','all_staff','department','program','section','user') NOT NULL,
  `target_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_event_targets_event` (`event_id`),
  CONSTRAINT `fk_event_targets_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE

);

CREATE TABLE `events` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(180) NOT NULL,
  `description` text DEFAULT NULL,
  `event_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `venue` varchar(180) NOT NULL,
  `banner_image_path` varchar(255) DEFAULT NULL,
  `attendance_required` tinyint(1) NOT NULL DEFAULT 1,
  `suspends_classes` tinyint(1) NOT NULL DEFAULT 0,
  `check_in_start` time DEFAULT NULL,
  `check_in_end` time DEFAULT NULL,
  `check_out_required` tinyint(1) NOT NULL DEFAULT 0,
  `is_announcement` tinyint(1) NOT NULL DEFAULT 0,
  `status` enum('draft','scheduled','open','closed','cancelled') NOT NULL DEFAULT 'draft',
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_events_creator` (`created_by`),
  CONSTRAINT `fk_events_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)

);

CREATE TABLE `event_scanners` (
  `event_id` int(10) unsigned NOT NULL,
  `device_id` int(10) unsigned NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`event_id`,`device_id`),
  KEY `idx_event_scanners_device` (`device_id`,`event_id`),
  CONSTRAINT `fk_event_scanners_event` FOREIGN KEY (`event_id`) REFERENCES `events` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_event_scanners_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE
);

CREATE TABLE `grades` (

  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `class_offering_id` int(10) unsigned NOT NULL,
  `student_id` int(10) unsigned NOT NULL,
  `prelim` decimal(5,2) DEFAULT NULL,
  `midterm` decimal(5,2) DEFAULT NULL,
  `final` decimal(5,2) DEFAULT NULL,
  `overall` decimal(5,2) DEFAULT NULL,
  `status` enum('draft','finalized') NOT NULL DEFAULT 'draft',
  `entered_by` int(10) unsigned NOT NULL,
  `finalized_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_grade` (`class_offering_id`,`student_id`),
  KEY `fk_grades_student` (`student_id`),
  KEY `fk_grades_actor` (`entered_by`),
  CONSTRAINT `fk_grades_actor` FOREIGN KEY (`entered_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_grades_offering` FOREIGN KEY (`class_offering_id`) REFERENCES `class_offerings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_grades_student` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`)

);

CREATE TABLE `grading_settings` (

  `id` int(10) unsigned NOT NULL,
  `has_prelim` tinyint(1) NOT NULL DEFAULT 0,
  `has_midterm` tinyint(1) NOT NULL DEFAULT 1,
  `has_final` tinyint(1) NOT NULL DEFAULT 1,
  `prelim_weight` decimal(5,2) NOT NULL DEFAULT 0.00,
  `midterm_weight` decimal(5,2) NOT NULL DEFAULT 40.00,
  `final_weight` decimal(5,2) NOT NULL DEFAULT 60.00,
  `passing_grade` decimal(5,2) NOT NULL DEFAULT 75.00,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_grading_settings_user` (`updated_by`),
  CONSTRAINT `fk_grading_settings_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL

);

CREATE TABLE `message_recipients` (

  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `message_id` bigint(20) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_message_recipient` (`message_id`,`user_id`),
  KEY `fk_message_recipients_user` (`user_id`),
  CONSTRAINT `fk_message_recipients_message` FOREIGN KEY (`message_id`) REFERENCES `messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_message_recipients_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE

);

CREATE TABLE `messages` (

  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sender_id` int(10) unsigned NOT NULL,
  `subject` varchar(180) NOT NULL,
  `body` text NOT NULL,
  `is_announcement` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_messages_sender` (`sender_id`),
  CONSTRAINT `fk_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`)

);

CREATE TABLE `permissions` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(80) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)

);

CREATE TABLE `programs` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `department_id` int(10) unsigned NOT NULL,
  `code` varchar(30) NOT NULL,
  `name` varchar(150) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `fk_programs_department` (`department_id`),
  CONSTRAINT `fk_programs_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)

);

CREATE TABLE `recollection_attendance` (

  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `recollection_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `time_in` datetime DEFAULT NULL,
  `time_out` datetime DEFAULT NULL,
  `status` enum('Attended','Late','Absent','Excused') NOT NULL,
  `source` enum('rfid','manual','system') NOT NULL,
  `recorded_by` int(10) unsigned DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_recollection_attendance` (`recollection_id`,`user_id`),
  KEY `fk_recollection_attendance_user` (`user_id`),
  KEY `fk_recollection_attendance_actor` (`recorded_by`),
  CONSTRAINT `fk_recollection_attendance_actor` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_recollection_attendance_recollection` FOREIGN KEY (`recollection_id`) REFERENCES `recollections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_recollection_attendance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)

);

CREATE TABLE `recollection_participants` (

  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `recollection_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_recollection_participant` (`recollection_id`,`user_id`),
  KEY `fk_recollection_participants_user` (`user_id`),
  CONSTRAINT `fk_recollection_participants_recollection` FOREIGN KEY (`recollection_id`) REFERENCES `recollections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_recollection_participants_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE

);

CREATE TABLE `recollection_target_roles` (

  `recollection_id` int(10) unsigned NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`recollection_id`,`role_id`),
  KEY `idx_recollection_target_roles_role` (`role_id`,`recollection_id`),
  CONSTRAINT `fk_recollection_target_roles_recollection` FOREIGN KEY (`recollection_id`) REFERENCES `recollections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_recollection_target_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE

);

CREATE TABLE `recollection_target_users` (

  `recollection_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`recollection_id`,`user_id`),
  KEY `idx_recollection_target_users_user` (`user_id`,`recollection_id`),
  CONSTRAINT `fk_recollection_target_users_recollection` FOREIGN KEY (`recollection_id`) REFERENCES `recollections` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_recollection_target_users_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE

);

CREATE TABLE `recollections` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(180) NOT NULL,
  `type` enum('Recollection','Retreat') NOT NULL,
  `section_id` int(10) unsigned NOT NULL,
  `date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `venue` varchar(180) NOT NULL,
  `facilitator` varchar(180) DEFAULT NULL,
  `attendance_required` tinyint(1) NOT NULL DEFAULT 1,
  `status` enum('draft','scheduled','open','closed','cancelled') NOT NULL DEFAULT 'scheduled',
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_recollections_section` (`section_id`),
  KEY `fk_recollections_creator` (`created_by`),
  CONSTRAINT `fk_recollections_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_recollections_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`)

);

CREATE TABLE `rfid_assignment_history` (

  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rfid_card_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `action` enum('assigned','replaced','deactivated') NOT NULL,
  `performed_by` int(10) unsigned NOT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_rfid_history_card` (`rfid_card_id`),
  KEY `fk_rfid_history_user` (`user_id`),
  KEY `fk_rfid_history_actor` (`performed_by`),
  CONSTRAINT `fk_rfid_history_actor` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_rfid_history_card` FOREIGN KEY (`rfid_card_id`) REFERENCES `rfid_cards` (`id`),
  CONSTRAINT `fk_rfid_history_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)

);

CREATE TABLE `rfid_cards` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `uid` varchar(32) NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `status` enum('active','inactive','replaced') NOT NULL DEFAULT 'active',
  `assigned_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid` (`uid`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `fk_rfid_cards_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL

);

CREATE TABLE `role_permissions` (

  `role_id` int(10) unsigned NOT NULL,
  `permission_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `fk_role_permissions_permission` (`permission_id`),
  CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE

);

CREATE TABLE `roles` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(80) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)

);

CREATE TABLE `room_seat_plan_cells` (

  `seat_plan_id` int(10) unsigned NOT NULL,
  `grid_row` tinyint(3) unsigned NOT NULL,
  `grid_column` tinyint(3) unsigned NOT NULL,
  `cell_type` enum('student_seat','teacher_table','label') NOT NULL DEFAULT 'student_seat',
  `label` varchar(80) NOT NULL DEFAULT 'Student Seat',
  `assigned_student_id` int(10) unsigned DEFAULT NULL,
  `occupied_at` datetime DEFAULT NULL,
  PRIMARY KEY (`seat_plan_id`,`grid_row`,`grid_column`),
  KEY `idx_room_seat_plan_student` (`assigned_student_id`),
  CONSTRAINT `fk_room_seat_plan_cells_plan` FOREIGN KEY (`seat_plan_id`) REFERENCES `room_seat_plans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_room_seat_plan_cells_student` FOREIGN KEY (`assigned_student_id`) REFERENCES `users` (`id`) ON DELETE SET NULL

);

CREATE TABLE `room_seat_plans` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `room_id` int(10) unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `row_count` tinyint(3) unsigned NOT NULL DEFAULT 10,
  `column_count` tinyint(3) unsigned NOT NULL DEFAULT 10,
  `based_on_preset_id` int(10) unsigned DEFAULT NULL,
  `updated_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_id` (`room_id`),
  KEY `fk_room_seat_plans_preset` (`based_on_preset_id`),
  KEY `fk_room_seat_plans_updater` (`updated_by`),
  CONSTRAINT `fk_room_seat_plans_preset` FOREIGN KEY (`based_on_preset_id`) REFERENCES `seat_plan_presets` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_room_seat_plans_room` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_room_seat_plans_updater` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_room_seat_plan_rows` CHECK (`row_count` between 1 and 20),
  CONSTRAINT `chk_room_seat_plan_columns` CHECK (`column_count` between 1 and 20)

);

CREATE TABLE `rooms` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `room_code` varchar(40) NOT NULL,
  `room_name` varchar(120) NOT NULL,
  `building` varchar(120) DEFAULT NULL,
  `floor` varchar(30) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `room_code` (`room_code`)

);

CREATE TABLE `school_years` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(30) NOT NULL,
  `starts_on` date NOT NULL,
  `ends_on` date NOT NULL,
  `status` enum('upcoming','active','closed') NOT NULL DEFAULT 'upcoming',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)

);

CREATE TABLE `seat_plan_preset_cells` (

  `preset_id` int(10) unsigned NOT NULL,
  `grid_row` tinyint(3) unsigned NOT NULL,
  `grid_column` tinyint(3) unsigned NOT NULL,
  `cell_type` enum('student_seat','teacher_table','label') NOT NULL DEFAULT 'student_seat',
  `label` varchar(80) NOT NULL DEFAULT 'Student Seat',
  PRIMARY KEY (`preset_id`,`grid_row`,`grid_column`),
  CONSTRAINT `fk_seat_plan_preset_cells_preset` FOREIGN KEY (`preset_id`) REFERENCES `seat_plan_presets` (`id`) ON DELETE CASCADE

);

CREATE TABLE `seat_plan_presets` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `row_count` tinyint(3) unsigned NOT NULL DEFAULT 10,
  `column_count` tinyint(3) unsigned NOT NULL DEFAULT 10,
  `visibility` enum('campus','personal') NOT NULL DEFAULT 'personal',
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_seat_plan_preset_owner_name` (`created_by`,`name`),
  KEY `idx_seat_plan_presets_visibility` (`visibility`,`created_by`),
  CONSTRAINT `fk_seat_plan_presets_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `chk_seat_plan_preset_rows` CHECK (`row_count` between 1 and 20),
  CONSTRAINT `chk_seat_plan_preset_columns` CHECK (`column_count` between 1 and 20)

);

CREATE TABLE `sections` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `program_id` int(10) unsigned NOT NULL,
  `name` varchar(80) NOT NULL,
  `year_level` tinyint(3) unsigned NOT NULL,
  `section_code` varchar(10) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sections_program_name` (`program_id`,`name`),
  UNIQUE KEY `uq_sections_program_year_code` (`program_id`,`year_level`,`section_code`),
  CONSTRAINT `fk_sections_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`),
  CONSTRAINT `chk_sections_year_level` CHECK (`year_level` between 1 and 4)

);

CREATE TABLE `semesters` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `school_year_id` int(10) unsigned NOT NULL,
  `name` varchar(60) NOT NULL,
  `starts_on` date NOT NULL,
  `ends_on` date NOT NULL,
  `status` enum('upcoming','active','closed') NOT NULL DEFAULT 'upcoming',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_semesters_year_name` (`school_year_id`,`name`),
  CONSTRAINT `fk_semesters_year` FOREIGN KEY (`school_year_id`) REFERENCES `school_years` (`id`)

);

CREATE TABLE `teacher_assignments` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `class_offering_id` int(10) unsigned NOT NULL,
  `teacher_id` int(10) unsigned NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_teacher_assignment` (`class_offering_id`,`teacher_id`),
  KEY `fk_teacher_assignments_teacher` (`teacher_id`),
  CONSTRAINT `fk_teacher_assignments_offering` FOREIGN KEY (`class_offering_id`) REFERENCES `class_offerings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_teacher_assignments_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`)

);

CREATE TABLE `teacher_time_off` (

  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `teacher_id` int(10) unsigned NOT NULL,
  `starts_on` date NOT NULL,
  `ends_on` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('active','cancelled') NOT NULL DEFAULT 'active',
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_teacher_time_off_lookup` (`teacher_id`,`status`,`starts_on`,`ends_on`),
  KEY `fk_teacher_time_off_creator` (`created_by`),
  CONSTRAINT `fk_teacher_time_off_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_teacher_time_off_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_teacher_time_off_dates` CHECK (`ends_on` >= `starts_on`)

);

CREATE TABLE `user_roles` (

  `user_id` int(10) unsigned NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`user_id`,`role_id`),
  KEY `idx_user_roles_role_user` (`role_id`,`user_id`),
  CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE

);

CREATE TABLE `users` (

  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `school_id` varchar(40) NOT NULL,
  `first_name` varchar(80) NOT NULL,
  `middle_name` varchar(80) DEFAULT NULL,
  `last_name` varchar(80) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  `department_id` int(10) unsigned DEFAULT NULL,
  `program_id` int(10) unsigned DEFAULT NULL,
  `section_id` int(10) unsigned DEFAULT NULL,
  `profile_image_path` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `school_id` (`school_id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_users_role` (`role_id`),
  KEY `idx_users_section` (`section_id`),
  KEY `idx_users_name` (`last_name`,`first_name`),
  KEY `idx_users_directory_filters` (`status`,`role_id`,`department_id`,`program_id`,`section_id`),
  KEY `fk_users_department` (`department_id`),
  KEY `fk_users_program` (`program_id`),
  KEY `idx_users_first_name` (`first_name`),
  CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `fk_users_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL

);
