<?php
declare(strict_types=1);

/**
 * Program Outcomes DataTables Server-Side Processing
 * 
 * Handles AJAX requests for program outcomes data with pagination, sorting, and filtering.
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
    'po.program_outcomes_pk',
    'p.program_name',
    'po.outcome_code',
    'po.outcome_description',
    'io.outcome_code'
];

// Column definitions for ordering (must match DataTables column order)
$columns = [
    'po.program_outcomes_pk',
    'p.program_name',
    'po.outcome_code',
    'po.outcome_description',
    'io.outcome_code',
    'po.sequence_num',
    'po.created_at',
    'u_created.full_name',
    'po.updated_at',
    'u_updated.full_name',
    'actions' // Not sortable
];

// Get order column name
$orderColumn = $columns[$params['orderColumn']] ?? 'po.sequence_num';
if ($orderColumn === 'actions') {
    $orderColumn = 'po.sequence_num';
}

// Build WHERE clause
$whereParams = [];
$whereTypes = '';
$whereConditions = [];
$statusMode = lifecycle_view_mode('program_outcomes');
$whereConditions[] = lifecycle_status_predicate('program_outcomes', 'po', $dbPrefix, $statusMode);

// Term filter (join through programs)
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

// Add column-specific searches with special handling for is_active and program
foreach ($params['columnSearches'] as $columnIndex => $searchValue) {
    if (!isset($columns[$columnIndex]) || empty($searchValue)) {
        continue;
    }
    
    $columnName = $columns[$columnIndex];
    
    // Special handling for program column (uses PK, not name)
    if ($columnName === 'p.program_name') {
        $whereConditions[] = "p.programs_pk = ?";
        $whereParams[] = (int)$searchValue;
        $whereTypes .= 'i';
    } elseif ($columnName !== 'actions') {
        $whereConditions[] = "{$columnName} LIKE ?";
        $whereParams[] = "%{$searchValue}%";
        $whereTypes .= 's';
    }
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get total records (filtered by current term if selected, via programs join)
if ($termFk) {
    $totalResult = $db->query(
        "SELECT COUNT(*) as total FROM {$dbPrefix}program_outcomes po JOIN {$dbPrefix}programs p ON po.program_fk = p.programs_pk WHERE p.term_fk = ?",
        [$termFk],
        'i'
    );
} else {
    $totalResult = $db->query("SELECT COUNT(*) as total FROM {$dbPrefix}program_outcomes");
}
$totalRow = fetch_row($totalResult);
$recordsTotal = $totalRow['total'];

// Get filtered records count
$countQuery = "
    SELECT COUNT(*) as total 
    FROM {$dbPrefix}program_outcomes po
    JOIN {$dbPrefix}programs p ON po.program_fk = p.programs_pk
    LEFT JOIN {$dbPrefix}institutional_outcomes io ON po.institutional_outcomes_fk = io.institutional_outcomes_pk
    LEFT JOIN {$dbPrefix}users u_created ON po.created_by_fk = u_created.users_pk
    LEFT JOIN {$dbPrefix}users u_updated ON po.updated_by_fk = u_updated.users_pk
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
        po.program_outcomes_pk,
        po.program_fk,
        p.program_name,
        p.program_code,
        po.outcome_code,
        po.outcome_description,
        po.institutional_outcomes_fk,
        io.outcome_code as institutional_outcome_code,
        io.outcome_description as institutional_outcome_description,
        po.sequence_num,
        po.is_active,
        po.created_at,
        po.updated_at,
        po.created_by_fk,
        po.updated_by_fk,
        u_created.full_name as created_by_name,
        u_updated.full_name as updated_by_name
    FROM {$dbPrefix}program_outcomes po
    JOIN {$dbPrefix}programs p ON po.program_fk = p.programs_pk
    LEFT JOIN {$dbPrefix}institutional_outcomes io ON po.institutional_outcomes_fk = io.institutional_outcomes_pk
    LEFT JOIN {$dbPrefix}users u_created ON po.created_by_fk = u_created.users_pk
    LEFT JOIN {$dbPrefix}users u_updated ON po.updated_by_fk = u_updated.users_pk
    {$whereClause}
    ORDER BY {$orderColumn} {$params['orderDir']}
    " . getPaginationClause($params['length'], $params['start']) . "
";

// Add limit params
try {
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
        
        // Format institutional outcome display
        $institutionalOutcome = $row['institutional_outcome_code'] 
            ? htmlspecialchars($row['institutional_outcome_code'])
            : '<span class="text-muted">N/A</span>';
        
        $data[] = [
            htmlspecialchars((string)$row['program_outcomes_pk']),
            htmlspecialchars($row['program_name']),
            htmlspecialchars($row['outcome_code']),
            htmlspecialchars($descriptionPreview),
            $institutionalOutcome,
            htmlspecialchars((string)$row['sequence_num']),
            htmlspecialchars($row['created_at'] ?? ''),
            htmlspecialchars(trim($row['created_by_name'] ?? '') ?: 'System'),
            htmlspecialchars($row['updated_at'] ?? ''),
            htmlspecialchars(trim($row['updated_by_name'] ?? '') ?: 'System'),
            '<div class="d-inline-flex flex-nowrap gap-1">'
                . related_records_button('program_outcomes', (int)$row['program_outcomes_pk'], $row['outcome_code'])
                . '<button class="btn btn-warning" onclick=\'editOutcome(' . $rowJson . ')\' aria-label="Edit outcome ' . htmlspecialchars($row['outcome_code'], ENT_QUOTES) . '"><i class="fas fa-edit" aria-hidden="true"></i></button>'
                . '</div>'
    ];
    }

    // Output JSON response
    outputDataTablesJson($params['draw'], $recordsTotal, $recordsFiltered, $data);
} catch (\Exception $e) {
    $errorReference = errorReference();
    error_log("Program Outcomes DataTables error [{$errorReference}]: " . $e->getMessage());
    echo json_encode([
        'draw' => $params['draw'] ?? 1,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => DEBUG_MODE ? $e->getMessage() : "An error occurred. Reference: {$errorReference}"
    ]);
}
