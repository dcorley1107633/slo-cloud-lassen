<?php
declare(strict_types=1);

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/datatables_helper.php';
require_once __DIR__ . '/../system/includes/init.php';

header('Content-Type: application/json');

try {
    $params = getDataTablesParams();

    $searchableColumns = ['slo_code', 'slo_description', 'c.course_name', 'po.outcome_code'];

    $columns = [
        'course_name',
        'program_outcome_code',
        'slo_code',
        'slo_description',
        'sequence_num',
        'created_at',
        'created_by_name',
        'updated_at',
        'updated_by_name',
        'actions'
    ];

    // Map column indices to database column names for individual column search
    $columnDbNames = [
        0 => 'c.course_name',
        1 => 'po.outcome_code',
        2 => 'slo.slo_code',
        3 => 'slo.slo_description',
        4 => 'slo.sequence_num',
        5 => 'slo.created_at',
        6 => 'u_created.full_name',
        7 => 'slo.updated_at',
        8 => 'u_updated.full_name'
    ];

    $orderColumn = $columns[$params['orderColumn']] ?? 'slo_code';
    if ($orderColumn === 'actions') {
        $orderColumn = 'slo_code';
    }
    if ($orderColumn === 'course_name') {
        $orderColumn = 'c.course_name';
    }
    if ($orderColumn === 'program_outcome_code') {
        $orderColumn = 'po.outcome_code';
    }
    if ($orderColumn === 'created_by_name') {
        $orderColumn = 'u_created.full_name';
    }
    if ($orderColumn === 'updated_by_name') {
        $orderColumn = 'u_updated.full_name';
    }

    $whereParams = [];
    $whereTypes = '';

    // Term filter (join through courses)
$whereConditions = [];
$statusMode = lifecycle_view_mode('student_learning_outcomes');
$whereConditions[] = lifecycle_status_predicate('student_learning_outcomes', 'slo', $dbPrefix, $statusMode);
    $termFkRaw = $_REQUEST['term_fk'] ?? null;
    $termFk = ($termFkRaw !== null && $termFkRaw !== '' && $termFkRaw !== 'null') ? (int)$termFkRaw : null;
    if ($termFk) {
        $whereConditions[] = 'c.term_fk = ?';
        $whereParams[] = $termFk;
        $whereTypes .= 'i';
    }

    // Global search
    $whereClause = buildSearchWhere($params['search'], $searchableColumns, $whereParams, $whereTypes);
    if (!empty($whereClause)) {
        $whereConditions[] = $whereClause;
    }

    // Individual column searches
    $columnSearchConditions = buildColumnSearchWhere($params['columnSearches'], $columnDbNames, $whereParams, $whereTypes);
    foreach ($columnSearchConditions as $condition) {
        $whereConditions[] = $condition;
    }

    $finalWhereClause = !empty($whereConditions) ? "WHERE " . implode(' AND ', $whereConditions) : '';

    // Get total records (filtered by current term if selected)
    if ($termFk) {
        $totalResult = $db->query(
            "SELECT COUNT(slo.student_learning_outcomes_pk) as total FROM {$dbPrefix}student_learning_outcomes slo LEFT JOIN {$dbPrefix}courses c ON slo.course_fk = c.courses_pk WHERE c.term_fk = ?",
            [$termFk],
            'i'
        );
    } else {
        $totalResult = $db->query("SELECT COUNT(*) as total FROM {$dbPrefix}student_learning_outcomes");
    }
    $totalRow = fetch_row($totalResult);
    $recordsTotal = $totalRow['total'];

    $countQuery = "SELECT COUNT(*) as total 
                   FROM {$dbPrefix}student_learning_outcomes slo 
                   LEFT JOIN {$dbPrefix}courses c ON slo.course_fk = c.courses_pk
                   LEFT JOIN {$dbPrefix}program_outcomes po ON slo.program_outcomes_fk = po.program_outcomes_pk 
                   LEFT JOIN {$dbPrefix}users u_created ON slo.created_by_fk = u_created.users_pk
                   LEFT JOIN {$dbPrefix}users u_updated ON slo.updated_by_fk = u_updated.users_pk
                   {$finalWhereClause}";
    if (!empty($whereParams)) {
        $filteredResult = $db->query($countQuery, $whereParams, $whereTypes);
    } else {
        $filteredResult = $db->query($countQuery);
    }
    $filteredRow = fetch_row($filteredResult);
    $recordsFiltered = $filteredRow['total'];

    $dataQuery = "
        SELECT slo.*, 
               c.course_name, c.course_number, 
               po.outcome_code as program_outcome_code,
               slo.created_by_fk,
               slo.updated_by_fk,
               u_created.full_name as created_by_name,
               u_updated.full_name as updated_by_name
        FROM {$dbPrefix}student_learning_outcomes slo
        LEFT JOIN {$dbPrefix}courses c ON slo.course_fk = c.courses_pk
        LEFT JOIN {$dbPrefix}program_outcomes po ON slo.program_outcomes_fk = po.program_outcomes_pk
        LEFT JOIN {$dbPrefix}users u_created ON slo.created_by_fk = u_created.users_pk
        LEFT JOIN {$dbPrefix}users u_updated ON slo.updated_by_fk = u_updated.users_pk
        {$finalWhereClause}
        ORDER BY {$orderColumn} {$params['orderDir']}
        " . getPaginationClause($params['length'], $params['start']) . "
    ";

    $result = $db->query($dataQuery, $whereParams, $whereTypes);
    $slos = fetch_all($result);

    $data = [];
    foreach ($slos as $row) {
        $rowJson = htmlspecialchars(json_encode($row), ENT_QUOTES, 'UTF-8');
        
        $descriptionPreview = strlen($row['slo_description']) > 60 
            ? substr($row['slo_description'], 0, 60) . '...' 
            : $row['slo_description'];
        
        $programOutcomeDisplay = !empty($row['program_outcome_code']) 
            ? htmlspecialchars($row['program_outcome_code'])
            : '<span class="text-muted">-</span>';
        
        $data[] = [
            htmlspecialchars($row['course_name'] ?? '') . ' (' . htmlspecialchars($row['course_number'] ?? '') . ')',
            $programOutcomeDisplay,
            htmlspecialchars($row['slo_code']),
            htmlspecialchars($descriptionPreview),
            htmlspecialchars((string)$row['sequence_num']),
            htmlspecialchars($row['created_at'] ?? ''),
            htmlspecialchars(trim($row['created_by_name'] ?? '') ?: 'System'),
            htmlspecialchars($row['updated_at'] ?? ''),
            htmlspecialchars(trim($row['updated_by_name'] ?? '') ?: 'System'),
            '<div class="d-inline-flex flex-nowrap gap-1">'
                . related_records_button('student_learning_outcomes', (int)$row['student_learning_outcomes_pk'], $row['slo_code'])
                . '<button class="btn btn-warning" onclick=\'editSLO(' . $rowJson . ')\' aria-label="Edit SLO ' . htmlspecialchars($row['slo_code'], ENT_QUOTES) . '"><i class="fas fa-edit" aria-hidden="true"></i></button>'
                . '</div>'
        ];
    }

    outputDataTablesJson($params['draw'], $recordsTotal, $recordsFiltered, $data);

} catch (Exception $e) {
    error_log("Error in student_learning_outcomes_data.php: " . $e->getMessage());
    echo json_encode([
        'draw' => $_REQUEST['draw'] ?? 1,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'An error occurred while fetching data'
    ]);
}
