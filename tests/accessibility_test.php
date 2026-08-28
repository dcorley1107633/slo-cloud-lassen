<?php
declare(strict_types=1);

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};
$source = static fn(string $path): string => (string)file_get_contents(__DIR__ . '/../' . $path);

$version = trim($source('src/VERSION'));
$check($version === '5.1.1', 'Accessibility report test must track the current product version.');

$sharedCss = $source('src/system/accessibility.css');
$check(str_contains($sharedCss, ':focus-visible'), 'Shared visible focus styling is missing.');
$check(!str_contains($sharedCss, 'outline: none'), 'Shared CSS suppresses a focus indicator.');
$check(str_contains($sharedCss, '@media (prefers-reduced-motion: reduce)'), 'Reduced-motion support is missing.');
$check(str_contains($sharedCss, '@media (forced-colors: active)'), 'Forced-colors support is missing.');
$check(str_contains($sharedCss, 'min-width: 24px') && str_contains($sharedCss, 'min-height: 24px'), 'Shared minimum target sizing is missing.');
$check(str_contains($sharedCss, 'box-shadow: 0 0 0 6px'), 'Focus styling does not provide a two-color boundary for mixed backgrounds.');
$check(str_contains($sharedCss, 'border-color: #6c757d'), 'Form-control boundaries do not have a minimum-contrast override.');
$check(str_contains($sharedCss, '.metis-sidebar') && str_contains($sharedCss, 'max-width: calc(100vw - 1rem)'), 'Shared 320-pixel reflow safeguards are missing.');
$check(str_contains($sharedCss, '.toast-container') && str_contains($sharedCss, 'min-width: 0'), 'Notification reflow safeguards are missing.');

$luminance = static function (string $hex): float {
    $channels = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
    $linear = array_map(static function (int $channel): float {
        $value = $channel / 255;
        return $value <= 0.04045 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }, $channels);
    return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
};
$contrast = static function (string $a, string $b) use ($luminance): float {
    $values = [$luminance($a), $luminance($b)];
    sort($values);
    [$dark, $light] = $values;
    return ($light + 0.05) / ($dark + 0.05);
};
$check($contrast('#0b57d0', '#ffffff') >= 4.5, 'Shared focus blue fails 4.5:1 against white.');
$check($contrast('#6c757d', '#ffffff') >= 4.5, 'Shared control boundary/muted color fails 4.5:1 against white.');
$check($contrast('#087990', '#ffffff') >= 4.5, 'Shared informational background fails 4.5:1 with white text.');
$check($contrast('#806000', '#ffffff') >= 4.5, 'Shared warning text fails 4.5:1 against white.');
$check($contrast('#146c43', '#ffffff') >= 4.5, 'Status success text fails 4.5:1 against white.');

$sharedJs = $source('src/system/accessibility.js');
foreach (['a11y-error-summary', 'aria-invalid', 'aria-describedby', 'dataTables_info', 'show.bs.modal', 'hidden.bs.modal'] as $behavior) {
    $check(str_contains($sharedJs, $behavior), "Shared accessibility behavior is missing $behavior support.");
}
$check(str_contains($sharedJs, 'Your session is about to expire') && str_contains($sharedJs, 'Continue session'), 'Admin session warning/extension dialog is missing.');
$check(str_contains($sharedJs, "container.style.setProperty('bottom', '1rem', 'important')"), 'Fixed notifications do not move away from obscured focus.');

$layoutFiles = [
    'src/system/plugins/local/theme-adminlte/layouts/admin/header.php',
    'src/system/plugins/local/theme-adminlte/layouts/default/header.php',
    'src/system/plugins/local/theme-adminlte/layouts/embedded/header.php',
    'src/system/plugins/local/theme-default/layouts/default/header.php',
    'src/system/plugins/local/theme-default/layouts/fluid/header.php',
    'src/system/plugins/local/theme-default/layouts/navbar/header.php',
    'src/system/plugins/local/theme-metis/layouts/default/header.php',
    'src/system/plugins/local/theme-metis/layouts/compact/header.php',
    'src/system/plugins/local/theme-metis/layouts/dashboard/header.php',
    'src/login.php',
    'src/setup/index.php',
];
foreach ($layoutFiles as $layoutFile) {
    $layout = $source($layoutFile);
    $check(str_contains($layout, 'system/accessibility.css'), "$layoutFile does not load the shared accessibility styles.");
    $check(str_contains($layout, 'system/accessibility.js'), "$layoutFile does not load the shared accessibility behavior.");
    $check(str_contains($layout, 'lang="en"'), "$layoutFile does not declare the page language.");
}

foreach (array_slice($layoutFiles, 0, 9) as $layoutFile) {
    $check(str_contains($source($layoutFile), 'session_timeout_meta.php'), "$layoutFile does not configure the session warning.");
}

foreach (['admin', 'default', 'embedded'] as $layout) {
    $header = $source("src/system/plugins/local/theme-adminlte/layouts/$layout/header.php");
    $check(str_contains($header, 'href="#main"') || str_contains($header, '<main id="main"'), "AdminLTE $layout layout has no main-content bypass target.");
}

$adminHeader = $source('src/system/plugins/local/theme-adminlte/layouts/admin/header.php');
$check(str_contains($adminHeader, '<main id="main"') && str_contains($adminHeader, 'tabindex="-1"'), 'Admin layout main target is not programmatically focusable.');
$check(!str_contains($adminHeader, '*:focus:not(:focus-visible)'), 'Admin layout suppresses keyboard focus fallback.');
$check(substr_count($adminHeader, '<button type="button" class="nav-link" aria-expanded="false" aria-controls="sidebar-') === 8, 'Admin sidebar groups are not native buttons with exposed expanded state.');
$check(str_contains($adminHeader, 'administration/site_map.php'), 'Admin navigation does not expose the alternate Site Map route.');

$login = $source('src/login.php');
$check(str_contains($login, '<h1 class="h2'), 'Login page has no page-level heading.');
$check(str_contains($login, 'autocomplete="username"') && str_contains($login, 'autocomplete="current-password"'), 'Login fields lack recognized autocomplete purposes.');
$check(str_contains($login, 'data-password-toggle="password"'), 'Login does not provide a password reveal control.');
$check(str_contains($login, 'data-a11y-focus="true"'), 'Login errors are not intentionally focused.');
$check(str_contains($login, 'aria-label="Dismiss error message"'), 'Login error dismiss control has no accessible name.');

$builder = $source('src/administration/dynamic_reports.php');
foreach (['${p}-field', '${p}-label', '${p}-aggregate', '${p}-sort', '${p}-control', '${p}-operator', '${p}-default'] as $id) {
    $check(str_contains($builder, 'for="' . $id . '"'), "Dynamic report builder label is not bound to $id.");
}
$check(str_contains($builder, '<legend class="visually-hidden">Column ${i+1}</legend>'), 'Generated report columns are not grouped with a legend.');
$check(str_contains($builder, '<legend class="visually-hidden">Runtime filter ${i+1}</legend>'), 'Generated report filters are not grouped with a legend.');

$assessment = $source('src/lti/assessment.php');
$check(!str_contains($assessment, '*:focus:not(:focus-visible)'), 'LTI page suppresses keyboard focus fallback.');
$check(str_contains($assessment, 'aria-label="Save notifications"'), 'LTI save notification region has no accessible name.');
$check(str_contains($assessment, "type === 'error' ? 'alert' : 'status'"), 'LTI notifications do not distinguish errors from routine status.');
$check(str_contains($assessment, "toast.addEventListener('focusin', pauseTimer)"), 'Timed LTI notifications do not pause on keyboard focus.');
$check(str_contains($assessment, "duration === 0"), 'Persistent LTI error notifications are not supported.');
$check(str_contains($assessment, 'Your assessment session is about to expire') && str_contains($assessment, 'Continue session'), 'LTI session warning/extension dialog is missing.');
$check(str_contains($assessment, 'Undo assessment change') && str_contains($assessment, 'Undo strategy changes'), 'LTI student-record changes do not provide an undo path.');
$keepalive = $source('src/lti/keepalive.php');
$check(str_contains($keepalive, "REQUEST_METHOD'] !== 'POST'") && str_contains($keepalive, 'hash_equals'), 'LTI session extension is not protected by POST and CSRF validation.');

$sessionEndpoint = $source('src/session.php');
$check(str_contains($sessionEndpoint, "REQUEST_METHOD'] !== 'POST'") && str_contains($sessionEndpoint, 'hash_equals'), 'Admin session extension is not protected by POST and CSRF validation.');

foreach ([
    'src/administration/courses.php' => 'viewCourseModalLabel',
    'src/administration/enrollment.php' => 'viewEnrollmentModalLabel',
    'src/administration/institutional_outcomes.php' => 'viewOutcomeModalLabel',
    'src/administration/programs.php' => 'viewProgramModalLabel',
    'src/administration/terms.php' => 'copyTermModalLabel',
] as $modalFile => $labelId) {
    $check(str_contains($source($modalFile), 'aria-labelledby="' . $labelId . '"'), "$modalFile has an unnamed dialog.");
}

foreach (['admin-interface', 'assessment-page'] as $reportName) {
    $report = $source("src/vpat/$reportName-vpat-v$version.md");
    $check(str_contains($report, "5.1.1"), "$reportName VPAT does not identify the current version.");
    $check(str_contains($report, 'claims WCAG 2.2 Level AA conformance for this scope'), "$reportName VPAT does not state the current Level AA claim.");
    $check(!str_contains($report, 'Partially Supports'), "$reportName current VPAT still contains partial-conformance language.");
    $check(!preg_match('/^\|\s*[1-4]\.\d+(?:\.\d+)?[^|]*\|\s*Partially Supports\s*\|/m', $report), "$reportName VPAT still contains a partially supporting WCAG result.");
    preg_match_all('/^\|\s*[1-4]\.\d+(?:\.\d+)?[^|]*\|\s*(?:Supports|Not Applicable)\s*\|/m', $report, $criterionRows);
    $check(count($criterionRows[0]) === 55, "$reportName VPAT does not contain 55 supported or not-applicable A/AA results.");
    $check(str_contains($report, 'Independent') || str_contains($report, 'independent'), "$reportName VPAT does not record the independent-testing gap.");
}

$vpatIndex = $source('src/administration/vpat.php');
$check(str_contains($vpatIndex, 'admin-interface-vpat-v5.1.0.md'), 'VPAT index does not link the current admin report.');
$check(str_contains($vpatIndex, 'assessment-page-vpat-v5.1.0.md'), 'VPAT index does not link the current assessment report.');
$check(substr_count($vpatIndex, '<caption') >= 2, 'VPAT data tables lack accessible captions.');
$check(substr_count($vpatIndex, 'Level AA: Supports') >= 2, 'VPAT index does not show both current scopes as supporting Level AA.');

$auditFiles = [];
foreach (['src/administration', 'src/lti', 'src/system/plugins/local'] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/../' . $directory));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') $auditFiles[] = $file->getPathname();
    }
}
foreach (['src/login.php', 'src/setup/index.php', 'src/api/index.php'] as $file) $auditFiles[] = __DIR__ . '/../' . $file;
$auditSource = implode("\n", array_map(static fn(string $file): string => (string)file_get_contents($file), $auditFiles));
$check(!preg_match('/<(?:img|audio|video|canvas|iframe)\b/i', $auditSource), 'In-scope source contains unreviewed non-text or media content.');
$check(!preg_match('/\b(?:accesskey|draggable|onfocus|onblur|onmouseover|onmouseout)\s*=/i', $auditSource), 'In-scope source contains an unreviewed keyboard, drag, or hover-only interaction.');
$check(!preg_match('/tabindex\s*=\s*["\'][1-9]/i', $auditSource), 'In-scope source contains a positive tabindex.');
$hasCustomInteractive = preg_match('/<(?:div|span|li)\b[^>]*(?:onclick\s*=|role\s*=\s*["\'](?:button|link|checkbox|radio|switch|tab|menuitem)["\'])/i', $auditSource, $customInteractiveMatch);
$check(!$hasCustomInteractive, 'In-scope source contains a non-native custom interactive element: ' . ($customInteractiveMatch[0] ?? 'unknown'));
$check(!preg_match('/<th\b(?![^>]*\bscope\s*=)[^>]*>/i', $auditSource), 'An in-scope table header is missing explicit scope.');

foreach ($auditFiles as $file) {
    $fileSource = (string)file_get_contents($file);
    preg_match_all('/<label\b[^>]*\bfor=["\']([^"\'\$<>{}]+)["\']/i', $fileSource, $labelTargets);
    foreach ($labelTargets[1] as $target) {
        $check(
            str_contains($fileSource, 'id="' . $target . '"') || str_contains($fileSource, "id='" . $target . "'"),
            basename($file) . " has a label for missing control #$target."
        );
    }
}

preg_match_all('/<a\b[^>]*target\s*=\s*["\']_blank["\'][^>]*>.*?<\/a>/is', $auditSource, $newWindowLinks);
foreach ($newWindowLinks[0] as $link) {
    $check(str_contains($link, 'rel="noopener noreferrer"'), 'A new-window link is missing rel="noopener noreferrer".');
    $check(str_contains($link, 'visually-hidden'), 'A new-window link does not disclose the context change in its accessible name.');
}
preg_match_all('/<form\b[^>]*target\s*=\s*["\']_blank["\'][^>]*>.*?<\/form>/is', $auditSource, $newWindowForms);
foreach ($newWindowForms[0] as $form) {
    $check(str_contains($form, 'visually-hidden'), 'A new-window form submission does not disclose the context change.');
}

if ($failures !== []) {
    fwrite(STDERR, "Accessibility tests failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Accessibility tests passed.\n";
