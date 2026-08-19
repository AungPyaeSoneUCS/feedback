-- ============================================================
-- SFIS Migration v6: Questions per module (shared), not per form
-- ============================================================
USE studentfeedbackintern;

-- ============================================================
-- STEP 1: Add module column to feedback_questions
-- ============================================================
ALTER TABLE feedback_questions
    ADD COLUMN module ENUM('academic','student_affairs','administration') DEFAULT NULL AFTER id;

-- ============================================================
-- STEP 2: Populate module from form's module for each question
-- ============================================================
UPDATE feedback_questions fq
JOIN feedback_forms ff ON fq.form_id = ff.id
SET fq.module = ff.module;

-- ============================================================
-- STEP 3: Deduplicate questions per module (keep lowest id)
-- ============================================================
DELETE fq1 FROM feedback_questions fq1
INNER JOIN feedback_questions fq2
WHERE fq1.id > fq2.id
  AND fq1.module = fq2.module
  AND fq1.question_no = fq2.question_no
  AND fq1.question_text = fq2.question_text
  AND fq1.question_type = fq2.question_type;

-- ============================================================
-- STEP 4: Drop foreign key then form_id column
-- ============================================================
SET @fk_name = (SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'feedback_questions'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME LIKE '%form%' LIMIT 1);
SET @sql_drop_fk = IF(@fk_name IS NOT NULL, CONCAT('ALTER TABLE feedback_questions DROP FOREIGN KEY ', @fk_name), 'SELECT 1');
PREPARE s_fk FROM @sql_drop_fk; EXECUTE s_fk; DEALLOCATE PREPARE s_fk;

ALTER TABLE feedback_questions DROP COLUMN form_id;

-- ============================================================
-- STEP 5: Add unique constraint per module+question_no
-- ============================================================
ALTER TABLE feedback_questions ADD UNIQUE KEY uq_module_qno (module, question_no);

-- ============================================================
-- STEP 6: Add index for module
-- ============================================================
CREATE INDEX idx_fq_module ON feedback_questions(module);
