-- SLOCloud schema migration 5.1.0 -> 5.1.1 (MySQL 8.0+)
--
-- Adds the LMS-supplied instructor name to sections. This is the change that
-- add_sections_instructor_name.php applied by hand, so on a database where that
-- script already ran, only the version stamp below changes anything.
-- Read docs/upgrades/Ver5.1.0_to_5.1.1.md before running it. Apply it with
-- src/system/scripts/apply_migration.php, which substitutes the configured table
-- prefix, applies pending migrations in order, and stops on the first error.
--
-- Every step checks the current shape first, so the file is safe to re-run
-- after a partial failure.

-- 1. Sections: LMS-supplied instructor name
SET @migration_sql = IF((SELECT COUNT(*) = 0 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_sections' AND COLUMN_NAME = 'instructor_name') = 1, 'ALTER TABLE slocloud_tbl_sections ADD COLUMN instructor_name VARCHAR(255) NULL COMMENT ''Instructor name supplied by the LMS launch'' AFTER crn', 'DO 0');
PREPARE migration_stmt FROM @migration_sql;
EXECUTE migration_stmt;
DEALLOCATE PREPARE migration_stmt;

-- 2. Record the schema version. Last, so a partial run is never stamped complete.
CREATE TABLE IF NOT EXISTS slocloud_tbl_meta (
    name VARCHAR(100) NOT NULL PRIMARY KEY,
    value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO slocloud_tbl_meta (name, value) VALUES ('schema_version', '5.1.1') ON DUPLICATE KEY UPDATE value = VALUES(value);
