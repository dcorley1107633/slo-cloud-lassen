<?php
declare(strict_types=1);

/**
 * Dashboard Sections DataTable AJAX Endpoint
 * 
 * Returns sections for a selected course with enrollment and assessment counts.
 * 
 * @package SLOCloud\Administration
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';
require_once __DIR__ . '/../system/includes/datatables_helper.php';

header('Content-Type: application/json');

try {
    // Get course and term filters
    $courseFk = isset($_REQUEST['course_fk']) ? $_REQUEST['course_fk'] : '';
    $termFk = isset($_REQUEST['term_fk']) ? (int)$_REQUEST['term_fk'] : 0;
    $disciplineFk = isset($_REQUEST['discipline_fk']) ? $_REQUEST['discipline_fk'] : '';
    
    if ($termFk <= 0) {
        echo json_encode([
            'draw' => isset($_REQUEST['draw']) ? (int)$_REQUEST['draw'] : 1,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => []
        ]);
        exit;
    }
    
    // Handle "all courses" case
    $showingAllCourses = ($courseFk === 'all');
    
    if ($showingAllCourses) {
        // Column definitions for all courses view
        $columns = [
            ['db' => 'd.discipline_name', 'dt' => 0, 'field' => 'discipline_name'],
            ['db' => 'course_display', 'dt' => 1, 'field' => 'course_display'],
            ['db' => 's.section_id', 'dt' => 2, 'field' => 'section_id'],
            ['db' => 'assessment_count', 'dt' => 3, 'field' => 'assessment_count']
        ];
        
        // Build base query for all courses
        $baseQuery = "
            FROM {$dbPrefix}sections s
            JOIN {$dbPrefix}courses c ON s.course_fk = c.courses_pk
            LEFT JOIN {$dbPrefix}disciplines d ON c.discipline_fk = d.disciplines_pk
            LEFT JOIN (
                SELECT section_fk, COUNT(DISTINCT enrollment_pk) as enrollment_count
                FROM {$dbPrefix}enrollment
                GROUP BY section_fk
            ) e ON s.sections_pk = e.section_fk
            LEFT JOIN (
                SELECT e.section_fk, COUNT(DISTINCT a.assessments_pk) as assessment_count
                FROM {$dbPrefix}enrollment e
                JOIN {$dbPrefix}assessments a ON e.enrollment_pk = a.enrollment_fk
                GROUP BY e.section_fk
            ) a ON s.sections_pk = a.section_fk
            WHERE s.term_fk = ? AND " . lifecycle_active_predicate('sections', 's', $dbPrefix) . "
        ";
        
        $queryParams = [$termFk];
        $queryTypes = 'i';
        
        // Add discipline filter if specific discipline is selected
        if ($disciplineFk !== 'all' && $disciplineFk !== '') {
            $baseQuery .= " AND c.discipline_fk = ?";
            $queryParams[] = (int)$disciplineFk;
            $queryTypes .= 'i';
        }
        
        // Build SELECT clause (driver-aware for SUBSTRING_INDEX)
        $isSqlServer = ($db->getDriver() === 'mssql' || $db->getDriver() === 'sqlsrv');
        $disciplineFallback = $isSqlServer
            ? "LEFT(c.course_number, CHARINDEX(' ', c.course_number + ' ') - 1)"
            : "SUBSTRING_INDEX(c.course_number, ' ', 1)";
        $selectClause = "
            COALESCE(d.discipline_name, {$disciplineFallback}) as discipline_name,
            CONCAT(c.course_number, ' - ', c.course_name) as course_display,
            s.section_id,
            COALESCE(a.assessment_count, 0) as assessment_count
        ";
    } else {
        // Single course view - original column definitions
        $courseFk = (int)$courseFk;
        
        if ($courseFk <= 0) {
            echo json_encode([
                'draw' => isset($_REQUEST['draw']) ? (int)$_REQUEST['draw'] : 1,
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => []
            ]);
            exit;
        }
        
        $columns = [
            ['db' => 's.section_id', 'dt' => 0, 'field' => 'section_id'],
            ['db' => 'enrollment_count', 'dt' => 1, 'field' => 'enrollment_count'],
            ['db' => 'assessment_count', 'dt' => 2, 'field' => 'assessment_count']
        ];
        
        // Build base query for single course
        $baseQuery = "
            FROM {$dbPrefix}sections s
            LEFT JOIN (
                SELECT section_fk, COUNT(DISTINCT enrollment_pk) as enrollment_count
                FROM {$dbPrefix}enrollment
                GROUP BY section_fk
            ) e ON s.sections_pk = e.section_fk
            LEFT JOIN (
                SELECT e.section_fk, COUNT(DISTINCT a.assessments_pk) as assessment_count
                FROM {$dbPrefix}enrollment e
                JOIN {$dbPrefix}assessments a ON e.enrollment_pk = a.enrollment_fk
                GROUP BY e.section_fk
            ) a ON s.sections_pk = a.section_fk
            WHERE s.course_fk = ? AND s.term_fk = ? AND " . lifecycle_active_predicate('sections', 's', $dbPrefix) . "
        ";
        
        $queryParams = [$courseFk, $termFk];
        $queryTypes = 'ii';
        
        // Build SELECT clause
        $selectClause = "
            s.section_id,
            COALESCE(e.enrollment_count, 0) as enrollment_count,
            COALESCE(a.assessment_count, 0) as assessment_count
        ";
    }
    
    // Get total count (unfiltered)
    $totalQuery = "SELECT COUNT(*) as total " . $baseQuery;
    $totalResult = $db->query($totalQuery, $queryParams, $queryTypes);
    $totalRow = fetch_row($totalResult);
    $recordsTotal = (int)($totalRow['total'] ?? 0);
    
    // Handle search and filtering
    $whereClause = '';
    $searchParams = $queryParams;
    $searchTypes = $queryTypes;
    
    if (!empty($_REQUEST['search']['value'])) {
        $searchValue = '%' . $_REQUEST['search']['value'] . '%';
        $whereClause = " AND s.section_id LIKE ?";
        $searchParams = array_merge($searchParams, [$searchValue]);
        $searchTypes .= 's';
    }
    
    // Column-specific search
    foreach ($columns as $column) {
        $columnIdx = $column['dt'];
        if (!empty($_REQUEST['columns'][$columnIdx]['search']['value'])) {
            $searchValue = '%' . $_REQUEST['columns'][$columnIdx]['search']['value'] . '%';
            $whereClause .= " AND " . $column['db'] . " LIKE ?";
            $searchParams[] = $searchValue;
            $searchTypes .= 's';
        }
    }
    
    // Get filtered count
    $filteredQuery = "SELECT COUNT(*) as filtered " . $baseQuery . $whereClause;
    $filteredResult = $db->query($filteredQuery, $searchParams, $searchTypes);
    $filteredRow = fetch_row($filteredResult);
    $recordsFiltered = (int)($filteredRow['filtered'] ?? 0);
    
    // Handle ordering
    $orderClause = '';
    if (isset($_REQUEST['order']) && is_array($_REQUEST['order'])) {
        $orderParts = [];
        foreach ($_REQUEST['order'] as $order) {
            $columnIdx = (int)$order['column'];
            $dir = strtoupper($order['dir']) === 'DESC' ? 'DESC' : 'ASC';
            if (isset($columns[$columnIdx])) {
                $orderParts[] = $columns[$columnIdx]['db'] . ' ' . $dir;
            }
        }
        if (!empty($orderParts)) {
            $orderClause = ' ORDER BY ' . implode(', ', $orderParts);
        }
    }
    
    if (empty($orderClause)) {
        if ($showingAllCourses) {
            $orderClause = ' ORDER BY d.discipline_code ASC, c.course_number ASC, s.section_id ASC';
        } else {
            $orderClause = ' ORDER BY s.section_id ASC';
        }
    }
    
    // Handle pagination
    $limitClause = '';
    if (isset($_REQUEST['start']) && isset($_REQUEST['length']) && $_REQUEST['length'] != -1) {
        $start = (int)$_REQUEST['start'];
        $length = (int)$_REQUEST['length'];
        $limitClause = ' ' . getPaginationClause($length, $start);
    }
    
    // Build final query
    $dataQuery = "SELECT " . $selectClause . $baseQuery . $whereClause . $orderClause . $limitClause;
    $dataResult = $db->query($dataQuery, $searchParams, $searchTypes);
    
    // Format data
    $data = [];
    while ($row = $dataResult->fetch()) {
        if ($showingAllCourses) {
            // All courses view - 4 columns
            $assessmentCount = (int)$row['assessment_count'];
            $rowData = [
                htmlspecialchars($row['discipline_name']),
                htmlspecialchars($row['course_display']),
                htmlspecialchars($row['section_id']),
                $assessmentCount > 0 ? 'Yes' : 'No'
            ];
        } else {
            // Single course view - 2 columns
            $assessmentCount = (int)$row['assessment_count'];
            $rowData = [
                htmlspecialchars($row['section_id']),
                $assessmentCount > 0 ? 'Yes' : 'No'
            ];
        }
        $data[] = $rowData;
    }
    
    // Build response
    $response = [
        'draw' => isset($_REQUEST['draw']) ? (int)$_REQUEST['draw'] : 1,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data' => $data
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    $errorReference = errorReference();
    error_log("Dashboard sections data error [{$errorReference}]: " . $e->getMessage());
    echo json_encode([
        'draw' => isset($_REQUEST['draw']) ? (int)$_REQUEST['draw'] : 1,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => DEBUG_MODE ? $e->getMessage() : "An error occurred. Reference: {$errorReference}"
    ]);
}
