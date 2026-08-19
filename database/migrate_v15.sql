-- ============================================================
-- Migration v15: Remove semester_id from feedback_question_sets
-- Question sets are now scoped by (academic_year_id, module) only.
-- One question set per academic year + module, shared across all semesters.
-- ============================================================

-- 1. For duplicate question sets (same academic_year_id + module, different semester_id),
--    keep the one with the most questions. Reassign feedback_forms to the kept set.
--    Delete redundant sets and their questions.

-- Create a temp table of sets to KEEP (one per year+module, the one with highest id)
CREATE TEMPORARY TABLE _qs_keep AS
SELECT fqs.id AS keep_id
FROM feedback_question_sets fqs
INNER JOIN (
    SELECT academic_year_id, module, MAX(id) AS max_id
    FROM feedback_question_sets
    GROUP BY academic_year_id, module
    HAVING COUNT(*) > 1
) dup ON fqs.academic_year_id = dup.academic_year_id
     AND fqs.module = dup.module
     AND fqs.id = dup.max_id;

-- Also keep sets that have no duplicates (they stay as-is)
INSERT IGNORE INTO _qs_keep
SELECT fqs.id FROM feedback_question_sets fqs
WHERE NOT EXISTS (
    SELECT 1 FROM feedback_question_sets fqs2
    WHERE fqs2.academic_year_id = fqs.academic_year_id
      AND fqs2.module = fqs.module
      AND fqs2.id != fqs.id
);

-- Reassign feedback_forms that reference non-kept sets to the kept set
UPDATE feedback_forms ff
    INNER JOIN feedback_question_sets fqs ON ff.question_set_id = fqs.id
    INNER JOIN (
        SELECT fqs_inner.academic_year_id, fqs_inner.module, _qs_keep.keep_id
        FROM feedback_question_sets fqs_inner
        JOIN _qs_keep ON fqs_inner.id != _qs_keep.keep_id
    ) repl ON fqs.academic_year_id = repl.academic_year_id
          AND fqs.module = repl.module
          AND fqs.id != repl.keep_id
SET ff.question_set_id = repl.keep_id
WHERE ff.question_set_id IS NOT NULL;

-- Delete questions from non-kept sets
DELETE fq FROM feedback_questions fq
    INNER JOIN feedback_question_sets fqs ON fq.question_set_id = fqs.id
    LEFT JOIN _qs_keep ON fqs.id = _qs_keep.keep_id
WHERE _qs_keep.keep_id IS NULL;

-- Delete non-kept question sets
DELETE fqs FROM feedback_question_sets fqs
    LEFT JOIN _qs_keep ON fqs.id = _qs_keep.keep_id
WHERE _qs_keep.keep_id IS NULL;

DROP TEMPORARY TABLE _qs_keep;

-- 2. Drop the foreign key on semester_id
SET @fk_name = (
    SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'feedback_question_sets'
      AND COLUMN_NAME = 'semester_id'
      AND REFERENCED_TABLE_NAME IS NOT NULL
    LIMIT 1
);
SET @sql = IF(@fk_name IS NOT NULL, CONCAT('ALTER TABLE feedback_question_sets DROP FOREIGN KEY `', @fk_name, '`'), 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Drop the semester_id column
ALTER TABLE feedback_question_sets DROP COLUMN semester_id;

-- 4. Update the unique constraint
ALTER TABLE feedback_question_sets DROP INDEX uq_question_set;
ALTER TABLE feedback_question_sets ADD UNIQUE KEY uq_question_set (academic_year_id, module);

-- 5. Update indexes
ALTER TABLE feedback_question_sets DROP INDEX idx_fqs_ay_sem;
ALTER TABLE feedback_question_sets ADD INDEX idx_fqs_ay_mod (academic_year_id, module);
