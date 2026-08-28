<?php
declare(strict_types=1);

/**
 * Users DataTables AJAX Handler
 * 
 * Provides server-side processing for users table.
 * 
 * @package SLOCloud
 */

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';
require_once __DIR__ . '/../system/includes/datatables_helper.php';

header('Content-Type: application/json');

// Get DataTables request parameters
$draw = (int)($_REQUEST['draw'] ?? 1);
$start = (int)($_REQUEST['start'] ?? 0);
$length = (int)($_REQUEST['length'] ?? 10);
$searchValue = $_REQUEST['search']['value'] ?? '';
$orderColumnIndex = (int)($_REQUEST['order'][0]['column'] ?? 0);
$orderDir = $_REQUEST['order'][0]['dir'] ?? 'asc';

// Column mapping: indices match the DataTable columns (role is derived via JOIN)
$columns = ['users_pk', 'full_name', 'email', 'role', 'is_active'];
$orderableMap = [
    'users_pk' => 'u.users_pk',
    'full_name' => 'u.full_name',
    'email'     => 'u.email',
    'role'      => 'r.role_name',
    'is_active' => 'u.is_active',
];
$orderColumn = $orderableMap[$columns[$orderColumnIndex] ?? ''] ?? 'u.users_pk';

// Validate order direction
if (!in_array($orderDir, ['asc', 'desc'])) {
    $orderDir = 'asc';
}

// Get individual column search values
$columnSearches = [];
foreach ($columns as $i => $column) {
    $columnSearchValue = $_REQUEST['columns'][$i]['search']['value'] ?? '';
    if ($columnSearchValue !== '') {
        $columnSearches[$column] = $columnSearchValue;
    }
}

// Base query - LEFT JOIN to get each user's global role (context_type IS NULL)
$baseQuery = "FROM {$dbPrefix}users u
    LEFT JOIN {$dbPrefix}user_roles ur ON ur.user_fk = u.users_pk AND ur.context_type IS NULL AND ur.context_id IS NULL
    LEFT JOIN {$dbPrefix}roles r ON r.roles_pk = ur.role_fk
    WHERE 1=1";
$params = [];
$types = '';

// Apply global search
if ($searchValue !== '') {
    $baseQuery .= " AND (
        u.full_name LIKE ? OR 
        u.email LIKE ?
    )";
    $searchParam = '%' . $searchValue . '%';
    $params = array_merge($params, [$searchParam, $searchParam]);
    $types .= 'ss';
}

// Apply column-specific filters
foreach ($columnSearches as $column => $value) {
    if ($column === 'is_active') {
        if (strtolower($value) === 'active') {
            $baseQuery .= " AND u.is_active = 1";
        } elseif (strtolower($value) === 'inactive') {
            $baseQuery .= " AND u.is_active = 0";
        }
    } elseif ($column === 'role') {
        $baseQuery .= " AND r.role_name LIKE ?";
        $params[] = '%' . $value . '%';
        $types .= 's';
    } elseif (in_array($column, ['full_name', 'email', 'users_pk'])) {
        $baseQuery .= " AND u.$column LIKE ?";
        $params[] = '%' . $value . '%';
        $types .= 's';
    }
}

// Get total count (before filtering)
$totalResult = $db->query("SELECT COUNT(*) as total FROM {$dbPrefix}users");
$totalRow = fetch_row($totalResult);
$recordsTotal = $totalRow['total'];

// Get filtered count
$filteredResult = $db->query(
    "SELECT COUNT(*) as total $baseQuery",
    $params,
    $types
);
$filteredRow = fetch_row($filteredResult);
$recordsFiltered = $filteredRow['total'];

// Get paginated data with ordering
$dataQuery = "
    SELECT 
        u.users_pk,
        u.full_name,
        u.email,
        r.role_name,
        u.is_active
    $baseQuery
    ORDER BY $orderColumn $orderDir
    " . getPaginationClause($length, $start) . "
";

$result = $db->query($dataQuery, $params, $types);

$data = [];
while ($row = fetch_row($result)) {
    $roleName   = $row['role_name'] ?? null;
    // Authorization is two-tier: the admin role grants edit access; every other
    // active account is the read/report "user" tier. A legacy 'user' role row
    // and a role-less account are the same tier and both display as User.
    $roleBadge  = ($roleName === 'admin')
        ? '<span class="badge bg-danger">Administrator</span>'
        : '<span class="badge bg-secondary">User</span>';
    $statusBadge = $row['is_active'] == 1
        ? '<span class="badge bg-success">Active</span>'
        : '<span class="badge bg-secondary">Inactive</span>';

    // Never expose password_hash in output
    $actions =
        '<button class="btn btn-sm btn-warning" onclick=\'editUser(' .
        htmlspecialchars(json_encode([
            'users_pk' => $row['users_pk'],
            'full_name' => $row['full_name'],
            'email'     => $row['email'],
            'role'      => $roleName ?? '',
            'is_active' => $row['is_active']
        ]), ENT_QUOTES, 'UTF-8') .
        ')\' aria-label="Edit user ' .
        htmlspecialchars($row['full_name'], ENT_QUOTES, 'UTF-8') .
        '"><i class="fas fa-edit" aria-hidden="true"></i></button>';

    $data[] = [
        htmlspecialchars((string)$row['users_pk']),
        htmlspecialchars($row['full_name']),
        htmlspecialchars($row['email']),
        $roleBadge,
        $statusBadge,
        $actions
    ];
}

// Return JSON response
echo json_encode([
    'draw' => $draw,
    'recordsTotal' => $recordsTotal,
    'recordsFiltered' => $recordsFiltered,
    'data' => $data
]);
