<?php
declare(strict_types=1);

/**
 * Sections DataTables Server-Side Processing
 * 
 * @package SLOCloud
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/datatables_helper.php';
require_once __DIR__ . '/../system/includes/init.php';

header('Content-Type: application/json');

$params = getDataTablesParams();

$searchableColumns = [
    'c.course_number',
    'c.course_name',
    's.section_id'
];

$columns = [
    'c.course_number',
    's.section_id',
    'enrollment_count',
    's.created_at',
    'created_by_name',
    's.updated_at',
    'updated_by_name',
    'actions'
];

$orderColumn = $columns[$params['orderColumn']] ?? 'c.course_number';
if ($orderColumn === 'actions' || $orderColumn === 'created_by_name' || $orderColumn === 'updated_by_name') {
    $orderColumn = 'c.course_number';
}

$whereParams = [];
$whereTypes = '';

// Filters
$whereConditions = [];
$statusMode = lifecycle_view_mode('sections');
$whereConditions[] = lifecycle_status_predicate('sections', 's', $dbPrefix, $statusMode);

// Term filter
$termFkRaw = $_REQUEST['term_fk'] ?? null;
$termFk = ($termFkRaw !== null && $termFkRaw !== '' && $termFkRaw !== 'null') ? (int)$termFkRaw : null;
if ($termFk) {
    $whereConditions[] = 's.term_fk = ?';
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
    
    // No special column handling needed
    if ($columnName !== 'actions' && $columnName !== 'created_by_name' && $columnName !== 'updated_by_name') {
        $whereConditions[] = "{$columnName} LIKE ?";
        $whereParams[] = "%{$searchValue}%";
        $whereTypes .= 's';
    }
}

$finalWhereClause = !empty($whereConditions) ? "WHERE " . implode(' AND ', $whereConditions) : '';

// Get total records (filtered by current term if selected)
$totalQuery = "SELECT COUNT(*) as total FROM {$dbPrefix}sections";
if ($termFk) {
    $totalQuery .= " WHERE term_fk = ?";
    $totalResult = $db->query($totalQuery, [$termFk], 'i');
} else {
    $totalResult = $db->query($totalQuery);
}
$totalRow = fetch_row($totalResult);
$recordsTotal = $totalRow['total'];

$countQuery = "SELECT COUNT(*) as total 
               FROM {$dbPrefix}sections s
               LEFT JOIN {$dbPrefix}courses c ON s.course_fk = c.courses_pk
               LEFT JOIN {$dbPrefix}terms t ON s.term_fk = t.terms_pk
               LEFT JOIN (
                   SELECT section_fk, COUNT(DISTINCT enrollment_pk) AS enrollment_count
                   FROM {$dbPrefix}enrollment
                   GROUP BY section_fk
               ) enrollment_counts ON s.sections_pk = enrollment_counts.section_fk
               {$finalWhereClause}";
if (!empty($whereParams)) {
    $filteredResult = $db->query($countQuery, $whereParams, $whereTypes);
} else {
    $filteredResult = $db->query($countQuery);
}
$filteredRow = fetch_row($filteredResult);
$recordsFiltered = $filteredRow['total'];

$dataQuery = "
    SELECT s.*, c.course_number, c.course_name, t.term_name, t.term_code,
           COALESCE(enrollment_counts.enrollment_count, 0) AS enrollment_count,
           COALESCE(created_user.full_name, 'System') as created_by_name,
           COALESCE(updated_user.full_name, 'System') as updated_by_name
    FROM {$dbPrefix}sections s
    LEFT JOIN {$dbPrefix}courses c ON s.course_fk = c.courses_pk
    LEFT JOIN {$dbPrefix}terms t ON s.term_fk = t.terms_pk
    LEFT JOIN {$dbPrefix}users created_user ON s.created_by_fk = created_user.users_pk
    LEFT JOIN {$dbPrefix}users updated_user ON s.updated_by_fk = updated_user.users_pk
    LEFT JOIN (
        SELECT section_fk, COUNT(DISTINCT enrollment_pk) AS enrollment_count
        FROM {$dbPrefix}enrollment
        GROUP BY section_fk
    ) enrollment_counts ON s.sections_pk = enrollment_counts.section_fk
    {$finalWhereClause}
    ORDER BY {$orderColumn} {$params['orderDir']}
    " . getPaginationClause($params['length'], $params['start']) . "
";

$result = $db->query($dataQuery, $whereParams, $whereTypes);
$sections = fetch_all($result);

$canSimulateLti = \SLOCloud\Core\Authorization::isGlobalAdmin(
    $db,
    $dbPrefix,
    isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
);
$data = [];
foreach ($sections as $row) {
    $courseNumber = $row['course_number'] ?? 'Missing course';
    $sectionLabel = $courseNumber . '-' . $row['section_id'];
    $rowJson = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
    
    $launchUrl = BASE_URL . 'lti/?target=section&section_pk=' . (int)$row['sections_pk'];
    $actions = '<div class="btn-group btn-group-sm" role="group" aria-label="Actions for section '
        . htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8') . '">'
        . related_records_button('sections', (int)$row['sections_pk'], $sectionLabel)
        . ($canSimulateLti
            ? '<a class="btn btn-primary" href="' . htmlspecialchars($launchUrl, ENT_QUOTES, 'UTF-8') . '" title="Simulate LTI launch" '
                . 'aria-label="Simulate LTI launch for section ' . htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8') . '">'
                . '<i class="fas fa-rocket" aria-hidden="true"></i></a>'
            : '')
        . '<button class="btn btn-warning" onclick=\'editSection(' . $rowJson . ')\' title="Edit section" '
        . 'aria-label="Edit section ' . htmlspecialchars($sectionLabel, ENT_QUOTES, 'UTF-8') . '">'
        . '<i class="fas fa-edit" aria-hidden="true"></i></button>'
        . '</div>';

    $data[] = [
        htmlspecialchars($courseNumber),
        htmlspecialchars($row['section_id']),
        (int)$row['enrollment_count'],
        htmlspecialchars($row['created_at'] ?? 'N/A'),
        htmlspecialchars($row['created_by_name'] ?? 'System'),
        htmlspecialchars($row['updated_at'] ?? 'N/A'),
        htmlspecialchars($row['updated_by_name'] ?? 'System'),
        $actions
    ];
}

outputDataTablesJson($params['draw'], $recordsTotal, $recordsFiltered, $data);
