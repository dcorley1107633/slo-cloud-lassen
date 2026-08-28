<?php
declare(strict_types=1);

use SLOCloud\Core\ThemeContext;
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Reporting\ReportCatalogBuilder;
use SLOCloud\Reporting\ReportDefinitionValidator;
use SLOCloud\Reporting\ReportEngine;

require_once __DIR__ . '/../system/includes/admin_session.php';
require_once __DIR__ . '/../system/includes/init.php';
require_once __DIR__ . '/../system/reporting/bootstrap.php';

header('Cache-Control: no-store, private');
$store = report_store();
$catalog = report_load_catalog();
if ($catalog === null) {
    // First-use initialization, sequenced exactly like Refresh catalog:
    // step 1 build from the live database, step 2 validate stored reports.
    $catalog = report_build_catalog($db->getConnection(), $db->getDriver(), $dbPrefix);
    $sweep = report_sweep_reports($catalog, $store);
    $logger->audit('dynamic_report_catalog', 0, 'initialize', report_actor_id(), $catalog['stats'] + [
        'reports_checked' => $sweep['checked'],
        'reports_needing_repair' => count($sweep['need_repair']),
        'affected' => $sweep['need_repair'],
    ]);
}
$canManage = report_user_can_manage($db, $dbPrefix);
$engine = new ReportEngine($db->getConnection(), $db->getDriver(), $dbPrefix, $catalog);
$mode = isset($_GET['mode']) && is_string($_GET['mode']) ? $_GET['mode'] : 'list';
$id = max(0, (int)($_GET['id'] ?? 0));
$errors = [];
$success = '';

if (in_array($mode, ['edit', 'raw'], true) && !$canManage) {
    http_response_code(403);
    $errors[] = 'Administrator permission is required to edit report definitions.';
    $mode = 'list';
}

function report_redirect(string $query = ''): never
{
    header('Location: ' . BASE_URL . 'administration/dynamic_reports.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function report_definition_or_404($store, int $id): array
{
    $definition = $store->find($id);
    if ($definition === null) {
        http_response_code(404);
        throw new RuntimeException('Report not found.');
    }
    return $definition;
}

function report_post_values(array $definition): array
{
    $values = [];
    foreach ($definition['filters'] ?? [] as $index => $_filter) {
        foreach (['f' . $index, 'f' . $index . 'b'] as $key) {
            if (array_key_exists($key, $_POST)) $values[$key] = $_POST[$key];
        }
    }
    return $values;
}

function report_format_value(mixed $value, array $meta): string
{
    if ($value === null || $value === '') return "\u{2014}";
    if ($meta['money'] && is_numeric($value)) return '$' . number_format((float)$value, 2);
    if ($meta['numeric'] && is_numeric($value)) return number_format((float)$value, str_contains((string)$value, '.') ? 2 : 0);
    if (in_array($meta['type'], ['date', 'datetime'], true)) {
        $time = strtotime((string)$value);
        if ($time !== false) return date($meta['type'] === 'date' ? 'M j, Y' : 'M j, Y g:i A', $time);
    }
    return (string)$value;
}

function report_render_table(array $result): void
{
    $meta = $result['column_meta'];
    ?>
    <table class="table table-striped table-sm mb-0 report-results" aria-label="Report results">
        <thead><tr><?php foreach ($meta as $column): ?><th scope="col" class="<?= $column['numeric'] ? 'text-end' : '' ?>"><?= report_h($column['label']) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($result['display_rows'] as $row):
            $labelPlaced = false; ?>
            <tr class="report-row-<?= report_h($row['kind']) ?>" data-level="<?= (int)$row['level'] ?>">
            <?php foreach ($meta as $column):
                $hasValue = array_key_exists($column['alias'], $row['cells']);
                $value = $hasValue ? $row['cells'][$column['alias']] : '';
                $isLabelCell = false;
                if ($row['kind'] !== 'data' && !$hasValue && !$labelPlaced) { $value = $row['label']; $labelPlaced = true; $isLabelCell = true; }
                ?>
                <?php $formatted = $row['kind'] !== 'data' && !$hasValue && !$isLabelCell ? '' : report_format_value($value, $column); ?>
                <td class="<?= $column['numeric'] && $hasValue ? 'text-end' : '' ?>"><?= report_h($formatted) ?></td>
            <?php endforeach; ?>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($result['display_rows'] === []): ?>
        <p class="text-center text-muted py-4 mb-0">No rows matched the filters.</p>
    <?php endif; ?>
    <?php
}

// Mutating manager actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($mode, ['list', 'edit', 'raw'], true)) {
    try {
        report_verify_csrf();
        report_require_manage($canManage);
        $action = isset($_POST['action']) && is_string($_POST['action']) ? $_POST['action'] : '';
        if ($action === 'delete') {
            $target = max(0, (int)($_POST['id'] ?? 0));
            if (!$store->delete($target)) throw new RuntimeException('The report could not be deleted.');
            $logger->audit('dynamic_reports', $target, 'delete', report_actor_id());
            $_SESSION['report_flash'] = 'Report deleted.';
            report_redirect();
        }
        if ($action === 'duplicate') {
            $copy = report_definition_or_404($store, (int)($_POST['id'] ?? 0));
            $copy['id'] = 0;
            $copy['name'] = trim((string)$copy['name']) . ' (Copy)';
            unset($copy['created_at'], $copy['updated_at']);
            $newId = $store->save($copy);
            $logger->audit('dynamic_reports', $newId, 'duplicate', report_actor_id(), ['source_report' => (int)($_POST['id'] ?? 0)]);
            report_redirect('mode=edit&id=' . $newId);
        }
        if ($action === 'refresh_catalog') {
            // Step 1: rebuild the catalog from the live database.
            $newCatalog = report_build_catalog($db->getConnection(), $db->getDriver(), $dbPrefix);
            // Step 2: revalidate every stored report against the new catalog.
            // Report-only: definitions are never rewritten.
            $sweep = report_sweep_reports($newCatalog, $store);

            $logger->audit('dynamic_report_catalog', 0, 'refresh', report_actor_id(), $newCatalog['stats'] + [
                'reports_checked' => $sweep['checked'],
                'reports_needing_repair' => count($sweep['need_repair']),
                'affected' => $sweep['need_repair'],
            ]);
            $_SESSION['report_flash'] = sprintf(
                'Catalog refreshed: %d tables and %d relationships. %s',
                $newCatalog['stats']['tables'],
                $newCatalog['stats']['relationships'],
                $sweep['need_repair'] === []
                    ? sprintf('All %d report(s) still valid.', $sweep['checked'])
                    : sprintf('%d of %d report(s) need repair: %s.', count($sweep['need_repair']), $sweep['checked'], implode(', ', $sweep['need_repair']))
            );
            report_redirect();
        }
        if ($action === 'save') {
            $submitted = json_decode((string)($_POST['definition'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($submitted)) throw new RuntimeException('The submitted report definition is invalid.');
            $submitted['id'] = $id;
            $prepared = (new ReportDefinitionValidator($catalog))->prepare($submitted);
            if ($prepared['errors'] !== []) {
                $errors = $prepared['errors'];
                $definition = $submitted;
            } else {
                $savedId = $store->save($prepared['definition']);
                $logger->audit('dynamic_reports', $savedId, $id > 0 ? 'update' : 'create', report_actor_id());
                $_SESSION['report_flash'] = 'Report saved.';
                report_redirect('mode=view&id=' . $savedId);
            }
        }
        if ($action === 'raw_save') {
            $text = (string)($_POST['raw_definition'] ?? '');
            $submitted = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($submitted)) throw new RuntimeException('The definition must be a JSON object.');
            if ((int)($submitted['id'] ?? 0) !== $id) throw new RuntimeException('The report ID cannot be changed in the raw editor.');
            $prepared = (new ReportDefinitionValidator($catalog))->prepare($submitted);
            if ($prepared['errors'] !== []) { $errors = $prepared['errors']; $rawText = $text; }
            else {
                $store->save($prepared['definition']);
                $logger->audit('dynamic_reports', $id, 'raw_update', report_actor_id());
                $_SESSION['report_flash'] = 'Raw definition saved.';
                report_redirect('mode=view&id=' . $id);
            }
        }
    } catch (JsonException $error) {
        $errors[] = 'Invalid JSON: ' . $error->getMessage();
        $rawText = (string)($_POST['raw_definition'] ?? $_POST['definition'] ?? '');
    } catch (Throwable $error) {
        $errors[] = $error->getMessage();
    }
}

// Run/export/print all share the same engine and submitted runtime values.
if (in_array($mode, ['export', 'print'], true)) {
    report_verify_csrf();
    $definition = report_definition_or_404($store, $id);
    $values = report_post_values($definition);
    $result = $engine->run($definition, $values);
    if ($result['errors'] !== []) {
        http_response_code(422);
        echo '<h1>Report could not run</h1><ul><li>' . implode('</li><li>', array_map('report_h', $result['errors'])) . '</li></ul>';
        exit;
    }
    if ($mode === 'export') {
        $slug = trim(preg_replace('/[^a-z0-9]+/i', '-', strtolower((string)$definition['name'])), '-') ?: 'report';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $slug . '-' . gmdate('Ymd-His') . '.csv"');
        header('X-Content-Type-Options: nosniff');
        echo "\xEF\xBB\xBF";
        $output = fopen('php://output', 'wb');
        // Header labels are admin-defined, but pass them through the same
        // formula-injection guard as the data cells so no export path emits an
        // unescaped leading =/+/-/@ into a spreadsheet.
        fputcsv($output, array_map('report_csv_safe', array_column($result['column_meta'], 'label')), ',', '"', '');
        foreach ($result['display_rows'] as $row) {
            $line = []; $labelPlaced = false;
            foreach ($result['column_meta'] as $column) {
                $has = array_key_exists($column['alias'], $row['cells']);
                $value = $has ? $row['cells'][$column['alias']] : '';
                if ($row['kind'] !== 'data' && !$has && !$labelPlaced) { $value = $row['label']; $labelPlaced = true; }
                $line[] = report_csv_safe($value);
            }
            fputcsv($output, $line, ',', '"', '');
        }
        fclose($output);
        $logger->audit('dynamic_reports', $id, 'export', report_actor_id(), ['row_count' => $result['row_count']]);
        exit;
    }
    $filterEcho = [];
    foreach ($definition['filters'] as $index => $filter) {
        $value = $values['f' . $index] ?? $filter['default'];
        $upper = $values['f' . $index . 'b'] ?? $filter['default_to'];
        if (report_value_empty($value) && report_value_empty($upper) && !in_array($filter['operator'], ['is_null', 'not_null'], true)) continue;
        $shown = is_array($value) ? implode(', ', $value) : (string)$value;
        if (!report_value_empty($upper)) $shown .= ' to ' . (string)$upper;
        $filterEcho[] = ($filter['label'] ?: $catalog['tables'][$filter['table']]['fields'][$filter['field']]['label']) . ' - ' . ($catalog['operators'][$filter['operator']] ?? $filter['operator']) . ': ' . $shown;
    }
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= report_h($definition['name']) ?> | <?= report_h(SITE_NAME) ?></title><style>
        *{box-sizing:border-box}body{font:12px/1.45 Arial,Helvetica,sans-serif;color:#111;margin:24px}h1{font-size:18px;margin:0 0 2px}.meta{color:#555;margin-bottom:14px}.meta div{margin-top:2px}.filters{margin:10px 0 14px}.report-results{border-collapse:collapse;width:100%}.report-results th,.report-results td{border:1px solid #bbb;padding:4px 6px;text-align:left;vertical-align:top}.report-results th{background:#eee}.text-end{text-align:right!important}.report-row-subtotal td{background:#f3f3f3;font-weight:600;border-top:2px solid #888}.report-row-total td{background:#ddd;font-weight:700;border-top:2px solid #333}.footer{margin-top:14px;color:#777;font-size:10px}.no-print{margin-bottom:14px}.no-print button{padding:6px 14px}p.text-center{text-align:center;color:#777}@media print{body{margin:0}.no-print{display:none}thead{display:table-header-group}@page{margin:.45in}}
    </style></head><body><div class="no-print"><button type="button" onclick="window.print()">Print</button> <button type="button" onclick="window.close()">Close</button></div><h1><?= report_h($definition['name']) ?></h1><?php if ($definition['description'] !== ''): ?><div><?= report_h($definition['description']) ?></div><?php endif; ?><div class="meta"><div>Generated <?= report_h(date('M j, Y g:i A')) ?> &middot; <?= number_format((int)$result['row_count']) ?> data row<?= (int)$result['row_count'] === 1 ? '' : 's' ?></div></div>
    <?php if ($filterEcho !== []): ?><div class="filters"><strong>Filters:</strong><ul><?php foreach ($filterEcho as $echo): ?><li><?= report_h($echo) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php report_render_table($result); ?><div class="footer"><?= report_h(SITE_NAME) ?> &mdash; <?= report_h($definition['name']) ?></div><script>window.addEventListener('load',function(){setTimeout(function(){window.print()},150)});</script></body></html><?php
    exit;
}

$pageTitle = match ($mode) {
    'edit' => $id > 0 ? 'Edit Report' : 'New Report',
    'raw' => 'Raw Report Definition',
    'view' => (string)($store->find($id)['name'] ?? 'Custom Report'),
    default => 'Report Manager',
};
$context = new ThemeContext([
    'layout' => 'admin', 'pageTitle' => $pageTitle, 'currentPage' => 'admin_dynamic_reports',
    'breadcrumbs' => [['url' => BASE_URL . 'administration/', 'label' => 'Administration'], ['label' => 'Custom Report']],
    'customCss' => '<link rel="stylesheet" href="' . report_h(BASE_URL . 'assets/css/custom-reports.css') . '">',
]);
$theme = ThemeLoader::getActiveTheme(null, 'admin');
$theme->showHeader($context);

$flash = $_SESSION['report_flash'] ?? '';
unset($_SESSION['report_flash']);
if ($flash !== ''): ?><div class="alert alert-success" role="alert"><?= report_h($flash) ?></div><?php endif;
if ($errors !== []): ?><div class="alert alert-danger" role="alert" data-a11y-focus="true"><strong>Please fix the following:</strong><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= report_h($error) ?></li><?php endforeach; ?></ul></div><?php endif;

if ($mode === 'list'):
    $definitions = $store->all();
    $validator = new ReportDefinitionValidator($catalog);
    ?>
    <div class="slo-report-manager">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
            <span class="text-muted">Configurable reports built from the master data catalog.
                <br><span class="small">Catalog: <?= count($catalog['tables'] ?? []) ?> tables &middot; <?= count($catalog['relationships'] ?? []) ?> relationships &middot; refreshed <?= report_h(date('M j, Y g:i A', strtotime((string)$catalog['generated_at']))) ?> (<?= report_h($catalog['source'] ?? 'unknown') ?>)</span>
            </span>
            <?php if ($canManage): ?>
                <div class="d-flex flex-wrap gap-2">
                    <form method="post" class="m-0" onsubmit="return confirm('Rebuild the master data catalog from the connected database? Reports referencing removed tables or fields will need to be repaired.');">
                        <input type="hidden" name="csrf_token" value="<?= report_h($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action" value="refresh_catalog">
                        <button class="btn btn-outline-secondary" type="submit" title="Re-read tables, fields, and relationships from the database"><i class="fas fa-rotate me-1" aria-hidden="true"></i>Refresh Catalog</button>
                    </form>
                    <a class="btn btn-primary" href="?mode=edit"><i class="fas fa-plus me-1" aria-hidden="true"></i>New Report</a>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header"><i class="fas fa-table-list me-2" aria-hidden="true"></i>Reports</div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-striped table-hover mb-0">
                    <caption class="visually-hidden">Saved custom reports</caption>
                    <thead><tr><th scope="col">Report</th><th scope="col">Description</th><th scope="col">Base Table</th><th scope="col">Columns</th><th scope="col">Filters</th><th scope="col">Updated</th><th scope="col" class="text-end">Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($definitions as $item): $validation = $validator->prepare($item); ?>
                        <tr>
                            <td class="fw-semibold"><a class="text-decoration-none" href="?mode=view&amp;id=<?= (int)$item['id'] ?>"><?= report_h($item['name']) ?></a><?php if ($validation['errors'] !== []): ?> <span class="badge text-bg-warning">Needs repair</span><?php endif; ?></td>
                            <td class="small text-muted"><?= report_h($item['description'] ?? '') ?></td>
                            <td><code><?= report_h($catalog['tables'][$item['base_table']]['label'] ?? $item['base_table']) ?></code></td>
                            <td><?= count($item['columns'] ?? []) ?></td>
                            <td><?= count($item['filters'] ?? []) ?></td>
                            <td class="small text-nowrap"><?= report_h(isset($item['updated_at']) ? date('M j, Y g:i A', strtotime($item['updated_at'])) : "\u{2014}") ?></td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="?mode=view&amp;id=<?= (int)$item['id'] ?>" title="Run or view"><i class="fas fa-play me-1" aria-hidden="true"></i>View</a>
                                <?php if ($canManage): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="?mode=edit&amp;id=<?= (int)$item['id'] ?>" title="Edit definition" aria-label="Edit <?= report_h($item['name']) ?>"><i class="fas fa-pen" aria-hidden="true"></i></a>
                                    <a class="btn btn-sm btn-outline-warning" href="?mode=raw&amp;id=<?= (int)$item['id'] ?>" title="Edit raw JSON" aria-label="Edit raw JSON for <?= report_h($item['name']) ?>"><i class="fas fa-code" aria-hidden="true"></i></a>
                                    <form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= report_h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button class="btn btn-sm btn-outline-secondary" type="submit" title="Duplicate" aria-label="Duplicate <?= report_h($item['name']) ?>"><i class="fas fa-copy" aria-hidden="true"></i></button></form>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Delete report &quot;<?= report_h($item['name']) ?>&quot;?');"><input type="hidden" name="csrf_token" value="<?= report_h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit" title="Delete" aria-label="Delete <?= report_h($item['name']) ?>"><i class="fas fa-trash" aria-hidden="true"></i></button></form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ($definitions === []): ?><p class="text-center text-muted py-4 mb-0">No reports yet.<?= $canManage ? ' Use "New Report" to create the first one.' : '' ?></p><?php endif; ?>
            </div>
        </div>
    </div>

<?php elseif ($mode === 'edit'):
    report_require_manage($canManage);
    $definition ??= $id > 0 ? report_definition_or_404($store, $id) : (new ReportDefinitionValidator($catalog))->blank();
    ?>
    <div class="slo-report-builder">
        <div id="pruneNotice" class="alert alert-warning d-none" role="status" aria-live="polite"></div>
        <form method="post" id="reportEditor">
            <input type="hidden" name="csrf_token" value="<?= report_h($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="definition" id="definitionJson">

            <div class="card mb-3">
                <div class="card-header"><i class="fas fa-circle-info me-2" aria-hidden="true"></i>Report</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4"><label class="form-label" for="reportName">Name <span class="text-danger" aria-label="required">*</span></label><input class="form-control" id="reportName" required maxlength="160"></div>
                        <div class="col-md-8"><label class="form-label" for="reportDescription">Description</label><input class="form-control" id="reportDescription" maxlength="500"></div>
                        <div class="col-md-4"><label class="form-label" for="baseTable">Base table</label><select class="form-select" id="baseTable" aria-describedby="baseTableHelp"></select><div class="form-text" id="baseTableHelp">Choose the record type the report must preserve. Orphan-review reports should start at the affected table.</div></div>
                        <div class="col-md-8"><label class="form-label">Joined tables</label><div id="joinPanel" class="report-joins border rounded p-2 bg-body-tertiary small"><span class="text-muted">Loading relationships...</span></div></div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2"><span><i class="fas fa-table-columns me-2" aria-hidden="true"></i>Display columns</span><div class="d-flex flex-wrap gap-2"><select class="form-select form-select-sm report-editor-picker" id="columnPicker" aria-label="Display column to add"></select><button type="button" class="btn btn-sm btn-primary text-nowrap report-editor-add" id="addColumn"><i class="fas fa-plus me-1" aria-hidden="true"></i>Add column</button></div></div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-sm align-middle mb-0" id="reportColumns"><caption class="visually-hidden">Display columns in result order</caption><thead><tr><th scope="col" class="text-nowrap"><span class="visually-hidden">Order</span></th><th scope="col">Field</th><th scope="col">Label override</th><th scope="col">Aggregate</th><th scope="col">Sort</th><th scope="col" class="text-center" title="Start a new group and subtotal when this value changes">Break</th><th scope="col" class="text-center" title="Sum this column in subtotals and the grand total">Summarize</th><th scope="col"><span class="visually-hidden">Remove</span></th></tr></thead><tbody></tbody></table>
                    <p class="text-muted text-center py-3 mb-0 d-none" id="columnsEmpty">No display columns yet.</p>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2"><span><i class="fas fa-filter me-2" aria-hidden="true"></i>Filter parameters</span><div class="d-flex flex-wrap gap-2"><select class="form-select form-select-sm report-editor-picker" id="filterPicker" aria-label="Filter field to add"></select><button type="button" class="btn btn-sm btn-primary text-nowrap report-editor-add" id="addFilter"><i class="fas fa-plus me-1" aria-hidden="true"></i>Add filter</button></div></div>
                <div class="card-body p-0 table-responsive">
                    <table class="table table-sm align-middle mb-0" id="reportFilters"><caption class="visually-hidden">Runtime filter parameters</caption><thead><tr><th scope="col" class="text-nowrap"><span class="visually-hidden">Order</span></th><th scope="col">Field</th><th scope="col">Prompt label</th><th scope="col">Input</th><th scope="col">Operator</th><th scope="col">Default value(s)</th><th scope="col" class="text-center">Required</th><th scope="col"><span class="visually-hidden">Remove</span></th></tr></thead><tbody></tbody></table>
                    <p class="text-muted text-center py-3 mb-0 d-none" id="filtersEmpty">No filters &mdash; the report will return everything up to the row limit.</p>
                </div>
            </div>

            <div class="card mb-3"><div class="card-header"><i class="fas fa-sliders me-2" aria-hidden="true"></i>Options</div><div class="card-body"><div class="row g-3 align-items-center"><div class="col-auto"><label class="form-label mb-0" for="rowLimit">Row limit</label><input class="form-control" type="number" min="1" max="<?= ReportDefinitionValidator::HARD_ROW_LIMIT ?>" id="rowLimit" style="width:7rem"></div><div class="col-auto form-check form-switch mt-4 ms-2"><input class="form-check-input" type="checkbox" id="grandTotal"><label class="form-check-label" for="grandTotal">Show grand total row</label></div></div></div></div>

            <div class="d-flex gap-2 mb-4"><button class="btn btn-primary" type="submit"><i class="fas fa-floppy-disk me-1" aria-hidden="true"></i>Save Report</button><a class="btn btn-outline-secondary" href="<?= $id > 0 ? '?mode=view&amp;id=' . $id : '?' ?>">Cancel</a></div>
        </form>
    </div>

    <script>
    (() => {
        'use strict';
        const catalog = <?= json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const def = <?= json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        const $ = id => document.getElementById(id);
        const esc = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
        const relationships = Array.isArray(catalog.relationships) ? catalog.relationships : [];
        const relationshipMap = Object.fromEntries(relationships.map(relationship => [relationship.id, relationship]));
        const originalBase = def.base_table;
        if (!catalog.tables[def.base_table]) {
            def.base_table = catalog.tables.terms ? 'terms' : (Object.keys(catalog.tables)[0] || '');
        }
        const baseWasReset = originalBase !== def.base_table;

        function normalize(showNotice = false) {
            const removed = [];
            const connected = new Set(def.base_table ? [def.base_table] : []);
            const pending = [...(Array.isArray(def.joins) ? def.joins : [])];
            const ordered = [];
            let progressed = true;
            while (progressed) {
                progressed = false;
                for (let index = 0; index < pending.length; index++) {
                    const relationship = relationshipMap[pending[index]];
                    const fromConnected = relationship && connected.has(relationship.from_table);
                    const toConnected = relationship && connected.has(relationship.to_table);
                    if (relationship && fromConnected !== toConnected) {
                        ordered.push(relationship.id);
                        connected.add(relationship.from_table);
                        connected.add(relationship.to_table);
                        pending.splice(index--, 1);
                        progressed = true;
                    } else if (relationship && fromConnected && toConnected) {
                        removed.push(`cyclic join ${relationship.id}`);
                        pending.splice(index--, 1);
                        progressed = true;
                    }
                }
            }
            pending.forEach(join => removed.push(`join ${join}`));
            def.joins = ordered;
            for (const key of ['columns', 'filters']) {
                def[key] = (Array.isArray(def[key]) ? def[key] : []).filter(item => {
                    const valid = connected.has(item.table) && catalog.tables[item.table]?.fields?.[item.field];
                    if (!valid) removed.push(`${key.slice(0, -1)} ${item.table}.${item.field}`);
                    return valid;
                });
            }
            if (showNotice && (removed.length || baseWasReset)) {
                $('pruneNotice').classList.remove('d-none');
                const messages = [];
                if (baseWasReset) messages.push(`the unavailable base table was replaced with ${esc(def.base_table)}`);
                if (removed.length) messages.push('unavailable items were removed: ' + removed.map(esc).join(', '));
                $('pruneNotice').innerHTML = '<strong>Report structure updated:</strong> ' + messages.join('; ') + '. Review the report before saving.';
            }
            return connected;
        }

        function fieldMeta(table, field) { return catalog.tables[table]?.fields?.[field] || null; }
        function fieldTitle(table, field) { return `${catalog.tables[table]?.label || table} · ${fieldMeta(table, field)?.label || field}`; }
        function splitField(value) { const point = value.indexOf('.'); return point < 0 ? ['', ''] : [value.slice(0, point), value.slice(point + 1)]; }
        function safeId(value) { return String(value).replace(/[^a-z0-9_-]/gi, '-'); }

        function renderJoins() {
            const panel = $('joinPanel');
            panel.innerHTML = '';
            if (!def.base_table) { panel.innerHTML = '<span class="text-danger">No reportable base table is available.</span>'; return; }
            const connected = normalize();
            const usable = relationships.filter(relationship =>
                def.joins.includes(relationship.id)
                || connected.has(relationship.from_table) !== connected.has(relationship.to_table)
            );
            if (!usable.length) { panel.innerHTML = '<span class="text-muted">No related tables are available for this base table.</span>'; return; }
            usable.forEach(relationship => {
                const id = `join-${safeId(relationship.id)}`;
                const wrapper = document.createElement('div');
                wrapper.className = 'form-check mb-1';
                wrapper.innerHTML = `<input class="form-check-input" type="checkbox" id="${id}"><label class="form-check-label" for="${id}">${esc(relationship.label)}</label>`;
                const checkbox = wrapper.querySelector('input');
                checkbox.checked = def.joins.includes(relationship.id);
                checkbox.addEventListener('change', () => {
                    if (checkbox.checked && !def.joins.includes(relationship.id)) def.joins.push(relationship.id);
                    if (!checkbox.checked) def.joins = def.joins.filter(id => id !== relationship.id);
                    normalize();
                    renderAll();
                });
                panel.appendChild(wrapper);
            });
        }

        function fillPicker(select) {
            select.innerHTML = '';
            const connected = normalize();
            connected.forEach(table => {
                if (!catalog.tables[table]) return;
                const group = document.createElement('optgroup');
                group.label = catalog.tables[table].label;
                Object.entries(catalog.tables[table].fields).forEach(([field, meta]) => {
                    const option = document.createElement('option');
                    option.value = `${table}.${field}`;
                    option.textContent = meta.label;
                    group.appendChild(option);
                });
                select.appendChild(group);
            });
            if (select.options.length === 0) {
                const option = document.createElement('option');
                option.value = '';
                option.textContent = 'No reportable fields are available';
                select.appendChild(option);
            }
        }

        function renderColumns() {
            const body = $('reportColumns').querySelector('tbody');
            body.innerHTML = '';
            $('columnsEmpty').classList.toggle('d-none', def.columns.length > 0);
            def.columns.forEach((column, index) => {
                const meta = fieldMeta(column.table, column.field);
                const aggregates = catalog.types[meta.type]?.aggregates || [];
                const numeric = ['int', 'decimal', 'bool'].includes(meta.type) || column.aggregate !== '';
                const row = document.createElement('tr');
                row.innerHTML = `<td class="text-nowrap report-drag-buttons"><button type="button" class="btn btn-sm btn-light px-1 py-0" data-action="up" title="Move up" aria-label="Move column ${index + 1} up"><i class="fas fa-caret-up" aria-hidden="true"></i></button><button type="button" class="btn btn-sm btn-light px-1 py-0" data-action="down" title="Move down" aria-label="Move column ${index + 1} down"><i class="fas fa-caret-down" aria-hidden="true"></i></button></td><td class="small">${esc(fieldTitle(column.table, column.field))}</td><td><input class="form-control form-control-sm" data-action="label" value="${esc(column.label)}" placeholder="${esc(meta.label)}" aria-label="Label override for column ${index + 1}"></td><td><select class="form-select form-select-sm" data-action="aggregate" aria-label="Aggregate for column ${index + 1}"><option value="">— group value —</option>${aggregates.map(aggregate => `<option value="${esc(aggregate)}" ${column.aggregate === aggregate ? 'selected' : ''}>${esc(catalog.aggregates[aggregate])}</option>`).join('')}</select></td><td><select class="form-select form-select-sm" data-action="sort" aria-label="Sort for column ${index + 1}"><option value="">—</option><option value="asc" ${column.sort === 'asc' ? 'selected' : ''}>Asc</option><option value="desc" ${column.sort === 'desc' ? 'selected' : ''}>Desc</option></select></td><td class="text-center"><input class="form-check-input" type="checkbox" data-action="break" ${column.break ? 'checked' : ''} aria-label="Control break for column ${index + 1}"></td><td class="text-center"><input class="form-check-input" type="checkbox" data-action="summarize" ${column.summarize ? 'checked' : ''} ${numeric ? '' : 'disabled'} aria-label="Summarize column ${index + 1}"></td><td><button type="button" class="btn btn-sm btn-outline-danger border-0" data-action="remove" title="Remove" aria-label="Remove column ${index + 1}">&times;</button></td>`;
                row.querySelector('[data-action="up"]').addEventListener('click', () => { if (index > 0) { def.columns.splice(index - 1, 0, def.columns.splice(index, 1)[0]); renderColumns(); } });
                row.querySelector('[data-action="down"]').addEventListener('click', () => { if (index < def.columns.length - 1) { def.columns.splice(index + 1, 0, def.columns.splice(index, 1)[0]); renderColumns(); } });
                row.querySelector('[data-action="remove"]').addEventListener('click', () => { def.columns.splice(index, 1); renderColumns(); });
                row.querySelector('[data-action="label"]').addEventListener('input', event => column.label = event.target.value);
                row.querySelector('[data-action="aggregate"]').addEventListener('change', event => { column.aggregate = event.target.value; if (column.aggregate) column.break = false; renderColumns(); });
                row.querySelector('[data-action="sort"]').addEventListener('change', event => column.sort = event.target.value);
                row.querySelector('[data-action="break"]').addEventListener('change', event => { column.break = event.target.checked; if (column.break) column.aggregate = ''; renderColumns(); });
                row.querySelector('[data-action="summarize"]').addEventListener('change', event => column.summarize = event.target.checked);
                body.appendChild(row);
            });
        }

        function renderFilters() {
            const body = $('reportFilters').querySelector('tbody');
            body.innerHTML = '';
            $('filtersEmpty').classList.toggle('d-none', def.filters.length > 0);
            def.filters.forEach((filter, index) => {
                const meta = fieldMeta(filter.table, filter.field);
                const type = catalog.types[meta.type];
                const operators = type?.operators || [];
                const supportsMulti = operators.includes('in');
                filter.control = filter.control === 'multi_select' && supportsMulti ? 'multi_select' : 'textbox';
                if (filter.control === 'multi_select') filter.operator = 'in';
                if (!operators.includes(filter.operator)) filter.operator = operators[0] || '';
                const shownOperators = filter.control === 'multi_select' ? ['in'] : operators;
                const isRange = filter.operator === 'between';
                const needsValue = !['is_null', 'not_null'].includes(filter.operator);
                const inputType = type?.input === 'number' ? 'number' : (type?.input === 'date' ? 'date' : 'text');
                const defaultValue = Array.isArray(filter.default) ? filter.default.join(', ') : (filter.default || '');
                const row = document.createElement('tr');
                row.innerHTML = `<td class="text-nowrap report-drag-buttons"><button type="button" class="btn btn-sm btn-light px-1 py-0" data-action="up" title="Move up" aria-label="Move filter ${index + 1} up"><i class="fas fa-caret-up" aria-hidden="true"></i></button><button type="button" class="btn btn-sm btn-light px-1 py-0" data-action="down" title="Move down" aria-label="Move filter ${index + 1} down"><i class="fas fa-caret-down" aria-hidden="true"></i></button></td><td class="small">${esc(fieldTitle(filter.table, filter.field))}</td><td><input class="form-control form-control-sm" data-action="label" value="${esc(filter.label)}" placeholder="${esc(meta.label)}" aria-label="Prompt label for filter ${index + 1}"></td><td><select class="form-select form-select-sm" data-action="control" aria-label="Input control for filter ${index + 1}"><option value="textbox">Textbox</option><option value="multi_select" ${filter.control === 'multi_select' ? 'selected' : ''} ${supportsMulti ? '' : 'disabled'}>Dropdown multiselect</option></select></td><td><select class="form-select form-select-sm" data-action="operator" ${filter.control === 'multi_select' ? 'disabled' : ''} aria-label="Operator for filter ${index + 1}">${shownOperators.map(operator => `<option value="${esc(operator)}" ${filter.operator === operator ? 'selected' : ''}>${esc(catalog.operators[operator])}</option>`).join('')}</select></td><td>${needsValue ? `<div class="d-flex gap-1"><input class="form-control form-control-sm" type="${filter.control === 'multi_select' ? 'text' : inputType}" step="any" data-action="default" value="${esc(defaultValue)}" ${filter.control === 'multi_select' ? 'placeholder="comma-separated values"' : ''} aria-label="Default value for filter ${index + 1}">${isRange ? `<input class="form-control form-control-sm" type="${inputType}" step="any" data-action="default-to" value="${esc(filter.default_to || '')}" aria-label="Default upper value for filter ${index + 1}">` : ''}</div>` : '<span class="text-muted small">n/a</span>'}</td><td class="text-center"><input class="form-check-input" type="checkbox" data-action="required" ${filter.required ? 'checked' : ''} aria-label="Require filter ${index + 1}"></td><td><button type="button" class="btn btn-sm btn-outline-danger border-0" data-action="remove" title="Remove" aria-label="Remove filter ${index + 1}">&times;</button></td>`;
                row.querySelector('[data-action="up"]').addEventListener('click', () => { if (index > 0) { def.filters.splice(index - 1, 0, def.filters.splice(index, 1)[0]); renderFilters(); } });
                row.querySelector('[data-action="down"]').addEventListener('click', () => { if (index < def.filters.length - 1) { def.filters.splice(index + 1, 0, def.filters.splice(index, 1)[0]); renderFilters(); } });
                row.querySelector('[data-action="remove"]').addEventListener('click', () => { def.filters.splice(index, 1); renderFilters(); });
                row.querySelector('[data-action="label"]').addEventListener('input', event => filter.label = event.target.value);
                row.querySelector('[data-action="control"]').addEventListener('change', event => { filter.control = event.target.value; if (filter.control === 'multi_select') filter.operator = 'in'; renderFilters(); });
                row.querySelector('[data-action="operator"]').addEventListener('change', event => { filter.operator = event.target.value; renderFilters(); });
                row.querySelector('[data-action="required"]').addEventListener('change', event => filter.required = event.target.checked);
                row.querySelector('[data-action="default"]')?.addEventListener('input', event => { filter.default = filter.control === 'multi_select' ? event.target.value.split(',').map(value => value.trim()).filter(Boolean) : event.target.value; });
                row.querySelector('[data-action="default-to"]')?.addEventListener('input', event => filter.default_to = event.target.value);
                body.appendChild(row);
            });
        }

        function renderAll() {
            renderJoins();
            fillPicker($('columnPicker'));
            fillPicker($('filterPicker'));
            $('addColumn').disabled = !def.base_table;
            $('addFilter').disabled = !def.base_table;
            renderColumns();
            renderFilters();
        }

        $('reportName').value = def.name || '';
        $('reportDescription').value = def.description || '';
        Object.entries(catalog.tables).forEach(([table, meta]) => {
            const option = document.createElement('option');
            option.value = table;
            option.textContent = `${meta.label} (${table})`;
            $('baseTable').appendChild(option);
        });
        $('baseTable').value = def.base_table || '';
        $('rowLimit').value = def.options?.row_limit || 500;
        $('grandTotal').checked = def.options?.grand_total !== false;
        $('baseTable').addEventListener('change', event => {
            def.base_table = event.target.value;
            normalize(true);
            renderAll();
        });

        $('addColumn').addEventListener('click', () => {
            const value = $('columnPicker').value;
            if (!value) return;
            const [table, field] = splitField(value);
            def.columns.push({table, field, label:'', aggregate:'', sort:'', break:false, summarize:false});
            renderColumns();
        });
        $('addFilter').addEventListener('click', () => {
            const value = $('filterPicker').value;
            if (!value) return;
            const [table, field] = splitField(value);
            const type = fieldMeta(table, field).type;
            def.filters.push({table, field, operator:catalog.types[type].operators[0], control:'textbox', label:'', required:false, default:'', default_to:''});
            renderFilters();
        });
        $('reportEditor').addEventListener('submit', () => {
            def.name = $('reportName').value.trim();
            def.description = $('reportDescription').value.trim();
            def.base_table = $('baseTable').value;
            def.options = {row_limit:parseInt($('rowLimit').value, 10) || 500, grand_total:$('grandTotal').checked};
            normalize();
            $('definitionJson').value = JSON.stringify(def);
        });

        normalize(true);
        renderAll();
    })();
    </script>

<?php elseif ($mode === 'raw'):
    report_require_manage($canManage);
    $definition = report_definition_or_404($store, $id);
    $rawText ??= json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ?><div class="alert alert-warning" role="alert"><i class="fas fa-triangle-exclamation me-2" aria-hidden="true"></i><strong>Advanced editing only.</strong> Changing this raw report configuration can corrupt or break the report. Invalid JSON and invalid report definitions will not be saved.</div>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= report_h($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="raw_save"><div class="card mb-3"><div class="card-header"><i class="fas fa-code me-2" aria-hidden="true"></i>Raw report JSON</div><div class="card-body"><label class="form-label" for="rawDefinition">Configuration for report #<?= $id ?></label><textarea class="form-control font-monospace" id="rawDefinition" name="raw_definition" rows="28" spellcheck="false" required><?= report_h($rawText) ?></textarea></div></div><div class="d-flex gap-2 mb-4"><button class="btn btn-warning" type="submit"><i class="fas fa-floppy-disk me-1" aria-hidden="true"></i>Validate and Save JSON</button><a class="btn btn-outline-secondary" href="?mode=view&amp;id=<?= $id ?>">Cancel</a><a class="btn btn-outline-primary ms-auto" href="?mode=edit&amp;id=<?= $id ?>"><i class="fas fa-pen me-1" aria-hidden="true"></i>Visual Editor</a></div></form>

<?php elseif ($mode === 'view'):
    $definition = report_definition_or_404($store, $id);
    $prepared = (new ReportDefinitionValidator($catalog))->prepare($definition);
    $values = $_SERVER['REQUEST_METHOD'] === 'POST' ? report_post_values($definition) : [];
    $result = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') { report_verify_csrf(); $result = $engine->run($definition, $values); }
    ?><div class="slo-report-viewer">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><a class="btn btn-outline-secondary" href="?"><i class="fas fa-arrow-left me-1" aria-hidden="true"></i>Report Manager</a><?php if ($canManage): ?><a class="btn btn-outline-primary" href="?mode=edit&amp;id=<?= $id ?>"><i class="fas fa-pen me-1" aria-hidden="true"></i>Edit Definition</a><?php endif; ?></div>
        <?php if ($definition['description'] !== ''): ?><p class="text-muted"><?= report_h($definition['description']) ?></p><?php endif; ?>

        <?php if ($prepared['errors'] !== []): ?>
            <div class="alert alert-warning"><strong>This report needs repair.</strong> It references data that no longer exists in the catalog. <?= $canManage ? '<a href="?mode=edit&amp;id=' . $id . '">Open the editor and re-save it</a>; removed fields will be listed.' : 'Ask an administrator to open and re-save it.' ?><ul class="mb-0 mt-2"><?php foreach ($prepared['errors'] as $error): ?><li><?= report_h($error) ?></li><?php endforeach; ?></ul></div>
        <?php else: ?>
            <div class="card mb-3 report-screen-only">
                <div class="card-header"><i class="fas fa-filter me-2" aria-hidden="true"></i>Filters</div>
                <div class="card-body">
                    <form method="post" id="runReport" class="row g-3 align-items-end">
                        <input type="hidden" name="csrf_token" value="<?= report_h($_SESSION['csrf_token']) ?>">
                        <?php foreach ($prepared['definition']['filters'] as $index => $filter): $field = $catalog['tables'][$filter['table']]['fields'][$filter['field']]; $key='f'.$index; $current=$values[$key] ?? $filter['default']; $upper=$values[$key.'b'] ?? $filter['default_to']; $label=$filter['label'] ?: $catalog['tables'][$filter['table']]['label'].': '.$field['label']; ?>
                            <div class="col-md-3"><label class="form-label" for="<?= $key ?><?= ($filter['control'] ?? '') === 'multi_select' ? '-toggle' : '' ?>"><?= report_h($label) ?> <span class="text-muted small">(<?= report_h(strtolower((string)$catalog['operators'][$filter['operator']])) ?>)</span><?php if ($filter['required']): ?> <span class="text-danger" aria-label="required">*</span><?php endif; ?></label><?= report_filter_input_html($filter, $field, $catalog['types'][$field['type']], $index, $current, $upper) ?></div>
                        <?php endforeach; ?>
                        <div class="col-md-auto d-flex flex-wrap flex-md-nowrap gap-2">
                            <button class="btn btn-primary text-nowrap" type="submit" id="runButton"><span data-run-idle><i class="fas fa-play me-1" aria-hidden="true"></i>Run Report</span><span class="d-none" data-run-busy role="status"><span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Generating...</span></button>
                            <button class="btn btn-outline-secondary text-nowrap" type="submit" formaction="?mode=print&amp;id=<?= $id ?>" formtarget="_blank"><i class="fas fa-print me-1" aria-hidden="true"></i>Print View<span class="visually-hidden"> (opens in a new tab)</span></button>
                            <button class="btn btn-outline-secondary text-nowrap" type="submit" formaction="?mode=export&amp;id=<?= $id ?>"><i class="fas fa-file-csv me-1" aria-hidden="true"></i>Export CSV</button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if ($result === null): ?>
                <div class="card"><div class="card-body text-center text-muted py-5"><i class="fas fa-table fa-2x mb-3 d-block" aria-hidden="true"></i>Set any filters above and choose <strong>Run Report</strong>.</div></div>
            <?php elseif ($result['errors'] !== []): ?>
                <div class="alert alert-danger" role="alert" data-a11y-focus="true"><ul class="mb-0"><?php foreach ($result['errors'] as $error): ?><li><?= report_h($error) ?></li><?php endforeach; ?></ul></div>
            <?php else: ?>
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center"><span><i class="fas fa-table me-2" aria-hidden="true"></i>Results</span><span class="text-muted small"><?= number_format((int)$result['row_count']) ?> row<?= (int)$result['row_count'] === 1 ? '' : 's' ?><?= $result['limit_reached'] ? ' (row limit reached)' : '' ?></span></div>
                    <div class="card-body p-0 table-responsive report-viewer-scroll"><?php report_render_table($result); ?></div>
                </div>
                <?php if ($canManage): ?><details class="mt-3 small text-muted report-screen-only report-sql"><summary>Generated SQL</summary><pre class="mt-2 p-2 bg-body-tertiary rounded"><code><?= report_h($result['sql']) ?></code></pre></details><?php endif; ?>
            <?php endif; ?>

            <script>
            (() => {
                'use strict';
                const multiSelects = document.querySelectorAll('.report-multiselect');
                multiSelects.forEach(container => {
                    const toggle = container.querySelector('.report-multiselect-toggle');
                    const summary = container.querySelector('.selection-summary');
                    const search = container.querySelector('.option-search');
                    const list = container.querySelector('.report-multiselect-options');
                    const status = container.querySelector('.option-status');
                    const select = container.querySelector('.option-values');
                    const clear = container.querySelector('.option-clear');
                    const selected = new Set([...select.selectedOptions].map(option => option.value));
                    let requestNumber = 0;
                    let searchTimer = null;
                    let optionsLoaded = false;

                    const syncSelection = () => {
                        select.innerHTML = '';
                        if (selected.size === 0) select.add(new Option('', '', true, true));
                        [...selected].sort((left, right) => left.localeCompare(right, undefined, {sensitivity:'base'})).forEach(value => {
                            const option = new Option(value, value, true, true);
                            select.add(option);
                        });
                        summary.textContent = selected.size === 0 ? 'Any' : (selected.size === 1 ? [...selected][0] : `${selected.size} selected`);
                        toggle.setAttribute('aria-invalid', 'false');
                    };

                    const renderOptions = values => {
                        list.innerHTML = '';
                        const unique = [...new Set(values.map(String))].sort((left, right) => left.localeCompare(right, undefined, {sensitivity:'base'}));
                        if (unique.length === 0) { list.innerHTML = '<div class="small text-muted p-2">No matching options.</div>'; return; }
                        unique.forEach((value, optionIndex) => {
                            const id = `f${container.dataset.filter}-option-${optionIndex}`;
                            const label = document.createElement('label');
                            label.className = 'dropdown-item d-flex align-items-start gap-2 rounded py-1';
                            label.htmlFor = id;
                            const checkbox = document.createElement('input');
                            checkbox.className = 'form-check-input report-option-check mt-1';
                            checkbox.type = 'checkbox';
                            checkbox.id = id;
                            checkbox.checked = selected.has(value);
                            checkbox.addEventListener('change', () => { if (checkbox.checked) selected.add(value); else selected.delete(value); syncSelection(); });
                            const text = document.createElement('span');
                            text.textContent = value;
                            label.append(checkbox, text);
                            list.appendChild(label);
                        });
                    };

                    const loadOptions = async () => {
                        const currentRequest = ++requestNumber;
                        status.textContent = 'Loading...';
                        try {
                            const separator = container.dataset.optionsUrl.includes('?') ? '&' : '?';
                            const response = await fetch(container.dataset.optionsUrl + separator + 'q=' + encodeURIComponent(search.value), {headers:{Accept:'application/json'}});
                            const data = await response.json();
                            if (!response.ok) throw new Error(data.errors?.join(' ') || 'Options could not be loaded.');
                            if (currentRequest !== requestNumber) return;
                            const values = Array.isArray(data.values) ? data.values : [];
                            renderOptions(values);
                            optionsLoaded = true;
                            status.textContent = data.truncated ? 'Keep typing to narrow the results.' : `${values.length} option${values.length === 1 ? '' : 's'}`;
                        } catch (error) {
                            if (currentRequest !== requestNumber) return;
                            list.innerHTML = '';
                            status.textContent = error.message || 'Options could not be loaded.';
                        }
                    };

                    container.addEventListener('shown.bs.dropdown', () => { search.focus(); if (!optionsLoaded) loadOptions(); });
                    search.addEventListener('input', () => { window.clearTimeout(searchTimer); searchTimer = window.setTimeout(loadOptions, 200); });
                    search.addEventListener('keydown', event => { if (event.key === 'Enter') event.preventDefault(); });
                    clear.addEventListener('click', () => { selected.clear(); list.querySelectorAll('input[type="checkbox"]').forEach(checkbox => checkbox.checked = false); syncSelection(); });
                    syncSelection();
                });

                const form = document.getElementById('runReport');
                const runButton = document.getElementById('runButton');
                const resetRunButton = () => {
                    runButton.disabled = false;
                    runButton.removeAttribute('aria-busy');
                    runButton.querySelector('[data-run-idle]').classList.remove('d-none');
                    runButton.querySelector('[data-run-busy]').classList.add('d-none');
                };
                form.addEventListener('submit', event => {
                    for (const container of multiSelects) {
                        const select = container.querySelector('.option-values');
                        const hasValue = [...select.selectedOptions].some(option => option.value.trim() !== '');
                        if (select.dataset.required === '1' && !hasValue) {
                            event.preventDefault();
                            const toggle = container.querySelector('.report-multiselect-toggle');
                            toggle.setAttribute('aria-invalid', 'true');
                            container.querySelector('.option-status').textContent = 'Select at least one value.';
                            toggle.focus();
                            return;
                        }
                    }
                    if (event.submitter !== runButton) return;
                    runButton.disabled = true;
                    runButton.setAttribute('aria-busy', 'true');
                    runButton.querySelector('[data-run-idle]').classList.add('d-none');
                    runButton.querySelector('[data-run-busy]').classList.remove('d-none');
                });
                window.addEventListener('pageshow', resetRunButton);
            })();
            </script>
        <?php endif; ?>
    </div>
<?php else: http_response_code(404); ?><div class="alert alert-danger">Unknown report page.</div><?php endif; ?>

<?php $theme->showFooter($context); ?>
