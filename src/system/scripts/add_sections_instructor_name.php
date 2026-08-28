#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../Core/Config.php';
require_once __DIR__ . '/../Core/Database.php';

use SLOCloud\Core\Config;
use SLOCloud\Core\Database;

$config = Config::getInstance(__DIR__ . '/../../config/config.yaml')->allDecrypted();
$databaseConfig = $config['database'] ?? [];
$prefix = (string)($databaseConfig['prefix'] ?? '');

if (preg_match('/^[A-Za-z0-9_]*$/', $prefix) !== 1) {
    fwrite(STDERR, "Invalid database table prefix.\n");
    exit(1);
}

$table = $prefix . 'sections';
$db = Database::getInstance($databaseConfig);
$pdo = $db->getConnection();
$driver = $db->getDriver();

if ($driver === 'mssql' || $driver === 'sqlsrv') {
    $qualifiedTable = 'dbo.' . $table;
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS '
        . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $statement->execute(['dbo', $table, 'instructor_name']);
    if ((int)$statement->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE {$qualifiedTable} ADD instructor_name NVARCHAR(255) NULL");
        fwrite(STDOUT, "Added {$qualifiedTable}.instructor_name.\n");
    } else {
        fwrite(STDOUT, "{$qualifiedTable}.instructor_name already exists.\n");
    }
} else {
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $statement->execute([$table, 'instructor_name']);
    if ((int)$statement->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN instructor_name VARCHAR(255) NULL");
        fwrite(STDOUT, "Added {$table}.instructor_name.\n");
    } else {
        fwrite(STDOUT, "{$table}.instructor_name already exists.\n");
    }
}

