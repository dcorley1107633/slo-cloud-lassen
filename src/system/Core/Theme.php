<?php
/**
 * Theme Base Class
 * 
 * Base class for all theme plugins.
 * Handles YAML loading, layout validation, and rendering.
 * 
 * Theme plugins extend this class and provide:
 * - Plugin directory path
 * - Optional custom rendering logic
 */

declare(strict_types=1);

namespace SLOCloud\Core;

class Theme
{
    protected string $pluginPath;
    protected array $config = [];
    protected string $defaultLayout;
    
    /**
     * Constructor
     * 
     * @param string $pluginPath Absolute path to plugin directory
     */
    public function __construct(string $pluginPath)
    {
        $this->pluginPath = rtrim($pluginPath, '/\\');
        $this->loadConfig();
    }
    
    /**
     * Load plugin configuration from YAML
     * 
     * @return void
     */
    protected function loadConfig(): void
    {
        $configPath = $this->pluginPath . '/plugin.yaml';
        
        if (!file_exists($configPath)) {
            throw new \RuntimeException("Theme config not found: {$configPath}");
        }
        
        $this->config = $this->parseYAML($configPath);
        $this->defaultLayout = $this->config['theme']['default_layout'] ?? 'simple';
    }
    
    /**
     * Parse and validate a theme manifest.
     */
    protected function parseYAML(string $filePath): array
    {
        if (!function_exists('yaml_parse')) {
            throw new \RuntimeException('The required PHP YAML extension (ext-yaml) is not loaded.');
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new \RuntimeException("Cannot read theme config: {$filePath}");
        }

        set_error_handler(static function (int $severity, string $message) use ($filePath): never {
            throw new \RuntimeException("Invalid YAML in {$filePath}: {$message}");
        });
        try {
            $result = \yaml_parse($content);
        } finally {
            restore_error_handler();
        }

        if (!is_array($result)
            || !is_array($result['theme'] ?? null)
            || !is_string($result['theme']['default_layout'] ?? null)
            || !is_array($result['theme']['layouts'] ?? null)) {
            throw new \RuntimeException("Invalid theme config structure: {$filePath}");
        }
        foreach ($result['theme']['layouts'] as $layout => $layoutConfig) {
            if (!is_string($layout)
                || !is_array($layoutConfig)
                || !is_string($layoutConfig['header'] ?? null)
                || !is_string($layoutConfig['footer'] ?? null)) {
                throw new \RuntimeException("Invalid layout '{$layout}' in theme config: {$filePath}");
            }
        }

        return $result;
    }
    
    /**
     * Show theme header
     * 
     * Fallback chain: layout-specific → default layout → error
     * 
     * @param ThemeContext $context Rendering context
     * @return void
     */
    public function showHeader(ThemeContext $context): void
    {
        $requestedLayout = $context->get('layout') ?? $this->defaultLayout;
        
        // Try requested layout first, fall back to default if not found
        $layout = $requestedLayout;
        if (!isset($this->config['theme']['layouts'][$layout])) {
            $layout = $this->defaultLayout;
        }
        
        $layoutConfig = $this->getLayoutConfig($layout);
        $headerPath = $this->pluginPath . '/' . $layoutConfig['header'];
        
        // Fallback to default layout if specific layout file doesn't exist
        if (!file_exists($headerPath) && $layout !== $this->defaultLayout) {
            $defaultConfig = $this->getLayoutConfig($this->defaultLayout);
            $headerPath = $this->pluginPath . '/' . $defaultConfig['header'];
        }
        
        if (!file_exists($headerPath)) {
            throw new \RuntimeException("Header file not found for layout '{$requestedLayout}' and no default fallback exists");
        }
        
        // Extract context variables for use in template
        extract($context->toArray());
        
        require $headerPath;
    }
    
    /**
     * Show theme footer
     * 
     * Fallback chain: layout-specific → default layout → error
     * 
     * @param ThemeContext $context Rendering context
     * @return void
     */
    public function showFooter(ThemeContext $context): void
    {
        $requestedLayout = $context->get('layout') ?? $this->defaultLayout;
        
        // Try requested layout first, fall back to default if not found
        $layout = $requestedLayout;
        if (!isset($this->config['theme']['layouts'][$layout])) {
            $layout = $this->defaultLayout;
        }
        
        $layoutConfig = $this->getLayoutConfig($layout);
        $footerPath = $this->pluginPath . '/' . $layoutConfig['footer'];
        
        // Fallback to default layout if specific layout file doesn't exist
        if (!file_exists($footerPath) && $layout !== $this->defaultLayout) {
            $defaultConfig = $this->getLayoutConfig($this->defaultLayout);
            $footerPath = $this->pluginPath . '/' . $defaultConfig['footer'];
        }
        
        if (!file_exists($footerPath)) {
            throw new \RuntimeException("Footer file not found for layout '{$requestedLayout}' and no default fallback exists");
        }
        
        // Extract context variables for use in template
        extract($context->toArray());
        
        require $footerPath;
    }
    
    /**
     * Get layout configuration
     * 
     * @param string $layout Layout name
     * @return array Layout configuration
     */
    protected function getLayoutConfig(string $layout): array
    {
        if (!isset($this->config['theme']['layouts'][$layout])) {
            throw new \RuntimeException("Layout not found: {$layout}");
        }
        
        return $this->config['theme']['layouts'][$layout];
    }
    
    /**
     * Get available layouts
     * 
     * @return array Array of layout names
     */
    public function getLayouts(): array
    {
        return array_keys($this->config['theme']['layouts'] ?? []);
    }
    
    /**
     * Get theme metadata
     * 
     * @return array Theme metadata (id, name, version, etc.)
     */
    public function getMetadata(): array
    {
        return [
            'id' => $this->config['id'] ?? 'unknown',
            'name' => $this->config['name'] ?? 'Unknown Theme',
            'version' => $this->config['version'] ?? '1.0.0',
            'type' => $this->config['type'] ?? 'theme',
            'description' => $this->config['description'] ?? '',
            'author' => $this->config['author'] ?? '',
            'default_layout' => $this->defaultLayout
        ];
    }
    
    /**
     * Get default layout name
     * 
     * @return string Default layout name
     */
    public function getDefaultLayout(): string
    {
        return $this->defaultLayout;
    }
}
