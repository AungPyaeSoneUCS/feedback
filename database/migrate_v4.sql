-- ============================================================
-- SFIS v4 Migration — Global SA & Admin Feedback Questions
-- Questions are now shared across all forms (same as academic)
-- ============================================================
USE studentfeedback;

-- ────────────────────────────────────────────────────────────
-- 1. Ensure all SA/Admin tables are InnoDB (required for FKs)
-- ────────────────────────────────────────────────────────────
ALTER TABLE IF EXISTS sa_feedback_forms ENGINE=InnoDB;
ALTER TABLE IF EXISTS sa_feedback_questions ENGINE=InnoDB;
ALTER TABLE IF EXISTS sa_feedback_submissions ENGINE=InnoDB;
ALTER TABLE IF EXISTS sa_feedback_ratings ENGINE=InnoDB;
ALTER TABLE IF EXISTS sa_feedback_comments ENGINE=InnoDB;
ALTER TABLE IF EXISTS adm_feedback_forms ENGINE=InnoDB;
ALTER TABLE IF EXISTS adm_feedback_questions ENGINE=InnoDB;
ALTER TABLE IF EXISTS adm_feedback_submissions ENGINE=InnoDB;
ALTER TABLE IF EXISTS adm_feedback_ratings ENGINE=InnoDB;
ALTER TABLE IF EXISTS adm_feedback_comments ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- 2. Create global SA feedback questions table
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS global_sa_feedback_questions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    question_no   INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('rating','comment') NOT NULL DEFAULT 'rating',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- 3. Create global Admin feedback questions table
-- ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS global_adm_feedback_questions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    question_no   INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('rating','comment') NOT NULL DEFAULT 'rating',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ────────────────────────────────────────────────────────────
-- 4. Migrate existing SA questions (deduplicate by text+type)
-- ────────────────────────────────────────────────────────────
INSERT IGNORE INTO global_sa_feedback_questions (question_no, question_text, question_type)
SELECT MIN(sq.question_no), sq.question_text, sq.question_type
FROM sa_feedback_questions sq
GROUP BY sq.question_text, sq.question_type;

-- ────────────────────────────────────────────────────────────
-- 5. Migrate existing Admin questions (deduplicate by text+type)
-- ────────────────────────────────────────────────────────────
INSERT IGNORE INTO global_adm_feedback_questions (question_no, question_text, question_type)
SELECT MIN(aq.question_no), aq.question_text, aq.question_type
FROM adm_feedback_questions aq
GROUP BY aq.question_text, aq.question_type;

-- ────────────────────────────────────────────────────────────
-- 6. Delete orphaned ratings/comments referencing missing questions
-- ────────────────────────────────────────────────────────────
DELETE FROM sa_feedback_ratings WHERE question_id NOT IN (SELECT id FROM global_sa_feedback_questions);
DELETE FROM sa_feedback_comments WHERE question_id NOT IN (SELECT id FROM global_sa_feedback_questions);
DELETE FROM adm_feedback_ratings WHERE question_id NOT IN (SELECT id FROM global_adm_feedback_questions);
DELETE FROM adm_feedback_comments WHERE question_id NOT IN (SELECT id FROM global_adm_feedback_questions);

-- ────────────────────────────────────────────────────────────
-- 7. Drop old FK constraints on SA ratings/comments
-- ────────────────────────────────────────────────────────────
SET @fk_sa_rat = (
    SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sa_feedback_ratings'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
      AND CONSTRAINT_NAME LIKE '%question%'
    LIMIT 1
);
SET @fk_sa_com = (
    SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'sa_feedback_comments'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
      AND CONSTRAINT_NAME LIKE '%question%'
    LIMIT 1
);

SET @sql1 = IF(@fk_sa_rat IS NOT NULL, CONCAT('ALTER TABLE sa_feedback_ratings DROP FOREIGN KEY ', @fk_sa_rat), 'SELECT 1');
SET @sql2 = IF(@fk_sa_com IS NOT NULL, CONCAT('ALTER TABLE sa_feedback_comments DROP FOREIGN KEY ', @fk_sa_com), 'SELECT 1');

PREPARE s1 FROM @sql1; EXECUTE s1; DEALLOCATE PREPARE s1;
PREPARE s2 FROM @sql2; EXECUTE s2; DEALLOCATE PREPARE s2;

-- ────────────────────────────────────────────────────────────
-- 8. Drop old FK constraints on Admin ratings/comments
-- ────────────────────────────────────────────────────────────
SET @fk_adm_rat = (
    SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'adm_feedback_ratings'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
      AND CONSTRAINT_NAME LIKE '%question%'
    LIMIT 1
);
SET @fk_adm_com = (
    SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'adm_feedback_comments'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
      AND CONSTRAINT_NAME LIKE '%question%'
    LIMIT 1
);

SET @sql3 = IF(@fk_adm_rat IS NOT NULL, CONCAT('ALTER TABLE adm_feedback_ratings DROP FOREIGN KEY ', @fk_adm_rat), 'SELECT 1');
SET @sql4 = IF(@fk_adm_com IS NOT NULL, CONCAT('ALTER TABLE adm_feedback_comments DROP FOREIGN KEY ', @fk_adm_com), 'SELECT 1');

PREPARE s3 FROM @sql3; EXECUTE s3; DEALLOCATE PREPARE s3;
PREPARE s4 FROM @sql4; EXECUTE s4; DEALLOCATE PREPARE s4;

-- ────────────────────────────────────────────────────────────
-- 9. Add new FK constraints referencing global tables
-- ────────────────────────────────────────────────────────────
ALTER TABLE sa_feedback_ratings
    ADD CONSTRAINT fk_sa_rat_global_question
    FOREIGN KEY (question_id) REFERENCES global_sa_feedback_questions(id) ON DELETE CASCADE;

ALTER TABLE sa_feedback_comments
    ADD CONSTRAINT fk_sa_com_global_question
    FOREIGN KEY (question_id) REFERENCES global_sa_feedback_questions(id) ON DELETE CASCADE;

ALTER TABLE adm_feedback_ratings
    ADD CONSTRAINT fk_adm_rat_global_question
    FOREIGN KEY (question_id) REFERENCES global_adm_feedback_questions(id) ON DELETE CASCADE;

ALTER TABLE adm_feedback_comments
    ADD CONSTRAINT fk_adm_com_global_question
    FOREIGN KEY (question_id) REFERENCES global_adm_feedback_questions(id) ON DELETE CASCADE;
