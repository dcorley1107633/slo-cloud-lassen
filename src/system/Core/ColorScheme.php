<?php
declare(strict_types=1);

namespace SLOCloud\Core;

/**
 * Site-wide color schemes (System > Configuration > Theme Settings, theme.color_scheme).
 *
 * Each scheme recolors the layouts' brand variables (--primary-dark,
 * --accent-blue, --brand-teal) and Bootstrap's "primary" color: buttons,
 * links, badges, pagination, progress bars and checked boxes. Every color is
 * dark enough for white text and for link text on white or light gray
 * (4.5:1, checked by tests/accessibility_test.php). Focus indicators are not
 * recolored: they keep the shared, tested dark blue.
 *
 * Blue is the original palette and outputs nothing, so the default look is
 * exactly what the layouts define.
 *
 * @package SLOCloud\Core
 */
final class ColorScheme
{
    public const DEFAULT = 'blue';

    /**
     * group: heading in the chooser. primary: buttons, links, badges; dark:
     * hover/active, headers and the admin sidebar;
     * accent and brand: the layouts' gradient and highlight colors. highlight
     * (optional): a light accent such as Vault-Tec yellow, used only on the dark
     * sidebar, never behind white text. stripe
     * (optional): a rainbow bar shown at the top of every page and under the
     * sidebar brand.
     */
    public const SCHEMES = [
        'blue'        => ['label' => 'Blue', 'group' => 'Standard', 'primary' => '#0d6efd', 'dark' => '#0d47a1', 'accent' => '#1976d2', 'brand' => '#1565c0'],
        'teal'        => ['label' => 'Teal', 'group' => 'Standard', 'primary' => '#0f766e', 'dark' => '#134e4a', 'accent' => '#0f766e', 'brand' => '#115e59'],
        'green'       => ['label' => 'Green', 'group' => 'Standard', 'primary' => '#166534', 'dark' => '#14532d', 'accent' => '#15803d', 'brand' => '#166534'],
        'maroon'      => ['label' => 'Maroon', 'group' => 'Standard', 'primary' => '#9f1239', 'dark' => '#881337', 'accent' => '#be123c', 'brand' => '#9f1239'],
        'purple'      => ['label' => 'Purple', 'group' => 'Standard', 'primary' => '#6d28d9', 'dark' => '#4c1d95', 'accent' => '#7c3aed', 'brand' => '#5b21b6'],
        'slate'       => ['label' => 'Slate gray', 'group' => 'Standard', 'primary' => '#475569', 'dark' => '#1e293b', 'accent' => '#334155', 'brand' => '#475569'],
        'vault-tec'   => ['label' => 'Vault-Tec', 'group' => 'Fallout', 'primary' => '#1d4f91', 'dark' => '#0f2a52', 'accent' => '#2563a8', 'brand' => '#1a4480', 'highlight' => '#facc15'],
        'pip-boy'     => ['label' => 'Pip-Boy green', 'group' => 'Fallout', 'primary' => '#1b6b1b', 'dark' => '#052e05', 'accent' => '#2e7d32', 'brand' => '#1b5e20', 'highlight' => '#4ade80'],
        'brotherhood' => ['label' => 'Brotherhood of Steel', 'group' => 'Fallout', 'primary' => '#991b1b', 'dark' => '#1f2937', 'accent' => '#7f1d1d', 'brand' => '#991b1b', 'highlight' => '#e5e7eb'],
        'nuka-cola'   => ['label' => 'Nuka-Cola', 'group' => 'Fallout', 'primary' => '#c1121f', 'dark' => '#7f0d14', 'accent' => '#b91c1c', 'brand' => '#a50f1b', 'highlight' => '#fef3c7'],
        'raiders'     => ['label' => 'Raiders', 'group' => 'Fallout', 'primary' => '#9a3412', 'dark' => '#431407', 'accent' => '#c2410c', 'brand' => '#7c2d12', 'highlight' => '#fb923c'],
        'ncr'         => ['label' => 'New California Republic', 'group' => 'Fallout', 'primary' => '#854d0e', 'dark' => '#422006', 'accent' => '#a16207', 'brand' => '#713f12', 'highlight' => '#fcd34d'],
        'enclave'     => ['label' => 'Enclave', 'group' => 'Fallout', 'primary' => '#1e3a5f', 'dark' => '#0b1320', 'accent' => '#334e7a', 'brand' => '#1e3a5f', 'highlight' => '#60a5fa'],
        'ocean'       => ['label' => 'Ocean', 'group' => 'Other', 'primary' => '#0369a1', 'dark' => '#0c4a6e', 'accent' => '#0369a1', 'brand' => '#075985'],
        'midnight'    => ['label' => 'Midnight', 'group' => 'Other', 'primary' => '#3730a3', 'dark' => '#1e1b4b', 'accent' => '#4f46e5', 'brand' => '#312e81'],
        'sunset'      => ['label' => 'Sunset', 'group' => 'Other', 'primary' => '#9a3412', 'dark' => '#7c2d12', 'accent' => '#b45309', 'brand' => '#9a3412'],
        'pride'       => ['label' => 'Pride rainbow', 'group' => 'Other', 'primary' => '#5b21b6', 'dark' => '#1e1b4b', 'accent' => '#be185d', 'brand' => '#4c1d95', 'highlight' => '#fde047', 'stripe' => 'linear-gradient(90deg, #e40303 0 16.66%, #ff8c00 0 33.33%, #ffed00 0 50%, #008026 0 66.66%, #004dff 0 83.33%, #750787 0)'],
        'mocha'       => ['label' => 'Mocha', 'group' => 'Other', 'primary' => '#78350f', 'dark' => '#2b1608', 'accent' => '#92400e', 'brand' => '#78350f'],
    ];

    /** Heading order for the chooser; each scheme's 'group' is one of these. */
    public const GROUPS = ['Standard', 'Fallout', 'Other'];

    /** @return array<string, array<string, array>> scheme definitions by group, in GROUPS order */
    public static function grouped(): array
    {
        $groups = array_fill_keys(self::GROUPS, []);
        foreach (self::SCHEMES as $name => $scheme) {
            $groups[$scheme['group']][$name] = $scheme;
        }
        return $groups;
    }

    /** A known scheme name; anything else falls back to the default. */
    public static function normalize(mixed $scheme): string
    {
        return is_string($scheme) && isset(self::SCHEMES[$scheme]) ? $scheme : self::DEFAULT;
    }

    /** Light tint of $hex: $whiteShare of white mixed in (0..1). */
    public static function tint(string $hex, float $whiteShare): string
    {
        [$r, $g, $b] = self::rgb($hex);
        $mix = static fn(int $channel): int => (int)round($channel + (255 - $channel) * $whiteShare);
        return sprintf('#%02x%02x%02x', $mix($r), $mix($g), $mix($b));
    }

    /** CSS custom properties and Bootstrap overrides for $scheme; '' for the default. */
    public static function css(mixed $scheme): string
    {
        $scheme = self::normalize($scheme);
        if ($scheme === self::DEFAULT) {
            return '';
        }
        $c = self::SCHEMES[$scheme];
        $p = $c['primary'];
        $d = $c['dark'];
        $pRgb = implode(', ', self::rgb($p));
        $dRgb = implode(', ', self::rgb($d));
        $subtle = self::tint($p, 0.88);
        $border = self::tint($p, 0.6);

        return ":root, [data-bs-theme=light] {"
            . "--primary-dark: {$d}; --accent-blue: {$c['accent']}; --brand-teal: {$c['brand']};"
            . "--bs-primary: {$p}; --bs-primary-rgb: {$pRgb};"
            . "--bs-primary-text-emphasis: {$d}; --bs-primary-bg-subtle: {$subtle}; --bs-primary-border-subtle: {$border};"
            . "--bs-link-color: {$p}; --bs-link-color-rgb: {$pRgb}; --bs-link-hover-color: {$d}; --bs-link-hover-color-rgb: {$dRgb};"
            . "}\n"
            . ".btn-primary {--bs-btn-bg: {$p}; --bs-btn-border-color: {$p}; --bs-btn-hover-bg: {$d}; --bs-btn-hover-border-color: {$d};"
            . " --bs-btn-active-bg: {$d}; --bs-btn-active-border-color: {$d}; --bs-btn-disabled-bg: {$p}; --bs-btn-disabled-border-color: {$p};"
            . " --bs-btn-focus-shadow-rgb: {$pRgb};}\n"
            . ".btn-outline-primary {--bs-btn-color: {$p}; --bs-btn-border-color: {$p}; --bs-btn-hover-bg: {$p}; --bs-btn-hover-border-color: {$p};"
            . " --bs-btn-active-bg: {$d}; --bs-btn-active-border-color: {$d}; --bs-btn-disabled-color: {$p}; --bs-btn-disabled-border-color: {$p};"
            . " --bs-btn-focus-shadow-rgb: {$pRgb};}\n"
            . ".btn-link {--bs-btn-color: {$p}; --bs-btn-hover-color: {$d}; --bs-btn-active-color: {$d};}\n"
            . ".nav-pills {--bs-nav-pills-link-active-bg: {$p};}\n"
            . ".pagination {--bs-pagination-active-bg: {$p}; --bs-pagination-active-border-color: {$p}; --bs-pagination-color: {$p}; --bs-pagination-hover-color: {$d};}\n"
            . ".list-group {--bs-list-group-active-bg: {$p}; --bs-list-group-active-border-color: {$p};}\n"
            . ".dropdown-menu {--bs-dropdown-link-active-bg: {$p};}\n"
            . ".progress, .progress-stacked {--bs-progress-bar-bg: {$p};}\n"
            . ".form-check-input:checked {background-color: {$p}; border-color: {$p};}\n"
            // The admin sidebar is dark gray by default; the other schemes tint it with their dark color.
            . ".app-sidebar, .scheme-sample-sidebar {--bs-body-bg: {$d}; --bs-secondary-bg: {$d}; background-color: {$d} !important;}\n"
            . ".app-sidebar .sidebar-brand, .scheme-sample-sidebar .sidebar-brand {border-bottom-color: rgba(255, 255, 255, 0.2);}\n"
            . (isset($c['highlight']) ? ":root, [data-bs-theme=light] {--scheme-highlight: {$c['highlight']};}\n" : '')
            . (isset($c['stripe'])
                ? ":root, [data-bs-theme=light] {--scheme-stripe: {$c['stripe']};}\n"
                    . "body::before {content: ''; position: fixed; top: 0; left: 0; right: 0; height: 4px; background: {$c['stripe']}; z-index: 2000; pointer-events: none;}\n"
                    . ".app-sidebar .sidebar-brand, .scheme-sample-sidebar .sidebar-brand {border-bottom: 4px solid transparent !important; border-image: {$c['stripe']} 1;}\n"
                : '')
            . (isset($c['highlight'])
                ? (isset($c['stripe']) ? '' : ".app-sidebar .sidebar-brand, .scheme-sample-sidebar .sidebar-brand {border-bottom: 3px solid {$c['highlight']} !important;}\n")
                    . ".app-sidebar .nav-link.active, .app-sidebar .nav-link.active:hover, .scheme-sample-sidebar .nav-link.active {background-color: rgba(255, 255, 255, 0.1) !important; color: {$c['highlight']} !important; box-shadow: inset 4px 0 0 {$c['highlight']};}\n"
                : '');
    }

    /** The <style> element a layout prints last in <head>; '' for the default. */
    public static function styleTag(mixed $scheme): string
    {
        $css = self::css($scheme);
        return $css === '' ? '' : "<style id=\"slo-color-scheme\">\n{$css}</style>\n";
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }
}
