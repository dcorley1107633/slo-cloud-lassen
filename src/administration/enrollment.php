<?php
declare(strict_types=1);

/**
 * Enrollment Administration
 * 
 * Manage student enrollments by term and Section ID.
 * 
 * @package SLOCloud
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';

use SLOCloud\Core\StudentEncryption;

// Handle POST requests
$successMessage = '';
$errorMessage = '';

// Initialize student encryption
$studentEncryption = new StudentEncryption($config);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die('This form has expired. Reload the page and try again.');
    }
    
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $errorMessage = 'Deletion is unavailable in the dashboard. Deactivate the record instead.';
        $action = '';
    }
    $isSqlServer = ($db->getDriver() === 'mssql' || $db->getDriver() === 'sqlsrv');
    $nowFunc = $db->now();
    
    try {
        switch ($action) {
            case 'add':
                $sectionFk = (int)($_POST['section_fk'] ?? 0);
                $studentCNum = trim($_POST['student_c_number'] ?? '');
                
                // Validation
                $errors = [];
                if ($sectionFk <= 0) {
                    $errors[] = 'Section is required';
                } else {
                    $sectionResult = $db->query(
                        "SELECT sections_pk FROM {$dbPrefix}sections s WHERE sections_pk = ? AND " . lifecycle_active_predicate('sections', 's', $dbPrefix),
                        [$sectionFk],
                        'i'
                    );
                    if (!$sectionResult->fetch()) {
                        $errors[] = 'Invalid section selected';
                    }
                }
                if (empty($studentCNum)) {
                    $errors[] = 'Student C-number is required';
                }
                
                // Find or create student
                if (empty($errors)) {                    $hash = $studentEncryption->hashCNumber($studentCNum);
                    $studentResult = $db->query(
                        "SELECT students_pk FROM {$dbPrefix}students WHERE student_id_hash = ?",
                        [$hash],
                        's'
                    );
                    
                    // rowCount() is -1 for a SELECT on SQL Server, so test the row itself.
                    $student = fetch_row($studentResult);
                    if (!$student) {
                        // Auto-create student with minimal data (C# only, no names yet)
                        $userId = getValidUserId();
                        $encrypted = $studentEncryption->encryptStudentData($studentCNum, '', '');
                        $db->query(
                            "INSERT INTO {$dbPrefix}students (student_data_encrypted, student_id_hash, created_at, updated_at, created_by_fk, updated_by_fk) 
                             VALUES (?, ?, {$nowFunc}, {$nowFunc}, ?, ?)",
                            [$encrypted['encrypted'], $encrypted['hash'], $userId, $userId],
                            'ssii'
                        );
                        $studentFk = $db->getInsertId();
                    } else {
                        $studentFk = $student['students_pk'];
                    }
                    
                    // Check if enrollment already exists
                    $checkResult = $db->query(
                        "SELECT e.enrollment_pk 
                         FROM {$dbPrefix}enrollment e
                         WHERE e.section_fk = ? AND e.student_fk = ?",
                        [$sectionFk, $studentFk],
                        'ii'
                    );
                    
                    if (fetch_row($checkResult)) {
                        $errors[] = 'Enrollment already exists for this student in this section';
                    } else {
                        // Insert enrollment
                        $userId = getValidUserId();
                        $db->query(
                            "INSERT INTO {$dbPrefix}enrollment (section_fk, student_fk, created_at, updated_at, created_by_fk, updated_by_fk) 
                             VALUES (?, ?, {$nowFunc}, {$nowFunc}, ?, ?)",
                            [$sectionFk, $studentFk, $userId, $userId],
                            'iiii'
                        );
                        $successMessage = 'Enrollment added successfully';
                    }
                }
                
                if (!empty($errors)) {
                    $errorMessage = implode('<br>', $errors);
                }
                break;
                
            case 'edit':
                $id = (int)($_POST['enrollment_id'] ?? 0);
                
                if ($id > 0) {
                    // Note: Currently no editable fields for enrollment
                    // This case exists for future extensibility
                    $successMessage = 'Enrollment updated successfully';
                } else {
                    $errorMessage = 'Invalid enrollment ID';
                }
                break;
                
            case 'delete':
                $id = (int)($_POST['enrollment_id'] ?? 0);
                if ($id > 0) {
                    $db->query(
                        "DELETE FROM {$dbPrefix}enrollment WHERE enrollment_pk = ?",
                        [$id],
                        'i'
                    );
                    
                    // Clean up students with no remaining enrollments
                    cleanupOrphanedStudents();
                    
                    $successMessage = 'Enrollment deleted successfully';
                }
                break;        }
    } catch (\Exception $e) {
        $errorReference = errorReference();
        error_log(basename(__FILE__) . " error [{$errorReference}]: " . $e->getMessage());
        $errorMessage = DEBUG_MODE
            ? 'Operation failed: ' . htmlspecialchars($e->getMessage())
            : "Operation failed. Reference: {$errorReference}";
        if (DEBUG_MODE) {
            $errorMessage .= '<br><br><strong>Debug Information:</strong><br>';
            $errorMessage .= '<pre style="text-align: left; font-size: 12px;">';
            $errorMessage .= 'File: ' . htmlspecialchars($e->getFile()) . '<br>';
            $errorMessage .= 'Line: ' . htmlspecialchars((string)$e->getLine()) . '<br>';
            $errorMessage .= 'Trace:<br>' . htmlspecialchars($e->getTraceAsString());
            $errorMessage .= '</pre>';
        }
    }
}

// Get terms for dropdown (sorted descending with latest first)
// Get selected term (from header dropdown/session)
$selectedTermFk = getSelectedTermFk();

// Get selected term name from session (cached during getSelectedTermFk())
$selectedTermName = getSelectedTermName() ?? '';
$selectedTermCode = getSelectedTermCode() ?? '';

// Sections are the enrollment owner. Limit the picker to the selected term
// when one is active, while still supporting an all-terms administration view.
if ($selectedTermFk) {
    $sectionsResult = $db->query(
        "SELECT sec.sections_pk, sec.section_id, sec.crn, t.term_code, c.course_number
         FROM {$dbPrefix}sections sec
         INNER JOIN {$dbPrefix}terms t ON t.terms_pk = sec.term_fk
         INNER JOIN {$dbPrefix}courses c ON c.courses_pk = sec.course_fk
         WHERE sec.term_fk = ?
         ORDER BY c.course_number, sec.section_id, sec.crn",
        [$selectedTermFk],
        'i'
    );
} else {
    $sectionsResult = $db->query(
        "SELECT sec.sections_pk, sec.section_id, sec.crn, t.term_code, c.course_number
         FROM {$dbPrefix}sections sec
         INNER JOIN {$dbPrefix}terms t ON t.terms_pk = sec.term_fk
         INNER JOIN {$dbPrefix}courses c ON c.courses_pk = sec.course_fk
         ORDER BY t.term_code DESC, c.course_number, sec.section_id, sec.crn"
    );
}
$availableSections = $sectionsResult->fetchAll();

// Calculate statistics (filtered by term)
if ($selectedTermFk) {
    $statsResult = $db->query("
        SELECT COUNT(*) as total
        FROM {$dbPrefix}enrollment e
        INNER JOIN {$dbPrefix}sections sec ON sec.sections_pk = e.section_fk
        INNER JOIN {$dbPrefix}terms t ON t.terms_pk = sec.term_fk
        WHERE t.terms_pk = ?
    ", [$selectedTermFk], 'i');
} else {
    $statsResult = $db->query("
        SELECT COUNT(*) as total
        FROM {$dbPrefix}enrollment e
    ");
}
$stats = fetch_row($statsResult);
$totalEnrollments = $stats['total'];

// Load theme system
require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$pageTitle = 'Enrollment Management';
if ($selectedTermName) {
    $pageTitle .= ' - ' . $selectedTermName;
    if ($selectedTermCode) {
        $pageTitle .= ' (' . $selectedTermCode . ')';
    }
}

$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => $pageTitle,
    'currentPage' => 'admin_enrollment',
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['label' => 'Enrollment']
    ]
]);

$theme = ThemeLoader::getActiveTheme(null, 'admin');
$theme->showHeader($context);
?>

<!-- DataTables CSS -->
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

        <!-- Enrollment Table -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-table" aria-hidden="true"></i> Student Enrollments</h2>
                <div class="card-tools">
                    <button type="button" class="btn btn-success btn-sm" onclick="exportAllEnrollments()" aria-label="Export all enrollment records to CSV">
                        <i class="fas fa-download" aria-hidden="true"></i> Export All to CSV
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addEnrollmentModal" aria-label="Add new enrollment">
                        <i class="fas fa-plus" aria-hidden="true"></i> Add Enrollment
                    </button>
                </div>
            </div>
            <div class="card-body">
                <table id="enrollmentTable" class="table table-bordered table-striped" aria-label="Student enrollments data table">
                    <caption class="visually-hidden">List of student enrollments with filtering and sorting capabilities</caption>
                    <thead>
                        <tr>
                            <th scope="col">Term Code</th>
                            <th scope="col">Section ID</th>
                            <th scope="col">Student C-Number</th>
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
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Data loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>


<!-- Add Enrollment Modal -->
<div class="modal fade" id="addEnrollmentModal" tabindex="-1" aria-labelledby="addEnrollmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title" id="addEnrollmentModalLabel"><i class="fas fa-plus" aria-hidden="true"></i> Add Enrollment</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label for="sectionFk" class="form-label">Section <span class="text-danger" aria-label="required">*</span></label>
                        <select class="form-select" id="sectionFk" name="section_fk" required aria-required="true" aria-describedby="sectionFkHelp">
                            <option value="">Select section</option>
                            <?php foreach ($availableSections as $section): ?>
                                <option value="<?= (int)$section['sections_pk'] ?>">
                                    <?= htmlspecialchars(
                                        $section['term_code'] . ' · ' . $section['course_number']
                                        . ' · ' . $section['section_id']
                                        . ($section['crn'] !== null && $section['crn'] !== '' ? ' · CRN ' . $section['crn'] : '')
                                    ) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small id="sectionFkHelp" class="form-text text-muted">Enrollments belong to a specific course section.</small>
                    </div>
                    <div class="mb-3">
                        <label for="studentCNumber" class="form-label">Student C-Number <span class="text-danger" aria-label="required">*</span></label>
                        <input type="text" class="form-control" id="studentCNumber" name="student_c_number" maxlength="20" required aria-required="true" placeholder="e.g., C00001001">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Enrollment Modal -->
<div class="modal fade" id="editEnrollmentModal" tabindex="-1" aria-labelledby="editEnrollmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title" id="editEnrollmentModalLabel"><i class="fas fa-edit" aria-hidden="true"></i> Edit Enrollment</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST" id="editEnrollmentForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="edit">
                    <?= lifecycle_edit_fields('enrollment', 'enrollment.php?status=' . lifecycle_view_mode('enrollment')) ?>
                    <input type="hidden" name="enrollment_id" id="editEnrollmentId">
                    <div class="alert alert-info">
                        <strong>Note:</strong> Term Code, Section ID, and Student C-Number cannot be changed. Delete and recreate enrollment if needed.
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <strong>Term Code:</strong>
                            <p id="editTermCode"></p>
                        </div>
                        <div class="col-md-4">
                            <strong>Section ID:</strong>
                            <p id="editCrn"></p>
                        </div>
                        <div class="col-md-4">
                            <strong>Student C-Number:</strong>
                            <p id="editStudentCNumber"></p>
                        </div>
                    </div>
                    
                    <hr>
                    <div class="text-muted mb-3"><i class="fas fa-info-circle" aria-hidden="true"></i> Audit Information</div>
                    <div class="row">
                        <div class="col-md-6">
                            <small class="text-muted"><strong>Created:</strong></small>
                            <p id="editEnrollmentCreated"></p>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted"><strong>Created By:</strong></small>
                            <p id="editEnrollmentCreatedBy"></p>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted"><strong>Last Updated:</strong></small>
                            <p id="editEnrollmentUpdated"></p>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted"><strong>Updated By:</strong></small>
                            <p id="editEnrollmentUpdatedBy"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <?= lifecycle_edit_controls() ?>
                    <!-- LEFT SIDE: Destructive Actions -->
                    <div>
                        <button type="button" class="btn btn-danger" onclick="confirmDeleteEnrollment()" aria-label="Delete enrollment">
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

<!-- View Enrollment Modal -->
<div class="modal fade" id="viewEnrollmentModal" tabindex="-1" aria-labelledby="viewEnrollmentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h2 class="modal-title h5" id="viewEnrollmentModalLabel"><i class="fas fa-eye" aria-hidden="true"></i> Enrollment Details</h2>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Term Code:</strong>
                        <p id="viewTermCode"></p>
                    </div>
                    <div class="col-md-6">
                        <strong>Section ID:</strong>
                        <p id="viewCrn"></p>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Student C-Number:</strong>
                        <p id="viewStudentCNumber"></p>
                    </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>ID:</strong>
                        <p id="viewEnrollmentId"></p>
                    </div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-md-6">
                        <strong>Created:</strong>
                        <p id="viewEnrollmentCreated"></p>
                    </div>
                    <div class="col-md-6">
                        <strong>Created By:</strong>
                        <p id="viewEnrollmentCreatedBy"></p>
                    </div>
                    <div class="col-md-6">
                        <strong>Last Updated:</strong>
                        <p id="viewEnrollmentUpdated"></p>
                    </div>
                    <div class="col-md-6">
                        <strong>Updated By:</strong>
                        <p id="viewEnrollmentUpdatedBy"></p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Form (hidden) -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="enrollment_id" id="deleteEnrollmentId">
</form>

<?php $theme->showFooter($context); ?>

<!-- DataTables JS -->
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
$(document).ready(function() {
    // Setup - add a text input to each header cell (second row)
    $('#enrollmentTable thead tr:eq(1) td').each(function(i) {
        var title = $('#enrollmentTable thead tr:eq(0) th:eq(' + i + ')').text();
        if (title !== 'Actions') {
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder="Search ' + title + '" aria-label="Filter by ' + title + '" />');
        } else {
            $(this).html(''); // No filter for Actions column
        }
    });
    
    var table = $('#enrollmentTable').DataTable({
        orderCellsTop: true,
        processing: true,
        serverSide: true,
        ajax: {
            url: '<?= BASE_URL ?>administration/enrollment_data.php',
            type: 'POST',
            data: function(d) {
                d.term_fk = <?= json_encode($selectedTermFk) ?>;
            }
        },
        dom: 'rtip',
        columns: [
            { data: 0, name: 'term_code' },
            { data: 1, name: 'crn' },
            { data: 2, name: 'student_id' },
            { data: 3, name: 'created_at' },
            { data: 4, name: 'created_by_name' },
            { data: 5, name: 'updated_at' },
            { data: 6, name: 'updated_by_name' },
            { data: 7, name: 'actions', orderable: false, searchable: false }
        ],
        order: [[5, 'desc']], // Sort by last updated
        language: {
            info: "Showing _START_ to _END_ of _TOTAL_ enrollments",
            infoEmpty: "No enrollments found",
            infoFiltered: "(filtered from _MAX_ total)",
            emptyTable: "No enrollments available"
        },
        initComplete: function() {
            // Apply the search - target the second header row where filters are
            var api = this.api();
            api.columns().every(function(colIdx) {
                var column = this;
                // Find input in the second header row (tr:eq(1)) for this column
                $('input, select', $('#enrollmentTable thead tr:eq(1) td').eq(colIdx)).on('keyup change clear', function() {
                    if (column.search() !== this.value) {
                        column.search(this.value).draw();
                    }
                });
            });
        }
    });

});

function viewEnrollment(enroll) {
    $('#viewTermCode').text(enroll.term_code);
    $('#viewCrn').text(enroll.crn);
    $('#viewStudentCNumber').text(enroll.student_id);
    $('#viewEnrollmentId').text(enroll.enrollment_pk);
    $('#viewEnrollmentCreated').text(enroll.created_at || 'N/A');
    $('#viewEnrollmentCreatedBy').text(enroll.created_by_name || 'System');
    $('#viewEnrollmentUpdated').text(enroll.updated_at || 'N/A');
    $('#viewEnrollmentUpdatedBy').text(enroll.updated_by_name || 'System');
    new bootstrap.Modal(document.getElementById('viewEnrollmentModal')).show();
}

function editEnrollment(enroll) {
    $('#editEnrollmentId').val(enroll.enrollment_pk);
    $('#editTermCode').text(enroll.term_code);
    $('#editCrn').text(enroll.crn);
    $('#editStudentCNumber').text(enroll.student_id);
    
    // Populate audit information
    $('#editEnrollmentCreated').text(enroll.created_at || 'N/A');
    $('#editEnrollmentCreatedBy').text(enroll.created_by_name || 'System');
    $('#editEnrollmentUpdated').text(enroll.updated_at || 'N/A');
    $('#editEnrollmentUpdatedBy').text(enroll.updated_by_name || 'System');
    configureLifecycleEditAction('#editEnrollmentForm', enroll.enrollment_pk, enroll.is_active, 'enrollment', enroll.student_display || '');
    new bootstrap.Modal(document.getElementById('editEnrollmentModal')).show();
}

function deleteEnrollment(id, cnum, crn) {
    if (confirm('Are you sure you want to DELETE enrollment for student ' + cnum + ' in CRN ' + crn + '? This action cannot be undone.')) {
        $('#deleteEnrollmentId').val(id);
        $('#deleteForm').submit();
    }
}

function confirmDeleteEnrollment() {
    const enrollmentPk = $('#editEnrollmentId').val();
    const studentId = $('#editStudentCNumber').text();
    const crn = $('#editCrn').text();
    deleteEnrollment(enrollmentPk, studentId, crn);
}

function getStatusClass(status) {
    switch(status.toLowerCase()) {
        case 'enrolled': return 'success';
        case 'completed': return 'primary';
        case 'dropped': return 'warning';
        case 'withdrawn': return 'danger';
        default: return 'secondary';
    }
}

function exportAllEnrollments() {
    var button = event.target.closest('button');
    var originalHTML = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
    button.disabled = true;
    
    $.ajax({
        url: '<?= BASE_URL ?>administration/enrollment_data.php',
        data: {
            start: 0,
            length: -1,
            draw: 1,
            term_fk: <?= json_encode($selectedTermFk) ?>
        },
        success: function(response) {
            var csvContent = "Term Code,Section ID,Student C-Number,Created At,Created By,Updated At,Updated By\n";
            
            response.data.forEach(function(row) {
                var termCode = escapeCSV(row[0]);
                var crn = escapeCSV(row[1]);
                var studentId = escapeCSV(row[2]);
                var createdAt = escapeCSV(row[3]);
                var createdBy = escapeCSV(row[4]);
                var updatedAt = escapeCSV(row[5]);
                var updatedBy = escapeCSV(row[6]);
                
                csvContent += termCode + "," + crn + "," + studentId + "," + createdAt + "," + createdBy + "," + updatedAt + "," + updatedBy + "\n";
            });
            
            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement("a");
            var url = URL.createObjectURL(blob);
            link.setAttribute("href", url);
            link.setAttribute("download", "enrollments_export_" + new Date().toISOString().slice(0,10) + ".csv");
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
