-- Add controls for previously login-only web features once. Preserve existing denied grants.
SET NAMES utf8mb4;
CREATE TEMPORARY TABLE new_web_permissions (code VARCHAR(80) PRIMARY KEY, description VARCHAR(255) NOT NULL);
INSERT INTO new_web_permissions VALUES
('view_dashboard','Open the overview. each panel also requires its domain permission'),
('view_schedule','View permitted class schedules, rooms, and the campus calendar'),
('view_class_rosters','View student identities in permitted class rosters'),
('view_events','View accessible events, announcements, and event calendar entries'),
('view_recollections','View accessible recollections, retreats, and calendar entries'),
('view_people','Search the people directory and open permitted profiles'),
('view_messages','Read, star, mark read or unread, trash, restore, and download attachments in your own mailbox'),
('use_search','Use universal search. results also require their destination permissions'),
('manage_own_profile','View and update your own contact details, picture, privacy, and password'),
('manage_own_settings','View and change your own appearance, accessibility, and notification preferences'),
('manage_own_notifications','View and mark your own notifications and register or revoke your push device');
DELETE n FROM new_web_permissions n JOIN permissions p ON p.code=n.code;
INSERT INTO permissions (code,description) SELECT code,description FROM new_web_permissions;
-- Existing roles retain capabilities that were previously available to every signed-in user.
-- New roles created after this migration start with no grants, as before.
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p JOIN new_web_permissions n ON n.code=p.code;
DROP TEMPORARY TABLE new_web_permissions;
UPDATE permissions SET description='Configure assigned-subject attendance rules. campus calendar changes also require Manage Academic Calendar' WHERE code='manage_attendance_policies';
UPDATE permissions SET description='Configure school years, academic terms, weekly days off, and no-class holidays. also requires Manage Attendance Policies' WHERE code='manage_academic_calendar';
UPDATE permissions SET description='Create, edit, deactivate and delete departments, programs, courses, sections, offerings, and enrollments. calendar rules are separate' WHERE code='manage_academic_structure';
