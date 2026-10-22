<?php
declare(strict_types=1);

// Structural checks for the schema migrations. These run without a database:
// they verify the chain, the file conventions the runner depends on, and that
// fresh installs and upgraded installs are stamped with the same version.

require_once __DIR__ . '/../src/system/includes/migrations.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$source = static fn(string $path): string => (string)file_get_contents(__DIR__ . '/../' . $path);

$dialects = ['mysql' => 'src/system/database/schema.sql', 'sqlsrv' => 'src/system/database/schema_mssql.sql'];
$latest = [];

foreach ($dialects as $driver => $schemaPath) {
    $files = slocloud_migration_files($driver);
    $check($files !== [], "No $driver migrations were found.");
    $latest[$driver] = slocloud_latest_schema_version($driver);

    // Every database without a version stamp starts at the baseline, so the
    // chain from there must reach the newest version with no gaps.
    try {
        $chain = slocloud_migration_chain($driver, SLOCLOUD_SCHEMA_BASELINE, $latest[$driver]);
        $check(count($chain) === count($files), "Some $driver migrations are unreachable from " . SLOCLOUD_SCHEMA_BASELINE . '.');
    } catch (Throwable $e) {
        $check(false, "The $driver migration chain is broken: " . $e->getMessage());
    }

    foreach ($files as $file) {
        $name = basename($file['path']);
        $sql = (string)file_get_contents($file['path']);
        $comments = implode("\n", array_filter(explode("\n", $sql), static fn(string $l): bool => str_starts_with(ltrim($l), '--')));

        // The prefix token is substituted everywhere, including comments.
        $check(!str_contains($comments, SLOCLOUD_SCHEMA_TABLE_TOKEN), "$name mentions the table token in a comment.");
        if ($driver === 'mysql') {
            $check(!str_contains($comments, ';'), "$name has a ';' in a comment, which splits the statement.");
        }

        $statements = slocloud_migration_statements($sql, $driver, 'test_');
        $check($statements !== [], "$name has no statements.");
        $check(!str_contains(implode('', $statements), SLOCLOUD_SCHEMA_TABLE_TOKEN), "$name leaves the table token after prefix substitution.");

        // Stamping the version last means an interrupted run is never recorded as complete.
        $last = (string)end($statements);
        $check(str_contains($last, "'schema_version'") && str_contains($last, "'{$file['to']}'"), "$name does not stamp schema_version {$file['to']} as its final step.");

        $plan = "docs/upgrades/Ver{$file['from']}_to_{$file['to']}.md";
        $check(is_file(__DIR__ . '/../' . $plan), "$name has no upgrade plan at $plan.");
    }

    // A fresh install must land on the same version an upgraded install reaches.
    $schema = $source($schemaPath);
    $check(
        str_contains($schema, "INSERT INTO slocloud_tbl_meta (name, value) VALUES ('schema_version', '{$latest[$driver]}')"),
        "$schemaPath is not stamped with schema version {$latest[$driver]}."
    );
}

$check(count(array_unique($latest)) === 1, 'MySQL and SQL Server migrations lead to different schema versions.');
$check(
    slocloud_migration_files('mysql') !== [] && array_keys(slocloud_migration_files('mysql')) === array_keys(slocloud_migration_files('sqlsrv')),
    'MySQL and SQL Server migrations do not cover the same version steps.'
);

// Both fresh schemas must declare the lifecycle flag on the same tables, since
// the application queries is_active on every lifecycle entity.
$lifecycleTables = static function (string $schema): array {
    preg_match_all('/CREATE TABLE slocloud_tbl_(\w+) \((.*?)\n\)/s', $schema, $tables, PREG_SET_ORDER);
    $withFlag = [];
    foreach ($tables as $table) {
        if (preg_match('/^\s+is_active\s/m', $table[2])) $withFlag[] = $table[1];
    }
    sort($withFlag);
    return $withFlag;
};
$check(
    $lifecycleTables(str_replace("\r\n", "\n", $source($dialects['mysql']))) === $lifecycleTables(str_replace("\r\n", "\n", $source($dialects['sqlsrv']))),
    'MySQL and SQL Server fresh schemas declare is_active on different tables.'
);

$runner = $source('src/system/scripts/apply_migration.php');
$check(str_contains($runner, "PHP_SAPI !== 'cli'"), 'The migration runner is not restricted to CLI execution.');
$check(str_contains($runner, "get('app.maintenance_mode', false) !== true"), 'The migration runner does not require maintenance mode.');
$check(str_contains($runner, 'LOCK_EX | LOCK_NB'), 'The migration runner does not take the student-data lock.');

$catalog = $source('src/system/reporting/ReportCatalogBuilder.php');
$check(str_contains($catalog, "'meta',"), 'The schema version table is exposed to the report catalog.');

if ($failures) {
    fwrite(STDERR, "Migration tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Migration tests passed.\n";
