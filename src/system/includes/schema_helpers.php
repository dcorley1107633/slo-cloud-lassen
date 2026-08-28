<?php
declare(strict_types=1);

/**
 * Schema helpers - table-prefix substitution for DDL files.
 *
 * schema.sql / schema_mssql.sql (and every migrations/VerX_to_VerY*.sql file)
 * are authored with the literal token below as the table-name prefix. At apply
 * time the token is replaced with the install's configured database.prefix
 * (an empty prefix strips the token entirely).
 *
 * The token intentionally equals the default prefix for new installs, so in
 * the common case the substitution is an identity operation and the DDL files
 * read exactly like a default install's database.
 *
 * Used by the setup wizard and the DB patcher CLI. Keep this the ONLY place
 * that performs the substitution so the two can never drift.
 *
 * SINGLE-TOKEN INVARIANT: there is exactly one token, and no configured
 * prefix may embed it (other than being exactly equal to it). This keeps the
 * substitution idempotent and chained/nested prefixes impossible - both this
 * helper and the setup wizard's validation reject offending prefixes.
 *
 * Notes:
 * - Plain str_replace: the token must not appear inside comments or string
 *   literals in the DDL files, or it will be rewritten there too.
 * - Apply to raw DDL as read from disk. Under the invariant the substitution
 *   is idempotent: identity for the default prefix, and for any other allowed
 *   prefix the token no longer exists after the first pass.
 */

/** Literal table-name token used in the DDL files. NOT the configured prefix. */
const SLOCLOUD_SCHEMA_TABLE_TOKEN = 'slocloud_tbl_';

/**
 * Replace the DDL table-name token with the configured table prefix.
 *
 * @param string $sql    DDL content authored with SLOCLOUD_SCHEMA_TABLE_TOKEN
 * @param string $prefix Configured database.prefix ('' strips the token)
 */
function slocloud_apply_table_prefix(string $sql, string $prefix): string
{
    if ($prefix === SLOCLOUD_SCHEMA_TABLE_TOKEN) {
        return $sql;
    }
    if (str_contains($prefix, SLOCLOUD_SCHEMA_TABLE_TOKEN)) {
        throw new RuntimeException(
            'Table prefix must not embed the schema token (' . SLOCLOUD_SCHEMA_TABLE_TOKEN
            . '). Use it exactly, or choose a prefix that does not contain it.'
        );
    }
    return str_replace(SLOCLOUD_SCHEMA_TABLE_TOKEN, $prefix, $sql);
}
