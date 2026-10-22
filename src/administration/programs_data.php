<?php
declare(strict_types=1);

/**
 * Programs DataTables Server-Side Processing
 * 
 * Handles AJAX requests for program data with pagination, sorting, and filtering.
 * 
 * @package SLOCloud
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/datatables_helper.php';
require_once __DIR__ . '/../system/includes/init.php';

header('Content-Type: application/json');

// Get DataTables parameters
$params = getDataTablesParams();

// Define searchable columns
$searchableColumns = [
    't.term_name',
    't.term_code',
    'p.program_code',
    'p.program_name',
    'p.degree_type',
    'd.discipline_name'
];

// Column definitions for ordering (must match DataTables column order)
$columns = [
    't.term_name',
    'd.discipline_name',
    'p.program_code',
    'p.program_name',
    'p.degree_type',
    'p.created_at',
    'u_created.full_name',
    'p.updated_at',
    'u_updated.full_name',
    'actions' // Not sortable
];

// Get order column name
$orderColumn = $columns[$params['orderColumn']] ?? 'p.program_name';
if ($orderColumn === 'actions') {
    $orderColumn = 'p.program_name';
}

// Build WHERE clause
$whereParams = [];
$whereTypes = '';
$whereConditions = [];
$statusMode = lifecycle_view_mode('programs');
$whereConditions[] = lifecycle_status_predicate('programs', 'p', $dbPrefix, $statusMode);

// Term filter
$termFkRaw = $_REQUEST['term_fk'] ?? null;
$termFk = ($termFkRaw !== null && $termFkRaw !== '' && $termFkRaw !== 'null') ? (int)$termFkRaw : null;
if ($termFk) {
    $whereConditions[] = 'p.term_fk = ?';
    $whereParams[] = $termFk;
    $whereTypes .= 'i';
}

// Global search
if (!empty($params['search'])) {
    $searchClause = buildSearchWhere($params['search'], $searchableColumns, $whereParams, $whereTypes);
    if (!empty($searchClause)) {
        $whereConditions[] = $searchClause;
    }
}

// Add column-specific searches with special handling for is_active and term
foreach ($params['columnSearches'] as $columnIndex => $searchValue) {
    if (!isset($columns[$columnIndex]) || empty($searchValue)) {
        continue;
    }
    
    $columnName = $columns[$columnIndex];
    
    // Special handling for term column - search both term_name and term_code
    if ($columnName === 't.term_name') {
        $whereConditions[] = "(t.term_name LIKE ? OR t.term_code LIKE ?)";
        $whereParams[] = "%{$searchValue}%";
        $whereParams[] = "%{$searchValue}%";
        $whereTypes .= 'ss';
    } elseif ($columnName !== 'actions') {
        $whereConditions[] = "{$columnName} LIKE ?";
        $whereParams[] = "%{$searchValue}%";
        $whereTypes .= 's';
    }
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get total records (filtered by current term if selected)
$totalQuery = "SELECT COUNT(*) as total FROM {$dbPrefix}programs";
if ($termFk) {
    $totalQuery .= " WHERE term_fk = ?";
    $totalResult = $db->query($totalQuery, [$termFk], 'i');
} else {
    $totalResult = $db->query($totalQuery);
}
$totalRow = fetch_row($totalResult);
$recordsTotal = $totalRow['total'];

// Get filtered records count
$countQuery = "
    SELECT COUNT(*) as total 
    FROM {$dbPrefix}programs p
    LEFT JOIN {$dbPrefix}terms t ON p.term_fk = t.terms_pk
    LEFT JOIN {$dbPrefix}disciplines d ON p.discipline_fk = d.disciplines_pk
    LEFT JOIN {$dbPrefix}users u_created ON p.created_by_fk = u_created.users_pk
    LEFT JOIN {$dbPrefix}users u_updated ON p.updated_by_fk = u_updated.users_pk
    {$whereClause}
";
if (!empty($whereParams)) {
    $filteredResult = $db->query($countQuery, $whereParams, $whereTypes);
} else {
    $filteredResult = $db->query($countQuery);
}
$filteredRow = fetch_row($filteredResult);
$recordsFiltered = $filteredRow['total'];

// Get data
$dataQuery = "
    SELECT 
        p.programs_pk,
        p.term_fk,
        p.discipline_fk,
        p.program_code,
        p.program_name,
        p.degree_type,
        p.is_active,
        p.created_at,
        p.updated_at,
        p.created_by_fk,
        p.updated_by_fk,
        t.term_code,
        t.term_name,
        d.discipline_name,
        d.discipline_code,
        u_created.full_name as created_by_name,
        u_updated.full_name as updated_by_name
    FROM {$dbPrefix}programs p
    LEFT JOIN {$dbPrefix}terms t ON p.term_fk = t.terms_pk
    LEFT JOIN {$dbPrefix}disciplines d ON p.discipline_fk = d.disciplines_pk
    LEFT JOIN {$dbPrefix}users u_created ON p.created_by_fk = u_created.users_pk
    LEFT JOIN {$dbPrefix}users u_updated ON p.updated_by_fk = u_updated.users_pk
    {$whereClause}
    ORDER BY {$orderColumn} {$params['orderDir']}
    " . getPaginationClause($params['length'], $params['start']) . "
";

try {
    $result = $db->query($dataQuery, $whereParams, $whereTypes);
    $programs = fetch_all($result);

    // Format data for DataTables
    $data = [];
    foreach ($programs as $row) {
        $rowJson = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
        
        $disciplineDisplay = $row['discipline_name'] ?? '';
        if ($disciplineDisplay && $row['discipline_code']) {
            $disciplineDisplay .= ' (' . $row['discipline_code'] . ')';
        }
        
        $data[] = [
            htmlspecialchars($row['term_name'] ?? 'N/A'),
            htmlspecialchars($disciplineDisplay ?: 'N/A'),
            htmlspecialchars($row['program_code']),
            htmlspecialchars($row['program_name']),
            htmlspecialchars($row['degree_type'] ?? ''),
            htmlspecialchars($row['created_at'] ?? ''),
            htmlspecialchars(trim($row['created_by_name'] ?? '') ?: 'System'),
            htmlspecialchars($row['updated_at'] ?? ''),
            htmlspecialchars(trim($row['updated_by_name'] ?? '') ?: 'System'),
            '<div class="d-inline-flex flex-nowrap gap-1">'
                . related_records_button('programs', (int)$row['programs_pk'], $row['program_name'])
                . '<button class="btn btn-warning" onclick=\'editProgram(' . $rowJson . ')\' aria-label="Edit program ' . htmlspecialchars($row['program_name'], ENT_QUOTES) . '"><i class="fas fa-edit" aria-hidden="true"></i></button>'
                . '</div>'
        ];
    }

    // Output JSON response
    outputDataTablesJson($params['draw'], $recordsTotal, $recordsFiltered, $data);
} catch (\Exception $e) {
    $errorReference = errorReference();
    error_log("Programs DataTables error [{$errorReference}]: " . $e->getMessage());
    echo json_encode([
        'draw' => $params['draw'] ?? 1,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => DEBUG_MODE ? $e->getMessage() : "An error occurred. Reference: {$errorReference}"
    ]);
}
