<?php
declare(strict_types=1);

/**
 * SLOCloud Front Controller
 * 
 * Entry point for all application requests.
 * Routes requests to appropriate controllers.
 * 
 * @package SLOCloud
 */

require_once __DIR__ . '/system/Core/Security.php';
$applicationDataRoot = \SLOCloud\Core\Security::dataRoot(__DIR__);
\SLOCloud\Core\Security::configureSession($applicationDataRoot . '/var/sessions');
\SLOCloud\Core\Security::sendResponseHeaders();

// Error reporting (adjust for production)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Ensure logs directory exists
$logDir = $applicationDataRoot . '/logs';
if (!is_dir($logDir)) {
    mkdir($logDir, 0700, true);
}
@chmod($logDir, 0700);
ini_set('error_log', $logDir . '/php_errors.log');

// Define application constants
define('APP_ROOT', __DIR__);
define('CONFIG_PATH', APP_ROOT . '/config/config.yaml');

// Load path helper for URL routing
require_once APP_ROOT . '/system/Core/Path.php';

// Load configuration if it exists
if (file_exists(CONFIG_PATH)) {
    // Configuration exists - application is set up
    require_once APP_ROOT . '/system/Core/Config.php';
    
    try {
        $config = \SLOCloud\Core\Config::getInstance(CONFIG_PATH);
        
        // Set base URL from config for better performance
        $baseUrl = $config->get('app.base_url', '/');
        \SLOCloud\Core\Path::setConfiguredBaseUrl($baseUrl);
        
        // Load application configuration
        $siteName = $config->get('app.name', 'SLOCloud');
        $dbPrefix = $config->get('database.prefix', '');
        $emailMethod = $config->get('email.method', 'disabled');
        $emailFromEmail = $config->get('email.from_email', '');
        $emailFromName = $config->get('email.from_name', $siteName);
        
    } catch (Exception $e) {
        error_log('Configuration error: ' . $e->getMessage());
        http_response_code(500);
        require_once APP_ROOT . '/system/includes/message_page.php';
        render_message_page('error', 'Configuration Error', 
            'Unable to load application configuration. Please check your config.yaml file.',
            'fa-exclamation-triangle text-danger');
        exit(1);
    }
    
    // Define constants for easy access throughout the application
    define('BASE_URL', \SLOCloud\Core\Path::getBaseUrl());
    define('BASE_PATH', \SLOCloud\Core\Path::getBasePath());
    define('SITE_NAME', $siteName);
    define('DB_PREFIX', $dbPrefix);
    define('EMAIL_METHOD', $emailMethod);
    define('EMAIL_FROM_EMAIL', $emailFromEmail);
    define('EMAIL_FROM_NAME', $emailFromName);
    define('DEBUG_MODE', ($config->get('app.debug_mode', 'false') === 'true' || $config->get('app.debug_mode', false) === true));
    
    // Errors are always logged to file, never displayed on screen (see init.php php_error_mode)
    
    // Check authentication
    if (!isset($_SESSION['user_id'])) {
        // Not logged in - redirect to login page
        \SLOCloud\Core\Path::redirect('login.php');
    } else {
        // Logged in - redirect to administration dashboard
        \SLOCloud\Core\Path::redirect('administration/');
    }
    
} else {
    // No configuration - redirect to web-based setup
    if (file_exists(APP_ROOT . '/setup/index.php')) {
        // Use Path helper for proper base-aware redirection
        \SLOCloud\Core\Path::redirect('setup/');
    } else {
        http_response_code(500);
        require_once APP_ROOT . '/system/includes/message_page.php';
        render_message_page('error', 'Installation Error',
            'Configuration file not found and setup script is missing.<br>Please reinstall or create <code>src/config/config.yaml</code> manually.',
            'fa-times-circle text-danger');
        exit(1);
    }
}
