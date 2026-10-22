<?php
declare(strict_types=1);

// Browser fixture API: the real CanvasDeployRun dispatch with test LTI values,
// a temp lock directory, and JSON files standing in for config and the audit log.
require_once __DIR__ . '/canvas_deploy_fixture_bootstrap.php';

use SLOCloud\Core\CanvasDeployRun;
use SLOCloud\Core\LtiLaunchContext;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !hash_equals($_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf', 'message' => 'Your page expired. Reload it and try again.']);
    return;
}

// Sections "imported" into this fixture's SLO Cloud: every 202630 CRN except 10003.
$sisInspector = static function (?string $sisId): array {
    $sisId = trim((string)$sisId);
    if ($sisId === '') {
        return ['No SIS ID, so SLO Cloud cannot match this course to a section.'];
    }
    $offering = LtiLaunchContext::parseOffering('{CRN}.{TERM}', $sisId);
    if ($offering['crn'] === null || $offering['term'] === null) {
        return ['SIS ID "' . $sisId . '" does not fit the offering format {CRN}.{TERM}, so launches will show no class.'];
    }
    if ($offering['term'] !== '202630' || $offering['crn'] === '10003') {
        return ['Section ' . $offering['crn'] . ' in term ' . $offering['term'] . ' is not imported yet; it is created on first launch.'];
    }
    return [];
};
$writeLog = static function (string $kind, string $event, array $data) use ($fixtureLogFile): void {
    file_put_contents($fixtureLogFile, json_encode(['kind' => $kind, 'event' => $event, 'data' => $data]) . "\n", FILE_APPEND | LOCK_EX);
};

// Same session handling as src/administration/canvas_deploy_api.php.
$action = (string)($_POST['action'] ?? '');
$store = is_array($_SESSION['canvas_deploy'] ?? null) ? $_SESSION['canvas_deploy'] : [];
$batchRunId = $store['run']['id'] ?? null;
if ($action === 'run_batch') {
    session_write_close();
}
$service = new CanvasDeployRun(
    $store,
    $fixtureLti,
    $fixtureDir . '/lock',
    1,
    $sisInspector,
    static fn(string $event, array $data) => $writeLog('audit', $event, $data),
    static fn(string $message, array $context) => $writeLog('info', $message, $context)
);

$response = $service->dispatch(
    $action,
    $_POST,
    static function (array $defaults) use ($fixtureDefaultsFile, $writeLog): void {
        $values = CanvasDeployRun::configValues($defaults);
        file_put_contents($fixtureDefaultsFile, (string)json_encode($values, JSON_PRETTY_PRINT), LOCK_EX);
        $writeLog('audit', 'canvas_deploy_defaults', $values);
    }
);
if ($action === 'run_batch') {
    session_start();
    if (($_SESSION['canvas_deploy']['run']['id'] ?? null) === $batchRunId) {
        $_SESSION['canvas_deploy'] = $store;
    }
} else {
    $_SESSION['canvas_deploy'] = $store;
}
http_response_code($response['status']);
echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
