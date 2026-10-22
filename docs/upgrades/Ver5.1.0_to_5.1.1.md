# Upgrade plan: schema 5.1.0 → 5.1.1

**Migration:** `src/system/database/migrations/Ver5.1.0_to_5.1.1.sql` (MySQL),
`Ver5.1.0_to_5.1.1_mssql.sql` (SQL Server)
**Applies to:** databases created by 5.0.x or 5.1.0, and 5.1.1 databases, since
none of them record a schema version.
**Risk:** Low. One nullable column is added, and no existing data changes.

## What changes

| Table | Change | Why |
|---|---|---|
| `sections` | Add `instructor_name` (nullable) | LTI launches record the instructor name supplied by the LMS |
| `meta` | Created, `schema_version = 5.1.1` | Starts schema version tracking |

The 5.1.1 release shipped `src/system/scripts/add_sections_instructor_name.php`
for the column change. If that script has already been run, the migration
skips the column and only records the version.

## Preflight

None required. You can check whether the column already exists:

```sql
-- MySQL
SELECT COUNT(*) FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'slocloud_tbl_sections' AND COLUMN_NAME = 'instructor_name';

-- SQL Server
SELECT CASE WHEN COL_LENGTH('slocloud_tbl_sections', 'instructor_name') IS NULL THEN 0 ELSE 1 END;
```

The queries in this plan use the default table prefix `slocloud_tbl_`.
Replace it with the prefix in your `config.yaml` (5.0.x installs used `tbl_`).

## Steps

Follow the [general procedure](README.md#general-procedure). This migration is
normally applied in the same run as the 5.1.1 → 5.2.1 migration.

## Verify

```sql
SELECT value FROM slocloud_tbl_meta WHERE name = 'schema_version';   -- 5.1.1 or later
```

Launch an assessment from the LMS and confirm the section shows the instructor
name.

## Rollback

Restore the database backup. Alternatively, the added column is harmless to
5.1.0 code and can be left in place.
