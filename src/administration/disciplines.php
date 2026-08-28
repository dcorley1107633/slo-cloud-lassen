<?php
declare(strict_types=1);

/**
 * Disciplines Administration
 * 
 * Manage academic disciplines (departments).
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
    
    try {
        switch ($action) {
            case 'add':
                $termFk = (int)($_POST['term_fk'] ?? 0);
                $name = trim($_POST['discipline_name'] ?? '');
                $code = trim($_POST['discipline_code'] ?? '');
                
                // Validation
                $errors = [];
                if ($termFk <= 0) {
                    $errors[] = 'Term is required';
                }
                if (empty($name)) {
                    $errors[] = 'Discipline name is required';
                } else {
                    // Check uniqueness per term
                    $result = $db->query(
                        "SELECT COUNT(*) as count FROM {$dbPrefix}disciplines WHERE term_fk = ? AND discipline_name = ?",
                        [$termFk, $name],
                        'is'
                    );
                    $row = $result->fetch();
                    if ($row['count'] > 0) {
                        $errors[] = 'Discipline name already exists in this term';
                    }
                }
                
                if (!empty($code)) {
                    if (!preg_match('/^[A-Z0-9_-]+$/i', $code)) {
                        $errors[] = 'Discipline code can only contain letters, numbers, hyphens, and underscores';
                    }
                }
                
                if (empty($errors)) {
                    $userId = getValidUserId();
                    $db->query(
                        "INSERT INTO {$dbPrefix}disciplines (term_fk, discipline_name, discipline_code, created_at, updated_at, created_by_fk, updated_by_fk) 
                         VALUES (?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                        [$termFk, $name, $code, $userId, $userId],
                        'issii'
                    );
                    $successMessage = 'Discipline added successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
                }
                break;
                
            case 'edit':
                $id = (int)($_POST['discipline_id'] ?? 0);
                $name = trim($_POST['discipline_name'] ?? '');
                $code = trim($_POST['discipline_code'] ?? '');
                
                // Validation
                $errors = [];
                if ($id <= 0) {
                    $errors[] = 'Invalid discipline ID';
                }
                if (empty($name)) {
                    $errors[] = 'Discipline name is required';
                } else {
                    // Check uniqueness within same term (excluding current record)
                    // Get current term_fk
                    $currResult = $db->query(
                        "SELECT term_fk FROM {$dbPrefix}disciplines WHERE disciplines_pk = ?",
                        [$id],
                        'i'
                    );
                    $currRow = $currResult->fetch();
                    $termFk = $currRow['term_fk'] ?? 0;
                    
                    $result = $db->query(
                        "SELECT COUNT(*) as count FROM {$dbPrefix}disciplines WHERE term_fk = ? AND discipline_name = ? AND disciplines_pk != ?",
                        [$termFk, $name, $id],
                        'isi'
                    );
                    $row = $result->fetch();
                    if ($row['count'] > 0) {
                        $errors[] = 'Discipline name already exists in this term';
                    }
                }
                
                if (!empty($code)) {
                    if (!preg_match('/^[A-Z0-9_-]+$/i', $code)) {
                        $errors[] = 'Discipline code can only contain letters, numbers, hyphens, and underscores';
                    }
                }
                
                if (empty($errors)) {
                    $userId = getValidUserId();
                    $db->query(
                        "UPDATE {$dbPrefix}disciplines 
                         SET discipline_name = ?, discipline_code = ?, updated_at = {$db->now()}, updated_by_fk = ?
                         WHERE disciplines_pk = ?",
                        [$name, $code, $userId, $id],
                        'ssii'
                    );
                    $successMessage = 'Discipline updated successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
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

// Term handling (for filtering disciplines by term)
$selectedTermFk = getSelectedTermFk();
$statusMode = lifecycle_view_mode('disciplines');

// Load all terms for dropdown
$termsResult = $db->query(
    "SELECT terms_pk, term_name, term_code, banner_term FROM {$dbPrefix}terms WHERE is_active = 1 ORDER BY term_code DESC"
);
$allTerms = [];
while ($term = $termsResult->fetch()) {
    $allTerms[] = $term;
}

// Get selected term information from session (cached during getSelectedTermFk())
$selectedTermName = getSelectedTermName() ?? '';
$selectedTermCode = getSelectedTermCode() ?? '';

// Load theme system
require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$pageTitle = 'Discipline Management';
if ($selectedTermName) {
    $pageTitle .= ' - ' . $selectedTermName;
    if ($selectedTermCode) {
        $pageTitle .= ' (' . $selectedTermCode . ')';
    }
}

$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => $pageTitle,
    'currentPage' => 'admin_disciplines',
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['label' => 'Disciplines']
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

        <!-- Disciplines Table -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-building" aria-hidden="true"></i> Academic Disciplines</h2>
                <div class="card-tools">
                    <button type="button" class="btn btn-success btn-sm" onclick="exportAllDisciplines()" aria-label="Export all discipline records to CSV">
                        <i class="fas fa-download" aria-hidden="true"></i> Export All to CSV
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addDisciplineModal" aria-label="Add new discipline">
                        <i class="fas fa-plus" aria-hidden="true"></i> Add Discipline
                    </button>
                </div>
            </div>
            <div class="card-body">
                <table id="disciplinesTable" class="table table-bordered table-striped" aria-label="Disciplines data table">
                    <caption class="visually-hidden">List of academic disciplines with filtering and sorting capabilities</caption>
                    <thead>
                        <tr>
                            <th scope="col">Term</th>
                            <th scope="col">Discipline Name</th>
                            <th scope="col">Code</th>
                            <th scope="col">Created</th>
                            <th scope="col">Created By</th>
                            <th scope="col">Updated</th>
                            <th scope="col">Updated By</th>
                            <th scope="col">Actions</th>
                        </tr>
                        <tr>
                            <td><input type="text" class="form-control form-control-sm" placeholder="Filter Term" aria-label="Filter by term"></td>
                            <td><input type="text" class="form-control form-control-sm" placeholder="Filter Name" aria-label="Filter by discipline name"></td>
                            <td><input type="text" class="form-control form-control-sm" placeholder="Filter Code" aria-label="Filter by discipline code"></td>
                            <td><input type="text" class="form-control form-control-sm" placeholder="Filter Created" aria-label="Filter by created date"></td>
                            <td><input type="text" class="form-control form-control-sm" placeholder="Filter Creator" aria-label="Filter by creator"></td>
                            <td><input type="text" class="form-control form-control-sm" placeholder="Filter Updated" aria-label="Filter by updated date"></td>
                            <td><input type="text" class="form-control form-control-sm" placeholder="Filter Updater" aria-label="Filter by updater"></td>
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


<!-- Add Discipline Modal -->
<div class="modal fade" id="addDisciplineModal" tabindex="-1" aria-labelledby="addDisciplineModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title" id="addDisciplineModalLabel"><i class="fas fa-plus" aria-hidden="true"></i> Add Discipline</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label for="termFk" class="form-label">Term <span class="text-danger" aria-label="required">*</span></label>
                        <select class="form-select" id="termFk" name="term_fk" required>
                            <option value="">Select term...</option>
                            <?php foreach ($allTerms as $term): ?>
                                <option value="<?= $term['terms_pk'] ?>" <?= $term['terms_pk'] == $selectedTermFk ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($term['term_code'] . ' - ' . $term['term_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="disciplineName" class="form-label">Discipline Name <span class="text-danger" aria-label="required">*</span></label>
                        <input type="text" class="form-control" id="disciplineName" name="discipline_name" maxlength="255" required aria-required="true" aria-describedby="disciplineNameHelp">
                        <small id="disciplineNameHelp" class="form-text text-muted">Full department/discipline name (e.g., "Biology", "Computer Science")</small>
                    </div>
                    <div class="mb-3">
                        <label for="disciplineCode" class="form-label">Discipline Code</label>
                        <input type="text" class="form-control" id="disciplineCode" name="discipline_code" maxlength="50" aria-describedby="disciplineCodeHelp">
                        <small id="disciplineCodeHelp" class="form-text text-muted">Optional short code (e.g., "BIOL", "CS")</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Add Discipline</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Discipline Modal -->
<div class="modal fade" id="editDisciplineModal" tabindex="-1" aria-labelledby="editDisciplineModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning text-dark">
                <span class="modal-title" id="editDisciplineModalLabel"><i class="fas fa-edit" aria-hidden="true"></i> Edit Discipline</span>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST" id="editDisciplineForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" id="editDisciplineId" name="discipline_id">
                    <?= lifecycle_edit_fields('disciplines', 'disciplines.php?status=' . $statusMode) ?>
                    <div class="mb-3">
                        <label for="editTermName" class="form-label">Term</label>
                        <input type="text" class="form-control" id="editTermName" readonly disabled>
                        <small class="form-text text-muted">Term cannot be changed after creation</small>
                    </div>
                    <div class="mb-3">
                        <label for="editDisciplineName" class="form-label">Discipline Name <span class="text-danger" aria-label="required">*</span></label>
                        <input type="text" class="form-control" id="editDisciplineName" name="discipline_name" maxlength="255" required aria-required="true">
                    </div>
                    <div class="mb-3">
                        <label for="editDisciplineCode" class="form-label">Discipline Code</label>
                        <input type="text" class="form-control" id="editDisciplineCode" name="discipline_code" maxlength="50">
                    </div>
                </div>
                <div class="modal-footer">
                    <?= lifecycle_edit_controls() ?>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">Update Discipline</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$theme->showFooter($context);
?>

<!-- DataTables JS -->
<script src="<?= BASE_URL ?>assets/vendor/datatables/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables-buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/jszip/3.10.1/jszip.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables-buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables-buttons/2.4.2/js/buttons.print.min.js"></script>

<script>
$(document).ready(function() {
    // Initialize DataTable (term filter applied server-side via session)
    var table = $('#disciplinesTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '<?= BASE_URL ?>administration/disciplines_data.php',
            type: 'POST',
            data: function(d) {
                d.term_fk = <?= json_encode($selectedTermFk) ?>;
            }
        },
        dom: 'rtip',
        orderCellsTop: true, // Sort on first header row only
        order: [[1, 'asc']], // Sort by name by default
        columns: [
            { data: 0 }, // Term
            { data: 1 }, // Name
            { data: 2 }, // Code
            { data: 3 }, // Created
            { data: 4 }, // Created By
            { data: 5 }, // Updated
            { data: 6 }, // Updated By
            { data: 7, orderable: false } // Actions
        ],
        language: {
            info: "Showing _START_ to _END_ of _TOTAL_ disciplines",
            infoEmpty: "No disciplines found",
            infoFiltered: "(filtered from _MAX_ total)",
            emptyTable: "No disciplines available"
        },
        initComplete: function() {
            // Add column filters
            this.api().columns([0, 1, 2, 3, 4, 5, 6]).every(function() {
                var column = this;
                var input = $('#disciplinesTable thead tr:eq(1) td:eq(' + column.index() + ') input');
                input.on('keyup change clear', function() {
                    if (column.search() !== this.value) {
                        column.search(this.value).draw();
                    }
                });
            });
        }
    });

});

function editDiscipline(data) {
    $('#editDisciplineId').val(data.disciplines_pk);
    $('#editDisciplineLifecycleId').val(data.disciplines_pk);
    $('#editDisciplineName').val(data.discipline_name);
    $('#editDisciplineCode').val(data.discipline_code || '');
    $('#editTermName').val(data.term_name || '');
    configureLifecycleEditAction(
        '#editDisciplineForm',
        data.disciplines_pk,
        data.is_active,
        'discipline',
        data.discipline_name || ''
    );
    $('#editDisciplineModal').modal('show');
}

function exportAllDisciplines() {
    var button = event.target.closest('button');
    var originalHTML = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting...';
    button.disabled = true;
    
    $.ajax({
        url: '<?= BASE_URL ?>administration/disciplines_data.php',
        data: {
            start: 0,
            length: -1,
            draw: 1,
            term_fk: <?= json_encode($selectedTermFk) ?>
        },
        success: function(response) {
            var csvContent = "Term,Name,Code,Created,Created By,Updated,Updated By\n";
            
            response.data.forEach(function(row) {
                var term = escapeCSV(row[0]);
                var name = escapeCSV(row[1]);
                var code = escapeCSV(row[2]);
                var created = escapeCSV(row[3]);
                var createdBy = escapeCSV(row[4]);
                var updated = escapeCSV(row[5]);
                var updatedBy = escapeCSV(row[6]);
                
                csvContent += term + "," + name + "," + code + "," + created + "," + createdBy + "," + updated + "," + updatedBy + "\n";
            });
            
            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement("a");
            var url = URL.createObjectURL(blob);
            link.setAttribute("href", url);
            link.setAttribute("download", "disciplines_export_" + new Date().toISOString().slice(0,10) + ".csv");
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
