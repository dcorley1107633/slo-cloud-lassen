<?php
declare(strict_types=1);

/**
 * Settings form fields built from the config.yaml.template metadata, shared by
 * System > Configuration (and the setup pages).
 *
 * @package SLOCloud
 */

/** Template fields in one section (for example 'theme'), keyed by dotted path. */
function configFieldsInSection(array $fields, string $section): array {
    return array_filter(
        $fields,
        static fn(string $key): bool => explode('.', $key)[0] === $section,
        ARRAY_FILTER_USE_KEY
    );
}

/**
 * Copy posted values for $fields into $config. Locked fields are skipped and a
 * blank password keeps the stored one.
 */
function applyPostedConfigFields(\SLOCloud\Core\Config $config, array $fields, array $post, array $lockedFields = []): void {
    foreach ($fields as $key => $meta) {
        if (in_array($key, $lockedFields, true)) {
            continue;
        }
        $postKey = str_replace('.', '_', $key);
        $type = $meta['type'] ?? 'string';

        if ($type === 'password') {
            if (!empty($post[$postKey])) {
                $config->set($key, $post[$postKey]);
            }
        } elseif ($type === 'boolean') {
            $config->set($key, isset($post[$postKey]));
        } elseif ($type === 'integer') {
            // A cleared number box keeps the default rather than saving 0,
            // which most integer settings reject.
            $posted = trim((string)($post[$postKey] ?? ''));
            $config->set($key, (int)($posted !== '' ? $posted : ($meta['default'] ?? 0)));
        } else {
            $config->set($key, trim((string)($post[$postKey] ?? $meta['default'] ?? '')));
        }
    }
}

/**
 * Render form field from metadata
 */
function renderConfigField(string $key, array $meta, $value): string {
    $parts = explode('.', $key);
    $name = implode('_', $parts);
    $id = $name;
    $type = $meta['type'] ?? 'string';
    $label = $meta['label'] ?? ucfirst($parts[1] ?? $key);
    $required = ($meta['required'] ?? 'false') === 'true';
    $help = $meta['help'] ?? '';
    $helpId = $id . '-help';
    $describedBy = $help !== '' ? ' aria-describedby="' . htmlspecialchars($helpId) . '"' : '';
    
    // Fields that cannot be changed after installation (would break existing data)
    $lockedFields = [
        'database.driver' => 'Database driver cannot be changed after installation. Changing this requires data migration.',
        'database.name' => 'Database name cannot be changed after installation. Changing this would lose access to your existing data.',
        'database.prefix' => 'Table prefix cannot be changed after installation. Changing this would lose access to your existing tables.'
    ];
    
    $html = '<div class="mb-3">';
    $html .= '<label for="' . htmlspecialchars($id) . '" class="form-label">';
    $html .= htmlspecialchars($label);
    if ($required) $html .= ' <span class="text-danger">*</span>';
    $html .= '</label>';
    
    // Check if this field is locked
    if (isset($lockedFields[$key])) {
        // Render as disabled field with hidden input to preserve value
        if ($type === 'select') {
            $html .= '<select class="form-select" id="' . htmlspecialchars($id) . '" aria-describedby="' . htmlspecialchars($helpId) . '" disabled>';
            $options = explode(',', $meta['options'] ?? '');
            foreach ($options as $opt) {
                if (str_contains($opt, '|')) {
                    [$val, $lbl] = explode('|', $opt, 2);
                } else {
                    $val = $lbl = $opt;
                }
                $selected = ($value == $val) ? 'selected' : '';
                $html .= '<option value="' . htmlspecialchars($val) . '" ' . $selected . '>';
                $html .= htmlspecialchars($lbl) . '</option>';
            }
            $html .= '</select>';
        } else {
            $html .= '<input type="text" class="form-control" id="' . htmlspecialchars($id) . '" value="' . htmlspecialchars((string)$value) . '" aria-describedby="' . htmlspecialchars($helpId) . '" disabled>';
        }
        
        // Hidden input to preserve value in POST
        $html .= '<input type="hidden" name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars((string)$value) . '">';
        
        // Warning message
        $html .= '<small class="form-text text-warning" id="' . htmlspecialchars($helpId) . '">';
        $html .= '<i class="fas fa-lock mr-1" aria-hidden="true"></i>' . htmlspecialchars($lockedFields[$key]);
        $html .= '</small>';
        $html .= '</div>';
        return $html;
    }
    
    switch ($type) {
        case 'password':
            $html .= '<input type="password" class="form-control" id="' . htmlspecialchars($id) . '" ';
            $html .= 'name="' . htmlspecialchars($name) . '" placeholder="Leave blank to keep current"' . $describedBy . '>';
            break;
            
        case 'boolean':
            $checked = ($value === true || $value === 'true') ? 'checked' : '';
            $html .= '<div class="form-check"><input type="checkbox" class="form-check-input" ';
            $html .= 'id="' . htmlspecialchars($id) . '" name="' . htmlspecialchars($name) . '"' . $describedBy . ' ' . $checked . '>';
            $html .= '<label class="form-check-label" for="' . htmlspecialchars($id) . '">' . htmlspecialchars($meta['description'] ?? '') . '</label></div>';
            break;
            
        case 'select':
            $html .= '<select class="form-select" id="' . htmlspecialchars($id) . '" name="' . htmlspecialchars($name) . '"';
            if ($required) $html .= ' required';
            if ($required) $html .= ' aria-required="true"';
            $html .= $describedBy;
            $html .= '>';
            
            $options = explode(',', $meta['options'] ?? '');
            foreach ($options as $opt) {
                if (str_contains($opt, '|')) {
                    [$val, $lbl] = explode('|', $opt, 2);
                } else {
                    $val = $lbl = $opt;
                }
                $selected = ($value == $val) ? 'selected' : '';
                $html .= '<option value="' . htmlspecialchars($val) . '" ' . $selected . '>';
                $html .= htmlspecialchars($lbl) . '</option>';
            }
            $html .= '</select>';
            break;
            
        case 'integer':
            $html .= '<input type="number" class="form-control" id="' . htmlspecialchars($id) . '" ';
            $html .= 'name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars((string)$value) . '"';
            if ($required) $html .= ' required';
            if ($required) $html .= ' aria-required="true"';
            $html .= $describedBy;
            if (isset($meta['validation']) && str_contains($meta['validation'], 'min=')) {
                preg_match('/min=(\d+)/', $meta['validation'], $m);
                $html .= ' min="' . $m[1] . '"';
            }
            if (isset($meta['validation']) && str_contains($meta['validation'], 'max=')) {
                preg_match('/max=(\d+)/', $meta['validation'], $m);
                $html .= ' max="' . $m[1] . '"';
            }
            $html .= '>';
            break;
            
        default: // string, email, timezone
            $inputType = ($type === 'email') ? 'email' : 'text';
            $html .= '<input type="' . $inputType . '" class="form-control" id="' . htmlspecialchars($id) . '" ';
            $html .= 'name="' . htmlspecialchars($name) . '" value="' . htmlspecialchars((string)$value) . '"';
            if (($meta['placeholder'] ?? '') !== '') $html .= ' placeholder="' . htmlspecialchars((string)$meta['placeholder']) . '"';
            if ($required) $html .= ' required';
            if ($required) $html .= ' aria-required="true"';
            $html .= $describedBy;
            $html .= '>';
    }
    
    if ($help) {
        $html .= '<small class="form-text text-muted" id="' . htmlspecialchars($helpId) . '">' . htmlspecialchars($help) . '</small>';
    }
    
    $html .= '</div>';
    return $html;
}

/**
 * The color scheme chooser: radio swatches grouped by theme family. Choosing
 * one previews it on the page at once (the layouts' own styles are swapped
 * for the scheme's); it is stored for everyone only when the form is saved.
 */
function renderColorSchemePicker(string $name, $current): string {
    require_once __DIR__ . '/../Core/ColorScheme.php';
    $current = \SLOCloud\Core\ColorScheme::normalize($current);
    $html = '<fieldset class="mb-3"><legend class="form-label">Color Scheme</legend>';
    $html .= '<p class="form-text text-muted mb-2" id="colorSchemeHelp">Choose a scheme to preview it on this page, including the menu. It applies to every user and every theme only after you save.</p>';
    foreach (\SLOCloud\Core\ColorScheme::grouped() as $group => $schemes) {
        $html .= '<div role="group" aria-label="' . htmlspecialchars($group) . ' color schemes" class="mb-2">';
        $html .= '<div class="small fw-semibold mb-1" aria-hidden="true">' . htmlspecialchars($group) . '</div>';
        $html .= '<div class="d-flex flex-wrap gap-2">';
        foreach ($schemes as $schemeName => $scheme) {
            $id = 'scheme_' . $schemeName;
            $html .= '<div><input type="radio" class="btn-check" name="' . htmlspecialchars($name) . '" id="' . htmlspecialchars($id) . '" value="' . htmlspecialchars($schemeName) . '"'
                . ($schemeName === $current ? ' checked' : '') . ' aria-describedby="colorSchemeHelp">'
                . '<label class="btn btn-outline-secondary d-flex align-items-center gap-2" for="' . htmlspecialchars($id) . '">'
                . '<span class="d-inline-flex" aria-hidden="true">';
            foreach (['dark', 'primary', 'accent', 'highlight'] as $part) {
                if (!isset($scheme[$part])) { continue; }
                $html .= '<span style="display:inline-block;width:1.25rem;height:1.25rem;background:' . htmlspecialchars($scheme[$part]) . ';"></span>';
            }
            if (isset($scheme['stripe'])) {
                $html .= '<span style="display:inline-block;width:1.25rem;height:1.25rem;background:' . htmlspecialchars($scheme['stripe']) . ';"></span>';
            }
            $html .= '</span>' . htmlspecialchars($scheme['label']) . '</label></div>';
        }
        $html .= '</div></div>';
    }
    $html .= '</fieldset>';

    $css = [];
    foreach (array_keys(\SLOCloud\Core\ColorScheme::SCHEMES) as $schemeName) {
        $css[$schemeName] = \SLOCloud\Core\ColorScheme::css($schemeName);
    }
    $html .= '<script>(function () {'
        . 'var css = ' . json_encode($css, JSON_HEX_TAG | JSON_HEX_AMP) . ';'
        . 'document.querySelectorAll(\'input[name="' . $name . '"]\').forEach(function (radio) {'
        . 'radio.addEventListener(\'change\', function () {'
        . 'var style = document.getElementById(\'slo-color-scheme\');'
        . 'if (!style) { style = document.createElement(\'style\'); style.id = \'slo-color-scheme\'; document.head.appendChild(style); }'
        . 'style.textContent = css[radio.value] || \'\';'
        . '});});})();</script>';
    return $html;
}
