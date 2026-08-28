<?php
declare(strict_types=1);

require_once __DIR__ . '/system/includes/init.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['extended' => false, 'error' => 'Method not allowed']);
    exit;
}

$submittedToken = (string)($_POST['csrf_token'] ?? '');
$sessionToken = (string)($_SESSION['csrf_token'] ?? '');
if ($submittedToken === '' || $sessionToken === '' || !hash_equals($sessionToken, $submittedToken)) {
    http_response_code(403);
    echo json_encode(['extended' => false, 'error' => 'Invalid request token']);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['extended' => false, 'error' => 'Session expired']);
    exit;
}

$_SESSION['last_activity'] = time();
echo json_encode(['extended' => true, 'expires_in' => 7200]);
