<?php
declare(strict_types=1);

// Browser fixture: renders the real view block from src/administration/canvas_deploy.php
// without the application bootstrap, wired to canvas_deploy_api_fixture.php.
require_once __DIR__ . '/canvas_deploy_fixture_bootstrap.php';

use SLOCloud\Core\CanvasDeployRun;

$source = (string)file_get_contents(__DIR__ . '/../../src/administration/canvas_deploy.php');
if (!preg_match('#<!-- canvas-deploy:view -->(.*)<!-- /canvas-deploy:view -->#s', $source, $match)) {
    throw new RuntimeException('Deploy to Canvas view block was not found.');
}

$canvasDeploy = [
    'apiUrl' => 'canvas_deploy_api_fixture.php',
    'configUrl' => '#',
    'csrfToken' => (string)$_SESSION['csrf_token'],
    'defaults' => CanvasDeployRun::defaultsFromConfig(
        static fn(string $key, mixed $default): mixed => $fixtureDefaults[$key] ?? $default
    ),
    'prerequisites' => CanvasDeployRun::prerequisites($fixtureLti),
];
$canvasDeploy['ready'] = !in_array(false, array_column($canvasDeploy['prerequisites'], 'ok'), true);
$assets = '../../src/assets/vendor/';
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Deploy to Canvas fixture</title>
    <link rel="stylesheet" href="<?= $assets ?>bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= $assets ?>fontawesome-free/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= $assets ?>bootstrap/5.3.8/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= $assets ?>admin-lte/4.1.0/css/adminlte.min.css">
    <link rel="stylesheet" href="../../src/system/accessibility.css">
</head>
<body class="bg-body-tertiary">
<main id="main" class="container-fluid py-4">
    <h1 class="h3 mb-3">Deploy to Canvas <span class="badge text-bg-secondary">fixture</span></h1>
    <?php eval('?>' . $match[1]); ?>
</main>
</body>
</html>
