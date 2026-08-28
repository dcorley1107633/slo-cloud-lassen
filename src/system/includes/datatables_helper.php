<?php
declare(strict_types=1);

/**
 * DataTables Helper
 * 
 * Reusable functions for DataTables server-side processing.
 * 
 * @package SLOCloud
 */

/**
 * Get DataTables request parameters
 * 
 * @return array{draw: int, start: int, length: int, search: string, orderColumn: int, orderDir: string, columnSearches: array}
 */
function getDataTablesParams(): array
{
    // Extract individual column search values
    $columnSearches = [];
    if (isset($_REQUEST['columns']) && is_array($_REQUEST['columns'])) {
        foreach ($_REQUEST['columns'] as $index => $column) {
            if (isset($column['search']['value']) && $column['search']['value'] !== '') {
                $columnSearches[(int)$index] = $column['search']['value'];
            }
        }
    }
    
    return [
        'draw' => (int)($_REQUEST['draw'] ?? 1),
        'start' => (int)($_REQUEST['start'] ?? 0),
        'length' => (int)($_REQUEST['length'] ?? 10),
        'search' => $_REQUEST['search']['value'] ?? '',
        'orderColumn' => (int)($_REQUEST['order'][0]['column'] ?? 0),
        'orderDir' => strtoupper($_REQUEST['order'][0]['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC',
        'columnSearches' => $columnSearches
    ];
}

/**
 * Build search WHERE clause for DataTables
 * 
 * @param string $searchValue Global search value
 * @param array<string> $searchableColumns Column names to search
 * @param array<string> &$params Reference to params array
 * @param string &$types Reference to types string
 * @return string WHERE clause (without 'WHERE' keyword)
 */
function buildSearchWhere(string $searchValue, array $searchableColumns, array &$params, string &$types): string
{
    if (empty($searchValue)) {
        return '';
    }
    
    $searchConditions = [];
    foreach ($searchableColumns as $column) {
        $searchConditions[] = "{$column} LIKE ?";
    }
    
    $searchParam = "%{$searchValue}%";
    for ($i = 0; $i < count($searchConditions); $i++) {
        $params[] = $searchParam;
        $types .= 's';
    }
    
    return '(' . implode(' OR ', $searchConditions) . ')';
}

/**
 * Build column-specific search WHERE clauses for DataTables
 * 
 * @param array<int, string> $columnSearches Map of column index to search value
 * @param array<string> $columnNames Column database names indexed by column number
 * @param array<string> &$params Reference to params array
 * @param string &$types Reference to types string
 * @return array<string> Array of WHERE conditions
 */
function buildColumnSearchWhere(array $columnSearches, array $columnNames, array &$params, string &$types): array
{
    $conditions = [];
    
    foreach ($columnSearches as $columnIndex => $searchValue) {
        if (!isset($columnNames[$columnIndex]) || empty($searchValue)) {
            continue;
        }
        
        $columnName = $columnNames[$columnIndex];
        $conditions[] = "{$columnName} LIKE ?";
        $params[] = "%{$searchValue}%";
        $types .= 's';
    }
    
    return $conditions;
}

/**
 * Output DataTables JSON response
 * 
 * @param int $draw Draw counter from request
 * @param int $totalRecords Total records before filtering
 * @param int $filteredRecords Total records after filtering
 * @param array<mixed> $data Result data
 * @return never
 */
function outputDatatablesJson(int $draw, int $totalRecords, int $filteredRecords, array $data): never
{
    header('Content-Type: application/json');
    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $filteredRecords,
        'data' => $data
    ]);
    exit;
}
