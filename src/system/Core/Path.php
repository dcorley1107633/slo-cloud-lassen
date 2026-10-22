<?php
declare(strict_types=1);

namespace SLOCloud\Core;

/**
 * Path Helper
 * 
 * Provides utilities for determining application paths and URLs.
 * 
 * Performance Note:
 * The base URL is auto-detected during initial setup and stored in config.yaml.
 * On subsequent requests, it's read from config and set via setConfiguredBaseUrl()
 * to avoid recalculating on every page load. This eliminates filesystem operations
 * and improves performance.
 * 
 * During setup (before config exists), getBaseUrl() auto-detects from request data.
 * 
 * @package SLOCloud\Core
 */
class Path
{
    private static ?string $basePath = null;
    private static ?string $baseUrl = null;
    private static ?string $configuredBaseUrl = null;
    
    /**
     * Get the base path (filesystem) of the application
     * 
     * @return string Absolute filesystem path to src/ directory
     */
    public static function getBasePath(): string
    {
        if (self::$basePath === null) {
            // Path.php is at src/system/Core/Path.php
            // Go up two levels to reach src/
            self::$basePath = dirname(dirname(__DIR__));
        }
        return self::$basePath;
    }
    
    /**
     * Set the base URL from configuration
     * 
     * @param string $baseUrl Configured base URL
     */
    public static function setConfiguredBaseUrl(string $baseUrl): void
    {
        // Links are built as BASE_URL . 'page.php', so "/slo" would produce
        // "/slopage.php" on every page.
        $baseUrl = rtrim($baseUrl, '/') . '/';
        self::$configuredBaseUrl = $baseUrl;
        self::$baseUrl = $baseUrl;
    }
    
    /**
     * Get the base URL path for the application
     * 
     * Uses configured base URL if available, otherwise auto-detects.
     * Examples:
     * - If src/ is document root: returns '/'
     * - If src/ is in subdirectory /beta/: returns '/beta/'
     * - Works correctly even when called from subdirectories like /beta/setup/
     * 
     * @return string Base URL path (always ends with /)
     */
    public static function getBaseUrl(): string
    {
        // Use configured value if set
        if (self::$configuredBaseUrl !== null) {
            return self::$configuredBaseUrl;
        }
        
        if (self::$baseUrl !== null) {
            return self::$baseUrl;
        }
        
        // Get the directory of the current script relative to document root
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        
        // Normalize path separators
        $scriptName = str_replace('\\', '/', $scriptName);
        
        // Get the document root
        $docRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $docRoot = str_replace('\\', '/', $docRoot);
        $docRoot = rtrim($docRoot, '/');
        
        // Get the application root (src/ directory)
        $appRoot = self::getBasePath();
        $appRoot = str_replace('\\', '/', $appRoot);
        $appRoot = rtrim($appRoot, '/');
        
        // If document root is empty or same as app root, we're at root
        if (empty($docRoot) || $docRoot === $appRoot) {
            self::$baseUrl = '/';
            return self::$baseUrl;
        }
        
        // Calculate the relative path from document root to app root
        if (str_starts_with($appRoot, $docRoot)) {
            // App root is within document root - extract the relative path
            $basePath = substr($appRoot, strlen($docRoot));
            $basePath = '/' . trim($basePath, '/') . '/';
        } else {
            // Fallback: parse from SCRIPT_NAME
            // Remove any /setup/, /admin/, etc. from the end
            $basePath = $scriptName;
            // Remove filename
            $basePath = dirname($basePath);
            // If we're in a subdirectory like /setup, go up one level
            if (basename($basePath) !== '' && basename($basePath) !== '/') {
                $basePath = dirname($basePath);
            }
            $basePath = rtrim($basePath, '/') . '/';
        }
        
        // Normalize
        $basePath = preg_replace('#/+#', '/', $basePath);
        
        self::$baseUrl = $basePath;
        return self::$baseUrl;
    }
    
    /**
     * Get the full base URL including scheme and host
     * 
     * @return string Full base URL (e.g., 'https://example.edu/slocloud/')
     */
    public static function getFullBaseUrl(): string
    {
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $baseUrl = self::getBaseUrl();
        
        return $scheme . '://' . $host . $baseUrl;
    }
    
    /**
     * Build a URL relative to the application base
     * 
     * @param string $path Path relative to base (e.g., 'setup/', 'admin/users')
     * @return string Full URL path
     */
    public static function url(string $path): string
    {
        $baseUrl = self::getBaseUrl();
        $path = ltrim($path, '/');
        
        return $baseUrl . $path;
    }
    
    /**
     * Redirect to a path relative to the application base
     * 
     * @param string $path Path relative to base (e.g., 'setup/', 'admin/users')
     * @param int $statusCode HTTP status code (default: 302)
     * @return never
     */
    public static function redirect(string $path, int $statusCode = 302): never
    {
        $url = self::url($path);
        http_response_code($statusCode);
        header('Location: ' . $url);
        exit(0);
    }
    
    /**
     * Check if .slocloud-root marker file exists (for validation)
     * 
     * @return bool True if root marker exists
     */
    public static function validateRoot(): bool
    {
        $rootMarker = dirname(self::getBasePath()) . '/.slocloud-root';
        return file_exists($rootMarker);
    }
}
