<?php
declare(strict_types=1);

/**
 * Students DataTables Server-Side Processing
 * 
 * @package SLOCloud
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';

use SLOCloud\Core\StudentEncryption;

header('Content-Type: application/json');

// Initialize student encryption
$studentEncryption = new StudentEncryption($config);

try {
    $draw = (int)($_REQUEST['draw'] ?? 1);
    $start = (int)($_REQUEST['start'] ?? 0);
    $length = (int)($_REQUEST['length'] ?? 10);
    $searchValue = $_REQUEST['search']['value'] ?? '';

    $orderColumnIndex = (int)($_REQUEST['order'][0]['column'] ?? 0);
    $orderDirection = strtoupper($_REQUEST['order'][0]['dir'] ?? 'ASC');
    if (!in_array($orderDirection, ['ASC', 'DESC'])) {
        $orderDirection = 'ASC';
    }

    $columns = ['student_id', 'student_name', 'created_at', 'created_by_name', 'updated_at', 'updated_by_name', 'actions'];
    $orderColumn = $columns[$orderColumnIndex] ?? 'students_pk';
    
    // Map friendly column names to database columns
    if ($orderColumn === 'student_id' || $orderColumn === 'student_name' || $orderColumn === 'actions') {
        // Cannot sort by encrypted data or actions column
        $orderColumn = 's.students_pk';
    } elseif ($orderColumn === 'created_by_name') {
        $orderColumn = 'u_created.full_name';
    } elseif ($orderColumn === 'updated_by_name') {
        $orderColumn = 'u_updated.full_name';
    } else {
        $orderColumn = 's.' . $orderColumn;
    }

    $columnSearch = [];
    foreach ($columns as $index => $column) {
        $searchVal = $_REQUEST['columns'][$index]['search']['value'] ?? '';
        if ($searchVal !== '') {
            $columnSearch[$column] = $searchVal;
        }
    }

    $where = [];
    $params = [];
    $types = '';
    $statusMode = lifecycle_view_mode('students');
    $where[] = lifecycle_status_predicate('students', 's', $dbPrefix, $statusMode);
    $globalSearch = trim((string)$searchValue);
    $studentIdFilter = trim((string)($columnSearch['student_id'] ?? ''));
    $studentNameFilter = trim((string)($columnSearch['student_name'] ?? ''));
    $requiresPostFilter = ($globalSearch !== '' || $studentIdFilter !== '' || $studentNameFilter !== '');

    // Global search is handled after decryption so encrypted student_id/name can be matched.
    // SQL WHERE below is limited to explicit non-encrypted column filters only.

    foreach ($columnSearch as $column => $value) {
        if ($column === 'created_by_name') {
            $where[] = "u_created.full_name LIKE ?";
            $params[] = "%{$value}%";
            $types .= 's';
        } elseif ($column === 'updated_by_name') {
            $where[] = "u_updated.full_name LIKE ?";
            $params[] = "%{$value}%";
            $types .= 's';
        } elseif (in_array($column, ['created_at', 'updated_at'])) {
            $where[] = "s." . $column . " LIKE ?";
            $params[] = "%{$value}%";
            $types .= 's';
        }
        // student_id and student_name are filtered post-decryption below.
    }

    $whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

    $totalResult = $db->query("SELECT COUNT(*) as total FROM {$dbPrefix}students");
    $totalRow = $totalResult->fetch();
    $totalRecords = $totalRow['total'];

    $countQuery = "SELECT COUNT(*) as total
                   FROM {$dbPrefix}students s
                   LEFT JOIN {$dbPrefix}users u_created ON s.created_by_fk = u_created.users_pk
                   LEFT JOIN {$dbPrefix}users u_updated ON s.updated_by_fk = u_updated.users_pk
                   {$whereClause}";
    
    if ($whereClause) {
        $filteredResult = $db->query($countQuery, $params, $types);
        $filteredRow = $filteredResult->fetch();
        $totalFiltered = $filteredRow['total'];
    } else {
        $totalFiltered = $totalRecords;
    }

    $sql = "SELECT s.students_pk, s.student_data_encrypted, s.student_id_hash, s.is_active,
                   s.created_at, s.updated_at,
                   s.created_by_fk, s.updated_by_fk,
                   u_created.full_name as created_by_name,
                   u_updated.full_name as updated_by_name
            FROM {$dbPrefix}students s
            LEFT JOIN {$dbPrefix}users u_created ON s.created_by_fk = u_created.users_pk
            LEFT JOIN {$dbPrefix}users u_updated ON s.updated_by_fk = u_updated.users_pk
            {$whereClause}
            ORDER BY {$orderColumn} {$orderDirection}";

    if (!$requiresPostFilter && $length !== -1) {
        $sql .= ' ' . getPaginationClause($length, $start);
    }

    $result = $db->query($sql, $params, $types);

    $rows = [];
    while ($row = fetch_row($result)) {
        // Decrypt student data for display
        try {
            $studentData = $studentEncryption->decryptStudentData($row['student_data_encrypted']);
            $studentId = $studentData['c_number'];
            $firstName = $studentData['first_name']; // Full name stored here
            $lastName = $studentData['last_name']; // May be empty
            // Use firstName if it contains full name, otherwise combine if both present
            $fullName = !empty($firstName) ? $firstName : trim($firstName . ' ' . $lastName);
        } catch (\Exception $e) {
            // Decryption failed - show error
            $studentId = '[DECRYPT ERROR]';
            $fullName = '[DECRYPT ERROR]';
            $firstName = '[DECRYPT ERROR]';
            $lastName = '[DECRYPT ERROR]';
            error_log("Failed to decrypt student data for PK {$row['students_pk']}: " . $e->getMessage());
        }
        
        // Prepare row data for edit modal (include decrypted values)
        $rowDataForModal = [
            'students_pk' => $row['students_pk'],
            'student_id' => $studentId,
            'student_name' => $fullName,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
            'created_by_name' => trim($row['created_by_name'] ?? '') ?: 'System',
            'updated_by_name' => trim($row['updated_by_name'] ?? '') ?: 'System',
            'is_active' => (bool)$row['is_active']
        ];
        $rowData = json_encode($rowDataForModal, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $rowDataAttribute = htmlspecialchars(base64_encode($rowData), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escapedStudentId = htmlspecialchars($studentId, ENT_QUOTES);
        
        $actions = '<div class="d-inline-flex flex-nowrap gap-1">'
            . related_records_button('students', (int)$row['students_pk'], $studentId)
            . '<button type="button" class="btn btn-warning js-edit-student" data-student="' . $rowDataAttribute . '" aria-label="Edit student ' . $escapedStudentId . '">
                <i class="fas fa-edit" aria-hidden="true"></i>
            </button></div>';
        
        $rows[] = [
            htmlspecialchars($studentId),
            htmlspecialchars($fullName),
            htmlspecialchars($row['created_at'] ?? ''),
            htmlspecialchars(trim($row['created_by_name'] ?? '') ?: 'System'),
            htmlspecialchars($row['updated_at'] ?? ''),
            htmlspecialchars(trim($row['updated_by_name'] ?? '') ?: 'System'),
            $actions
        ];
    }

    $data = $rows;
    if ($requiresPostFilter) {
        $searchNeedle = strtolower($globalSearch);
        $studentIdNeedle = strtolower($studentIdFilter);
        $studentNameNeedle = strtolower($studentNameFilter);

        $filteredRows = [];
        foreach ($rows as $rowData) {
            $rowStudentId = strtolower(html_entity_decode((string)$rowData[0], ENT_QUOTES | ENT_HTML5));
            $rowStudentName = strtolower(html_entity_decode((string)$rowData[1], ENT_QUOTES | ENT_HTML5));
            $rowCreatedAt = strtolower(html_entity_decode((string)$rowData[2], ENT_QUOTES | ENT_HTML5));
            $rowCreatedBy = strtolower(html_entity_decode((string)$rowData[3], ENT_QUOTES | ENT_HTML5));
            $rowUpdatedAt = strtolower(html_entity_decode((string)$rowData[4], ENT_QUOTES | ENT_HTML5));
            $rowUpdatedBy = strtolower(html_entity_decode((string)$rowData[5], ENT_QUOTES | ENT_HTML5));

            if ($studentIdNeedle !== '' && strpos($rowStudentId, $studentIdNeedle) === false) {
                continue;
            }
            if ($studentNameNeedle !== '' && strpos($rowStudentName, $studentNameNeedle) === false) {
                continue;
            }

            if ($searchNeedle !== '') {
                $globalMatched = (
                    strpos($rowStudentId, $searchNeedle) !== false ||
                    strpos($rowStudentName, $searchNeedle) !== false ||
                    strpos($rowCreatedAt, $searchNeedle) !== false ||
                    strpos($rowCreatedBy, $searchNeedle) !== false ||
                    strpos($rowUpdatedAt, $searchNeedle) !== false ||
                    strpos($rowUpdatedBy, $searchNeedle) !== false
                );
                if (!$globalMatched) {
                    continue;
                }
            }

            $filteredRows[] = $rowData;
        }

        $totalFiltered = count($filteredRows);
        if ($length === -1) {
            $data = $filteredRows;
        } else {
            $data = array_slice($filteredRows, $start, $length);
        }
    }

    echo json_encode([
        'draw' => $draw,
        'recordsTotal' => $totalRecords,
        'recordsFiltered' => $totalFiltered,
        'data' => $data
    ]);

} catch (Exception $e) {
    error_log("Error in students_data.php: " . $e->getMessage());
    echo json_encode([
        'draw' => $_REQUEST['draw'] ?? 1,
        'recordsTotal' => 0,
        'recordsFiltered' => 0,
        'data' => [],
        'error' => 'An error occurred while fetching data'
    ]);
}
