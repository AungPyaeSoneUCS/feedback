-- ============================================================
-- SFIS Migration v9: Add academic_year column to feedback_forms
-- ============================================================
USE studentfeedbackintern;

ALTER TABLE feedback_forms
ADD COLUMN academic_year VARCHAR(20) NULL AFTER section_id;
