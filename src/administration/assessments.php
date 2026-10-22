<?php
declare(strict_types=1);

/**
 * Assessments Administration
 * 
 * @package SLOCloud
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';

use SLOCloud\Core\StudentEncryption;

$successMessage = '';
$errorMessage = '';

// Initialize student encryption
$studentEncryption = new StudentEncryption($config);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die('This form has expired. Reload the page and try again.');
    }
    
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $errorMessage = 'Deletion is unavailable in the dashboard. Deactivate the record instead.';
        $action = '';
    }
    
    try {
        switch ($action) {
            case 'add':
                $enrollmentFk = (int)($_POST['enrollment_fk'] ?? 0);
                $sloFk = (int)($_POST['student_learning_outcome_fk'] ?? 0);
                $achievementLevel = trim($_POST['achievement_level'] ?? '');
                $assessedDate = trim($_POST['assessed_date'] ?? '');
                $assessorEmail = trim($_POST['assessor_email'] ?? '');
                
                $errors = [];
                if ($enrollmentFk <= 0) {
                    $errors[] = 'Enrollment is required';
                }
                if ($sloFk <= 0) {
                    $errors[] = 'Student Learning Outcome is required';
                }
                
                if (empty($errors)) {
                    $db->query(
                        "INSERT INTO {$dbPrefix}assessments 
                         (enrollment_fk, student_learning_outcome_fk, achievement_level, 
                          assessed_date, assessor_email, created_at, updated_at) 
                         VALUES (?, ?, ?, ?, ?, {$db->now()}, {$db->now()})",
                        [$enrollmentFk, $sloFk, $achievementLevel, $assessedDate, $assessorEmail],
                        'iisss'
                    );
                    $successMessage = 'Assessment added successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
                }
                break;
                
            case 'edit':
                $id = (int)($_POST['assessments_pk'] ?? 0);
                $enrollmentFk = (int)($_POST['enrollment_fk'] ?? 0);
                $sloFk = (int)($_POST['student_learning_outcome_fk'] ?? 0);
                $achievementLevel = trim($_POST['achievement_level'] ?? '');
                $assessedDate = trim($_POST['assessed_date'] ?? '');
                $assessorEmail = trim($_POST['assessor_email'] ?? '');
                
                $errors = [];
                if ($id <= 0) {
                    $errors[] = 'Invalid assessment ID';
                }
                if ($enrollmentFk <= 0) {
                    $errors[] = 'Enrollment is required';
                }
                if ($sloFk <= 0) {
                    $errors[] = 'Student Learning Outcome is required';
                }
                
                if (empty($errors)) {
                    $db->query(
                        "UPDATE {$dbPrefix}assessments 
                         SET enrollment_fk = ?, student_learning_outcome_fk = ?, 
                             achievement_level = ?, assessed_date = ?, assessor_email = ?,
                             updated_at = {$db->now()}
                         WHERE assessments_pk = ?",
                        [$enrollmentFk, $sloFk, $achievementLevel, $assessedDate, $assessorEmail, $id],
                        'iisssi'
                    );
                    $successMessage = 'Assessment updated successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
                }
                break;
                
            case 'delete':
                $id = (int)($_POST['assessments_pk'] ?? 0);
                if ($id > 0) {
                    $db->query(
                        "DELETE FROM {$dbPrefix}assessments WHERE assessments_pk = ?",
                        [$id],
                        'i'
                    );
                    $successMessage = 'Assessment deleted successfully';
                }
                break;
        }
    } catch (\Exception $e) {
        $errorReference = errorReference();
        error_log("Assessments error [{$errorReference}]: " . $e->getMessage());
        $errorMessage = DEBUG_MODE
            ? 'Database error: ' . $e->getMessage()
            : "Database error. Reference: {$errorReference}";
    }
    
    $_SESSION['success_message'] = $successMessage;
    $_SESSION['error_message'] = $errorMessage;
    header('Location: ' . BASE_URL . 'administration/assessments.php');
    exit;
}

if (isset($_SESSION['success_message'])) {
    $successMessage = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $errorMessage = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Get terms for dropdown
$termsResult = $db->query("
    SELECT terms_pk, term_code, term_name, academic_year
    FROM {$dbPrefix}terms
    ORDER BY term_code ASC
");
$terms = $termsResult->fetchAll();

// Get selected term (handled by getSelectedTermFk())
$selectedTermFk = getSelectedTermFk();

// Get selected term information from session (cached during getSelectedTermFk())
$selectedTermName = getSelectedTermName() ?? '';
$selectedTermCode = getSelectedTermCode() ?? '';

// Get statistics (filtered by selected term using banner_term)
$totalAssessments = 0;

if ($selectedTermFk) {
    $statsResult = $db->query("
        SELECT COUNT(*) as total
        FROM {$dbPrefix}assessments a
        INNER JOIN {$dbPrefix}enrollment e ON a.enrollment_fk = e.enrollment_pk
        INNER JOIN {$dbPrefix}sections sec ON sec.sections_pk = e.section_fk
        INNER JOIN {$dbPrefix}terms t ON t.terms_pk = sec.term_fk
        WHERE t.terms_pk = ? AND " . lifecycle_active_predicate('enrollment', 'e', $dbPrefix) . "
    ", [$selectedTermFk], 'i');
} else {
    $statsResult = $db->query("
        SELECT COUNT(*) as total
        FROM {$dbPrefix}assessments a
        WHERE " . lifecycle_active_predicate('assessments', 'a', $dbPrefix) . "
    ");
}
$stats = $statsResult->fetch();
$totalAssessments = (int)($stats['total'] ?? 0);

// Get enrollments for dropdowns - filtered by selected term for performance
if ($selectedTermFk) {
    $enrollmentsResult = $db->query("
        SELECT e.enrollment_pk, t.term_code, sec.crn,
               s.student_data_encrypted, s.student_id_hash
        FROM {$dbPrefix}enrollment e
        LEFT JOIN {$dbPrefix}students s ON e.student_fk = s.students_pk
        INNER JOIN {$dbPrefix}sections sec ON sec.sections_pk = e.section_fk
        INNER JOIN {$dbPrefix}terms t ON t.terms_pk = sec.term_fk
        WHERE t.terms_pk = ? AND " . lifecycle_active_predicate('enrollment', 'e', $dbPrefix) . "
        ORDER BY sec.crn
    ", [$selectedTermFk], 'i');
} else {
    // No term selected: load enrollments from the most recent term only
    $enrollmentsResult = $db->query("
        SELECT e.enrollment_pk, t.term_code, sec.crn,
               s.student_data_encrypted, s.student_id_hash
        FROM {$dbPrefix}enrollment e
        LEFT JOIN {$dbPrefix}students s ON e.student_fk = s.students_pk
        INNER JOIN {$dbPrefix}sections sec ON sec.sections_pk = e.section_fk
        INNER JOIN {$dbPrefix}terms t ON t.terms_pk = sec.term_fk
        WHERE t.term_code = (SELECT MAX(term_code) FROM {$dbPrefix}terms WHERE is_active = 1)
          AND " . lifecycle_active_predicate('enrollment', 'e', $dbPrefix) . "
        ORDER BY sec.crn
    ");
}
$enrollmentsRaw = $enrollmentsResult->fetchAll();

// Decrypt student data for display
$enrollments = [];
foreach ($enrollmentsRaw as $enrollment) {
    if (!is_string($enrollment['student_data_encrypted'] ?? null)) {
        // Enrollment whose student row is gone (SQL Server schema has no FKs).
        continue;
    }
    try {
        $studentData = $studentEncryption->decryptStudentData($enrollment['student_data_encrypted']);
        $enrollments[] = [
            'enrollment_pk' => $enrollment['enrollment_pk'],
            'term_code'     => $enrollment['term_code'],
            'crn'           => $enrollment['crn'],
            'student_id'    => $studentData['c_number'],
            'first_name'    => $studentData['first_name'] ?? '',
            'last_name'     => $studentData['last_name'] ?? '',
        ];
    } catch (\Exception $e) {
        error_log("Failed to decrypt student data for enrollment {$enrollment['enrollment_pk']}: " . $e->getMessage());
    }
}

// Get SLOs for dropdowns
$slosResult = $db->query("
    SELECT slo.student_learning_outcomes_pk, slo.slo_code, slo.slo_description, c.course_name, c.course_number
    FROM {$dbPrefix}student_learning_outcomes slo
    LEFT JOIN {$dbPrefix}courses c ON slo.course_fk = c.courses_pk
    WHERE " . lifecycle_active_predicate('student_learning_outcomes', 'slo', $dbPrefix) . "
    ORDER BY c.course_number, slo.sequence_num
");
$slos = $slosResult->fetchAll();

require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$pageTitle = 'Assessment Management';
if ($selectedTermName) {
    $pageTitle .= ' - ' . $selectedTermName;
    if ($selectedTermCode) {
        $pageTitle .= ' (' . $selectedTermCode . ')';
    }
}

$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => $pageTitle,
    'currentPage' => 'admin_assessments',
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['label' => 'Assessments']
    ]
]);

$theme = ThemeLoader::getActiveTheme(null, 'admin');
$theme->showHeader($context);
?>

<link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/datatables/1.13.7/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css">

<style>
    .modal-body {
        max-height: 70vh;
        overflow-y: auto;
    }
</style>

<div class="app-content">
    <div class="container-fluid">

<!-- Success/Error Messages -->
<?php if ($successMessage): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle" aria-hidden="true"></i> <?= $successMessage ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close alert"></button>
    </div>
<?php endif; ?>

<?php if ($errorMessage): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-triangle" aria-hidden="true"></i> <?= $errorMessage ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close alert"></button>
    </div>
<?php endif; ?>

<!-- Assessments Table -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title"><i class="fas fa-table" aria-hidden="true"></i> Assessments</h2>
    </div>
    <div class="card-body">
        <table id="assessmentsTable" class="table table-bordered table-striped table-hover" aria-label="Assessments data table">
            <caption class="visually-hidden">List of assessments with filtering and sorting capabilities</caption>
            <thead>
                <tr>
                    <th scope="col">ID</th>
                    <th scope="col">Term</th>
                    <th scope="col">CRN</th>
                    <th scope="col">Course</th>
                    <th scope="col">Student ID</th>
                    <th scope="col">SLO</th>
                    <th scope="col">Level</th>
                    <th scope="col">Date</th>
                    <th scope="col">Actions</th>
                </tr>
                <tr>
                    <td><input type="text" class="form-control form-control-sm" placeholder="Search ID" aria-label="Filter by ID"></td>
                    <td><input type="text" class="form-control form-control-sm" placeholder="Search Term" aria-label="Filter by Term"></td>
                    <td><input type="text" class="form-control form-control-sm" placeholder="Search CRN" aria-label="Filter by CRN"></td>
                    <td><input type="text" class="form-control form-control-sm" placeholder="Search Course" aria-label="Filter by Course"></td>
                    <td></td>
                    <td><input type="text" class="form-control form-control-sm" placeholder="Search SLO" aria-label="Filter by SLO"></td>
                    <td><input type="text" class="form-control form-control-sm" placeholder="Search Level" aria-label="Filter by Level"></td>
                    <td><input type="text" class="form-control form-control-sm" placeholder="Search Date" aria-label="Filter by Date"></td>
                    <td>&nbsp;</td>
                </tr>
            </thead>
        </table>
    </div>
</div>

    </div>
</div>

<!-- Add Assessment Modal -->
<div class="modal fade" id="addAssessmentModal" tabindex="-1" aria-labelledby="addAssessmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title" id="addAssessmentModalLabel"><i class="fas fa-plus" aria-hidden="true"></i> Add Assessment</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="add">
                    
                    <fieldset>
                        <legend class="h6 mb-3">Assessment Selection</legend>
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label for="enrollmentFk" class="form-label">Enrollment (Term CRN - Student) <span class="text-danger" aria-label="required">*</span></label>
                            <select class="form-select" id="enrollmentFk" name="enrollment_fk" required aria-required="true">
                                <option value="">Select Enrollment</option>
                                <?php foreach ($enrollments as $enrollment): 
                                    $studentName = htmlspecialchars(($enrollment['last_name'] ?? '') . ', ' . ($enrollment['first_name'] ?? ''));
                                    $displayText = htmlspecialchars($enrollment['term_code']) . ' CRN:' . htmlspecialchars($enrollment['crn'] ?? '') . ' - ' . htmlspecialchars($enrollment['student_id'] ?? '') . ' (' . trim($studentName, ', ') . ')';
                                ?>
                                    <option value="<?= $enrollment['enrollment_pk'] ?>">
                                        <?= $displayText ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label for="sloFk" class="form-label">Student Learning Outcome <span class="text-danger" aria-label="required">*</span></label>
                            <select class="form-select" id="sloFk" name="student_learning_outcome_fk" required aria-required="true">
                                <option value="">Select SLO</option>
                                <?php foreach ($slos as $slo): ?>
                                    <option value="<?= $slo['student_learning_outcomes_pk'] ?>">
                                        <?= htmlspecialchars($slo['course_number'] ?? '') ?> - <?= htmlspecialchars($slo['slo_code']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    </fieldset>
                    
                    <fieldset>
                        <legend class="h6 mb-3">Assessment Details</legend>
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="achievementLevel" class="form-label">Achievement Level</label>
                                <select class="form-select" id="achievementLevel" name="achievement_level">
                                    <option value="met">Met</option>
                                    <option value="partially_met">Partially Met</option>
                                    <option value="not_met">Not Met</option>
                                    <option value="not_assessed" selected>Not Assessed</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="assessedDate" class="form-label">Assessment Date</label>
                                <input type="date" class="form-control" id="assessedDate" name="assessed_date" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="assessorEmail" class="form-label">Assessor Email</label>
                                <input type="email" class="form-control" id="assessorEmail" name="assessor_email">
                            </div>
                        </div>
                        
                    </fieldset>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Assessment Modal -->
<div class="modal fade" id="editAssessmentModal" tabindex="-1" aria-labelledby="editAssessmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title" id="editAssessmentModalLabel"><i class="fas fa-edit" aria-hidden="true"></i> Edit Assessment</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST" id="editAssessmentForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="edit">
                    <?= lifecycle_edit_fields('assessments', 'assessments.php?status=' . lifecycle_view_mode('assessments')) ?>
                    <input type="hidden" name="assessments_pk" id="editAssessmentPk">
                    
                    <fieldset>
                        <legend class="h6 mb-3">Assessment Selection</legend>
                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label for="editEnrollmentFk" class="form-label">Enrollment (Term CRN - Student) <span class="text-danger" aria-label="required">*</span></label>
                            <select class="form-select" id="editEnrollmentFk" name="enrollment_fk" required aria-required="true">
                                <option value="">Select Enrollment</option>
                                <?php foreach ($enrollments as $enrollment): 
                                    $studentName = htmlspecialchars(($enrollment['last_name'] ?? '') . ', ' . ($enrollment['first_name'] ?? ''));
                                    $displayText = htmlspecialchars($enrollment['term_code']) . ' CRN:' . htmlspecialchars($enrollment['crn'] ?? '') . ' - ' . htmlspecialchars($enrollment['student_id'] ?? '') . ' (' . trim($studentName, ', ') . ')';
                                ?>
                                    <option value="<?= $enrollment['enrollment_pk'] ?>">
                                        <?= $displayText ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="col-md-4 mb-3">
                            <label for="editSloFk" class="form-label">Student Learning Outcome <span class="text-danger" aria-label="required">*</span></label>
                            <select class="form-select" id="editSloFk" name="student_learning_outcome_fk" required aria-required="true">
                                <option value="">Select SLO</option>
                                <?php foreach ($slos as $slo): ?>
                                    <option value="<?= $slo['student_learning_outcomes_pk'] ?>">
                                        <?= htmlspecialchars($slo['course_number'] ?? '') ?> - <?= htmlspecialchars($slo['slo_code']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    </fieldset>
                    
                    <fieldset>
                        <legend class="h6 mb-3">Assessment Details</legend>
                        <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="editAchievementLevel" class="form-label">Achievement Level</label>
                            <select class="form-select" id="editAchievementLevel" name="achievement_level">
                                <option value="met">Met</option>
                                <option value="partially_met">Partially Met</option>
                                <option value="not_met">Not Met</option>
                                <option value="not_assessed">Not Assessed</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="editAssessedDate" class="form-label">Assessment Date</label>
                            <input type="date" class="form-control" id="editAssessedDate" name="assessed_date">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="editAssessorEmail" class="form-label">Assessor Email</label>
                            <input type="email" class="form-control" id="editAssessorEmail" name="assessor_email">
                        </div>
                    </div>
                    
                    </fieldset>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <?= lifecycle_edit_controls() ?>
                    <!-- LEFT SIDE: Destructive Actions -->
                    <div>
                        <button type="button" class="btn btn-danger" onclick="confirmDeleteAssessment()" aria-label="Delete assessment">
                            <i class="fas fa-trash" aria-hidden="true"></i> Delete
                        </button>
                    </div>
                    <!-- RIGHT SIDE: Primary Actions -->
                    <div>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Save Changes</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<!-- Delete Confirmation Form (hidden) -->
<form method="POST" id="deleteForm" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="assessments_pk" id="deleteAssessmentId">
</form>


<?php $theme->showFooter($context); ?>

<script src="<?= BASE_URL ?>assets/vendor/datatables/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables-buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js"></script>

<script>
$(document).ready(function() {
    // Setup - add filter inputs to each header cell (second row)
    $('#assessmentsTable thead tr:eq(1) td').each(function(i) {
        var title = $('#assessmentsTable thead tr:eq(0) th:eq(' + i + ')').text();
        if (title === 'Actions' || title === 'ID') {
            $(this).html('');
        } else {
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder="Search ' + title + '" aria-label="Filter by ' + title + '" />');
        }
    });

    var table = $('#assessmentsTable').DataTable({
        orderCellsTop: true,
        processing: true,
        serverSide: true,
        ajax: {
            url: '<?= BASE_URL ?>administration/assessments_data.php',
            type: 'POST',
            data: function(d) {
                d.term_fk = <?= json_encode($selectedTermFk) ?>;
            }
        },
        dom: 'Brtip',
        buttons: ['copy', 'csv', 'print'],
        order: [[0, 'desc']],
        columns: [
            { data: 0, name: 'assessments_pk' },
            { data: 1, name: 'term_code' },
            { data: 2, name: 'crn' },
            { data: 3, name: 'course_number' },
            { data: 4, name: 'student_id', orderable: false, searchable: false },
            { data: 5, name: 'slo_code' },
            { data: 6, name: 'achievement_level' },
            { data: 7, name: 'assessed_date' },
            { data: 8, name: 'actions', orderable: false, searchable: false }
        ],
        language: {
            info: "Showing _START_ to _END_ of _TOTAL_ assessments",
            infoEmpty: "No assessments found",
            infoFiltered: "(filtered from _MAX_ total)",
            emptyTable: "No assessments available"
        },
        initComplete: function() {
            // Apply the search - target the second header row where filters are
            var api = this.api();
            api.columns().every(function(colIdx) {
                var column = this;
                // Find input in the second header row (tr:eq(1)) for this column
                $('input', $('#assessmentsTable thead tr:eq(1) td').eq(colIdx)).on('keyup change clear', function() {
                    if (column.search() !== this.value) {
                        column.search(this.value).draw();
                    }
                });
            });
        }
    });
});

function editAssessment(assessment) {
    $('#editAssessmentPk').val(assessment.assessments_pk);
    $('#editEnrollmentFk').val(assessment.enrollment_fk);
    $('#editSloFk').val(assessment.student_learning_outcome_fk);
    $('#editAchievementLevel').val(assessment.achievement_level);
    $('#editAssessedDate').val(assessment.assessed_date);
    $('#editAssessorEmail').val(assessment.assessor_email);
    configureLifecycleEditAction('#editAssessmentForm', assessment.assessments_pk, assessment.is_active, 'assessment', '#' + assessment.assessments_pk);
    new bootstrap.Modal(document.getElementById('editAssessmentModal')).show();
}

function deleteAssessment(id, displayId) {
    if (confirm('Are you sure you want to DELETE assessment #' + displayId + '? This action cannot be undone.')) {
        $('#deleteAssessmentId').val(id);
        $('#deleteForm').submit();
    }
}

function confirmDeleteAssessment() {
    const assessmentPk = $('#editAssessmentPk').val();
    deleteAssessment(assessmentPk, assessmentPk);
}
</script>

