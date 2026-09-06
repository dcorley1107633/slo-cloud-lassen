<?php
declare(strict_types=1);

use SLOCloud\Reporting\ReportEngine;

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';
require_once __DIR__ . '/../system/reporting/bootstrap.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

try {
    $id = max(0, (int)($_GET['id'] ?? 0));
    $index = max(0, (int)($_GET['filter'] ?? 0));
    $term = isset($_GET['q']) && is_string($_GET['q']) ? $_GET['q'] : '';
    $definition = report_store()->find($id);
    if ($definition === null) { http_response_code(404); throw new RuntimeException('Report not found.'); }
    report_require_view($definition, report_user_can_manage($db, $dbPrefix));
    $catalog = report_load_catalog();
    if ($catalog === null) {
        // Initialization belongs to the report manager page; this endpoint
        // only serves filter options for an already-working catalog.
        throw new RuntimeException('The report catalog has not been built yet. Open Custom Report first.');
    }
    $engine = new ReportEngine($db->getConnection(), $db->getDriver(), $dbPrefix, $catalog, report_settings($config));
    $result = $engine->distinctValues($definition, $index, $term);
    if ($result['errors'] !== []) http_response_code(422);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    if (http_response_code() < 400) http_response_code(500);
    error_log('Custom report option search failed: ' . $error->getMessage());
    $message = match (http_response_code()) { 404 => 'Report not found.', 403 => 'This report is available to administrators only.', default => 'Filter values could not be loaded.' };
    echo json_encode(['errors' => [$message], 'values' => [], 'truncated' => false], JSON_UNESCAPED_UNICODE);
}
