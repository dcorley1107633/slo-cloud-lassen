<?php
/**
 * Autoloader for the bundled third-party libraries (no Composer).
 *
 *   OneLogin\*                   -> onelogin-php-saml/src/       (onelogin/php-saml 4.3.2, MIT)
 *   RobRichards\XMLSecLibs\*     -> xmlseclibs/src/              (robrichards/xmlseclibs 3.1.5, BSD-3-Clause)
 *
 * See README.md in this folder for versions and how to update them.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    static $prefixes = [
        'OneLogin\\' => __DIR__ . '/onelogin-php-saml/src/',
        'RobRichards\\XMLSecLibs\\' => __DIR__ . '/xmlseclibs/src/',
    ];

    foreach ($prefixes as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        $file = $directory . str_replace('\\', '/', $relative) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
        return;
    }
});
