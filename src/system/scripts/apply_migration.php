#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Apply pending schema migrations to the configured database.
 *
 * Reads the database's schema version from the {prefix}meta table (a database
 * without one is treated as 5.1.0, the last unversioned schema), then applies
 * each migration in src/system/database/migrations/ in order until the schema
 * reaches the target version. Each file stamps its own version as its final
 * step, so an interrupted run resumes from the last file that completed.
 *
 * Read the upgrade plan for every step first: docs/upgrades/README.md.
 *
 * Usage:
 *   php src/system/scripts/apply_migration.php [options]
 *
 *   --print           Print the pending SQL (with the table prefix applied) and
 *                     exit. Changes nothing, and does not need maintenance mode.
 *   --to=<version>    Stop at this schema version instead of the newest.
 *   --from=<version>  Treat the database as being at this version. Only for a
 *                     database whose version stamp is missing or wrong.
 *   --yes             Skip the interactive confirmation (for scripted runs).
 *
 * @package SLOCloud\Scripts
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../Core/Config.php';
require_once __DIR__ . '/../Core/Database.php';
require_once __DIR__ . '/../Core/Security.php';
require_once __DIR__ . '/../includes/migrations.php';

use SLOCloud\Core\Config;
use SLOCloud\Core\Database;
use SLOCloud\Core\Security;

$options = getopt('', ['print', 'to:', 'from:', 'yes']);
$printOnly = array_key_exists('print', $options);
$skipPrompt = array_key_exists('yes', $options);

function migration_fail(string $message): never
{
    fwrite(STDERR, "[X] {$message}\n");
    exit(1);
}

function migration_say(string $message): void
{
    fwrite(STDOUT, $message . "\n");
}

foreach (['to', 'from'] as $option) {
    if (isset($options[$option]) && preg_match('/^\d+\.\d+\.\d+$/', (string)$options[$option]) !== 1) {
        migration_fail("--{$option} must be a version such as 5.2.1.");
    }
}

// ---------------------------------------------------------------------------
// Load configuration and connect.
// ---------------------------------------------------------------------------
$configFile = __DIR__ . '/../../config/config.yaml';
if (!is_file($configFile)) {
    migration_fail('Configuration file not found. Run setup first.');
}
try {
    $configObject = Config::getInstance($configFile);
    $configData = $configObject->allDecrypted();
} catch (Throwable) {
    migration_fail('Configuration could not be loaded safely.');
}

$prefix = (string)($configData['database']['prefix'] ?? '');
if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
    migration_fail('Invalid database table prefix.');
}
$driver = (string)($configData['database']['driver'] ?? 'mysql');

try {
    $db = Database::getInstance($configData['database']);
    $pdo = $db->getConnection();
} catch (Throwable) {
    migration_fail('Database connection failed.');
}

// ---------------------------------------------------------------------------
// Work out what is pending.
// ---------------------------------------------------------------------------
$current = $options['from'] ?? null;
if ($current === null) {
    try {
        $current = (string)$pdo->query("SELECT value FROM {$prefix}meta WHERE name = 'schema_version'")->fetchColumn();
    } catch (Throwable) {
        $current = '';
    }
    if ($current === '') {
        $current = SLOCLOUD_SCHEMA_BASELINE;
        migration_say("[i] No schema version recorded. Treating the database as {$current} (the last unversioned schema).");
    }
}
$target = $options['to'] ?? slocloud_latest_schema_version($driver);

try {
    $chain = slocloud_migration_chain($driver, $current, $target);
} catch (Throwable $e) {
    migration_fail($e->getMessage());
}

migration_say("Schema version: {$current}  Target: {$target}  Driver: {$driver}  Prefix: " . ($prefix === '' ? '(none)' : $prefix));
if ($chain === []) {
    migration_say('[OK] The schema is already at the target version. Nothing to do.');
    exit(0);
}
foreach ($chain as $step) {
    migration_say("  pending: " . basename($step['path']) . "  (see docs/upgrades/Ver{$step['from']}_to_{$step['to']}.md)");
}

if ($printOnly) {
    foreach ($chain as $step) {
        migration_say("\n-- ===== " . basename($step['path']) . " =====");
        $separator = slocloud_is_sqlserver($driver) ? "\nGO\n" : ";\n";
        migration_say(implode($separator, slocloud_migration_statements((string)file_get_contents($step['path']), $driver, $prefix)) . $separator);
    }
    exit(0);
}

// ---------------------------------------------------------------------------
// Safety gates: maintenance mode, the student-data lock, and confirmation.
// ---------------------------------------------------------------------------
if ($configObject->get('app.maintenance_mode', false) !== true) {
    migration_fail("Maintenance mode is off. Enable app.maintenance_mode before changing the schema:\n"
        . '    requests running during a migration can read or write half-converted tables.');
}

// Imports and student updates hold this lock while they run. Holding it
// exclusively keeps any in-flight request from overlapping the migration.
$lockFile = Security::dataRoot(dirname(__DIR__, 2)) . '/var/key_rotation.lock';
$lockFh = @fopen($lockFile, 'c');
if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
    migration_fail('Student data is currently in use or a key rotation is running. Try again shortly.');
}

if (!$skipPrompt) {
    migration_say("\nThis changes the database schema. MySQL cannot roll back schema changes, so the");
    migration_say('only undo is restoring the backup you took for this upgrade.');
    fwrite(STDOUT, 'Type MIGRATE to proceed: ');
    if (trim((string)fgets(STDIN)) !== 'MIGRATE') {
        migration_fail('Cancelled. Nothing was changed.');
    }
}

// ---------------------------------------------------------------------------
// Apply each file in order, stopping at the first failure.
// ---------------------------------------------------------------------------
foreach ($chain as $step) {
    $name = basename($step['path']);
    $statements = slocloud_migration_statements((string)file_get_contents($step['path']), $driver, $prefix);
    migration_say("\nApplying {$name} (" . count($statements) . ' statements)...');
    foreach ($statements as $index => $statement) {
        try {
            $pdo->exec($statement);
        } catch (Throwable $e) {
            $firstLine = strtok(ltrim(preg_replace('/^\s*--.*$/m', '', $statement) ?? $statement), "\n");
            migration_fail("{$name} failed at statement " . ($index + 1) . ' of ' . count($statements) . ":\n"
                . "    {$firstLine}\n"
                . '    ' . $e->getMessage() . "\n"
                . "    The schema version was not advanced past {$step['from']}. Fix the cause and run this\n"
                . '    command again: every step checks the current shape first, so it is safe to re-run.');
        }
    }
    migration_say("[OK] {$name} applied. Schema version is now {$step['to']}.");
}

flock($lockFh, LOCK_UN);
fclose($lockFh);
migration_say("\n[OK] Schema is at {$target}. Follow the verification steps in the upgrade plan before");
migration_say('     turning maintenance mode off.');
