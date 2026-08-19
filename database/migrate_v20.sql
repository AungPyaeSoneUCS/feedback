-- ============================================================
-- SFIS v20 — Survey-only feedback
-- Preserves all survey questions, submissions, and survey answers.
-- Removes rating/comment response storage and makes every remaining
-- feedback question implicitly a survey question.
-- ============================================================
USE studentfeedbackintern;

START TRANSACTION;

-- These tables contain no survey data.
DROP TABLE IF EXISTS feedback_ratings;
DROP TABLE IF EXISTS feedback_comments;

-- Survey answers reference only survey questions. Remove question
-- definitions that can no longer be answered by the application.
DELETE FROM feedback_questions
WHERE question_type <> 'survey';

-- The type discriminator is redundant when Survey is the only type.
ALTER TABLE feedback_questions
    DROP INDEX uq_qs_qno,
    DROP INDEX idx_fq_module,
    DROP COLUMN question_type,
    DROP COLUMN module,
    DROP FOREIGN KEY fk_fq_question_set;

-- Preserve legacy Survey definitions while repairing stale optional
-- ownership references left by older migrations.
UPDATE feedback_questions fq
LEFT JOIN feedback_question_sets fqs ON fqs.id = fq.question_set_id
SET fq.question_set_id = NULL
WHERE fq.question_set_id IS NOT NULL
  AND fqs.id IS NULL;

ALTER TABLE feedback_questions
    MODIFY COLUMN options_json TEXT NOT NULL,
    ADD UNIQUE KEY uq_qs_qno (question_set_id, question_no),
    ADD CONSTRAINT fk_fq_question_set
        FOREIGN KEY (question_set_id) REFERENCES feedback_question_sets(id)
        ON DELETE SET NULL,
    ADD CONSTRAINT chk_feedback_question_options
        CHECK (JSON_VALID(options_json) AND JSON_LENGTH(options_json) >= 2);

ALTER TABLE feedback_survey_answers
    MODIFY COLUMN selected_option_index INT NOT NULL,
    ADD UNIQUE KEY uq_fsa_submission_question (submission_id, question_id);

COMMIT;
