-- ============================================================
-- SFIS v13 — Complete Database Migration
-- Academic Year Versioning & Question Set System
-- ============================================================
-- Run this file against the studentfeedbackintern database.
-- It creates new tables, adds new columns, migrates data,
-- and adds all foreign keys.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- STEP 1: CREATE NEW MASTER TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS academic_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year_name VARCHAR(20) NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_year_name (year_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS semesters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    semester_no INT NOT NULL,
    semester_name VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_semester_no (semester_no)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feedback_question_sets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year_id INT NOT NULL,
    semester_id INT NOT NULL,
    module ENUM('academic','student_affairs','administration') NOT NULL DEFAULT 'academic',
    title VARCHAR(150) NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_question_set (academic_year_id, semester_id, module),
    INDEX idx_fqs_module (module),
    INDEX idx_fqs_year (academic_year_id),
    INDEX idx_fqs_semester (semester_id)
) ENGINE=InnoDB;

-- ============================================================
-- STEP 2: SEED SEMESTERS (1 through 20)
-- ============================================================

INSERT IGNORE INTO semesters (semester_no, semester_name) VALUES
(1, '1st Semester'),
(2, '2nd Semester'),
(3, '3rd Semester'),
(4, '4th Semester'),
(5, '5th Semester'),
(6, '6th Semester'),
(7, '7th Semester'),
(8, '8th Semester'),
(9, '9th Semester'),
(10, '10th Semester'),
(11, '11th Semester'),
(12, '12th Semester'),
(13, '13th Semester'),
(14, '14th Semester'),
(15, '15th Semester'),
(16, '16th Semester'),
(17, '17th Semester'),
(18, '18th Semester'),
(19, '19th Semester'),
(20, '20th Semester');

-- ============================================================
-- STEP 3: ADD NEW COLUMNS TO EXISTING TABLES
-- ============================================================

-- 3a. sections — add FK columns (skip if already exist)
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'sections' AND COLUMN_NAME = 'academic_year_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE sections ADD COLUMN academic_year_id INT DEFAULT NULL AFTER academic_year', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'sections' AND COLUMN_NAME = 'semester_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE sections ADD COLUMN semester_id INT DEFAULT NULL AFTER academic_year_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3b. feedback_forms — add FK columns
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'feedback_forms' AND COLUMN_NAME = 'academic_year_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE feedback_forms ADD COLUMN academic_year_id INT DEFAULT NULL AFTER academic_year', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'feedback_forms' AND COLUMN_NAME = 'semester_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE feedback_forms ADD COLUMN semester_id INT DEFAULT NULL AFTER academic_year_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'feedback_forms' AND COLUMN_NAME = 'question_set_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE feedback_forms ADD COLUMN question_set_id INT DEFAULT NULL AFTER semester_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3c. feedback_questions — add question_set_id
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'feedback_questions' AND COLUMN_NAME = 'question_set_id');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE feedback_questions ADD COLUMN question_set_id INT DEFAULT NULL AFTER id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- STEP 4: MIGRATE DATA — Populate academic_years from existing data
-- ============================================================

INSERT IGNORE INTO academic_years (year_name)
SELECT DISTINCT academic_year FROM sections
WHERE academic_year IS NOT NULL AND academic_year != ''
UNION
SELECT DISTINCT academic_year FROM feedback_forms
WHERE academic_year IS NOT NULL AND academic_year != '';

-- ============================================================
-- STEP 5: MIGRATE DATA — Update FK columns in sections
-- ============================================================

UPDATE sections s
INNER JOIN academic_years ay ON s.academic_year = ay.year_name
SET s.academic_year_id = ay.id
WHERE s.academic_year IS NOT NULL AND s.academic_year != ''
  AND (s.academic_year_id IS NULL OR s.academic_year_id = 0);

UPDATE sections s
INNER JOIN semesters sm ON s.semester = sm.semester_name
SET s.semester_id = sm.id
WHERE s.semester IS NOT NULL AND s.semester != ''
  AND (s.semester_id IS NULL OR s.semester_id = 0);

-- ============================================================
-- STEP 6: MIGRATE DATA — Update FK columns in feedback_forms
-- ============================================================

UPDATE feedback_forms ff
INNER JOIN academic_years ay ON ff.academic_year = ay.year_name
SET ff.academic_year_id = ay.id
WHERE ff.academic_year IS NOT NULL AND ff.academic_year != ''
  AND (ff.academic_year_id IS NULL OR ff.academic_year_id = 0);

-- For academic forms: get semester from the linked section
UPDATE feedback_forms ff
INNER JOIN sections s ON ff.section_id = s.id
SET ff.semester_id = s.semester_id
WHERE ff.section_id IS NOT NULL
  AND s.semester_id IS NOT NULL
  AND (ff.semester_id IS NULL OR ff.semester_id = 0);

-- For SA/Admin forms with no section: try to find matching year and create a default semester
-- (SA/Admin forms don't have a section, so semester stays NULL unless we can infer it)

-- ============================================================
-- STEP 7: MIGRATE DATA — Create question sets for existing data
-- ============================================================

-- 7a. Create question sets for each academic year that has academic forms
INSERT IGNORE INTO feedback_question_sets (academic_year_id, semester_id, module, title, status)
SELECT DISTINCT
    ff.academic_year_id,
    COALESCE(ff.semester_id, (SELECT id FROM semesters WHERE semester_no = 1 LIMIT 1)),
    'academic',
    CONCAT('Academic Questions - ', ay.year_name),
    'active'
FROM feedback_forms ff
INNER JOIN academic_years ay ON ff.academic_year_id = ay.id
WHERE ff.module = 'academic'
  AND ff.academic_year_id IS NOT NULL;

-- 7b. Create question sets for each academic year that has SA forms
INSERT IGNORE INTO feedback_question_sets (academic_year_id, semester_id, module, title, status)
SELECT DISTINCT
    ff.academic_year_id,
    COALESCE(ff.semester_id, (SELECT id FROM semesters WHERE semester_no = 1 LIMIT 1)),
    'student_affairs',
    CONCAT('SA Questions - ', ay.year_name),
    'active'
FROM feedback_forms ff
INNER JOIN academic_years ay ON ff.academic_year_id = ay.id
WHERE ff.module = 'student_affairs'
  AND ff.academic_year_id IS NOT NULL;

-- 7c. Create question sets for each academic year that has Admin forms
INSERT IGNORE INTO feedback_question_sets (academic_year_id, semester_id, module, title, status)
SELECT DISTINCT
    ff.academic_year_id,
    COALESCE(ff.semester_id, (SELECT id FROM semesters WHERE semester_no = 1 LIMIT 1)),
    'administration',
    CONCAT('Admin Questions - ', ay.year_name),
    'active'
FROM feedback_forms ff
INNER JOIN academic_years ay ON ff.academic_year_id = ay.id
WHERE ff.module = 'administration'
  AND ff.academic_year_id IS NOT NULL;

-- 7d. Also create a "default" question set for modules that have shared questions
-- but no forms with academic_year set (backward compatibility)
-- This handles the case where the very first form (id=1) has NULL academic_year

-- For academic module: create a set for the most recent year
INSERT IGNORE INTO feedback_question_sets (academic_year_id, semester_id, module, title, status)
SELECT
    ay.id,
    (SELECT id FROM semesters WHERE semester_no = 1 LIMIT 1),
    'academic',
    CONCAT('Academic Questions - ', ay.year_name),
    'active'
FROM academic_years ay
WHERE ay.year_name = (SELECT MAX(year_name) FROM academic_years)
  AND NOT EXISTS (
      SELECT 1 FROM feedback_question_sets fqs
      WHERE fqs.module = 'academic'
      AND fqs.academic_year_id = ay.id
  );

-- For SA module
INSERT IGNORE INTO feedback_question_sets (academic_year_id, semester_id, module, title, status)
SELECT
    ay.id,
    (SELECT id FROM semesters WHERE semester_no = 1 LIMIT 1),
    'student_affairs',
    CONCAT('SA Questions - ', ay.year_name),
    'active'
FROM academic_years ay
WHERE ay.year_name = (SELECT MAX(year_name) FROM academic_years)
  AND NOT EXISTS (
      SELECT 1 FROM feedback_question_sets fqs
      WHERE fqs.module = 'student_affairs'
      AND fqs.academic_year_id = ay.id
  );

-- For Admin module
INSERT IGNORE INTO feedback_question_sets (academic_year_id, semester_id, module, title, status)
SELECT
    ay.id,
    (SELECT id FROM semesters WHERE semester_no = 1 LIMIT 1),
    'administration',
    CONCAT('Admin Questions - ', ay.year_name),
    'active'
FROM academic_years ay
WHERE ay.year_name = (SELECT MAX(year_name) FROM academic_years)
  AND NOT EXISTS (
      SELECT 1 FROM feedback_question_sets fqs
      WHERE fqs.module = 'administration'
      AND fqs.academic_year_id = ay.id
  );

-- ============================================================
-- STEP 8: MIGRATE DATA — Assign existing questions to question sets
-- ============================================================

-- For each module, assign questions to the question set of the MOST RECENT year
-- This ensures all existing questions are linked to at least one set

-- Academic questions → link to the latest academic question set
UPDATE feedback_questions fq
INNER JOIN feedback_question_sets fqs
    ON fqs.module = fq.module
    AND fqs.id = (
        SELECT fqs2.id FROM feedback_question_sets fqs2
        WHERE fqs2.module = fq.module
        ORDER BY fqs2.academic_year_id DESC, fqs2.semester_id DESC
        LIMIT 1
    )
SET fq.question_set_id = fqs.id
WHERE fq.module = 'academic'
  AND fq.question_set_id IS NULL;

-- SA questions → link to the latest SA question set
UPDATE feedback_questions fq
INNER JOIN feedback_question_sets fqs
    ON fqs.module = fq.module
    AND fqs.id = (
        SELECT fqs2.id FROM feedback_question_sets fqs2
        WHERE fqs2.module = fq.module
        ORDER BY fqs2.academic_year_id DESC, fqs2.semester_id DESC
        LIMIT 1
    )
SET fq.question_set_id = fqs.id
WHERE fq.module = 'student_affairs'
  AND fq.question_set_id IS NULL;

-- Admin questions → link to the latest Admin question set
UPDATE feedback_questions fq
INNER JOIN feedback_question_sets fqs
    ON fqs.module = fq.module
    AND fqs.id = (
        SELECT fqs2.id FROM feedback_question_sets fqs2
        WHERE fqs2.module = fq.module
        ORDER BY fqs2.academic_year_id DESC, fqs2.semester_id DESC
        LIMIT 1
    )
SET fq.question_set_id = fqs.id
WHERE fq.module = 'administration'
  AND fq.question_set_id IS NULL;

-- ============================================================
-- STEP 9: MIGRATE DATA — Link forms to question sets
-- ============================================================

-- For academic forms: link to the question set matching their year+module
UPDATE feedback_forms ff
INNER JOIN feedback_question_sets fqs
    ON fqs.academic_year_id = ff.academic_year_id
    AND fqs.module = ff.module
SET ff.question_set_id = fqs.id
WHERE ff.academic_year_id IS NOT NULL
  AND ff.question_set_id IS NULL;

-- For forms without academic_year: link to the latest question set for their module
UPDATE feedback_forms ff
INNER JOIN feedback_question_sets fqs
    ON fqs.module = ff.module
    AND fqs.id = (
        SELECT fqs2.id FROM feedback_question_sets fqs2
        WHERE fqs2.module = ff.module
        ORDER BY fqs2.academic_year_id DESC, fqs2.semester_id DESC
        LIMIT 1
    )
SET ff.question_set_id = fqs.id
WHERE ff.academic_year_id IS NULL
  AND ff.question_set_id IS NULL;

-- ============================================================
-- STEP 10: ADD INDEXES FOR PERFORMANCE
-- ============================================================

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'sections' AND INDEX_NAME = 'idx_sec_academic_year_id');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE sections ADD INDEX idx_sec_academic_year_id (academic_year_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'sections' AND INDEX_NAME = 'idx_sec_semester_id');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE sections ADD INDEX idx_sec_semester_id (semester_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'feedback_forms' AND INDEX_NAME = 'idx_ff_academic_year_id');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE feedback_forms ADD INDEX idx_ff_academic_year_id (academic_year_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'feedback_forms' AND INDEX_NAME = 'idx_ff_semester_id');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE feedback_forms ADD INDEX idx_ff_semester_id (semester_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'feedback_forms' AND INDEX_NAME = 'idx_ff_question_set_id');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE feedback_forms ADD INDEX idx_ff_question_set_id (question_set_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'feedback_questions' AND INDEX_NAME = 'idx_fq_question_set_id');
SET @sql = IF(@idx_exists = 0, 'ALTER TABLE feedback_questions ADD INDEX idx_fq_question_set_id (question_set_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- STEP 11: ADD FOREIGN KEY CONSTRAINTS
-- ============================================================

-- feedback_question_sets → academic_years
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'feedback_question_sets' AND CONSTRAINT_NAME = 'fk_fqs_academic_year');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE feedback_question_sets ADD CONSTRAINT fk_fqs_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- feedback_question_sets → semesters
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'feedback_question_sets' AND CONSTRAINT_NAME = 'fk_fqs_semester');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE feedback_question_sets ADD CONSTRAINT fk_fqs_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE RESTRICT', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- sections → academic_years
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'sections' AND CONSTRAINT_NAME = 'fk_sec_academic_year');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE sections ADD CONSTRAINT fk_sec_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- sections → semesters
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'sections' AND CONSTRAINT_NAME = 'fk_sec_semester');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE sections ADD CONSTRAINT fk_sec_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- feedback_forms → academic_years
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'feedback_forms' AND CONSTRAINT_NAME = 'fk_ff_academic_year');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE feedback_forms ADD CONSTRAINT fk_ff_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- feedback_forms → semesters
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'feedback_forms' AND CONSTRAINT_NAME = 'fk_ff_semester');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE feedback_forms ADD CONSTRAINT fk_ff_semester FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- feedback_forms → feedback_question_sets
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'feedback_forms' AND CONSTRAINT_NAME = 'fk_ff_question_set');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE feedback_forms ADD CONSTRAINT fk_ff_question_set FOREIGN KEY (question_set_id) REFERENCES feedback_question_sets(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- feedback_questions → feedback_question_sets
SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'feedback_questions' AND CONSTRAINT_NAME = 'fk_fq_question_set');
SET @sql = IF(@fk_exists = 0, 'ALTER TABLE feedback_questions ADD CONSTRAINT fk_fq_question_set FOREIGN KEY (question_set_id) REFERENCES feedback_question_sets(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- DONE
-- ============================================================
