-- Rename the three feedback module identifiers without changing related records.
-- The temporary expanded ENUM prevents data loss while existing values are converted.

ALTER TABLE feedback_question_sets
    MODIFY module ENUM(
        'academic',
        'student_affairs',
        'administration',
        'teaching_quality',
        'student_support_services',
        'learning_environment'
    ) NOT NULL;

ALTER TABLE feedback_forms
    MODIFY module ENUM(
        'academic',
        'student_affairs',
        'administration',
        'teaching_quality',
        'student_support_services',
        'learning_environment'
    ) NOT NULL DEFAULT 'academic';

UPDATE feedback_question_sets
SET module = CASE module
    WHEN 'academic' THEN 'teaching_quality'
    WHEN 'student_affairs' THEN 'student_support_services'
    WHEN 'administration' THEN 'learning_environment'
    ELSE module
END;

UPDATE feedback_forms
SET module = CASE module
    WHEN 'academic' THEN 'teaching_quality'
    WHEN 'student_affairs' THEN 'student_support_services'
    WHEN 'administration' THEN 'learning_environment'
    ELSE module
END;

ALTER TABLE feedback_question_sets
    MODIFY module ENUM(
        'teaching_quality',
        'student_support_services',
        'learning_environment'
    ) NOT NULL;

ALTER TABLE feedback_forms
    MODIFY module ENUM(
        'teaching_quality',
        'student_support_services',
        'learning_environment'
    ) NOT NULL DEFAULT 'teaching_quality';
