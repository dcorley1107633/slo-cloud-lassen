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

// Group releases by minor version (## v5.2.x under "Version 5.2"), in
// sections that open and close; the first (newest) is open. Headings inside
// a group move down one level so the group heading stays above them.
$grouped = [];
$currentMinor = null;
foreach (preg_split('/\R/', $markdownContent) ?: [] as $line) {
    if (preg_match('/^## v?(\d+)\.(\d+|x)\b/', $line, $m) === 1) {
        $minor = $m[1] . '.' . $m[2];
        if ($minor !== $currentMinor) {
            if ($currentMinor !== null) {
                $grouped[] = '</details>';
                $grouped[] = '';
            }
            $label = $m[2] === 'x' ? 'Version ' . $m[1] . '.x' : 'Version ' . $minor;
            $grouped[] = '<details class="patch-notes-group"' . ($currentMinor === null ? ' open' : '') . '>';
            $grouped[] = '<summary><h2>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</h2></summary>';
            $grouped[] = '';
            $currentMinor = $minor;
        }
    }
    if ($currentMinor !== null) {
        if (trim($line) === '---') {
            continue;
        }
        if (preg_match('/^#{2,5} /', $line) === 1) {
            $line = '#' . $line;
        }
    }
    $grouped[] = $line;
}
if ($currentMinor !== null) {
    $grouped[] = '';
    $grouped[] = '</details>';
}
$markdownContent = implode("\n", $grouped);
?>

<style>
    .patch-notes-group { margin-bottom: 0.75rem; border: 1px solid #dee2e6; border-radius: 0.375rem; }
    .patch-notes-group > summary { padding: 0.5rem 0.75rem; cursor: pointer; background: #f8f9fa; border-radius: 0.375rem; }
    .patch-notes-group[open] > summary { border-bottom: 1px solid #dee2e6; border-radius: 0.375rem 0.375rem 0 0; }
    .patch-notes-group > summary h2 { display: inline; margin: 0; padding: 0; border: 0; font-size: 1.35rem; }
    .patch-notes-group > :not(summary) { margin-left: 0.75rem; margin-right: 0.75rem; }
    .markdown-document .patch-notes-group h3 { margin-top: 1.25rem; padding-bottom: 0.3rem; border-bottom: 1px solid #dee2e6; color: inherit; }
    .markdown-document .patch-notes-group h4 { margin-top: 1.2rem; color: #1976D2; }
</style>

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
