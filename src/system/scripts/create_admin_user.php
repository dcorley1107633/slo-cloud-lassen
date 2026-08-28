#!/usr/bin/env php
<?php
/**
 * Create Admin User Script
 * 
 * Creates an initial admin user in the SLOCloud system.
 * Must be run after database setup has been completed.
 * 
 * Usage: php src/system/scripts/create_admin_user.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../Core/Config.php';
require_once __DIR__ . '/../Core/Database.php';
require_once __DIR__ . '/../Core/Security.php';
require_once __DIR__ . '/../Core/PasswordPolicy.php';

use SLOCloud\Core\Database;
use SLOCloud\Core\Config;
use SLOCloud\Core\Security;

$configFile = __DIR__ . '/../../config/config.yaml';
if (!is_file($configFile)) {
    fwrite(STDERR, "Configuration file not found. Run setup first.\n");
    exit(1);
}

try {
    $configObject = Config::getInstance($configFile);
    $config = $configObject->allDecrypted();
} catch (Throwable) {
    fwrite(STDERR, "Configuration could not be loaded safely.\n");
    exit(1);
}

define('SITE_NAME', (string)($config['app']['name'] ?? 'SLOCloud'));
$dbPrefix = (string)($config['database']['prefix'] ?? '');

// Setup log file outside the document root.
$logFile = Security::dataRoot(dirname(__DIR__, 2)) . '/logs/admin_user_' . date('Y-m-d_His') . '.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    mkdir($logDir, 0700, true);
}
@chmod($logDir, 0700);

// Logging function
function log_message(string $message, string $level = 'INFO'): void {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    @chmod($logFile, 0600);
}

// Security logging to database
function log_security_event(Database $db, string $eventType, string $description, ?int $userFk = null, ?string $username = null): void {
    global $dbPrefix;
    $stmt = $db->query(
        "INSERT INTO {$dbPrefix}security_log (event_type, event_description, user_fk, username, ip_address, severity) 
         VALUES (?, ?, ?, ?, ?, 'info')",
        [$eventType, $description, $userFk, $username === null ? null : 'id:' . substr(hash('sha256', $username), 0, 16), 'CLI']
    );
}

// Color output for CLI
function color_output(string $message, string $color = 'green'): void {
    $colors = [
        'red' => "\033[0;31m",
        'green' => "\033[0;32m",
        'yellow' => "\033[0;33m",
        'blue' => "\033[0;34m",
        'reset' => "\033[0m"
    ];
    echo $colors[$color] . $message . $colors['reset'] . PHP_EOL;
    
    // Also log to file
    log_message($message, $color === 'red' ? 'ERROR' : ($color === 'yellow' ? 'INFO' : 'SUCCESS'));
}

function prompt(string $message, string $default = ''): string {
    $defaultText = $default ? " [$default]" : '';
    echo $message . $defaultText . ': ';
    $input = trim(fgets(STDIN));
    return $input ?: $default;
}

function prompt_hidden(string $message): string {
    echo $message . ': ';
    
    // Windows-specific hidden input
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $command = 'powershell -Command "$password = Read-Host -AsSecureString; [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($password))"';
        $password = shell_exec($command);
        return trim($password);
    }
    
    // Unix-based systems
    system('stty -echo');
    $password = trim(fgets(STDIN));
    system('stty echo');
    echo PHP_EOL;
    return $password;
}

function validate_email(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Check a password against the shared policy.
 *
 * @return string|null Null when acceptable, otherwise the problem to display
 */
function validate_password(string $password, string $email = '', string $name = ''): ?string {
    return \SLOCloud\Core\PasswordPolicy::firstProblem($password, [$email, $name]);
}

// ============================================================================
// MAIN SCRIPT
// ============================================================================

echo PHP_EOL;
color_output("+===============================================================+", 'blue');
color_output("|          " . SITE_NAME . " Admin User Creation" . str_repeat(' ', 60 - strlen(SITE_NAME) - 23) . "|", 'blue');
color_output("+===============================================================+", 'blue');
echo PHP_EOL;

log_message("========================================");
log_message("Admin User Creation Started");
log_message("Log file: $logFile");
log_message("========================================");

// Connect to database using Database class
try {
    log_message('Connecting to configured database');
    
    $db = Database::getInstance($config['database']);
    
    log_message("Database connection successful");
} catch (Exception $e) {
    log_message('Database connection failed', 'ERROR');
    color_output('[X] Database connection failed.', 'red');
    exit(1);
}

color_output("[OK] Connected to database", 'green');
echo PHP_EOL;

// Collect user information
color_output("Enter admin user details:", 'yellow');
echo PHP_EOL;

$fullName = prompt("Full Name");
if (empty($fullName)) {
    color_output("[X] Full name is required", 'red');
    exit(1);
}

$email = prompt("Email");
if (!validate_email($email)) {
    log_message('Email validation failed', 'ERROR');
    color_output("[X] Invalid email address", 'red');
    exit(1);
}

log_message('Email validated');

$password = prompt_hidden("Password (minimum " . \SLOCloud\Core\PasswordPolicy::MINIMUM_LENGTH . " characters)");
$passwordProblem = validate_password($password, $email, $fullName);
if ($passwordProblem !== null) {
    log_message("Password validation failed: {$passwordProblem}", 'ERROR');
    color_output("[X] {$passwordProblem}", 'red');
    exit(1);
}

$passwordConfirm = prompt_hidden("Confirm Password");
if ($password !== $passwordConfirm) {
    log_message("Password confirmation failed: mismatch", 'ERROR');
    color_output("[X] Passwords do not match", 'red');
    exit(1);
}

log_message("Password validated successfully");

echo PHP_EOL;
color_output("Creating admin user...", 'yellow');

// Hash password with Argon2id
log_message("Hashing password with Argon2id");
$passwordHash = password_hash($password, PASSWORD_ARGON2ID, [
    'memory_cost' => 65536,  // 64 MB
    'time_cost' => 4,
    'threads' => 2
]);

// Insert user
log_message('Inserting administrator user record');
try {
    $stmt = $db->query(
        "INSERT INTO {$dbPrefix}users (full_name, email, password_hash, is_active) 
         VALUES (?, ?, ?, 1)",
        [$fullName, $email, $passwordHash]
    );
    
    $userPk = $db->getInsertId();
} catch (Exception $e) {
    log_message("Failed to create user: " . $e->getMessage(), 'ERROR');
    color_output("[X] Failed to create user: " . $e->getMessage(), 'red');
    exit(1);
}

log_message("User created successfully with PK: $userPk");
color_output("[OK] User created with ID: $userPk", 'green');

// Log security event
log_security_event($db, 'admin_user_created', 'Administrator user created by CLI', $userPk, $email);

// Assign admin role
log_message("Assigning admin role to user PK: $userPk");
try {
    $stmt = $db->query(
        "INSERT INTO {$dbPrefix}user_roles (user_fk, role_fk, context_type, context_id) 
         VALUES (?, (SELECT roles_pk FROM {$dbPrefix}roles WHERE role_name = 'admin'), NULL, NULL)",
        [$userPk]
    );
    
    log_message("Admin role assigned successfully");
    log_security_event($db, 'admin_role_assigned', 'Global administrator role assigned by CLI', $userPk, $email);
    
    color_output("OK Admin role assigned", 'green');
} catch (Exception $e) {
    log_message("Failed to assign admin role: " . $e->getMessage(), 'ERROR');
    color_output("X Failed to assign admin role: " . $e->getMessage(), 'red');
    exit(1);
}
echo PHP_EOL;

// Success summary
log_message("========================================");
log_message("Admin User Created Successfully");
log_message("Role: Global Admin");
log_message("========================================");

color_output("+===============================================================+", 'green');
color_output("|                  Admin User Created!                         |", 'green');
color_output("+===============================================================+", 'green');
echo PHP_EOL;

echo "Email: {$email}" . PHP_EOL;
color_output("Role: Global Admin", 'green');
color_output("Log: $logFile", 'green');
echo PHP_EOL;

color_output("You can now log in to " . SITE_NAME . " using these credentials.", 'yellow');
echo PHP_EOL;

exit(0);
