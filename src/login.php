<?php
/**
 * Login Page
 * 
 * User authentication interface for SLOCloud.
 * Handles both GET (display form) and POST (process login).
 */

declare(strict_types=1);

require_once __DIR__ . '/system/includes/init.php';

use SLOCloud\Core\Database;
use SLOCloud\Core\Logger;
use SLOCloud\Core\LoginLimiter;

// If already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'administration/');
    exit;
}

$error = '';
$message = '';

// Get flash messages
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// SAML SSO availability
$samlEnabled  = filter_var($config->get('saml.enabled') ?? false, FILTER_VALIDATE_BOOLEAN);
$samlIdpName  = htmlspecialchars((string) ($config->get('saml.idp_name') ?? 'Single Sign-On'));
$samlSsoUrl   = BASE_URL . 'saml/sso.php';

// ============================================================================
// BRUTE FORCE RATE LIMITING
// ============================================================================
// Counters are keyed on both the client IP and the targeted account, held in
// the protected data root (never src/sessions, which is web-reachable on a
// misconfigured server), and updated atomically. See Core\LoginLimiter.

require_once __DIR__ . '/system/Core/LoginLimiter.php';

$loginLimiter = new LoginLimiter($applicationDataRoot . '/var/loginlimits');

/**
 * Format a wait time for a user-facing message.
 */
function formatLockoutWait(int $seconds): string
{
    $minutes = (int)ceil($seconds / 60);
    if ($minutes <= 1) {
        return 'a minute';
    }
    return $minutes . ' minutes';
}

// ============================================================================
// HANDLE LOGIN POST
// ============================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    } else {
    // Use REMOTE_ADDR for rate limiting - do NOT trust X-Forwarded-For as it can be
    // spoofed by clients to bypass the lockout by cycling through fake IPs.
    $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // Both the address and the account being targeted are rate limited, so the
    // buckets are resolved once here and reused for the failure and success
    // paths below.
    $limiterKeys = LoginLimiter::keysFor($clientIp, $email);
    $retryAfter = $loginLimiter->retryAfter($limiterKeys);

    // Rate limiting check
    if ($retryAfter > 0) {
        $error = 'Too many failed login attempts. Please wait ' . formatLockoutWait($retryAfter) . ' and try again.';
        http_response_code(429);
        header('Retry-After: ' . $retryAfter);
        Logger::getInstance()->warning('Login attempt refused by rate limiter', [
            'email' => $email,
            'ip' => $clientIp,
            'retry_after' => $retryAfter
        ]);
    } else {
    if (empty($email) || empty($password)) {
        $error = 'Email and password are required.';
    } else {
        // CHECK EMERGENCY ADMIN FIRST (Break Glass Account)
        $emergencyAdminEnabled = EMERGENCY_ADMIN_ENABLED ?? false;
        $emergencyAdminUsername = EMERGENCY_ADMIN_USERNAME ?? null;
        $emergencyAdminPassword = EMERGENCY_ADMIN_PASSWORD ?? null;
        
        if ($emergencyAdminEnabled && $emergencyAdminUsername && $emergencyAdminPassword) {
            // Emergency credentials must be stored as a password hash. Plaintext
            // configuration is rejected even if an older install still contains it.
            $emergencyPasswordValid = password_get_info($emergencyAdminPassword)['algo'] !== null
                && password_verify($password, $emergencyAdminPassword);
            if ($email === $emergencyAdminUsername && $emergencyPasswordValid) {
                // Emergency admin login successful
                $loginLimiter->clear($limiterKeys);
                session_regenerate_id(true);
                
                $_SESSION['user_id'] = 0; // Special ID for emergency admin
                $_SESSION['user_email'] = $emergencyAdminUsername;
                $_SESSION['user_name'] = 'Emergency Admin';
                $_SESSION['logged_in_at'] = time();
                $_SESSION['authenticated_at'] = time();
                $_SESSION['last_activity'] = time();
                $_SESSION['is_emergency_admin'] = true;
                
                Logger::getInstance()->warning('Emergency admin login used', [
                    'username' => $emergencyAdminUsername,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                ]);
                
                header('Location: ' . BASE_URL . 'administration/');
                exit;
            }
        }
        
        // Normal database authentication
        try {
            // Get user by email (using $dbPrefix from init.php)
            $result = $db->query(
                "SELECT users_pk, full_name, email, password_hash, is_active
                 FROM {$dbPrefix}users
                 WHERE email = ?",
                [$email],
                's'
            );
            
            if ($user = fetch_row($result)) {
                // Check if account is active
                if (!$user['is_active']) {
                    $error = 'Your account has been deactivated. Please contact your administrator.';
                    Logger::getInstance()->warning('Login attempt on inactive account', [
                        'email' => $email,
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]);
                } 
                // Verify password
                elseif (password_verify($password, $user['password_hash'])) {
                    // Successful login - the only event that resets the counters
                    $loginLimiter->clear($limiterKeys);
                    
                    // Regenerate session ID to prevent session fixation
                    session_regenerate_id(true);
                    
                    // Set session variables
                    $_SESSION['user_id'] = $user['users_pk'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_name'] = $user['full_name'];
                    $_SESSION['logged_in_at'] = time();
                    $_SESSION['authenticated_at'] = time();
                    $_SESSION['last_activity'] = time();
                    
                    Logger::getInstance()->info('User logged in', [
                        'user_id' => $user['users_pk'],
                        'email' => $email,
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                    ]);
                    
                    // Redirect to dashboard
                    header('Location: ' . BASE_URL . 'administration/');
                    exit;
                } else {
                    // Invalid password
                    $error = 'Invalid email or password.';
                    $lockSeconds = $loginLimiter->recordFailure($limiterKeys);
                    Logger::getInstance()->warning('Failed login attempt', [
                        'email' => $email,
                        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'reason' => 'invalid_password',
                        'lockout_seconds' => $lockSeconds
                    ]);
                }
            } else {
                // User not found
                $error = 'Invalid email or password.';
                $lockSeconds = $loginLimiter->recordFailure($limiterKeys);
                Logger::getInstance()->warning('Failed login attempt', [
                    'email' => $email,
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'reason' => 'user_not_found',
                    'lockout_seconds' => $lockSeconds
                ]);
            }
            
        } catch (Exception $e) {
            if (defined('DEBUG_MODE') && DEBUG_MODE === true) {
                $error = 'Login error: ' . $e->getMessage() . '<br><br>Stack trace: <pre>' . $e->getTraceAsString() . '</pre>';
            } else {
                $error = 'An error occurred during login. Please try again.';
            }
            Logger::getInstance()->warning('Login error: ' . $e->getMessage(), [
                'email' => $email,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    } // end rate-limit else
    } // end CSRF else
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?= htmlspecialchars(SITE_NAME) ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="<?= BASE_URL ?>assets/vendor/bootstrap/5.3.8/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>system/accessibility.css">
    <script src="<?= BASE_URL ?>system/accessibility.js" defer></script>
    
    <style>
        body {
            background: linear-gradient(135deg, #0D47A1 0%, #1976D2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-container {
            width: 100%;
            max-width: 420px;
            padding: 15px;
        }
        .login-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        .login-header {
            background: linear-gradient(135deg, #0D47A1 0%, #1565C0 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .login-header i {
            font-size: 3rem;
            margin-bottom: 0.5rem;
        }
        .login-body {
            padding: 2rem;
        }
        .form-floating label {
            color: #666;
        }
        .btn-login {
            background: linear-gradient(135deg, #1976D2 0%, #1565C0 100%);
            border: none;
            padding: 0.75rem;
            font-weight: 500;
            transition: all 0.3s;
        }
        .btn-login:hover {
            background: linear-gradient(135deg, #0D47A1 0%, #1976D2 100%);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(13, 71, 161, 0.4);
        }
        .sso-divider {
            display: flex;
            align-items: center;
            color: #6c757d;
            font-size: 0.8rem;
        }
        .sso-divider::before,
        .sso-divider::after {
            content: '';
            flex: 1;
            border-top: 1px solid #dee2e6;
        }
        .sso-divider span { padding: 0 0.75rem; }
        .login-footer {
            padding: 1.5rem 2rem;
            background: #f8f9fa;
            text-align: center;
            color: #666;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
    <a href="#main" class="skip-link">Skip to main content</a>
    <main id="main" class="login-container" tabindex="-1">
        <div class="login-card">
            <div class="login-header">
                <i class="bi bi-graph-up"></i>
                <h1 class="h2 mb-0"><?= htmlspecialchars(SITE_NAME) ?></h1>
                <p class="mb-0 mt-1" style="opacity: 0.9;">Student Learning Outcomes Assessment</p>
            </div>
            
            <div class="login-body">
                <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert" data-a11y-focus="true">
                    <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>
                    <?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss error message"></button>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($message)): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="bi bi-info-circle-fill me-2" aria-hidden="true"></i>
                    <?= htmlspecialchars($message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss information message"></button>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-floating mb-3">
                        <input type="email" class="form-control" id="email" name="email" 
                               placeholder="name@example.com" required autofocus
                               autocomplete="username"
                               aria-required="true"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        <label for="email"><i class="bi bi-envelope me-2"></i>Email Address</label>
                    </div>
                    
                    <div class="form-floating mb-3">
                        <input type="password" class="form-control" id="password" name="password" 
                               placeholder="Password" required autocomplete="current-password" aria-required="true">
                        <label for="password"><i class="bi bi-lock me-2"></i>Password</label>
                    </div>
                    <div class="mb-3">
                        <button type="button" class="btn btn-outline-secondary btn-sm" data-password-toggle="password" aria-controls="password" aria-pressed="false">Show password</button>
                    </div>
                    
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary btn-lg btn-login">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Sign In
                        </button>
                    </div>
                </form>

                <?php if ($samlEnabled): ?>
                <div class="sso-divider my-3">
                    <span>or</span>
                </div>
                <div class="d-grid">
                    <a href="<?= htmlspecialchars($samlSsoUrl) ?>" class="btn btn-outline-secondary btn-lg">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Login with <?= $samlIdpName ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="login-footer">
                <i class="bi bi-shield-check me-1"></i>
                Secure encrypted authentication
            </div>
        </div>
        
        <div class="text-center mt-3">
            <small class="text-white">
                <i class="bi bi-c-circle"></i> <?= date('Y') ?> <?= htmlspecialchars(SITE_NAME) ?> - Student Learning Outcomes Assessment
            </small>
        </div>
    </main>
    
    <!-- Bootstrap JS -->
    <script src="<?= BASE_URL ?>assets/vendor/bootstrap/5.3.8/js/bootstrap.bundle.min.js"></script>
</body>
</html>
