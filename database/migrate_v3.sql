-- ============================================================
-- SFIS v3 Migration — Shared Global Feedback Questions
-- All semesters/forms now share one set of questions.
-- ============================================================
USE studentfeedback;

-- ────────────────────────────────────────────────────────────
-- 1. Create the global questions table
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS global_feedback_questions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    question_no   INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('rating','comment') NOT NULL DEFAULT 'rating',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- ────────────────────────────────────────────────────────────
-- 2. Migrate existing questions (deduplicate by text+type)
-- ────────────────────────────────────────────────────────────
INSERT IGNORE INTO global_feedback_questions (question_no, question_text, question_type)
SELECT MIN(fq.question_no), fq.question_text, fq.question_type
FROM feedback_questions fq
GROUP BY fq.question_text, fq.question_type;

-- ────────────────────────────────────────────────────────────
-- 3. Drop old FK constraints so question_id can reference
--    the new global table
-- ────────────────────────────────────────────────────────────
SET @fk_rating = (
    SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'feedback_ratings'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
      AND CONSTRAINT_NAME LIKE '%question%'
    LIMIT 1
);
SET @fk_comment = (
    SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'feedback_comments'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
      AND CONSTRAINT_NAME LIKE '%question%'
    LIMIT 1
);

SET @sql1 = IF(@fk_rating IS NOT NULL, CONCAT('ALTER TABLE feedback_ratings DROP FOREIGN KEY ', @fk_rating), 'SELECT 1');
SET @sql2 = IF(@fk_comment IS NOT NULL, CONCAT('ALTER TABLE feedback_comments DROP FOREIGN KEY ', @fk_comment), 'SELECT 1');

PREPARE s1 FROM @sql1; EXECUTE s1; DEALLOCATE PREPARE s1;
PREPARE s2 FROM @sql2; EXECUTE s2; DEALLOCATE PREPARE s2;

-- ────────────────────────────────────────────────────────────
-- 4. Add new FK constraints referencing global_feedback_questions
-- ────────────────────────────────────────────────────────────
ALTER TABLE feedback_ratings
    ADD CONSTRAINT fk_rating_global_question
    FOREIGN KEY (question_id) REFERENCES global_feedback_questions(id) ON DELETE CASCADE;

ALTER TABLE feedback_comments
    ADD CONSTRAINT fk_comment_global_question
    FOREIGN KEY (question_id) REFERENCES global_feedback_questions(id) ON DELETE CASCADE;
