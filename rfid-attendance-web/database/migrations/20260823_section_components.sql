ALTER TABLE sections
    ADD COLUMN IF NOT EXISTS section_code VARCHAR(10) NULL AFTER year_level;

UPDATE sections AS section_record
INNER JOIN programs AS program ON program.id = section_record.program_id
SET section_record.section_code = UPPER(
    REGEXP_REPLACE(
        TRIM(section_record.name),
        CONCAT('^', program.code, '[[:space:]-]*', section_record.year_level, '[[:space:]-]*'),
        ''
    )
)
WHERE section_record.section_code IS NULL OR section_record.section_code = '';

UPDATE sections AS section_record
INNER JOIN programs AS program ON program.id = section_record.program_id
SET section_record.name = CONCAT(UPPER(program.code), ' ', section_record.year_level, UPPER(section_record.section_code));

ALTER TABLE sections
    MODIFY COLUMN section_code VARCHAR(10) NOT NULL,
    ADD UNIQUE KEY IF NOT EXISTS uq_sections_program_year_code (program_id, year_level, section_code),
    ADD CONSTRAINT IF NOT EXISTS chk_sections_year_level CHECK (year_level BETWEEN 1 AND 4);
