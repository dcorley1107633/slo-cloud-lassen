<?php
declare(strict_types=1);

/**
 * LTI Session Keepalive
 *
 * Called periodically by assessment.php to confirm the per-launch token
 * is still alive in the session. Returns JSON {alive: true/false}.
 * A false response means the session or launch context has expired and
 * the client should redirect the user to a session-expired message.
 */

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require_once __DIR__ . '/../system/includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['alive' => false]);
    exit;
}

$submittedCsrf = (string)($_POST['csrf_token'] ?? '');
$sessionCsrf = (string)($_SESSION['csrf_token'] ?? '');
if ($submittedCsrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $submittedCsrf)) {
    http_response_code(403);
    echo json_encode(['alive' => false]);
    exit;
}

$rawToken = $_GET['lt'] ?? $_POST['lt'] ?? '';
$ltiToken = preg_match('/^[0-9a-f]{32}$/', $rawToken) ? $rawToken : null;

if ($ltiToken === null || empty($_SESSION['lti_launches'][$ltiToken]['lti_authenticated'])) {
    echo json_encode(['alive' => false]);
    exit;
}

$lastActivity = (int)($_SESSION['lti_launches'][$ltiToken]['lti_last_ping']
    ?? $_SESSION['lti_launches'][$ltiToken]['lti_launched_at'] ?? 0);
if ($lastActivity <= 0 || (time() - $lastActivity) > 28800) {
    unset($_SESSION['lti_launches'][$ltiToken]);
    echo json_encode(['alive' => false]);
    exit;
}

// Extend only in response to recent activity or the explicit Continue action.
$_SESSION['lti_launches'][$ltiToken]['lti_last_ping'] = time();

echo json_encode(['alive' => true, 'expires_in' => 28800]);
