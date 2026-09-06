<?php
declare(strict_types=1);

/**
 * Configuration Editor
 * 
 * Edit system configuration settings.
 * Uses pragmatic page pattern (logic + template in one file).
 * 
 * @package SLOCloud
 */

require_once __DIR__ . '/../system/includes/init.php';

// Override $configData with raw (unencrypted) values for the config editor display
$configPath = __DIR__ . '/../config/config.yaml';
$configData = $config->all();
$lookupKeyConfigured = (string)$config->get('security.student_lookup_key', '') !== '';

// Parse template for field metadata
$templatePath = __DIR__ . '/../config/config.yaml.template';
$fields = parseTemplate($templatePath);

/**
 * Parse template to extract field metadata
 */
function parseTemplate(string $path): array {
    return \SLOCloud\Core\Config::schemaFromTemplate($path);
}

/**
 * Render form field from metadata
 */
function renderField(string $key, array $meta, $value): string {
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

// Handle POST requests
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die('CSRF token validation failed');
    }
    
    try {
        // Fields that cannot be changed after installation
        $lockedFields = ['database.driver', 'database.name', 'database.prefix'];
        
        // Dynamically update all fields from template
        foreach ($fields as $key => $meta) {
            // Skip locked fields - they cannot be changed post-installation
            if (in_array($key, $lockedFields)) {
                continue;
            }
            
            $postKey = str_replace('.', '_', $key);
            $type = $meta['type'] ?? 'string';
            
            if ($type === 'password') {
                // Only update if provided
                if (!empty($_POST[$postKey])) {
                    $config->set($key, $_POST[$postKey]);
                }
            } elseif ($type === 'boolean') {
                $config->set($key, isset($_POST[$postKey]));
            } elseif ($type === 'integer') {
                $config->set($key, (int)($_POST[$postKey] ?? $meta['default'] ?? 0));
            } else {
                $config->set($key, trim($_POST[$postKey] ?? $meta['default'] ?? ''));
            }
        }
        
        // Save configuration
        $config->save();
        
        $successMessage = 'Configuration updated successfully';
        
        // Reload config data
        $configData = $config->all();
    } catch (\Exception $e) {
        $errorMessage = 'Failed to save configuration: ' . htmlspecialchars($e->getMessage());
        if ($debugMode) {
            $errorMessage .= '<br><br><strong>Debug Information:</strong><br>';
            $errorMessage .= '<pre style="text-align: left; font-size: 12px;">';
            $errorMessage .= 'File: ' . htmlspecialchars($e->getFile()) . '<br>';
            $errorMessage .= 'Line: ' . htmlspecialchars((string)$e->getLine()) . '<br>';
            $errorMessage .= 'Trace:<br>' . htmlspecialchars($e->getTraceAsString());
            $errorMessage .= '</pre>';
        }
    }
}

// Group fields by section
$sections = [];
foreach ($fields as $key => $meta) {
    $section = explode('.', $key)[0];
    if (!isset($sections[$section])) {
        $sections[$section] = [];
    }
    $sections[$section][$key] = $meta;
}

// Section icons and titles
$sectionConfig = [
    'database' => ['icon' => 'fas fa-database', 'title' => 'Database Settings'],
    'app' => ['icon' => 'fas fa-laptop', 'title' => 'Application Settings'],
    'theme' => ['icon' => 'fas fa-palette', 'title' => 'Theme Settings'],
    'email' => ['icon' => 'fas fa-envelope', 'title' => 'Email Settings'],
    'reports' => ['icon' => 'fas fa-table', 'title' => 'Custom Report Settings'],
];

// Load theme system (auto-loads ThemeContext and Theme)
require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => 'System Configuration',
    'currentPage' => 'admin_config',
    'breadcrumbs' => [
        ['url' => BASE_URL, 'label' => 'Home'],
        ['label' => 'Configuration']
    ]
]);

$theme = ThemeLoader::getActiveTheme(null, 'admin');
$theme->showHeader($context);
?>

<?php if ($successMessage): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fas fa-check-circle"></i> <?= htmlspecialchars($successMessage) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close alert"></button>
</div>
<?php endif; ?>

<?php if ($errorMessage): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="fas fa-exclamation-circle"></i> <?= $errorMessage ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close alert"></button>
</div>
<?php endif; ?>

<div class="alert alert-warning">
    <i class="fas fa-exclamation-triangle"></i> <strong>Warning:</strong> Incorrect settings can break your installation. Make sure you have a backup of your config.yaml file.
</div>

<form method="POST">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
    
    <?php foreach ($sections as $sectionName => $sectionFields): ?>
    <div class="card mb-3">
        <div class="card-header">
            <h2 class="card-title">
                <i class="<?= $sectionConfig[$sectionName]['icon'] ?? 'fas fa-cog' ?>" aria-hidden="true"></i>
                <?= $sectionConfig[$sectionName]['title'] ?? ucfirst($sectionName) ?>
            </h2>
        </div>
        <div class="card-body">
            <div class="row">
                <?php foreach ($sectionFields as $key => $meta): ?>
                <div class="col-md-6">
                    <?php
                    $value = $config->get($key, $meta['default'] ?? '');
                    echo renderField($key, $meta, $value);
                    ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Submit Buttons -->
    <div class="card">
        <div class="card-body">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fas fa-save"></i> Save Configuration
            </button>
            <a href="<?= BASE_URL ?>" class="btn btn-secondary btn-lg">
                <i class="fas fa-times"></i> Cancel
            </a>
        </div>
    </div>
</form>

<!-- Key Rotation Section -->
<div class="card mb-3">
    <div class="card-header">
        <h2 class="card-title">
            <i class="fas fa-key" aria-hidden="true"></i>
            Encryption Key Rotation
        </h2>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">
            Key rotation generates a new key and rewrites all dependent encrypted or hashed data.
            This should be done periodically as a security best practice.
            <strong>Always back up key.yaml and config.yaml before rotating keys.</strong>
        </p>
        <div class="row g-3">
            <div class="col-lg-4">
                <div class="card h-100 border-warning">
                    <div class="card-body d-flex flex-column">
                        <h3 class="h5 mb-2 d-flex align-items-center gap-2"><i class="fas fa-lock text-warning" aria-hidden="true"></i> Config Encryption Key</h3>
                        <p class="text-muted small mb-3">
                            Encrypts database password, SMTP password, and other sensitive values in
                            <code>config.yaml</code>. Rotates <code>encryption_key</code> in key.yaml
                            and re-encrypts all <code>ENC:</code> values in config.yaml.
                        </p>
                        <button type="button" class="btn btn-warning mt-auto align-self-start" onclick="rotateKey('config')">
                            <i class="fas fa-sync-alt"></i> Rotate Config Key
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100 border-danger">
                    <div class="card-body d-flex flex-column">
                        <h3 class="h5 mb-2 d-flex align-items-center gap-2"><i class="fas fa-user-lock text-danger" aria-hidden="true"></i> Student Data Key</h3>
                        <p class="text-muted small mb-3">
                            Encrypts student PII (names, ID numbers) stored in the database (FERPA compliance).
                            Rotates <code>student_encryption_key</code> in key.yaml and re-encrypts
                            all student records in the database.
                        </p>
                        <button type="button" class="btn btn-danger mt-auto align-self-start" onclick="rotateKey('student')">
                            <i class="fas fa-sync-alt"></i> Rotate Student Key
                        </button>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100 border-info">
                    <div class="card-body d-flex flex-column">
                        <h3 class="h5 mb-2 d-flex align-items-center gap-2"><i class="fas fa-search text-info" aria-hidden="true"></i> Student Lookup Key</h3>
                        <p class="text-muted small mb-2">
                            Keys the deterministic student-ID hash used for indexed matching. This decrypts
                            each stored ID, recomputes every <code>student_id_hash</code>, and
                            <?= $lookupKeyConfigured ? 'replaces' : 'creates' ?> <code>student_lookup_key</code> in key.yaml.
                        </p>
                        <p class="small mb-3">
                            Status:
                            <?php if ($lookupKeyConfigured): ?>
                                <span class="badge text-bg-success">Configured</span>
                            <?php else: ?>
                                <span class="badge text-bg-warning">Legacy unkeyed hash</span>
                            <?php endif; ?>
                        </p>
                        <button type="button" class="btn btn-info mt-auto align-self-start" onclick="rotateKey('lookup')"
                                <?= MAINTENANCE_MODE ? '' : 'disabled aria-disabled="true"' ?>>
                            <i class="fas fa-sync-alt"></i>
                            <?= $lookupKeyConfigured ? 'Rotate Lookup Key' : 'Create Lookup Key' ?>
                        </button>
                        <?php if (!MAINTENANCE_MODE): ?>
                            <div class="form-text text-warning mt-2">
                                <i class="fas fa-tools" aria-hidden="true"></i>
                                Enable Maintenance Mode and save the configuration first.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Key Rotation Progress Modal -->
<div class="modal fade" id="rotationModal" tabindex="-1" aria-labelledby="rotationModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5" id="rotationModalLabel">
                    <i class="fas fa-sync-alt fa-spin"></i> Key Rotation in Progress
                </h2>
            </div>
            <div class="modal-body">
                <div id="rotationSteps" class="list-group list-group-flush mb-3" style="font-size: 0.9em;"></div>
                <div id="rotationSpinner" class="text-center py-3">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Rotating key...</span>
                    </div>
                    <p class="mt-2 text-muted mb-0">Rotating key, please wait&hellip;</p>
                </div>
                <div id="rotationResult" style="display: none;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="rotationCloseBtn" data-bs-dismiss="modal" disabled>
                    Close
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    function escHtml(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(String(str)));
        return d.innerHTML;
    }

    window.rotateKey = function (keyType) {
        var labels = {
            config:  'Config Encryption Key',
            student: 'Student Data Key',
            lookup:  'Student Lookup Key'
        };
        var warnings = {
            config:  'This will re-encrypt all sensitive values in config.yaml with a new key.',
            student: 'This will re-encrypt ALL student records in the database.\nThis cannot be undone once started.',
            lookup:  'This will decrypt every student ID and replace every indexed lookup hash.\nMaintenance Mode must remain enabled until the operation finishes.'
        };

        var label = labels[keyType] || keyType;

        if (!confirm('Rotate ' + label + '?\n\n' + warnings[keyType] + '\n\nEnsure you have a backup of key.yaml and config.yaml before proceeding.')) {
            return;
        }

        // Reset modal
        document.getElementById('rotationSteps').innerHTML    = '';
        document.getElementById('rotationSpinner').style.display = '';
        document.getElementById('rotationResult').style.display  = 'none';
        document.getElementById('rotationResult').innerHTML       = '';
        document.getElementById('rotationCloseBtn').disabled      = true;

        var titleEl = document.getElementById('rotationModalLabel');
        titleEl.innerHTML = '<i class="fas fa-sync-alt fa-spin"></i> Rotating ' + escHtml(label) + '&hellip;';

        var modal = new bootstrap.Modal(document.getElementById('rotationModal'));
        modal.show();

        var csrfToken = (document.querySelector('input[name="csrf_token"]') || {}).value || '';

        var formData = new FormData();
        formData.append('csrf_token', csrfToken);
        formData.append('key_type',   keyType);

        fetch('<?= htmlspecialchars(BASE_URL) ?>api/rotate_key.php', {
            method:      'POST',
            body:        formData,
            credentials: 'same-origin'
        })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('Server returned HTTP ' + response.status);
            }
            return response.json();
        })
        .then(function (data) {
            document.getElementById('rotationSpinner').style.display = 'none';

            // Render step list
            var stepsHtml = '';
            (data.steps || []).forEach(function (step) {
                var icon = step.status === 'ok'
                    ? '<span class="text-success fw-bold font-monospace">[OK]&nbsp;&nbsp;</span>'
                    : '<span class="text-danger fw-bold font-monospace">[FAIL]</span>';
                stepsHtml +=
                    '<div class="list-group-item px-2 py-1">' +
                    '<span class="me-2">' + icon + '</span>' +
                    '<span class="fw-semibold">' + escHtml(step.name) + '</span>' +
                    ' &mdash; <span class="text-muted">' + escHtml(step.message) + '</span>' +
                    '</div>';
            });
            document.getElementById('rotationSteps').innerHTML = stepsHtml;

            // Render result banner
            var resultDiv = document.getElementById('rotationResult');
            resultDiv.style.display = '';
            if (data.success) {
                resultDiv.innerHTML =
                    '<div class="alert alert-success mb-0">' +
                    '<i class="fas fa-check-circle"></i> <strong>Success!</strong> ' +
                    escHtml(label) + ' rotated successfully.' +
                    '</div>';
                titleEl.innerHTML = '<i class="fas fa-check-circle text-success"></i> ' + escHtml(label) + ' Rotated';
            } else {
                resultDiv.innerHTML =
                    '<div class="alert alert-danger mb-0">' +
                    '<i class="fas fa-exclamation-triangle"></i> <strong>Rotation failed.</strong> ' +
                    'Review the steps above. Configuration was not permanently changed (see step details for exceptions).' +
                    '</div>';
                titleEl.innerHTML = '<i class="fas fa-times-circle text-danger"></i> Rotation Failed';
            }
            document.getElementById('rotationCloseBtn').disabled = false;
        })
        .catch(function (err) {
            document.getElementById('rotationSpinner').style.display = 'none';
            var resultDiv = document.getElementById('rotationResult');
            resultDiv.style.display = '';
            resultDiv.innerHTML =
                '<div class="alert alert-danger mb-0">' +
                '<i class="fas fa-exclamation-triangle"></i> <strong>Request failed:</strong> ' +
                escHtml(err.message) +
                '</div>';
            document.getElementById('rotationCloseBtn').disabled = false;
            titleEl.innerHTML = '<i class="fas fa-times-circle text-danger"></i> Rotation Failed';
        });
    };
}());
</script>

<?php $theme->showFooter($context); ?>

