<?php
declare(strict_types=1);

/**
 * Institutional Outcomes Administration
 * 
 * Manage institutional-level learning outcomes.
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
                $termFk = (int)($_POST['term_fk'] ?? 0);
                $outcomeCode = trim($_POST['outcome_code'] ?? '');
                $outcomeDescription = trim($_POST['outcome_description'] ?? '');
                $sequenceNum = (int)($_POST['sequence_num'] ?? 0);
                
                // Validation
                $errors = [];
                if ($termFk <= 0) {
                    $errors[] = 'Term is required';
                }
                if (empty($outcomeCode)) {
                    $errors[] = 'Outcome code is required';
                } elseif (!preg_match('/^[A-Z0-9_.-]+$/i', $outcomeCode)) {
                    $errors[] = 'Outcome code can only contain letters, numbers, hyphens, underscores, and periods';
                } else {
                    // Check uniqueness within the term
                    $result = $db->query(
                        "SELECT COUNT(*) as count FROM {$dbPrefix}institutional_outcomes WHERE term_fk = ? AND outcome_code = ?",
                        [$termFk, $outcomeCode],
                        'is'
                    );
                    $row = $result->fetch();
                    if ($row['count'] > 0) {
                        $errors[] = 'Outcome code already exists in this term';
                    }
                }
                if (empty($outcomeDescription)) {
                    $errors[] = 'Description is required';
                }
                
                if (empty($errors)) {
                    $userId = getValidUserId();
                    $db->query(
                        "INSERT INTO {$dbPrefix}institutional_outcomes (term_fk, outcome_code, outcome_description, sequence_num, created_at, updated_at, created_by_fk, updated_by_fk) 
                         VALUES (?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                        [$termFk, $outcomeCode, $outcomeDescription, $sequenceNum, $userId, $userId],
                        'issiii'
                    );
                    $successMessage = 'Institutional outcome added successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
                }
                break;
                
            case 'edit':
                $id = (int)($_POST['outcome_id'] ?? 0);
                $termFk = (int)($_POST['term_fk'] ?? 0);
                $outcomeCode = trim($_POST['outcome_code'] ?? '');
                $outcomeDescription = trim($_POST['outcome_description'] ?? '');
                $sequenceNum = (int)($_POST['sequence_num'] ?? 0);
                
                // Validation
                $errors = [];
                if ($id <= 0) {
                    $errors[] = 'Invalid outcome ID';
                }
                if ($termFk <= 0) {
                    $errors[] = 'Term is required';
                }
                if (empty($outcomeCode)) {
                    $errors[] = 'Outcome code is required';
                } elseif (!preg_match('/^[A-Z0-9_.-]+$/i', $outcomeCode)) {
                    $errors[] = 'Outcome code can only contain letters, numbers, hyphens, underscores, and periods';
                } else {
                    // Check uniqueness within the term (excluding current record)
                    $result = $db->query(
                        "SELECT COUNT(*) as count FROM {$dbPrefix}institutional_outcomes 
                         WHERE term_fk = ? AND outcome_code = ? AND institutional_outcomes_pk != ?",
                        [$termFk, $outcomeCode, $id],
                        'isi'
                    );
                    $row = $result->fetch();
                    if ($row['count'] > 0) {
                        $errors[] = 'Outcome code already exists in this term';
                    }
                }
                if (empty($outcomeDescription)) {
                    $errors[] = 'Description is required';
                }
                
                if (empty($errors)) {
                    $userId = getValidUserId();
                    $db->query(
                        "UPDATE {$dbPrefix}institutional_outcomes 
                         SET term_fk = ?, outcome_code = ?, outcome_description = ?, sequence_num = ?, updated_at = {$db->now()}, updated_by_fk = ?
                         WHERE institutional_outcomes_pk = ?",
                        [$termFk, $outcomeCode, $outcomeDescription, $sequenceNum, $userId, $id],
                        'issiii'
                    );
                    $successMessage = 'Institutional outcome updated successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
                }
                break;
                
                
            case 'delete':
                $id = (int)($_POST['outcome_id'] ?? 0);
                if ($id > 0) {
                    // Cascade behavior: Program outcomes linked to this will have their institutional_outcomes_fk set to NULL
                    $db->query(
                        "DELETE FROM {$dbPrefix}institutional_outcomes WHERE institutional_outcomes_pk = ?",
                        [$id],
                        'i'
                    );
                    $successMessage = 'Institutional outcome deleted successfully (program outcome links preserved with NULL mapping)';
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

// Calculate statistics (filtered by term)
$termFilter = $selectedTermFk ? "WHERE term_fk = {$selectedTermFk}" : '';
$statsResult = $db->query("
    SELECT COUNT(*) as total
    FROM {$dbPrefix}institutional_outcomes
    {$termFilter}
");
$stats = $statsResult->fetch();
$totalOutcomes = $stats['total'] ?? 0;

// Load theme system
require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$pageTitle = 'Institutional Outcomes';
if ($selectedTermName) {
    $pageTitle .= ' - ' . $selectedTermName;
    if ($selectedTermCode) {
        $pageTitle .= ' (' . $selectedTermCode . ')';
    }
}

$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => $pageTitle,
    'currentPage' => 'admin_institutional_outcomes',
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['label' => 'Institutional Outcomes']
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

        <!-- Outcomes Table -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-table" aria-hidden="true"></i> Institutional Learning Outcomes</h2>
                <div class="card-tools">
                    <button type="button" class="btn btn-success btn-sm" onclick="exportAllISLOs()" aria-label="Export all institutional outcome records to CSV">
                        <i class="fas fa-download" aria-hidden="true"></i> Export All to CSV
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addOutcomeModal" aria-label="Add new institutional outcome">
                        <i class="fas fa-plus" aria-hidden="true"></i> Add Outcome
                    </button>
                </div>
            </div>
            <div class="card-body">
                <table id="outcomesTable" class="table table-bordered table-striped" aria-label="Institutional outcomes data table">
                    <caption class="visually-hidden">List of institutional learning outcomes with filtering and sorting capabilities</caption>
                    <thead>
                        <tr>
                            <th scope="col">Term</th>
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

<!-- Add Outcome Modal -->
<div class="modal fade" id="addOutcomeModal" tabindex="-1" aria-labelledby="addOutcomeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title" id="addOutcomeModalLabel"><i class="fas fa-plus" aria-hidden="true"></i> Add Institutional Outcome</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="add">
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label for="addTermFk" class="form-label">Term <span class="text-danger" aria-label="required">*</span></label>
                            <select class="form-select" id="addTermFk" name="term_fk" required aria-required="true">
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?= $term['terms_pk'] ?>" <?= $term['terms_pk'] == $selectedTermFk ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($term['term_code'] . ' - ' . $term['term_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="outcomeCode" class="form-label">Outcome Code <span class="text-danger" aria-label="required">*</span></label>
                            <input type="text" class="form-control" id="outcomeCode" name="outcome_code" maxlength="50" required aria-required="true" aria-describedby="outcomeCodeHelp">
                            <small id="outcomeCodeHelp" class="form-text text-muted">e.g., ILO-1, INST-A</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="sequenceNum" class="form-label">Sequence Number</label>
                            <input type="number" class="form-control" id="sequenceNum" name="sequence_num" min="0" value="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="outcomeDescription" class="form-label">Description <span class="text-danger" aria-label="required">*</span></label>
                        <textarea class="form-control" id="outcomeDescription" name="outcome_description" rows="4" required aria-required="true"></textarea>
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

<!-- Edit Outcome Modal -->
<div class="modal fade" id="editOutcomeModal" tabindex="-1" aria-labelledby="editOutcomeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title" id="editOutcomeModalLabel"><i class="fas fa-edit" aria-hidden="true"></i> Edit Institutional Outcome</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST" id="editInstitutionalOutcomeForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="edit">
                    <?= lifecycle_edit_fields('institutional_outcomes', 'institutional_outcomes.php?status=' . lifecycle_view_mode('institutional_outcomes')) ?>
                    <input type="hidden" name="outcome_id" id="editOutcomeId">
                    <div class="row">
                        <div class="col-md-12 mb-3">
                            <label for="editTermFk" class="form-label">Term <span class="text-danger" aria-label="required">*</span></label>
                            <select class="form-select" id="editTermFk" name="term_fk" required aria-required="true">
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?= $term['terms_pk'] ?>">
                                        <?= htmlspecialchars($term['term_code'] . ' - ' . $term['term_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="editOutcomeCode" class="form-label">Outcome Code <span class="text-danger" aria-label="required">*</span></label>
                            <input type="text" class="form-control" id="editOutcomeCode" name="outcome_code" maxlength="50" required aria-required="true">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="editSequenceNum" class="form-label">Sequence Number</label>
                            <input type="number" class="form-control" id="editSequenceNum" name="sequence_num" min="0">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="editOutcomeDescription" class="form-label">Description <span class="text-danger" aria-label="required">*</span></label>
                        <textarea class="form-control" id="editOutcomeDescription" name="outcome_description" rows="4" required aria-required="true"></textarea>
                    </div>
                    <hr>
                    <div class="text-muted mb-3"><i class="fas fa-history" aria-hidden="true"></i> Audit Information</div>
                    <div class="row mb-2">
                        <div class="col-md-6">
                            <small class="text-muted">Created:</small>
                            <p class="mb-0" id="editOutcomeCreated"></p>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted">Created By:</small>
                            <p class="mb-0" id="editOutcomeCreatedBy"></p>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <small class="text-muted">Last Updated:</small>
                            <p class="mb-0" id="editOutcomeUpdated"></p>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted">Updated By:</small>
                            <p class="mb-0" id="editOutcomeUpdatedBy"></p>
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
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Update</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="modal fade" id="viewOutcomeModal" tabindex="-1" aria-labelledby="viewOutcomeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h2 class="modal-title h5" id="viewOutcomeModalLabel"><i class="fas fa-eye" aria-hidden="true"></i> Outcome Details</h2>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <strong>Outcome Code:</strong>
                        <p id="viewOutcomeCode"></p>
                    </div>
                    <div class="col-md-4">
                        <strong>Sequence:</strong>
                        <p id="viewSequenceNum"></p>
                    </div>
                    <div class="col-md-4">
                        <strong>ID:</strong>
                        <p id="viewOutcomeId"></p>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <strong>Term:</strong>
                        <p id="viewOutcomeTerm"></p>
                    </div>
                </div>
                <div class="row mb-3">
                    <div class="col-12">
                        <strong>Description:</strong>
                        <p id="viewOutcomeDescription"></p>
                    </div>
                </div>
                <hr>
                <div class="text-muted mb-3"><i class="fas fa-history" aria-hidden="true"></i> Audit Information</div>
                <div class="row mb-2">
                    <div class="col-md-6">
                        <strong>Created:</strong>
                        <p id="viewOutcomeCreated"></p>
                    </div>
                    <div class="col-md-6">
                        <strong>Created By:</strong>
                        <p id="viewOutcomeCreatedBy"></p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <strong>Last Updated:</strong>
                        <p id="viewOutcomeUpdated"></p>
                    </div>
                    <div class="col-md-6">
                        <strong>Updated By:</strong>
                        <p id="viewOutcomeUpdatedBy"></p>
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
$(document).ready(function() {
    // Setup - add a text input or dropdown to each header cell (second row)
    $('#outcomesTable thead tr:eq(1) td').each(function(i) {
        var title = $('#outcomesTable thead tr:eq(0) th:eq(' + i + ')').text();
        if (title === 'Actions') {
            $(this).html('');
        } else if (title === 'Term') {
            var select = '<select class="form-select form-select-sm" id="outcomesTermFkFilter" aria-label="Filter by Term"><option value="">All Terms</option>';
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

    var table = $('#outcomesTable').DataTable({
        orderCellsTop: true,
        processing: true,
        serverSide: true,
        ajax: {
            url: '<?= BASE_URL ?>administration/institutional_outcomes_data.php',
            type: 'POST',
            data: function(d) {
                d.term_fk = currentTermFk;
            }
        },
        dom: 'rtip',
        columns: [
            { data: 0, name: 'term_code' },
            { data: 1, name: 'outcome_code' },
            { data: 2, name: 'outcome_description' },
            { data: 3, name: 'sequence_num' },
            { data: 4, name: 'created_at' },
            { data: 5, name: 'created_by' },
            { data: 6, name: 'updated_at' },
            { data: 7, name: 'updated_by' },
            { data: 8, name: 'actions', orderable: false, searchable: false }
        ],
        language: {
            info: "Showing _START_ to _END_ of _TOTAL_ institutional outcomes",
            infoEmpty: "No institutional outcomes found",
            infoFiltered: "(filtered from _MAX_ total)",
            emptyTable: "No institutional outcomes available"
        },
        initComplete: function() {
            var api = this.api();

            // Term FK dropdown
            $('#outcomesTermFkFilter').on('change', function() {
                currentTermFk = $(this).val();
                api.draw();
            });

            // Text inputs for all other columns
            api.columns().every(function(colIdx) {
                var column = this;
                $('input', $('#outcomesTable thead tr:eq(1) td').eq(colIdx)).on('keyup change clear', function() {
                    if (column.search() !== this.value) {
                        column.search(this.value).draw();
                    }
                });
            });
        }
    });

});

function viewOutcome(outcome) {
    $('#viewOutcomeCode').text(outcome.outcome_code);
    $('#viewOutcomeDescription').text(outcome.outcome_description);
    $('#viewSequenceNum').text(outcome.sequence_num);
    $('#viewOutcomeId').text(outcome.institutional_outcomes_pk);
    // Display term_code and term_name if available
    var termDisplay = outcome.term_code ? (outcome.term_code + (outcome.term_name ? ' - ' + outcome.term_name : '')) : 'N/A';
    $('#viewOutcomeTerm').text(termDisplay);
    $('#viewOutcomeCreated').text(outcome.created_at || 'N/A');
    $('#viewOutcomeCreatedBy').text(outcome.created_by_name || 'System');
    $('#viewOutcomeUpdated').text(outcome.updated_at || 'N/A');
    $('#viewOutcomeUpdatedBy').text(outcome.updated_by_name || 'System');
    new bootstrap.Modal(document.getElementById('viewOutcomeModal')).show();
}

function editOutcome(outcome) {
    $('#editOutcomeId').val(outcome.institutional_outcomes_pk);
    $('#editTermFk').val(outcome.term_fk);
    $('#editOutcomeCode').val(outcome.outcome_code);
    $('#editOutcomeDescription').val(outcome.outcome_description);
    $('#editSequenceNum').val(outcome.sequence_num);
    // Populate read-only audit info
    $('#editOutcomeCreated').text(outcome.created_at || 'N/A');
    $('#editOutcomeCreatedBy').text(outcome.created_by_name || 'System');
    $('#editOutcomeUpdated').text(outcome.updated_at || 'N/A');
    $('#editOutcomeUpdatedBy').text(outcome.updated_by_name || 'System');
    configureLifecycleEditAction('#editInstitutionalOutcomeForm', outcome.institutional_outcomes_pk, outcome.is_active, 'institutional outcome', outcome.outcome_code || '');
    new bootstrap.Modal(document.getElementById('editOutcomeModal')).show();
}

function deleteOutcome(id, code) {
    if (confirm('Are you sure you want to DELETE "' + code + '"?\n\nProgram outcomes linked to this will be preserved with NULL mapping.\n\nThis action cannot be undone.')) {
        $('#deleteOutcomeId').val(id);
        $('#deleteForm').submit();
    }
}

function confirmDeleteOutcome() {
    const outcomePk = $('#editOutcomeId').val();
    const outcomeCode = $('#editOutcomeCode').val();
    deleteOutcome(outcomePk, outcomeCode);
}

function exportAllISLOs() {
    var button = event.target.closest('button');
    var originalHTML = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
    button.disabled = true;
    
    $.ajax({
        url: '<?= BASE_URL ?>administration/institutional_outcomes_data.php',
        data: {
            start: 0,
            length: -1,
            draw: 1,
            term_fk: <?= json_encode($selectedTermFk) ?>
        },
        success: function(response) {
            var csvContent = "Term Code,Outcome Code,Description,Sequence,Created At,Created By,Updated At,Updated By\n";
            
            response.data.forEach(function(row) {
                var termCode = escapeCSV(row[0]);
                var outcomeCode = escapeCSV(row[1]);
                var description = escapeCSV(row[2]);
                var sequence = escapeCSV(row[3]);
                var createdAt = escapeCSV(row[4]);
                var createdBy = escapeCSV(row[5]);
                var updatedAt = escapeCSV(row[6]);
                var updatedBy = escapeCSV(row[7]);
                
                csvContent += termCode + "," + outcomeCode + "," + description + "," + sequence + "," + createdAt + "," + createdBy + "," + updatedAt + "," + updatedBy + "\n";
            });
            
            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement("a");
            var url = URL.createObjectURL(blob);
            link.setAttribute("href", url);
            link.setAttribute("download", "institutional_outcomes_export_" + new Date().toISOString().slice(0,10) + ".csv");
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
