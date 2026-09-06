<?php
declare(strict_types=1);

namespace SLOCloud\Reporting;

use PDO;
use Throwable;

final class ReportEngine
{
    private const AGGREGATES = [
        'count' => 'COUNT', 'count_distinct' => 'COUNT_DISTINCT', 'sum' => 'SUM',
        'avg' => 'AVG', 'min' => 'MIN', 'max' => 'MAX',
    ];

    /** @var array<string,int> normalized ReportSettings */
    private readonly array $settings;

    /** @var \Closure|null maps an encrypted catalog field's ciphertext to its decoded key/value array */
    private readonly ?\Closure $decryptor;

    /**
     * @param array         $settings  Tunable limits from the `reports` config section
     *                                 (see ReportSettings::DEFAULTS). Anything missing
     *                                 falls back to the documented default.
     * @param callable|null $decryptor Decoder for catalog fields of type `encrypted`
     *                                 (string ciphertext => array). Null means such
     *                                 columns cannot be produced and a run says so.
     */
    public function __construct(
        private readonly ?PDO $pdo,
        private readonly string $driver,
        private readonly string $prefix,
        private readonly array $catalog,
        array $settings = [],
        ?callable $decryptor = null
    ) {
        $this->settings = ReportSettings::normalize($settings);
        $this->decryptor = $decryptor === null ? null : \Closure::fromCallable($decryptor);
    }

    public function run(array $rawDefinition, array $values): array
    {
        if ($this->pdo === null) return ['errors' => ['A database connection is required to run reports.']];
        $prepared = (new ReportDefinitionValidator($this->catalog, $this->settings))->prepare($rawDefinition);
        if ($prepared['errors'] !== []) {
            return ['errors' => array_merge($prepared['errors'], ['This saved report no longer matches the current catalog. Open it in the editor and re-save it to repair the report.'])];
        }
        $query = $this->buildQuery($prepared['definition'], $values);
        if ($query['errors'] !== []) return $query;
        $encryptedColumns = array_values(array_map(
            static fn(array $column): string => $column['label'],
            array_filter($query['column_meta'], static fn(array $column): bool => $column['encrypted'] !== null)
        ));
        if ($encryptedColumns !== [] && $this->decryptor === null) {
            return ['errors' => ['This report includes decrypted student fields, but student data decryption is not configured on this server.']];
        }
        try {
            $statement = $this->pdo->prepare($query['sql']);
            $statement->execute($query['params']);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $error) {
            error_log('Custom report query failed: ' . $error->getMessage());
            return ['errors' => ['The report query could not be completed. Check the report definition or contact an administrator.']];
        }
        $rows = $this->decryptRows($rows, $query['column_meta']);
        $displayRows = $this->postProcess($rows, $query['column_meta'], (bool)$prepared['definition']['options']['grand_total']);
        return [
            'errors' => [], 'column_meta' => $query['column_meta'], 'display_rows' => $displayRows,
            'row_count' => count($rows), 'row_limit' => $prepared['definition']['options']['row_limit'],
            'limit_reached' => count($rows) >= $prepared['definition']['options']['row_limit'],
            'sql' => $query['sql'], 'params' => $query['params'],
            'encrypted_columns' => $encryptedColumns,
        ];
    }

    /**
     * Replace the ciphertext cells of encrypted catalog fields with the decoded value.
     *
     * Decryption happens here, after the query, which is why such fields can be
     * displayed, exported, printed, and counted but never filtered, sorted, or
     * grouped in SQL. Each distinct ciphertext is decoded once per run; a cell
     * that fails to decode shows "(unreadable)" and the failure is logged.
     */
    public function decryptRows(array $rows, array $columnMeta): array
    {
        $targets = [];
        foreach ($columnMeta as $column) if (($column['encrypted'] ?? null) !== null) $targets[$column['alias']] = $column['encrypted'];
        if ($targets === [] || $rows === []) return $rows;
        if ($this->decryptor === null) throw new \RuntimeException('Student data decryption is not configured.');
        $cache = [];
        foreach ($rows as &$row) {
            foreach ($targets as $alias => $key) {
                $ciphertext = $row[$alias] ?? null;
                if ($ciphertext === null || $ciphertext === '') { $row[$alias] = null; continue; }
                $ciphertext = (string)$ciphertext;
                if (!array_key_exists($ciphertext, $cache)) {
                    try {
                        $cache[$ciphertext] = ($this->decryptor)($ciphertext);
                    } catch (Throwable $error) {
                        error_log('Custom report could not decrypt a student record: ' . $error->getMessage());
                        $cache[$ciphertext] = null;
                    }
                }
                $decoded = $cache[$ciphertext];
                $row[$alias] = $decoded === null ? '(unreadable)' : (string)($decoded[$key] ?? '');
            }
        }
        unset($row);
        return $rows;
    }

    public function buildQuery(array $definition, array $values): array
    {
        $errors = [];
        $isSqlServer = in_array(strtolower($this->driver), ['mssql', 'sqlsrv'], true);
        $limit = max(1, min($this->settings['max_row_limit'], (int)$definition['options']['row_limit']));
        $select = [];
        $groupBy = [];
        $columnMeta = [];
        $hasAggregate = false;
        foreach ($definition['columns'] as $index => $column) if ($column['aggregate'] !== '') $hasAggregate = true;
        foreach ($definition['columns'] as $index => $column) {
            $fieldMeta = $this->catalog['tables'][$column['table']]['fields'][$column['field']];
            // Encrypted catalog fields select their ciphertext column; decryptRows() decodes it after the fetch.
            $encrypted = $fieldMeta['encrypted'] ?? null;
            $expression = $this->qualified($column['table'], $encrypted !== null ? $encrypted['source'] : $column['field']);
            $aggregate = $column['aggregate'];
            if ($aggregate !== '') {
                $function = self::AGGREGATES[$aggregate];
                $expression = $function === 'COUNT_DISTINCT' ? "COUNT(DISTINCT {$expression})" : "{$function}({$expression})";
            } elseif ($hasAggregate) {
                $groupBy[] = $expression;
            }
            $alias = 'c' . $index;
            $select[] = $expression . ' AS ' . $this->quote($alias);
            $baseLabel = $this->catalog['tables'][$column['table']]['fields'][$column['field']]['label'];
            $label = $column['label'] !== '' ? $column['label'] : ($aggregate !== '' ? ($this->catalog['aggregates'][$aggregate] . ' of ' . $baseLabel) : $baseLabel);
            $numeric = $aggregate !== '' || in_array($fieldMeta['type'], ['int', 'decimal', 'bool'], true);
            $columnMeta[] = [
                'alias' => $alias, 'label' => $label, 'numeric' => $numeric,
                'money' => $fieldMeta['type'] === 'decimal' && !in_array($aggregate, ['count', 'count_distinct'], true),
                'break' => (bool)$column['break'], 'summarize' => (bool)$column['summarize'] && $numeric,
                'type' => $fieldMeta['type'], 'aggregate' => $aggregate,
                'encrypted' => $encrypted !== null && $aggregate === '' ? $encrypted['key'] : null,
            ];
        }
        $sql = 'SELECT ' . ($isSqlServer ? "TOP ({$limit}) " : '') . implode(', ', $select);
        $sql .= ' FROM ' . $this->quote($this->prefix . $definition['base_table']);
        $joined = [$definition['base_table'] => true];
        $relationshipMap = [];
        foreach ($this->catalog['relationships'] as $relationship) $relationshipMap[$relationship['id']] = $relationship;
        foreach ($definition['joins'] as $joinId) {
            $relationship = $relationshipMap[$joinId];
            $newTable = isset($joined[$relationship['from_table']]) ? $relationship['to_table'] : $relationship['from_table'];
            $sql .= ' LEFT JOIN ' . $this->quote($this->prefix . $newTable) . ' ON ';
            $sql .= $this->qualified($relationship['from_table'], $relationship['from_field']) . ' = ' . $this->qualified($relationship['to_table'], $relationship['to_field']);
            $joined[$relationship['from_table']] = $joined[$relationship['to_table']] = true;
        }
        [$where, $params, $filterErrors] = $this->filters($definition, $values);
        $errors = array_merge($errors, $filterErrors);
        $lifecycleEntities = function_exists('lifecycle_entities') ? lifecycle_entities() : [];
        foreach (array_keys($joined) as $table) {
            if (isset($lifecycleEntities[$table])) {
                $where[] = lifecycle_active_predicate($table, $this->prefix . $table, $this->prefix);
            }
        }
        if ($where !== []) $sql .= ' WHERE ' . implode(' AND ', $where);
        if ($groupBy !== []) $sql .= ' GROUP BY ' . implode(', ', $groupBy);
        $order = [];
        foreach ($definition['columns'] as $index => $column) {
            if ($column['break']) $order[] = $this->quote('c' . $index) . ' ' . strtoupper($column['sort'] ?: 'asc');
        }
        foreach ($definition['columns'] as $index => $column) {
            if (!$column['break'] && $column['sort'] !== '' && $columnMeta[$index]['encrypted'] === null) $order[] = $this->quote('c' . $index) . ' ' . strtoupper($column['sort']);
        }
        if ($order !== []) $sql .= ' ORDER BY ' . implode(', ', $order);
        if (!$isSqlServer) $sql .= ' LIMIT ' . $limit;
        return ['errors' => $errors, 'sql' => $sql, 'params' => $params, 'column_meta' => $columnMeta];
    }

    public function distinctValues(array $rawDefinition, int $filterIndex, string $term, ?int $limit = null): array
    {
        if ($this->pdo === null) return ['errors' => ['A database connection is required to load filter values.'], 'values' => [], 'truncated' => false];
        $prepared = (new ReportDefinitionValidator($this->catalog, $this->settings))->prepare($rawDefinition);
        if ($prepared['errors'] !== []) return ['errors' => $prepared['errors'], 'values' => [], 'truncated' => false];
        $definition = $prepared['definition'];
        if (!isset($definition['filters'][$filterIndex]) || $definition['filters'][$filterIndex]['control'] !== 'multi_select') {
            return ['errors' => ['The requested filter is not a multi-select filter.'], 'values' => [], 'truncated' => false];
        }
        $filter = $definition['filters'][$filterIndex];
        $column = $this->qualified($filter['table'], $filter['field']);
        $isSqlServer = in_array(strtolower($this->driver), ['mssql', 'sqlsrv'], true);
        $cap = max(1, min($this->settings['option_search_limit'], $limit ?? $this->settings['option_search_limit']));
        $fetch = $cap + 1;
        $sql = 'SELECT DISTINCT ' . ($isSqlServer ? "TOP ({$fetch}) " : '') . $column . ' AS option_value FROM ' . $this->quote($this->prefix . $definition['base_table']);
        $relationshipMap = [];
        foreach ($this->catalog['relationships'] as $relationship) $relationshipMap[$relationship['id']] = $relationship;
        $joined = [$definition['base_table'] => true];
        foreach ($definition['joins'] as $joinId) {
            $relationship = $relationshipMap[$joinId];
            $newTable = isset($joined[$relationship['from_table']]) ? $relationship['to_table'] : $relationship['from_table'];
            $sql .= ' LEFT JOIN ' . $this->quote($this->prefix . $newTable) . ' ON ' . $this->qualified($relationship['from_table'], $relationship['from_field']) . ' = ' . $this->qualified($relationship['to_table'], $relationship['to_field']);
            $joined[$relationship['from_table']] = $joined[$relationship['to_table']] = true;
        }
        $params = [];
        $sql .= " WHERE {$column} IS NOT NULL";
        $lifecycleEntities = function_exists('lifecycle_entities') ? lifecycle_entities() : [];
        foreach (array_keys($joined) as $table) {
            if (isset($lifecycleEntities[$table])) {
                $sql .= ' AND ' . lifecycle_active_predicate($table, $this->prefix . $table, $this->prefix);
            }
        }
        if ($term !== '') {
            $searchColumn = $isSqlServer
                ? 'LOWER(CAST(' . $column . ' AS NVARCHAR(4000)))'
                : 'LOWER(CAST(' . $column . ' AS CHAR))';
            $sql .= ' AND ' . $searchColumn . " LIKE ? ESCAPE '!'";
            $shortTerm = function_exists('mb_substr') ? mb_substr($term, 0, 100) : substr($term, 0, 100);
            $shortTerm = function_exists('mb_strtolower') ? mb_strtolower($shortTerm) : strtolower($shortTerm);
            $params[] = '%' . $this->escapeLike($shortTerm) . '%';
        }
        $sql .= ' ORDER BY option_value';
        if (!$isSqlServer) $sql .= ' LIMIT ' . $fetch;
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $values = array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $truncated = count($values) > $cap;
        return ['errors' => [], 'values' => array_slice($values, 0, $cap), 'truncated' => $truncated];
    }

    public function postProcess(array $rows, array $meta, bool $grandTotal): array
    {
        $breaks = [];
        $summaries = [];
        foreach ($meta as $column) {
            if ($column['break']) $breaks[] = $column;
            if ($column['summarize']) $summaries[] = $column['alias'];
        }
        $display = [];
        $open = [];
        $grand = array_fill_keys($summaries, 0.0);
        $grandCount = 0;
        $close = function (int $from, int $to) use (&$open, &$display, $breaks): void {
            for ($level = $from; $level >= $to; $level--) {
                if (!isset($open[$level]) || $open[$level]['count'] === 0) continue;
                $value = $open[$level]['value'];
                $shown = $value === null || $value === '' ? '(blank)' : (string)$value;
                $display[] = ['kind' => 'subtotal', 'level' => $level, 'label' => $breaks[$level]['label'] . ': ' . $shown . ' (' . $open[$level]['count'] . ' rows)', 'cells' => $open[$level]['sums']];
                unset($open[$level]);
            }
        };
        $previous = null;
        foreach ($rows as $row) {
            $changed = null;
            if ($previous !== null) {
                foreach ($breaks as $level => $break) {
                    if (($previous[$break['alias']] ?? null) !== ($row[$break['alias']] ?? null)) { $changed = $level; break; }
                }
            }
            if ($changed !== null) $close(count($breaks) - 1, $changed);
            foreach ($breaks as $level => $break) {
                if (!isset($open[$level])) $open[$level] = ['value' => $row[$break['alias']] ?? null, 'count' => 0, 'sums' => array_fill_keys($summaries, 0.0)];
                $open[$level]['count']++;
                foreach ($summaries as $alias) $open[$level]['sums'][$alias] += is_numeric($row[$alias] ?? null) ? (float)$row[$alias] : 0.0;
            }
            foreach ($summaries as $alias) $grand[$alias] += is_numeric($row[$alias] ?? null) ? (float)$row[$alias] : 0.0;
            $grandCount++;
            $display[] = ['kind' => 'data', 'level' => 0, 'label' => '', 'cells' => $row];
            $previous = $row;
        }
        if ($breaks !== []) $close(count($breaks) - 1, 0);
        if ($grandTotal && $grandCount > 0 && ($summaries !== [] || $breaks !== [])) {
            $display[] = ['kind' => 'total', 'level' => 0, 'label' => 'Grand total (' . $grandCount . ' rows)', 'cells' => $grand];
        }
        return $display;
    }

    private function filters(array $definition, array $values): array
    {
        $where = []; $params = []; $errors = [];
        foreach ($definition['filters'] as $index => $filter) {
            $key = 'f' . $index;
            $value = array_key_exists($key, $values) ? $values[$key] : $filter['default'];
            $upper = array_key_exists($key . 'b', $values) ? $values[$key . 'b'] : $filter['default_to'];
            $operator = $filter['operator'];
            $noValue = in_array($operator, ['is_null', 'not_null'], true);
            if (!$noValue && $this->empty($value) && ($operator !== 'between' || $this->empty($upper))) {
                if ($filter['required']) $errors[] = ($filter['label'] ?: "Filter " . ($index + 1)) . ' is required.';
                continue;
            }
            $column = $this->qualified($filter['table'], $filter['field']);
            $type = $this->catalog['tables'][$filter['table']]['fields'][$filter['field']]['type'];
            if (in_array($operator, ['is_null', 'not_null'], true)) {
                $string = in_array($type, ['string', 'text', 'enum'], true);
                $where[] = $operator === 'is_null'
                    ? ($string ? "({$column} IS NULL OR {$column} = '')" : "{$column} IS NULL")
                    : ($string ? "({$column} IS NOT NULL AND {$column} <> '')" : "{$column} IS NOT NULL");
                continue;
            }
            if ($operator === 'between') {
                if (is_array($value) || is_array($upper)) { $errors[] = 'Range filters require single values.'; continue; }
                $lowerEmpty = $this->empty($value); $upperEmpty = $this->empty($upper);
                if ($filter['required'] && ($lowerEmpty || $upperEmpty)) { $errors[] = ($filter['label'] ?: "Filter " . ($index + 1)) . ' requires both values.'; continue; }
                $exclusiveUpper = !$upperEmpty && $type === 'datetime' ? $this->nextDay((string)$upper) : null;
                if (!$lowerEmpty && !$upperEmpty && $exclusiveUpper !== null) { $where[] = "({$column} >= ? AND {$column} < ?)"; $params[] = $value; $params[] = $exclusiveUpper; }
                elseif (!$lowerEmpty && !$upperEmpty) { $where[] = "{$column} BETWEEN ? AND ?"; $params[] = $value; $params[] = $upper; }
                elseif (!$lowerEmpty) { $where[] = "{$column} >= ?"; $params[] = $value; }
                elseif ($exclusiveUpper !== null) { $where[] = "{$column} < ?"; $params[] = $exclusiveUpper; }
                elseif (!$upperEmpty) { $where[] = "{$column} <= ?"; $params[] = $upper; }
                continue;
            }
            if ($operator === 'in') {
                $items = is_array($value) ? $value : explode(',', (string)$value);
                $items = array_values(array_slice(array_filter(array_map(static fn($v) => is_scalar($v) ? trim((string)$v) : '', $items), static fn($v) => $v !== ''), 0, $this->settings['in_filter_max_values']));
                if ($items === []) { if ($filter['required']) $errors[] = ($filter['label'] ?: "Filter " . ($index + 1)) . ' is required.'; continue; }
                $where[] = $column . ' IN (' . implode(', ', array_fill(0, count($items), '?')) . ')';
                array_push($params, ...$items);
                continue;
            }
            if (is_array($value)) { $errors[] = ($filter['label'] ?: "Filter " . ($index + 1)) . ' requires a single value.'; continue; }
            if ($operator === 'not_equals') {
                $where[] = in_array($type, ['string', 'text', 'enum'], true)
                    ? "({$column} <> ? OR {$column} IS NULL OR {$column} = '')"
                    : "({$column} <> ? OR {$column} IS NULL)";
                $params[] = $value;
                continue;
            }
            $symbols = ['equals' => '=', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='];
            if (isset($symbols[$operator])) { $where[] = "{$column} {$symbols[$operator]} ?"; $params[] = $value; continue; }
            $escaped = $this->escapeLike((string)$value);
            if ($operator === 'contains') { $where[] = "{$column} LIKE ? ESCAPE '!'"; $params[] = "%{$escaped}%"; }
            elseif ($operator === 'not_contains') { $where[] = "({$column} NOT LIKE ? ESCAPE '!' OR {$column} IS NULL)"; $params[] = "%{$escaped}%"; }
            elseif ($operator === 'starts_with') { $where[] = "{$column} LIKE ? ESCAPE '!'"; $params[] = "{$escaped}%"; }
        }
        return [$where, $params, $errors];
    }

    private function quote(string $identifier): string
    {
        return in_array(strtolower($this->driver), ['mssql', 'sqlsrv'], true) ? '[' . str_replace(']', ']]', $identifier) . ']' : '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function qualified(string $table, string $field): string
    {
        return $this->quote($this->prefix . $table) . '.' . $this->quote($field);
    }

    private function escapeLike(string $value): string
    {
        $replacements = ['!' => '!!', '%' => '!%', '_' => '!_'];
        if (in_array(strtolower($this->driver), ['mssql', 'sqlsrv'], true)) $replacements['['] = '![';
        return strtr($value, $replacements);
    }

    private function nextDay(string $value): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) return null;
        return $date->modify('+1 day')->format('Y-m-d');
    }

    private function empty(mixed $value): bool
    {
        return is_array($value) ? count(array_filter($value, static fn($v) => trim((string)$v) !== '')) === 0 : trim((string)($value ?? '')) === '';
    }
}
