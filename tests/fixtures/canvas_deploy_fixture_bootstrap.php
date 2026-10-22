<?php
declare(strict_types=1);

/**
 * Shared setup for the Deploy to Canvas browser fixture. It stands in for the
 * application bootstrap (database, YAML config, login) with test values so the
 * real view block and the real CanvasDeployRun can run against the mock Canvas.
 */

require_once __DIR__ . '/../../src/system/Core/CanvasDeployRun.php';
require_once __DIR__ . '/../../src/system/Core/LtiLaunchContext.php';
require_once __DIR__ . '/FakeCanvas.php';

$fixtureDir = sys_get_temp_dir() . '/slocloud-canvas-deploy-fixture';
if (!is_dir($fixtureDir)) {
    mkdir($fixtureDir . '/sessions', 0700, true);
}
session_save_path($fixtureDir . '/sessions');
session_name('cdfixture');
session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$fixtureLti = [
    'launch_url' => FakeCanvas::LAUNCH_URL,
    'consumer_key' => FakeCanvas::CONSUMER_KEY,
    'consumer_secret' => FakeCanvas::CONSUMER_SECRET,
];
$fixtureDefaultsFile = $fixtureDir . '/defaults.json';
$fixtureLogFile = $fixtureDir . '/log.jsonl';
$fixtureDefaults = is_file($fixtureDefaultsFile) ? (json_decode((string)file_get_contents($fixtureDefaultsFile), true) ?: []) : [];
