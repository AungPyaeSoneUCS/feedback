-- ============================================================
-- SFIS v19 — Replace sections.section VARCHAR with section_id FK
-- section_master becomes the single source of truth for section names.
-- ============================================================
USE studentfeedbackintern;

-- 1. Add section_id column (nullable initially for data migration)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'sections' AND COLUMN_NAME = 'section_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE sections ADD COLUMN section_id INT DEFAULT NULL AFTER semester_id', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Migrate data: populate section_id from section_master
UPDATE sections s
JOIN section_master sm ON TRIM(s.section) = sm.section_name
SET s.section_id = sm.id
WHERE s.section_id IS NULL;

-- 3. Add foreign key constraint
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'sections' AND CONSTRAINT_NAME = 'fk_sections_section_master');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE sections ADD CONSTRAINT fk_sections_section_master FOREIGN KEY (section_id) REFERENCES section_master(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Add index on section_id
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'sections' AND INDEX_NAME = 'idx_sections_section_id');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE sections ADD INDEX idx_sections_section_id (section_id)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5. Drop the old section VARCHAR column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'sections' AND COLUMN_NAME = 'section');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE sections DROP COLUMN section', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
