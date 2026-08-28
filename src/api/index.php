<?php
declare(strict_types=1);

/**
 * System Status Dashboard
 * 
 * User-friendly display of system health checks.
 * Uses themed interface for consistency with application.
 * 
 * @package SLOCloud
 */

// Execute status checks (reuse API logic)
ob_start();
require_once __DIR__ . '/api.php';
$jsonOutput = ob_get_clean();
$statusData = json_decode($jsonOutput, true);
header('Content-Type: text/html; charset=UTF-8');

// Load theme if available
$useTheme = class_exists(\SLOCloud\Core\ThemeLoader::class);

$pageTitle = 'System Status';
$bodyClass = 'status-page';

if ($useTheme) {
    $context = new \SLOCloud\Core\ThemeContext([
        'layout' => 'admin',
        'pageTitle' => $pageTitle,
        'currentPage' => 'admin_system_status',
        'breadcrumbs' => [
            ['url' => BASE_URL . 'administration/', 'label' => 'Administration'],
            ['label' => 'System Status'],
        ],
    ]);
    $theme = \SLOCloud\Core\ThemeLoader::getActiveTheme(null, 'admin');
    $theme->showHeader($context);
} else {
    // Fallback header
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($pageTitle) ?></title>
        <link rel="stylesheet" href="<?= BASE_URL ?>system/accessibility.css">
        <script src="<?= BASE_URL ?>system/accessibility.js" defer></script>
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; color: #333; }
            .status-card { border: 1px solid #ddd; border-radius: 8px; padding: 20px; margin-bottom: 20px; background: #fff; }
            .status-ok { color: #146c43; }
            .status-warning { color: #806000; }
            .status-error { color: #dc3545; }
            .badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 14px; font-weight: 600; }
            .badge-ok { background: #d4edda; color: #155724; }
            .badge-warning { background: #fff3cd; color: #856404; }
            .badge-error { background: #f8d7da; color: #721c24; }
            .overall-status { font-size: 24px; font-weight: bold; margin: 20px 0; }
            h1 { color: #0D47A1; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
            th { background: #f8f9fa; font-weight: 600; }
            .timestamp { color: #6c757d; font-size: 14px; }
        </style>
    </head>
    <body>
    <a href="#main" class="skip-link">Skip to main content</a>
    <main id="main" tabindex="-1">
    <?php
}

$overallClass = match($statusData['overall_status']) {
    'operational' => 'status-ok',
    'degraded' => 'status-warning',
    'down' => 'status-error',
    default => 'status-warning'
};

$overallBadge = match($statusData['overall_status']) {
    'operational' => 'badge-ok',
    'degraded' => 'badge-warning',
    'down' => 'badge-error',
    default => 'badge-warning'
};

$overallText = match($statusData['overall_status']) {
    'operational' => 'All Systems Operational',
    'degraded' => 'Degraded Performance',
    'down' => 'System Down',
    default => 'Unknown Status'
};
?>

<div class="<?= $useTheme ? 'app-content' : '' ?>">
    <div class="<?= $useTheme ? 'container-fluid' : '' ?>">
        
        <div class="<?= $useTheme ? 'card' : 'status-card' ?>">
            <div class="<?= $useTheme ? 'card-header' : '' ?>">
                <?php if ($useTheme): ?>
                    <h2><i class="fas fa-heartbeat" aria-hidden="true"></i> System Status Details</h2>
                <?php else: ?>
                    <h1>System Status</h1>
                <?php endif; ?>
            </div>
            <div class="<?= $useTheme ? 'card-body' : '' ?>">
                
                <div class="overall-status <?= $overallClass ?>" role="status">
                    <span class="badge <?= $overallBadge ?>"><?= htmlspecialchars(strtoupper($statusData['overall_status'])) ?></span>
                    <span style="margin-left: 10px;"><?= htmlspecialchars($overallText) ?></span>
                </div>
                
                <?php if ($statusData['version']): ?>
                <p class="timestamp">
                    Version: <strong><?= htmlspecialchars($statusData['version']) ?></strong> | 
                    Last checked: <?= date('Y-m-d H:i:s T', strtotime($statusData['timestamp'])) ?>
                </p>
                <?php else: ?>
                <p class="timestamp">
                    Last checked: <?= date('Y-m-d H:i:s T', strtotime($statusData['timestamp'])) ?>
                </p>
                <?php endif; ?>
                
                <table class="<?= $useTheme ? 'table table-striped' : '' ?>">
                    <caption class="visually-hidden">Current health of application services and dependencies</caption>
                    <thead>
                        <tr>
                            <th scope="col">Component</th>
                            <th scope="col">Status</th>
                            <th scope="col">Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($statusData['checks'] as $component => $check): 
                            $statusClass = match($check['status']) {
                                'ok' => 'status-ok',
                                'warning' => 'status-warning',
                                'error' => 'status-error',
                                default => ''
                            };
                            
                            $badgeClass = match($check['status']) {
                                'ok' => 'badge-ok',
                                'warning' => 'badge-warning',
                                'error' => 'badge-error',
                                default => ''
                            };
                            
                            $icon = match($check['status']) {
                                'ok' => $useTheme ? '<i class="fas fa-check-circle" aria-hidden="true"></i>' : 'OK',
                                'warning' => $useTheme ? '<i class="fas fa-exclamation-triangle" aria-hidden="true"></i>' : 'Warning',
                                'error' => $useTheme ? '<i class="fas fa-times-circle" aria-hidden="true"></i>' : 'Error',
                                default => ''
                            };
                            
                            $componentName = ucwords(str_replace('_', ' ', $component));
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($componentName) ?></strong></td>
                            <td>
                                <span class="badge <?= $badgeClass ?> <?= $statusClass ?>">
                                    <?= $icon ?> <?= htmlspecialchars(strtoupper($check['status'])) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($check['message']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">
                    <p style="margin-bottom: 10px;">
                        <strong>API Endpoint:</strong> 
                        <a href="api.php">
                            <?= htmlspecialchars(BASE_URL . 'api/api.php', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </a>
                    </p>
                    <p style="color: #6c757d; font-size: 14px;">
                        Use the API endpoint for automated monitoring and health checks.
                    </p>
                </div>
                
            </div>
        </div>
        
    </div>
</div>

<?php
if ($useTheme) {
    $theme->showFooter($context);
} else {
    ?>
    </main>
    </body>
    </html>
    <?php
}
?>
