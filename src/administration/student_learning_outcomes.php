<?php
declare(strict_types=1);

/**
 * Student Learning Outcomes (S Los) Administration
 * 
 * @package SLOCloud
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';

$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die('CSRF token validation failed');
    }
    
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $errorMessage = 'Deletion is unavailable in the dashboard. Deactivate the record instead.';
        $action = '';
    }
    
    try {
        switch ($action) {
            case 'add':
                $courseFk = (int)($_POST['course_fk'] ?? 0);
                $programOutcomesFk = !empty($_POST['program_outcomes_fk']) ? (int)$_POST['program_outcomes_fk'] : null;
                $sloCode = trim($_POST['slo_code'] ?? '');
                $sloDescription = trim($_POST['slo_description'] ?? '');
                $sequenceNum = (int)($_POST['sequence_num'] ?? 0);
                
                $errors = [];
                if ($courseFk <= 0) {
                    $errors[] = 'Course is required';
                }
                if (empty($sloCode)) {
                    $errors[] = 'SLO code is required';
                }
                if (empty($sloDescription)) {
                    $errors[] = 'SLO description is required';
                }
                
                if (empty($errors)) {
                    $userId = getValidUserId();
                    if ($programOutcomesFk !== null) {
                        $db->query(
                            "INSERT INTO {$dbPrefix}student_learning_outcomes (course_fk, program_outcomes_fk, slo_code, slo_description, sequence_num, created_at, updated_at, created_by_fk, updated_by_fk) 
                             VALUES (?, ?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                            [$courseFk, $programOutcomesFk, $sloCode, $sloDescription, $sequenceNum, $userId, $userId],
                            'iissiii'
                        );
                    } else {
                        $db->query(
                            "INSERT INTO {$dbPrefix}student_learning_outcomes (course_fk, slo_code, slo_description, sequence_num, created_at, updated_at, created_by_fk, updated_by_fk) 
                             VALUES (?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                            [$courseFk, $sloCode, $sloDescription, $sequenceNum, $userId, $userId],
                            'issiii'
                        );
                    }
                    $successMessage = 'SLO added successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
                }
                break;
                
            case 'edit':
                $id = (int)($_POST['slo_id'] ?? 0);
                $courseFk = (int)($_POST['course_fk'] ?? 0);
                $programOutcomesFk = !empty($_POST['program_outcomes_fk']) ? (int)$_POST['program_outcomes_fk'] : null;
                $sloCode = trim($_POST['slo_code'] ?? '');
                $sloDescription = trim($_POST['slo_description'] ?? '');
                $sequenceNum = (int)($_POST['sequence_num'] ?? 0);
                
                $errors = [];
                if ($id <= 0) {
                    $errors[] = 'Invalid SLO ID';
                }
                if ($courseFk <= 0) {
                    $errors[] = 'Course is required';
                }
                if (empty($sloCode)) {
                    $errors[] = 'SLO code is required';
                }
                if (empty($sloDescription)) {
                    $errors[] = 'SLO description is required';
                }
                
                if (empty($errors)) {
                    $userId = getValidUserId();
                    if ($programOutcomesFk !== null) {
                        $db->query(
                            "UPDATE {$dbPrefix}student_learning_outcomes 
                             SET course_fk = ?, program_outcomes_fk = ?, slo_code = ?, slo_description = ?, sequence_num = ?, updated_at = {$db->now()}, updated_by_fk = ?
                             WHERE student_learning_outcomes_pk = ?",
                            [$courseFk, $programOutcomesFk, $sloCode, $sloDescription, $sequenceNum, $userId, $id],
                            'iissiii'
                        );
                    } else {
                        $db->query(
                            "UPDATE {$dbPrefix}student_learning_outcomes 
                             SET course_fk = ?, program_outcomes_fk = NULL, slo_code = ?, slo_description = ?, sequence_num = ?, updated_at = {$db->now()}, updated_by_fk = ?
                             WHERE student_learning_outcomes_pk = ?",
                            [$courseFk, $sloCode, $sloDescription, $sequenceNum, $userId, $id],
                            'issiii'
                        );
                    }
                    $successMessage = 'SLO updated successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
                }
                break;
                
            case 'toggle_status':
                $id = (int)($_POST['slo_id'] ?? 0);
                if ($id > 0) {
                    $successMessage = 'SLO status updated';
                }
                break;
                
            case 'delete':
                $id = (int)($_POST['slo_id'] ?? 0);
                if ($id > 0) {
                    // Cascade delete: assessments will be automatically deleted by database
                    $db->query("DELETE FROM {$dbPrefix}student_learning_outcomes WHERE student_learning_outcomes_pk = ?", [$id], 'i');
                    $successMessage = 'SLO deleted successfully (including all assessments)';
                }
                break;        }
    } catch (\Exception $e) {
        $errorReference = errorReference();
        error_log(basename(__FILE__) . " error [{$errorReference}]: " . $e->getMessage());
        $errorMessage = DEBUG_MODE
            ? 'Operation failed: ' . htmlspecialchars($e->getMessage())
            : "Operation failed. Reference: {$errorReference}";
    }
}

// Get selected term (handled by getSelectedTermFk())
$selectedTermFk = getSelectedTermFk();

// Get selected term name from session (cached during getSelectedTermFk())
$selectedTermName = getSelectedTermName() ?? '';
$selectedTermCode = getSelectedTermCode() ?? '';

// Calculate statistics (filtered by term through courses)
if ($selectedTermFk) {
    $statsResult = $db->query("
        SELECT 
            COUNT(*) as total
        FROM {$dbPrefix}student_learning_outcomes slo
        LEFT JOIN {$dbPrefix}courses c ON slo.course_fk = c.courses_pk
        WHERE c.term_fk = ?
    ", [$selectedTermFk], 'i');
} else {
    $statsResult = $db->query("
        SELECT 
            COUNT(*) as total
        FROM {$dbPrefix}student_learning_outcomes slo
        LEFT JOIN {$dbPrefix}courses c ON slo.course_fk = c.courses_pk
    ");
}
$stats = fetch_row($statsResult);
$totalSLOs = $stats['total'];
$activeSLOs = $totalSLOs;

// Get courses for dropdown (filtered by selected term so added SLOs are visible)
if ($selectedTermFk) {
    $coursesResult = $db->query(
        "SELECT courses_pk, course_name, course_number FROM {$dbPrefix}courses WHERE term_fk = ? AND is_active = 1 ORDER BY course_name",
        [$selectedTermFk],
        'i'
    );
} else {
    $coursesResult = $db->query("SELECT courses_pk, course_name, course_number FROM {$dbPrefix}courses WHERE is_active = 1 ORDER BY course_name");
}
$courses = $coursesResult->fetchAll();

// Get program outcomes for dropdown
$programOutcomesResult = $db->query("SELECT program_outcomes_pk, outcome_code, outcome_description FROM {$dbPrefix}program_outcomes ORDER BY outcome_code");
$programOutcomes = $programOutcomesResult->fetchAll();

require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$pageTitle = 'Student Learning Outcomes';
if ($selectedTermName) {
    $pageTitle .= ' - ' . $selectedTermName;
    if ($selectedTermCode) {
        $pageTitle .= ' (' . $selectedTermCode . ')';
    }
}

$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => $pageTitle,
    'currentPage' => 'admin_slos',
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['label' => 'SLOs']
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
        
        <?php if ($successMessage): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle" aria-hidden="true"></i> <?= $successMessage ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if ($errorMessage): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle" aria-hidden="true"></i> <?= $errorMessage ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close alert"></button>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-table" aria-hidden="true"></i> Student Learning Outcomes</h2>
                <div class="card-tools">
                    <button type="button" class="btn btn-success btn-sm" onclick="exportAllCSLOs()" aria-label="Export all student learning outcome records to CSV">
                        <i class="fas fa-download" aria-hidden="true"></i> Export All to CSV
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addSLOModal" aria-label="Add new student learning outcome">
                        <i class="fas fa-plus" aria-hidden="true"></i> Add SLO
                    </button>
                </div>
            </div>
            <div class="card-body">
                <table id="slosTable" class="table table-bordered table-striped" aria-label="Student learning outcomes data table">
                    <caption class="visually-hidden">List of student learning outcomes with filtering and sorting capabilities</caption>
                    <thead>
                        <tr>
                            <th scope="col">Course</th>
                            <th scope="col">Program Outcome</th>
                            <th scope="col">Code</th>
                            <th scope="col">Description</th>
                            <th scope="col">Sequence</th>
                            <th scope="col">Created</th>
                            <th scope="col">Created By</th>
                            <th scope="col">Updated</th>
                            <th scope="col">Updated By</th>
                            <th scope="col">Actions</th>
                        </tr>
                        <tr>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                            <td>&nbsp;</td>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>


<!-- Add SLO Modal -->
<div class="modal fade" id="addSLOModal" tabindex="-1" aria-labelledby="addSLOModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title" id="addSLOModalLabel"><i class="fas fa-plus" aria-hidden="true"></i> Add Student Learning Outcome</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="mb-3">
                        <label for="courseFk" class="form-label">Course <span class="text-danger" aria-label="required">*</span></label>
                        <select class="form-select" id="courseFk" name="course_fk" required aria-required="true">
                            <option value="">Select Course</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?= $course['courses_pk'] ?>">
                                    <?= htmlspecialchars($course['course_name']) ?> (<?= htmlspecialchars($course['course_number']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="programOutcomesFk" class="form-label">Program Outcome <small class="text-muted">(Optional)</small></label>
                        <select class="form-select" id="programOutcomesFk" name="program_outcomes_fk">
                            <option value="">None</option>
                            <?php foreach ($programOutcomes as $po): ?>
                                <option value="<?= $po['program_outcomes_pk'] ?>">
                                    <?= htmlspecialchars($po['outcome_code']) ?> - <?= htmlspecialchars(substr($po['outcome_description'], 0, 60)) ?><?= strlen($po['outcome_description']) > 60 ? '...' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Links this SLO to a program-level outcome (optional)</div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="sloCode" class="form-label">SLO Code <span class="text-danger" aria-label="required">*</span></label>
                            <input type="text" class="form-control" id="sloCode" name="slo_code" maxlength="50" required aria-required="true">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="sequenceNum" class="form-label">Sequence Number</label>
                            <input type="number" class="form-control" id="sequenceNum" name="sequence_num" value="0">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="sloDescription" class="form-label">SLO Description <span class="text-danger" aria-label="required">*</span></label>
                        <textarea class="form-control" id="sloDescription" name="slo_description" rows="4" required aria-required="true"></textarea>
                    </div>
                    
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-plus" aria-hidden="true"></i> Add SLO</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit SLO Modal -->
<div class="modal fade" id="editSLOModal" tabindex="-1" aria-labelledby="editSLOModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title" id="editSLOModalLabel"><i class="fas fa-edit" aria-hidden="true"></i> Edit Student Learning Outcome</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST" id="editSLOForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="edit">
                    <?= lifecycle_edit_fields('student_learning_outcomes', 'student_learning_outcomes.php?status=' . lifecycle_view_mode('student_learning_outcomes')) ?>
                    <input type="hidden" name="slo_id" id="editSLOId">
                    
                    <div class="mb-3">
                        <label for="editCourseFk" class="form-label">Course <span class="text-danger" aria-label="required">*</span></label>
                        <select class="form-select" id="editCourseFk" name="course_fk" required aria-required="true">
                            <option value="">Select Course</option>
                            <?php foreach ($courses as $course): ?>
                                <option value="<?= $course['courses_pk'] ?>">
                                    <?= htmlspecialchars($course['course_name']) ?> (<?= htmlspecialchars($course['course_number']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editProgramOutcomesFk" class="form-label">Program Outcome <small class="text-muted">(Optional)</small></label>
                        <select class="form-select" id="editProgramOutcomesFk" name="program_outcomes_fk">
                            <option value="">None</option>
                            <?php foreach ($programOutcomes as $po): ?>
                                <option value="<?= $po['program_outcomes_pk'] ?>">
                                    <?= htmlspecialchars($po['outcome_code']) ?> - <?= htmlspecialchars(substr($po['outcome_description'], 0, 60)) ?><?= strlen($po['outcome_description']) > 60 ? '...' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Links this SLO to a program-level outcome (optional)</div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="editSLOCode" class="form-label">SLO Code <span class="text-danger" aria-label="required">*</span></label>
                            <input type="text" class="form-control" id="editSLOCode" name="slo_code" maxlength="50" required aria-required="true">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="editSequenceNum" class="form-label">Sequence Number</label>
                            <input type="number" class="form-control" id="editSequenceNum" name="sequence_num">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editSLODescription" class="form-label">SLO Description <span class="text-danger" aria-label="required">*</span></label>
                        <textarea class="form-control" id="editSLODescription" name="slo_description" rows="4" required aria-required="true"></textarea>
                    </div>
                    
                    <hr>
                    <div class="text-muted mb-3"><i class="fas fa-info-circle" aria-hidden="true"></i> Audit Information</div>
                    <div class="row">
                        <div class="col-md-6">
                            <small class="text-muted"><strong>Created:</strong></small>
                            <p id="editSLOCreated"></p>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted"><strong>Created By:</strong></small>
                            <p id="editSLOCreatedBy"></p>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted"><strong>Last Updated:</strong></small>
                            <p id="editSLOUpdated"></p>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted"><strong>Updated By:</strong></small>
                            <p id="editSLOUpdatedBy"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <?= lifecycle_edit_controls() ?>
                    <!-- LEFT SIDE: Destructive Actions -->
                    <div>
                        <button type="button" class="btn btn-danger" onclick="confirmDeleteSLO()" aria-label="Delete student learning outcome">
                            <i class="fas fa-trash" aria-hidden="true"></i> Delete
                        </button>
                    </div>
                    <!-- RIGHT SIDE: Primary Actions -->
                    <div>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Update</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="slo_id" id="deleteSLOId">
</form>

<?php $theme->showFooter($context); ?>

<script src="<?= BASE_URL ?>assets/vendor/datatables/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables-buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/jszip/3.10.1/jszip.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/pdfmake/0.2.7/pdfmake.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/pdfmake/0.2.7/vfs_fonts.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js"></script>

<script>
// Convert PHP arrays to JavaScript
var courses = <?= json_encode(array_map(function($c) { 
    return ['name' => $c['course_name'], 'number' => $c['course_number']]; 
}, $courses)) ?>;
var programOutcomes = <?= json_encode(array_map(function($po) { 
    return ['code' => $po['outcome_code']]; 
}, $programOutcomes)) ?>;

$(document).ready(function() {
    $('#slosTable thead tr:eq(1) td').each(function(i) {
        var title = $('#slosTable thead tr:eq(0) th:eq(' + i + ')').text();
        
        // Course column (index 1)
        if (title === 'Course') {
            var select = $('<select class="form-select form-select-sm" aria-label="Filter by ' + title + '"><option value="">All Courses</option></select>')
                .appendTo($(this).empty());
            courses.forEach(function(course) {
                select.append('<option value="' + course.name + '">' + course.name + '</option>');
            });
        }
        // Program Outcome column (index 2)
        else if (title === 'Program Outcome') {
            var select = $('<select class="form-select form-select-sm" aria-label="Filter by ' + title + '"><option value="">All Program Outcomes</option></select>')
                .appendTo($(this).empty());
            programOutcomes.forEach(function(po) {
                if (po.code) {
                    select.append('<option value="' + po.code + '">' + po.code + '</option>');
                }
            });
        }
        else if (title !== 'Actions') {
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder="Search ' + title + '" aria-label="Filter by ' + title + '" />');
        } else {
            $(this).html('');
        }
    });
    
    var table = $('#slosTable').DataTable({
        orderCellsTop: true,
        processing: true,
        serverSide: true,
        ajax: {
            url: '<?= BASE_URL ?>administration/student_learning_outcomes_data.php',
            type: 'POST',
            data: function(d) {
                d.term_fk = <?= json_encode($selectedTermFk) ?>;
            }
        },
        dom: 'rtip',
        columns: [
            { data: 0, name: 'course_name' },
            { data: 1, name: 'program_outcome_code' },
            { data: 2, name: 'slo_code' },
            { data: 3, name: 'slo_description' },
            { data: 4, name: 'sequence_num' },
            { data: 5, name: 'created_at' },
            { data: 6, name: 'created_by_name' },
            { data: 7, name: 'updated_at' },
            { data: 8, name: 'updated_by_name' },
            { data: 9, name: 'actions', orderable: false, searchable: false }
        ],
        language: {
            info: "Showing _START_ to _END_ of _TOTAL_ student learning outcomes",
            infoEmpty: "No student learning outcomes found",
            infoFiltered: "(filtered from _MAX_ total)",
            emptyTable: "No student learning outcomes available"
        },
        initComplete: function() {
            var api = this.api();
            
            // Apply the search
            api.columns().every(function(colIdx) {
                var column = this;
                
                // Find the input/select in the second header row for this column
                var filterCell = $('#slosTable thead tr:eq(1) td:eq(' + colIdx + ')');
                
                $('select', filterCell).on('change', function() {
                    var val = $(this).val();
                    column.search(val ? val : '', true, false).draw();
                });
                
                $('input', filterCell).on('keyup change clear', function() {
                    var val = $(this).val();
                    if (column.search() !== val) {
                        column.search(val).draw();
                    }
                });
            });
        }
    });

});

function editSLO(slo) {
    $('#editSLOId').val(slo.student_learning_outcomes_pk);
    $('#editCourseFk').val(slo.course_fk);
    $('#editProgramOutcomesFk').val(slo.program_outcomes_fk || '');
    $('#editSLOCode').val(slo.slo_code);
    $('#editSLODescription').val(slo.slo_description);
    $('#editSequenceNum').val(slo.sequence_num);
    
    // Populate audit information
    $('#editSLOCreated').text(slo.created_at || 'N/A');
    $('#editSLOCreatedBy').text(slo.created_by_name || 'System');
    $('#editSLOUpdated').text(slo.updated_at || 'N/A');
    $('#editSLOUpdatedBy').text(slo.updated_by_name || 'System');
    configureLifecycleEditAction('#editSLOForm', slo.student_learning_outcomes_pk, slo.is_active, 'student learning outcome', slo.slo_code || '');
    new bootstrap.Modal(document.getElementById('editSLOModal')).show();
}

function deleteSLO(id, code) {
    if (confirm('Are you sure you want to DELETE SLO "' + code + '"?\n\nThis will also delete:\n- All assessments for this SLO\n\nThis action cannot be undone.')) {
        $('#deleteSLOId').val(id);
        $('#deleteForm').submit();
    }
}

function confirmDeleteSLO() {
    const sloPk = $('#editSLOId').val();
    const sloCode = $('#editSLOCode').val();
    deleteSLO(sloPk, sloCode);
}

function exportAllCSLOs() {
    var button = event.target.closest('button');
    var originalHTML = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
    button.disabled = true;
    
    $.ajax({
        url: '<?= BASE_URL ?>administration/student_learning_outcomes_data.php',
        data: {
            start: 0,
            length: -1,
            draw: 1,
            term_fk: <?= json_encode($selectedTermFk) ?>
        },
        success: function(response) {
            var csvContent = "Course,Program Outcome,Code,Description,Sequence,Created At,Created By,Updated At,Updated By\n";
            
            response.data.forEach(function(row) {
                var course = escapeCSV(row[0]);
                var programOutcome = escapeCSV($(row[1]).text().trim());
                var code = escapeCSV(row[2]);
                var description = escapeCSV(row[3]);
                var sequence = escapeCSV(row[4]);
                var createdAt = escapeCSV(row[5]);
                var createdBy = escapeCSV(row[6]);
                var updatedAt = escapeCSV(row[7]);
                var updatedBy = escapeCSV(row[8]);
                
                csvContent += course + "," + programOutcome + "," + code + "," + description + "," + sequence + "," + createdAt + "," + createdBy + "," + updatedAt + "," + updatedBy + "\n";
            });
            
            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement("a");
            var url = URL.createObjectURL(blob);
            link.setAttribute("href", url);
            link.setAttribute("download", "student_learning_outcomes_export_" + new Date().toISOString().slice(0,10) + ".csv");
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            button.innerHTML = originalHTML;
            button.disabled = false;
        },
        error: function() {
            alert('Error exporting data. Please try again.');
            button.innerHTML = originalHTML;
            button.disabled = false;
        }
    });
}

function escapeCSV(str) {
    if (str === null || str === undefined) return '""';
    str = String(str);
    if (str.includes('"') || str.includes(',') || str.includes('\n')) {
        return '"' + str.replace(/"/g, '""') + '"';
    }
    return str;
}
</script>
