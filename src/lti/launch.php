<?php
declare(strict_types=1);

require_once __DIR__ . '/../system/includes/init.php';
require_once __DIR__ . '/../system/includes/message_page.php';

// LTI installations commonly change LMS hostnames during upgrades or migrations.
// Keep HTTPS embedding compatible while retaining every other global CSP rule.
header('Content-Security-Policy: ' . \SLOCloud\Core\Security::contentSecurityPolicy(["'self'", 'https:']));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    render_message_page('error', 'Invalid Request', 'LTI launches must use POST method.');
    exit;
}

$remoteIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$forwardedIp = \SLOCloud\Core\Security::isTrustedProxy($remoteIp)
    ? trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0])
    : '';
$clientIp = $forwardedIp !== '' ? $forwardedIp : $remoteIp;
$clientIp = filter_var($clientIp, FILTER_VALIDATE_IP) ? $clientIp : (filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ? $_SERVER['REMOTE_ADDR'] : 'unknown');

// OAuth validation — exits on failure
require_once __DIR__ . '/oauth_validate.php';

// Validate 3-digit status flag from oauth_validate.php
$ltiAuthStatusFlag = isset($ltiAuthStatusFlag) && preg_match('/^[12][0-2][0-2]$/', $ltiAuthStatusFlag)
    ? $ltiAuthStatusFlag
    : '000';

// Parse user
$firstName = $_POST['lis_person_name_given'] ?? '';
$lastName  = $_POST['lis_person_name_family'] ?? '';
if (empty($firstName) && empty($lastName) && !empty($_POST['lis_person_name_full'])) {
    [$firstName, $lastName] = array_pad(explode(' ', $_POST['lis_person_name_full'], 2), 2, '');
}
$firstName = $firstName ?: 'LTI';
$lastName  = $lastName  ?: 'User';

$roles        = strtolower($_POST['roles'] ?? '');
$isInstructor = (bool)preg_match('/instructor|faculty|teacher/', $roles);
$isStudent    = (bool)preg_match('/learner|student/', $roles);
$role         = $isInstructor ? 'Faculty' : 'Student';

// Parse course context using configurable field names and tokenized formats
$offeringField      = $config->get('lti.offering_field', 'lis_course_offering_sourcedid');
$offeringFormat     = $config->get('lti.offering_format', '{CRN}.{TERM}');
$courseLabelField   = $config->get('lti.course_label_field', 'context_label');
$courseLabelFormat  = $config->get('lti.course_label_format', '{COURSE}-{REST}');

$offeringRaw = $_POST[$offeringField] ?? '';

// Shared with the Canvas deployment preview so both read SIS IDs identically.
$offering = \SLOCloud\Core\LtiLaunchContext::parseOffering($offeringFormat, (string)$offeringRaw);
$crn    = $offering['crn'];
$termId = $offering['term'];

$labelTokens  = \SLOCloud\Core\LtiLaunchContext::parseTokenFormat($courseLabelFormat, (string)($_POST[$courseLabelField] ?? ''));
$courseNumber = $labelTokens['course'] ?? null;
$courseName   = $_POST['context_title'] ?? '';

// Auto-create term/course/section if needed
if ($crn && $termId && $courseNumber && stripos($crn, 'XLS') !== 0) {
    try {
        $isMssql = in_array($db->getDriver(), ['mssql', 'sqlsrv']);
        $now     = $isMssql ? 'GETDATE()' : 'NOW()';
        $top1    = $isMssql ? 'SELECT TOP 1' : 'SELECT';
        $limit1  = $isMssql ? '' : ' LIMIT 1';

        // Term
        $row = $db->query("$top1 terms_pk FROM {$dbPrefix}terms WHERE banner_term = ?$limit1", [$termId], 's')->fetch();
        if ($row) {
            $termFk = (int)$row['terms_pk'];
        } else {
            $year = strlen($termId) >= 4 ? substr($termId, 0, 4) : null;
            $db->query("INSERT INTO {$dbPrefix}terms (term_code, banner_term, term_name, academic_year, start_date, end_date, created_at, updated_at) VALUES (?, ?, ?, ?, '1970-01-01', '1970-01-01', $now, $now)",
                [$termId, $termId, "Term $termId", $year], 'ssss');
            $termFk = $db->getInsertId();
        }

        // Course
        $row = $db->query("$top1 courses_pk FROM {$dbPrefix}courses WHERE course_number = ?$limit1", [$courseNumber], 's')->fetch();
        if ($row) {
            $courseFk = (int)$row['courses_pk'];
        } else {
            $db->query("INSERT INTO {$dbPrefix}courses (course_number, course_name, term_fk, discipline_fk, created_at, updated_at) VALUES (?, ?, ?, NULL, $now, $now)",
                [$courseNumber, $courseName ?: $courseNumber, $termFk], 'ssi');
            $courseFk = $db->getInsertId();
        }

        // Section
        $row = $db->query(
            "$top1 s.sections_pk FROM {$dbPrefix}sections s JOIN {$dbPrefix}terms t ON s.term_fk = t.terms_pk WHERE s.crn = ? AND t.banner_term = ?$limit1",
            [$crn, $termId], 'ss'
        )->fetch();
        if (!$row) {
            $instructor = $isInstructor ? trim("$firstName $lastName") : null;
            $db->query("INSERT INTO {$dbPrefix}sections (course_fk, term_fk, section_id, crn, instructor_name, created_at, updated_at) VALUES (?, ?, ?, ?, ?, $now, $now)",
                [$courseFk, $termFk, '01', $crn, $instructor], 'iisss');
        }
    } catch (\Exception $e) {
        $logger->error('LTI auto-create: ' . $e->getMessage());
    }
}

// Generate a per-launch nonce so multiple concurrent LTI launches do not
// overwrite each other's session data. All launch data is stored under
// $_SESSION['lti_launches'][$ltiToken] and the token is forwarded to
// assessment.php via the query string (?lt=<token>).
$ltiToken = bin2hex(random_bytes(16));

if (!isset($_SESSION['lti_launches']) || !is_array($_SESSION['lti_launches'])) {
    $_SESSION['lti_launches'] = [];
}

// Prune stale launches older than 8 hours to prevent unbounded session growth.
// Use $nowTs (Unix timestamp) to avoid collision with the $now SQL string used
// in the auto-create block above.
$launchTtl = 28800;
$nowTs = time();
foreach ($_SESSION['lti_launches'] as $tok => $launch) {
    $lastActivity = $launch['lti_last_ping'] ?? $launch['lti_launched_at'] ?? 0;
    if (($nowTs - $lastActivity) > $launchTtl) {
        unset($_SESSION['lti_launches'][$tok]);
    }
}

$launchData = [
    'lti_authenticated'            => true,
    'lti_user_id'                  => $_POST['user_id'] ?? '',
    'lti_user_email'               => $_POST['lis_person_contact_email_primary'] ?? '',
    'lti_first_name'               => $firstName,
    'lti_last_name'                => $lastName,
    'lti_full_name'                => trim("$firstName $lastName"),
    'lti_role'                     => $role,
    'lti_is_instructor'            => $isInstructor,
    'lti_is_student'               => $isStudent,
    'lti_crn'                      => $crn,
    'lti_term_id'                  => $termId,
    'lti_course_offering_sourcedid' => $_POST[$offeringField] ?? '',
    'lti_course_number'            => $courseNumber,
    'lti_course_name'              => $courseName,
    'lti_context_id'               => $_POST['context_id'] ?? '',
    'lti_context_label'            => $_POST[$courseLabelField] ?? '',
    'lti_resource_link'            => $_POST['resource_link_id'] ?? '',
    'lti_consumer_key'             => $_POST['oauth_consumer_key'] ?? '',
    'lti_client_ip'                => $clientIp,
    'lti_status_flag'              => $ltiAuthStatusFlag,
    'lti_launched_at'              => $nowTs,
];

try {
    $launchData['authorized_context'] = \SLOCloud\Core\LtiLaunchContext::resolve($db, $dbPrefix, $launchData);
} catch (\SLOCloud\Core\LtiAuthorizationException $e) {
    $logger->warning('LTI launch context resolution denied', [
        'user_id' => $_POST['user_id'] ?? '',
        'crn' => $crn,
        'term' => $termId,
        'reason' => $e->getMessage(),
    ]);
    http_response_code(403);
    render_message_page(
        'error',
        'Course Not Available',
        sprintf(
            '%s Received: %s = "%s" (format %s), %s = "%s" (format %s); parsed CRN "%s", term "%s", course "%s". Please contact your LMS administrator.',
            $e->getMessage(),
            $offeringField, (string)$offeringRaw, $offeringFormat,
            $courseLabelField, (string)($_POST[$courseLabelField] ?? ''), $courseLabelFormat,
            (string)$crn, (string)$termId, (string)$courseNumber
        )
    );
    exit;
}

$_SESSION['lti_launches'][$ltiToken] = $launchData;

$logger->info('LTI launch', ['user' => $_POST['user_id'] ?? '', 'role' => $role, 'crn' => $crn, 'token' => $ltiToken]);

if ($isInstructor) {
    \SLOCloud\Core\Path::redirect('lti/assessment.php?lt=' . urlencode($ltiToken));
} elseif ($isStudent) {
    render_message_page('info', 'Student View', 'Student results coming soon.');
} else {
    render_message_page('error', 'Access Denied', 'Your role does not have access to this tool.');
}
