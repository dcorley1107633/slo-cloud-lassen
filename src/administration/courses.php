<?php
declare(strict_types=1);

/**
 * Course Administration
 * 
 * Manage courses and program-course mappings.
 * 
 * @package SLOCloud
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';

// Get selected term (from GET/session, or default to latest)
$selectedTermFk = getSelectedTermFk();

// Handle POST requests
$successMessage = '';
$errorMessage = '';

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
    
    try {
        switch ($action) {
            case 'add':
                $courseNumber = trim($_POST['course_number'] ?? '');
                $courseName = trim($_POST['course_name'] ?? '');
                $termFk = (int)($_POST['term_fk'] ?? 0);
                
                // Validation
                $errors = [];
                if (empty($courseNumber)) {
                    $errors[] = 'Course number is required';
                } else {
                    // Check uniqueness
                    $result = $db->query(
                        "SELECT COUNT(*) as count FROM {$dbPrefix}courses WHERE course_number = ?",
                        [$courseNumber],
                        's'
                    );
                    $row = $result->fetch();
                    if ($row['count'] > 0) {
                        $errors[] = 'Course number already exists';
                    }
                }
                if (empty($courseName)) {
                    $errors[] = 'Course name is required';
                }
                if ($termFk <= 0) {
                    $errors[] = 'Term is required';
                } else {
                    // Validate term exists
                    $termCheck = $db->query(
                        "SELECT COUNT(*) as count FROM {$dbPrefix}terms WHERE terms_pk = ? AND is_active = 1",
                        [$termFk],
                        'i'
                    );
                    $termRow = $termCheck->fetch();
                    if ($termRow['count'] == 0) {
                        $errors[] = 'Invalid term selected';
                    }
                }
                
                if (empty($errors)) {
                    $userId = getValidUserId();
                    $db->query(
                        "INSERT INTO {$dbPrefix}courses (course_number, course_name, term_fk, discipline_fk, created_at, updated_at, created_by_fk, updated_by_fk) 
                         VALUES (?, ?, ?, NULL, {$db->now()}, {$db->now()}, ?, ?)",
                        [$courseNumber, $courseName, $termFk, $userId, $userId],
                        'ssiii'
                    );
                    $successMessage = 'Course added successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
                }
                break;
                
            case 'edit':
                $id = (int)($_POST['course_id'] ?? 0);
                $courseNumber = trim($_POST['course_number'] ?? '');
                $courseName = trim($_POST['course_name'] ?? '');
                $termFk = (int)($_POST['term_fk'] ?? 0);
                
                // Validation
                $errors = [];
                if ($id <= 0) {
                    $errors[] = 'Invalid course ID';
                }
                if (empty($courseNumber)) {
                    $errors[] = 'Course number is required';
                } else {
                    // Check uniqueness (excluding current record)
                    $result = $db->query(
                        "SELECT COUNT(*) as count FROM {$dbPrefix}courses WHERE course_number = ? AND courses_pk != ?",
                        [$courseNumber, $id],
                        'si'
                    );
                    $row = $result->fetch();
                    if ($row['count'] > 0) {
                        $errors[] = 'Course number already exists';
                    }
                }
                if (empty($courseName)) {
                    $errors[] = 'Course name is required';
                }
                if ($termFk <= 0) {
                    $errors[] = 'Term is required';
                } else {
                    // Validate term exists
                    $termCheck = $db->query(
                        "SELECT COUNT(*) as count FROM {$dbPrefix}terms WHERE terms_pk = ? AND is_active = 1",
                        [$termFk],
                        'i'
                    );
                    $termRow = $termCheck->fetch();
                    if ($termRow['count'] == 0) {
                        $errors[] = 'Invalid term selected';
                    }
                }
                
                if (empty($errors)) {
                    $userId = getValidUserId();
                    $db->query(
                        "UPDATE {$dbPrefix}courses 
                         SET course_number = ?, course_name = ?, term_fk = ?, updated_at = {$db->now()}, updated_by_fk = ?
                         WHERE courses_pk = ?",
                        [$courseNumber, $courseName, $termFk, $userId, $id],
                        'ssiii'
                    );
                    $successMessage = 'Course updated successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
                }
                break;
                
            case 'delete':
                $id = (int)($_POST['course_id'] ?? 0);
                if ($id > 0) {
                    // Cascade delete: sections, SLOs, program_courses, enrollment, and assessments will be automatically deleted by database
                    $db->query(
                        "DELETE FROM {$dbPrefix}courses WHERE courses_pk = ?",
                        [$id],
                        'i'
                    );
                    $successMessage = 'Course deleted successfully (including all sections, SLOs, enrollments, and assessments)';
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

// Fetch terms for add/edit modals
$termsResult = $db->query("
    SELECT terms_pk, term_code, term_name, academic_year
    FROM {$dbPrefix}terms
    WHERE is_active = 1
    ORDER BY term_code ASC
");
$terms = $termsResult->fetchAll();

// Get selected term name from session (cached during getSelectedTermFk())
$selectedTermName = getSelectedTermName() ?? '';
$selectedTermCode = getSelectedTermCode() ?? '';

// Calculate statistics (filtered by term)
$termFilter = $selectedTermFk ? "WHERE term_fk = {$selectedTermFk}" : '';
$statsResult = $db->query("
    SELECT 
        COUNT(*) as total
    FROM {$dbPrefix}courses
    {$termFilter}
");
$stats = $statsResult->fetch();
$totalCourses = $stats['total'];

// Load theme system
require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$pageTitle = 'Course Management';
if ($selectedTermName) {
    $pageTitle .= ' - ' . $selectedTermName;
    if ($selectedTermCode) {
        $pageTitle .= ' (' . $selectedTermCode . ')';
    }
}

$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => $pageTitle,
    'currentPage' => 'admin_courses',
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['label' => 'Courses']
    ]
]);

// Use admin-specific theme (full navigation and sidebar)
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

        <!-- Courses Table -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-book" aria-hidden="true"></i> Courses</h2>
                <div class="card-tools">
                    <button type="button" class="btn btn-success btn-sm" onclick="exportAllCourses()" aria-label="Export all course records to CSV">
                        <i class="fas fa-download" aria-hidden="true"></i> Export All to CSV
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addCourseModal" aria-label="Add new course">
                        <i class="fas fa-plus" aria-hidden="true"></i> Add Course
                    </button>
                </div>
            </div>
            <div class="card-body">
                <table id="coursesTable" class="table table-bordered table-striped" aria-label="Courses data table">
                    <caption class="visually-hidden">List of courses with filtering and sorting capabilities</caption>
                    <thead>
                        <tr>
                            <th scope="col">Term</th>
                            <th scope="col">Course Number</th>
                            <th scope="col">Course Name</th>
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


<!-- Add Course Modal -->
<div class="modal fade" id="addCourseModal" tabindex="-1" aria-labelledby="addCourseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title" id="addCourseModalLabel"><i class="fas fa-plus" aria-hidden="true"></i> Add Course</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="add">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="courseNumber" class="form-label">Course Number <span class="text-danger" aria-label="required">*</span></label>
                            <input type="text" class="form-control" id="courseNumber" name="course_number" maxlength="50" required aria-required="true" aria-describedby="courseNumberHelp">
                            <small id="courseNumberHelp" class="form-text text-muted">Unique identifier (e.g., MATH 101)</small>
                        </div>
                        <div class="col-md-6 mb-3">
                           <label for="termFk" class="form-label">Term <span class="text-danger" aria-label="required">*</span></label>
                            <select class="form-select" id="termFk" name="term_fk" required aria-required="true">
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?= $term['terms_pk'] ?>" <?= $term['terms_pk'] == $selectedTermFk ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($term['term_code'] . ' - ' . $term['term_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="courseName" class="form-label">Course Name <span class="text-danger" aria-label="required">*</span></label>
                        <input type="text" class="form-control" id="courseName" name="course_name" maxlength="255" required aria-required="true">
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

<!-- Edit Course Modal -->
<div class="modal fade" id="editCourseModal" tabindex="-1" aria-labelledby="editCourseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title" id="editCourseModalLabel"><i class="fas fa-edit" aria-hidden="true"></i> Edit Course</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST" id="editCourseForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="edit">
                    <?= lifecycle_edit_fields('courses', 'courses.php?status=' . lifecycle_view_mode('courses')) ?>
                    <input type="hidden" name="course_id" id="editCourseId">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="editCourseNumber" class="form-label">Course Number <span class="text-danger" aria-label="required">*</span></label>
                            <input type="text" class="form-control" id="editCourseNumber" name="course_number" maxlength="50" required aria-required="true">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="editTermFk" class="form-label">Term <span class="text-danger" aria-label="required">*</span></label>
                            <select class="form-select" id="editTermFk" name="term_fk" required aria-required="true">
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?= $term['terms_pk'] ?>">
                                        <?= htmlspecialchars($term['term_code'] . ' - ' . $term['term_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="editCourseName" class="form-label">Course Name <span class="text-danger" aria-label="required">*</span></label>
                        <input type="text" class="form-control" id="editCourseName" name="course_name" maxlength="255" required aria-required="true">
                    </div>
                    <hr>
                    <div class="text-muted mb-3"><i class="fas fa-history" aria-hidden="true"></i> Audit Information</div>
                    <div class="row mb-2">
                        <div class="col-md-6">
                            <small class="text-muted">Created:</small>
                            <p class="mb-0" id="editCourseCreated"></p>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted">Created By:</small>
                            <p class="mb-0" id="editCourseCreatedBy"></p>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <small class="text-muted">Last Updated:</small>
                            <p class="mb-0" id="editCourseUpdated"></p>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted">Updated By:</small>
                            <p class="mb-0" id="editCourseUpdatedBy"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <?= lifecycle_edit_controls() ?>
                    <!-- LEFT SIDE: Destructive Actions -->
                    <div>
                        <button type="button" class="btn btn-danger" onclick="confirmDeleteCourse()" aria-label="Delete course">
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
<div class="modal fade" id="viewCourseModal" tabindex="-1" aria-labelledby="viewCourseModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h2 class="modal-title h5" id="viewCourseModalLabel"><i class="fas fa-eye" aria-hidden="true"></i> Course Details</h2>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <strong>Course Number:</strong>
                        <p id="viewCourseNumber"></p>
                    </div>
                    <div class="col-md-4">
                        <strong>ID:</strong>
                        <p id="viewCourseId"></p>
                    </div>
                    <div class="col-md-4">
                        <strong>Status:</strong>
                        <p id="viewCourseStatus"></p>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Course Name:</strong>
                        <p id="viewCourseName"></p>
                    </div>
                    <div class="col-md-6">
                        <strong>Term:</strong>
                        <p id="viewCourseTerm"></p>
                    </div>
                </div>
                <hr>
                <div class="text-muted mb-3"><i class="fas fa-history"></i> Audit Information</div>
                <div class="row mb-2">
                    <div class="col-md-6">
                        <strong>Created:</strong>
                        <p id="viewCourseCreated"></p>
                    </div>
                    <div class="col-md-6">
                        <strong>Created By:</strong>
                        <p id="viewCourseCreatedBy"></p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <strong>Last Updated:</strong>
                        <p id="viewCourseUpdated"></p>
                    </div>
                    <div class="col-md-6">
                        <strong>Updated By:</strong>
                        <p id="viewCourseUpdatedBy"></p>
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
    <input type="hidden" name="course_id" id="deleteCourseId">
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
    // Setup - add a text input or dropdown to each header cell (second row)
    $('#coursesTable thead tr:eq(1) td').each(function(i) {
        var title = $('#coursesTable thead tr:eq(0) th:eq(' + i + ')').text();
        if (title === 'Actions') {
            $(this).html('');
        } else if (title === 'Term') {
            var select = '<select class="form-select form-select-sm" id="termFkFilter" aria-label="Filter by Term"><option value="">All Terms</option>';
            <?php foreach ($terms as $term): ?>
            select += '<option value="<?= (int)$term['terms_pk'] ?>"<?= $term['terms_pk'] == $selectedTermFk ? ' selected' : '' ?>><?= htmlspecialchars($term['term_name']) ?></option>';
            <?php endforeach; ?>
            select += '</select>';
            $(this).html(select);
        } else {
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder="Search ' + title + '" aria-label="Filter by ' + title + '" />');
        }
    });

    var currentTermFk = <?= json_encode($selectedTermFk) ?>;

    var table = $('#coursesTable').DataTable({
        orderCellsTop: true,
        processing: true,
        serverSide: true,
        ajax: {
            url: '<?= BASE_URL ?>administration/courses_data.php',
            type: 'POST',
            data: function(d) {
                d.term_fk = currentTermFk;
            }
        },
        dom: 'Brtip',
        buttons: [
            'copy', 'csv', 'print'
        ],
        columns: [
            { data: 0, name: 'term_code' },
            { data: 1, name: 'course_number' },
            { data: 2, name: 'course_name' },
            { data: 3, name: 'created_at' },
            { data: 4, name: 'created_by' },
            { data: 5, name: 'updated_at' },
            { data: 6, name: 'updated_by' },
            { data: 7, name: 'actions', orderable: false, searchable: false }
        ],
        order: [[1, 'asc']],
        language: {
            info: "Showing _START_ to _END_ of _TOTAL_ courses",
            infoEmpty: "No courses found",
            infoFiltered: "(filtered from _MAX_ total)",
            emptyTable: "No courses available"
        },
        initComplete: function() {
            var api = this.api();

            // Term FK dropdown — reloads via server-side term_fk param
            $('#termFkFilter').on('change', function() {
                currentTermFk = $(this).val();
                api.draw();
            });

            // Text inputs for all other columns
            api.columns().every(function(colIdx) {
                var column = this;
                $('input', $('#coursesTable thead tr:eq(1) td').eq(colIdx)).on('keyup change clear', function() {
                    if (column.search() !== this.value) {
                        column.search(this.value).draw();
                    }
                });
            });
        }
    });

});

function viewCourse(course) {
    $('#viewCourseNumber').text(course.course_number);
    $('#viewCourseName').text(course.course_name);
    $('#viewCourseId').text(course.courses_pk);
    // Display term_code and term_name if available
    var termDisplay = course.term_code ? (course.term_code + (course.term_name ? ' - ' + course.term_name : '')) : 'N/A';
    $('#viewCourseTerm').text(termDisplay);
    $('#viewCourseCreated').text(course.created_at || 'N/A');
    $('#viewCourseCreatedBy').text(course.created_by_name || 'System');
    $('#viewCourseUpdated').text(course.updated_at || 'N/A');
    $('#viewCourseUpdatedBy').text(course.updated_by_name || 'System');
    new bootstrap.Modal(document.getElementById('viewCourseModal')).show();
}

function editCourse(course) {
    $('#editCourseId').val(course.courses_pk);
    $('#editCourseNumber').val(course.course_number);
    $('#editCourseName').val(course.course_name);
    $('#editTermFk').val(course.term_fk);
    // Populate read-only audit info
    $('#editCourseCreated').text(course.created_at || 'N/A');
    $('#editCourseCreatedBy').text(course.created_by_name || 'System');
    $('#editCourseUpdated').text(course.updated_at || 'N/A');
    $('#editCourseUpdatedBy').text(course.updated_by_name || 'System');
    configureLifecycleEditAction('#editCourseForm', course.courses_pk, course.is_active, 'course', course.course_number || '');
    new bootstrap.Modal(document.getElementById('editCourseModal')).show();
}

function deleteCourse(id, name) {
    if (confirm('Are you sure you want to DELETE "' + name + '"?\n\nThis will also delete:\n- All sections for this course\n- All SLOs for this course\n- All enrollments for this course\n- All assessments for this course\n\nThis action cannot be undone.')) {
        $('#deleteCourseId').val(id);
        $('#deleteForm').submit();
    }
}

function confirmDeleteCourse() {
    const coursePk = $('#editCourseId').val();
    const courseName = $('#editCourseName').val();
    deleteCourse(coursePk, courseName);
}

function exportAllCourses() {
    var button = event.target.closest('button');
    var originalHTML = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
    button.disabled = true;
    
    $.ajax({
        url: '<?= BASE_URL ?>administration/courses_data.php',
        data: {
            start: 0,
            length: -1,
            draw: 1,
            term_fk: <?= json_encode($selectedTermFk) ?>
        },
        success: function(response) {
            var csvContent = "Term Code,Course Number,Course Name,Created At,Created By,Updated At,Updated By\n";
            
            response.data.forEach(function(row) {
                var termCode = escapeCSV(row[0]);
                var courseNumber = escapeCSV(row[1]);
                var courseName = escapeCSV(row[2]);
                var createdAt = escapeCSV(row[3]);
                var createdBy = escapeCSV(row[4]);
                var updatedAt = escapeCSV(row[5]);
                var updatedBy = escapeCSV(row[6]);
                
                csvContent += termCode + "," + courseNumber + "," + courseName + "," + createdAt + "," + createdBy + "," + updatedAt + "," + updatedBy + "\n";
            });
            
            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement("a");
            var url = URL.createObjectURL(blob);
            link.setAttribute("href", url);
            link.setAttribute("download", "courses_export_" + new Date().toISOString().slice(0,10) + ".csv");
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
