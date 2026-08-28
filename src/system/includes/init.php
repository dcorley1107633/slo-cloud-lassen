<?php
declare(strict_types=1);

/**
 * Common Initialization
 * 
 * Sets up configuration, database connection, and common variables.
 * Include this at the top of every page that needs database access.
 * 
 * Security: secrets are never cached in session; session state is stored outside src/.
 * Backward Compatibility: Template defaults are merged with user config automatically.
 * 
 * Available after include:
 *   $config      - Config instance (use $config->get('key'))
 *   $configData  - Full config array (decrypted values, from session cache)
 *   $db          - Database connection instance
 *   $logger      - Logger instance for error and security logging
 *   $baseUrl     - Application base URL
 *   $basePath    - Application base filesystem path
 *   $siteName    - Site name from config
 *   $dbPrefix    - Database table prefix
 *   $debugMode   - Debug mode boolean
 *   $appVersion  - Application version from VERSION file
 *   BASE_URL     - Constant alternative to $baseUrl
 *   BASE_PATH    - Constant alternative to $basePath
 *   SITE_NAME    - Constant alternative to $siteName
 *   APP_VERSION  - Constant alternative to $appVersion
 *   MAINTENANCE_MODE - Boolean indicating if maintenance mode is enabled
 *   EMERGENCY_ADMIN_ENABLED  - Whether emergency admin is enabled
 *   EMERGENCY_ADMIN_USERNAME - Emergency admin username (break glass)
 *   EMERGENCY_ADMIN_PASSWORD - Emergency admin password (break glass)
 * 
 * Helper functions (loaded via helpers.php):
 *   cacheTermInSession()       - Cache term info in session
 *   getSelectedTermFk()        - Get selected term ID with auto-selection
 *   getSelectedTermName()      - Get selected term name
 *   getSelectedTermCode()      - Get selected term code
 *   getSelectedTermBanner()    - Get selected term banner code
 *   cleanupOrphanedStudents()  - Remove students with no enrollments
 * 
 * Usage:
 *   require_once __DIR__ . '/../system/includes/init.php';
 */

// Security initialization
require_once __DIR__ . '/../Core/Security.php';
$applicationDataRoot = \SLOCloud\Core\Security::dataRoot(dirname(__DIR__, 2));
// Redirect before the session cookie is issued, so a Secure cookie is never
// attempted on a plaintext connection.
\SLOCloud\Core\Security::requireHttps();
\SLOCloud\Core\Security::configureSession($applicationDataRoot . '/var/sessions');
\SLOCloud\Core\Security::sendResponseHeaders();

// Explicit UTF-8 configuration for entire pipeline
// This ensures consistent encoding across file I/O, string operations, and output
ini_set('default_charset', 'UTF-8');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
    mb_http_output('UTF-8');
    mb_regex_encoding('UTF-8');
}

// Session IP validation (prevent session hijacking for dashboard/admin sessions only).
// Exempt: LTI launch POST requests (launch.php) - the POST comes from the LMS server,
// so its IP would be stored as initial_ip, causing all subsequent browser requests to
// fail the check. Also exempt active LTI sessions for the same reason.
$currentIp = $_SERVER['REMOTE_ADDR'] ?? '';
$isLtiLaunchPost = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['oauth_consumer_key']));
// Detect active LTI session using the per-launch token store (lti_authenticated flat key
// was removed when multi-launch support was added; lti_launches presence is the signal).
$isLtiSession = !empty($_SESSION['lti_launches']);
if (!$isLtiLaunchPost && !$isLtiSession) {
    if (!isset($_SESSION['initial_ip'])) {
        $_SESSION['initial_ip'] = $currentIp;
    } elseif ($_SESSION['initial_ip'] !== $currentIp) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['initial_ip'] = $currentIp;
    }
}

// Generate CSRF token for forms
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Load core classes
require_once __DIR__ . '/../Core/Config.php';
require_once __DIR__ . '/../Core/Database.php';
require_once __DIR__ . '/../Core/Path.php';
require_once __DIR__ . '/../Core/Logger.php';
require_once __DIR__ . '/../Core/Authorization.php';
require_once __DIR__ . '/../Core/Encryption.php';
require_once __DIR__ . '/../Core/PasswordPolicy.php';
require_once __DIR__ . '/../Core/StudentEncryption.php';
require_once __DIR__ . '/../Core/LtiLaunchContext.php';
require_once __DIR__ . '/../Core/Theme.php';
require_once __DIR__ . '/../Core/ThemeContext.php';
require_once __DIR__ . '/../Core/ThemeLoader.php';

// Load helper functions
require_once __DIR__ . '/csv_helpers.php';  // UTF-8 CSV handling
require_once __DIR__ . '/lifecycle.php';

// Check if configured
if (!file_exists(__DIR__ . '/../../config/config.yaml')) {
    \SLOCloud\Core\Path::redirect('setup/');
}
@chmod(__DIR__ . '/../../config', 0700);
@chmod(__DIR__ . '/../../config/config.yaml', 0600);
$configuredKeyPath = getenv('SLOCloud_KEY_PATH');
if (is_string($configuredKeyPath) && $configuredKeyPath !== '') {
    @chmod($configuredKeyPath, 0600);
} elseif (file_exists(__DIR__ . '/../../config/key.yaml')) {
    @chmod(__DIR__ . '/../../config/key.yaml', 0600);
}

// Load configuration for this request. Decrypted secrets must never be serialized
// into a PHP session record.
$configPath = __DIR__ . '/../../config/config.yaml';
$config = \SLOCloud\Core\Config::getInstance($configPath);
$configData = $config->allDecrypted();

// Set configured base URL if available (for performance)
$configuredBaseUrl = $config->get('app.base_url');
if ($configuredBaseUrl) {
    \SLOCloud\Core\Path::setConfiguredBaseUrl($configuredBaseUrl);
}

// Common variables (no constants - easier to override if needed)
$baseUrl = \SLOCloud\Core\Path::getBaseUrl();
$basePath = \SLOCloud\Core\Path::getBasePath();
$siteName = $config->get('app.name', 'SLOCloud');
$dbPrefix = $config->get('database.prefix', '');
$debugMode = $config->get('app.debug_mode', false) === true || $config->get('app.debug_mode', 'false') === 'true';
$appVersion = trim(file_get_contents(__DIR__ . '/../../VERSION'));

// Configure PHP error handling based on php_error_mode setting
$phpErrorMode = $config->get('app.php_error_mode', 'logged');

// Use configured log directory if set, otherwise default outside the document root.
$configuredLogDir = $config->get('logging.log_directory', '');
$logDirectory = ($configuredLogDir !== '' && $configuredLogDir !== null)
    ? rtrim($configuredLogDir, '/\\')
    : $applicationDataRoot . '/logs';
$configData['logging']['log_directory'] = $logDirectory;

// Ensure logs directory exists
if (!is_dir($logDirectory)) {
    @mkdir($logDirectory, 0700, true);
}
@chmod($logDirectory, 0700);

switch ($phpErrorMode) {
    case 'off':
        // Disable all error reporting (not recommended)
        error_reporting(0);
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '0');
        break;
        
    case 'onscreen':
        // Display errors on screen (development only - UNSAFE for production)
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
        ini_set('display_startup_errors', '1');
        ini_set('log_errors', '1');
        ini_set('error_log', $logDirectory . '/php_errors.log');
        break;
        
    case 'logged':
        // Log errors to file only, no display (production safe - RECOMMENDED)
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('display_startup_errors', '0');
        ini_set('log_errors', '1');
        ini_set('error_log', $logDirectory . '/php_errors.log');
        break;
        
    case 'system':
        // Use php.ini defaults (don't override anything)
        // No ini_set calls - respect system configuration
        break;
        
    default:
        // Fallback to logged mode if invalid value
        error_reporting(E_ALL);
        ini_set('display_errors', '0');
        ini_set('log_errors', '1');
        ini_set('error_log', $logDirectory . '/php_errors.log');
        break;
}

// Legacy debug_mode still available for application logic (not error display)
// Use php_error_mode for error handling, debug_mode for feature flags

// Define constants for template convenience
if (!defined('BASE_URL')) define('BASE_URL', $baseUrl);
if (!defined('BASE_PATH')) define('BASE_PATH', $basePath);
if (!defined('SITE_NAME')) define('SITE_NAME', $siteName);
if (!defined('APP_VERSION')) define('APP_VERSION', $appVersion);
if (!defined('DEBUG_MODE')) define('DEBUG_MODE', $debugMode);
if (!defined('MAINTENANCE_MODE')) define('MAINTENANCE_MODE', $config->get('app.maintenance_mode', false) === true);

// Emergency admin (break glass account)
if (!defined('EMERGENCY_ADMIN_ENABLED')) {
    define('EMERGENCY_ADMIN_ENABLED', $config->get('emergency_admin.enabled', false) === true);
}
if (!defined('EMERGENCY_ADMIN_USERNAME')) {
    define('EMERGENCY_ADMIN_USERNAME', $config->get('emergency_admin.username', null));
}
if (!defined('EMERGENCY_ADMIN_PASSWORD')) {
    define('EMERGENCY_ADMIN_PASSWORD', $config->get('emergency_admin.password', null));
}

// Config class now auto-decrypts sensitive fields (database.password, email.smtp_password, lti.consumer_secret)
// Rebuild config array with decrypted values for legacy code that uses $configData directly
$configData['database']['password'] = $config->get('database.password', '');
if (isset($configData['email']['smtp_password'])) {
    $configData['email']['smtp_password'] = $config->get('email.smtp_password', '');
}

// Initialize database connection
$db = \SLOCloud\Core\Database::getInstance($configData['database']);

// Initialize logger
$logger = \SLOCloud\Core\Logger::getInstance($configData, $db->getConnection());

// Ensure critical variables are in GLOBALS for theme access
$GLOBALS['db'] = $db;
$GLOBALS['dbPrefix'] = $dbPrefix;
$GLOBALS['config'] = $config;

// Load helper functions
require_once __DIR__ . '/helpers.php';

// ============================================================================
// AUTHENTICATION CHECK FOR ADMIN PAGES
// ============================================================================
// Automatically protect all pages except explicitly exempt ones.
$scriptPath = \SLOCloud\Core\Security::relativeScriptPath();
$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$routePolicy = \SLOCloud\Core\Authorization::policyForRequest($scriptPath, $requestMethod);
if ($routePolicy === 'deny' || $routePolicy === 'include_only' || $routePolicy === 'installation_token') {
    \SLOCloud\Core\Authorization::denyUnclassifiedRoute($logger, $scriptPath);
}
$isExemptPage = in_array($routePolicy, [
    'public', 'api_key_or_admin', 'lti_protocol', 'lti_launch_context', 'saml_protocol',
], true);

if (!$isExemptPage) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }

    // Session inactivity timeout: 2 hours
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 7200) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['flash_error'] = 'Your session has expired. Please log in again.';
        header('Location: ' . BASE_URL . 'login.php');
        exit;
    }
    // session.php performs the touch only after validating its POST token.
    if ($scriptPath !== 'session.php') {
        $_SESSION['last_activity'] = time();
    }
    \SLOCloud\Core\Security::rotateSessionIfDue();

    // For emergency admin (user_id = 0), skip database check
    $isEmergencyAdmin = (isset($_SESSION['is_emergency_admin']) && $_SESSION['is_emergency_admin'] === true);
    
    if (!$isEmergencyAdmin) {
        $authUserId = (int)$_SESSION['user_id'];
        
        $authCheckResult = $db->query(
            "SELECT users_pk, is_active FROM {$dbPrefix}users WHERE users_pk = ?",
            [$authUserId],
            'i'
        );
        
        $authUser = $authCheckResult ? $authCheckResult->fetch() : false;
        
        if (!$authUser || !$authUser['is_active']) {
            session_unset();
            session_destroy();
            
            $logger->warning('Access denied - user deactivated or deleted', [
                'user_id' => $authUserId,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            
            header('Location: ' . BASE_URL . 'login.php');
            exit;
        }
    }

    if ($routePolicy === 'admin' || $routePolicy === 'admin_recent_auth') {
        if (str_starts_with($scriptPath, 'administration/')) {
            \SLOCloud\Core\Authorization::requireAdministrationRoute($db, $dbPrefix, $logger, $scriptPath, $requestMethod);
        } else {
            \SLOCloud\Core\Authorization::requireGlobalAdmin($db, $dbPrefix, $logger, $scriptPath);
        }
        if ($routePolicy === 'admin_recent_auth') {
            \SLOCloud\Core\Authorization::requireRecentAuthentication($logger, $scriptPath);
        }
    }
}
