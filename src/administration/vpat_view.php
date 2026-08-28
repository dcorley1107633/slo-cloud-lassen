<?php
declare(strict_types=1);

/** Render a VPAT markdown document inside the administration theme. */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';

$document = str_replace('\\', '/', trim((string)($_GET['doc'] ?? '')));
$vpatDirectory = realpath(__DIR__ . '/../vpat');
$documentPath = $document !== '' && preg_match('/\A[A-Za-z0-9._\/-]+\.md\z/', $document) === 1
    && !in_array('..', explode('/', $document), true)
    ? realpath(__DIR__ . '/../vpat/' . $document)
    : false;

$isVpatDocument = $vpatDirectory !== false
    && $documentPath !== false
    && str_starts_with(strtolower($documentPath), strtolower($vpatDirectory . DIRECTORY_SEPARATOR))
    && is_readable($documentPath);

if (!$isVpatDocument) {
    http_response_code(404);
    $pageTitle = 'VPAT document not found';
    $markdownContent = '# Document not found' . "\n\n" . 'The requested VPAT document is unavailable.';
} else {
    $pageTitle = pathinfo($document, PATHINFO_FILENAME);
    $markdownContent = (string)file_get_contents($documentPath);
}

$currentPage = 'admin_vpat';

require_once __DIR__ . '/../system/Core/ThemeLoader.php';
require_once __DIR__ . '/../system/Core/MarkdownRenderer.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;
use SLOCloud\Core\MarkdownRenderer;

$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => $pageTitle,
    'currentPage' => $currentPage,
    'breadcrumbs' => [
        ['text' => 'Dashboard', 'url' => BASE_URL . 'administration/'],
        ['text' => 'Accessibility (VPAT)', 'url' => BASE_URL . 'administration/vpat.php'],
        ['text' => $pageTitle, 'url' => '', 'active' => true],
    ],
]);

$theme = ThemeLoader::getActiveTheme(null, 'admin');
$theme->showHeader($context);
?>

<div class="card card-primary card-outline">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h2 class="card-title mb-0">
            <i class="bi bi-universal-access me-2" aria-hidden="true"></i>
            Accessibility Report
        </h2>
        <a class="btn btn-sm btn-outline-secondary" href="<?= BASE_URL ?>administration/vpat.php">
            <i class="bi bi-arrow-left me-1" aria-hidden="true"></i> All reports
        </a>
    </div>
    <div class="card-body">
        <?php MarkdownRenderer::render($markdownContent, [
            'id' => 'vpat-output',
            'sourceBaseUrl' => BASE_URL . 'vpat/' . $document,
            'documentRootUrl' => BASE_URL . 'vpat/',
            'viewerUrl' => BASE_URL . 'administration/vpat_view.php?doc=',
        ]); ?>
    </div>
</div>

<?php $theme->showFooter($context); ?>
