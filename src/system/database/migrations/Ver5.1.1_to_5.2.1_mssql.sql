-- SLOCloud schema migration 5.1.1 -> 5.2.1 (SQL Server 2016+)
--
-- Adds record lifecycle (activation) flags, one CRN per term, section-owned
-- enrollments, and the not_assessed achievement level.
-- Read docs/upgrades/Ver5.1.1_to_5.2.1.md before running it. Apply it with
-- src/system/scripts/apply_migration.php, which substitutes the configured table
-- prefix, applies pending migrations in order, and stops on the first error.
--
-- Every step checks the current shape first, so the file is safe to re-run
-- after a partial failure. Statements that depend on columns added earlier run
-- in a later batch or through EXEC, so each batch compiles against the table
-- as it is at that point.
--
-- Legacy enrollment term, CRN, and course columns are kept (nullable, no longer
-- related) so unresolved rows can be reviewed with the queries in the upgrade
-- plan. Enrollment section_fk becomes NOT NULL only when every row resolved.
-- Re-running this file after those rows are fixed or removed completes that step.

-- 1. Lifecycle flag and index on every lifecycle entity (existing rows stay active)
IF COL_LENGTH('slocloud_tbl_terms', 'is_active') IS NULL
    ALTER TABLE slocloud_tbl_terms ADD is_active BIT NOT NULL DEFAULT 1;
IF COL_LENGTH('slocloud_tbl_institutional_outcomes', 'is_active') IS NULL
    ALTER TABLE slocloud_tbl_institutional_outcomes ADD is_active BIT NOT NULL DEFAULT 1;
IF COL_LENGTH('slocloud_tbl_disciplines', 'is_active') IS NULL
    ALTER TABLE slocloud_tbl_disciplines ADD is_active BIT NOT NULL DEFAULT 1;
IF COL_LENGTH('slocloud_tbl_programs', 'is_active') IS NULL
    ALTER TABLE slocloud_tbl_programs ADD is_active BIT NOT NULL DEFAULT 1;
IF COL_LENGTH('slocloud_tbl_program_outcomes', 'is_active') IS NULL
    ALTER TABLE slocloud_tbl_program_outcomes ADD is_active BIT NOT NULL DEFAULT 1;
IF COL_LENGTH('slocloud_tbl_courses', 'is_active') IS NULL
    ALTER TABLE slocloud_tbl_courses ADD is_active BIT NOT NULL DEFAULT 1;
IF COL_LENGTH('slocloud_tbl_sections', 'is_active') IS NULL
    ALTER TABLE slocloud_tbl_sections ADD is_active BIT NOT NULL DEFAULT 1;
IF COL_LENGTH('slocloud_tbl_student_learning_outcomes', 'is_active') IS NULL
    ALTER TABLE slocloud_tbl_student_learning_outcomes ADD is_active BIT NOT NULL DEFAULT 1;
IF COL_LENGTH('slocloud_tbl_students', 'is_active') IS NULL
    ALTER TABLE slocloud_tbl_students ADD is_active BIT NOT NULL DEFAULT 1;
IF COL_LENGTH('slocloud_tbl_enrollment', 'is_active') IS NULL
    ALTER TABLE slocloud_tbl_enrollment ADD is_active BIT NOT NULL DEFAULT 1;
IF COL_LENGTH('slocloud_tbl_section_xlist', 'is_active') IS NULL
    ALTER TABLE slocloud_tbl_section_xlist ADD is_active BIT NOT NULL DEFAULT 1;
IF COL_LENGTH('slocloud_tbl_assessments', 'is_active') IS NULL
    ALTER TABLE slocloud_tbl_assessments ADD is_active BIT NOT NULL DEFAULT 1;
GO

IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('slocloud_tbl_terms') AND name = 'idx_is_active_terms')
    CREATE INDEX idx_is_active_terms ON slocloud_tbl_terms(is_active);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('slocloud_tbl_institutional_outcomes') AND name = 'idx_is_active_io')
    CREATE INDEX idx_is_active_io ON slocloud_tbl_institutional_outcomes(is_active);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('slocloud_tbl_disciplines') AND name = 'idx_is_active_disciplines')
    CREATE INDEX idx_is_active_disciplines ON slocloud_tbl_disciplines(is_active);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('slocloud_tbl_programs') AND name = 'idx_is_active_programs')
    CREATE INDEX idx_is_active_programs ON slocloud_tbl_programs(is_active);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('slocloud_tbl_program_outcomes') AND name = 'idx_is_active_po')
    CREATE INDEX idx_is_active_po ON slocloud_tbl_program_outcomes(is_active);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('slocloud_tbl_courses') AND name = 'idx_is_active_courses')
    CREATE INDEX idx_is_active_courses ON slocloud_tbl_courses(is_active);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('slocloud_tbl_sections') AND name = 'idx_is_active_sections')
    CREATE INDEX idx_is_active_sections ON slocloud_tbl_sections(is_active);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('slocloud_tbl_student_learning_outcomes') AND name = 'idx_is_active_slo')
    CREATE INDEX idx_is_active_slo ON slocloud_tbl_student_learning_outcomes(is_active);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('slocloud_tbl_students') AND name = 'idx_is_active_students')
    CREATE INDEX idx_is_active_students ON slocloud_tbl_students(is_active);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('slocloud_tbl_enrollment') AND name = 'idx_is_active_enroll')
    CREATE INDEX idx_is_active_enroll ON slocloud_tbl_enrollment(is_active);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('slocloud_tbl_section_xlist') AND name = 'idx_is_active_xlist')
    CREATE INDEX idx_is_active_xlist ON slocloud_tbl_section_xlist(is_active);
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('slocloud_tbl_assessments') AND name = 'idx_is_active_assess')
    CREATE INDEX idx_is_active_assess ON slocloud_tbl_assessments(is_active);
GO

-- 2. Sections: one CRN per term. Fails here, before any enrollment change, if
--    duplicate (term, CRN) sections exist. Resolve them and re-run.
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('slocloud_tbl_sections') AND name = 'unique_section_crn')
    CREATE UNIQUE INDEX unique_section_crn ON slocloud_tbl_sections(term_fk, crn) WHERE crn IS NOT NULL;
GO

-- 3. Enrollment: relate to sections instead of term/CRN/course
IF COL_LENGTH('slocloud_tbl_enrollment', 'section_fk') IS NULL
    ALTER TABLE slocloud_tbl_enrollment ADD section_fk INT NULL;
GO

-- 3a. Legacy uniqueness and indexes block the column changes below, so drop them.
IF EXISTS (SELECT 1 FROM sys.key_constraints kc
           JOIN sys.index_columns ic ON ic.object_id = kc.parent_object_id AND ic.index_id = kc.unique_index_id
           WHERE kc.parent_object_id = OBJECT_ID('slocloud_tbl_enrollment') AND kc.name = 'unique_enrollment'
             AND COL_NAME(ic.object_id, ic.column_id) = 'term_code')
    ALTER TABLE slocloud_tbl_enrollment DROP CONSTRAINT unique_enrollment;
IF EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('slocloud_tbl_enrollment') AND name = 'idx_term_code_enroll')
    DROP INDEX idx_term_code_enroll ON slocloud_tbl_enrollment;
IF EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('slocloud_tbl_enrollment') AND name = 'idx_crn_enroll')
    DROP INDEX idx_crn_enroll ON slocloud_tbl_enrollment;
IF EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('slocloud_tbl_enrollment') AND name = 'idx_course_fk_enroll')
    DROP INDEX idx_course_fk_enroll ON slocloud_tbl_enrollment;
GO

-- 3b. Keep legacy values for cleanup review, but stop requiring them.
IF COL_LENGTH('slocloud_tbl_enrollment', 'term_code') IS NOT NULL
    ALTER TABLE slocloud_tbl_enrollment ALTER COLUMN term_code NVARCHAR(20) NULL;
IF COL_LENGTH('slocloud_tbl_enrollment', 'crn') IS NOT NULL
    ALTER TABLE slocloud_tbl_enrollment ALTER COLUMN crn NVARCHAR(20) NULL;
IF COL_LENGTH('slocloud_tbl_enrollment', 'course_fk') IS NOT NULL
    ALTER TABLE slocloud_tbl_enrollment ALTER COLUMN course_fk INT NULL;
GO

-- 3c. Backfill section_fk from the legacy term and CRN. 5.1.x matched a term by
--     banner_term or term_code, so accept either. Only rows with exactly one
--     candidate section are linked. Others stay NULL for review.
IF COL_LENGTH('slocloud_tbl_enrollment', 'term_code') IS NOT NULL AND COL_LENGTH('slocloud_tbl_enrollment', 'crn') IS NOT NULL
    EXEC('UPDATE e SET section_fk = m.sections_pk
          FROM slocloud_tbl_enrollment e
          JOIN (SELECT e2.enrollment_pk, MIN(s.sections_pk) AS sections_pk
                FROM slocloud_tbl_enrollment e2
                JOIN slocloud_tbl_terms t ON t.banner_term = e2.term_code OR t.term_code = e2.term_code
                JOIN slocloud_tbl_sections s ON s.term_fk = t.terms_pk AND s.crn = e2.crn
                WHERE e2.section_fk IS NULL
                GROUP BY e2.enrollment_pk
                HAVING COUNT(DISTINCT s.sections_pk) = 1) m ON m.enrollment_pk = e.enrollment_pk');
GO

-- 3d. Require section_fk once no unresolved rows remain. Indexes on the column
--     block ALTER COLUMN, so drop them here and recreate them in 3e.
IF NOT EXISTS (SELECT 1 FROM slocloud_tbl_enrollment WHERE section_fk IS NULL)
   AND COLUMNPROPERTY(OBJECT_ID('slocloud_tbl_enrollment'), 'section_fk', 'AllowsNull') = 1
BEGIN
    IF EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('slocloud_tbl_enrollment') AND name = 'unique_enrollment')
        DROP INDEX unique_enrollment ON slocloud_tbl_enrollment;
    IF EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('slocloud_tbl_enrollment') AND name = 'idx_section_fk_enroll')
        DROP INDEX idx_section_fk_enroll ON slocloud_tbl_enrollment;
    ALTER TABLE slocloud_tbl_enrollment ALTER COLUMN section_fk INT NOT NULL;
END;
GO

-- 3e. New uniqueness and index. Filtered, because SQL Server treats NULLs as
--     equal in unique indexes and unresolved rows may still be present.
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('slocloud_tbl_enrollment') AND name = 'unique_enrollment')
    CREATE UNIQUE INDEX unique_enrollment ON slocloud_tbl_enrollment(section_fk, student_fk) WHERE section_fk IS NOT NULL;
IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID('slocloud_tbl_enrollment') AND name = 'idx_section_fk_enroll')
    CREATE INDEX idx_section_fk_enroll ON slocloud_tbl_enrollment(section_fk);
GO

-- 4. Assessments: 'pending' is replaced by 'not_assessed'. The default
--    constraint name was generated by SQL Server, so look it up.
IF EXISTS (SELECT 1 FROM sys.check_constraints WHERE parent_object_id = OBJECT_ID('slocloud_tbl_assessments') AND name = 'chk_achievement_level')
    ALTER TABLE slocloud_tbl_assessments DROP CONSTRAINT chk_achievement_level;

DECLARE @default_name SYSNAME = (
    SELECT dc.name FROM sys.default_constraints dc
    JOIN sys.columns c ON c.object_id = dc.parent_object_id AND c.column_id = dc.parent_column_id
    WHERE dc.parent_object_id = OBJECT_ID('slocloud_tbl_assessments') AND c.name = 'achievement_level');
IF @default_name IS NOT NULL
    EXEC('ALTER TABLE slocloud_tbl_assessments DROP CONSTRAINT [' + @default_name + ']');

UPDATE slocloud_tbl_assessments SET achievement_level = 'not_assessed'
WHERE achievement_level = 'pending' OR achievement_level IS NULL;

ALTER TABLE slocloud_tbl_assessments ADD DEFAULT 'not_assessed' FOR achievement_level;
ALTER TABLE slocloud_tbl_assessments ADD CONSTRAINT chk_achievement_level
    CHECK (achievement_level IN ('met', 'partially_met', 'not_met', 'not_assessed'));
GO

-- 5. Record the schema version. Last, so a partial run is never stamped complete.
IF OBJECT_ID('slocloud_tbl_meta', 'U') IS NULL
    CREATE TABLE slocloud_tbl_meta (
        name NVARCHAR(100) NOT NULL PRIMARY KEY,
        value NVARCHAR(255) NOT NULL,
        updated_at DATETIME2 NOT NULL DEFAULT GETDATE()
    );
GO

IF EXISTS (SELECT 1 FROM slocloud_tbl_meta WHERE name = 'schema_version')
    UPDATE slocloud_tbl_meta SET value = '5.2.1', updated_at = GETDATE() WHERE name = 'schema_version';
ELSE
    INSERT INTO slocloud_tbl_meta (name, value) VALUES ('schema_version', '5.2.1');
GO
