-- SLOCloud Database Schema
-- MySQL 8.0+ Required
-- Character Set: UTF8MB4
-- Collation: utf8mb4_unicode_ci
-- Storage Engine: InnoDB

-- Drop existing tables if they exist (for clean reinstall)
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS slocloud_tbl_lti_nonces;
DROP TABLE IF EXISTS slocloud_tbl_lti_consumers;
DROP TABLE IF EXISTS slocloud_tbl_user_roles;
DROP TABLE IF EXISTS slocloud_tbl_roles;
DROP TABLE IF EXISTS slocloud_tbl_section_slo_methods;
DROP TABLE IF EXISTS slocloud_tbl_assessments;
DROP TABLE IF EXISTS slocloud_tbl_enrollment;
DROP TABLE IF EXISTS slocloud_tbl_students;
DROP TABLE IF EXISTS slocloud_tbl_student_learning_outcomes;
DROP TABLE IF EXISTS slocloud_tbl_sections;
DROP TABLE IF EXISTS slocloud_tbl_program_courses;
DROP TABLE IF EXISTS slocloud_tbl_courses;
DROP TABLE IF EXISTS slocloud_tbl_program_outcomes;
DROP TABLE IF EXISTS slocloud_tbl_programs;
DROP TABLE IF EXISTS slocloud_tbl_disciplines;
DROP TABLE IF EXISTS slocloud_tbl_institutional_outcomes;
DROP TABLE IF EXISTS slocloud_tbl_terms;
DROP TABLE IF EXISTS slocloud_tbl_users;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- 1. USER MANAGEMENT (Created First - Referenced by Audit Fields)
-- ============================================================================

CREATE TABLE slocloud_tbl_users (
    users_pk INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(200) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE slocloud_tbl_roles (
    roles_pk INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_role_name (role_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE slocloud_tbl_user_roles (
    user_roles_pk INT AUTO_INCREMENT PRIMARY KEY,
    user_fk INT NOT NULL,
    role_fk INT NOT NULL,
    context_type VARCHAR(50),
    context_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE CASCADE,
    FOREIGN KEY (role_fk) REFERENCES slocloud_tbl_roles(roles_pk) ON DELETE CASCADE,
    INDEX idx_user_fk (user_fk),
    INDEX idx_role_fk (role_fk),
    INDEX idx_context (context_type, context_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 2. TERMS (Top-Level Controlling Entity)
-- ============================================================================

CREATE TABLE slocloud_tbl_terms (
    terms_pk INT AUTO_INCREMENT PRIMARY KEY,
    banner_term VARCHAR(20) NOT NULL UNIQUE COMMENT 'Banner system term code (e.g., 202533)',
    term_code VARCHAR(50) NOT NULL UNIQUE COMMENT 'Institutional term code (e.g., 202630)',
    term_name VARCHAR(100) NOT NULL,
    academic_year VARCHAR(20) COMMENT 'Academic year label (e.g., 2022-2023)',
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_fk INT,
    updated_by_fk INT,
    FOREIGN KEY (created_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    FOREIGN KEY (updated_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    INDEX idx_banner_term (banner_term),
    INDEX idx_term_code (term_code),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 3. OUTCOMES HIERARCHY
-- ============================================================================

CREATE TABLE slocloud_tbl_institutional_outcomes (
    institutional_outcomes_pk INT AUTO_INCREMENT PRIMARY KEY,
    term_fk INT NOT NULL,
    outcome_code VARCHAR(50) NOT NULL,
    outcome_description TEXT NOT NULL,
    sequence_num INT DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_fk INT,
    updated_by_fk INT,
    FOREIGN KEY (term_fk) REFERENCES slocloud_tbl_terms(terms_pk) ON DELETE CASCADE,
    FOREIGN KEY (created_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    FOREIGN KEY (updated_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    INDEX idx_term_fk (term_fk),
    INDEX idx_outcome_code (outcome_code),
    INDEX idx_term_outcome (term_fk, outcome_code),
    INDEX idx_sequence_num (sequence_num),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 4. DISCIPLINES
-- ============================================================================

CREATE TABLE slocloud_tbl_disciplines (
    disciplines_pk INT AUTO_INCREMENT PRIMARY KEY,
    term_fk INT NOT NULL,
    discipline_name VARCHAR(255) NOT NULL,
    discipline_code VARCHAR(50),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_fk INT,
    updated_by_fk INT,
    FOREIGN KEY (term_fk) REFERENCES slocloud_tbl_terms(terms_pk) ON DELETE CASCADE,
    FOREIGN KEY (created_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    FOREIGN KEY (updated_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    INDEX idx_term_fk (term_fk),
    INDEX idx_discipline_name (discipline_name),
    INDEX idx_discipline_code (discipline_code),
    INDEX idx_discipline_per_term (term_fk, discipline_code),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 5. PROGRAMS
-- ============================================================================

CREATE TABLE slocloud_tbl_programs (
    programs_pk INT AUTO_INCREMENT PRIMARY KEY,
    term_fk INT NOT NULL,
    discipline_fk INT,
    program_code VARCHAR(50) NOT NULL,
    program_name VARCHAR(255) NOT NULL,
    degree_type VARCHAR(50),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_fk INT,
    updated_by_fk INT,
    FOREIGN KEY (term_fk) REFERENCES slocloud_tbl_terms(terms_pk) ON DELETE CASCADE,
    FOREIGN KEY (discipline_fk) REFERENCES slocloud_tbl_disciplines(disciplines_pk) ON DELETE SET NULL,
    FOREIGN KEY (created_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    FOREIGN KEY (updated_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    INDEX idx_term_fk (term_fk),
    INDEX idx_discipline_fk (discipline_fk),
    INDEX idx_program_code (program_code),
    INDEX idx_program_per_term (term_fk, program_code),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE slocloud_tbl_program_outcomes (
    program_outcomes_pk INT AUTO_INCREMENT PRIMARY KEY,
    program_fk INT NOT NULL,
    institutional_outcomes_fk INT,
    outcome_code VARCHAR(50) NOT NULL,
    outcome_description TEXT NOT NULL,
    sequence_num INT DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_fk INT,
    updated_by_fk INT,
    FOREIGN KEY (program_fk) REFERENCES slocloud_tbl_programs(programs_pk) ON DELETE CASCADE,
    FOREIGN KEY (institutional_outcomes_fk) REFERENCES slocloud_tbl_institutional_outcomes(institutional_outcomes_pk) ON DELETE SET NULL,
    FOREIGN KEY (created_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    FOREIGN KEY (updated_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    INDEX idx_outcome_code (outcome_code),
    INDEX idx_program_fk (program_fk),
    INDEX idx_outcome_per_program (program_fk, outcome_code),
    INDEX idx_institutional_outcomes_fk (institutional_outcomes_fk),
    INDEX idx_sequence_num (sequence_num),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 6. COURSES & STUDENT LEARNING OUTCOMES
-- ============================================================================

CREATE TABLE slocloud_tbl_courses (
    courses_pk INT AUTO_INCREMENT PRIMARY KEY,
    term_fk INT NOT NULL,
    discipline_fk INT,
    course_number VARCHAR(50) NOT NULL,
    course_name VARCHAR(255) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_fk INT,
    updated_by_fk INT,
    FOREIGN KEY (term_fk) REFERENCES slocloud_tbl_terms(terms_pk) ON DELETE CASCADE,
    FOREIGN KEY (discipline_fk) REFERENCES slocloud_tbl_disciplines(disciplines_pk) ON DELETE SET NULL,
    FOREIGN KEY (created_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    FOREIGN KEY (updated_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    INDEX idx_term_fk (term_fk),
    INDEX idx_discipline_fk (discipline_fk),
    INDEX idx_course_number (course_number),
    INDEX idx_course_per_term (term_fk, course_number),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Junction table for many-to-many relationship between programs and courses
CREATE TABLE slocloud_tbl_program_courses (
    program_courses_pk INT AUTO_INCREMENT PRIMARY KEY,
    program_fk INT NOT NULL,
    course_fk INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    created_by_fk INT,
    FOREIGN KEY (program_fk) REFERENCES slocloud_tbl_programs(programs_pk) ON DELETE CASCADE,
    FOREIGN KEY (course_fk) REFERENCES slocloud_tbl_courses(courses_pk) ON DELETE CASCADE,
    FOREIGN KEY (created_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    INDEX idx_program_fk (program_fk),
    INDEX idx_course_fk (course_fk),
    INDEX idx_program_course (program_fk, course_fk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE slocloud_tbl_sections (
    sections_pk INT AUTO_INCREMENT PRIMARY KEY,
    course_fk INT NOT NULL,
    term_fk INT NOT NULL,
    section_id VARCHAR(20) NOT NULL COMMENT 'Section identifier (e.g., 01, 02, A, B)',
    crn VARCHAR(20) COMMENT 'Course Reference Number from Banner SIS',
    instructor_name VARCHAR(255) NULL COMMENT 'Instructor name supplied by the LMS launch',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_fk INT,
    updated_by_fk INT,
    FOREIGN KEY (course_fk) REFERENCES slocloud_tbl_courses(courses_pk) ON DELETE CASCADE,
    FOREIGN KEY (term_fk) REFERENCES slocloud_tbl_terms(terms_pk) ON DELETE CASCADE,
    FOREIGN KEY (created_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    FOREIGN KEY (updated_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    INDEX idx_course_fk (course_fk),
    INDEX idx_term_fk (term_fk),
    INDEX idx_section_id (section_id),
    INDEX idx_course_term_section (course_fk, term_fk, section_id),
    INDEX idx_crn (crn),
    UNIQUE KEY unique_section_crn (term_fk, crn),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE slocloud_tbl_student_learning_outcomes (
    student_learning_outcomes_pk INT AUTO_INCREMENT PRIMARY KEY,
    course_fk INT NOT NULL,
    program_outcomes_fk INT,
    slo_code VARCHAR(50) NOT NULL,
    slo_description TEXT NOT NULL,
    sequence_num INT DEFAULT 0,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_fk INT,
    updated_by_fk INT,
    FOREIGN KEY (course_fk) REFERENCES slocloud_tbl_courses(courses_pk) ON DELETE CASCADE,
    FOREIGN KEY (program_outcomes_fk) REFERENCES slocloud_tbl_program_outcomes(program_outcomes_pk) ON DELETE SET NULL,
    FOREIGN KEY (created_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    FOREIGN KEY (updated_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    INDEX idx_course_fk (course_fk),
    INDEX idx_program_outcomes_fk (program_outcomes_fk),
    INDEX idx_slo_code (slo_code),
    INDEX idx_course_slo (course_fk, slo_code),
    INDEX idx_sequence_num (sequence_num),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 7. STUDENTS & ENROLLMENT
-- ============================================================================

CREATE TABLE slocloud_tbl_students (
    students_pk INT AUTO_INCREMENT PRIMARY KEY,
    student_data_encrypted TEXT NOT NULL COMMENT 'Encrypted JSON: {c_number, first_name, last_name}',
    student_id_hash VARCHAR(64) NOT NULL UNIQUE COMMENT 'SHA-256 hash of C# for import matching',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_fk INT,
    updated_by_fk INT,
    FOREIGN KEY (created_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    FOREIGN KEY (updated_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    INDEX idx_student_id_hash (student_id_hash),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE slocloud_tbl_enrollment (
    enrollment_pk INT AUTO_INCREMENT PRIMARY KEY,
    section_fk INT NOT NULL,
    student_fk INT NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Last update from Banner (updated)',
    created_by_fk INT,
    updated_by_fk INT,
    FOREIGN KEY (section_fk) REFERENCES slocloud_tbl_sections(sections_pk) ON DELETE CASCADE,
    FOREIGN KEY (student_fk) REFERENCES slocloud_tbl_students(students_pk) ON DELETE CASCADE,
    FOREIGN KEY (created_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    FOREIGN KEY (updated_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    UNIQUE KEY unique_enrollment (section_fk, student_fk),
    INDEX idx_section_fk (section_fk),
    INDEX idx_student_fk (student_fk),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE slocloud_tbl_section_xlist (
    section_xlist_pk INT AUTO_INCREMENT PRIMARY KEY,
    term_fk INT NOT NULL COMMENT 'Foreign key to terms table',
    xlist_group VARCHAR(50) NOT NULL COMMENT 'Cross-listing group identifier (e.g., 1, 2, C01, etc.)',
    crn VARCHAR(20) NOT NULL COMMENT 'Course Reference Number (section identifier)',
    course_number VARCHAR(50) COMMENT 'Course number for reference (e.g., ETHS C123)',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_fk INT,
    updated_by_fk INT,
    FOREIGN KEY (term_fk) REFERENCES slocloud_tbl_terms(terms_pk) ON DELETE CASCADE,
    FOREIGN KEY (created_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    FOREIGN KEY (updated_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    UNIQUE KEY idx_term_crn (term_fk, crn),
    INDEX idx_term_xlist (term_fk, xlist_group),
    INDEX idx_crn (crn),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 8. ASSESSMENT DATA
-- ============================================================================

CREATE TABLE slocloud_tbl_assessments (
    assessments_pk INT AUTO_INCREMENT PRIMARY KEY,
    enrollment_fk INT NOT NULL,
    student_learning_outcome_fk INT NOT NULL,
    achievement_level ENUM('met', 'partially_met', 'not_met', 'not_assessed') NOT NULL DEFAULT 'not_assessed',
    assessed_date DATE,
    assessor_email VARCHAR(255),
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (enrollment_fk) REFERENCES slocloud_tbl_enrollment(enrollment_pk) ON DELETE CASCADE,
    FOREIGN KEY (student_learning_outcome_fk) REFERENCES slocloud_tbl_student_learning_outcomes(student_learning_outcomes_pk) ON DELETE CASCADE,
    INDEX idx_enrollment_fk (enrollment_fk),
    INDEX idx_student_learning_outcome_fk (student_learning_outcome_fk),
    INDEX idx_achievement_level (achievement_level),
    INDEX idx_assessed_date (assessed_date),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SLO Assessment Methods per Section
-- Tracks which assessment method was used to assess a specific SLO in a specific section
CREATE TABLE slocloud_tbl_section_slo_methods (
    section_slo_methods_pk INT AUTO_INCREMENT PRIMARY KEY,
    crn VARCHAR(20) NOT NULL COMMENT 'Course Reference Number',
    term_code VARCHAR(50) NOT NULL COMMENT 'Term code from enrollment',
    student_learning_outcome_fk INT NOT NULL,
    assessment_method VARCHAR(100) NULL COMMENT 'Type of assessment: Quiz, Exam, Project, etc. - NULL if not yet selected',
    improvement_strategies TEXT COMMENT 'Pipe-wrapped double-pipe-separated list of continuous improvement strategies (|val1||val2|)',
    assessed_date DATE COMMENT 'Date this assessment was administered',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by_fk INT,
    updated_by_fk INT,
    FOREIGN KEY (student_learning_outcome_fk) REFERENCES slocloud_tbl_student_learning_outcomes(student_learning_outcomes_pk) ON DELETE CASCADE,
    FOREIGN KEY (created_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    FOREIGN KEY (updated_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    INDEX idx_crn (crn),
    INDEX idx_term_code (term_code),
    INDEX idx_student_learning_outcome_fk (student_learning_outcome_fk),
    INDEX idx_section_slo (crn, term_code, student_learning_outcome_fk),
    INDEX idx_assessed_date (assessed_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 9. AUDIT & ERROR LOGGING
-- ============================================================================

CREATE TABLE slocloud_tbl_audit_log (
    audit_log_pk INT AUTO_INCREMENT PRIMARY KEY,
    table_name VARCHAR(100) NOT NULL,
    record_pk INT NOT NULL,
    action ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
    changed_by_fk INT,
    changed_data JSON,
    old_data JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (changed_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    INDEX idx_table_name (table_name),
    INDEX idx_record_pk (record_pk),
    INDEX idx_action (action),
    INDEX idx_changed_by_fk (changed_by_fk),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE slocloud_tbl_error_log (
    error_log_pk INT AUTO_INCREMENT PRIMARY KEY,
    error_type VARCHAR(100) NOT NULL,
    error_message TEXT NOT NULL,
    error_code VARCHAR(50),
    stack_trace TEXT,
    file_path VARCHAR(255),
    line_number INT,
    user_fk INT,
    request_uri TEXT,
    request_method VARCHAR(10),
    request_data JSON,
    ip_address VARCHAR(45),
    user_agent TEXT,
    severity ENUM('debug', 'info', 'warning', 'error', 'critical') DEFAULT 'error',
    is_resolved BOOLEAN DEFAULT FALSE,
    resolved_at TIMESTAMP NULL,
    resolved_by_fk INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    FOREIGN KEY (resolved_by_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    INDEX idx_error_type (error_type),
    INDEX idx_severity (severity),
    INDEX idx_is_resolved (is_resolved),
    INDEX idx_user_fk (user_fk),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE slocloud_tbl_security_log (
    security_log_pk INT AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(100) NOT NULL,
    event_description TEXT NOT NULL,
    user_fk INT,
    username VARCHAR(100),
    ip_address VARCHAR(45),
    user_agent TEXT,
    request_uri TEXT,
    severity ENUM('info', 'warning', 'critical') DEFAULT 'info',
    is_threat BOOLEAN DEFAULT FALSE,
    metadata JSON,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_fk) REFERENCES slocloud_tbl_users(users_pk) ON DELETE SET NULL,
    INDEX idx_event_type (event_type),
    INDEX idx_user_fk (user_fk),
    INDEX idx_username (username),
    INDEX idx_ip_address (ip_address),
    INDEX idx_severity (severity),
    INDEX idx_is_threat (is_threat),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- 10. LTI INTEGRATION
-- ============================================================================
-- Note: LTI consumer keys are stored in config.yaml

CREATE TABLE slocloud_tbl_lti_nonces (
    lti_nonces_pk INT AUTO_INCREMENT PRIMARY KEY,
    consumer_key VARCHAR(255) NOT NULL,
    nonce_value VARCHAR(255) NOT NULL,
    timestamp BIGINT NOT NULL,
    client_ip VARCHAR(45) NULL COMMENT 'IPv4 or IPv6 address for security monitoring',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_nonce (consumer_key, nonce_value, timestamp),
    INDEX idx_consumer_key (consumer_key),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- INITIAL DATA: Standard Roles
-- ============================================================================

INSERT INTO slocloud_tbl_roles (role_name, description) VALUES
    ('admin', 'System administrator with full access to all features including configuration, imports, and user management'),
    ('user', 'Standard user with read and export access only');

-- ============================================================================
-- SCHEMA COMPLETE
-- ============================================================================
