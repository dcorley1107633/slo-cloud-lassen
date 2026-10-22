<?php
declare(strict_types=1);

/**
 * Color Scheme
 *
 * Choose the site-wide color scheme (theme.color_scheme in config.yaml).
 * Choosing a swatch previews it on this page; Save stores it for everyone.
 * Administrators only (Authorization::ADMIN_ONLY_ROUTES).
 *
 * @package SLOCloud
 */

require_once __DIR__ . '/../system/includes/init.php';
require_once __DIR__ . '/../system/includes/config_form.php';
require_once __DIR__ . '/../system/Core/ColorScheme.php';

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)$_POST['csrf_token'])) {
        http_response_code(403);
        die('This form has expired. Reload the page and try again.');
    }

    try {
        $posted = $_POST['theme_color_scheme'] ?? '';
        if (!is_string($posted) || !isset(\SLOCloud\Core\ColorScheme::SCHEMES[$posted])) {
            throw new \InvalidArgumentException('Choose one of the listed color schemes.');
        }
        $config->set('theme.color_scheme', $posted);
        $config->save();
        $successMessage = 'Color scheme saved. Pages use it from the next page load.';
    } catch (\Exception $e) {
        $logger->warning('Color scheme save failed', ['error' => $e->getMessage()]);
        $errorMessage = 'The color scheme was not saved: ' . $e->getMessage();
    }
}

require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => 'Color Scheme',
    'currentPage' => 'admin_color_scheme',
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['label' => 'Color Scheme']
    ]
]);

$theme = ThemeLoader::getActiveTheme(null, 'admin');
$theme->showHeader($context);
?>

<?php if ($successMessage): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle" aria-hidden="true"></i> <?= htmlspecialchars($successMessage) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close alert"></button>
</div>
<?php endif; ?>

<?php if ($errorMessage): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle" aria-hidden="true"></i> <?= htmlspecialchars($errorMessage) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close alert"></button>
</div>
<?php endif; ?>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$_SESSION['csrf_token']) ?>">

    <div class="card mb-3">
        <div class="card-header">
            <h2 class="card-title">
                <i class="fas fa-palette" aria-hidden="true"></i>
                Color Scheme
            </h2>
        </div>
        <div class="card-body">
            <?= renderColorSchemePicker('theme_color_scheme', $config->get('theme.color_scheme', \SLOCloud\Core\ColorScheme::DEFAULT)) ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h2 class="card-title">
                <i class="fas fa-eye" aria-hidden="true"></i>
                What the color scheme changes
            </h2>
        </div>
        <div class="card-body">
            <p class="text-muted">This sample screen is for looking only (it is hidden from screen readers and cannot be clicked). It updates as you choose a scheme above.</p>

            <div class="d-flex flex-wrap gap-3 mb-3" aria-hidden="true">
                <div class="text-center small"><div class="rounded border" style="width:5rem;height:2.25rem;background:var(--primary-dark);"></div>Dark</div>
                <div class="text-center small"><div class="rounded border" style="width:5rem;height:2.25rem;background:var(--bs-primary);"></div>Primary</div>
                <div class="text-center small"><div class="rounded border" style="width:5rem;height:2.25rem;background:var(--accent-blue);"></div>Accent</div>
                <div class="text-center small"><div class="rounded border" style="width:5rem;height:2.25rem;background:var(--scheme-highlight, repeating-linear-gradient(45deg, #fff 0 4px, #ddd 4px 8px));"></div>Highlight</div>
                <div class="text-center small"><div class="rounded border" style="width:5rem;height:2.25rem;background:var(--scheme-stripe, repeating-linear-gradient(45deg, #fff 0 4px, #ddd 4px 8px));"></div>Rainbow bar</div>
                <p class="small text-muted align-self-center mb-0">A striped box means the scheme does not use that color.</p>
            </div>

            <div class="border rounded overflow-hidden" aria-hidden="true" inert>
                <div style="height:4px;background:var(--scheme-stripe, transparent);"></div>
                <div class="row g-0">
                    <div class="col-md-3 scheme-sample-sidebar p-2" style="background:#343a40;" data-bs-theme="dark">
                        <div class="sidebar-brand text-white fw-semibold px-2 pb-2 mb-2" style="border-bottom:1px solid rgba(255,255,255,.2);">SLO Cloud</div>
                        <ul class="nav flex-column">
                            <li class="nav-item"><span class="nav-link text-white-50"><i class="bi bi-house me-2"></i>Home</span></li>
                            <li class="nav-item"><span class="nav-link active text-white"><i class="bi bi-gear me-2"></i>Current page</span></li>
                            <li class="nav-item"><span class="nav-link text-white-50"><i class="bi bi-people me-2"></i>Another page</span></li>
                        </ul>
                    </div>
                    <div class="col-md-9 p-3 bg-body">
                        <div class="rounded text-white px-3 py-3 mb-3" style="background:linear-gradient(135deg, var(--primary-dark), var(--accent-blue));border-bottom:4px solid var(--scheme-highlight, transparent);">
                            <span class="fw-semibold">Assessment Results</span><br>
                            <span class="small">Page header, gradient and highlight edge</span>
                        </div>

                        <div class="row g-3">
                            <div class="col-lg-6">
                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    <span class="btn btn-primary">Primary</span>
                                    <span class="btn btn-outline-primary">Outline</span>
                                    <span class="btn btn-link">Link button</span>
                                </div>
                                <p>Body text with a <a href="#colorSchemeSampleLink">sample link</a> inside it.</p>
                                <p>
                                    <span class="badge text-bg-primary">Badge</span>
                                    <span class="badge bg-primary-subtle text-primary-emphasis">Subtle badge</span>
                                </p>
                                <div class="alert alert-primary py-2">Primary alert message</div>
                                <div class="progress mb-3" style="height:1rem;"><div class="progress-bar" style="width:65%;"></div></div>
                                <div class="form-check"><input class="form-check-input" type="checkbox" checked disabled id="sampleCheck"><label class="form-check-label" for="sampleCheck">Checked box</label></div>
                                <div class="form-check"><input class="form-check-input" type="radio" checked disabled id="sampleRadio"><label class="form-check-label" for="sampleRadio">Selected option</label></div>
                            </div>
                            <div class="col-lg-6">
                                <ul class="pagination">
                                    <li class="page-item"><span class="page-link">Previous</span></li>
                                    <li class="page-item active"><span class="page-link">1</span></li>
                                    <li class="page-item"><span class="page-link">2</span></li>
                                </ul>
                                <ul class="nav nav-pills mb-2">
                                    <li class="nav-item"><span class="nav-link active">Selected</span></li>
                                    <li class="nav-item"><span class="nav-link">Other</span></li>
                                </ul>
                                <ul class="list-group mb-2">
                                    <li class="list-group-item active">Selected list item</li>
                                    <li class="list-group-item">Another list item</li>
                                </ul>
                                <ul class="dropdown-menu position-static d-block mb-3">
                                    <li><span class="dropdown-item active">Selected menu item</span></li>
                                    <li><span class="dropdown-item">Menu item</span></li>
                                </ul>
                                <table class="table table-striped table-sm mb-0">
                                    <tbody>
                                        <tr><td>Row one</td><td><a href="#colorSchemeSampleLink">Link</a></td></tr>
                                        <tr><td>Row two</td><td><a href="#colorSchemeSampleLink">Link</a></td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fas fa-save" aria-hidden="true"></i> Save Color Scheme
            </button>
            <a href="<?= BASE_URL ?>administration/config.php" class="btn btn-secondary btn-lg">
                <i class="fas fa-cog" aria-hidden="true"></i> Configuration
            </a>
        </div>
    </div>
</form>

<?php $theme->showFooter($context); ?>
