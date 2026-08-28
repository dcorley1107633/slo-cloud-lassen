<?php
declare(strict_types=1);

use SLOCloud\Reporting\ReportCatalogBuilder;
use SLOCloud\Reporting\ReportDefinitionValidator;
use SLOCloud\Reporting\ReportEngine;
use SLOCloud\Reporting\ReportStore;

require_once __DIR__ . '/../src/system/reporting/bootstrap.php';

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$builder = new ReportCatalogBuilder();
$catalog = $builder->fromDdl(__DIR__ . '/../src/system/database/schema.sql', 'slocloud_tbl_');
$check($catalog['stats']['tables'] >= 10, 'MySQL DDL catalog should contain business tables.');
$check(!isset($catalog['tables']['users']), 'Authentication tables must be excluded.');
$check(!isset($catalog['tables']['students']['fields']['student_id_hash']), 'Sensitive hashes must be excluded.');
$check(!isset($catalog['tables']['students']['fields']['student_data_encrypted']), 'Encrypted student data must be excluded.');
$check($catalog['tables']['assessments']['fields']['assessments_pk']['label'] === 'Assessments ID', 'Primary-key suffixes should render as ID.');
$check($catalog['tables']['assessments']['fields']['enrollment_fk']['label'] === 'Enrollment ID', 'Foreign-key suffixes should render as ID.');

$overrideBuilder = new ReportCatalogBuilder(['payments.amount' => 'decimal']);
$overrideCatalog = $overrideBuilder->assemble([
    'slocloud_tbl_payments' => ['primary_key' => ['payments_pk'], 'fields' => [
        'payments_pk' => ['native_type' => 'int', 'nullable' => false],
        'amount' => ['native_type' => 'varchar(30)', 'nullable' => false],
    ]],
], [], 'test', 'slocloud_tbl_');
$check($overrideCatalog['tables']['payments']['fields']['amount']['type'] === 'decimal', 'Per-column type overrides should replace misleading native types.');

$sqlServerCatalog = $builder->fromDdl(__DIR__ . '/../src/system/database/schema_mssql.sql', 'slocloud_tbl_');
$check($sqlServerCatalog['stats']['tables'] >= 10, 'SQL Server DDL catalog should contain business tables.');

$validator = new ReportDefinitionValidator($catalog);
$seedDefinitions = [];
foreach (glob(__DIR__ . '/../src/system/reporting/seeds/*.json') ?: [] as $seedPath) {
    $seedDefinition = json_decode((string)file_get_contents($seedPath), true, 512, JSON_THROW_ON_ERROR);
    $seedPrepared = $validator->prepare($seedDefinition);
    $check($seedPrepared['errors'] === [], basename($seedPath) . ' should validate: ' . implode('; ', $seedPrepared['errors']));
    $check(($seedDefinition['base_table'] ?? '') === 'terms', basename($seedPath) . ' should use Terms as its base table.');
    $seedDefinitions[basename($seedPath)] = $seedDefinition;
}
$check(count($seedDefinitions) >= 2, 'Reporting should include multiple example definitions.');
$check(isset($seedDefinitions['institutional_outcome_alignment.json']), 'Starter reports should include institutional outcome alignment.');
$check(isset($seedDefinitions['unmapped_cslo_inventory.json']), 'Starter reports should include an unmapped CSLO inventory.');
$check(isset($seedDefinitions['assessment_participation_by_course.json']), 'Starter reports should include assessment participation by course.');
$check(isset($seedDefinitions['improvement_strategy_evidence.json']), 'Starter reports should include improvement strategy evidence.');
$seed = $seedDefinitions['assessment_outcomes.json'];
$prepared = $validator->prepare($seed);

$attack = $seed;
$attack['base_table'] = 'assessments; DROP TABLE slocloud_tbl_users';
$attackResult = $validator->prepare($attack);
$check($attackResult['errors'] !== [], 'Unknown/injected identifiers must fail validation.');

$definition = $prepared['definition'];
$definition['filters'][0]['required'] = true;
$engine = new ReportEngine(null, 'mysql', 'slocloud_tbl_', $catalog);
$query = $engine->buildQuery($definition, ['f0' => '2026-01-01', 'f0b' => '2026-07-18']);
$check($query['errors'] === [], 'A complete required range should build.');
$check(str_contains($query['sql'], ' BETWEEN ? AND ?'), 'Runtime range values must use placeholders.');
$check(!str_contains($query['sql'], '2026-01-01'), 'Runtime values must never appear in SQL.');
$check($query['params'] === ['2026-01-01', '2026-07-18'], 'Range values should remain bound parameters.');
$check(str_starts_with($query['sql'], 'SELECT '), 'MySQL query should generate SELECT.');
$check(str_ends_with($query['sql'], 'LIMIT 500'), 'MySQL query should always have a bounded limit.');

$containsDefinition = $definition;
$containsDefinition['filters'] = [[
    'table' => 'courses', 'field' => 'course_name', 'operator' => 'contains', 'control' => 'textbox',
    'label' => '', 'required' => false, 'default' => '', 'default_to' => '',
]];
$containsQuery = $engine->buildQuery($containsDefinition, ['f0' => '100%_[ready]!']);
$check($containsQuery['params'] === ['%100!%!_[ready]!!%'], 'MySQL LIKE wildcards and the escape character must be escaped.');
$check(str_contains($containsQuery['sql'], "ESCAPE '!'"), 'LIKE predicates should declare the portable escape character.');
$sqlServerContains = (new ReportEngine(null, 'mssql', 'slocloud_tbl_', $catalog))->buildQuery($containsDefinition, ['f0' => '100%_[ready]!']);
$check($sqlServerContains['params'] === ['%100!%!_![ready]!!%'], 'SQL Server LIKE patterns must also escape the left-bracket wildcard.');

$stringNotEqualsDefinition = $containsDefinition;
$stringNotEqualsDefinition['filters'][0]['operator'] = 'not_equals';
$stringNotEqualsQuery = $engine->buildQuery($stringNotEqualsDefinition, ['f0' => 'Archived']);
$check(str_contains($stringNotEqualsQuery['sql'], "<> ? OR `slocloud_tbl_courses`.`course_name` IS NULL OR `slocloud_tbl_courses`.`course_name` = ''"), 'String not-equals filters should keep null and blank values.');
$check($stringNotEqualsQuery['params'] === ['Archived'], 'String not-equals filters should bind exactly one parameter.');

$intNotEqualsDefinition = $definition;
$intNotEqualsDefinition['filters'] = [[
    'table' => 'assessments', 'field' => 'enrollment_fk', 'operator' => 'not_equals', 'control' => 'textbox',
    'label' => '', 'required' => false, 'default' => '', 'default_to' => '',
]];
$intNotEqualsQuery = $engine->buildQuery($intNotEqualsDefinition, ['f0' => '42']);
$check(str_contains($intNotEqualsQuery['sql'], '(`slocloud_tbl_assessments`.`enrollment_fk` <> ? OR `slocloud_tbl_assessments`.`enrollment_fk` IS NULL)'), 'Integer not-equals filters should keep null values without a blank-string predicate.');
$check($intNotEqualsQuery['params'] === ['42'], 'Integer not-equals filters should bind exactly one parameter.');

$dateTimeCatalog = $catalog;
$dateTimeCatalog['tables']['assessments']['fields']['updated_at']['type'] = 'datetime';
$dateTimeDefinition = $definition;
$dateTimeDefinition['filters'] = [[
    'table' => 'assessments', 'field' => 'updated_at', 'operator' => 'between', 'control' => 'textbox',
    'label' => '', 'required' => false, 'default' => '', 'default_to' => '',
]];
$dateQuery = (new ReportEngine(null, 'mysql', 'slocloud_tbl_', $dateTimeCatalog))->buildQuery($dateTimeDefinition, ['f0' => '', 'f0b' => '2026-07-18']);
$check($dateQuery['params'] === ['2026-07-19'], 'Bare datetime upper dates should use the next day as an exclusive bound.');
$check(str_contains($dateQuery['sql'], ' < ?'), 'Bare datetime upper dates should use an exclusive predicate.');

$mssqlQuery = (new ReportEngine(null, 'mssql', 'slocloud_tbl_', $catalog))->buildQuery($definition, ['f0' => '2026-01-01', 'f0b' => '2026-07-18']);
$check(str_starts_with($mssqlQuery['sql'], 'SELECT TOP (500)'), 'SQL Server should use TOP for the hard row bound.');
$check(!str_contains($mssqlQuery['sql'], ' LIMIT '), 'SQL Server query must not use LIMIT.');

$rows = [
    ['c0' => 'A', 'c1' => 'met', 'c2' => 2],
    ['c0' => 'A', 'c1' => 'not_met', 'c2' => 1],
    ['c0' => 'B', 'c1' => 'met', 'c2' => 4],
];
$display = $engine->postProcess($rows, $query['column_meta'], true);
$kinds = array_column($display, 'kind');
$check($kinds === ['data', 'data', 'subtotal', 'data', 'subtotal', 'total'], 'Break rows should close before the next group and end with a total.');
$check(end($display)['cells']['c2'] === 7.0, 'Grand total should sum summarized values.');

$nestedMeta = [
    ['alias' => 'c0', 'label' => 'Course', 'break' => true, 'summarize' => false],
    ['alias' => 'c1', 'label' => 'Outcome', 'break' => true, 'summarize' => false],
    ['alias' => 'c2', 'label' => 'Count', 'break' => false, 'summarize' => true],
];
$nestedRows = [['c0' => 'A', 'c1' => 'met', 'c2' => 1], ['c0' => 'A', 'c1' => 'not_met', 'c2' => 2], ['c0' => 'B', 'c1' => 'met', 'c2' => 3]];
$nestedDisplay = $engine->postProcess($nestedRows, $nestedMeta, true);
$check(array_column($nestedDisplay, 'kind') === ['data', 'subtotal', 'data', 'subtotal', 'subtotal', 'data', 'subtotal', 'subtotal', 'total'], 'Nested breaks should close inner groups before outer groups.');
$check(end($nestedDisplay)['cells']['c2'] === 6.0, 'Nested-break grand totals should remain correct.');

$enumFilter = ['operator' => 'equals', 'control' => 'textbox', 'required' => false];
$enumField = $catalog['tables']['assessments']['fields']['achievement_level'];
$enumHtml = report_filter_input_html($enumFilter, $enumField, $catalog['types']['enum'], 0, 'met', '');
$check(str_contains($enumHtml, '<select') && str_contains($enumHtml, 'value="met" selected'), 'Enum textbox prompts should render catalog options as a select.');
$numericInHtml = report_filter_input_html(['operator' => 'in', 'control' => 'textbox', 'required' => false], ['type' => 'int'], $catalog['types']['int'], 1, '1, 2', '');
$check(str_contains($numericInHtml, 'type="text"') && str_contains($numericInHtml, 'comma, separated, values'), 'Numeric textbox IN prompts should accept comma-separated text.');
$boolHtml = report_filter_input_html(['operator' => 'equals', 'control' => 'textbox', 'required' => false], ['type' => 'bool', 'options' => ['0', '1']], $catalog['types']['bool'], 2, '1', '');
$check(str_contains($boolHtml, '>No</option>') && str_contains($boolHtml, '>Yes</option>'), 'Boolean prompts should render readable select options.');

$_SESSION['user_id'] = 7;
$_SESSION['is_emergency_admin'] = false;
$roleDb = static function (int $roleCount, int $adminCount): object {
    return new class($roleCount, $adminCount) {
        public function __construct(private int $roleCount, private int $adminCount) {}
        public function query(): object {
            return new class($this->roleCount, $this->adminCount) {
                public function __construct(private int $roleCount, private int $adminCount) {}
                public function fetch(): array { return ['role_count' => $this->roleCount, 'admin_count' => $this->adminCount]; }
            };
        }
    };
};
$check(report_user_can_manage($roleDb(0, 0), 'slocloud_tbl_') === false, 'Accounts without a global role must not receive management access.');
$check(report_user_can_manage($roleDb(1, 1), 'slocloud_tbl_') === true, 'Global administrators should receive management access.');

$pageSource = (string)file_get_contents(__DIR__ . '/../src/administration/dynamic_reports.php');
$check(str_contains($pageSource, 'position:sticky') && str_contains($pageSource, '--report-sticky-top'), 'Viewer table headers should stay pinned below sticky page chrome.');
$check(str_contains($pageSource, 'window.print()'), 'The print view should invoke the browser print dialog.');
$check(str_contains($pageSource, 'fputcsv'), 'CSV export should use the shared display-row stream.');

$check(report_csv_safe('=HYPERLINK("bad")') === "'=HYPERLINK(\"bad\")", 'CSV formulas must be neutralized.');
$check(report_csv_safe('-12.5') === '-12.5', 'Pure numeric negatives should remain numeric in CSV.');

$temporary = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'slocloud-report-test-' . bin2hex(random_bytes(4));
$store = new ReportStore($temporary);
$savedId = $store->save($definition);
$check($savedId === 1 && $store->find(1)['name'] === $definition['name'], 'File store should save and find a definition.');
$copy = $definition; $copy['id'] = 0; $copy['name'] .= ' Copy';
$check($store->save($copy) === 2, 'File store should allocate monotonically increasing IDs.');
$check($store->delete(1) && $store->find(1) === null, 'File store should delete exact report IDs.');
foreach (glob($temporary . DIRECTORY_SEPARATOR . '*') ?: [] as $path) unlink($path);
@unlink($temporary . DIRECTORY_SEPARATOR . '.sequence.lock');
rmdir($temporary);

if ($failures !== []) {
    fwrite(STDERR, "Reporting tests failed:\n - " . implode("\n - ", $failures) . PHP_EOL);
    exit(1);
}
fwrite(STDOUT, "Reporting tests passed.\n");
