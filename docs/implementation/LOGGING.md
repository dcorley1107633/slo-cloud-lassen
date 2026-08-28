# SLOCloud Logging Guide

This guide explains how to use SLOCloud's comprehensive logging system for audit trails, error tracking, and security monitoring.

## Overview

SLOCloud provides three types of logging:

1. **Audit Logs** - Track all data changes for compliance
2. **Error Logs** - Record application errors with full context
3. **Security Logs** - Monitor authentication and authorization events

All logs support both database storage (for querying/reporting) and file storage (for backup/external analysis).

## Quick Start

```php
use SLOCloud\Core\Logger;

// Get logger instance (singleton)
$logger = Logger::getInstance($config, $db);

// Log an error
$logger->error('Database connection failed', 'DB_ERROR');

// Log a security event
$logger->security(Logger::EVENT_LOGIN_FAILED, 'Invalid password');

// Log a data change
$logger->audit('users', $userPk, 'UPDATE', $changedBy, $newData, $oldData);
```

## Logger Initialization

### Basic Initialization

```php
use SLOCloud\Core\Logger;

// Initialize with config array
$config = [
    'logging' => [
        'enabled' => true,
        'log_to_database' => true,
        'log_to_file' => true,
        'log_directory' => 'logs',
        'max_log_age_days' => 90,
        'audit_enabled' => true,
        'security_log_enabled' => true
    ],
    'app' => [
        'debug' => false
    ]
];

// Get singleton instance
$logger = Logger::getInstance($config, $db->getConnection());
```

### Set Database Connection Later

```php
$logger = Logger::getInstance($config);
// ... later when database is available
$logger->setDatabase($db->getConnection());
```

## Error Logging

### Basic Error

```php
$logger->error(
    'Failed to process payment',
    'PAYMENT_ERROR'
);
```

### Full Error with Context

```php
try {
    // Some operation
} catch (Exception $e) {
    $logger->error(
        $e->getMessage(),
        'APPLICATION_ERROR',
        $e->getCode(),
        $e->getTraceAsString(),
        $e->getFile(),
        $e->getLine(),
        $currentUserPk,  // Who was logged in
        Logger::CRITICAL
    );
}
```

### Severity Levels

```php
Logger::DEBUG      // Detailed debug information
Logger::INFO       // Informational messages
Logger::WARNING    // Warning messages
Logger::ERROR      // Error conditions
Logger::CRITICAL   // Critical conditions
```

## Security Logging

### Predefined Event Types

```php
Logger::EVENT_LOGIN_SUCCESS       // Successful login
Logger::EVENT_LOGIN_FAILED        // Failed login attempt
Logger::EVENT_LOGOUT              // User logout
Logger::EVENT_PERMISSION_DENIED   // Authorization failure
Logger::EVENT_DATA_EXPORT         // Data export operation
Logger::EVENT_ADMIN_ACTION        // Administrative action
Logger::EVENT_LTI_LAUNCH          // LTI launch attempt
Logger::EVENT_PASSWORD_CHANGE     // Password modification
```

### Login Success

```php
$logger->security(
    Logger::EVENT_LOGIN_SUCCESS,
    "User logged in successfully",
    $userPk,
    $username,
    'info'
);
```

### Failed Login (Potential Threat)

```php
$logger->security(
    Logger::EVENT_LOGIN_FAILED,
    "Failed login attempt with invalid credentials",
    null,                    // No user PK (login failed)
    $attemptedUsername,
    'warning',
    true,                    // Mark as potential threat
    ['attempts' => 5]        // Additional metadata
);
```

### Permission Denial

```php
$logger->security(
    Logger::EVENT_PERMISSION_DENIED,
    "User attempted to access restricted resource: /admin/users",
    $userPk,
    $username,
    'warning',
    false,
    ['resource' => '/admin/users', 'required_role' => 'admin']
);
```

### LTI Launch

```php
$logger->security(
    Logger::EVENT_LTI_LAUNCH,
    "LTI launch from Canvas",
    $userPk,
    $username,
    'info',
    false,
    [
        'consumer_key' => $consumerKey,
        'context_id' => $contextId,
        'resource_link_id' => $resourceLinkId
    ]
);
```

## Audit Logging

Audit logs track data modifications for compliance and forensic analysis.

### INSERT Operation

```php
// After inserting a new user
$logger->audit(
    'users',                           // Table name
    $newUserPk,                        // New record primary key
    'INSERT',                          // Action
    $currentUserPk,                    // Who created it
    [                                  // New data
        'user_id' => 'jdoe',
        'email' => 'jdoe@example.edu',
        'first_name' => 'John',
        'last_name' => 'Doe'
    ],
    null                               // No old data for INSERT
);
```

### UPDATE Operation

```php
// After updating a user's email
$logger->audit(
    'users',
    $userPk,
    'UPDATE',
    $currentUserPk,
    ['email' => 'newemail@example.edu'],  // Changed data
    ['email' => 'oldemail@example.edu']   // Original data
);
```

### DELETE Operation

```php
// Before soft-deleting a record
$oldData = [
    'course_id' => 'CS101',
    'course_name' => 'Introduction to Computer Science',
    'is_active' => true
];

$logger->audit(
    'courses',
    $coursePk,
    'DELETE',
    $currentUserPk,
    ['is_active' => false],  // New state
    $oldData                 // Original data
);
```

## General Logging

### Informational Messages

```php
$logger->info('Application started successfully');
$logger->info('Scheduled job completed', ['records_processed' => 150]);
```

### Warnings

```php
$logger->warning('Disk space low', ['available_mb' => 500]);
$logger->warning('API rate limit approaching', ['requests_remaining' => 10]);
```

### Debug Messages

Debug messages are only logged when `debug` mode is enabled in config.

```php
$logger->debug('Processing assessment', [
    'assessment_pk' => $assessmentPk,
    'student_pk' => $studentPk,
    'slo_pk' => $sloPk
]);
```

## Log Cleanup

### Manual Cleanup

```php
// Clean up logs older than 90 days
$logger->cleanup(90);

// Clean up using config default
$logger->cleanup();
```

### Scheduled Cleanup Script

```powershell
# Run cleanup script
php src/system/scripts/cleanup_logs.php 90
```

### What Gets Cleaned

- **Audit Logs**: All entries older than specified days
- **Error Logs**: Only **resolved** errors older than specified days
- **Security Logs**: Only **non-threat** entries older than specified days
- **File Logs**: All log files older than specified days

## Querying Logs

### Recent Errors

```sql
SELECT 
    error_type,
    error_message,
    severity,
    created_at
FROM error_log
WHERE is_resolved = 0
ORDER BY created_at DESC
LIMIT 50;
```

### Audit Trail for Specific Record

```sql
SELECT 
    action,
    changed_data,
    old_data,
    u.first_name,
    u.last_name,
    a.created_at
FROM audit_log a
LEFT JOIN users u ON a.changed_by_fk = u.users_pk
WHERE a.table_name = 'users' 
  AND a.record_pk = 123
ORDER BY a.created_at DESC;
```

### Failed Login Attempts

```sql
SELECT 
    event_description,
    username,
    ip_address,
    created_at,
    is_threat
FROM security_log
WHERE event_type = 'login_failed'
  AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
ORDER BY created_at DESC;
```

### Security Threats

```sql
SELECT 
    event_type,
    event_description,
    username,
    ip_address,
    metadata,
    created_at
FROM security_log
WHERE is_threat = 1
ORDER BY created_at DESC;
```

## Best Practices

### DO

✓ Log all security-relevant events (logins, permission checks, data exports)
✓ Log all data modifications with before/after values
✓ Include user context (who performed the action)
✓ Use appropriate severity levels
✓ Sanitize sensitive data before logging (no passwords in plaintext)
✓ Run cleanup regularly to manage log size
✓ Monitor for unresolved errors and security threats

### DON'T

✗ Log sensitive data like passwords or tokens
✗ Log excessive debug info in production
✗ Ignore failed logging attempts (fallback to file)
✗ Skip audit logging for compliance-critical operations
✗ Delete threat logs during cleanup

## Error Handling

The Logger class handles its own errors gracefully:

```php
// If database logging fails, falls back to file logging
$logger->error('Something went wrong', 'APP_ERROR');

// If file logging fails, writes to PHP error_log
$logger->info('Message');
```

## Performance Considerations

1. **Batch Operations**: Consider bulk logging for high-volume operations
2. **Async Logging**: For critical path operations, consider queueing logs
3. **Index Usage**: Logging tables are indexed for query performance
4. **Cleanup**: Regular cleanup prevents table bloat

## Integration with Models

### Base Model Pattern

```php
abstract class Model {
    protected Logger $logger;
    
    protected function logAuditTrail(
        string $action,
        int $recordPk,
        ?array $changedData = null,
        ?array $oldData = null
    ): void {
        $this->logger->audit(
            $this->table,
            $recordPk,
            $action,
            $_SESSION['user_pk'] ?? null,
            $changedData,
            $oldData
        );
    }
    
    public function create(array $data): int {
        // Insert record
        $pk = $this->insert($data);
        
        // Log audit trail
        $this->logAuditTrail('INSERT', $pk, $data);
        
        return $pk;
    }
}
```

## File Log Format

```text
[2024-02-14 15:30:45] [ERROR] Database connection failed {"host":"localhost","port":3306}
[2024-02-14 15:31:12] [INFO] User logged in successfully {"username":"admin","user_pk":1}
[2024-02-14 15:32:00] [CRITICAL] Payment processing failed {"transaction_id":"TX123","amount":99.99}
```

## Retention Policies

### Recommended Retention

- **Production**: 90-180 days for compliance
- **Development**: 30 days
- **High-Security**: 1+ years for security logs

### Compliance Considerations

- **FERPA**: Requires audit trails for student data access
- **SOC 2**: Requires security event logging
- **GDPR**: May require user data access logs for 6+ months

## Additional Resources

- [SECURITY.md](../concepts/SECURITY.md) - Security requirements
- [TESTING.md](../concepts/TESTING.md) - Testing error handling
- [setup/README.md](../../src/setup/README.md) - Log configuration
