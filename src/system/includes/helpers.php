<?php
declare(strict_types=1);

/**
 * Common Helper Functions
 * 
 * Reusable utility functions for the application.
 * Requires init.php to be loaded first (for $db, $dbPrefix globals).
 * 
 * @package SLOCloud
 */

/**
 * Cache term information in session
 * 
 * @param int $termFk Term primary key
 * @return bool Whether the term exists and is active
 */
function cacheTermInSession(int $termFk): bool {
    global $db, $dbPrefix;
    
    try {
        $result = $db->query(
            "SELECT terms_pk, term_name, term_code, banner_term FROM {$dbPrefix}terms WHERE terms_pk = ? AND is_active = 1",
            [$termFk],
            'i'
        );
        
        if ($result && $row = $result->fetch()) {
            $_SESSION['selected_term'] = [
                'fk' => (int)$row['terms_pk'],
                'name' => $row['term_name'],
                'code' => $row['term_code'],
                'banner_term' => $row['banner_term']
            ];
            return true;
        }
    } catch (\Exception $e) {
        error_log("Failed to cache term in session: " . $e->getMessage());
    }
    unset($_SESSION['selected_term']);
    return false;
}

/**
 * Get the currently selected term ID with session persistence
 * 
 * Handles term selection across page navigation:
 * 1. If term_fk is in GET/POST, use it and update session
 * 2. Otherwise, use session value
 * 3. If no session value, auto-select term closest to today
 * 4. If no terms exist, return null (all terms)
 * 
 * @return int|null Selected term primary key or null for all terms
 */
function getSelectedTermFk(): ?int {
    global $db, $dbPrefix;
    
    // Check if term_fk is explicitly in request (including empty = "All Terms")
    if (isset($_GET['term_fk'])) {
        if ($_GET['term_fk'] === '') {
            // User explicitly selected "All Terms" - clear the session
            unset($_SESSION['selected_term']);
            return null;
        }
        $termFk = (int)$_GET['term_fk'];
        if (cacheTermInSession($termFk)) return $termFk;
    }
    
    if (isset($_POST['term_fk'])) {
        if ($_POST['term_fk'] === '') {
            unset($_SESSION['selected_term']);
            return null;
        }
        $termFk = (int)$_POST['term_fk'];
        if (cacheTermInSession($termFk)) return $termFk;
    }
    
    // Use session value if available
    if (isset($_SESSION['selected_term']['fk']) && $_SESSION['selected_term']['fk'] !== '') {
        $sessionTermFk = (int)$_SESSION['selected_term']['fk'];
        if (cacheTermInSession($sessionTermFk)) return $sessionTermFk;
    }
    
    // No term selected - auto-select the term closest to today
    // Priority:
    // 1. Active term where today is between start_date and end_date
    // 2. If no dates, use the most recently created active term
    // 3. Otherwise, find the term with the smallest date distance to today
    try {
        $today = date('Y-m-d');
        $isSqlServer = ($db->getDriver() === 'mssql' || $db->getDriver() === 'sqlsrv');
        
        // First, try to find an active term containing today
        if ($isSqlServer) {
            $query = "SELECT TOP 1 terms_pk, term_name, term_code, banner_term FROM {$dbPrefix}terms 
                 WHERE start_date IS NOT NULL 
                 AND is_active = 1
                 AND end_date IS NOT NULL
                 AND ? BETWEEN start_date AND end_date
                 ORDER BY start_date DESC";
        } else {
            $query = "SELECT terms_pk, term_name, term_code, banner_term FROM {$dbPrefix}terms 
                 WHERE start_date IS NOT NULL 
                 AND is_active = 1
                 AND end_date IS NOT NULL
                 AND ? BETWEEN start_date AND end_date
                 ORDER BY start_date DESC
                 LIMIT 1";
        }
        $result = $db->query($query, [$today], 's');
        
        if ($result && $row = $result->fetch()) {
            $_SESSION['selected_term'] = [
                'fk' => (int)$row['terms_pk'],
                'name' => $row['term_name'],
                'code' => $row['term_code'],
                'banner_term' => $row['banner_term']
            ];
            return (int)$row['terms_pk'];
        }
        
        // Fall back to most recently created active term (if no dates)
        if ($isSqlServer) {
            $query = "SELECT TOP 1 terms_pk, term_name, term_code, banner_term FROM {$dbPrefix}terms 
                 WHERE is_active = 1
                 ORDER BY created_at DESC";
        } else {
            $query = "SELECT terms_pk, term_name, term_code, banner_term FROM {$dbPrefix}terms 
                 WHERE is_active = 1
                 ORDER BY created_at DESC
                 LIMIT 1";
        }
        $result = $db->query($query);
        
        if ($result && $row = $result->fetch()) {
            $_SESSION['selected_term'] = [
                'fk' => (int)$row['terms_pk'],
                'name' => $row['term_name'],
                'code' => $row['term_code'],
                'banner_term' => $row['banner_term']
            ];
            return (int)$row['terms_pk'];
        }
        
        // Last resort: find closest term by date distance
        if ($isSqlServer) {
            // SQL Server version
            $query = "SELECT TOP 1 terms_pk, term_name, term_code, banner_term,
                    CASE 
                        WHEN ABS(DATEDIFF(DAY, COALESCE(start_date, ?), ?)) < ABS(DATEDIFF(DAY, COALESCE(end_date, ?), ?))
                        THEN ABS(DATEDIFF(DAY, COALESCE(start_date, ?), ?))
                        ELSE ABS(DATEDIFF(DAY, COALESCE(end_date, ?), ?))
                    END as date_distance
             FROM {$dbPrefix}terms 
             WHERE is_active = 1
             ORDER BY date_distance ASC, created_at DESC";
            $result = $db->query($query, [$today, $today, $today, $today, $today, $today, $today, $today], 'ssssssss');
        } else {
            // MySQL version
            $query = "SELECT terms_pk, term_name, term_code, banner_term,
                    LEAST(
                        ABS(DATEDIFF(COALESCE(start_date, ?), ?)),
                        ABS(DATEDIFF(COALESCE(end_date, ?), ?))
                    ) as date_distance
             FROM {$dbPrefix}terms 
             WHERE is_active = 1
             ORDER BY date_distance ASC, created_at DESC
             LIMIT 1";
            $result = $db->query($query, [$today, $today, $today, $today], 'ssss');
        }
        
        if ($result && $row = $result->fetch()) {
            $_SESSION['selected_term'] = [
                'fk' => (int)$row['terms_pk'],
                'name' => $row['term_name'],
                'code' => $row['term_code'],
                'banner_term' => $row['banner_term']
            ];
            return (int)$row['terms_pk'];
        }
    } catch (\Exception $e) {
        // If database query fails, log error and fall through to return null
        error_log("Failed to auto-select term: " . $e->getMessage());
    }
    
    // No terms available - return null for "all terms"
    return null;
}

/**
 * Get the currently selected term name from session
 * 
 * @return string|null Term name or null if not set
 */
function getSelectedTermName(): ?string {
    return $_SESSION['selected_term']['name'] ?? null;
}

/**
 * Get the currently selected term code from session
 * 
 * @return string|null Term code or null if not set
 */
function getSelectedTermCode(): ?string {
    return $_SESSION['selected_term']['code'] ?? null;
}

/**
 * Get the currently selected term banner code from session
 * 
 * @return string|null Banner term or null if not set
 */
function getSelectedTermBanner(): ?string {
    return $_SESSION['selected_term']['banner_term'] ?? null;
}

/**
 * Clean up orphaned student records (students with no enrollments)
 * 
 * Removes student records that have no remaining enrollments in the system.
 * Should be called after enrollment deletions to maintain referential integrity.
 * 
 * @return int Number of orphaned student records deleted
 */
function cleanupOrphanedStudents(): int {
    global $db, $dbPrefix;
    
    $statement = $db->query(
        "DELETE FROM {$dbPrefix}students
         WHERE NOT EXISTS (
             SELECT 1
             FROM {$dbPrefix}enrollment
             WHERE {$dbPrefix}enrollment.student_fk = {$dbPrefix}students.students_pk
         )"
    );

    return $statement->rowCount();
}

/**
 * Get a valid user ID for created_by_fk and updated_by_fk fields
 * 
 * Returns a valid user ID in this priority:
 * 1. Current session user (if logged in)
 * 2. First active user in database
 * 3. Fallback to user ID 1
 * 
 * This ensures foreign key constraints are never violated.
 * 
 * @return int Valid user ID (never null)
 */
function getValidUserId(): int {
    global $db, $dbPrefix;
    
    // Priority 1: Session user
    if (!empty($_SESSION['user_id'])) {
        return (int)$_SESSION['user_id'];
    }
    
    // Priority 2: First active user
    try {
        $isSqlServer = ($db->getDriver() === 'mssql' || $db->getDriver() === 'sqlsrv');
        if ($isSqlServer) {
            $query = "SELECT TOP 1 users_pk FROM {$dbPrefix}users ORDER BY users_pk ASC";
        } else {
            $query = "SELECT users_pk FROM {$dbPrefix}users ORDER BY users_pk ASC LIMIT 1";
        }
        $result = $db->query($query);
        $user = $result->fetch();
        if ($user) {
            return (int)$user['users_pk'];
        }
    } catch (\Exception $e) {
        error_log("getValidUserId: Failed to query users table: " . $e->getMessage());
    }
    
    // Priority 3: Fallback to user ID 1
    return 1;
}

/**
 * Get pagination SQL clause for the current database driver
 * 
 * @param int $limit Number of records to fetch
 * @param int $offset Number of records to skip
 * @return string SQL pagination clause
 */
function getPaginationClause(int $limit, int $offset): string {
    global $db;
    
    $isSqlServer = ($db->getDriver() === 'mssql' || $db->getDriver() === 'sqlsrv');
    
    if ($isSqlServer) {
        // SQL Server 2012+ uses OFFSET...FETCH NEXT
        return "OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";
    } else {
        // MySQL uses LIMIT...OFFSET
        return "LIMIT {$limit} OFFSET {$offset}";
    }
}

/**
 * Fetch and normalize a single row (handles SQL Server numeric strings)
 * 
 * @param PDOStatement $result Query result
 * @return array|false Normalized row or false
 */
function fetch_row($result) {
    global $db;
    $row = $result->fetch();
    return $db->normalizeRow($row);
}

/**
 * Fetch and normalize all rows (handles SQL Server numeric strings)
 * 
 * @param PDOStatement $result Query result
 * @return array Normalized rows
 */
function fetch_all($result): array {
    global $db;
    $rows = $result->fetchAll();
    
    // Only normalize for SQL Server
    $isSqlServer = ($db->getDriver() === 'mssql' || $db->getDriver() === 'sqlsrv');
    if (!$isSqlServer) {
        return $rows;
    }
    
    // Normalize each row
    $normalized = [];
    foreach ($rows as $row) {
        $normalized[] = $db->normalizeRow($row);
    }
    
    return $normalized;
}

/**
 * Short correlation token for an error.
 *
 * Ties a generic client-facing error message back to the detailed log entry
 * (which carries the same token) without exposing internal detail - stack
 * traces, file paths, raw exception text - to the browser. Support can grep
 * the logs for the token instead of guessing by timestamp.
 *
 * @return string 8-character uppercase hex token
 */
function errorReference(): string
{
    return strtoupper(bin2hex(random_bytes(4)));
}

