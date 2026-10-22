<?php
declare(strict_types=1);

/**
 * Prints the site-wide color scheme (System > Theme Settings) as the last
 * <style> in <head>, so it overrides the layout's and the page's own colors.
 * Prints nothing for the default Blue scheme or before configuration loads.
 */
require_once __DIR__ . '/../Core/ColorScheme.php';
$colorSchemeConfig = $GLOBALS['config'] ?? null;
echo \SLOCloud\Core\ColorScheme::styleTag(
    $colorSchemeConfig instanceof \SLOCloud\Core\Config
        ? $colorSchemeConfig->get('theme.color_scheme', \SLOCloud\Core\ColorScheme::DEFAULT)
        : \SLOCloud\Core\ColorScheme::DEFAULT
);
