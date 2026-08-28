<?php
declare(strict_types=1);

/**
 * Fail-closed LTI 1.1 OAuth validation.
 * Expects $db, $dbPrefix, $config, $logger, and $clientIp in scope.
 */

if (!isset($db, $dbPrefix, $config, $logger, $clientIp)) {
    http_response_code(404);
    exit;
}

$validationEnabled = filter_var($config->get('lti.validate_signatures', true), FILTER_VALIDATE_BOOLEAN);
$testEnvironment = strtolower((string)getenv('SLOCLOUD_ENV')) === 'test';

// Failed launches are throttled, but tuned around one fact: every launch from
// an LMS arrives from the same address under the same consumer key, so a
// lockout hits the whole institution rather than one guesser. Three things keep
// that safe:
//
//   1. Only credential failures count (throttled_failure_reasons.php), so a
//      misconfiguration cannot arm the lockout.
//   2. The allowance is generous and the lock is flat, not escalating - a
//      wrong guess costs an attacker real time, while a bad day for the LMS
//      costs users five minutes rather than an hour.
//   3. Repeats of the *same* attempt do not count, so a client retrying one
//      request cannot drive itself into a lockout.
//
// The secret itself is what actually stops guessing: it is never transmitted,
// so an attacker must produce a valid HMAC blind. The throttle only bounds the
// noise and the request volume.
require_once __DIR__ . '/../system/Core/LoginLimiter.php';
require_once __DIR__ . '/throttled_failure_reasons.php';

// 100 distinct credential failures within a rolling minute. No institution
// reaches that legitimately - and if a bad secret rollout somehow did, at that
// point every launch is already failing, so the lock adds a minute rather than
// causing the outage. For an attacker it caps sustained guessing at roughly
// 100 per two minutes against a secret that is infeasible at any rate.
const LTI_THROTTLE_FREE_ATTEMPTS = 100;
const LTI_THROTTLE_WINDOW_SECONDS = 60;
const LTI_THROTTLE_LOCK_SECONDS = 60;

$ltiLimiter = new \SLOCloud\Core\LoginLimiter(
    \SLOCloud\Core\Security::dataRoot(dirname(__DIR__)) . '/var/ltilimits',
    LTI_THROTTLE_FREE_ATTEMPTS,
    LTI_THROTTLE_LOCK_SECONDS,
    // Base equal to max: a flat window, so repeated failures never compound
    // into a longer outage. Matching the counting window also caps the tail
    // after an operator fixes a bad rollout at one minute.
    LTI_THROTTLE_LOCK_SECONDS,
    LTI_THROTTLE_WINDOW_SECONDS
);

// Keyed on the source address only. The consumer key is deliberately *not* used
// as a second bucket here: unlike an account name it is shared by every user of
// the institution, so an attacker who knows it could burn the allowance from
// anywhere and lock out everyone. Keying on the address instead means an
// attacker throttles only themselves - a real launch arrives from the LMS
// server, which has its own bucket.
$ltiLimiterKeys = \SLOCloud\Core\LoginLimiter::keysFor($clientIp);

function lti_auth_fail(string $reason): never
{
    global $logger, $clientIp, $ltiLimiter, $ltiLimiterKeys;

    if ($ltiLimiter instanceof \SLOCloud\Core\LoginLimiter
        && in_array($reason, LTI_THROTTLED_FAILURE_REASONS, true)) {
        // The submitted signature identifies which attempt this is. It has to
        // change for a guess to make any progress, so an attacker cannot hold
        // it constant to avoid being counted - unlike the nonce or timestamp,
        // which they choose freely and could pin.
        $ltiLimiter->recordFailure(
            $ltiLimiterKeys,
            is_string($_POST['oauth_signature'] ?? null) ? $_POST['oauth_signature'] : null
        );
    }

    $logger->security(
        'lti_validation_failed',
        'LTI launch authentication failed',
        null,
        null,
        'warning',
        true,
        ['reason' => $reason, 'ip' => $clientIp]
    );
    http_response_code(401);
    render_message_page(
        'error',
        'LTI Launch Authentication Unsuccessful',
        'SLOCloud was unable to verify the authenticity of this LTI launch. This generally occurs when the provider key, shared secret, or launch URL configured in the LMS does not match the corresponding SLOCloud configuration. Please return to your course and launch the tool again. If the issue persists, contact your LMS administrator to review the LTI configuration.',
        'fa-exclamation-circle text-warning'
    );
    exit;
}

if ($ltiLimiter->retryAfter($ltiLimiterKeys) > 0) {
    // Not itself a throttled reason: counting it would let a retrying client
    // extend its own lock on every attempt.
    lti_auth_fail('launch_rate_limited');
}

if (!$validationEnabled && !$testEnvironment) {
    lti_auth_fail('validation_disabled_outside_tests');
}

if (!$validationEnabled) {
    // Automated tests may explicitly exercise downstream launch parsing without
    // manufacturing an OAuth signature. No deployed environment may use this.
    $ltiAuthStatusFlag = '200';
    return;
}

$configuredKey = (string)$config->get('lti.consumer_key', '');
$consumerSecret = (string)$config->get('lti.consumer_secret', '');
$canonicalUrl = (string)$config->get('lti.launch_url', '');
$submittedKey = is_string($_POST['oauth_consumer_key'] ?? null) ? $_POST['oauth_consumer_key'] : '';
$oauthSignature = is_string($_POST['oauth_signature'] ?? null) ? $_POST['oauth_signature'] : '';
$oauthMethod = is_string($_POST['oauth_signature_method'] ?? null) ? $_POST['oauth_signature_method'] : '';
$oauthTimestamp = is_string($_POST['oauth_timestamp'] ?? null) ? $_POST['oauth_timestamp'] : '';
$oauthNonce = is_string($_POST['oauth_nonce'] ?? null) ? $_POST['oauth_nonce'] : '';
$supportedMethods = [
    'HMAC-SHA256' => 'sha256',
    'HMAC-SHA1' => 'sha1',
];
$configuredMethods = (string)$config->get('lti.allowed_signature_methods', 'HMAC-SHA256||HMAC-SHA1');
$allowedMethods = array_values(array_intersect(
    array_keys($supportedMethods),
    array_filter(array_map('trim', explode('||', trim($configuredMethods, '|'))))
));

if ($configuredKey === '' || $consumerSecret === '') {
    lti_auth_fail('integration_not_configured');
}
if ($submittedKey === '' || !hash_equals($configuredKey, $submittedKey)) {
    lti_auth_fail('invalid_consumer');
}
if ($allowedMethods === []) {
    lti_auth_fail('signature_method_allowlist_not_configured');
}
if (!isset($supportedMethods[$oauthMethod]) || !in_array($oauthMethod, $allowedMethods, true)) {
    lti_auth_fail('unsupported_signature_method');
}
if ($oauthSignature === '' || $oauthNonce === '' || !preg_match('/^\d{1,12}$/', $oauthTimestamp)) {
    lti_auth_fail('missing_oauth_parameter');
}
if (strlen($oauthNonce) > 255 || abs(time() - (int)$oauthTimestamp) > 300) {
    lti_auth_fail('expired_or_invalid_nonce');
}
if (filter_var($canonicalUrl, FILTER_VALIDATE_URL) === false
    || !in_array(strtolower((string)parse_url($canonicalUrl, PHP_URL_SCHEME)), ['https', 'http'], true)) {
    lti_auth_fail('canonical_launch_url_not_configured');
}

$parameters = [];
if (!empty($_SERVER['QUERY_STRING'])) {
    parse_str((string)$_SERVER['QUERY_STRING'], $parameters);
    unset($parameters['cls_param1'], $parameters['cls_param2'], $parameters['cls_original_url']);
}
foreach ($_POST as $key => $value) {
    if ($key === 'oauth_signature') {
        continue;
    }
    if (!is_scalar($value)) {
        lti_auth_fail('invalid_parameter_shape');
    }
    $parameters[(string)$key] = (string)$value;
}
ksort($parameters, SORT_STRING);
$encoded = [];
foreach ($parameters as $key => $value) {
    $encoded[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
}
$parameterString = implode('&', $encoded);
$baseString = 'POST&' . rawurlencode($canonicalUrl) . '&' . rawurlencode($parameterString);
$signingKey = rawurlencode($consumerSecret) . '&';
$expectedSignature = base64_encode(hash_hmac($supportedMethods[$oauthMethod], $baseString, $signingKey, true));

if (!hash_equals($expectedSignature, $oauthSignature)) {
    lti_auth_fail('signature_mismatch');
}

// The schema's unique consumer+nonce+timestamp constraint makes replay
// consumption atomic: concurrent copies cannot both pass this INSERT.
try {
    $isMssql = in_array($db->getDriver(), ['mssql', 'sqlsrv'], true);
    $db->query(
        "INSERT INTO {$dbPrefix}lti_nonces "
        . '(consumer_key, nonce_value, timestamp, client_ip, created_at) '
        . 'VALUES (?, ?, ?, ?, ' . ($isMssql ? 'GETDATE()' : 'NOW()') . ')',
        [$configuredKey, $oauthNonce, (int)$oauthTimestamp, $clientIp],
        'ssis'
    );
} catch (\Throwable) {
    lti_auth_fail('nonce_replay');
}

// A launch that validates end to end clears the throttle, so an institution
// with healthy traffic never accumulates counted failures.
$ltiLimiter->clear($ltiLimiterKeys);

$ltiAuthStatusFlag = '101';
$logger->info('LTI OAuth validated', ['method' => $oauthMethod, 'result' => 'configured URL passed', 'flag' => $ltiAuthStatusFlag]);
