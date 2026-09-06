<?php
declare(strict_types=1);

namespace SLOCloud\Reporting;

/**
 * Tunable limits for custom reports.
 *
 * Every value here used to be a literal scattered across the validator, the
 * engine, and the report page. They are now read from the `reports` section
 * of config.yaml (config.yaml.template documents each one) and handed to the
 * reporting classes as a single normalized array, so the classes stay usable
 * without a Config instance (tests, setup) and the page, the SQL, and the
 * client script all agree on the same numbers.
 */
final class ReportSettings
{
    /** Top-level config.yaml section holding these keys. */
    public const SECTION = 'reports';

    /**
     * Fallbacks when a key is absent. config.yaml.template carries the same
     * defaults, and mergeTemplateDefaults() feeds them to older deployments
     * whose config.yaml predates the section.
     */
    public const DEFAULTS = [
        'max_row_limit' => 20000,
        'default_row_limit' => 500,
        'option_search_limit' => 100,
        'in_filter_max_values' => 100,
        'option_search_debounce_ms' => 200,
    ];

    /**
     * Coerce and bound a raw settings array.
     *
     * Unknown keys are dropped, missing or non-numeric keys take the default,
     * every limit is at least 1 (the debounce at least 0), and the default row
     * limit can never exceed the maximum so a freshly created report is always
     * valid as created.
     *
     * @return array<string,int>
     */
    public static function normalize(array $settings): array
    {
        $normalized = [];
        foreach (self::DEFAULTS as $key => $default) {
            $value = $settings[$key] ?? null;
            $normalized[$key] = is_numeric($value) ? (int)$value : $default;
        }
        $normalized['max_row_limit'] = max(1, $normalized['max_row_limit']);
        $normalized['default_row_limit'] = max(1, min($normalized['max_row_limit'], $normalized['default_row_limit']));
        $normalized['option_search_limit'] = max(1, $normalized['option_search_limit']);
        $normalized['in_filter_max_values'] = max(1, $normalized['in_filter_max_values']);
        $normalized['option_search_debounce_ms'] = max(0, $normalized['option_search_debounce_ms']);
        return $normalized;
    }

    /**
     * Read the `reports` section from a loaded application Config.
     *
     * @return array<string,int>
     */
    public static function fromConfig(\SLOCloud\Core\Config $config): array
    {
        $settings = [];
        foreach (self::DEFAULTS as $key => $default) {
            $settings[$key] = $config->get(self::SECTION . '.' . $key, $default);
        }
        return self::normalize($settings);
    }
}
