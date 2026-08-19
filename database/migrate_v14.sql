-- ============================================================
-- Migration v14: Fix feedback_questions UNIQUE constraint
-- Problem: uq_module_qno(module, question_type, question_no)
--   prevents same question_no across ALL sets for a module.
-- Fix: Change to per-set constraint (question_set_id, question_type, question_no)
-- ============================================================

-- 1. Drop the old module-scoped unique key
ALTER TABLE feedback_questions
    DROP INDEX uq_module_qno;

-- 2. Add new per-set unique key
ALTER TABLE feedback_questions
    ADD UNIQUE KEY uq_qs_qno (question_set_id, question_type, question_no);

-- 3. Clean up any orphaned module values where question_set_id is NULL
--    (set module to match the question_set's module where possible)
UPDATE feedback_questions fq
    JOIN feedback_question_sets fqs ON fq.question_set_id = fqs.id
    SET fq.module = fqs.module
    WHERE fq.module != fqs.module;

-- 4. For any questions with NULL question_set_id, remove them (orphaned)
DELETE FROM feedback_questions WHERE question_set_id IS NULL;
