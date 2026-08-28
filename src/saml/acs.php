<?php
/**
 * SAML 2.0 Assertion Consumer Service (ACS)
 *
 * Receives the IdP's HTTP-POST response, validates the signed assertion,
 * and logs the user in if their email exists in the SLOCloud database.
 *
 * This endpoint must be registered with your IdP as the ACS URL
 * (should match saml.sp_acs_url in config.yaml).
 *
 * TODO: Auto-provisioning
 *   When a valid SAML assertion arrives for an email not in the database,
 *   samlUserNotFound() is called and login is denied. Add an optional
 *   saml.auto_provision config flag that creates the user record on first
 *   SSO login (role: user, is_active: 1) using assertion attributes.
 *
 * TODO: Encrypted assertions
 *   Saml::parseResponse() only handles signed assertions. Some IdPs
 *   (e.g. Azure AD in strict mode) send EncryptedAssertion elements.
 *   Add decryption support using the SP private key configured via
 *   saml.sp_private_key in config.yaml.
 */

declare(strict_types=1);

require_once __DIR__ . '/../system/includes/init.php';
require_once __DIR__ . '/../system/Core/Saml.php';

use SLOCloud\Core\Saml;
use SLOCloud\Core\Logger;

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

// SAML must be enabled
$samlEnabled = filter_var($config->get('saml.enabled') ?? false, FILTER_VALIDATE_BOOLEAN);
if (!$samlEnabled) {
    http_response_code(403);
    exit;
}

// Already logged in - redirect
if (isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . 'administration/');
    exit;
}

$samlConfig = [
    'idp_entity_id' => (string) ($config->get('saml.idp_entity_id') ?? ''),
    'idp_cert'      => (string) ($config->get('saml.idp_cert')      ?? ''),
    'sp_entity_id'  => (string) ($config->get('saml.sp_entity_id')  ?? ''),
    'sp_acs_url'    => (string) ($config->get('saml.sp_acs_url')    ?? ''),
    'attr_email'    => (string) ($config->get('saml.attr_email')    ?? 'email'),
    'attr_name'     => (string) ($config->get('saml.attr_name')     ?? 'displayName'),
];

$samlResponseRaw = $_POST['SAMLResponse'] ?? '';
if ($samlResponseRaw === '') {
    samlError('No SAMLResponse received from Identity Provider.', 400);
}

// Parse and validate the SAML assertion
$userEmail = '';
$userName  = '';
try {
    $attributes = Saml::parseResponse($samlResponseRaw, $samlConfig);
    $userEmail  = $attributes['email'];
    $userName   = $attributes['name'];
} catch (Exception $e) {
    Logger::getInstance()->warning('SAML response validation failed: ' . $e->getMessage(), [
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);

    // Do not expose internal validation details to the browser
    samlError('SSO login failed: the Identity Provider response could not be validated. Please contact your administrator.');
}

// Look up the user in the database
try {
    $result = $db->query(
        "SELECT users_pk, full_name, email, is_active
         FROM {$dbPrefix}users
         WHERE email = ?",
        [$userEmail],
        's'
    );
    $user = fetch_row($result);
} catch (Exception $e) {
    Logger::getInstance()->error('SAML DB lookup error: ' . $e->getMessage(), [
        'email' => $userEmail,
        'ip'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);
    samlError('A database error occurred during SSO login. Please contact your administrator.');
}

// User not in the database
if (!$user) {
    Logger::getInstance()->warning('SAML login: user not found in database', [
        'email' => $userEmail,
        'ip'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);
    samlUserNotFound($userEmail);
}

// Account deactivated
if (!$user['is_active']) {
    Logger::getInstance()->warning('SAML login attempt on inactive account', [
        'email'   => $userEmail,
        'user_id' => $user['users_pk'],
        'ip'      => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);
    samlError('Your account has been deactivated. Please contact your administrator.');
}

// Success — establish session
session_regenerate_id(true);
$_SESSION['authenticated_at'] = time();

$_SESSION['user_id']      = $user['users_pk'];
$_SESSION['user_email']   = $user['email'];
$_SESSION['user_name']    = $user['full_name'];
$_SESSION['logged_in_at'] = time();
$_SESSION['last_activity']= time();
$_SESSION['saml_login']   = true;

Logger::getInstance()->info('SAML SSO login successful', [
    'user_id' => $user['users_pk'],
    'email'   => $userEmail,
    'ip'      => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
]);

// Honour RelayState if the IdP echoed one back (must be a relative path for security)
$relayState = $_POST['RelayState'] ?? '';
if ($relayState !== '' && str_starts_with($relayState, '/') && !str_starts_with($relayState, '//')) {
    header('Location: ' . $relayState);
} else {
    header('Location: ' . BASE_URL . 'administration/');
}
exit;

// ---------------------------------------------------------------------------
// Helper: render an error page and halt
// ---------------------------------------------------------------------------
function samlError(string $message, int $httpCode = 200): never
{
    http_response_code($httpCode);
    $loginUrl  = BASE_URL . 'login.php';
    $siteName  = defined('SITE_NAME') ? htmlspecialchars(SITE_NAME) : 'SLOCloud';
    $msgEsc    = htmlspecialchars($message);
    echo samlPageHtml(
        $siteName,
        'SSO Error',
        'bi-shield-exclamation text-danger',
        "<p class=\"text-muted mb-4\">{$msgEsc}</p>",
        $loginUrl
    );
    exit;
}

// ---------------------------------------------------------------------------
// Helper: render "user not found" page and halt
// ---------------------------------------------------------------------------
function samlUserNotFound(string $email): never
{
    $loginUrl = BASE_URL . 'login.php';
    $siteName = defined('SITE_NAME') ? htmlspecialchars(SITE_NAME) : 'SLOCloud';
    $emailEsc = htmlspecialchars($email);
    echo samlPageHtml(
        $siteName,
        'Account Not Found',
        'bi-person-x text-warning',
        <<<HTML
        <p class="text-muted mb-2">
            No SLOCloud account was found for:
        </p>
        <p class="fw-semibold mb-4">{$emailEsc}</p>
        <p class="text-muted small mb-4">
            Your Identity Provider authenticated you successfully, but your email address
            does not match any account in SLOCloud. Please contact your administrator to
            have an account created for you.
        </p>
        HTML,
        $loginUrl
    );
    exit;
}

// ---------------------------------------------------------------------------
// Helper: build a simple standalone page (no sidebar, consistent with login.php)
// ---------------------------------------------------------------------------
function samlPageHtml(
    string $siteName,
    string $heading,
    string $iconClass,
    string $bodyHtml,
    string $loginUrl
): string {
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$heading} - {$siteName}</title>
    <link href="<?= BASE_URL ?>assets/vendor/bootstrap/5.3.8/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #0D47A1 0%, #1976D2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card-box {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 460px;
            width: 100%;
            padding: 0;
            overflow: hidden;
        }
        .card-header-bar {
            background: linear-gradient(135deg, #0D47A1 0%, #1565C0 100%);
            color: #fff;
            padding: 2rem;
            text-align: center;
        }
        .card-body-area { padding: 2rem; text-align: center; }
        .card-footer-bar {
            padding: 1.25rem 2rem;
            background: #f8f9fa;
            text-align: center;
            color: #666;
            font-size: 0.875rem;
        }
    </style>
</head>
<body>
<div class="px-3">
    <div class="card-box mx-auto">
        <div class="card-header-bar">
            <i class="bi bi-graph-up" style="font-size:3rem;"></i>
            <h2 class="mb-0 mt-1">{$siteName}</h2>
        </div>
        <div class="card-body-area">
            <i class="bi {$iconClass}" style="font-size:3rem;"></i>
            <h4 class="mt-3 mb-3">{$heading}</h4>
            {$bodyHtml}
            <a href="{$loginUrl}" class="btn btn-primary px-4">
                <i class="bi bi-arrow-left me-2"></i>Back to Login
            </a>
        </div>
        <div class="card-footer-bar">
            <i class="bi bi-shield-check me-1"></i>Secure encrypted authentication
        </div>
    </div>
</div>
<script src="<?= BASE_URL ?>assets/vendor/bootstrap/5.3.8/js/bootstrap.bundle.min.js"></script>
</body>
</html>
HTML;
}
