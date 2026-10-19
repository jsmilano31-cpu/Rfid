SET NAMES utf8mb4;

INSERT INTO roles (id, name, description) VALUES
    (1, 'Administrator', 'Full system administration access'),
    (2, 'Teacher', 'Assigned class attendance and grade management'),
    (3, 'Student', 'Self-service academic and attendance access'),
    (4, 'Staff', 'Operational support permissions'),
    (5, 'Event Coordinator', 'School event and event attendance management');

INSERT INTO permissions (id, code, description) VALUES
    (1, 'manage_users', 'Create and update user accounts'),
    (2, 'manage_roles', 'Manage roles and permissions'),
    (3, 'manage_rfid', 'Manage RFID cards and assignments'),
    (4, 'manage_rooms', 'Manage rooms and scanner devices'),
    (5, 'manage_academic_structure', 'Manage courses, sections, and terms'),
    (6, 'manage_teacher_assignments', 'Assign teachers to classes'),
    (7, 'manage_events', 'Manage school-wide events'),
    (8, 'manage_recollections', 'Manage recollections and retreats'),
    (9, 'manage_attendance', 'Create and correct attendance'),
    (10, 'view_attendance', 'View permitted attendance records'),
    (11, 'manage_grades', 'Enter and finalize grades'),
    (12, 'view_grades', 'View permitted grades'),
    (13, 'manage_compliance', 'Manage attendance compliance'),
    (14, 'view_compliance', 'View permitted compliance records'),
    (15, 'send_messages', 'Send messages and announcements'),
    (16, 'manage_reports', 'View and print operational reports'),
    (17, 'manage_seat_plans', 'Create room seat plans and reusable presets'),
    (18, 'manage_attendance_policies', 'Configure campus and assigned-subject attendance rules'),
    (19, 'manage_teacher_time_off', 'Schedule personal teaching time off and class suspensions'),
    (20, 'manage_academic_calendar', 'Configure school years, academic terms, and department weekly schedules'),
    (21, 'manage_grading_settings', 'Configure institutional grading terms, weights, and passing criteria'),
    (22, 'view_audit_logs', 'View management audit history and actor details'),
    (23, 'view_dashboard', 'Open the overview. each panel also requires its domain permission'),
    (24, 'view_schedule', 'View permitted class schedules, rooms, and the campus calendar'),
    (25, 'view_class_rosters', 'View student identities in permitted class rosters'),
    (26, 'view_events', 'View accessible events, announcements, and event calendar entries'),
    (27, 'view_recollections', 'View accessible recollections, retreats, and calendar entries'),
    (28, 'view_people', 'Search the people directory and open permitted profiles'),
    (29, 'view_messages', 'Read, star, mark read or unread, trash, restore, and download attachments in your own mailbox'),
    (30, 'use_search', 'Use universal search. results also require their destination permissions'),
    (31, 'manage_own_profile', 'View and update your own contact details, picture, privacy, and password'),
    (32, 'manage_own_settings', 'View and change your own appearance, accessibility, and notification preferences'),
    (33, 'manage_own_notifications', 'View and mark your own notifications and register or revoke your push device'),
    (34, 'correct_class_attendance', 'Correct attendance for permitted classes without scanning, finalization, or policy editing'),
    (35, 'assign_class_seats', 'Assign enrolled students to existing seat layouts without layout or preset editing');

INSERT INTO role_permissions (role_id, permission_id)
SELECT 1, id FROM permissions;

INSERT INTO role_permissions (role_id, permission_id) VALUES
    (2, 10), (2, 11), (2, 12), (2, 14), (2, 15), (2, 34), (2, 35),
    (3, 10), (3, 12), (3, 14),
    (4, 9), (4, 10), (4, 13), (4, 14), (4, 15), (4, 16), (4, 22),
    (5, 7), (5, 9), (5, 10), (5, 14), (5, 15), (5, 16), (5, 22);

INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT roles.id,permissions.id FROM roles CROSS JOIN permissions WHERE permissions.id BETWEEN 23 AND 33;

INSERT INTO departments (id, code, name, color_key) VALUES
    (1, 'CCS', 'College of Computing Studies', 'grey'),
    (2, 'CBA', 'College of Business and Accountancy', 'yellow'),
    (3, 'CAS', 'College of Arts and Sciences', 'green'),
    (4, 'CED', 'College of Education', 'blue'),
    (5, 'COE', 'College of Engineering', 'orange'),
    (6, 'CON', 'College of Nursing', 'red'),
    (7, 'CTH', 'College of Tourism and Hospitality', 'maroon');

INSERT INTO department_days_off (department_id, day_of_week, reason) VALUES
    (1, 'Friday', 'Department weekly day off'),
    (1, 'Sunday', 'Campus weekly day off'),
    (2, 'Monday', 'Department weekly day off'),
    (2, 'Sunday', 'Campus weekly day off');

INSERT INTO programs (id, department_id, code, name) VALUES
    (1, 1, 'BSIT', 'Bachelor of Science in Information Technology'),
    (2, 2, 'BSBA', 'Bachelor of Science in Business Administration');

INSERT INTO sections (id, program_id, name, year_level, section_code) VALUES
    (1, 1, 'BSIT 3A', 3, 'A'),
    (2, 2, 'BSBA 2A', 2, 'A');

INSERT INTO users
    (id, school_id, first_name, middle_name, last_name, email, password_hash, role_id, department_id, program_id, section_id, profile_image_path, status)
VALUES
    (1, 'ADMIN-001', 'System', NULL, 'Administrator', 'admin@rfid.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 1, NULL, NULL, NULL, NULL, 'active'),
    (2, 'TCH-001', 'Maria', 'Lopez', 'Santos', 'maria.santos@rfid.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 2, 1, NULL, NULL, '/assets/images/default-avatar.svg', 'active'),
    (3, 'TCH-002', 'Daniel', NULL, 'Reyes', 'daniel.reyes@rfid.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 2, 1, NULL, NULL, '/assets/images/default-avatar.svg', 'active'),
    (4, 'TCH-003', 'Angela', 'Cruz', 'Lim', 'angela.lim@rfid.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 2, 2, NULL, NULL, '/assets/images/default-avatar.svg', 'active'),
    (5, '2026-0001', 'Juan', NULL, 'Dela Cruz', 'juan.delacruz@student.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 3, 1, 1, 1, '/assets/images/default-avatar.svg', 'active'),
    (6, '2026-0002', 'Ana', 'Mae', 'Garcia', 'ana.garcia@student.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 3, 1, 1, 1, '/assets/images/default-avatar.svg', 'active'),
    (7, '2026-0003', 'Miguel', NULL, 'Torres', 'miguel.torres@student.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 3, 1, 1, 1, '/assets/images/default-avatar.svg', 'active'),
    (8, '2026-0004', 'Sofia', NULL, 'Mendoza', 'sofia.mendoza@student.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 3, 1, 1, 1, '/assets/images/default-avatar.svg', 'active'),
    (9, '2026-0005', 'Carlo', 'Luis', 'Navarro', 'carlo.navarro@student.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 3, 1, 1, 1, '/assets/images/default-avatar.svg', 'active'),
    (10, '2026-0006', 'Bea', NULL, 'Ramos', 'bea.ramos@student.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 3, 1, 1, 1, '/assets/images/default-avatar.svg', 'active'),
    (11, '2026-0007', 'Paolo', NULL, 'Villanueva', 'paolo.villanueva@student.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 3, 1, 1, 1, '/assets/images/default-avatar.svg', 'active'),
    (12, '2026-0008', 'Lara', 'Joy', 'Flores', 'lara.flores@student.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 3, 1, 1, 1, '/assets/images/default-avatar.svg', 'active'),
    (13, '2026-0009', 'Noel', NULL, 'Castro', 'noel.castro@student.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 3, 1, 1, 1, '/assets/images/default-avatar.svg', 'active'),
    (14, '2026-0010', 'Ella', NULL, 'Bautista', 'ella.bautista@student.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 3, 2, 2, 2, '/assets/images/default-avatar.svg', 'active'),
    (15, '2026-0011', 'Marco', NULL, 'Aquino', 'marco.aquino@student.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 3, 2, 2, 2, '/assets/images/default-avatar.svg', 'active'),
    (16, '2026-0012', 'Nina', 'Rose', 'Chua', 'nina.chua@student.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 3, 2, 2, 2, '/assets/images/default-avatar.svg', 'active'),
    (17, '2026-0013', 'Luis', NULL, 'Ocampo', 'luis.ocampo@student.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 3, 2, 2, 2, '/assets/images/default-avatar.svg', 'active'),
    (18, '2026-0014', 'Grace', NULL, 'Sy', 'grace.sy@student.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 3, 2, 2, 2, '/assets/images/default-avatar.svg', 'active'),
    (19, '2026-0015', 'Ivan', NULL, 'Mercado', 'ivan.mercado@student.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 3, 2, 2, 2, '/assets/images/default-avatar.svg', 'active'),
    (20, '2026-0016', 'Faith', NULL, 'Domingo', 'faith.domingo@student.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 3, 2, 2, 2, '/assets/images/default-avatar.svg', 'active'),
    (21, '2026-0017', 'Ramon', NULL, 'Diaz', 'ramon.diaz@student.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 3, 2, 2, 2, '/assets/images/default-avatar.svg', 'active'),
    (22, '2026-0018', 'Mia', NULL, 'Pascual', 'mia.pascual@student.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 3, 2, 2, 2, '/assets/images/default-avatar.svg', 'active'),
    (23, 'STF-001', 'Roberto', NULL, 'Cruz', 'roberto.cruz@rfid.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 4, 1, NULL, NULL, '/assets/images/default-avatar.svg', 'active'),
    (24, 'STF-002', 'Cynthia', NULL, 'Go', 'cynthia.go@rfid.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 4, 2, NULL, NULL, '/assets/images/default-avatar.svg', 'active'),
    (25, 'EVT-001', 'Patricia', NULL, 'Tan', 'patricia.tan@rfid.local', '$2y$12$pwCEpxpfz6RNkLHfBDudq..OaTQ8Plz6KkDLEXdpU0MmAVCYvCmge', 5, NULL, NULL, NULL, '/assets/images/default-avatar.svg', 'active');

INSERT INTO user_roles (user_id, role_id)
SELECT id, role_id FROM users;

INSERT INTO rooms (id, room_code, room_name, building, floor) VALUES
    (1, 'IT-LAB-01', 'Programming Laboratory 1', 'Technology Building', '2'),
    (2, 'IT-LAB-02', 'Programming Laboratory 2', 'Technology Building', '2'),
    (3, 'CBA-201', 'Business Lecture Room 201', 'Business Building', '2'),
    (4, 'AUDITORIUM', 'University Auditorium', 'Main Building', 'Ground');

INSERT INTO devices (id, device_code, name, room_id, status, last_seen_at) VALUES
    (1, 'SCANNER-IT01', 'IT Lab 1 RFID Scanner', 1, 'active', NOW()),
    (2, 'SCANNER-IT02', 'IT Lab 2 RFID Scanner', 2, 'active', NOW()),
    (3, 'SCANNER-CBA01', 'CBA Room RFID Scanner', 3, 'active', NOW());

INSERT INTO school_years (id, name, starts_on, ends_on, status) VALUES
    (1, '2026-2027', '2026-08-01', '2027-05-31', 'active');

INSERT INTO semesters (id, school_year_id, name, starts_on, ends_on, status) VALUES
    (1, 1, 'First Semester', '2026-08-01', '2026-12-20', 'active'),
    (2, 1, 'Second Semester', '2027-01-05', '2027-05-15', 'upcoming'),
    (3, 1, 'Summer Class', '2027-05-17', '2027-05-31', 'upcoming');

INSERT INTO courses (id, department_id, course_code, title, units) VALUES
    (1, 1, 'IT 301', 'Web Systems and Technologies', 3.0),
    (2, 1, 'IT 302', 'Database Administration', 3.0),
    (3, 1, 'IT 303', 'Information Assurance and Security', 3.0),
    (4, 2, 'BA 201', 'Principles of Marketing', 3.0),
    (5, 2, 'BA 202', 'Financial Management', 3.0),
    (6, 2, 'BA 203', 'Business Analytics', 3.0);

INSERT INTO class_offerings (id, course_id, section_id, semester_id, room_id) VALUES
    (1, 1, 1, 1, 1), (2, 2, 1, 1, 2), (3, 3, 1, 1, 1),
    (4, 4, 2, 1, 3), (5, 5, 2, 1, 3), (6, 6, 2, 1, 3);

INSERT INTO teacher_assignments (class_offering_id, teacher_id) VALUES
    (1, 2), (2, 3), (3, 2), (4, 4), (5, 4), (6, 4);

INSERT INTO class_schedules (class_offering_id, room_id, day_of_week, start_time, end_time) VALUES
    (1, 1, 'Monday', '08:00:00', '10:00:00'),
    (2, 2, 'Tuesday', '10:00:00', '12:00:00'),
    (3, 1, 'Wednesday', '13:00:00', '15:00:00'),
    (4, 3, 'Saturday', '10:00:00', '12:00:00'),
    (5, 3, 'Thursday', '08:00:00', '10:00:00'),
    (6, 3, 'Friday', '13:00:00', '15:00:00');

INSERT INTO class_enrollments (class_offering_id, student_id)
SELECT class_offerings.id, users.id
FROM class_offerings
JOIN users ON users.section_id = class_offerings.section_id
WHERE EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id = 3);

INSERT INTO attendance_settings (id, grace_period_minutes, absent_after_minutes, tardies_per_absence, updated_by)
VALUES (1, 15, 30, 3, 1);

INSERT INTO rfid_cards (id, uid, user_id, status, assigned_at) VALUES
    (1, 'A3F91C02', 5, 'active', NOW()), (2, '04B18F9A72C180', 6, 'active', NOW()),
    (3, 'B7812D44', 7, 'active', NOW()), (4, '046A21BB91C080', 8, 'active', NOW()),
    (5, 'C0129EA1', 9, 'active', NOW()), (6, '04F1218C77A280', 10, 'active', NOW()),
    (7, 'D31A8810', 11, 'active', NOW()), (8, '04E1992AC9B180', 12, 'active', NOW()),
    (9, 'E88319AA', 13, 'active', NOW()), (10, '04110AC8124B80', 14, 'active', NOW());

INSERT INTO rfid_assignment_history (rfid_card_id, user_id, action, performed_by, notes)
SELECT id, user_id, 'assigned', 1, 'Seeded RFID assignment' FROM rfid_cards;

INSERT INTO attendance_sessions (id, class_offering_id, attendance_date, finalized_at, finalized_by) VALUES
    (1, 1, CURRENT_DATE - INTERVAL 14 DAY, NOW(), 2),
    (2, 4, CURRENT_DATE - INTERVAL 7 DAY, NOW(), 4),
    (3, 1, CURRENT_DATE - INTERVAL 13 DAY, NOW(), 2),
    (4, 1, CURRENT_DATE - INTERVAL 12 DAY, NOW(), 2),
    (5, 1, CURRENT_DATE - INTERVAL 11 DAY, NOW(), 2);

INSERT INTO attendance_records
    (attendance_session_id, class_offering_id, student_id, attendance_date, time_in, status, source, recorded_by, notes)
SELECT 1, 1, users.id, CURRENT_DATE - INTERVAL 14 DAY,
       CASE WHEN users.id = 12 THEN NULL WHEN users.id = 13 THEN '08:22:00' ELSE '07:55:00' END,
       CASE WHEN users.id = 12 THEN 'Absent' WHEN users.id = 13 THEN 'Late' ELSE 'Present' END,
       CASE WHEN users.id = 12 THEN 'system' ELSE 'rfid' END, 2, 'Demo class attendance'
FROM users WHERE section_id = 1 AND EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id = 3);

INSERT INTO attendance_records
    (attendance_session_id, class_offering_id, student_id, attendance_date, time_in, status, source, recorded_by, notes)
SELECT 2, 4, users.id, CURRENT_DATE - INTERVAL 7 DAY,
       CASE WHEN users.id IN (21, 22) THEN NULL ELSE '09:58:00' END,
       CASE WHEN users.id IN (21, 22) THEN 'Absent' ELSE 'Present' END,
       CASE WHEN users.id IN (21, 22) THEN 'system' ELSE 'rfid' END, 4, 'Demo class attendance'
FROM users WHERE section_id = 2 AND EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id = 3);

INSERT INTO attendance_records
    (attendance_session_id, class_offering_id, student_id, attendance_date, time_in, status, source, recorded_by, notes)
VALUES
    (3, 1, 13, CURRENT_DATE - INTERVAL 13 DAY, '08:19:00', 'Late', 'rfid', 2, 'Demo tardy'),
    (4, 1, 13, CURRENT_DATE - INTERVAL 12 DAY, '08:21:00', 'Late', 'rfid', 2, 'Demo tardy'),
    (5, 1, 13, CURRENT_DATE - INTERVAL 11 DAY, '08:18:00', 'Late', 'rfid', 2, 'Demo tardy');

INSERT INTO events
    (id, title, description, event_date, end_date, start_time, end_time, venue, banner_image_path, attendance_required, check_in_start, check_in_end, check_out_required, status, created_by)
VALUES
    (1, 'Foundation Day', 'Annual university foundation celebration.', CURRENT_DATE - INTERVAL 5 DAY, NULL, '08:00:00', '16:00:00', 'University Auditorium', '/assets/images/default-event-banner.svg', TRUE, '07:30:00', '09:00:00', TRUE, 'closed', 25),
    (2, 'General Assembly', 'University-wide student assembly.', CURRENT_DATE + INTERVAL 10 DAY, NULL, '09:00:00', '11:00:00', 'University Auditorium', NULL, TRUE, '08:30:00', '09:30:00', FALSE, 'scheduled', 25);

INSERT INTO event_target_groups (event_id, target_type, target_id) VALUES
    (1, 'all_students', NULL), (2, 'all_students', NULL);

INSERT INTO event_participants (event_id, user_id, is_required)
SELECT events.id, users.id, TRUE
FROM events CROSS JOIN users
WHERE EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id = 3);

INSERT INTO event_attendance (event_id, user_id, check_in_at, check_out_at, status, source, recorded_by, notes)
SELECT 1, users.id,
       CASE WHEN users.id IN (12, 21) THEN NULL ELSE TIMESTAMP(CURRENT_DATE - INTERVAL 5 DAY, '07:55:00') END,
       CASE WHEN users.id IN (12, 21) THEN NULL ELSE TIMESTAMP(CURRENT_DATE - INTERVAL 5 DAY, '16:05:00') END,
       CASE WHEN users.id IN (12, 21) THEN 'Absent' WHEN users.id = 13 THEN 'Late' ELSE 'Attended' END,
       CASE WHEN users.id IN (12, 21) THEN 'system' ELSE 'rfid' END,
       25, 'Demo event attendance'
FROM users WHERE EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id = 3);

INSERT INTO recollections
    (id, title, type, section_id, date, start_time, end_time, venue, facilitator, attendance_required, status, created_by)
VALUES
    (1, 'BSIT 3A Recollection', 'Recollection', 1, CURRENT_DATE - INTERVAL 3 DAY, '08:00:00', '15:00:00', 'Campus Chapel', 'Fr. Mateo Cruz', TRUE, 'closed', 1),
    (2, 'BSBA 2A Leadership Retreat', 'Retreat', 2, CURRENT_DATE + INTERVAL 20 DAY, '07:00:00', '17:00:00', 'Retreat Center', 'Sr. Elena Reyes', TRUE, 'scheduled', 1);

INSERT INTO recollection_participants (recollection_id, user_id)
SELECT recollections.id, users.id
FROM recollections JOIN users ON users.section_id = recollections.section_id
WHERE EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id = 3);

INSERT INTO recollection_attendance (recollection_id, user_id, time_in, time_out, status, source, recorded_by, notes)
SELECT 1, users.id,
       CASE WHEN users.id = 12 THEN NULL ELSE TIMESTAMP(CURRENT_DATE - INTERVAL 3 DAY, '07:50:00') END,
       CASE WHEN users.id = 12 THEN NULL ELSE TIMESTAMP(CURRENT_DATE - INTERVAL 3 DAY, '15:05:00') END,
       CASE WHEN users.id = 12 THEN 'Absent' ELSE 'Attended' END,
       CASE WHEN users.id = 12 THEN 'system' ELSE 'rfid' END,
       1, 'Demo recollection attendance'
FROM users WHERE users.section_id = 1 AND EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id = 3);

INSERT INTO grades (class_offering_id, student_id, prelim, midterm, final, overall, status, entered_by, finalized_at)
SELECT enrollments.class_offering_id, enrollments.student_id,
       82 + MOD(enrollments.student_id, 9),
       83 + MOD(enrollments.student_id, 8),
       84 + MOD(enrollments.student_id, 7),
       ROUND((249 + MOD(enrollments.student_id, 9) + MOD(enrollments.student_id, 8) + MOD(enrollments.student_id, 7)) / 3, 2),
       'finalized', assignments.teacher_id, NOW()
FROM class_enrollments AS enrollments
JOIN teacher_assignments AS assignments ON assignments.class_offering_id = enrollments.class_offering_id;

INSERT INTO messages (id, sender_id, subject, body, is_announcement) VALUES
    (1, 1, 'Welcome to the RFID Attendance Portal', 'Your account is ready. Use the portal to review attendance, schedules, grades, and compliance records.', TRUE),
    (2, 2, 'IT 301 Class Reminder', 'Please review the materials before our next Web Systems class.', FALSE),
    (3, 25, 'General Assembly Notice', 'The General Assembly is required for all students. Please arrive before check-in closes.', TRUE);

INSERT INTO message_recipients (message_id, user_id)
SELECT 1, id FROM users WHERE id <> 1;
INSERT INTO message_recipients (message_id, user_id)
SELECT 2, id FROM users WHERE section_id = 1 AND EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id = 3);
INSERT INTO message_recipients (message_id, user_id)
SELECT 3, id FROM users WHERE EXISTS(SELECT 1 FROM user_roles WHERE user_roles.user_id = users.id AND user_roles.role_id = 3);

INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES
    (1, 'seed_database', 'system', NULL, 'Initial MVP demonstration data created.', '127.0.0.1');

INSERT INTO grading_settings (id, has_prelim, has_midterm, has_final, prelim_weight, midterm_weight, final_weight, passing_grade)
VALUES (1, 0, 1, 1, 0, 40, 60, 75);
