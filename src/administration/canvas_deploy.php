<?php
declare(strict_types=1);

require_once __DIR__ . '/../system/includes/init.php';
require_once __DIR__ . '/../system/Core/CanvasDeployRun.php';

use SLOCloud\Core\CanvasDeployRun;
use SLOCloud\Core\ThemeContext;
use SLOCloud\Core\ThemeLoader;

$lti = [
    'launch_url' => trim((string)$config->get('lti.launch_url', '')),
    'consumer_key' => trim((string)$config->get('lti.consumer_key', '')),
    'consumer_secret' => (string)$config->get('lti.consumer_secret', ''),
];
$canvasDeploy = [
    'apiUrl' => BASE_URL . 'administration/canvas_deploy_api.php',
    'configUrl' => BASE_URL . 'administration/config.php',
    'csrfToken' => (string)$_SESSION['csrf_token'],
    'defaults' => CanvasDeployRun::defaultsFromConfig(
        static fn(string $key, mixed $default): mixed => $config->get($key, $default)
    ),
    'prerequisites' => CanvasDeployRun::prerequisites($lti),
];
$canvasDeploy['ready'] = !in_array(false, array_column($canvasDeploy['prerequisites'], 'ok'), true);

$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => 'Deploy to Canvas',
    'currentPage' => 'admin_canvas_deploy',
    'breadcrumbs' => [
        ['url' => BASE_URL . 'administration/', 'label' => 'Home'],
        ['url' => BASE_URL . 'lti/', 'label' => 'LTI Integration'],
        ['label' => 'Deploy to Canvas'],
    ],
]);
$theme = ThemeLoader::getActiveTheme(null, 'admin');
$theme->showHeader($context);
?>
<!-- canvas-deploy:view -->
<?php
$h = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$cdDefaults = $canvasDeploy['defaults'];
$cdScopeValue = (string)$cdDefaults['scope_value'];
?>
<p class="lead">Install the SLO Cloud LTI 1.1 tool in Canvas courses that do not have it yet. Courses that already have it are left alone, so this can be run again at any time.</p>

<div class="row g-4">
    <div class="col-xl-8">
        <form id="canvasDeployForm" novalidate>
            <div class="card card-primary card-outline mb-4">
                <div class="card-header">
                    <h2 class="card-title">1. Connect to Canvas</h2>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="cdBaseUrl" class="form-label">Canvas URL</label>
                        <input type="url" class="form-control" id="cdBaseUrl" name="base_url" value="<?= $h($cdDefaults['base_url']) ?>"
                            placeholder="https://college.instructure.com" autocomplete="url" spellcheck="false" required
                            aria-describedby="cdBaseUrlHelp cdBaseUrlError">
                        <div id="cdBaseUrlHelp" class="form-text">The Canvas instance paired with this SLO Cloud server.</div>
                        <div id="cdBaseUrlError" class="invalid-feedback"></div>
                    </div>
                    <div class="mb-3">
                        <label for="cdToken" class="form-label">Canvas API token</label>
                        <input type="password" class="form-control" id="cdToken" name="canvas_token" autocomplete="off" spellcheck="false" required
                            aria-describedby="cdTokenHelp cdTokenError">
                        <div id="cdTokenHelp" class="form-text">A Canvas account admin token, ideally one used only for deployment. It is never saved. When a run starts, this field is cleared and the server keeps only an encrypted copy until the run ends.</div>
                        <div id="cdTokenError" class="invalid-feedback"></div>
                    </div>
                    <button type="button" class="btn btn-outline-primary" id="cdTestConnection">Test connection</button>
                    <div id="cdConnectionStatus" class="mt-3" role="status" aria-live="polite"></div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="card-title">2. What to install</h2>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="cdLinkName" class="form-label">Link name</label>
                        <input type="text" class="form-control" id="cdLinkName" name="link_name" maxlength="100" value="<?= $h($cdDefaults['link_name']) ?>" required
                            aria-describedby="cdLinkNameHelp cdLinkNameError">
                        <div id="cdLinkNameHelp" class="form-text">Shown as the course navigation tab (instructors only) and as the module link title.</div>
                        <div id="cdLinkNameError" class="invalid-feedback"></div>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="cdCreateModule" name="create_module" <?= $cdDefaults['create_module'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="cdCreateModule">Also add an unpublished module with a link to SLO Cloud</label>
                    </div>
                    <div class="mb-3 ms-4">
                        <label for="cdModuleName" class="form-label">Module name</label>
                        <input type="text" class="form-control" id="cdModuleName" name="module_name" maxlength="100" value="<?= $h($cdDefaults['module_name']) ?>"
                            aria-describedby="cdModuleNameHelp cdModuleNameError">
                        <div id="cdModuleNameHelp" class="form-text">An existing module with this name is reused.</div>
                        <div id="cdModuleNameError" class="invalid-feedback"></div>
                    </div>
                    <div class="mb-0">
                        <label for="cdDelay" class="form-label">Delay between courses (milliseconds)</label>
                        <input type="number" class="form-control" id="cdDelay" name="course_delay_ms" min="0" max="10000" step="50" inputmode="numeric"
                            value="<?= $h($cdDefaults['course_delay_ms']) ?>" required aria-describedby="cdDelayHelp cdDelayError">
                        <div id="cdDelayHelp" class="form-text">A pause between courses (0 to 10,000) to go easy on Canvas during busy hours.</div>
                        <div id="cdDelayError" class="invalid-feedback"></div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="card-title">3. Courses</h2>
                </div>
                <div class="card-body">
                    <fieldset aria-describedby="cdScopeError">
                        <legend class="form-label fs-6">Which courses should be checked?</legend>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="scope" id="cdScopeCourse" value="course" <?= $cdDefaults['scope'] === 'course' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="cdScopeCourse">Course by Canvas ID</label>
                        </div>
                        <div class="ms-4 mb-2" id="cdCourseIdsWrap">
                            <label for="cdCourseIds" class="form-label">Canvas course IDs</label>
                            <input type="text" class="form-control" id="cdCourseIds" inputmode="numeric" spellcheck="false"
                                value="<?= $cdDefaults['scope'] === 'course' ? $h($cdScopeValue) : '' ?>" aria-describedby="cdCourseIdsHelp cdCourseIdsError">
                            <div id="cdCourseIdsHelp" class="form-text">The number in the course address, for example 12345 in /courses/12345. Separate several with commas.</div>
                            <div id="cdCourseIdsError" class="invalid-feedback"></div>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="scope" id="cdScopeTerm" value="term" <?= $cdDefaults['scope'] === 'term' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="cdScopeTerm">Term</label>
                        </div>
                        <div class="ms-4 mb-2" id="cdTermWrap">
                            <div class="d-none" id="cdTermSelectWrap">
                                <label for="cdTermSelect" class="form-label">Term</label>
                                <select class="form-select" id="cdTermSelect" aria-describedby="cdTermError"></select>
                            </div>
                            <div id="cdTermIdWrap">
                                <label for="cdTermId" class="form-label">Canvas term ID</label>
                                <input type="text" class="form-control" id="cdTermId" inputmode="numeric" spellcheck="false"
                                    value="<?= $cdDefaults['scope'] === 'term' ? $h($cdScopeValue) : '' ?>" aria-describedby="cdTermIdHelp cdTermError">
                                <div id="cdTermIdHelp" class="form-text">Test the connection to choose from a list of terms instead.</div>
                            </div>
                            <div id="cdTermError" class="invalid-feedback"></div>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="scope" id="cdScopeActive" value="active" <?= $cdDefaults['scope'] === 'active' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="cdScopeActive">All active courses (not concluded)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="scope" id="cdScopeAll" value="all" <?= $cdDefaults['scope'] === 'all' ? 'checked' : '' ?>>
                            <label class="form-check-label" for="cdScopeAll">All courses, including concluded</label>
                        </div>
                        <div id="cdScopeError" class="invalid-feedback"></div>
                    </fieldset>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="card-title">4. Review and run</h2>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <caption>Settings for this run compared with the saved defaults</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Setting</th>
                                    <th scope="col">Saved default</th>
                                    <th scope="col">This run</th>
                                </tr>
                            </thead>
                            <tbody id="cdSummaryBody"></tbody>
                        </table>
                    </div>
                    <div class="form-check mb-3 d-none" id="cdSaveDefaultsWrap">
                        <input class="form-check-input" type="checkbox" id="cdSaveDefaults">
                        <label class="form-check-label" for="cdSaveDefaults">Save these settings as the new defaults</label>
                    </div>
                    <div id="cdFormError" class="alert alert-danger d-none" role="alert"></div>
                    <?php if (!$canvasDeploy['ready']): ?>
                        <div class="alert alert-warning" role="alert">
                            Finish the missing prerequisites on the <a href="<?= $h($canvasDeploy['configUrl']) ?>">Configuration</a> page before running.
                        </div>
                    <?php endif; ?>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-outline-primary" id="cdDryRun" <?= $canvasDeploy['ready'] ? '' : 'disabled' ?>>Dry run</button>
                        <button type="button" class="btn btn-primary" id="cdDeploy" <?= $canvasDeploy['ready'] ? '' : 'disabled' ?>>Deploy</button>
                    </div>
                    <p class="form-text mt-2 mb-0">A dry run checks every course and reports what would change without writing anything to Canvas.</p>
                </div>
            </div>
        </form>

        <div class="card mb-4 d-none" id="cdProgressCard">
            <div class="card-header">
                <h2 class="card-title" id="cdProgressTitle" tabindex="-1">Run in progress</h2>
            </div>
            <div class="card-body">
                <p id="cdProgressStatus" class="mb-2" role="status" aria-live="polite"></p>
                <div class="progress mb-3" id="cdProgressBar" role="progressbar" aria-label="Run in progress">
                    <div class="progress-bar progress-bar-striped progress-bar-animated w-100"></div>
                </div>
                <dl class="row mb-3" id="cdCounts"></dl>
                <div id="cdRunError" class="alert alert-danger d-none" role="alert"></div>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-primary d-none" id="cdRetry">Retry</button>
                    <button type="button" class="btn btn-outline-danger" id="cdCancel">Cancel run</button>
                </div>
                <ul id="cdRunNotes" class="mt-3 mb-0"></ul>
            </div>
        </div>

        <div class="card mb-4 d-none" id="cdResultsCard">
            <div class="card-header">
                <h2 class="card-title">Results</h2>
            </div>
            <div class="card-body">
                <div class="d-flex flex-wrap gap-3 align-items-center mb-2">
                    <div class="form-check mb-0">
                        <input class="form-check-input" type="checkbox" id="cdOnlyIssues">
                        <label class="form-check-label" for="cdOnlyIssues">Show only warnings, failures, and skipped courses</label>
                    </div>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="cdDownloadCsv">Download CSV</button>
                </div>
                <p id="cdResultsNote" class="form-text"></p>
                <div class="table-responsive">
                    <table class="table table-sm table-striped align-middle">
                        <caption>Per-course results</caption>
                        <thead>
                            <tr>
                                <th scope="col">Course ID</th>
                                <th scope="col">Course</th>
                                <th scope="col">SIS ID</th>
                                <th scope="col">Term</th>
                                <th scope="col">Tool</th>
                                <th scope="col">Module</th>
                                <th scope="col">Status</th>
                                <th scope="col">Notes</th>
                            </tr>
                        </thead>
                        <tbody id="cdResultsBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card mb-4">
            <div class="card-header">
                <h2 class="card-title">Prerequisites</h2>
            </div>
            <div class="card-body">
                <ul class="list-group list-group-flush">
                    <?php foreach ($canvasDeploy['prerequisites'] as $check): ?>
                        <li class="list-group-item px-0">
                            <div class="d-flex justify-content-between gap-3">
                                <span><?= $h($check['label']) ?></span>
                                <span class="badge text-bg-<?= $check['ok'] ? 'success' : 'danger' ?>"><?= $check['ok'] ? 'Ready' : 'Missing' ?></span>
                            </div>
                            <div class="small text-break"><?= $h($check['detail']) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-header">
                <h2 class="card-title">What gets installed</h2>
            </div>
            <div class="card-body">
                <ul class="mb-0">
                    <li>A course navigation tab that only instructors, TAs, and designers can see.</li>
                    <li>Optionally, an unpublished module with a link to SLO Cloud.</li>
                    <li>Courses that already have SLO Cloud, directly or from their account, are left alone.</li>
                    <li>Blueprint child courses are skipped; deploy to the Blueprint course and let sync apply it.</li>
                    <li>Keep this tab open during a run. If it closes, run the same selection again to continue.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
(() => {
    const config = <?= json_encode([
        'apiUrl' => $canvasDeploy['apiUrl'],
        'csrfToken' => $canvasDeploy['csrfToken'],
        'defaults' => $cdDefaults,
        'ready' => $canvasDeploy['ready'],
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES) ?>;
    const byId = (id) => document.getElementById(id);
    const form = byId('canvasDeployForm');
    const els = {
        baseUrl: byId('cdBaseUrl'), token: byId('cdToken'), test: byId('cdTestConnection'), connection: byId('cdConnectionStatus'),
        linkName: byId('cdLinkName'), createModule: byId('cdCreateModule'), moduleName: byId('cdModuleName'), delay: byId('cdDelay'),
        courseIdsWrap: byId('cdCourseIdsWrap'), courseIds: byId('cdCourseIds'), termWrap: byId('cdTermWrap'),
        termSelectWrap: byId('cdTermSelectWrap'), termSelect: byId('cdTermSelect'), termIdWrap: byId('cdTermIdWrap'), termId: byId('cdTermId'),
        summary: byId('cdSummaryBody'), saveWrap: byId('cdSaveDefaultsWrap'), save: byId('cdSaveDefaults'), formError: byId('cdFormError'),
        dryRun: byId('cdDryRun'), deploy: byId('cdDeploy'),
        progressCard: byId('cdProgressCard'), progressTitle: byId('cdProgressTitle'), progressStatus: byId('cdProgressStatus'),
        progressBar: byId('cdProgressBar'), counts: byId('cdCounts'), runError: byId('cdRunError'), retry: byId('cdRetry'),
        cancel: byId('cdCancel'), runNotes: byId('cdRunNotes'),
        resultsCard: byId('cdResultsCard'), onlyIssues: byId('cdOnlyIssues'), csv: byId('cdDownloadCsv'),
        resultsNote: byId('cdResultsNote'), results: byId('cdResultsBody'),
    };
    const fieldInputs = {
        base_url: ['cdBaseUrl', 'cdBaseUrlError'], canvas_token: ['cdToken', 'cdTokenError'], link_name: ['cdLinkName', 'cdLinkNameError'],
        module_name: ['cdModuleName', 'cdModuleNameError'], course_delay_ms: ['cdDelay', 'cdDelayError'], scope: ['cdScopeCourse', 'cdScopeError'],
    };
    const labels = {
        tool: { created: 'Created', would_create: 'Would create', present: 'Already present', present_account: 'Present (account level)', skipped: 'Skipped', failed: 'Failed' },
        module: { created: 'Module created', added: 'Link added', would_create: 'Would create module', would_add: 'Would add link', present: 'Already present', off: 'Not requested', skipped: 'Skipped', failed: 'Failed' },
        status: { ok: 'OK', warning: 'Warning', failed: 'Failed', skipped: 'Skipped' },
        scope: { course: 'Course by Canvas ID', term: 'Term', active: 'All active courses', all: 'All courses, including concluded' },
    };
    const MAX_TABLE_ROWS = 2000;
    let terms = [];
    let run = null;

    const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
    const selectedScope = () => (form.querySelector('input[name="scope"]:checked') || {}).value || '';
    const termListShown = () => !els.termSelectWrap.classList.contains('d-none');

    async function post(action, data) {
        const body = new FormData();
        body.append('action', action);
        body.append('csrf_token', config.csrfToken);
        Object.entries(data || {}).forEach(([key, value]) => body.append(key, value));
        let response;
        try {
            response = await fetch(config.apiUrl, { method: 'POST', body, credentials: 'same-origin', headers: { Accept: 'application/json' } });
        } catch (error) {
            throw { error: 'network', message: 'SLO Cloud could not be reached. Check your connection, then retry.' };
        }
        let payload = null;
        try { payload = await response.json(); } catch (error) { payload = null; }
        if (!payload) {
            if (response.redirected) throw { error: 'session', message: 'Your SLO Cloud session ended. Sign in again in another tab, then retry.' };
            if (response.status === 403) throw { error: 'forbidden', message: 'Access denied. Only global administrators can run a deployment or save its defaults.' };
            throw { error: 'http_' + response.status, message: 'SLO Cloud returned an unexpected response (HTTP ' + response.status + ').' };
        }
        if (!response.ok || payload.ok !== true) throw payload;
        return payload;
    }

    function showAlert(container, kind, text) {
        container.replaceChildren();
        if (!text) return;
        const alert = document.createElement('div');
        alert.className = 'alert alert-' + kind + ' mb-0';
        alert.textContent = text;
        container.appendChild(alert);
    }

    function setFormError(text) {
        els.formError.textContent = text || '';
        els.formError.classList.toggle('d-none', !text);
    }

    function clearFieldErrors() {
        form.querySelectorAll('.is-invalid').forEach((input) => { input.classList.remove('is-invalid'); input.removeAttribute('aria-invalid'); });
        form.querySelectorAll('.invalid-feedback').forEach((feedback) => { feedback.textContent = ''; feedback.classList.remove('d-block'); });
    }

    function markFieldErrors(fields) {
        let first = null;
        Object.entries(fields || {}).forEach(([field, message]) => {
            let target = fieldInputs[field];
            if (field === 'scope_value') {
                target = selectedScope() === 'course' ? ['cdCourseIds', 'cdCourseIdsError'] : [termListShown() ? 'cdTermSelect' : 'cdTermId', 'cdTermError'];
            }
            if (!target) return;
            const input = byId(target[0]);
            const feedback = byId(target[1]);
            input.classList.add('is-invalid');
            input.setAttribute('aria-invalid', 'true');
            feedback.textContent = message;
            feedback.classList.add('d-block');
            first = first || input;
        });
        if (first) first.focus();
    }

    function currentSettings() {
        const scope = selectedScope();
        let scopeValue = '';
        if (scope === 'course') scopeValue = els.courseIds.value.trim();
        if (scope === 'term') scopeValue = termListShown() ? els.termSelect.value : els.termId.value.trim();
        return {
            base_url: els.baseUrl.value.trim(),
            link_name: els.linkName.value.trim(),
            create_module: els.createModule.checked ? '1' : '0',
            module_name: els.moduleName.value.trim(),
            course_delay_ms: els.delay.value.trim(),
            scope,
            scope_value: scopeValue,
        };
    }

    function describe(key, value, scope) {
        if (key === 'create_module') return value === true || value === '1' ? 'Yes' : 'No';
        if (key === 'scope') return labels.scope[value] || 'Not chosen';
        if (key === 'scope_value') {
            if (scope === 'term') {
                const term = terms.find((item) => String(item.id) === String(value));
                return term ? term.name + ' (' + term.id + ')' : (value ? 'Term ' + value : 'Not chosen');
            }
            if (scope === 'course') return value || 'None entered';
            return 'Not needed';
        }
        if (key === 'course_delay_ms') return String(value) + ' ms';
        return String(value || '') || 'Not set';
    }

    function comparable(key, value) {
        if (key === 'create_module') return value === true || value === '1' ? '1' : '0';
        if (key === 'base_url') return String(value || '').trim().replace(/\/+$/, '').toLowerCase();
        return String(value ?? '').trim();
    }

    function renderSummary() {
        const current = currentSettings();
        const rows = [
            ['Canvas URL', 'base_url'], ['Link name', 'link_name'], ['Add module', 'create_module'], ['Module name', 'module_name'],
            ['Delay between courses', 'course_delay_ms'], ['Courses', 'scope'], ['Course IDs or term', 'scope_value'],
        ];
        let changed = 0;
        els.summary.replaceChildren();
        rows.forEach(([label, key]) => {
            const saved = config.defaults[key];
            const now = current[key];
            const differs = comparable(key, saved) !== comparable(key, now);
            if (differs) changed++;
            const tr = document.createElement('tr');
            if (differs) tr.className = 'table-warning';
            const th = document.createElement('th');
            th.scope = 'row';
            th.textContent = label;
            const savedCell = document.createElement('td');
            savedCell.textContent = describe(key, saved, config.defaults.scope);
            const nowCell = document.createElement('td');
            nowCell.textContent = describe(key, now, current.scope);
            if (differs) {
                const badge = document.createElement('span');
                badge.className = 'badge text-bg-warning ms-2';
                badge.textContent = 'Changed';
                nowCell.appendChild(badge);
            }
            tr.append(th, savedCell, nowCell);
            els.summary.appendChild(tr);
        });
        els.saveWrap.classList.toggle('d-none', changed === 0);
        if (changed === 0) els.save.checked = false;
    }

    function syncScope() {
        const scope = selectedScope();
        els.courseIdsWrap.classList.toggle('d-none', scope !== 'course');
        els.termWrap.classList.toggle('d-none', scope !== 'term');
        els.moduleName.disabled = !els.createModule.checked || !!(run && run.active);
        renderSummary();
    }

    function setBusy(button, busy, busyText) {
        if (busy) {
            button.dataset.label = button.textContent;
            button.textContent = busyText;
        } else if (button.dataset.label) {
            button.textContent = button.dataset.label;
        }
        button.disabled = busy;
    }

    function lockForm(locked) {
        form.querySelectorAll('input, select, button').forEach((control) => {
            if (control === els.dryRun || control === els.deploy) {
                control.disabled = locked || !config.ready;
            } else {
                control.disabled = locked;
            }
        });
        if (!locked) els.moduleName.disabled = !els.createModule.checked;
    }

    async function loadTerms() {
        try {
            const response = await post('terms', { base_url: els.baseUrl.value.trim(), canvas_token: els.token.value });
            if (response.fallback || !response.terms.length) {
                els.termSelectWrap.classList.add('d-none');
                els.termIdWrap.classList.remove('d-none');
                byId('cdTermIdHelp').textContent = 'This token cannot list terms. Enter the Canvas term ID.';
                return;
            }
            terms = response.terms;
            const selected = els.termId.value.trim() || (config.defaults.scope === 'term' ? String(config.defaults.scope_value) : '');
            els.termSelect.replaceChildren();
            terms.forEach((term) => {
                const option = document.createElement('option');
                option.value = String(term.id);
                option.textContent = term.name + ' (' + term.id + ')';
                option.selected = String(term.id) === selected;
                els.termSelect.appendChild(option);
            });
            els.termSelectWrap.classList.remove('d-none');
            els.termIdWrap.classList.add('d-none');
            renderSummary();
        } catch (error) {
            byId('cdTermIdHelp').textContent = 'Terms could not be listed (' + (error.message || 'unknown error') + '). Enter the Canvas term ID.';
        }
    }

    els.test.addEventListener('click', async () => {
        clearFieldErrors();
        setBusy(els.test, true, 'Testing…');
        showAlert(els.connection, 'info', 'Contacting Canvas…');
        try {
            const result = await post('test_connection', { base_url: els.baseUrl.value.trim(), canvas_token: els.token.value });
            if (result.is_admin) {
                const names = result.accounts.map((account) => account.name || ('Account ' + account.id)).join(', ');
                showAlert(els.connection, 'success', 'Connected to ' + result.origin + ' as ' + (result.user || 'the token owner') + '. Canvas account admin for: ' + names + '.');
                await loadTerms();
            } else {
                showAlert(els.connection, 'warning', 'Connected as ' + (result.user || 'the token owner') + ', but this token is not a Canvas account admin. Deployment needs an account admin token.');
            }
        } catch (error) {
            showAlert(els.connection, 'danger', error.message || 'The connection test failed.');
            markFieldErrors(error.fields);
        } finally {
            setBusy(els.test, false);
        }
    });

    function renderCounts(counts, dryRun) {
        const items = [
            ['Courses processed', counts.processed],
            [dryRun ? 'Tools to create' : 'Tools created', dryRun ? counts.tool_would_create : counts.tool_created],
            ['Tool already present', counts.tool_present],
            [dryRun ? 'Module links to add' : 'Module links added', dryRun ? counts.module_would_add : counts.module_created + counts.module_added],
            ['Module link already present', counts.module_present],
            ['Warnings', counts.warnings],
            ['Failed', counts.failed],
            ['Skipped (Blueprint child)', counts.skipped],
            ['Concluded, not included', counts.out_of_scope],
        ];
        els.counts.replaceChildren();
        items.forEach(([label, value]) => {
            const dt = document.createElement('dt');
            dt.className = 'col-sm-8 fw-normal';
            dt.textContent = label;
            const dd = document.createElement('dd');
            dd.className = 'col-sm-4 mb-1 fw-semibold';
            dd.textContent = String(value || 0);
            els.counts.append(dt, dd);
        });
    }

    const isIssue = (row) => row.status !== 'ok';

    function resultRow(row) {
        const tr = document.createElement('tr');
        [
            String(row.course_id), row.name, row.sis_course_id, row.term,
            labels.tool[row.tool] || row.tool, labels.module[row.module] || row.module,
            labels.status[row.status] || row.status, (row.notes || []).join(' '),
        ].forEach((value) => {
            const td = document.createElement('td');
            td.textContent = value || '';
            tr.appendChild(td);
        });
        return tr;
    }

    function renderResults() {
        const visible = run.results.filter((row) => !els.onlyIssues.checked || isIssue(row));
        els.results.replaceChildren(...visible.slice(0, MAX_TABLE_ROWS).map(resultRow));
        els.resultsNote.textContent = visible.length > MAX_TABLE_ROWS
            ? 'Showing the first ' + MAX_TABLE_ROWS + ' of ' + visible.length + ' rows. The CSV includes every course.'
            : '';
    }

    function appendResults(rows) {
        run.results.push(...rows);
        renderResults();
    }

    function csvCell(value) {
        let text = String(value ?? '');
        if (/^[=+\-@\t\r]/.test(text)) text = "'" + text;
        return '"' + text.replace(/"/g, '""') + '"';
    }

    els.csv.addEventListener('click', () => {
        if (!run) return;
        const header = ['course_id', 'course_name', 'sis_course_id', 'term', 'tool', 'module', 'status', 'notes'];
        const lines = [header.map(csvCell).join(',')];
        run.results.forEach((row) => {
            lines.push([row.course_id, row.name, row.sis_course_id, row.term, row.tool, row.module, row.status, (row.notes || []).join(' | ')].map(csvCell).join(','));
        });
        const stamp = new Date().toISOString().slice(0, 16).replace(/[-:]/g, '').replace('T', '-');
        const link = document.createElement('a');
        link.href = URL.createObjectURL(new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }));
        link.download = 'slocloud-canvas-deploy' + (run.dryRun ? '-dry-run' : '') + '-' + stamp + '.csv';
        document.body.appendChild(link);
        link.click();
        setTimeout(() => { URL.revokeObjectURL(link.href); link.remove(); }, 0);
    });
    els.onlyIssues.addEventListener('change', () => { if (run) renderResults(); });

    const beforeUnload = (event) => { event.preventDefault(); event.returnValue = ''; };

    function endRun(title, message) {
        window.removeEventListener('beforeunload', beforeUnload);
        run.active = false;
        run.runKey = null;
        els.progressTitle.textContent = title;
        els.progressStatus.textContent = message;
        els.progressBar.classList.add('d-none');
        els.cancel.classList.add('d-none');
        els.retry.classList.add('d-none');
        lockForm(false);
        els.progressTitle.focus();
    }

    async function closeRun(cancelled) {
        let counts = run.counts;
        try {
            const response = await post(cancelled ? 'run_cancel' : 'run_finish', { run_id: run.runId, run_key: run.runKey });
            counts = response.counts || counts;
        } catch (error) {
            if (error.error !== 'run_missing') run.notes.push('The run ended but could not be closed cleanly: ' + (error.message || 'unknown error'));
        }
        if (counts) renderCounts(counts, run.dryRun);
        const label = run.dryRun ? 'Dry run' : 'Deployment';
        const processed = counts ? counts.processed : run.results.length;
        endRun(
            cancelled ? label + ' cancelled' : label + ' complete',
            (cancelled ? 'Stopped after ' : 'Finished: ') + processed + ' courses checked. Review the results below or download the CSV.'
        );
    }

    async function loop() {
        els.runError.classList.add('d-none');
        els.retry.classList.add('d-none');
        els.progressBar.classList.remove('d-none');
        while (run && run.active) {
            if (run.cancelRequested) { await closeRun(true); return; }
            let batch;
            try {
                batch = await post('run_batch', { run_id: run.runId, run_key: run.runKey });
            } catch (error) {
                if (['run_missing', 'run_key_rejected', 'run_forbidden', 'run_locked'].includes(error.error)) {
                    endRun('Run stopped', error.message || 'The run is no longer active.');
                    return;
                }
                run.paused = true;
                els.runError.textContent = (error.message || 'The batch failed.') + ' Retry continues from the same place.';
                els.runError.classList.remove('d-none');
                els.retry.classList.remove('d-none');
                els.progressBar.classList.add('d-none');
                els.progressStatus.textContent = 'Paused after an error.';
                return;
            }
            run.counts = batch.counts;
            appendResults(batch.results);
            renderCounts(batch.counts, run.dryRun);
            els.progressStatus.textContent = 'Processed ' + batch.counts.processed + ' courses so far. Last batch: '
                + batch.processed + ' in ' + (batch.elapsed_ms / 1000).toFixed(1) + ' seconds.';
            if (batch.done) { await closeRun(false); return; }
            if (batch.delay_ms > 0) await sleep(batch.delay_ms);
        }
    }

    els.retry.addEventListener('click', () => {
        if (!run || !run.active) return;
        run.paused = false;
        els.progressStatus.textContent = 'Retrying…';
        loop();
    });

    els.cancel.addEventListener('click', () => {
        if (!run || !run.active) return;
        if (run.paused) { closeRun(true); return; }
        run.cancelRequested = true;
        els.cancel.disabled = true;
        els.progressStatus.textContent = 'Cancelling after the current batch…';
    });

    async function startRun(dryRun) {
        if (run && run.active) return;
        clearFieldErrors();
        setFormError('');
        const settings = currentSettings();
        if (!els.token.value.trim()) {
            markFieldErrors({ canvas_token: 'Enter a Canvas API token.' });
            return;
        }
        if (!dryRun) {
            const scopeText = describe('scope', settings.scope) + (settings.scope === 'course' || settings.scope === 'term' ? ': ' + describe('scope_value', settings.scope_value, settings.scope) : '');
            if (!window.confirm('Deploy SLO Cloud to ' + scopeText + '?\n\nThis creates the tool' + (settings.create_module === '1' ? ' and module link' : '') + ' in every course that does not have it yet. Existing installs are left alone.')) {
                return;
            }
        }
        lockForm(true);
        try {
            if (els.save.checked && !els.saveWrap.classList.contains('d-none')) {
                const saved = await post('save_defaults', settings);
                config.defaults = Object.assign({}, config.defaults, saved.defaults);
                renderSummary();
            }
            const started = await post('run_start', Object.assign({}, settings, { dry_run: dryRun ? '1' : '0', canvas_token: els.token.value }));
            els.token.value = '';
            run = { active: true, runId: started.run_id, runKey: started.run_key, dryRun, results: [], counts: null, notes: [], cancelRequested: false, paused: false };
            window.addEventListener('beforeunload', beforeUnload);
            els.progressCard.classList.remove('d-none');
            els.resultsCard.classList.remove('d-none');
            els.cancel.classList.remove('d-none');
            els.cancel.disabled = false;
            els.progressTitle.textContent = dryRun ? 'Dry run in progress' : 'Deployment in progress';
            els.progressStatus.textContent = 'Starting…';
            els.runNotes.replaceChildren(...(started.notes || []).map((note) => {
                const li = document.createElement('li');
                li.textContent = note;
                return li;
            }));
            renderCounts({}, dryRun);
            renderResults();
            els.progressTitle.focus();
            await loop();
        } catch (error) {
            lockForm(false);
            setFormError(error.message || 'The run could not start.');
            markFieldErrors(error.fields);
        }
    }

    els.dryRun.addEventListener('click', () => startRun(true));
    els.deploy.addEventListener('click', () => startRun(false));
    form.addEventListener('submit', (event) => event.preventDefault());
    form.addEventListener('input', renderSummary);
    form.addEventListener('change', syncScope);
    syncScope();
})();
</script>
<!-- /canvas-deploy:view -->
<?php $theme->showFooter($context); ?>
