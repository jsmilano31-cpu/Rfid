ALTER TABLE departments
    ADD COLUMN IF NOT EXISTS color_key
        ENUM('green', 'yellow', 'grey', 'blue', 'orange', 'red', 'maroon')
        NOT NULL DEFAULT 'grey'
        AFTER name;

INSERT INTO departments (code, name, color_key, status) VALUES
    ('CAS', 'College of Arts and Sciences', 'green', 'active'),
    ('CBA', 'College of Business and Accountancy', 'yellow', 'active'),
    ('CCS', 'College of Computing Studies', 'grey', 'active'),
    ('CED', 'College of Education', 'blue', 'active'),
    ('COE', 'College of Engineering', 'orange', 'active'),
    ('CON', 'College of Nursing', 'red', 'active'),
    ('CTH', 'College of Tourism and Hospitality', 'maroon', 'active')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    color_key = VALUES(color_key),
    status = 'active';
