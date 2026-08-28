<?php
/**
 * Logout Handler
 * 
 * Destroys user session and redirects to login page.
 */

declare(strict_types=1);

require_once __DIR__ . '/system/includes/init.php';

use SLOCloud\Core\Logger;
use SLOCloud\Core\Security;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Method Not Allowed';
    exit;
}

$submittedToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
if ($submittedToken === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $submittedToken)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden';
    exit;
}

// Log the logout if user was logged in
if (isset($_SESSION['user_id'])) {
    Logger::getInstance()->info('User logged out', [
        'user_id' => $_SESSION['user_id'],
        'email' => $_SESSION['user_email'] ?? 'unknown',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'session_duration' => isset($_SESSION['logged_in_at']) ? (time() - $_SESSION['logged_in_at']) : 0
    ]);
}

// Destroy session
Security::destroySession();

// Start new session for flash message
Security::configureSession(Security::dataRoot(__DIR__) . '/var/sessions');
$_SESSION['flash_message'] = 'You have been successfully logged out.';

// Redirect to login page
header('Location: ' . BASE_URL . 'login.php');
exit;
