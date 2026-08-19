-- SFIS v26: one-time refresh for Upcoming forms with zero submissions.
--
-- The current schema stores a Question Set reference on each feedback form;
-- questions and Survey Groups are not stored per form. Therefore, changing or
-- deleting rows in an old shared Question Set would also change historical
-- forms. This migration safely points only forms with zero submissions to the
-- latest active Question Set for the same module.

START TRANSACTION;

CREATE TEMPORARY TABLE v26_unsubmitted_form_targets (
    form_id INT PRIMARY KEY,
    previous_question_set_id INT NULL,
    target_question_set_id INT NOT NULL
) ENGINE=InnoDB;

INSERT INTO v26_unsubmitted_form_targets (
    form_id,
    previous_question_set_id,
    target_question_set_id
)
SELECT
    candidate.form_id,
    candidate.previous_question_set_id,
    candidate.target_question_set_id
FROM (
    SELECT
        ff.id AS form_id,
        ff.question_set_id AS previous_question_set_id,
        (
            SELECT current_set.id
            FROM feedback_question_sets current_set
            WHERE current_set.module = ff.module
              AND current_set.status = 'active'
            ORDER BY
                current_set.academic_year_id DESC,
                current_set.created_at DESC,
                current_set.id DESC
            LIMIT 1
        ) AS target_question_set_id
    FROM feedback_forms ff
    WHERE NOW() < ff.start_date
      AND NOT EXISTS (
        SELECT 1
        FROM feedback_submissions fs
        WHERE fs.form_id = ff.id
    )
) candidate
WHERE candidate.target_question_set_id IS NOT NULL
  AND NOT (candidate.previous_question_set_id <=> candidate.target_question_set_id);

UPDATE feedback_forms ff
JOIN v26_unsubmitted_form_targets migration_target
  ON migration_target.form_id = ff.id
LEFT JOIN feedback_submissions submission_guard
  ON submission_guard.form_id = ff.id
SET ff.question_set_id = migration_target.target_question_set_id
WHERE submission_guard.id IS NULL
  AND NOW() < ff.start_date;

SELECT
    COUNT(*) AS migrated_forms
FROM v26_unsubmitted_form_targets;

COMMIT;
