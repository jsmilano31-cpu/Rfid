ALTER TABLE events
    ADD COLUMN IF NOT EXISTS end_date DATE NULL AFTER event_date;

INSERT INTO semesters (school_year_id, name, starts_on, ends_on, status)
SELECT sy.id, 'First Semester', sy.starts_on,
       LEAST(DATE_ADD(sy.starts_on, INTERVAL 4 MONTH), sy.ends_on),
       IF(sy.status = 'active', 'active', sy.status)
FROM school_years sy
WHERE NOT EXISTS (
    SELECT 1 FROM semesters s WHERE s.school_year_id = sy.id AND s.name = 'First Semester'
);

INSERT INTO semesters (school_year_id, name, starts_on, ends_on, status)
SELECT sy.id, 'Second Semester',
       LEAST(DATE_ADD(sy.starts_on, INTERVAL 5 MONTH), sy.ends_on),
       DATE_SUB(LEAST(DATE_ADD(sy.starts_on, INTERVAL 9 MONTH), sy.ends_on), INTERVAL 1 DAY),
       IF(sy.status = 'closed', 'closed', 'upcoming')
FROM school_years sy
WHERE NOT EXISTS (
    SELECT 1 FROM semesters s WHERE s.school_year_id = sy.id AND s.name = 'Second Semester'
);

INSERT INTO semesters (school_year_id, name, starts_on, ends_on, status)
SELECT sy.id, 'Summer Class',
       LEAST(DATE_ADD(sy.starts_on, INTERVAL 9 MONTH), sy.ends_on),
       sy.ends_on,
       IF(sy.status = 'closed', 'closed', 'upcoming')
FROM school_years sy
WHERE NOT EXISTS (
    SELECT 1 FROM semesters s WHERE s.school_year_id = sy.id AND s.name = 'Summer Class'
);
