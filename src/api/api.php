<?php
declare(strict_types=1);

/**
 * System Status API
 * 
 * Returns JSON status for automated monitoring systems.
 * Provides health check without exposing sensitive architecture details.
 * 
 * @package SLOCloud
 */

// Start timer
$startTime = microtime(true);

// Bootstrap: loads config, opens DB, starts session in the same save_path as all other pages
require_once __DIR__ . '/../system/includes/init.php';

// ---- Authentication --------------------------------------------------------
// Require either a configured API key (X-Api-Key header) or an admin session.
(function () {
    // Session already started by init.php with the correct save_path
    if (!empty($_SESSION['user_id'])) {
        global $db, $dbPrefix;
        if (\SLOCloud\Core\Authorization::isGlobalAdmin($db, $dbPrefix)) {
            return;
        }
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Forbidden', 'message' => 'Administrator permission required']);
        exit;
    }

    // Check API key
    $submittedKey = $_SERVER['HTTP_X_API_KEY'] ?? null;
    if ($submittedKey !== null) {
        global $config;
        $configuredKey = $config->get('api.monitoring_key', null);
        if ($configuredKey !== null && hash_equals((string)$configuredKey, $submittedKey)) {
            return; // valid API key
        }
    }

    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized', 'message' => 'Valid X-Api-Key header or administrator session required']);
    exit;
})();
// ---------------------------------------------------------------------------

header('Content-Type: application/json');

$status = [
    'timestamp' => date('c'),
    'version' => null,
    'overall_status' => 'operational',
    'checks' => [],
    '_meta' => [
        'check_status_levels' => ['ok', 'warning', 'critical'],
        'overall_status_levels' => ['operational', 'degraded', 'down']
    ]
];

// Check 1: Configuration File
try {
    $configPath = __DIR__ . '/../config/config.yaml';
    if (file_exists($configPath) && is_readable($configPath)) {
        $status['checks']['config'] = [
            'status' => 'ok',
            'component' => 'configuration',
            'message' => 'Configuration accessible'
        ];
    } else {
        $status['checks']['config'] = [
            'status' => 'critical',
            'component' => 'configuration',
            'message' => 'Configuration file not found'
        ];
        $status['overall_status'] = 'degraded';
    }
} catch (Exception $e) {
    $status['checks']['config'] = [
        'status' => 'critical',
        'component' => 'configuration',
        'message' => 'Configuration check failed'
    ];
    $status['overall_status'] = 'degraded';
}

// Check 2: Application Version
try {
    $versionFile = __DIR__ . '/../VERSION';
    if (file_exists($versionFile)) {
        $status['version'] = trim(file_get_contents($versionFile));
    }
} catch (Exception $e) {
    // Version is optional metadata, don't fail if unavailable
    $status['version'] = null;
}

// Check 2b: Required PHP Extensions
try {
    $requiredExtensions = [
        'pdo' => 'pdo',
        'yaml' => 'yaml',
        'json' => 'json',
        'mbstring' => 'mbstring',
        'openssl' => 'openssl',
    ];
    $missingExtensions = [];
    foreach ($requiredExtensions as $label => $extName) {
        if (!extension_loaded($extName)) {
            $missingExtensions[] = $label;
        }
    }

    $hasDbDriver = extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlsrv');
    if (!$hasDbDriver) {
        $missingExtensions[] = 'pdo_mysql or pdo_sqlsrv';
    }

    $argon2Ok = defined('PASSWORD_ARGON2ID');
    if (!$argon2Ok) {
        $missingExtensions[] = 'argon2 (libargon2)';
    }

    if (empty($missingExtensions)) {
        $status['checks']['php_extensions'] = [
            'status' => 'ok',
            'component' => 'php',
            'message' => 'All required PHP extensions loaded'
        ];
    } else {
        $status['checks']['php_extensions'] = [
            'status' => 'critical',
            'component' => 'php',
            'message' => 'Missing required PHP extensions/support: ' . implode(', ', $missingExtensions)
        ];
        $status['overall_status'] = 'degraded';
    }
} catch (Exception $e) {
    $status['checks']['php_extensions'] = [
        'status' => 'critical',
        'component' => 'php',
        'message' => 'PHP extension check failed'
    ];
    $status['overall_status'] = 'degraded';
}

// Check 3: Session Support
try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['health_check'] = time();
    if (isset($_SESSION['health_check'])) {
        $status['checks']['session'] = [
            'status' => 'ok',
            'component' => 'infrastructure',
            'message' => 'Session storage operational'
        ];
    } else {
        $status['checks']['session'] = [
            'status' => 'critical',
            'component' => 'infrastructure',
            'message' => 'Session storage unavailable'
        ];
        $status['overall_status'] = 'degraded';
    }
} catch (Exception $e) {
    $status['checks']['session'] = [
        'status' => 'critical',
        'component' => 'infrastructure',
        'message' => 'Session check failed'
    ];
    $status['overall_status'] = 'degraded';
}

// Check 4: Configuration Decryption (if config exists)
if ($status['checks']['config']['status'] === 'ok') {
    try {
        require_once __DIR__ . '/../system/Core/Config.php';
        require_once __DIR__ . '/../system/Core/Encryption.php';
        
        $config = \SLOCloud\Core\Config::getInstance($configPath);
        $configData = $config->allDecrypted();
        
        // Verify a known key exists (don't expose values)
        if (isset($configData['app']['name'])) {
            $status['checks']['config_decrypt'] = [
                'status' => 'ok',
                'component' => 'configuration',
                'message' => 'Configuration parsed successfully'
            ];
        } else {
            $status['checks']['config_decrypt'] = [
                'status' => 'critical',
                'component' => 'configuration',
                'message' => 'Configuration structure invalid'
            ];
            $status['overall_status'] = 'degraded';
        }
    } catch (Exception $e) {
        $status['checks']['config_decrypt'] = [
            'status' => 'critical',
            'component' => 'configuration',
            'message' => 'Configuration parsing failed'
        ];
        $status['overall_status'] = 'degraded';
    }
}

// Check 5: Database Connection (if config decryption succeeded)
if (isset($status['checks']['config_decrypt']) && $status['checks']['config_decrypt']['status'] === 'ok') {
    try {
        require_once __DIR__ . '/../system/Core/Database.php';
        
        $dbConfig = $configData['database'] ?? null;
        if ($dbConfig) {
            $db = \SLOCloud\Core\Database::getInstance($dbConfig);
            
            $status['checks']['database_connection'] = [
                'status' => 'ok',
                'component' => 'database',
                'message' => 'Database connection established'
            ];
            
            // Check 6: Database Query
            try {
                $result = $db->query("SELECT 1 as test");
                if ($result && $row = $result->fetch()) {
                    $status['checks']['database_query'] = [
                        'status' => 'ok',
                        'component' => 'database',
                        'message' => 'Database queries functional'
                    ];
                } else {
                    $status['checks']['database_query'] = [
                        'status' => 'critical',
                        'component' => 'database',
                        'message' => 'Database query test failed'
                    ];
                    $status['overall_status'] = 'degraded';
                }
            } catch (Exception $e) {
                $status['checks']['database_query'] = [
                    'status' => 'critical',
                    'component' => 'database',
                    'message' => 'Database query failed'
                ];
                $status['overall_status'] = 'degraded';
            }
        } else {
            $status['checks']['database_connection'] = [
                'status' => 'critical',
                'component' => 'database',
                'message' => 'Database configuration missing'
            ];
            $status['overall_status'] = 'degraded';
        }
    } catch (Exception $e) {
        $status['checks']['database_connection'] = [
            'status' => 'critical',
            'component' => 'database',
            'message' => 'Database connection failed'
        ];
        $status['overall_status'] = 'down';
    }
}

// Check 7: LTI OAuth Enforcement
if (isset($configData['lti']['oauth_enforcement'])) {
    $oauthEnforcement = $configData['lti']['oauth_enforcement'];
    if ($oauthEnforcement === true) {
        $status['checks']['oauth_enforcement'] = [
            'status' => 'ok',
            'component' => 'security',
            'message' => 'OAuth signature validation enabled'
        ];
    } else {
        $status['checks']['oauth_enforcement'] = [
            'status' => 'warning',
            'component' => 'security',
            'message' => 'OAuth signature validation disabled (development mode)'
        ];
    }
}

// Check 8: Debug Mode
if (isset($configData['app']['debug'])) {
    $debugMode = $configData['app']['debug'];
    if ($debugMode === false) {
        $status['checks']['debug_mode'] = [
            'status' => 'ok',
            'component' => 'security',
            'message' => 'Debug mode disabled'
        ];
    } else {
        $status['checks']['debug_mode'] = [
            'status' => 'warning',
            'component' => 'security',
            'message' => 'Debug mode enabled (should be disabled in production)'
        ];
    }
}

// Check 9: File System Permissions
try {
    $logsPath = $logDirectory;
    if (is_dir($logsPath) && is_writable($logsPath)) {
        $status['checks']['filesystem'] = [
            'status' => 'ok',
            'component' => 'infrastructure',
            'message' => 'File system writable'
        ];
    } else {
        $status['checks']['filesystem'] = [
            'status' => 'warning',
            'component' => 'infrastructure',
            'message' => 'Log directory not writable'
        ];
        if ($status['overall_status'] === 'operational') {
            $status['overall_status'] = 'degraded';
        }
    }
} catch (Exception $e) {
    $status['checks']['filesystem'] = [
        'status' => 'warning',
        'component' => 'infrastructure',
        'message' => 'File system check failed'
    ];
}

// Calculate overall status based on check results
$criticalCount = 0;
$warningCount = 0;
foreach ($status['checks'] as $check) {
    if ($check['status'] === 'critical') {
        $criticalCount++;
    } elseif ($check['status'] === 'warning') {
        $warningCount++;
    }
}

// Override overall status based on critical errors
if ($criticalCount >= 3) {
    $status['overall_status'] = 'down';
} elseif ($criticalCount >= 1) {
    $status['overall_status'] = 'degraded';
} elseif ($warningCount >= 2) {
    $status['overall_status'] = 'degraded';
}

// Calculate load time
$endTime = microtime(true);
$status['load_time_ms'] = round(($endTime - $startTime) * 1000, 2);

// Check 10: Performance (Load Time)
if ($status['load_time_ms'] < 100) {
    $status['checks']['performance'] = [
        'status' => 'ok',
        'component' => 'application',
        'message' => 'Response time: ' . $status['load_time_ms'] . 'ms (excellent)'
    ];
} elseif ($status['load_time_ms'] < 500) {
    $status['checks']['performance'] = [
        'status' => 'warning',
        'component' => 'application',
        'message' => 'Response time: ' . $status['load_time_ms'] . 'ms (slow)'
    ];
    if ($status['overall_status'] === 'operational') {
        $status['overall_status'] = 'degraded';
    }
} else {
    $status['checks']['performance'] = [
        'status' => 'critical',
        'component' => 'application',
        'message' => 'Response time: ' . $status['load_time_ms'] . 'ms (critical)'
    ];
    $status['overall_status'] = 'degraded';
}

echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
