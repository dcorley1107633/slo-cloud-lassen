<?php
declare(strict_types=1);

/**
 * Program Administration
 * 
 * Manage academic programs.
 * 
 * @package SLOCloud
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';

// Handle POST requests
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
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
                $code = trim($_POST['program_code'] ?? '');
                $name = trim($_POST['program_name'] ?? '');
                $degreeType = trim($_POST['degree_type'] ?? '');
                $termFk = (int)($_POST['term_fk'] ?? 0);
                $disciplineFk = !empty($_POST['discipline_fk']) ? (int)$_POST['discipline_fk'] : null;
                
                // Validation
                $errors = [];
                if (empty($code)) {
                    $errors[] = 'Program code is required';
                } elseif (!preg_match('/^[A-Z0-9_-]+$/i', $code)) {
                    $errors[] = 'Program code can only contain letters, numbers, hyphens, and underscores';
                } else {
                    // Check uniqueness
                    $result = $db->query(
                        "SELECT COUNT(*) as count FROM {$dbPrefix}programs WHERE program_code = ?",
                        [$code],
                        's'
                    );
                    $row = fetch_row($result);
                    if ($row['count'] > 0) {
                        $errors[] = 'Program code already exists';
                    }
                }
                if (empty($name)) {
                    $errors[] = 'Program name is required';
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
                        "INSERT INTO {$dbPrefix}programs (program_code, program_name, degree_type, term_fk, discipline_fk, created_at, updated_at, created_by_fk, updated_by_fk) 
                         VALUES (?, ?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                        [$code, $name, $degreeType, $termFk, $disciplineFk, $userId, $userId],
                        'sssiiiii'
                    );
                    $successMessage = 'Program added successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
                }
                break;
                
            case 'edit':
                $id = (int)($_POST['program_id'] ?? 0);
                $code = trim($_POST['program_code'] ?? '');
                $name = trim($_POST['program_name'] ?? '');
                $degreeType = trim($_POST['degree_type'] ?? '');
                $termFk = (int)($_POST['term_fk'] ?? 0);
                $disciplineFk = !empty($_POST['discipline_fk']) ? (int)$_POST['discipline_fk'] : null;
                
                // Validation
                $errors = [];
                if ($id <= 0) {
                    $errors[] = 'Invalid program ID';
                }
                if (empty($code)) {
                    $errors[] = 'Program code is required';
                } elseif (!preg_match('/^[A-Z0-9_-]+$/i', $code)) {
                    $errors[] = 'Program code can only contain letters, numbers, hyphens, and underscores';
                } else {
                    // Check uniqueness (excluding current record)
                    $result = $db->query(
                        "SELECT COUNT(*) as count FROM {$dbPrefix}programs WHERE program_code = ? AND programs_pk != ?",
                        [$code, $id],
                        'si'
                    );
                    $row = fetch_row($result);
                    if ($row['count'] > 0) {
                        $errors[] = 'Program code already exists';
                    }
                }
                if (empty($name)) {
                    $errors[] = 'Program name is required';
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
                        "UPDATE {$dbPrefix}programs 
                         SET program_code = ?, program_name = ?, degree_type = ?, term_fk = ?, discipline_fk = ?, updated_at = {$db->now()}, updated_by_fk = ?
                         WHERE programs_pk = ?",
                        [$code, $name, $degreeType, $termFk, $disciplineFk, $userId, $id],
                        'sssiiii'
                    );
                    $successMessage = 'Program updated successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
                }
                break;
                
            case 'delete':
                $id = (int)($_POST['program_id'] ?? 0);
                if ($id > 0) {
                    // Cascade delete: program_outcomes and program_courses will be automatically deleted by database
                    // Note: SLOs linked to program outcomes will have their program_outcomes_fk set to NULL
                    $db->query(
                        "DELETE FROM {$dbPrefix}programs WHERE programs_pk = ?",
                        [$id],
                        'i'
                    );
                    $successMessage = 'Program deleted successfully (including all program outcomes and course links)';
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

// Fetch disciplines for dropdown
$disciplinesResult = $db->query("
    SELECT disciplines_pk, discipline_name, discipline_code
    FROM {$dbPrefix}disciplines
    WHERE is_active = 1
    ORDER BY discipline_name ASC
");
$disciplines = $disciplinesResult->fetchAll();

// Fetch terms for dropdown (sorted descending with latest first)
$termsResult = $db->query("
    SELECT terms_pk, term_code, term_name, academic_year
    FROM {$dbPrefix}terms
    WHERE is_active = 1
    ORDER BY term_code ASC
");
$terms = $termsResult->fetchAll();

// Get selected term (handled by getSelectedTermFk())
$selectedTermFk = getSelectedTermFk();

// Get selected term name from session (cached during getSelectedTermFk())
$selectedTermName = getSelectedTermName() ?? '';
$selectedTermCode = getSelectedTermCode() ?? '';

// Calculate statistics (filtered by term)
$termFilter = $selectedTermFk ? "AND term_fk = {$selectedTermFk}" : '';
$statsResult = $db->query("
    SELECT COUNT(*) as total
    FROM {$dbPrefix}programs
    WHERE is_active = 1
    {$termFilter}
");
$stats = fetch_row($statsResult);
$totalPrograms = $stats['total'];

// Load theme system
require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$pageTitle = 'Program Management';
if ($selectedTermName) {
    $pageTitle .= ' - ' . $selectedTermName;
    if ($selectedTermCode) {
        $pageTitle .= ' (' . $selectedTermCode . ')';
    }
}

$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => $pageTitle,
    'currentPage' => 'admin_programs',
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['label' => 'Programs']
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

        <!-- Programs Table -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-table" aria-hidden="true"></i> Academic Programs</h2>
                <div class="card-tools">
                    <button type="button" class="btn btn-success btn-sm" onclick="exportAllPrograms()" aria-label="Export all program records to CSV">
                        <i class="fas fa-download" aria-hidden="true"></i> Export All to CSV
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addProgramModal" aria-label="Add new program">
                        <i class="fas fa-plus" aria-hidden="true"></i> Add Program
                    </button>
                </div>
            </div>
            <div class="card-body">
                <table id="programsTable" class="table table-bordered table-striped" aria-label="Programs data table">
                    <caption class="visually-hidden">List of academic programs with filtering and sorting capabilities</caption>
                    <thead>
                        <tr>
                            <th scope="col">Term</th>
                            <th scope="col">Discipline</th>
                            <th scope="col">Code</th>
                            <th scope="col">Program Name</th>
                            <th scope="col">Degree Type</th>
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
                    <tbody>
                        <!-- Data loaded via AJAX -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>


<!-- Add Program Modal -->
<div class="modal fade" id="addProgramModal" tabindex="-1" aria-labelledby="addProgramModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title" id="addProgramModalLabel"><i class="fas fa-plus" aria-hidden="true"></i> Add Program</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="add">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="programCode" class="form-label">Program Code <span class="text-danger" aria-label="required">*</span></label>
                            <input type="text" class="form-control" id="programCode" name="program_code" maxlength="50" required aria-required="true" aria-describedby="programCodeHelp">
                            <small id="programCodeHelp" class="form-text text-muted">Unique identifier (letters, numbers, hyphens, underscores)</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="degreeType" class="form-label">Degree Type</label>
                            <select class="form-select" id="degreeType" name="degree_type">
                                <option value="">Select Degree Type</option>
                                <?php
                                $degreeTypes = explode('||', trim($config->get('app.degree_types', 'Associate of Arts (AA)||Associate of Science (AS)||Associate in Arts for Transfer (AA-T)||Associate in Science for Transfer (AS-T)||Bachelor of Science (BS)||Bachelor of Applied Science (BAS)||Certificate of Achievement (16 or more semester units)||Certificate of Achievement (8-15.5 semester units)||Local Certificate (fewer than 8 semester units)||Noncredit Certificate of Completion||Noncredit Certificate of Competency'), '|'));
                                foreach ($degreeTypes as $type) {
                                    $type = trim($type);
                                    echo '<option value="' . htmlspecialchars($type) . '">' . htmlspecialchars($type) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="programName" class="form-label">Program Name <span class="text-danger" aria-label="required">*</span></label>
                        <input type="text" class="form-control" id="programName" name="program_name" maxlength="255" required aria-required="true">
                    </div>
                    <div class="mb-3">
                        <label for="termFk" class="form-label">Term <span class="text-danger" aria-label="required">*</span></label>
                        <select class="form-select" id="termFk" name="term_fk" required aria-required="true">
                            <?php foreach ($terms as $term): ?>
                                <option value="<?= $term['terms_pk'] ?>" <?= $term['terms_pk'] == $selectedTermFk ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($term['term_code'] . ' - ' . $term['term_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="disciplineFk" class="form-label">Discipline</label>
                        <select class="form-select" id="disciplineFk" name="discipline_fk">
                            <option value="">Select Discipline</option>
                            <?php foreach ($disciplines as $discipline): ?>
                                <option value="<?= $discipline['disciplines_pk'] ?>">
                                    <?= htmlspecialchars($discipline['discipline_name']) ?>
                                    <?php if ($discipline['discipline_code']): ?>
                                        (<?= htmlspecialchars($discipline['discipline_code']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
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

<!-- Edit Program Modal -->
<div class="modal fade" id="editProgramModal" tabindex="-1" aria-labelledby="editProgramModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title" id="editProgramModalLabel"><i class="fas fa-edit" aria-hidden="true"></i> Edit Program</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST" id="editProgramForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="edit">
                    <?= lifecycle_edit_fields('programs', 'programs.php?status=' . lifecycle_view_mode('programs')) ?>
                    <input type="hidden" name="program_id" id="editProgramId">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="editProgramCode" class="form-label">Program Code <span class="text-danger" aria-label="required">*</span></label>
                            <input type="text" class="form-control" id="editProgramCode" name="program_code" maxlength="50" required aria-required="true">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="editDegreeType" class="form-label">Degree Type</label>
                            <select class="form-select" id="editDegreeType" name="degree_type">
                                <option value="">Select Degree Type</option>
                                <?php
                                $degreeTypes = explode('||', trim($config->get('app.degree_types', 'Associate of Arts (AA)||Associate of Science (AS)||Associate in Arts for Transfer (AA-T)||Associate in Science for Transfer (AS-T)||Bachelor of Science (BS)||Bachelor of Applied Science (BAS)||Certificate of Achievement (16 or more semester units)||Certificate of Achievement (8-15.5 semester units)||Local Certificate (fewer than 8 semester units)||Noncredit Certificate of Completion||Noncredit Certificate of Competency'), '|'));
                                foreach ($degreeTypes as $type) {
                                    $type = trim($type);
                                    echo '<option value="' . htmlspecialchars($type) . '">' . htmlspecialchars($type) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="editProgramName" class="form-label">Program Name <span class="text-danger" aria-label="required">*</span></label>
                        <input type="text" class="form-control" id="editProgramName" name="program_name" maxlength="255" required aria-required="true">
                    </div>
                    <div class="mb-3">
                        <label for="editTermFk" class="form-label">Term <span class="text-danger" aria-label="required">*</span></label>
                        <select class="form-select" id="editTermFk" name="term_fk" required aria-required="true">
                            <?php foreach ($terms as $term): ?>
                                <option value="<?= $term['terms_pk'] ?>">
                                    <?= htmlspecialchars($term['term_code'] . ' - ' . $term['term_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="editDisciplineFk" class="form-label">Discipline</label>
                        <select class="form-select" id="editDisciplineFk" name="discipline_fk">
                            <option value="">Select Discipline</option>
                            <?php foreach ($disciplines as $discipline): ?>
                                <option value="<?= $discipline['disciplines_pk'] ?>">
                                    <?= htmlspecialchars($discipline['discipline_name']) ?>
                                    <?php if ($discipline['discipline_code']): ?>
                                        (<?= htmlspecialchars($discipline['discipline_code']) ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <hr>
                    <div class="text-muted mb-3"><i class="fas fa-history" aria-hidden="true"></i> Audit Information</div>
                    <div class="row mb-2">
                        <div class="col-md-6">
                            <small class="text-muted">Created:</small>
                            <p class="mb-0" id="editProgramCreated"></p>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted">Created By:</small>
                            <p class="mb-0" id="editProgramCreatedBy"></p>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <small class="text-muted">Last Updated:</small>
                            <p class="mb-0" id="editProgramUpdated"></p>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted">Updated By:</small>
                            <p class="mb-0" id="editProgramUpdatedBy"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <?= lifecycle_edit_controls() ?>
                    <!-- LEFT SIDE: Destructive Actions -->
                    <div>
                        <button type="button" class="btn btn-danger" onclick="confirmDeleteProgram()" aria-label="Delete program">
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
<div class="modal fade" id="viewProgramModal" tabindex="-1" aria-labelledby="viewProgramModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h2 class="modal-title h5" id="viewProgramModalLabel"><i class="fas fa-eye" aria-hidden="true"></i> Program Details</h2>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <strong>Program Code:</strong>
                        <p id="viewProgramCode"></p>
                    </div>
                    <div class="col-md-4">
                        <strong>Degree Type:</strong>
                        <p id="viewDegreeType"></p>
                    </div>
                    <div class="col-md-4">
                        <strong>ID:</strong>
                        <p id="viewProgramId"></p>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-12">
                        <strong>Program Name:</strong>
                        <p id="viewProgramName"></p>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-12">
                        <strong>Term:</strong>
                        <p id="viewProgramTerm"></p>
                    </div>
                </div>
                <hr>
                <div class="text-muted mb-3"><i class="fas fa-history" aria-hidden="true"></i> Audit Information</div>
                <div class="row mb-2">
                    <div class="col-md-6">
                        <strong>Created:</strong>
                        <p id="viewProgramCreated"></p>
                    </div>
                    <div class="col-md-6">
                        <strong>Created By:</strong>
                        <p id="viewProgramCreatedBy"></p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <strong>Last Updated:</strong>
                        <p id="viewProgramUpdated"></p>
                    </div>
                    <div class="col-md-6">
                        <strong>Updated By:</strong>
                        <p id="viewProgramUpdatedBy"></p>
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
    <input type="hidden" name="program_id" id="deleteProgramId">
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
    $('#programsTable thead tr:eq(1) td').each(function(i) {
        var title = $('#programsTable thead tr:eq(0) th:eq(' + i + ')').text();
        if (title === 'Actions') {
            $(this).html('');
        } else if (title === 'Term') {
            var select = '<select class="form-select form-select-sm" id="programTermFkFilter" aria-label="Filter by Term"><option value="">All Terms</option>';
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

    var table = $('#programsTable').DataTable({
        orderCellsTop: true,
        processing: true,
        serverSide: true,
        ajax: {
            url: '<?= BASE_URL ?>administration/programs_data.php',
            type: 'POST',
            data: function(d) {
                d.term_fk = currentTermFk;
            }
        },
        dom: 'rtip',
        columns: [
            { data: 0, name: 'term_code' },
            { data: 1, name: 'discipline_name' },
            { data: 2, name: 'program_code' },
            { data: 3, name: 'program_name' },
            { data: 4, name: 'degree_type' },
            { data: 5, name: 'created_at' },
            { data: 6, name: 'created_by' },
            { data: 7, name: 'updated_at' },
            { data: 8, name: 'updated_by' },
            { data: 9, name: 'actions', orderable: false, searchable: false }
        ],
        order: [[3, 'asc']],
        language: {
            info: "Showing _START_ to _END_ of _TOTAL_ programs",
            infoEmpty: "No programs found",
            infoFiltered: "(filtered from _MAX_ total)",
            emptyTable: "No programs available"
        },
        initComplete: function() {
            var api = this.api();

            // Term FK dropdown
            $('#programTermFkFilter').on('change', function() {
                currentTermFk = $(this).val();
                api.draw();
            });

            // Text inputs for all other columns
            api.columns().every(function(colIdx) {
                var column = this;
                $('input', $('#programsTable thead tr:eq(1) td').eq(colIdx)).on('keyup change clear', function() {
                    if (column.search() !== this.value) {
                        column.search(this.value).draw();
                    }
                });
            });
        }
    });

});

function viewProgram(prog) {
    $('#viewProgramName').text(prog.program_name);
    $('#viewProgramCode').text(prog.program_code);
    $('#viewDegreeType').text(prog.degree_type || 'N/A');
    $('#viewProgramId').text(prog.programs_pk);
    // Display term_code and term_name if available
    var termDisplay = prog.term_code ? (prog.term_code + (prog.term_name ? ' - ' + prog.term_name : '')) : 'N/A';
    $('#viewProgramTerm').text(termDisplay);
    $('#viewProgramCreated').text(prog.created_at || 'N/A');
    $('#viewProgramCreatedBy').text(prog.created_by_name || 'System');
    $('#viewProgramUpdated').text(prog.updated_at || 'N/A');
    $('#viewProgramUpdatedBy').text(prog.updated_by_name || 'System');
    new bootstrap.Modal(document.getElementById('viewProgramModal')).show();
}

function editProgram(prog) {
    $('#editProgramId').val(prog.programs_pk);
    $('#editProgramCode').val(prog.program_code);
    $('#editProgramName').val(prog.program_name);
    $('#editDegreeType').val(prog.degree_type);
    $('#editTermFk').val(prog.term_fk);
    $('#editDisciplineFk').val(prog.discipline_fk || '');
    // Populate read-only audit info
    $('#editProgramCreated').text(prog.created_at || 'N/A');
    $('#editProgramCreatedBy').text(prog.created_by_name || 'System');
    $('#editProgramUpdated').text(prog.updated_at || 'N/A');
    $('#editProgramUpdatedBy').text(prog.updated_by_name || 'System');
    configureLifecycleEditAction('#editProgramForm', prog.programs_pk, prog.is_active, 'program', prog.program_code || '');
    new bootstrap.Modal(document.getElementById('editProgramModal')).show();
}

function deleteProgram(id, name) {
    if (confirm('Are you sure you want to DELETE "' + name + '"?\n\nThis will also delete:\n- All program outcomes (PSLOs)\n- All program-course links\n\nSLOs linked to program outcomes will be preserved with NULL mapping.\n\nThis action cannot be undone.')) {
        $('#deleteProgramId').val(id);
        $('#deleteForm').submit();
    }
}

function confirmDeleteProgram() {
    const programPk = $('#editProgramId').val();
    const programName = $('#editProgramName').val();
    deleteProgram(programPk, programName);
}

function exportAllPrograms() {
    var button = event.target.closest('button');
    var originalHTML = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
    button.disabled = true;
    
    $.ajax({
        url: '<?= BASE_URL ?>administration/programs_data.php',
        data: {
            start: 0,
            length: -1,
            draw: 1,
            term_fk: <?= json_encode($selectedTermFk) ?>
        },
        success: function(response) {
            var csvContent = "Term Code,Discipline,Program Code,Program Name,Degree Type,Created At,Created By,Updated At,Updated By\n";
            
            response.data.forEach(function(row) {
                var termCode = escapeCSV(row[0]);
                var discipline = escapeCSV(row[1]);
                var programCode = escapeCSV(row[2]);
                var programName = escapeCSV(row[3]);
                var degreeType = escapeCSV(row[4]);
                var createdAt = escapeCSV(row[5]);
                var createdBy = escapeCSV(row[6]);
                var updatedAt = escapeCSV(row[7]);
                var updatedBy = escapeCSV(row[8]);
                
                csvContent += termCode + "," + discipline + "," + programCode + "," + programName + "," + degreeType + "," + createdAt + "," + createdBy + "," + updatedAt + "," + updatedBy + "\n";
            });
            
            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement("a");
            var url = URL.createObjectURL(blob);
            link.setAttribute("href", url);
            link.setAttribute("download", "programs_export_" + new Date().toISOString().slice(0,10) + ".csv");
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
