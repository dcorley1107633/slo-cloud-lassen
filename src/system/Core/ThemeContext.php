<?php
/**
 * Theme Context
 * 
 * Encapsulates all variables needed for theme rendering.
 * Passed to both showHeader() and showFooter() methods.
 * 
 * Usage:
 *   $context = new ThemeContext([
 *       'layout' => 'admin',
 *       'pageTitle' => 'Dashboard',
 *       'currentPage' => 'admin_dashboard',
 *       'customCss' => '<style>...</style>',
 *       'customScripts' => '<script>...</script>'
 *   ]);
 */

declare(strict_types=1);

namespace SLOCloud\Core;

class ThemeContext
{
    private array $data = [];
    
    /**
     * Constructor
     * 
     * @param array $data Initial context data
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;
        
        // Set defaults
        if (!isset($this->data['layout'])) {
            $this->data['layout'] = null; // Will use theme default
        }
        if (!isset($this->data['pageTitle'])) {
            $this->data['pageTitle'] = defined('SITE_NAME') ? SITE_NAME : 'SLOCloud';
        }
        if (!isset($this->data['appVersion'])) {
            $this->data['appVersion'] = defined('APP_VERSION') ? APP_VERSION : '1.0.0';
        }
    }
    
    /**
     * Get a context value
     * 
     * @param string $key Key to retrieve
     * @param mixed $default Default value if key not found
     * @return mixed Value or default
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
    
    /**
     * Set a context value
     * 
     * @param string $key Key to set
     * @param mixed $value Value to set
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
    
    /**
     * Check if key exists
     * 
     * @param string $key Key to check
     * @return bool True if key exists
     */
    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }
    
    /**
     * Get all context data as array
     * 
     * @return array All context data
     */
    public function toArray(): array
    {
        return $this->data;
    }
    
    /**
     * Magic getter for property access
     * 
     * @param string $key Property name
     * @return mixed Value or null
     */
    public function __get(string $key): mixed
    {
        return $this->get($key);
    }
    
    /**
     * Magic setter for property access
     * 
     * @param string $key Property name
     * @param mixed $value Value to set
     * @return void
     */
    public function __set(string $key, mixed $value): void
    {
        $this->set($key, $value);
    }
    
    /**
     * Magic isset for property access
     * 
     * @param string $key Property name
     * @return bool True if property exists
     */
    public function __isset(string $key): bool
    {
        return $this->has($key);
    }
}
