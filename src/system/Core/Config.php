<?php
declare(strict_types=1);

namespace SLOCloud\Core;

use RuntimeException;

/**
 * YAML configuration loader
 * 
 * Uses ext-yaml for standards-compliant parsing and serialization.
 * Auto-decrypts sensitive configuration values using AES-256-GCM.
 * Decrypted values live only for the current request and are never session-cached.
 * 
 * TEMPLATE MERGING (BACKWARD COMPATIBILITY):
 * - Automatically loads config.yaml.template on every config load
 * - Template defaults are merged with user's config.yaml
 * - User values always take precedence over template defaults
 * - New config options from updates are available even if not in deployed config.yaml
 * - Old config files continue working after upgrades without manual updates
 * 
 * SECURITY ARCHITECTURE:
 * - key.yaml contains master encryption key (protected, outside web root)
 * - config.yaml contains encrypted credentials (can be in version control)
 * - Config and key files require strict file permissions (600)
 * 
 * @package SLOCloud\Core
 */
class Config
{
    private static ?Config $instance = null;
    private array $config = [];
    private string $configPath;
    private ?Encryption $encryption = null;
    
    /**
     * Configuration fields that support encryption
     */
    private const ENCRYPTED_FIELDS = [
        'database.password',
        'email.smtp_password',
        'lti.consumer_secret',
        'security.student_encryption_key'
    ];
    
    /**
     * Private constructor
     * 
     * @param string $configPath Path to config.yml
     * @param array|null $cachedData Optional cached config data
     * @throws RuntimeException If config file not found or invalid
     */
    private function __construct(string $configPath, ?array $cachedData = null)
    {
        self::requireYamlExtension();
        $this->configPath = $configPath;
        
        if ($cachedData !== null) {
            // Load from cache
            $this->config = $cachedData;
            $this->normalizeLegacySchemaTypes($this->config);
            $this->validateSchema($this->config);
            
            // Still need to initialize encryption for get() calls
            $encryptionKey = $this->loadMasterKey();
            if (!empty($encryptionKey) && strlen($encryptionKey) === 64) {
                require_once __DIR__ . '/Encryption.php';
                try {
                    $this->encryption = new Encryption($encryptionKey);
                } catch (\Exception $e) {
                    $this->encryption = null;
                }
            }
            $this->validateSecurityConfiguration();
        } else {
            // Parse from file
            $this->load();
        }
    }
    
    /**
     * Get singleton instance
     * 
     * @param string|null $configPath Path to config file (required on first call)
     * @param array|null $cachedData Optional cached config data to avoid re-parsing
     * @return Config
     */
    public static function getInstance(?string $configPath = null, ?array $cachedData = null): Config
    {
        if (self::$instance === null) {
            if ($configPath === null) {
                throw new RuntimeException('Config path required on first instantiation');
            }
            self::$instance = new self($configPath, $cachedData);
        }
        
        return self::$instance;
    }
    
    /**
     * Check if configuration file exists
     * 
     * @param string $configPath Path to config file
     * @return bool
     */
    public static function exists(string $configPath): bool
    {
        return file_exists($configPath);
    }
    
    /**
     * Load configuration from YAML file
     * 
     * @throws RuntimeException If file cannot be read or parsed
     */
    private function load(): void
    {
        if (!file_exists($this->configPath)) {
            throw new RuntimeException("Configuration file not found: {$this->configPath}");
        }
        
        $content = file_get_contents($this->configPath);
        if ($content === false) {
            throw new RuntimeException("Cannot read configuration file: {$this->configPath}");
        }
        
        $this->config = $this->parseYaml($content, $this->configPath);
        $this->normalizeLegacySchemaTypes($this->config);
        $this->validateSchema($this->config);
        
        // Load template and merge defaults (ensures new config options are available)
        $this->mergeTemplateDefaults();
        
        // Load master encryption key from key.yaml
        $encryptionKey = $this->loadMasterKey();
        if (!empty($encryptionKey) && strlen($encryptionKey) === 64) {
            require_once __DIR__ . '/Encryption.php';
            try {
                $this->encryption = new Encryption($encryptionKey);
            } catch (\Exception $e) {
                // Invalid encryption key - continue without encryption
                $this->encryption = null;
            }
        }
        $this->validateSecurityConfiguration();
    }

    private function validateSecurityConfiguration(): void
    {
        $publicDefaults = [
            'database.password' => ['slopass'],
            'lti.consumer_key' => ['slocloud_lti_key'],
            'lti.consumer_secret' => ['slocloud_lti_secret'],
            'emergency_admin.username' => ['sloadmin@example.com', 'sloadmin@breakglass.local'],
            'emergency_admin.password' => ['slopass'],
        ];
        foreach ($publicDefaults as $field => $defaults) {
            $value = strtolower(trim((string)$this->getRaw($field, '')));
            if ($value !== '' && in_array($value, $defaults, true)) {
                throw new RuntimeException("Insecure placeholder/default detected in {$field}.");
            }
        }

        $emergencyEnabled = filter_var($this->getRaw('emergency_admin.enabled', false), FILTER_VALIDATE_BOOLEAN);
        $emergencyUsername = trim((string)$this->getRaw('emergency_admin.username', ''));
        $emergencyPassword = (string)$this->getRaw('emergency_admin.password', '');
        if ($emergencyEnabled && $emergencyUsername === '') {
            throw new RuntimeException('Emergency access requires a username.');
        }
        if ($emergencyEnabled && password_get_info($emergencyPassword)['algo'] === null) {
            throw new RuntimeException('Emergency access requires a password hash.');
        }
        if (!filter_var($this->getRaw('lti.validate_signatures', true), FILTER_VALIDATE_BOOLEAN)
            && strtolower((string)getenv('SLOCLOUD_ENV')) !== 'test') {
            throw new RuntimeException('LTI signature validation can be disabled only in the test environment.');
        }

        $keyPaths = [];
        $environmentPath = getenv('SLOCloud_KEY_PATH');
        if (is_string($environmentPath) && $environmentPath !== '') $keyPaths[] = $environmentPath;
        $keyPaths[] = dirname($this->configPath) . '/key.yaml';
        $keyPaths[] = dirname(dirname($this->configPath)) . '/key.yaml';
        foreach (array_unique($keyPaths) as $keyPath) {
            if (!is_file($keyPath)) continue;
            $keyData = $this->parseYaml((string)file_get_contents($keyPath), $keyPath);
            $configKey = (string)($keyData['encryption_key'] ?? '');
            $studentKey = (string)($keyData['student_encryption_key'] ?? '');
            // Optional (SEC-008): absent is a supported state, so it is excluded
            // from the format/all-zero checks below unless actually set.
            $lookupKey = (string)($keyData['student_lookup_key'] ?? '');
            $allKeys = ['encryption_key' => $configKey, 'student_encryption_key' => $studentKey];
            if ($lookupKey !== '') {
                $allKeys['student_lookup_key'] = $lookupKey;
            }
            foreach ($allKeys as $name => $key) {
                if ($key !== '' && !preg_match('/^[0-9a-f]{64}$/i', $key)) {
                    throw new RuntimeException("Invalid {$name} in key configuration.");
                }
                if (preg_match('/^0{64}$/', $key)) {
                    throw new RuntimeException("All-zero {$name} is not permitted.");
                }
            }
            if ($configKey !== '' && hash_equals(strtolower($configKey), strtolower($studentKey))) {
                throw new RuntimeException('Configuration and student encryption keys must be different.');
            }
            // Reusing a key across purposes lets compromise of one recompute
            // the other; each purpose gets its own key or none at all.
            if ($lookupKey !== '' && $configKey !== '' && hash_equals(strtolower($configKey), strtolower($lookupKey))) {
                throw new RuntimeException('Configuration and student lookup keys must be different.');
            }
            if ($lookupKey !== '' && $studentKey !== '' && hash_equals(strtolower($studentKey), strtolower($lookupKey))) {
                throw new RuntimeException('Student encryption and student lookup keys must be different.');
            }
        }

        if (strtolower((string)getenv('SLOCLOUD_ENV')) === 'production') {
            if (filter_var($this->getRaw('app.debug_mode', false), FILTER_VALIDATE_BOOLEAN)
                || $this->getRaw('app.php_error_mode', 'logged') === 'onscreen') {
                throw new RuntimeException('Debug or onscreen error output is not permitted in production.');
            }
        }
    }
    
    /**
     * Merge template defaults with user config
     * 
     * Ensures all fields from config.yaml.template are available,
     * even if they're missing from the user's config.yaml file.
     * User values always take precedence over template defaults.
     * 
     * @return void
     */
    private function mergeTemplateDefaults(): void
    {
        $templatePath = dirname($this->configPath) . '/config.yaml.template';
        
        if (!file_exists($templatePath)) {
            // No template file - skip merge
            return;
        }
        
        $content = file_get_contents($templatePath);
        if ($content === false) {
            // Cannot read template - skip merge
            return;
        }
        
        // Parse template (this will include default values)
        $templateConfig = $this->parseYaml($content, $templatePath);
        
        // Merge: user config takes precedence over template defaults
        $this->config = $this->arrayMergeRecursive($templateConfig, $this->config);
    }
    
    /**
     * Recursively merge arrays (right array takes precedence)
     * 
     * Unlike array_merge_recursive, this properly handles:
     * - Non-array values (right wins)
     * - Empty strings (right wins if set)
     * - Numeric keys (append rather than overwrite)
     * 
     * @param array $array1 Base array (defaults)
     * @param array $array2 Override array (user values)
     * @return array Merged array
     */
    private function arrayMergeRecursive(array $array1, array $array2): array
    {
        $merged = $array1;
        
        foreach ($array2 as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                // Both are arrays - merge recursively
                $merged[$key] = $this->arrayMergeRecursive($merged[$key], $value);
            } else {
                // Right value wins (user config overrides template default)
                $merged[$key] = $value;
            }
        }
        
        return $merged;
    }
    
    /**
     * Load master encryption key from key.yaml
     * 
     * Searches for key.yaml in multiple locations (in order):
     * 1. Path specified in SLOCloud_KEY_PATH environment variable
     * 2. Same directory as config.yaml
     * 3. One directory up from config.yaml (outside web root)
     * 
     * @return string Encryption key (64 hex chars) or empty string
     */
    private function loadMasterKey(): string
    {
        $keyPaths = [];
        
        // 1. Environment variable override
        $envPath = getenv('SLOCloud_KEY_PATH');
        if ($envPath !== false && !empty($envPath)) {
            $keyPaths[] = $envPath;
        }
        
        // 2. Same directory as config.yaml
        $configDir = dirname($this->configPath);
        $keyPaths[] = $configDir . '/key.yaml';
        
        // 3. One directory up (outside web root if config is in src/config/)
        $keyPaths[] = dirname($configDir) . '/key.yaml';
        
        // Try each path
        foreach ($keyPaths as $keyPath) {
            if (!file_exists($keyPath)) {
                continue;
            }
            
            $content = file_get_contents($keyPath);
            if ($content === false) {
                continue;
            }
            
            // Parse key.yaml (simple format: encryption_key: value)
            $keyData = $this->parseYaml($content, $keyPath);
            
            if (isset($keyData['encryption_key'])) {
                $key = $keyData['encryption_key'];
                
                // Validate key format
                if (preg_match('/^[0-9a-f]{64}$/i', $key) && !preg_match('/^0{64}$/', $key)) {
                    return $key;
                }
            }
        }
        
        // TODO: KPS (Key Protection Service) support
        // When encryption_key_source = 'kps', fetch the master key from an external
        // key management endpoint instead of a local file. Planned implementation:
        //   $kpsEndpoint = $this->getRaw('security.kps.endpoint', '');
        //   $kpsToken    = $this->getRaw('security.kps.token', '');
        //   $kpsKeyId    = $this->getRaw('security.kps.key_id', '');
        //   HTTP GET $kpsEndpoint with Authorization: Bearer $kpsToken,
        //   expect JSON response {"key": "<64-hex>"}, validate and return.
        // See docs/concepts/CONFIGURATION.md - "Key Protection Service" section.

        // Fallback: key stored directly in config.yaml (development / backwards compat)
        return $this->getRaw('security.encryption_key', '');
    }
    
    private static function requireYamlExtension(): void
    {
        if (!function_exists('yaml_parse') || !function_exists('yaml_emit')) {
            throw new RuntimeException('The required PHP YAML extension (ext-yaml) is not loaded.');
        }
    }

    /**
     * Parse a YAML document and require a mapping at its root.
     */
    private function parseYaml(string $yaml, string $source = 'YAML document'): array
    {
        set_error_handler(static function (int $severity, string $message) use ($source): never {
            throw new RuntimeException("Invalid YAML in {$source}: {$message}");
        });

        try {
            $parsed = \yaml_parse($yaml);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException("Invalid YAML in {$source}.", 0, $e);
        } finally {
            restore_error_handler();
        }

        if (!is_array($parsed) || array_is_list($parsed)) {
            throw new RuntimeException("Invalid YAML in {$source}: the document root must be a mapping.");
        }

        return $parsed;
    }

    /**
     * Read the field schema embedded in config.yaml.template metadata.
     */
    public static function schemaFromTemplate(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException("Cannot read configuration schema: {$path}");
        }

        $schema = [];
        $section = null;
        $metadata = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*# @(\w+):\s*(.*)$/', $line, $matches)) {
                $metadata[$matches[1]] = $matches[2];
                continue;
            }
            if (!preg_match('/^(\s*)([A-Za-z_][A-Za-z0-9_]*):(?:\s.*)?$/', $line, $matches)) {
                continue;
            }

            $indent = strlen($matches[1]);
            if ($indent === 0) {
                $section = $matches[2];
                $metadata = [];
            } elseif ($indent === 2 && $section !== null && $metadata !== []) {
                $schema[$section . '.' . $matches[2]] = $metadata;
                $metadata = [];
            }
        }

        return $schema;
    }

    private function validateSchema(array $config): void
    {
        $templatePath = dirname($this->configPath) . '/config.yaml.template';
        $schema = self::schemaFromTemplate($templatePath);
        foreach ($schema as $path => $metadata) {
            $value = $this->valueAtPath($config, $path, $found);
            if (!$found) {
                continue; // Older deployments receive missing fields from template defaults.
            }

            $required = ($metadata['required'] ?? 'false') === 'true';
            if ($value === null) {
                if ($required) {
                    throw new RuntimeException("Configuration field {$path} is required.");
                }
                continue;
            }

            $type = $metadata['type'] ?? 'string';
            $validType = match ($type) {
                'boolean' => is_bool($value),
                'integer' => is_int($value),
                default => is_string($value),
            };
            if (!$validType) {
                throw new RuntimeException("Configuration field {$path} must be {$type}.");
            }
            if ($required && is_string($value) && trim($value) === '') {
                throw new RuntimeException("Configuration field {$path} is required.");
            }
            if ($type === 'email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException("Configuration field {$path} must be a valid email address.");
            }
            if ($type === 'timezone' && !in_array($value, timezone_identifiers_list(), true)) {
                throw new RuntimeException("Configuration field {$path} must be a valid timezone.");
            }
            if ($type === 'select' && isset($metadata['options'])) {
                $options = array_map(
                    static fn(string $option): string => explode('|', $option, 2)[0],
                    explode(',', $metadata['options'])
                );
                if (!in_array($value, $options, true)) {
                    throw new RuntimeException("Configuration field {$path} has an unsupported value.");
                }
            }
            if (isset($metadata['validation'])) {
                $this->validateFieldConstraint($path, $value, $metadata['validation']);
            }
        }
    }

    private function normalizeLegacySchemaTypes(array &$config): void
    {
        $templatePath = dirname($this->configPath) . '/config.yaml.template';
        foreach (self::schemaFromTemplate($templatePath) as $path => $metadata) {
            if (($metadata['type'] ?? '') !== 'boolean') {
                continue;
            }
            $parts = explode('.', $path);
            $current = &$config;
            foreach ($parts as $part) {
                if (!is_array($current) || !array_key_exists($part, $current)) {
                    unset($current);
                    continue 2;
                }
                $current = &$current[$part];
            }
            if ($current === 'true' || $current === 'false') {
                $current = $current === 'true';
            }
            unset($current);
        }
    }

    private function valueAtPath(array $config, string $path, ?bool &$found): mixed
    {
        $current = $config;
        foreach (explode('.', $path) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                $found = false;
                return null;
            }
            $current = $current[$part];
        }
        $found = true;
        return $current;
    }

    private function validateFieldConstraint(string $path, mixed $value, string $constraint): void
    {
        if (is_int($value)) {
            if (preg_match('/(?:^|,)min=(\d+)/', $constraint, $match) && $value < (int)$match[1]) {
                throw new RuntimeException("Configuration field {$path} is below its minimum.");
            }
            if (preg_match('/(?:^|,)max=(\d+)/', $constraint, $match) && $value > (int)$match[1]) {
                throw new RuntimeException("Configuration field {$path} exceeds its maximum.");
            }
            return;
        }
        if (is_string($value) && str_starts_with($constraint, '^')) {
            $pattern = '~' . str_replace('~', '\\~', $constraint) . '~D';
            if (preg_match($pattern, $value) !== 1) {
                throw new RuntimeException("Configuration field {$path} has an invalid format.");
            }
        }
    }
    
    /**
     * Set a nested array value using a path
     * 
     * @param array $array Array to modify
     * @param array $path Path to the value
     * @param mixed $value Value to set
     */
    private function setNestedValue(array &$array, array $path, mixed $value): void
    {
        $current = &$array;
        
        foreach ($path as $key) {
            if (!isset($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }
        
        $current = $value;
    }
    
    /**
     * Get a configuration value (auto-decrypts if encrypted)
     * 
     * Special handling for student_encryption_key which is also in key.yaml.
     * 
     * @param string $key Dot-notation key (e.g., 'database.host')
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // Special handling for student encryption key (load from key.yaml)
        if ($key === 'security.student_encryption_key') {
            return $this->loadStudentEncryptionKey();
        }
        if ($key === 'security.student_lookup_key') {
            return $this->loadStudentLookupKey();
        }

        $value = $this->getRaw($key, $default);
        
        // Auto-decrypt sensitive fields. An ENC value is never returned as though
        // it were a usable secret when the key is missing or decryption fails.
        if (in_array($key, self::ENCRYPTED_FIELDS, true) && is_string($value)) {
            // Check for ENC: prefix and strip it
            if (str_starts_with($value, 'ENC:')) {
                if ($this->encryption === null) {
                    throw new RuntimeException("Encrypted configuration field {$key} cannot be decrypted.");
                }
                $encryptedValue = substr($value, 4); // Remove "ENC:" prefix
                try {
                    return $this->encryption->decrypt($encryptedValue);
                } catch (\Exception $e) {
                    throw new RuntimeException("Encrypted configuration field {$key} cannot be decrypted.", 0, $e);
                }
            }
            // Legacy: Check if value is already encrypted (no prefix)
            elseif ($this->encryption !== null && $this->encryption->isEncrypted($value)) {
                try {
                    return $this->encryption->decrypt($value);
                } catch (\Exception $e) {
                    throw new RuntimeException("Encrypted configuration field {$key} cannot be decrypted.", 0, $e);
                }
            }
        }
        
        return $value;
    }
    
    /**
     * Load student encryption key from key.yaml
     * 
     * This is a separate key from the master encryption_key for FERPA compliance.
     * Both keys should be in key.yaml (not config.yaml).
     * 
     * @return string Student encryption key (64 hex chars) or empty string
     */
    private function loadStudentEncryptionKey(): string
    {
        $keyPaths = [];
        
        // 1. Environment variable override
        $envPath = getenv('SLOCloud_KEY_PATH');
        if ($envPath !== false && !empty($envPath)) {
            $keyPaths[] = $envPath;
        }
        
        // 2. Same directory as config.yaml
        $configDir = dirname($this->configPath);
        $keyPaths[] = $configDir . '/key.yaml';
        
        // 3. One directory up
        $keyPaths[] = dirname($configDir) . '/key.yaml';
        
        // Try each path
        foreach ($keyPaths as $keyPath) {
            if (!file_exists($keyPath)) {
                continue;
            }
            
            $content = file_get_contents($keyPath);
            if ($content === false) {
                continue;
            }
            
            $keyData = $this->parseYaml($content, $keyPath);
            
            if (isset($keyData['student_encryption_key'])) {
                $key = $keyData['student_encryption_key'];
                
                // Validate key format
                if (preg_match('/^[0-9a-f]{64}$/i', $key) && !preg_match('/^0{64}$/', $key)) {
                    return $key;
                }
            }
        }
        
        // Fallback: Try config.yaml (backwards compatibility)
        return $this->getRaw('security.student_encryption_key', '');
    }

    /**
     * Load the student C-number lookup key from key.yaml (SEC-008).
     *
     * Unlike the two keys above, absence is a supported state, not an error:
     * an install with no lookup key configured falls back to the unkeyed
     * lookup hash it has always used, so upgrading this code does not require
     * every existing install to migrate immediately. See
     * StudentEncryption::hashCNumber() for the fallback and
     * docs/concepts/SECURITY.md for why closing that fallback requires a
     * versioned migration rather than just adding this key.
     *
     * @return string 64-hex-char key, or '' if not configured
     */
    private function loadStudentLookupKey(): string
    {
        $keyPaths = [];

        $envPath = getenv('SLOCloud_KEY_PATH');
        if ($envPath !== false && !empty($envPath)) {
            $keyPaths[] = $envPath;
        }

        $configDir = dirname($this->configPath);
        $keyPaths[] = $configDir . '/key.yaml';
        $keyPaths[] = dirname($configDir) . '/key.yaml';

        foreach ($keyPaths as $keyPath) {
            if (!file_exists($keyPath)) {
                continue;
            }

            $content = file_get_contents($keyPath);
            if ($content === false) {
                continue;
            }

            $keyData = $this->parseYaml($content, $keyPath);

            if (isset($keyData['student_lookup_key'])) {
                $key = $keyData['student_lookup_key'];

                if (preg_match('/^[0-9a-f]{64}$/i', $key) && !preg_match('/^0{64}$/', $key)) {
                    return $key;
                }
            }
        }

        // Fallback: Try config.yaml (backwards compatibility), then absent.
        return (string)$this->getRaw('security.student_lookup_key', '');
    }
    
    /**
     * Get a raw configuration value without decryption
     * 
     * @param string $key Dot-notation key (e.g., 'database.host')
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    public function getRaw(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }
    
    /**
     * Get all configuration
     * 
     * @return array
     */
    public function all(): array
    {
        return $this->config;
    }
    
    /**
     * Get all configuration with sensitive fields decrypted
     * 
     * @return array Configuration array with decrypted passwords
     */
    public function allDecrypted(): array
    {
        $config = $this->config;
        
        // Decrypt each sensitive field
        foreach (self::ENCRYPTED_FIELDS as $field) {
            $value = $this->get($field, null);
            if ($value !== null) {
                // Set decrypted value in array
                $keys = explode('.', $field);
                $this->setNestedValueInArray($config, $keys, $value);
            }
        }
        
        return $config;
    }
    
    /**
     * Helper to set nested array value
     * 
     * @param array $array Array to modify
     * @param array $keys Path to value
     * @param mixed $value Value to set
     */
    private function setNestedValueInArray(array &$array, array $keys, mixed $value): void
    {
        $current = &$array;
        $lastKey = array_pop($keys);
        
        foreach ($keys as $key) {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }
        
        $current[$lastKey] = $value;
    }
    
    /**
     * Check if a key exists
     * 
     * @param string $key Dot-notation key
     * @return bool
     */
    public function has(string $key): bool
    {
        $keys = explode('.', $key);
        $value = $this->config;
        
        foreach ($keys as $k) {
            if (!isset($value[$k])) {
                return false;
            }
            $value = $value[$k];
        }
        
        return true;
    }
    
    /**
     * Set a configuration value
     * 
     * @param string $key Dot-notation key (e.g., 'database.host')
     * @param mixed $value Value to set
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $keys = explode('.', $key);
        $this->setNestedValue($this->config, $keys, $value);
    }
    
    /**
     * Save configuration to YAML file
     * 
     * @throws RuntimeException If file cannot be written
     * @return bool
     */
    public function save(): bool
    {
        foreach (self::ENCRYPTED_FIELDS as $field) {
            $value = $this->getRaw($field, null);
            if (!is_string($value) || $value === '' || str_starts_with($value, 'ENC:')) {
                continue;
            }
            if ($this->encryption === null) {
                throw new RuntimeException("Cannot encrypt configuration field {$field}.");
            }
            $this->set($field, 'ENC:' . $this->encryption->encrypt($value));
        }

        $emergencyEnabled = filter_var($this->getRaw('emergency_admin.enabled', false), FILTER_VALIDATE_BOOLEAN);
        $emergencyPassword = (string)$this->getRaw('emergency_admin.password', '');
        if ($emergencyEnabled && password_get_info($emergencyPassword)['algo'] === null) {
            throw new RuntimeException('Emergency access requires a password hash.');
        }

        $this->validateSchema($this->config);
        $yaml = \yaml_emit($this->config, YAML_UTF8_ENCODING, YAML_LN_BREAK);
        if (!is_string($yaml)) {
            throw new RuntimeException('Cannot serialize configuration as YAML.');
        }
        $directory = dirname($this->configPath);
        $temporary = tempnam($directory, '.config-');
        if ($temporary === false || file_put_contents($temporary, $yaml, LOCK_EX) === false) {
            if (is_string($temporary)) @unlink($temporary);
            throw new RuntimeException("Cannot write configuration file: {$this->configPath}");
        }
        @chmod($temporary, 0600);
        $backup = $this->configPath . '.bak';
        if (is_file($this->configPath) && !@copy($this->configPath, $backup)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot create configuration backup.');
        }
        @chmod($backup, 0600);
        if (!@rename($temporary, $this->configPath)) {
            @unlink($temporary);
            throw new RuntimeException('Cannot atomically replace configuration file.');
        }
        @chmod($this->configPath, 0600);
        
        return true;
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization
     */
    public function __wakeup()
    {
        throw new RuntimeException('Cannot unserialize singleton');
    }
}
