SET NAMES utf8mb4;

INSERT INTO permissions (code, description) VALUES
    ('manage_academic_calendar', 'Configure school years, academic terms, and department weekly schedules'),
    ('manage_grading_settings', 'Configure institutional grading terms, weights, and passing criteria'),
    ('view_audit_logs', 'View management audit history and actor details')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id
FROM roles CROSS JOIN permissions
WHERE roles.name = 'Administrator'
  AND permissions.code IN ('manage_academic_calendar', 'manage_grading_settings', 'view_audit_logs');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT existing.role_id, audit_permission.id
FROM role_permissions AS existing
JOIN permissions AS report_permission
  ON report_permission.id = existing.permission_id
CROSS JOIN permissions AS audit_permission
WHERE report_permission.code = 'manage_reports'
  AND audit_permission.code = 'view_audit_logs';
