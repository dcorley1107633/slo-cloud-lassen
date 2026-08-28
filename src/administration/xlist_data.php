<?php
declare(strict_types=1);

/**
 * Cross-Listed Sections DataTables Server-Side Processing
 *
 * @package SLOCloud
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/datatables_helper.php';
require_once __DIR__ . '/../system/includes/init.php';

header('Content-Type: application/json');

$params = getDataTablesParams();

$searchableColumns = [
    'x.xlist_group',
    'x.crn',
    'x.course_number',
];

$columns = [
    'x.xlist_group',
    'x.crn',
    'x.course_number',
    'x.created_at',
    'created_by_name',
    'x.updated_at',
    'updated_by_name',
    'actions',
];

$orderColumn = $columns[$params['orderColumn']] ?? 'x.xlist_group';
if (in_array($orderColumn, ['actions', 'created_by_name', 'updated_by_name'], true)) {
    $orderColumn = 'x.xlist_group';
}

$whereParams = [];
$whereTypes  = '';
$whereConditions = [];
$statusMode = lifecycle_view_mode('section_xlist');
$whereConditions[] = lifecycle_status_predicate('section_xlist', 'x', $dbPrefix, $statusMode);

// Term filter
$termFkRaw = $_REQUEST['term_fk'] ?? null;
$termFk = ($termFkRaw !== null && $termFkRaw !== '' && $termFkRaw !== 'null') ? (int)$termFkRaw : null;
if ($termFk) {
    $whereConditions[] = 'x.term_fk = ?';
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

// Column-specific searches
foreach ($params['columnSearches'] as $columnIndex => $searchValue) {
    if (!isset($columns[$columnIndex]) || empty($searchValue)) {
        continue;
    }
    $columnName = $columns[$columnIndex];
    if (!in_array($columnName, ['actions', 'created_by_name', 'updated_by_name'], true)) {
        $whereConditions[] = "{$columnName} LIKE ?";
        $whereParams[] = "%{$searchValue}%";
        $whereTypes .= 's';
    }
}

$finalWhereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Total records (optionally term-filtered for the stat badge)
$totalQuery = "SELECT COUNT(*) as total FROM {$dbPrefix}section_xlist";
if ($termFk) {
    $totalQuery .= ' WHERE term_fk = ?';
    $totalResult = $db->query($totalQuery, [$termFk], 'i');
} else {
    $totalResult = $db->query($totalQuery);
}
$totalRow    = fetch_row($totalResult);
$recordsTotal = $totalRow['total'];

// Filtered count
$countQuery = "
    SELECT COUNT(*) as total
    FROM {$dbPrefix}section_xlist x
    INNER JOIN {$dbPrefix}terms t ON x.term_fk = t.terms_pk
    {$finalWhereClause}
";
$filteredResult  = !empty($whereParams) ? $db->query($countQuery, $whereParams, $whereTypes) : $db->query($countQuery);
$filteredRow     = fetch_row($filteredResult);
$recordsFiltered = $filteredRow['total'];

// Page data
$dataQuery = "
    SELECT x.*,
           t.term_name, t.term_code,
           COALESCE(cu.full_name, 'System') as created_by_name,
           COALESCE(uu.full_name, 'System') as updated_by_name
    FROM {$dbPrefix}section_xlist x
    INNER JOIN {$dbPrefix}terms t ON x.term_fk = t.terms_pk
    LEFT JOIN {$dbPrefix}users cu ON x.created_by_fk = cu.users_pk
    LEFT JOIN {$dbPrefix}users uu ON x.updated_by_fk = uu.users_pk
    {$finalWhereClause}
    ORDER BY {$orderColumn} {$params['orderDir']}, x.crn ASC
    " . getPaginationClause($params['length'], $params['start']) . "
";
$result = !empty($whereParams) ? $db->query($dataQuery, $whereParams, $whereTypes) : $db->query($dataQuery);
$rows = fetch_all($result);

$canSimulateLti = \SLOCloud\Core\Authorization::isGlobalAdmin(
    $db,
    $dbPrefix,
    isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null
);
$data = [];
foreach ($rows as $row) {
    $label  = htmlspecialchars($row['xlist_group'] . '-' . $row['crn'], ENT_QUOTES, 'UTF-8');
    $rowJson = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');

    $launchUrl = BASE_URL . 'lti/?target=xlist&term_fk=' . (int)$row['term_fk']
        . '&xlist_group=' . rawurlencode((string)$row['xlist_group']);
    $actions = '<div class="btn-group btn-group-sm" role="group" aria-label="Actions for cross-list '
        . $label . '">'
        . ($canSimulateLti
            ? '<a class="btn btn-primary" href="' . htmlspecialchars($launchUrl, ENT_QUOTES, 'UTF-8') . '" '
                . 'title="Simulate LTI launch" aria-label="Simulate LTI launch for cross-list ' . $label . '">'
                . '<i class="fas fa-rocket" aria-hidden="true"></i></a>'
            : '')
        . '<button class="btn btn-warning" onclick=\'editXlist(' . $rowJson . ')\' title="Edit cross-list entry" '
        . 'aria-label="Edit cross-list entry ' . $label . '">'
        . '<i class="fas fa-edit" aria-hidden="true"></i></button>'
        . '</div>';

    $data[] = [
        htmlspecialchars($row['xlist_group']),
        htmlspecialchars($row['crn']),
        htmlspecialchars($row['course_number'] ?? ''),
        htmlspecialchars($row['created_at'] ?? 'N/A'),
        htmlspecialchars($row['created_by_name'] ?? 'System'),
        htmlspecialchars($row['updated_at'] ?? 'N/A'),
        htmlspecialchars($row['updated_by_name'] ?? 'System'),
        $actions,
    ];
}

outputDataTablesJson($params['draw'], $recordsTotal, $recordsFiltered, $data);
