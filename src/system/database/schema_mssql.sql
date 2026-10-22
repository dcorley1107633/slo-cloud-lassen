-- SLOCloud Database Schema - Microsoft SQL Server (NO FOREIGN KEYS)
-- SQL Server 2016+ Required
-- Collation: SQL_Latin1_General_CP1_CI_AS
-- REFERENTIAL INTEGRITY REMOVED FOR COMPATIBILITY

-- Drop existing tables if they exist (for clean reinstall)
IF OBJECT_ID('slocloud_tbl_meta', 'U') IS NOT NULL DROP TABLE slocloud_tbl_meta;
IF OBJECT_ID('slocloud_tbl_lti_nonces', 'U') IS NOT NULL DROP TABLE slocloud_tbl_lti_nonces;
IF OBJECT_ID('slocloud_tbl_lti_consumers', 'U') IS NOT NULL DROP TABLE slocloud_tbl_lti_consumers;
IF OBJECT_ID('slocloud_tbl_user_roles', 'U') IS NOT NULL DROP TABLE slocloud_tbl_user_roles;
IF OBJECT_ID('slocloud_tbl_roles', 'U') IS NOT NULL DROP TABLE slocloud_tbl_roles;
IF OBJECT_ID('slocloud_tbl_section_slo_methods', 'U') IS NOT NULL DROP TABLE slocloud_tbl_section_slo_methods;
IF OBJECT_ID('slocloud_tbl_assessments', 'U') IS NOT NULL DROP TABLE slocloud_tbl_assessments;
IF OBJECT_ID('slocloud_tbl_enrollment', 'U') IS NOT NULL DROP TABLE slocloud_tbl_enrollment;
IF OBJECT_ID('slocloud_tbl_students', 'U') IS NOT NULL DROP TABLE slocloud_tbl_students;
IF OBJECT_ID('slocloud_tbl_student_learning_outcomes', 'U') IS NOT NULL DROP TABLE slocloud_tbl_student_learning_outcomes;
IF OBJECT_ID('slocloud_tbl_sections', 'U') IS NOT NULL DROP TABLE slocloud_tbl_sections;
IF OBJECT_ID('slocloud_tbl_program_courses', 'U') IS NOT NULL DROP TABLE slocloud_tbl_program_courses;
IF OBJECT_ID('slocloud_tbl_courses', 'U') IS NOT NULL DROP TABLE slocloud_tbl_courses;
IF OBJECT_ID('slocloud_tbl_program_outcomes', 'U') IS NOT NULL DROP TABLE slocloud_tbl_program_outcomes;
IF OBJECT_ID('slocloud_tbl_programs', 'U') IS NOT NULL DROP TABLE slocloud_tbl_programs;
IF OBJECT_ID('slocloud_tbl_disciplines', 'U') IS NOT NULL DROP TABLE slocloud_tbl_disciplines;
IF OBJECT_ID('slocloud_tbl_institutional_outcomes', 'U') IS NOT NULL DROP TABLE slocloud_tbl_institutional_outcomes;
IF OBJECT_ID('slocloud_tbl_terms', 'U') IS NOT NULL DROP TABLE slocloud_tbl_terms;
IF OBJECT_ID('slocloud_tbl_audit_log', 'U') IS NOT NULL DROP TABLE slocloud_tbl_audit_log;
IF OBJECT_ID('slocloud_tbl_error_log', 'U') IS NOT NULL DROP TABLE slocloud_tbl_error_log;
IF OBJECT_ID('slocloud_tbl_security_log', 'U') IS NOT NULL DROP TABLE slocloud_tbl_security_log;
IF OBJECT_ID('slocloud_tbl_users', 'U') IS NOT NULL DROP TABLE slocloud_tbl_users;
GO

-- ============================================================================
-- 1. USER MANAGEMENT
-- ============================================================================

CREATE TABLE slocloud_tbl_users (
    users_pk INT IDENTITY(1,1) PRIMARY KEY,
    full_name NVARCHAR(200) NOT NULL,
    email NVARCHAR(255) NOT NULL UNIQUE,
    password_hash NVARCHAR(255) NOT NULL,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    updated_at DATETIME2 NOT NULL DEFAULT GETDATE()
);
CREATE INDEX idx_email ON slocloud_tbl_users(email);
CREATE INDEX idx_is_active ON slocloud_tbl_users(is_active);
GO

CREATE TRIGGER trg_users_updated_at
ON slocloud_tbl_users
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE slocloud_tbl_users
    SET updated_at = GETDATE()
    FROM slocloud_tbl_users u
    INNER JOIN inserted i ON u.users_pk = i.users_pk;
END;
GO

CREATE TABLE slocloud_tbl_roles (
    roles_pk INT IDENTITY(1,1) PRIMARY KEY,
    role_name NVARCHAR(50) NOT NULL UNIQUE,
    description NVARCHAR(MAX),
    created_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    updated_at DATETIME2 NOT NULL DEFAULT GETDATE()
);
CREATE INDEX idx_role_name ON slocloud_tbl_roles(role_name);
GO

CREATE TRIGGER trg_roles_updated_at
ON slocloud_tbl_roles
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE slocloud_tbl_roles
    SET updated_at = GETDATE()
    FROM slocloud_tbl_roles r
    INNER JOIN inserted i ON r.roles_pk = i.roles_pk;
END;
GO

CREATE TABLE slocloud_tbl_user_roles (
    user_roles_pk INT IDENTITY(1,1) PRIMARY KEY,
    user_fk INT NOT NULL,
    role_fk INT NOT NULL,
    context_type NVARCHAR(50),
    context_id INT,
    created_at DATETIME2 NOT NULL DEFAULT GETDATE()
);
CREATE INDEX idx_user_fk ON slocloud_tbl_user_roles(user_fk);
CREATE INDEX idx_role_fk ON slocloud_tbl_user_roles(role_fk);
CREATE INDEX idx_context ON slocloud_tbl_user_roles(context_type, context_id);
GO

-- ============================================================================
-- 2. TERMS
-- ============================================================================

CREATE TABLE slocloud_tbl_terms (
    terms_pk INT IDENTITY(1,1) PRIMARY KEY,
    banner_term NVARCHAR(20) NOT NULL,
    term_code NVARCHAR(50) NOT NULL UNIQUE,
    term_name NVARCHAR(100) NOT NULL,
    academic_year NVARCHAR(20),
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    updated_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    created_by_fk INT,
    updated_by_fk INT
);
CREATE UNIQUE INDEX idx_banner_term ON slocloud_tbl_terms(banner_term);
CREATE INDEX idx_term_code ON slocloud_tbl_terms(term_code);
CREATE INDEX idx_created_by_fk_terms ON slocloud_tbl_terms(created_by_fk);
CREATE INDEX idx_updated_by_fk_terms ON slocloud_tbl_terms(updated_by_fk);
CREATE INDEX idx_is_active_terms ON slocloud_tbl_terms(is_active);
GO

CREATE TRIGGER trg_terms_updated_at
ON slocloud_tbl_terms
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE slocloud_tbl_terms
    SET updated_at = GETDATE()
    FROM slocloud_tbl_terms t
    INNER JOIN inserted i ON t.terms_pk = i.terms_pk;
END;
GO

-- ============================================================================
-- 3. OUTCOMES HIERARCHY
-- ============================================================================

CREATE TABLE slocloud_tbl_institutional_outcomes (
    institutional_outcomes_pk INT IDENTITY(1,1) PRIMARY KEY,
    term_fk INT NOT NULL,
    outcome_code NVARCHAR(50) NOT NULL,
    outcome_description NVARCHAR(MAX) NOT NULL,
    sequence_num INT NOT NULL DEFAULT 0,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    updated_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    created_by_fk INT,
    updated_by_fk INT
);
CREATE INDEX idx_term_fk_io ON slocloud_tbl_institutional_outcomes(term_fk);
CREATE INDEX idx_outcome_code_io ON slocloud_tbl_institutional_outcomes(outcome_code);
CREATE INDEX idx_term_outcome ON slocloud_tbl_institutional_outcomes(term_fk, outcome_code);
CREATE INDEX idx_sequence_num_io ON slocloud_tbl_institutional_outcomes(sequence_num);
CREATE INDEX idx_created_by_fk_io ON slocloud_tbl_institutional_outcomes(created_by_fk);
CREATE INDEX idx_updated_by_fk_io ON slocloud_tbl_institutional_outcomes(updated_by_fk);
CREATE INDEX idx_is_active_io ON slocloud_tbl_institutional_outcomes(is_active);
GO

CREATE TRIGGER trg_institutional_outcomes_updated_at
ON slocloud_tbl_institutional_outcomes
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE slocloud_tbl_institutional_outcomes
    SET updated_at = GETDATE()
    FROM slocloud_tbl_institutional_outcomes io
    INNER JOIN inserted i ON io.institutional_outcomes_pk = i.institutional_outcomes_pk;
END;
GO

-- ============================================================================
-- 4. DISCIPLINES
-- ============================================================================

CREATE TABLE slocloud_tbl_disciplines (
    disciplines_pk INT IDENTITY(1,1) PRIMARY KEY,
    term_fk INT NOT NULL,
    discipline_name NVARCHAR(255) NOT NULL,
    discipline_code NVARCHAR(50),
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    updated_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    created_by_fk INT,
    updated_by_fk INT
);
CREATE INDEX idx_term_fk_disciplines ON slocloud_tbl_disciplines(term_fk);
CREATE INDEX idx_discipline_name ON slocloud_tbl_disciplines(discipline_name);
CREATE INDEX idx_discipline_code ON slocloud_tbl_disciplines(discipline_code);
CREATE INDEX idx_discipline_per_term ON slocloud_tbl_disciplines(term_fk, discipline_code);
CREATE INDEX idx_created_by_fk_disciplines ON slocloud_tbl_disciplines(created_by_fk);
CREATE INDEX idx_updated_by_fk_disciplines ON slocloud_tbl_disciplines(updated_by_fk);
CREATE INDEX idx_is_active_disciplines ON slocloud_tbl_disciplines(is_active);
GO

CREATE TRIGGER trg_disciplines_updated_at
ON slocloud_tbl_disciplines
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE slocloud_tbl_disciplines
    SET updated_at = GETDATE()
    FROM slocloud_tbl_disciplines d
    INNER JOIN inserted i ON d.disciplines_pk = i.disciplines_pk;
END;
GO

-- ============================================================================
-- 5. PROGRAMS
-- ============================================================================

CREATE TABLE slocloud_tbl_programs (
    programs_pk INT IDENTITY(1,1) PRIMARY KEY,
    term_fk INT NOT NULL,
    discipline_fk INT,
    program_code NVARCHAR(50) NOT NULL,
    program_name NVARCHAR(255) NOT NULL,
    degree_type NVARCHAR(50),
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    updated_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    created_by_fk INT,
    updated_by_fk INT
);
CREATE INDEX idx_term_fk_programs ON slocloud_tbl_programs(term_fk);
CREATE INDEX idx_discipline_fk_programs ON slocloud_tbl_programs(discipline_fk);
CREATE INDEX idx_program_code ON slocloud_tbl_programs(program_code);
CREATE INDEX idx_program_per_term ON slocloud_tbl_programs(term_fk, program_code);
CREATE INDEX idx_created_by_fk_programs ON slocloud_tbl_programs(created_by_fk);
CREATE INDEX idx_updated_by_fk_programs ON slocloud_tbl_programs(updated_by_fk);
CREATE INDEX idx_is_active_programs ON slocloud_tbl_programs(is_active);
GO

CREATE TRIGGER trg_programs_updated_at
ON slocloud_tbl_programs
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE slocloud_tbl_programs
    SET updated_at = GETDATE()
    FROM slocloud_tbl_programs p
    INNER JOIN inserted i ON p.programs_pk = i.programs_pk;
END;
GO

CREATE TABLE slocloud_tbl_program_outcomes (
    program_outcomes_pk INT IDENTITY(1,1) PRIMARY KEY,
    program_fk INT NOT NULL,
    institutional_outcomes_fk INT,
    outcome_code NVARCHAR(50) NOT NULL,
    outcome_description NVARCHAR(MAX) NOT NULL,
    sequence_num INT NOT NULL DEFAULT 0,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    updated_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    created_by_fk INT,
    updated_by_fk INT
);
CREATE INDEX idx_outcome_code_po ON slocloud_tbl_program_outcomes(outcome_code);
CREATE INDEX idx_program_fk ON slocloud_tbl_program_outcomes(program_fk);
CREATE INDEX idx_outcome_per_program ON slocloud_tbl_program_outcomes(program_fk, outcome_code);
CREATE INDEX idx_institutional_outcomes_fk ON slocloud_tbl_program_outcomes(institutional_outcomes_fk);
CREATE INDEX idx_sequence_num_po ON slocloud_tbl_program_outcomes(sequence_num);
CREATE INDEX idx_created_by_fk_po ON slocloud_tbl_program_outcomes(created_by_fk);
CREATE INDEX idx_updated_by_fk_po ON slocloud_tbl_program_outcomes(updated_by_fk);
CREATE INDEX idx_is_active_po ON slocloud_tbl_program_outcomes(is_active);
GO

CREATE TRIGGER trg_program_outcomes_updated_at
ON slocloud_tbl_program_outcomes
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE slocloud_tbl_program_outcomes
    SET updated_at = GETDATE()
    FROM slocloud_tbl_program_outcomes po
    INNER JOIN inserted i ON po.program_outcomes_pk = i.program_outcomes_pk;
END;
GO

-- ============================================================================
-- 6. COURSES & STUDENT LEARNING OUTCOMES
-- ============================================================================

CREATE TABLE slocloud_tbl_courses (
    courses_pk INT IDENTITY(1,1) PRIMARY KEY,
    term_fk INT NOT NULL,
    discipline_fk INT,
    course_number NVARCHAR(50) NOT NULL,
    course_name NVARCHAR(255) NOT NULL,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    updated_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    created_by_fk INT,
    updated_by_fk INT
);
CREATE INDEX idx_term_fk_courses ON slocloud_tbl_courses(term_fk);
CREATE INDEX idx_discipline_fk_courses ON slocloud_tbl_courses(discipline_fk);
CREATE INDEX idx_course_number ON slocloud_tbl_courses(course_number);
CREATE INDEX idx_course_per_term ON slocloud_tbl_courses(term_fk, course_number);
CREATE INDEX idx_created_by_fk_courses ON slocloud_tbl_courses(created_by_fk);
CREATE INDEX idx_updated_by_fk_courses ON slocloud_tbl_courses(updated_by_fk);
CREATE INDEX idx_is_active_courses ON slocloud_tbl_courses(is_active);
GO

CREATE TRIGGER trg_courses_updated_at
ON slocloud_tbl_courses
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE slocloud_tbl_courses
    SET updated_at = GETDATE()
    FROM slocloud_tbl_courses c
    INNER JOIN inserted i ON c.courses_pk = i.courses_pk;
END;
GO

CREATE TABLE slocloud_tbl_program_courses (
    program_courses_pk INT IDENTITY(1,1) PRIMARY KEY,
    program_fk INT NOT NULL,
    course_fk INT NOT NULL,
    created_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    created_by_fk INT
);
CREATE INDEX idx_program_fk_pc ON slocloud_tbl_program_courses(program_fk);
CREATE INDEX idx_course_fk_pc ON slocloud_tbl_program_courses(course_fk);
CREATE INDEX idx_program_course ON slocloud_tbl_program_courses(program_fk, course_fk);
CREATE INDEX idx_created_by_fk_pc ON slocloud_tbl_program_courses(created_by_fk);
GO

CREATE TABLE slocloud_tbl_sections (
    sections_pk INT IDENTITY(1,1) PRIMARY KEY,
    course_fk INT NOT NULL,
    term_fk INT NOT NULL,
    section_id NVARCHAR(20) NOT NULL,
    crn NVARCHAR(20),
    instructor_name NVARCHAR(255) NULL,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    updated_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    created_by_fk INT,
    updated_by_fk INT
);
CREATE INDEX idx_course_fk_sections ON slocloud_tbl_sections(course_fk);
CREATE INDEX idx_term_fk_sections ON slocloud_tbl_sections(term_fk);
CREATE INDEX idx_section_id ON slocloud_tbl_sections(section_id);
CREATE INDEX idx_course_term_section ON slocloud_tbl_sections(course_fk, term_fk, section_id);
CREATE INDEX idx_crn ON slocloud_tbl_sections(crn);
CREATE UNIQUE INDEX unique_section_crn ON slocloud_tbl_sections(term_fk, crn) WHERE crn IS NOT NULL;
CREATE INDEX idx_created_by_fk_sections ON slocloud_tbl_sections(created_by_fk);
CREATE INDEX idx_updated_by_fk_sections ON slocloud_tbl_sections(updated_by_fk);
CREATE INDEX idx_is_active_sections ON slocloud_tbl_sections(is_active);
GO

CREATE TRIGGER trg_sections_updated_at
ON slocloud_tbl_sections
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE slocloud_tbl_sections
    SET updated_at = GETDATE()
    FROM slocloud_tbl_sections s
    INNER JOIN inserted i ON s.sections_pk = i.sections_pk;
END;
GO

CREATE TABLE slocloud_tbl_student_learning_outcomes (
    student_learning_outcomes_pk INT IDENTITY(1,1) PRIMARY KEY,
    course_fk INT NOT NULL,
    program_outcomes_fk INT,
    slo_code NVARCHAR(50) NOT NULL,
    slo_description NVARCHAR(MAX) NOT NULL,
    sequence_num INT NOT NULL DEFAULT 0,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    updated_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    created_by_fk INT,
    updated_by_fk INT
);
CREATE INDEX idx_course_fk_slo ON slocloud_tbl_student_learning_outcomes(course_fk);
CREATE INDEX idx_program_outcomes_fk ON slocloud_tbl_student_learning_outcomes(program_outcomes_fk);
CREATE INDEX idx_slo_code ON slocloud_tbl_student_learning_outcomes(slo_code);
CREATE INDEX idx_course_slo ON slocloud_tbl_student_learning_outcomes(course_fk, slo_code);
CREATE INDEX idx_sequence_num_slo ON slocloud_tbl_student_learning_outcomes(sequence_num);
CREATE INDEX idx_created_by_fk_slo ON slocloud_tbl_student_learning_outcomes(created_by_fk);
CREATE INDEX idx_updated_by_fk_slo ON slocloud_tbl_student_learning_outcomes(updated_by_fk);
CREATE INDEX idx_is_active_slo ON slocloud_tbl_student_learning_outcomes(is_active);
GO

CREATE TRIGGER trg_student_learning_outcomes_updated_at
ON slocloud_tbl_student_learning_outcomes
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE slocloud_tbl_student_learning_outcomes
    SET updated_at = GETDATE()
    FROM slocloud_tbl_student_learning_outcomes slo
    INNER JOIN inserted i ON slo.student_learning_outcomes_pk = i.student_learning_outcomes_pk;
END;
GO

-- ============================================================================
-- 7. STUDENTS & ENROLLMENT
-- ============================================================================

CREATE TABLE slocloud_tbl_students (
    students_pk INT IDENTITY(1,1) PRIMARY KEY,
    student_data_encrypted NVARCHAR(MAX) NOT NULL,
    student_id_hash NVARCHAR(64) NOT NULL UNIQUE,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    updated_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    created_by_fk INT,
    updated_by_fk INT
);
CREATE INDEX idx_student_id_hash ON slocloud_tbl_students(student_id_hash);
CREATE INDEX idx_created_by_fk_students ON slocloud_tbl_students(created_by_fk);
CREATE INDEX idx_updated_by_fk_students ON slocloud_tbl_students(updated_by_fk);
CREATE INDEX idx_is_active_students ON slocloud_tbl_students(is_active);
GO

CREATE TRIGGER trg_students_updated_at
ON slocloud_tbl_students
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE slocloud_tbl_students
    SET updated_at = GETDATE()
    FROM slocloud_tbl_students s
    INNER JOIN inserted i ON s.students_pk = i.students_pk;
END;
GO

CREATE TABLE slocloud_tbl_enrollment (
    enrollment_pk INT IDENTITY(1,1) PRIMARY KEY,
    section_fk INT NOT NULL,
    student_fk INT NOT NULL,
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    updated_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    created_by_fk INT,
    updated_by_fk INT,
    CONSTRAINT unique_enrollment UNIQUE (section_fk, student_fk)
);
CREATE INDEX idx_section_fk_enroll ON slocloud_tbl_enrollment(section_fk);
CREATE INDEX idx_student_fk_enroll ON slocloud_tbl_enrollment(student_fk);
CREATE INDEX idx_created_by_fk_enroll ON slocloud_tbl_enrollment(created_by_fk);
CREATE INDEX idx_updated_by_fk_enroll ON slocloud_tbl_enrollment(updated_by_fk);
CREATE INDEX idx_is_active_enroll ON slocloud_tbl_enrollment(is_active);
GO

CREATE TRIGGER trg_enrollment_updated_at
ON slocloud_tbl_enrollment
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE slocloud_tbl_enrollment
    SET updated_at = GETDATE()
    FROM slocloud_tbl_enrollment e
    INNER JOIN inserted i ON e.enrollment_pk = i.enrollment_pk;
END;
GO

CREATE TABLE slocloud_tbl_section_xlist (
    section_xlist_pk INT IDENTITY(1,1) PRIMARY KEY,
    term_fk INT NOT NULL,
    xlist_group NVARCHAR(50) NOT NULL,
    crn NVARCHAR(20) NOT NULL,
    course_number NVARCHAR(50),
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    updated_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    created_by_fk INT,
    updated_by_fk INT,
    CONSTRAINT unique_term_crn_xlist UNIQUE (term_fk, crn)
);
CREATE INDEX idx_term_xlist ON slocloud_tbl_section_xlist(term_fk, xlist_group);
CREATE INDEX idx_crn_xlist ON slocloud_tbl_section_xlist(crn);
CREATE INDEX idx_created_by_fk_xlist ON slocloud_tbl_section_xlist(created_by_fk);
CREATE INDEX idx_updated_by_fk_xlist ON slocloud_tbl_section_xlist(updated_by_fk);
CREATE INDEX idx_is_active_xlist ON slocloud_tbl_section_xlist(is_active);
GO

CREATE TRIGGER trg_section_xlist_updated_at
ON slocloud_tbl_section_xlist
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE slocloud_tbl_section_xlist
    SET updated_at = GETDATE()
    FROM slocloud_tbl_section_xlist sx
    INNER JOIN inserted i ON sx.section_xlist_pk = i.section_xlist_pk;
END;
GO

-- ============================================================================
-- 8. ASSESSMENT DATA
-- ============================================================================

CREATE TABLE slocloud_tbl_assessments (
    assessments_pk INT IDENTITY(1,1) PRIMARY KEY,
    enrollment_fk INT NOT NULL,
    student_learning_outcome_fk INT NOT NULL,
    achievement_level NVARCHAR(20) NOT NULL DEFAULT 'not_assessed',
    assessed_date DATE,
    assessor_email NVARCHAR(255),
    is_active BIT NOT NULL DEFAULT 1,
    created_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    updated_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    CONSTRAINT chk_achievement_level CHECK (achievement_level IN ('met', 'partially_met', 'not_met', 'not_assessed'))
);
CREATE INDEX idx_enrollment_fk_assess ON slocloud_tbl_assessments(enrollment_fk);
CREATE INDEX idx_student_learning_outcome_fk ON slocloud_tbl_assessments(student_learning_outcome_fk);
CREATE INDEX idx_achievement_level ON slocloud_tbl_assessments(achievement_level);
CREATE INDEX idx_assessed_date ON slocloud_tbl_assessments(assessed_date);
CREATE INDEX idx_is_active_assess ON slocloud_tbl_assessments(is_active);
GO

CREATE TRIGGER trg_assessments_updated_at
ON slocloud_tbl_assessments
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE slocloud_tbl_assessments
    SET updated_at = GETDATE()
    FROM slocloud_tbl_assessments a
    INNER JOIN inserted i ON a.assessments_pk = i.assessments_pk;
END;
GO

CREATE TABLE slocloud_tbl_section_slo_methods (
    section_slo_methods_pk INT IDENTITY(1,1) PRIMARY KEY,
    crn NVARCHAR(20) NOT NULL,
    term_code NVARCHAR(50) NOT NULL,
    student_learning_outcome_fk INT NOT NULL,
    assessment_method NVARCHAR(100) NULL,
    improvement_strategies NVARCHAR(MAX),
    assessed_date DATE,
    created_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    updated_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    created_by_fk INT,
    updated_by_fk INT
);
CREATE INDEX idx_crn_ssm ON slocloud_tbl_section_slo_methods(crn);
CREATE INDEX idx_term_code_ssm ON slocloud_tbl_section_slo_methods(term_code);
CREATE INDEX idx_student_learning_outcome_fk_ssm ON slocloud_tbl_section_slo_methods(student_learning_outcome_fk);
CREATE INDEX idx_section_slo ON slocloud_tbl_section_slo_methods(crn, term_code, student_learning_outcome_fk);
CREATE INDEX idx_assessed_date_ssm ON slocloud_tbl_section_slo_methods(assessed_date);
CREATE INDEX idx_created_by_fk_ssm ON slocloud_tbl_section_slo_methods(created_by_fk);
CREATE INDEX idx_updated_by_fk_ssm ON slocloud_tbl_section_slo_methods(updated_by_fk);
GO

CREATE TRIGGER trg_section_slo_methods_updated_at
ON slocloud_tbl_section_slo_methods
AFTER UPDATE
AS
BEGIN
    SET NOCOUNT ON;
    UPDATE slocloud_tbl_section_slo_methods
    SET updated_at = GETDATE()
    FROM slocloud_tbl_section_slo_methods ssm
    INNER JOIN inserted i ON ssm.section_slo_methods_pk = i.section_slo_methods_pk;
END;
GO

-- ============================================================================
-- 9. AUDIT & ERROR LOGGING
-- ============================================================================

CREATE TABLE slocloud_tbl_audit_log (
    audit_log_pk INT IDENTITY(1,1) PRIMARY KEY,
    table_name NVARCHAR(100) NOT NULL,
    record_pk INT NOT NULL,
    action NVARCHAR(10) NOT NULL,
    changed_by_fk INT,
    changed_data NVARCHAR(MAX),
    old_data NVARCHAR(MAX),
    ip_address NVARCHAR(45),
    user_agent NVARCHAR(MAX),
    created_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    CONSTRAINT chk_action CHECK (action IN ('INSERT', 'UPDATE', 'DELETE'))
);
CREATE INDEX idx_table_name ON slocloud_tbl_audit_log(table_name);
CREATE INDEX idx_record_pk_audit ON slocloud_tbl_audit_log(record_pk);
CREATE INDEX idx_action ON slocloud_tbl_audit_log(action);
CREATE INDEX idx_changed_by_fk ON slocloud_tbl_audit_log(changed_by_fk);
CREATE INDEX idx_created_at_audit ON slocloud_tbl_audit_log(created_at);
GO

CREATE TABLE slocloud_tbl_error_log (
    error_log_pk INT IDENTITY(1,1) PRIMARY KEY,
    error_type NVARCHAR(100) NOT NULL,
    error_message NVARCHAR(MAX) NOT NULL,
    error_code NVARCHAR(50),
    stack_trace NVARCHAR(MAX),
    file_path NVARCHAR(255),
    line_number INT,
    user_fk INT,
    request_uri NVARCHAR(MAX),
    request_method NVARCHAR(10),
    request_data NVARCHAR(MAX),
    ip_address NVARCHAR(45),
    user_agent NVARCHAR(MAX),
    severity NVARCHAR(20) NOT NULL DEFAULT 'error',
    is_resolved BIT NOT NULL DEFAULT 0,
    resolved_at DATETIME2,
    resolved_by_fk INT,
    created_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    CONSTRAINT chk_severity CHECK (severity IN ('debug', 'info', 'warning', 'error', 'critical'))
);
CREATE INDEX idx_error_type ON slocloud_tbl_error_log(error_type);
CREATE INDEX idx_severity ON slocloud_tbl_error_log(severity);
CREATE INDEX idx_is_resolved ON slocloud_tbl_error_log(is_resolved);
CREATE INDEX idx_user_fk_error ON slocloud_tbl_error_log(user_fk);
CREATE INDEX idx_created_at_error ON slocloud_tbl_error_log(created_at);
CREATE INDEX idx_resolved_by_fk_error ON slocloud_tbl_error_log(resolved_by_fk);
GO

CREATE TABLE slocloud_tbl_security_log (
    security_log_pk INT IDENTITY(1,1) PRIMARY KEY,
    event_type NVARCHAR(100) NOT NULL,
    event_description NVARCHAR(MAX) NOT NULL,
    user_fk INT,
    username NVARCHAR(100),
    ip_address NVARCHAR(45),
    user_agent NVARCHAR(MAX),
    request_uri NVARCHAR(MAX),
    severity NVARCHAR(20) NOT NULL DEFAULT 'info',
    is_threat BIT NOT NULL DEFAULT 0,
    metadata NVARCHAR(MAX),
    created_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    CONSTRAINT chk_severity_security CHECK (severity IN ('info', 'warning', 'critical'))
);
CREATE INDEX idx_event_type ON slocloud_tbl_security_log(event_type);
CREATE INDEX idx_user_fk_security ON slocloud_tbl_security_log(user_fk);
CREATE INDEX idx_username ON slocloud_tbl_security_log(username);
CREATE INDEX idx_ip_address ON slocloud_tbl_security_log(ip_address);
CREATE INDEX idx_severity_security ON slocloud_tbl_security_log(severity);
CREATE INDEX idx_is_threat ON slocloud_tbl_security_log(is_threat);
CREATE INDEX idx_created_at_security ON slocloud_tbl_security_log(created_at);
GO

-- ============================================================================
-- 10. LTI INTEGRATION
-- ============================================================================

CREATE TABLE slocloud_tbl_lti_nonces (
    lti_nonces_pk INT IDENTITY(1,1) PRIMARY KEY,
    consumer_key NVARCHAR(255) NOT NULL,
    nonce_value NVARCHAR(255) NOT NULL,
    timestamp BIGINT NOT NULL,
    client_ip NVARCHAR(45) NULL, -- IPv4 or IPv6 address for security monitoring
    created_at DATETIME2 NOT NULL DEFAULT GETDATE(),
    CONSTRAINT unique_nonce UNIQUE (consumer_key, nonce_value, timestamp)
);
CREATE INDEX idx_consumer_key ON slocloud_tbl_lti_nonces(consumer_key);
CREATE INDEX idx_timestamp ON slocloud_tbl_lti_nonces(timestamp);
GO

-- ============================================================================
-- SCHEMA VERSION
-- ============================================================================
-- The app version of the last schema change. Upgrades apply the files in
-- migrations/ from this value forward (see docs/upgrades/README.md).

CREATE TABLE slocloud_tbl_meta (
    name NVARCHAR(100) NOT NULL PRIMARY KEY,
    value NVARCHAR(255) NOT NULL,
    updated_at DATETIME2 NOT NULL DEFAULT GETDATE()
);
INSERT INTO slocloud_tbl_meta (name, value) VALUES ('schema_version', '5.2.1');
GO

-- ============================================================================
-- INITIAL DATA: Standard Roles
-- ============================================================================

INSERT INTO slocloud_tbl_roles (role_name, description) VALUES
    ('admin', 'System administrator with full access to all features including configuration, imports, and user management'),
    ('user', 'Standard user with read and export access only');
GO

-- ============================================================================
-- SCHEMA COMPLETE (NO FOREIGN KEYS)
-- ============================================================================
