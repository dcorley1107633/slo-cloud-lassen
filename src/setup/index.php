<?php
declare(strict_types=1);

/**
 * SLOCloud Web Setup Interface
 * 
 * Browser-based installation wizard for configuring SLOCloud.
 * Creates database and saves configuration to config.yaml.
 * 
 * @package SLOCloud\Setup
 */

// Load path helper for proper redirects
require_once __DIR__ . '/../system/Core/Path.php';
require_once __DIR__ . '/../system/Core/Security.php';
require_once __DIR__ . '/../system/Core/PasswordPolicy.php';
require_once __DIR__ . '/../system/Core/Config.php';

// Define base URL for easy access in this script
define('BASE_URL', \SLOCloud\Core\Path::getBaseUrl());
\SLOCloud\Core\Security::sendResponseHeaders();

function setupRequireYamlExtension(): void {
    if (!function_exists('yaml_parse') || !function_exists('yaml_emit')) {
        throw new RuntimeException('The required PHP YAML extension (ext-yaml) is not loaded.');
    }
}

/**
 * Every page validates config.yaml against the template's rules, so a value the
 * rules reject would leave a finished install where no page loads. Check the
 * submitted values against the same rules before anything is created.
 */
function setupConfigProblem(array $config): ?string {
    try {
        \SLOCloud\Core\Config::validateAgainstTemplate($config, dirname(__DIR__) . '/config/config.yaml.template');
        return null;
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
}

function setupEmitYaml(array $data): string {
    $yaml = yaml_emit($data, YAML_UTF8_ENCODING, YAML_LN_BREAK);
    if (!is_string($yaml)) {
        throw new RuntimeException('Failed to serialize YAML configuration.');
    }
    return $yaml;
}

function setupAtomicWrite(string $path, string $content, int $mode = 0600): bool {
    $directory = dirname($path);
    $temporary = tempnam($directory, '.slocloud-');
    if ($temporary === false) return false;
    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        @unlink($temporary);
        return false;
    }
    @chmod($temporary, $mode);
    if (!@rename($temporary, $path)) {
        @unlink($temporary);
        return false;
    }
    @chmod($path, $mode);
    return true;
}

// Load template defaults
function getTemplateDefaults(): array {
    $templateFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.yaml.template';
    if (!file_exists($templateFile)) {
        return [];
    }
    if (!function_exists('yaml_parse')) {
        return [];
    }
    
    $content = file_get_contents($templateFile);
    if ($content === false) {
        throw new RuntimeException('Cannot read the configuration template.');
    }

    $parsed = yaml_parse($content);
    if (!is_array($parsed)) {
        throw new RuntimeException('The configuration template is not valid YAML.');
    }

    $defaults = [];
    $flatten = static function (array $values) use (&$flatten, &$defaults): void {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $flatten($value);
            } elseif ($value !== null) {
                $defaults[(string)$key] = $value;
            }
        }
    };
    $flatten($parsed);
    
    return $defaults;
}

$templateDefaults = getTemplateDefaults();

// Prevent setup from running if already configured
$configFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.yaml';
if (file_exists($configFile)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Setup Unavailable</title>'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<link rel="stylesheet" href="../system/accessibility.css"><script src="../system/accessibility.js" defer></script>'
        . '<style>body{font-family:sans-serif;max-width:520px;margin:80px auto;padding:0 1rem;color:#333}'
        . 'h1{color:#c0392b}a{color:#1976D2}</style></head><body>'
        . '<a class="skip-link" href="#main">Skip to main content</a><main id="main" tabindex="-1"><h1>Setup Already Complete</h1>'
        . '<p>SLOCloud is already configured. The setup wizard cannot be run again while '
        . '<code>config.yaml</code> exists.</p>'
        . '<p>To access the administration panel, <a href="' . htmlspecialchars(\SLOCloud\Core\Path::getBaseUrl()) . 'login.php">log in here</a>.</p>'
        . '<p>To reinstall, remove <code>src/config/config.yaml</code> first.</p></main>'
        . '</body></html>';
    exit;
}

// Setup is local/allowlisted.
$clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$allowedIps = ['127.0.0.1', '::1'];
$allowedIps = array_merge($allowedIps, array_filter(array_map('trim', explode(',', (string)getenv('SLOCLOUD_SETUP_ALLOWED_IPS')))));
if (!in_array($clientIp, $allowedIps, true)) {
    http_response_code(403);
    exit('Setup is not permitted from this address.');
}

$applicationDataRoot = \SLOCloud\Core\Security::dataRoot(dirname(__DIR__));
\SLOCloud\Core\Security::configureSession($applicationDataRoot . '/var/sessions');
if (empty($_SESSION['setup_csrf'])) {
    $_SESSION['setup_csrf'] = bin2hex(random_bytes(32));
}

const SETUP_CODE_FILE = 'slocloud-setup-code.txt';
const SETUP_CODE_TTL = 900;
const SETUP_CODE_MAX_ATTEMPTS = 5;

/**
 * Resolve the folder that will hold key.yaml, or explain why it cannot be used.
 * The folder must already exist, be writable, and sit outside everything the
 * web server serves, so the keys can never be fetched over HTTP.
 *
 * @return array{0: ?string, 1: ?string} [resolved path, problem]
 */
function setupResolveKeysDirectory(string $path): array {
    $path = trim($path);
    if ($path === '') {
        return [null, 'Enter the folder that will hold the encryption keys.'];
    }
    if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', $path) !== 1) {
        return [null, 'Enter a full path, such as D:\\slocloud\\keys or /etc/slocloud.'];
    }
    $resolved = realpath($path);
    if ($resolved === false || !is_dir($resolved)) {
        return [null, 'That folder does not exist. Create it on the server first.'];
    }
    if (!is_writable($resolved)) {
        return [null, 'The web server account cannot write to that folder.'];
    }

    $normalize = static function (string $directory): string {
        $directory = rtrim(str_replace('\\', '/', $directory), '/') . '/';
        return PHP_OS_FAMILY === 'Windows' ? strtolower($directory) : $directory;
    };
    $candidate = $normalize($resolved);
    foreach ([dirname(__DIR__), (string)($_SERVER['DOCUMENT_ROOT'] ?? '')] as $root) {
        $resolvedRoot = $root !== '' ? realpath($root) : false;
        if ($resolvedRoot !== false && str_starts_with($candidate, $normalize($resolvedRoot))) {
            return [null, 'That folder is inside the web root. Choose one the web server does not serve.'];
        }
    }
    return [$resolved, null];
}

// Before anything is installed, the operator names a folder outside the web
// root for key.yaml and pastes back a code that setup writes there. That shows
// the person at the browser can read the server's disk, and gives the keys a
// home that is never served, so nothing has to be moved after setup.
$gateError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_SESSION['setup_authorized'])
    && (isset($_POST['setup_keys_submit']) || isset($_POST['setup_code_submit']) || isset($_POST['setup_change_directory']))) {
    $submittedCsrf = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    if ($submittedCsrf === '' || !hash_equals((string)$_SESSION['setup_csrf'], $submittedCsrf)) {
        http_response_code(403);
        exit('Invalid setup request.');
    }

    if (isset($_POST['setup_change_directory'])) {
        if (is_array($_SESSION['setup_pending'] ?? null)) {
            @unlink($_SESSION['setup_pending']['directory'] . DIRECTORY_SEPARATOR . SETUP_CODE_FILE);
        }
        unset($_SESSION['setup_pending']);
    } elseif (isset($_POST['setup_keys_submit'])) {
        [$keysDirectory, $gateError] = setupResolveKeysDirectory(
            is_string($_POST['keys_directory'] ?? null) ? $_POST['keys_directory'] : ''
        );
        if ($keysDirectory !== null) {
            $code = strtoupper(bin2hex(random_bytes(6)));
            if (@file_put_contents($keysDirectory . DIRECTORY_SEPARATOR . SETUP_CODE_FILE, $code . PHP_EOL, LOCK_EX) === false) {
                $gateError = 'Could not write the verification code to that folder.';
            } else {
                $_SESSION['setup_pending'] = [
                    'directory' => $keysDirectory,
                    'code_hash' => hash('sha256', $code),
                    'expires_at' => time() + SETUP_CODE_TTL,
                    'attempts' => 0,
                ];
            }
        }
    } else {
        $pending = is_array($_SESSION['setup_pending'] ?? null) ? $_SESSION['setup_pending'] : null;
        $submittedCode = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string)($_POST['setup_code'] ?? '')) ?? '');
        if ($pending === null || (int)$pending['expires_at'] < time()) {
            unset($_SESSION['setup_pending']);
            $gateError = 'The verification code expired. Enter the keys folder again.';
        } elseif ($submittedCode !== '' && hash_equals((string)$pending['code_hash'], hash('sha256', $submittedCode))) {
            @unlink($pending['directory'] . DIRECTORY_SEPARATOR . SETUP_CODE_FILE);
            unset($_SESSION['setup_pending']);
            session_regenerate_id(true);
            $_SESSION['setup_authorized'] = true;
            $_SESSION['setup_keys_directory'] = $pending['directory'];
        } elseif ((int)$pending['attempts'] + 1 >= SETUP_CODE_MAX_ATTEMPTS) {
            @unlink($pending['directory'] . DIRECTORY_SEPARATOR . SETUP_CODE_FILE);
            unset($_SESSION['setup_pending']);
            $gateError = 'Too many incorrect codes. Enter the keys folder again to get a new one.';
        } else {
            $_SESSION['setup_pending']['attempts'] = (int)$pending['attempts'] + 1;
            $gateError = 'That code does not match the one in ' . SETUP_CODE_FILE . '.';
        }
    }
}
$setupAuthorized = !empty($_SESSION['setup_authorized'])
    && is_string($_SESSION['setup_keys_directory'] ?? null);
$setupPending = is_array($_SESSION['setup_pending'] ?? null) ? $_SESSION['setup_pending'] : null;

// Process form submission
$error = null;
$success = false;
$step = $_GET['step'] ?? 'form';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup_submit']) && $setupAuthorized) {
    $submittedCsrf = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    if ($submittedCsrf === '' || !hash_equals((string)$_SESSION['setup_csrf'], $submittedCsrf)) {
        http_response_code(403);
        exit('Invalid setup request.');
    }
    // Validate and sanitize input
    $site_name = trim($_POST['site_name'] ?? 'SLOCloud');
    $base_url = trim($_POST['base_url'] ?? BASE_URL);
    if ($base_url !== '' && !str_ends_with($base_url, '/')) {
        $base_url .= '/'; // Links are built as BASE_URL . 'path'
    }
    $db_driver = trim($_POST['db_driver'] ?? 'mysql');
    $db_host = trim($_POST['db_host'] ?? '');
    $db_port = filter_var($_POST['db_port'] ?? 3306, FILTER_VALIDATE_INT);
    $db_name = trim($_POST['db_name'] ?? '');
    $db_prefix = trim($_POST['db_prefix'] ?? '');
    $db_user = trim($_POST['db_user'] ?? '');
    $db_pass = $_POST['db_pass'] ?? ''; // Don't trim password
    
    $admin_name = trim($_POST['admin_name'] ?? '');
    $admin_email = trim($_POST['admin_email'] ?? '');
    $admin_password = is_string($_POST['admin_password'] ?? null) ? $_POST['admin_password'] : '';

    // Email Configuration (optional)
    $mail_method = trim($_POST['mail_method'] ?? 'disabled');
    if ($mail_method === 'server') {
        $mail_method = 'mail'; // Older form value for PHP mail()
    }
    $smtp_host = trim($_POST['smtp_host'] ?? '');
    $smtp_port = filter_var($_POST['smtp_port'] ?? 587, FILTER_VALIDATE_INT);
    $smtp_user = trim($_POST['smtp_user'] ?? '');
    $smtp_pass = $_POST['smtp_pass'] ?? ''; // Don't trim password
    $smtp_from_email = trim($_POST['smtp_from_email'] ?? '');
    $smtp_from_name = trim($_POST['smtp_from_name'] ?? $site_name);
    $smtp_encryption = trim($_POST['smtp_encryption'] ?? 'tls');
    
    // Validation
    if (empty($db_host) || empty($db_name) || empty($db_user)) {
        $error = 'Database host, name, and username are required.';
    } elseif ($admin_name === '' || !filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
        $error = 'An administrator name and valid email address are required.';
    } elseif (($adminPasswordProblem = \SLOCloud\Core\PasswordPolicy::firstProblem(
        $admin_password,
        [$admin_email, $admin_name]
    )) !== null) {
        $error = $adminPasswordProblem;
    } elseif (!in_array($db_driver, ['mysql', 'mssql'])) {
        $error = 'Invalid database driver. Must be mysql or mssql.';
    } elseif (!in_array($mail_method, ['disabled', 'mail', 'smtp'], true)) {
        $error = 'Invalid email delivery method.';
    } elseif (!in_array($smtp_encryption, ['tls', 'ssl', 'none'], true)) {
        $error = 'Invalid SMTP encryption mode.';
    } elseif (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,62}$/', $db_name)) {
        $error = 'Database name must start with a letter and contain only letters, numbers, and underscores.';
    } elseif (!empty($db_prefix) && !preg_match('/^[a-zA-Z0-9_]+$/', $db_prefix)) {
        $error = 'Table prefix can only contain letters, numbers, and underscores.';
    } elseif (!empty($db_prefix) && $db_prefix !== 'slocloud_tbl_' && str_contains($db_prefix, 'slocloud_tbl_')) {
        $error = 'Table prefix must not embed the default prefix (slocloud_tbl_). Use it exactly, or choose a prefix that does not contain it.';
    } elseif (empty($base_url) || !str_starts_with($base_url, '/')) {
        $error = 'Base URL is required and must start with /';
    } elseif ($db_port === false || $db_port < 1 || $db_port > 65535) {
        $error = 'Invalid port number. Must be between 1 and 65535.';
    } elseif ($mail_method === 'smtp' && ($smtp_port === false || $smtp_port < 1 || $smtp_port > 65535)) {
        $error = 'Invalid SMTP port. Must be between 1 and 65535.';
    } elseif (($configProblem = setupConfigProblem([
        'database' => ['driver' => $db_driver, 'host' => $db_host, 'port' => (int)$db_port,
            'name' => $db_name, 'prefix' => $db_prefix, 'username' => $db_user],
        'app' => ['name' => $site_name, 'base_url' => $base_url],
        'email' => ['method' => $mail_method, 'from_email' => $smtp_from_email, 'from_name' => $smtp_from_name,
            'smtp_host' => $smtp_host, 'smtp_port' => (int)($smtp_port ?: ($templateDefaults['smtp_port'] ?? 587)),
            'smtp_username' => $smtp_user, 'smtp_encryption' => $smtp_encryption],
    ])) !== null) {
        $error = $configProblem . ' Correct it and submit again.';
    } else {
        // Attempt connection with PDO (supports both MySQL and MSSQL)
        try {
            // Build DSN based on driver
            if ($db_driver === 'mssql' || $db_driver === 'sqlsrv') {
                $dsn = sprintf('sqlsrv:Server=%s,%d;Encrypt=yes;TrustServerCertificate=yes', $db_host, $db_port);
            } else {
                $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $db_host, $db_port);
            }
            
            $pdo = new PDO($dsn, $db_user, $db_pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);
            
        } catch (PDOException $e) {
            $error = 'Database connection failed. Check your host, port, credentials, and database name.';
            $pdo = null;
        }
        
        if ($pdo !== null) {
            // Connection successful - try to create database (may fail on shared hosting)
            $createSuccess = false;
            try {
                if ($db_driver === 'mssql' || $db_driver === 'sqlsrv') {
                    // MSSQL database creation
                    $pdo->exec("CREATE DATABASE [$db_name]");
                    $createSuccess = true;
                } else {
                    // MySQL database creation
                    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $createSuccess = true;
                }
            } catch (PDOException $e) {
                // User doesn't have CREATE DATABASE privilege - that's okay on shared hosting
                // We'll try to connect to existing database below
                $createSuccess = false;
            }
            
            // Reconnect with database selected
            try {
                if ($db_driver === 'mssql' || $db_driver === 'sqlsrv') {
                    $dsn = sprintf('sqlsrv:Server=%s,%d;Database=%s;Encrypt=yes;TrustServerCertificate=yes', $db_host, $db_port, $db_name);
                } else {
                    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $db_host, $db_port, $db_name);
                }
                
                $pdo = new PDO($dsn, $db_user, $db_pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);
                
                // Create setup log file for debugging
                $logsDir = $applicationDataRoot . DIRECTORY_SEPARATOR . 'logs';
                $setupLog = null;
                $setupLogFile = null;
                
                if (!is_dir($logsDir)) {
                    @mkdir($logsDir, 0700, true); // Windows ignores Unix perms anyway
                }
                
                if (is_dir($logsDir)) {
                    $logPath = $logsDir . DIRECTORY_SEPARATOR . 'setup_' . date('Y-m-d_H-i-s') . '.log';
                    $setupLog = @fopen($logPath, 'w');
                    if ($setupLog) {
                        $setupLogFile = $logPath; // Only set if successfully opened
                        fwrite($setupLog, "=== SLOCloud Setup Log - " . date('Y-m-d H:i:s') . " ===\n\n");
                        fwrite($setupLog, "Driver: $db_driver\n");
                        fwrite($setupLog, "Connection details: [REDACTED]\n\n");
                    }
                }
                
                if ($setupLog) {
                    fwrite($setupLog, "Database connection established.\n\n");
                }
                
                // Database connected successfully - proceed with schema installation
                // Choose schema file based on database driver
                if ($db_driver === 'mssql' || $db_driver === 'sqlsrv') {
                    $schemaFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema_mssql.sql';
                } else {
                    $schemaFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'system' . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sql';
                }
                
                if (!file_exists($schemaFile)) {
                    $error = 'Schema file not found at: ' . $schemaFile;
                } else {
                    $schema = file_get_contents($schemaFile);
                    
                    // Replace the schema's table-name token with the configured
                    // prefix (empty prefix strips it). Shared with the DB patcher
                    // CLI - keep all substitution in schema_helpers.php.
                    require_once __DIR__ . '/../system/includes/schema_helpers.php';
                    $schema = slocloud_apply_table_prefix($schema, $db_prefix);
                    
                    // Execute schema - split based on database driver
                    try {
                            $isSqlServer = ($db_driver === 'mssql' || $db_driver === 'sqlsrv');
                            
                            // Split schema appropriately
                            if ($isSqlServer) {
                                // SQL Server: Split on GO batch separator (case-insensitive, line boundaries)
                                $statements = preg_split('/^\s*GO\s*$/im', $schema);
                            } else {
                                // MySQL: Split on semicolons
                                $statements = explode(';', $schema);
                            }
                            
                            // Filter out empty statements and pure comment blocks
                            $statements = array_filter(
                                array_map('trim', $statements),
                                function($stmt) {
                                    if (empty($stmt)) return false;
                                    // Remove comment lines but keep SQL statements that have comments before them
                                    $lines = explode("\n", $stmt);
                                    $hasSQL = false;
                                    foreach ($lines as $line) {
                                        $trimmed = trim($line);
                                        if (!empty($trimmed) && !preg_match('/^(--|#)/', $trimmed)) {
                                            $hasSQL = true;
                                            break;
                                        }
                                    }
                                    return $hasSQL;
                                }
                            );
                            
                            $successCount = 0;
                            $failureCount = 0;
                            
                            foreach ($statements as $idx => $statement) {
                                if (!empty(trim($statement))) {
                                    // Strip comment lines for detection (but keep original for execution)
                                    $lines = explode("\n", $statement);
                                    $sqlOnly = '';
                                    foreach ($lines as $line) {
                                        $trimmed = trim($line);
                                        if (!empty($trimmed) && !preg_match('/^(--|#)/', $trimmed)) {
                                            $sqlOnly .= $line . "\n";
                                        }
                                    }
                                    $sqlOnly = trim($sqlOnly);
                                    
                                    // Detect statement type and log it
                                    $statementType = 'Unknown';
                                    $statementDetail = '';
                                    
                                    if (preg_match('/^DROP TABLE/i', $sqlOnly)) {
                                        $statementType = 'DROP TABLE';
                                        if (preg_match('/DROP TABLE\s+IF\s+EXISTS\s+(`?\w+`?)/i', $sqlOnly, $matches)) {
                                            $statementDetail = str_replace('`', '', $matches[1]);
                                        }
                                    } elseif (preg_match('/^CREATE TABLE/i', $sqlOnly)) {
                                        $statementType = 'CREATE TABLE';
                                        if (preg_match('/CREATE TABLE\s+(`?\w+`?)/i', $sqlOnly, $matches)) {
                                            $statementDetail = str_replace('`', '', $matches[1]);
                                        }
                                    } elseif (preg_match('/^INSERT INTO/i', $sqlOnly)) {
                                        $statementType = 'INSERT';
                                        if (preg_match('/INSERT INTO\s+(`?\w+`?)/i', $sqlOnly, $matches)) {
                                            $statementDetail = str_replace('`', '', $matches[1]);
                                        }
                                    } elseif (preg_match('/^SET\s+/i', $sqlOnly)) {
                                        $statementType = 'SET';
                                        $statementDetail = trim(substr($sqlOnly, 0, 50));
                                    }
                                    
                                    $msg = "Executing #$idx: $statementType" . ($statementDetail ? " $statementDetail" : "");
                                    if ($setupLog) fwrite($setupLog, $msg . "\n");
                                    echo "<!-- $msg -->\n";
                                    flush();
                                    
                                    try {
                                        $pdo->exec($statement);
                                        $successCount++;
                                    } catch (PDOException $e) {
                                        $failureCount++;
                                        $preview = substr($statement, 0, 100);
                                        $errorMsg = "ERROR #$idx: " . $e->getMessage() . " | Preview: $preview";
                                        if ($setupLog) fwrite($setupLog, $errorMsg . "\n");
                                        echo "<!-- $errorMsg -->\n";
                                        flush();
                                        // Continue with next statement instead of failing entire setup
                                    }
                                }
                            }
                            
                            $summaryMsg = "Schema execution complete: $successCount succeeded, $failureCount failed";
                            if ($setupLog) fwrite($setupLog, "\n$summaryMsg\n\n");
                            
                            // ========================================================
                            // CREATE DEFAULT ADMIN USER
                            // ========================================================
                            if ($setupLog) fwrite($setupLog, "\n=== Creating Default Admin User ===\n");
                            try {
                                $adminPassword = $admin_password;
                                $adminEmail = $admin_email;
                                $adminFullName = $admin_name;
                                
                                // Hash password with Argon2id
                                $passwordHash = password_hash($adminPassword, PASSWORD_ARGON2ID, [
                                    'memory_cost' => 65536,  // 64 MB
                                    'time_cost' => 4,
                                    'threads' => 2
                                ]);
                                
                                // Insert admin user
                                $stmt = $pdo->prepare("
                                    INSERT INTO {$db_prefix}users (full_name, email, password_hash, is_active) 
                                    VALUES (?, ?, ?, 1)
                                ");
                                $stmt->execute([$adminFullName, $adminEmail, $passwordHash]);
                                $adminUserId = $pdo->lastInsertId();

                                // Assign the global administrator role used by permission-gated tools.
                                $roleStmt = $pdo->prepare("
                                    INSERT INTO {$db_prefix}user_roles (user_fk, role_fk, context_type, context_id)
                                    SELECT ?, roles_pk, NULL, NULL FROM {$db_prefix}roles WHERE role_name = 'admin'
                                ");
                                $roleStmt->execute([$adminUserId]);
                                
                                $adminMsg = 'Installer-provided administrator created successfully';
                                if ($setupLog) fwrite($setupLog, "$adminMsg\n");
                                echo "<!-- $adminMsg -->\n";
                                flush();
                                
                            } catch (PDOException $e) {
                                $errorMsg = "Failed to create admin user: " . $e->getMessage();
                                if ($setupLog) fwrite($setupLog, "ERROR: $errorMsg\n");
                                echo "<!-- ERROR: $errorMsg -->\n";
                                flush();
                                // Continue even if admin creation fails
                            }
                            
                            // ========================================================
                            // PRE-GENERATE TERMS FOR CURRENT + NEXT 5 YEARS
                            // ========================================================
                            if ($setupLog) fwrite($setupLog, "\n=== Pre-generating Terms ===\n");
                            try {
                                // Determine current term year (starts in July)
                                $currentMonth = (int)date('n');
                                $currentYear = (int)date('Y');
                                $startYear = ($currentMonth >= 7) ? $currentYear : ($currentYear - 1);
                                
                                // Generate terms for 6 years (current + next 5)
                                $seasons = [
                                    ['code' => '10', 'name' => 'Winter'],
                                    ['code' => '30', 'name' => 'Spring'],
                                    ['code' => '50', 'name' => 'Summer'],
                                    ['code' => '70', 'name' => 'Fall']
                                ];
                                
                                $termsCreated = 0;
                                $stmt = $pdo->prepare("
                                    INSERT INTO {$db_prefix}terms 
                                    (term_code, term_name, academic_year) 
                                    VALUES (?, ?, ?)
                                ");
                                
                                for ($yearOffset = 0; $yearOffset < 6; $yearOffset++) {
                                    $year = $startYear + $yearOffset;
                                    $academicYear = $year . '-' . ($year + 1);
                                    
                                    foreach ($seasons as $season) {
                                        $termCode = $year . $season['code'];
                                        $termName = $season['name'] . ' ' . $year;
                                        
                                        $stmt->execute([$termCode, $termName, $academicYear]);
                                        $termsCreated++;
                                        
                                        if ($setupLog) {
                                            fwrite($setupLog, "  Created: $termCode - $termName ($academicYear)\n");
                                        }
                                    }
                                }
                                
                                $termsMsg = "Pre-generated $termsCreated terms for years $startYear-" . ($startYear + 5);
                                if ($setupLog) fwrite($setupLog, "$termsMsg\n");
                                echo "<!-- $termsMsg -->\n";
                                flush();
                                
                            } catch (PDOException $e) {
                                $errorMsg = "Failed to generate terms: " . $e->getMessage();
                                if ($setupLog) fwrite($setupLog, "ERROR: $errorMsg\n");
                                echo "<!-- ERROR: $errorMsg -->\n";
                                flush();
                                // Continue even if term generation fails
                            }
                            
                            // SEED DATA IMPORT DISABLED - User will import manually
                            if (false && file_exists($seedFile)) {
                                if ($setupLog) fwrite($setupLog, "\n=== Importing Seed Data ===\n");
                                try {
                                    $handle = fopen($seedFile, 'r');
                                    if ($handle !== false) {
                                        // Read header row
                                        $headers = fgetcsv($handle, 0, ',', '"', '');
                                        
                                        // Strip UTF-8 BOM from first header if present
                                        if (!empty($headers[0])) {
                                            $headers[0] = preg_replace('/^\x{FEFF}/u', '', $headers[0]);
                                        }
                                        
                                        if ($setupLog) {
                                            fwrite($setupLog, "CSV Headers: " . json_encode($headers, JSON_UNESCAPED_SLASHES) . "\n\n");
                                        }
                                        
                                        // Collections for unique values (to avoid duplicates)
                                        $programs = [];
                                        $slo_sets = [];
                                        $terms_data = [];
                                        $slos = [];
                                        $enrollments = [];
                                        $assessments = [];
                                        
                                        // Parse CSV and collect unique entities
                                        $rowNum = 1; // Track row number for debugging
                                        $skippedRows = [];
                                        
                                        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                                            $rowNum++;
                                            
                                            // Skip completely empty rows
                                            if (empty(array_filter($row))) {
                                                continue;
                                            }
                                            
                                            // Check column count
                                            $colCount = count($row);
                                            $headerCount = count($headers);
                                            if ($colCount !== $headerCount) {
                                                $msg = "Row $rowNum: column mismatch (has $colCount, expected $headerCount)";
                                                $rowDump = "  Raw row data: " . json_encode($row, JSON_UNESCAPED_SLASHES);
                                                $skippedRows[] = $msg;
                                                if ($setupLog) {
                                                    fwrite($setupLog, "SKIP: $msg\n");
                                                    fwrite($setupLog, "$rowDump\n");
                                                }
                                                continue;
                                            }
                                            
                                            $data = array_combine($headers, $row);
                                            if ($data === false) {
                                                $msg = "Row $rowNum: array_combine failed";
                                                $rowDump = "  Headers: " . json_encode($headers, JSON_UNESCAPED_SLASHES) . "\n";
                                                $rowDump .= "  Row: " . json_encode($row, JSON_UNESCAPED_SLASHES);
                                                $skippedRows[] = $msg;
                                                if ($setupLog) {
                                                    fwrite($setupLog, "SKIP: $msg\n");
                                                    fwrite($setupLog, "$rowDump\n");
                                                }
                                                continue;
                                            }
                                            
                                            // Skip rows with missing critical data
                                            if (empty($data['Academic Year']) || trim($data['Academic Year']) === '') {
                                                $msg = "Row $rowNum: empty Academic Year";
                                                $rowDump = "  Full row data: " . json_encode($data, JSON_UNESCAPED_SLASHES);
                                                $skippedRows[] = $msg;
                                                if ($setupLog) {
                                                    fwrite($setupLog, "SKIP: $msg\n");
                                                    fwrite($setupLog, "$rowDump\n");
                                                }
                                                continue;
                                            }
                                            
                                            // Extract program (unique by Program column)
                                            if (!empty($data['Program'])) {
                                                $programCode = strtoupper(str_replace(' ', '_', $data['Program']));
                                                if (!isset($programs[$programCode])) {
                                                    $programs[$programCode] = [
                                                        'code' => $programCode,
                                                        'name' => $data['Program'],
                                                        'degree_type' => 'AS' // Default
                                                    ];
                                                }
                                            }
                                            
                                            // Student data will be denormalized into enrollment table
                                            
                                            // Extract SLO Set (academic year)
                                            if (!empty($data['Academic Year'])) {
                                                $setCode = str_replace('-', '_', $data['Academic Year']);
                                                if (!isset($slo_sets[$setCode])) {
                                                    $years = explode('-', $data['Academic Year']);
                                                    $startYear = '20' . $years[0];
                                                    $endYear = '20' . $years[1];
                                                    $slo_sets[$setCode] = [
                                                        'set_code' => $setCode,
                                                        'set_name' => 'Academic Year ' . $data['Academic Year'],
                                                        'set_type' => 'year',
                                                        'start_date' => $startYear . '-08-01',
                                                        'end_date' => $endYear . '-07-31'
                                                    ];
                                                }
                                            }
                                            
                                            // Extract term
                                            if (!empty($data['Term']) && !empty($data['Academic Year'])) {
                                                $setCode = str_replace('-', '_', $data['Academic Year']);
                                                $termCode = str_replace(' ', '_', strtoupper($data['Term']));
                                                $termKey = $setCode . '_' . $termCode;
                                                
                                                if (!isset($terms_data[$termKey])) {
                                                    $year = '20' . explode('-', $data['Academic Year'])[0];
                                                    if (stripos($data['Term'], 'Spring') !== false) {
                                                        $year = '20' . explode('-', $data['Academic Year'])[1];
                                                        $start = $year . '-01-15';
                                                        $end = $year . '-05-31';
                                                    } else {
                                                        $start = $year . '-08-15';
                                                        $end = $year . '-12-31';
                                                    }
                                                    
                                                    $terms_data[$termKey] = [
                                                        'slo_set_code' => $setCode,
                                                        'term_code' => $termCode,
                                                        'term_name' => $data['Term'],
                                                        'term_year' => (int)$year,
                                                        'start_date' => $start,
                                                        'end_date' => $end
                                                    ];
                                                }
                                            }
                                            
                                            // Extract SLO (course + CSLO within academic year set)
                                            if (!empty($data['CSLO']) && !empty($data['SLO Language']) && !empty($data['Course']) && !empty($data['Academic Year'])) {
                                                $setCode = str_replace('-', '_', $data['Academic Year']);
                                                $courseCode = str_replace(' ', '_', $data['Course']);
                                                $csloCode = str_replace(' ', '_', strtoupper($data['CSLO']));
                                                $sloCode = $courseCode . '_' . $csloCode;  // e.g., JAPN_C185_CSLO_1
                                                $sloKey = $setCode . '_' . $sloCode;
                                                
                                                if (!isset($slos[$sloKey])) {
                                                    $slos[$sloKey] = [
                                                        'slo_set_code' => $setCode,
                                                        'slo_code' => $sloCode,
                                                        'description' => substr($data['SLO Language'], 0, 500),
                                                        'assessment_method' => $data['Assessment'] ?? 'Assignment',
                                                        'sequence_num' => count($slos) + 1
                                                    ];
                                                }
                                            }
                                            
                                            // Extract enrollment with all denormalized context data
                                            if (!empty($data['CRN']) && !empty($data['StudentID']) && !empty($data['Term']) && !empty($data['Academic Year'])) {
                                                $setCode = str_replace('-', '_', $data['Academic Year']);
                                                $termCode = str_replace(' ', '_', strtoupper($data['Term']));
                                                $enrollKey = $termCode . '_' . $data['CRN'] . '_' . $data['StudentID'];
                                                
                                                if (!isset($enrollments[$enrollKey])) {
                                                    $enrollments[$enrollKey] = [
                                                        'term_code' => $termCode,
                                                        'crn' => $data['CRN'],
                                                        'student_id' => $data['StudentID'],
                                                        'first_name' => 'Student',
                                                        'last_name' => substr($data['StudentID'], 1), // C00123456 -> 00123456
                                                        'academic_year' => $data['Academic Year'] ?? null,
                                                        'semester' => $data['Semester'] ?? null,
                                                        'course_code' => $data['Course'] ?? null,
                                                        'course_title' => $data['Title'] ?? null,
                                                        'course_modality' => $data['Modality'] ?? null,
                                                        'program_name' => $data['Program'] ?? null,
                                                        'subject_code' => $data['Sub Code'] ?? null,
                                                        'subject_name' => $data['Subject'] ?? null
                                                    ];
                                                }
                                            }
                                            
                                            // Store assessment for later (after enrollment is inserted)
                                            if (!empty($data['CSLO']) && !empty($data['Met/Not Met']) && !empty($data['Course']) && !empty($data['Term'])) {
                                                $termCode = str_replace(' ', '_', strtoupper($data['Term']));
                                                $courseCode = str_replace(' ', '_', $data['Course']);
                                                $csloCode = str_replace(' ', '_', strtoupper($data['CSLO']));
                                                $sloCode = $courseCode . '_' . $csloCode;
                                                
                                                $assessments[] = [
                                                    'term_code' => $termCode,
                                                    'crn' => $data['CRN'],
                                                    'student_id' => $data['StudentID'],
                                                    'slo_code' => $sloCode,
                                                    'score' => ($data['Met/Not Met'] === 'Met') ? 1 : 0,
                                                    'assessment_type' => $data['Assessment'] ?? 'Assignment',
                                                    'assessment_date' => date('Y-m-d')
                                                ];
                                            }
                                        }
                                        
                                        fclose($handle);
                                        
                                        // Insert data in foreign key dependency order
                                        $pdo->beginTransaction();
                                        
                                        // 1. Programs
                                        $stmt = $pdo->prepare("INSERT INTO {$db_prefix}programs (program_code, program_name, degree_type) VALUES (?, ?, ?)");
                                        foreach ($programs as $program) {
                                            $stmt->execute([$program['code'], $program['name'], $program['degree_type']]);
                                            $seedStats['programs']++;
                                        }
                                        
                                        // 2. SLO Sets
                                        $stmt = $pdo->prepare("INSERT INTO {$db_prefix}slo_sets (set_code, set_name, set_type, start_date, end_date, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                                        foreach ($slo_sets as $set) {
                                            $stmt->execute([$set['set_code'], $set['set_name'], $set['set_type'], $set['start_date'], $set['end_date']]);
                                            $seedStats['slo_sets']++;
                                        }
                                        
                                        // 4. Terms
                                        $stmt = $pdo->prepare("INSERT INTO {$db_prefix}terms (slo_set_code, term_code, term_name, term_year, start_date, end_date, is_active) VALUES (?, ?, ?, ?, ?, ?, 1)");
                                        foreach ($terms_data as $term) {
                                            $stmt->execute([$term['slo_set_code'], $term['term_code'], $term['term_name'], $term['term_year'], $term['start_date'], $term['end_date']]);
                                            $seedStats['terms']++;
                                        }
                                        
                                        // 5. Student Learning Outcomes
                                        $stmt = $pdo->prepare("INSERT INTO {$db_prefix}student_learning_outcomes (slo_set_code, slo_code, description, assessment_method, sequence_num, is_active) VALUES (?, ?, ?, ?, ?, 1)");
                                        foreach ($slos as $slo) {
                                            $stmt->execute([$slo['slo_set_code'], $slo['slo_code'], $slo['description'], $slo['assessment_method'], $slo['sequence_num']]);
                                            $seedStats['slos']++;
                                        }
                                        
                                        // 6. Enrollment
                                        $stmt = $pdo->prepare("INSERT INTO {$db_prefix}enrollment 
                                            (term_code, crn, student_id, student_first_name, student_last_name, 
                                             academic_year, semester, course_code, course_title, course_modality, 
                                             program_name, subject_code, subject_name) 
                                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                        foreach ($enrollments as $enrollment) {
                                            $stmt->execute([
                                                $enrollment['term_code'], 
                                                $enrollment['crn'], 
                                                $enrollment['student_id'], 
                                                $enrollment['first_name'], 
                                                $enrollment['last_name'],
                                                $enrollment['academic_year'],
                                                $enrollment['semester'],
                                                $enrollment['course_code'],
                                                $enrollment['course_title'],
                                                $enrollment['course_modality'],
                                                $enrollment['program_name'],
                                                $enrollment['subject_code'],
                                                $enrollment['subject_name']
                                            ]);
                                            $seedStats['enrollment']++;
                                        }
                                        
                                        // 7. Assessments
                                        $stmt = $pdo->prepare("INSERT INTO {$db_prefix}assessments (enrollment_fk, student_learning_outcome_fk, score_value, achievement_level, assessed_date, notes)
                                                               SELECT e.enrollment_pk, s.student_learning_outcomes_pk, ?, ?, ?, ?
                                                               FROM {$db_prefix}enrollment e
                                                               JOIN {$db_prefix}student_learning_outcomes s ON s.slo_code = ?
                                                               WHERE e.term_code = ? AND e.crn = ? AND e.student_id = ?");
                                        foreach ($assessments as $assessment) {
                                            $achievementLevel = $assessment['score'] == 1 ? 'met' : 'not_met';
                                            $stmt->execute([
                                                $assessment['score'],
                                                $achievementLevel,
                                                $assessment['assessment_date'],
                                                $assessment['assessment_type'] ?? null,
                                                $assessment['slo_code'],
                                                $assessment['term_code'],
                                                $assessment['crn'],
                                                $assessment['student_id']
                                            ]);
                                            $seedStats['assessments']++;
                                        }
                                        
                                        $pdo->commit();
                                        
                                        // Log seed import summary
                                        $importSummary = "\n=== Seed Import Complete ===\n";
                                        $importSummary .= "Total rows processed: " . ($rowNum - 1) . "\n";
                                        $importSummary .= "Rows skipped: " . count($skippedRows) . "\n";
                                        $importSummary .= "Programs: {$seedStats['programs']}\n";
                                        $importSummary .= "SLO Sets: {$seedStats['slo_sets']}\n";
                                        $importSummary .= "Terms: {$seedStats['terms']}\n";
                                        $importSummary .= "SLOs: {$seedStats['slos']}\n";
                                        $importSummary .= "Enrollments: {$seedStats['enrollment']}\n";
                                        $importSummary .= "Assessments: {$seedStats['assessments']}\n";
                                        
                                        if (count($skippedRows) > 0 && count($skippedRows) <= 20) {
                                            $importSummary .= "\nSkipped row details:\n";
                                            foreach ($skippedRows as $skip) {
                                                $importSummary .= "  - $skip\n";
                                            }
                                        } elseif (count($skippedRows) > 20) {
                                            $importSummary .= "\nFirst 10 skipped rows:\n";
                                            for ($i = 0; $i < 10 && $i < count($skippedRows); $i++) {
                                                $importSummary .= "  - {$skippedRows[$i]}\n";
                                            }
                                            $importSummary .= "... and " . (count($skippedRows) - 10) . " more\n";
                                        }
                                        
                                        if ($setupLog) fwrite($setupLog, $importSummary);
                                    }
                                } catch (Exception $e) {
                                    if (isset($pdo) && $pdo->inTransaction()) {
                                        $pdo->rollBack();
                                    }
                                    // Don't fail setup if seed data import fails - log it but continue
                                    $errorMsg = 'Seed data import failed: ' . $e->getMessage();
                                    if ($setupLog) fwrite($setupLog, "\nERROR: $errorMsg\n");
                                }
                            }
                            
                            // Save configuration
                                    $configDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config';
                                    
                                    // Create config directory if it doesn't exist
                                    if (!is_dir($configDir)) {
                                        @mkdir($configDir, 0700, true);
                                    }
                                    @chmod($configDir, 0700);
                                    
                                    // ========================================================
                                    // GENERATE ENCRYPTION KEYS (Two-File Architecture)
                                    // ========================================================
                                    // Generate secure encryption keys (256-bit = 32 bytes = 64 hex characters)
                                    $encryptionKey = bin2hex(random_bytes(32));
                                    $studentEncryptionKey = bin2hex(random_bytes(32));
                                    // LTI consumer credentials for the LMS. Generated here so no install
                                    // ever runs with a hand-typed secret; the secret is stored encrypted
                                    // and shown once on the completion screen (and on the LTI
                                    // Configuration page) for copying into the LMS.
                                    $ltiConsumerKey = 'slocloud-' . bin2hex(random_bytes(8));
                                    $ltiConsumerSecret = bin2hex(random_bytes(32));
                                    // Keyed lookup hash for student C-numbers (SEC-008). A dedicated key,
                                    // never reused for encryption, so compromise of one does not help
                                    // recompute the other.
                                    $studentLookupKey = bin2hex(random_bytes(32));
                                    
                                    // Create .htaccess to protect YAML files from web access
                                    $htaccessFile = $configDir . DIRECTORY_SEPARATOR . '.htaccess';
                                    $htaccessContent = "# SLOCloud Config Security\n";
                                    $htaccessContent .= "# Deny access to all YAML configuration files\n\n";
                                    $htaccessContent .= "<Files \"*.yaml\">\n";
                                    $htaccessContent .= "    Require all denied\n";
                                    $htaccessContent .= "</Files>\n\n";
                                    $htaccessContent .= "# Also protect .yml extension\n";
                                    $htaccessContent .= "<Files \"*.yml\">\n";
                                    $htaccessContent .= "    Require all denied\n";
                                    $htaccessContent .= "</Files>\n";
                                    file_put_contents($htaccessFile, $htaccessContent);
                                    
                                    if ($setupLog) {
                                        fwrite($setupLog, "\n=== Configuration Security ===\n");
                                        fwrite($setupLog, ".htaccess created to block web access to YAML files\n\n");
                                    }
                                    
                                    // Create key.yaml (master encryption keys) in the verified
                                    // keys folder, outside the web root. The folder is checked
                                    // again here because it was chosen earlier in the session.
                                    [$keysDirectory, $keysDirectoryProblem] = setupResolveKeysDirectory((string)$_SESSION['setup_keys_directory']);
                                    if ($keysDirectory === null) {
                                        throw new RuntimeException('Keys folder is no longer usable: ' . $keysDirectoryProblem);
                                    }
                                    $keyFile = $keysDirectory . DIRECTORY_SEPARATOR . 'key.yaml';
                                    $keyContent = "# SLOCloud Master Encryption Keys\n";
                                    $keyContent .= "# Generated: " . date('Y-m-d H:i:s') . "\n";
                                    $keyContent .= "#\n";
                                    $keyContent .= "# CRITICAL SECURITY:\n";
                                    $keyContent .= "# - Keep this file outside the web root, readable only by the web server account\n";
                                    $keyContent .= "# - Never commit to version control\n";
                                    $keyContent .= "#\n";
                                    $keyContent .= "# These keys decrypt sensitive values in config.yaml\n";
                                    $keyContent .= "# Changing these keys will invalidate all encrypted data!\n";
                                    $keyContent .= "#\n";
                                    $keyContent .= "# The application finds this file through security.key_path in config.yaml.\n";
                                    $keyContent .= "# If you move it, update that setting, or set the SLOCloud_KEY_PATH\n";
                                    $keyContent .= "# environment variable, which is checked first.\n\n";
                                    $keyContent .= setupEmitYaml([
                                        'encryption_key' => $encryptionKey,
                                        'student_encryption_key' => $studentEncryptionKey,
                                        'student_lookup_key' => $studentLookupKey,
                                    ]);

                                    if (!setupAtomicWrite($keyFile, $keyContent)) {
                                        throw new RuntimeException('Failed to write key.yaml atomically.');
                                    }
                                    chmod($keyFile, 0600); // Secure the key file
                                    
                                    if ($setupLog) {
                                        fwrite($setupLog, "\n=== Encryption Keys Generated ===\n");
                                        fwrite($setupLog, "key.yaml created at: $keyFile\n");
                                        fwrite($setupLog, "File permissions set to 600\n\n");
                                    }
                                    
                                    // Load Encryption class to encrypt sensitive values
                                    require_once __DIR__ . '/../system/Core/Encryption.php';
                                    $encryption = new \SLOCloud\Core\Encryption($encryptionKey);
                                    
                                    // Encrypt sensitive values
                                    $dbPasswordEncrypted = $encryption->encrypt($db_pass);
                                    $ltiConsumerSecretEncrypted = $encryption->encrypt($ltiConsumerSecret);
                                    $smtpPasswordEncrypted = !empty($smtp_pass) ? $encryption->encrypt($smtp_pass) : '';
                                    
                                    // Rebuild config.yaml with encrypted values
                                    $configContent = "# SLOCloud Configuration\n";
                                    $configContent .= "# Generated: " . date('Y-m-d H:i:s') . "\n";
                                    $configContent .= "#\n";
                                    $configContent .= "# SECURITY ARCHITECTURE:\n";
                                    $configContent .= "# - Sensitive values are encrypted with key from key.yaml\n";
                                    $configContent .= "# - This file CAN be committed to version control (encrypted values are safe)\n";
                                    $configContent .= "# - All .yaml files protected by .htaccess (blocks web access)\n";
                                    $configContent .= "#\n";
                                    $configContent .= "# See: src/config/README.md for security setup instructions\n\n";
                                    $configContent .= setupEmitYaml([
                                        'database' => [
                                            'driver' => $db_driver,
                                            'host' => $db_host,
                                            'port' => (int)$db_port,
                                            'name' => $db_name,
                                            'prefix' => $db_prefix,
                                            'username' => $db_user,
                                            'password' => 'ENC:' . $dbPasswordEncrypted,
                                            'charset' => $templateDefaults['charset'] ?? 'utf8mb4',
                                        ],
                                        'app' => [
                                            'name' => $site_name,
                                            'timezone' => $templateDefaults['timezone'] ?? 'America/Los_Angeles',
                                            'base_url' => $base_url,
                                            'debug_mode' => $templateDefaults['debug_mode'] ?? false,
                                            'degree_types' => $templateDefaults['degree_types'] ?? '',
                                            'assessment_types' => $templateDefaults['assessment_types'] ?? '',
                                            'improvement_strategies' => $templateDefaults['improvement_strategies'] ?? '',
                                        ],
                                        'theme' => [
                                            'active_theme' => $templateDefaults['active_theme'] ?? 'theme-default',
                                            'admin_theme' => $templateDefaults['admin_theme'] ?? 'theme-adminlte',
                                            'lti_theme' => $templateDefaults['lti_theme'] ?? 'theme-default',
                                        ],
                                        'email' => [
                                            'method' => $mail_method,
                                            'from_email' => $smtp_from_email,
                                            'from_name' => $smtp_from_name,
                                            'smtp_host' => $smtp_host,
                                            'smtp_port' => (int)($smtp_port ?: ($templateDefaults['smtp_port'] ?? 587)),
                                            'smtp_username' => $smtp_user,
                                            'smtp_password' => $smtpPasswordEncrypted !== '' ? 'ENC:' . $smtpPasswordEncrypted : '',
                                            'smtp_encryption' => $smtp_encryption ?: ($templateDefaults['smtp_encryption'] ?? 'tls'),
                                        ],
                                        'emergency_admin' => [
                                            'enabled' => false,
                                            'username' => '',
                                            'password' => '',
                                        ],
                                        'lti' => [
                                            'consumer_key' => $ltiConsumerKey,
                                            'consumer_secret' => 'ENC:' . $ltiConsumerSecretEncrypted,
                                        ],
                                        'security' => [
                                            'encryption_key_source' => 'file',
                                            'key_path' => $keyFile,
                                        ],
                                    ]);
                                    
                                    if (setupAtomicWrite($configFile, $configContent)) {
                                        // Create index.php fallback (in case .htaccess fails)
                                        $indexFile = $configDir . DIRECTORY_SEPARATOR . 'index.php';
                                        file_put_contents($indexFile, "<?php\nhttp_response_code(403);\nexit('Forbidden');\n");
                                        
                                        // Report catalog. Production catalogs are ALWAYS introspected
                                        // from the live database, never derived from the checked-in
                                        // DDL: the DDL is single-dialect and cannot reflect this
                                        // install's driver or customizations. Two sequential steps,
                                        // same order as the admin Refresh action.
                                        try {
                                            require_once __DIR__ . '/../system/reporting/bootstrap.php';
                                            // Step 1: build the catalog from the just-created database.
                                            $freshCatalog = report_build_catalog($pdo, $db_driver, $db_prefix);
                                            // Step 2: validate the seeded report definitions against it.
                                            $reportSweep = report_sweep_reports($freshCatalog, report_store());
                                            if ($setupLog) {
                                                fwrite($setupLog, "\n=== Report Catalog ===\n");
                                                fwrite($setupLog, sprintf(
                                                    "Built from live database: %d tables, %d relationships.\n",
                                                    $freshCatalog['stats']['tables'],
                                                    $freshCatalog['stats']['relationships']
                                                ));
                                                fwrite($setupLog, $reportSweep['need_repair'] === []
                                                    ? sprintf("All %d seeded report(s) validate against the catalog.\n", $reportSweep['checked'])
                                                    : sprintf(
                                                        "%d of %d report(s) need repair: %s\n",
                                                        count($reportSweep['need_repair']),
                                                        $reportSweep['checked'],
                                                        implode(', ', $reportSweep['need_repair'])
                                                    ));
                                            }
                                        } catch (Throwable $catalogError) {
                                            // Non-fatal: the same build-then-sweep sequence runs on
                                            // first use of Custom Report. Setup should not fail
                                            // over reporting.
                                            if ($setupLog) {
                                                fwrite($setupLog, "\n=== Report Catalog ===\n");
                                                fwrite($setupLog, 'Could not build catalog during setup: ' . $catalogError->getMessage() . "\n");
                                                fwrite($setupLog, "It will be built automatically on first use of Custom Report.\n");
                                            }
                                        }

                                        // Close setup log
                                        if ($setupLog) {
                                            fwrite($setupLog, "\n=== Setup Complete ===\n");
                                            fwrite($setupLog, "Setup log saved to: $setupLogFile\n");
                                            fclose($setupLog);
                                        }
                                        
                                        $success = true;
                                        $step = 'complete';
                                        
                                        // Display log file path
                                        if (isset($setupLogFile)) {
                                            echo "<!-- Setup log saved to: $setupLogFile -->\n";
                                        }
                                    } else {
                                        $error = 'Failed to write configuration file. Check directory permissions.';
                                    }
                                
                        } catch (PDOException $e) {
                            $error = 'Schema execution failed: ' . $e->getMessage();
                        }
                } // End else (schema file exists / complete installation)
            } catch (PDOException $e) {
                // The login works (the server-level connection above succeeded), but the database
                // could not be opened. $error is escaped when it is displayed, so it stays plain text here.
                $error = sprintf('Signed in to the database server as "%s", but could not open database "%s". ', $db_user, $db_name);
                if (!$createSuccess) {
                    if ($db_driver === 'mssql' || $db_driver === 'sqlsrv') {
                        $error .= sprintf(
                            'Setup could not create it, so it does not exist yet or this login has no access to it. '
                            . 'Ask your DBA to run: CREATE DATABASE [%1$s]; then, in that database: '
                            . 'CREATE USER [%2$s] FOR LOGIN [%2$s]; ALTER ROLE db_owner ADD MEMBER [%2$s]; '
                            . '(Read and write roles alone cannot create the tables and triggers. Instead of db_owner, '
                            . 'db_ddladmin plus db_datareader and db_datawriter also works; db_ddladmin can be removed after setup.) ',
                            $db_name, $db_user
                        );
                    } else {
                        $error .= sprintf(
                            'Setup could not create it. Create the database and grant this user all privileges on it, '
                            . 'for example: CREATE DATABASE `%1$s`; GRANT ALL PRIVILEGES ON `%1$s`.* TO \'%2$s\'@\'%%\'; ',
                            $db_name, $db_user
                        );
                    }
                }
                $error .= 'Server message: ' . $e->getMessage();
            }
            
            // PDO connection will be closed automatically when $pdo goes out of scope
        }
    }
}

// Setup page variables
$pageTitle = 'Setup';
$bodyClass = '';

// Define inline CSS for setup page
ob_start();
?>
<style>
    body {
        background: linear-gradient(135deg, var(--brand-teal) 0%, var(--primary-dark) 100%);
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        font-family: 'Source Sans Pro', sans-serif;
    }
    
    .setup-container {
        background: white;
        border-radius: 8px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        max-width: 600px;
        width: 100%;
        overflow: hidden;
    }
    
    .setup-header {
        background: var(--brand-teal);
        color: white;
        padding: 30px;
        text-align: center;
    }
    
    .setup-header h1 {
        font-size: 28px;
        font-weight: 600;
        margin-bottom: 8px;
    }
    
    .setup-header p {
        font-size: 14px;
        opacity: 0.9;
        margin-bottom: 0;
    }
    
    .setup-body {
        padding: 40px;
    }
    
    .btn-primary {
        background: var(--brand-teal);
        border-color: var(--brand-teal);
    }
    
    .btn-primary:hover {
        background: var(--primary-dark);
        border-color: var(--primary-dark);
    }
    
    .success-icon {
        text-align: center;
        font-size: 64px;
        color: #28a745;
        margin-bottom: 20px;
    }
    
    .requirements {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 4px;
        margin-bottom: 25px;
    }
    
    .requirements ul {
        list-style: none;
        padding-left: 0;
        margin-bottom: 0;
    }
    
    .requirements li {
        padding: 5px 0 5px 50px;
        position: relative;
    }
    
    .requirements li:before {
        position: absolute;
        left: 0;
        font-weight: bold;
        font-family: monospace;
    }
    
    .requirements li.pass:before {
        content: "[OK]";
        color: #146c43;
    }
    
    .requirements li.warning:before {
        content: "[!]";
        color: #806000;
    }
    
    .requirements li.fail:before {
        content: "[X]";
        color: #dc3545;
    }
    
    .form-section-title {
        font-size: 16px;
        font-weight: 600;
        color: #495057;
        margin-top: 25px;
        margin-bottom: 15px;
        padding-bottom: 8px;
        border-bottom: 2px solid #e9ecef;
    }
</style>
<?php
$customStyles = ob_get_clean();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SLOCloud Setup</title>
    
    <!-- Bootstrap 5 -->
    <link href="<?= BASE_URL ?>assets/vendor/bootstrap/5.3.8/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/fontawesome-free/6.4.0/css/all.min.css">
    <!-- Google Font -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/source-sans-pro/5.3.0/source-sans-pro.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>system/accessibility.css">
    <script src="<?= BASE_URL ?>system/accessibility.js" defer></script>
    
    <style>
        :root {
            --primary-dark: #0D47A1;
            --accent-blue: #1976D2;
            --brand-teal: #1565C0;
        }
    </style>
    
    <?= $customStyles ?>
</head>
<body>

<a href="#main" class="skip-link">Skip to main content</a>
<main id="main" class="setup-container" tabindex="-1">
    <div class="setup-header">
        <h1>Welcome to SLOCloud!</h1>
        <p>Let's get your student learning outcomes system up and running</p>
    </div>
    
    <div class="setup-body">
        <?php if ($step === 'complete'): ?>
                <!-- Success Page -->
                <div class="success-icon">
                    <i class="fas fa-check-circle" aria-hidden="true"></i>
                </div>
                <div class="text-center">
                    <h2 class="mb-3">You're All Set!</h2>
                    <p class="text-muted mb-2">Your installation was successful.</p>
                    <p class="text-muted mb-4">The database is ready and you can start using your new system.</p>
                </div>
                <?php if (isset($ltiConsumerKey, $ltiConsumerSecret)): ?>
                <div class="alert alert-info text-left" role="region" aria-labelledby="lti-credentials-heading">
                    <h3 id="lti-credentials-heading" class="h6 mb-2"><i class="fas fa-plug mr-2" aria-hidden="true"></i>LTI credentials for your LMS</h3>
                    <p class="mb-2 small">Enter these when registering SLOCloud as an external tool. They are also shown under <strong>System &rarr; Configuration &rarr; LTI</strong>.</p>
                    <dl class="mb-0 small">
                        <dt>Consumer key</dt>
                        <dd><code><?php echo htmlspecialchars($ltiConsumerKey); ?></code></dd>
                        <dt>Shared secret</dt>
                        <dd class="mb-0"><code style="word-break: break-all;"><?php echo htmlspecialchars($ltiConsumerSecret); ?></code></dd>
                    </dl>
                </div>
                <?php endif; ?>
                <div class="text-center">
                    <a href="<?php echo htmlspecialchars(BASE_URL); ?>administration/" class="btn btn-primary btn-lg btn-block">
                        <i class="fas fa-arrow-right mr-2"></i>Get Started
                    </a>
                </div>
                
            <?php elseif (!$setupAuthorized): ?>
                <!-- Keys folder and verification code -->
                <?php if ($gateError): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fas fa-exclamation-circle mr-2" aria-hidden="true"></i>
                        <strong>Error:</strong> <?php echo htmlspecialchars($gateError); ?>
                    </div>
                <?php endif; ?>

                <?php if ($setupPending === null): ?>
                    <form method="POST" action="<?php echo htmlspecialchars(BASE_URL . 'setup/'); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['setup_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="form-group">
                            <label for="keys_directory"><i class="fas fa-key mr-2" aria-hidden="true"></i>Where should the encryption keys be stored?</label>
                            <input type="text" class="form-control" id="keys_directory" name="keys_directory"
                                   value="<?php echo htmlspecialchars(is_string($_POST['keys_directory'] ?? null) ? $_POST['keys_directory'] : ''); ?>"
                                   placeholder="e.g., D:\slocloud\keys" required autofocus>
                            <small class="form-text text-muted">A folder on this server, outside the web root, that already exists and that the web server account can write to.</small>
                        </div>
                        <button type="submit" name="setup_keys_submit" class="btn btn-primary btn-lg btn-block">Continue</button>
                    </form>
                <?php else: ?>
                    <form method="POST" action="<?php echo htmlspecialchars(BASE_URL . 'setup/'); ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['setup_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="form-group">
                            <label for="setup_code"><i class="fas fa-shield-alt mr-2" aria-hidden="true"></i>Verification code</label>
                            <input type="text" class="form-control" id="setup_code" name="setup_code"
                                   autocomplete="off" required autofocus>
                            <small class="form-text text-muted">
                                Open <code><?php echo htmlspecialchars((string)$setupPending['directory'] . DIRECTORY_SEPARATOR . SETUP_CODE_FILE); ?></code>
                                on this server and paste the code it contains. It expires in 15 minutes.
                            </small>
                        </div>
                        <button type="submit" name="setup_code_submit" class="btn btn-primary btn-lg btn-block">Verify</button>
                    </form>
                    <form method="POST" action="<?php echo htmlspecialchars(BASE_URL . 'setup/'); ?>" class="mt-3">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['setup_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="keys_directory" value="">
                        <button type="submit" name="setup_change_directory" class="btn btn-link">Use a different folder</button>
                    </form>
                <?php endif; ?>

            <?php else: ?>
                <!-- Preflight Checks -->
                <?php
                // Perform real preflight checks
                $php_version = PHP_VERSION;
                $php_ok = version_compare($php_version, '8.1.0', '>=');
                $pdo_ok = extension_loaded('pdo');
                $yaml_ok = extension_loaded('yaml');
                $pdo_mysql_ok = extension_loaded('pdo_mysql');
                $pdo_sqlsrv_ok = extension_loaded('pdo_sqlsrv');
                $argon2_ok = defined('PASSWORD_ARGON2ID');
                $db_driver_ok = $pdo_mysql_ok || $pdo_sqlsrv_ok;
                $all_checks_pass = $php_ok && $pdo_ok && $yaml_ok && $db_driver_ok && $argon2_ok;
                ?>
                
                <div class="requirements">
                    <h2 class="h5 mb-3"><i class="fas fa-clipboard-check mr-2" aria-hidden="true"></i>System Requirements</h2>
                    <ul>
                        <li class="<?php echo $php_ok ? 'pass' : 'fail'; ?>">
                            PHP 8.1 or higher <span class="text-muted">(found <?php echo $php_version; ?>)</span>
                        </li>
                        <li class="<?php echo $pdo_ok ? 'pass' : 'fail'; ?>">
                            PDO extension enabled
                        </li>
                        <li class="<?php echo $yaml_ok ? 'pass' : 'fail'; ?>">
                            YAML extension enabled
                        </li>
                        <li class="<?php echo $pdo_mysql_ok ? 'pass' : 'warning'; ?>">
                            PDO MySQL driver (pdo_mysql) <?php echo $pdo_mysql_ok ? '<span class="text-success">Available</span>' : '<span class="text-muted">Not installed</span>'; ?>
                        </li>
                        <li class="<?php echo $pdo_sqlsrv_ok ? 'pass' : 'warning'; ?>">
                            PDO MS SQL Server driver (pdo_sqlsrv) <?php echo $pdo_sqlsrv_ok ? '<span class="text-success">Available</span>' : '<span class="text-muted">Not installed</span>'; ?>
                        </li>
                        <li class="<?php echo $db_driver_ok ? 'pass' : 'fail'; ?>">
                            At least one database driver required
                        </li>
                        <li class="<?php echo $argon2_ok ? 'pass' : 'fail'; ?>">
                            Argon2 password hashing support <span class="text-muted">(PHP 7.3+ with libargon2)</span>
                        </li>
                    </ul>
                </div>
                
                <?php if (!$all_checks_pass): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        <strong>Requirements Not Met:</strong> Please fix the issues above before continuing.
                    </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle mr-2"></i>
                        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($setupLogFile)): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="fas fa-file-alt mr-2"></i>
                        <strong>Setup Log:</strong> Detailed installation log saved to:<br>
                        <code style="background: rgba(0,0,0,0.1); padding: 2px 6px; border-radius: 3px;"><?php echo htmlspecialchars($setupLogFile); ?></code>
                        <br><small class="text-muted">View this file to see table creation details and any errors that occurred.</small>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="<?php echo htmlspecialchars(BASE_URL . 'setup/'); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)$_SESSION['setup_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                    <p class="text-muted">
                        <i class="fas fa-key mr-2" aria-hidden="true"></i>Encryption keys will be saved to
                        <code><?php echo htmlspecialchars((string)$_SESSION['setup_keys_directory'] . DIRECTORY_SEPARATOR . 'key.yaml'); ?></code>
                    </p>
                    <!-- Site Configuration -->
                    <div class="form-group">
                        <label for="site_name"><i class="fas fa-graduation-cap mr-2"></i>What would you like to call your site?</label>
                        <input type="text" class="form-control" id="site_name" name="site_name" 
                               value="<?php echo htmlspecialchars($_POST['site_name'] ?? 'SLOCloud'); ?>" 
                               placeholder="e.g., Springfield University Assessment" 
                               required>
                        <small class="form-text text-muted">This name will appear throughout your installation</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="base_url"><i class="fas fa-link mr-2"></i>Installation Path</label>
                        <input type="text" class="form-control" id="base_url" name="base_url" 
                               value="<?php echo htmlspecialchars($_POST['base_url'] ?? BASE_URL); ?>" 
                               required>
                        <small class="form-text text-muted">We've detected your installation path. Most users can leave this as-is.</small>
                    </div>
                    
                    <!-- Database Configuration -->
                    <h2 class="form-section-title"><i class="fas fa-database mr-2" aria-hidden="true"></i>Database Connection</h2>
                    <p class="text-muted mb-3" style="font-size: 14px;">
                        We support MySQL (for development or smaller deployments) and MS SQL Server (for enterprise production). If you're not sure about these settings, contact your hosting provider or system administrator.
                        <br><strong>Shared Hosting Note:</strong> If using cPanel, Plesk, or similar, create your database first through their interface and use those credentials here.
                    </p>
                    
                    <div class="form-group">
                        <label for="db_driver">Database Type</label>
                        <select class="form-control" id="db_driver" name="db_driver" onchange="updatePortDefault()" required>
                            <option value="mysql" <?php echo ($_POST['db_driver'] ?? 'mysql') === 'mysql' ? 'selected' : ''; ?> <?php echo !$pdo_mysql_ok ? 'disabled' : ''; ?>>MySQL / MariaDB<?php echo !$pdo_mysql_ok ? ' (PDO driver missing: pdo_mysql)' : ''; ?></option>
                            <option value="mssql" <?php echo ($_POST['db_driver'] ?? '') === 'mssql' ? 'selected' : ''; ?> <?php echo !$pdo_sqlsrv_ok ? 'disabled' : ''; ?>>Microsoft SQL Server<?php echo !$pdo_sqlsrv_ok ? ' (PDO driver missing: pdo_sqlsrv)' : ''; ?></option>
                        </select>
                        <small class="form-text text-muted">Choose MySQL for easy entry and development, or MS SQL Server for production enterprise environments. <strong>This choice is permanent</strong> - the database type cannot be changed after installation.
                        <?php if (!$pdo_mysql_ok || !$pdo_sqlsrv_ok): ?>
                            <br><span class="text-danger"><i class="fas fa-exclamation-triangle"></i> 
                            <?php if (!$pdo_mysql_ok): ?>
                                pdo_mysql PDO driver must be installed on your server before you can use MySQL.
                            <?php endif; ?>
                            <?php if (!$pdo_mysql_ok && !$pdo_sqlsrv_ok): ?>
                                <br>
                            <?php endif; ?>
                            <?php if (!$pdo_sqlsrv_ok): ?>
                                pdo_sqlsrv PDO driver must be installed on your server before you can use MSSQL.
                            <?php endif; ?>
                            </span>
                        <?php endif; ?>
                        </small>
                    </div>
                    
                    <div class="form-group">
                        <label for="db_host">Server Address</label>
                        <input type="text" class="form-control" id="db_host" name="db_host" 
                               value="<?php echo htmlspecialchars($_POST['db_host'] ?? 'localhost'); ?>" 
                               required>
                        <small class="form-text text-muted">Usually "localhost" if MySQL is on the same server</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="db_port">Port</label>
                        <input type="number" class="form-control" id="db_port" name="db_port" 
                               value="<?php echo htmlspecialchars($_POST['db_port'] ?? '3306'); ?>" 
                               min="1" max="65535" required>
                        <small class="form-text text-muted">MySQL default: 3306, MSSQL default: 1433</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="db_name">Database Name</label>
                        <input type="text" class="form-control" id="db_name" name="db_name" 
                               value="<?php echo htmlspecialchars($_POST['db_name'] ?? 'slocloud'); ?>" 
                               required>
                        <small class="form-text text-muted">On shared hosting, use the exact database name created in your control panel. On dedicated servers, we'll create it if needed.</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="db_prefix">Table Prefix</label>
                        <input type="text" class="form-control" id="db_prefix" name="db_prefix" 
                               value="<?php echo htmlspecialchars($_POST['db_prefix'] ?? 'slocloud_tbl_'); ?>"
                               placeholder="e.g., slocloud_tbl_">
                        <small class="form-text text-muted">Prefix for all database tables. Include underscore if desired (e.g., slocloud_tbl_). Leave blank for no prefix.</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="db_user">Username</label>
                        <input type="text" class="form-control" id="db_user" name="db_user" 
                               value="<?php echo htmlspecialchars($_POST['db_user'] ?? 'root'); ?>" 
                               required>
                        <small class="form-text text-muted">Your MySQL username</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="db_pass">Password</label>
                        <input type="password" class="form-control" id="db_pass" name="db_pass" 
                               value="<?php echo htmlspecialchars($_POST['db_pass'] ?? ''); ?>">
                        <small class="form-text text-muted">Leave blank if no password is set</small>
                    </div>
                    
                    <!-- Email Configuration (Optional) -->
                    <h2 class="form-section-title"><i class="fas fa-envelope mr-2" aria-hidden="true"></i>Email Notifications <span class="badge badge-secondary" style="font-size: 11px;">Optional</span></h2>
                    <p class="text-muted mb-3" style="font-size: 14px;">Configure email settings to enable notifications for assessments, reminders, and reports. You can skip this and configure it later if needed.</p>
                    
                    <div class="form-group">
                        <label for="mail_method">Email Method</label>
                        <select class="form-control" id="mail_method" name="mail_method" aria-describedby="mail_method_help" onchange="toggleSmtpFields()">
                            <option value="disabled" <?php echo ($_POST['mail_method'] ?? 'disabled') === 'disabled' ? 'selected' : ''; ?>>Disabled (configure later)</option>
                            <option value="mail" <?php echo in_array($_POST['mail_method'] ?? '', ['mail', 'server'], true) ? 'selected' : ''; ?>>Server Mail (PHP mail function)</option>
                            <option value="smtp" <?php echo ($_POST['mail_method'] ?? '') === 'smtp' ? 'selected' : ''; ?>>SMTP (recommended)</option>
                        </select>
                        <small id="mail_method_help" class="form-text text-muted">Server Mail uses your server's built-in mail. SMTP is more reliable for delivery.</small>
                    </div>
                    
                    <div id="smtp-fields" style="display: <?php echo ($_POST['mail_method'] ?? 'disabled') === 'smtp' ? 'block' : 'none'; ?>;">
                        <div class="form-group">
                            <label for="smtp_host">SMTP Server</label>
                            <input type="text" class="form-control" id="smtp_host" name="smtp_host" 
                                   value="<?php echo htmlspecialchars($_POST['smtp_host'] ?? $templateDefaults['smtp_host'] ?? ''); ?>" 
                                   placeholder="e.g., smtp.gmail.com">
                            <small class="form-text text-muted">Your email server address</small>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="smtp_port">SMTP Port</label>
                                    <input type="number" class="form-control" id="smtp_port" name="smtp_port" 
                                           value="<?php echo htmlspecialchars($_POST['smtp_port'] ?? '587'); ?>" 
                                           min="1" max="65535">
                                    <small class="form-text text-muted">Usually 587 (TLS) or 465 (SSL)</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label for="smtp_encryption">Encryption</label>
                                    <select class="form-control" id="smtp_encryption" name="smtp_encryption">
                                        <option value="tls" <?php echo ($_POST['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                                        <option value="ssl" <?php echo ($_POST['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                                        <option value="none" <?php echo ($_POST['smtp_encryption'] ?? '') === 'none' ? 'selected' : ''; ?>>None</option>
                                    </select>
                                    <small class="form-text text-muted">Recommended: TLS</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="smtp_user">SMTP Username</label>
                            <input type="text" class="form-control" id="smtp_user" name="smtp_user" 
                                   value="<?php echo htmlspecialchars($_POST['smtp_user'] ?? $templateDefaults['smtp_username'] ?? ''); ?>" 
                                   placeholder="Your email or username">
                            <small class="form-text text-muted">Usually your full email address</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="smtp_pass">SMTP Password</label>
                            <input type="password" class="form-control" id="smtp_pass" name="smtp_pass" 
                                   value="<?php echo htmlspecialchars($_POST['smtp_pass'] ?? $templateDefaults['smtp_password'] ?? ''); ?>" 
                                   placeholder="Your email password or app password">
                            <small class="form-text text-muted">For Gmail, use an <a href="https://support.google.com/accounts/answer/185833" target="_blank" rel="noopener noreferrer">App Password<span class="visually-hidden"> (opens in a new tab)</span></a></small>
                        </div>
                    </div>
                    
                    <div id="email-common-fields" style="display: <?php echo ($_POST['mail_method'] ?? 'disabled') !== 'disabled' ? 'block' : 'none'; ?>;">
                        <div class="form-group">
                            <label for="smtp_from_email">From Email Address</label>
                            <input type="email" class="form-control" id="smtp_from_email" name="smtp_from_email" 
                                   value="<?php echo htmlspecialchars($_POST['smtp_from_email'] ?? $templateDefaults['from_email'] ?? ''); ?>" 
                                   placeholder="noreply@yourdomain.edu">
                            <small class="form-text text-muted">Email address that notifications will be sent from</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="smtp_from_name">From Name</label>
                            <input type="text" class="form-control" id="smtp_from_name" name="smtp_from_name" 
                                   value="<?php echo htmlspecialchars($_POST['smtp_from_name'] ?? $_POST['site_name'] ?? 'SLOCloud'); ?>" 
                                   placeholder="Your site name">
                            <small class="form-text text-muted">Display name for outgoing emails</small>
                        </div>
                    </div>
                    
                    <script>
                    function toggleSmtpFields() {
                        var method = document.getElementById('mail_method').value;
                        var smtpFields = document.getElementById('smtp-fields');
                        var commonFields = document.getElementById('email-common-fields');
                        
                        if (method === 'smtp') {
                            smtpFields.style.display = 'block';
                            commonFields.style.display = 'block';
                        } else if (method === 'mail') {
                            smtpFields.style.display = 'none';
                            commonFields.style.display = 'block';
                        } else {
                            smtpFields.style.display = 'none';
                            commonFields.style.display = 'none';
                        }
                    }
                    
                    function updatePortDefault() {
                        var driver = document.getElementById('db_driver').value;
                        var portField = document.getElementById('db_port');
                        
                        // Only update if port is still at default value
                        if (portField.value === '3306' || portField.value === '1433') {
                            portField.value = (driver === 'mssql') ? '1433' : '3306';
                        }
                    }
                    </script>
                    
                    <!-- Initial Administrator -->
                    <h2 class="form-section-title"><i class="fas fa-user-shield mr-2" aria-hidden="true"></i>Initial Administrator</h2>
                    <p class="text-muted mb-3" style="font-size: 14px;">
                        Create the named administrator for this installation. Emergency access remains disabled by default.
                    </p>

                    <div class="form-group">
                        <label for="admin_name">Full Name</label>
                        <input type="text" class="form-control" id="admin_name" name="admin_name"
                               value="<?php echo htmlspecialchars($_POST['admin_name'] ?? ''); ?>"
                               autocomplete="name" required>
                    </div>

                    <div class="form-group">
                        <label for="admin_email">Email Address</label>
                        <input type="email" class="form-control" id="admin_email" name="admin_email"
                               value="<?php echo htmlspecialchars($_POST['admin_email'] ?? ''); ?>"
                               autocomplete="email" required>
                    </div>

                    <div class="form-group">
                        <label for="admin_password">Password</label>
                        <input type="password" class="form-control" id="admin_password" name="admin_password"
                               minlength="12" autocomplete="new-password" required>
                        <small class="form-text text-muted">Use at least 12 characters. Password managers and paste are supported.</small>
                    </div>

                    <button type="submit" name="setup_submit" class="btn btn-primary btn-lg btn-block" <?php echo !$all_checks_pass ? 'disabled' : ''; ?>>
                        <i class="fas fa-rocket mr-2"></i>Install Now
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</main>

<!-- Bootstrap Bundle with Popper -->
<script src="<?= BASE_URL ?>assets/vendor/bootstrap/5.3.8/js/bootstrap.bundle.min.js"></script>
</body>
</html>
