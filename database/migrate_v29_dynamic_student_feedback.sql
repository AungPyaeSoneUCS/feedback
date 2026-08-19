-- Student assignments are the only audience mapping for teaching-quality forms.
-- Remove legacy duplicates before enforcing one assignment per student/section.
DELETE sa_old
FROM section_assignments sa_old
JOIN section_assignments sa_keep
  ON sa_keep.student_id = sa_old.student_id
 AND sa_keep.section_id = sa_old.section_id
 AND sa_keep.id < sa_old.id;

SET @uq_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'section_assignments'
      AND INDEX_NAME = 'uq_section_assignment');
SET @sql = IF(@uq_exists = 0,
    'ALTER TABLE section_assignments ADD UNIQUE KEY uq_section_assignment (student_id, section_id)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- A form belongs to the course/teacher represented by its section. Deleting
-- that section must not leave an unreachable feedback form behind.
DELETE ff
FROM feedback_forms ff
LEFT JOIN sections s ON s.id = ff.section_id
WHERE ff.section_id IS NOT NULL AND s.id IS NULL;

SET @fk_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'feedback_forms'
      AND CONSTRAINT_NAME = 'fk_feedback_forms_section');
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE feedback_forms ADD CONSTRAINT fk_feedback_forms_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
