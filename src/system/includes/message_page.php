<?php
/**
 * Simple Error/Success Page Helper
 * 
 * Usage:
 *   render_message_page('error', 'Title', 'Message', 'icon-class');
 */
function render_message_page(string $type, string $title, string $message, string $icon = ''): void {
    $siteName = defined('SITE_NAME') ? SITE_NAME : 'SLOCloud';

    // Error pages can render before init.php runs, so guarantee BASE_URL exists.
    if (!defined('BASE_URL')) {
        require_once __DIR__ . '/../Core/Path.php';
        define('BASE_URL', \SLOCloud\Core\Path::getBaseUrl());
    }
    
    // Load theme system
    require_once __DIR__ . '/../Core/ThemeLoader.php';
    require_once __DIR__ . '/../Core/ThemeContext.php';
    
    $context = new \SLOCloud\Core\ThemeContext([
        'layout' => 'default',
        'pageTitle' => $title . ' - ' . $siteName
    ]);
    
    $theme = \SLOCloud\Core\ThemeLoader::getActiveTheme();
    $theme->showHeader($context);
    
    $iconClass = $icon ?: ($type === 'error' ? 'fa-times-circle text-danger' : 'fa-check-circle text-success');
    ?>
    
    <div class="container">
        <div class="row justify-content-center align-items-center min-vh-100">
            <div class="col-md-6">
                <div class="card shadow">
                    <div class="card-body p-5">
                        <div class="text-center mb-4">
                            <i class="fas <?= $iconClass ?> fa-4x"></i>
                        </div>
                        <h1 class="h4 text-center mb-3"><?= htmlspecialchars($title) ?></h1>
                        <p class="text-muted text-center mb-0"><?= htmlspecialchars($message) ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php
    $theme->showFooter($context);
}
