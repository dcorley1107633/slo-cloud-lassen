<?php
declare(strict_types=1);

/**
 * Assessments DataTables Server-Side Processing
 *
 * @package SLOCloud
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';

use SLOCloud\Core\StudentEncryption;

header('Content-Type: application/json');

try {
    $studentEncryption = new StudentEncryption($config);

    $draw        = (int)($_REQUEST['draw'] ?? 1);
    $start       = (int)($_REQUEST['start'] ?? 0);
    $length      = (int)($_REQUEST['length'] ?? 25);
    $searchValue = $_REQUEST['search']['value'] ?? '';
    $orderColIdx = (int)($_REQUEST['order'][0]['column'] ?? 0);
    $orderDir    = strtoupper($_REQUEST['order'][0]['dir'] ?? 'DESC') === 'DESC' ? 'DESC' : 'ASC';

    // Sortable columns matching DataTables column order:
    // 0=ID, 1=Term, 2=CRN, 3=Course, 4=Student(encrypted), 5=SLO, 6=Level, 7=Date, 8=Actions
    $columns = [
        0 => 'a.assessments_pk',
        1 => 't.term_code',
        2 => 'sec.crn',
        3 => 'c.course_number',
        4 => 'a.assessments_pk', // can't sort on encrypted field
        5 => 'slo.slo_code',
        6 => 'a.achievement_level',
        7 => 'a.assessed_date',
    ];
    $orderColumn = $columns[$orderColIdx] ?? 'a.assessments_pk';

    $where  = [];
    $params = [];
    $types  = '';
    $statusMode = lifecycle_view_mode('assessments');
    $where[] = lifecycle_status_predicate('assessments', 'a', $dbPrefix, $statusMode);

    // Term filter
    $termFkRaw = $_REQUEST['term_fk'] ?? null;
    $termFk = ($termFkRaw !== null && $termFkRaw !== '' && $termFkRaw !== 'null') ? (int)$termFkRaw : null;
    if ($termFk) {
        $where[]  = 't.terms_pk = ?';
        $params[] = $termFk;
        $types   .= 'i';
    }

    // Global search (term, crn, course, slo code, level)
    if ($searchValue !== '') {
        $where[] = '(t.term_code LIKE ? OR sec.crn LIKE ? OR c.course_number LIKE ? OR slo.slo_code LIKE ? OR a.achievement_level LIKE ?)';
        for ($i = 0; $i < 5; $i++) {
            $params[] = "%{$searchValue}%";
            $types   .= 's';
        }
    }

    // Column-level search
    $colSearchMap = [
        1 => 't.term_code',
        2 => 'sec.crn',
        3 => 'c.course_number',
        5 => 'slo.slo_code',
        6 => 'a.achievement_level',
        7 => 'a.assessed_date',
    ];
    foreach ($colSearchMap as $colIdx => $colExpr) {
        $val = $_REQUEST['columns'][$colIdx]['search']['value'] ?? '';
        if ($val !== '') {
            $where[]  = "{$colExpr} LIKE ?";
            $params[] = "%{$val}%";
            $types   .= 's';
        }
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $fromClause = "
        FROM {$dbPrefix}assessments a
        INNER JOIN {$dbPrefix}enrollment e ON a.enrollment_fk = e.enrollment_pk
        INNER JOIN {$dbPrefix}sections sec ON sec.sections_pk = e.section_fk
        INNER JOIN {$dbPrefix}terms t      ON t.terms_pk = sec.term_fk
        INNER JOIN {$dbPrefix}students s   ON e.student_fk = s.students_pk
        INNER JOIN {$dbPrefix}courses c    ON sec.course_fk = c.courses_pk
        LEFT JOIN  {$dbPrefix}student_learning_outcomes slo
                                           ON a.student_learning_outcome_fk = slo.student_learning_outcomes_pk
    ";

    // Total unfiltered
    $totalRow     = $db->query("SELECT COUNT(*) as total FROM {$dbPrefix}assessments")->fetch();
    $totalRecords = (int)($totalRow['total'] ?? 0);

    // Total filtered
    $filteredResult = $params
        ? $db->query("SELECT COUNT(*) as total {$fromClause} {$whereClause}", $params, $types)
        : $db->query("SELECT COUNT(*) as total {$fromClause} {$whereClause}");
    $filteredRow    = $filteredResult->fetch();
    $totalFiltered  = (int)($filteredRow['total'] ?? 0);

    // Data
    $sql = "
        SELECT a.assessments_pk,
               a.enrollment_fk,
               a.student_learning_outcome_fk,
               a.achievement_level,
               a.assessed_date,
               a.assessor_email,
               a.is_active,
               t.term_code,
               sec.crn,
               e.student_fk,
               c.course_number,
               s.student_data_encrypted,
               slo.slo_code,
               slo.slo_description
        {$fromClause}
        {$whereClause}
        ORDER BY {$orderColumn} {$orderDir}
    " . getPaginationClause($length, $start);

    $result = $params
        ? $db->query($sql, $params, $types)
        : $db->query($sql);
    $rows = $result->fetchAll();

    $data = [];
    foreach ($rows as $row) {
        $studentDisplay = 'Unknown';
        if (!empty($row['student_data_encrypted'])) {
            try {
                $studentData    = $studentEncryption->decryptStudentData($row['student_data_encrypted']);
                $studentDisplay = htmlspecialchars($studentData['c_number'] ?? '');
            } catch (\Exception $e) {
                $studentDisplay = 'Student #' . $row['student_fk'];
            }
        }

        $levelBadge = match($row['achievement_level']) {
            'met'           => '<span class="badge bg-success">Met</span>',
            'not_met'       => '<span class="badge bg-danger">Not Met</span>',
            'partially_met' => '<span class="badge bg-warning text-dark">Partially Met</span>',
            default         => '<span class="badge bg-secondary">Not Assessed</span>',
        };

        $rowJson = htmlspecialchars(json_encode([
            'assessments_pk'              => $row['assessments_pk'],
            'enrollment_fk'               => $row['enrollment_fk'],
            'student_learning_outcome_fk' => $row['student_learning_outcome_fk'],
            'achievement_level'           => $row['achievement_level'] ?? 'not_assessed',
            'assessed_date'               => $row['assessed_date'] ?? '',
            'assessor_email'              => $row['assessor_email'] ?? '',
            'is_active'                   => (bool)$row['is_active'],
        ]), ENT_QUOTES, 'UTF-8');

        $actions = '<button class="btn btn-warning btn-sm"
                            onclick=\'editAssessment(' . $rowJson . ')\'
                            aria-label="Edit assessment #' . $row['assessments_pk'] . '">
                        <i class="fas fa-edit" aria-hidden="true"></i>
                    </button>';

        $data[] = [
            $row['assessments_pk'],
            htmlspecialchars($row['term_code']),
            htmlspecialchars($row['crn'] ?? ''),
            htmlspecialchars($row['course_number'] ?? ''),
            $studentDisplay,
            htmlspecialchars($row['slo_code'] ?? ''),
            $levelBadge,
            htmlspecialchars($row['assessed_date'] ?? ''),
            $actions,
        ];
    }

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $totalRecords,
        'recordsFiltered' => $totalFiltered,
        'data'            => $data,
    ]);

} catch (\Exception $e) {
    $errorReference = errorReference();
    error_log("Assessments DataTables Error [{$errorReference}]: " . $e->getMessage());
    echo json_encode([
        'draw'            => (int)($_REQUEST['draw'] ?? 1),
        'recordsTotal'    => 0,
        'recordsFiltered' => 0,
        'data'            => [],
        'error'           => DEBUG_MODE ? $e->getMessage() : "An error occurred. Reference: {$errorReference}",
    ]);
}
