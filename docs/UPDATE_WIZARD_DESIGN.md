# SLOCloud Update Wizard — Design

Status: **Design / not yet implemented**
Audience: maintainers building the in-admin update feature.

## 1. Core Principle

An in-application updater is only safe to run **unattended** when every code version
in play is compatible with the one live database throughout the update. That is true
**if and only if the database schema does not change.**

The wizard therefore has **two modes**, and a single check chooses between them:

| Condition | Mode | Risk | Automation |
|-----------|------|------|------------|
| Code's required schema == DB's stored schema | **A — Schema-stable self-update** | Low (code deploy only) | Safe to be one-click / automatic |
| Code requires a newer schema than the DB has | **B — Schema-changing guided update** | High (migration, rollback) | Manual, backup-gated, confirmed |

The version number literally routes the updater: a schema-stable release (patch, or a
minor that does not alter the schema) takes Mode A; a schema-changing release (major)
takes Mode B. See [§9](#9-versioning-policy-tie-in).

## 2. The Two Versions

Two independent versions can drift, and the whole feature exists to detect the gap:

- **Code version** — `src/VERSION`. Ships with the code; it *is* the deployed code.
  Nothing else needs to store it.
- **Schema version** — the shape of the database. Must be stored **in the database**
  so it travels with the data (a restored backup carries its own true schema stamp;
  a file on the deployment cannot vouch for the DB's actual shape).

Code also carries a `REQUIRED_SCHEMA_VERSION` constant — the schema version this code
needs. The gate compares that constant against the value stored in the database.

### Storage

Add a small key/value meta table (authored for **both** SQL dialects, like
`schema.sql` / `schema_mssql.sql`):

```
{prefix}meta ( name VARCHAR PRIMARY KEY, value VARCHAR )
-- seeded row: ('schema_version', '5.1.0')
```

Bootstrap gracefully: if the table or row is absent, treat the database as
"pre-versioning" (baseline) and create it rather than erroring. Note that adding this
table is itself a one-time schema change (a Mode B / major step).

## 3. The Compatibility Gate (Boot Check)

On boot, after config load and DB connect, cheaply read `{prefix}meta.schema_version`
and compare to `REQUIRED_SCHEMA_VERSION`:

- **Equal** → normal operation. (An update, if any, is Mode A and touches code only.)
- **Code newer than DB** → the database is behind the code. Block normal pages with a
  clear "database update required" notice and route the admin to the update wizard
  (Mode B). Mirror the setup wizard's guard-and-redirect pattern (setup fires when
  `config.yaml` is absent → `/setup/`; update fires on schema mismatch → `/update/`).
- **DB newer than code** → someone deployed *older* code over a newer database. Refuse
  to run and say so; do not attempt to "downgrade."

## 4. Mode A — Schema-Stable Self-Update

Because the schema is unchanged, old and new code are both compatible with the live
database at every instant of the swap. The database is never a failure point, so this
reduces to a **code deployment**:

1. (If downloading) fetch the release and **verify its signature** — see [§6](#6-download--integrity).
2. Enter **maintenance mode** (a lock/flag file the front controller checks, returning
   503 for all non-updater requests).
3. Extract to a **staging directory**, then **atomically swap** it in (rename), rather
   than overwriting files in place.
4. Preserve the **exclusion set** (never overwrite): `src/config/`, `src/sessions/`,
   the log directory, the encryption key, and `var/reports/definitions`.
5. **Reset the opcode cache** (`opcache_reset()`), or stale compiled code keeps running
   — especially with `opcache.validate_timestamps=0`.
6. Exit maintenance mode.

Rollback is trivial: swap the retained old `src/` back and reset opcache. A DB backup
is *not required for safety* here (the schema never moved), though a cheap snapshot is
still good hygiene.

This mode is safe to offer as one-click, and — with signature verification and a tested
swap — could even run automatically for patch releases.

## 5. Mode B — Schema-Changing Guided Update

Here the new code needs a newer schema, so there is a window in which some code version
is incompatible with the database no matter the ordering. This mode is **never blind**.

Required sequence (order is deliberate):

1. Enter maintenance mode.
2. **Back up both** the database *and* the current `src/` tree.
3. Show a **preview**: current schema version, required version, and the ordered list of
   pending migration steps. Require explicit admin confirmation. (No silent "upgrade now"
   button that runs DDL.)
4. Swap in the new code (as in Mode A, staging + atomic swap + exclusion set).
5. **Run the migration chain in order** (see [Migration Files](#migration-files) below),
   recording each applied version in `{prefix}meta` as it succeeds. Stop on first failure
   in a clearly recorded state.
6. Reset opcache; exit maintenance mode.

### Migration Files

Each schema-changing step is an **explicit, named SQL file**:

```
src/system/database/migrations/
  Ver5.1.0_to_6.0.0.sql          -- MySQL
  Ver5.1.0_to_6.0.0_mssql.sql    -- SQL Server
  Ver6.0.0_to_6.1.0.sql
  Ver6.0.0_to_6.1.0_mssql.sql
  ...
```

- **Dual dialect.** Like `schema.sql` / `schema_mssql.sql`, every migration is authored
  for both engines; the runner picks the file matching the configured driver.
- **Files exist only for schema-changing releases.** A schema-stable release (Mode A) has
  no migration file — that absence is exactly what makes it Mode A. So the `from → to`
  boundaries are the app versions at which the schema actually changed, and may **skip
  over** intervening schema-stable versions (e.g. `Ver5.1.0_to_6.0.0.sql` even though
  5.2–5.9 shipped in between). `{prefix}meta.schema_version` stores the app version of the
  **last applied schema migration**, i.e. the file's `to` value.
- **Chaining.** To go from the DB's stored version to `REQUIRED_SCHEMA_VERSION`, the runner
  finds the file whose `from` matches the current stored version, applies it, advances
  `schema_version` to its `to`, and repeats until it reaches the required version. The
  chain must be unbroken — each `to` is the next file's `from`. Skips are not allowed;
  intermediate migration files must exist.
- **Idempotency.** Guard every statement (`IF NOT EXISTS`, existence checks) so re-running
  a file after a partial failure is safe — mandatory on MySQL, which cannot roll back DDL.
- **No down-migrations required.** Rollback is by restoring the pre-update backup (below),
  not by reverse SQL, so `VerY_to_VerX.sql` files are optional and not part of the core
  design.

### Rollback

- **Code**: swap the retained old `src/` back.
- **Database**: restore from the pre-update backup. Backup-first is **mandatory** because
  **MySQL cannot roll back DDL** (DDL implicitly commits); MSSQL has transactional DDL and
  can roll back a failed migration cleanly, but the backup covers both engines uniformly.
- Migrations should be **idempotent** (`IF NOT EXISTS`, guards) so re-running after a
  partial failure is safe.

### Auth-before-upgrade hazard

If a migration touches the users/roles tables, normal admin login can break on the *old*
schema — locking the admin out of the very screen that fixes it. The update wizard must
therefore be reachable via the **break-glass emergency admin** and run on a minimal
bootstrap that does not assume the new schema (the same reason setup runs without normal
auth).

## 6. Download & Integrity

Applies whenever the wizard fetches code rather than having it placed by an external
pipeline. An auto-updater is, by construction, a remote-code-execution channel: the app
downloads code and then runs it as itself.

- A **version manifest endpoint** ("is there an update, and what version").
- Transport over **HTTPS**.
- **Cryptographic signature verification** of the downloaded artifact against a **public
  key pinned in the application**. A checksum alone is not sufficient for update
  integrity. If signing cannot be done properly, ship only the DB-only /
  externally-deployed variant (see [�10](#10-open-decisions)).

## 7. Windows / IIS Specifics

This is the primary implementation deployment and the hardest part of the code swap.

- **File locks**: IIS/PHP can hold handles on in-use files, so renaming or deleting the
  old `src/` mid-serve can fail. The **app-pool recycle** may need to be part of the swap.
- **Opcache** must be reset after the swap; production configs often disable timestamp
  validation, so files changing on disk is not enough.
- **Writable webroot tension**: for the app to overwrite its own files, the app-pool
  identity needs **write access to `src/`** — the opposite of the read-only-webroot
  hardening. Treat this as a deliberate, documented trade-off, scoped as tightly as
  possible. (The externally-deployed variant avoids this entirely.)

## 8. Backups, Failure Handling, Resumability

- Reuse the existing backup mechanism (the directory-copy backup added for key rotation).
- Each stage (download, verify, backup, swap, migrate) is a **checkpoint**: on failure,
  stop in a recorded state and present either a resume or a rollback, never a silent retry.
- Account for **live LTI sessions** (users mid-assessment): maintenance mode should warn/
  drain rather than corrupt their session.

## 9. Versioning Policy Tie-in

The two modes give the major/minor line a **functional** meaning, not just a label:

- **Patch / schema-stable minor** → Mode A → auto-updatable.
- **Major (schema change)** → Mode B → guided, backup-gated.

In the current no-migration-runner world, "schema-stable" means the schema is *identical*
(even an additive column would break a drop-in), so any schema change is a major and takes
Mode B. If a richer migration runner is later built, backward-compatible migrations could
be applied automatically and the auto-update set could widen — but that is Mode B
machinery and is out of scope for the "true" (blind) self-update.

## 10. Open Decisions

1. **Full download-and-patch vs DB-only / externally-deployed.** The full wizard
   (download + verify + swap + migrate) is the most convenient and the most dangerous
   (writable webroot, self-overwrite, file locks, signing). A DB-only wizard — code
   deployed by CI/CD, MSI, or manual `src/` swap, wizard does version check + migration
   only — removes ~70% of the risk surface. **Decide this first**, as it changes §4/§6/§7.
2. **Automatic vs one-click for Mode A.** Even when safe, do patch releases apply
   automatically, or only on admin confirmation?
3. **Release hosting and signing-key management.** Where releases and the manifest live,
   and how the signing key is generated, stored, and rotated.

## 11. Suggested Build Sequence

Bottom-up, each layer usable and testable on its own:

1. `{prefix}meta.schema_version` (both dialects) + `REQUIRED_SCHEMA_VERSION` constant +
   boot-time compare that **warns** on mismatch. Useful immediately, before any wizard.
2. Migration runner over explicit `VerX_to_VerY.sql` files (dual-dialect, idempotent,
   chained) — see [§5 Migration Files](#migration-files).
3. Signed download + signature verification against a pinned key.
4. Staged swap + maintenance mode + opcache reset (the Mode A engine).
5. The wizard UI orchestrating all of the above, routed by the §3 gate.

Design §3 (the gate) and §6 (download integrity) most carefully; they are the load-bearing
pieces.
