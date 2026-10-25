<?php
/**
 * Logout Handler
 * 
 * Destroys user session and redirects to login page.
 */

declare(strict_types=1);

require_once __DIR__ . '/system/includes/init.php';
require_once __DIR__ . '/system/Core/Saml.php';

use SLOCloud\Core\Logger;
use SLOCloud\Core\Saml;
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

// Record the logout on the Activity Log if a user was logged in
if (isset($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
    Logger::getInstance()->security(
        Logger::EVENT_LOGOUT,
        'Signed out',
        $userId > 0 ? $userId : null,
        (string)($_SESSION['user_email'] ?? ''),
        'info',
        false,
        ['reason' => 'user_logout']
    );
}

// A single sign-on session also ends at the identity provider when it supports
// single logout; otherwise the user could sign straight back in without a password.
$idpLogoutUrl = null;
if (!empty($_SESSION['saml_login'])) {
    $samlConfig = Saml::configFrom($config);
    if ($samlConfig['enabled'] && Saml::sloEnabled($samlConfig) && Saml::problems($samlConfig) === []) {
        try {
            $logout = Saml::beginLogout(
                $samlConfig,
                (string)($_SESSION['saml_name_id'] ?? ''),
                (string)($_SESSION['saml_name_id_format'] ?? ''),
                (string)($_SESSION['saml_session_index'] ?? '')
            );
            Saml::rememberRequest('logout', $logout['request_id'], Saml::pendingDirectory());
            $idpLogoutUrl = $logout['url'];
        } catch (Throwable $e) {
            Logger::getInstance()->warning('SAML logout request could not be built: ' . $e->getMessage());
        }
    }
}

// Destroy session
Security::destroySession();

// Start new session for flash message
Security::configureSession(Security::dataRoot(__DIR__) . '/var/sessions');
$_SESSION['flash_message'] = 'You have been successfully logged out.';

// Sign out at the identity provider, which returns to saml/slo.php and then the login page
if ($idpLogoutUrl !== null) {
    header('Cache-Control: no-store');
    header('Location: ' . $idpLogoutUrl);
    exit;
}

// Redirect to login page
header('Location: ' . BASE_URL . 'login.php');
exit;
