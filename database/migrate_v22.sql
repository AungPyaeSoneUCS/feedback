-- SFIS v22: normalized bilingual survey groups/questions and fixed Likert ratings.
START TRANSACTION;

CREATE TABLE survey_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_set_id INT NOT NULL,
    group_code VARCHAR(30) NOT NULL,
    group_name_en VARCHAR(200) NOT NULL,
    group_name_mm VARCHAR(200) NOT NULL,
    instruction_en TEXT NULL,
    instruction_mm TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_survey_group_question_set FOREIGN KEY (question_set_id)
        REFERENCES feedback_question_sets(id) ON DELETE CASCADE,
    UNIQUE KEY uq_survey_group_code (question_set_id, group_code),
    INDEX idx_survey_group_set (question_set_id)
) ENGINE=InnoDB;

INSERT INTO survey_groups
    (question_set_id, group_code, group_name_en, group_name_mm,
     instruction_en, instruction_mm)
SELECT id, 'GENERAL', 'General', 'á€¡á€‘á€½á€±á€‘á€½á€±',
       'Please select one response for each statement.',
       'á€–á€±á€¬á€ºá€•á€¼á€á€»á€€á€ºá€á€…á€ºá€á€¯á€…á€®á€¡á€á€½á€€á€º á€¡á€–á€¼á€±á€á€…á€ºá€á€¯á€€á€­á€¯ á€›á€½á€±á€¸á€á€»á€šá€ºá€•á€«á‹'
FROM feedback_question_sets;

-- These are detached legacy questions left by deleted question sets. They are
-- not used by any form or submission and cannot satisfy the new mandatory
-- group relationship. The pre-migration backup retains them.
DELETE fq FROM feedback_questions fq
LEFT JOIN feedback_survey_answers fsa ON fsa.question_id = fq.id
WHERE fq.question_set_id IS NULL AND fsa.id IS NULL;

ALTER TABLE feedback_questions
    DROP CHECK chk_feedback_question_options,
    DROP INDEX uq_qs_qno,
    ADD COLUMN survey_group_id INT NULL AFTER question_set_id,
    ADD COLUMN question_code VARCHAR(30) NULL AFTER survey_group_id,
    ADD COLUMN question_text_en TEXT NULL AFTER question_code,
    ADD COLUMN question_text_mm TEXT NULL AFTER question_text_en;

UPDATE feedback_questions fq
JOIN survey_groups sg ON sg.question_set_id = fq.question_set_id
SET fq.survey_group_id = sg.id,
    fq.question_code = CONCAT('Q', fq.question_no),
    fq.question_text_en = fq.question_text,
    fq.question_text_mm = fq.question_text;

ALTER TABLE feedback_questions
    MODIFY survey_group_id INT NOT NULL,
    MODIFY question_code VARCHAR(30) NOT NULL,
    MODIFY question_text_en TEXT NOT NULL,
    MODIFY question_text_mm TEXT NOT NULL,
    DROP COLUMN question_no,
    DROP COLUMN question_text,
    DROP COLUMN options_json,
    ADD CONSTRAINT fk_feedback_question_group FOREIGN KEY (survey_group_id)
        REFERENCES survey_groups(id) ON DELETE CASCADE,
    ADD UNIQUE KEY uq_group_question_code (survey_group_id, question_code),
    ADD INDEX idx_fq_group (survey_group_id);

ALTER TABLE feedback_survey_answers
    ADD COLUMN rating TINYINT UNSIGNED NULL AFTER question_id;

UPDATE feedback_survey_answers
SET rating = CASE selected_option_index
    WHEN 0 THEN 5
    WHEN 1 THEN 3
    WHEN 2 THEN 1
    ELSE 1
END;

ALTER TABLE feedback_survey_answers
    DROP INDEX idx_fsa_question_option,
    MODIFY rating TINYINT UNSIGNED NOT NULL,
    DROP COLUMN selected_option_index,
    ADD CONSTRAINT chk_feedback_survey_rating CHECK (rating BETWEEN 1 AND 5),
    ADD INDEX idx_fsa_question_rating (question_id, rating);

COMMIT;

