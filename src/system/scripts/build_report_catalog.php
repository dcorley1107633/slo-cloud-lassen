<?php
declare(strict_types=1);

use SLOCloud\Reporting\ReportCatalogBuilder;
use SLOCloud\Core\Security;

require_once __DIR__ . '/../reporting/ReportCatalogBuilder.php';
require_once __DIR__ . '/../includes/schema_helpers.php';
require_once __DIR__ . '/../Core/Security.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// DEVELOPMENT / TEST TOOLING ONLY.
// Production catalogs are introspected from the live database (at the end of
// setup, and on Refresh catalog) and stored in var/reports/catalog.json. A
// DDL-derived catalog is single-dialect and cannot reflect an install's
// customizations, so it must never be deployed as an install's catalog.
$ddl = $argv[1] ?? (__DIR__ . '/../database/schema.sql');
$output = $argv[2] ?? (Security::dataRoot(dirname(__DIR__, 2)) . '/var/reports/catalog.reference.json');
// The prefix argument is the literal table-name token in the DDL file (NOT the
// install's configured database.prefix). The shipped schema files always use
// SLOCLOUD_SCHEMA_TABLE_TOKEN; only override when parsing a DDL dump whose
// table names carry a different literal prefix.
$prefix = $argv[3] ?? SLOCLOUD_SCHEMA_TABLE_TOKEN;

try {
    $builder = new ReportCatalogBuilder();
    $catalog = $builder->fromDdl($ddl, $prefix);
    $builder->write($catalog, $output);
    fwrite(STDOUT, sprintf(
        "Report catalog written to %s (%d tables, %d relationships, %d fields dropped).%s",
        $output,
        $catalog['stats']['tables'],
        $catalog['stats']['relationships'],
        $catalog['stats']['fields_dropped'],
        PHP_EOL
    ));
} catch (Throwable $error) {
    fwrite(STDERR, 'Catalog generation failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
