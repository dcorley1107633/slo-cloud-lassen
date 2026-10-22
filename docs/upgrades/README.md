# Upgrading SLOCloud

Every release that changes the database schema ships a migration pair in
`src/system/database/migrations/` (one file for MySQL, one for SQL Server) and an
upgrade plan in this folder. Releases that do not change the schema need no
database step: deploy the code and follow the patch notes.

## Version map

| From → To | Schema change | Migration | Upgrade plan |
|---|---|---|---|
| 5.0.2 → 5.1.0 | None. The default table prefix for *new* installs changed from `tbl_` to `slocloud_tbl_`; existing installs keep the prefix in their `config.yaml`. | — | Patch notes only |
| 5.1.0 → 5.1.1 | Sections gain `instructor_name` | `Ver5.1.0_to_5.1.1` | [Ver5.1.0_to_5.1.1.md](Ver5.1.0_to_5.1.1.md) |
| 5.1.1 → 5.2.1 | Lifecycle flags, one CRN per term, section-owned enrollments, `not_assessed` level, schema version table | `Ver5.1.1_to_5.2.1` | [Ver5.1.1_to_5.2.1.md](Ver5.1.1_to_5.2.1.md) |

Each release is tagged in git (`v5.0.2`, `v5.1.0`, `v5.1.1`, `v5.2.1`), so the
schema for any two versions can be compared directly:

```bash
git diff v5.1.1 v5.2.1 -- src/system/database/
```

## How versions are tracked

From 5.2.1 the database records its schema version in the `meta` table
(`name = 'schema_version'`). Fresh installs are stamped by `schema.sql` /
`schema_mssql.sql`, and each migration stamps its target version as its last
step. A database without that table predates versioning and is treated as
5.1.0, which is safe: the 5.1.0 → 5.1.1 migration only adds a column when it is
missing.

## Applying migrations

`src/system/scripts/apply_migration.php` reads the recorded version, finds the
pending migrations, substitutes the configured table prefix, and applies them in
order, stopping at the first error. It refuses to run unless Maintenance Mode is
on.

```bash
php src/system/scripts/apply_migration.php --print
```

Shows the pending SQL, with your table prefix applied, without changing
anything. Run it without `--print` to apply. Follow the upgrade plan for each
pending step; the plans cover preflight checks, verification, and rollback.

Upgrading across several releases (for example 5.1.0 → 5.2.1) applies every
migration in between in one run, but read every plan in the path first.

## General procedure

1. Read every upgrade plan between your version and the target, and run their
   preflight checks.
2. Turn on Maintenance Mode (**Administration > System > Configuration**) and
   save.
3. Back up the database, `config.yaml`, `key.yaml`, and the `var/` data
   directory.
4. Deploy the new code, keeping `config.yaml`, `key.yaml`, and `var/`.
5. Run `apply_migration.php --print`, review it, then run it without `--print`.
6. Run the verification steps in each plan.
7. Turn Maintenance Mode off.

Rollback is always "restore the backup and the previous code". Migrations have
no down scripts, and MySQL cannot roll back schema changes.
