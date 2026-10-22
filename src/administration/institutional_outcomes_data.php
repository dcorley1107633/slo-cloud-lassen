<?php
declare(strict_types=1);

/**
 * Institutional Outcomes DataTables Server-Side Processing
 * 
 * Handles AJAX requests for institutional outcomes data with pagination, sorting, and filtering.
 * 
 * @package SLOCloud
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/datatables_helper.php';
require_once __DIR__ . '/../system/includes/init.php';

header('Content-Type: application/json');

try {
    // Get DataTables parameters
    $params = getDataTablesParams();

// Define searchable columns
$searchableColumns = [
    'io.outcome_code',
    'io.outcome_description',
    't.term_code',
    't.term_name'
];

// Column definitions for ordering (must match DataTables column order)
$columns = [
    't.term_code',
    'io.outcome_code',
    'io.outcome_description',
    'io.sequence_num',
    'io.created_at',
    'u_created.full_name',
    'io.updated_at',
    'u_updated.full_name',
    'actions' // Not sortable, placeholder
];

// Get order column name
$orderColumn = $columns[$params['orderColumn']] ?? 'io.sequence_num';
if ($orderColumn === 'actions') {
    $orderColumn = 'io.sequence_num';
}

// Build WHERE clause
$whereParams = [];
$whereTypes = '';
$whereConditions = [];
$statusMode = lifecycle_view_mode('institutional_outcomes');
$whereConditions[] = lifecycle_status_predicate('institutional_outcomes', 'io', $dbPrefix, $statusMode);

// Term filter
$termFkRaw = $_REQUEST['term_fk'] ?? null;
$termFk = ($termFkRaw !== null && $termFkRaw !== '' && $termFkRaw !== 'null') ? (int)$termFkRaw : null;
if ($termFk) {
    $whereConditions[] = 'io.term_fk = ?';
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

// Add column-specific searches with special handling for is_active
foreach ($params['columnSearches'] as $columnIndex => $searchValue) {
    if (!isset($columns[$columnIndex]) || empty($searchValue)) {
        continue;
    }
    
    $columnName = $columns[$columnIndex];
    
    // Special handling for status/active columns removed - no is_active column
    if ($columnName !== 'actions') {
        $whereConditions[] = "{$columnName} LIKE ?";
        $whereParams[] = "%{$searchValue}%";
        $whereTypes .= 's';
    }
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get total records (filtered by current term if selected)
$totalQuery = "SELECT COUNT(*) as total FROM {$dbPrefix}institutional_outcomes";
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
    FROM {$dbPrefix}institutional_outcomes io
    LEFT JOIN {$dbPrefix}terms t ON io.term_fk = t.terms_pk
    LEFT JOIN {$dbPrefix}users u_created ON io.created_by_fk = u_created.users_pk
    LEFT JOIN {$dbPrefix}users u_updated ON io.updated_by_fk = u_updated.users_pk
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
        io.institutional_outcomes_pk,
        io.term_fk,
        io.outcome_code,
        io.outcome_description,
        io.sequence_num,
        io.is_active,
        io.created_at,
        io.updated_at,
        io.created_by_fk,
        io.updated_by_fk,
        t.term_code,
        t.term_name,
        u_created.full_name as created_by_name,
        u_updated.full_name as updated_by_name
    FROM {$dbPrefix}institutional_outcomes io
    LEFT JOIN {$dbPrefix}terms t ON io.term_fk = t.terms_pk
    LEFT JOIN {$dbPrefix}users u_created ON io.created_by_fk = u_created.users_pk
    LEFT JOIN {$dbPrefix}users u_updated ON io.updated_by_fk = u_updated.users_pk
    {$whereClause}
    ORDER BY {$orderColumn} {$params['orderDir']}
    " . getPaginationClause($params['length'], $params['start']) . "
";

$result = $db->query($dataQuery, $whereParams, $whereTypes);
$outcomes = fetch_all($result);

// Format data for DataTables
$data = [];
foreach ($outcomes as $row) {
    $rowJson = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
    
    // Truncate description for display
    $descriptionPreview = strlen($row['outcome_description']) > 100 
        ? substr($row['outcome_description'], 0, 100) . '...' 
        : $row['outcome_description'];
    
    $data[] = [
        htmlspecialchars($row['term_name'] ?? 'N/A'),
        htmlspecialchars($row['outcome_code']),
        htmlspecialchars($descriptionPreview),
        htmlspecialchars((string)$row['sequence_num']),
        htmlspecialchars($row['created_at'] ?? ''),
        htmlspecialchars(trim($row['created_by_name'] ?? '') ?: 'System'),
        htmlspecialchars($row['updated_at'] ?? ''),
        htmlspecialchars(trim($row['updated_by_name'] ?? '') ?: 'System'),
        '<div class="d-inline-flex flex-nowrap gap-1">'
            . related_records_button('institutional_outcomes', (int)$row['institutional_outcomes_pk'], $row['outcome_code'])
            . '<button class="btn btn-warning" onclick=\'editOutcome(' . $rowJson . ')\' aria-label="Edit outcome ' . htmlspecialchars($row['outcome_code'], ENT_QUOTES) . '"><i class="fas fa-edit" aria-hidden="true"></i></button>'
            . '</div>'
    ];
}

    // Output JSON response
    outputDataTablesJson($params['draw'], $recordsTotal, $recordsFiltered, $data);
} catch (\Exception $e) {
    $errorReference = errorReference();
    error_log("Institutional Outcomes DataTables error [{$errorReference}]: " . $e->getMessage());
    echo json_encode([
        'draw' => $params['draw'] ?? 1,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => DEBUG_MODE ? $e->getMessage() : "An error occurred. Reference: {$errorReference}"
    ]);
}
