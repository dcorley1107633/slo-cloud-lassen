<?php
declare(strict_types=1);

/**
 * SLOCloud Administration Dashboard
 * 
 * Main landing page for administrative interface.
 * Shows key metrics and system status.
 * 
 * @package SLOCloud\Administration
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';

// Authentication is handled by init.php automatically for /administration/ pages

// Get selected term
$selectedTermFk = getSelectedTermFk();
$selectedTermName = getSelectedTermName() ?? 'No Term Selected';
$selectedTermCode = getSelectedTermCode() ?? '';

// Fetch term-specific statistics
$stats = [
    'programs' => 0,
    'courses' => 0,
    'sections' => 0,
    'sections_with_evaluations' => 0,
    'sections_without_evaluations' => 0,
    'students' => 0,
    'enrollments' => 0,
    'institutional_outcomes' => 0,
    'program_outcomes' => 0,
    'student_learning_outcomes' => 0,
    'assessments' => 0
];
$disciplineCourseStats = [];

try {
    if ($selectedTermFk) {
        // Count programs for selected term
        $result = $db->query(
            "SELECT COUNT(*) as count FROM {$dbPrefix}programs p WHERE term_fk = ? AND " . lifecycle_active_predicate('programs', 'p', $dbPrefix),
            [$selectedTermFk],
            'i'
        );
        $row = $db->normalizeRow($result->fetch());
        $stats['programs'] = (int)($row['count'] ?? 0);
        
        // Count courses for selected term
        $result = $db->query(
            "SELECT COUNT(*) as count FROM {$dbPrefix}courses c WHERE term_fk = ? AND " . lifecycle_active_predicate('courses', 'c', $dbPrefix),
            [$selectedTermFk],
            'i'
        );
        $row = $db->normalizeRow($result->fetch());
        $stats['courses'] = (int)($row['count'] ?? 0);
        
        // Count sections for selected term
        $result = $db->query(
            "SELECT COUNT(*) as count FROM {$dbPrefix}sections s WHERE term_fk = ? AND " . lifecycle_active_predicate('sections', 's', $dbPrefix),
            [$selectedTermFk],
            'i'
        );
        $row = $db->normalizeRow($result->fetch());
        $stats['sections'] = (int)($row['count'] ?? 0);
        
        // A section is considered assessed if it has at least one student assessment.
        $result = $db->query(
            "SELECT COUNT(DISTINCT s.sections_pk) as count 
             FROM {$dbPrefix}sections s
             JOIN {$dbPrefix}enrollment e ON e.section_fk = s.sections_pk
             JOIN {$dbPrefix}assessments a ON e.enrollment_pk = a.enrollment_fk
             WHERE s.term_fk = ? AND " . lifecycle_active_predicate('sections', 's', $dbPrefix)
                . " AND " . lifecycle_active_predicate('enrollment', 'e', $dbPrefix)
                . " AND " . lifecycle_active_predicate('assessments', 'a', $dbPrefix),
            [$selectedTermFk],
            'i'
        );
        $row = $db->normalizeRow($result->fetch());
        $stats['sections_with_evaluations'] = (int)($row['count'] ?? 0);
        $stats['sections_without_evaluations'] = $stats['sections'] - $stats['sections_with_evaluations'];
        
        // Count students (all active students, not term-specific)
        $result = $db->query(
            "SELECT COUNT(*) as count FROM {$dbPrefix}students s WHERE " . lifecycle_active_predicate('students', 's', $dbPrefix)
        );
        $row = $db->normalizeRow($result->fetch());
        $stats['students'] = (int)($row['count'] ?? 0);
        
        // Count enrollments for selected term
        $result = $db->query(
            "SELECT COUNT(DISTINCT e.enrollment_pk) as count 
             FROM {$dbPrefix}enrollment e
             JOIN {$dbPrefix}sections s ON s.sections_pk = e.section_fk
             WHERE s.term_fk = ? AND " . lifecycle_active_predicate('enrollment', 'e', $dbPrefix),
            [$selectedTermFk],
            'i'
        );
        $row = $db->normalizeRow($result->fetch());
        $stats['enrollments'] = (int)($row['count'] ?? 0);
        
        // Count institutional outcomes for selected term
        $result = $db->query(
            "SELECT COUNT(*) as count FROM {$dbPrefix}institutional_outcomes io WHERE term_fk = ? AND " . lifecycle_active_predicate('institutional_outcomes', 'io', $dbPrefix),
            [$selectedTermFk],
            'i'
        );
        $row = $db->normalizeRow($result->fetch());
        $stats['institutional_outcomes'] = (int)($row['count'] ?? 0);
        
        // Count program outcomes for selected term
        $result = $db->query(
            "SELECT COUNT(DISTINCT po.program_outcomes_pk) as count 
             FROM {$dbPrefix}program_outcomes po
             JOIN {$dbPrefix}programs p ON po.program_fk = p.programs_pk
             WHERE p.term_fk = ? AND " . lifecycle_active_predicate('program_outcomes', 'po', $dbPrefix),
            [$selectedTermFk],
            'i'
        );
        $row = $db->normalizeRow($result->fetch());
        $stats['program_outcomes'] = (int)($row['count'] ?? 0);
        
        // Count student learning outcomes (CSLOs) for selected term
        $result = $db->query(
            "SELECT COUNT(DISTINCT slo.student_learning_outcomes_pk) as count 
             FROM {$dbPrefix}student_learning_outcomes slo
             JOIN {$dbPrefix}courses c ON slo.course_fk = c.courses_pk
             WHERE c.term_fk = ? AND " . lifecycle_active_predicate('student_learning_outcomes', 'slo', $dbPrefix),
            [$selectedTermFk],
            'i'
        );
        $row = $db->normalizeRow($result->fetch());
        $stats['student_learning_outcomes'] = (int)($row['count'] ?? 0);
        
        // Count assessments for selected term
        $result = $db->query(
            "SELECT COUNT(DISTINCT a.assessments_pk) as count 
             FROM {$dbPrefix}assessments a
             JOIN {$dbPrefix}enrollment e ON a.enrollment_fk = e.enrollment_pk
             JOIN {$dbPrefix}sections s ON s.sections_pk = e.section_fk
             WHERE s.term_fk = ? AND " . lifecycle_active_predicate('assessments', 'a', $dbPrefix),
            [$selectedTermFk],
            'i'
        );
        $row = $db->normalizeRow($result->fetch());
        $stats['assessments'] = (int)($row['count'] ?? 0);

        // Compare course inventory and assessment participation by discipline.
        $result = $db->query(
            "SELECT d.disciplines_pk, d.discipline_code, d.discipline_name,
                    COUNT(DISTINCT c.courses_pk) AS course_count,
                    COUNT(DISTINCT CASE
                        WHEN a.assessments_pk IS NOT NULL THEN c.courses_pk
                        ELSE NULL
                    END) AS assessed_course_count
             FROM {$dbPrefix}courses c
             LEFT JOIN {$dbPrefix}disciplines d
                    ON c.discipline_fk = d.disciplines_pk
             LEFT JOIN {$dbPrefix}sections s
                    ON s.course_fk = c.courses_pk
                   AND s.term_fk = c.term_fk
             LEFT JOIN {$dbPrefix}enrollment e
                    ON e.section_fk = s.sections_pk
             LEFT JOIN {$dbPrefix}assessments a
                    ON a.enrollment_fk = e.enrollment_pk
             WHERE c.term_fk = ? AND " . lifecycle_active_predicate('courses', 'c', $dbPrefix) . "
             GROUP BY d.disciplines_pk, d.discipline_code, d.discipline_name
             ORDER BY d.discipline_code ASC, d.discipline_name ASC",
            [$selectedTermFk],
            'i'
        );
        foreach ($result->fetchAll() as $disciplineRow) {
            $disciplineRow = $db->normalizeRow($disciplineRow);
            $code = trim((string)($disciplineRow['discipline_code'] ?? ''));
            $name = trim((string)($disciplineRow['discipline_name'] ?? ''));
            $label = $code !== '' && $name !== '' ? $code . ' - ' . $name : ($code !== '' ? $code : $name);
            $disciplineCourseStats[] = [
                'label' => $label !== '' ? $label : 'Unassigned discipline',
                'course_count' => (int)($disciplineRow['course_count'] ?? 0),
                'assessed_course_count' => (int)($disciplineRow['assessed_course_count'] ?? 0),
            ];
        }
    }
} catch (Exception $e) {
    // Tables might not exist yet - that's okay for new installations
    error_log('Dashboard statistics error: ' . $e->getMessage());
}

// Load theme system (auto-loads ThemeContext and Theme)
require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => 'Administration Dashboard',
    'currentPage' => 'admin_dashboard',
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['label' => 'Dashboard']
    ]
]);

$theme = ThemeLoader::getActiveTheme(null, 'admin');
$theme->showHeader($context);
?>

<!-- Term Information -->
<section aria-labelledby="term-info-heading" class="mb-4">
    <div class="row">
        <div class="col-12">
            <div class="card card-primary">
                <div class="card-header">
                    <h2 id="term-info-heading" class="card-title">
                        <i class="fas fa-calendar-alt" aria-hidden="true"></i> Selected Term
                    </h2>
                </div>
                <div class="card-body">
                    <h3 class="text-lg"><?= htmlspecialchars((string)$selectedTermName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
                    <?php if ($selectedTermCode): ?>
                        <p class="text-muted mb-0">Term Code: <?= htmlspecialchars((string)$selectedTermCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Section Statistics -->
<?php if ($selectedTermFk): ?>
<section aria-labelledby="statistics-heading" class="mb-4">
    <div class="row">
        <div class="col-12">
            <h2 id="statistics-heading" class="mb-3">Section Statistics</h2>
        </div>
    </div>
    
    <div class="row">
        <div class="col-12 col-sm-6 col-md-4">
            <div class="info-box">
                <span class="info-box-icon bg-primary elevation-1"><i class="fas fa-chalkboard-teacher"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Total Sections</span>
                    <span class="info-box-number"><?= number_format($stats['sections']) ?></span>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <div class="info-box">
                <span class="info-box-icon bg-success elevation-1"><i class="fas fa-check-circle"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">With Evaluations</span>
                    <span class="info-box-number"><?= number_format($stats['sections_with_evaluations']) ?></span>
                </div>
            </div>
        </div>
        <div class="col-12 col-sm-6 col-md-4">
            <div class="info-box">
                <span class="info-box-icon bg-warning elevation-1"><i class="fas fa-exclamation-triangle"></i></span>
                <div class="info-box-content">
                    <span class="info-box-text">Without Evaluations</span>
                    <span class="info-box-number"><?= number_format($stats['sections_without_evaluations']) ?></span>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Course assessment chart -->
<section aria-labelledby="course-assessment-charts-heading" class="mb-4">
    <div class="row">
        <div class="col-12">
            <h2 id="course-assessment-charts-heading" class="mb-3">Courses with Assessments</h2>
        </div>
    </div>

    <?php if ($disciplineCourseStats !== []): ?>
    <div class="row g-4">
        <div class="col-12">
            <div class="card h-100">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-clipboard-check" aria-hidden="true"></i> Assessment Coverage by Discipline
                    </h3>
                </div>
                <div class="card-body">
                    <p class="text-muted">Each bar shows the share of courses with at least one recorded assessment. Counts are shown as (assessed/total).</p>
                    <div class="dashboard-bar-chart" role="list" aria-label="Courses with assessments by discipline">
                        <?php foreach ($disciplineCourseStats as $discipline):
                            $width = $discipline['course_count'] > 0
                                ? ($discipline['assessed_course_count'] / $discipline['course_count']) * 100
                                : 0;
                        ?>
                        <div class="dashboard-bar-row" role="listitem" aria-label="<?= htmlspecialchars($discipline['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>: <?= number_format($discipline['assessed_course_count']) ?> of <?= number_format($discipline['course_count']) ?> courses have assessments">
                            <div class="dashboard-bar-label" title="<?= htmlspecialchars($discipline['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                                <?= htmlspecialchars($discipline['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </div>
                            <div class="dashboard-bar-track" aria-hidden="true">
                                <div class="dashboard-bar-fill dashboard-bar-fill-assessed" style="width: <?= number_format($width, 2, '.', '') ?>%;"></div>
                            </div>
                            <div class="dashboard-bar-value">(<?= number_format($discipline['assessed_course_count']) ?>/<?= number_format($discipline['course_count']) ?>)</div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle" aria-hidden="true"></i> No course data is available for the selected term.
    </div>
    <?php endif; ?>
</section>

<style>
.dashboard-bar-chart {
    display: grid;
    gap: .8rem;
}

.dashboard-bar-row {
    align-items: center;
    display: grid;
    gap: .65rem;
    grid-template-columns: minmax(7rem, 14rem) minmax(8rem, 1fr) 7rem;
}

.dashboard-bar-label {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.dashboard-bar-track {
    background: var(--bs-secondary-bg, #e9ecef);
    border-radius: .3rem;
    height: 1.15rem;
    overflow: hidden;
}

.dashboard-bar-fill {
    border-radius: inherit;
    height: 100%;
    min-width: 0;
}

.dashboard-bar-fill-assessed {
    background: #198754;
}

.dashboard-bar-value {
    font-variant-numeric: tabular-nums;
    font-weight: 600;
    text-align: right;
}

@media (max-width: 575.98px) {
    .dashboard-bar-row {
        grid-template-columns: minmax(6rem, 8rem) minmax(4rem, 1fr) 5.5rem;
    }
}

@media (forced-colors: active) {
    .dashboard-bar-track,
    .dashboard-bar-fill {
        border: 1px solid CanvasText;
    }
}
</style>

<?php else: ?>
<section aria-labelledby="no-term-heading" class="mb-4">
    <div class="row">
        <div class="col-12">
            <div class="alert alert-info">
                <h2 id="no-term-heading" class="alert-heading">
                    <i class="fas fa-info-circle" aria-hidden="true"></i> No Term Selected
                </h2>
                <p class="mb-0">Please select a term to view statistics.</p>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php $theme->showFooter($context); ?>
