<?php
declare(strict_types=1);

/**
 * Schema migration helpers - locating the migration chain and splitting files
 * into executable statements. Used by src/system/scripts/apply_migration.php
 * and by the tests, so both read the migration files the same way.
 *
 * Files live in src/system/database/migrations/ and are named
 * Ver<from>_to_<to>.sql (MySQL) and Ver<from>_to_<to>_mssql.sql (SQL Server).
 * A file exists only for a release that changed the schema, and the chain must
 * be unbroken: each file's <to> is the next file's <from>.
 * See docs/UPDATE_WIZARD_DESIGN.md and docs/upgrades/README.md.
 */

require_once __DIR__ . '/schema_helpers.php';

/** Schema version assumed when a database has no version stamp yet. */
const SLOCLOUD_SCHEMA_BASELINE = '5.1.0';

function slocloud_migrations_dir(): string
{
    return dirname(__DIR__) . '/database/migrations';
}

function slocloud_is_sqlserver(string $driver): bool
{
    return $driver === 'mssql' || $driver === 'sqlsrv';
}

/**
 * Every migration for the driver's dialect, keyed by from-version.
 *
 * @return array<string, array{from: string, to: string, path: string}>
 */
function slocloud_migration_files(string $driver, ?string $dir = null): array
{
    $dir ??= slocloud_migrations_dir();
    $suffix = slocloud_is_sqlserver($driver) ? '_mssql' : '';
    $files = [];
    foreach (glob($dir . '/Ver*.sql') ?: [] as $path) {
        if (preg_match('/^Ver(\d+\.\d+\.\d+)_to_(\d+\.\d+\.\d+)' . $suffix . '\.sql$/', basename($path), $m) !== 1) {
            continue;
        }
        if (isset($files[$m[1]])) {
            throw new RuntimeException("Two migrations start at {$m[1]}: " . basename($files[$m[1]]['path']) . ' and ' . basename($path));
        }
        $files[$m[1]] = ['from' => $m[1], 'to' => $m[2], 'path' => $path];
    }
    return $files;
}

/** The newest schema version the migration files lead to. */
function slocloud_latest_schema_version(string $driver, ?string $dir = null): string
{
    $latest = SLOCLOUD_SCHEMA_BASELINE;
    foreach (slocloud_migration_files($driver, $dir) as $file) {
        if (version_compare($file['to'], $latest, '>')) {
            $latest = $file['to'];
        }
    }
    return $latest;
}

/**
 * Ordered migrations that take a database from $current to $target.
 *
 * @return list<array{from: string, to: string, path: string}>
 */
function slocloud_migration_chain(string $driver, string $current, string $target, ?string $dir = null): array
{
    $files = slocloud_migration_files($driver, $dir);
    $chain = [];
    $version = $current;
    while (version_compare($version, $target, '<')) {
        if (!isset($files[$version])) {
            throw new RuntimeException("No migration starts at schema version {$version}; cannot reach {$target}.");
        }
        $chain[] = $files[$version];
        $version = $files[$version]['to'];
    }
    if ($version !== $target) {
        throw new RuntimeException("Migrations from {$current} step past {$target} (to {$version}).");
    }
    return $chain;
}

/**
 * Split a migration file into statements (MySQL) or batches (SQL Server),
 * after substituting the configured table prefix.
 *
 * MySQL files keep ';' out of comments and string literals, so full-line
 * comments are dropped and the rest is split on ';'. SQL Server files are
 * split on GO lines, as SQL Server tools do.
 *
 * @return list<string>
 */
function slocloud_migration_statements(string $sql, string $driver, string $prefix): array
{
    $sql = str_replace("\r\n", "\n", slocloud_apply_table_prefix($sql, $prefix));
    if (slocloud_is_sqlserver($driver)) {
        $parts = preg_split('/^\s*GO\s*$/im', $sql) ?: [];
    } else {
        $lines = array_filter(explode("\n", $sql), static fn(string $line): bool => !str_starts_with(ltrim($line), '--'));
        $parts = explode(';', implode("\n", $lines));
    }
    $statements = [];
    foreach ($parts as $part) {
        $code = implode("\n", array_filter(
            explode("\n", trim($part)),
            static fn(string $line): bool => !str_starts_with(ltrim($line), '--')
        ));
        if (trim($code) !== '') {
            $statements[] = trim($part);
        }
    }
    return $statements;
}
