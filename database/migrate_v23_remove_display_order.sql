-- SFIS v23: remove manual survey display ordering.
-- Groups now use creation order (id); questions use their generated question_code.
START TRANSACTION;

ALTER TABLE survey_groups
    DROP INDEX idx_survey_group_order,
    DROP COLUMN display_order;

ALTER TABLE feedback_questions
    DROP INDEX idx_group_question_order,
    DROP INDEX idx_fq_set_order,
    DROP COLUMN display_order;

COMMIT;
