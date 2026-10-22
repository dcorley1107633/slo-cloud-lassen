<?php
declare(strict_types=1);

use SLOCloud\Core\Authorization;
use SLOCloud\Core\ThemeContext;
use SLOCloud\Core\ThemeLoader;

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';
require_once __DIR__ . '/../system/reporting/bootstrap.php';

header('Cache-Control: no-store, private');

if (!Authorization::isGlobalAdmin($db, $dbPrefix)) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

function activity_filter_date(string $key, string $fallback): string
{
    $value = isset($_REQUEST[$key]) && is_string($_REQUEST[$key]) ? trim($_REQUEST[$key]) : '';
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : $fallback;
}

function activity_filter_source(): string
{
    $source = isset($_REQUEST['source']) && is_string($_REQUEST['source']) ? strtolower(trim($_REQUEST['source'])) : 'all';
    return in_array($source, ['all', 'audit', 'security'], true) ? $source : 'all';
}

function activity_filter_search(): string
{
    $search = isset($_REQUEST['q']) && is_string($_REQUEST['q']) ? trim($_REQUEST['q']) : '';
    return substr($search, 0, 80);
}

function activity_audit_event(?string $json, string $fallback): string
{
    if ($json === null || $json === '') return $fallback;
    $decoded = json_decode($json, true);
    if (is_array($decoded) && isset($decoded['audit_event']) && is_scalar($decoded['audit_event'])) {
        return (string)$decoded['audit_event'];
    }
    return $fallback;
}

/** Plain-language details for a security event: its recorded reason or sign-in method, else its description. */
function activity_security_details(?string $metadataJson, string $description): string
{
    $reasons = [
        'admin_required' => 'Administrator role required',
        'unclassified_route' => 'Page not available',
        'recent_authentication_required' => 'Sign-in older than 15 minutes (rule removed in 5.2.3)',
        'wrong_password' => 'Wrong password',
        'unknown_account' => 'No account with that email',
        'account_inactive' => 'Account deactivated',
        'account_deleted' => 'Account no longer exists',
        'rate_limited' => 'Too many failed sign-in attempts',
        'saml_invalid_response' => 'Identity provider response could not be validated',
        'idle_timeout' => 'Signed out after 2 hours idle',
        'max_session_length' => 'Signed out 20 hours after sign-in',
        'user_logout' => 'Signed out by the user',
    ];
    $methods = ['password' => 'Password sign-in', 'emergency' => 'Emergency administrator sign-in', 'saml' => 'Single sign-on', 'lti' => 'Sign-in from the LMS (LTI)'];
    $via = ['emergency' => 'emergency administrator sign-in', 'saml' => 'single sign-on', 'lti' => 'the LMS (LTI)'];
    $metadata = is_string($metadataJson) && $metadataJson !== '' ? json_decode($metadataJson, true) : null;
    $reason = is_array($metadata) && is_string($metadata['reason'] ?? null) ? $metadata['reason'] : '';
    $method = is_array($metadata) && is_string($metadata['method'] ?? null) ? $metadata['method'] : '';
    $parts = [];
    if ($reason !== '') $parts[] = $reasons[$reason] ?? str_replace('_', ' ', $reason);
    // A password sign-in is the default; name the method only when it is something else.
    if ($method !== '' && $method !== 'password') {
        $parts[] = $reason !== '' ? 'via ' . ($via[$method] ?? $method) : ($methods[$method] ?? $method);
    } elseif ($method === 'password' && $reason === '') {
        $parts[] = $methods['password'];
    }
    return $parts !== [] ? implode(', ', $parts) : $description;
}

function activity_security_outcome(string $eventType, bool $isThreat, ?string $metadataJson): string
{
    $metadata = is_string($metadataJson) && $metadataJson !== '' ? json_decode($metadataJson, true) : null;
    $reason = is_array($metadata) && is_string($metadata['reason'] ?? null) ? $metadata['reason'] : '';
    // Before 5.2.3 every refusal was stored as a threat; a refusal for an ordinary
    // reason (role, old sign-in) is shown as denied whatever was stored.
    if ($isThreat && !in_array($reason, ['admin_required', 'recent_authentication_required'], true)) return 'threat';
    $event = strtolower($eventType);
    if ($event === 'permission_denied' || str_ends_with($event, '_denied')) return 'denied';
    if ($event === 'login_failed' || str_ends_with($event, '_failed')) return 'failed';
    return 'recorded';
}

function activity_rows($db, string $prefix, string $driver, string $from, string $to, string $source, string $search, int $limit): array
{
    $rows = [];
    $toExclusive = date('Y-m-d', strtotime($to . ' +1 day'));
    $auditParams = [$from . ' 00:00:00', $toExclusive . ' 00:00:00'];
    $securityParams = $auditParams;
    $auditWhere = 'created_at >= ? AND created_at < ?';
    $securityWhere = $auditWhere;

    if ($search !== '') {
        $like = '%' . $search . '%';
        $auditWhere .= ' AND (table_name LIKE ? OR action LIKE ?)';
        array_push($auditParams, $like, $like);
        $securityWhere .= ' AND (event_type LIKE ? OR event_description LIKE ? OR username LIKE ? OR severity LIKE ? OR request_uri LIKE ?)';
        array_push($securityParams, $like, $like, $like, $like, $like);
    }

    if ($source === 'all' || $source === 'audit') {
        $limitSql = ($driver === 'mssql' || $driver === 'sqlsrv') ? "SELECT TOP {$limit}" : 'SELECT';
        $suffix = ($driver === 'mssql' || $driver === 'sqlsrv') ? '' : " LIMIT {$limit}";
        $stmt = $db->query(
            "{$limitSql} audit_log_pk, table_name, record_pk, action, changed_by_fk, changed_data, ip_address, user_agent, created_at FROM {$prefix}audit_log WHERE {$auditWhere} ORDER BY created_at DESC{$suffix}",
            $auditParams
        );
        foreach ($stmt->fetchAll() as $row) {
            $event = activity_audit_event($row['changed_data'] ?? null, (string)$row['action']);
            $rows[] = [
                'created_at' => (string)$row['created_at'],
                'source' => 'Audit',
                'actor' => $row['changed_by_fk'] !== null ? 'User #' . (int)$row['changed_by_fk'] : '',
                'event' => $event,
                'details' => '',
                'target' => (string)$row['table_name'] . ' #' . (int)$row['record_pk'],
                'outcome' => strtolower($event) === 'denied' ? 'denied' : 'recorded',
                'severity' => '',
                'route' => '',
                'ip_address' => (string)($row['ip_address'] ?? ''),
            ];
        }
    }

    if ($source === 'all' || $source === 'security') {
        $limitSql = ($driver === 'mssql' || $driver === 'sqlsrv') ? "SELECT TOP {$limit}" : 'SELECT';
        $suffix = ($driver === 'mssql' || $driver === 'sqlsrv') ? '' : " LIMIT {$limit}";
        $stmt = $db->query(
            "{$limitSql} security_log_pk, event_type, event_description, user_fk, username, ip_address, request_uri, severity, is_threat, metadata, created_at FROM {$prefix}security_log WHERE {$securityWhere} ORDER BY created_at DESC{$suffix}",
            $securityParams
        );
        foreach ($stmt->fetchAll() as $row) {
            $actor = $row['user_fk'] !== null ? 'User #' . (int)$row['user_fk'] : (string)($row['username'] ?? '');
            $rows[] = [
                'created_at' => (string)$row['created_at'],
                'source' => 'Security',
                'actor' => $actor,
                'event' => (string)$row['event_type'],
                'details' => activity_security_details($row['metadata'] ?? null, (string)($row['event_description'] ?? '')),
                'target' => '',
                'outcome' => activity_security_outcome((string)$row['event_type'], (int)($row['is_threat'] ?? 0) === 1, $row['metadata'] ?? null),
                'severity' => (string)$row['severity'],
                'route' => (string)($row['request_uri'] ?? ''),
                'ip_address' => (string)($row['ip_address'] ?? ''),
            ];
        }
    }

    usort($rows, static fn(array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));
    return array_slice($rows, 0, $limit);
}

$today = date('Y-m-d');
$from = activity_filter_date('from', date('Y-m-d', strtotime('-30 days')));
$to = activity_filter_date('to', $today);
if ($from > $to) [$from, $to] = [$to, $from];
$source = activity_filter_source();
$search = activity_filter_search();
$limit = 500;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export') {
    report_verify_csrf();
    $rows = activity_rows($db, $dbPrefix, $db->getDriver(), $from, $to, $source, $search, 5000);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="activity-log-' . gmdate('Ymd-His') . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'wb');
    fputcsv($output, ['Timestamp', 'Source', 'Actor', 'Event', 'Details', 'Target', 'Outcome', 'Severity', 'Route', 'IP address'], ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($output, array_map('report_csv_safe', [
            $row['created_at'], $row['source'], $row['actor'], $row['event'], $row['details'], $row['target'],
            $row['outcome'], $row['severity'], $row['route'], $row['ip_address'],
        ]), ',', '"', '');
    }
    fclose($output);
    $logger->audit('activity_log', 0, 'export', report_actor_id(), ['row_count' => count($rows), 'source' => $source]);
    exit;
}

$rows = activity_rows($db, $dbPrefix, $db->getDriver(), $from, $to, $source, $search, $limit);

$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => 'Activity Log',
    'currentPage' => 'admin_activity_log',
    'breadcrumbs' => [
        ['url' => BASE_URL . 'administration/', 'label' => 'Administration'],
        ['label' => 'Activity Log'],
    ],
]);
$theme = ThemeLoader::getActiveTheme(null, 'admin');
$theme->showHeader($context);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h2 class="h3 mb-1">Activity Log</h2>
        <p class="text-muted mb-0">Curated audit and security events for administrator review.</p>
    </div>
    <form method="post" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?= report_h($_SESSION['csrf_token']) ?>">
        <input type="hidden" name="action" value="export">
        <input type="hidden" name="from" value="<?= report_h($from) ?>">
        <input type="hidden" name="to" value="<?= report_h($to) ?>">
        <input type="hidden" name="source" value="<?= report_h($source) ?>">
        <input type="hidden" name="q" value="<?= report_h($search) ?>">
        <button class="btn btn-success"><i class="fas fa-download" aria-hidden="true"></i> Export CSV</button>
    </form>
</div>

<form method="get" class="card mb-3">
    <div class="card-body row g-3 align-items-end">
        <div class="col-12 col-md-3">
            <label for="from" class="form-label">From date</label>
            <input type="date" class="form-control" id="from" name="from" value="<?= report_h($from) ?>">
        </div>
        <div class="col-12 col-md-3">
            <label for="to" class="form-label">To date</label>
            <input type="date" class="form-control" id="to" name="to" value="<?= report_h($to) ?>">
        </div>
        <div class="col-12 col-md-2">
            <label for="source" class="form-label">Source</label>
            <select class="form-select" id="source" name="source">
                <?php foreach (['all' => 'All', 'audit' => 'Audit', 'security' => 'Security'] as $value => $label): ?>
                    <option value="<?= report_h($value) ?>" <?= $source === $value ? 'selected' : '' ?>><?= report_h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-3">
            <label for="q" class="form-label">Search activity</label>
            <input type="search" class="form-control" id="q" name="q" value="<?= report_h($search) ?>">
        </div>
        <div class="col-12 col-md-1">
            <button type="submit" class="btn btn-primary w-100">Apply</button>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-striped table-hover mb-0">
                <caption class="visually-hidden">Curated activity log events</caption>
                <thead><tr><th scope="col">Timestamp</th><th scope="col">Source</th><th scope="col">Actor</th><th scope="col">Event</th><th scope="col">Details</th><th scope="col">Target</th><th scope="col">Outcome</th><th scope="col">Severity</th><th scope="col">Route</th><th scope="col">IP address</th></tr></thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr><td colspan="10" class="text-center text-muted py-5">No activity matched the selected filters.</td></tr>
                <?php endif; ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= report_h($row['created_at']) ?></td>
                        <td><?= report_h($row['source']) ?></td>
                        <td><?= report_h($row['actor']) ?></td>
                        <td><?= report_h($row['event']) ?></td>
                        <td><?= report_h($row['details']) ?></td>
                        <td><?= report_h($row['target']) ?></td>
                        <td><?= report_h($row['outcome']) ?></td>
                        <td><?= report_h($row['severity']) ?></td>
                        <td><?= report_h($row['route']) ?></td>
                        <td><?= report_h($row['ip_address']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer text-muted small">Showing up to <?= (int)$limit ?> events. CSV export includes up to 5000 matching events.</div>
</div>
<?php $theme->showFooter($context); ?>
