-- ============================================================
-- SFIS Migration v11: DATE → DATETIME for feedback periods
-- Changes start_date/end_date from DATE to DATETIME
-- Removes manual status column (status is now computed dynamically)
-- ============================================================
USE studentfeedbackintern;

-- ============================================================
-- STEP 1: Convert start_date DATE → DATETIME
-- Existing DATE values are preserved as 'YYYY-MM-DD 00:00:00'
-- ============================================================
ALTER TABLE feedback_forms
    MODIFY COLUMN start_date DATETIME NOT NULL;

-- ============================================================
-- STEP 2: Convert end_date DATE → DATETIME
-- ============================================================
ALTER TABLE feedback_forms
    MODIFY COLUMN end_date DATETIME NOT NULL;

-- ============================================================
-- STEP 3: Drop the status column (now computed dynamically)
-- ============================================================
ALTER TABLE feedback_forms
    DROP COLUMN status;

-- ============================================================
-- VERIFICATION
-- ============================================================
-- DESCRIBE feedback_forms;
-- SELECT id, title, start_date, end_date FROM feedback_forms LIMIT 5;
