-- ============================================================
-- SFIS Migration v10: Add Survey (MCQ) question type
-- Adds options_json column + feedback_survey_answers table
-- ============================================================
USE studentfeedbackintern;

-- ============================================================
-- STEP 1: Add options_json column to feedback_questions
-- (nullable TEXT column for storing 4 MCQ options as JSON)
-- ============================================================
ALTER TABLE feedback_questions
ADD COLUMN options_json TEXT NULL AFTER question_text;

-- ============================================================
-- STEP 2: Safely modify the question_type ENUM to include 'Survey'
-- MySQL requires rebuilding the ENUM; we add 'survey' alongside
-- existing 'rating' and 'comment' values.
-- ============================================================
ALTER TABLE feedback_questions
MODIFY COLUMN question_type ENUM('rating','comment','survey') NOT NULL DEFAULT 'rating';

-- ============================================================
-- STEP 3: Create feedback_survey_answers table
-- Stores one row per student per survey question
-- ============================================================
CREATE TABLE IF NOT EXISTS feedback_survey_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    question_id INT NOT NULL,
    selected_option_index INT NOT NULL COMMENT '0, 1, 2, or 3',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (submission_id) REFERENCES feedback_submissions(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES feedback_questions(id) ON DELETE CASCADE,
    INDEX idx_fsa_submission (submission_id),
    INDEX idx_fsa_question (question_id),
    INDEX idx_fsa_question_option (question_id, selected_option_index)
) ENGINE=InnoDB;

-- ============================================================
-- VERIFICATION
-- ============================================================
-- SELECT COLUMN_TYPE FROM information_schema.COLUMNS
-- WHERE TABLE_SCHEMA = 'studentfeedbackintern' AND TABLE_NAME = 'feedback_questions' AND COLUMN_NAME = 'question_type';
-- DESCRIBE feedback_survey_answers;
