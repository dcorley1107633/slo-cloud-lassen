<?php
declare(strict_types=1);

/**
 * Enrollment DataTables Server-Side Processing
 * 
 * Handles AJAX requests for enrollment data with pagination, sorting, and filtering.
 * 
 * @package SLOCloud
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';

use SLOCloud\Core\StudentEncryption;

header('Content-Type: application/json');

try {
    // Initialize student encryption
    $studentEncryption = new StudentEncryption($config);
    
    // DataTables parameters
    $draw = (int)($_REQUEST['draw'] ?? 1);
    $start = (int)($_REQUEST['start'] ?? 0);
    $length = (int)($_REQUEST['length'] ?? 10);
    $searchValue = $_REQUEST['search']['value'] ?? '';
    $orderColumnIndex = (int)($_REQUEST['order'][0]['column'] ?? 0);
    $orderDir = $_REQUEST['order'][0]['dir'] ?? 'asc';

    // Column definitions (must match table columns)
    $columns = [
        't.term_code',
        'sec.crn',
        'e.student_fk', // Student ID column (will decrypt for display)
        'e.created_at',
        'u_created.full_name',
        'e.updated_at',
        'u_updated.full_name',
        'actions' // Not sortable, placeholder
    ];

    // Validate order direction
    $orderDir = strtoupper($orderDir) === 'DESC' ? 'DESC' : 'ASC';

    // Get order column name (prevent SQL injection)
    $orderColumn = $columns[$orderColumnIndex] ?? 'e.updated_at';
    if ($orderColumn === 'actions') {
        $orderColumn = 'e.updated_at'; // Default for actions column
    }

    // Build WHERE clause for global search
$whereConditions = [];
$statusMode = lifecycle_view_mode('enrollment');
$whereConditions[] = lifecycle_status_predicate('enrollment', 'e', $dbPrefix, $statusMode);
    $params = [];
    $types = '';

    // Term filter (enrollment is scoped through its section).
    $termFkRaw = $_REQUEST['term_fk'] ?? null;
    $termFk = ($termFkRaw !== null && $termFkRaw !== '' && $termFkRaw !== 'null') ? (int)$termFkRaw : null;
    if ($termFk) {
        $whereConditions[] = 't.terms_pk = ?';
        $params[] = $termFk;
        $types .= 'i';
    }

    if (!empty($searchValue)) {
        $searchConditions = [
            't.term_code LIKE ?',
            'sec.crn LIKE ?'
        ];
        $whereConditions[] = '(' . implode(' OR ', $searchConditions) . ')';
        $searchParam = "%{$searchValue}%";
        for ($i = 0; $i < count($searchConditions); $i++) {
            $params[] = $searchParam;
            $types .= 's';
        }
    }

    // Add column-specific filters
    for ($i = 0; $i < count($columns) - 1; $i++) { // Exclude actions column
        $columnSearch = $_REQUEST['columns'][$i]['search']['value'] ?? '';
        if (!empty($columnSearch) && $columns[$i] !== 'actions') {
            if ($columns[$i] === 'u_created.full_name' || $columns[$i] === 'u_updated.full_name') {
                $whereConditions[] = "{$columns[$i]} LIKE ?";
            } else {
                $whereConditions[] = "{$columns[$i]} LIKE ?";
            }
            $params[] = "%{$columnSearch}%";
            $types .= 's';
        }
    }

    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

    // Get total records (filtered by current term if selected)
    if ($termFk) {
        $totalResult = $db->query(
            "SELECT COUNT(*) as total
             FROM {$dbPrefix}enrollment e
             INNER JOIN {$dbPrefix}sections sec ON sec.sections_pk = e.section_fk
             INNER JOIN {$dbPrefix}terms t ON t.terms_pk = sec.term_fk
             WHERE t.terms_pk = ?",
            [$termFk],
            'i'
        );
    } else {
        $totalResult = $db->query("SELECT COUNT(*) as total FROM {$dbPrefix}enrollment");
    }
    $totalRow = fetch_row($totalResult);
    $recordsTotal = $totalRow['total'];

    // Get filtered records count
    $countQuery = "
        SELECT COUNT(*) as total 
        FROM {$dbPrefix}enrollment e
        INNER JOIN {$dbPrefix}sections sec ON sec.sections_pk = e.section_fk
        INNER JOIN {$dbPrefix}terms t ON t.terms_pk = sec.term_fk
        LEFT JOIN {$dbPrefix}students s ON e.student_fk = s.students_pk
        LEFT JOIN {$dbPrefix}users u_created ON e.created_by_fk = u_created.users_pk
        LEFT JOIN {$dbPrefix}users u_updated ON e.updated_by_fk = u_updated.users_pk
        {$whereClause}
    ";
    if (!empty($params)) {
        $filteredResult = $db->query($countQuery, $params, $types);
    } else {
        $filteredResult = $db->query($countQuery);
    }
    $filteredRow = fetch_row($filteredResult);
    $recordsFiltered = $filteredRow['total'];

    // Get data
    $dataQuery = "
        SELECT 
            e.enrollment_pk,
            t.term_code,
            sec.crn,
            e.student_fk,
            e.is_active,
            s.student_data_encrypted,
            e.created_at,
            e.updated_at,
            e.created_by_fk,
            e.updated_by_fk,
            u_created.full_name as created_by_name,
            u_updated.full_name as updated_by_name
        FROM {$dbPrefix}enrollment e
        INNER JOIN {$dbPrefix}sections sec ON sec.sections_pk = e.section_fk
        INNER JOIN {$dbPrefix}terms t ON t.terms_pk = sec.term_fk
        LEFT JOIN {$dbPrefix}students s ON e.student_fk = s.students_pk
        LEFT JOIN {$dbPrefix}users u_created ON e.created_by_fk = u_created.users_pk
        LEFT JOIN {$dbPrefix}users u_updated ON e.updated_by_fk = u_updated.users_pk
        {$whereClause}
        ORDER BY {$orderColumn} {$orderDir}
        " . getPaginationClause($length, $start) . "
    ";

    $result = $db->query($dataQuery, $params, $types);
    $enrollments = fetch_all($result);

    // Format data for DataTables
    $data = [];
    foreach ($enrollments as $row) {
        // Decrypt student data (FERPA: only show C-number, not full name in listing)
        $studentDisplay = 'Unknown';
        if (!empty($row['student_data_encrypted'])) {
            try {
                $studentData = $studentEncryption->decryptStudentData($row['student_data_encrypted']);
                $studentDisplay = htmlspecialchars($studentData['c_number']);
            } catch (Exception $e) {
                error_log("Failed to decrypt student data for student_fk={$row['student_fk']}: " . $e->getMessage());
                $studentDisplay = "Student #{$row['student_fk']}";
            }
        }
        
        // Prepare row data for edit button (without sensitive student data)
        $rowData = [
            'enrollment_pk' => $row['enrollment_pk'],
            'term_code' => $row['term_code'],
            'crn' => $row['crn'],
            'student_fk' => $row['student_fk'],
            'student_display' => $studentDisplay,
            'is_active' => (bool)$row['is_active']
        ];
        $rowJson = htmlspecialchars(json_encode($rowData), ENT_QUOTES, 'UTF-8');
        
        $data[] = [
            htmlspecialchars($row['term_code']),
            htmlspecialchars($row['crn']),
            $studentDisplay,
            htmlspecialchars($row['created_at'] ?? ''),
            htmlspecialchars(trim($row['created_by_name'] ?? '') ?: 'System'),
            htmlspecialchars($row['updated_at'] ?? ''),
            htmlspecialchars(trim($row['updated_by_name'] ?? '') ?: 'System'),
            '<div class="d-inline-flex flex-nowrap gap-1">'
                . related_records_button('enrollment', (int)$row['enrollment_pk'], strip_tags($studentDisplay))
                . '<button class="btn btn-warning" onclick=\'editEnrollment(' . $rowJson . ')\' aria-label="Edit enrollment for ' . $studentDisplay . '"><i class="fas fa-edit" aria-hidden="true"></i></button>'
                . '</div>'
        ];
    }

    // Return JSON response
    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $recordsTotal,
        'recordsFiltered' => $recordsFiltered,
        'data' => $data
    ]);

} catch (Exception $e) {
    error_log("Error in enrollment_data.php: " . $e->getMessage());
    echo json_encode([
        'draw' => $_REQUEST['draw'] ?? 1,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'An error occurred while fetching data'
    ]);
}
