-- SLOCloud schema migration 5.1.0 -> 5.1.1 (SQL Server 2016+)
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
IF COL_LENGTH('slocloud_tbl_sections', 'instructor_name') IS NULL
    ALTER TABLE slocloud_tbl_sections ADD instructor_name NVARCHAR(255) NULL;
GO

-- 2. Record the schema version. Last, so a partial run is never stamped complete.
IF OBJECT_ID('slocloud_tbl_meta', 'U') IS NULL
    CREATE TABLE slocloud_tbl_meta (
        name NVARCHAR(100) NOT NULL PRIMARY KEY,
        value NVARCHAR(255) NOT NULL,
        updated_at DATETIME2 NOT NULL DEFAULT GETDATE()
    );
GO

IF EXISTS (SELECT 1 FROM slocloud_tbl_meta WHERE name = 'schema_version')
    UPDATE slocloud_tbl_meta SET value = '5.1.1', updated_at = GETDATE() WHERE name = 'schema_version';
ELSE
    INSERT INTO slocloud_tbl_meta (name, value) VALUES ('schema_version', '5.1.1');
GO
