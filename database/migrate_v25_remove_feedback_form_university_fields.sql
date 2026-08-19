-- SFIS v25: remove unused university metadata from feedback forms.
-- University branding remains application-level content and is not affected.
ALTER TABLE feedback_forms
    DROP COLUMN university_name,
    DROP COLUMN university_campus;
