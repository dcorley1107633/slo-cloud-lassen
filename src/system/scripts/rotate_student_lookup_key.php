#!/usr/bin/env php
<?php
/**
 * Rotate the student C-number lookup key (SEC-008).
 *
 * WHY THIS IS POSSIBLE AT ALL
 * ---------------------------
 * `student_id_hash` is a one-way digest, so it cannot be re-keyed by
 * transforming the stored value. It does not need to be: the same C-number is
 * also stored in `student_data_encrypted`, reversibly, under a *different* key
 * (`student_encryption_key`, which this tool never changes). That encrypted
 * column acts as an escrow for the hash's input, so every digest can simply be
 * recomputed from plaintext under the new key.
 *
 * WHY THERE IS NO KEY-VERSION COLUMN
 * ----------------------------------
 * Recomputation is deterministic and depends only on (plaintext, target key),
 * so this operation is idempotent: running it twice with the same target key
 * produces byte-identical results. An interrupted run therefore self-heals by
 * being re-run with the same key, which is the job a key-ID column would
 * otherwise do. That is what keeps this a data migration rather than a schema
 * change requiring a major-version bump.
 *
 * WHY IT NEEDS A MAINTENANCE WINDOW
 * ---------------------------------
 * Lookups are search-by-recompute: the application hashes an incoming C-number
 * and searches for a match. During the rewrite, stored digests and the key in
 * `key.yaml` are necessarily out of step, so a concurrent CSV import would fail
 * to match existing students and would silently insert duplicates. The window
 * is short (a single UPDATE per student) but it must be exclusive.
 *
 * Usage:
 *   php src/system/scripts/rotate_student_lookup_key.php [options]
 *
 *   --dry-run     Report what would change; write nothing.
 *   --key=<hex>   Use this 64-hex-char key instead of generating one. Required
 *                 when completing an interrupted run (see the recovery file).
 *   --force       Proceed even though maintenance mode is off. Only correct if
 *                 the application is already unreachable by another means.
 *   --yes         Skip the interactive confirmation (for scripted runs).
 *
 * @package SLOCloud\Scripts
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../Core/Config.php';
require_once __DIR__ . '/../Core/Database.php';
require_once __DIR__ . '/../Core/Security.php';
require_once __DIR__ . '/../Core/Encryption.php';
require_once __DIR__ . '/../Core/StudentEncryption.php';

use SLOCloud\Core\Config;
use SLOCloud\Core\Database;
use SLOCloud\Core\Security;
use SLOCloud\Core\StudentEncryption;

/** Rows decrypted, rehashed, and committed per transaction. */
const ROTATION_BATCH_SIZE = 500;

$options = getopt('', ['dry-run', 'key::', 'force', 'yes']);
$isDryRun = array_key_exists('dry-run', $options);
$skipPrompt = array_key_exists('yes', $options);
$force = array_key_exists('force', $options);
$suppliedKey = is_string($options['key'] ?? null) ? strtolower(trim($options['key'])) : '';

function rotation_fail(string $message): never
{
    fwrite(STDERR, "[X] {$message}\n");
    exit(1);
}

function rotation_say(string $message): void
{
    fwrite(STDOUT, $message . "\n");
}

// ---------------------------------------------------------------------------
// Load configuration and connect.
// ---------------------------------------------------------------------------
$configFile = __DIR__ . '/../../config/config.yaml';
if (!is_file($configFile)) {
    rotation_fail('Configuration file not found. Run setup first.');
}

try {
    $configObject = Config::getInstance($configFile);
    $configData = $configObject->allDecrypted();
} catch (Throwable) {
    rotation_fail('Configuration could not be loaded safely.');
}

$dbPrefix = (string)($configData['database']['prefix'] ?? '');

try {
    $db = Database::getInstance($configData['database']);
} catch (Throwable) {
    rotation_fail('Database connection failed.');
}

// Coordinate with web requests that use StudentEncryption. Those requests hold
// a shared lock for their lifetime; rotation requires the exclusive form so it
// cannot begin while an import or student update is still using the old key.
$rotationDirectory = Security::dataRoot(dirname(__DIR__, 2)) . '/var';
$rotationLockFile = $rotationDirectory . '/key_rotation.lock';
$rotationLockFh = @fopen($rotationLockFile, 'c');
if ($rotationLockFh === false || !flock($rotationLockFh, LOCK_EX | LOCK_NB)) {
    if (is_resource($rotationLockFh)) {
        fclose($rotationLockFh);
    }
    rotation_fail('Student data is currently in use or another key rotation is running. Try again shortly.');
}
register_shutdown_function(static function () use ($rotationLockFh): void {
    if (is_resource($rotationLockFh)) {
        flock($rotationLockFh, LOCK_UN);
        fclose($rotationLockFh);
    }
});

// The encryption key is untouched by this tool; it is what makes the plaintext
// recoverable. If it is missing, nothing can be recomputed and we must stop.
try {
    // This worker already owns the exclusive coordination lock above.
    $studentEncryption = new StudentEncryption($configObject, false);
} catch (Throwable $e) {
    rotation_fail('Student encryption is not configured, so plaintext cannot be recovered: ' . $e->getMessage());
}

$currentLookupKey = (string)($configObject->get('security.student_lookup_key') ?? '');

// ---------------------------------------------------------------------------
// Locate key.yaml. This must be the same file the application reads, or the
// rotation would write a key the running site never picks up.
// ---------------------------------------------------------------------------
$keyFile = '';
$environmentKeyPath = getenv('SLOCloud_KEY_PATH');
$candidatePaths = [];
if (is_string($environmentKeyPath) && $environmentKeyPath !== '') {
    $candidatePaths[] = $environmentKeyPath;
}
$candidatePaths[] = dirname($configFile) . '/key.yaml';
$candidatePaths[] = dirname(dirname($configFile)) . '/key.yaml';
foreach ($candidatePaths as $candidate) {
    if (is_file($candidate)) {
        $keyFile = $candidate;
        break;
    }
}
if ($keyFile === '') {
    rotation_fail('key.yaml not found. Nowhere to write the rotated key.');
}

// ---------------------------------------------------------------------------
// Determine the target key.
// ---------------------------------------------------------------------------
if ($suppliedKey !== '') {
    if (!preg_match('/^[0-9a-f]{64}$/', $suppliedKey) || preg_match('/^0{64}$/', $suppliedKey)) {
        rotation_fail('--key must be 64 hexadecimal characters and not all zeroes.');
    }
    $newLookupKey = $suppliedKey;
} else {
    $newLookupKey = bin2hex(random_bytes(32));
}

// Reusing a key across purposes would let compromise of one recompute the
// other; Config rejects this at startup, so refuse here rather than writing a
// key.yaml that will not load.
$encryptionKey = (string)($configObject->get('security.encryption_key') ?? '');
$studentEncryptionKey = (string)($configObject->get('security.student_encryption_key') ?? '');
foreach (['configuration encryption key' => $encryptionKey, 'student encryption key' => $studentEncryptionKey] as $label => $existing) {
    if ($existing !== '' && hash_equals(strtolower($existing), $newLookupKey)) {
        rotation_fail("The target lookup key is identical to the {$label}. Keys must differ per purpose.");
    }
}
if ($currentLookupKey !== '' && hash_equals(strtolower($currentLookupKey), $newLookupKey)) {
    rotation_say('[i] Target key matches the current lookup key. This will recompute every row to the same value.');
    rotation_say('    That is harmless and is the correct way to repair a partially completed run.');
}

// ---------------------------------------------------------------------------
// Safety gate: refuse to run against a live site.
// ---------------------------------------------------------------------------
$maintenanceMode = ($configObject->get('app.maintenance_mode', false) === true);
if (!$maintenanceMode && !$isDryRun && !$force) {
    rotation_fail(
        "Maintenance mode is off.\n"
        . "    While this runs, stored digests and the configured key are out of step, so a\n"
        . "    concurrent CSV import would fail to match existing students and would silently\n"
        . "    insert duplicates. Enable app.maintenance_mode, or pass --force if the site is\n"
        . "    already unreachable by other means."
    );
}

// ---------------------------------------------------------------------------
// Survey the work.
// ---------------------------------------------------------------------------
$rowCountResult = $db->query("SELECT COUNT(*) AS row_count FROM {$dbPrefix}students");
$totalRows = (int)(($rowCountResult ? $rowCountResult->fetch() : ['row_count' => 0])['row_count'] ?? 0);

rotation_say('');
rotation_say('Student lookup key rotation');
rotation_say('---------------------------');
rotation_say('  Key file:      ' . $keyFile);
rotation_say('  Student rows:  ' . $totalRows);
rotation_say('  Current hash:  ' . ($currentLookupKey === '' ? 'unkeyed SHA-256 (legacy)' : 'HMAC-SHA256 (keyed)'));
rotation_say('  After run:     HMAC-SHA256 (keyed)');
rotation_say('  Mode:          ' . ($isDryRun ? 'DRY RUN - no changes written' : 'LIVE'));
rotation_say('');

if ($totalRows === 0) {
    rotation_say('[i] No student rows. Only the key file needs updating.');
}

if (!$isDryRun && !$skipPrompt) {
    rotation_say('This rewrites student_id_hash for every student row and replaces the lookup');
    rotation_say('key in key.yaml. Back up the database and key.yaml before continuing.');
    fwrite(STDOUT, 'Type ROTATE to proceed: ');
    $answer = trim((string)fgets(STDIN));
    if ($answer !== 'ROTATE') {
        rotation_fail('Aborted at operator confirmation.');
    }
    rotation_say('');
}

// ---------------------------------------------------------------------------
// Record the target key before touching any row.
//
// If the run dies midway, the rows are partly rewritten and key.yaml still
// holds the old key. Recovery is to re-run with the SAME target key - so that
// key must survive the crash, or the rewritten rows are unmatchable by anything.
// ---------------------------------------------------------------------------
$recoveryFile = '';
if (!$isDryRun) {
    $recoveryDirectory = Security::dataRoot(dirname(__DIR__, 2)) . '/var/keyrotation';
    if (!is_dir($recoveryDirectory) && !mkdir($recoveryDirectory, 0700, true) && !is_dir($recoveryDirectory)) {
        rotation_fail('Could not create the recovery directory: ' . $recoveryDirectory);
    }
    @chmod($recoveryDirectory, 0700);
    $recoveryFile = $recoveryDirectory . '/pending-' . date('Ymd-His') . '.yaml';
    $recoveryNote = "# Student lookup key rotation in progress, started " . date('c') . "\n"
        . "# If the rotation did not complete, re-run it with this key to finish:\n"
        . "#   php src/system/scripts/rotate_student_lookup_key.php --key=<the value below>\n"
        . "# Delete this file once the rotation has completed successfully.\n"
        . "student_lookup_key: " . $newLookupKey . "\n";
    if (file_put_contents($recoveryFile, $recoveryNote, LOCK_EX) === false) {
        rotation_fail('Could not write the recovery file. Refusing to start without a way to resume.');
    }
    @chmod($recoveryFile, 0600);
    rotation_say('[OK] Target key recorded at ' . $recoveryFile);
}

// ---------------------------------------------------------------------------
// Rewrite the digests.
//
// Primary keys are collected up front so paging does not depend on LIMIT/OFFSET
// syntax, which differs between MySQL and SQL Server.
// ---------------------------------------------------------------------------
$pkResult = $db->query("SELECT students_pk FROM {$dbPrefix}students ORDER BY students_pk");
$studentPks = [];
if ($pkResult) {
    foreach ($pkResult->fetchAll() as $pkRow) {
        $studentPks[] = (int)$pkRow['students_pk'];
    }
}

$processed = 0;
$rewritten = 0;
$unchanged = 0;
$failures = [];

foreach (array_chunk($studentPks, ROTATION_BATCH_SIZE) as $chunk) {
    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
    $rowsResult = $db->query(
        "SELECT students_pk, student_data_encrypted, student_id_hash
         FROM {$dbPrefix}students
         WHERE students_pk IN ({$placeholders})",
        $chunk,
        str_repeat('i', count($chunk))
    );
    $rows = $rowsResult ? $rowsResult->fetchAll() : [];

    if (!$isDryRun) {
        $db->beginTransaction();
    }

    try {
        foreach ($rows as $row) {
            $studentPk = (int)$row['students_pk'];
            $processed++;

            try {
                $plaintext = $studentEncryption->decryptStudentData((string)$row['student_data_encrypted']);
            } catch (Throwable) {
                // Identify the row by primary key only. The C-number is the
                // protected identifier this whole scheme exists to hide; it
                // must not reach a console or a log file.
                $failures[] = $studentPk;
                continue;
            }

            $cNumber = (string)($plaintext['c_number'] ?? '');
            if ($cNumber === '') {
                $failures[] = $studentPk;
                continue;
            }

            $newHash = StudentEncryption::hashCNumberWith($cNumber, $newLookupKey);
            if (hash_equals((string)$row['student_id_hash'], $newHash)) {
                $unchanged++;
                continue;
            }

            if (!$isDryRun) {
                $db->query(
                    "UPDATE {$dbPrefix}students SET student_id_hash = ? WHERE students_pk = ?",
                    [$newHash, $studentPk],
                    'si'
                );
            }
            $rewritten++;
        }

        if (!$isDryRun) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if (!$isDryRun) {
            $db->rollback();
        }
        rotation_fail(
            'Batch failed and was rolled back: ' . $e->getMessage() . "\n"
            . '    Re-run with --key=' . $newLookupKey . ' to resume; the operation is idempotent.'
        );
    }

    rotation_say(sprintf('  ... %d / %d rows processed', $processed, $totalRows));
}

if ($failures !== []) {
    rotation_say('');
    rotation_say('[!] ' . count($failures) . ' row(s) could not be decrypted and were left unchanged.');
    rotation_say('    students_pk: ' . implode(', ', array_slice($failures, 0, 50)) . (count($failures) > 50 ? ' ...' : ''));
    rotation_say('    These rows keep their old digest and will not match on import after the key');
    rotation_say('    is swapped. Resolve them before completing the rotation.');
    if (!$isDryRun) {
        rotation_fail('Refusing to swap the key while rows remain unconvertible.');
    }
}

// ---------------------------------------------------------------------------
// Verify before committing to the new key.
// ---------------------------------------------------------------------------
if (!$isDryRun && $totalRows > 0) {
    $duplicateResult = $db->query(
        "SELECT COUNT(*) AS collisions FROM (
            SELECT student_id_hash FROM {$dbPrefix}students
            GROUP BY student_id_hash HAVING COUNT(*) > 1
         ) AS duplicated"
    );
    $collisions = (int)(($duplicateResult ? $duplicateResult->fetch() : ['collisions' => 0])['collisions'] ?? 0);
    if ($collisions > 0) {
        rotation_fail(
            "Verification failed: {$collisions} duplicate lookup value(s) after rewrite.\n"
            . '    The key has NOT been swapped. Restore from backup and investigate.'
        );
    }
    rotation_say('[OK] Verified: all lookup values are distinct.');
}

// ---------------------------------------------------------------------------
// Swap the key. Last step, so a failure anywhere above leaves key.yaml valid
// for the digests that are actually stored.
// ---------------------------------------------------------------------------
if ($isDryRun) {
    rotation_say('');
    rotation_say(sprintf(
        'Dry run complete: %d row(s) would be rewritten, %d already correct, %d unconvertible.',
        $rewritten,
        $unchanged,
        count($failures)
    ));
    rotation_say('No changes were written and key.yaml was not touched.');
    exit(0);
}

$keyContents = (string)file_get_contents($keyFile);
$keyLine = 'student_lookup_key: ' . $newLookupKey;
if (preg_match('/^student_lookup_key:.*$/m', $keyContents)) {
    $updatedContents = preg_replace('/^student_lookup_key:.*$/m', $keyLine, $keyContents, 1);
} else {
    // First-time adoption on an install that had no lookup key.
    $updatedContents = rtrim($keyContents, "\r\n") . "\n\n"
        . "# Student C-number lookup key (SEC-008). Added by rotate_student_lookup_key.php.\n"
        . "# Do not edit by hand: every stored student_id_hash was computed under this exact\n"
        . "# value, and changing it without re-running the rotation tool makes existing\n"
        . "# students stop matching on import, silently creating duplicates.\n"
        . $keyLine . "\n";
}

if (!is_string($updatedContents)) {
    rotation_fail('Failed to build the updated key file contents. Rows were rewritten; re-run with --key=' . $newLookupKey);
}

$keyBackup = $keyFile . '.bak-' . date('Ymd-His');
if (!@copy($keyFile, $keyBackup)) {
    rotation_fail('Could not back up key.yaml. Rows were rewritten; re-run with --key=' . $newLookupKey);
}
@chmod($keyBackup, 0600);

$temporaryKeyFile = tempnam(dirname($keyFile), '.key-');
if ($temporaryKeyFile === false || file_put_contents($temporaryKeyFile, $updatedContents, LOCK_EX) === false) {
    if (is_string($temporaryKeyFile)) {
        @unlink($temporaryKeyFile);
    }
    rotation_fail('Could not stage the new key file. Rows were rewritten; re-run with --key=' . $newLookupKey);
}
@chmod($temporaryKeyFile, 0600);
if (!@rename($temporaryKeyFile, $keyFile)) {
    @unlink($temporaryKeyFile);
    rotation_fail('Could not replace key.yaml atomically. Rows were rewritten; re-run with --key=' . $newLookupKey);
}
@chmod($keyFile, 0600);

if ($recoveryFile !== '' && is_file($recoveryFile)) {
    @unlink($recoveryFile);
}

rotation_say('');
rotation_say('[OK] Rotation complete.');
rotation_say(sprintf('     %d row(s) rewritten, %d already correct.', $rewritten, $unchanged));
rotation_say('     Previous key file backed up to ' . $keyBackup);
rotation_say('');
rotation_say('Next steps:');
rotation_say('  1. Turn off app.maintenance_mode.');
rotation_say('  2. Run a small CSV import of known-existing students and confirm they MATCH');
rotation_say('     rather than being inserted as new records.');
rotation_say('  3. Once satisfied, delete the key backup: ' . $keyBackup);
exit(0);
