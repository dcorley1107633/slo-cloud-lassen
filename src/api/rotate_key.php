<?php
declare(strict_types=1);

/**
 * Key Rotation API
 *
 * Rotates encryption keys stored in key.yaml and re-encrypts dependent data.
 *
 * SECURITY REQUIREMENTS:
 *   - Active admin session (user with 'admin' role in the database)
 *   - Maintenance mode enabled (applies to every key type)
 *   - Emergency admin (break-glass, user_id = 0) is explicitly blocked
 *   - Valid CSRF token from the current session
 *   - Exclusive file lock acquired before any write; concurrent requests are rejected
 *
 * POST params:
 *   key_type    - 'config', 'student', or 'lookup'
 *   csrf_token  - CSRF token from session
 *
 * Response JSON:
 *   {
 *     "success": bool,
 *     "steps": [{"name": string, "status": "ok"|"fail", "message": string}]
 *   }
 *
 * Config key rotation (steps):
 *   1. Verify admin role and maintenance mode
 *   2. Acquire exclusive file lock
 *   3. Resolve key.yaml path (respects SLOCloud_KEY_PATH env var)
 *   4. Load and validate current encryption_key
 *   5. Read config.yaml
 *   6. Decrypt all 'ENC:...' values with the old key
 *   7. Generate a new encryption_key
 *   8. Re-encrypt all values with the new key
 *   9. Atomically write new config.yaml (tempnam + rename)
 *  10. Atomically write new key.yaml (tempnam + rename); rolls back config.yaml on failure
 *  11. Clear session config cache
 *  12. Release file lock
 *
 * Student key rotation (steps):
 *   1. Verify admin role and maintenance mode
 *   2. Acquire exclusive file lock
 *   3. Resolve key.yaml path
 *   4. Load and validate current student_encryption_key
 *   5. Count encrypted student records
 *   6. Generate a new student_encryption_key
 *   7. Decrypt and re-encrypt all records in memory, commit in one DB transaction
 *   8. Atomically write new key.yaml (only after DB commit)
 *   9. Clear session config cache
 *  10. Release file lock
 *
 * Student lookup key adoption/rotation (steps):
 *   1. Verify admin role and maintenance mode
 *   2. Acquire exclusive file lock
 *   3. Resolve key.yaml and load the student data encryption key
 *   4. Generate a new student_lookup_key (the previous lookup key is not needed)
 *   5. Decrypt every student ID and compute its new keyed lookup digest in memory
 *   6. Verify all replacement digests are distinct
 *   7. Update every digest in one database transaction
 *   8. Atomically add or replace student_lookup_key in key.yaml
 *   9. Commit the database transaction, rolling key.yaml back if commit fails
 *  10. Clear session config cache and release the file lock
 *
 * @package SLOCloud\Api
 */

require_once __DIR__ . '/../system/includes/init.php';

header('Content-Type: application/json');
// Prevent response caching for this sensitive endpoint
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$clientIp  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$actorId   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$actorName = $_SESSION['user_email'] ?? 'unknown';

// ============================================================
// AUTHENTICATION: must have a positive user_id (not 0 / empty)
// ============================================================
// user_id = 0 is the emergency admin (break-glass). That account is for
// database recovery only and must not perform key rotation operations.
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    $logger->security('KEY_ROTATION_DENIED', 'Key rotation rejected: not authenticated', null, 'anonymous', 'warning', true, ['ip' => $clientIp]);
    echo json_encode([
        'success' => false,
        'steps'   => [['name' => 'Authenticate', 'status' => 'fail', 'message' => 'Not authenticated']]
    ]);
    exit;
}

if (!empty($_SESSION['is_emergency_admin'])) {
    http_response_code(403);
    $logger->security('KEY_ROTATION_DENIED', 'Key rotation rejected: emergency admin account not permitted', 0, $actorName, 'warning', false, ['ip' => $clientIp]);
    echo json_encode([
        'success' => false,
        'steps'   => [['name' => 'Authenticate', 'status' => 'fail', 'message' => 'Key rotation is not available for the emergency admin account']]
    ]);
    exit;
}

// ============================================================
// METHOD CHECK
// ============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'steps' => []]);
    exit;
}

// ============================================================
// CSRF VALIDATION
// ============================================================
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    http_response_code(403);
    $logger->security('KEY_ROTATION_DENIED', 'Key rotation rejected: invalid CSRF token', $actorId, $actorName, 'warning', false, ['ip' => $clientIp]);
    echo json_encode([
        'success' => false,
        'steps'   => [['name' => 'CSRF check', 'status' => 'fail', 'message' => 'Invalid CSRF token']]
    ]);
    exit;
}

// ============================================================
// INPUT VALIDATION
// ============================================================
$keyType = $_POST['key_type'] ?? '';
if (!in_array($keyType, ['config', 'student', 'lookup'], true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'steps'   => [['name' => 'Validate input', 'status' => 'fail', 'message' => 'Invalid key_type - must be config, student, or lookup']]
    ]);
    exit;
}

// ============================================================
// AUTHORIZATION: require 'admin' role
// ============================================================
$isAdmin = false;
try {
    $roleResult = $db->query(
        "SELECT COUNT(1) as cnt FROM {$dbPrefix}user_roles ur
         INNER JOIN {$dbPrefix}roles r ON r.roles_pk = ur.role_fk
         WHERE ur.user_fk = ? AND r.role_name = 'admin'",
        [$actorId],
        'i'
    );
    $row = $roleResult ? $roleResult->fetch() : null;
    $isAdmin = $row && (int)$row['cnt'] > 0;
} catch (\Exception $e) {
    // DB failure during role check - deny and log
    $logger->security('KEY_ROTATION_DENIED', 'Key rotation rejected: role check failed', $actorId, $actorName, 'critical', false, ['ip' => $clientIp]);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'steps'   => [['name' => 'Verify role', 'status' => 'fail', 'message' => 'Could not verify role - try again']]
    ]);
    exit;
}

if (!$isAdmin) {
    http_response_code(403);
    $logger->security('KEY_ROTATION_DENIED', 'Key rotation rejected: insufficient role (admin required)', $actorId, $actorName, 'warning', false, ['ip' => $clientIp]);
    echo json_encode([
        'success' => false,
        'steps'   => [['name' => 'Verify role', 'status' => 'fail', 'message' => 'Admin role required to rotate encryption keys']]
    ]);
    exit;
}

// Every rotation rewrites stored data and key.yaml as one logical unit.
// Maintenance mode keeps users, imports, and manual student changes out of
// the old-key/new-key transition.
if (!MAINTENANCE_MODE) {
    http_response_code(409);
    $logger->security('KEY_ROTATION_DENIED', "Key rotation ({$keyType}) rejected: maintenance mode is off", $actorId, $actorName, 'warning', false, ['ip' => $clientIp]);
    echo json_encode([
        'success' => false,
        'steps'   => [[
            'name' => 'Verify maintenance mode',
            'status' => 'fail',
            'message' => 'Enable Maintenance Mode in configuration before rotating encryption keys'
        ]]
    ]);
    exit;
}

// ============================================================
// HELPERS
// ============================================================
require_once __DIR__ . '/../system/Core/Encryption.php';

$steps = [];

$addStep = function (string $name, string $status, string $message) use (&$steps): void {
    $steps[] = ['name' => $name, 'status' => $status, 'message' => $message];
};

/**
 * Resolve key.yaml path using the same search order the application reads it
 * with (Config::keyPathCandidates()).
 *
 * @return string|null Resolved absolute path, or null if not found
 */
$resolveKeyPath = function () use ($config): ?string {
    foreach ($config->keyPathCandidates() as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    return null;
};

/**
 * Atomically write $content to $destPath using a tempnam-based temp file.
 * Returns null on success, or an error string on failure.
 * On failure also attempts to remove the temp file.
 */
$atomicWrite = function (string $destPath, string $content) use ($logger, $actorId, $actorName, $clientIp): ?string {
    $dir     = dirname($destPath);
    $tmpPath = tempnam($dir, '.slocloud_rot_');
    if ($tmpPath === false) {
        $logger->warning('Key rotation: tempnam() failed', ['dir' => $dir, 'user_id' => $actorId, 'ip' => $clientIp]);
        return 'Could not create temporary file in config directory - check directory permissions';
    }

    if (file_put_contents($tmpPath, $content) === false) {
        @unlink($tmpPath);
        return 'Could not write temporary file - check directory permissions';
    }

    if (!rename($tmpPath, $destPath)) {
        @unlink($tmpPath);
        return 'Could not replace target file - check directory permissions';
    }

    return null;
};

$configFile = __DIR__ . '/../config/config.yaml';
$backupDir  = __DIR__ . '/../config/backups';

/**
 * Create a timestamped zip of key.yaml and config.yaml in the backups directory.
 * Also ensures the directory exists and has an .htaccess denying web access.
 * Returns null on success (sets $backupZipName), or an error string on failure.
 */
$backupZipName = '';
$createBackup  = function () use ($backupDir, &$keyFile, $configFile, $logger, $actorId, $clientIp, &$backupZipName): ?string {
    // Backups hold a copy of the keys, so they stay beside key.yaml: outside
    // the web root whenever the key is.
    if (is_string($keyFile) && $keyFile !== '') {
        $backupDir = dirname($keyFile) . '/backups';
    }
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true)) {
        return 'Could not create backup directory - check permissions';
    }

    // Block direct web access to the backup directory
    // Note: src/config/.htaccess already denies access to the backups/ subdirectory.
    // The index.php guard below is a defence-in-depth fallback.

    // Block PHP include/require access via an index guard
    $indexGuard = $backupDir . '/index.php';
    if (!file_exists($indexGuard)) {
        file_put_contents($indexGuard, "<?php http_response_code(403); exit;\n");
    }

    $stamp   = date('Ymd_His');
    $subDir  = $backupDir . '/key_backup_' . $stamp;

    if (!mkdir($subDir, 0700, true)) {
        return 'Could not create backup subdirectory - check permissions';
    }

    $copied = 0;
    foreach ([['src' => $keyFile, 'dest' => $subDir . '/key.yaml'], ['src' => $configFile, 'dest' => $subDir . '/config.yaml']] as $f) {
        if (file_exists($f['src'])) {
            if (!copy($f['src'], $f['dest'])) {
                return 'Could not copy ' . basename($f['src']) . ' to backup directory - check permissions';
            }
            chmod($f['dest'], 0600);
            $copied++;
        }
    }

    if ($copied === 0) {
        return 'No files found to back up';
    }

    $backupZipName = basename($subDir); // reuse this variable as the backup folder name
    return null;
};

// ============================================================
// EXCLUSIVE FILE LOCK (prevents concurrent rotation)
// ============================================================
$lockDirectory = $applicationDataRoot . '/var';
$lockFile = $lockDirectory . '/key_rotation.lock';
$lockFh   = @fopen($lockFile, 'c');
if ($lockFh === false) {
    $addStep('Acquire lock', 'fail', 'Could not open lock file - check directory permissions');
    echo json_encode(['success' => false, 'steps' => $steps]);
    exit;
}
if (!flock($lockFh, LOCK_EX | LOCK_NB)) {
    fclose($lockFh);
    $logger->warning('Key rotation blocked: concurrent request', ['key_type' => $keyType, 'user_id' => $actorId, 'ip' => $clientIp]);
    $addStep('Acquire lock', 'fail', 'Student data is currently in use or another key rotation is running - please wait and try again');
    echo json_encode(['success' => false, 'steps' => $steps]);
    exit;
}
$addStep('Acquire lock', 'ok', 'Exclusive rotation lock acquired');

// ============================================================
// RESOLVE key.yaml PATH
// ============================================================
$keyFile = $resolveKeyPath();
if ($keyFile === null) {
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    $addStep('Resolve key.yaml', 'fail', 'key.yaml not found in any expected location (check SLOCloud_KEY_PATH)');
    echo json_encode(['success' => false, 'steps' => $steps]);
    exit;
}

$keyContent = file_get_contents($keyFile);
if ($keyContent === false) {
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    $addStep('Resolve key.yaml', 'fail', 'Cannot read key.yaml - check file permissions');
    echo json_encode(['success' => false, 'steps' => $steps]);
    exit;
}
$addStep('Resolve key.yaml', 'ok', 'key.yaml located and loaded');

// Ensure lock file is always released even on unexpected exit
register_shutdown_function(function () use ($lockFh): void {
    if (is_resource($lockFh)) {
        flock($lockFh, LOCK_UN);
        fclose($lockFh);
    }
});

// ============================================================
// BACKUP key.yaml AND config.yaml BEFORE ANY MODIFICATION
// ============================================================
$backupErr = $createBackup();
if ($backupErr !== null) {
    flock($lockFh, LOCK_UN);
    fclose($lockFh);
    $logger->security('KEY_ROTATION_FAILED', 'Key rotation aborted: pre-rotation backup failed: ' . $backupErr, $actorId, $actorName, 'warning', false, ['ip' => $clientIp]);
    $addStep('Backup files', 'fail', 'Pre-rotation backup failed: ' . $backupErr);
    echo json_encode(['success' => false, 'steps' => $steps]);
    exit;
}

$addStep('Backup files', 'ok', 'Pre-rotation backup saved: ' . $backupZipName);

// ============================================================
// CONFIG ENCRYPTION KEY ROTATION
// ============================================================
if ($keyType === 'config') {

    // Step 1: Extract current encryption_key
    if (!preg_match('/^encryption_key:\s*([0-9a-f]{64})\s*$/im', $keyContent, $m)) {
        $addStep('Load current key', 'fail', 'encryption_key not found or invalid format in key.yaml');
        $logger->security('KEY_ROTATION_FAILED', 'Config key rotation failed: key parse error', $actorId, $actorName, 'critical', false, ['ip' => $clientIp]);
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }
    $oldConfigKey = $m[1];
    $addStep('Load current key', 'ok', 'Config encryption_key loaded from key.yaml');

    // Step 2: Read config.yaml
    if (!file_exists($configFile)) {
        $addStep('Read config.yaml', 'fail', 'config.yaml not found');
        $logger->security('KEY_ROTATION_FAILED', 'Config key rotation failed: config.yaml not found', $actorId, $actorName, 'critical', false, ['ip' => $clientIp]);
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }
    $configContent = file_get_contents($configFile);
    if ($configContent === false) {
        $addStep('Read config.yaml', 'fail', 'Cannot read config.yaml - check file permissions');
        $logger->security('KEY_ROTATION_FAILED', 'Config key rotation failed: cannot read config.yaml', $actorId, $actorName, 'critical', false, ['ip' => $clientIp]);
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }
    $addStep('Read config.yaml', 'ok', 'Configuration file loaded');

    // Step 3: Decrypt all ENC: values with old key
    try {
        $oldEncryption = new \SLOCloud\Core\Encryption($oldConfigKey);

        // yaml_emit (setup, System > Configuration) writes ENC values unquoted;
        // hand-edited files may quote them. Matching only 'ENC:...' found nothing
        // in an emitted file, wrote the new key anyway, and left every encrypted
        // setting undecryptable.
        preg_match_all('/([\'"]?)ENC:([A-Za-z0-9+\/=]+)\1/', $configContent, $matches, PREG_SET_ORDER);

        $decrypted = [];
        foreach ($matches as $match) {
            $fullMatch = $match[0]; // ENC:... with its quotes, if any
            $encValue  = $match[2]; // base64 payload
            $plaintext = $oldEncryption->decrypt($encValue);
            $decrypted[] = ['original' => $fullMatch, 'quote' => $match[1], 'plaintext' => $plaintext];
        }

        $count = count($decrypted);
        if ($count !== substr_count($configContent, 'ENC:')) {
            throw new \RuntimeException("Found $count readable ENC values but " . substr_count($configContent, 'ENC:') . ' ENC markers in config.yaml');
        }
        $addStep('Decrypt encrypted values', 'ok', "Decrypted $count encrypted value(s) successfully");
    } catch (\Exception $e) {
        // Log full detail server-side; return generic message to client
        $logger->security('KEY_ROTATION_FAILED', 'Config key rotation failed during decryption: ' . $e->getMessage(), $actorId, $actorName, 'critical', false, ['ip' => $clientIp]);
        $addStep('Decrypt encrypted values', 'fail', 'Decryption failed - ensure the current key in key.yaml is correct');
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }

    // Step 4: Generate new config key
    try {
        $newConfigKey = bin2hex(random_bytes(32));
        $addStep('Generate new key', 'ok', 'New 256-bit config encryption key generated');
    } catch (\Exception $e) {
        $logger->security('KEY_ROTATION_FAILED', 'Config key rotation failed during key generation: ' . $e->getMessage(), $actorId, $actorName, 'critical', false, ['ip' => $clientIp]);
        $addStep('Generate new key', 'fail', 'Key generation failed - check server entropy source');
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }

    // Step 5: Re-encrypt all values with the new key
    // AES-256-GCM uses a random 96-bit IV per call, so two encryptions of the
    // same plaintext produce different ciphertexts. Each $item['original'] is
    // therefore unique in the file, making str_replace safe here.
    try {
        $newEncryption    = new \SLOCloud\Core\Encryption($newConfigKey);
        $newConfigContent = $configContent;

        foreach ($decrypted as $item) {
            $newEncValue     = $newEncryption->encrypt($item['plaintext']);
            $newQuotedValue  = $item['quote'] . 'ENC:' . $newEncValue . $item['quote'];
            $newConfigContent = str_replace($item['original'], $newQuotedValue, $newConfigContent);
        }

        $addStep('Re-encrypt values', 'ok', 'All values re-encrypted with the new key');
    } catch (\Exception $e) {
        $logger->security('KEY_ROTATION_FAILED', 'Config key rotation failed during re-encryption: ' . $e->getMessage(), $actorId, $actorName, 'critical', false, ['ip' => $clientIp]);
        $addStep('Re-encrypt values', 'fail', 'Re-encryption failed - no files have been modified');
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }

    // Step 6: Atomically write new config.yaml
    $writeErr = $atomicWrite($configFile, $newConfigContent);
    if ($writeErr !== null) {
        $logger->security('KEY_ROTATION_FAILED', 'Config key rotation failed writing config.yaml', $actorId, $actorName, 'critical', false, ['ip' => $clientIp]);
        $addStep('Write config.yaml', 'fail', $writeErr);
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }
    $addStep('Write config.yaml', 'ok', 'config.yaml updated with re-encrypted values');

    // Step 7: Atomically write new key.yaml; roll back config.yaml on failure
    $newKeyContent = preg_replace(
        '/^(encryption_key:\s*)[0-9a-f]{64}/im',
        '${1}' . $newConfigKey,
        $keyContent
    );

    $writeErr = $atomicWrite($keyFile, $newKeyContent);
    if ($writeErr !== null) {
        // Roll back config.yaml to its original content
        $rollbackErr = $atomicWrite($configFile, $configContent);
        $logger->security('KEY_ROTATION_FAILED', 'Config key rotation failed writing key.yaml', $actorId, $actorName, 'critical', false, ['ip' => $clientIp]);
        if ($rollbackErr !== null) {
            // Both the key write and the rollback failed - system is in an inconsistent state
            $logger->security('KEY_ROTATION_CRITICAL', 'CRITICAL: config.yaml rollback also failed - system may be in inconsistent state', $actorId, $actorName, 'critical', true, ['ip' => $clientIp]);
            $addStep('Write key.yaml', 'fail', $writeErr . ' | CRITICAL: config.yaml rollback also failed. Contact your system administrator immediately.');
        } else {
            $addStep('Write key.yaml', 'fail', $writeErr . ' | config.yaml has been restored to original state.');
        }
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }

    @chmod($keyFile, 0600);
    $addStep('Write key.yaml', 'ok', 'key.yaml updated with new encryption_key (permissions set to 600)');

    // Step 8: Clear session config cache
    unset($_SESSION['config_cache']);
    $addStep('Clear config cache', 'ok', 'Session config cache cleared - new key is now active');

    flock($lockFh, LOCK_UN);
    fclose($lockFh);

    $logger->security('KEY_ROTATION_SUCCESS', 'Config encryption key rotated successfully', $actorId, $actorName, 'info', false, ['ip' => $clientIp]);
    echo json_encode(['success' => true, 'steps' => $steps]);
    exit;
}

// ============================================================
// STUDENT LOOKUP KEY ADOPTION / ROTATION
// ============================================================
if ($keyType === 'lookup') {

    // The old lookup key is deliberately not required. Each replacement
    // digest is derived from the recoverable student ID in the encrypted blob,
    // so this same path supports both first-time adoption and later rotation.
    $hadLookupKey = false;
    if (preg_match('/^student_lookup_key:\s*([0-9a-f]{64})\s*(?:#.*)?$/im', $keyContent, $m)) {
        if (preg_match('/^0{64}$/', $m[1])) {
            $addStep('Inspect current lookup key', 'fail', 'student_lookup_key exists in key.yaml but has an invalid format');
            $logger->security('KEY_ROTATION_FAILED', 'Student lookup key rotation failed: existing key has invalid format', $actorId, $actorName, 'critical', false, ['ip' => $clientIp]);
            echo json_encode(['success' => false, 'steps' => $steps]);
            exit;
        }
        $hadLookupKey = true;
        $addStep('Inspect current lookup key', 'ok', 'Existing student_lookup_key found; it will be replaced');
    } elseif (preg_match('/^student_lookup_key\s*:/im', $keyContent)) {
        $addStep('Inspect current lookup key', 'fail', 'student_lookup_key exists in key.yaml but has an invalid format');
        $logger->security('KEY_ROTATION_FAILED', 'Student lookup key rotation failed: existing key has invalid format', $actorId, $actorName, 'critical', false, ['ip' => $clientIp]);
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    } else {
        $addStep('Inspect current lookup key', 'ok', 'No existing lookup key found; a first-time key will be created');
    }

    // The student data key is the only existing key needed for this operation:
    // it recovers the plaintext ID from which the new HMAC is computed.
    if (!preg_match('/^student_encryption_key:\s*([0-9a-f]{64})\s*$/im', $keyContent, $m)
        || preg_match('/^0{64}$/', $m[1])) {
        $addStep('Load student data key', 'fail', 'student_encryption_key not found or invalid in key.yaml');
        $logger->security('KEY_ROTATION_FAILED', 'Student lookup key rotation failed: student encryption key unavailable', $actorId, $actorName, 'critical', false, ['ip' => $clientIp]);
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }
    $studentEncryptionKey = strtolower($m[1]);
    $addStep('Load student data key', 'ok', 'Student data key loaded for recovering student IDs');

    $configEncryptionKey = '';
    if (preg_match('/^encryption_key:\s*([0-9a-f]{64})\s*$/im', $keyContent, $m)) {
        $configEncryptionKey = strtolower($m[1]);
    }

    // Generate a purpose-specific key. The loop is mostly documentary—the
    // probability of a random collision is negligible—but it enforces the
    // application's key-separation invariant explicitly.
    try {
        do {
            $newLookupKey = bin2hex(random_bytes(32));
        } while (hash_equals($newLookupKey, $studentEncryptionKey)
            || ($configEncryptionKey !== '' && hash_equals($newLookupKey, $configEncryptionKey)));
        $addStep('Generate new lookup key', 'ok', 'New 256-bit student lookup key generated');
    } catch (\Throwable $e) {
        $logger->security('KEY_ROTATION_FAILED', 'Student lookup key rotation failed during key generation: ' . $e->getMessage(), $actorId, $actorName, 'critical', false, ['ip' => $clientIp]);
        $addStep('Generate new lookup key', 'fail', 'Key generation failed - check server entropy source');
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }

    // Decrypt and compute everything before modifying either the database or
    // key.yaml. No plaintext student identifier is logged or returned.
    try {
        $studentEncryption = new \SLOCloud\Core\Encryption($studentEncryptionKey);
        $stmt = $db->query(
            "SELECT students_pk, student_data_encrypted FROM {$dbPrefix}students ORDER BY students_pk",
            [],
            ''
        );
        $students = $stmt ? $stmt->fetchAll() : [];
        $replacementHashes = [];
        $seenHashes = [];

        foreach ($students as $row) {
            $studentPk = (int)$row['students_pk'];
            $json = $studentEncryption->decrypt((string)$row['student_data_encrypted']);
            $studentData = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $studentId = is_array($studentData) ? (string)($studentData['c_number'] ?? '') : '';
            if ($studentId === '') {
                throw new \RuntimeException("Student record {$studentPk} does not contain an ID");
            }

            $newHash = \SLOCloud\Core\StudentEncryption::hashCNumberWith($studentId, $newLookupKey);
            if (isset($seenHashes[$newHash])) {
                throw new \RuntimeException(
                    "Student records {$seenHashes[$newHash]} and {$studentPk} resolve to the same lookup value"
                );
            }
            $seenHashes[$newHash] = $studentPk;
            $replacementHashes[$studentPk] = $newHash;
        }

        $studentCount = count($replacementHashes);
        $addStep('Recompute lookup hashes', 'ok', "Recovered and rehashed {$studentCount} student record(s)");
        $addStep('Verify lookup hashes', 'ok', 'All replacement lookup hashes are distinct');
    } catch (\Throwable $e) {
        $logger->security(
            'KEY_ROTATION_FAILED',
            'Student lookup key rotation failed while recomputing hashes: ' . $e->getMessage(),
            $actorId,
            $actorName,
            'critical',
            false,
            ['ip' => $clientIp]
        );
        $addStep('Recompute lookup hashes', 'fail', 'A student record could not be decrypted or validated; no data was changed');
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }

    // Add the key on legacy installations or replace it on keyed installs.
    $lookupKeyLine = 'student_lookup_key: ' . $newLookupKey;
    if ($hadLookupKey) {
        $newKeyContent = preg_replace(
            '/^student_lookup_key:\s*[^\r\n]*$/im',
            $lookupKeyLine,
            $keyContent,
            1
        );
    } else {
        $newKeyContent = rtrim($keyContent, "\r\n") . "\n\n"
            . "# Student ID blind-index key. Rotate only through the supported administration tool.\n"
            . $lookupKeyLine . "\n";
    }
    if (!is_string($newKeyContent)) {
        $addStep('Prepare key.yaml', 'fail', 'Could not prepare the updated key file; no data was changed');
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }

    // Keep the database transaction open until key.yaml is safely in place.
    // While the endpoint's exclusive rotation lock is held, another rotation
    // cannot observe the intermediate state. A failed key write rolls the DB
    // updates back; a failed DB commit restores the original key file.
    $keyFileUpdated = false;
    try {
        if (!$db->beginTransaction()) {
            throw new \RuntimeException('Could not start the database transaction');
        }
        $pdo = $db->getConnection();
        $updateStmt = $pdo->prepare(
            "UPDATE {$dbPrefix}students SET student_id_hash = ? WHERE students_pk = ?"
        );
        foreach ($replacementHashes as $studentPk => $newHash) {
            $updateStmt->execute([$newHash, $studentPk]);
        }

        $writeErr = $atomicWrite($keyFile, $newKeyContent);
        if ($writeErr !== null) {
            throw new \RuntimeException($writeErr);
        }
        $keyFileUpdated = true;

        if (!$db->commit()) {
            throw new \RuntimeException('Could not commit the database transaction');
        }
        $addStep('Update lookup hashes', 'ok', "{$studentCount} lookup hash(es) updated in one database transaction");
        $addStep('Write key.yaml', 'ok', $hadLookupKey
            ? 'key.yaml updated with the new student_lookup_key'
            : 'student_lookup_key added to key.yaml');
    } catch (\Throwable $e) {
        try {
            if ($db->getConnection()->inTransaction()) {
                $db->rollback();
            }
        } catch (\Throwable) {
            // The restoration result below determines whether manual recovery is required.
        }

        $restoreErr = null;
        if ($keyFileUpdated) {
            $restoreErr = $atomicWrite($keyFile, $keyContent);
        }

        if ($restoreErr !== null) {
            $logger->security(
                'KEY_ROTATION_CRITICAL',
                'CRITICAL: student lookup rotation failed and key.yaml could not be restored: ' . $restoreErr,
                $actorId,
                $actorName,
                'critical',
                true,
                ['ip' => $clientIp]
            );
            $addStep('Apply lookup rotation', 'fail', 'Database update failed and key.yaml restoration also failed. Restore the pre-rotation backup immediately.');
        } else {
            $logger->security(
                'KEY_ROTATION_FAILED',
                'Student lookup key rotation failed and was rolled back: ' . $e->getMessage(),
                $actorId,
                $actorName,
                'critical',
                false,
                ['ip' => $clientIp]
            );
            $addStep('Apply lookup rotation', 'fail', 'Rotation failed; database changes were rolled back and key.yaml was left unchanged');
        }
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }

    @chmod($keyFile, 0600);
    unset($_SESSION['config_cache']);
    $addStep('Clear config cache', 'ok', 'Session config cache cleared - the new lookup key is active');

    flock($lockFh, LOCK_UN);
    fclose($lockFh);

    $verb = $hadLookupKey ? 'rotated' : 'created';
    $logger->security(
        'KEY_ROTATION_SUCCESS',
        "Student lookup key {$verb} successfully ({$studentCount} record(s) rehashed)",
        $actorId,
        $actorName,
        'info',
        false,
        ['ip' => $clientIp]
    );
    echo json_encode(['success' => true, 'steps' => $steps]);
    exit;
}

// ============================================================
// STUDENT DATA ENCRYPTION KEY ROTATION
// ============================================================
if ($keyType === 'student') {

    // Step 1: Extract current student_encryption_key
    if (!preg_match('/^student_encryption_key:\s*([0-9a-f]{64})\s*$/im', $keyContent, $m)) {
        $addStep('Load current key', 'fail', 'student_encryption_key not found or invalid format in key.yaml');
        $logger->security('KEY_ROTATION_FAILED', 'Student key rotation failed: key parse error', $actorId, $actorName, 'critical', false, ['ip' => $clientIp]);
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }
    $oldStudentKey = $m[1];
    $addStep('Load current key', 'ok', 'student_encryption_key loaded from key.yaml');

    // Step 2: Count student records
    try {
        $countStmt = $db->query("SELECT COUNT(*) AS cnt FROM {$dbPrefix}students", [], '');
        $countRow  = $countStmt->fetch();
        $count     = (int)($countRow['cnt'] ?? 0);
        $addStep('Count student records', 'ok', "$count encrypted student record(s) found in database");
    } catch (\Exception $e) {
        $logger->security('KEY_ROTATION_FAILED', 'Student key rotation failed: DB count error: ' . $e->getMessage(), $actorId, $actorName, 'critical', false, ['ip' => $clientIp]);
        $addStep('Count student records', 'fail', 'Database query failed - check database connectivity');
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }

    // Step 3: Generate new student key
    try {
        $newStudentKey = bin2hex(random_bytes(32));
        $addStep('Generate new key', 'ok', 'New 256-bit student encryption key generated');
    } catch (\Exception $e) {
        $logger->security('KEY_ROTATION_FAILED', 'Student key rotation failed during key generation: ' . $e->getMessage(), $actorId, $actorName, 'critical', false, ['ip' => $clientIp]);
        $addStep('Generate new key', 'fail', 'Key generation failed - check server entropy source');
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }

    // Step 4: Decrypt and re-encrypt all student records in a single DB transaction
    // All work is done in memory first; only then is the transaction committed.
    // If key.yaml cannot be written after commit, that is logged as critical.
    try {
        $oldEncryption = new \SLOCloud\Core\Encryption($oldStudentKey);
        $newEncryption = new \SLOCloud\Core\Encryption($newStudentKey);

        $stmt     = $db->query("SELECT students_pk, student_data_encrypted FROM {$dbPrefix}students", [], '');
        $students = $stmt->fetchAll();

        // Decrypt and re-encrypt every record in memory first; any corrupt record fails fast
        $reencrypted = [];
        foreach ($students as $row) {
            $plaintext = $oldEncryption->decrypt($row['student_data_encrypted']);
            $reencrypted[(int)$row['students_pk']] = $newEncryption->encrypt($plaintext);
        }

        // Commit all updates in a single transaction
        $db->beginTransaction();
        $pdo        = $db->getConnection();
        $updateStmt = $pdo->prepare(
            "UPDATE {$dbPrefix}students SET student_data_encrypted = ? WHERE students_pk = ?"
        );
        foreach ($reencrypted as $pk => $newEncData) {
            $updateStmt->execute([$newEncData, $pk]);
        }
        $db->commit();

        $updated = count($reencrypted);
        $addStep('Re-encrypt student records', 'ok', "$updated student record(s) re-encrypted in database transaction");
    } catch (\Exception $e) {
        try { $db->rollback(); } catch (\Exception $re) { /* ignore */ }
        $logger->security('KEY_ROTATION_FAILED', 'Student key rotation failed during DB re-encryption (rolled back): ' . $e->getMessage(), $actorId, $actorName, 'critical', false, ['ip' => $clientIp]);
        $addStep('Re-encrypt student records', 'fail', 'Database transaction rolled back - no data was changed');
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }

    // Step 5: Atomically write new key.yaml (only after successful DB commit)
    $newKeyContent = preg_replace(
        '/^(student_encryption_key:\s*)[0-9a-f]{64}/im',
        '${1}' . $newStudentKey,
        $keyContent
    );

    $writeErr = $atomicWrite($keyFile, $newKeyContent);
    if ($writeErr !== null) {
        // Database is now using the new key but key.yaml still has the old key.
        // This is a critical inconsistency requiring manual intervention.
        $logger->security(
            'KEY_ROTATION_CRITICAL',
            'CRITICAL: student records re-encrypted but key.yaml write failed - manual intervention required to update key.yaml with new student_encryption_key',
            $actorId,
            $actorName,
            'critical',
            true,
            ['ip' => $clientIp, 'new_key_partial' => substr($newStudentKey, 0, 8) . '...']
        );
        $addStep('Write key.yaml', 'fail', 'Cannot write key.yaml. CRITICAL: the database was already updated. Contact your system administrator immediately to update key.yaml manually.');
        echo json_encode(['success' => false, 'steps' => $steps]);
        exit;
    }

    @chmod($keyFile, 0600);
    $addStep('Write key.yaml', 'ok', 'key.yaml updated with new student_encryption_key (permissions set to 600)');

    // Step 6: Clear session config cache
    unset($_SESSION['config_cache']);
    $addStep('Clear config cache', 'ok', 'Session config cache cleared - new student key is now active');

    flock($lockFh, LOCK_UN);
    fclose($lockFh);

    $logger->security('KEY_ROTATION_SUCCESS', "Student encryption key rotated successfully ($updated record(s) re-encrypted)", $actorId, $actorName, 'info', false, ['ip' => $clientIp]);
    echo json_encode(['success' => true, 'steps' => $steps]);
    exit;
}
