<?php
declare(strict_types=1);

/**
 * Cross-Listed Sections Administration
 *
 * Manage section cross-listing groups (xlist).
 *
 * @package SLOCloud
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';

// Get selected term (from GET/session, or default to latest)
$selectedTermFk = getSelectedTermFk();

$successMessage = '';
$errorMessage   = '';

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
                $termFk      = (int)($_POST['term_fk'] ?? 0);
                $xlistGroup  = trim($_POST['xlist_group'] ?? '');
                $crn         = trim($_POST['crn'] ?? '');
                $courseNumber = trim($_POST['course_number'] ?? '');

                $errors = [];

                if ($termFk <= 0) {
                    $errors[] = 'Term is required';
                } else {
                    $termCheck = $db->query(
                        "SELECT COUNT(*) as count FROM {$dbPrefix}terms WHERE terms_pk = ?",
                        [$termFk], 'i'
                    );
                    if ($termCheck->fetch()['count'] == 0) {
                        $errors[] = 'Invalid term selected';
                    }
                }
                if (empty($xlistGroup)) {
                    $errors[] = 'Cross-list group ID is required';
                }
                if (empty($crn)) {
                    $errors[] = 'CRN is required';
                } else {
                    $dupCheck = $db->query(
                        "SELECT COUNT(*) as count FROM {$dbPrefix}section_xlist WHERE term_fk = ? AND crn = ?",
                        [$termFk, $crn], 'is'
                    );
                    if ($dupCheck->fetch()['count'] > 0) {
                        $errors[] = 'CRN already exists in this term';
                    }
                }

                if (empty($errors)) {
                    $userId = getValidUserId();
                    $db->query(
                        "INSERT INTO {$dbPrefix}section_xlist
                             (term_fk, xlist_group, crn, course_number, created_at, updated_at, created_by_fk, updated_by_fk)
                         VALUES (?, ?, ?, ?, {$db->now()}, {$db->now()}, ?, ?)",
                        [$termFk, $xlistGroup, $crn, $courseNumber, $userId, $userId],
                        'isssii'
                    );
                    $successMessage = 'Cross-list entry added successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
                }
                break;

            case 'edit':
                $id          = (int)($_POST['xlist_pk'] ?? 0);
                $termFk      = (int)($_POST['term_fk'] ?? 0);
                $xlistGroup  = trim($_POST['xlist_group'] ?? '');
                $crn         = trim($_POST['crn'] ?? '');
                $courseNumber = trim($_POST['course_number'] ?? '');

                $errors = [];

                if ($id <= 0) {
                    $errors[] = 'Invalid record';
                }
                if ($termFk <= 0) {
                    $errors[] = 'Term is required';
                }
                if (empty($xlistGroup)) {
                    $errors[] = 'Cross-list group ID is required';
                }
                if (empty($crn)) {
                    $errors[] = 'CRN is required';
                } else {
                    $dupCheck = $db->query(
                        "SELECT COUNT(*) as count FROM {$dbPrefix}section_xlist WHERE term_fk = ? AND crn = ? AND section_xlist_pk != ?",
                        [$termFk, $crn, $id], 'isi'
                    );
                    if ($dupCheck->fetch()['count'] > 0) {
                        $errors[] = 'CRN already exists in this term';
                    }
                }

                if (empty($errors)) {
                    $userId = getValidUserId();
                    $db->query(
                        "UPDATE {$dbPrefix}section_xlist
                         SET term_fk = ?, xlist_group = ?, crn = ?, course_number = ?,
                             updated_at = {$db->now()}, updated_by_fk = ?
                         WHERE section_xlist_pk = ?",
                        [$termFk, $xlistGroup, $crn, $courseNumber, $userId, $id],
                        'isssii'
                    );
                    $successMessage = 'Cross-list entry updated successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
                }
                break;

            case 'delete':
                $id = (int)($_POST['xlist_pk'] ?? 0);
                if ($id > 0) {
                    $db->query(
                        "DELETE FROM {$dbPrefix}section_xlist WHERE section_xlist_pk = ?",
                        [$id], 'i'
                    );
                    $successMessage = 'Cross-list entry deleted successfully';
                }
                break;
        }
    } catch (\Exception $e) {
        $errorReference = errorReference();
        error_log(basename(__FILE__) . " error [{$errorReference}]: " . $e->getMessage());
        $errorMessage = "Operation failed. Reference: {$errorReference}";
        if (DEBUG_MODE) {
            $errorMessage .= '<br><pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
        }
    }
}

// Fetch terms for dropdowns (ascending)
$termsResult = $db->query(
    "SELECT terms_pk, term_code, term_name FROM {$dbPrefix}terms ORDER BY term_code ASC"
);
$terms = $termsResult->fetchAll();

// Statistics for selected term
$selectedTermName = getSelectedTermName() ?? '';
$selectedTermCode = getSelectedTermCode() ?? '';

$statsQuery = $selectedTermFk
    ? "SELECT COUNT(*) as total FROM {$dbPrefix}section_xlist WHERE term_fk = ?"
    : "SELECT COUNT(*) as total FROM {$dbPrefix}section_xlist";
$statsResult  = $selectedTermFk
    ? $db->query($statsQuery, [$selectedTermFk], 'i')
    : $db->query($statsQuery);
$totalEntries = $statsResult->fetch()['total'];

// Theme
require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$pageTitle = 'Cross-Listed Sections';
if ($selectedTermName) {
    $pageTitle .= ' - ' . $selectedTermName;
    if ($selectedTermCode) {
        $pageTitle .= ' (' . $selectedTermCode . ')';
    }
}

$context = new ThemeContext([
    'layout'      => 'admin',
    'pageTitle'   => $pageTitle,
    'currentPage' => 'admin_xlist',
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['label' => 'Cross-Listed Sections'],
    ],
]);

$theme = ThemeLoader::getActiveTheme(null, 'admin');
$theme->showHeader($context);
?>

<!-- DataTables CSS -->
<link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/datatables/1.13.7/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css">

<div class="app-content">
    <div class="container-fluid">

        <?php if ($successMessage): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle" aria-hidden="true"></i> <?= htmlspecialchars($successMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle" aria-hidden="true"></i> <?= $errorMessage ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close alert"></button>
        </div>
        <?php endif; ?>

        <!-- Stats row -->
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body text-center py-2">
                        <div class="h4 mb-0"><?= (int)$totalEntries ?></div>
                        <small class="text-muted">Cross-list Entries<?= $selectedTermName ? ' (' . htmlspecialchars($selectedTermCode) . ')' : '' ?></small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main table card -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-layer-group" aria-hidden="true"></i> Cross-Listed Sections</h2>
                <div class="card-tools">
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addXlistModal" aria-label="Add new cross-list entry">
                        <i class="fas fa-plus" aria-hidden="true"></i> Add Entry
                    </button>
                </div>
            </div>
            <div class="card-body">
                <table id="xlistTable" class="table table-bordered table-striped" aria-label="Cross-listed sections data table">
                    <caption class="visually-hidden">Cross-listing groups and their CRNs, grouped by term</caption>
                    <thead>
                        <tr>
                            <th scope="col">XList Group</th>
                            <th scope="col">CRN</th>
                            <th scope="col">Course Number</th>
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

<!-- Add Modal -->
<div class="modal fade" id="addXlistModal" tabindex="-1" aria-labelledby="addXlistModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title" id="addXlistModalLabel"><i class="fas fa-plus" aria-hidden="true"></i> Add Cross-List Entry</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="add">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="addTermFk" class="form-label">Term <span class="text-danger" aria-label="required">*</span></label>
                            <select class="form-select" id="addTermFk" name="term_fk" required aria-required="true">
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?= (int)$term['terms_pk'] ?>" <?= $term['terms_pk'] == $selectedTermFk ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($term['term_code'] . ' - ' . $term['term_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="addXlistGroup" class="form-label">XList Group ID <span class="text-danger" aria-label="required">*</span></label>
                            <input type="text" class="form-control" id="addXlistGroup" name="xlist_group" maxlength="50" required aria-required="true" aria-describedby="addXlistGroupHelp">
                            <small id="addXlistGroupHelp" class="form-text text-muted">e.g., 1, 2, C01</small>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="addCrn" class="form-label">CRN <span class="text-danger" aria-label="required">*</span></label>
                            <input type="text" class="form-control" id="addCrn" name="crn" maxlength="20" required aria-required="true">
                            <small class="form-text text-muted">Course Reference Number</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="addCourseNumber" class="form-label">Course Number</label>
                            <input type="text" class="form-control" id="addCourseNumber" name="course_number" maxlength="50" aria-describedby="addCourseNumberHelp">
                            <small id="addCourseNumberHelp" class="form-text text-muted">e.g., ETHS C123 (optional, for reference)</small>
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

<!-- Edit Modal -->
<div class="modal fade" id="editXlistModal" tabindex="-1" aria-labelledby="editXlistModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <span class="modal-title" id="editXlistModalLabel"><i class="fas fa-edit" aria-hidden="true"></i> Edit Cross-List Entry</span>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
            </div>
            <form method="POST" id="editXlistForm">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="edit">
                    <?= lifecycle_edit_fields('section_xlist', 'xlist.php?status=' . lifecycle_view_mode('section_xlist')) ?>
                    <input type="hidden" name="xlist_pk" id="editXlistPk">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="editTermFk" class="form-label">Term <span class="text-danger" aria-label="required">*</span></label>
                            <select class="form-select" id="editTermFk" name="term_fk" required aria-required="true">
                                <?php foreach ($terms as $term): ?>
                                    <option value="<?= (int)$term['terms_pk'] ?>">
                                        <?= htmlspecialchars($term['term_code'] . ' - ' . $term['term_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="editXlistGroup" class="form-label">XList Group ID <span class="text-danger" aria-label="required">*</span></label>
                            <input type="text" class="form-control" id="editXlistGroup" name="xlist_group" maxlength="50" required aria-required="true">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="editCrn" class="form-label">CRN <span class="text-danger" aria-label="required">*</span></label>
                            <input type="text" class="form-control" id="editCrn" name="crn" maxlength="20" required aria-required="true">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="editCourseNumber" class="form-label">Course Number</label>
                            <input type="text" class="form-control" id="editCourseNumber" name="course_number" maxlength="50">
                        </div>
                    </div>

                    <hr>
                    <div class="text-muted mb-3"><i class="fas fa-info-circle" aria-hidden="true"></i> Audit Information</div>
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Created:</strong>
                            <p id="editCreatedAt" class="text-muted">N/A</p>
                        </div>
                        <div class="col-md-6">
                            <strong>Created By:</strong>
                            <p id="editCreatedBy" class="text-muted">N/A</p>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <strong>Last Updated:</strong>
                            <p id="editUpdatedAt" class="text-muted">N/A</p>
                        </div>
                        <div class="col-md-6">
                            <strong>Updated By:</strong>
                            <p id="editUpdatedBy" class="text-muted">N/A</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer d-flex justify-content-between">
                    <?= lifecycle_edit_controls() ?>
                    <div>
                        <button type="button" class="btn btn-danger" onclick="confirmDeleteXlist()" aria-label="Delete this entry">
                            <i class="fas fa-trash" aria-hidden="true"></i> Delete
                        </button>
                    </div>
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
<form id="deleteXlistForm" method="POST" style="display:none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="xlist_pk" id="deleteXlistPk">
</form>

<?php $theme->showFooter($context); ?>

<!-- DataTables JS -->
<script src="<?= BASE_URL ?>assets/vendor/datatables/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/datatables-buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>

<script>
$(document).ready(function () {

    // Add per-column search inputs to the second header row
    $('#xlistTable thead tr:eq(1) td').each(function (i) {
        var title = $('#xlistTable thead tr:eq(0) th:eq(' + i + ')').text();
        if (title === 'Actions') {
            $(this).html('');
        } else {
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder="Search ' + title + '" aria-label="Filter by ' + title + '" />');
        }
    });

    var table = $('#xlistTable').DataTable({
        orderCellsTop: true,
        processing: true,
        serverSide: true,
        ajax: {
            url: '<?= BASE_URL ?>administration/xlist_data.php',
            type: 'POST',
            data: function (d) {
                // Always send the currently-selected term so the data endpoint filters correctly
                d.term_fk = <?= json_encode($selectedTermFk) ?>;
            }
        },
        dom: 'rtip',
        columns: [
            { data: 0, name: 'xlist_group' },
            { data: 1, name: 'crn' },
            { data: 2, name: 'course_number' },
            { data: 3, name: 'created_at' },
            { data: 4, name: 'created_by_name' },
            { data: 5, name: 'updated_at' },
            { data: 6, name: 'updated_by_name' },
            { data: 7, name: 'actions', orderable: false, searchable: false }
        ],
        order: [[0, 'asc'], [1, 'asc']],
        language: {
            info: 'Showing _START_ to _END_ of _TOTAL_ entries',
            infoEmpty: 'No entries found',
            infoFiltered: '(filtered from _MAX_ total)',
            emptyTable: 'No cross-list entries available for the selected term'
        },
        initComplete: function () {
            var api = this.api();
            api.columns().every(function (colIdx) {
                var column = this;
                var filterCell = $('#xlistTable thead tr:eq(1) td').eq(colIdx);
                $('input', filterCell).on('keyup change clear', function () {
                    if (column.search() !== this.value) {
                        column.search(this.value).draw();
                    }
                });
            });
        }
    });
});

function editXlist(row) {
    $('#editXlistPk').val(row.section_xlist_pk);
    $('#editTermFk').val(row.term_fk);
    $('#editXlistGroup').val(row.xlist_group);
    $('#editCrn').val(row.crn);
    $('#editCourseNumber').val(row.course_number || '');
    $('#editCreatedAt').text(row.created_at || 'N/A');
    $('#editCreatedBy').text(row.created_by_name || 'System');
    $('#editUpdatedAt').text(row.updated_at || 'N/A');
    $('#editUpdatedBy').text(row.updated_by_name || 'System');
    configureLifecycleEditAction('#editXlistForm', row.section_xlist_pk, row.is_active, 'cross-list entry', row.crn || '');
    new bootstrap.Modal(document.getElementById('editXlistModal')).show();
}

function confirmDeleteXlist() {
    var pk  = $('#editXlistPk').val();
    var grp = $('#editXlistGroup').val();
    var crn = $('#editCrn').val();
    if (confirm('Delete cross-list entry "' + grp + ' / CRN ' + crn + '"?\n\nThis action cannot be undone.')) {
        $('#deleteXlistPk').val(pk);
        $('#deleteXlistForm').submit();
    }
}
</script>
