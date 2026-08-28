<?php
declare(strict_types=1);

namespace SLOCloud\Core;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Database Connection Manager
 * 
 * Provides PDO connection management with singleton pattern.
 * Supports both MySQL and MSSQL databases.
 * All queries must use prepared statements for security.
 * 
 * @package SLOCloud\Core
 */
class Database
{
    private static ?Database $instance = null;
    private ?PDO $connection = null;
    private array $config;
    private string $driver;
    private array $queryLog = [];
    private bool $debugMode = false;
    
    /**
     * Private constructor
     * 
     * @param array $config Database configuration
     */
    private function __construct(array $config)
    {
        $this->config = $config;
        $this->debugMode = defined('DEBUG_MODE') && DEBUG_MODE === true;
        $this->connect();
    }
    
    /**
     * Get singleton instance
     * 
     * @param array|null $config Database configuration (required on first call)
     * @return Database
     * @throws RuntimeException If config missing on first call
     */
    public static function getInstance(?array $config = null): Database
    {
        if (self::$instance === null) {
            if ($config === null) {
                throw new RuntimeException('Database config required on first instantiation');
            }
            self::$instance = new self($config);
        }
        
        return self::$instance;
    }
    
    /**
     * Establish database connection
     * 
     * @throws RuntimeException If connection fails
     */
    private function connect(): void
    {
        $this->driver = $this->config['driver'] ?? 'mysql';
        
        try {
            if ($this->driver === 'mssql' || $this->driver === 'sqlsrv') {
                // Microsoft SQL Server connection with encrypted transport.
                $dsn = sprintf(
                    'sqlsrv:Server=%s,%d;Database=%s;Encrypt=yes;TrustServerCertificate=yes',
                    $this->config['host'],
                    $this->config['port'] ?? 1433,
                    $this->config['name']
                );
            } else {
                // MySQL connection (default)
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    $this->config['host'],
                    $this->config['port'] ?? 3306,
                    $this->config['name'],
                    $this->config['charset'] ?? 'utf8mb4'
                );
            }
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            if ($this->driver === 'mssql' || $this->driver === 'sqlsrv') {
                $options[PDO::SQLSRV_ATTR_ENCODING] = PDO::SQLSRV_ENCODING_UTF8;
            }
            
            $this->connection = new PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                $options
            );
            
            // Set timezone to UTC for MySQL
            if ($this->driver === 'mysql') {
                $this->connection->exec("SET time_zone = '+00:00'");
            }
            
        } catch (PDOException $e) {
            throw new RuntimeException(
                'Database connection failed: ' . $e->getMessage(),
                (int)$e->getCode(),
                $e
            );
        }
    }
    
    /**
     * Get PDO connection
     *
     * @return PDO
     * @throws RuntimeException If connection not established
     */
    public function getConnection(): PDO
    {
        if ($this->connection === null) {
            throw new RuntimeException('Database connection not established');
        }
        
        return $this->connection;
    }
    
    /**
     * Get database driver name
     * 
     * @return string 'mysql' or 'mssql'
     */
    public function getDriver(): string
    {
        return $this->driver;
    }
    
    /**
     * Execute prepared statement with parameters
     * 
     * @param string $sql SQL query with ? placeholders
     * @param array $params Parameters to bind (positional, matching ? in query)
     * @param string $types Optional type hints parameter (ignored, kept for legacy compatibility)
     * @return PDOStatement|bool Query result statement or boolean for non-SELECT queries
     * @throws RuntimeException If query fails
     */
    public function query(string $sql, array $params = [], string $types = ''): mixed
    {
        $conn = $this->getConnection();
        $startTime = microtime(true);
        
        try {
            // If no parameters, execute directly
            if (empty($params)) {
                $result = $conn->query($sql);
                $this->logQuery($sql, [], microtime(true) - $startTime);
                return $result;
            }
            
            // Prepare and execute with parameters
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            $this->logQuery($sql, $params, microtime(true) - $startTime);
            
            return $stmt;
            
        } catch (PDOException $e) {
            $this->logQuery($sql, $params, microtime(true) - $startTime, $e->getMessage());
            $trace = array_map(
                static fn(array $f): string => sprintf(
                    '%s(%d)',
                    $f['file'] ?? '[internal]',
                    $f['line'] ?? 0
                ),
                array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 1, 6)
            );
            error_log(sprintf(
                '[SLOCloud DB ERROR] %s | SQL: %s | Parameter count: %d | Trace: %s',
                $e->getMessage(),
                preg_replace('/\s+/', ' ', $sql),
                count($params),
                implode(' <- ', $trace)
            ));
            throw new RuntimeException(
                'Database operation failed.',
                (int)$e->getCode(),
                $e
            );
        }
    }
    
    /**
     * Get last inserted ID
     * 
     * @return int
     */
    public function getInsertId(): int
    {
        return (int)$this->getConnection()->lastInsertId();
    }
    
    /**
     * Normalize row data from SQL Server (cast numeric strings to proper types)
     * 
     * SQL Server PDO returns all values as strings. This method casts numeric
     * values back to int/float for compatibility with type-strict functions.
     * 
     * @param array|false $row Row from fetch()
     * @return array|false Normalized row with proper types
     */
    public function normalizeRow($row)
    {
        if ($row === false || !is_array($row)) {
            return $row;
        }
        
        // Only do this for SQL Server
        if ($this->driver !== 'mssql' && $this->driver !== 'sqlsrv') {
            return $row;
        }
        
        foreach ($row as $key => $value) {
            if (is_string($value) && is_numeric($value)) {
                // Only cast columns that are provably integer keys or aggregates.
                // Columns like banner_term, term_code, crn, etc. must stay as strings
                // even when their value looks numeric.
                $lowerKey = strtolower((string)$key);
                $isIntegerColumn = str_ends_with($lowerKey, '_pk')
                    || str_ends_with($lowerKey, '_fk')
                    || $lowerKey === 'count'
                    || $lowerKey === 'total'
                    || $lowerKey === 'max_seq'
                    || $lowerKey === 'sequence_num';

                if ($isIntegerColumn) {
                    $row[$key] = (int)$value;
                } elseif (strpos($value, '.') !== false) {
                    // Float columns (e.g. percentage, score)
                    $row[$key] = (float)$value;
                }
                // All other numeric-looking strings stay as strings
            }
        }
        
        return $row;
    }
    
    /**
     * Get number of affected rows from last statement
     * 
     * Note: With PDO, you need to call this on the PDOStatement:
     * $stmt = $db->query(...);
     * $affected = $stmt->rowCount();
     * 
     * This method is kept for compatibility but may not return expected results.
     * 
     * @return int
     * @deprecated Use PDOStatement::rowCount() instead
     */
    public function getAffectedRows(): int
    {
        // PDO doesn't have a connection-level affected rows
        // This is here for backwards compatibility but always returns 0
        return 0;
    }
    
    /**
     * Begin transaction
     * 
     * @return bool
     */
    public function beginTransaction(): bool
    {
        return $this->getConnection()->beginTransaction();
    }
    
    /**
     * Commit transaction
     * 
     * @return bool
     */
    public function commit(): bool
    {
        return $this->getConnection()->commit();
    }
    
    /**
     * Rollback transaction
     * 
     * @return bool
     */
    public function rollback(): bool
    {
        return $this->getConnection()->rollBack();
    }
    
    /**
     * Return the SQL expression for the current timestamp, appropriate for the active driver.
     *
     * MySQL uses NOW(); SQL Server uses GETDATE().
     * Use via PHP string interpolation:  "INSERT INTO ... VALUES (?, {$db->now()})"
     *
     * @return string SQL timestamp expression
     */
    public function now(): string
    {
        return ($this->driver === 'mssql' || $this->driver === 'sqlsrv') ? 'GETDATE()' : 'NOW()';
    }

    /**
     * Return the SQL expression for the current date (no time component).
     *
     * MySQL uses CURDATE(); SQL Server uses CAST(GETDATE() AS DATE).
     * Use via PHP string interpolation:  "SET assessed_date = {$db->curdate()}"
     *
     * @return string SQL date expression
     */
    public function curdate(): string
    {
        return ($this->driver === 'mssql' || $this->driver === 'sqlsrv') ? 'CAST(GETDATE() AS DATE)' : 'CURDATE()';
    }

    /**
     * Return the SQL expression for the current date plus N days.
     *
     * MySQL uses DATE_ADD(CURDATE(), INTERVAL n DAY);
     * SQL Server uses DATEADD(day, n, CAST(GETDATE() AS DATE)).
     * Use via PHP string interpolation:  "SET end_date = {$db->dateAddDays(120)}"
     *
     * @param int $days Number of days to add
     * @return string SQL date-add expression
     */
    public function dateAddDays(int $days): string
    {
        if ($this->driver === 'mssql' || $this->driver === 'sqlsrv') {
            return "DATEADD(day, {$days}, CAST(GETDATE() AS DATE))";
        }
        return "DATE_ADD(CURDATE(), INTERVAL {$days} DAY)";
    }

    /**
     * Escape string for SQL (use prepared statements instead when possible)
     * 
     * @param string $value Value to escape
     * @return string Escaped value
     */
    public function escape(string $value): string
    {
        // PDO quote returns value with quotes, so we strip them
        $quoted = $this->getConnection()->quote($value);
        return substr($quoted, 1, -1);
    }
    
    /**
     * Close connection
     */
    public function close(): void
    {
        $this->connection = null;
    }
    
    /**
     * Destructor - ensure connection is closed
     */
    public function __destruct()
    {
        $this->close();
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
    
    /**
     * Log executed query for debugging
     * 
     * @param string $sql SQL query
     * @param array $params Query parameters
     * @param float $executionTime Execution time in seconds
     * @param string|null $error Error message if query failed
     */
    private function logQuery(string $sql, array $params, float $executionTime, ?string $error = null): void
    {
        if (!$this->debugMode) {
            return;
        }
        
        $this->queryLog[] = [
            'sql' => $sql,
            'parameter_count' => count($params),
            'execution_time' => $executionTime,
            'error' => $error,
            'timestamp' => microtime(true)
        ];
    }
    
    /**
     * Get all logged queries
     * 
     * @return array
     */
    public function getQueryLog(): array
    {
        return $this->queryLog;
    }
    
    /**
     * Clear query log
     */
    public function clearQueryLog(): void
    {
        $this->queryLog = [];
    }
    
    /**
     * Get the last executed query with parameters
     * 
     * @return array|null
     */
    public function getLastQuery(): ?array
    {
        return empty($this->queryLog) ? null : end($this->queryLog);
    }
}
