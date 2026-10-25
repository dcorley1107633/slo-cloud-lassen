<?php
/**
 * SAML 2.0 Single Logout (SLO) endpoint
 *
 * Receives, by HTTP-Redirect binding:
 *   - a LogoutResponse: the IdP finished the sign-out that logout.php started
 *   - a LogoutRequest: the user signed out of another application, and the IdP
 *     asks this one to end their session too
 *
 * Both must be signed by the IdP certificate. Active only when both
 * saml.idp_slo_url and saml.sp_slo_url are set.
 */

declare(strict_types=1);

require_once __DIR__ . '/../system/includes/init.php';
require_once __DIR__ . '/../system/Core/Saml.php';

use SLOCloud\Core\Saml;
use SLOCloud\Core\Logger;
use SLOCloud\Core\Security;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$samlConfig = Saml::configFrom($config);
if (!$samlConfig['enabled'] || !Saml::sloEnabled($samlConfig) || Saml::problems($samlConfig) !== []) {
    http_response_code(404);
    exit;
}

$loginUrl = BASE_URL . 'login.php';
$isRequest = isset($_GET['SAMLRequest']) && is_string($_GET['SAMLRequest']);
$isResponse = isset($_GET['SAMLResponse']) && is_string($_GET['SAMLResponse']);
if ($isRequest === $isResponse) {
    header('Location: ' . $loginUrl);
    exit;
}

// A LogoutResponse must answer a logout this application started, once.
$requestId = null;
if ($isResponse) {
    $inResponseTo = Saml::inResponseTo($_GET['SAMLResponse'], true);
    if (!Saml::takeRequest('logout', $inResponseTo, Saml::pendingDirectory())) {
        header('Location: ' . $loginUrl);
        exit;
    }
    $requestId = $inResponseTo;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$userEmail = (string)($_SESSION['user_email'] ?? '');
$result = Saml::handleLogout($samlConfig, $requestId, static function () {
    Security::destroySession();
});

if ($result['error'] !== null) {
    Logger::getInstance()->warning('SAML logout message rejected: ' . substr($result['error'], 0, 500), [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);
    header('Location: ' . $loginUrl);
    exit;
}

if ($result['kind'] === 'request') {
    Logger::getInstance()->security(
        Logger::EVENT_LOGOUT,
        'Signed out by the identity provider',
        $userId > 0 ? $userId : null,
        $userEmail,
        'info',
        false,
        ['reason' => 'saml_idp_logout', 'method' => 'saml']
    );
    // The response to the IdP's request.
    header('Location: ' . (string)$result['redirect']);
    exit;
}

header('Location: ' . $loginUrl);
exit;
