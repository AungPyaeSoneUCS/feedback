-- SFIS v28: remove questions accidentally propagated into an Active form snapshot.
--
-- Question Set 21 was already used by Active Teaching Quality forms when TQ20-TQ27
-- were copied into it. Active forms must retain their original TQ1-TQ19 snapshot.
-- The answer guards make this correction safe to rerun and prevent removal of any
-- question that has subsequently received a student response.

START TRANSACTION;

DELETE fq
FROM feedback_questions fq
WHERE fq.question_set_id = 21
  AND fq.question_code IN ('TQ20', 'TQ21', 'TQ22', 'TQ23', 'TQ24', 'TQ25', 'TQ26', 'TQ27')
  AND NOT EXISTS (
      SELECT 1
      FROM feedback_survey_answers fsa
      WHERE fsa.question_id = fq.id
  );

SELECT ROW_COUNT() AS removed_unanswered_propagated_questions;

COMMIT;
