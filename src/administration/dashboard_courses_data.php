<?php
declare(strict_types=1);

/**
 * Dashboard Courses AJAX Endpoint
 * 
 * Returns courses for a selected discipline (used in dashboard dropdowns).
 * 
 * @package SLOCloud\Administration
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';

header('Content-Type: application/json');

// Get filter parameters
$disciplineFk = isset($_REQUEST['discipline_fk']) ? $_REQUEST['discipline_fk'] : '';
$termFk = isset($_REQUEST['term_fk']) ? (int)$_REQUEST['term_fk'] : 0;

try {
    if ($termFk <= 0) {
        echo json_encode([]);
        exit;
    }
    
    // Handle "all disciplines" case
    if ($disciplineFk === 'all') {
        // Fetch all courses for the term with discipline information
        $result = $db->query(
            "SELECT c.courses_pk, c.course_number, c.course_name, d.discipline_code
             FROM {$dbPrefix}courses c
             LEFT JOIN {$dbPrefix}disciplines d ON c.discipline_fk = d.disciplines_pk
             WHERE c.term_fk = ? AND " . lifecycle_active_predicate('courses', 'c', $dbPrefix) . "
             ORDER BY d.discipline_code ASC, c.course_number ASC",
            [$termFk],
            'i'
        );
        
        $courses = [];
        while ($row = $result->fetch()) {
            $courses[] = [
                'courses_pk' => $row['courses_pk'],
                'course_number' => $row['course_number'],
                'course_name' => $row['course_name'],
                'discipline_code' => $row['discipline_code'] ?? ''
            ];
        }
        
        echo json_encode($courses);
    } else {
        // Fetch courses for specific discipline
        $disciplineFk = (int)$disciplineFk;
        
        if ($disciplineFk <= 0) {
            echo json_encode([]);
            exit;
        }
        
        $result = $db->query(
            "SELECT c.courses_pk, c.course_number, c.course_name
             FROM {$dbPrefix}courses c
             WHERE c.discipline_fk = ? AND c.term_fk = ? AND " . lifecycle_active_predicate('courses', 'c', $dbPrefix) . "
             ORDER BY course_number ASC",
            [$disciplineFk, $termFk],
            'ii'
        );
        
        $courses = [];
        while ($row = $result->fetch()) {
            $courses[] = [
                'courses_pk' => $row['courses_pk'],
                'course_number' => $row['course_number'],
                'course_name' => $row['course_name']
            ];
        }
        
        echo json_encode($courses);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch courses']);
    error_log('Dashboard courses error: ' . $e->getMessage());
}
