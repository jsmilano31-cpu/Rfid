-- Separate classroom correction and assignment from campus management.
SET @teacher_portal_install = (SELECT COUNT(*)<2 FROM permissions WHERE code IN ('correct_class_attendance','assign_class_seats'));
INSERT IGNORE INTO permissions(code,description) VALUES
('correct_class_attendance','Correct attendance for permitted classes. Does not allow RFID scanning, quick marking, finalization, or policy editing'),
('assign_class_seats','Assign and remove enrolled students on existing seat layouts for permitted classes. Does not allow layout or preset editing');

-- Preserve existing non-teacher management capabilities.
INSERT IGNORE INTO role_permissions(role_id,permission_id)
SELECT rp.role_id,target.id FROM role_permissions rp
JOIN permissions source ON source.id=rp.permission_id
JOIN permissions target ON target.code=CASE source.code WHEN 'manage_attendance' THEN 'correct_class_attendance' WHEN 'manage_seat_plans' THEN 'assign_class_seats' END
JOIN roles r ON r.id=rp.role_id
WHERE source.code IN ('manage_attendance','manage_seat_plans') AND r.name<>'Teacher' AND @teacher_portal_install=1;

-- Teacher reset is a one-time versioned migration. Future role edits remain explicit.
DELETE rp FROM role_permissions rp JOIN roles r ON r.id=rp.role_id WHERE r.name='Teacher' AND @teacher_portal_install=1;
INSERT INTO role_permissions(role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.name='Teacher' AND @teacher_portal_install=1 AND p.code IN (
'view_dashboard','view_schedule','view_attendance','view_grades','view_class_rosters',
'view_events','view_recollections','view_people','view_messages','view_compliance','view_analytics','use_search',
'manage_own_profile','manage_own_settings','manage_own_notifications','send_messages',
'manage_grades','correct_class_attendance','assign_class_seats');
