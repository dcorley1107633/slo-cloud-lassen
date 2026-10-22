<?php
declare(strict_types=1);

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
    http_response_code(403);
    exit('This form has expired. Reload the page and try again.');
}

$entity = (string)($_POST['entity'] ?? '');
$id = (int)($_POST['record_id'] ?? 0);
$newStatus = (string)($_POST['new_status'] ?? '') === '1' ? 1 : 0;
$entities = lifecycle_entities();
if (!isset($entities[$entity]) || $id <= 0) {
    http_response_code(400);
    exit('Invalid lifecycle request');
}

$pk = $entities[$entity]['pk'];
$returnUrl = (string)($_POST['return_url'] ?? 'index.php');
if (preg_match('/^[a-z_]+\\.php(?:\\?status=(?:active|inactive))?$/', $returnUrl) !== 1) {
    $returnUrl = 'index.php';
}
if ($newStatus === 1) {
    $predicate = lifecycle_parent_active_predicate($entity, 'target', $dbPrefix);
    $check = $db->query(
        "SELECT {$pk} FROM {$dbPrefix}{$entity} target WHERE {$pk} = ? AND {$predicate}",
        [$id],
        'i'
    );
    if (!fetch_row($check)) {
        $separator = str_contains($returnUrl, '?') ? '&' : '?';
        header('Location: ' . $returnUrl . $separator . 'lifecycle_error=parent');
        exit;
    }
}

$actorId = getValidUserId();
if (($entities[$entity]['updated_by'] ?? true) === true) {
    $db->query(
        "UPDATE {$dbPrefix}{$entity} SET is_active = ?, updated_at = {$db->now()}, updated_by_fk = ? WHERE {$pk} = ?",
        [$newStatus, $actorId, $id],
        'iii'
    );
} else {
    $db->query(
        "UPDATE {$dbPrefix}{$entity} SET is_active = ?, updated_at = {$db->now()} WHERE {$pk} = ?",
        [$newStatus, $id],
        'ii'
    );
}
$logger->audit($entity, $id, $newStatus === 1 ? 'reactivate' : 'deactivate', $actorId, [
    'is_active' => $newStatus,
]);
header('Location: ' . $returnUrl);
