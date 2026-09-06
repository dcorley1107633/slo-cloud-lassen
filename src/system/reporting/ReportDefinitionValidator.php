<?php
declare(strict_types=1);

namespace SLOCloud\Reporting;

final class ReportDefinitionValidator
{
    /** @var array<string,int> normalized ReportSettings */
    private readonly array $settings;

    /**
     * @param array $settings Tunable limits from the `reports` config section
     *                        (see ReportSettings::DEFAULTS). Anything missing
     *                        falls back to the documented default.
     */
    public function __construct(private readonly array $catalog, array $settings = [])
    {
        $this->settings = ReportSettings::normalize($settings);
    }

    /** @return array{definition: array, errors: string[]} */
    public function prepare(mixed $raw): array
    {
        if (!is_array($raw)) {
            return ['definition' => $this->blank(), 'errors' => ['The report definition must be a JSON object.']];
        }
        $errors = [];
        $definition = $this->blank();
        $definition['id'] = max(0, (int)($raw['id'] ?? 0));
        $definition['name'] = trim($this->scalar($raw['name'] ?? '', 'Report name', $errors));
        $definition['description'] = trim($this->scalar($raw['description'] ?? '', 'Description', $errors));
        if ($definition['name'] === '') $errors[] = 'Report name is required.';
        $rowLimit = (int)($raw['options']['row_limit'] ?? $this->settings['default_row_limit']);
        $definition['options']['row_limit'] = max(1, min($this->settings['max_row_limit'], $rowLimit));
        $definition['options']['grand_total'] = filter_var($raw['options']['grand_total'] ?? true, FILTER_VALIDATE_BOOLEAN);
        // Default on: definitions saved before the option existed stay administrator-only.
        $definition['options']['admin_only'] = filter_var($raw['options']['admin_only'] ?? true, FILTER_VALIDATE_BOOLEAN);
        foreach (['created_at', 'updated_at'] as $stamp) {
            if (isset($raw[$stamp]) && is_scalar($raw[$stamp])) $definition[$stamp] = (string)$raw[$stamp];
        }

        $base = trim($this->scalar($raw['base_table'] ?? '', 'Base table', $errors));
        $definition['base_table'] = $base;
        if ($base === '' || !isset($this->catalog['tables'][$base])) {
            $errors[] = $base === ''
                ? 'Select a base table.'
                : "Base table '{$base}' is not available in the report catalog.";
            return ['definition' => $definition, 'errors' => array_values(array_unique($errors))];
        }

        $relationshipMap = [];
        foreach ($this->catalog['relationships'] ?? [] as $relationship) $relationshipMap[$relationship['id']] = $relationship;
        $joined = [$base => true];
        $joins = is_array($raw['joins'] ?? null) ? $raw['joins'] : [];
        if (isset($raw['joins']) && !is_array($raw['joins'])) $errors[] = 'Joins must be a list.';
        foreach ($joins as $index => $joinId) {
            if (!is_scalar($joinId) || !isset($relationshipMap[(string)$joinId])) {
                $errors[] = 'Join ' . ($index + 1) . ' is not available in the report catalog.';
                continue;
            }
            $relationship = $relationshipMap[(string)$joinId];
            $fromConnected = isset($joined[$relationship['from_table']]);
            $toConnected = isset($joined[$relationship['to_table']]);
            if (!$fromConnected && !$toConnected) {
                $errors[] = "Join '{$joinId}' is disconnected from the base table. Reorder or remove it.";
                continue;
            }
            if ($fromConnected && $toConnected) {
                $errors[] = "Join '{$joinId}' creates a cycle or joins a table more than once. Remove it.";
                continue;
            }
            $definition['joins'][] = (string)$joinId;
            $joined[$relationship['from_table']] = true;
            $joined[$relationship['to_table']] = true;
        }

        $columns = is_array($raw['columns'] ?? null) ? $raw['columns'] : [];
        if (isset($raw['columns']) && !is_array($raw['columns'])) $errors[] = 'Columns must be a list.';
        foreach ($columns as $index => $column) {
            if (!is_array($column)) {
                $errors[] = 'Column ' . ($index + 1) . ' is invalid.';
                continue;
            }
            $field = $this->resolveField($column, $joined, 'Column ' . ($index + 1), $errors);
            if ($field === null) continue;
            [$table, $fieldName, $fieldMeta] = $field;
            $aggregate = trim($this->scalar($column['aggregate'] ?? '', 'Column aggregate', $errors));
            $allowedAggregates = $this->catalog['types'][$fieldMeta['type']]['aggregates'] ?? [];
            if ($aggregate !== '' && !in_array($aggregate, $allowedAggregates, true)) {
                $errors[] = "Column " . ($index + 1) . " cannot use aggregate '{$aggregate}'.";
                $aggregate = '';
            }
            $sort = strtolower(trim($this->scalar($column['sort'] ?? '', 'Column sort', $errors)));
            if (!in_array($sort, ['', 'asc', 'desc'], true)) {
                $errors[] = 'Column ' . ($index + 1) . ' has an invalid sort direction.';
                $sort = '';
            }
            $break = filter_var($column['break'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($aggregate !== '' && $break) {
                $errors[] = 'Column ' . ($index + 1) . ' cannot be both aggregated and a control break.';
                $break = false;
            }
            if (isset($fieldMeta['encrypted']) && $aggregate === '' && ($sort !== '' || $break)) {
                $errors[] = 'Column ' . ($index + 1) . ' (' . $fieldMeta['label'] . ') is decrypted after the query runs, so it cannot be sorted or used as a control break.';
                $sort = '';
                $break = false;
            }
            $numeric = in_array($fieldMeta['type'], ['int', 'decimal', 'bool'], true) || $aggregate !== '';
            $definition['columns'][] = [
                'table' => $table, 'field' => $fieldName,
                'label' => trim($this->scalar($column['label'] ?? '', 'Column label', $errors)),
                'aggregate' => $aggregate, 'sort' => $sort, 'break' => $break,
                'summarize' => $numeric && filter_var($column['summarize'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }
        if ($definition['columns'] === []) $errors[] = 'Add at least one report column.';

        $filters = is_array($raw['filters'] ?? null) ? $raw['filters'] : [];
        if (isset($raw['filters']) && !is_array($raw['filters'])) $errors[] = 'Filters must be a list.';
        foreach ($filters as $index => $filter) {
            if (!is_array($filter)) {
                $errors[] = 'Filter ' . ($index + 1) . ' is invalid.';
                continue;
            }
            $field = $this->resolveField($filter, $joined, 'Filter ' . ($index + 1), $errors);
            if ($field === null) continue;
            [$table, $fieldName, $fieldMeta] = $field;
            if (isset($fieldMeta['encrypted'])) {
                $errors[] = 'Filter ' . ($index + 1) . ' (' . $fieldMeta['label'] . ') cannot filter on a decrypted field.';
                continue;
            }
            $allowedOperators = $this->catalog['types'][$fieldMeta['type']]['operators'] ?? [];
            $operator = trim($this->scalar($filter['operator'] ?? '', 'Filter operator', $errors));
            if (!in_array($operator, $allowedOperators, true)) {
                $errors[] = "Filter " . ($index + 1) . " cannot use operator '{$operator}'.";
                continue;
            }
            $control = $filter['control'] ?? 'textbox';
            if (!is_scalar($control) || !in_array((string)$control, ['textbox', 'multi_select'], true)) {
                $errors[] = 'Filter ' . ($index + 1) . ' has an invalid control.';
                $control = 'textbox';
            }
            if ($control === 'multi_select' && !in_array('in', $allowedOperators, true)) {
                $errors[] = 'Filter ' . ($index + 1) . ' cannot use a multi-select control.';
                $control = 'textbox';
            }
            if ($control === 'multi_select') $operator = 'in';
            $definition['filters'][] = [
                'table' => $table, 'field' => $fieldName, 'operator' => $operator, 'control' => (string)$control,
                'label' => trim($this->scalar($filter['label'] ?? '', 'Filter label', $errors)),
                'required' => filter_var($filter['required'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'default' => $this->defaultValue($filter['default'] ?? ''),
                'default_to' => $this->defaultValue($filter['default_to'] ?? ''),
            ];
        }
        return ['definition' => $definition, 'errors' => array_values(array_unique($errors))];
    }

    public function blank(): array
    {
        $tables = array_keys($this->catalog['tables'] ?? []);
        $defaultBase = isset($this->catalog['tables']['terms']) ? 'terms' : (string)($tables[0] ?? '');
        return [
            'id' => 0, 'name' => '', 'description' => '',
            'base_table' => $defaultBase, 'joins' => [],
            'columns' => [], 'filters' => [], 'options' => ['row_limit' => $this->settings['default_row_limit'], 'grand_total' => true, 'admin_only' => true],
        ];
    }

    private function resolveField(array $item, array $joined, string $label, array &$errors): ?array
    {
        $table = isset($item['table']) && is_scalar($item['table']) ? trim((string)$item['table']) : '';
        $field = isset($item['field']) && is_scalar($item['field']) ? trim((string)$item['field']) : '';
        if (!isset($joined[$table])) {
            $errors[] = "{$label} references table '{$table}', which is not connected to the report.";
            return null;
        }
        if (!isset($this->catalog['tables'][$table]['fields'][$field])) {
            $errors[] = "{$label} field '{$table}.{$field}' is not available in the report catalog.";
            return null;
        }
        return [$table, $field, $this->catalog['tables'][$table]['fields'][$field]];
    }

    private function scalar(mixed $value, string $label, array &$errors): string
    {
        if (!is_scalar($value) && $value !== null) {
            $errors[] = "{$label} must be a single value.";
            return '';
        }
        return (string)($value ?? '');
    }

    private function defaultValue(mixed $value): string|array
    {
        if (is_array($value)) {
            return array_values(array_slice(array_map(static fn($v) => is_scalar($v) ? (string)$v : '', $value), 0, $this->settings['in_filter_max_values']));
        }
        return is_scalar($value) ? (string)$value : '';
    }
}
