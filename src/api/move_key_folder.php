<?php
declare(strict_types=1);

/**
 * Keys Folder API
 *
 * Checks a proposed keys folder, or moves key.yaml and its key-rotation
 * backups there (System > Configuration > Encryption Keys Folder).
 *
 * SECURITY REQUIREMENTS (same as api/rotate_key.php):
 *   - Active admin session; the emergency admin (user_id = 0) is blocked
 *   - Valid CSRF token from the current session
 *   - Maintenance Mode for a move (a check changes nothing)
 *   - The key rotation lock, so a move and a rotation never overlap
 *   - SLOCloud_KEY_PATH not set: it overrides config.yaml, so a move could not take effect
 *
 * POST params:
 *   action      - 'check' or 'move'
 *   folder      - full path of an existing folder outside the web root
 *   csrf_token  - CSRF token from session
 *
 * Response JSON: {"success": bool, "steps": [{"name", "status": "ok"|"warn"|"fail", "message"}]}
 *
 * @package SLOCloud\Api
 */

require_once __DIR__ . '/../system/includes/init.php';
require_once __DIR__ . '/../system/Core/KeyFolder.php';

use SLOCloud\Core\Authorization;
use SLOCloud\Core\KeyFolder;

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

$clientIp  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$actorId   = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$actorName = $_SESSION['user_email'] ?? 'unknown';

$refuse = static function (int $status, string $step, string $message): never {
    http_response_code($status);
    echo json_encode(['success' => false, 'steps' => [['name' => $step, 'status' => 'fail', 'message' => $message]]]);
    exit;
};

if (empty($_SESSION['user_id'])) {
    $logger->security('KEY_FOLDER_DENIED', 'Keys folder request rejected: not authenticated', null, 'anonymous', 'warning', true, ['ip' => $clientIp]);
    $refuse(401, 'Authenticate', 'Not authenticated');
}
if (!empty($_SESSION['is_emergency_admin'])) {
    $logger->security('KEY_FOLDER_DENIED', 'Keys folder request rejected: emergency admin account not permitted', 0, $actorName, 'warning', false, ['ip' => $clientIp]);
    $refuse(403, 'Authenticate', 'Moving the keys folder is not available for the emergency admin account');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $refuse(405, 'Request', 'Use POST');
}
if (!isset($_POST['csrf_token']) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$_POST['csrf_token'])) {
    $logger->security('KEY_FOLDER_DENIED', 'Keys folder request rejected: invalid CSRF token', $actorId, $actorName, 'warning', false, ['ip' => $clientIp]);
    $refuse(403, 'CSRF check', 'This form has expired. Reload the page and try again.');
}
$action = $_POST['action'] ?? '';
if (!in_array($action, ['check', 'move'], true)) {
    $refuse(400, 'Validate input', 'Unknown action');
}
$targetInput = is_string($_POST['folder'] ?? null) ? $_POST['folder'] : '';
if (!Authorization::isGlobalAdmin($db, $dbPrefix, $actorId)) {
    $logger->security('KEY_FOLDER_DENIED', 'Keys folder request rejected: admin role required', $actorId, $actorName, 'warning', false, ['ip' => $clientIp]);
    $refuse(403, 'Verify role', 'Admin role required to move the keys folder');
}

$environmentPath = getenv('SLOCloud_KEY_PATH');
if (is_string($environmentPath) && $environmentPath !== '') {
    $refuse(409, 'Key location setting', 'The SLOCloud_KEY_PATH environment variable sets where key.yaml is, and it overrides config.yaml. '
        . 'Move the file and update that variable on the server instead.');
}

// A move rewrites config.yaml and deletes the old key file, so no one may be
// using the site in between. A check changes nothing and runs any time.
if ($action === 'move' && !MAINTENANCE_MODE) {
    $logger->security('KEY_FOLDER_DENIED', 'Keys folder move rejected: maintenance mode is off', $actorId, $actorName, 'warning', false, ['ip' => $clientIp]);
    $refuse(409, 'Verify maintenance mode', 'Turn on Maintenance Mode and save the configuration before moving the keys folder');
}

$keyFile = KeyFolder::activeKeyFile($config->keyPathCandidates());
if ($keyFile === null) {
    $refuse(409, 'Current key file', 'key.yaml was not found in any expected location');
}
$webRoots = KeyFolder::webRoots([dirname(__DIR__), (string)($_SERVER['DOCUMENT_ROOT'] ?? '')]);

if ($action === 'check') {
    $checked = KeyFolder::check($keyFile, $targetInput, $webRoots);
    echo json_encode(['success' => $checked['folder'] !== null, 'steps' => $checked['steps']]);
    exit;
}

// Same lock as key rotation: a rotation writing the old key.yaml during a
// move would be lost.
$lockFile = $applicationDataRoot . '/var/key_rotation.lock';
$lockHandle = @fopen($lockFile, 'c');
if ($lockHandle === false) {
    $refuse(500, 'Acquire lock', 'Could not open the lock file - check permissions on the data folder');
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    fclose($lockHandle);
    $refuse(409, 'Acquire lock', 'A key rotation or another move is running - wait and try again');
}
register_shutdown_function(static function () use ($lockHandle): void {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
});

$configFile = __DIR__ . '/../config/config.yaml';
$previousKeyPath = $config->getRaw('security.key_path');

$outcome = KeyFolder::move(
    $keyFile,
    $targetInput,
    $webRoots,
    static function (string $newKeyFile) use ($config): void {
        $config->set('security.key_path', $newKeyFile);
        $config->save();
    },
    static function (string $oldKeyFile) use ($config, $previousKeyPath): void {
        if (is_string($previousKeyPath) && $previousKeyPath !== '') {
            $config->set('security.key_path', $previousKeyPath);
        } else {
            $config->remove('security.key_path');
        }
        $config->save();
    },
    // Read config.yaml back from disk, so the check proves what the next
    // request will load, not what this request holds in memory.
    static function () use ($configFile): ?string {
        $saved = @yaml_parse_file($configFile);
        $savedPath = is_array($saved) ? (string)($saved['security']['key_path'] ?? '') : '';
        return KeyFolder::activeKeyFile([$savedPath, dirname($configFile) . '/key.yaml', dirname($configFile, 2) . '/key.yaml']);
    }
);

unset($_SESSION['config_cache']);
if ($outcome['success']) {
    $logger->security('KEY_FOLDER_MOVED', 'Keys folder moved from ' . dirname($keyFile) . ' to ' . dirname((string)$outcome['key_file']),
        $actorId, $actorName, 'info', false, ['ip' => $clientIp]);
} else {
    $logger->security('KEY_FOLDER_MOVE_FAILED', 'Keys folder move did not complete', $actorId, $actorName, 'warning', false,
        ['ip' => $clientIp, 'steps' => array_map(static fn(array $step): string => $step['name'] . ': ' . $step['status'], $outcome['steps'])]);
}
echo json_encode(['success' => $outcome['success'], 'steps' => $outcome['steps']]);
