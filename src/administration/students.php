<?php
declare(strict_types=1);

/**
 * Students Administration
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
                $studentId = trim($_POST['student_id'] ?? '');
                $studentName = trim($_POST['student_name'] ?? '');

                
                $errors = [];
                if (empty($studentId)) {
                    $errors[] = 'Student ID is required';
                }
                if (empty($studentName)) {
                    $errors[] = 'Student name is required';
                }
                
                // Check student_id uniqueness via hash
                if (!empty($studentId)) {
                    $hash = $studentEncryption->hashCNumber($studentId);
                    $checkResult = $db->query(
                        "SELECT students_pk FROM {$dbPrefix}students WHERE student_id_hash = ?",
                        [$hash],
                        's'
                    );
                    if (fetch_row($checkResult)) {
                        $errors[] = 'Student ID already exists';
                    }
                }
                
                if (empty($errors)) {
                    // Encrypt student data (full name in first_name, as edit does)
                    $firstName = $studentName;
                    $lastName = '';
                    $encrypted = $studentEncryption->encryptStudentData($studentId, $firstName, $lastName);
                    $userId = getValidUserId();
                    $db->query(
                        "INSERT INTO {$dbPrefix}students (student_data_encrypted, student_id_hash, created_at, updated_at, created_by_fk, updated_by_fk) 
                         VALUES (?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                        [$encrypted['encrypted'], $encrypted['hash'], $userId, $userId],
                        'ssii'
                    );
                    $successMessage = 'Student added successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
                }
                break;
                
            case 'edit':
                $id = (int)($_POST['student_pk'] ?? 0);
                $studentId = trim($_POST['student_id'] ?? '');
                $studentName = trim($_POST['student_name'] ?? '');
                
                $errors = [];
                if ($id <= 0) {
                    $errors[] = 'Invalid student PK';
                }
                if (empty($studentId)) {
                    $errors[] = 'Student ID is required';
                }
                if (empty($studentName)) {
                    $errors[] = 'Student name is required';
                }
                
                // Check student_id uniqueness via hash (exclude current record)
                if (!empty($studentId)) {
                    $hash = $studentEncryption->hashCNumber($studentId);
                    $checkResult = $db->query(
                        "SELECT students_pk FROM {$dbPrefix}students WHERE student_id_hash = ? AND students_pk != ?",
                        [$hash, $id],
                        'si'
                    );
                    if (fetch_row($checkResult)) {
                        $errors[] = 'Student ID already exists';
                    }
                }
                
                if (empty($errors)) {
                    // Encrypt student data (store full name as-is)
                    $firstName = $studentName; // Store full name in first_name field
                    $lastName = ''; // Leave last_name empty
                    $encrypted = $studentEncryption->encryptStudentData($studentId, $firstName, $lastName);
                    $userId = getValidUserId();
                    $db->query(
                        "UPDATE {$dbPrefix}students 
                         SET student_data_encrypted = ?, student_id_hash = ?, updated_at = {$db->now()}, updated_by_fk = ?
                         WHERE students_pk = ?",
                        [$encrypted['encrypted'], $encrypted['hash'], $userId, $id],
                        'ssii'
                    );
                    $successMessage = 'Student updated successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
                }
                break;
                
                
            case 'delete':
                $id = (int)($_POST['student_pk'] ?? 0);
                if ($id > 0) {
                    // Cascade delete: enrollments (and their assessments) will be automatically deleted by database
                    $db->query("DELETE FROM {$dbPrefix}students WHERE students_pk = ?", [$id], 'i');
                    $successMessage = 'Student deleted successfully (including all enrollments and assessments)';
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

// Get selected term for display
$selectedTermFk = getSelectedTermFk();
$selectedTermName = getSelectedTermName() ?? '';
$selectedTermCode = getSelectedTermCode() ?? '';

require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

// Build page title with term information
$pageTitle = 'Student Management';
if ($selectedTermName) {
    $pageTitle .= ' - ' . $selectedTermName;
    if ($selectedTermCode) {
        $pageTitle .= ' (' . $selectedTermCode . ')';
    }
}

$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => $pageTitle,
    'currentPage' => 'admin_students',
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['label' => 'Students']
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
                <h2 class="card-title"><i class="fas fa-table" aria-hidden="true"></i> Students <small class="text-muted">(Encrypted Data)</small></h2>
                <div class="card-tools">
                    <button type="button" class="btn btn-success btn-sm" onclick="exportAllStudents()" aria-label="Export all student records to CSV">
                        <i class="fas fa-download" aria-hidden="true"></i> Export All to CSV
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addStudentModal" aria-label="Add new student">
                        <i class="fas fa-plus" aria-hidden="true"></i> Add Student
                    </button>
                </div>
            </div>
            <div class="card-body">
                <table id="studentsTable" class="table table-bordered table-striped" aria-label="Students data table">
                    <caption class="visually-hidden">List of students with filtering and sorting capabilities</caption>
                    <thead>
                        <tr>
                            <th scope="col">Student ID</th>
                            <th scope="col">Student Name</th>
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
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>
</div>


<!-- Add Student Modal -->
<div class="modal fade" id="addStudentModal" tabindex="-1" aria-labelledby="addStudentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title" id="addStudentModalLabel"><i class="fas fa-plus" aria-hidden="true"></i> Add Student</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="mb-3">
                        <label for="studentId" class="form-label">Student ID <span class="text-danger" aria-label="required">*</span></label>
                        <input type="text" class="form-control" id="studentId" name="student_id" maxlength="255" required aria-required="true" aria-describedby="studentIdHelp">
                        <small id="studentIdHelp" class="form-text text-muted">Unique student identifier</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="studentName" class="form-label">Student Name <span class="text-danger" aria-label="required">*</span></label>
                        <input type="text" class="form-control" id="studentName" name="student_name" maxlength="255" required aria-required="true" placeholder="Full name">
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

<!-- Edit Student Modal -->
<div class="modal fade" id="editStudentModal" tabindex="-1" aria-labelledby="editStudentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title" id="editStudentModalLabel"><i class="fas fa-edit" aria-hidden="true"></i> Edit Student</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST" id="editStudentForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="edit">
                    <?= lifecycle_edit_fields('students', 'students.php?status=' . lifecycle_view_mode('students')) ?>
                    <input type="hidden" name="student_pk" id="editStudentPk">
                    
                    <div class="mb-3">
                        <label for="editStudentId" class="form-label">Student ID <span class="text-danger" aria-label="required">*</span></label>
                        <input type="text" class="form-control" id="editStudentId" name="student_id" maxlength="255" required aria-required="true" aria-describedby="editStudentIdHelp">
                        <small id="editStudentIdHelp" class="form-text text-muted">Unique student identifier</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editStudentName" class="form-label">Student Name <span class="text-danger" aria-label="required">*</span></label>
                        <input type="text" class="form-control" id="editStudentName" name="student_name" maxlength="255" required aria-required="true" placeholder="Full name">
                    </div>
                    
                    <hr>
                    <div class="text-muted mb-3"><i class="fas fa-info-circle" aria-hidden="true"></i> Audit Information</div>
                    <div class="row">
                        <div class="col-md-6">
                            <small class="text-muted"><strong>Created:</strong></small>
                            <p id="editStudentCreated"></p>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted"><strong>Created By:</strong></small>
                            <p id="editStudentCreatedBy"></p>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted"><strong>Last Updated:</strong></small>
                            <p id="editStudentUpdated"></p>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted"><strong>Updated By:</strong></small>
                            <p id="editStudentUpdatedBy"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <?= lifecycle_edit_controls() ?>
                    <!-- LEFT SIDE: Destructive Actions -->
                    <div>
                        <button type="button" class="btn btn-danger" onclick="confirmDeleteStudent()" aria-label="Delete student">
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

<form id="toggleStatusForm" method="POST" style="display: none;" aria-label="Toggle student status">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="student_pk" id="toggleStudentPk">
</form>

<form id="deleteForm" method="POST" style="display: none;" aria-label="Delete student">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="student_pk" id="deleteStudentPk">
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
$(document).ready(function() {
    // Create filter inputs with proper ARIA labels
    $('#studentsTable thead tr:eq(1) td').each(function(i) {
        var title = $('#studentsTable thead tr:eq(0) th:eq(' + i + ')').text();
        if (title === 'Status') {
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder="Search ' + title + '" aria-label="Filter by ' + title + '" />');
        } else if (title !== 'Actions') {
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder="Search ' + title + '" aria-label="Filter by ' + title + '" />');
        } else {
            $(this).html('');
        }
    });
    
    var table = $('#studentsTable').DataTable({
        orderCellsTop: true,
        processing: true,
        serverSide: true,
        ajax: { url: '<?= BASE_URL ?>administration/students_data.php', type: 'POST' },
        dom: 'rtip',
        columns: [
            { data: 0, name: 'student_id' },
            { data: 1, name: 'student_name' },
            { data: 2, name: 'created_at' },
            { data: 3, name: 'created_by_name' },
            { data: 4, name: 'updated_at' },
            { data: 5, name: 'updated_by_name' },
            { data: 6, name: 'actions', orderable: false, searchable: false }
        ],
        language: {
            info: "Showing _START_ to _END_ of _TOTAL_ students",
            infoEmpty: "No students found",
            infoFiltered: "(filtered from _MAX_ total)",
            emptyTable: "No students available"
        },
        initComplete: function() {
            // Apply the search - target the second header row where filters are
            var api = this.api();
            api.columns().every(function(colIdx) {
                var column = this;
                // Find input in the second header row (tr:eq(1)) for this column
                $('input, select', $('#studentsTable thead tr:eq(1) td').eq(colIdx)).on('keyup change clear', function() {
                    if (column.search() !== this.value) {
                        column.search(this.value).draw();
                    }
                });
            });
        }
    });
});

function exportAllStudents() {
    // Show loading indicator
    var button = event.target.closest('button');
    var originalHTML = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
    button.disabled = true;
    
    // Fetch all records
    $.ajax({
        url: '<?= BASE_URL ?>administration/students_data.php',
        data: {
            start: 0,
            length: -1, // Get all records
            draw: 1
        },
        success: function(response) {
            // Convert to CSV
            var csv = [];
            var headers = ['Student ID', 'First Name', 'Last Name', 'Status', 'Created', 'Created By', 'Updated', 'Updated By'];
            csv.push(headers.join(','));
            
            response.data.forEach(function(row) {
                var csvRow = [];
                // Skip the last column (actions)
                for (var i = 0; i < row.length - 1; i++) {
                    var cell = row[i] ? row[i].toString() : '';
                    // Escape quotes and wrap in quotes if contains comma or quote
                    if (cell.includes(',') || cell.includes('"') || cell.includes('\n')) {
                        cell = '"' + cell.replace(/"/g, '""') + '"';
                    }
                    csvRow.push(cell);
                }
                csv.push(csvRow.join(','));
            });
            
            // Download CSV
            var csvContent = csv.join('\n');
            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement('a');
            var url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'students_all.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            
            // Reset button
            button.innerHTML = originalHTML;
            button.disabled = false;
        },
        error: function() {
            alert('Error exporting data');
            button.innerHTML = originalHTML;
            button.disabled = false;
        }
    });
}

function editStudent(student) {
    $('#editStudentPk').val(student.students_pk);
    $('#editStudentId').val(student.student_id);
    $('#editStudentName').val(student.student_name);
    
    // Populate audit information
    $('#editStudentCreated').text(student.created_at || 'N/A');
    $('#editStudentCreatedBy').text(student.created_by_name || 'System');
    $('#editStudentUpdated').text(student.updated_at || 'N/A');
    $('#editStudentUpdatedBy').text(student.updated_by_name || 'System');
    configureLifecycleEditAction('#editStudentForm', student.students_pk, student.is_active, 'student', student.student_id || '');
    new bootstrap.Modal(document.getElementById('editStudentModal')).show();
}

$('#studentsTable').on('click', '.js-edit-student', function() {
    try {
        const encoded = atob(this.dataset.student || '');
        const json = new TextDecoder().decode(Uint8Array.from(encoded, character => character.charCodeAt(0)));
        const student = JSON.parse(json);
        editStudent(student);
    } catch (error) {
        console.error('Unable to open the selected student record.');
    }
});

function deleteStudent(id, studentId) {
    if (confirm('Are you sure you want to DELETE student "' + studentId + '"?\n\nThis will also delete:\n- All enrollments for this student\n- All assessments for this student\n\nThis action cannot be undone.')) {
        $('#deleteStudentPk').val(id);
        $('#deleteForm').submit();
    }
}

function confirmDeleteStudent() {
    const studentPk = $('#editStudentPk').val();
    const studentId = $('#editStudentId').val();
    deleteStudent(studentPk, studentId);
}
</script>
