CREATE TABLE IF NOT EXISTS recollection_target_users (
    recollection_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (recollection_id, user_id),
    INDEX idx_recollection_target_users_user (user_id, recollection_id),
    CONSTRAINT fk_recollection_target_users_recollection FOREIGN KEY (recollection_id) REFERENCES recollections(id) ON DELETE CASCADE,
    CONSTRAINT fk_recollection_target_users_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS recollection_target_roles (
    recollection_id INT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (recollection_id, role_id),
    INDEX idx_recollection_target_roles_role (role_id, recollection_id),
    CONSTRAINT fk_recollection_target_roles_recollection FOREIGN KEY (recollection_id) REFERENCES recollections(id) ON DELETE CASCADE,
    CONSTRAINT fk_recollection_target_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

DELETE participants
FROM recollection_participants participants
LEFT JOIN recollection_attendance attendance
  ON attendance.recollection_id = participants.recollection_id
 AND attendance.user_id = participants.user_id
WHERE attendance.id IS NULL;

INSERT IGNORE INTO recollection_participants (recollection_id, user_id)
SELECT DISTINCT recollections.id, enrollments.student_id
FROM recollections
JOIN class_offerings
  ON class_offerings.section_id = recollections.section_id
 AND class_offerings.status = 'active'
JOIN class_enrollments enrollments
  ON enrollments.class_offering_id = class_offerings.id
 AND enrollments.status = 'enrolled'
JOIN users ON users.id = enrollments.student_id AND users.status = 'active';

INSERT IGNORE INTO recollection_participants (recollection_id, user_id)
SELECT targets.recollection_id, targets.user_id
FROM recollection_target_users targets
JOIN users ON users.id = targets.user_id AND users.status = 'active';

INSERT IGNORE INTO recollection_participants (recollection_id, user_id)
SELECT targets.recollection_id, user_roles.user_id
FROM recollection_target_roles targets
JOIN user_roles ON user_roles.role_id = targets.role_id
JOIN users ON users.id = user_roles.user_id AND users.status = 'active';
