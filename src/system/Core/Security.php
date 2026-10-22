<?php
declare(strict_types=1);

namespace SLOCloud\Core;

/**
 * Security primitives needed before the application configuration is loaded.
 *
 * Proxy trust is deliberately opt-in. Set SLOCLOUD_TRUSTED_PROXIES to a
 * comma-separated list of proxy IP addresses before forwarded HTTPS headers
 * are honored. Set SLOCLOUD_ENV=production to make cookies Secure even when a
 * broken proxy fails to forward the original scheme (the session will fail
 * safely instead of crossing cleartext HTTP).
 */
final class Security
{
    public static function dataRoot(string $sourceRoot): string
    {
        $override = getenv('SLOCLOUD_DATA_PATH');
        if (is_string($override) && trim($override) !== '') {
            return rtrim($override, '/\\');
        }

        $sourceRoot = rtrim(str_replace('\\', '/', $sourceRoot), '/');
        if (strtolower(basename($sourceRoot)) === 'src') {
            return dirname($sourceRoot);
        }
        return dirname($sourceRoot) . '/' . basename($sourceRoot) . '-data';
    }

    /**
     * True for D:\path, D:/path, \\server\share and /path. A relative configured
     * directory would resolve against the requested script's folder in the web root.
     */
    public static function isAbsolutePath(string $path): bool
    {
        return preg_match('#^(?:[A-Za-z]:[\\\\/]|[\\\\/])#', $path) === 1;
    }

    /**
     * Log directory from logging.log_directory, or <data>/logs when it is empty or
     * not an absolute path, so a bad setting cannot move logs into the web root.
     * A folder that does not exist and cannot be created (a missing drive, no
     * permission for the app pool) also falls back: the logger cannot start
     * without its folder, and every page would fail.
     */
    public static function logDirectory(mixed $configured, string $sourceRoot): string
    {
        if (is_string($configured) && self::isAbsolutePath(trim($configured))) {
            $directory = rtrim(trim($configured), '/\\');
            if (is_dir($directory) || @mkdir($directory, 0700, true)) {
                return $directory;
            }
        }
        return self::dataRoot($sourceRoot) . '/logs';
    }

    public static function isHttps(array $server = []): bool
    {
        $server = $server ?: $_SERVER;
        if (strtolower((string)($server['HTTPS'] ?? '')) === 'on'
            || (int)($server['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        $remoteAddress = (string)($server['REMOTE_ADDR'] ?? '');
        if (!self::isTrustedProxy($remoteAddress)) {
            return false;
        }

        $forwardedProto = strtolower(trim(explode(',', (string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        return $forwardedProto === 'https';
    }

    /**
     * True when the deployment has been explicitly marked as production.
     *
     * Security defaults that can be relaxed for local development (certificate
     * verification bypasses, plaintext cookies) must consult this and refuse to
     * relax themselves when it returns true.
     */
    public static function isProduction(): bool
    {
        return strtolower(trim((string)getenv('SLOCLOUD_ENV'))) === 'production';
    }

    public static function isTrustedProxy(string $address): bool
    {
        if ($address === '') {
            return false;
        }
        $trusted = array_filter(array_map('trim', explode(',', (string)getenv('SLOCLOUD_TRUSTED_PROXIES'))));
        return in_array($address, $trusted, true);
    }

    public static function configureSession(string $storagePath): void
    {
        if (session_status() !== PHP_SESSION_NONE) {
            return;
        }

        if (!is_dir($storagePath) && !mkdir($storagePath, 0700, true) && !is_dir($storagePath)) {
            throw new \RuntimeException('Unable to create protected session storage.');
        }
        @chmod($storagePath, 0700);
        if (!is_writable($storagePath)) {
            throw new \RuntimeException('Protected session storage is not writable.');
        }

        ini_set('session.save_path', $storagePath);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');

        $https = self::isHttps();
        $production = self::isProduction();
        $isLti = self::isLtiRoute();
        session_name('SLOCLOUDSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => self::cookiePath(),
            'domain' => '',
            'secure' => $https || $production,
            'httponly' => true,
            'samesite' => $isLti && ($https || $production) ? 'None' : 'Strict',
        ]);
        session_start();
    }

    public static function rotateSessionIfDue(int $intervalSeconds = 900): void
    {
        $lastRotation = (int)($_SESSION['session_rotated_at'] ?? 0);
        if ($lastRotation === 0 || time() - $lastRotation >= $intervalSeconds) {
            session_regenerate_id(true);
            $_SESSION['session_rotated_at'] = time();
        }
    }

    public static function destroySession(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Strict',
            ]);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public static function sendResponseHeaders(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Content-Security-Policy: ' . self::contentSecurityPolicy());
        header('Cache-Control: no-store, private');
        header('Pragma: no-cache');

        // HSTS is only meaningful, and only safe, once every route is reachable
        // over HTTPS: a premature header locks browsers out of an install that
        // still has plaintext-only paths. It therefore stays opt-in through
        // SLOCLOUD_HSTS_MAX_AGE and is sent only on connections already secured.
        $maxAge = self::hstsMaxAge();
        if ($maxAge > 0 && self::isHttps()) {
            $value = 'max-age=' . $maxAge;
            if (strtolower(trim((string)getenv('SLOCLOUD_HSTS_INCLUDE_SUBDOMAINS'))) === 'true') {
                $value .= '; includeSubDomains';
            }
            header('Strict-Transport-Security: ' . $value);
        }
    }

    /**
     * Build the enforced browser policy shared by normal and embedded routes.
     *
     * Inline scripts and styles remain enabled for compatibility with the
     * current server-rendered interface. LTI callers can broaden only the
     * framing directive without replacing the rest of the policy.
     *
     * @param string[] $frameAncestors Valid CSP frame-ancestor source values
     */
    public static function contentSecurityPolicy(array $frameAncestors = ["'self'"]): string
    {
        $frameAncestors = array_values(array_unique(array_filter(
            array_map('trim', $frameAncestors),
            static fn(string $source): bool => preg_match("/^(?:'self'|'none'|https:|https:\/\/[A-Za-z0-9.-]+(?::\d{1,5})?)$/", $source) === 1
        )));
        if ($frameAncestors === []) {
            $frameAncestors = ["'self'"];
        }

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            'frame-ancestors ' . implode(' ', $frameAncestors),
        ]);
    }

    /**
     * Configured HSTS max-age in seconds, or 0 when HSTS is not enabled.
     */
    public static function hstsMaxAge(): int
    {
        $configured = trim((string)getenv('SLOCLOUD_HSTS_MAX_AGE'));
        if ($configured === '' || !ctype_digit($configured)) {
            return 0;
        }
        return (int)$configured;
    }

    /**
     * Send a plaintext request back over HTTPS.
     *
     * Uses isHttps(), so a request arriving at a trusted proxy over TLS is not
     * bounced into a redirect loop. Enabled by SLOCLOUD_FORCE_HTTPS or implied
     * by an HSTS policy - both require the operator to have verified HTTPS
     * coverage first. Non-idempotent methods get a 308 so the method and body
     * survive the redirect rather than being silently downgraded to GET.
     */
    public static function requireHttps(): void
    {
        if (self::isHttps() || headers_sent()) {
            return;
        }

        $forced = strtolower(trim((string)getenv('SLOCLOUD_FORCE_HTTPS'))) === 'true';
        if (!$forced && self::hstsMaxAge() === 0) {
            return;
        }

        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        // Only a trusted proxy's Host value may steer the redirect target; an
        // untrusted Host header would let a caller aim the redirect anywhere.
        if (!self::isTrustedProxy((string)($_SERVER['REMOTE_ADDR'] ?? ''))) {
            $host = (string)($_SERVER['SERVER_NAME'] ?? $host);
        }
        if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+(:\d{1,5})?$/', $host)) {
            return;
        }

        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $status = in_array($method, ['GET', 'HEAD'], true) ? 301 : 308;
        $target = 'https://' . $host . (string)($_SERVER['REQUEST_URI'] ?? '/');
        header('Location: ' . $target, true, $status);
        exit;
    }

    /**
     * Find secret-looking configuration data in a session payload.
     *
     * Session records live on disk and outlive the request, so a secret that
     * reaches one has to be treated as disclosed. This walks the array and
     * reports the dotted path of anything whose key names a credential and
     * whose value is not obviously already encrypted, which lets a test assert
     * the invariant rather than trusting review to catch a future regression.
     *
     * @param array $session Session payload to inspect
     * @param string $prefix Internal - dotted path accumulated so far
     * @return string[] Dotted paths of offending entries; empty when clean
     */
    public static function findSessionSecrets(array $session, string $prefix = ''): array
    {
        static $secretKeyPattern = '/(password|passwd|secret|private_key|encryption_key|api_key|apikey|token|credential)/i';

        $found = [];
        foreach ($session as $key => $value) {
            $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;

            if (is_array($value)) {
                $found = array_merge($found, self::findSessionSecrets($value, $path));
                continue;
            }

            if (!is_string($value) && !is_int($value)) {
                continue;
            }
            $value = (string)$value;
            if ($value === '') {
                continue;
            }

            // CSRF and per-launch tokens are session-scoped values that belong
            // in a session; they are not configuration secrets.
            if (in_array((string)$key, ['csrf_token', 'lti_token', 'session_rotated_at'], true)) {
                continue;
            }

            if (preg_match($secretKeyPattern, (string)$key) === 1) {
                $found[] = $path;
            }
        }

        return $found;
    }

    public static function relativeScriptPath(array $server = []): string
    {
        $server = $server ?: $_SERVER;
        $filename = str_replace('\\', '/', (string)($server['SCRIPT_FILENAME'] ?? ''));
        $documentRoot = rtrim(str_replace('\\', '/', (string)($server['DOCUMENT_ROOT'] ?? '')), '/');
        if ($filename !== '' && $documentRoot !== '' && str_starts_with(strtolower($filename), strtolower($documentRoot . '/'))) {
            $relative = ltrim(substr($filename, strlen($documentRoot)), '/');
            return str_starts_with(strtolower($relative), 'src/') ? substr($relative, 4) : $relative;
        }

        $path = str_replace('\\', '/', (string)($server['SCRIPT_NAME'] ?? ''));
        foreach (['administration/', 'api/', 'lti/', 'saml/', 'setup/', 'system/'] as $marker) {
            $position = strripos($path, '/' . $marker);
            if ($position !== false) {
                return ltrim(substr($path, $position + 1), '/');
            }
        }
        return basename($path);
    }

    private static function isLtiRoute(): bool
    {
        return str_starts_with(self::relativeScriptPath(), 'lti/') || isset($_POST['oauth_consumer_key']);
    }

    private static function cookiePath(): string
    {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'));
        foreach (['/administration/', '/api/', '/lti/', '/saml/', '/setup/', '/system/'] as $marker) {
            $position = stripos($script, $marker);
            if ($position !== false) {
                $base = substr($script, 0, $position + 1);
                return $base === '' ? '/' : $base;
            }
        }
        $directory = str_replace('\\', '/', dirname($script));
        return $directory === '/' || $directory === '.' ? '/' : rtrim($directory, '/') . '/';
    }
}
