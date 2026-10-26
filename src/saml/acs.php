<?php
/**
 * SAML 2.0 Assertion Consumer Service (ACS)
 *
 * Receives the IdP's HTTP-POST response, validates it (see Core\Saml), and
 * signs the user in if their email exists in the SLOCloud database and the
 * account is active. An account is created from an assertion only when
 * saml.auto_provision is on and the email is at one of
 * saml.auto_provision_domains; it is active, has no admin role, and gets a
 * random password nobody knows. An existing account is never changed or
 * reactivated.
 *
 * This endpoint must be registered with your IdP as the ACS URL
 * (should match saml.sp_acs_url in config.yaml).
 *
 * A response is accepted only if it answers a sign-in that sso.php started
 * (the request ID is recorded there and works once). A response the IdP sends
 * on its own, a replayed response and an expired one are refused.
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

$samlConfig = Saml::configFrom($config);

// SAML must be enabled
if (!$samlConfig['enabled']) {
    http_response_code(403);
    exit;
}

// Already logged in - redirect
if (isset($_SESSION['user_id'])) {
    samlContinue(BASE_URL . 'administration/');
}

$problems = Saml::problems($samlConfig);
if ($problems !== []) {
    Logger::getInstance()->error('SAML response received but configuration is not usable: ' . implode(' ', $problems));
    Logger::getInstance()->security(Logger::EVENT_LOGIN_FAILED, 'Single sign-on failed: its settings are incomplete',
        null, null, 'error', false, ['reason' => 'saml_not_configured', 'method' => 'saml']);
    samlError('SSO login is not fully configured. Please contact your administrator.');
}

$samlResponseRaw = $_POST['SAMLResponse'] ?? '';
if (!is_string($samlResponseRaw) || $samlResponseRaw === '') {
    samlError('No SAMLResponse received from Identity Provider.', 400);
}

// The response must answer a sign-in this application started, once.
$requestId = Saml::inResponseTo($samlResponseRaw, false);
if (!Saml::takeRequest('login', $requestId, Saml::pendingDirectory())) {
    Logger::getInstance()->security(Logger::EVENT_LOGIN_FAILED, 'Single sign-on failed: the response did not answer a sign-in started here',
        null, null, 'warning', false, ['reason' => 'saml_unsolicited_response', 'method' => 'saml']);
    if ($requestId === '') {
        // No InResponseTo: the identity provider started this sign-in itself,
        // for example the Test button in Microsoft Entra ID or a portal tile.
        samlError(
            'You have arrived here unsolicited. The identity provider signed you in, but SLO Cloud did not ask it to, '
            . 'and SLO Cloud only accepts a sign-in that it started. If you clicked Test in Microsoft Entra ID (or opened SLO Cloud '
            . 'from a portal tile that starts at the provider), this is expected. Test from within the app instead: '
            . 'go to the SLO Cloud login page and choose the single sign-on button.',
            200,
            [],
            'Sign-in Not Started Here'
        );
    }
    samlError(
        'This sign-in has already been used, has expired (a sign-in stays valid for 10 minutes), or was not started by this SLO Cloud. '
        . 'Go to the SLO Cloud login page and start again.',
        200,
        [],
        'Sign-in Expired'
    );
}

// Validate the signed response
$userEmail = '';
$userName  = '';
$identity  = [];
try {
    $identity  = Saml::validateResponse($samlConfig, $requestId);
    $userEmail = $identity['email'];
    $userName  = $identity['name'];
} catch (Throwable $e) {
    $reason = $e instanceof \SLOCloud\Core\SamlSignInError ? $e->reason : 'saml_invalid_response';
    // The values worth comparing (settings, attributes received, NameID) go to the log file
    // under a reference; the page shows only the explanation and that reference.
    $reference = 'SSO-' . strtoupper(bin2hex(random_bytes(4)));
    $logged = [];
    foreach ($e instanceof \SLOCloud\Core\SamlSignInError ? $e->details : [] as $label => $value) {
        $logged[] = $label . ': ' . ((string)$value !== '' ? (string)$value : '(empty)');
    }
    Logger::getInstance()->warning(
        "SAML response rejected [{$reference}] ({$reason}): " . substr($e->getMessage(), 0, 500)
            . ($logged !== [] ? ' | ' . implode(' | ', $logged) : ''),
        ['ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown']
    );
    Logger::getInstance()->security(Logger::EVENT_LOGIN_FAILED, 'Single sign-on failed: the identity provider response could not be validated',
        null, null, 'warning', false, ['reason' => $reason, 'method' => 'saml']);

    samlError(
        ($e instanceof \SLOCloud\Core\SamlSignInError
            ? $e->explanation
            : 'The response from the identity provider could not be validated.')
        . ' Give the reference below to your administrator.',
        200,
        ['Reference' => $reference, 'Time' => date('Y-m-d H:i:s T')],
        'Sign-in Could Not Be Validated'
    );
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
    Logger::getInstance()->warning('SAML DB lookup error: ' . $e->getMessage(), [
        'email' => $userEmail,
        'ip'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    ]);
    samlError('A database error occurred during SSO login. Please contact your administrator.');
}

// Not in the database: create a non-admin account if allowed
if (!$user && Saml::mayProvision($samlConfig, $userEmail)) {
    try {
        $now = $db->now();
        $db->query(
            "INSERT INTO {$dbPrefix}users (full_name, email, password_hash, is_active, created_at, updated_at)
             VALUES (?, ?, ?, 1, {$now}, {$now})",
            [
                $userName !== '' ? $userName : $userEmail,
                $userEmail,
                // Nobody knows this password, so only single sign-on can open the account.
                password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4]),
            ],
            'sss'
        );
        $result = $db->query(
            "SELECT users_pk, full_name, email, is_active FROM {$dbPrefix}users WHERE email = ?",
            [$userEmail],
            's'
        );
        $user = fetch_row($result);
        Logger::getInstance()->security(Logger::EVENT_ADMIN_ACTION, 'Account created by single sign-on',
            $user ? (int)$user['users_pk'] : null, $userEmail, 'info', false, ['reason' => 'saml_auto_provisioned', 'method' => 'saml']);
    } catch (Exception $e) {
        Logger::getInstance()->warning('SAML auto-provision error: ' . $e->getMessage(), ['email' => $userEmail]);
        samlError('Your account could not be created. Please contact your administrator.');
    }
}

// User not in the database
if (!$user) {
    Logger::getInstance()->security(Logger::EVENT_LOGIN_FAILED, 'Single sign-on failed: no account with that email',
        null, $userEmail, 'warning', false, ['reason' => 'unknown_account', 'method' => 'saml']);
    samlUserNotFound($userEmail);
}

// Account deactivated
if (!$user['is_active']) {
    Logger::getInstance()->security(Logger::EVENT_LOGIN_FAILED, 'Single sign-on refused: account deactivated',
        (int)$user['users_pk'], $userEmail, 'warning', false, ['reason' => 'account_inactive', 'method' => 'saml']);
    samlError('Your account has been deactivated. Please contact your administrator.');
}

// Success — establish session
session_regenerate_id(true);

$_SESSION['user_id']      = $user['users_pk'];
$_SESSION['user_email']   = $user['email'];
$_SESSION['user_name']    = $user['full_name'];
$_SESSION['logged_in_at'] = time();
$_SESSION['last_activity']= time();
$_SESSION['saml_login']   = true;
// Kept so logging out can sign the user out at the identity provider too.
$_SESSION['saml_name_id']        = $identity['name_id'];
$_SESSION['saml_name_id_format'] = $identity['name_id_format'];
$_SESSION['saml_session_index']  = $identity['session_index'];

Logger::getInstance()->security(Logger::EVENT_LOGIN_SUCCESS, 'Signed in with single sign-on',
    (int)$user['users_pk'], $userEmail, 'info', false, ['method' => 'saml']);

// RelayState is not used: the IdP echoes it, so it is not trusted. Return to the
// page that required signing in again (kept in the session), or the dashboard.
samlContinue(\SLOCloud\Core\Authorization::takeLoginReturn());

// ---------------------------------------------------------------------------
// Helper: continue to a page of this site.
//
// The IdP reached this endpoint with a cross-site POST, and the session cookie
// is SameSite=Strict, so a redirect from here would be treated as cross-site
// and the browser would not send the new session cookie: the user would land on
// the login page. A page that refreshes itself is a same-site navigation.
// ---------------------------------------------------------------------------
function samlContinue(string $url): never
{
    $urlEsc = htmlspecialchars($url, ENT_QUOTES);
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store');
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="refresh" content="0;url={$urlEsc}">
    <title>Signing in</title>
</head>
<body>
    <main>
        <h1>Signing in</h1>
        <p>Signing you in. If nothing happens, <a href="{$urlEsc}">continue</a>.</p>
    </main>
</body>
</html>
HTML;
    exit;
}

// ---------------------------------------------------------------------------
// Helper: render an error page and halt
// ---------------------------------------------------------------------------
function samlError(string $message, int $httpCode = 200, array $details = [], string $heading = 'SSO Error'): never
{
    http_response_code($httpCode);
    $loginUrl  = BASE_URL . 'login.php';
    $siteName  = defined('SITE_NAME') ? htmlspecialchars(SITE_NAME) : 'SLOCloud';
    $body = '<p class="text-muted mb-4">' . htmlspecialchars($message) . '</p>';
    $rows = '';
    foreach ($details as $label => $value) {
        if ((string)$value === '') {
            continue;
        }
        $rows .= '<dt class="fw-semibold">' . htmlspecialchars((string)$label) . '</dt>'
            . '<dd class="mb-2 text-break"><code>' . htmlspecialchars((string)$value) . '</code></dd>';
    }
    if ($rows !== '') {
        $body .= '<dl class="text-start small mb-4">' . $rows . '</dl>';
    }
    echo samlPageHtml($siteName, htmlspecialchars($heading), 'bi-shield-exclamation text-danger', $body, $loginUrl);
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
    $baseUrl = htmlspecialchars(BASE_URL, ENT_QUOTES);
    $loginUrl = htmlspecialchars($loginUrl, ENT_QUOTES);
    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$heading} - {$siteName}</title>
    <link href="{$baseUrl}assets/vendor/bootstrap/5.3.8/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="{$baseUrl}assets/vendor/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
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
<main class="px-3">
    <div class="card-box mx-auto">
        <div class="card-header-bar">
            <i class="bi bi-graph-up" style="font-size:3rem;" aria-hidden="true"></i>
            <h2 class="mb-0 mt-1">{$siteName}</h2>
        </div>
        <div class="card-body-area">
            <i class="bi {$iconClass}" style="font-size:3rem;" aria-hidden="true"></i>
            <h4 class="mt-3 mb-3">{$heading}</h4>
            {$bodyHtml}
            <a href="{$loginUrl}" class="btn btn-primary px-4">
                <i class="bi bi-arrow-left me-2" aria-hidden="true"></i>Back to Login
            </a>
        </div>
        <div class="card-footer-bar">
            <i class="bi bi-shield-check me-1" aria-hidden="true"></i>Secure encrypted authentication
        </div>
    </div>
</main>
<script src="{$baseUrl}assets/vendor/bootstrap/5.3.8/js/bootstrap.bundle.min.js"></script>
</body>
</html>
HTML;
}
