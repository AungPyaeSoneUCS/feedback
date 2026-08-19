-- ============================================================
-- SFIS v15 — Final Database Schema
-- Question Sets scoped by (academic_year_id, module) only.
-- One question set per academic year + module, shared across all semesters.
-- ============================================================
CREATE DATABASE IF NOT EXISTS studentfeedbackintern CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE studentfeedbackintern;

-- ============================================================
-- CORE TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin','teacher','student') NOT NULL,
    profile_image VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS teachers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    department_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    roll_no VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(50) NOT NULL UNIQUE,
    course_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS academic_years (
    id INT AUTO_INCREMENT PRIMARY KEY,
    year_name VARCHAR(20) NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'inactive',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_year_name (year_name),
    INDEX idx_ay_status (status)
) ENGINE=InnoDB;

-- Academic-year-specific performance grade configuration.
CREATE TABLE IF NOT EXISTS performance_grade_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year_id INT NOT NULL,
    grade_code VARCHAR(50) NOT NULL,
    min_score DECIMAL(5,2) NOT NULL,
    max_score DECIMAL(5,2) NOT NULL,
    recommendation_key VARCHAR(100) NOT NULL,
    recommendation TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
    UNIQUE KEY (academic_year_id, grade_code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS semesters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    semester_name VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS section_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_name VARCHAR(10) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    teacher_id INT NOT NULL,
    academic_year_id INT DEFAULT NULL,
    semester_id INT DEFAULT NULL,
    section_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE SET NULL,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE SET NULL,
    FOREIGN KEY (section_id) REFERENCES section_master(id) ON DELETE SET NULL,
    INDEX idx_sec_academic_year_id (academic_year_id),
    INDEX idx_sec_semester_id (semester_id),
    INDEX idx_sections_section_id (section_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS section_assignments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    section_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    UNIQUE KEY uq_section_assignment (student_id, section_id)
) ENGINE=InnoDB;

-- ============================================================
-- FEEDBACK TABLES
-- ============================================================

CREATE TABLE IF NOT EXISTS feedback_question_sets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year_id INT NOT NULL,
    module ENUM('teaching_quality','student_support_services','learning_environment') NOT NULL,
    title VARCHAR(150) NOT NULL,
    status ENUM('active','inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_question_set (academic_year_id, module),
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE RESTRICT,
    INDEX idx_fqs_ay_mod (academic_year_id, module),
    INDEX idx_fqs_module (module),
    INDEX idx_fqs_status (status)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feedback_forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module ENUM('teaching_quality','student_support_services','learning_environment') NOT NULL DEFAULT 'teaching_quality',
    section_id INT DEFAULT NULL,
    academic_year_id INT DEFAULT NULL,
    semester_id INT DEFAULT NULL,
    question_set_id INT DEFAULT NULL,
    title VARCHAR(150) NOT NULL,
    start_date DATETIME NOT NULL,
    end_date DATETIME NOT NULL,
    status ENUM('Upcoming','Active','Expired') NOT NULL DEFAULT 'Upcoming',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE SET NULL,
    FOREIGN KEY (semester_id) REFERENCES semesters(id) ON DELETE SET NULL,
    FOREIGN KEY (question_set_id) REFERENCES feedback_question_sets(id) ON DELETE SET NULL,
    INDEX idx_fb_forms_module (module),
    INDEX idx_fb_forms_status (status),
    INDEX idx_fb_forms_ay (academic_year_id),
    INDEX idx_fb_forms_sem (semester_id),
    INDEX idx_fb_forms_qs (question_set_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS survey_groups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_set_id INT NOT NULL,
    group_code VARCHAR(30) NOT NULL,
    group_name_en VARCHAR(200) NOT NULL,
    group_name_mm VARCHAR(200) NOT NULL,
    instruction_en TEXT DEFAULT NULL,
    instruction_mm TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (question_set_id) REFERENCES feedback_question_sets(id) ON DELETE CASCADE,
    UNIQUE KEY uq_survey_group_code (question_set_id, group_code),
    INDEX idx_survey_group_set (question_set_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feedback_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_set_id INT NOT NULL,
    survey_group_id INT NOT NULL,
    question_code VARCHAR(30) NOT NULL,
    question_text_en TEXT NOT NULL,
    question_text_mm TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (question_set_id) REFERENCES feedback_question_sets(id) ON DELETE CASCADE,
    FOREIGN KEY (survey_group_id) REFERENCES survey_groups(id) ON DELETE CASCADE,
    UNIQUE KEY uq_group_question_code (survey_group_id, question_code),
    INDEX idx_fq_qs (question_set_id),
    INDEX idx_fq_group (survey_group_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feedback_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,
    student_id INT NOT NULL,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fb_sub (form_id, student_id),
    FOREIGN KEY (form_id) REFERENCES feedback_forms(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    INDEX idx_fb_submissions_form (form_id),
    INDEX idx_fb_submissions_student (student_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feedback_survey_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    submission_id INT NOT NULL,
    question_id INT NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (submission_id) REFERENCES feedback_submissions(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES feedback_questions(id) ON DELETE CASCADE,
    INDEX idx_fsa_submission (submission_id),
    INDEX idx_fsa_question (question_id),
    CONSTRAINT chk_feedback_survey_rating CHECK (rating BETWEEN 1 AND 5),
    INDEX idx_fsa_question_rating (question_id, rating),
    UNIQUE KEY uq_fsa_submission_question (submission_id, question_id)
) ENGINE=InnoDB;

INSERT IGNORE INTO users (name, username, email, password, role)
VALUES (
    'System Admin',
    'admin',
    'admin@ucsh.edu.mm',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin'
);

INSERT IGNORE INTO section_master (section_name) VALUES ('A'), ('B'), ('C');
