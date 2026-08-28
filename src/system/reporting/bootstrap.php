<?php
declare(strict_types=1);

require_once __DIR__ . '/ReportCatalogBuilder.php';
require_once __DIR__ . '/ReportDefinitionValidator.php';
require_once __DIR__ . '/ReportStore.php';
require_once __DIR__ . '/ReportEngine.php';
require_once __DIR__ . '/../Core/Security.php';

use SLOCloud\Core\Security;
use SLOCloud\Reporting\ReportCatalogBuilder;
use SLOCloud\Reporting\ReportStore;

/**
 * The catalog is per-install runtime state, not a shipped artifact.
 *
 * It lives in var/ beside the report definitions because the two are a matched
 * pair (definitions are only meaningful against the catalog they were validated
 * against) and because both must survive a drop-in upgrade that replaces src/.
 *
 * In production it is ALWAYS built from the live database - at the end of setup
 * and on explicit refresh - never from the checked-in DDL. A DDL-derived
 * catalog is only coincidentally correct: it is single-dialect (schema.sql is
 * MySQL) and cannot see an install's customizations. ReportCatalogBuilder's
 * fromDdl() remains for tests and development tooling only.
 */
function report_catalog_path(): string
{
    return report_data_directory() . '/catalog.json';
}

/**
 * Resolve writable report state independently of the source layout.
 *
 * In a source checkout this is <repository>/var/reports. When only the
 * contents of src/ are deployed, it is a protected sibling such as
 * <webroot>-data/var/reports. The directory is created by the store/catalog
 * writers at runtime and is not part of the upload artifact.
 */
function report_data_directory(): string
{
    return Security::dataRoot(dirname(__DIR__, 2)) . '/var/reports';
}

/**
 * Rebuilding is always two sequential steps, ordered BY THE CALLER - these
 * functions do not chain into each other:
 *
 *   1. report_build_catalog()  - introspect the live database, persist catalog
 *   2. report_sweep_reports()  - revalidate every stored report against it
 *
 * Every path that produces a catalog (end of setup, first-use initialization,
 * the explicit Refresh action) runs both steps in that order, so no catalog
 * can exist that stored reports were never checked against.
 */

/**
 * Step 1: introspect the live database and persist the catalog. This is the
 * only way a production catalog is ever produced.
 */
function report_build_catalog(PDO $pdo, string $driver, string $prefix): array
{
    $builder = new ReportCatalogBuilder();
    $catalog = $builder->fromLive($pdo, $driver, $prefix);
    $builder->write($catalog, report_catalog_path());
    return $catalog;
}

/**
 * Step 2: validate every stored report definition against a catalog.
 * Report-only - definitions are never rewritten.
 *
 * @return array{checked: int, need_repair: string[]}
 */
function report_sweep_reports(array $catalog, ReportStore $store): array
{
    $validator = new \SLOCloud\Reporting\ReportDefinitionValidator($catalog);
    $checked = 0;
    $needRepair = [];
    foreach ($store->all() as $definition) {
        $checked++;
        if ($validator->prepare($definition)['errors'] !== []) {
            $needRepair[] = (string)($definition['name'] ?? ('#' . (int)($definition['id'] ?? 0)));
        }
    }
    return ['checked' => $checked, 'need_repair' => $needRepair];
}

/**
 * Pure read of the persisted catalog. Returns null when missing or invalid so
 * the caller decides what to do (initialize, or error) - loading never builds.
 */
function report_load_catalog(): ?array
{
    $decoded = json_decode((string)@file_get_contents(report_catalog_path()), true);
    if (is_array($decoded) && isset($decoded['tables'], $decoded['types'])) {
        return $decoded;
    }
    return null;
}

function report_store(): ReportStore
{
    static $store;
    return $store ??= new ReportStore(report_data_directory() . '/definitions', __DIR__ . '/seeds');
}

function report_user_can_manage($db, string $prefix): bool
{
    if (($_SESSION['is_emergency_admin'] ?? false) === true) return true;
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) return false;
    $result = $db->query(
        "SELECT COUNT(*) AS role_count, SUM(CASE WHEN r.role_name = 'admin' THEN 1 ELSE 0 END) AS admin_count FROM {$prefix}user_roles ur JOIN {$prefix}roles r ON r.roles_pk = ur.role_fk WHERE ur.user_fk = ? AND ur.context_type IS NULL AND ur.context_id IS NULL",
        [$userId]
    );
    $roles = $result->fetch() ?: [];
    return (int)($roles['admin_count'] ?? 0) > 0;
}

function report_actor_id(): ?int
{
    $id = (int)($_SESSION['user_id'] ?? 0);
    return $id > 0 ? $id : null;
}

function report_require_manage(bool $canManage): void
{
    if (!$canManage) {
        http_response_code(403);
        throw new RuntimeException('Administrator permission is required for this report action.');
    }
}

function report_verify_csrf(): void
{
    $token = isset($_POST['csrf_token']) && is_string($_POST['csrf_token']) ? $_POST['csrf_token'] : '';
    if ($token === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $token)) {
        http_response_code(403);
        throw new RuntimeException('CSRF token validation failed. Refresh the page and try again.');
    }
}

function report_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function report_csv_safe(mixed $value): string
{
    $value = (string)($value ?? '');
    $trimmed = ltrim($value);
    if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@'], true) && !is_numeric($trimmed)) return "'" . $value;
    return $value;
}

function report_value_empty(mixed $value): bool
{
    return is_array($value) ? count(array_filter($value, static fn($v) => trim((string)$v) !== '')) === 0 : trim((string)($value ?? '')) === '';
}

/** Render one validated runtime-filter control from its catalog input hint. */
function report_filter_input_html(array $filter, array $field, array $typeMeta, int $index, mixed $current, mixed $upper): string
{
    $key = 'f' . $index;
    $required = !empty($filter['required']) ? ' required' : '';
    $operator = (string)$filter['operator'];
    if (in_array($operator, ['is_null', 'not_null'], true)) {
        return '<input class="form-control" disabled value="No value needed">';
    }
    if (($filter['control'] ?? 'textbox') === 'multi_select') {
        $selected = array_values(array_unique(array_filter(
            array_map('strval', (array)$current),
            static fn(string $value): bool => trim($value) !== ''
        )));
        $summary = count($selected) === 0
            ? 'Any'
            : (count($selected) === 1 ? $selected[0] : count($selected) . ' selected');
        $html = '<div class="dropdown report-multiselect" data-filter="' . $index . '" data-options-url="' . report_h(BASE_URL . 'administration/report_options.php?id=' . (int)($_GET['id'] ?? 0) . '&filter=' . $index) . '">';
        $html .= '<button class="form-select text-start report-multiselect-toggle" type="button" id="' . $key . '-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-controls="' . $key . '-menu"' . (!empty($filter['required']) ? ' aria-required="true"' : '') . '><span class="selection-summary">' . report_h($summary) . '</span></button>';
        $html .= '<div class="dropdown-menu report-multiselect-menu p-2 shadow" id="' . $key . '-menu" aria-labelledby="' . $key . '-toggle">';
        $html .= '<label class="visually-hidden" for="' . $key . '-search">Search available values</label>';
        $html .= '<input class="form-control form-control-sm option-search mb-2" type="search" id="' . $key . '-search" autocomplete="off" placeholder="Search options...">';
        $html .= '<div class="report-multiselect-options" role="group" aria-label="Available values">';
        foreach ($selected as $optionIndex => $value) {
            $optionId = $key . '-option-' . $optionIndex;
            $html .= '<label class="dropdown-item d-flex align-items-start gap-2 rounded py-1" for="' . $optionId . '"><input class="form-check-input report-option-check mt-1" type="checkbox" checked id="' . $optionId . '" data-value="' . report_h($value) . '"><span>' . report_h($value) . '</span></label>';
        }
        $html .= '</div><div class="d-flex justify-content-between align-items-center border-top mt-2 pt-2"><span class="small text-muted option-status" role="status" aria-live="polite">Open to load options.</span><button class="btn btn-sm btn-link option-clear py-0" type="button">Clear</button></div></div>';
        $html .= '<select class="visually-hidden option-values" tabindex="-1" aria-hidden="true" multiple id="' . $key . '" name="' . $key . '[]" data-required="' . (!empty($filter['required']) ? '1' : '0') . '">';
        foreach ($selected as $value) $html .= '<option selected value="' . report_h($value) . '">' . report_h($value) . '</option>';
        return $html . '</select></div>';
    }
    $options = is_array($field['options'] ?? null) ? $field['options'] : [];
    if (($typeMeta['input'] ?? '') === 'select' && $options !== []) {
        $multiple = $operator === 'in';
        $selected = is_array($current) ? array_map('strval', $current) : ($multiple ? array_values(array_filter(array_map('trim', explode(',', (string)$current)), static fn($v) => $v !== '')) : [(string)$current]);
        $html = '<select class="form-select" id="' . $key . '" name="' . $key . ($multiple ? '[]' : '') . '"' . ($multiple ? ' multiple size="5"' : '') . $required . '>';
        if (!$multiple && empty($filter['required'])) $html .= '<option value="">Any value</option>';
        foreach ($options as $option) {
            $option = (string)$option;
            $label = ($field['type'] ?? '') === 'bool' ? ($option === '1' ? 'Yes' : 'No') : str_replace('_', ' ', ucfirst($option));
            $html .= '<option value="' . report_h($option) . '"' . (in_array($option, $selected, true) ? ' selected' : '') . '>' . report_h($label) . '</option>';
        }
        return $html . '</select>' . ($multiple ? '<div class="form-text">Hold Ctrl/Cmd to select several values.</div>' : '');
    }
    if ($operator === 'between') {
        $inputType = ($typeMeta['input'] ?? '') === 'date' ? 'date' : (($typeMeta['input'] ?? '') === 'number' ? 'number' : 'text');
        return '<div class="input-group"><input class="form-control" type="' . $inputType . '" step="any" id="' . $key . '" name="' . $key . '" value="' . report_h($current) . '"' . $required . '><span class="input-group-text">to</span><input class="form-control" type="' . $inputType . '" step="any" name="' . $key . 'b" value="' . report_h($upper) . '"' . $required . '></div>';
    }
    $inputType = $operator === 'in' ? 'text' : (($typeMeta['input'] ?? '') === 'date' ? 'date' : (($typeMeta['input'] ?? '') === 'number' ? 'number' : 'text'));
    $value = is_array($current) ? implode(', ', $current) : (string)$current;
    return '<input class="form-control" type="' . $inputType . '" step="any" id="' . $key . '" name="' . $key . '" value="' . report_h($value) . '" placeholder="' . ($operator === 'in' ? 'comma, separated, values' : '') . '"' . $required . '>';
}
