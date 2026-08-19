-- ============================================================
-- SFIS Migration v7: Make feedback anonymous
-- Remove student_id from feedback_ratings and feedback_comments
-- feedback_submissions keeps student_id for one-submission-per-form
-- ============================================================
USE studentfeedbackintern;

-- ============================================================
-- STEP 1: Drop foreign keys on student_id
-- ============================================================
ALTER TABLE feedback_ratings DROP FOREIGN KEY feedback_ratings_ibfk_1;
ALTER TABLE feedback_comments DROP FOREIGN KEY feedback_comments_ibfk_1;

-- ============================================================
-- STEP 2: Drop indexes on student_id
-- ============================================================
ALTER TABLE feedback_ratings DROP INDEX idx_fb_ratings_student;
ALTER TABLE feedback_comments DROP INDEX idx_fb_comments_student;

-- ============================================================
-- STEP 3: Drop student_id columns
-- ============================================================
ALTER TABLE feedback_ratings DROP COLUMN student_id;
ALTER TABLE feedback_comments DROP COLUMN student_id;
