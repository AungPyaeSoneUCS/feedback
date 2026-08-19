-- SFIS v27: corrective one-time synchronization for Upcoming forms with zero submissions.
-- Match the same active Question Set selection used by feedback form creation:
-- the form's Academic Year plus its module. Submitted forms remain untouched.

START TRANSACTION;

CREATE TEMPORARY TABLE v27_unsubmitted_form_targets (
    form_id INT PRIMARY KEY,
    previous_question_set_id INT NULL,
    target_question_set_id INT NOT NULL
) ENGINE=InnoDB;

INSERT INTO v27_unsubmitted_form_targets (
    form_id,
    previous_question_set_id,
    target_question_set_id
)
SELECT
    ff.id,
    ff.question_set_id,
    current_set.id
FROM feedback_forms ff
JOIN feedback_question_sets current_set
  ON current_set.academic_year_id = ff.academic_year_id
 AND current_set.module = ff.module
 AND current_set.status = 'active'
WHERE NOW() < ff.start_date
  AND NOT EXISTS (
    SELECT 1
    FROM feedback_submissions fs
    WHERE fs.form_id = ff.id
)
  AND NOT (ff.question_set_id <=> current_set.id);

UPDATE feedback_forms ff
JOIN v27_unsubmitted_form_targets migration_target
  ON migration_target.form_id = ff.id
LEFT JOIN feedback_submissions submission_guard
  ON submission_guard.form_id = ff.id
SET ff.question_set_id = migration_target.target_question_set_id
WHERE submission_guard.id IS NULL
  AND NOW() < ff.start_date;

SELECT COUNT(*) AS corrected_forms
FROM v27_unsubmitted_form_targets;

COMMIT;
