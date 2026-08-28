<?php
declare(strict_types=1);

/**
 * Disciplines DataTables Server-Side Processing
 * 
 * Handles AJAX requests for discipline data with pagination, sorting, and filtering.
 * 
 * @package SLOCloud
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/datatables_helper.php';
require_once __DIR__ . '/../system/includes/init.php';

header('Content-Type: application/json');

// Get term filter from AJAX request (null = All Terms)
$termFkRaw = $_REQUEST['term_fk'] ?? null;
$selectedTermId = ($termFkRaw !== null && $termFkRaw !== '' && $termFkRaw !== 'null') ? (int)$termFkRaw : null;

// Get DataTables parameters
$params = getDataTablesParams();

// Define searchable columns
$searchableColumns = [
    'd.discipline_name',
    'd.discipline_code'
];

// Column definitions for ordering (must match DataTables column order)
$columns = [
    't.term_name',
    'd.discipline_name',
    'd.discipline_code',
    'd.created_at',
    'u_created.full_name',
    'd.updated_at',
    'u_updated.full_name',
    'actions' // Not sortable
];

// Get order column name
$orderColumn = $columns[$params['orderColumn']] ?? 'd.discipline_name';
if ($orderColumn === 'actions') {
    $orderColumn = 'd.discipline_name';
}

// Build WHERE clause
$whereParams = [];
$whereTypes = '';
$whereConditions = [];
$statusMode = lifecycle_view_mode('disciplines');
$whereConditions[] = lifecycle_status_predicate('disciplines', 'd', $dbPrefix, $statusMode);

// Global search
if (!empty($params['search'])) {
    $searchClause = buildSearchWhere($params['search'], $searchableColumns, $whereParams, $whereTypes);
    if (!empty($searchClause)) {
        $whereConditions[] = $searchClause;
    }
}

// Filter by selected term
if ($selectedTermId) {
    $whereConditions[] = "d.term_fk = ?";
    $whereParams[] = $selectedTermId;
    $whereTypes .= 'i';
}

// Add column-specific searches
foreach ($params['columnSearches'] as $columnIndex => $searchValue) {
    if (!isset($columns[$columnIndex]) || empty($searchValue)) {
        continue;
    }
    
    $columnName = $columns[$columnIndex];
    
    if ($columnName !== 'actions') {
        $whereConditions[] = "{$columnName} LIKE ?";
        $whereParams[] = "%{$searchValue}%";
        $whereTypes .= 's';
    }
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Get total records (without filtering, but with term filter if specified)
$totalQuery = "SELECT COUNT(*) as total FROM {$dbPrefix}disciplines";
if ($selectedTermId) {
    $totalQuery .= " WHERE term_fk = ?";
    $totalResult = $db->query($totalQuery, [$selectedTermId], 'i');
} else {
    $totalResult = $db->query($totalQuery);
}
$totalRow = fetch_row($totalResult);
 $recordsTotal = $totalRow['total'];

// Get filtered records count
$countQuery = "
    SELECT COUNT(*) as total 
    FROM {$dbPrefix}disciplines d
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
        d.disciplines_pk,
        d.term_fk,
        d.discipline_name,
        d.discipline_code,
        d.is_active,
        d.created_at,
        d.updated_at,
        d.created_by_fk,
        d.updated_by_fk,
        t.term_name,
        u_created.full_name as created_by_name,
        u_updated.full_name as updated_by_name
    FROM {$dbPrefix}disciplines d
    LEFT JOIN {$dbPrefix}terms t ON d.term_fk = t.terms_pk
    LEFT JOIN {$dbPrefix}users u_created ON d.created_by_fk = u_created.users_pk
    LEFT JOIN {$dbPrefix}users u_updated ON d.updated_by_fk = u_updated.users_pk
    {$whereClause}
    ORDER BY {$orderColumn} {$params['orderDir']}
    " . getPaginationClause($params['length'], $params['start']) . "
";

try {
    $result = $db->query($dataQuery, $whereParams, $whereTypes);
    $disciplines = fetch_all($result);

    // Format data for DataTables
    $data = [];
    foreach ($disciplines as $row) {
        $rowJson = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
        
        $disciplineId = (int)$row['disciplines_pk'];
        $disciplineLabel = htmlspecialchars($row['discipline_name'], ENT_QUOTES);
        $actions = '<div class="d-inline-flex flex-nowrap gap-1">'
            . related_records_button('disciplines', $disciplineId, $row['discipline_name'])
            . '<button class="btn btn-sm btn-warning" onclick=\'editDiscipline(' . $rowJson . ')\' aria-label="Edit discipline ' . $disciplineLabel . '"><i class="fas fa-edit" aria-hidden="true"></i></button>'
            . '</div>';
        
        $data[] = [
            htmlspecialchars($row['term_name'] ?? ''),
            htmlspecialchars($row['discipline_name']),
            htmlspecialchars($row['discipline_code'] ?? ''),
            htmlspecialchars($row['created_at'] ?? ''),
            htmlspecialchars(trim($row['created_by_name'] ?? '') ?: 'System'),
            htmlspecialchars($row['updated_at'] ?? ''),
            htmlspecialchars(trim($row['updated_by_name'] ?? '') ?: 'System'),
            $actions
        ];
    }

    // Build response
    $response = [
        'draw' => $params['draw'],
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data' => $data
    ];
    
    // Add debug info if debug mode enabled
    if (DEBUG_MODE) {
        $response['debug'] = [
            'selectedTermId' => $selectedTermId,
            'whereConditions' => $whereConditions,
            'whereParams' => $whereParams,
            'whereTypes' => $whereTypes,
            'whereClause' => $whereClause,
            'dataQuery' => $dataQuery,
            'queryParams' => $whereParams,
            'queryTypes' => $whereTypes,
            'session_term_fk' => $_SESSION['selected_term_fk'] ?? 'NOT SET',
            'get_term_fk' => $_REQUEST['term_fk'] ?? 'NOT SET',
            'post_term_fk' => $_POST['term_fk'] ?? 'NOT SET'
        ];
    }
    
    echo json_encode($response);
} catch (\Exception $e) {
    $errorReference = errorReference();
    error_log("Disciplines DataTables error [{$errorReference}]: " . $e->getMessage());

    $errorResponse = [
        'draw' => $params['draw'] ?? 1,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => DEBUG_MODE ? $e->getMessage() : "An error occurred. Reference: {$errorReference}"
    ];
    
    // Add debug info if debug mode enabled
    if (DEBUG_MODE) {
        $errorResponse['debug'] = [
            'selectedTermId' => $selectedTermId ?? null,
            'whereConditions' => $whereConditions ?? [],
            'whereParams' => $whereParams ?? [],
            'whereTypes' => $whereTypes ?? '',
            'whereClause' => $whereClause ?? '',
            'session_term_fk' => $_SESSION['selected_term_fk'] ?? 'NOT SET',
            'get_term_fk' => $_REQUEST['term_fk'] ?? 'NOT SET',
            'post_term_fk' => $_POST['term_fk'] ?? 'NOT SET',
            'exception' => $e->getMessage()
        ];
    }
    
    echo json_encode($errorResponse);
}
