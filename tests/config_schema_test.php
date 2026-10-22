<?php
declare(strict_types=1);

// Every page loads config.yaml through the template's rules, so anything the
// application itself writes (template defaults, setup's values) must pass them.

require_once __DIR__ . '/../src/system/Core/Config.php';
require_once __DIR__ . '/../src/system/Core/Path.php';

use SLOCloud\Core\Config;
use SLOCloud\Core\Path;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$template = __DIR__ . '/../src/config/config.yaml.template';
$problem = static function (array $config) use ($template): ?string {
    try {
        Config::validateAgainstTemplate($config, $template);
        return null;
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
};
$typed = static function (string $value, string $type): mixed {
    return match ($type) {
        'boolean' => $value === 'true',
        'integer' => (int)$value,
        default => $value,
    };
};

// The Configuration page shows @default when a setting has no value and saves
// every field, so each @default must be a value the field accepts.
foreach (Config::schemaFromTemplate($template) as $path => $meta) {
    if (!array_key_exists('default', $meta)) continue;
    $type = $meta['type'] ?? 'string';
    if ($type === 'integer') {
        $check(preg_match('/^\d+$/', $meta['default']) === 1, "{$path}: @default is not a number");
    }
    if ($type === 'boolean') {
        $check(in_array($meta['default'], ['true', 'false'], true), "{$path}: @default is not true/false");
    }
    [$section, $key] = explode('.', $path, 2);
    if ($meta['default'] === '' && ($meta['required'] ?? 'false') !== 'true') continue;
    $error = $problem([$section => [$key => $typed($meta['default'], $type)]]);
    $check($error === null, "{$path}: @default '{$meta['default']}' is rejected: {$error}");
}

// Template values are merged into config.yaml for settings it lacks.
$section = null;
$schema = Config::schemaFromTemplate($template);
foreach (file($template, FILE_IGNORE_NEW_LINES) as $line) {
    if (preg_match('/^([a-z_]+):\s*$/', $line, $m)) { $section = $m[1]; continue; }
    if ($section === null || !preg_match('/^  ([a-z_]+):\s*(.*?)\s*$/', $line, $m)) continue;
    $path = $section . '.' . $m[1];
    if (!isset($schema[$path]) || $m[2] === '') continue;
    $value = trim($m[2], '"');
    $error = $problem([$section => [$m[1] => $typed($value, $schema[$path]['type'] ?? 'string')]]);
    $check($error === null, "{$path}: template value '{$value}' is rejected: {$error}");
}

// Setup's email choices and SQL Server host forms.
$check($problem(['email' => ['method' => 'mail']]) === null, 'email.method mail must be accepted');
$check($problem(['email' => ['method' => 'server']]) !== null, 'email.method server is not a template option');
$check($problem(['database' => ['host' => 'SQL01\\SQLEXPRESS']]) === null, 'named SQL Server instance host must be accepted');
$check($problem(['database' => ['host' => 'db.example.edu']]) === null, 'DNS host must be accepted');
$check($problem(['database' => ['host' => 'bad host;x=y']]) !== null, 'host with spaces or ; must be rejected');

// Configurations saved by setup up to 5.2.2 with "Server Mail".
$reflection = new ReflectionClass(Config::class);
$instance = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('configPath')->setValue($instance, dirname($template) . '/config.yaml');
$legacy = ['email' => ['method' => 'server']];
$reflection->getMethod('normalizeLegacySchemaTypes')->invokeArgs($instance, [&$legacy]);
$check($legacy['email']['method'] === 'mail', 'email.method server must load as mail');
$check($problem($legacy) === null, 'normalized legacy email method must validate');

// A log folder that cannot be created falls back to <data>/logs instead of
// stopping the logger (and every page).
require_once __DIR__ . '/../src/system/Core/Security.php';
$blocker = tempnam(sys_get_temp_dir(), 'slo-log-');
$sourceRoot = sys_get_temp_dir() . '/slo-src-' . bin2hex(random_bytes(4)) . '/src';
$fallback = \SLOCloud\Core\Security::logDirectory($blocker . DIRECTORY_SEPARATOR . 'logs', $sourceRoot);
$check(!str_starts_with($fallback, $blocker), 'an uncreatable log folder must fall back to the data folder');
$usable = sys_get_temp_dir();
$check(\SLOCloud\Core\Security::logDirectory($usable, $sourceRoot) === rtrim($usable, '/\\'), 'an existing absolute log folder must be used');
@unlink($blocker);

// Theme Settings offers exactly the color schemes the application defines.
require_once __DIR__ . '/../src/system/Core/ColorScheme.php';
$colorSchemeField = Config::schemaFromTemplate($template)['theme.color_scheme'] ?? [];
$colorSchemeOptions = array_map(static fn(string $option): string => explode('|', $option, 2)[0], explode(',', $colorSchemeField['options'] ?? ''));
$check($colorSchemeOptions === array_keys(\SLOCloud\Core\ColorScheme::SCHEMES), 'theme.color_scheme options do not match ColorScheme::SCHEMES');
$check(($colorSchemeField['default'] ?? '') === \SLOCloud\Core\ColorScheme::DEFAULT, 'theme.color_scheme default is not ColorScheme::DEFAULT');

// Links are built as BASE_URL . 'page.php'.
Path::setConfiguredBaseUrl('/slo');
$check(Path::getBaseUrl() === '/slo/', 'base URL without trailing slash must gain one');
Path::setConfiguredBaseUrl('/');
$check(Path::getBaseUrl() === '/', 'root base URL must stay /');

if ($failures) {
    fwrite(STDERR, "FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "config_schema_test: OK\n";
