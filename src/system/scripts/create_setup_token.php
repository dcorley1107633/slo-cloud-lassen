<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$ttl = isset($argv[1]) ? (int)$argv[1] : 900;
if ($ttl < 60 || $ttl > 3600) {
    fwrite(STDERR, "TTL must be between 60 and 3600 seconds.\n");
    exit(1);
}

$token = bin2hex(random_bytes(32));
$sourceRoot = dirname(__DIR__, 2);
require_once $sourceRoot . '/system/Core/Security.php';
$directory = \SLOCloud\Core\Security::dataRoot($sourceRoot) . '/var';
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
    fwrite(STDERR, "Unable to create var directory.\n");
    exit(1);
}
@chmod($directory, 0700);
$path = $directory . '/setup-token.json';
$payload = json_encode([
    'token_hash' => hash('sha256', $token),
    'expires_at' => time() + $ttl,
], JSON_THROW_ON_ERROR);
$temporary = tempnam($directory, 'setup-token-');
if ($temporary === false || file_put_contents($temporary, $payload, LOCK_EX) === false) {
    fwrite(STDERR, "Unable to write setup token.\n");
    exit(1);
}
@chmod($temporary, 0600);
if (!@rename($temporary, $path)) {
    @unlink($temporary);
    fwrite(STDERR, "Unable to publish setup token.\n");
    exit(1);
}
@chmod($path, 0600);

fwrite(STDOUT, "Installation token (expires in {$ttl} seconds):\n{$token}\n");
fwrite(STDOUT, "Open /setup/?token={$token} from localhost or an IP listed in SLOCLOUD_SETUP_ALLOWED_IPS.\n");
