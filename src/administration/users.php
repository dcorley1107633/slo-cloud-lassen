<?php
declare(strict_types=1);

/**
 * Users Administration
 * 
 * Manage system users and their roles.
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
        die('This form has expired. Reload the page and try again.');
    }
    
    $action = $_POST['action'] ?? '';
    $isSqlServer = ($db->getDriver() === 'mssql' || $db->getDriver() === 'sqlsrv');
    $nowFunc = $db->now();

    /**
     * Assign a global role to a user (replaces any existing global role).
     * Pass null to remove all global roles without assigning a new one.
     */
    $assignGlobalRole = function (int $userId, ?string $roleName) use ($db, $dbPrefix, $nowFunc): void {
        $db->query(
            "DELETE FROM {$dbPrefix}user_roles WHERE user_fk = ? AND context_type IS NULL AND context_id IS NULL",
            [$userId],
            'i'
        );
        if ($roleName !== null) {
            $db->query(
                "INSERT INTO {$dbPrefix}user_roles (user_fk, role_fk, context_type, context_id, created_at)
                 SELECT ?, roles_pk, NULL, NULL, {$nowFunc}
                 FROM {$dbPrefix}roles WHERE role_name = ?",
                [$userId, $roleName],
                'is'
            );
        }
    };

    try {
        switch ($action) {
            case 'add':
                $fullName = trim($_POST['full_name'] ?? '');
                $email    = trim($_POST['email'] ?? '');
                $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                // Two-tier authorization: an active account is the read/report
                // "user" tier; the admin role adds edit access. There is no
                // separate user role: being active IS the user tier.
                $grantAdmin = isset($_POST['is_admin']);

                $errors = [];
                if (empty($fullName)) {
                    $errors[] = 'Full name is required';
                }
                if (empty($email)) {
                    $errors[] = 'Email is required';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Invalid email format';
                }
                if (empty($password)) {
                    $errors[] = 'Password is required';
                } else {
                    $errors = array_merge(
                        $errors,
                        \SLOCloud\Core\PasswordPolicy::validate($password, [$email, $fullName])
                    );
                }

                // Check email uniqueness
                if (!empty($email)) {
                    $checkResult = $db->query(
                        "SELECT users_pk FROM {$dbPrefix}users WHERE email = ?",
                        [$email],
                        's'
                    );
                    if ($checkResult->fetch()) {
                        $errors[] = 'Email already exists';
                    }
                }

                if (empty($errors)) {
                    $passwordHash = password_hash($password, PASSWORD_ARGON2ID, [
                        'memory_cost' => 65536,
                        'time_cost'   => 4,
                    ]);
                    $db->query(
                        "INSERT INTO {$dbPrefix}users (full_name, email, password_hash, is_active, created_at, updated_at)
                         VALUES (?, ?, ?, ?, {$nowFunc}, {$nowFunc})",
                        [$fullName, $email, $passwordHash, $isActive],
                        'sssi'
                    );
                    $newUserPk = $db->getInsertId();
                    $assignGlobalRole($newUserPk, $grantAdmin ? 'admin' : null);
                    $successMessage = 'User added successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
                }
                break;

            case 'edit':
                $id       = (int)($_POST['users_pk'] ?? 0);
                $fullName = trim($_POST['full_name'] ?? '');
                $email    = trim($_POST['email'] ?? '');
                $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                // Two-tier authorization: an active account is the read/report
                // "user" tier; the admin role adds edit access. There is no
                // separate user role: being active IS the user tier.
                $grantAdmin = isset($_POST['is_admin']);

                $errors = [];
                if ($id <= 0) {
                    $errors[] = 'Invalid user ID';
                }
                if (empty($fullName)) {
                    $errors[] = 'Full name is required';
                }
                if (empty($email)) {
                    $errors[] = 'Email is required';
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Invalid email format';
                }
                
                // Check email uniqueness (exclude current record)
                if (!empty($email)) {
                    $checkResult = $db->query(
                        "SELECT users_pk FROM {$dbPrefix}users WHERE email = ? AND users_pk != ?",
                        [$email, $id],
                        'si'
                    );
                    if ($checkResult->fetch()) {
                        $errors[] = 'Email already exists';
                    }
                }
                
                if (empty($errors)) {
                    if (!empty($password)) {
                        $passwordProblem = \SLOCloud\Core\PasswordPolicy::firstProblem($password, [$email, $fullName]);
                        if ($passwordProblem !== null) {
                            $errorMessage = $passwordProblem;
                            break;
                        }
                        $passwordHash = password_hash($password, PASSWORD_ARGON2ID, [
                            'memory_cost' => 65536,
                            'time_cost'   => 4,
                        ]);
                        $db->query(
                            "UPDATE {$dbPrefix}users
                             SET full_name = ?, email = ?, password_hash = ?, is_active = ?, updated_at = {$nowFunc}
                             WHERE users_pk = ?",
                            [$fullName, $email, $passwordHash, $isActive, $id],
                            'sssii'
                        );
                    } else {
                        $db->query(
                            "UPDATE {$dbPrefix}users
                             SET full_name = ?, email = ?, is_active = ?, updated_at = {$nowFunc}
                             WHERE users_pk = ?",
                            [$fullName, $email, $isActive, $id],
                            'ssii'
                        );
                    }
                    $assignGlobalRole($id, $grantAdmin ? 'admin' : null);
                    $successMessage = 'User updated successfully';
                } else {
                    $errorMessage = implode('<br>', $errors);
                }
                break;
                
            case 'toggle_status':
                $id = (int)($_POST['users_pk'] ?? 0);
                if ($id > 0) {
                    $db->query(
                        "UPDATE {$dbPrefix}users SET is_active = 1 - is_active, updated_at = {$nowFunc} WHERE users_pk = ?",
                        [$id],
                        'i'
                    );
                    $successMessage = 'User status updated';
                }
                break;
                
            case 'delete':
                $errorMessage = 'User deletion is disabled. Deactivate the user instead.';
                break;
        }
    } catch (\Exception $e) {
        $errorReference = errorReference();
        error_log(basename(__FILE__) . " error [{$errorReference}]: " . $e->getMessage());
        $errorMessage = DEBUG_MODE
            ? 'Operation failed: ' . htmlspecialchars($e->getMessage())
            : "Operation failed. Reference: {$errorReference}";
    }
}

// Calculate statistics
$statsResult = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active,
        SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) as inactive
    FROM {$dbPrefix}users
");
$stats = fetch_row($statsResult);
$totalUsers = (int)($stats['total'] ?? 0);
$activeUsers = (int)($stats['active'] ?? 0);
$inactiveUsers = (int)($stats['inactive'] ?? 0);

require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => 'User Management',
    'currentPage' => 'admin_users',
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['label' => 'Users']
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

        <!-- Users Table -->
        <div class="card shadow-sm">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-table" aria-hidden="true"></i> System Users</h2>
                <div class="card-tools">
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal" aria-label="Add new user">
                        <i class="fas fa-plus" aria-hidden="true"></i> Add User
                    </button>
                </div>
            </div>
            <div class="card-body">
                <table id="usersTable" class="table table-bordered table-striped table-hover" aria-label="System users data table">
                    <caption class="visually-hidden">List of system users with filtering and sorting capabilities</caption>
                    <thead>
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Full Name</th>
                            <th scope="col">Email</th>
                            <th scope="col">Role</th>
                            <th scope="col">Status</th>
                            <th scope="col">Actions</th>
                        </tr>
                        <tr>
                            <td><input type="text" class="form-control form-control-sm" placeholder="Filter ID" aria-label="Filter by ID"></td>
                            <td><input type="text" class="form-control form-control-sm" placeholder="Filter Name" aria-label="Filter by full name"></td>
                            <td><input type="text" class="form-control form-control-sm" placeholder="Filter Email" aria-label="Filter by email"></td>
                            <td><input type="text" class="form-control form-control-sm" placeholder="Filter Role" aria-label="Filter by role"></td>
                            <td><input type="text" class="form-control form-control-sm" placeholder="Filter Status" aria-label="Filter by status"></td>
                            <td>&nbsp;</td>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="add">
                <div class="modal-header bg-primary text-white">
                    <span class="modal-title" id="addUserModalLabel"><i class="fas fa-user-plus" aria-hidden="true"></i> Add User</span>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close dialog"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="email" class="form-label">Email <span class="text-danger" aria-label="required">*</span></label>
                        <input type="email" class="form-control" id="email" name="email" required aria-required="true" autocomplete="email">
                    </div>
                    <div class="mb-3">
                        <label for="fullName" class="form-label">Full Name <span class="text-danger" aria-label="required">*</span></label>
                        <input type="text" class="form-control" id="fullName" name="full_name" required aria-required="true" autocomplete="name">
                    </div>
                    <div class="mb-3">
                        <label for="password" class="form-label">Password <span class="text-danger" aria-label="required">*</span></label>
                        <input type="password" class="form-control" id="password" name="password" required aria-required="true" minlength="12" autocomplete="new-password" aria-describedby="passwordHelp">
                        <small id="passwordHelp" class="form-text text-muted">Minimum 12 characters; password managers and paste are supported.</small>
                    </div>
                    <fieldset class="mb-3">
                        <legend class="h6">Access</legend>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="isActive" name="is_active" checked>
                            <label class="form-check-label" for="isActive">Active</label>
                            <small class="form-text text-muted d-block">An active account can sign in, view data, and run and export reports.</small>
                        </div>
                        <div class="form-check mt-2">
                            <input type="checkbox" class="form-check-input" id="isAdmin" name="is_admin">
                            <label class="form-check-label" for="isAdmin">Administrator</label>
                            <small class="form-text text-muted d-block">Adds full edit access, including managing users, keys, and configuration.</small>
                        </div>
                    </fieldset>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Add User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="users_pk" id="editUserPk">
                <div class="modal-header bg-warning text-dark">
                    <span class="modal-title" id="editUserModalLabel"><i class="fas fa-edit" aria-hidden="true"></i> Edit User</span>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close dialog"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="editEmail" class="form-label">Email <span class="text-danger" aria-label="required">*</span></label>
                        <input type="email" class="form-control" id="editEmail" name="email" required aria-required="true" autocomplete="email">
                    </div>
                    <div class="mb-3">
                        <label for="editFullName" class="form-label">Full Name <span class="text-danger" aria-label="required">*</span></label>
                        <input type="text" class="form-control" id="editFullName" name="full_name" required aria-required="true" autocomplete="name">
                    </div>
                    <div class="mb-3">
                        <label for="editPassword" class="form-label">Password</label>
                        <input type="password" class="form-control" id="editPassword" name="password" minlength="12" autocomplete="new-password" aria-describedby="editPasswordHelp">
                        <small id="editPasswordHelp" class="form-text text-muted">Leave blank to keep existing password. Minimum 12 characters if changing.</small>
                    </div>
                    <fieldset class="mb-3">
                        <legend class="h6">Access</legend>
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="editIsActive" name="is_active">
                            <label class="form-check-label" for="editIsActive">Active</label>
                            <small class="form-text text-muted d-block">An active account can sign in, view data, and run and export reports.</small>
                        </div>
                        <div class="form-check mt-2">
                            <input type="checkbox" class="form-check-input" id="editIsAdmin" name="is_admin">
                            <label class="form-check-label" for="editIsAdmin">Administrator</label>
                            <small class="form-text text-muted d-block">Adds full edit access, including managing users, keys, and configuration.</small>
                        </div>
                    </fieldset>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save" aria-hidden="true"></i> Update</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Toggle Status Form (hidden) -->
<form id="toggleStatusForm" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="users_pk" id="toggleUserPk">
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
    $('#usersTable thead tr:eq(1) td').each(function(i) {
        var title = $('#usersTable thead tr:eq(0) th:eq(' + i + ')').text();
        if (title !== 'Actions' && title !== 'ID') {
            $(this).html('<input type="text" class="form-control form-control-sm" placeholder="Search ' + title + '" aria-label="Filter by ' + title + '" />');
        } else {
            $(this).html('');
        }
    });

    var table = $('#usersTable').DataTable({
        orderCellsTop: true,
        processing: true,
        serverSide: true,
        ajax: { url: '<?= BASE_URL ?>administration/users_data.php', type: 'POST' },
        dom: 'Brtip',
        buttons: ['copy', 'csv', 'print'],
        columns: [
            { data: 0, name: 'users_pk' },
            { data: 1, name: 'full_name' },
            { data: 2, name: 'email' },
            { data: 3, name: 'role', orderable: true, searchable: true },
            { data: 4, name: 'is_active' },
            { data: 5, name: 'actions', orderable: false, searchable: false }
        ],
        language: {
            info: "Showing _START_ to _END_ of _TOTAL_ users",
            infoEmpty: "No users found",
            infoFiltered: "(filtered from _MAX_ total)",
            emptyTable: "No users available"
        },
        initComplete: function() {
            // Apply the search - target the second header row where filters are
            var api = this.api();
            api.columns().every(function(colIdx) {
                var column = this;
                // Find input in the second header row (tr:eq(1)) for this column
                $('input', $('#usersTable thead tr:eq(1) td').eq(colIdx)).on('keyup change clear', function() {
                    if (column.search() !== this.value) {
                        column.search(this.value).draw();
                    }
                });
            });
        }
    });
});

function editUser(user) {
    $('#editUserPk').val(user.users_pk);
    $('#editFullName').val(user.full_name);
    $('#editEmail').val(user.email);
    $('#editIsAdmin').prop('checked', user.role === 'admin');
    $('#editIsActive').prop('checked', user.is_active == 1);
    $('#editPassword').val('');
    new bootstrap.Modal(document.getElementById('editUserModal')).show();
}

function toggleStatus(id, name) {
    if (confirm('Toggle status for user "' + name + '"?')) {
        $('#toggleUserPk').val(id);
        $('#toggleStatusForm').submit();
    }
}

</script>
