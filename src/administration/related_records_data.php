<?php
declare(strict_types=1);

/**
 * Direct-child dependency summaries for lifecycle-managed administration rows.
 *
 * @package SLOCloud
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';

header('Content-Type: application/json');

$entity = (string)($_GET['entity'] ?? '');
$recordId = filter_input(INPUT_GET, 'record_id', FILTER_VALIDATE_INT);
$displayLimit = 50;

$simpleGroup = static function (
    string $label,
    string $from,
    string $foreignKey,
    array $columns,
    string $select,
    string $orderBy
): array {
    return compact('label', 'from', 'foreignKey', 'columns', 'select', 'orderBy');
};

$definitions = [
    'terms' => [
        'table' => 'terms',
        'pk' => 'terms_pk',
        'groups' => [
            $simpleGroup('Institutional outcomes', "{$dbPrefix}institutional_outcomes child", 'child.term_fk', [
                ['key' => 'code', 'label' => 'Code'],
                ['key' => 'description', 'label' => 'Description'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
            ], 'child.outcome_code AS code, child.outcome_description AS description, child.is_active AS status', 'child.sequence_num ASC, child.outcome_code ASC'),
            $simpleGroup('Disciplines', "{$dbPrefix}disciplines child", 'child.term_fk', [
                ['key' => 'code', 'label' => 'Code'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
            ], 'child.discipline_code AS code, child.discipline_name AS name, child.is_active AS status', 'child.discipline_code ASC, child.discipline_name ASC'),
            $simpleGroup('Programs', "{$dbPrefix}programs child", 'child.term_fk', [
                ['key' => 'code', 'label' => 'Code'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
            ], 'child.program_code AS code, child.program_name AS name, child.is_active AS status', 'child.program_code ASC, child.program_name ASC'),
            $simpleGroup('Courses', "{$dbPrefix}courses child", 'child.term_fk', [
                ['key' => 'number', 'label' => 'Number'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
            ], 'child.course_number AS number, child.course_name AS name, child.is_active AS status', 'child.course_number ASC, child.course_name ASC'),
            $simpleGroup('Sections', "{$dbPrefix}sections child", 'child.term_fk', [
                ['key' => 'section', 'label' => 'Section'],
                ['key' => 'crn', 'label' => 'CRN'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
            ], 'child.section_id AS section, child.crn AS crn, child.is_active AS status', 'child.section_id ASC, child.crn ASC'),
            $simpleGroup('Cross-list entries', "{$dbPrefix}section_xlist child", 'child.term_fk', [
                ['key' => 'group_code', 'label' => 'Group'],
                ['key' => 'crn', 'label' => 'CRN'],
                ['key' => 'course', 'label' => 'Course'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
            ], 'child.xlist_group AS group_code, child.crn AS crn, child.course_number AS course, child.is_active AS status', 'child.xlist_group ASC, child.crn ASC'),
        ],
    ],
    'institutional_outcomes' => [
        'table' => 'institutional_outcomes',
        'pk' => 'institutional_outcomes_pk',
        'groups' => [
            $simpleGroup('Program outcomes', "{$dbPrefix}program_outcomes child", 'child.institutional_outcomes_fk', [
                ['key' => 'code', 'label' => 'Code'],
                ['key' => 'description', 'label' => 'Description'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
            ], 'child.outcome_code AS code, child.outcome_description AS description, child.is_active AS status', 'child.sequence_num ASC, child.outcome_code ASC'),
        ],
    ],
    'disciplines' => [
        'table' => 'disciplines',
        'pk' => 'disciplines_pk',
        'groups' => [
            $simpleGroup('Programs', "{$dbPrefix}programs child", 'child.discipline_fk', [
                ['key' => 'code', 'label' => 'Code'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'degree', 'label' => 'Degree type'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
            ], 'child.program_code AS code, child.program_name AS name, child.degree_type AS degree, child.is_active AS status', 'child.program_code ASC, child.program_name ASC'),
            $simpleGroup('Courses', "{$dbPrefix}courses child", 'child.discipline_fk', [
                ['key' => 'number', 'label' => 'Number'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
            ], 'child.course_number AS number, child.course_name AS name, child.is_active AS status', 'child.course_number ASC, child.course_name ASC'),
        ],
    ],
    'programs' => [
        'table' => 'programs',
        'pk' => 'programs_pk',
        'groups' => [
            $simpleGroup('Program outcomes', "{$dbPrefix}program_outcomes child", 'child.program_fk', [
                ['key' => 'code', 'label' => 'Code'],
                ['key' => 'description', 'label' => 'Description'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
            ], 'child.outcome_code AS code, child.outcome_description AS description, child.is_active AS status', 'child.sequence_num ASC, child.outcome_code ASC'),
            $simpleGroup('Assigned courses', "{$dbPrefix}program_courses link INNER JOIN {$dbPrefix}courses child ON link.course_fk = child.courses_pk", 'link.program_fk', [
                ['key' => 'number', 'label' => 'Course'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
            ], 'child.course_number AS number, child.course_name AS name, child.is_active AS status', 'child.course_number ASC, child.course_name ASC'),
        ],
    ],
    'program_outcomes' => [
        'table' => 'program_outcomes',
        'pk' => 'program_outcomes_pk',
        'groups' => [
            $simpleGroup('Student learning outcomes', "{$dbPrefix}student_learning_outcomes child", 'child.program_outcomes_fk', [
                ['key' => 'code', 'label' => 'Code'],
                ['key' => 'description', 'label' => 'Description'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
            ], 'child.slo_code AS code, child.slo_description AS description, child.is_active AS status', 'child.sequence_num ASC, child.slo_code ASC'),
        ],
    ],
    'courses' => [
        'table' => 'courses',
        'pk' => 'courses_pk',
        'groups' => [
            $simpleGroup('Program assignments', "{$dbPrefix}program_courses link INNER JOIN {$dbPrefix}programs child ON link.program_fk = child.programs_pk", 'link.course_fk', [
                ['key' => 'code', 'label' => 'Program'],
                ['key' => 'name', 'label' => 'Name'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
            ], 'child.program_code AS code, child.program_name AS name, child.is_active AS status', 'child.program_code ASC, child.program_name ASC'),
            $simpleGroup('Sections', "{$dbPrefix}sections child", 'child.course_fk', [
                ['key' => 'section', 'label' => 'Section'],
                ['key' => 'crn', 'label' => 'CRN'],
                ['key' => 'instructor', 'label' => 'Instructor'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
            ], 'child.section_id AS section, child.crn AS crn, child.instructor_name AS instructor, child.is_active AS status', 'child.section_id ASC, child.crn ASC'),
            $simpleGroup('Student learning outcomes', "{$dbPrefix}student_learning_outcomes child", 'child.course_fk', [
                ['key' => 'code', 'label' => 'Code'],
                ['key' => 'description', 'label' => 'Description'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
            ], 'child.slo_code AS code, child.slo_description AS description, child.is_active AS status', 'child.sequence_num ASC, child.slo_code ASC'),
        ],
    ],
    'sections' => [
        'table' => 'sections',
        'pk' => 'sections_pk',
        'groups' => [
            $simpleGroup('Enrollments', "{$dbPrefix}enrollment child", 'child.section_fk', [
                ['key' => 'id', 'label' => 'Enrollment ID'],
                ['key' => 'created', 'label' => 'Created'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
            ], 'child.enrollment_pk AS id, child.created_at AS created, child.is_active AS status', 'child.enrollment_pk ASC'),
        ],
    ],
    'student_learning_outcomes' => [
        'table' => 'student_learning_outcomes',
        'pk' => 'student_learning_outcomes_pk',
        'groups' => [
            $simpleGroup('Assessments', "{$dbPrefix}assessments child", 'child.student_learning_outcome_fk', [
                ['key' => 'level', 'label' => 'Achievement', 'type' => 'achievement'],
                ['key' => 'assessed_on', 'label' => 'Assessed'],
                ['key' => 'assessor', 'label' => 'Assessor'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
            ], 'child.achievement_level AS level, child.assessed_date AS assessed_on, child.assessor_email AS assessor, child.is_active AS status', 'child.assessed_date DESC, child.assessments_pk DESC'),
            $simpleGroup('Section assessment methods', "{$dbPrefix}section_slo_methods child", 'child.student_learning_outcome_fk', [
                ['key' => 'crn', 'label' => 'CRN'],
                ['key' => 'term', 'label' => 'Term'],
                ['key' => 'method', 'label' => 'Method'],
                ['key' => 'assessed_on', 'label' => 'Assessed'],
            ], 'child.crn AS crn, child.term_code AS term, child.assessment_method AS method, child.assessed_date AS assessed_on', 'child.term_code DESC, child.crn ASC'),
        ],
    ],
    'students' => [
        'table' => 'students',
        'pk' => 'students_pk',
        'groups' => [
            $simpleGroup('Enrollments', "{$dbPrefix}enrollment child INNER JOIN {$dbPrefix}sections section ON child.section_fk = section.sections_pk", 'child.student_fk', [
                ['key' => 'section', 'label' => 'Section'],
                ['key' => 'crn', 'label' => 'CRN'],
                ['key' => 'created', 'label' => 'Created'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
            ], 'section.section_id AS section, section.crn AS crn, child.created_at AS created, child.is_active AS status', 'child.created_at DESC, child.enrollment_pk DESC'),
        ],
    ],
    'enrollment' => [
        'table' => 'enrollment',
        'pk' => 'enrollment_pk',
        'groups' => [
            $simpleGroup('Assessments', "{$dbPrefix}assessments child", 'child.enrollment_fk', [
                ['key' => 'level', 'label' => 'Achievement', 'type' => 'achievement'],
                ['key' => 'assessed_on', 'label' => 'Assessed'],
                ['key' => 'assessor', 'label' => 'Assessor'],
                ['key' => 'status', 'label' => 'Status', 'type' => 'status'],
            ], 'child.achievement_level AS level, child.assessed_date AS assessed_on, child.assessor_email AS assessor, child.is_active AS status', 'child.assessed_date DESC, child.assessments_pk DESC'),
        ],
    ],
];

if (!isset($definitions[$entity]) || !$recordId || $recordId < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'A valid record type and ID are required.']);
    exit;
}

try {
    $definition = $definitions[$entity];
    $exists = $db->query(
        "SELECT {$definition['pk']} FROM {$dbPrefix}{$definition['table']} WHERE {$definition['pk']} = ?",
        [$recordId],
        'i'
    );
    if (!$exists->fetch()) {
        http_response_code(404);
        echo json_encode(['error' => 'Record not found.']);
        exit;
    }

    $groups = [];
    $pagination = getPaginationClause($displayLimit, 0);
    foreach ($definition['groups'] as $group) {
        $countResult = $db->query(
            "SELECT COUNT(*) AS total FROM {$group['from']} WHERE {$group['foreignKey']} = ?",
            [$recordId],
            'i'
        );
        $total = (int)($countResult->fetch()['total'] ?? 0);
        $recordsResult = $db->query(
            "SELECT {$group['select']} FROM {$group['from']}
             WHERE {$group['foreignKey']} = ?
             ORDER BY {$group['orderBy']} {$pagination}",
            [$recordId],
            'i'
        );
        $groups[] = [
            'label' => $group['label'],
            'columns' => $group['columns'],
            'total' => $total,
            'records' => fetch_all($recordsResult),
        ];
    }

    echo json_encode([
        'groups' => $groups,
        'display_limit' => $displayLimit,
    ]);
} catch (\Exception $e) {
    $errorReference = errorReference();
    error_log("Related records error [{$errorReference}]: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => DEBUG_MODE
            ? $e->getMessage()
            : "Unable to load related records. Reference: {$errorReference}",
    ]);
}
