-- ============================================================
-- SFIS v2 Migration — Student Affairs & Administration Modules
-- Run this once against the existing `studentfeedback` database
-- ============================================================
USE studentfeedback;

-- ────────────────────────────────────────────────────────────
-- STUDENT AFFAIRS FEEDBACK MODULE
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS sa_feedback_forms (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(150) NOT NULL,
    description TEXT DEFAULT NULL,
    start_date  DATE NOT NULL,
    end_date    DATE NOT NULL,
    status      ENUM('active','inactive') DEFAULT 'active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sa_feedback_questions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    form_id       INT NOT NULL,
    question_no   INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('rating','comment') NOT NULL DEFAULT 'rating',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sa_question_form FOREIGN KEY (form_id)
        REFERENCES sa_feedback_forms(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sa_feedback_submissions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    form_id     INT NOT NULL,
    student_id  INT NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sa_sub (form_id, student_id),
    CONSTRAINT fk_sa_sub_form    FOREIGN KEY (form_id)    REFERENCES sa_feedback_forms(id)  ON DELETE CASCADE,
    CONSTRAINT fk_sa_sub_student FOREIGN KEY (student_id) REFERENCES students(id)            ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sa_feedback_ratings (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    form_id     INT NOT NULL,
    question_id INT NOT NULL,
    student_id  INT NOT NULL,
    rating      ENUM('Good','Fair','Bad','Excellent','Poor') NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sa_rat_form     FOREIGN KEY (form_id)     REFERENCES sa_feedback_forms(id)     ON DELETE CASCADE,
    CONSTRAINT fk_sa_rat_question FOREIGN KEY (question_id) REFERENCES sa_feedback_questions(id)  ON DELETE CASCADE,
    CONSTRAINT fk_sa_rat_student  FOREIGN KEY (student_id)  REFERENCES students(id)               ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sa_feedback_comments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    form_id      INT NOT NULL,
    question_id  INT NOT NULL,
    student_id   INT NOT NULL,
    comment_text TEXT NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sa_com_form     FOREIGN KEY (form_id)     REFERENCES sa_feedback_forms(id)     ON DELETE CASCADE,
    CONSTRAINT fk_sa_com_question FOREIGN KEY (question_id) REFERENCES sa_feedback_questions(id)  ON DELETE CASCADE,
    CONSTRAINT fk_sa_com_student  FOREIGN KEY (student_id)  REFERENCES students(id)               ON DELETE CASCADE
);

-- ────────────────────────────────────────────────────────────
-- ADMINISTRATION FEEDBACK MODULE
-- ────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS adm_feedback_forms (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(150) NOT NULL,
    description TEXT DEFAULT NULL,
    start_date  DATE NOT NULL,
    end_date    DATE NOT NULL,
    status      ENUM('active','inactive') DEFAULT 'active',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS adm_feedback_questions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    form_id       INT NOT NULL,
    question_no   INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('rating','comment') NOT NULL DEFAULT 'rating',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_adm_question_form FOREIGN KEY (form_id)
        REFERENCES adm_feedback_forms(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS adm_feedback_submissions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    form_id     INT NOT NULL,
    student_id  INT NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_adm_sub (form_id, student_id),
    CONSTRAINT fk_adm_sub_form    FOREIGN KEY (form_id)    REFERENCES adm_feedback_forms(id) ON DELETE CASCADE,
    CONSTRAINT fk_adm_sub_student FOREIGN KEY (student_id) REFERENCES students(id)           ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS adm_feedback_ratings (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    form_id     INT NOT NULL,
    question_id INT NOT NULL,
    student_id  INT NOT NULL,
    rating      ENUM('Good','Fair','Bad','Excellent','Poor') NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_adm_rat_form     FOREIGN KEY (form_id)     REFERENCES adm_feedback_forms(id)     ON DELETE CASCADE,
    CONSTRAINT fk_adm_rat_question FOREIGN KEY (question_id) REFERENCES adm_feedback_questions(id)  ON DELETE CASCADE,
    CONSTRAINT fk_adm_rat_student  FOREIGN KEY (student_id)  REFERENCES students(id)                ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS adm_feedback_comments (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    form_id      INT NOT NULL,
    question_id  INT NOT NULL,
    student_id   INT NOT NULL,
    comment_text TEXT NOT NULL,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_adm_com_form     FOREIGN KEY (form_id)     REFERENCES adm_feedback_forms(id)     ON DELETE CASCADE,
    CONSTRAINT fk_adm_com_question FOREIGN KEY (question_id) REFERENCES adm_feedback_questions(id)  ON DELETE CASCADE,
    CONSTRAINT fk_adm_com_student  FOREIGN KEY (student_id)  REFERENCES students(id)                ON DELETE CASCADE
);
