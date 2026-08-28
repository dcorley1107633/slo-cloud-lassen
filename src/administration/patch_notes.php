<?php
declare(strict_types=1);

/**
 * Patch Notes - Admin Interface
 *
 * Displays the project PATCH_NOTES.md rendered as HTML.
 *
 * @package SLOCloud
 */

require_once __DIR__ . '/../system/includes/init.php';

$pageTitle = 'Patch Notes';
$currentPage = 'admin_patch_notes';

require_once __DIR__ . '/../system/Core/ThemeLoader.php';
require_once __DIR__ . '/../system/Core/MarkdownRenderer.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;
use SLOCloud\Core\MarkdownRenderer;

$context = new ThemeContext([
    'layout'      => 'admin',
    'pageTitle'   => $pageTitle,
    'currentPage' => $currentPage,
    'breadcrumbs' => [
        ['text' => 'Dashboard', 'url' => BASE_URL . 'administration/'],
        ['text' => 'System', 'url' => '#'],
        ['text' => 'Patch Notes', 'url' => '', 'active' => true]
    ],
]);

$theme = ThemeLoader::getActiveTheme(null, 'admin');
$theme->showHeader($context);

// Resolve path to PATCH_NOTES.md (one level up from src/administration/ = src/)
$patchNotesPath = realpath(__DIR__ . '/../PATCH_NOTES.md');
$markdownContent = '';
if ($patchNotesPath !== false && is_readable($patchNotesPath)) {
    $markdownContent = (string)file_get_contents($patchNotesPath);
} else {
    $markdownContent = 'PATCH_NOTES.md could not be found.';
}
?>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h2 class="card-title mb-0">
            <i class="bi bi-journal-text me-2" aria-hidden="true"></i>
            Patch Notes
        </h2>
        <span class="badge bg-primary fs-6">v<?= htmlspecialchars(trim(file_get_contents(__DIR__ . '/../VERSION'))) ?></span>
    </div>
    <div class="card-body">
        <?php MarkdownRenderer::render($markdownContent, ['id' => 'patch-notes-output']); ?>
    </div>
</div>

<?php $theme->showFooter($context); ?>
