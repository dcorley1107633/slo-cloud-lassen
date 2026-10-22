<?php
declare(strict_types=1);

namespace SLOCloud\Reporting;

use PDO;
use RuntimeException;

/** Builds the report allowlist from live metadata or a checked-in DDL file. */
final class ReportCatalogBuilder
{
    /** Deployment-specific corrections for physical columns whose native type is misleading. */
    private const TYPE_OVERRIDES = [];

    private const EXCLUDED_TABLES = [
        'users', 'roles', 'user_roles', 'audit_log', 'error_log', 'security_log',
        'lti_nonces', 'lti_consumers', 'sessions', 'login_attempts', 'meta',
    ];

    /**
     * Transitional columns that remain reportable only so administrators can
     * identify retained migration exceptions. They are never relationships.
     */
    private const CLEANUP_FIELDS = [
        'enrollment.term_code' => 'Legacy Term Code (cleanup only)',
        'enrollment.crn' => 'Legacy CRN (cleanup only)',
        'enrollment.course_fk' => 'Legacy Course Key (cleanup only)',
    ];

    /**
     * Report fields derived from an encrypted JSON column. The ciphertext column
     * itself stays excluded (its name matches the sensitive pattern); these
     * entries are type `encrypted`, selected as the ciphertext in SQL, and
     * decoded in PHP by ReportEngine after the query runs. They can be shown,
     * exported, printed, and counted, never filtered, sorted, or grouped.
     */
    private const ENCRYPTED_FIELDS = [
        'students' => ['source' => 'student_data_encrypted', 'fields' => [
            'student_id' => ['label' => 'Student ID', 'key' => 'c_number'],
            'first_name' => ['label' => 'First Name', 'key' => 'first_name'],
            'last_name' => ['label' => 'Last Name', 'key' => 'last_name'],
        ]],
    ];

    private const CURATED_RELATIONSHIPS = [
        ['terms', 'created_by_fk', 'users', 'users_pk'], ['terms', 'updated_by_fk', 'users', 'users_pk'],
        ['institutional_outcomes', 'term_fk', 'terms', 'terms_pk'],
        ['disciplines', 'term_fk', 'terms', 'terms_pk'],
        ['programs', 'term_fk', 'terms', 'terms_pk'], ['programs', 'discipline_fk', 'disciplines', 'disciplines_pk'],
        ['program_outcomes', 'program_fk', 'programs', 'programs_pk'],
        ['program_outcomes', 'institutional_outcomes_fk', 'institutional_outcomes', 'institutional_outcomes_pk'],
        ['courses', 'term_fk', 'terms', 'terms_pk'], ['courses', 'discipline_fk', 'disciplines', 'disciplines_pk'],
        ['program_courses', 'program_fk', 'programs', 'programs_pk'], ['program_courses', 'course_fk', 'courses', 'courses_pk'],
        ['sections', 'course_fk', 'courses', 'courses_pk'], ['sections', 'term_fk', 'terms', 'terms_pk'],
        ['student_learning_outcomes', 'course_fk', 'courses', 'courses_pk'],
        ['student_learning_outcomes', 'program_outcomes_fk', 'program_outcomes', 'program_outcomes_pk'],
        ['enrollment', 'section_fk', 'sections', 'sections_pk'], ['enrollment', 'student_fk', 'students', 'students_pk'],
        ['section_xlist', 'term_fk', 'terms', 'terms_pk'],
        ['assessments', 'enrollment_fk', 'enrollment', 'enrollment_pk'],
        ['assessments', 'student_learning_outcome_fk', 'student_learning_outcomes', 'student_learning_outcomes_pk'],
        ['section_slo_methods', 'student_learning_outcome_fk', 'student_learning_outcomes', 'student_learning_outcomes_pk'],
    ];

    private const TABLE_LABELS = [
        'institutional_outcomes' => 'Institutional Outcomes',
        'program_outcomes' => 'Program Outcomes',
        'student_learning_outcomes' => 'Student Learning Outcomes',
        'section_slo_methods' => 'Section Assessment Methods',
        'section_xlist' => 'Cross-listed Sections',
    ];

    private array $typeOverrides;

    public function __construct(array $typeOverrides = [])
    {
        $this->typeOverrides = array_replace(self::TYPE_OVERRIDES, $typeOverrides);
        $validTypes = ['int', 'decimal', 'date', 'datetime', 'string', 'text', 'enum', 'bool'];
        foreach ($this->typeOverrides as $column => $type) {
            if (!is_string($column) || !is_string($type) || !in_array($type, $validTypes, true)) {
                throw new RuntimeException('Invalid report type override. Expected table.field => canonical type.');
            }
        }
    }

    public static function capabilities(): array
    {
        $numbers = ['equals', 'not_equals', 'gt', 'gte', 'lt', 'lte', 'between', 'in', 'is_null', 'not_null'];
        $numberAggregates = ['count', 'count_distinct', 'sum', 'avg', 'min', 'max'];
        $dates = ['between', 'equals', 'gte', 'lte', 'in', 'is_null', 'not_null'];
        return [
            'types' => [
                'int' => ['operators' => $numbers, 'aggregates' => $numberAggregates, 'input' => 'number'],
                'decimal' => ['operators' => $numbers, 'aggregates' => $numberAggregates, 'input' => 'number'],
                'date' => ['operators' => $dates, 'aggregates' => ['count', 'count_distinct', 'min', 'max'], 'input' => 'date'],
                'datetime' => ['operators' => $dates, 'aggregates' => ['count', 'count_distinct', 'min', 'max'], 'input' => 'date'],
                'string' => ['operators' => ['contains', 'not_contains', 'equals', 'not_equals', 'starts_with', 'in', 'is_null', 'not_null'], 'aggregates' => ['count', 'count_distinct', 'min', 'max'], 'input' => 'text'],
                'text' => ['operators' => ['contains', 'not_contains', 'is_null', 'not_null'], 'aggregates' => ['count'], 'input' => 'text'],
                'enum' => ['operators' => ['equals', 'not_equals', 'in'], 'aggregates' => ['count', 'count_distinct'], 'input' => 'select'],
                'bool' => ['operators' => ['equals', 'in'], 'aggregates' => ['count', 'sum'], 'input' => 'select'],
                // Decrypted after the query: display only, so no filter operators; counting the ciphertext is fine.
                'encrypted' => ['operators' => [], 'aggregates' => ['count', 'count_distinct'], 'input' => 'text'],
            ],
            'operators' => [
                'equals' => '=', 'not_equals' => 'Not equal to', 'gt' => 'Greater than', 'gte' => 'At least',
                'lt' => 'Less than', 'lte' => 'At most', 'between' => 'Between', 'in' => 'In',
                'contains' => 'Contains', 'not_contains' => 'Does not contain', 'starts_with' => 'Starts with',
                'is_null' => 'Is blank', 'not_null' => 'Is not blank',
            ],
            'aggregates' => [
                'count' => 'Count', 'count_distinct' => 'Count distinct', 'sum' => 'Sum',
                'avg' => 'Average', 'min' => 'Minimum', 'max' => 'Maximum',
            ],
        ];
    }

    public function fromLive(PDO $pdo, string $driver, string $prefix): array
    {
        $driver = strtolower($driver);
        if (in_array($driver, ['mssql', 'sqlsrv'], true)) {
            [$tables, $relationships] = $this->introspectSqlServer($pdo, $prefix);
        } else {
            [$tables, $relationships] = $this->introspectMySql($pdo, $prefix);
        }
        return $this->assemble($tables, $relationships, 'live:' . $driver, $prefix);
    }

    /**
     * Build the catalog from a DDL file (schema.sql / schema_mssql.sql).
     *
     * $prefix is the literal table-name token used in the DDL file
     * ('slocloud_tbl_', see SLOCLOUD_SCHEMA_TABLE_TOKEN in schema_helpers.php),
     * used to strip
     * names down to canonical form. It is NOT the install's configured
     * database.prefix - the catalog stores canonical (unprefixed) names and
     * ReportEngine applies the configured prefix at query time. For live
     * introspection of an installed database, use fromLive() with the
     * configured prefix instead.
     */
    public function fromDdl(string $path, string $prefix): array
    {
        $ddl = file_get_contents($path);
        if ($ddl === false) {
            throw new RuntimeException("Unable to read DDL file: {$path}");
        }
        [$tables, $relationships] = $this->parseDdl($ddl, $prefix);
        return $this->assemble($tables, $relationships, 'ddl:' . basename($path), $prefix);
    }

    public function write(array $catalog, string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create catalog directory: {$directory}");
        }
        @chmod($directory, 0700);
        if (!is_writable($directory)) {
            throw new RuntimeException("Catalog directory is not writable: {$directory}");
        }
        $json = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
        $temporary = tempnam($directory, 'catalog_');
        if ($temporary === false || file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write the report catalog.');
        }
        if (!@rename($temporary, $path)) {
            @unlink($path);
            if (!@rename($temporary, $path)) {
                @unlink($temporary);
                throw new RuntimeException('Unable to replace the report catalog.');
            }
        }
    }

    public function assemble(array $rawTables, array $declaredRelationships, string $source, string $prefix = ''): array
    {
        $catalogTables = [];
        $dropped = 0;
        foreach ($rawTables as $physical => $rawTable) {
            $logical = $this->logicalName((string)$physical, $prefix);
            if (in_array($logical, self::EXCLUDED_TABLES, true)) {
                $dropped += count($rawTable['fields'] ?? []);
                continue;
            }
            $fields = [];
            foreach (($rawTable['fields'] ?? []) as $fieldName => $field) {
                if ((string)$fieldName === 'is_active') {
                    $dropped++;
                    continue;
                }
                if (preg_match('/(secret|password|passwd|token|hash|salt|otp|api[_-]?key|signature|encrypted)/i', (string)$fieldName)) {
                    $dropped++;
                    continue;
                }
                $overrideKey = $logical . '.' . (string)$fieldName;
                $type = $this->typeOverrides[$overrideKey]
                    ?? $this->canonicalType((string)($field['native_type'] ?? ''));
                if ($type === null) {
                    $dropped++;
                    continue;
                }
                $fieldKey = $logical . '.' . (string)$fieldName;
                $entry = [
                    'label' => self::CLEANUP_FIELDS[$fieldKey] ?? $this->label((string)$fieldName),
                    'type' => $type,
                    'native_type' => strtolower((string)($field['native_type'] ?? '')),
                    'nullable' => (bool)($field['nullable'] ?? true),
                ];
                if (isset(self::CLEANUP_FIELDS[$fieldKey])) {
                    $entry['cleanup_only'] = true;
                }
                $options = $field['options'] ?? [];
                if ($type === 'bool') {
                    $entry['options'] = ['0', '1'];
                } elseif ($type === 'enum' && $options !== []) {
                    $entry['options'] = array_values($options);
                }
                $fields[(string)$fieldName] = $entry;
            }
            $encryptedSource = self::ENCRYPTED_FIELDS[$logical] ?? null;
            if ($encryptedSource !== null && isset($rawTable['fields'][$encryptedSource['source']])) {
                $derived = [];
                foreach ($encryptedSource['fields'] as $fieldName => $meta) {
                    $derived[$fieldName] = [
                        'label' => $meta['label'], 'type' => 'encrypted', 'native_type' => 'encrypted', 'nullable' => false,
                        'encrypted' => ['source' => $encryptedSource['source'], 'key' => $meta['key']],
                    ];
                }
                $fields = $derived + $fields;
            }
            if ($fields === []) {
                continue;
            }
            $primaryKey = array_values(array_filter(
                array_map(fn($name) => (string)$name, $rawTable['primary_key'] ?? []),
                static fn($name) => isset($fields[$name])
            ));
            $catalogTables[$logical] = [
                'label' => self::TABLE_LABELS[$logical] ?? $this->label($logical),
                'category' => $this->category($logical),
                'primary_key' => $primaryKey,
                'fields' => $fields,
            ];
        }

        $relationships = [];
        $append = function (array $rel, string $origin) use (&$relationships, $catalogTables, $prefix): void {
            [$fromTable, $fromField, $toTable, $toField] = $rel;
            $fromTable = $this->logicalName((string)$fromTable, $prefix);
            $toTable = $this->logicalName((string)$toTable, $prefix);
            if (!isset($catalogTables[$fromTable]['fields'][$fromField], $catalogTables[$toTable]['fields'][$toField])) {
                return;
            }
            if (($catalogTables[$fromTable]['fields'][$fromField]['cleanup_only'] ?? false)
                || ($catalogTables[$toTable]['fields'][$toField]['cleanup_only'] ?? false)) {
                return;
            }
            $id = "{$fromTable}.{$fromField}->{$toTable}.{$toField}";
            if (isset($relationships[$id])) {
                return;
            }
            $relationships[$id] = [
                'id' => $id, 'from_table' => $fromTable, 'from_field' => (string)$fromField,
                'to_table' => $toTable, 'to_field' => (string)$toField, 'source' => $origin,
                'label' => ($catalogTables[$fromTable]['label'] ?? $this->label($fromTable)) . ' → ' .
                    ($catalogTables[$toTable]['label'] ?? $this->label($toTable)) . " ({$fromField})",
            ];
        };
        foreach ($declaredRelationships as $relationship) {
            $append($relationship, 'fk');
        }
        foreach (self::CURATED_RELATIONSHIPS as $relationship) {
            $append($relationship, 'curated');
        }

        $capabilities = self::capabilities();
        ksort($catalogTables);
        return [
            'version' => 1,
            'generated_at' => gmdate('c'),
            'source' => $source,
            'stats' => ['tables' => count($catalogTables), 'relationships' => count($relationships), 'fields_dropped' => $dropped],
            'types' => $capabilities['types'],
            'operators' => $capabilities['operators'],
            'aggregates' => $capabilities['aggregates'],
            'tables' => $catalogTables,
            'relationships' => array_values($relationships),
        ];
    }

    private function introspectMySql(PDO $pdo, string $prefix): array
    {
        $tables = [];
        $statement = $pdo->prepare("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE ? ORDER BY TABLE_NAME");
        $statement->execute([$prefix . '%']);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $tables[$name] = ['fields' => [], 'primary_key' => []];
        }
        $columnStatement = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? ORDER BY TABLE_NAME, ORDINAL_POSITION");
        $columnStatement->execute([$prefix . '%']);
        foreach ($columnStatement->fetchAll() as $row) {
            $name = $row['TABLE_NAME'];
            if (!isset($tables[$name])) continue;
            $native = strtolower((string)$row['COLUMN_TYPE']);
            $options = [];
            if (str_starts_with($native, 'enum(') && preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $native, $matches)) {
                $options = array_map(static fn($v) => stripcslashes($v), $matches[1]);
            }
            $tables[$name]['fields'][$row['COLUMN_NAME']] = ['native_type' => $native, 'nullable' => $row['IS_NULLABLE'] === 'YES', 'options' => $options];
            if ($row['COLUMN_KEY'] === 'PRI') $tables[$name]['primary_key'][] = $row['COLUMN_NAME'];
        }
        $relationships = [];
        $fk = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND TABLE_NAME LIKE ? ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION");
        $fk->execute([$prefix . '%']);
        foreach ($fk->fetchAll() as $row) {
            $relationships[] = [$row['TABLE_NAME'], $row['COLUMN_NAME'], $row['REFERENCED_TABLE_NAME'], $row['REFERENCED_COLUMN_NAME']];
        }
        return [$tables, $relationships];
    }

    private function introspectSqlServer(PDO $pdo, string $prefix): array
    {
        $tables = [];
        $statement = $pdo->prepare("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME LIKE ? ORDER BY TABLE_NAME");
        $statement->execute([$prefix . '%']);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $name) $tables[$name] = ['fields' => [], 'primary_key' => []];
        $columns = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME LIKE ? ORDER BY TABLE_NAME, ORDINAL_POSITION");
        $columns->execute([$prefix . '%']);
        foreach ($columns->fetchAll() as $row) {
            if (isset($tables[$row['TABLE_NAME']])) {
                $tables[$row['TABLE_NAME']]['fields'][$row['COLUMN_NAME']] = ['native_type' => $row['DATA_TYPE'], 'nullable' => $row['IS_NULLABLE'] === 'YES'];
            }
        }
        $pk = $pdo->prepare("SELECT KU.TABLE_NAME, KU.COLUMN_NAME FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS TC JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE KU ON TC.CONSTRAINT_NAME = KU.CONSTRAINT_NAME AND TC.TABLE_SCHEMA = KU.TABLE_SCHEMA WHERE TC.CONSTRAINT_TYPE = 'PRIMARY KEY' AND KU.TABLE_NAME LIKE ? ORDER BY KU.TABLE_NAME, KU.ORDINAL_POSITION");
        $pk->execute([$prefix . '%']);
        foreach ($pk->fetchAll() as $row) if (isset($tables[$row['TABLE_NAME']])) $tables[$row['TABLE_NAME']]['primary_key'][] = $row['COLUMN_NAME'];
        $relationships = [];
        $fk = $pdo->prepare("SELECT FK.TABLE_NAME AS FROM_TABLE, CU.COLUMN_NAME AS FROM_FIELD, PK.TABLE_NAME AS TO_TABLE, PT.COLUMN_NAME AS TO_FIELD FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS C JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS FK ON C.CONSTRAINT_NAME = FK.CONSTRAINT_NAME JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS PK ON C.UNIQUE_CONSTRAINT_NAME = PK.CONSTRAINT_NAME JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE CU ON C.CONSTRAINT_NAME = CU.CONSTRAINT_NAME JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE PT ON C.UNIQUE_CONSTRAINT_NAME = PT.CONSTRAINT_NAME AND CU.ORDINAL_POSITION = PT.ORDINAL_POSITION WHERE FK.TABLE_NAME LIKE ?");
        $fk->execute([$prefix . '%']);
        foreach ($fk->fetchAll() as $row) $relationships[] = [$row['FROM_TABLE'], $row['FROM_FIELD'], $row['TO_TABLE'], $row['TO_FIELD']];
        return [$tables, $relationships];
    }

    private function parseDdl(string $ddl, string $prefix): array
    {
        $tables = [];
        $relationships = [];
        if (!preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:\[?\w+\]?\.)?[`\[]?([a-zA-Z0-9_]+)[`\]]?\s*\((.*?)\)\s*(?:ENGINE|;|GO)/is', $ddl, $matches, PREG_SET_ORDER)) {
            throw new RuntimeException('No CREATE TABLE statements were found in the DDL.');
        }
        foreach ($matches as $match) {
            $table = $match[1];
            if ($prefix !== '' && !str_starts_with($table, $prefix)) continue;
            $tables[$table] = ['fields' => [], 'primary_key' => []];
            $body = $match[2];
            foreach (preg_split('/\R/', $body) ?: [] as $line) {
                $line = trim($line, " \t\r\n,");
                if ($line === '' || preg_match('/^(?:CONSTRAINT|FOREIGN\s+KEY|UNIQUE|INDEX|KEY\s|PRIMARY\s+KEY)/i', $line)) {
                    if (preg_match('/FOREIGN\s+KEY\s*\([`\[]?([\w]+)[`\]]?\)\s+REFERENCES\s+[`\[]?([\w]+)[`\]]?\s*\([`\[]?([\w]+)[`\]]?\)/i', $line, $fk)) {
                        $relationships[] = [$table, $fk[1], $fk[2], $fk[3]];
                    }
                    if (preg_match('/PRIMARY\s+KEY\s*\(([^)]+)\)/i', $line, $pk)) {
                        $tables[$table]['primary_key'] = array_map(static fn($v) => trim($v, " `[]"), explode(',', $pk[1]));
                    }
                    continue;
                }
                if (!preg_match('/^[`\[]?([a-zA-Z0-9_]+)[`\]]?\s+([a-zA-Z]+(?:\s*\([^)]*\))?)/i', $line, $column)) continue;
                $name = $column[1];
                $native = strtolower(preg_replace('/\s+/', '', $column[2]) ?? $column[2]);
                $options = [];
                if (str_starts_with($native, 'enum(') && preg_match_all("/'([^']*)'/", $native, $enum)) $options = $enum[1];
                $tables[$table]['fields'][$name] = ['native_type' => $native, 'nullable' => !preg_match('/\bNOT\s+NULL\b/i', $line), 'options' => $options];
                if (preg_match('/\bPRIMARY\s+KEY\b/i', $line)) $tables[$table]['primary_key'][] = $name;
            }
        }
        return [$tables, $relationships];
    }

    private function canonicalType(string $native): ?string
    {
        $native = strtolower(trim($native));
        if (preg_match('/\b(json|jsonb|blob|binary|varbinary|image|xml|geography|geometry)\b/', $native)) return null;
        if (str_starts_with($native, 'enum')) return 'enum';
        if (preg_match('/\b(bit|boolean|bool)\b/', $native) || preg_match('/tinyint\s*\(\s*1\s*\)/', $native)) return 'bool';
        if (preg_match('/\b(datetime|timestamp|datetime2|smalldatetime|datetimeoffset)\b/', $native)) return 'datetime';
        if (preg_match('/^date\b/', $native)) return 'date';
        if (preg_match('/\b(decimal|numeric|float|double|real|money|smallmoney)\b/', $native)) return 'decimal';
        if (preg_match('/\b(tinyint|smallint|mediumint|bigint|integer|int|year)\b/', $native)) return 'int';
        if (preg_match('/\b(text|ntext|clob)\b/', $native)) return 'text';
        if (preg_match('/\b(char|varchar|nvarchar|nchar|uniqueidentifier)\b/', $native)) return 'string';
        return null;
    }

    private function logicalName(string $physical, string $prefix): string
    {
        return $prefix !== '' && str_starts_with($physical, $prefix) ? substr($physical, strlen($prefix)) : $physical;
    }

    private function label(string $name): string
    {
        $abbreviations = ['id', 'pk', 'fk', 'isbn', 'sku', 'ip', 'po', 'url', 'slo', 'cslo', 'pslo', 'islo', 'crn'];
        $parts = array_map(static function (string $part) use ($abbreviations): string {
            $part = strtolower($part);
            if ($part === 'id') return 'ID';
            // Internal row keys read as "Key" so they are never mistaken for a
            // business identifier such as the decrypted Student ID.
            if ($part === 'pk' || $part === 'fk') return 'Key';
            if (in_array($part, $abbreviations, true)) return strtoupper($part);
            return ucfirst($part);
        }, explode('_', $name));
        return implode(' ', $parts);
    }

    private function category(string $table): string
    {
        if (str_contains($table, 'outcome')) return 'Outcomes';
        if (in_array($table, ['terms', 'disciplines', 'programs', 'program_courses', 'courses', 'sections', 'section_xlist'], true)) return 'Curriculum';
        if (in_array($table, ['students', 'enrollment'], true)) return 'Enrollment';
        if (str_contains($table, 'assessment') || str_contains($table, 'slo_methods')) return 'Assessment';
        return 'Other';
    }
}
