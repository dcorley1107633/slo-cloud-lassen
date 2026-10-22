<?php
declare(strict_types=1);

/**
 * Program Outcomes Administration
 * 
 * Manage program-level learning outcomes.
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
                $programFk = (int)($_POST['program_fk'] ?? 0);
                $institutionalOutcomesFk = !empty($_POST['institutional_outcomes_fk']) ? (int)$_POST['institutional_outcomes_fk'] : null;
                $outcomeCode = trim($_POST['outcome_code'] ?? '');
                $outcomeDescription = trim($_POST['outcome_description'] ?? '');
                $sequenceNum = (int)($_POST['sequence_num'] ?? 0);
                
                // Validation
                $errors = [];
                if ($programFk <= 0) {
                    $errors[] = 'Program is required';
                }
                if (empty($outcomeCode)) {
                    $errors[] = 'Outcome code is required';
                } elseif (!preg_match('/^[A-Z0-9_.-]+$/i', $outcomeCode)) {
                    $errors[] = 'Outcome code can only contain letters, numbers, hyphens, underscores, and periods';
                } else {
                    // Check uniqueness for program + code combination
                    $result = $db->query(
                        "SELECT COUNT(*) as count FROM {$dbPrefix}program_outcomes WHERE outcome_code = ? AND program_fk = ?",
                        [$outcomeCode, $programFk],
                        'si'
                    );
                    $row = fetch_row($result);
                    if ($row['count'] > 0) {
                        $errors[] = 'Outcome code already exists for this program';
                    }
                }
                if (empty($outcomeDescription)) {
                    $errors[] = 'Description is required';
                }
                
                if (empty($errors)) {
                    $userId = getValidUserId();
                    if ($institutionalOutcomesFk !== null) {
                        $db->query(
                            "INSERT INTO {$dbPrefix}program_outcomes (program_fk, institutional_outcomes_fk, outcome_code, outcome_description, sequence_num, created_at, updated_at, created_by_fk, updated_by_fk) 
                             VALUES (?, ?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                            [$programFk, $institutionalOutcomesFk, $outcomeCode, $outcomeDescription, $sequenceNum, $userId, $userId],
                            'iissiii'
                        );
                    } else {
                        $db->query(
                            "INSERT INTO {$dbPrefix}program_outcomes (program_fk, outcome_code, outcome_description, sequence_num, created_at, updated_at, created_by_fk, updated_by_fk) 
                             VALUES (?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                            [$programFk, $outcomeCode, $outcomeDescription, $sequenceNum, $userId, $userId],
                            'issiii'
                        );
                    }
                    $successMessage = 'Program outcome added successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
                }
                break;
                
            case 'edit':
                $id = (int)($_POST['outcome_id'] ?? 0);
                $programFk = (int)($_POST['program_fk'] ?? 0);
                $institutionalOutcomesFk = !empty($_POST['institutional_outcomes_fk']) ? (int)$_POST['institutional_outcomes_fk'] : null;
                $outcomeCode = trim($_POST['outcome_code'] ?? '');
                $outcomeDescription = trim($_POST['outcome_description'] ?? '');
                $sequenceNum = (int)($_POST['sequence_num'] ?? 0);
                
                // Validation
                $errors = [];
                if ($id <= 0) {
                    $errors[] = 'Invalid outcome ID';
                }
                if ($programFk <= 0) {
                    $errors[] = 'Program is required';
                }
                if (empty($outcomeCode)) {
                    $errors[] = 'Outcome code is required';
                } elseif (!preg_match('/^[A-Z0-9_.-]+$/i', $outcomeCode)) {
                    $errors[] = 'Outcome code can only contain letters, numbers, hyphens, underscores, and periods';
                } else {
                    // Check uniqueness (excluding current record)
                    $result = $db->query(
                        "SELECT COUNT(*) as count FROM {$dbPrefix}program_outcomes 
                         WHERE outcome_code = ? AND program_fk = ? AND program_outcomes_pk != ?",
                        [$outcomeCode, $programFk, $id],
                        'sii'
                    );
                    $row = fetch_row($result);
                    if ($row['count'] > 0) {
                        $errors[] = 'Outcome code already exists for this program';
                    }
                }
                if (empty($outcomeDescription)) {
                    $errors[] = 'Description is required';
                }
                
                if (empty($errors)) {
                    $userId = getValidUserId();
                    if ($institutionalOutcomesFk !== null) {
                        $db->query(
                            "UPDATE {$dbPrefix}program_outcomes 
                             SET program_fk = ?, institutional_outcomes_fk = ?, outcome_code = ?, outcome_description = ?, sequence_num = ?, updated_at = {$db->now()}, updated_by_fk = ?
                             WHERE program_outcomes_pk = ?",
                            [$programFk, $institutionalOutcomesFk, $outcomeCode, $outcomeDescription, $sequenceNum, $userId, $id],
                            'iissii'
                        );
                    } else {
                        $db->query(
                            "UPDATE {$dbPrefix}program_outcomes 
                             SET program_fk = ?, institutional_outcomes_fk = NULL, outcome_code = ?, outcome_description = ?, sequence_num = ?, updated_at = {$db->now()}, updated_by_fk = ?
                             WHERE program_outcomes_pk = ?",
                            [$programFk, $outcomeCode, $outcomeDescription, $sequenceNum, $userId, $id],
                            'issii'
                        );
                    }
                    $successMessage = 'Program outcome updated successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
                }
                break;
                
            case 'delete':
                $id = (int)($_POST['outcome_id'] ?? 0);
                if ($id > 0) {
                    // Cascade behavior: SLOs linked to this outcome will have their program_outcomes_fk set to NULL
                    $db->query(
                        "DELETE FROM {$dbPrefix}program_outcomes WHERE program_outcomes_pk = ?",
                        [$id],
                        'i'
                    );
                    $successMessage = 'Program outcome deleted successfully (SLO links preserved with NULL mapping)';
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

// Fetch terms for dropdown (sorted descending with latest first)
$termsResult = $db->query("
    SELECT terms_pk, term_code, term_name, academic_year
    FROM {$dbPrefix}terms
    ORDER BY term_code ASC
");
$terms = $termsResult->fetchAll();

// Get selected term (handled by getSelectedTermFk())
$selectedTermFk = getSelectedTermFk();

// Get selected term name from session (cached during getSelectedTermFk())
$selectedTermName = getSelectedTermName() ?? '';
$selectedTermCode = getSelectedTermCode() ?? '';

// Fetch programs for dropdown
$programsResult = $db->query("SELECT * FROM {$dbPrefix}programs WHERE is_active = 1 ORDER BY program_name ASC");
$programs = $programsResult->fetchAll();

// Fetch institutional outcomes for dropdown
$institutionalOutcomesResult = $db->query(
    "SELECT io.institutional_outcomes_pk, io.outcome_code, io.outcome_description
     FROM {$dbPrefix}institutional_outcomes io
     ORDER BY io.outcome_code"
);
$institutionalOutcomes = $institutionalOutcomesResult->fetchAll();

// Calculate statistics (filtered by term)
$termFilter = $selectedTermFk ? "WHERE p.term_fk = {$selectedTermFk}" : '';
$statsResult = $db->query("
    SELECT COUNT(*) as total
    FROM {$dbPrefix}program_outcomes po
    JOIN {$dbPrefix}programs p ON po.program_fk = p.programs_pk
    {$termFilter}
");
$stats = fetch_row($statsResult);
$totalOutcomes = $stats['total'];

// Load theme system
require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$pageTitle = 'Program Outcomes';
if ($selectedTermName) {
    $pageTitle .= ' - ' . $selectedTermName;
    if ($selectedTermCode) {
        $pageTitle .= ' (' . $selectedTermCode . ')';
    }
}

$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => $pageTitle,
    'currentPage' => 'admin_program_outcomes',
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['label' => 'Program Outcomes']
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
        
        <!-- Program Outcomes Table -->
        <div class="card shadow-sm">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-bullseye" aria-hidden="true"></i> Program Outcomes
                </h2>
                <div class="card-tools">
                    <button type="button" class="btn btn-success btn-sm" onclick="exportAllPSLOs()" aria-label="Export all program outcome records to CSV">
                        <i class="fas fa-download" aria-hidden="true"></i> Export All to CSV
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addOutcomeModal" aria-label="Add new program outcome">
                        <i class="fas fa-plus" aria-hidden="true"></i> Add Outcome
                    </button>
                </div>
            </div>
            <div class="card-body">
                <table id="outcomesTable" class="table table-bordered table-striped table-hover" aria-label="Program outcomes data table">
                    <caption class="visually-hidden">List of program outcomes with filtering and sorting capabilities</caption>
                    <thead>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Program</th>
                            <th scope="col">PSLO Code</th>
                            <th scope="col">PSLO Language</th>
                            <th scope="col">ISLO Map</th>
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
                            <td>&nbsp;</td>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
</div>


<!-- Add Outcome Modal -->
<div class="modal fade" id="addOutcomeModal" tabindex="-1" aria-labelledby="addOutcomeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <span class="modal-title" id="addOutcomeModalLabel">
                        <i class="fas fa-plus" aria-hidden="true"></i> Add Program Outcome
                    </span>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close dialog"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="programFk" class="form-label">Program <span class="text-danger" aria-label="required">*</span></label>
                        <select class="form-select" id="programFk" name="program_fk" required aria-required="true">
                            <option value="">-- Select Program --</option>
                            <?php foreach ($programs as $prog): ?>
                                <option value="<?= $prog['programs_pk'] ?>">
                                    <?= htmlspecialchars($prog['program_code'] . ' - ' . $prog['program_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="outcomeCode" class="form-label">Outcome Code <span class="text-danger" aria-label="required">*</span></label>
                        <input type="text" class="form-control" id="outcomeCode" name="outcome_code" 
                               required aria-required="true" pattern="[A-Z0-9_.-]+" 
                               placeholder="e.g., PO1, PO-2, OUTCOME1" aria-describedby="outcomeCodeHelp">
                        <small id="outcomeCodeHelp" class="form-text text-muted">Letters, numbers, hyphens, underscores, and periods only</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="outcomeDescription" class="form-label">Description <span class="text-danger" aria-label="required">*</span></label>
                        <textarea class="form-control" id="outcomeDescription" name="outcome_description" 
                                  rows="3" required aria-required="true" placeholder="Enter outcome description"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="institutionalOutcomesFk" class="form-label">Institutional Outcome (Optional)</label>
                        <select class="form-select" id="institutionalOutcomesFk" name="institutional_outcomes_fk">
                            <option value="">-- No Mapping --</option>
                            <?php foreach ($institutionalOutcomes as $io): ?>
                                <option value="<?= $io['institutional_outcomes_pk'] ?>">
                                    <?= htmlspecialchars($io['outcome_code'] . ' - ' . substr($io['outcome_description'], 0, 60)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="sequenceNum" class="form-label">Sequence Number</label>
                            <input type="number" class="form-control" id="sequenceNum" name="sequence_num" 
                                   value="0" min="0">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save" aria-hidden="true"></i> Add Outcome
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Outcome Modal -->
<div class="modal fade" id="editOutcomeModal" tabindex="-1" aria-labelledby="editOutcomeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" action="" id="editProgramOutcomeForm">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="edit">
                <?= lifecycle_edit_fields('program_outcomes', 'program_outcomes.php?status=' . lifecycle_view_mode('program_outcomes')) ?>
                <input type="hidden" name="outcome_id" id="editOutcomeId">
                <div class="modal-header">
                    <span class="modal-title" id="editOutcomeModalLabel">
                        <i class="fas fa-edit" aria-hidden="true"></i> Edit Program Outcome
                    </span>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close dialog"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="editProgramFk" class="form-label">Program <span class="text-danger" aria-label="required">*</span></label>
                        <select class="form-select" id="editProgramFk" name="program_fk" required aria-required="true">
                            <option value="">-- Select Program --</option>
                            <?php foreach ($programs as $prog): ?>
                                <option value="<?= $prog['programs_pk'] ?>">
                                    <?= htmlspecialchars($prog['program_code'] . ' - ' . $prog['program_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editOutcomeCode" class="form-label">Outcome Code <span class="text-danger" aria-label="required">*</span></label>
                        <input type="text" class="form-control" id="editOutcomeCode" name="outcome_code" 
                               required aria-required="true" pattern="[A-Z0-9_.-]+" aria-describedby="editOutcomeCodeHelp">
                        <small id="editOutcomeCodeHelp" class="form-text text-muted">Letters, numbers, hyphens, underscores, and periods only</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editOutcomeDescription" class="form-label">Description <span class="text-danger" aria-label="required">*</span></label>
                        <textarea class="form-control" id="editOutcomeDescription" name="outcome_description" 
                                  rows="3" required aria-required="true"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="editInstitutionalOutcomesFk" class="form-label">Institutional Outcome (Optional)</label>
                        <select class="form-select" id="editInstitutionalOutcomesFk" name="institutional_outcomes_fk">
                            <option value="">-- No Mapping --</option>
                            <?php foreach ($institutionalOutcomes as $io): ?>
                                <option value="<?= $io['institutional_outcomes_pk'] ?>">
                                    <?= htmlspecialchars($io['outcome_code'] . ' - ' . substr($io['outcome_description'], 0, 60)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="editSequenceNum" class="form-label">Sequence Number</label>
                            <input type="number" class="form-control" id="editSequenceNum" name="sequence_num" 
                                   value="0" min="0">
                        </div>
                    </div>
                    <hr>
                    <div class="text-muted mb-3"><i class="fas fa-history" aria-hidden="true"></i> Audit Information</div>
                    <div class="row mb-2">
                        <div class="col-md-6">
                            <small class="text-muted">Created:</small>
                            <p class="mb-0" id="editCreated"></p>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted">Created By:</small>
                            <p class="mb-0" id="editCreatedBy"></p>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <small class="text-muted">Last Updated:</small>
                            <p class="mb-0" id="editUpdated"></p>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted">Updated By:</small>
                            <p class="mb-0" id="editUpdatedBy"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <?= lifecycle_edit_controls() ?>
                    <!-- LEFT SIDE: Destructive Actions -->
                    <div>
                        <button type="button" class="btn btn-danger" onclick="confirmDeleteOutcome()" aria-label="Delete outcome">
                            <i class="fas fa-trash" aria-hidden="true"></i> Delete
                        </button>
                    </div>
                    <!-- RIGHT SIDE: Primary Actions -->
                    <div>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save" aria-hidden="true"></i> Update Outcome
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Outcome Modal -->
<div class="modal fade" id="viewOutcomeModal" tabindex="-1" aria-labelledby="viewOutcomeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <span class="modal-title" id="viewOutcomeModalLabel">
                    <i class="fas fa-eye"></i> View Program Outcome
                </span>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <dl class="row">
                    <dt class="col-sm-3">ID:</dt>
                    <dd class="col-sm-9" id="viewId"></dd>
                    
                    <dt class="col-sm-3">Program:</dt>
                    <dd class="col-sm-9" id="viewProgram"></dd>
                    
                    <dt class="col-sm-3">Code:</dt>
                    <dd class="col-sm-9" id="viewCode"></dd>
                    
                    <dt class="col-sm-3">Description:</dt>
                    <dd class="col-sm-9" id="viewDescription"></dd>
                    
                    <dt class="col-sm-3">Institutional Outcome:</dt>
                    <dd class="col-sm-9" id="viewInstitutionalOutcome"></dd>
                    
                    <dt class="col-sm-3">Sequence:</dt>
                    <dd class="col-sm-9" id="viewSequence"></dd>
                </dl>
                <hr>
                <div class="text-muted mb-3"><i class="fas fa-history"></i> Audit Information</div>
                <dl class="row">
                    <dt class="col-sm-3">Created:</dt>
                    <dd class="col-sm-9" id="viewCreated"></dd>
                    
                    <dt class="col-sm-3">Created By:</dt>
                    <dd class="col-sm-9" id="viewCreatedBy"></dd>
                    
                    <dt class="col-sm-3">Updated:</dt>
                    <dd class="col-sm-9" id="viewUpdated"></dd>
                    
                    <dt class="col-sm-3">Updated By:</dt>
                    <dd class="col-sm-9" id="viewUpdatedBy"></dd>
                </dl>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden forms for delete -->
<form id="deleteForm" method="POST" action="" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="outcome_id" id="deleteOutcomeId">
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
// Convert PHP arrays to JavaScript
var programs = <?= json_encode(array_map(function($p) { 
    return ['pk' => $p['programs_pk'], 'name' => $p['program_name'], 'code' => $p['program_code']]; 
}, $programs)) ?>;
var institutionalOutcomes = <?= json_encode(array_map(function($io) { 
    return ['code' => $io['outcome_code']]; 
}, $institutionalOutcomes)) ?>;

$(document).ready(function() {

    // Setup - add filters to each header cell (second row)
    $('#outcomesTable thead tr:eq(1) td').each(function(i) {
        var title = $('#outcomesTable thead tr:eq(0) th:eq(' + i + ')').text();
        
        // Program column (index 1)
        if (title === 'Program') {
            var select = $('<select class="form-select form-select-sm" aria-label="Filter by ' + title + '"><option value="">All Programs</option></select>')
                .appendTo($(this).empty());
            programs.forEach(function(program) {
                select.append('<option value="' + program.pk + '">' + program.name + '</option>');
            });
        }
        // Institutional Outcome column (index 4)
        else if (title === 'ISLO Map') {
            var select = $('<select class="form-select form-select-sm" aria-label="Filter by ' + title + '"><option value="">All</option></select>')
                .appendTo($(this).empty());
            institutionalOutcomes.forEach(function(io) {
                if (io.code) {
                    select.append('<option value="' + io.code + '">' + io.code + '</option>');
                }
            });
        }
        else if (title !== 'Actions' && title !== 'ID') {
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder="Search ' + title + '" aria-label="Filter by ' + title + '" />');
        } else {
            $(this).html('');
        }
    });
    
    // Initialize DataTable
    var table = $('#outcomesTable').DataTable({
        orderCellsTop: true,
        processing: true,
        serverSide: true,
        ajax: {
            url: '<?= BASE_URL ?>administration/program_outcomes_data.php',
            type: 'POST',
            data: function(d) {
                d.term_fk = <?= json_encode($selectedTermFk) ?>;
            }
        },
        dom: 'rtip',
        order: [[5, 'asc'], [2, 'asc']],
        pageLength: 25,
        columns: [
            { data: 0, name: 'program_outcomes_pk' },
            { data: 1, name: 'program_name' },
            { data: 2, name: 'outcome_code' },
            { data: 3, name: 'outcome_description' },
            { data: 4, name: 'institutional_outcome_code' },
            { data: 5, name: 'sequence_num' },
            { data: 6, name: 'created_at' },
            { data: 7, name: 'created_by_name' },
            { data: 8, name: 'updated_at' },
            { data: 9, name: 'updated_by_name' },
            { data: 10, name: 'actions', orderable: false }
        ],
        columnDefs: [
            { targets: [0], visible: false }
        ],
        language: {
            info: "Showing _START_ to _END_ of _TOTAL_ program outcomes",
            infoEmpty: "No program outcomes found",
            infoFiltered: "(filtered from _MAX_ total)",
            emptyTable: "No program outcomes available",
            search: "_INPUT_",
            searchPlaceholder: "Search outcomes..."
        },
        initComplete: function() {
            // Apply the search - target the second header row where filters are
            var api = this.api();
            api.columns().every(function(colIdx) {
                var column = this;
                
                // Skip hidden columns (ID column is hidden at index 0)
                if (!column.visible()) {
                    return;
                }
                
                // For visible columns, get the index among visible columns only
                var visibleIndex = api.columns(':visible').indexes().toArray().indexOf(colIdx);
                var filterCell = $('#outcomesTable thead tr:eq(1) td').eq(visibleIndex);
                
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

function viewOutcome(outcome) {
    $('#viewId').text(outcome.program_outcomes_pk);
    $('#viewProgram').text(outcome.program_name);
    $('#viewCode').text(outcome.outcome_code);
    $('#viewDescription').text(outcome.outcome_description);
    $('#viewInstitutionalOutcome').text(outcome.institutional_outcome_code || 'N/A');
    $('#viewSequence').text(outcome.sequence_num);
    $('#viewCreated').text(outcome.created_at || 'N/A');
    $('#viewCreatedBy').text(outcome.created_by_name || 'System');
    $('#viewUpdated').text(outcome.updated_at || 'N/A');
    $('#viewUpdatedBy').text(outcome.updated_by_name || 'System');
    new bootstrap.Modal(document.getElementById('viewOutcomeModal')).show();
}

function editOutcome(outcome) {
    $('#editOutcomeId').val(outcome.program_outcomes_pk);
    $('#editProgramFk').val(outcome.program_fk);
    $('#editOutcomeCode').val(outcome.outcome_code);
    $('#editOutcomeDescription').val(outcome.outcome_description);
    $('#editInstitutionalOutcomesFk').val(outcome.institutional_outcomes_fk || '');
    $('#editSequenceNum').val(outcome.sequence_num);
    // Populate read-only audit info
    $('#editCreated').text(outcome.created_at || 'N/A');
    $('#editCreatedBy').text(outcome.created_by_name || 'System');
    $('#editUpdated').text(outcome.updated_at || 'N/A');
    $('#editUpdatedBy').text(outcome.updated_by_name || 'System');
    configureLifecycleEditAction('#editProgramOutcomeForm', outcome.program_outcomes_pk, outcome.is_active, 'program outcome', outcome.outcome_code || '');
    new bootstrap.Modal(document.getElementById('editOutcomeModal')).show();
}

function deleteOutcome(id, code) {
    if (confirm('Are you sure you want to DELETE outcome "' + code + '"?\n\nSLOs linked to this outcome will be preserved with NULL mapping.\n\nThis action cannot be undone.')) {
        $('#deleteOutcomeId').val(id);
        $('#deleteForm').submit();
    }
}

function confirmDeleteOutcome() {
    const outcomePk = $('#editOutcomeId').val();
    const outcomeCode = $('#editOutcomeCode').val();
    deleteOutcome(outcomePk, outcomeCode);
}

function exportAllPSLOs() {
    var button = event.target.closest('button');
    var originalHTML = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
    button.disabled = true;
    
    $.ajax({
        url: '<?= BASE_URL ?>administration/program_outcomes_data.php',
        data: {
            start: 0,
            length: -1,
            draw: 1,
            term_fk: <?= json_encode($selectedTermFk) ?>
        },
        success: function(response) {
            var csvContent = "ID,Program,PSLO Code,PSLO Language,Institutional Outcome,Sequence,Created At,Created By,Updated At,Updated By\n";
            
            response.data.forEach(function(row) {
                var id = escapeCSV(row[0]);
                var program = escapeCSV(row[1]);
                var outcomeCode = escapeCSV(row[2]);
                var description = escapeCSV(row[3]);
                var instOutcome = escapeCSV(row[4]);
                var sequence = escapeCSV(row[5]);
                var createdAt = escapeCSV(row[6]);
                var createdBy = escapeCSV(row[7]);
                var updatedAt = escapeCSV(row[8]);
                var updatedBy = escapeCSV(row[9]);
                
                csvContent += id + "," + program + "," + outcomeCode + "," + description + "," + instOutcome + "," + sequence + "," + createdAt + "," + createdBy + "," + updatedAt + "," + updatedBy + "\n";
            });
            
            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement("a");
            var url = URL.createObjectURL(blob);
            link.setAttribute("href", url);
            link.setAttribute("download", "program_outcomes_export_" + new Date().toISOString().slice(0,10) + ".csv");
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
