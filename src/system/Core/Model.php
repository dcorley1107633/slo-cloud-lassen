<?php
declare(strict_types=1);

namespace SLOCloud\Core;

use PDOStatement;
use RuntimeException;

/**
 * Base Model Class
 * 
 * Provides common CRUD operations and database interaction patterns.
 * All domain models extend this base class.
 * 
 * Security: All queries use prepared statements.
 * 
 * @package SLOCloud\Core
 */
abstract class Model
{
    protected Database $db;
    protected string $table = '';
    protected string $primaryKey = '';
    
    /**
     * Constructor
     * 
     * @param Database $db Database instance
     */
    public function __construct(Database $db)
    {
        $this->db = $db;
        
        if (empty($this->table)) {
            throw new RuntimeException('Model must define $table property');
        }
        
        if (empty($this->primaryKey)) {
            throw new RuntimeException('Model must define $primaryKey property');
        }
    }
    
    /**
     * Find record by primary key
     * 
     * @param int $id Primary key value
     * @return array|null Record data or null if not found
     */
    public function find(int $id): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE {$this->primaryKey} = ?";
        $result = $this->db->query($sql, [$id], 'i');
        
        if ($result instanceof PDOStatement) {
            $row = $result->fetch();
            return $row ?: null;
        }
        
        return null;
    }
    
    /**
     * Find all records
     * 
     * @param array $conditions WHERE conditions (key => value)
     * @param string $orderBy ORDER BY clause (e.g., 'sequence_num ASC')
     * @param int|null $limit Maximum number of records
     * @return array Array of records
     */
    public function findAll(array $conditions = [], string $orderBy = '', ?int $limit = null): array
    {
        $sql = "SELECT * FROM {$this->table}";
        $params = [];
        $types = '';
        
        // Build WHERE clause
        if (!empty($conditions)) {
            $whereClauses = [];
            foreach ($conditions as $key => $value) {
                $whereClauses[] = "$key = ?";
                $params[] = $value;
                $types .= is_int($value) ? 'i' : 's';
            }
            $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
        }
        
        // Add ORDER BY
        if (!empty($orderBy)) {
            $sql .= " ORDER BY $orderBy";
        }
        
        // Add LIMIT (SQL Server uses TOP n before column list, MySQL uses LIMIT n at end)
        if ($limit !== null) {
            if ($this->isMssql()) {
                $sql = preg_replace('/^SELECT /i', "SELECT TOP $limit ", $sql);
            } else {
                $sql .= " LIMIT $limit";
            }
        }
        
        $result = $this->db->query($sql, $params, $types);
        
        if ($result instanceof PDOStatement) {
            return $result->fetchAll();
        }
        
        return [];
    }
    
    /**
     * Find first record matching conditions
     * 
     * @param array $conditions WHERE conditions (key => value)
     * @return array|null Record data or null if not found
     */
    public function findOne(array $conditions): ?array
    {
        $results = $this->findAll($conditions, '', 1);
        return $results[0] ?? null;
    }
    
    /**
     * Find records by column value
     * 
     * @param string $column Column name
     * @param mixed $value Value to match
     * @return array Array of records
     */
    public function findBy(string $column, mixed $value): array
    {
        return $this->findAll([$column => $value]);
    }
    
    /**
     * Create new record
     * 
     * @param array $data Record data (column => value)
     * @return int Inserted primary key
     * @throws RuntimeException If insert fails
     */
    public function create(array $data): int
    {
        // Add timestamps if columns exist
        if ($this->hasColumn('created_at') && !isset($data['created_at'])) {
            $data['created_at'] = date('Y-m-d H:i:s');
        }
        if ($this->hasColumn('updated_at') && !isset($data['updated_at'])) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }
        
        $columns = array_keys($data);
        $values = array_values($data);
        $placeholders = str_repeat('?,', count($values) - 1) . '?';
        
        $columnList = implode(', ', $columns);
        $sql = "INSERT INTO {$this->table} ($columnList) VALUES ($placeholders)";
        
        // Build types string
        $types = '';
        foreach ($values as $value) {
            if (is_int($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }
        
        $this->db->query($sql, $values, $types);
        
        $insertId = $this->db->getInsertId();
        if ($insertId === 0) {
            throw new RuntimeException('Insert failed - no ID returned');
        }
        
        return $insertId;
    }
    
    /**
     * Update existing record
     * 
     * @param int $id Primary key value
     * @param array $data Record data (column => value)
     * @return bool True if successful
     */
    public function update(int $id, array $data): bool
    {
        // Add updated_at timestamp if column exists
        if ($this->hasColumn('updated_at') && !isset($data['updated_at'])) {
            $data['updated_at'] = date('Y-m-d H:i:s');
        }
        
        $setClauses = [];
        $params = [];
        $types = '';
        
        foreach ($data as $column => $value) {
            $setClauses[] = "$column = ?";
            $params[] = $value;
            if (is_int($value)) {
                $types .= 'i';
            } elseif (is_float($value)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }
        
        $params[] = $id;
        $types .= 'i';
        
        $setClause = implode(', ', $setClauses);
        $sql = "UPDATE {$this->table} SET $setClause WHERE {$this->primaryKey} = ?";
        
        $this->db->query($sql, $params, $types);
        
        return $this->db->getAffectedRows() > 0;
    }
    
    /**
     * Soft delete record (set is_active = false)
     * Falls back to hard delete if is_active column doesn't exist
     * 
     * @param int $id Primary key value
     * @return bool True if successful
     */
    public function delete(int $id): bool
    {
        if ($this->hasColumn('is_active')) {
            return $this->update($id, ['is_active' => false]);
        }
        
        return $this->hardDelete($id);
    }
    
    /**
     * Permanently delete record
     * 
     * @param int $id Primary key value
     * @return bool True if successful
     */
    public function hardDelete(int $id): bool
    {
        $sql = "DELETE FROM {$this->table} WHERE {$this->primaryKey} = ?";
        $this->db->query($sql, [$id], 'i');
        
        return $this->db->getAffectedRows() > 0;
    }
    
    /**
     * Count records
     * 
     * @param array $conditions WHERE conditions (key => value)
     * @return int Record count
     */
    public function count(array $conditions = []): int
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table}";
        $params = [];
        $types = '';
        
        if (!empty($conditions)) {
            $whereClauses = [];
            foreach ($conditions as $key => $value) {
                $whereClauses[] = "$key = ?";
                $params[] = $value;
                $types .= is_int($value) ? 'i' : 's';
            }
            $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
        }
        
        $result = $this->db->query($sql, $params, $types);
        
        if ($result instanceof PDOStatement) {
            $row = $result->fetch();
            return (int)($row['count'] ?? 0);
        }
        
        return 0;
    }
    
    /**
     * Check if record exists
     * 
     * @param int $id Primary key value
     * @return bool True if exists
     */
    public function exists(int $id): bool
    {
        $sql = "SELECT COUNT(1) as cnt FROM {$this->table} WHERE {$this->primaryKey} = ?";
        $result = $this->db->query($sql, [$id], 'i');
        
        if ($result instanceof PDOStatement) {
            $row = $result->fetch();
            return $row !== false && (int)$row['cnt'] > 0;
        }
        
        return false;
    }
    
    /**
     * Execute raw SQL query (use sparingly, prefer specific methods)
     * 
     * @param string $sql SQL query with placeholders
     * @param array $params Parameters to bind
     * @param string $types Parameter types
     * @return array|bool Query result
     */
    protected function query(string $sql, array $params = [], string $types = ''): mixed
    {
        $result = $this->db->query($sql, $params, $types);
        
        if ($result instanceof PDOStatement) {
            return $result->fetchAll();
        }
        
        return $result;
    }
    
    /**
     * Check if table has a specific column
     * 
     * @param string $column Column name
     * @return bool True if column exists
     */
    private function isMssql(): bool
    {
        $driver = $this->db->getDriver();
        return $driver === 'mssql' || $driver === 'sqlsrv';
    }

    private function hasColumn(string $column): bool
    {
        static $columns = [];
        
        if (!isset($columns[$this->table])) {
            if ($this->isMssql()) {
                $sql = "SELECT COLUMN_NAME as Field FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = ?";
                $result = $this->db->query($sql, [$this->table], 's');
            } else {
                $sql = "SHOW COLUMNS FROM {$this->table}";
                $result = $this->db->query($sql);
            }
            
            if ($result instanceof PDOStatement) {
                $columns[$this->table] = [];
                while ($row = $result->fetch()) {
                    $columns[$this->table][] = $row['Field'];
                }
            }
        }
        
        return in_array($column, $columns[$this->table] ?? [], true);
    }
    
    /**
     * Begin database transaction
     * 
     * @return bool
     */
    protected function beginTransaction(): bool
    {
        return $this->db->beginTransaction();
    }
    
    /**
     * Commit database transaction
     * 
     * @return bool
     */
    protected function commit(): bool
    {
        return $this->db->commit();
    }
    
    /**
     * Rollback database transaction
     * 
     * @return bool
     */
    protected function rollback(): bool
    {
        return $this->db->rollback();
    }
}
