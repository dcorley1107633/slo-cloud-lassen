<?php
/**
 * SAML 2.0 SSO Initiator
 *
 * Builds an unsigned AuthnRequest and redirects the browser to the IdP
 * using the HTTP-Redirect binding.
 *
 * Entry point: linked from login.php when saml.enabled = true in config.yaml.
 *
 * TODO: SP Metadata endpoint
 *   Add saml/metadata.php that returns a standard SAML 2.0 SP metadata XML
 *   document. Most IdPs require this URL during registration instead of manual
 *   ACS/entity-ID entry. Should expose: entity ID, ACS URL, NameID format,
 *   and the SP signing certificate (if request signing is added).
 *
 * TODO: SAML Single Logout (SLO)
 *   Add saml/slo.php to handle IdP-initiated and SP-initiated logout.
 *   Currently, logging out of SLOCloud does not terminate the IdP session,
 *   so users can re-authenticate silently without re-entering credentials.
 *   Requires: LogoutRequest/LogoutResponse handling, saml.idp_slo_url in config.
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

// SAML must be enabled
$samlEnabled = filter_var($config->get('saml.enabled') ?? false, FILTER_VALIDATE_BOOLEAN);
if (!$samlEnabled) {
    $_SESSION['flash_error'] = 'SAML SSO is not enabled.';
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

$samlConfig = [
    'idp_sso_url'  => (string) ($config->get('saml.idp_sso_url')  ?? ''),
    'sp_entity_id' => (string) ($config->get('saml.sp_entity_id') ?? ''),
    'sp_acs_url'   => (string) ($config->get('saml.sp_acs_url')   ?? ''),
];

if ($samlConfig['idp_sso_url'] === '' || $samlConfig['sp_entity_id'] === '' || $samlConfig['sp_acs_url'] === '') {
    Logger::getInstance()->error('SAML SSO initiated but configuration is incomplete');
    $_SESSION['flash_error'] = 'SAML SSO is not fully configured. Please contact your administrator.';
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}

try {
    $redirectUrl = Saml::buildAuthnRequestUrl($samlConfig);

    Logger::getInstance()->info('SAML AuthnRequest initiated', [
        'ip'          => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'idp_sso_url' => $samlConfig['idp_sso_url'],
    ]);

    header('Location: ' . $redirectUrl);
    exit;
} catch (Exception $e) {
    Logger::getInstance()->error('SAML AuthnRequest build failed: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Could not initiate SSO. Please try again or use password login.';
    header('Location: ' . BASE_URL . 'login.php');
    exit;
}
