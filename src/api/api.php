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
        // Saving System > Configuration stores an unset key as '', which an
        // empty X-Api-Key header would otherwise match.
        if (is_string($configuredKey) && $configuredKey !== '' && hash_equals($configuredKey, (string)$submittedKey)) {
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
    }
} catch (Exception $e) {
    $status['checks']['config'] = [
        'status' => 'critical',
        'component' => 'configuration',
        'message' => 'Configuration check failed'
    ];
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
    }
} catch (Exception $e) {
    $status['checks']['php_extensions'] = [
        'status' => 'critical',
        'component' => 'php',
        'message' => 'PHP extension check failed'
    ];
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
    }
} catch (Exception $e) {
    $status['checks']['session'] = [
        'status' => 'critical',
        'component' => 'infrastructure',
        'message' => 'Session check failed'
    ];
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
        }
    } catch (Exception $e) {
        $status['checks']['config_decrypt'] = [
            'status' => 'critical',
            'component' => 'configuration',
            'message' => 'Configuration parsing failed'
        ];
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
                }
            } catch (Exception $e) {
                $status['checks']['database_query'] = [
                    'status' => 'critical',
                    'component' => 'database',
                    'message' => 'Database query failed'
                ];
            }
        } else {
            $status['checks']['database_connection'] = [
                'status' => 'critical',
                'component' => 'database',
                'message' => 'Database configuration missing'
            ];
        }
    } catch (Exception $e) {
        $status['checks']['database_connection'] = [
            'status' => 'critical',
            'component' => 'database',
            'message' => 'Database connection failed'
        ];
    }
}

// Check 7: LTI OAuth Enforcement (same setting oauth_validate.php enforces)
if (isset($config)) {
    $oauthEnforcement = filter_var($config->get('lti.validate_signatures', true), FILTER_VALIDATE_BOOLEAN);
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

// Check 8: Debug Mode (same interpretation of app.debug_mode as init.php)
if (isset($config)) {
    $debugMode = $config->get('app.debug_mode', false) === true || $config->get('app.debug_mode', 'false') === 'true';
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
    }
} catch (Exception $e) {
    $status['checks']['filesystem'] = [
        'status' => 'warning',
        'component' => 'infrastructure',
        'message' => 'File system check failed'
    ];
}

// Check 10: Encryption Key Location
// key.yaml holds the master keys. Inside the web root its only protection is the
// web server's deny rules, so any copy found there fails. Candidates follow
// Config's lookup order, plus the copies key rotation keeps in config/backups.
// The site's document root counts too: a flat deploy's sibling data directory
// can sit inside it. Only web-root-relative paths are reported.
try {
    $caseInsensitivePaths = PHP_OS_FAMILY === 'Windows';
    $webRoots = [];
    foreach ([dirname(__DIR__), (string)($_SERVER['DOCUMENT_ROOT'] ?? '')] as $root) {
        $resolvedRoot = $root !== '' ? realpath($root) : false;
        if ($resolvedRoot !== false) {
            $webRoots[] = rtrim(str_replace('\\', '/', $resolvedRoot), '/') . '/';
        }
    }

    $configDirectory = dirname(__DIR__) . '/config';
    $keyCandidates = array_merge(
        $config->keyPathCandidates(),
        glob($configDirectory . '/backups/*/key.yaml') ?: []
    );

    $keyFound = false;
    $exposedKeys = [];
    foreach ($keyCandidates as $candidate) {
        $resolvedKey = realpath($candidate);
        if ($resolvedKey === false || !is_file($resolvedKey)) {
            continue;
        }
        $keyFound = true;
        $keyPath = str_replace('\\', '/', $resolvedKey);
        foreach ($webRoots as $webRoot) {
            $inside = $caseInsensitivePaths
                ? str_starts_with(strtolower($keyPath), strtolower($webRoot))
                : str_starts_with($keyPath, $webRoot);
            if ($inside) {
                $exposedKeys[substr($keyPath, strlen($webRoot))] = true;
                break;
            }
        }
    }
    $exposedKeys = array_keys($exposedKeys);

    if ($exposedKeys !== []) {
        $listed = array_slice($exposedKeys, 0, 3);
        $more = count($exposedKeys) - count($listed);
        $status['checks']['key_location'] = [
            'status' => 'critical',
            'component' => 'security',
            'message' => 'Encryption key file inside the web root: ' . implode(', ', $listed)
                . ($more > 0 ? " and {$more} more" : '')
        ];
    } elseif ($keyFound) {
        $status['checks']['key_location'] = [
            'status' => 'ok',
            'component' => 'security',
            'message' => 'Encryption key file is outside the web root'
        ];
    }
    // No key file at all is reported by config_decrypt.
} catch (Throwable $e) {
    $status['checks']['key_location'] = [
        'status' => 'warning',
        'component' => 'security',
        'message' => 'Encryption key location check failed'
    ];
}

// Calculate load time
$endTime = microtime(true);
$status['load_time_ms'] = round(($endTime - $startTime) * 1000, 2);

// Check 11: Performance (Load Time)
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
} else {
    $status['checks']['performance'] = [
        'status' => 'critical',
        'component' => 'application',
        'message' => 'Response time: ' . $status['load_time_ms'] . 'ms (critical)'
    ];
}

// Overall status is decided here only. A failing breaking check means the
// application cannot serve requests (down), even if it is the only one. Any
// other check that is not ok means it runs with a problem (degraded), no matter
// how many there are.
$breakingChecks = ['config', 'config_decrypt', 'php_extensions', 'session', 'database_connection', 'database_query'];
$status['overall_status'] = 'operational';
foreach ($status['checks'] as $name => $check) {
    if ($check['status'] === 'ok') {
        continue;
    }
    if ($check['status'] === 'critical' && in_array($name, $breakingChecks, true)) {
        $status['overall_status'] = 'down';
        break;
    }
    $status['overall_status'] = 'degraded';
}

echo json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
