<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/system/reporting/bootstrap.php';
require_once __DIR__ . '/../src/system/includes/schema_helpers.php';

// Build from the checked-in DDL: production catalogs are introspected from the
// live database and are not part of the source tree.
$catalog = (new SLOCloud\Reporting\ReportCatalogBuilder())->fromDdl(
    __DIR__ . '/../src/system/database/schema.sql',
    SLOCLOUD_SCHEMA_TABLE_TOKEN
);
$enumField = $catalog['tables']['assessments']['fields']['achievement_level'];
$enumControl = report_filter_input_html(
    ['operator' => 'equals', 'control' => 'textbox', 'required' => false],
    $enumField,
    $catalog['types']['enum'],
    0,
    'met',
    ''
);
$numericInControl = report_filter_input_html(
    ['operator' => 'in', 'control' => 'textbox', 'required' => false],
    ['type' => 'int'],
    $catalog['types']['int'],
    1,
    '1, 2',
    ''
);
$source = (string)file_get_contents(__DIR__ . '/../src/administration/dynamic_reports.php');
if (!preg_match('/const updateReportStickyOffset=.*?window\.addEventListener\(\x27resize\x27,updateReportStickyOffset,\{passive:true\}\);/s', $source, $stickyScript)) {
    throw new RuntimeException('Sticky-header script was not found.');
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><title>Report viewer fixture</title><style>
body{font:14px system-ui;margin:0}.app-header{position:sticky;top:0;height:52px;background:#173b68;color:#fff;z-index:10;display:flex;align-items:center;padding:0 16px}.content{padding:16px}.report-results{width:100%;table-layout:auto;border-collapse:collapse}.report-results th,.report-results td{border:1px solid #aaa;padding:6px}.report-results thead th{position:sticky;top:var(--report-sticky-top,0px);z-index:3;background:#fff;box-shadow:inset 0 -2px 0 #777}.report-cell-value{display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:3;overflow:hidden;max-width:24rem}.form-control,.form-select{display:block;margin:8px 0;padding:6px}
</style></head><body><header class="app-header">SLOCloud</header><main class="content"><h1>Report viewer fixture</h1><label for="f0">Achievement level</label><?= $enumControl ?><label for="f1">Assessment IDs</label><?= $numericInControl ?><table class="report-results"><thead><tr><th>Course</th><th>Description</th></tr></thead><tbody><?php for ($i=1; $i<=80; $i++): ?><tr><td>COURSE <?= $i ?></td><td><span class="report-cell-value"><?= str_repeat('Long assessment narrative ', 18) ?></span></td></tr><?php endfor; ?></tbody></table></main><script><?= $stickyScript[0] ?></script></body></html>
