<?php
/**
 * Terms Data Endpoint
 * 
 * DataTables server-side processing endpoint for terms management.
 * Returns terms data with term year information and course section counts.
 */

declare(strict_types=1);

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';

header('Content-Type: application/json');

try {
    // DataTables parameters
    $draw = (int)($_REQUEST['draw'] ?? 1);
    $start = (int)($_REQUEST['start'] ?? 0);
    $length = (int)($_REQUEST['length'] ?? 10);
    $searchValue = $_REQUEST['search']['value'] ?? '';
    $orderColumn = (int)($_REQUEST['order'][0]['column'] ?? 0);
    $orderDir = strtoupper($_REQUEST['order'][0]['dir'] ?? 'ASC') === 'DESC' ? 'DESC' : 'ASC';

    // Column mapping
    $columns = [
        0 => 't.banner_term',
        1 => 't.term_code',
        2 => 't.term_name',
        3 => 't.academic_year',
        4 => 't.start_date',
        5 => 't.end_date',
        6 => ''  // Actions column
    ];

    $orderBy = $columns[$orderColumn] ?? 't.term_code';

    // Total records
    $totalResult = $db->query("SELECT COUNT(*) as total FROM {$dbPrefix}terms");
    $totalRow = fetch_row($totalResult);
    $totalRecords = $totalRow['total'];

    // Build WHERE clause
    $where = [];
    $params = [];
    $types = '';
    $statusMode = lifecycle_view_mode('terms');
    $where[] = lifecycle_status_predicate('terms', 't', $dbPrefix, $statusMode);

    if (!empty($searchValue)) {
        $where[] = "(t.banner_term LIKE ? OR t.term_code LIKE ? OR t.term_name LIKE ? OR t.academic_year LIKE ?)";
        $params[] = "%{$searchValue}%";
        $params[] = "%{$searchValue}%";
        $params[] = "%{$searchValue}%";
        $params[] = "%{$searchValue}%";
        $types .= 'ssss';
    }

    // Add column-specific filters
    for ($i = 0; $i < count($columns) - 1; $i++) { // Exclude actions column
        $columnSearch = $_REQUEST['columns'][$i]['search']['value'] ?? '';
        if (!empty($columnSearch) && !empty($columns[$i])) {
            $where[] = "{$columns[$i]} LIKE ?";
            $params[] = "%{$columnSearch}%";
            $types .= 's';
        }
    }

    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

    // Filtered records
    if (!empty($where)) {
        $filteredResult = $db->query(
            "SELECT COUNT(*) as total FROM {$dbPrefix}terms t {$whereClause}",
            $params,
            $types
        );
        $filteredRow = fetch_row($filteredResult);
        $filteredRecords = $filteredRow['total'];
    } else {
        $filteredRecords = $totalRecords;
    }

    // Main query
    $query = "
        SELECT 
            t.terms_pk,
            t.banner_term,
            t.term_code,
            t.term_name,
            t.academic_year,
            t.start_date,
            t.end_date
            ,t.is_active
        FROM {$dbPrefix}terms t
        {$whereClause}
        ORDER BY {$orderBy} {$orderDir}
        " . getPaginationClause($length, $start) . "
    ";

    $result = $db->query($query, $params, $types);

    // Format data
    $data = [];
    while ($row = fetch_row($result)) {
        // Prepare data for JavaScript functions (keeping all data for edit functionality)
        $termJson = htmlspecialchars(json_encode([
            'terms_pk' => (int)$row['terms_pk'],
            'banner_term' => (string)($row['banner_term'] ?? ''),
            'term_code' => (string)($row['term_code'] ?? ''),
            'term_name' => (string)$row['term_name'],
            'academic_year' => (string)($row['academic_year'] ?? ''),
            'start_date' => (string)($row['start_date'] ?? ''),
            'end_date' => (string)($row['end_date'] ?? ''),
            'is_active' => (bool)$row['is_active']
        ]), ENT_QUOTES, 'UTF-8');
        
        // Action buttons
        $actions = '<div class="d-inline-flex flex-nowrap gap-1">'
            . related_records_button('terms', (int)$row['terms_pk'], (string)($row['term_code'] ?? ''))
            . '<button type="button" class="btn btn-warning" onclick=\'editTerm(' . $termJson . ')\' aria-label="Edit term ' . htmlspecialchars((string)($row['term_code'] ?? ''), ENT_QUOTES) . '">
                <i class="fas fa-edit" aria-hidden="true"></i>
            </button></div>';
        
        // Format dates
        $startDate = $row['start_date'] ? date('M d, Y', strtotime($row['start_date'])) : '';
        $endDate = $row['end_date'] ? date('M d, Y', strtotime($row['end_date'])) : '';
        
        $data[] = [
            htmlspecialchars((string)($row['banner_term'] ?? '')),
            htmlspecialchars((string)($row['term_code'] ?? '')),
            htmlspecialchars((string)$row['term_name']),
            htmlspecialchars((string)($row['academic_year'] ?? '')),
            $startDate,
            $endDate,
            $actions
        ];
    }

    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $filteredRecords,
        'data' => $data
    ]);
} catch (\Exception $e) {
    $errorReference = errorReference();
    error_log("Terms DataTables error [{$errorReference}]: " . $e->getMessage());
    echo json_encode([
        'draw' => $draw ?? 1,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => DEBUG_MODE ? $e->getMessage() : "An error occurred. Reference: {$errorReference}"
    ]);
}
