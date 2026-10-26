<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/system/Core/Security.php';
require_once __DIR__ . '/../src/system/Core/Logger.php';
require_once __DIR__ . '/../src/system/Core/Database.php';
require_once __DIR__ . '/../src/system/Core/Authorization.php';
require_once __DIR__ . '/../src/system/Core/Config.php';
require_once __DIR__ . '/../src/system/Core/LoginLimiter.php';
require_once __DIR__ . '/../src/system/Core/PasswordPolicy.php';
require_once __DIR__ . '/../src/system/Core/Encryption.php';
require_once __DIR__ . '/../src/system/Core/StudentEncryption.php';

use SLOCloud\Core\Authorization;
use SLOCloud\Core\Config;
use SLOCloud\Core\Logger;
use SLOCloud\Core\LoginLimiter;
use SLOCloud\Core\PasswordPolicy;
use SLOCloud\Core\Security;
use SLOCloud\Core\StudentEncryption;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$source = static fn(string $path): string => (string)file_get_contents(__DIR__ . '/../' . $path);

// Proxy headers are ignored until the immediate peer is explicitly trusted.
putenv('SLOCLOUD_TRUSTED_PROXIES');
$proxyServer = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_PROTO' => 'https', 'SERVER_PORT' => '80'];
$check(!Security::isHttps($proxyServer), 'Untrusted proxy header affected HTTPS detection.');
putenv('SLOCLOUD_TRUSTED_PROXIES=10.0.0.5');
$check(Security::isHttps($proxyServer), 'Trusted proxy HTTPS detection failed.');
putenv('SLOCLOUD_TRUSTED_PROXIES');

$check(Security::relativeScriptPath([
    'SCRIPT_FILENAME' => 'C:/site/src/administration/users.php',
    'DOCUMENT_ROOT' => 'C:/site',
]) === 'administration/users.php', 'Repository-root route normalization failed.');
$check(str_replace('\\', '/', Security::dataRoot('C:/site/src')) === 'C:/site', 'Repository-layout data root is incorrect.');
$check(str_replace('\\', '/', Security::dataRoot('C:/inetpub/slocloud')) === 'C:/inetpub/slocloud-data', 'Flat-deployment data root is not isolated per application.');

$defaultCsp = Security::contentSecurityPolicy();
foreach ([
    "default-src 'self'",
    "script-src 'self' 'unsafe-inline'",
    "style-src 'self' 'unsafe-inline'",
    "object-src 'none'",
    "base-uri 'self'",
    "form-action 'self'",
    "frame-ancestors 'self'",
] as $directive) {
    $check(str_contains($defaultCsp, $directive), "Default CSP is missing {$directive}.");
}
$ltiCsp = Security::contentSecurityPolicy(["'self'", 'https:']);
$check(str_contains($ltiCsp, "frame-ancestors 'self' https:"), 'LTI CSP does not allow HTTPS LMS framing.');
$check(str_contains($ltiCsp, "object-src 'none'"), 'LTI framing override discarded the shared CSP protections.');
$check(!str_contains(Security::contentSecurityPolicy(["https://lms.example.edu\r\nX-Test: injected"]), 'X-Test'), 'CSP frame sources permit header injection.');

// Every shipped administration endpoint must be explicitly classified.
$constant = (new ReflectionClass(Authorization::class))->getReflectionConstant('ADMIN_ROUTES');
$matrix = $constant ? $constant->getValue() : [];
$adminFiles = array_map('basename', glob(__DIR__ . '/../src/administration/*.php') ?: []);
sort($matrix);
sort($adminFiles);
$check($matrix === $adminFiles, 'Administration route matrix is incomplete or stale.');
$routeMatrix = Authorization::routeMatrix();
$expectedEntryRoutes = ['index.php', 'login.php', 'logout.php', 'session.php'];
foreach (['administration', 'api', 'lti', 'saml', 'setup'] as $directory) {
    foreach (glob(__DIR__ . '/../src/' . $directory . '/*.php') ?: [] as $file) {
        $expectedEntryRoutes[] = $directory . '/' . basename($file);
    }
}
sort($expectedEntryRoutes);
$classifiedRoutes = array_keys($routeMatrix);
sort($classifiedRoutes);
$check($expectedEntryRoutes === $classifiedRoutes, 'Web route-to-permission matrix is incomplete or stale.');
$check(Authorization::policyForRoute('api/new_endpoint.php') === 'deny', 'New routes do not deny by default.');
$check(Authorization::policyForRequest('administration/index.php', 'GET') === 'authenticated', 'Read-only users are locked out of the administration dashboard.');
$check(Authorization::policyForRequest('administration/courses_data.php', 'POST') === 'authenticated', 'Read-only DataTables requests are incorrectly treated as writes.');
$check(Authorization::policyForRequest('administration/courses.php', 'POST') === 'admin', 'Administration mutations are not restricted to administrators.');
$check(Authorization::policyForRequest('administration/users_data.php', 'GET') === 'admin', 'User-management data is not restricted to administrators.');
$check(Authorization::policyForRequest('administration/activity_log.php', 'GET') === 'admin', 'Activity Log is not restricted to administrators.');
// After a session ends and the user signs in again, only an administration page is a valid destination.
if (!defined('BASE_URL')) {
    define('BASE_URL', '/');
}
foreach ([
    'administration/users.php' => '/administration/users.php',
    'https://evil.example/administration/users.php' => '/administration/',
    '//evil.example/x.php' => '/administration/',
    'administration/../login.php' => '/administration/',
    '' => '/administration/',
] as $stored => $expected) {
    $_SESSION['login_return'] = $stored;
    $check(
        Authorization::takeLoginReturn() === $expected && !isset($_SESSION['login_return']),
        "Login return '{$stored}' is not limited to administration pages."
    );
}
$check(Authorization::policyForRequest('api/rotate_key.php', 'POST') === 'admin', 'Key rotation is not restricted to administrators.');
$check(Authorization::policyForRequest('api/move_key_folder.php', 'POST') === 'admin', 'Moving the keys folder is not restricted to administrators.');
$check(Authorization::policyForRequest('administration/color_scheme.php', 'GET') === 'admin', 'Color Scheme is not restricted to administrators.');
$check(Authorization::policyForRequest('administration/color_scheme.php', 'POST') === 'admin', 'Saving the color scheme is not restricted to administrators.');
// Sessions end after 2 hours idle or 20 hours from sign-in (WCAG 2.2.1's 20-hour exception),
// with an explanation on the login page.
$initSource = $source('src/system/includes/init.php');
$check(
    preg_match("/> 7200\)\s*\{\s*\\\\SLOCloud\\\\Core\\\\Authorization::endSession\(/", $initSource) === 1
        && preg_match("/> 72000\)\s*\{\s*\\\\SLOCloud\\\\Core\\\\Authorization::endSession\(/", $initSource) === 1,
    'Sessions do not end after 2 hours idle and 20 hours from sign-in through endSession().'
);

$clean = Logger::sanitizeContext([
    'user_id' => 7,
    'reason' => 'invalid_password',
    'password' => 'canary-password',
    'oauth_signature' => 'canary-signature',
    'unknown' => 'canary-value',
]);
$check($clean['user_id'] === 7 && $clean['reason'] === 'invalid_password', 'Safe structured log fields were lost.');
$check(!str_contains(json_encode($clean), 'canary'), 'Sensitive or unknown log context was not redacted.');
$auditAction = (new ReflectionClass(Logger::class))->getMethod('databaseAuditAction');
$auditAction->setAccessible(true);
$check($auditAction->invoke(null, 'create') === 'INSERT', 'Semantic create audit events are not compatible with the database schema.');
$check($auditAction->invoke(null, 'delete') === 'DELETE', 'Delete audit events are not compatible with the database schema.');
$check($auditAction->invoke(null, 'denied') === 'UPDATE', 'Semantic audit events are not normalized to a database-compatible action.');
$activityLog = $source('src/administration/activity_log.php');
$check(str_contains($activityLog, 'Authorization::isGlobalAdmin'), 'Activity Log does not enforce independent administrator authorization.');
$check(str_contains($activityLog, "report_csv_safe"), 'Activity Log CSV export does not use formula-injection protection.');
$check(!str_contains($activityLog, "old_data") && !str_contains($activityLog, "metadata JSON"), 'Activity Log exposes raw log payload columns.');

$init = $source('src/system/includes/init.php');
$check(!str_contains($init, "SESSION['config_data']"), 'Decrypted configuration is still written to sessions.');
$check(str_contains($init, "'/var/sessions'"), 'Session storage is not outside src/.');
$check(str_contains($init, '$configData[\'logging\'][\'log_directory\'] = $logDirectory;'), 'The centralized logger does not receive the protected runtime log path.');
$check(!str_contains($source('src/api/api.php'), "__DIR__ . '/../../logs'"), 'The status API still checks a repository-level log directory.');
$reportBootstrap = $source('src/system/reporting/bootstrap.php');
$check(str_contains($reportBootstrap, 'Security::dataRoot(dirname(__DIR__, 2))'), 'Report runtime data does not use the deployment-aware protected data root.');
$check(!str_contains($reportBootstrap, "dirname(__DIR__, 3) . '/var"), 'Report runtime data still depends on a repository-level var directory.');

$oauth = $source('src/lti/oauth_validate.php');
$check(!str_contains($oauth, 'HTTP_HOST'), 'OAuth still trusts the Host header.');
$check(str_contains($oauth, "'HMAC-SHA256' => 'sha256'") && str_contains($oauth, "'HMAC-SHA1' => 'sha1'"), 'OAuth compatibility algorithms are not explicitly mapped.');
$check(str_contains($oauth, 'allowed_signature_methods') && str_contains($oauth, 'in_array($oauthMethod, $allowedMethods, true)'), 'OAuth signature algorithms are not controlled by an explicit allowlist.');
$check(!str_contains($oauth, 'consumer_secret:'), 'OAuth source still emits secret-bearing debug output.');

$launch = $source('src/lti/launch.php');
$check(!str_contains($launch, 'lti_raw_post'), 'Raw LTI POST data is still stored in the session.');
$check(!str_contains($launch, 'ALLOWALL'), 'Obsolete X-Frame-Options ALLOWALL remains.');
$check(str_contains($launch, 'Security::contentSecurityPolicy(["\'self\'", \'https:\'])'), 'LTI does not use the shared HTTPS-compatible CSP.');
$check(str_contains($launch, "stripos(\$crn, 'XLS') !== 0"), 'Cross-list pseudo-CRNs can still be auto-created as ordinary sections.');
$assessment = $source('src/lti/assessment.php');
$check(!str_contains($assessment, 'print_r('), 'LTI assessment still renders protocol, query, or student debug data.');
$check(str_contains($assessment, '(time() - $lastLaunchActivity) > $launchTtl') && str_contains($assessment, "unset(\$_SESSION['lti_launches'][\$ltiToken])"), 'LTI assessment tokens are not expired at their point of use.');
$expiredLaunchCheck = strpos($assessment, "if (empty(\$launchData['lti_authenticated']))");
$check($expiredLaunchCheck !== false && strpos($assessment, 'http_response_code(403);', $expiredLaunchCheck) !== false, 'Missing or stale LTI launch contexts do not return HTTP 403.');
$check(str_contains($launch, "['authorized_context']") && str_contains($launch, 'LtiLaunchContext::resolve'), 'LTI launch does not persist its resolved server-side authorization context.');
$check(!str_contains($assessment, "\$_POST['term_code']") && !str_contains($assessment, "\$_POST['crn']") && !str_contains($assessment, "\$_POST['crns']"), 'LTI assessment writes still trust browser-supplied term or CRN ownership fields.');
$check(str_contains($assessment, 'LtiLaunchContext::authorizeSlo') && str_contains($assessment, 'LtiLaunchContext::authorizeEnrollment'), 'LTI assessment does not authorize the full section/SLO/enrollment relationship.');
$check(str_contains($assessment, 'http_response_code($authorizationDenied ? 403 : 400)'), 'LTI authorization failures do not return HTTP 403.');

$studentsData = $source('src/administration/students_data.php');
$dashboard = $source('src/administration/index.php');
$check(!str_contains($studentsData, 'onclick='), 'Student row data still enters an inline event handler.');
$check(str_contains($dashboard, 'htmlspecialchars((string)$selectedTermName'), 'Selected term name is not HTML-escaped.');

$template = $source('src/config/config.yaml.template');
foreach (['slo' . 'pass', 'slocloud_' . 'lti_key', 'slocloud_' . 'lti_secret', 'sloadmin' . '@example.com'] as $fixedDefault) {
    $check(!str_contains($template, $fixedDefault), "Public default remains in configuration template: {$fixedDefault}");
}
$setup = $source('src/setup/index.php');
$check(str_contains($setup, 'setupResolveKeysDirectory') && str_contains($setup, 'setup_code_submit') && str_contains($setup, 'setup_csrf'), 'Setup keys-folder verification or CSRF gate is missing.');
$check(str_contains($setup, "'key_path' => \$keyFile"), 'Setup does not record the key location in config.yaml.');
$check(str_contains($setup, "bin2hex(random_bytes(32));\n") && str_contains($setup, "'consumer_secret' => 'ENC:' . \$ltiConsumerSecretEncrypted"), 'Setup does not generate and encrypt the LTI consumer secret.');
$check(!str_contains($setup, "\$adminPassword = '" . 'slo' . "pass'"), 'Installer still creates the fixed administrator password.');
$check(!str_contains($source('src/system/scripts/create_admin_user.php'), "require_once __DIR__ . '/../../config/config.yaml'"), 'CLI administrator tool directly includes the YAML secret file.');

foreach (['src/.htaccess', 'src/web.config'] as $serverConfig) {
    $contents = strtolower($source($serverConfig));
    foreach (['config', 'sessions', 'logs', 'backups', 'archive', 'var'] as $path) {
        $check(str_contains($contents, $path), "{$serverConfig} does not deny {$path} paths.");
    }
    $check(str_contains($contents, '.md'), "{$serverConfig} does not deny .md documentation files.");
}

$logout = $source('src/logout.php');
$check(str_contains($logout, "REQUEST_METHOD'] !== 'POST'") && str_contains($logout, 'csrf_token'), 'Logout is not POST+CSRF protected.');

// Browser dependencies must remain local, pinned, present, and checksumed.
$externalDependencyHosts = ['cdn.jsdelivr.net', 'cdnjs.cloudflare.com', 'cdn.datatables.net', 'code.jquery.com', 'fonts.googleapis.com'];
$phpFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../src'));
foreach ($phpFiles as $phpFile) {
    if (!$phpFile->isFile() || strtolower($phpFile->getExtension()) !== 'php') continue;
    $contents = (string)file_get_contents($phpFile->getPathname());
    foreach ($externalDependencyHosts as $host) {
        $check(!str_contains(strtolower($contents), $host), $phpFile->getPathname() . " still loads a browser dependency from {$host}.");
    }
    if (preg_match_all('~assets/vendor/([^\"\'?#<]+)~', $contents, $matches)) {
        foreach (array_unique($matches[1]) as $relativeAsset) {
            $check(is_file(__DIR__ . '/../src/assets/vendor/' . $relativeAsset), "Missing vendored browser asset: {$relativeAsset}");
        }
    }
}
$vendorReadme = $source('src/assets/vendor/README.md');
$check(str_contains($vendorReadme, '| AdminLTE | 4.1.0 | `admin-lte` |') && is_file(__DIR__ . '/../src/assets/vendor/admin-lte/4.1.0/js/adminlte.min.js'), 'AdminLTE is not pinned to a stable vendored release.');
$checksumFile = __DIR__ . '/../src/assets/vendor/SHA256SUMS';
$checksumLines = file($checksumFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$check($checksumLines !== [], 'Vendored asset checksum manifest is empty or missing.');
foreach ($checksumLines as $checksumLine) {
    if (!preg_match('/^([a-f0-9]{64})  (.+)$/', $checksumLine, $parts)) {
        $check(false, "Malformed vendor checksum line: {$checksumLine}");
        continue;
    }
    $assetPath = __DIR__ . '/../src/assets/vendor/' . str_replace('/', DIRECTORY_SEPARATOR, $parts[2]);
    $check(is_file($assetPath) && hash_equals($parts[1], hash_file('sha256', $assetPath) ?: ''), "Vendored asset checksum failed: {$parts[2]}");
}

// Secret save/reload remains encrypted at rest, and malformed ciphertext fails closed.
$temporaryDirectory = sys_get_temp_dir() . '/slocloud-security-test-' . bin2hex(random_bytes(6));
mkdir($temporaryDirectory, 0700, true);
file_put_contents($temporaryDirectory . '/config.yaml', "database:\n  password: \"canary-plaintext-secret\"\nlti:\n  validate_signatures: true\nemergency_admin:\n  enabled: false\n");
file_put_contents(
    $temporaryDirectory . '/config.yaml.template',
    "database:\n"
        . "  # @required: true\n"
        . "  # @type: password\n"
        . "  password:\n"
        . "  # @required: false\n"
        . "  # @type: integer\n"
        . "  port:\n"
        . "lti:\n"
        . "  # @required: true\n"
        . "  # @type: boolean\n"
        . "  validate_signatures: true\n"
);
file_put_contents($temporaryDirectory . '/key.yaml', 'encryption_key: ' . str_repeat('a', 64) . "\nstudent_encryption_key: " . str_repeat('b', 64) . "\n");
$resetConfig = static function (): void {
    $property = (new ReflectionClass(Config::class))->getProperty('instance');
    $property->setAccessible(true);
    $property->setValue(null, null);
};
$config = Config::getInstance($temporaryDirectory . '/config.yaml');
$config->set('app.numeric_text', '00123');
$config->set('app.boolean_text', 'false');
$config->set('app.special_text', 'value: # still text');
$config->save();
$savedConfiguration = (string)file_get_contents($temporaryDirectory . '/config.yaml');
$check(str_contains($savedConfiguration, 'ENC:') && !str_contains($savedConfiguration, 'canary-plaintext-secret'), 'Configuration save left a secret in plaintext.');
$resetConfig();
$reloadedConfig = Config::getInstance($temporaryDirectory . '/config.yaml');
$check($reloadedConfig->get('database.password') === 'canary-plaintext-secret', 'Encrypted configuration did not reload correctly.');
$check($reloadedConfig->get('app.numeric_text') === '00123', 'Quoted numeric configuration text changed type or value after save/reload.');
$check($reloadedConfig->get('app.boolean_text') === 'false', 'Quoted boolean-like configuration text changed type after save/reload.');
$check($reloadedConfig->get('app.special_text') === 'value: # still text', 'Special characters were not preserved after configuration save/reload.');
// A failed keys folder move on an older install takes security.key_path back out.
$reloadedConfig->set('security.key_path', '/srv/keys/key.yaml');
$reloadedConfig->remove('security.key_path');
$check(!$reloadedConfig->has('security.key_path') && is_array($reloadedConfig->getRaw('security')), 'Config::remove() did not remove only the named setting.');

file_put_contents($temporaryDirectory . '/config.yaml', "database:\n  password: \"ENC:not-valid-ciphertext\"\nlti:\n  validate_signatures: true\n");
$resetConfig();
$threw = false;
try {
    Config::getInstance($temporaryDirectory . '/config.yaml')->get('database.password');
} catch (RuntimeException) {
    $threw = true;
}
$check($threw, 'Undecryptable configuration did not fail closed.');

file_put_contents($temporaryDirectory . '/config.yaml', "database:\n  password: canary-plaintext-secret\n  port: not-an-integer\nlti:\n  validate_signatures: true\n");
$resetConfig();
$threw = false;
try {
    Config::getInstance($temporaryDirectory . '/config.yaml');
} catch (RuntimeException) {
    $threw = true;
}
$check($threw, 'Configuration schema accepted an invalid integer value.');

// ---------------------------------------------------------------------------
// SEC-008: student lookup hash is keyed when a key is configured, and falls
// back to the pre-SEC-008 unkeyed hash byte-for-byte when it is not, so an
// upgrade does not change matching behavior for an install with no key.
// ---------------------------------------------------------------------------
file_put_contents($temporaryDirectory . '/config.yaml', "database:\n  password: \"canary-plaintext-secret\"\nlti:\n  validate_signatures: true\nemergency_admin:\n  enabled: false\n");
file_put_contents($temporaryDirectory . '/key.yaml', 'encryption_key: ' . str_repeat('a', 64) . "\nstudent_encryption_key: " . str_repeat('b', 64) . "\n");
$resetConfig();
$configWithoutLookupKey = Config::getInstance($temporaryDirectory . '/config.yaml');
$studentEncryptionWithoutKey = new StudentEncryption($configWithoutLookupKey);
$check(
    $studentEncryptionWithoutKey->hashCNumber('C00123456') === hash('sha256', 'C00123456'),
    'Student lookup hash changed for an install with no lookup key configured; existing rows would stop matching on import.'
);

file_put_contents(
    $temporaryDirectory . '/key.yaml',
    'encryption_key: ' . str_repeat('a', 64)
        . "\nstudent_encryption_key: " . str_repeat('b', 64)
        . "\nstudent_lookup_key: " . str_repeat('c', 64) . "\n"
);
$resetConfig();
$configWithLookupKey = Config::getInstance($temporaryDirectory . '/config.yaml');
$studentEncryptionWithKey = new StudentEncryption($configWithLookupKey);
$keyedHash = $studentEncryptionWithKey->hashCNumber('C00123456');
$check($keyedHash !== hash('sha256', 'C00123456'), 'A configured lookup key had no effect; the hash is still the plain unkeyed SHA-256.');
$check($keyedHash === hash_hmac('sha256', 'C00123456', str_repeat('c', 64)), 'The keyed lookup hash is not HMAC-SHA256 over the configured key.');
// Same plaintext, same key, must be deterministic - that is the whole point
// of a lookup hash (search by recomputing, not by comparing to every row).
$check($keyedHash === $studentEncryptionWithKey->hashCNumber('C00123456'), 'The keyed lookup hash is not deterministic for the same input and key.');

// Key reuse across purposes must be rejected: it would let compromise of one
// key recompute the other.
file_put_contents(
    $temporaryDirectory . '/key.yaml',
    'encryption_key: ' . str_repeat('a', 64)
        . "\nstudent_encryption_key: " . str_repeat('b', 64)
        . "\nstudent_lookup_key: " . str_repeat('a', 64) . "\n"
);
$resetConfig();
$reusedKeyThrew = false;
try {
    Config::getInstance($temporaryDirectory . '/config.yaml');
} catch (RuntimeException) {
    $reusedKeyThrew = true;
}
$check($reusedKeyThrew, 'A student lookup key equal to the configuration encryption key was accepted.');

// Rotation depends on the tool and the runtime computing byte-identical
// digests. They must therefore share one implementation, not two that happen
// to agree today - a drift here would silently orphan every rotated row.
$check(
    StudentEncryption::hashCNumberWith('C00123456', str_repeat('c', 64)) === $keyedHash,
    'The static hash helper disagrees with the instance method; a rotation would produce unmatchable digests.'
);
$check(
    StudentEncryption::hashCNumberWith('C00123456', '') === hash('sha256', 'C00123456'),
    'The static hash helper does not reproduce the legacy unkeyed hash, so pre-rotation rows cannot be read.'
);
// Rotating to a different key must actually move the digest, and rotating to
// the same key must be a no-op - that idempotence is what lets an interrupted
// run be repaired by simply re-running it.
$check(
    StudentEncryption::hashCNumberWith('C00123456', str_repeat('d', 64)) !== $keyedHash,
    'Rotating to a different lookup key left the digest unchanged.'
);
$check(
    StudentEncryption::hashCNumberWith('C00123456', str_repeat('c', 64))
        === StudentEncryption::hashCNumberWith('C00123456', str_repeat('c', 64)),
    'Recomputing under the same key is not deterministic, so rotation cannot be idempotent.'
);

$rotateKeyApiSource = $source('src/api/rotate_key.php');
// Every key type rewrites stored data and key.yaml together, so none may run
// against a live site.
$check(
    preg_match('/if \(!MAINTENANCE_MODE\) \{/', $rotateKeyApiSource) === 1,
    'The key rotation API does not require maintenance mode for every key type.'
);

// Moving the keys folder rewrites config.yaml and deletes the old key file:
// administrators only, never the emergency account, never on a live site,
// never alongside a rotation, and never when SLOCloud_KEY_PATH would override it.
$moveKeyFolderSource = $source('src/api/move_key_folder.php');
$check(str_contains($moveKeyFolderSource, "\$action === 'move' && !MAINTENANCE_MODE"), 'Moving the keys folder does not require maintenance mode.');
$check(str_contains($moveKeyFolderSource, "empty(\$_SESSION['is_emergency_admin'])"), 'The emergency admin can move the keys folder.');
$check(str_contains($moveKeyFolderSource, "hash_equals((string)(\$_SESSION['csrf_token']"), 'Moving the keys folder does not check the CSRF token.');
$check(str_contains($moveKeyFolderSource, '/var/key_rotation.lock'), 'A keys folder move can overlap a key rotation.');
$check(str_contains($moveKeyFolderSource, "getenv('SLOCloud_KEY_PATH')"), 'A keys folder move is allowed while SLOCloud_KEY_PATH overrides config.yaml.');

$rotationSource = $source('src/system/scripts/rotate_student_lookup_key.php');
$check(str_contains($rotationSource, "PHP_SAPI !== 'cli'"), 'The rotation tool is not restricted to CLI execution.');
// The tool must not swap the key while any row is still on the old digest, and
// must not run against a live site: both leave lookups unmatchable, which
// surfaces as silent duplicate student records rather than an error.
$check(str_contains($rotationSource, 'maintenance_mode'), 'The rotation tool does not check maintenance mode.');
$check(
    str_contains($rotationSource, 'Refusing to swap the key while rows remain unconvertible'),
    'The rotation tool would swap the key even with rows it could not convert.'
);
$check(str_contains($rotationSource, 'HAVING COUNT(*) > 1'), 'The rotation tool does not verify digest uniqueness before swapping the key.');
// The C-number is the identifier this whole scheme exists to conceal; failures
// must be reported by primary key only.
$check(
    !preg_match('/rotation_say\([^)]*\$cNumber/', $rotationSource),
    'The rotation tool prints plaintext C-numbers.'
);

$setupSourceForLookupKey = $source('src/setup/index.php');
$check(str_contains($setupSourceForLookupKey, 'student_lookup_key'), 'Setup does not generate a student lookup key for fresh installs.');
$check(
    str_contains($setupSourceForLookupKey, 'bin2hex(random_bytes(32))') && substr_count($setupSourceForLookupKey, 'bin2hex(random_bytes(32))') >= 3,
    'Setup does not generate the student lookup key with the same randomness as the other master keys.'
);

$resetConfig();
@unlink($temporaryDirectory . '/config.yaml');
@unlink($temporaryDirectory . '/config.yaml.template');
@unlink($temporaryDirectory . '/key.yaml');
@unlink($temporaryDirectory . '/config.yaml.bak');
@rmdir($temporaryDirectory);

// ---------------------------------------------------------------------------
// SEC-001: secret-looking configuration must never reach session storage.
// ---------------------------------------------------------------------------
$cleanSession = [
    'user_id' => 7,
    'user_email' => 'faculty@example.edu',
    'csrf_token' => bin2hex(random_bytes(8)),
    'selected_term' => ['term_fk' => 12, 'term_code' => '202601'],
];
$check(Security::findSessionSecrets($cleanSession) === [], 'A legitimate session was reported as holding secrets.');

$leakySession = $cleanSession + [
    'config_data' => [
        'database' => ['host' => 'localhost', 'password' => 'canary-db-password'],
        'email' => ['smtp_password' => 'canary-smtp-password'],
        'lti' => ['consumer_secret' => 'canary-lti-secret'],
    ],
];
$leaks = Security::findSessionSecrets($leakySession);
$check(in_array('config_data.database.password', $leaks, true), 'A database password in session storage was not detected.');
$check(in_array('config_data.email.smtp_password', $leaks, true), 'An SMTP password in session storage was not detected.');
$check(in_array('config_data.lti.consumer_secret', $leaks, true), 'An LTI consumer secret in session storage was not detected.');

// ---------------------------------------------------------------------------
// SEC-012: HSTS stays opt-in and is never sent over a plaintext connection.
// ---------------------------------------------------------------------------
putenv('SLOCLOUD_HSTS_MAX_AGE');
$check(Security::hstsMaxAge() === 0, 'HSTS is enabled without explicit configuration.');
putenv('SLOCLOUD_HSTS_MAX_AGE=not-a-number');
$check(Security::hstsMaxAge() === 0, 'A malformed HSTS max-age was accepted.');
putenv('SLOCLOUD_HSTS_MAX_AGE=31536000');
$check(Security::hstsMaxAge() === 31536000, 'A configured HSTS max-age was not honoured.');
putenv('SLOCLOUD_HSTS_MAX_AGE');

// ---------------------------------------------------------------------------
// SEC-012: the login limiter counts both signals, escalates, and resets safely.
// ---------------------------------------------------------------------------
$limiterDirectory = sys_get_temp_dir() . '/slocloud-limiter-' . bin2hex(random_bytes(6));
$limiter = new LoginLimiter($limiterDirectory);

$ipKeys = LoginLimiter::keysFor('203.0.113.7', 'victim@example.edu');
$check(count($ipKeys) === 2, 'Limiter did not derive both an address and an account bucket.');
$check(!str_contains(implode('|', $ipKeys), 'victim@example.edu'), 'Limiter key exposes the account identifier.');
$check(
    LoginLimiter::keysFor('203.0.113.7', 'Victim@Example.edu')[1] === $ipKeys[1],
    'Account buckets are case sensitive, letting case changes bypass the limit.'
);

$check($limiter->retryAfter($ipKeys) === 0, 'A fresh bucket was already limited.');
for ($attempt = 0; $attempt < 3; $attempt++) {
    $limiter->recordFailure($ipKeys);
}
$check($limiter->retryAfter($ipKeys) === 0, 'The free attempt allowance was not honoured.');
$firstLock = $limiter->recordFailure($ipKeys);
$check($firstLock > 0, 'Exceeding the allowance did not produce a lock.');
$secondLock = $limiter->recordFailure($ipKeys);
$check($secondLock > $firstLock, 'The lock did not escalate with repeated failures.');

// A different address targeting the same account is still blocked by the
// shared account bucket - the distributed-guessing case.
$otherAddressSameAccount = LoginLimiter::keysFor('198.51.100.9', 'victim@example.edu');
$check($limiter->retryAfter($otherAddressSameAccount) > 0, 'Account lockout did not follow the account across addresses.');

// An unrelated account from an unrelated address is unaffected.
$check($limiter->retryAfter(LoginLimiter::keysFor('198.51.100.9', 'other@example.edu')) === 0, 'Limiter blocked an unrelated account.');

$limiter->clear($ipKeys);
$check($limiter->retryAfter($ipKeys) === 0, 'A successful authentication did not clear the limiter.');
foreach (glob($limiterDirectory . '/*') ?: [] as $leftover) {
    @unlink($leftover);
}
@rmdir($limiterDirectory);

// ---------------------------------------------------------------------------
// SEC-012: password policy rejects short, default, and self-referential values.
// ---------------------------------------------------------------------------
$check(PasswordPolicy::firstProblem('correct horse battery staple') === null, 'A long passphrase was rejected.');
$check(PasswordPolicy::firstProblem('Short1!') !== null, 'A password below the minimum length was accepted.');
$check(PasswordPolicy::firstProblem('password1234') !== null, 'A blocklisted password was accepted.');
$check(PasswordPolicy::firstProblem('slopass') !== null, 'The shipped default password was accepted.');
$check(PasswordPolicy::firstProblem('PASSWORD1234') !== null, 'Blocklist matching is case sensitive.');
$check(
    PasswordPolicy::firstProblem('sloadmin-is-my-password', ['sloadmin@example.com', 'SLO Admin']) !== null,
    'A password containing the account identifier was accepted.'
);
$check(
    !str_contains(strtolower((string)PasswordPolicy::firstProblem('short')), 'uppercase'),
    'Password guidance reintroduced composition rules.'
);

// ---------------------------------------------------------------------------
// SEC-006/SEC-012: throttle state never lands in a web-reachable directory.
// ---------------------------------------------------------------------------
$loginSource = $source('src/login.php');
$check(!str_contains($loginSource, "__DIR__ . '/sessions/"), 'Login throttle state is written inside the document root.');
$check(str_contains($loginSource, 'LoginLimiter'), 'Login does not use the shared limiter.');
$oauthSource = $source('src/lti/oauth_validate.php');
$check(str_contains($oauthSource, 'LoginLimiter'), 'Failed LTI launches are not rate limited.');
$check(str_contains($oauthSource, 'lti_nonces'), 'LTI replay protection is missing.');
$check(str_contains($oauthSource, "lti_auth_fail('nonce_replay')"), 'A replayed LTI launch is not rejected.');
$check(str_contains($oauthSource, "\$logger->security("), 'Failed LTI launches are not recorded in the security log.');

// The de-duplication token must be the signature. It is the only submitted
// value an attacker has to change in order to make a new guess; keying on a
// value they choose freely (nonce, timestamp) would let them pin it and
// collapse every guess into one counted failure.
$check(
    str_contains($oauthSource, "\$_POST['oauth_signature']") && str_contains($oauthSource, 'recordFailure('),
    'The LTI throttle does not de-duplicate on the submitted signature.'
);
$check(
    !preg_match('/recordFailure\([^)]*oauth_nonce/s', $oauthSource),
    'The LTI throttle de-duplicates on the attacker-chosen nonce, which lets a guesser pin it and avoid being counted.'
);

// The LTI lock must be flat, not escalating: a lockout here hits every user of
// the institution, so repeated failures must not compound the outage.
$check(
    str_contains($oauthSource, 'LTI_THROTTLE_FREE_ATTEMPTS')
        && str_contains($oauthSource, 'LTI_THROTTLE_LOCK_SECONDS')
        && str_contains($oauthSource, 'LTI_THROTTLE_WINDOW_SECONDS'),
    'The LTI throttle no longer states its own thresholds.'
);

// The LTI throttle must key on the source address ONLY. The consumer key is
// shared by every user of the institution, so using it as a second bucket would
// let an attacker who knows it burn the allowance from anywhere and lock
// everyone out - a remote denial of service against the whole integration.
$check(
    !preg_match('/keysFor\([^)]*oauth_consumer_key/s', $oauthSource),
    'The LTI throttle buckets on the shared consumer key, so an attacker can lock out the whole institution.'
);

// Only genuine authentication failures may count toward the LTI throttle.
require_once __DIR__ . '/../src/lti/throttled_failure_reasons.php';
$check(
    in_array('signature_mismatch', LTI_THROTTLED_FAILURE_REASONS, true)
        && in_array('invalid_consumer', LTI_THROTTLED_FAILURE_REASONS, true)
        && in_array('nonce_replay', LTI_THROTTLED_FAILURE_REASONS, true),
    'Credential-guessing failures are not counted toward the LTI throttle.'
);
foreach ([
    'launch_rate_limited',   // counting this lets a retrying client ratchet its own lock
    'expired_or_invalid_nonce', // fires on ordinary clock drift
    'integration_not_configured',
    'canonical_launch_url_not_configured',
    'signature_method_allowlist_not_configured',
    'unsupported_signature_method',
    'validation_disabled_outside_tests',
    'missing_oauth_parameter',
    'invalid_parameter_shape',
] as $operationalReason) {
    $check(
        !in_array($operationalReason, LTI_THROTTLED_FAILURE_REASONS, true),
        "LTI launches are throttled on '{$operationalReason}', so a misconfiguration or clock problem locks out the institution."
    );
}

// ---------------------------------------------------------------------------
// Limiter behaviour: de-duplication and flat locks.
// ---------------------------------------------------------------------------
$dedupDirectory = sys_get_temp_dir() . '/slocloud-dedup-' . bin2hex(random_bytes(6));
$flatLimiter = new LoginLimiter($dedupDirectory, 5, 300, 300);
$dedupKeys = LoginLimiter::keysFor('203.0.113.20', 'shared-consumer-key');

// One request retried many times is one attempt, however often it arrives.
for ($retry = 0; $retry < 40; $retry++) {
    $flatLimiter->recordFailure($dedupKeys, 'identical-signature-value');
}
$check($flatLimiter->retryAfter($dedupKeys) === 0, 'A single retried request drove the throttle into a lockout.');

// Distinct attempts still count, and still lock once the allowance is spent.
for ($guess = 0; $guess < 5; $guess++) {
    $flatLimiter->recordFailure($dedupKeys, 'distinct-signature-' . $guess);
}
$flatLock = $flatLimiter->recordFailure($dedupKeys, 'distinct-signature-final');
$check($flatLock > 0, 'Distinct failed attempts did not reach the throttle.');

// The window stays flat: further failures must not compound the outage.
for ($extra = 0; $extra < 10; $extra++) {
    $laterLock = $flatLimiter->recordFailure($dedupKeys, 'extra-signature-' . $extra);
}
$check($laterLock <= 300, 'The LTI-style lock escalated instead of staying flat.');

$flatLimiter->clear($dedupKeys);
$check($flatLimiter->retryAfter($dedupKeys) === 0, 'A validated launch did not clear the throttle.');

// The counting window must actually roll: failures older than the window stop
// counting, so the allowance means "N within the window" and not "N ever".
$windowLimiter = new LoginLimiter($dedupDirectory, 2, 1, 1, 1);
$windowKeys = LoginLimiter::keysFor('203.0.113.30');
$windowLimiter->recordFailure($windowKeys, 'aged-attempt-1');
$windowLimiter->recordFailure($windowKeys, 'aged-attempt-2');
$check($windowLimiter->recordFailure($windowKeys, 'aged-attempt-3') > 0, 'Failures inside the window did not reach the allowance.');
sleep(2);
$check($windowLimiter->retryAfter($windowKeys) === 0, 'A lock outlived its window.');
$windowLimiter->recordFailure($windowKeys, 'fresh-attempt-1');
$check(
    $windowLimiter->retryAfter($windowKeys) === 0,
    'Failures older than the window still count, so the allowance never refreshes.'
);

foreach (glob($dedupDirectory . '/*') ?: [] as $leftover) {
    @unlink($leftover);
}
@rmdir($dedupDirectory);

// ---------------------------------------------------------------------------
// SEC-012: session fixation. A session must not keep the ID it had before
// authentication - otherwise an attacker who plants a known ID in a victim's
// browser (a fixation attack) can hijack the session the moment it becomes
// authenticated, without ever learning the post-auth ID.
//
// This tests the actual rotation primitive, Security::rotateSessionIfDue(),
// with a real live session in this process - not a source-presence check.
// ---------------------------------------------------------------------------
$sessionSavePath = sys_get_temp_dir() . '/slocloud-session-test-' . bin2hex(random_bytes(6));
mkdir($sessionSavePath, 0700, true);
session_save_path($sessionSavePath);
// Pin a known starting ID, standing in for one an attacker fixed before login.
session_id(bin2hex(random_bytes(16)));
@session_start();
$fixedId = session_id();
Security::rotateSessionIfDue(); // No session_rotated_at yet - must rotate immediately.
$check(session_id() !== $fixedId, 'A fresh session was not rotated, so a pre-authentication (attacker-fixed) session ID could survive into an authenticated session.');
$rotatedId = session_id();
Security::rotateSessionIfDue(); // Just rotated - must not rotate again immediately.
$check(session_id() === $rotatedId, 'Rotation ignored its interval and rotated on every call, which would churn every server-side session record needlessly.');
$_SESSION['session_rotated_at'] = time() - 1000; // Simulate the interval having elapsed.
Security::rotateSessionIfDue(900);
$check(session_id() !== $rotatedId, 'Rotation did not occur once its interval had elapsed, so a long-lived session ID never gets refreshed.');
session_write_close();
foreach (glob($sessionSavePath . '/*') ?: [] as $leftover) {
    @unlink($leftover);
}
@rmdir($sessionSavePath);

$check(
    substr_count($source('src/login.php'), 'session_regenerate_id(true)') === 2,
    'Login does not regenerate the session ID with old-session deletion on both the normal and emergency-admin authentication paths.'
);

// ---------------------------------------------------------------------------
// SEC-012: proxy-aware cookie attributes. Security::configureSession() can
// only run once per process (it no-ops if a session is already active), so
// each scenario runs in its own subprocess. This is a live test of the actual
// function - it asserts on the real session_get_cookie_params() PHP computed,
// not on the presence of a pattern in the source.
//
// The property under test: an untrusted intermediary claiming HTTPS over
// X-Forwarded-Proto must NOT be able to talk the application into issuing a
// non-Secure-attribute cookie's protection as if the connection were secure,
// while a genuinely trusted reverse proxy terminating TLS must be recognized.
// ---------------------------------------------------------------------------
$cookieProbeDir = sys_get_temp_dir() . '/slocloud-cookie-probe-' . bin2hex(random_bytes(6));
mkdir($cookieProbeDir, 0700, true);
$cookieProbeScript = $cookieProbeDir . '/probe.php';
file_put_contents($cookieProbeScript, '<?php
declare(strict_types=1);
require ' . var_export(__DIR__ . '/../src/system/Core/Security.php', true) . ';
$_SERVER["REMOTE_ADDR"] = getenv("PROBE_REMOTE_ADDR") ?: "";
$_SERVER["HTTP_X_FORWARDED_PROTO"] = getenv("PROBE_FORWARDED_PROTO") ?: "";
$_SERVER["SERVER_PORT"] = getenv("PROBE_SERVER_PORT") ?: "80";
if (getenv("PROBE_SCRIPT_NAME") !== false) { $_SERVER["SCRIPT_NAME"] = getenv("PROBE_SCRIPT_NAME"); }
if (getenv("PROBE_POST_KEY") !== false) { $_POST["oauth_consumer_key"] = getenv("PROBE_POST_KEY"); }
\SLOCloud\Core\Security::configureSession(getenv("PROBE_STORAGE_PATH"));
echo json_encode(session_get_cookie_params());
');

/**
 * Run the cookie probe in a fresh PHP process under the given environment.
 *
 * @param array<string,string> $env
 * @return array{lifetime:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string}|null
 */
$probeSessionCookie = static function (array $env) use ($cookieProbeScript, $cookieProbeDir): ?array {
    $storagePath = $cookieProbeDir . '/store-' . bin2hex(random_bytes(4));
    $env['PROBE_STORAGE_PATH'] = $storagePath;
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        [PHP_BINARY, $cookieProbeScript],
        $descriptors,
        $pipes,
        null,
        array_merge($_ENV, $env)
    );
    if (!is_resource($process)) {
        return null;
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $decoded = json_decode((string)$stdout, true);
    return is_array($decoded) ? $decoded : null;
};

$plainHttp = $probeSessionCookie(['PROBE_REMOTE_ADDR' => '203.0.113.9']);
$check($plainHttp !== null && $plainHttp['secure'] === false, 'A plain HTTP request was treated as secure.');

$untrustedProxyClaim = $probeSessionCookie([
    'PROBE_REMOTE_ADDR' => '203.0.113.9',
    'PROBE_FORWARDED_PROTO' => 'https',
]);
$check(
    $untrustedProxyClaim !== null && $untrustedProxyClaim['secure'] === false,
    'An X-Forwarded-Proto: https claim from an untrusted address made the session cookie non-Secure, or was wrongly trusted - either way the untrusted claim changed the outcome it must not affect.'
);

$trustedProxyClaim = $probeSessionCookie([
    'PROBE_REMOTE_ADDR' => '203.0.113.9',
    'PROBE_FORWARDED_PROTO' => 'https',
    'SLOCLOUD_TRUSTED_PROXIES' => '203.0.113.9',
]);
$check(
    $trustedProxyClaim !== null && $trustedProxyClaim['secure'] === true,
    'A trusted reverse proxy terminating TLS was not recognized, so the session cookie was not marked Secure behind a legitimate proxy.'
);

$productionNoProxy = $probeSessionCookie(['SLOCLOUD_ENV' => 'production']);
$check(
    $productionNoProxy !== null && $productionNoProxy['secure'] === true,
    'Production did not force the Secure cookie attribute even without confirmed HTTPS detection, risking a cleartext session cookie.'
);

$ltiSecure = $probeSessionCookie([
    'PROBE_REMOTE_ADDR' => '203.0.113.9',
    'PROBE_FORWARDED_PROTO' => 'https',
    'SLOCLOUD_TRUSTED_PROXIES' => '203.0.113.9',
    'PROBE_POST_KEY' => 'some-consumer-key',
]);
$check(
    $ltiSecure !== null && $ltiSecure['samesite'] === 'None',
    'A secure LTI launch did not relax SameSite to None, which breaks embedding in the parent LMS iframe.'
);
$nonLtiSecure = $probeSessionCookie([
    'PROBE_REMOTE_ADDR' => '203.0.113.9',
    'PROBE_FORWARDED_PROTO' => 'https',
    'SLOCLOUD_TRUSTED_PROXIES' => '203.0.113.9',
]);
$check(
    $nonLtiSecure !== null && $nonLtiSecure['samesite'] === 'Strict',
    'A non-LTI dashboard session did not default to SameSite=Strict.'
);

foreach (glob($cookieProbeDir . '/*') ?: [] as $leftover) {
    if (is_dir($leftover)) {
        foreach (glob($leftover . '/*') ?: [] as $inner) {
            @unlink($inner);
        }
        @rmdir($leftover);
    } else {
        @unlink($leftover);
    }
}
@rmdir($cookieProbeDir);

if ($failures !== []) {
    fwrite(STDERR, "Security tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Security tests passed.\n";
