-- ============================================================
-- SFIS v18 — Add section_master table for centralized section management
-- ============================================================
USE studentfeedbackintern;

-- 1. Create section_master table
CREATE TABLE IF NOT EXISTS section_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_name VARCHAR(10) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Seed with existing distinct sections from the sections table
INSERT IGNORE INTO section_master (section_name)
SELECT DISTINCT TRIM(section)
FROM sections
WHERE section IS NOT NULL AND section != ''
ORDER BY section ASC;
