-- ============================================================
-- SFIS Migration v12: Restore status column to feedback_forms
-- ENUM('Upcoming','Active','Expired') with auto-calculation
-- ============================================================
USE studentfeedbackintern;

-- ============================================================
-- STEP 1: Add status column after end_date
-- ============================================================
ALTER TABLE feedback_forms
ADD COLUMN status ENUM('Upcoming','Active','Expired')
NOT NULL DEFAULT 'Upcoming'
AFTER end_date;

-- ============================================================
-- STEP 2: Recalculate status for all existing records
-- ============================================================
UPDATE feedback_forms
SET status = CASE
    WHEN NOW() < start_date THEN 'Upcoming'
    WHEN NOW() >= start_date AND NOW() <= end_date THEN 'Active'
    WHEN NOW() > end_date THEN 'Expired'
END;

-- ============================================================
-- VERIFICATION
-- ============================================================
-- DESCRIBE feedback_forms;
-- SELECT id, title, start_date, end_date, status FROM feedback_forms;
