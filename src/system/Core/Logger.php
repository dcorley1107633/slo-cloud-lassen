<?php
/**
 * SLOCloud Logger Class
 * 
 * Provides centralized logging for errors, security events, and audit trails.
 * Supports both file-based and database logging based on configuration.
 */

declare(strict_types=1);

namespace SLOCloud\Core;

use PDO;
use Exception;

class Logger
{
    private static ?Logger $instance = null;
    private array $config;
    private ?PDO $db = null;
    private string $logDirectory;
    private string $dbPrefix;
    
    // Log levels
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const CRITICAL = 'critical';
    
    // Event types
    public const EVENT_LOGIN_SUCCESS = 'login_success';
    public const EVENT_LOGIN_FAILED = 'login_failed';
    public const EVENT_LOGOUT = 'logout';
    public const EVENT_SESSION_ENDED = 'session_ended';
    public const EVENT_PERMISSION_DENIED = 'permission_denied';
    public const EVENT_DATA_EXPORT = 'data_export';
    public const EVENT_ADMIN_ACTION = 'admin_action';
    public const EVENT_LTI_LAUNCH = 'lti_launch';
    public const EVENT_PASSWORD_CHANGE = 'password_change';

    /** Operational-log fields are allowlisted; everything else is redacted. */
    private const SAFE_CONTEXT_FIELDS = [
        'type', 'code', 'file', 'line', 'event_type', 'is_threat', 'metadata',
        'user_id', 'actor_id', 'changed_by', 'ip', 'reason', 'result', 'method',
        'flag', 'status', 'action', 'table', 'route', 'role', 'key_type', 'dir',
        'count', 'row_count', 'record_count', 'source_report', 'execution_time',
    ];
    
    /**
     * Private constructor for singleton pattern
     */
    private function __construct(array $config, ?PDO $db = null)
    {
        $this->config = $config;
        $this->db = $db;
        $this->logDirectory = Security::logDirectory($config['logging']['log_directory'] ?? null, dirname(__DIR__, 2));
        $this->dbPrefix = $config['database']['prefix'] ?? '';
        
        // Ensure log directory exists
        if (!is_dir($this->logDirectory)
            && !mkdir($this->logDirectory, 0700, true)
            && !is_dir($this->logDirectory)) {
            throw new \RuntimeException('Unable to create protected log storage.');
        }
        @chmod($this->logDirectory, 0700);
    }
    
    /**
     * Get Logger instance (singleton)
     */
    public static function getInstance(array $config = [], ?PDO $db = null): Logger
    {
        if (self::$instance === null) {
            self::$instance = new Logger($config, $db);
        }
        return self::$instance;
    }
    
    /**
     * Set database connection for database logging
     */
    public function setDatabase(PDO $db): void
    {
        $this->db = $db;
    }
    
    /**
     * Log an error to error_log table and/or file
     */
    public function error(
        string $message,
        string $errorType = 'APPLICATION_ERROR',
        ?string $errorCode = null,
        ?string $stackTrace = null,
        ?string $filePath = null,
        ?int $lineNumber = null,
        ?int $userFk = null,
        string $severity = self::ERROR
    ): void {
        // Log to file
        if ($this->config['logging']['log_to_file'] ?? true) {
            $this->logToFile('error', $severity, $message, [
                'type' => $errorType,
                'code' => $errorCode,
                'file' => $filePath,
                'line' => $lineNumber
            ]);
        }
        
        // Log to database
        if (($this->config['logging']['log_to_database'] ?? true) && $this->db !== null) {
            $this->logErrorToDatabase(
                $errorType,
                $message,
                $errorCode,
                $stackTrace,
                $filePath,
                $lineNumber,
                $userFk,
                $severity
            );
        }
    }
    
    /**
     * Log a security event to security_log table and/or file
     */
    public function security(
        string $eventType,
        string $description,
        ?int $userFk = null,
        ?string $username = null,
        string $severity = 'info',
        bool $isThreat = false,
        ?array $metadata = null
    ): void {
        $username = self::tokenizeIdentifier($username);
        $metadata = $metadata === null ? null : self::sanitizeContext($metadata);
        // Log to file
        if ($this->config['logging']['log_to_file'] ?? true) {
            $this->logToFile('security', $severity, $description, [
                'event_type' => $eventType,
                'username' => $username,
                'is_threat' => $isThreat,
                'metadata' => $metadata
            ]);
        }
        
        // Log to database
        if (($this->config['logging']['security_log_enabled'] ?? true) && $this->db !== null) {
            $this->logSecurityToDatabase(
                $eventType,
                $description,
                $userFk,
                $username,
                $severity,
                $isThreat,
                $metadata
            );
        }
    }
    
    /**
     * Log an audit trail entry to audit_log table
     */
    public function audit(
        string $tableName,
        int $recordPk,
        string $action,
        ?int $changedByFk = null,
        ?array $changedData = null,
        ?array $oldData = null
    ): void {
        // Audit logs only go to database
        if (($this->config['logging']['audit_enabled'] ?? true) && $this->db !== null) {
            $databaseAction = self::databaseAuditAction($action);
            if (strtoupper($action) !== $databaseAction) {
                $changedData = ['audit_event' => $action] + ($changedData ?? []);
            }
            $this->logAuditToDatabase(
                $tableName,
                $recordPk,
                $databaseAction,
                $changedByFk,
                $changedData,
                $oldData
            );
        }
        
        // Also log to file for backup
        if ($this->config['logging']['log_to_file'] ?? true) {
            $this->logToFile('audit', self::INFO, "Audit: $action on $tableName($recordPk)", [
                'changed_by' => $changedByFk,
                'action' => $action,
                'table' => $tableName,
            ]);
        }
    }

    /**
     * Keep the database value compatible with both shipped schemas while the
     * caller's more specific event name is retained in changed_data.
     */
    private static function databaseAuditAction(string $action): string
    {
        return match (strtolower(trim($action))) {
            'insert', 'create', 'initialize', 'duplicate' => 'INSERT',
            'delete', 'remove' => 'DELETE',
            default => 'UPDATE',
        };
    }

    /**
     * Log a general informational message
     */
    public function info(string $message, array $context = []): void
    {
        if ($this->config['logging']['log_to_file'] ?? true) {
            $this->logToFile('app', self::INFO, $message, $context);
        }
    }
    
    /**
     * Log a debug message
     */
    public function debug(string $message, array $context = []): void
    {
        // Only log debug messages if debug mode is enabled
        if (($this->config['app']['debug'] ?? false) && ($this->config['logging']['log_to_file'] ?? true)) {
            $this->logToFile('debug', self::DEBUG, $message, $context);
        }
    }
    
    /**
     * Log a warning message
     */
    public function warning(string $message, array $context = []): void
    {
        if ($this->config['logging']['log_to_file'] ?? true) {
            $this->logToFile('app', self::WARNING, $message, $context);
        }
    }
    
    /**
     * Clean up old log files and database entries
     */
    public function cleanup(int $daysToKeep = 90): void
    {
        $maxAge = $this->config['logging']['max_log_age_days'] ?? $daysToKeep;
        
        // Clean up file logs
        $this->cleanupFileLogs($maxAge);
        
        // Clean up database logs
        if ($this->db !== null) {
            $this->cleanupDatabaseLogs($maxAge);
        }
    }
    
    /**
     * Log to file
     */
    private function logToFile(string $category, string $level, string $message, array $context = []): void
    {
        try {
            $message = self::redactMessage($message);
            $context = self::sanitizeContext($context);
            $timestamp = date('Y-m-d H:i:s');
            $logFile = $this->logDirectory . DIRECTORY_SEPARATOR . $category . '_' . date('Y-m-d') . '.log';
            
            $logEntry = sprintf(
                "[%s] [%s] %s %s\n",
                $timestamp,
                strtoupper($level),
                $message,
                !empty($context) ? json_encode($context, JSON_UNESCAPED_SLASHES) : ''
            );
            
            file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        } catch (Exception $e) {
            // Fallback - write to PHP error log
            error_log("Logger failed to write to file: " . $e->getMessage());
            error_log("Original message: $message");
        }
    }
    
    /**
     * Log error to database
     */
    private function logErrorToDatabase(
        string $errorType,
        string $message,
        ?string $errorCode,
        ?string $stackTrace,
        ?string $filePath,
        ?int $lineNumber,
        ?int $userFk,
        string $severity
    ): void {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO {$this->dbPrefix}error_log (
                    error_type, error_message, error_code, stack_trace, 
                    file_path, line_number, user_fk, request_uri, 
                    request_method, request_data, ip_address, user_agent, severity
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            
            $requestUri = self::requestPath();
            $requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
            // Raw request data can contain credentials, assertions, CSRF tokens,
            // OAuth signatures, and student PII. It is intentionally never logged.
            $requestData = null;
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([
                $errorType,
                $message,
                $errorCode,
                $stackTrace,
                $filePath,
                $lineNumber,
                $userFk,
                $requestUri,
                $requestMethod,
                $requestData,
                $ipAddress,
                $userAgent,
                $severity
            ]);
        } catch (Exception $e) {
            // Fallback to file logging
            $this->logToFile('error', self::CRITICAL, "Failed to log error to database: " . $e->getMessage());
        }
    }
    
    /**
     * Log security event to database
     */
    private function logSecurityToDatabase(
        string $eventType,
        string $description,
        ?int $userFk,
        ?string $username,
        string $severity,
        bool $isThreat,
        ?array $metadata
    ): void {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO {$this->dbPrefix}security_log (
                    event_type, event_description, user_fk, username,
                    ip_address, user_agent, request_uri, severity, 
                    is_threat, metadata
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $requestUri = self::requestPath();
            $isThreatInt = $isThreat ? 1 : 0;
            $metadataJson = $metadata ? json_encode(self::sanitizeContext($metadata)) : null;
            
            $stmt->execute([
                $eventType,
                $description,
                $userFk,
                $username,
                $ipAddress,
                $userAgent,
                $requestUri,
                $severity,
                $isThreatInt,
                $metadataJson
            ]);
        } catch (Exception $e) {
            // Fallback to file logging
            $this->logToFile('security', self::CRITICAL, "Failed to log security event to database: " . $e->getMessage());
        }
    }

    public static function sanitizeContext(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            $key = (string)$key;
            if (!in_array($key, self::SAFE_CONTEXT_FIELDS, true)) {
                $clean[$key] = '[REDACTED]';
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = self::sanitizeContext($value);
            } elseif (is_string($value)) {
                $clean[$key] = self::redactMessage($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            } else {
                $clean[$key] = '[REDACTED]';
            }
        }
        return $clean;
    }

    private static function redactMessage(string $message): string
    {
        $patterns = [
            '/\b(password|passwd|secret|token|api[_-]?key|authorization|cookie|csrf|oauth_signature)\s*[:=]\s*[^\s,;]+/i',
            '/\bENC:[A-Za-z0-9+\/=._-]+/i',
        ];
        return (string)preg_replace($patterns, '$1=[REDACTED]', $message);
    }

    private static function tokenizeIdentifier(?string $value): ?string
    {
        if ($value === null || $value === '' || $value === 'anonymous' || $value === 'unknown') {
            return $value;
        }
        return 'id:' . substr(hash('sha256', $value), 0, 16);
    }

    private static function requestPath(): ?string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? null;
        if (!is_string($uri) || $uri === '') {
            return null;
        }
        $path = parse_url($uri, PHP_URL_PATH);
        return is_string($path) ? $path : null;
    }
    
    /**
     * Log audit trail to database
     */
    private function logAuditToDatabase(
        string $tableName,
        int $recordPk,
        string $action,
        ?int $changedByFk,
        ?array $changedData,
        ?array $oldData
    ): void {
        try {
            $stmt = $this->db->prepare(
                "INSERT INTO {$this->dbPrefix}audit_log (
                    table_name, record_pk, action, changed_by_fk,
                    changed_data, old_data, ip_address, user_agent
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            
            $changedDataJson = $changedData ? json_encode($changedData) : null;
            $oldDataJson = $oldData ? json_encode($oldData) : null;
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $stmt->execute([
                $tableName,
                $recordPk,
                $action,
                $changedByFk,
                $changedDataJson,
                $oldDataJson,
                $ipAddress,
                $userAgent
            ]);
        } catch (Exception $e) {
            // Fallback to file logging
            $this->logToFile('audit', self::CRITICAL, "Failed to log audit to database: " . $e->getMessage());
        }
    }
    
    /**
     * Clean up old file logs
     */
    private function cleanupFileLogs(int $daysToKeep): void
    {
        try {
            $files = glob($this->logDirectory . DIRECTORY_SEPARATOR . '*.log');
            $cutoffTime = time() - ($daysToKeep * 86400);
            
            foreach ($files as $file) {
                if (filemtime($file) < $cutoffTime) {
                    unlink($file);
                }
            }
        } catch (Exception $e) {
            $this->logToFile('app', self::WARNING, "Failed to cleanup old log files: " . $e->getMessage());
        }
    }
    
    /**
     * Clean up old database logs
     */
    private function cleanupDatabaseLogs(int $daysToKeep): void
    {
        try {
            $cutoffDate = date('Y-m-d H:i:s', time() - ($daysToKeep * 86400));
            
            // Clean audit logs
            $stmt = $this->db->prepare("DELETE FROM {$this->dbPrefix}audit_log WHERE created_at < ?");
            $stmt->execute([$cutoffDate]);
            
            // Clean error logs (only resolved errors)
            $stmt = $this->db->prepare("DELETE FROM {$this->dbPrefix}error_log WHERE created_at < ? AND is_resolved = 1");
            $stmt->execute([$cutoffDate]);
            
            // Clean security logs (only non-threats)
            $stmt = $this->db->prepare("DELETE FROM {$this->dbPrefix}security_log WHERE created_at < ? AND is_threat = 0");
            $stmt->execute([$cutoffDate]);
        } catch (Exception $e) {
            $this->logToFile('app', self::WARNING, "Failed to cleanup database logs: " . $e->getMessage());
        }
    }
}
