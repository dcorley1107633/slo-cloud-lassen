<?php
declare(strict_types=1);

/**
 * Courses DataTables Server-Side Processing
 * 
 * @package SLOCloud
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/datatables_helper.php';
require_once __DIR__ . '/../system/includes/init.php';

header('Content-Type: application/json');

$params = getDataTablesParams();

$searchableColumns = [
    't.term_code',
    'c.course_number', 
    'c.course_name'
];

$columns = [
    't.term_code',
    'c.course_number',
    'c.course_name',
    'c.created_at',
    'u_created.full_name',
    'c.updated_at',
    'u_updated.full_name',
    'actions'
];

$orderColumn = $columns[$params['orderColumn']] ?? 'c.course_number';
if ($orderColumn === 'actions') {
    $orderColumn = 'c.course_number';
}

$whereParams = [];
$whereTypes = '';
$whereConditions = [];
$statusMode = lifecycle_view_mode('courses');
$whereConditions[] = lifecycle_status_predicate('courses', 'c', $dbPrefix, $statusMode);

// Term filter
$termFkRaw = $_REQUEST['term_fk'] ?? null;
$termFk = ($termFkRaw !== null && $termFkRaw !== '' && $termFkRaw !== 'null') ? (int)$termFkRaw : null;
if ($termFk) {
    $whereConditions[] = 'c.term_fk = ?';
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

// Get total records (filtered by current term if selected)
$totalQuery = "SELECT COUNT(*) as total FROM {$dbPrefix}courses";
if ($termFk) {
    $totalQuery .= " WHERE term_fk = ?";
    $totalResult = $db->query($totalQuery, [$termFk], 'i');
} else {
    $totalResult = $db->query($totalQuery);
}
$totalRow = fetch_row($totalResult);
$recordsTotal = $totalRow['total'];

$countQuery = "
    SELECT COUNT(*) as total 
    FROM {$dbPrefix}courses c
    LEFT JOIN {$dbPrefix}terms t ON c.term_fk = t.terms_pk
    {$whereClause}
";
if (!empty($whereParams)) {
    $filteredResult = $db->query($countQuery, $whereParams, $whereTypes);
} else {
    $filteredResult = $db->query($countQuery);
}
$filteredRow = fetch_row($filteredResult);
$recordsFiltered = $filteredRow['total'];

$dataQuery = "
    SELECT 
        c.courses_pk,
        c.term_fk,
        c.course_number,
        c.course_name,
        c.is_active,
        c.created_at,
        c.updated_at,
        c.created_by_fk,
        c.updated_by_fk,
        t.term_name,
        t.term_code,
        u_created.full_name as created_by_name,
        u_updated.full_name as updated_by_name
    FROM {$dbPrefix}courses c
    LEFT JOIN {$dbPrefix}terms t ON c.term_fk = t.terms_pk
    LEFT JOIN {$dbPrefix}users u_created ON c.created_by_fk = u_created.users_pk
    LEFT JOIN {$dbPrefix}users u_updated ON c.updated_by_fk = u_updated.users_pk
    {$whereClause}
    ORDER BY {$orderColumn} {$params['orderDir']}
    " . getPaginationClause($params['length'], $params['start']) . "
";

try {
    $result = $db->query($dataQuery, $whereParams, $whereTypes);
    $courses = fetch_all($result);

    $data = [];
    foreach ($courses as $row) {
        $rowJson = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
        
        $data[] = [
            htmlspecialchars($row['term_code'] ?? 'N/A'),
            htmlspecialchars($row['course_number']),
            htmlspecialchars($row['course_name']),
            htmlspecialchars($row['created_at'] ?? ''),
            htmlspecialchars(trim($row['created_by_name'] ?? '') ?: 'System'),
            htmlspecialchars($row['updated_at'] ?? ''),
            htmlspecialchars(trim($row['updated_by_name'] ?? '') ?: 'System'),
            '<div class="d-inline-flex flex-nowrap gap-1">'
                . related_records_button('courses', (int)$row['courses_pk'], $row['course_number'])
                . '<button class="btn btn-warning" onclick=\'editCourse(' . $rowJson . ')\' aria-label="Edit course ' . htmlspecialchars($row['course_name'], ENT_QUOTES) . '"><i class="fas fa-edit" aria-hidden="true"></i></button>'
                . '</div>'
        ];
    }

    outputDataTablesJson($params['draw'], $recordsTotal, $recordsFiltered, $data);
} catch (\Exception $e) {
    $errorReference = errorReference();
    error_log("Courses DataTables error [{$errorReference}]: " . $e->getMessage());
    echo json_encode([
        'draw' => $params['draw'] ?? 1,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => DEBUG_MODE ? $e->getMessage() : "An error occurred. Reference: {$errorReference}"
    ]);
}
