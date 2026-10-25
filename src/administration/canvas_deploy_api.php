<?php
declare(strict_types=1);

require_once __DIR__ . '/../system/includes/init.php';
require_once __DIR__ . '/../system/Core/CanvasDeployRun.php';

use SLOCloud\Core\CanvasDeployException;
use SLOCloud\Core\CanvasDeployRun;
use SLOCloud\Core\LtiAuthorizationException;
use SLOCloud\Core\LtiLaunchContext;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed', 'message' => 'Use POST.']);
    exit;
}
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string)$_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf', 'message' => 'Your page expired. Reload it and try again.']);
    exit;
}

@set_time_limit(90);

$lti = [
    'launch_url' => trim((string)$config->get('lti.launch_url', '')),
    'consumer_key' => trim((string)$config->get('lti.consumer_key', '')),
    'consumer_secret' => (string)$config->get('lti.consumer_secret', ''),
];
$offeringField = trim((string)$config->get('lti.offering_field', 'lis_course_offering_sourcedid'));
$offeringFormat = (string)$config->get('lti.offering_format', '{CRN}.{TERM}');

// Canvas sends the course SIS ID as lis_course_offering_sourcedid, so SIS checks
// only apply when SLO Cloud reads the offering from that field.
$sisInspector = static function (?string $sisId) use ($db, $dbPrefix, $offeringField, $offeringFormat): array {
    if ($offeringField !== 'lis_course_offering_sourcedid') {
        return [];
    }
    $sisId = trim((string)$sisId);
    if ($sisId === '') {
        return ['No SIS ID, so SLO Cloud cannot match this course to a section.'];
    }
    $offering = LtiLaunchContext::parseOffering($offeringFormat, $sisId);
    if ($offering['crn'] === null) {
        return ['SIS ID "' . $sisId . '" does not fit the offering format ' . $offeringFormat . ', so launches will show no class.'];
    }
    try {
        LtiLaunchContext::resolve($db, $dbPrefix, ['lti_term_id' => (string)$offering['term'], 'lti_crn' => $offering['crn']]);
        return [];
    } catch (LtiAuthorizationException) {
        return stripos($offering['crn'], 'XLS') === 0
            ? ['Cross-listed group for SIS ID "' . $sisId . '" is not in SLO Cloud, so launches will fail.']
            : ['Section ' . $offering['crn'] . ($offering['term'] !== null ? ' in term ' . $offering['term'] : '') . ' is not imported yet' . ($offering['term'] !== null ? '; it is created on first launch.' : '.')];
    } catch (\Throwable) {
        return ['Could not check this section in SLO Cloud.'];
    }
};

$actorId = getValidUserId();
$action = (string)($_POST['action'] ?? '');
$store = is_array($_SESSION['canvas_deploy'] ?? null) ? $_SESSION['canvas_deploy'] : [];
$batchRunId = $store['run']['id'] ?? null;
if ($action === 'run_batch') {
    // A batch talks to Canvas for about 20 seconds. Release the session lock so
    // the administrator's other SLO Cloud tabs are not blocked meanwhile.
    session_write_close();
}
$service = new CanvasDeployRun(
    $store,
    $lti,
    $applicationDataRoot . '/var/canvas_deploy',
    (int)($_SESSION['user_id'] ?? 0),
    $sisInspector,
    static function (string $event, array $data) use ($logger, $actorId): void {
        $logger->audit('canvas_deploy', 0, $event, $actorId, $data);
    },
    static function (string $message, array $context) use ($logger): void {
        $logger->info($message, $context);
    }
);

$saveDefaults = static function (array $defaults) use ($config, $logger, $actorId): void {
    $values = CanvasDeployRun::configValues($defaults);
    $previous = [];
    foreach ($values as $path => $value) {
        $previous[$path] = $config->get($path);
        $config->set($path, $value);
    }
    try {
        $config->save();
    } catch (\Throwable $exception) {
        throw new CanvasDeployException('The defaults could not be saved: ' . $exception->getMessage(), 'save_failed', 500);
    }
    $logger->audit('canvas_deploy', 0, 'canvas_deploy_defaults', $actorId, $values, $previous);
};

$response = $service->dispatch($action, $_POST, $saveDefaults);
if ($action === 'run_batch') {
    session_start();
    // Write back only if no other tab replaced or ended this run meanwhile.
    if (($_SESSION['canvas_deploy']['run']['id'] ?? null) === $batchRunId) {
        $_SESSION['canvas_deploy'] = $store;
    }
} else {
    $_SESSION['canvas_deploy'] = $store;
}
http_response_code($response['status']);
echo json_encode($response['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
