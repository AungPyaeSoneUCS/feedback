-- ============================================================
-- SFIS Migration v8: Shared questions per module + form_id on ratings/comments
-- ============================================================
USE studentfeedbackintern;

-- ============================================================
-- STEP 1: Add module column to feedback_questions
-- ============================================================
ALTER TABLE feedback_questions
    ADD COLUMN module ENUM('academic','student_affairs','administration') DEFAULT NULL AFTER id;

-- ============================================================
-- STEP 2: Populate module from the form each question belongs to
-- ============================================================
UPDATE feedback_questions fq
JOIN feedback_forms ff ON fq.form_id = ff.id
SET fq.module = ff.module;

-- Fallback for orphan questions (no matching form)
UPDATE feedback_questions SET module = 'academic' WHERE module IS NULL;

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
-- STEP 4: Drop foreign key then form_id column from feedback_questions
-- ============================================================
SET @fk_name = (SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'feedback_questions'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME LIKE '%form%' LIMIT 1);
SET @sql_drop_fk = IF(@fk_name IS NOT NULL, CONCAT('ALTER TABLE feedback_questions DROP FOREIGN KEY ', @fk_name), 'SELECT 1');
PREPARE s_fk FROM @sql_drop_fk; EXECUTE s_fk; DEALLOCATE PREPARE s_fk;

ALTER TABLE feedback_questions DROP COLUMN form_id;

-- ============================================================
-- STEP 5: Add unique constraint (module, question_no) and index
-- ============================================================
ALTER TABLE feedback_questions ADD UNIQUE KEY uq_module_qno (module, question_no);
ALTER TABLE feedback_questions ADD INDEX idx_fq_module (module);

-- ============================================================
-- STEP 6: Add form_id to feedback_ratings
-- ============================================================
ALTER TABLE feedback_ratings ADD COLUMN form_id INT DEFAULT NULL AFTER id;
ALTER TABLE feedback_ratings ADD CONSTRAINT fk_rating_form FOREIGN KEY(form_id) REFERENCES feedback_forms(id) ON DELETE CASCADE;
ALTER TABLE feedback_ratings ADD INDEX idx_fr_form (form_id);

-- ============================================================
-- STEP 7: Add form_id to feedback_comments
-- ============================================================
ALTER TABLE feedback_comments ADD COLUMN form_id INT DEFAULT NULL AFTER id;
ALTER TABLE feedback_comments ADD CONSTRAINT fk_comment_form FOREIGN KEY(form_id) REFERENCES feedback_forms(id) ON DELETE CASCADE;
ALTER TABLE feedback_comments ADD INDEX idx_fc_form (form_id);

-- ============================================================
-- VERIFICATION
-- ============================================================
-- SELECT 'feedback_questions module:' AS info, module, COUNT(*) AS cnt FROM feedback_questions GROUP BY module;
-- SELECT 'feedback_ratings has form_id' AS info, COUNT(*) AS cnt FROM feedback_ratings WHERE form_id IS NOT NULL;
-- SELECT 'feedback_comments has form_id' AS info, COUNT(*) AS cnt FROM feedback_comments WHERE form_id IS NOT NULL;
