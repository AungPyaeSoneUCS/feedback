-- ============================================================
-- SFIS Migration v5: Old 25-table → New 14-table schema
-- Run this ONCE. Back up your database first!
-- ============================================================
USE studentfeedback;

-- ============================================================
-- STEP 1: Add `module` column to feedback_forms
-- ============================================================
ALTER TABLE feedback_forms
    ADD COLUMN module ENUM('academic','student_affairs','administration') NOT NULL DEFAULT 'academic' AFTER id;

-- ============================================================
-- STEP 2: Rename feedback_form_id → form_id in academic tables
-- ============================================================

-- feedback_questions
ALTER TABLE feedback_questions ADD COLUMN form_id INT DEFAULT NULL AFTER id;
UPDATE feedback_questions SET form_id = feedback_form_id;
ALTER TABLE feedback_questions DROP FOREIGN KEY fk_question_form;
ALTER TABLE feedback_questions DROP COLUMN feedback_form_id;
ALTER TABLE feedback_questions ADD CONSTRAINT fk_question_form FOREIGN KEY(form_id) REFERENCES feedback_forms(id) ON DELETE CASCADE;

-- feedback_submissions
ALTER TABLE feedback_submissions ADD COLUMN form_id INT NOT NULL AFTER id;
UPDATE feedback_submissions SET form_id = feedback_form_id;
ALTER TABLE feedback_submissions DROP FOREIGN KEY fk_submission_form;
ALTER TABLE feedback_submissions DROP COLUMN feedback_form_id;
ALTER TABLE feedback_submissions ADD CONSTRAINT fk_submission_form FOREIGN KEY(form_id) REFERENCES feedback_forms(id) ON DELETE CASCADE;

-- feedback_ratings
ALTER TABLE feedback_ratings ADD COLUMN form_id INT NOT NULL AFTER id;
UPDATE feedback_ratings SET form_id = feedback_form_id;
ALTER TABLE feedback_ratings DROP FOREIGN KEY fk_rating_form;
ALTER TABLE feedback_ratings DROP COLUMN feedback_form_id;
ALTER TABLE feedback_ratings ADD CONSTRAINT fk_rating_form FOREIGN KEY(form_id) REFERENCES feedback_forms(id) ON DELETE CASCADE;

-- feedback_comments
ALTER TABLE feedback_comments ADD COLUMN form_id INT NOT NULL AFTER id;
UPDATE feedback_comments SET form_id = feedback_form_id;
ALTER TABLE feedback_comments DROP FOREIGN KEY fk_comment_form;
ALTER TABLE feedback_comments DROP COLUMN feedback_form_id;
ALTER TABLE feedback_comments ADD CONSTRAINT fk_comment_form FOREIGN KEY(form_id) REFERENCES feedback_forms(id) ON DELETE CASCADE;

-- ============================================================
-- STEP 3: Create new global_feedback_questions with module column
-- ============================================================
CREATE TABLE IF NOT EXISTS global_feedback_questions_new (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module ENUM('academic','student_affairs','administration') NOT NULL,
    question_no INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('rating','comment') NOT NULL DEFAULT 'rating',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Migrate academic global questions
INSERT INTO global_feedback_questions_new (module, question_no, question_text, question_type, created_at, updated_at)
SELECT 'academic', question_no, question_text, question_type, created_at, updated_at
FROM global_feedback_questions;

-- Migrate SA global questions
INSERT INTO global_feedback_questions_new (module, question_no, question_text, question_type, created_at, updated_at)
SELECT 'student_affairs', question_no, question_text, question_type, created_at, updated_at
FROM global_sa_feedback_questions;

-- Migrate Admin global questions
INSERT INTO global_feedback_questions_new (module, question_no, question_text, question_type, created_at, updated_at)
SELECT 'administration', question_no, question_text, question_type, created_at, updated_at
FROM global_adm_feedback_questions;

-- Drop old and rename new
DROP TABLE IF EXISTS global_feedback_questions;
ALTER TABLE global_feedback_questions_new RENAME TO global_feedback_questions;

-- ============================================================
-- STEP 4: Migrate SA forms → feedback_forms
-- ============================================================
INSERT INTO feedback_forms (module, title, start_date, end_date, status, created_at, updated_at)
SELECT 'student_affairs', title, start_date, end_date, status, created_at, updated_at
FROM sa_feedback_forms;

CREATE TEMPORARY TABLE sa_form_map (old_id INT, new_id INT);
INSERT INTO sa_form_map (old_id, new_id)
SELECT sf.id, ff.id
FROM sa_feedback_forms sf
JOIN feedback_forms ff ON ff.module = 'student_affairs'
    AND ff.title = sf.title
    AND ff.start_date = sf.start_date
    AND ff.end_date = sf.end_date;

-- Migrate SA questions
CREATE TEMPORARY TABLE sa_question_map (old_id INT, new_id INT);

INSERT INTO feedback_questions (form_id, question_no, question_text, question_type)
SELECT m.new_id, sq.question_no, sq.question_text, sq.question_type
FROM sa_feedback_questions sq
JOIN sa_form_map m ON sq.form_id = m.old_id;

INSERT INTO sa_question_map (old_id, new_id)
SELECT sq_old.id, sq_new.id
FROM sa_feedback_questions sq_old
JOIN sa_form_map m ON sq_old.form_id = m.old_id
JOIN feedback_questions sq_new ON sq_new.form_id = m.new_id
    AND sq_new.question_no = sq_old.question_no
    AND sq_new.question_text = sq_old.question_text;

-- Migrate SA submissions
INSERT INTO feedback_submissions (form_id, student_id, submitted_at)
SELECT m.new_id, s.student_id, s.submitted_at
FROM sa_feedback_submissions s
JOIN sa_form_map m ON s.form_id = m.old_id;

-- Migrate SA ratings
INSERT INTO feedback_ratings (form_id, student_id, question_id, rating, created_at)
SELECT m.new_id, sr.student_id, qm.new_id, sr.rating, sr.created_at
FROM sa_feedback_ratings sr
JOIN sa_form_map m ON sr.form_id = m.old_id
JOIN sa_question_map qm ON sr.question_id = qm.old_id;

-- Migrate SA comments
INSERT INTO feedback_comments (form_id, student_id, question_id, comment_text, created_at)
SELECT m.new_id, sc.student_id, qm.new_id, sc.comment_text, sc.created_at
FROM sa_feedback_comments sc
JOIN sa_form_map m ON sc.form_id = m.old_id
JOIN sa_question_map qm ON sc.question_id = qm.old_id;

-- ============================================================
-- STEP 5: Migrate Admin forms → feedback_forms
-- ============================================================
INSERT INTO feedback_forms (module, title, start_date, end_date, status, created_at, updated_at)
SELECT 'administration', title, start_date, end_date, status, created_at, updated_at
FROM adm_feedback_forms;

CREATE TEMPORARY TABLE adm_form_map (old_id INT, new_id INT);
INSERT INTO adm_form_map (old_id, new_id)
SELECT af.id, ff.id
FROM adm_feedback_forms af
JOIN feedback_forms ff ON ff.module = 'administration'
    AND ff.title = af.title
    AND ff.start_date = af.start_date
    AND ff.end_date = af.end_date;

-- Migrate Admin questions
CREATE TEMPORARY TABLE adm_question_map (old_id INT, new_id INT);

INSERT INTO feedback_questions (form_id, question_no, question_text, question_type)
SELECT m.new_id, aq.question_no, aq.question_text, aq.question_type
FROM adm_feedback_questions aq
JOIN adm_form_map m ON aq.form_id = m.old_id;

INSERT INTO adm_question_map (old_id, new_id)
SELECT aq_old.id, aq_new.id
FROM adm_feedback_questions aq_old
JOIN adm_form_map m ON aq_old.form_id = m.old_id
JOIN feedback_questions aq_new ON aq_new.form_id = m.new_id
    AND aq_new.question_no = aq_old.question_no
    AND aq_new.question_text = aq_old.question_text;

-- Migrate Admin submissions
INSERT INTO feedback_submissions (form_id, student_id, submitted_at)
SELECT m.new_id, s.student_id, s.submitted_at
FROM adm_feedback_submissions s
JOIN adm_form_map m ON s.form_id = m.old_id;

-- Migrate Admin ratings
INSERT INTO feedback_ratings (form_id, student_id, question_id, rating, created_at)
SELECT m.new_id, ar.student_id, qm.new_id, ar.rating, ar.created_at
FROM adm_feedback_ratings ar
JOIN adm_form_map m ON ar.form_id = m.old_id
JOIN adm_question_map qm ON ar.question_id = qm.old_id;

-- Migrate Admin comments
INSERT INTO feedback_comments (form_id, student_id, question_id, comment_text, created_at)
SELECT m.new_id, ac.student_id, qm.new_id, ac.comment_text, ac.created_at
FROM adm_feedback_comments ac
JOIN adm_form_map m ON ac.form_id = m.old_id
JOIN adm_question_map qm ON ac.question_id = qm.old_id;

-- ============================================================
-- STEP 6: Drop all old redundant tables
-- ============================================================
DROP TABLE IF EXISTS sa_feedback_comments;
DROP TABLE IF EXISTS sa_feedback_ratings;
DROP TABLE IF EXISTS sa_feedback_submissions;
DROP TABLE IF EXISTS sa_feedback_questions;
DROP TABLE IF EXISTS sa_feedback_forms;

DROP TABLE IF EXISTS adm_feedback_comments;
DROP TABLE IF EXISTS adm_feedback_ratings;
DROP TABLE IF EXISTS adm_feedback_submissions;
DROP TABLE IF EXISTS adm_feedback_questions;
DROP TABLE IF EXISTS adm_feedback_forms;

DROP TABLE IF EXISTS global_sa_feedback_questions;
DROP TABLE IF EXISTS global_adm_feedback_questions;

-- ============================================================
-- STEP 7: Add performance indexes
-- ============================================================
CREATE INDEX idx_fb_forms_module ON feedback_forms(module);
CREATE INDEX idx_fb_forms_status ON feedback_forms(status);
CREATE INDEX idx_fb_questions_form ON feedback_questions(form_id);
CREATE INDEX idx_fb_submissions_form ON feedback_submissions(form_id);
CREATE INDEX idx_fb_submissions_student ON feedback_submissions(student_id);
CREATE INDEX idx_fb_ratings_form ON feedback_ratings(form_id);
CREATE INDEX idx_fb_ratings_question ON feedback_ratings(question_id);
CREATE INDEX idx_fb_comments_form ON feedback_comments(form_id);
CREATE INDEX idx_fb_comments_question ON feedback_comments(question_id);
CREATE INDEX idx_globalQuestions_module ON global_feedback_questions(module);

-- ============================================================
-- STEP 8: Clean up temp tables
-- ============================================================
DROP TEMPORARY TABLE IF EXISTS sa_form_map;
DROP TEMPORARY TABLE IF EXISTS sa_question_map;
DROP TEMPORARY TABLE IF EXISTS adm_form_map;
DROP TEMPORARY TABLE IF EXISTS adm_question_map;

-- ============================================================
-- VERIFICATION (uncomment to check)
-- ============================================================
-- SELECT 'feedback_forms' AS tbl, module, COUNT(*) AS cnt FROM feedback_forms GROUP BY module;
-- SELECT 'feedback_questions' AS tbl, COUNT(*) AS cnt FROM feedback_questions;
-- SELECT 'feedback_submissions' AS tbl, COUNT(*) AS cnt FROM feedback_submissions;
-- SELECT 'feedback_ratings' AS tbl, COUNT(*) AS cnt FROM feedback_ratings;
-- SELECT 'feedback_comments' AS tbl, COUNT(*) AS cnt FROM feedback_comments;
-- SELECT 'global_feedback_questions' AS tbl, module, COUNT(*) AS cnt FROM global_feedback_questions GROUP BY module;
-- SHOW TABLES;
