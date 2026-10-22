<?php
declare(strict_types=1);

// Moving the keys folder (System > Configuration > Encryption Keys Folder).
// The new folder must already exist and pass every check before anything is
// copied; a failure at any step before the old copies are deleted must leave
// both folders and the recorded location exactly as they were.

require_once __DIR__ . '/../src/system/Core/KeyFolder.php';

use SLOCloud\Core\KeyFolder;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$root = realpath(sys_get_temp_dir()) . '/slocloud-key-folder-test-' . bin2hex(random_bytes(4));
$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_dir($path) && !is_link($path)) {
        @chmod($path, 0700);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') $removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    } elseif (file_exists($path) || is_link($path)) {
        @unlink($path);
    }
};
$keyText = 'encryption_key: ' . str_repeat('a', 64) . "\nstudent_encryption_key: " . str_repeat('b', 64) . "\n";
$listing = static function (string $path): array {
    if (!is_dir($path)) return [];
    $found = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    foreach ($iterator as $item) $found[] = substr($item->getPathname(), strlen($path));
    sort($found);
    return $found;
};

// A web root with an old-style key inside it, and a keys folder outside it.
$setUp = static function () use ($root, $removeTree, $keyText): array {
    $removeTree($root);
    mkdir($root . '/web/config/backups/key_backup_20260101_000000', 0700, true);
    mkdir($root . '/secrets/backups/key_backup_20260102_000000', 0700, true);
    mkdir($root . '/new', 0700);
    file_put_contents($root . '/web/config/key.yaml', $keyText);
    file_put_contents($root . '/web/config/config.yaml', "app: {}\n");
    file_put_contents($root . '/web/config/backups/index.php', "<?php http_response_code(403); exit;\n");
    file_put_contents($root . '/web/config/backups/key_backup_20260101_000000/key.yaml', $keyText);
    file_put_contents($root . '/web/config/backups/key_backup_20260101_000000/config.yaml', "app: {}\n");
    file_put_contents($root . '/secrets/key.yaml', $keyText);
    file_put_contents($root . '/secrets/backups/key_backup_20260102_000000/key.yaml', $keyText);
    file_put_contents($root . '/secrets/notes.txt', "not key material\n");
    return KeyFolder::webRoots([$root . '/web'], false);
};

// --- Checks: nothing is created, and a bad path never gets past the checks ---
$webRoots = $setUp();
$keyFile = $root . '/secrets/key.yaml';
$failsAt = static function (string $target, string $step, ?bool $windows = false) use ($keyFile, $webRoots): bool {
    $result = KeyFolder::check($keyFile, $target, $webRoots, $windows);
    $last = end($result['steps']);
    return $result['folder'] === null && $last['status'] === 'fail' && $last['name'] === $step;
};
$check($failsAt('', 'Folder path'), 'An empty folder path was accepted.');
$check($failsAt('relative/keys', 'Folder path'), 'A relative folder path was accepted.');
$check($failsAt($root . '/new/../web', 'Folder path'), 'A path with .. segments was accepted.');
$check($failsAt("$root/new\0evil", 'Folder path'), 'A path with a null byte was accepted.');
$check($failsAt('file:///etc/keys', 'Folder path'), 'A URL was accepted as a folder.');
$check($failsAt('C:\\keys', 'Folder path'), 'A Windows drive path was accepted on a non-Windows server.');
$check($failsAt('keys', 'Folder path', true), 'A relative Windows path was accepted.');
$check(!$failsAt('C:\\keys-that-do-not-exist', 'Folder path', true), 'A Windows drive path was refused as not a full path.');
$check(!$failsAt('\\\\server\\share\\keys', 'Folder path', true), 'A Windows UNC path was refused as not a full path.');
$check($failsAt($root . '/missing', 'Folder exists'), 'A folder that does not exist was accepted.');
$check(!is_dir($root . '/missing'), 'Checking a missing folder created it; the folder must already exist.');
file_put_contents($root . '/plain-file', 'x');
$check($failsAt($root . '/plain-file', 'Folder exists'), 'A file was accepted as the keys folder.');
$check($failsAt($root . '/web/config', 'Outside the web root'), 'A folder inside the web root was accepted.');
$check($failsAt($root . '/web', 'Outside the web root'), 'The web root itself was accepted.');
symlink($root . '/web/config', $root . '/link-into-web');
$check($failsAt($root . '/link-into-web', 'Outside the web root'), 'A link pointing into the web root was accepted.');
$check($failsAt($root . '/secrets', 'Different folder'), 'The current keys folder was accepted as the new one.');
chmod($root . '/new', 0777);
$check($failsAt($root . '/new', 'Folder access'), 'A folder every account can change was accepted.');
chmod($root . '/new', 0700);
mkdir($root . '/taken');
file_put_contents($root . '/taken/key.yaml', 'x');
$check($failsAt($root . '/taken', 'Folder is free'), 'A folder that already holds key.yaml was accepted.');
mkdir($root . '/taken-backups/backups', 0700, true);
$check($failsAt($root . '/taken-backups', 'Folder is free'), 'A folder that already holds a backups folder was accepted.');
if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    mkdir($root . '/read-only', 0500);
    $check($failsAt($root . '/read-only', 'Write test'), 'A folder the web server cannot write to was accepted.');
    chmod($root . '/read-only', 0700);
}
$ready = KeyFolder::check($keyFile, $root . '/new/', $webRoots, false);
$check($ready['folder'] === realpath($root . '/new'), 'A usable folder did not pass the checks.');
$check($listing($root . '/new') === [], 'The folder check left files behind.');
$check(is_file($keyFile) && $listing($root . '/secrets') === ['/backups', '/backups/key_backup_20260102_000000', '/backups/key_backup_20260102_000000/key.yaml', '/key.yaml', '/notes.txt'], 'The folder check changed the current keys folder.');

// --- A successful move ---
$recorded = [];
$outcome = KeyFolder::move(
    $keyFile,
    $root . '/new',
    $webRoots,
    static function (string $path) use (&$recorded): void { $recorded[] = $path; },
    static function (): void { throw new LogicException('restore must not run after a good move'); },
    static function () use (&$recorded): ?string { return KeyFolder::activeKeyFile([end($recorded) ?: '']); },
    false
);
$newFolder = realpath($root . '/new');
$check($outcome['success'] === true, 'A move to a usable folder failed: ' . json_encode($outcome['steps']));
$check($recorded === [$newFolder . '/key.yaml'], 'The move did not record the new key.yaml path once.');
$check(file_get_contents($newFolder . '/key.yaml') === $keyText, 'The moved key.yaml does not match the original.');
$check(file_get_contents($newFolder . '/backups/key_backup_20260102_000000/key.yaml') === $keyText, 'Key rotation backups were not moved with the key.');
$check((fileperms($newFolder . '/key.yaml') & 0o777) === 0o600, 'The moved key.yaml is readable by other accounts.');
$check((fileperms($newFolder . '/backups') & 0o777) === 0o700, 'The moved backups folder is open to other accounts.');
$check(!file_exists($keyFile) && !is_dir($root . '/secrets/backups'), 'The old key copies were not deleted after the move.');
$check(is_file($root . '/secrets/notes.txt') && is_dir($root . '/secrets'), 'The move deleted files that are not key material, or the old folder.');
$check(array_column($outcome['steps'], 'status') === array_fill(0, count($outcome['steps']), 'ok'), 'A clean move reported a warning or failure.');

// --- Older installs: key.yaml inside the web root, no key_path yet ---
$webRoots = $setUp();
$legacyKey = $root . '/web/config/key.yaml';
$outcome = KeyFolder::move(
    $legacyKey,
    $root . '/new',
    $webRoots,
    static function (): void {},
    static function (): void {},
    static fn(): ?string => KeyFolder::activeKeyFile([$root . '/new/key.yaml', $legacyKey]),
    false
);
$check($outcome['success'] === true, 'An older install could not move its key out of the web root.');
$check(!file_exists($legacyKey) && !is_dir($root . '/web/config/backups/key_backup_20260101_000000'), 'Key copies were left inside the web root after the move.');
$check(is_file($root . '/web/config/config.yaml') && is_file($root . '/web/config/backups/index.php'), 'The move deleted files beside key.yaml that are not key material.');
$check(is_file($root . '/new/backups/key_backup_20260101_000000/config.yaml'), 'A key rotation backup lost its config.yaml copy in the move.');

// --- Failures leave everything as it was ---
$unchanged = static function (string $label) use ($root, $listing, $keyText): array {
    return [
        $listing($root . '/new') === [] ? null : "$label: copies were left in the new folder.",
        file_get_contents($root . '/secrets/key.yaml') === $keyText ? null : "$label: the original key.yaml changed or was deleted.",
        is_file($root . '/secrets/backups/key_backup_20260102_000000/key.yaml') ? null : "$label: an original backup was deleted.",
    ];
};
$recordFails = static function (): void { throw new RuntimeException('disk full'); };

$webRoots = $setUp();
$outcome = KeyFolder::move($root . '/secrets/key.yaml', $root . '/missing', $webRoots, $recordFails, $recordFails, static fn() => null, false);
$check($outcome['success'] === false && !is_dir($root . '/missing'), 'A move to a missing folder created it.');
foreach ($unchanged('Missing folder') as $problem) if ($problem) $failures[] = $problem;

$webRoots = $setUp();
$restored = false;
$outcome = KeyFolder::move($root . '/secrets/key.yaml', $root . '/new', $webRoots, $recordFails, static function () use (&$restored): void { $restored = true; }, static fn() => null, false);
$check($outcome['success'] === false && end($outcome['steps'])['name'] === 'Save new location', 'A failed config.yaml save was not reported.');
foreach ($unchanged('Save fails') as $problem) if ($problem) $failures[] = $problem;

$webRoots = $setUp();
$restored = false;
$outcome = KeyFolder::move(
    $root . '/secrets/key.yaml',
    $root . '/new',
    $webRoots,
    static function (): void {},
    static function (string $old) use (&$restored, $root): void { $restored = $old === realpath($root . '/secrets/key.yaml'); },
    static fn(): ?string => realpath($root . '/secrets/key.yaml'),
    false
);
$check($outcome['success'] === false && $restored, 'A move the application did not pick up was not put back.');
foreach ($unchanged('Not picked up') as $problem) if ($problem) $failures[] = $problem;

$webRoots = $setUp();
$outcome = KeyFolder::move(
    $root . '/secrets/key.yaml',
    $root . '/new',
    $webRoots,
    static function (): void {},
    $recordFails,
    static fn() => null,
    false
);
$check($outcome['success'] === false && is_file($root . '/new/key.yaml') && is_file($root . '/secrets/key.yaml'),
    'When config.yaml could not be put back, a key copy that config.yaml may name was deleted.');
$check(str_contains(end($outcome['steps'])['message'], 'security.key_path'), 'A failed restore does not tell the administrator what to fix.');

// --- Where the key file in use came from ---
$webRoots = $setUp();
$secretsKey = realpath($root . '/secrets/key.yaml');
$check(KeyFolder::keySource($secretsKey, $secretsKey, '') === KeyFolder::SOURCE_ENVIRONMENT, 'A key set by SLOCloud_KEY_PATH was not reported as such.');
$check(KeyFolder::keySource($secretsKey, '', $secretsKey) === KeyFolder::SOURCE_CONFIG, 'A key set by security.key_path was not reported as such.');
$check(KeyFolder::keySource(realpath($root . '/web/config/key.yaml'), '', '') === KeyFolder::SOURCE_LEGACY, 'A key found in the old default location was not reported as such.');
$check(KeyFolder::isInsideWebRoot($root . '/web/config/key.yaml', $webRoots, false), 'A key inside the web root was not reported as inside it.');
$check(!KeyFolder::isInsideWebRoot($root . '/website/key.yaml', $webRoots, false), 'A sibling folder whose name starts like the web root was treated as inside it.');

$removeTree($root);

if ($failures !== []) {
    fwrite(STDERR, "Key folder tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}
echo "Key folder tests passed.\n";
