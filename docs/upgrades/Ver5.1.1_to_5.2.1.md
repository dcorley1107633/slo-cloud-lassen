# Upgrade plan: schema 5.1.1 → 5.2.1

**Migration:** `src/system/database/migrations/Ver5.1.1_to_5.2.1.sql` (MySQL),
`Ver5.1.1_to_5.2.1_mssql.sql` (SQL Server)
**Applies to:** databases at schema 5.1.1 (apply the
[5.1.0 → 5.1.1 plan](Ver5.1.0_to_5.1.1.md) first, which the runner does
automatically).
**Risk:** Medium. Enrollments are re-linked to sections, and assessment levels
are rewritten. Plan a maintenance window and take a tested backup.

The 5.2.1 code does not work against a 5.1.1 schema, and 5.1.1 code does not
work against a 5.2.1 schema. Deploy the code and apply the migration in the same
maintenance window.

## What changes

| Table | Change | Existing data |
|---|---|---|
| `users` | `is_active` becomes `NOT NULL` (MySQL) | `NULL` becomes active |
| `terms`, `institutional_outcomes`, `disciplines`, `programs`, `program_outcomes`, `courses`, `sections`, `student_learning_outcomes`, `students`, `enrollment`, `section_xlist`, `assessments` | Add `is_active` (default active) and an index on it | Every existing record stays active |
| `sections` | Unique (`term_fk`, `crn`) | **Fails if two sections in a term share a CRN** (see preflight 1) |
| `enrollment` | Add `section_fk`, backfilled from the legacy term and CRN. New uniqueness on (`section_fk`, `student_fk`). The legacy `term_code`, `crn`, and `course_fk` columns become nullable and lose their indexes, uniqueness, and course relationship. | Rows that cannot be matched to exactly one section keep `section_fk` empty (see preflight 2) |
| `assessments` | `achievement_level` allows `met`, `partially_met`, `not_met`, `not_assessed`; default `not_assessed` | `pending` (and empty) becomes `not_assessed` |
| `meta` | `schema_version = 5.2.1` | — |

### How enrollments are re-linked

In 5.1.x an enrollment stored the term code and CRN from the import file, and
the term was matched on either `banner_term` or `term_code`. The migration
links an enrollment to a section when exactly one section has that CRN in a term
matching either value. It does not guess:

- **No match:** the section was never imported, the CRN changed, or the term is
  missing. `section_fk` stays empty.
- **More than one match:** one term's `banner_term` equals another term's
  `term_code`, and both terms have a section with that CRN. `section_fk` stays
  empty.

Unresolved enrollments are kept, not deleted. They are treated as inactive,
because an enrollment is active only through an active section, so they drop
out of active listings and LTI rosters.
The legacy columns remain so they can be reviewed. While any remain, `section_fk`
stays nullable. After they are fixed or removed, run the migration again with
`--from=5.1.1`: it re-links anything newly resolvable and makes `section_fk`
required.

## Preflight

Run these on the live database before the maintenance window. They only read
data. The queries use the default table prefix `slocloud_tbl_`; replace it with
the prefix in your `config.yaml`. Each works on both MySQL and SQL Server.

**1. Sections sharing a CRN within a term (must return no rows).** The
migration stops at this step, before changing any enrollment, if any exist.

```sql
SELECT s.term_fk, s.crn, COUNT(*) AS sections
FROM slocloud_tbl_sections s
WHERE s.crn IS NOT NULL
GROUP BY s.term_fk, s.crn
HAVING COUNT(*) > 1;
```

Merge or re-number the duplicates, moving their enrollments and assessment
methods to the section you keep, then re-run the query.

**2. Enrollments that will not be re-linked.** Review this list: every row will
be kept but hidden until it is fixed.

```sql
SELECT e.enrollment_pk, e.term_code, e.crn, e.student_fk,
       COUNT(DISTINCT s.sections_pk) AS candidate_sections
FROM slocloud_tbl_enrollment e
LEFT JOIN slocloud_tbl_terms t ON t.banner_term = e.term_code OR t.term_code = e.term_code
LEFT JOIN slocloud_tbl_sections s ON s.term_fk = t.terms_pk AND s.crn = e.crn
GROUP BY e.enrollment_pk, e.term_code, e.crn, e.student_fk
HAVING COUNT(DISTINCT s.sections_pk) <> 1;
```

`candidate_sections = 0` usually means the section import for that term is
missing. Importing it before the upgrade avoids the exception entirely.

**3. Assessments that will change level.**

```sql
SELECT COUNT(*) AS pending_assessments
FROM slocloud_tbl_assessments
WHERE achievement_level = 'pending' OR achievement_level IS NULL;
```

These become `not_assessed`, which is how 5.2 shows an outcome with no recorded
decision.

**4. Record the counts** from queries 2 and 3 and the total enrollment count
(`SELECT COUNT(*) FROM slocloud_tbl_enrollment`) to compare after the upgrade.

## Steps

1. Complete the preflight. Query 1 must return no rows.
2. Turn on Maintenance Mode and save the configuration.
3. Back up the database, `config.yaml`, `key.yaml`, and `var/`:
   - MySQL: `mysqldump --single-transaction --routines <database> > slocloud-5.1.1.sql`
   - SQL Server: `BACKUP DATABASE [<database>] TO DISK = N'...\slocloud-5.1.1.bak' WITH COPY_ONLY, CHECKSUM;`
4. Deploy the 5.2.1 code, keeping `config.yaml`, `key.yaml`, and `var/`.
5. Review the SQL: `php src/system/scripts/apply_migration.php --print`
6. Apply it: `php src/system/scripts/apply_migration.php`
   If it stops with an error, the message names the failing statement. Fix
   the cause and run the same command again: every step checks the current
   shape first and skips work that is already done.
7. Verify (below).
8. In **Custom Reports**, choose **Refresh Catalog** so reports see the new
   fields.
9. Turn Maintenance Mode off.

Expected duration: seconds for the column and index changes. The enrollment
backfill is one statement, so it takes about as long as a join across
enrollment, terms, and sections.

## Verify

```sql
SELECT value FROM slocloud_tbl_meta WHERE name = 'schema_version';        -- 5.2.1
SELECT COUNT(*) FROM slocloud_tbl_enrollment;                              -- unchanged from preflight
SELECT COUNT(*) FROM slocloud_tbl_enrollment WHERE section_fk IS NULL;     -- equals preflight query 2
SELECT COUNT(*) FROM slocloud_tbl_assessments
WHERE achievement_level NOT IN ('met', 'partially_met', 'not_met', 'not_assessed');  -- 0
```

Then, in the application:

- Sign in as an administrator. Dashboards and the Sections, Enrollment, and
  Students screens load and show records as active.
- Launch an assessment from the LMS for a section with known students. The
  roster is complete, and previously saved levels appear. Former "pending"
  entries show as not assessed.
- Import a small enrollment file of existing students. They **match** existing
  records rather than being inserted again.
- Run a custom report on Enrollment.

## Unresolved enrollments after the upgrade

List them with the preflight 2 query, restricted to `WHERE e.section_fk IS NULL`.
For each, either import or correct the missing section and set `section_fk`, or
delete the enrollment if it is not wanted. When none remain, finish with:

```bash
php src/system/scripts/apply_migration.php --from=5.1.1
```

## Rollback

Restore the database backup and redeploy the 5.1.1 code. There is no partial
rollback: once the migration has started, 5.1.1 code cannot use the database
reliably (assessment levels and enrollment uniqueness have changed).

## Other 5.2.1 changes that affect the upgrade

- Every encryption key rotation now requires Maintenance Mode, not only the
  student lookup key.
- Fresh SQL Server installs now get the same lifecycle columns as MySQL. Before
  5.2.1 the SQL Server schema file lacked them.
