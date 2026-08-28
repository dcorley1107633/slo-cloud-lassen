<?php
declare(strict_types=1);

namespace SLOCloud\Core;

/** Shared, themed client-side Markdown renderer. */
final class MarkdownRenderer
{
    /**
     * @param array{id?: string, sourceBaseUrl?: string, documentRootUrl?: string, viewerUrl?: string} $options
     */
    public static function render(string $markdown, array $options = []): void
    {
        $id = (string)($options['id'] ?? 'markdown-document');
        if (preg_match('/\A[A-Za-z][A-Za-z0-9_-]*\z/', $id) !== 1) {
            throw new \InvalidArgumentException('Markdown renderer ID is invalid.');
        }

        $sourceBaseUrl = (string)($options['sourceBaseUrl'] ?? '');
        $documentRootUrl = (string)($options['documentRootUrl'] ?? '');
        $viewerUrl = (string)($options['viewerUrl'] ?? '');
        $jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
        ?>
        <div id="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>" class="markdown-document"></div>
        <script src="<?= BASE_URL ?>assets/vendor/marked/12.0.2/marked.min.js"></script>
        <script>
        (function () {
            var output = document.getElementById(<?= json_encode($id, $jsonFlags) ?>);
            var markdown = <?= json_encode($markdown, $jsonFlags) ?>;
            output.innerHTML = marked.parse(markdown);

            var sourceBaseUrl = <?= json_encode($sourceBaseUrl, $jsonFlags) ?>;
            var documentRootUrl = <?= json_encode($documentRootUrl, $jsonFlags) ?>;
            var viewerUrl = <?= json_encode($viewerUrl, $jsonFlags) ?>;
            if (!sourceBaseUrl || !documentRootUrl || !viewerUrl) {
                return;
            }

            var sourceUrl = new URL(sourceBaseUrl, window.location.origin);
            var documentRoot = new URL(documentRootUrl, window.location.origin);
            output.querySelectorAll('a[href]').forEach(function (link) {
                var href = link.getAttribute('href');
                if (!href || href.charAt(0) === '#' || /^[a-z][a-z0-9+.-]*:/i.test(href)) {
                    return;
                }

                var target = new URL(href, sourceUrl);
                if (target.pathname.startsWith(documentRoot.pathname) && target.pathname.toLowerCase().endsWith('.md')) {
                    var targetDocument = decodeURIComponent(target.pathname.slice(documentRoot.pathname.length));
                    link.href = viewerUrl + encodeURIComponent(targetDocument);
                } else {
                    link.href = target.href;
                }
            });
        })();
        </script>
        <style>
            .markdown-document { overflow-wrap: anywhere; overflow-x: auto; }
            .markdown-document h1, .markdown-document h2 {
                margin-top: 1.5rem;
                border-bottom: 2px solid #dee2e6;
                padding-bottom: 0.4rem;
            }
            .markdown-document h1:first-child { margin-top: 0; }
            .markdown-document h3 { margin-top: 1.2rem; color: #1976D2; }
            .markdown-document ul { padding-left: 1.5rem; }
            .markdown-document li { margin-bottom: 0.3rem; }
            .markdown-document hr { margin: 2rem 0; }
            .markdown-document table { width: 100%; margin-bottom: 1rem; border-collapse: collapse; }
            .markdown-document th, .markdown-document td {
                padding: 0.75rem;
                border: 1px solid #dee2e6;
                vertical-align: top;
            }
            .markdown-document th { background: #f8f9fa; }
            .markdown-document blockquote {
                padding: 0.75rem 1rem;
                border-left: 0.25rem solid #6c757d;
                background: #f8f9fa;
            }
        </style>
        <?php
    }
}
