<?php
/**
 * SAML 2.0 SSO Initiator
 *
 * Builds an AuthnRequest (signed when saml.sign_requests is on), records its
 * ID so one answer to it is accepted, and redirects the browser to the
 * identity provider using the HTTP-Redirect binding.
 *
 * Entry point: linked from login.php when saml.enabled = true in config.yaml.
 * Point the identity provider's "sign-on URL" at this page so an app tile in
 * the user's portal also starts here (sign-in is SP-initiated only).
 */

declare(strict_types=1);

require_once __DIR__ . '/../system/includes/init.php';
require_once __DIR__ . '/../system/Core/Saml.php';

use SLOCloud\Core\Saml;
use SLOCloud\Core\Logger;

// Already logged in - go to admin
if (isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'administration/');
    exit;
}

$samlConfig = Saml::configFrom($config);

// SAML must be enabled
if (!$samlConfig['enabled']) {
    $_SESSION['flash_error'] = 'SAML SSO is not enabled.';
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$problems = Saml::problems($samlConfig);
if ($problems !== []) {
    Logger::getInstance()->error('SAML SSO initiated but configuration is not usable: ' . implode(' ', $problems));
    $_SESSION['flash_error'] = 'SAML SSO is not fully configured. Please contact your administrator.';
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

try {
    $request = Saml::beginLogin($samlConfig);
    Saml::rememberRequest('login', $request['request_id'], Saml::pendingDirectory());

    Logger::getInstance()->info('SAML AuthnRequest initiated', [
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'idp_sso_url' => $samlConfig['idp_sso_url'],
    ]);

    header('Cache-Control: no-store');
    header('Location: ' . $request['url']);
    exit;
} catch (Throwable $e) {
    Logger::getInstance()->error('SAML AuthnRequest build failed: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Could not initiate SSO. Please try again or use password login.';
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}
