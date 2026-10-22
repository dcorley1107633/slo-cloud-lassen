-- SLOCloud schema migration 5.1.1 -> 5.2.1 (MySQL 8.0+)
--
-- Adds record lifecycle (activation) flags, one CRN per term, section-owned
-- enrollments, and the not_assessed achievement level.
-- Read docs/upgrades/Ver5.1.1_to_5.2.1.md before running it. Apply it with
-- src/system/scripts/apply_migration.php, which substitutes the configured table
-- prefix, applies pending migrations in order, and stops on the first error.
--
-- Every step checks the current shape first, so the file is safe to re-run
-- after a partial failure. MySQL cannot roll back DDL: recovery from a failed run
-- is to fix the reported cause and run the file again, or restore the backup.
--
-- Legacy enrollment term, CRN, and course columns are kept (nullable, no longer
-- related) so unresolved rows can be reviewed with the queries in the upgrade
-- plan. Enrollment section_fk becomes NOT NULL only when every row resolved.
-- Re-running this file after those rows are fixed or removed completes that step.

-- 1. Users: is_active becomes NOT NULL
UPDATE slocloud_tbl_users SET is_active = TRUE WHERE is_active IS NULL;
ALTER TABLE slocloud_tbl_users MODIFY is_active BOOLEAN NOT NULL DEFAULT TRUE;

-- 2. Lifecycle flag and index on every lifecycle entity (existing rows stay active)
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_terms' AND COLUMN_NAME = 'is_active') = 1, 'ALTER TABLE slocloud_tbl_terms ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE AFTER end_date', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_terms' AND INDEX_NAME = 'idx_is_active') = 1, 'ALTER TABLE slocloud_tbl_terms ADD INDEX idx_is_active (is_active)', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_institutional_outcomes' AND COLUMN_NAME = 'is_active') = 1, 'ALTER TABLE slocloud_tbl_institutional_outcomes ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE AFTER sequence_num', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_institutional_outcomes' AND INDEX_NAME = 'idx_is_active') = 1, 'ALTER TABLE slocloud_tbl_institutional_outcomes ADD INDEX idx_is_active (is_active)', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_disciplines' AND COLUMN_NAME = 'is_active') = 1, 'ALTER TABLE slocloud_tbl_disciplines ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE AFTER discipline_code', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_disciplines' AND INDEX_NAME = 'idx_is_active') = 1, 'ALTER TABLE slocloud_tbl_disciplines ADD INDEX idx_is_active (is_active)', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_programs' AND COLUMN_NAME = 'is_active') = 1, 'ALTER TABLE slocloud_tbl_programs ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE AFTER degree_type', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_programs' AND INDEX_NAME = 'idx_is_active') = 1, 'ALTER TABLE slocloud_tbl_programs ADD INDEX idx_is_active (is_active)', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_program_outcomes' AND COLUMN_NAME = 'is_active') = 1, 'ALTER TABLE slocloud_tbl_program_outcomes ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE AFTER sequence_num', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_program_outcomes' AND INDEX_NAME = 'idx_is_active') = 1, 'ALTER TABLE slocloud_tbl_program_outcomes ADD INDEX idx_is_active (is_active)', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_courses' AND COLUMN_NAME = 'is_active') = 1, 'ALTER TABLE slocloud_tbl_courses ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE AFTER course_name', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_courses' AND INDEX_NAME = 'idx_is_active') = 1, 'ALTER TABLE slocloud_tbl_courses ADD INDEX idx_is_active (is_active)', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_sections' AND COLUMN_NAME = 'is_active') = 1, 'ALTER TABLE slocloud_tbl_sections ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE AFTER instructor_name', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_sections' AND INDEX_NAME = 'idx_is_active') = 1, 'ALTER TABLE slocloud_tbl_sections ADD INDEX idx_is_active (is_active)', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_student_learning_outcomes' AND COLUMN_NAME = 'is_active') = 1, 'ALTER TABLE slocloud_tbl_student_learning_outcomes ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE AFTER sequence_num', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_student_learning_outcomes' AND INDEX_NAME = 'idx_is_active') = 1, 'ALTER TABLE slocloud_tbl_student_learning_outcomes ADD INDEX idx_is_active (is_active)', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_students' AND COLUMN_NAME = 'is_active') = 1, 'ALTER TABLE slocloud_tbl_students ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE AFTER student_id_hash', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_students' AND INDEX_NAME = 'idx_is_active') = 1, 'ALTER TABLE slocloud_tbl_students ADD INDEX idx_is_active (is_active)', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_enrollment' AND COLUMN_NAME = 'is_active') = 1, 'ALTER TABLE slocloud_tbl_enrollment ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE AFTER student_fk', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_enrollment' AND INDEX_NAME = 'idx_is_active') = 1, 'ALTER TABLE slocloud_tbl_enrollment ADD INDEX idx_is_active (is_active)', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_section_xlist' AND COLUMN_NAME = 'is_active') = 1, 'ALTER TABLE slocloud_tbl_section_xlist ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE AFTER course_number', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_section_xlist' AND INDEX_NAME = 'idx_is_active') = 1, 'ALTER TABLE slocloud_tbl_section_xlist ADD INDEX idx_is_active (is_active)', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_assessments' AND COLUMN_NAME = 'is_active') = 1, 'ALTER TABLE slocloud_tbl_assessments ADD COLUMN is_active BOOLEAN NOT NULL DEFAULT TRUE AFTER assessor_email', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_assessments' AND INDEX_NAME = 'idx_is_active') = 1, 'ALTER TABLE slocloud_tbl_assessments ADD INDEX idx_is_active (is_active)', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- 3. Sections: one CRN per term. Fails here, before any enrollment change, if
--    duplicate (term, CRN) sections exist. Resolve them and re-run.
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_sections' AND INDEX_NAME = 'unique_section_crn') = 1, 'ALTER TABLE slocloud_tbl_sections ADD UNIQUE KEY unique_section_crn (term_fk, crn)', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- 4. Enrollment: relate to sections instead of term/CRN/course
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_enrollment' AND COLUMN_NAME = 'section_fk') = 1, 'ALTER TABLE slocloud_tbl_enrollment ADD COLUMN section_fk INT NULL AFTER enrollment_pk', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- 4a. Legacy uniqueness, indexes, and the course relationship no longer apply.
--     The old foreign key name was generated by MySQL, so look it up.
SET @legacy_fk = (SELECT MIN(CONSTRAINT_NAME) FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_enrollment'
      AND COLUMN_NAME = 'course_fk' AND REFERENCED_TABLE_NAME IS NOT NULL);
SET @migration_sql = IF(@legacy_fk IS NULL, 'DO 0', CONCAT('ALTER TABLE slocloud_tbl_enrollment DROP FOREIGN KEY `', @legacy_fk, '`'));
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) > 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_enrollment' AND INDEX_NAME = 'unique_enrollment' AND COLUMN_NAME = 'term_code') = 1, 'ALTER TABLE slocloud_tbl_enrollment DROP INDEX unique_enrollment', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) > 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_enrollment' AND INDEX_NAME = 'idx_term_code') = 1, 'ALTER TABLE slocloud_tbl_enrollment DROP INDEX idx_term_code', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) > 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_enrollment' AND INDEX_NAME = 'idx_crn') = 1, 'ALTER TABLE slocloud_tbl_enrollment DROP INDEX idx_crn', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) > 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_enrollment' AND INDEX_NAME = 'idx_course_fk') = 1, 'ALTER TABLE slocloud_tbl_enrollment DROP INDEX idx_course_fk', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- 4b. Keep legacy values for cleanup review, but stop requiring them.
SET @migration_sql = IF((SELECT COUNT(*) > 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_enrollment' AND COLUMN_NAME = 'term_code') = 1, 'ALTER TABLE slocloud_tbl_enrollment MODIFY term_code VARCHAR(20) NULL COMMENT ''Legacy term code, cleanup only''', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) > 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_enrollment' AND COLUMN_NAME = 'crn') = 1, 'ALTER TABLE slocloud_tbl_enrollment MODIFY crn VARCHAR(20) NULL COMMENT ''Legacy CRN, cleanup only''', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) > 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_enrollment' AND COLUMN_NAME = 'course_fk') = 1, 'ALTER TABLE slocloud_tbl_enrollment MODIFY course_fk INT NULL COMMENT ''Legacy course key, cleanup only''', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- 4c. Backfill section_fk from the legacy term and CRN. 5.1.x matched a term by
--     banner_term or term_code, so accept either. Only rows with exactly one
--     candidate section are linked. Others stay NULL for review.
DROP TEMPORARY TABLE IF EXISTS migration_enrollment_sections;
CREATE TEMPORARY TABLE migration_enrollment_sections AS
SELECT e.enrollment_pk, MIN(s.sections_pk) AS sections_pk
FROM slocloud_tbl_enrollment e
JOIN slocloud_tbl_terms t ON t.banner_term = e.term_code OR t.term_code = e.term_code
JOIN slocloud_tbl_sections s ON s.term_fk = t.terms_pk AND s.crn = e.crn
WHERE e.section_fk IS NULL
GROUP BY e.enrollment_pk
HAVING COUNT(DISTINCT s.sections_pk) = 1;
UPDATE slocloud_tbl_enrollment e
JOIN migration_enrollment_sections m ON m.enrollment_pk = e.enrollment_pk
SET e.section_fk = m.sections_pk;
DROP TEMPORARY TABLE migration_enrollment_sections;

-- 4d. New relationship, uniqueness, and index.
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_enrollment' AND INDEX_NAME = 'unique_enrollment') = 1, 'ALTER TABLE slocloud_tbl_enrollment ADD UNIQUE KEY unique_enrollment (section_fk, student_fk)', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_enrollment' AND INDEX_NAME = 'idx_section_fk') = 1, 'ALTER TABLE slocloud_tbl_enrollment ADD INDEX idx_section_fk (section_fk)', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_enrollment' AND COLUMN_NAME = 'section_fk' AND REFERENCED_TABLE_NAME = 'slocloud_tbl_sections') = 1, 'ALTER TABLE slocloud_tbl_enrollment ADD CONSTRAINT fk_enrollment_section FOREIGN KEY (section_fk) REFERENCES slocloud_tbl_sections(sections_pk) ON DELETE CASCADE', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- 4e. Require section_fk once no unresolved rows remain.
SET @migration_sql = IF((SELECT (SELECT COUNT(*) FROM slocloud_tbl_enrollment WHERE section_fk IS NULL) = 0 AND (SELECT IS_NULLABLE = 'YES' FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_enrollment' AND COLUMN_NAME = 'section_fk')) = 1, 'ALTER TABLE slocloud_tbl_enrollment MODIFY section_fk INT NOT NULL', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- 5. Assessments: 'pending' is replaced by 'not_assessed'. Widen, convert, narrow.
--    Empty values (invalid enum input stored by non-strict servers) are converted too.
ALTER TABLE slocloud_tbl_assessments MODIFY achievement_level ENUM('met', 'partially_met', 'not_met', 'pending', 'not_assessed') NULL DEFAULT 'not_assessed';
UPDATE slocloud_tbl_assessments SET achievement_level = 'not_assessed' WHERE achievement_level IN ('pending', '') OR achievement_level IS NULL;
ALTER TABLE slocloud_tbl_assessments MODIFY achievement_level ENUM('met', 'partially_met', 'not_met', 'not_assessed') NOT NULL DEFAULT 'not_assessed';

-- 6. Record the schema version. Last, so a partial run is never stamped complete.
CREATE TABLE IF NOT EXISTS slocloud_tbl_meta (
    name VARCHAR(100) NOT NULL PRIMARY KEY,
    value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO slocloud_tbl_meta (name, value) VALUES ('schema_version', '5.2.1') ON DUPLICATE KEY UPDATE value = VALUES(value);
