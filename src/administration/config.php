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

// Use raw (stored) values for the config editor display. The LTI shared secret
// is the one encrypted value an administrator must be able to read, to copy it
// into the LMS, so it is shown decrypted; save() re-encrypts it.
$configPath = __DIR__ . '/../config/config.yaml';
$configData = $config->all();
$configData['lti']['consumer_secret'] = (string)$config->get('lti.consumer_secret', '');
$lookupKeyConfigured = (string)$config->get('security.student_lookup_key', '') !== '';

// Where key.yaml is now, and what pointed the application there.
require_once __DIR__ . '/../system/Core/KeyFolder.php';
require_once __DIR__ . '/../system/Core/Saml.php';
$keyEnvironmentPath = (string)(getenv('SLOCloud_KEY_PATH') ?: '');
$activeKeyFile = \SLOCloud\Core\KeyFolder::activeKeyFile($config->keyPathCandidates());
$keySource = \SLOCloud\Core\KeyFolder::keySource($activeKeyFile, $keyEnvironmentPath, (string)$config->getRaw('security.key_path', ''));
$keyInWebRoot = $activeKeyFile !== null && \SLOCloud\Core\KeyFolder::isInsideWebRoot(
    $activeKeyFile,
    \SLOCloud\Core\KeyFolder::webRoots([dirname(__DIR__), (string)($_SERVER['DOCUMENT_ROOT'] ?? '')])
);
$keyFolderExample = PHP_OS_FAMILY === 'Windows' ? 'D:\\SLO Cloud 5\\keys' : '/etc/slocloud/keys';
// SLOCloud_KEY_PATH overrides config.yaml, so a move could not take effect.
$keyMoveAllowed = $activeKeyFile !== null && $keyEnvironmentPath === '';

// Field metadata from the template.
require_once __DIR__ . '/../system/includes/config_form.php';
$templatePath = __DIR__ . '/../config/config.yaml.template';
// The color scheme has its own page (color_scheme.php), so it is neither shown nor saved here.
$fields = array_diff_key(\SLOCloud\Core\Config::schemaFromTemplate($templatePath), ['theme.color_scheme' => true]);

// Handle POST requests
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die('This form has expired. Reload the page and try again.');
    }
    
    try {
        // Fields that cannot be changed after installation
        $lockedFields = ['database.driver', 'database.name', 'database.prefix'];
        $connectionKeys = ['host', 'port', 'username', 'password'];
        $databaseBefore = [];
        foreach ($connectionKeys as $connectionKey) {
            $databaseBefore[$connectionKey] = $config->get('database.' . $connectionKey);
        }

        applyPostedConfigFields($config, $fields, $_POST, $lockedFields);

        // Links are built as BASE_URL . 'page.php', so the path must end in /.
        $config->set('app.base_url', rtrim((string)$config->get('app.base_url', '/'), '/') . '/');

        $logDirectorySetting = trim((string)$config->get('logging.log_directory', ''));
        if ($logDirectorySetting !== '' && !\SLOCloud\Core\Security::isAbsolutePath($logDirectorySetting)) {
            throw new \Exception('Log Directory must be a full path outside the web root (for example D:\\SLOCloud5\\logs), or empty to use the application data folder.');
        }
        if ($logDirectorySetting !== '' && !is_dir($logDirectorySetting) && !@mkdir($logDirectorySetting, 0700, true)) {
            throw new \Exception('Log Directory does not exist and SLO Cloud cannot create it. Create the folder and give the application pool modify access to it, or leave Log Directory empty.');
        }

        // Every page opens the database with these settings, so a typo here would
        // take the whole site down. Refuse to save settings that cannot connect.
        $databaseAfter = [];
        foreach ($connectionKeys as $connectionKey) {
            $databaseAfter[$connectionKey] = $config->get('database.' . $connectionKey);
        }
        if ($databaseAfter !== $databaseBefore) {
            try {
                \SLOCloud\Core\Database::open(array_merge($config->allDecrypted()['database'] ?? [], $databaseAfter));
            } catch (\PDOException $e) {
                $logger->warning('Configuration save refused: new database settings cannot connect', ['error' => $e->getMessage()]);
                throw new \Exception('SLO Cloud could not connect to the database with the new Database Settings, so nothing was saved. Check the host, port, username and password. Details: ' . $e->getMessage());
            }
        }

        // Single sign-on is offered on the login page as soon as it is enabled,
        // so it is not enabled until its settings can work.
        $samlSettings = \SLOCloud\Core\Saml::configFrom($config);
        if ($samlSettings['enabled'] && ($samlProblems = \SLOCloud\Core\Saml::problems($samlSettings)) !== []) {
            throw new \Exception('Single sign-on cannot be enabled yet, so nothing was saved. ' . implode(' ', $samlProblems));
        }

        // Save configuration
        $config->save();
        
        $successMessage = 'Configuration updated successfully';
        
        // Reload config data
        $configData = $config->all();
        $configData['lti']['consumer_secret'] = (string)$config->get('lti.consumer_secret', '');
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
    'canvas' => ['icon' => 'fas fa-cloud-arrow-up', 'title' => 'Canvas Deployment Settings'],
    'saml' => ['icon' => 'fas fa-right-to-bracket', 'title' => 'Single Sign-On (SAML) Settings'],
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

<p>
    <i class="fas fa-palette" aria-hidden="true"></i>
    The site color scheme is chosen on the
    <a href="<?= BASE_URL ?>administration/color_scheme.php">Color Scheme</a> page.
</p>

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
                    echo renderConfigField($key, $meta, $value);
                    ?>
                </div>

                <?php endforeach; ?>
            </div>
            <?php if ($sectionName === 'saml'): ?>
            <?php
            $samlValues = \SLOCloud\Core\Saml::configFrom($config);
            $samlShown = [
                'Identifier (Entity ID)' => $samlValues['sp_entity_id'],
                'Reply URL (Assertion Consumer Service URL)' => $samlValues['sp_acs_url'],
                'Sign-on URL' => \SLOCloud\Core\Saml::derivedUrls($samlValues['site_url'], (string)$config->get('app.base_url', '/'))['sso_url'],
                'Logout URL (optional)' => $samlValues['sp_slo_url'],
                'SP metadata' => \SLOCloud\Core\Saml::derivedUrls($samlValues['site_url'], (string)$config->get('app.base_url', '/'))['metadata_url'],
            ];
            ?>
            <h3 class="h5 mt-3">Values to enter at the identity provider</h3>
            <?php if ($samlValues['sp_acs_url'] === ''): ?>
            <p class="text-muted mb-0">Enter the Site Address and save to see the addresses to give your identity provider.</p>
            <?php else: ?>
            <p class="text-muted">Built from the saved settings. In Microsoft Entra ID these go in Basic SAML Configuration; see docs/ENTRA_SSO_SETUP.md.</p>
            <dl class="mb-0">
                <?php foreach ($samlShown as $samlLabel => $samlValue): ?>
                <?php if ($samlValue === '') continue; ?>
                <dt><?= htmlspecialchars($samlLabel) ?></dt>
                <dd><code class="user-select-all"><?= htmlspecialchars($samlValue) ?></code></dd>
                <?php endforeach; ?>
            </dl>
            <?php endif; ?>
            <?php endif; ?>
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

<!-- Keys Folder Section -->
<div class="card mb-3">
    <div class="card-header">
        <h2 class="card-title">
            <i class="fas fa-folder" aria-hidden="true"></i>
            Encryption Keys Folder
        </h2>
    </div>
    <div class="card-body">
        <dl class="row mb-3">
            <dt class="col-sm-3">Key file in use</dt>
            <dd class="col-sm-9">
                <?php if ($activeKeyFile !== null): ?>
                    <code id="keyFileInUse"><?= htmlspecialchars($activeKeyFile) ?></code>
                <?php else: ?>
                    <span class="text-danger">key.yaml was not found</span>
                <?php endif; ?>
            </dd>
            <dt class="col-sm-3">Set by</dt>
            <dd class="col-sm-9">
                <?php if ($keySource === \SLOCloud\Core\KeyFolder::SOURCE_ENVIRONMENT): ?>
                    The <code>SLOCloud_KEY_PATH</code> environment variable
                <?php elseif ($keySource === \SLOCloud\Core\KeyFolder::SOURCE_CONFIG): ?>
                    <code>security.key_path</code> in config.yaml
                <?php else: ?>
                    The default location (no <code>security.key_path</code> in config.yaml)
                <?php endif; ?>
            </dd>
        </dl>
        <?php if ($keyInWebRoot): ?>
            <div class="alert alert-danger py-2 mb-3">
                <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                The key file is inside the web root, where only the web server's deny rules protect it. Move it to a folder outside the web root.
            </div>
        <?php endif; ?>
        <?php if ($keyEnvironmentPath !== ''): ?>
            <div class="alert alert-info py-2 mb-3">
                <i class="fas fa-info-circle" aria-hidden="true"></i>
                The <code>SLOCloud_KEY_PATH</code> environment variable overrides config.yaml, so the folder cannot be moved here.
                Move the file on the server and update that variable instead.
            </div>
        <?php endif; ?>
        <p class="text-muted mb-3">
            Moving copies key.yaml and its key rotation backups to the new folder, checks the copies,
            saves the new location as <code>security.key_path</code> in config.yaml, confirms SLO Cloud loads the key from there,
            and only then deletes the old copies. If any step fails, everything is left as it was.
            <strong>Back up key.yaml and config.yaml first.</strong>
        </p>
        <?php if ($keyMoveAllowed && !MAINTENANCE_MODE): ?>
            <div class="alert alert-warning py-2 mb-3">
                <i class="fas fa-tools" aria-hidden="true"></i>
                Moving the keys folder requires Maintenance Mode. You can check a folder now; enable Maintenance Mode and save the configuration to move.
            </div>
        <?php endif; ?>
        <div class="mb-3">
            <label for="keyFolderTarget" class="form-label">New folder</label>
            <input type="text" class="form-control" id="keyFolderTarget" autocomplete="off" spellcheck="false"
                   placeholder="<?= htmlspecialchars($keyFolderExample) ?>" aria-describedby="keyFolderTarget-help"
                   <?= $keyMoveAllowed ? '' : 'disabled' ?>>
            <small class="form-text text-muted" id="keyFolderTarget-help">
                Full path of a folder outside the web root. The folder must already exist, be empty of key files, and allow only
                <?= PHP_OS_FAMILY === 'Windows'
                    ? 'Administrators, SYSTEM and the application pool (modify access)'
                    : 'the web server account (for example <code>chmod 700</code>)' ?>.
            </small>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-primary" onclick="keyFolderAction('check')"
                    <?= $keyMoveAllowed ? '' : 'disabled aria-disabled="true"' ?>>
                <i class="fas fa-clipboard-check" aria-hidden="true"></i> Check Folder
            </button>
            <button type="button" class="btn btn-warning" onclick="keyFolderAction('move')"
                    <?= $keyMoveAllowed && MAINTENANCE_MODE ? '' : 'disabled aria-disabled="true"' ?>>
                <i class="fas fa-truck-moving" aria-hidden="true"></i> Move Keys Folder
            </button>
        </div>
    </div>
</div>

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
        <?php if (!MAINTENANCE_MODE): ?>
            <div class="alert alert-warning py-2 mb-3">
                <i class="fas fa-tools" aria-hidden="true"></i>
                Key rotation requires Maintenance Mode. Enable it and save the configuration first.
            </div>
        <?php endif; ?>
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
                        <button type="button" class="btn btn-warning mt-auto align-self-start" onclick="rotateKey('config')"
                                <?= MAINTENANCE_MODE ? '' : 'disabled aria-disabled="true"' ?>>
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
                        <button type="button" class="btn btn-danger mt-auto align-self-start" onclick="rotateKey('student')"
                                <?= MAINTENANCE_MODE ? '' : 'disabled aria-disabled="true"' ?>>
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
                        <span class="visually-hidden" id="rotationSpinnerLabel">Working...</span>
                    </div>
                    <p class="mt-2 text-muted mb-0" id="rotationSpinnerText">Rotating key, please wait&hellip;</p>
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

    var operationUrls = {
        rotate: '<?= htmlspecialchars(BASE_URL) ?>api/rotate_key.php',
        folder: '<?= htmlspecialchars(BASE_URL) ?>api/move_key_folder.php'
    };

    // Shows a key operation's steps in the progress dialog.
    // text: {busy, spinner, success, failure, failedTitle, successTitle}
    function runKeyOperation(url, fields, text, onSuccess) {
        document.getElementById('rotationSteps').innerHTML    = '';
        document.getElementById('rotationSpinner').style.display = '';
        document.getElementById('rotationSpinnerText').textContent = text.spinner;
        document.getElementById('rotationSpinnerLabel').textContent = text.spinner;
        document.getElementById('rotationResult').style.display  = 'none';
        document.getElementById('rotationResult').innerHTML       = '';
        document.getElementById('rotationCloseBtn').disabled      = true;

        var titleEl = document.getElementById('rotationModalLabel');
        titleEl.innerHTML = '<i class="fas fa-sync-alt fa-spin" aria-hidden="true"></i> ' + escHtml(text.busy) + '&hellip;';

        var modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('rotationModal'));
        modal.show();

        var formData = new FormData();
        formData.append('csrf_token', (document.querySelector('input[name="csrf_token"]') || {}).value || '');
        Object.keys(fields).forEach(function (name) { formData.append(name, fields[name]); });

        fetch(url, {
            method:      'POST',
            body:        formData,
            credentials: 'same-origin',
            headers:     { Accept: 'application/json' }
        })
        .then(function (response) {
            // Refusals (expired form, maintenance mode off) carry their steps as JSON.
            return response.json().catch(function () {
                throw new Error('Server returned HTTP ' + response.status);
            }).then(function (data) {
                if (response.status === 401 && data.error === 'session_ended' && data.login_url) {
                    window.location.href = data.login_url;
                    throw new Error(data.message || 'Your session has ended.');
                }
                if (!response.ok && !data.steps) {
                    throw new Error(data.message || data.error || ('Server returned HTTP ' + response.status));
                }
                return data;
            });
        })
        .then(function (data) {
            document.getElementById('rotationSpinner').style.display = 'none';

            var stepsHtml = '';
            (data.steps || []).forEach(function (step) {
                var icon = step.status === 'ok'
                    ? '<span class="text-success fw-bold font-monospace">[OK]&nbsp;&nbsp;</span>'
                    : step.status === 'warn'
                        ? '<span class="text-warning-emphasis fw-bold font-monospace">[WARN]</span>'
                        : '<span class="text-danger fw-bold font-monospace">[FAIL]</span>';
                stepsHtml +=
                    '<div class="list-group-item px-2 py-1">' +
                    '<span class="me-2">' + icon + '</span>' +
                    '<span class="fw-semibold">' + escHtml(step.name) + '</span>' +
                    ' &mdash; <span class="text-muted">' + escHtml(step.message) + '</span>' +
                    '</div>';
            });
            document.getElementById('rotationSteps').innerHTML = stepsHtml;

            var resultDiv = document.getElementById('rotationResult');
            resultDiv.style.display = '';
            if (data.success) {
                resultDiv.innerHTML =
                    '<div class="alert alert-success mb-0" role="status">' +
                    '<i class="fas fa-check-circle" aria-hidden="true"></i> <strong>Success!</strong> ' + escHtml(text.success) +
                    '</div>';
                titleEl.innerHTML = '<i class="fas fa-check-circle text-success" aria-hidden="true"></i> ' + escHtml(text.successTitle);
                if (onSuccess) onSuccess();
            } else {
                resultDiv.innerHTML =
                    '<div class="alert alert-danger mb-0" role="alert">' +
                    '<i class="fas fa-exclamation-triangle" aria-hidden="true"></i> <strong>' + escHtml(text.failedTitle) + '.</strong> ' +
                    escHtml(text.failure) +
                    '</div>';
                titleEl.innerHTML = '<i class="fas fa-times-circle text-danger" aria-hidden="true"></i> ' + escHtml(text.failedTitle);
            }
            document.getElementById('rotationCloseBtn').disabled = false;
        })
        .catch(function (err) {
            document.getElementById('rotationSpinner').style.display = 'none';
            var resultDiv = document.getElementById('rotationResult');
            resultDiv.style.display = '';
            resultDiv.innerHTML =
                '<div class="alert alert-danger mb-0" role="alert">' +
                '<i class="fas fa-exclamation-triangle" aria-hidden="true"></i> <strong>Request failed:</strong> ' +
                escHtml(err.message) +
                '</div>';
            document.getElementById('rotationCloseBtn').disabled = false;
            titleEl.innerHTML = '<i class="fas fa-times-circle text-danger" aria-hidden="true"></i> ' + escHtml(text.failedTitle);
        });
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
            lookup:  'This will decrypt every student ID and replace every indexed lookup hash.'
        };

        var label = labels[keyType] || keyType;

        if (!confirm('Rotate ' + label + '?\n\n' + warnings[keyType] + '\nMaintenance Mode must remain enabled until the operation finishes.\n\nEnsure you have a backup of key.yaml and config.yaml before proceeding.')) {
            return;
        }

        runKeyOperation(operationUrls.rotate, { key_type: keyType }, {
            busy:         'Rotating ' + label,
            spinner:      'Rotating key, please wait...',
            success:      label + ' rotated successfully.',
            successTitle: label + ' Rotated',
            failedTitle:  'Rotation failed',
            failure:      'Review the steps above. Configuration was not permanently changed (see step details for exceptions).'
        });
    };

    window.keyFolderAction = function (action) {
        var input = document.getElementById('keyFolderTarget');
        var folder = input.value.trim();
        if (folder === '') {
            input.setAttribute('aria-invalid', 'true');
            input.focus();
            return;
        }
        input.removeAttribute('aria-invalid');

        if (action === 'move' && !confirm('Move the encryption keys to\n' + folder + '?\n\n' +
                'The old copies are deleted once SLO Cloud loads the key from the new folder.\n' +
                'Maintenance Mode must remain enabled until the move finishes.\n\n' +
                'Ensure you have a backup of key.yaml and config.yaml before proceeding.')) {
            return;
        }

        var moving = action === 'move';
        runKeyOperation(operationUrls.folder, { action: action, folder: folder }, {
            busy:         moving ? 'Moving keys folder' : 'Checking folder',
            spinner:      moving ? 'Moving the keys, please wait...' : 'Checking the folder, please wait...',
            success:      moving ? 'SLO Cloud now loads its keys from the new folder.' : 'The folder can hold the keys. Nothing was changed.',
            successTitle: moving ? 'Keys Folder Moved' : 'Folder Ready',
            failedTitle:  moving ? 'Move failed' : 'Folder not ready',
            failure:      moving ? 'Review the steps above. Unless a step says otherwise, everything was left as it was.' : 'Review the steps above. Nothing was changed.'
        }, moving ? function () {
            document.getElementById('rotationModal').addEventListener('hidden.bs.modal', function () {
                window.location.reload();
            }, { once: true });
        } : null);
    };
}());
</script>

<?php $theme->showFooter($context); ?>

