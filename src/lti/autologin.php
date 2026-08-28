<?php
declare(strict_types=1);

/**
 * LTI Auto-Login
 *
 * Redeems a short-lived auto-login token set by assessment.php when the LTI
 * user's email matches a dashboard user. Logs the user in and redirects to
 * the administration dashboard.
 *
 * Token is stored in session (lti_autologin_user_id + lti_autologin_expires)
 * and is valid for 5 minutes. It is consumed (cleared) on first use.
 *
 * @package SLOCloud\LTI
 */

require_once __DIR__ . '/../system/includes/init.php';

// Validate token from session
$userId = isset($_SESSION['lti_autologin_user_id']) ? (int)$_SESSION['lti_autologin_user_id'] : 0;
$expires = isset($_SESSION['lti_autologin_expires']) ? (int)$_SESSION['lti_autologin_expires'] : 0;

// Consume the token immediately regardless of outcome
unset($_SESSION['lti_autologin_user_id'], $_SESSION['lti_autologin_expires']);

if ($userId <= 0 || $expires < time()) {
    // Token missing, expired, or already used
    http_response_code(403);
    require_once __DIR__ . '/../system/includes/message_page.php';
    render_message_page(
        'error',
        'Link Expired',
        'This dashboard link has expired or already been used. Please return to the LTI page to get a new link.'
    );
    exit;
}

// Look up the user and verify they are still active
$userResult = $db->query(
    "SELECT u.users_pk, u.full_name, u.email FROM {$dbPrefix}users u "
    . "JOIN {$dbPrefix}user_roles ur ON ur.user_fk = u.users_pk "
    . "JOIN {$dbPrefix}roles r ON r.roles_pk = ur.role_fk "
    . "WHERE u.users_pk = ? AND u.is_active = 1 AND r.role_name = 'admin' "
    . "AND ur.context_type IS NULL AND ur.context_id IS NULL",
    [$userId],
    'i'
);
$user = fetch_row($userResult);

if (!$user) {
    http_response_code(403);
    require_once __DIR__ . '/../system/includes/message_page.php';
    render_message_page(
        'error',
        'Access Denied',
        'Your user account could not be found or has been deactivated.'
    );
    exit;
}

// Log the user in — keep session data (false) so LTI session in the original tab stays intact
session_regenerate_id(true);
$_SESSION['user_id']       = (int)$user['users_pk'];
$_SESSION['user_email']    = $user['email'];
$_SESSION['user_name']     = $user['full_name'];
$_SESSION['logged_in_at']  = time();
$_SESSION['last_activity'] = time();
$_SESSION['authenticated_at'] = time();

$logger->info('LTI auto-login used', [
    'user_id' => $user['users_pk'],
    'email'   => $user['email'],
    'ip'      => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
]);

header('Location: ' . BASE_URL . 'administration/');
exit;
