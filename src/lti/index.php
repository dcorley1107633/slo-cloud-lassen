<?php
declare(strict_types=1);

/**
 * LTI 1.1 integration and browser-side launch bootstrapper.
 *
 * This page is restricted to global administrators by the central route
 * policy. Launch targets are always resolved from canonical database IDs;
 * query-string CRNs, course numbers, and term codes are never trusted.
 */

require_once __DIR__ . '/../system/includes/init.php';

/**
 * Escape text for HTML output.
 */
function lti_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Apply one of the configured token formats to canonical values.
 *
 * @param array<string,string> $tokens
 */
function lti_apply_format(string $format, array $tokens): string
{
    $replacements = [];
    foreach ($tokens as $name => $value) {
        $replacements['{' . strtoupper($name) . '}'] = $value;
    }
    return strtr($format, $replacements);
}

// Resolve launch URL exactly as launch validation does. A configured absolute
// URL is required for a signed production launch.
$configuredLaunchUrl = trim((string)$config->get('lti.launch_url', ''));
$displayLaunchUrl = $configuredLaunchUrl !== ''
    ? $configuredLaunchUrl
    : BASE_URL . 'lti/launch.php';
$consumerKey = (string)$config->get('lti.consumer_key', '');
$consumerSecret = (string)$config->get('lti.consumer_secret', '');
$offeringField = trim((string)$config->get('lti.offering_field', 'lis_course_offering_sourcedid'));
$offeringFormat = (string)$config->get('lti.offering_format', '{CRN}.{TERM}');
$courseLabelField = trim((string)$config->get('lti.course_label_field', 'context_label'));
$courseLabelFormat = (string)$config->get('lti.course_label_format', '{COURSE}-{REST}');
$allowedSignatureMethods = array_values(array_filter(array_map(
    'trim',
    explode('||', trim((string)$config->get('lti.allowed_signature_methods', 'HMAC-SHA256||HMAC-SHA1'), '|'))
)));
$signatureMethod = in_array('HMAC-SHA256', $allowedSignatureMethods, true)
    ? 'HMAC-SHA256'
    : (in_array('HMAC-SHA1', $allowedSignatureMethods, true) ? 'HMAC-SHA1' : '');

$configurationProblems = [];
if (filter_var($configuredLaunchUrl, FILTER_VALIDATE_URL) === false) {
    $configurationProblems[] = 'Configure an absolute LTI launch URL.';
}
if ($consumerKey === '') {
    $configurationProblems[] = 'Configure an LTI consumer key.';
}
if ($consumerSecret === '') {
    $configurationProblems[] = 'Configure an LTI consumer secret.';
}
if ($signatureMethod === '') {
    $configurationProblems[] = 'Allow HMAC-SHA256 or HMAC-SHA1 signatures.';
}
if ($offeringField === '' || $courseLabelField === '') {
    $configurationProblems[] = 'Configure valid LTI offering and course-label field names.';
}

$targetType = is_string($_GET['target'] ?? null) ? strtolower(trim($_GET['target'])) : '';
$target = null;
$targetError = null;
$members = [];

if ($targetType === 'section') {
    $sectionPk = filter_var($_GET['section_pk'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    if ($sectionPk === false) {
        $targetError = 'Choose a valid section from the Sections screen.';
    } else {
        $result = $db->query(
            "SELECT s.sections_pk, s.section_id, s.crn, c.course_number, c.course_name, "
            . "t.terms_pk, t.term_name, t.term_code, t.banner_term "
            . "FROM {$dbPrefix}sections s "
            . "JOIN {$dbPrefix}courses c ON c.courses_pk = s.course_fk "
            . "JOIN {$dbPrefix}terms t ON t.terms_pk = s.term_fk "
            . "WHERE s.sections_pk = ?",
            [(int)$sectionPk],
            'i'
        );
        $row = $result ? $result->fetch() : false;
        if (!$row) {
            $targetError = 'The selected section is no longer available.';
        } elseif (trim((string)$row['crn']) === '' || trim((string)$row['banner_term']) === '') {
            $targetError = 'The selected section does not have a CRN and Banner term.';
        } else {
            $offeringCode = lti_apply_format($offeringFormat, [
                'crn' => (string)$row['crn'],
                'term' => (string)$row['banner_term'],
            ]);
            $courseLabel = lti_apply_format($courseLabelFormat, [
                'course' => (string)$row['course_number'],
                'rest' => (string)$row['crn'],
            ]);
            $target = [
                'type' => 'section',
                'title' => (string)$row['course_number'] . ' · Section ' . (string)$row['section_id'],
                'course_number' => (string)$row['course_number'],
                'course_name' => (string)$row['course_name'],
                'term_name' => (string)$row['term_name'],
                'term_code' => (string)$row['term_code'],
                'banner_term' => (string)$row['banner_term'],
                'offering_code' => $offeringCode,
                'course_label' => $courseLabel,
                'context_id' => 'section-' . (int)$row['sections_pk'] . '-' . (string)$row['banner_term'],
                'resource_link_id' => 'slo-assessment-section-' . (int)$row['sections_pk'],
                'return_url' => BASE_URL . 'administration/sections.php?term_fk=' . (int)$row['terms_pk'],
            ];
        }
    }
} elseif ($targetType === 'xlist') {
    $termFk = filter_var($_GET['term_fk'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1],
    ]);
    $xlistGroup = is_string($_GET['xlist_group'] ?? null) ? trim($_GET['xlist_group']) : '';
    if ($termFk === false || $xlistGroup === '' || strlen($xlistGroup) > 50) {
        $targetError = 'Choose a valid group from the Cross-Listed Sections screen.';
    } else {
        $result = $db->query(
            "SELECT sx.xlist_group, sx.crn AS mapped_crn, sx.course_number AS mapped_course_number, "
            . "s.sections_pk, s.section_id, s.crn, c.course_number, c.course_name, "
            . "t.terms_pk, t.term_name, t.term_code, t.banner_term "
            . "FROM {$dbPrefix}section_xlist sx "
            . "JOIN {$dbPrefix}terms t ON t.terms_pk = sx.term_fk "
            . "LEFT JOIN {$dbPrefix}sections s ON s.term_fk = t.terms_pk AND s.crn = sx.crn "
            . "LEFT JOIN {$dbPrefix}courses c ON c.courses_pk = s.course_fk "
            . "WHERE sx.term_fk = ? AND sx.xlist_group = ? "
            . "ORDER BY sx.course_number ASC, sx.crn ASC",
            [(int)$termFk, $xlistGroup],
            'is'
        );
        $rows = $result ? $result->fetchAll() : [];
        if ($rows === []) {
            $targetError = 'The selected cross-listed group is no longer available.';
        } else {
            $unresolved = false;
            foreach ($rows as $row) {
                if ((int)($row['sections_pk'] ?? 0) <= 0
                    || (string)($row['crn'] ?? '') !== (string)$row['mapped_crn']) {
                    $unresolved = true;
                }
                $members[] = [
                    'crn' => (string)$row['mapped_crn'],
                    'course_number' => (string)($row['course_number'] ?? $row['mapped_course_number'] ?? ''),
                    'section_id' => (string)($row['section_id'] ?? ''),
                    'resolved' => (int)($row['sections_pk'] ?? 0) > 0,
                ];
            }

            $first = $rows[0];
            if ($unresolved) {
                $targetError = 'Every CRN in the cross-listed group must map to an existing section before it can be launched.';
            } elseif (trim((string)$first['banner_term']) === '') {
                $targetError = 'The selected cross-listed group does not have a Banner term.';
            } else {
                // Cross-listed launches use the special XLS{GROUP}{BANNER_TERM}
                // form understood by launch.php and LtiLaunchContext.
                $offeringCode = 'XLS' . $xlistGroup . (string)$first['banner_term'];
                $courseNumber = 'XLS' . $xlistGroup;
                $courseLabel = lti_apply_format($courseLabelFormat, [
                    'course' => $courseNumber,
                    'rest' => 'Cross-listed group ' . $xlistGroup,
                ]);
                $target = [
                    'type' => 'xlist',
                    'title' => 'Cross-listed group ' . $xlistGroup,
                    'course_number' => $courseNumber,
                    'course_name' => 'Cross-listed group ' . $xlistGroup,
                    'term_name' => (string)$first['term_name'],
                    'term_code' => (string)$first['term_code'],
                    'banner_term' => (string)$first['banner_term'],
                    'offering_code' => $offeringCode,
                    'course_label' => $courseLabel,
                    'context_id' => 'xlist-' . rawurlencode($xlistGroup) . '-' . (string)$first['banner_term'],
                    'resource_link_id' => 'slo-assessment-xlist-' . hash('sha256', (int)$termFk . ':' . $xlistGroup),
                    'return_url' => BASE_URL . 'administration/xlist.php?term_fk=' . (int)$first['terms_pk'],
                ];
            }
        }
    }
} elseif ($targetType !== '') {
    $targetError = 'The requested LTI launch target type is not supported.';
}

$adminName = trim((string)($_SESSION['user_name'] ?? 'SLOCloud Administrator'));
$adminEmail = trim((string)($_SESSION['user_email'] ?? ''));
$simulatedUserId = 'slocloud-admin-' . (int)($_SESSION['user_id'] ?? 0);

use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$context = new ThemeContext([
    'layout' => 'admin',
    'pageTitle' => 'LTI Integration',
    'currentPage' => 'admin_lti',
    'breadcrumbs' => [
        ['url' => BASE_URL . 'administration/', 'label' => 'Home'],
        ['label' => 'LTI Integration'],
    ],
]);

// The missing admin context was the reason this page fell back to the plain
// default theme while every administration page used AdminLTE.
$theme = ThemeLoader::getActiveTheme(null, 'admin');
$theme->showHeader($context);
?>

<div class="row g-4">
    <div class="col-xl-8">
        <?php if ($targetError !== null): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-circle-exclamation me-2" aria-hidden="true"></i>
                <?= lti_h($targetError) ?>
            </div>
        <?php endif; ?>

        <?php if ($target !== null): ?>
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-rocket me-2" aria-hidden="true"></i>Launch Bootstrapper
                    </h2>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                        <div>
                            <div class="text-uppercase text-muted small fw-semibold">Selected launch context</div>
                            <h3 class="h4 mb-1"><?= lti_h($target['title']) ?></h3>
                            <div class="text-muted">
                                <?= lti_h($target['term_name']) ?> · Banner <?= lti_h($target['banner_term']) ?>
                            </div>
                        </div>
                        <span class="badge text-bg-<?= $target['type'] === 'xlist' ? 'info' : 'primary' ?> fs-6">
                            <?= $target['type'] === 'xlist' ? 'Cross-listed' : 'Section' ?>
                        </span>
                    </div>

                    <?php if ($target['type'] === 'xlist'): ?>
                        <div class="mb-4">
                            <div class="fw-semibold mb-2">Group members</div>
                            <div class="d-flex flex-wrap gap-2">
                                <?php foreach ($members as $member): ?>
                                    <span class="badge text-bg-light border text-dark">
                                        <?= lti_h($member['course_number']) ?> · CRN <?= lti_h($member['crn']) ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <dl class="row mb-4">
                        <dt class="col-sm-4">Offering identifier</dt>
                        <dd class="col-sm-8"><code><?= lti_h($target['offering_code']) ?></code></dd>
                        <dt class="col-sm-4">Course label</dt>
                        <dd class="col-sm-8"><code><?= lti_h($target['course_label']) ?></code></dd>
                        <dt class="col-sm-4">Launch endpoint</dt>
                        <dd class="col-sm-8 text-break"><code><?= lti_h($displayLaunchUrl) ?></code></dd>
                        <dt class="col-sm-4">Signature</dt>
                        <dd class="col-sm-8"><?= lti_h($signatureMethod !== '' ? $signatureMethod : 'Unavailable') ?></dd>
                    </dl>

                    <?php if ($configurationProblems !== []): ?>
                        <div class="alert alert-warning" role="alert">
                            <div class="fw-semibold mb-1">
                                <i class="fas fa-triangle-exclamation me-2" aria-hidden="true"></i>
                                Complete the LTI configuration before launching
                            </div>
                            <ul class="mb-0">
                                <?php foreach ($configurationProblems as $problem): ?>
                                    <li><?= lti_h($problem) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form id="ltiBootstrapper" novalidate>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="simulatedName" class="form-label">Simulated user name</label>
                                <input type="text" class="form-control" id="simulatedName"
                                       value="<?= lti_h($adminName) ?>" maxlength="200" required>
                            </div>
                            <div class="col-md-6">
                                <label for="simulatedEmail" class="form-label">Simulated user email</label>
                                <input type="email" class="form-control" id="simulatedEmail"
                                       value="<?= lti_h($adminEmail) ?>" maxlength="255" required>
                            </div>
                            <div class="col-md-6">
                                <label for="simulatedRole" class="form-label">LTI role</label>
                                <select class="form-select" id="simulatedRole">
                                    <option value="urn:lti:role:ims/lis/Instructor" selected>Instructor</option>
                                    <option value="urn:lti:role:ims/lis/Learner">Student</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="simulatedUserId" class="form-label">LTI user ID</label>
                                <input type="text" class="form-control font-monospace" id="simulatedUserId"
                                       value="<?= lti_h($simulatedUserId) ?>" maxlength="255" required>
                            </div>
                        </div>

                        <div class="card bg-body-tertiary mt-4 mb-0">
                            <div class="card-body">
                                <h4 class="h6 mb-3">
                                    <i class="fas fa-fingerprint me-2" aria-hidden="true"></i>Signature preview
                                </h4>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="oauthTimestampPreview" class="form-label small">OAuth timestamp</label>
                                        <input type="text" class="form-control form-control-sm font-monospace"
                                               id="oauthTimestampPreview" readonly>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="oauthNoncePreview" class="form-label small">OAuth nonce</label>
                                        <input type="text" class="form-control form-control-sm font-monospace"
                                               id="oauthNoncePreview" readonly>
                                    </div>
                                    <div class="col-12">
                                        <label for="oauthSignaturePreview" class="form-label small">OAuth signature</label>
                                        <input type="text" class="form-control form-control-sm font-monospace"
                                               id="oauthSignaturePreview" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div id="signingStatus" class="small mt-3" aria-live="polite">
                            Review the launch context, then sign the request.
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-4">
                            <button type="button" class="btn btn-outline-primary" id="signLaunchRequest"
                                    <?= $configurationProblems !== [] ? 'disabled' : '' ?>>
                                <i class="fas fa-signature me-2" aria-hidden="true"></i>Sign Launch Request
                            </button>
                            <button type="button" class="btn btn-primary" id="submitSignedLaunch" disabled>
                                <i class="fas fa-arrow-up-right-from-square me-2" aria-hidden="true"></i>Launch LTI Tool
                            </button>
                            <a href="<?= lti_h($target['return_url']) ?>" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2" aria-hidden="true"></i>Back to records
                            </a>
                        </div>
                    </form>
                </div>
                <div class="card-footer text-muted small">
                    Signing happens in this browser. Changing any launch field invalidates the signature,
                    and every signature receives a fresh timestamp and cryptographically random nonce.
                </div>
            </div>
        <?php else: ?>
            <div class="card card-primary card-outline">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="fas fa-rocket me-2" aria-hidden="true"></i>LTI Launch Bootstrapper
                    </h2>
                </div>
                <div class="card-body">
                    <div class="text-center py-4">
                        <div class="display-6 text-primary mb-3">
                            <i class="fas fa-plug-circle-bolt" aria-hidden="true"></i>
                        </div>
                        <h3 class="h4">Choose a launch context</h3>
                        <p class="text-muted mx-auto" style="max-width: 42rem;">
                            Start from a section or cross-listed group. SLOCloud will bring the canonical
                            CRN, Banner term, course label, and group membership here for review.
                        </p>
                        <div class="d-flex flex-wrap justify-content-center gap-2">
                            <a href="<?= BASE_URL ?>administration/sections.php" class="btn btn-primary">
                                <i class="fas fa-chalkboard-user me-2" aria-hidden="true"></i>Choose a Section
                            </a>
                            <a href="<?= BASE_URL ?>administration/xlist.php" class="btn btn-outline-primary">
                                <i class="fas fa-layer-group me-2" aria-hidden="true"></i>Choose a Cross-list
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-xl-4">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-circle-info me-2" aria-hidden="true"></i>Integration Status</h2>
            </div>
            <div class="card-body">
                <ul class="list-group list-group-flush">
                    <li class="list-group-item px-0 d-flex justify-content-between gap-3">
                        <span>Protocol</span><span class="fw-semibold">LTI 1.1</span>
                    </li>
                    <li class="list-group-item px-0 d-flex justify-content-between gap-3">
                        <span>Consumer key</span>
                        <span class="badge text-bg-<?= $consumerKey !== '' ? 'success' : 'warning' ?>">
                            <?= $consumerKey !== '' ? 'Configured' : 'Missing' ?>
                        </span>
                    </li>
                    <li class="list-group-item px-0 d-flex justify-content-between gap-3">
                        <span>Shared secret</span>
                        <span class="badge text-bg-<?= $consumerSecret !== '' ? 'success' : 'warning' ?>">
                            <?= $consumerSecret !== '' ? 'Configured' : 'Missing' ?>
                        </span>
                    </li>
                    <li class="list-group-item px-0 d-flex justify-content-between gap-3">
                        <span>Launch URL</span>
                        <span class="badge text-bg-<?= filter_var($configuredLaunchUrl, FILTER_VALIDATE_URL) !== false ? 'success' : 'warning' ?>">
                            <?= filter_var($configuredLaunchUrl, FILTER_VALIDATE_URL) !== false ? 'Configured' : 'Missing' ?>
                        </span>
                    </li>
                    <li class="list-group-item px-0 d-flex justify-content-between gap-3">
                        <span>Signature validation</span>
                        <span class="badge text-bg-<?= $config->get('lti.validate_signatures', true) ? 'success' : 'danger' ?>">
                            <?= $config->get('lti.validate_signatures', true) ? 'Enabled' : 'Disabled' ?>
                        </span>
                    </li>
                </ul>
            </div>
            <div class="card-footer d-flex flex-wrap gap-2">
                <a href="<?= BASE_URL ?>administration/config.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-gear me-2" aria-hidden="true"></i>Open LTI Configuration
                </a>
                <a href="<?= BASE_URL ?>administration/canvas_deploy.php" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-cloud-arrow-up me-2" aria-hidden="true"></i>Deploy to Canvas courses
                </a>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-shield-halved me-2" aria-hidden="true"></i>Launch checks</h2>
            </div>
            <div class="card-body">
                <p class="small text-muted">
                    The bootstrapper uses the same endpoint and validation path as an LMS:
                </p>
                <ul class="small mb-0">
                    <li>Canonical launch URL</li>
                    <li>Consumer key and HMAC signature</li>
                    <li>Five-minute timestamp window</li>
                    <li>One-time nonce replay protection</li>
                    <li>Database-bound section authorization</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php if ($target !== null && $configurationProblems === []): ?>
<script>
(() => {
    'use strict';

    const bootstrapper = <?= json_encode([
        'launchUrl' => $configuredLaunchUrl,
        'consumerKey' => $consumerKey,
        'consumerSecret' => $consumerSecret,
        'signatureMethod' => $signatureMethod,
        'offeringField' => $offeringField,
        'courseLabelField' => $courseLabelField,
        'offeringCode' => $target['offering_code'],
        'courseLabel' => $target['course_label'],
        'courseName' => $target['course_name'],
        'contextId' => $target['context_id'],
        'resourceLinkId' => $target['resource_link_id'],
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    const form = document.getElementById('ltiBootstrapper');
    const status = document.getElementById('signingStatus');
    const signButton = document.getElementById('signLaunchRequest');
    const launchButton = document.getElementById('submitSignedLaunch');
    const timestampPreview = document.getElementById('oauthTimestampPreview');
    const noncePreview = document.getElementById('oauthNoncePreview');
    const signaturePreview = document.getElementById('oauthSignaturePreview');
    const editableControls = Array.from(form.querySelectorAll('input:not([readonly]), select'));
    let signedParameters = null;
    let signedAt = 0;
    const oauthEncode = (value) => encodeURIComponent(String(value))
        .replace(/[!'()*]/g, (character) => '%' + character.charCodeAt(0).toString(16).toUpperCase());

    const toBase64 = (buffer) => {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (const byte of bytes) {
            binary += String.fromCharCode(byte);
        }
        return btoa(binary);
    };

    const makeNonce = () => {
        const bytes = crypto.getRandomValues(new Uint8Array(16));
        return Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    };

    const splitName = (fullName) => {
        const parts = fullName.trim().split(/\s+/);
        return {
            given: parts.shift() || 'LTI',
            family: parts.join(' ') || 'User',
        };
    };

    const sign = async (parameters) => {
        const launchUrl = new URL(bootstrapper.launchUrl);
        const signingParameters = new Map();
        launchUrl.searchParams.forEach((value, key) => signingParameters.set(key, value));
        Object.entries(parameters).forEach(([key, value]) => signingParameters.set(key, String(value)));

        const parameterString = Array.from(signingParameters.entries())
            .sort(([left], [right]) => left < right ? -1 : (left > right ? 1 : 0))
            .map(([key, value]) => oauthEncode(key) + '=' + oauthEncode(value))
            .join('&');
        const baseString = 'POST&' + oauthEncode(bootstrapper.launchUrl) + '&' + oauthEncode(parameterString);
        const signingKey = oauthEncode(bootstrapper.consumerSecret) + '&';
        const hash = bootstrapper.signatureMethod === 'HMAC-SHA256' ? 'SHA-256' : 'SHA-1';
        const cryptoKey = await crypto.subtle.importKey(
            'raw',
            new TextEncoder().encode(signingKey),
            {name: 'HMAC', hash},
            false,
            ['sign']
        );
        return toBase64(await crypto.subtle.sign('HMAC', cryptoKey, new TextEncoder().encode(baseString)));
    };

    const submitLaunch = (parameters, targetWindow) => {
        const launchForm = document.createElement('form');
        launchForm.method = 'post';
        launchForm.action = bootstrapper.launchUrl;
        launchForm.target = targetWindow;
        launchForm.hidden = true;

        Object.entries(parameters).forEach(([name, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = String(value);
            launchForm.appendChild(input);
        });
        document.body.appendChild(launchForm);
        launchForm.submit();
        launchForm.remove();
    };

    const invalidateSignature = () => {
        signedParameters = null;
        signedAt = 0;
        timestampPreview.value = '';
        noncePreview.value = '';
        signaturePreview.value = '';
        launchButton.disabled = true;
        status.className = 'small mt-3 text-muted';
        status.textContent = 'Launch parameters changed. Sign the request again.';
    };

    editableControls.forEach((control) => {
        control.addEventListener('input', invalidateSignature);
        control.addEventListener('change', invalidateSignature);
    });

    signButton.addEventListener('click', async () => {
        if (!form.reportValidity()) {
            return;
        }
        if (!window.crypto || !window.crypto.subtle) {
            status.className = 'small mt-3 text-danger';
            status.textContent = 'This browser does not support Web Crypto signing.';
            return;
        }

        signButton.disabled = true;
        launchButton.disabled = true;
        editableControls.forEach((control) => {
            control.disabled = true;
        });
        status.className = 'small mt-3 text-primary';
        status.innerHTML = '<i class="fas fa-spinner fa-spin me-2" aria-hidden="true"></i>Signing launch request…';

        try {
            const fullName = document.getElementById('simulatedName').value.trim();
            const email = document.getElementById('simulatedEmail').value.trim();
            const name = splitName(fullName);
            const parameters = {
                lti_message_type: 'basic-lti-launch-request',
                lti_version: 'LTI-1p0',
                resource_link_id: bootstrapper.resourceLinkId,
                user_id: document.getElementById('simulatedUserId').value.trim(),
                roles: document.getElementById('simulatedRole').value,
                lis_person_name_full: fullName,
                lis_person_name_given: name.given,
                lis_person_name_family: name.family,
                lis_person_contact_email_primary: email,
                context_id: bootstrapper.contextId,
                context_title: bootstrapper.courseName,
                oauth_callback: 'about:blank',
                oauth_consumer_key: bootstrapper.consumerKey,
                oauth_nonce: makeNonce(),
                oauth_signature_method: bootstrapper.signatureMethod,
                oauth_timestamp: Math.floor(Date.now() / 1000),
                oauth_version: '1.0',
                custom_slocloud_launch_source: 'admin-bootstrapper',
            };
            parameters[bootstrapper.offeringField] = bootstrapper.offeringCode;
            parameters[bootstrapper.courseLabelField] = bootstrapper.courseLabel;
            if (!Object.prototype.hasOwnProperty.call(parameters, 'context_label')) {
                parameters.context_label = bootstrapper.courseLabel;
            }

            parameters.oauth_signature = await sign(parameters);
            signedParameters = parameters;
            signedAt = Date.now();
            timestampPreview.value = String(parameters.oauth_timestamp);
            noncePreview.value = parameters.oauth_nonce;
            signaturePreview.value = parameters.oauth_signature;
            status.className = 'small mt-3 text-success';
            status.innerHTML = '<i class="fas fa-circle-check me-2" aria-hidden="true"></i>Request signed. It is ready to launch.';
            launchButton.disabled = false;
        } catch (error) {
            signedParameters = null;
            status.className = 'small mt-3 text-danger';
            status.textContent = 'Unable to sign the launch request. Verify the LTI configuration and try again.';
        } finally {
            editableControls.forEach((control) => {
                control.disabled = false;
            });
            signButton.disabled = false;
        }
    });

    launchButton.addEventListener('click', () => {
        if (!signedParameters) {
            invalidateSignature();
            return;
        }
        // The receiver permits a five-minute skew. Force a new signature after
        // four minutes so the request cannot expire while the new tab opens.
        if ((Date.now() - signedAt) > 240000) {
            invalidateSignature();
            status.className = 'small mt-3 text-warning';
            status.textContent = 'The signature expired. Sign the request again before launching.';
            return;
        }

        const windowName = 'slocloud-lti-' + Date.now();
        const launchWindow = window.open('', windowName);
        status.className = 'small mt-3 text-primary';
        status.innerHTML = '<i class="fas fa-spinner fa-spin me-2" aria-hidden="true"></i>Opening the simulated launch…';
        submitLaunch(signedParameters, launchWindow ? windowName : '_self');
        invalidateSignature();
        status.className = 'small mt-3 text-success';
        status.innerHTML = '<i class="fas fa-circle-check me-2" aria-hidden="true"></i>Launch submitted. Sign again to create another launch.';
    });
})();
</script>
<?php endif; ?>

<?php $theme->showFooter($context); ?>
