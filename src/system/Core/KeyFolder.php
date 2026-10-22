<?php
declare(strict_types=1);

namespace SLOCloud\Core;

/**
 * Finds and moves the folder that holds key.yaml (the keys folder).
 *
 * The application reads key.yaml from the first path in
 * Config::keyPathCandidates(): SLOCloud_KEY_PATH, then security.key_path in
 * config.yaml, then the old locations inside the web root. A move copies
 * key.yaml and the key-rotation backups beside it into an existing folder,
 * checks the copies, records the new key.yaml path as security.key_path, checks
 * the application now resolves it, and only then deletes the old copies. Any
 * failure before that last step puts everything back as it was.
 *
 * The new folder must already exist, so its access can be restricted first
 * (on Windows, PHP cannot set folder ACLs). Nothing is created or changed
 * until every check on the folder has passed.
 *
 * @package SLOCloud\Core
 */
final class KeyFolder
{
    /** Folder beside key.yaml that key rotation writes its backups to. */
    public const BACKUPS_DIRECTORY = 'backups';

    /** Where the key file in use was found. */
    public const SOURCE_ENVIRONMENT = 'environment';
    public const SOURCE_CONFIG = 'config';
    public const SOURCE_LEGACY = 'legacy';

    /** The key file the application loads: the first candidate that exists. */
    public static function activeKeyFile(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                $resolved = realpath($candidate);
                return $resolved === false ? $candidate : $resolved;
            }
        }
        return null;
    }

    /**
     * Which setting pointed the application at $activeKeyFile: the
     * SLOCloud_KEY_PATH environment variable, security.key_path, or one of the
     * old fallback locations.
     */
    public static function keySource(?string $activeKeyFile, string $environmentPath, string $configuredPath): string
    {
        if ($activeKeyFile === null) {
            return self::SOURCE_LEGACY;
        }
        foreach ([self::SOURCE_ENVIRONMENT => $environmentPath, self::SOURCE_CONFIG => $configuredPath] as $source => $path) {
            if ($path !== '' && is_file($path) && realpath($path) === $activeKeyFile) {
                return $source;
            }
        }
        return self::SOURCE_LEGACY;
    }

    /**
     * Folders the web server serves: the application source folder and the
     * site's document root, resolved and normalized.
     *
     * @return string[]
     */
    public static function webRoots(array $roots, ?bool $windows = null): array
    {
        $windows ??= PHP_OS_FAMILY === 'Windows';
        $normalized = [];
        foreach ($roots as $root) {
            $resolved = is_string($root) && $root !== '' ? realpath($root) : false;
            if ($resolved !== false) {
                $normalized[] = self::normalize($resolved, $windows);
            }
        }
        return array_values(array_unique($normalized));
    }

    /** True when $path is one of $webRoots or inside one. */
    public static function isInsideWebRoot(string $path, array $webRoots, ?bool $windows = null): bool
    {
        $windows ??= PHP_OS_FAMILY === 'Windows';
        $candidate = self::normalize($path, $windows);
        foreach ($webRoots as $root) {
            if (str_starts_with($candidate, $root)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check a proposed keys folder without changing anything except a short
     * write test inside it.
     *
     * @return array{folder: ?string, steps: list<array{name: string, status: string, message: string}>}
     */
    public static function check(string $keyFile, string $targetInput, array $webRoots, ?bool $windows = null): array
    {
        $windows ??= PHP_OS_FAMILY === 'Windows';
        $steps = [];
        $fail = static function (string $name, string $message) use (&$steps): array {
            $steps[] = ['name' => $name, 'status' => 'fail', 'message' => $message];
            return ['folder' => null, 'steps' => $steps];
        };
        $ok = static function (string $name, string $message) use (&$steps): void {
            $steps[] = ['name' => $name, 'status' => 'ok', 'message' => $message];
        };

        if (!is_file($keyFile) || !is_readable($keyFile)) {
            return $fail('Current key file', 'The key file in use cannot be read, so it cannot be moved.');
        }
        $ok('Current key file', 'Found ' . $keyFile);

        $path = trim($targetInput);
        if ($path === '') {
            return $fail('Folder path', 'Enter the full path of the new folder.');
        }
        if (strlen($path) > 1024 || preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            return $fail('Folder path', 'The path contains characters that cannot be used in a folder name.');
        }
        if (str_contains($path, '://')) {
            return $fail('Folder path', 'Enter a folder on this server, not a web address.');
        }
        $absolute = $windows
            ? preg_match('#^(?:[A-Za-z]:[\\\\/]|\\\\\\\\[^\\\\/]+[\\\\/][^\\\\/]+)#', $path) === 1
            : str_starts_with($path, '/');
        if (!$absolute) {
            return $fail('Folder path', $windows
                ? 'Enter a full path, such as D:\\SLO Cloud 5\\keys.'
                : 'Enter a full path, such as /etc/slocloud/keys.');
        }
        foreach (preg_split('#[\\\\/]+#', $path) ?: [] as $segment) {
            if ($segment === '.' || $segment === '..') {
                return $fail('Folder path', 'The path cannot contain . or .. folders. Enter the folder\'s full path.');
            }
        }
        $ok('Folder path', 'The path is a full path.');

        $folder = realpath($path);
        if ($folder === false || !is_dir($folder)) {
            return $fail('Folder exists', 'That folder does not exist. Create it on the server first and give access only to '
                . ($windows ? 'Administrators, SYSTEM and the application pool.' : 'the web server account (for example chmod 700).'));
        }
        $ok('Folder exists', 'Found ' . $folder);

        if (self::isInsideWebRoot($folder, $webRoots, $windows)) {
            return $fail('Outside the web root', 'That folder is inside the web root, where the web server could serve the keys. Choose a folder outside it.');
        }
        $ok('Outside the web root', 'The web server does not serve that folder.');

        $currentFolder = realpath(dirname($keyFile));
        if ($currentFolder !== false && self::normalize($currentFolder, $windows) === self::normalize($folder, $windows)) {
            return $fail('Different folder', 'The keys are already in that folder.');
        }
        $ok('Different folder', 'The folder is not the current keys folder.');

        if (!$windows) {
            $permissions = @fileperms($folder);
            if ($permissions !== false && ($permissions & 0o002) !== 0) {
                return $fail('Folder access', 'Every account on the server can change that folder. Restrict it (for example chmod 700) and try again.');
            }
            $ok('Folder access', ($permissions !== false && ($permissions & 0o077) === 0)
                ? 'Only the folder owner can use it.'
                : 'Other accounts can list the folder; key files are written readable by the web server account only.');
        }

        $keyName = basename($keyFile);
        foreach ([$keyName, self::BACKUPS_DIRECTORY] as $existing) {
            if (file_exists($folder . DIRECTORY_SEPARATOR . $existing)) {
                return $fail('Folder is free', 'That folder already has ' . $existing . '. Choose a folder without one, so no keys are overwritten.');
            }
        }
        $ok('Folder is free', 'No ' . $keyName . ' or ' . self::BACKUPS_DIRECTORY . ' folder there yet.');

        // The application must read, write and delete here: key rotation
        // rewrites key.yaml and adds backups beside it.
        $probe = $folder . DIRECTORY_SEPARATOR . '.slocloud-write-test-' . bin2hex(random_bytes(6));
        $token = bin2hex(random_bytes(16));
        $written = @file_put_contents($probe, $token, LOCK_EX) === strlen($token);
        $readBack = $written ? @file_get_contents($probe) : false;
        $deleted = !file_exists($probe) || @unlink($probe);
        if (!$written || $readBack !== $token || !$deleted) {
            return $fail('Write test', 'The web server account cannot write, read and delete files in that folder. Give it modify access and try again.');
        }
        $ok('Write test', 'The web server account can write, read and delete files there.');

        return ['folder' => $folder, 'steps' => $steps];
    }

    /**
     * Move the keys folder.
     *
     * @param callable(string): void $record   Saves the new key file path where the application reads it. Throws on failure.
     * @param callable(string): void $restore  Puts the previous setting back. Throws on failure.
     * @param callable(): ?string    $resolve  The key file the application would load now, read from disk.
     * @return array{success: bool, key_file: ?string, steps: list<array{name: string, status: string, message: string}>}
     */
    public static function move(
        string $keyFile,
        string $targetInput,
        array $webRoots,
        callable $record,
        callable $restore,
        callable $resolve,
        ?bool $windows = null
    ): array {
        $windows ??= PHP_OS_FAMILY === 'Windows';
        $checked = self::check($keyFile, $targetInput, $webRoots, $windows);
        $steps = $checked['steps'];
        $folder = $checked['folder'];
        if ($folder === null) {
            return ['success' => false, 'key_file' => null, 'steps' => $steps];
        }
        $keyFile = (string)realpath($keyFile);
        $add = static function (string $name, string $status, string $message) use (&$steps): void {
            $steps[] = ['name' => $name, 'status' => $status, 'message' => $message];
        };
        $result = static function (bool $success, ?string $newKeyFile) use (&$steps): array {
            return ['success' => $success, 'key_file' => $newKeyFile, 'steps' => $steps];
        };

        // Everything to copy: key.yaml, and each rotation backup folder beside it.
        $oldFolder = dirname($keyFile);
        $newKeyFile = $folder . DIRECTORY_SEPARATOR . basename($keyFile);
        $copies = [[$keyFile, $newKeyFile]];
        $directories = [];
        $oldBackups = $oldFolder . DIRECTORY_SEPARATOR . self::BACKUPS_DIRECTORY;
        $newBackups = $folder . DIRECTORY_SEPARATOR . self::BACKUPS_DIRECTORY;
        $backupFolders = is_dir($oldBackups) ? (glob($oldBackups . DIRECTORY_SEPARATOR . 'key_backup_*', GLOB_ONLYDIR) ?: []) : [];
        if ($backupFolders !== []) {
            $directories[] = $newBackups;
        }
        foreach ($backupFolders as $backupFolder) {
            $target = $newBackups . DIRECTORY_SEPARATOR . basename($backupFolder);
            $directories[] = $target;
            foreach (glob($backupFolder . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file)) {
                    $copies[] = [$file, $target . DIRECTORY_SEPARATOR . basename($file)];
                }
            }
        }

        $created = [];
        $undoCopies = static function () use (&$created): void {
            foreach (array_reverse($created) as $path) {
                is_dir($path) ? @rmdir($path) : @unlink($path);
            }
        };

        foreach ($directories as $directory) {
            if (!@mkdir($directory, 0700) && !is_dir($directory)) {
                $undoCopies();
                $add('Copy keys', 'fail', 'Could not create ' . $directory . '. Nothing was changed.');
                return $result(false, null);
            }
            $created[] = $directory;
            if (!$windows) @chmod($directory, 0700);
        }
        foreach ($copies as [$from, $to]) {
            if (file_exists($to) || !@copy($from, $to)) {
                $undoCopies();
                $add('Copy keys', 'fail', 'Could not copy ' . basename($from) . ' to the new folder. Nothing was changed.');
                return $result(false, null);
            }
            $created[] = $to;
            if (!$windows) @chmod($to, 0600);
        }
        $add('Copy keys', 'ok', 'Copied ' . basename($keyFile)
            . ($backupFolders !== [] ? ' and ' . count($backupFolders) . ' key rotation backup(s)' : ''));

        foreach ($copies as [$from, $to]) {
            $original = @hash_file('sha256', $from);
            if ($original === false || !hash_equals($original, (string)@hash_file('sha256', $to))) {
                $undoCopies();
                $add('Verify copies', 'fail', 'The copy of ' . basename($from) . ' does not match the original. Nothing was changed.');
                return $result(false, null);
            }
        }
        $add('Verify copies', 'ok', 'Every copy matches its original.');

        try {
            $record($newKeyFile);
        } catch (\Throwable $e) {
            $undoCopies();
            $add('Save new location', 'fail', 'Could not save the new location in config.yaml (' . $e->getMessage() . '). Nothing was changed.');
            return $result(false, null);
        }
        $add('Save new location', 'ok', 'security.key_path in config.yaml is now ' . $newKeyFile);

        $active = null;
        try {
            $active = $resolve();
        } catch (\Throwable) {
            // Treated as not resolving below.
        }
        if ($active === null || realpath($active) !== realpath($newKeyFile)) {
            try {
                $restore($keyFile);
            } catch (\Throwable $e) {
                // config.yaml may still name the new copy, so keep both copies.
                $add('Confirm switch', 'fail', 'SLO Cloud does not load the key from the new folder, and config.yaml could not be put back ('
                    . $e->getMessage() . '). Both copies were kept. Set security.key_path in config.yaml to ' . $keyFile . '.');
                return $result(false, null);
            }
            $undoCopies();
            $add('Confirm switch', 'fail', 'SLO Cloud does not load the key from the new folder, so the previous location was put back. Nothing was changed.');
            return $result(false, null);
        }
        $add('Confirm switch', 'ok', 'SLO Cloud now loads ' . $newKeyFile);

        // The switch is done. A copy that cannot be deleted is reported, not
        // undone: the application already uses the new folder.
        $leftovers = [];
        foreach ($copies as [$from]) {
            if (!@unlink($from) && file_exists($from)) {
                $leftovers[] = $from;
            }
        }
        foreach ($backupFolders as $backupFolder) {
            @rmdir($backupFolder);
        }
        if ($backupFolders !== [] && count(glob($oldBackups . DIRECTORY_SEPARATOR . '*') ?: []) === 0) {
            @rmdir($oldBackups);
        }
        if ($leftovers === []) {
            $add('Remove old copies', 'ok', 'Deleted the keys from ' . $oldFolder);
        } else {
            $add('Remove old copies', 'warn', 'SLO Cloud uses the new folder, but these old copies could not be deleted. Delete them by hand: '
                . implode(', ', $leftovers));
        }

        return $result(true, $newKeyFile);
    }

    private static function normalize(string $path, bool $windows): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/') . '/';
        return $windows ? strtolower($path) : $path;
    }
}
