-- Restore the built-in Administrator's existing grading-policy capability.
-- Existing grading periods, weights, grades, and custom-role permissions stay intact.
INSERT IGNORE INTO permissions (code, description)
VALUES ('manage_grading_settings', 'Configure institutional grading terms, weights, and passing criteria');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT roles.id, permissions.id FROM roles CROSS JOIN permissions
WHERE roles.id = 1 AND roles.name = 'Administrator' AND permissions.code = 'manage_grading_settings';
