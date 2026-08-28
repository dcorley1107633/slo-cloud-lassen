# SLOCloud Patch Notes

## Unreleased

- Added live orphaned-enrollment summary and detail reports for reviewing
  retained section-migration exceptions without deleting them.
- Custom reports can now start from the record type they need to preserve,
  allowing null-parent/orphan reviews that cannot be reached from Terms.
- Legacy enrollment term, CRN, and course values are labeled cleanup-only and
  remain non-relational; the catalog cannot recreate Enrollment -> Course.
- Cyclic report join graphs are rejected before SQL generation to prevent
  duplicate aliases, row fan-out, and gateway timeouts.

### New Features

- Added a global-administrator-only LTI 1.1 launch bootstrapper using the standard AdminLTE interface. Administrators can open canonical section and cross-listed launch contexts directly from their respective administration screens, review the generated LTI parameters, create an OAuth HMAC signature in the browser, inspect its timestamp, nonce, and signature, and submit the simulated launch through the production validation path.
- Added activation and deactivation controls for terms, departments, outcomes,
  programs, courses, sections, students, enrollments, assessments, and
  cross-list entries. Management screens show active records by default and
  provide a page-local toggle for viewing deactivated records only.
- Added expandable related-record summaries to administration tables with
  direct child dependencies, including counts, active/inactive state, and
  compact relationship-specific tables.

### Improvements

- Normalized enrollments under course sections using `section_fk`; term, CRN, and course context now resolve through the section record across imports, administration, dashboards, LTI authorization, and Dynamic Reports.
- Added a guarded enrollment migration utility that backs up legacy mappings, validates term/CRN resolution, supports retaining unresolved legacy rows for later review, and works with both MySQL and SQL Server installations.
- Enforced one CRN per term and updated fresh setup schemas and starter reports for the section-owned enrollment model.
- Added effective-parent status handling: children of a deactivated parent are
  excluded without overwriting the children's stored activation state and
  automatically become available again when their required parent is active.
- Excluded deactivated and effectively inactive records from dashboard
  statistics, report results and filter options, active selectors, normalized
  imports, and term-copy operations.
- Imports and term copy now skip deactivated matching records instead of
  updating, duplicating, or implicitly reactivating them, and report the
  affected records in their results.
- Disabled individual academic-record deletion from dashboard screens and
  server-side dashboard requests in favor of deactivation. The guarded Clear
  Term Data workflow and deletion of saved custom-report definitions remain
  available.
- Added indexed, default-active lifecycle fields to the connected database and
  the MySQL reference schema, plus an idempotent maintenance utility that
  synchronizes and verifies lifecycle metadata and effective-status queries.
- Added audit events for record activation and deactivation.
- Moved the infrequently used activation and deactivation actions from table
  rows into each record's edit dialog, while preserving confirmation,
  parent-status safeguards, and audit logging.
- Standardized administration-row actions as non-wrapping control groups and
  added accessible names, expanded-state announcements, live loading and error
  messages, scoped table headings and captions, keyboard-scrollable dependency
  regions, and text labels that do not rely on color alone.

## v5.1.1 (2026-07-20)

### New Features

- Added an administrator-only Activity Log report with filters and CSV export.
- Added administrator controls to create or rotate the keyed student-ID lookup index, including first-time adoption for installations still using legacy unkeyed hashes.

### Security Fixes

- LTI assessment reads and writes are now bound to the signed launch context and reject stale, altered, or unauthorized requests.
- Dashboard XSS paths were closed, and shared Content-Security-Policy enforcement now applies across routes.
- Sessions, protected configuration, logs, and runtime data are better isolated from web access.
- Administrator-only routes are centrally enforced, with simplified user access controls.
- Student lookup hashes now use an install-specific HMAC key. Administration-panel and maintenance-script rotations recover each plaintext ID from encrypted student data, rehash all rows transactionally, and do not depend on the previous lookup key.
- Student data operations and key rotation now share a coordination lock so imports and updates cannot overlap a key transition.
- Security headers, OAuth validation, login throttling, session rotation, and emergency credential handling were hardened.
- Configuration writes are encrypted, atomic, backed up, and fail closed on invalid secret values.
- Setup now requires a short-lived token, CSRF validation, an allowed source, and a named initial administrator.
- Admin error handling now returns generic browser messages outside development while preserving server-side detail.
- Activity Log output excludes raw log payloads and student PII.

### Improvements

- Added a shared in-app Markdown renderer for patch notes and accessibility documentation; VPAT reports and guides now render within the administration theme instead of opening as raw Markdown files.
- Corrected the normalized import routine to keep departments, programs, courses, outcomes, sections, enrollments, mappings, and cross-listings scoped to each CSV row's term.
- Corrected File 5 PSLO-to-ISLO alignment imports and preserved those alignments when copying term data.
- Corrected Copy Term Data to upsert institutional outcomes, departments, programs, program outcomes, courses, student learning outcomes, and program-course mappings using the same entity keys as normalized imports. Existing destination records are now refreshed in place, missing records are created, CSLO-to-PSLO mappings are refreshed, and repeated copies no longer create duplicates for any copied entity type.
- Improved CSV text normalization for Windows-1252 punctuation and unsupported Unicode characters that previously caused database-operation failures.
- Added preflight validation requiring `term_code` as the first column in all 12 normalized import files; malformed files now fail before any data is written.
- Added an import processing state that disables all import buttons and shows a spinner on the submitted file to prevent duplicate submissions.
- Removed the PHP execution-time ceiling from normalized imports so deployments can allow long-running imports without extending unrelated requests.
- Corrected floated AdminLTE card headings on the key-management and normalized-import screens so descriptions remain below their titles and action buttons align consistently.

## v5.1.0 (2026-07-19)

### New Features

- Added Dynamic Reports for validated custom reporting with saved definitions, printing, and CSV export.
- Added multi-launch LTI session support and an embedded assessment keepalive endpoint.
- Added branded 403, 404, and 500 error pages.
- Improved user and role management screens.

### Security Fixes

- Added default-deny route authorization across web entry points.
- Moved sessions, logs, setup tokens, and report state outside the shipped `src/` web root.
- Hardened session cookies, session rotation, LTI expiry checks, and CSRF-protected logout.
- Hardened setup, configuration encryption, LTI OAuth validation, structured logging, and audit event normalization.
- Vendored browser dependencies under `src/assets/vendor/` with licenses, policy, and checksums.
- Blocked direct web access to sensitive config, log, backup, and archive directories.
- Added key-rotation backups, critical failure alerts, LTI session checks, and safer cross-driver database queries.

### Improvements

- Completed WCAG 2.2 Level AA implementation evidence for administration and LTI scopes.
- Added security regression checks for route policy, protected paths, OAuth/setup/logout controls, encryption, local assets, and vendor checksums.
- Improved CSV date normalization, PSLO import column handling, SLO form handling, and assessment save errors.
- Added IIS deployment support and configurable logging location/retention.
- Documented moving encryption keys outside the web root.
- Changed the default table prefix for new installs to `slocloud_tbl_`.
- Centralized schema prefix substitution and clarified report catalog behavior.
- Report catalogs are now live per-install runtime state and saved reports are revalidated after catalog rebuilds.
- Backups now use directory copies instead of requiring `ZipArchive`.

### Maintenance

- Removed legacy standalone cross-listing scripts and bulk staging CSV files.

---

## v5.0.2 (2026-05-11)

### New Features

- Added key rotation controls for config and student data encryption keys.

### Security Fixes

- Config/key rotation now uses atomic writes and restores originals on failure.
- Student data re-encryption now runs in one database transaction before key updates are committed.

---

## v5.0.1 (2026-05-10)

### Removed

- Removed unfinished denormalized CSV import UI paths and raw staging data files.
- Removed the de-scoped `imports.php` UI link.

### Security Fixes

- Removed invisible characters from PHP files.
- Hardened CSV upload validation on the remaining import path.
- Added API key authentication to `/api/`.
- Tightened cross-listed CRN authorization in LTI launches.

### New Features

- Added cross-listed section management.

### Improvements

- Normalized cross-listed CRN handling and improved related error messages.
- Updated the VPAT to reflect the current implementation state.

---

## v5.0.0 (2026-05-10)

### New Features

- Added cross-listing management, normalized term imports, CSLO reporting, system status, VPAT viewing, global term selection, and LTI administration.

### Improvements

- Added the AdminLTE 4 theme, reorganized navigation, two-file encryption, configuration template merging, parameterized queries, configurable table prefixes, and session hardening.

### Security

- Added Argon2id password hashing, configurable emergency admin access, CSRF protection, and AES-256 student data encryption.

---

## v4.x (Legacy)

Prior releases did not maintain formal patch notes. Please refer to git history for changes prior to v5.0.0.
