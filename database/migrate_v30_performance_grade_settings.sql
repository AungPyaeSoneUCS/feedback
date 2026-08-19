CREATE TABLE IF NOT EXISTS performance_grade_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    academic_year_id INT NOT NULL,
    grade_code VARCHAR(50) NOT NULL,
    min_score DECIMAL(5,2) NOT NULL,
    max_score DECIMAL(5,2) NOT NULL,
    recommendation_key VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_performance_grade_academic_year FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE,
    UNIQUE KEY uq_performance_grade_year_code (academic_year_id, grade_code)
) ENGINE=InnoDB;

INSERT IGNORE INTO performance_grade_settings (academic_year_id, grade_code, min_score, max_score, recommendation_key)
SELECT id, 'excellent', 90.00, 100.00, 'performance_recommendation_excellent' FROM academic_years
UNION ALL SELECT id, 'good', 80, 89, 'performance_recommendation_good' FROM academic_years
UNION ALL SELECT id, 'satisfactory', 70, 79, 'performance_recommendation_satisfactory' FROM academic_years
UNION ALL SELECT id, 'needs_improvement', 60, 69, 'performance_recommendation_needs_improvement' FROM academic_years
UNION ALL SELECT id, 'unsatisfactory', 0, 59, 'performance_recommendation_unsatisfactory' FROM academic_years;
