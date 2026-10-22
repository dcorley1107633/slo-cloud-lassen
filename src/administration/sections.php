<?php
declare(strict_types=1);

/**
 * Section Administration
 * 
 * Manage course sections.
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
                $courseFk = (int)($_POST['course_fk'] ?? 0);
                $termFk = (int)($_POST['term_fk'] ?? 0);
                $sectionId = trim($_POST['section_id'] ?? '');
                $crn = trim($_POST['crn'] ?? '');
                $crn = $crn === '' ? null : $crn;
                
                // Validation
                $errors = [];
                if ($courseFk <= 0) {
                    $errors[] = 'Course is required';
                } else {
                    // Validate course exists
                    $courseCheck = $db->query(
                        "SELECT COUNT(*) as count FROM {$dbPrefix}courses c WHERE courses_pk = ? AND " . lifecycle_active_predicate('courses', 'c', $dbPrefix),
                        [$courseFk],
                        'i'
                    );
                    $courseRow = $courseCheck->fetch();
                    if ($courseRow['count'] == 0) {
                        $errors[] = 'Invalid course selected';
                    }
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
                if (empty($sectionId)) {
                    $errors[] = 'Section ID is required';
                } else {
                    // Check uniqueness (course + term + section_id must be unique)
                    $result = $db->query(
                        "SELECT COUNT(*) as count FROM {$dbPrefix}sections WHERE course_fk = ? AND term_fk = ? AND section_id = ?",
                        [$courseFk, $termFk, $sectionId],
                        'iis'
                    );
                    $row = $result->fetch();
                    if ($row['count'] > 0) {
                        $errors[] = 'Section ID already exists for this course and term';
                    }
                }
                if ($crn !== null) {
                    $result = $db->query(
                        "SELECT COUNT(*) as count FROM {$dbPrefix}sections WHERE term_fk = ? AND crn = ?",
                        [$termFk, $crn],
                        'is'
                    );
                    if ((int)($result->fetch()['count'] ?? 0) > 0) {
                        $errors[] = 'CRN already exists in this term';
                    }
                }
                
                if (empty($errors)) {
                    $userId = getValidUserId();
                    $db->query(
                        "INSERT INTO {$dbPrefix}sections (course_fk, term_fk, section_id, crn, created_at, updated_at, created_by_fk, updated_by_fk) 
                         VALUES (?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                        [$courseFk, $termFk, $sectionId, $crn, $userId, $userId],
                        'iissii'
                    );
                    $successMessage = 'Section added successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
                }
                break;
                
            case 'edit':
                $id = (int)($_POST['section_id'] ?? 0);
                $courseFk = (int)($_POST['course_fk'] ?? 0);
                $termFk = (int)($_POST['term_fk'] ?? 0);
                $sectionId = trim($_POST['section_id_value'] ?? '');
                $crn = trim($_POST['crn'] ?? '');
                $crn = $crn === '' ? null : $crn;
                
                // Validation
                $errors = [];
                if ($id <= 0) {
                    $errors[] = 'Invalid section ID';
                }
                if ($courseFk <= 0) {
                    $errors[] = 'Course is required';
                } else {
                    // Validate course exists
                    $courseCheck = $db->query(
                        "SELECT COUNT(*) as count FROM {$dbPrefix}courses c WHERE courses_pk = ? AND " . lifecycle_active_predicate('courses', 'c', $dbPrefix),
                        [$courseFk],
                        'i'
                    );
                    $courseRow = $courseCheck->fetch();
                    if ($courseRow['count'] == 0) {
                        $errors[] = 'Invalid course selected';
                    }
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
                if (empty($sectionId)) {
                    $errors[] = 'Section ID is required';
                } else {
                    // Check uniqueness (excluding current record)
                    $result = $db->query(
                        "SELECT COUNT(*) as count FROM {$dbPrefix}sections WHERE course_fk = ? AND term_fk = ? AND section_id = ? AND sections_pk != ?",
                        [$courseFk, $termFk, $sectionId, $id],
                        'iisi'
                    );
                    $row = $result->fetch();
                    if ($row['count'] > 0) {
                        $errors[] = 'Section ID already exists for this course and term';
                    }
                }
                if ($crn !== null) {
                    $result = $db->query(
                        "SELECT COUNT(*) as count FROM {$dbPrefix}sections WHERE term_fk = ? AND crn = ? AND sections_pk != ?",
                        [$termFk, $crn, $id],
                        'isi'
                    );
                    if ((int)($result->fetch()['count'] ?? 0) > 0) {
                        $errors[] = 'CRN already exists in this term';
                    }
                }
                
                if (empty($errors)) {
                    $userId = getValidUserId();
                    $db->query(
                        "UPDATE {$dbPrefix}sections 
                         SET course_fk = ?, term_fk = ?, section_id = ?, crn = ?, updated_at = {$db->now()}, updated_by_fk = ?
                         WHERE sections_pk = ?",
                        [$courseFk, $termFk, $sectionId, $crn, $userId, $id],
                        'iissii'
                    );
                    $successMessage = 'Section updated successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
                }
                break;
                
            case 'delete':
                $id = (int)($_POST['section_id'] ?? 0);
                if ($id > 0) {
                    // Delete explicitly for SQL Server installations, whose
                    // schema intentionally omits foreign-key cascades.
                    $db->query(
                        "DELETE FROM {$dbPrefix}assessments
                         WHERE enrollment_fk IN (
                             SELECT enrollment_pk FROM {$dbPrefix}enrollment WHERE section_fk = ?
                         )",
                        [$id],
                        'i'
                    );
                    $db->query(
                        "DELETE FROM {$dbPrefix}enrollment WHERE section_fk = ?",
                        [$id],
                        'i'
                    );
                    $db->query(
                        "DELETE FROM {$dbPrefix}sections WHERE sections_pk = ?",
                        [$id],
                        'i'
                    );
                    cleanupOrphanedStudents();
                    $successMessage = 'Section deleted successfully (including all enrollments and assessments)';
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

// Fetch terms for dropdown (sorted ascending)
$termsResult = $db->query("
    SELECT terms_pk, term_code, term_name, academic_year
    FROM {$dbPrefix}terms
    WHERE is_active = 1
    ORDER BY term_code ASC
");
$terms = $termsResult->fetchAll();

// Fetch courses for dropdown
$coursesResult = $db->query("
    SELECT courses_pk, course_number, course_name
    FROM {$dbPrefix}courses
    WHERE is_active = 1
    ORDER BY course_number ASC
");
$courses = $coursesResult->fetchAll();

// Get selected term info from session (cached during getSelectedTermFk())
$selectedTermName = getSelectedTermName() ?? '';
$selectedTermCode = getSelectedTermCode() ?? '';

// Calculate statistics (filtered by term)
$termFilter = $selectedTermFk ? "WHERE term_fk = {$selectedTermFk}" : '';
$statsResult = $db->query("
    SELECT COUNT(*) as total
    FROM {$dbPrefix}sections
    {$termFilter}
");
$stats = $statsResult->fetch();
$totalSections = $stats['total'];

// Load theme system
require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$pageTitle = 'Section Management';
if ($selectedTermName) {
    $pageTitle .= ' - ' . $selectedTermName;
    if ($selectedTermCode) {
        $pageTitle .= ' (' . $selectedTermCode . ')';
    }
}

$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => $pageTitle,
    'currentPage' => 'admin_sections',
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['label' => 'Sections']
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

        <!-- Sections Table -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-chalkboard-teacher" aria-hidden="true"></i> Course Sections</h2>
                <div class="card-tools">
                    <button type="button" class="btn btn-success btn-sm" onclick="exportAllSections()" aria-label="Export all section records to CSV">
                        <i class="fas fa-download" aria-hidden="true"></i> Export All to CSV
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addSectionModal" aria-label="Add new section">
                        <i class="fas fa-plus" aria-hidden="true"></i> Add Section
                    </button>
                </div>
            </div>
            <div class="card-body">
                <table id="sectionsTable" class="table table-bordered table-striped" aria-label="Course sections data table">
                    <caption class="visually-hidden">List of course sections with filtering and sorting capabilities</caption>
                    <thead>
                        <tr>
                            <th scope="col">Course</th>
                            <th scope="col">Section</th>
                            <th scope="col">Enrollment Count</th>
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


<!-- Add Section Modal -->
<div class="modal fade" id="addSectionModal" tabindex="-1" aria-labelledby="addSectionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title" id="addSectionModalLabel"><i class="fas fa-plus" aria-hidden="true"></i> Add Section</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="add">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="courseFk" class="form-label">Course <span class="text-danger" aria-label="required">*</span></label>
                            <select class="form-select" id="courseFk" name="course_fk" required aria-required="true">
                                <option value="">Select Course</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?= $course['courses_pk'] ?>">
                                        <?= htmlspecialchars($course['course_number'] . ' - ' . $course['course_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
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
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="sectionId" class="form-label">Section ID <span class="text-danger" aria-label="required">*</span></label>
                            <input type="text" class="form-control" id="sectionId" name="section_id" maxlength="20" required aria-required="true" aria-describedby="sectionIdHelp">
                            <small id="sectionIdHelp" class="form-text text-muted">e.g., 01, 02, A, B</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="crn" class="form-label">CRN</label>
                            <input type="text" class="form-control" id="crn" name="crn" maxlength="20">
                            <small class="form-text text-muted">Course Reference Number</small>
                        </div>
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

<!-- Edit Section Modal -->
<div class="modal fade" id="editSectionModal" tabindex="-1" aria-labelledby="editSectionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title" id="editSectionModalLabel"><i class="fas fa-edit" aria-hidden="true"></i> Edit Section</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST" id="editSectionForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="edit">
                    <?= lifecycle_edit_fields('sections', 'sections.php?status=' . lifecycle_view_mode('sections')) ?>
                    <input type="hidden" name="section_id" id="editSectionId">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="editCourseFk" class="form-label">Course <span class="text-danger" aria-label="required">*</span></label>
                            <select class="form-select" id="editCourseFk" name="course_fk" required aria-required="true">
                                <option value="">Select Course</option>
                                <?php foreach ($courses as $course): ?>
                                    <option value="<?= $course['courses_pk'] ?>">
                                        <?= htmlspecialchars($course['course_number'] . ' - ' . $course['course_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
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
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="editSectionIdValue" class="form-label">Section ID <span class="text-danger" aria-label="required">*</span></label>
                            <input type="text" class="form-control" id="editSectionIdValue" name="section_id_value" maxlength="20" required aria-required="true">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="editCrn" class="form-label">CRN</label>
                            <input type="text" class="form-control" id="editCrn" name="crn" maxlength="20">
                        </div>
                    </div>
                    
                    <hr>
                    <div class="text-muted mb-3"><i class="fas fa-info-circle" aria-hidden="true"></i> Audit Information</div>
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Created:</strong>
                            <p id="editSectionCreated" class="text-muted">N/A</p>
                        </div>
                        <div class="col-md-6">
                            <strong>Created By:</strong>
                            <p id="editSectionCreatedBy" class="text-muted">N/A</p>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Last Updated:</strong>
                            <p id="editSectionUpdated" class="text-muted">N/A</p>
                        </div>
                        <div class="col-md-6">
                            <strong>Updated By:</strong>
                            <p id="editSectionUpdatedBy" class="text-muted">N/A</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <?= lifecycle_edit_controls() ?>
                    <!-- LEFT SIDE: Destructive Actions -->
                    <div>
                        <button type="button" class="btn btn-danger" onclick="confirmDeleteSection()" aria-label="Delete section">
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


<!-- Delete Form (hidden) -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="section_id" id="deleteSectionId">
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
    // Setup - add filter inputs to each column header cell (second row)
    $('#sectionsTable thead tr:eq(1) td').each(function(i) {
        var title = $('#sectionsTable thead tr:eq(0) th:eq(' + i + ')').text();
        if (title === 'Actions' || title === 'Enrollment Count') {
            $(this).html('');
        } else {
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder="Search ' + title + '" aria-label="Filter by ' + title + '" />');
        }
    });
    
    var table = $('#sectionsTable').DataTable({
        orderCellsTop: true,
        processing: true,
        serverSide: true,
        ajax: {
            url: '<?= BASE_URL ?>administration/sections_data.php',
            type: 'POST',
            data: function(d) {
                d.term_fk = <?= json_encode($selectedTermFk) ?>;
            }
        },
        dom: 'rtip',
        columns: [
            { data: 0, name: 'course_number' },
            { data: 1, name: 'section_id' },
            { data: 2, name: 'enrollment_count', searchable: false },
            { data: 3, name: 'created_at' },
            { data: 4, name: 'created_by_name' },
            { data: 5, name: 'updated_at' },
            { data: 6, name: 'updated_by_name' },
            { data: 7, name: 'actions', orderable: false, searchable: false }
        ],
        order: [[0, 'asc'], [1, 'asc']],
        language: {
            info: "Showing _START_ to _END_ of _TOTAL_ sections",
            infoEmpty: "No sections found",
            infoFiltered: "(filtered from _MAX_ total)",
            emptyTable: "No sections available"
        },
        initComplete: function() {
            // Apply the search - target the second header row where filters are
            var api = this.api();
            api.columns().every(function(colIdx) {
                var column = this;
                var filterCell = $('#sectionsTable thead tr:eq(1) td').eq(colIdx);
                
                $('select', filterCell).on('change', function() {
                    column.search($(this).val()).draw();
                });
                $('input', filterCell).on('keyup change clear', function() {
                    if (column.search() !== this.value) {
                        column.search(this.value).draw();
                    }
                });
            });
        }
    });

});

function editSection(section) {
    $('#editSectionId').val(section.sections_pk);
    $('#editCourseFk').val(section.course_fk);
    $('#editTermFk').val(section.term_fk);
    $('#editSectionIdValue').val(section.section_id);
    $('#editCrn').val(section.crn);
    // Populate audit information
    $('#editSectionCreated').text(section.created_at || 'N/A');
    $('#editSectionCreatedBy').text(section.created_by_name || 'System');
    $('#editSectionUpdated').text(section.updated_at || 'N/A');
    $('#editSectionUpdatedBy').text(section.updated_by_name || 'System');
    configureLifecycleEditAction('#editSectionForm', section.sections_pk, section.is_active, 'section', (section.course_number || '') + '-' + (section.section_id || ''));
    new bootstrap.Modal(document.getElementById('editSectionModal')).show();
}

function deleteSection(id, name) {
    if (confirm('Are you sure you want to DELETE section "' + name + '"?\n\nThis will also delete:\n- All enrollments for this section\n- All assessments for this section\n\nThis action cannot be undone.')) {
        $('#deleteSectionId').val(id);
        $('#deleteForm').submit();
    }
}

function confirmDeleteSection() {
    const sectionPk = $('#editSectionId').val();
    const crn = $('#editCrn').val();
    deleteSection(sectionPk, crn);
}

function exportAllSections() {
    var button = event.target.closest('button');
    var originalHTML = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
    button.disabled = true;
    
    $.ajax({
        url: '<?= BASE_URL ?>administration/sections_data.php',
        data: {
            start: 0,
            length: -1,
            draw: 1,
            term_fk: <?= json_encode($selectedTermFk) ?>
        },
        success: function(response) {
            var csvContent = "Course,Section,Enrollment Count,Created At,Created By,Updated At,Updated By\n";
            
            response.data.forEach(function(row) {
                var course = escapeCSV(row[0]);
                var section = escapeCSV(row[1]);
                var enrollmentCount = escapeCSV(row[2]);
                var createdAt = escapeCSV(row[3]);
                var createdBy = escapeCSV(row[4]);
                var updatedAt = escapeCSV(row[5]);
                var updatedBy = escapeCSV(row[6]);
                
                csvContent += course + "," + section + "," + enrollmentCount + "," + createdAt + "," + createdBy + "," + updatedAt + "," + updatedBy + "\n";
            });
            
            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement("a");
            var url = URL.createObjectURL(blob);
            link.setAttribute("href", url);
            link.setAttribute("download", "sections_export_" + new Date().toISOString().slice(0,10) + ".csv");
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
