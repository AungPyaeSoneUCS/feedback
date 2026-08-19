-- ============================================================
-- SFIS v17 — Remove redundant text columns
-- Keeps FK columns (academic_year_id, semester_id) as sole references.
-- Safe to run multiple times (uses IF EXISTS / column-existence checks).
-- ============================================================
USE studentfeedbackintern;

-- 1. feedback_forms: drop redundant `academic_year` VARCHAR column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'feedback_forms' AND COLUMN_NAME = 'academic_year');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE feedback_forms DROP COLUMN academic_year', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. sections: drop redundant `academic_year` VARCHAR column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'sections' AND COLUMN_NAME = 'academic_year');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE sections DROP COLUMN academic_year', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3. sections: drop redundant `semester` VARCHAR column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'sections' AND COLUMN_NAME = 'semester');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE sections DROP COLUMN semester', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. semesters: drop unique key `uq_semester_no` if it exists
SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'semesters' AND INDEX_NAME = 'uq_semester_no');
SET @sql = IF(@idx_exists > 0, 'ALTER TABLE semesters DROP INDEX uq_semester_no', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 5. semesters: drop redundant `semester_no` column
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'semesters' AND COLUMN_NAME = 'semester_no');
SET @sql = IF(@col_exists > 0, 'ALTER TABLE semesters DROP COLUMN semester_no', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
