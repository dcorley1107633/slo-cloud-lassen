<?php
declare(strict_types=1);

// Standalone browser fixture for the real visual-editor script. It deliberately
// avoids application bootstrap so UI behavior can be checked without a database.
$source = (string)file_get_contents(__DIR__ . '/../src/administration/dynamic_reports.php');
// Build from the checked-in DDL: production catalogs are introspected from the
// live database and are not part of the source tree.
require_once __DIR__ . '/../src/system/reporting/ReportCatalogBuilder.php';
require_once __DIR__ . '/../src/system/includes/schema_helpers.php';
$catalog = (new SLOCloud\Reporting\ReportCatalogBuilder())->fromDdl(
    __DIR__ . '/../src/system/database/schema.sql',
    SLOCLOUD_SCHEMA_TABLE_TOKEN
);
$definition = json_decode((string)file_get_contents(__DIR__ . '/../src/system/reporting/seeds/assessment_outcomes.json'), true, 512, JSON_THROW_ON_ERROR);
if (!preg_match('/<script>\s*(\(\(\)=>\{[\s\S]*?\}\)\(\);)\s*<\/script>/', $source, $match)) {
    throw new RuntimeException('Visual editor script was not found.');
}
$script = preg_replace('/const catalog=<\?= json_encode\(\$catalog,[\s\S]*?\) \?>;/', 'const catalog=' . json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';', $match[1]);
$script = preg_replace('/let def=<\?= json_encode\(\$definition,[\s\S]*?\) \?>;/', 'let def=' . json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ';', (string)$script);
$tables = '';
foreach ($catalog['tables'] as $key => $table) $tables .= '<option value="' . htmlspecialchars($key) . '">' . htmlspecialchars($table['label']) . '</option>';
?><!doctype html><html lang="en"><head><meta charset="utf-8"><title>Report editor fixture</title><style>
body{font:14px system-ui;margin:24px}.d-none{display:none}.report-builder-row{border:1px solid #bbb;padding:8px;margin:8px 0}.form-control,.form-select{padding:5px;margin:2px}button{padding:5px;margin:2px}.is-invalid{border-color:#c00}
</style></head><body><h1>Report editor fixture</h1><div id="pruneNotice" class="d-none"></div><form id="reportEditor"><input id="definitionJson" type="hidden"><label>Name <input id="reportName"></label><label>Description <textarea id="reportDescription"></textarea></label><label>Base table <select id="baseTable"><option value=""></option><?= $tables ?></select></label><label>Limit <input id="rowLimit" type="number"></label><label><input id="grandTotal" type="checkbox"> Grand total</label><h2>Joins</h2><div id="joinPanel"></div><h2>Columns</h2><button id="addColumn" type="button">Add column</button><div id="columnsPanel"></div><h2>Filters</h2><button id="addFilter" type="button">Add filter</button><div id="filtersPanel"></div><button type="submit">Serialize</button></form><script><?= $script ?></script></body></html>
