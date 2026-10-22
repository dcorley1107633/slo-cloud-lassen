<?php
declare(strict_types=1);

/**
 * LTI Assessment Interface
 * 
 * Instructor-facing interface for entering SLO assessment data.
 * Accessed via LTI launch from Learning Management System.
 * Uses pragmatic page pattern (logic + template in one file).
 * 
 * Cross-Listed Sections Support:
 * - When LTI CRN comes in as "XLS{code}" (e.g., "XLS1", "XLSC01"), 
 *   the system queries section_xlist table to find all related sections
 * - If multiple sections found, displays dropdown selector at top of page
 * - All students from selected section are shown for assessment
 * 
 * @package SLOCloud
 */

// Initialize common variables and database (handles session config for LTI context)
require_once __DIR__ . '/../system/includes/init.php';

// LTI installations commonly change LMS hostnames during upgrades or migrations.
// Keep HTTPS embedding compatible while retaining every other global CSP rule.
header('Content-Security-Policy: ' . \SLOCloud\Core\Security::contentSecurityPolicy(["'self'", 'https:']));

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/../system/includes/message_page.php';

// $logger is now available from init.php

// Resolve per-launch token. The token arrives via ?lt= on GET requests and is
// also present in $_GET when the page POSTs to itself (action="" preserves the
// query string). We also accept it from $_POST as a fallback for clients that
// strip query strings on form submit.
$ltiToken = null;
$rawToken = $_GET['lt'] ?? $_POST['lt'] ?? '';
if (preg_match('/^[0-9a-f]{32}$/', $rawToken)) {
    $ltiToken = $rawToken;
}
$launchData = ($ltiToken !== null && isset($_SESSION['lti_launches'][$ltiToken]))
    ? $_SESSION['lti_launches'][$ltiToken]
    : null;

// Enforce the same eight-hour lifetime here as launch.php. Pruning only when a
// new launch arrives would otherwise allow an old assessment URL to remain
// usable indefinitely in a long-lived browser session.
$launchTtl = 28800;
$lastLaunchActivity = is_array($launchData)
    ? (int)($launchData['lti_last_ping'] ?? $launchData['lti_launched_at'] ?? 0)
    : 0;
if ($launchData !== null && ($lastLaunchActivity <= 0 || (time() - $lastLaunchActivity) > $launchTtl)) {
    if ($ltiToken !== null) {
        unset($_SESSION['lti_launches'][$ltiToken]);
    }
    $launchData = null;
}

// Initialize StudentEncryption for decrypting student data
$studentEncryption = new \SLOCloud\Core\StudentEncryption($config);

// Check LTI authentication - ONLY teachers can make assessments
if (empty($launchData['lti_authenticated'])) {
    http_response_code(403);
    render_message_page(
        'warning',
        'Session Expired',
        'Your session has expired, or this page was opened directly. '
        . 'For security reasons, assessments must be launched from your Learning Management System. '
        . 'Please return to your course site and click the assessment link again.'
    );
    exit;
}

if (empty($launchData['lti_is_instructor'])) {
    http_response_code(403);
    render_message_page(
        'error',
        'Access Denied',
        'Only instructors can access the assessment interface. Students should view their assessments through their Learning Management System.'
    );
    exit;
}

// Check for IP change during session (security monitoring)
$remoteIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$forwardedIp = \SLOCloud\Core\Security::isTrustedProxy($remoteIp)
    ? trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))[0])
    : '';
$currentIp = $forwardedIp !== '' ? $forwardedIp : $remoteIp;
$currentIp = filter_var($currentIp, FILTER_VALIDATE_IP) ? $currentIp : (filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ? $_SERVER['REMOTE_ADDR'] : 'unknown');
if (isset($launchData['lti_client_ip']) && $launchData['lti_client_ip'] !== $currentIp) {
    $logger->warning('Session IP change detected', [
        'user_id' => $launchData['lti_user_id'] ?? 'unknown',
        'original_ip' => $launchData['lti_client_ip'],
        'current_ip' => $currentIp,
        'context' => 'assessment_page',
        'note' => 'Possible session hijacking or mobile/VPN IP change'
    ]);
    // Update launch IP (don't block - could be legitimate mobile/VPN change)
    $_SESSION['lti_launches'][$ltiToken]['lti_client_ip'] = $currentIp;
    $launchData['lti_client_ip'] = $currentIp;
}

// Check maintenance mode
$maintenanceMode = $config->get('app.maintenance_mode', false);
if ($maintenanceMode) {
    // In maintenance mode, allow users on the admin list (active users table entries).
    $isAdmin = false;
    $sessionUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
    if ($sessionUserId > 0) {
        $adminCheck = $db->query(
            "SELECT u.users_pk FROM {$dbPrefix}users u "
            . "JOIN {$dbPrefix}user_roles ur ON ur.user_fk = u.users_pk "
            . "JOIN {$dbPrefix}roles r ON r.roles_pk = ur.role_fk "
            . "WHERE u.users_pk = ? AND u.is_active = 1 AND r.role_name = 'admin' "
            . "AND ur.context_type IS NULL AND ur.context_id IS NULL",
            [$sessionUserId],
            'i'
        );
        $isAdmin = ($adminCheck->fetch() !== false);
    }

    if (!$isAdmin) {
        $ltiUserEmail = $launchData['lti_user_email'] ?? '';
        if (!empty($ltiUserEmail)) {
            $isSqlServer = ($db->getDriver() === 'mssql' || $db->getDriver() === 'sqlsrv');
            $normalizedEmailExpr = $isSqlServer
                ? "LOWER(LTRIM(RTRIM(email)))"
                : "LOWER(TRIM(email))";
            $normalizedEmail = strtolower(trim($ltiUserEmail));

            $adminQuery = "SELECT u.users_pk FROM {$dbPrefix}users u "
                . "JOIN {$dbPrefix}user_roles ur ON ur.user_fk = u.users_pk "
                . "JOIN {$dbPrefix}roles r ON r.roles_pk = ur.role_fk "
                . "WHERE {$normalizedEmailExpr} = ? AND u.is_active = 1 "
                . "AND r.role_name = 'admin' AND ur.context_type IS NULL AND ur.context_id IS NULL";
            $adminCheck = $db->query($adminQuery, [$normalizedEmail], 's');
            $isAdmin = ($adminCheck->fetch() !== false);
        }
    }
    
    if (!$isAdmin) {
        $maintenanceMessage = $config->get('app.maintenance_message', 'SLOCloud is currently undergoing maintenance. Please check back later.');
        $isAjaxRequest = (
            (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['action']))
        );
        if ($isAjaxRequest) {
            http_response_code(503);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => $maintenanceMessage,
                'error' => 'maintenance_mode'
            ]);
            exit;
        }
        render_message_page(
            'warning',
            'Maintenance Mode',
            htmlspecialchars($maintenanceMessage)
        );
        exit;
    }
}

// Get LTI launch data (keyed by per-launch token to support multiple concurrent launches)
$ltiUserName = $launchData['lti_full_name'] ?? 'Instructor';
$ltiUserEmail = $launchData['lti_user_email'] ?? '';
$ltiCourseNumber = $launchData['lti_course_number'] ?? null;
$ltiCourseName = $launchData['lti_course_name'] ?? 'Course';
$ltiCrn = $launchData['lti_crn'] ?? null;
$ltiTermId = $launchData['lti_term_id'] ?? null;
$ltiAcademicYear = $launchData['lti_academic_year'] ?? null;
$ltiTermNumber = $launchData['lti_term_number'] ?? null;
$ltiStatusFlag = $launchData['lti_status_flag'] ?? '000';

// The signed launch is resolved once into canonical, term-bound section rows.
// Resolve older in-flight sessions once after deployment, then persist the same
// immutable structure used by new launches.
$authorizedContext = $launchData['authorized_context'] ?? null;
try {
    if (!is_array($authorizedContext) || ($authorizedContext['version'] ?? 0) !== 1) {
        $authorizedContext = \SLOCloud\Core\LtiLaunchContext::resolve($db, $dbPrefix, $launchData);
        $_SESSION['lti_launches'][$ltiToken]['authorized_context'] = $authorizedContext;
        $launchData['authorized_context'] = $authorizedContext;
    }
} catch (\SLOCloud\Core\LtiAuthorizationException $e) {
    $logger->warning('LTI assessment context resolution denied', [
        'user_id' => $launchData['lti_user_id'] ?? '',
        'reason' => $e->getMessage(),
    ]);
    http_response_code(403);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Access denied.']);
        exit;
    }
    render_message_page('error', 'Course Not Available', 'This launch is not authorized for an available course section.');
    exit;
}

$isXlisted = !empty($authorizedContext['is_crosslisted']);
$xlistGroup = $authorizedContext['xlist_group'] ?? null;

// Show dashboard link only when the matched dashboard user has a non-student role.
$hasAdminAccount = false;
$dashboardUserId = 0;
if (!empty($ltiUserEmail)) {
    $isSqlServer = ($db->getDriver() === 'mssql' || $db->getDriver() === 'sqlsrv');
    $normalizedEmailExpr = $isSqlServer
        ? "LOWER(LTRIM(RTRIM(u.email)))"
        : "LOWER(TRIM(u.email))";
    $userQuery = "
        SELECT u.users_pk
        FROM {$dbPrefix}users u
        JOIN {$dbPrefix}user_roles ur ON ur.user_fk = u.users_pk
        JOIN {$dbPrefix}roles r ON r.roles_pk = ur.role_fk
        WHERE {$normalizedEmailExpr} = ?
          AND u.is_active = 1
          AND r.role_name = 'admin'
          AND ur.context_type IS NULL AND ur.context_id IS NULL
    ";
    $normalizedLtiEmail = strtolower(trim($ltiUserEmail));
    $userCheck = $db->query($userQuery, [$normalizedLtiEmail], 's');
    $userRow = $userCheck->fetch();
    if ($userRow !== false) {
        $hasAdminAccount = true;
        $dashboardUserId = (int)$userRow['users_pk'];
    }
}

// Prepare one-time auto-login token for dashboard link.
if ($hasAdminAccount && $dashboardUserId > 0) {
    $_SESSION['lti_autologin_user_id'] = $dashboardUserId;
    $_SESSION['lti_autologin_expires'] = time() + 300; // 5 minutes
}

/**
 * Helper function to save section SLO methods (assessment_method and improvement_strategies)
 * Uses DELETE+INSERT pattern to prevent duplicates
 * Always saves both fields together from current form state
 * 
 * @param object $db Database connection
 * @param string $dbPrefix Table prefix
 * @param array $crns Array of CRN strings
 * @param string $termCode Term code
 * @param int $sloId Student Learning Outcome PK
 * @param string $assessmentMethod Assessment method value (can be empty to clear)
 * @param string $improvementStrategies Strategies value (can be empty to clear)
 * @param int|null $userId User ID for audit trail
 * @return int Number of sections updated
 */
function saveSectionSloMethods($db, $dbPrefix, $crns, $termCode, $sloId, $assessmentMethod, $improvementStrategies, $userId) {
    $isSqlServer = ($db->getDriver() === 'mssql' || $db->getDriver() === 'sqlsrv');
    $sectionsUpdated = 0;
    
    foreach ($crns as $crn) {
        // Delete any existing records (prevents duplicates even if multiple exist)
        $db->query(
            "DELETE FROM {$dbPrefix}section_slo_methods 
             WHERE crn = ? AND term_code = ? AND student_learning_outcome_fk = ?",
            [$crn, $termCode, $sloId],
            'ssi'
        );
        
        // Insert new record with all field values exactly as passed (skip if both are empty)
        if (!empty($assessmentMethod) || !empty($improvementStrategies)) {
            if ($isSqlServer) {
                $insertQuery = "INSERT INTO {$dbPrefix}section_slo_methods 
                 (crn, term_code, student_learning_outcome_fk, assessment_method, improvement_strategies, assessed_date, created_at, updated_at, created_by_fk, updated_by_fk)
                 VALUES (?, ?, ?, ?, ?, CAST(GETDATE() AS DATE), GETDATE(), GETDATE(), ?, ?)";
                $db->query($insertQuery, [
                    $crn, $termCode, $sloId, 
                    !empty($assessmentMethod) ? $assessmentMethod : null,
                    !empty($improvementStrategies) ? $improvementStrategies : null,
                    $userId, $userId
                ], 'ssissii');
            } else {
                $insertQuery = "INSERT INTO {$dbPrefix}section_slo_methods 
                 (crn, term_code, student_learning_outcome_fk, assessment_method, improvement_strategies, assessed_date, created_at, updated_at, created_by_fk, updated_by_fk)
                 VALUES (?, ?, ?, ?, ?, CURDATE(), NOW(), NOW(), ?, ?)";
                $db->query($insertQuery, [
                    $crn, $termCode, $sloId, 
                    !empty($assessmentMethod) ? $assessmentMethod : null,
                    !empty($improvementStrategies) ? $improvementStrategies : null,
                    $userId, $userId
                ], 'ssissii');
            }
            $sectionsUpdated++;
        }
    }
    
    return $sectionsUpdated;
}

// Handle form submission
$successMessage = '';
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF validation
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        http_response_code(403);
        die('This form has expired. Reload the page and try again.');
    }
    
    $action = $_POST['action'] ?? '';
    
    $writeTransactionStarted = false;
    try {
        // The request identifies a desired target. Its term, CRN, course, and
        // ownership are derived exclusively from the server-side launch context.
        $sectionPk = (int)($_POST['section_pk'] ?? 0);
        $sloId = (int)($_POST['slo_id'] ?? 0);
        if ($sectionPk <= 0 || $sloId <= 0) {
            throw new \SLOCloud\Core\LtiAuthorizationException('The requested target is invalid.');
        }

        $authorizedTarget = \SLOCloud\Core\LtiLaunchContext::authorizeSlo(
            $db,
            $dbPrefix,
            $authorizedContext,
            $sectionPk,
            $sloId
        );
        $authorizedCrn = (string)$authorizedTarget['crn'];
        $termCode = (string)$authorizedTarget['term_code'];
        
        // Action-specific handlers
        if ($action === 'save_method' || $action === 'save_strategies') {
            $writeTransactionStarted = $db->beginTransaction();
            // Unified AJAX handler for saving section SLO methods (assessment_method + improvement_strategies)
            
            // Get both fields from form state
            // Use NULL when empty to maintain database consistency

            // Allowlist: validate assessment_method against configured types
            $allowedAssessmentTypes = array_map('trim', explode('||', trim($config->get('app.assessment_types', 'Quiz||Exam||Project||Assignment'), '|')));
            $rawAssessmentMethod = !empty($_POST['assessment_method']) ? trim($_POST['assessment_method']) : null;
            $assessmentMethod = ($rawAssessmentMethod !== null && in_array($rawAssessmentMethod, $allowedAssessmentTypes, true))
                ? $rawAssessmentMethod
                : null;

            // Allowlist: validate each improvement strategy against configured list
            $allowedStrategies = array_map('trim', explode('||', trim($config->get('app.improvement_strategies', ''), '|')));
            $allowedStrategies = array_filter($allowedStrategies); // remove empties
            $rawStrategies = is_array($_POST['improvement_strategies'] ?? null) ? $_POST['improvement_strategies'] : [];
            $improvementStrategies = !empty($allowedStrategies)
                ? array_filter($rawStrategies, fn($s) => in_array(trim($s), $allowedStrategies, true))
                : $rawStrategies; // if no list configured, pass through (backward compat)

            // Convert strategies array to pipe-wrapped string for exact-match queries
            // Use NULL when empty to maintain database consistency
            $strategiesString = null;
            if (!empty($improvementStrategies)) {
                $strategiesString = '|' . implode('||', array_map('trim', $improvementStrategies)) . '|';
            }
            
            // Save both fields together (blind DELETE+INSERT)
            $userId = $_SESSION['user_id'] ?? null;
            $sectionsUpdated = saveSectionSloMethods(
                $db, 
                $dbPrefix, 
                [$authorizedCrn],
                $termCode, 
                $sloId, 
                $assessmentMethod,
                $strategiesString,
                $userId
            );
            $db->commit();
            $writeTransactionStarted = false;
            $logger->audit('lti_section_slo_methods', $sloId, 'update', null, [
                'count' => $sectionsUpdated,
                'actor_id' => 'lti:' . substr(hash('sha256', (string)($launchData['lti_user_id'] ?? '')), 0, 16),
                'context_id' => 'launch:' . substr(hash('sha256', (string)$ltiToken), 0, 16),
                'status' => 'success',
            ]);
            
            // Build response message based on which UI action triggered the save
            if ($action === 'save_method') {
                $message = empty($assessmentMethod) ? 'Primary assessment type cleared' : 'Primary assessment type saved';
            } elseif ($action === 'save_strategies') {
                $strategyCount = count($improvementStrategies);
                $message = "Saved {$strategyCount} improvement strategies";
            } else {
                // Fallback - should never happen given the action check above
                $message = 'Assessment method and improvement strategies saved';
            }
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => $message]);
            exit;
            
        } elseif ($action === 'save_assessments') {
            $outcomes = $_POST['outcome'] ?? [];
            if (!is_array($outcomes) || $outcomes === []) {
                throw new \SLOCloud\Core\LtiAuthorizationException('No authorized enrollment was supplied.');
            }

            // Authorize the complete batch before beginning any mutation.
            $authorizedOutcomes = [];
            $validLevels = ['met', 'partially_met', 'not_met', 'not_assessed'];
            foreach ($outcomes as $enrollmentId => $achievementLevel) {
                $enrollmentId = (int)$enrollmentId;
                if ($enrollmentId <= 0 || !is_string($achievementLevel)) {
                    throw new \SLOCloud\Core\LtiAuthorizationException('The requested enrollment is invalid.');
                }
                if (!in_array($achievementLevel, $validLevels, true)) {
                    throw new \InvalidArgumentException('The achievement level is invalid.');
                }
                \SLOCloud\Core\LtiLaunchContext::authorizeEnrollment(
                    $db,
                    $dbPrefix,
                    $authorizedContext,
                    $sectionPk,
                    $sloId,
                    $enrollmentId
                );
                $authorizedOutcomes[$enrollmentId] = $achievementLevel;
            }

            $writeTransactionStarted = $db->beginTransaction();
            
            // Get assessor email from LTI launch data
            $assessorEmail = $launchData['lti_user_email'] ?? '';
            
            $saved = 0;
            
            foreach ($authorizedOutcomes as $enrollmentId => $achievementLevel) {
                // Clear any existing assessments for this student/SLO
                $db->query(
                    "DELETE FROM {$dbPrefix}assessments WHERE enrollment_fk = ? AND student_learning_outcome_fk = ?",
                    [$enrollmentId, $sloId],
                    'ii'
                );
                
                // Insert the explicit assessment decision, including not_assessed.
                $isSqlServer = ($db->getDriver() === 'mssql' || $db->getDriver() === 'sqlsrv');
                if ($isSqlServer) {
                    $insertQuery = "INSERT INTO {$dbPrefix}assessments 
                     (enrollment_fk, student_learning_outcome_fk, achievement_level,
                      assessed_date, assessor_email, created_at, updated_at)
                     VALUES (?, ?, ?, CAST(GETDATE() AS DATE), ?, GETDATE(), GETDATE())";
                } else {
                    $insertQuery = "INSERT INTO {$dbPrefix}assessments 
                     (enrollment_fk, student_learning_outcome_fk, achievement_level,
                      assessed_date, assessor_email, created_at, updated_at)
                     VALUES (?, ?, ?, CURDATE(), ?, NOW(), NOW())";
                }
                $db->query($insertQuery, [$enrollmentId, $sloId, $achievementLevel, $assessorEmail], 'iiss');
                
                $saved++;
            }
            $db->commit();
            $writeTransactionStarted = false;
            $logger->audit('lti_assessments', $sloId, 'update', null, [
                'count' => $saved,
                'actor_id' => 'lti:' . substr(hash('sha256', (string)($launchData['lti_user_id'] ?? '')), 0, 16),
                'context_id' => 'launch:' . substr(hash('sha256', (string)$ltiToken), 0, 16),
                'status' => 'success',
            ]);
            
            $logger->info('LTI Assessment Saved', [
                'crn' => $authorizedCrn,
                'term_code' => $termCode,
                'slo_id' => $sloId,
                'saved' => $saved,
                'section_pk' => $sectionPk
            ]);
            
            $message = "{$saved} student(s) assessed";
            
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => $message, 'saved' => $saved, 'skipped' => 0]);
            exit;
        }
    } catch (\Exception $e) {
        if ($writeTransactionStarted) {
            $db->rollback();
        }
        $authorizationDenied = $e instanceof \SLOCloud\Core\LtiAuthorizationException;
        $logger->audit('lti_assessments', max(0, (int)($sloId ?? 0)), 'denied', null, [
            'actor_id' => 'lti:' . substr(hash('sha256', (string)($launchData['lti_user_id'] ?? '')), 0, 16),
            'context_id' => 'launch:' . substr(hash('sha256', (string)$ltiToken), 0, 16),
            'target_id' => max(0, (int)($sectionPk ?? 0)),
            'status' => 'denied',
        ]);
        $errorMessage = 'Failed to save assessments: ' . htmlspecialchars($e->getMessage());
        $logger->error(
            'LTI Assessment Save Failed: ' . $e->getMessage(),
            'LTI_ERROR',
            null,
            $e->getTraceAsString(),
            $e->getFile(),
            $e->getLine()
        );
        
        // Return JSON for AJAX requests
        if ($action === 'save_assessments' || $action === 'save_method' || $action === 'save_strategies') {
            http_response_code($authorizationDenied ? 403 : 400);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => $authorizationDenied ? 'Access denied.' : 'The assessment could not be saved.',
            ]);
            exit;
        }
        
        $errorMessage = 'Failed to save assessments: ' . htmlspecialchars($e->getMessage());
        
        if (DEBUG_MODE) {
            $errorMessage .= '<br><br><strong>Debug Information:</strong><br>';
            $errorMessage .= '<pre style="text-align: left; font-size: 12px;">';
            $errorMessage .= 'File: ' . htmlspecialchars($e->getFile()) . '<br>';
            $errorMessage .= 'Line: ' . htmlspecialchars((string)$e->getLine()) . '<br>';
            $errorMessage .= 'Trace:<br>' . htmlspecialchars($e->getTraceAsString());
            $errorMessage .= '</pre>';
        }
    }
}

// Build the available-section view directly from the server-side launch scope.
$hasLaunchSectionContext = true;
$courseSections = array_map(
    static fn(array $section): array => [
        'sections_pk' => $section['section_pk'],
        // Legacy page code uses section_id as the enrollment CRN.
        'section_id' => $section['crn'],
        'display_section_id' => $section['section_id'],
        'crn' => $section['crn'],
        'term_code' => $section['term_code'],
        'course_fk' => $section['course_fk'],
        'term_name' => $section['term_name'],
        'banner_term' => $section['banner_term'],
        'course_number' => $section['course_number'],
        'course_name' => $section['course_name'],
    ],
    $authorizedContext['sections']
);

// For cross-listed sections, user must select which section to assess
// For single sections, use the one section from launch context
$selectedTermCode = '';
$selectedSectionPk = null; // sections_pk for URL/UI state
$selectedCourseFk = null;
$courseNumbers = [];

// A requested section is only a selector within the stored launch scope.
$selectedSectionPk = isset($_GET['section_id'])
    ? (int)$_GET['section_id']
    : (int)($courseSections[0]['sections_pk'] ?? 0);
$authorizedSectionPks = array_map('intval', array_column($courseSections, 'sections_pk'));
if ($selectedSectionPk <= 0 || !in_array($selectedSectionPk, $authorizedSectionPks, true)) {
    $logger->warning('LTI Assessment: Unauthorized section access attempt', [
        'requested_section_pk' => $selectedSectionPk,
        'authorized_sections' => $authorizedSectionPks,
        'lti_user' => $ltiUserEmail ?? 'unknown',
        'session_id' => session_id(),
    ]);
    http_response_code(403);
    render_message_page('error', 'Access Denied', 'The requested section is outside this LTI launch.');
    exit;
}

foreach ($courseSections as $cs) {
    if ((int)$cs['sections_pk'] === $selectedSectionPk) {
        $selectedTermCode = $cs['term_code'];
        $courseNumbers[$cs['sections_pk']] = $cs['course_number'];
        $selectedCourseFk = $cs['course_fk'];
        break;
    }
}

// Query SLOs for the selected course
$slos = [];
if ($selectedCourseFk) {
    $slosResult = $db->query(
        "SELECT student_learning_outcomes_pk, slo_code, slo_description
         FROM {$dbPrefix}student_learning_outcomes
         WHERE course_fk = ?
         ORDER BY sequence_num ASC",
        [$selectedCourseFk],
        'i'
    );
    $slos = $slosResult->fetchAll();
}

// SECURITY: Validate selected SLO belongs to authorized course
$selectedSloId = 0;
if (isset($_GET['slo_id']) && !empty($slos)) {
    $requestedSloId = (int)$_GET['slo_id'];
    $authorizedSloIds = array_map('intval', array_column($slos, 'student_learning_outcomes_pk'));
    
    if (in_array($requestedSloId, $authorizedSloIds, true)) {
        $selectedSloId = $requestedSloId;
    } else {
        // Unauthorized SLO access attempt - log and block assessment
        $logger->warning('LTI Assessment: Unauthorized SLO access attempt', [
            'requested_slo_id' => $requestedSloId,
            'authorized_slos' => $authorizedSloIds,
            'selected_course_fk' => $selectedCourseFk,
            'lti_user' => $ltiUserEmail ?? 'unknown',
            'session_id' => session_id()
        ]);
        
        http_response_code(403);
        render_message_page('error', 'Access Denied', 'The requested learning outcome is outside this LTI launch.');
        exit;
    }
} elseif (!empty($slos)) {
    $selectedSloId = $slos[0]['student_learning_outcomes_pk'];
}

if ($selectedSloId > 0) {
    try {
        \SLOCloud\Core\LtiLaunchContext::authorizeSlo(
            $db,
            $dbPrefix,
            $authorizedContext,
            $selectedSectionPk,
            (int)$selectedSloId
        );
    } catch (\SLOCloud\Core\LtiAuthorizationException $e) {
        $logger->warning('LTI assessment read denied', [
            'requested_section_pk' => $selectedSectionPk,
            'requested_slo_id' => (int)$selectedSloId,
            'reason' => $e->getMessage(),
        ]);
        http_response_code(403);
        render_message_page('error', 'Access Denied', 'The requested assessment is outside this LTI launch.');
        exit;
    }
}

// Get enrolled students through the selected authorized section relationship.
$students = [];
if ($selectedSectionPk > 0 && $selectedTermCode && $selectedSloId > 0) {
    if ($selectedSloId > 0) {
        $studentsQuery = "
            SELECT e.enrollment_pk, sec.crn, t.term_code, sec.course_fk,
                   c.course_number,
                   s.student_data_encrypted,
                   a.achievement_level
            FROM {$dbPrefix}enrollment e
            INNER JOIN {$dbPrefix}sections sec ON sec.sections_pk = e.section_fk
                AND sec.sections_pk = ?
            INNER JOIN {$dbPrefix}terms t ON t.terms_pk = ?
                AND t.terms_pk = sec.term_fk
            INNER JOIN {$dbPrefix}students s ON e.student_fk = s.students_pk
            INNER JOIN {$dbPrefix}courses c ON sec.course_fk = c.courses_pk
            LEFT JOIN {$dbPrefix}assessments a ON a.assessments_pk = (
                SELECT MAX(a2.assessments_pk)
                FROM {$dbPrefix}assessments a2
                WHERE a2.enrollment_fk = e.enrollment_pk
                  AND a2.student_learning_outcome_fk = ?
            )
            ORDER BY c.course_number ASC, s.student_data_encrypted ASC
        ";
        $queryParams = [$selectedSectionPk, (int)$authorizedContext['term_pk'], $selectedSloId];
        $queryTypes = 'iii';
        $studentsResult = $db->query($studentsQuery, $queryParams, $queryTypes);
    } else {
        $studentsQuery = "
            SELECT e.enrollment_pk, sec.crn, t.term_code, sec.course_fk,
                   c.course_number,
                   s.student_data_encrypted,
                   NULL as achievement_level
            FROM {$dbPrefix}enrollment e
            INNER JOIN {$dbPrefix}sections sec ON sec.sections_pk = e.section_fk
                AND sec.sections_pk = ?
            INNER JOIN {$dbPrefix}terms t ON t.terms_pk = ?
                AND t.terms_pk = sec.term_fk
            INNER JOIN {$dbPrefix}students s ON e.student_fk = s.students_pk
            INNER JOIN {$dbPrefix}courses c ON sec.course_fk = c.courses_pk
            ORDER BY c.course_number ASC, s.student_data_encrypted ASC
        ";
        $queryParams = [$selectedSectionPk, (int)$authorizedContext['term_pk']];
        $queryTypes = 'ii';
        $studentsResult = $db->query($studentsQuery, $queryParams, $queryTypes);
    }
    
    // Fetch and decrypt student data
    $studentsRaw = $studentsResult->fetchAll();
    $students = [];
    foreach ($studentsRaw as $studentRow) {
        try {
            $studentData = $studentEncryption->decryptStudentData($studentRow['student_data_encrypted']);
            $students[] = [
                'enrollment_pk' => $studentRow['enrollment_pk'],
                'crn' => $studentRow['crn'],
                'term_code' => $studentRow['term_code'],
                'course_fk' => $studentRow['course_fk'],
                'course_number' => $studentRow['course_number'] ?? '',
                'student_id' => $studentData['c_number'],
                'first_name' => $studentData['first_name'],
                'last_name' => $studentData['last_name'],
                'achievement_level' => $studentRow['achievement_level']
            ];
        } catch (\Exception $e) {
            $logger->error('Error decrypting student data in LTI assessment: ' . $e->getMessage());
            // Skip students with decryption errors
            continue;
        }
    }
    
    // Sort students by last name, then first name
    usort($students, function($a, $b) {
        $lastNameCmp = strcasecmp($a['last_name'], $b['last_name']);
        if ($lastNameCmp !== 0) {
            return $lastNameCmp;
        }
        return strcasecmp($a['first_name'], $b['first_name']);
    });
}

// Get methods only where section, term, course, and SLO still form an authorized chain.
$sectionMethods = [];
if ($selectedSectionPk > 0 && $selectedTermCode) {
    $methodQuery = "
        SELECT m.student_learning_outcome_fk, m.assessment_method, m.improvement_strategies
        FROM {$dbPrefix}section_slo_methods m
        INNER JOIN {$dbPrefix}sections sec ON sec.sections_pk = ? AND sec.crn = m.crn
        INNER JOIN {$dbPrefix}terms t ON t.terms_pk = ?
            AND t.terms_pk = sec.term_fk AND t.term_code = m.term_code
        INNER JOIN {$dbPrefix}student_learning_outcomes slo
            ON slo.student_learning_outcomes_pk = m.student_learning_outcome_fk
            AND slo.course_fk = sec.course_fk
    ";
    $methodResult = $db->query(
        $methodQuery,
        [$selectedSectionPk, (int)$authorizedContext['term_pk']],
        'ii'
    );
    while ($methodRow = $methodResult->fetch()) {
        $sectionMethods[$methodRow['student_learning_outcome_fk']] = $methodRow;
    }
}

// Extract current SLO's method from cached section methods
$currentAssessmentMethod = '';
$currentImprovementStrategies = [];
if ($selectedSloId > 0 && isset($sectionMethods[$selectedSloId])) {
    $currentAssessmentMethod = (string)($sectionMethods[$selectedSloId]['assessment_method'] ?? '');
    if (!empty($sectionMethods[$selectedSloId]['improvement_strategies'])) {
        $strategiesValue = $sectionMethods[$selectedSloId]['improvement_strategies'];
        // Remove outer pipes from wrapped format (|val1||val2|)
        $strategiesValue = trim($strategiesValue, '|');
        $currentImprovementStrategies = explode('||', $strategiesValue);
        $currentImprovementStrategies = array_map('trim', $currentImprovementStrategies);
    }
}

// Get selected course section details
$courseSection = null;
if ($selectedSectionPk) {
    foreach ($courseSections as $cs) {
        if ($cs['sections_pk'] == $selectedSectionPk) {
            $courseSection = $cs;
            break;
        }
    }
}

// Build first SLO lookup per course_fk (used when building section switch links)
$firstSloByCourseFk = [];
if ($isXlisted && count($courseSections) > 1) {
    $uniqueCourseFks = array_unique(array_column($courseSections, 'course_fk'));
    if (!empty($uniqueCourseFks)) {
        $courseFkPlaceholders = implode(',', array_fill(0, count($uniqueCourseFks), '?'));
        $firstSloTypes = str_repeat('i', count($uniqueCourseFks));
        $firstSloResult = $db->query(
            "SELECT course_fk, MIN(student_learning_outcomes_pk) AS first_slo_pk
             FROM {$dbPrefix}student_learning_outcomes
             WHERE course_fk IN ($courseFkPlaceholders)
             GROUP BY course_fk",
            $uniqueCourseFks,
            $firstSloTypes
        );
        while ($row = $firstSloResult->fetch()) {
            $firstSloByCourseFk[(int)$row['course_fk']] = (int)$row['first_slo_pk'];
        }
    }
}

// Get selected SLO details
$selectedSlo = null;
if ($selectedSloId > 0) {
    foreach ($slos as $slo) {
        if ($slo['student_learning_outcomes_pk'] == $selectedSloId) {
            $selectedSlo = $slo;
            break;
        }
    }
}

// Capture custom styles
ob_start();
?>
<style>
    :root {
        --primary-dark: #0D47A1;
        --accent-blue: #1976D2;
        --brand-teal: #1565C0;
    }
    body {
        background-color: #f4f6f9;
    }
    .lti-header {
        background: linear-gradient(135deg, var(--accent-blue), var(--brand-teal));
        color: white;
        padding: 20px;
        margin: -1rem -1rem 30px -1rem;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .lti-header h2 {
        color: #ffffff;
        font-weight: 600;
        text-shadow: 0 1px 3px rgba(0,0,0,0.3);
        margin: 0;
    }
    .student-row:hover {
        background-color: #f8f9fa;
    }
    .btn-primary {
        background-color: var(--accent-blue);
        border-color: var(--accent-blue);
    }
    .btn-primary:hover {
        background-color: var(--primary-dark);
        border-color: var(--primary-dark);
    }
    .lti-badge {
        background: #087990;
        color: white;
        padding: 5px 10px;
        border-radius: 3px;
        font-size: 0.875rem;
    }
    .toast-container {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
    }
    .toast {
        min-width: 250px;
        background: white;
        border-radius: 4px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        margin-bottom: 10px;
        overflow: hidden;
        opacity: 0;
        transform: translateX(100%);
        transition: all 0.3s ease;
    }
    .toast.show {
        opacity: 1;
        transform: translateX(0);
    }
    .toast-header {
        padding: 10px 15px;
        border-bottom: 1px solid #dee2e6;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .toast-body {
        padding: 10px 15px;
    }
    .toast.success .toast-header {
        background-color: #d4edda;
        color: #155724;
        border-color: #c3e6cb;
    }
    .toast.error .toast-header {
        background-color: #f8d7da;
        color: #721c24;
        border-color: #f5c6cb;
    }
    .toast.info .toast-header {
        background-color: #d1ecf1;
        color: #0c5460;
        border-color: #bee5eb;
    }
    .slo-description {
        line-height: 1.4;
    }
    .btn.slo-button {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        justify-content: flex-start;
        padding: 10px 15px;
        white-space: normal;
        height: auto;
        text-align: left;
    }
    .card-header {
        padding: 0.75rem 1.25rem;
    }
    .card-header .card-title {
        margin-bottom: 0;
    }
    .card-tools .btn-tool {
        color: inherit;
        font-size: 1.2rem;
    }
    .slo-content {
        opacity: 0;
        transition: opacity 0.5s ease-in;
    }
    .slo-content.fade-in {
        opacity: 1;
    }
    
    /* ==================== WCAG 2.2 AA Accessibility Styles ==================== */
    
    /* Respect user preference for reduced motion (WCAG 2.3.3) */
    @media (prefers-reduced-motion: reduce) {
        .slo-content {
            opacity: 1 !important;
            transition: none !important;
        }
        .toast {
            transition: none !important;
        }
    }
    
    /* Enhanced focus indicators (WCAG 2.4.7, 2.4.11) */
    a:focus,
    button:focus,
    input:focus,
    select:focus,
    textarea:focus,
    .btn:focus,
    .btn-check:focus + label,
    [tabindex]:focus {
        outline: 3px solid #0D47A1;
        outline-offset: 2px;
        box-shadow: 0 0 0 4px rgba(13, 71, 161, 0.2);
    }
    
    /* Remove default Bootstrap focus styles to avoid double outlines */
    .btn-check:focus + .btn {
        box-shadow: 0 0 0 4px rgba(13, 71, 161, 0.2);
    }
    
    /* Focus visible for :focus-visible support */
    *:focus-visible {
        outline: 3px solid #0D47A1;
        outline-offset: 2px;
        box-shadow: 0 0 0 4px rgba(13, 71, 161, 0.2);
    }
    
    /* Enhanced color contrast (WCAG 1.4.6 Level AAA - 7:1 for normal text) */
    .text-muted {
        color: #495057 !important; /* 8.59:1 contrast ratio on white */
    }
    
    /* Enhanced line spacing for readability (WCAG 1.4.8 Level AAA) */
    body {
        line-height: 1.5;
    }
    p {
        margin-bottom: 1.5em; /* 1.5x font size */
    }
    li {
        line-height: 1.5;
        margin-bottom: 0.5em;
    }
    
    /* Visually hidden but accessible to screen readers */
    .visually-hidden-focusable:not(:focus):not(:focus-within) {
        position: absolute !important;
        width: 1px !important;
        height: 1px !important;
        padding: 0 !important;
        margin: -1px !important;
        overflow: hidden !important;
        clip: rect(0, 0, 0, 0) !important;
        white-space: nowrap !important;
        border: 0 !important;
    }
    
    /* Minimum touch target sizes (WCAG 2.5.8 Level AA - 24x24px) */
    .btn-sm {
        min-height: 24px;
        min-width: 24px;
        padding: 0.5rem 1rem;
    }
    .btn-tool {
        min-height: 24px;
        min-width: 24px;
    }
    /* Radio button labels for touch targets */
    .outcome-buttons label.btn {
        min-height: 24px;
        padding: 0.5rem 1rem;
    }
    
    /* ==================== Button Contrast Fixes (WCAG 1.4.11 Level AA) ==================== */
    
    /* Enhance outline button borders to meet 3:1 contrast ratio */
    .btn-outline-warning {
        border-color: #cc9a06 !important; /* Darker yellow for 3:1 contrast on white */
        color: #664d00 !important; /* Dark text for readability */
    }
    .btn-outline-warning:hover,
    .btn-outline-warning:focus,
    .btn-outline-warning.active,
    .btn-check:checked + .btn-outline-warning {
        background-color: #ffc107 !important;
        border-color: #cc9a06 !important;
        color: #000 !important;
    }
    
    /* Ensure all outline buttons have sufficient border contrast (3:1 minimum) */
    .btn-outline-success {
        border-width: 2px;
        border-color: #198754 !important; /* Bootstrap success green - 3.87:1 on white */
    }
    .btn-outline-danger {
        border-width: 2px;
        border-color: #dc3545 !important; /* Bootstrap danger red - 4.17:1 on white */
    }
    .btn-outline-secondary {
        border-width: 2px;
        border-color: #6c757d !important; /* Bootstrap secondary gray - 4.64:1 on white */
    }
    
    /* Enhance focus indicators specifically for radio button labels (WCAG 2.4.7 Level AA) */
    .btn-check:focus + .btn-outline-success,
    .btn-check:focus + .btn-outline-warning,
    .btn-check:focus + .btn-outline-danger,
    .btn-check:focus + .btn-outline-secondary {
        outline: 3px solid #0D47A1;
        outline-offset: 2px;
        box-shadow: 0 0 0 4px rgba(13, 71, 161, 0.25);
    }
    
    /* Remove default fieldset styling to prevent spacing issues */
    fieldset.outcome-buttons,
    fieldset.btn-group,
    .card-body > fieldset {
        border: 0;
        margin: 0;
        padding: 0;
        min-width: 0;
    }
    
    /* Table caption for screen readers */
    caption {
        caption-side: top;
        text-align: left;
        padding: 0.75rem;
    }
    
    /* Ensure save indicators are announced but not visually intrusive */
    .save-indicator[role="status"] {
        min-width: 20px;
        min-height: 20px;
        display: inline-block;
    }
    
    /* ==================== WCAG 2.2 Level AAA Enhancements ==================== */
    
    /* Abbreviation styling for clarity (WCAG 3.1.4 Level AAA) */
    abbr[title] {
        text-decoration: underline dotted;
        cursor: help;
        border-bottom: none; /* Remove duplicate underline */
    }
    
    /* ==================== End AAA Accessibility Styles ==================== */
</style>
<?php
$customStyles = ob_get_clean();

// Load theme system (auto-loads ThemeContext and Theme)
require_once __DIR__ . '/../system/Core/ThemeLoader.php';
use SLOCloud\Core\ThemeLoader;
use SLOCloud\Core\ThemeContext;

$context = new ThemeContext([
    'layout' => 'embedded',
    'pageTitle' => 'SLO Assessment Entry - ' . SITE_NAME,
    'customCss' => $customStyles
]);

// Load LTI theme with embedded layout for iframe embedding
$theme = ThemeLoader::getActiveTheme(null, 'lti');
$theme->showHeader($context);
?>

<header role="banner">
<div class="lti-header" aria-label="Course and instructor information">
    <div class="container-fluid">
        <h1 class="visually-hidden">Student Learning Outcome Assessment Entry</h1>
        <div class="row align-items-center">
            <div class="col-md-8">
                <?php if ($ltiCourseName): ?>
                    <h2 class="mb-0 h5 fw-normal">
                        <?= htmlspecialchars($ltiCourseName) ?>
                        <?php if ($isXlisted && $xlistGroup): ?>
                            <span class="badge bg-primary ms-2">
                                <i class="fas fa-layer-group" aria-hidden="true"></i> Group <?= htmlspecialchars($xlistGroup) ?>
                            </span>
                        <?php endif; ?>
                    </h2>
                <?php endif; ?>
            </div>
            <div class="col-md-4 text-end">
                <div class="text-white">
                    <i class="fas fa-user-circle fa-2x" aria-hidden="true"></i>
                    <div class="mt-2">
                        <strong><?= htmlspecialchars($ltiUserName) ?></strong><br>
                        <?php if ($ltiUserEmail): ?>
                            <small><?= htmlspecialchars($ltiUserEmail) ?></small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</header>

<main id="main" role="main" tabindex="-1">
<div class="container-fluid">
    <?php if ($successMessage): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle" aria-hidden="true"></i> <?= htmlspecialchars($successMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle" aria-hidden="true"></i> <?= $errorMessage ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if (empty($courseSections)): ?>
        <div class="alert alert-warning" role="alert">
            <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
            <strong>Student Learning Outcome Assessment Unavailable</strong><br>
            <?php if ($hasLaunchSectionContext): ?>
                Student Learning Outcome assessment data is not currently available for this course section<?php if ($isXlisted): ?> (Multi-Section Group: <?= htmlspecialchars($xlistGroup) ?>, Term: <?= htmlspecialchars($ltiTermId ?? 'unknown') ?>)<?php else: ?> (CRN: <?= htmlspecialchars($ltiCrn ?? 'unknown') ?>, Term: <?= htmlspecialchars($ltiTermId ?? 'unknown') ?>)<?php endif; ?>. 
                This may mean:
                <ul class="mb-0 mt-2">
                    <li>The section has not been imported yet.</li>
                    <li>There are no student enrollments for this section.</li>                    
                    <li>No Student Learning Outcomes have been associated with this course.</li>
                    <li>The CRN or term information from the LMS doesn't match the Student Learning Outcome system.</li>
                </ul>
                Please contact your administrator to verify the course section data.
            <?php else: ?>
                Unable to load the assessment. The LTI launch did not include a recognisable CRN or term.
                <?php if (!empty($launchData['lti_course_offering_sourcedid'])): ?>
                    The offering code received was: <strong><?= htmlspecialchars($launchData['lti_course_offering_sourcedid']) ?></strong>.
                    Expected a CRN or XLS cross-listing code (e.g. <code>45581.202633</code> or <code>XLS27202633</code>).
                <?php endif; ?>
                Please contact your administrator to verify the LMS tool configuration.
            <?php endif; ?>
        </div>
    <?php else: ?>
        <!-- Instructions Card -->
        <div class="card card-info card-outline mb-3" id="instructions-card">
            <div class="card-header">
                <h2 class="card-title" id="instructions-title"><i class="fas fa-question-circle" aria-hidden="true"></i> Instructions</h2>
                <div class="card-tools">
                    <button type="button" class="btn btn-tool" 
                            data-lte-toggle="card-collapse" 
                            aria-expanded="true" 
                            aria-controls="instructions-body"
                            aria-label="Collapse instructions section">
                        <i class="fas fa-minus" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div class="card-body" id="instructions-body" role="region" aria-labelledby="instructions-title">
                <ol class="mb-2">
                    <li><strong>Select Course Student Learning Outcome:</strong> Choose the specific learning outcome you're assessing.</li>
                    <li><strong>Choose Primary Assessment Type:</strong> Select the type of assessment used (Quiz, Exam, Project, etc.). This is saved per Student Learning Outcome and will be remembered for this course section.</li>
                    <li><strong>Enter Achievement Levels:</strong> Click the button to indicate whether each student Met, Partially Met, did Not Meet, or was Not Assessed for the learning outcome.</li>
                    <li><strong>Quick Actions:</strong> Use the buttons above the table to quickly set all students to the same achievement level.</li>
                    <li><strong>Continuous Improvement Strategies (Optional):</strong> Check any strategies you've implemented or plan to implement.</li>
                </ol>
                <p class="mb-0 text-muted"><i class="fas fa-info-circle" aria-hidden="true"></i> <em>Note: All changes are saved automatically as you make them. No save button needed.</em></p>
            </div>
        </div>

        <?php if ($isXlisted && count($courseSections) > 1): ?>
            <!-- Multi-Section Course Selection -->
            <div class="card card-info card-outline mb-3">
                <div class="card-header">
                    <h2 class="card-title"><i class="fas fa-layer-group" aria-hidden="true"></i> Step 1: Select Which Section to Assess</h2>
                </div>
                <div class="card-body">
                    <p class="mb-3 text-muted">
                        <i class="fas fa-info-circle" aria-hidden="true"></i> 
                        This is a multi-section course. Choose the section you want to assess. 
                        The student roster and available Student Learning Outcomes will update based on your selection.
                    </p>
                    <nav aria-label="Course sections">
                    <div class="row g-2" role="group">
                        <?php foreach ($courseSections as $section): 
                            $isSelectedSection = ($section['sections_pk'] == $selectedSectionPk);
                            // Build URL parameters for section selection
                            // Use the first SLO for this section's course so switching never lands on an invalid SLO
                            $sectionUrl = '?lt=' . urlencode($ltiToken ?? '') . '&section_id=' . $section['sections_pk'];
                            $sectionCourseFk = (int)$section['course_fk'];
                            $sectionFirstSlo = $firstSloByCourseFk[$sectionCourseFk] ?? 0;
                            if ($sectionFirstSlo > 0) {
                                $sectionUrl .= '&slo_id=' . $sectionFirstSlo;
                            }
                        ?>
                            <div class="col-12 col-sm-6 col-md-4 col-lg-3 d-flex">
                                <a href="<?= htmlspecialchars($sectionUrl) ?>" 
                                   class="btn <?= $isSelectedSection ? 'btn-primary' : 'btn-outline-primary' ?> w-100 h-100 text-start p-3"
                                   <?= $isSelectedSection ? 'aria-current="true"' : '' ?>
                                   aria-label="<?= htmlspecialchars($section['course_number'] . ' - CRN ' . $section['section_id']) ?>">
                                    <div>
                                        <strong class="d-block mb-1"><?= htmlspecialchars($section['course_number']) ?></strong>
                                        <?php if (!empty($section['course_name'])): ?>
                                            <small class="d-block <?= $isSelectedSection ? 'text-white' : 'text-muted' ?>"><?= htmlspecialchars($section['course_name']) ?></small>
                                        <?php endif; ?>
                                        <small class="d-block mt-2 <?= $isSelectedSection ? 'text-white' : 'text-muted' ?>">
                                            <i class="fas fa-id-card" aria-hidden="true"></i> CRN: <?= htmlspecialchars($section['section_id']) ?>
                                        </small>
                                    </div>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    </nav>
                </div>
            </div>
        <?php endif; ?>

        <!-- SLO Selection Buttons -->
        <div class="card card-primary card-outline mb-3">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-tasks" aria-hidden="true"></i> <?= ($isXlisted && count($courseSections) > 1) ? 'Step 2:' : 'Step 1:' ?> Choose Learning Outcome to Assess</h2>
            </div>
            <div class="card-body">
                <p class="mb-3 text-muted">
                    <i class="fas fa-info-circle" aria-hidden="true"></i> 
                    Select the Student Learning Outcome you want to assess. The student roster and assessment form will update based on your selection.
                </p>
                <nav aria-label="Student Learning Outcomes">
                <div class="row g-2" role="group">
                    <?php foreach ($slos as $slo): 
                        $isSelected = $slo['student_learning_outcomes_pk'] == $selectedSloId;
                        // Preserve section_id in SLO links for multi-section courses
                        $sloUrl = '?lt=' . urlencode($ltiToken ?? '') . '&slo_id=' . $slo['student_learning_outcomes_pk'];
                        if ($isXlisted && count($courseSections) > 1 && $selectedSectionPk) {
                            $sloUrl .= '&section_id=' . $selectedSectionPk;
                        }
                    ?>
                        <div class="col-12 col-sm-6 col-md-4 col-lg-3 d-flex">
                            <a href="<?= htmlspecialchars($sloUrl) ?>" 
                               class="btn slo-button <?= $isSelected ? 'btn-primary' : 'btn-outline-primary' ?> w-100 h-100"
                               <?= $isSelected ? 'aria-current="true"' : '' ?>
                               aria-label="<?= htmlspecialchars($slo['slo_code'] . ': ' . $slo['slo_description']) ?>">
                                <strong><?= htmlspecialchars($slo['slo_code']) ?></strong>
                                <small class="slo-description mt-1"><?= htmlspecialchars($slo['slo_description']) ?></small>
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
                </nav>
            </div>
        </div>

        <?php if ($selectedSectionPk && $selectedSloId > 0): ?>
        <?php if (empty($students)): ?>
        <div class="alert alert-info mt-3" role="alert">
            <i class="fas fa-info-circle" aria-hidden="true"></i>
            No students are currently enrolled in this course section.
        </div>
        <?php else: ?>
        <!-- Assessment Entry Form -->
        <div class="slo-content" id="sloContent">
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="lt" value="<?= htmlspecialchars($ltiToken ?? '') ?>">
            <input type="hidden" name="action" value="save_assessments">
            <input type="hidden" name="section_pk" value="<?= (int)$selectedSectionPk ?>">
            <input type="hidden" name="slo_id" value="<?= $selectedSloId ?>">

            <!-- Assessment Method Selection -->
            <div class="card card-info card-outline mb-3">
                <div class="card-header">
                    <h2 class="card-title"><i class="fas fa-clipboard-list" aria-hidden="true"></i> Primary Assessment Type</h2>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <label for="assessment_method" class="form-label">What type of assessment did you use to evaluate students on this learning outcome?</label>
                            <select class="form-select" id="assessment_method" name="assessment_method">
                                <option value="">-- Select Primary Assessment Type --</option>
                                <?php
                                $assessmentTypes = explode('||', trim($config->get('app.assessment_types', 'Quiz||Exam||Project||Assignment'), '|'));
                                foreach ($assessmentTypes as $type):
                                    $type = trim($type);
                                    $selected = ($type === $currentAssessmentMethod) ? ' selected' : '';
                                ?>
                                    <option value="<?= htmlspecialchars($type) ?>"<?= $selected ?>><?= htmlspecialchars($type) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($currentAssessmentMethod): ?>
                            <small class="form-text text-muted">
                                Currently: <strong><?= htmlspecialchars($currentAssessmentMethod) ?></strong>
                            </small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h2 class="card-title">
                        <i class="fas fa-users" aria-hidden="true"></i> Student Assessments (<?= count($students) ?> students)
                    </h2>
                    <div class="card-tools ms-auto">
                        <fieldset class="btn-group">
                            <legend class="visually-hidden">Quick assessment actions</legend>
                            <button type="button" class="btn btn-sm btn-success" onclick="setAllOutcomes('met')" 
                                    aria-label="Mark all students as met">
                                <i class="fas fa-check" aria-hidden="true"></i> All Met
                            </button>
                            <button type="button" class="btn btn-sm btn-warning text-dark" onclick="setAllOutcomes('partially_met')"
                                    aria-label="Mark all students as partially met">
                                <i class="fas fa-minus-circle" aria-hidden="true"></i> All Partially Met
                            </button>
                            <button type="button" class="btn btn-sm btn-danger" onclick="setAllOutcomes('not_met')"
                                    aria-label="Mark all students as not met">
                                <i class="fas fa-times" aria-hidden="true"></i> All Not Met
                            </button>
                            <button type="button" class="btn btn-sm btn-secondary" onclick="setAllOutcomes('not_assessed')"
                                    aria-label="Mark all students as not assessed">
                                <i class="fas fa-minus" aria-hidden="true"></i> All Not Assessed
                            </button>
                        </fieldset>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0" aria-label="Student assessment results">
                            <caption class="visually-hidden">Student assessment results for selected SLO</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Student ID</th>
                                    <th scope="col">Student Name</th>
                                    <th scope="col">Achievement Level</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                foreach ($students as $student): 
                                    // A missing value is the same user-facing state as Not Assessed.
                                    $currentLevel = $student['achievement_level'] ?? 'not_assessed';
                                ?>
                                    <tr class="student-row">
                                        <td><?= htmlspecialchars($student['student_id']) ?></td>
                                        <td>
                                            <strong><?= htmlspecialchars($student['first_name']) ?> <?= htmlspecialchars($student['last_name']) ?></strong>
                                        </td>
                                        <td>
                                            <fieldset class="btn-group btn-group-sm outcome-buttons" data-enrollment="<?= $student['enrollment_pk'] ?>">
                                                <legend class="visually-hidden">Achievement level for <?= htmlspecialchars($student['first_name'] . ' ' . $student['last_name']) ?></legend>
                                                <input type="radio" class="btn-check outcome-radio" name="outcome[<?= $student['enrollment_pk'] ?>]" id="met_<?= $student['enrollment_pk'] ?>" value="met" <?= $currentLevel === 'met' ? 'checked' : '' ?> data-enrollment="<?= $student['enrollment_pk'] ?>">
                                                <label class="btn btn-outline-success" for="met_<?= $student['enrollment_pk'] ?>">
                                                    <i class="fas fa-check" aria-hidden="true"></i> Met
                                                </label>

                                                <input type="radio" class="btn-check outcome-radio" name="outcome[<?= $student['enrollment_pk'] ?>]" id="partially_met_<?= $student['enrollment_pk'] ?>" value="partially_met" <?= $currentLevel === 'partially_met' ? 'checked' : '' ?> data-enrollment="<?= $student['enrollment_pk'] ?>">
                                                <label class="btn btn-outline-warning text-dark" for="partially_met_<?= $student['enrollment_pk'] ?>">
                                                    <i class="fas fa-minus-circle" aria-hidden="true"></i> Partially Met
                                                </label>

                                                <input type="radio" class="btn-check outcome-radio" name="outcome[<?= $student['enrollment_pk'] ?>]" id="not_met_<?= $student['enrollment_pk'] ?>" value="not_met" <?= $currentLevel === 'not_met' ? 'checked' : '' ?> data-enrollment="<?= $student['enrollment_pk'] ?>">
                                                <label class="btn btn-outline-danger" for="not_met_<?= $student['enrollment_pk'] ?>">
                                                    <i class="fas fa-times" aria-hidden="true"></i> Not Met
                                                </label>

                                                <input type="radio" class="btn-check outcome-radio" name="outcome[<?= $student['enrollment_pk'] ?>]" id="not_assessed_<?= $student['enrollment_pk'] ?>" value="not_assessed" <?= $currentLevel === 'not_assessed' ? 'checked' : '' ?> data-enrollment="<?= $student['enrollment_pk'] ?>">
                                                <label class="btn btn-outline-secondary" for="not_assessed_<?= $student['enrollment_pk'] ?>">
                                                    <i class="fas fa-minus" aria-hidden="true"></i> Not Assessed
                                                </label>
                                            </fieldset>
                                            <span class="save-indicator ms-2" id="indicator_<?= $student['enrollment_pk'] ?>" role="status" aria-live="polite" aria-atomic="true"></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </form>

        <!-- Continuous Improvement Strategies -->
        <div class="card card-warning card-outline mt-3">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-lightbulb" aria-hidden="true"></i> Continuous Improvement Strategies (Optional)</h2>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <p class="text-muted mb-0">
                        Select any strategies you've implemented or plan to implement to improve student learning for this outcome.
                    </p>
                    <div class="btn-group" role="group" aria-label="Strategy selection actions">
                        <button type="button" id="selectAllStrategies" class="btn btn-sm btn-outline-primary" aria-label="Select all strategies">
                            <i class="fas fa-check-square" aria-hidden="true"></i> Select All
                        </button>
                        <button type="button" id="selectNoneStrategies" class="btn btn-sm btn-outline-secondary" aria-label="Clear all strategies">
                            <i class="fas fa-square" aria-hidden="true"></i> Select None
                        </button>
                    </div>
                </div>
                <fieldset>
                    <legend class="visually-hidden">Continuous improvement strategies</legend>
                    <div class="row">
                        <?php
                        $improvementStrategies = explode('||', trim($config->get('app.improvement_strategies', ''), '|'));
                        foreach ($improvementStrategies as $index => $strategy):
                            $strategy = trim($strategy);
                            if (empty($strategy)) continue;
                            $checked = in_array($strategy, $currentImprovementStrategies) ? ' checked' : '';
                            $strategyId = 'strategy_' . $index;
                        ?>
                            <div class="col-md-6 col-lg-4 mb-2">
                                <div class="form-check">
                                    <input class="form-check-input improvement-strategy-checkbox" type="checkbox" 
                                           id="<?= $strategyId ?>" 
                                           name="improvement_strategies[]" 
                                           value="<?= htmlspecialchars($strategy) ?>"<?= $checked ?>>
                                    <label class="form-check-label" for="<?= $strategyId ?>">
                                        <?= htmlspecialchars($strategy) ?>
                                    </label>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            </div>
        </div>
        </div><!-- /.slo-content -->
        <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
    
</div>
</main>

<!-- Toast Container -->
<div id="toast-container" class="toast-container" role="region" aria-label="Save notifications"></div>

<footer role="contentinfo" class="mt-5 py-3 border-top">
    <div class="container-fluid">
        <?php if ($hasAdminAccount): ?>
            <div class="mb-3 text-center">
                <a href="<?= BASE_URL ?>lti/autologin.php" target="_blank" rel="noopener noreferrer" class="btn btn-primary">
                    <i class="fas fa-external-link-alt" aria-hidden="true"></i> Open Dashboard
                    <span class="visually-hidden">(opens in new tab)</span>
                </a>
            </div>
        <?php endif; ?>
        <div class="d-flex justify-content-between align-items-center text-muted">
            <?php
            $copyrightYearStart = $config->get('app.copyright_year_start', 2024);
            $copyrightOrg = $config->get('app.copyright_organization', 'SLOCloud');
            $copyrightTagline = $config->get('app.copyright_tagline', 'Student Learning Outcomes Assessment');
            $currentYear = date('Y');
            $yearRange = ($copyrightYearStart == $currentYear) ? $currentYear : "$copyrightYearStart-$currentYear";
            ?>
            <small>&copy; <?= htmlspecialchars($yearRange) ?> <?= htmlspecialchars($copyrightOrg) ?><?= $copyrightTagline ? ' - ' . htmlspecialchars($copyrightTagline) : '' ?></small>
            <small>v<?= htmlspecialchars(APP_VERSION) ?> | status <?= htmlspecialchars($ltiStatusFlag) ?><?php
                $offerCode = $launchData['lti_course_offering_sourcedid'] ?? '';
                if ($offerCode): ?> | offer <?= htmlspecialchars($offerCode) ?><?php endif; ?></small>
        </div>
    </div>
</footer>

<?php
// Capture custom scripts to load after AdminLTE
ob_start();
?>

<script>
// CSRF token for AJAX requests
const csrfToken = '<?= htmlspecialchars($_SESSION['csrf_token']) ?>';
const selectedSectionPk = <?= (int)$selectedSectionPk ?>;
const selectedSloId = <?= $selectedSloId ?>;

// Toast notification system
function showToast(message, type = 'info', duration = 8000, action = null) {
    const container = document.getElementById('toast-container');
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    toast.setAttribute('aria-atomic', 'true');
    
    const icon = type === 'success' ? 'fa-check-circle' : 
                 type === 'error' ? 'fa-exclamation-circle' : 
                 'fa-info-circle';
    
    const toastHeader = document.createElement('div');
    toastHeader.className = 'toast-header';
    const iconElement = document.createElement('i');
    iconElement.className = `fas ${icon} me-2`;
    iconElement.setAttribute('aria-hidden', 'true');
    const heading = document.createElement('strong');
    heading.className = 'me-auto';
    heading.textContent = type.charAt(0).toUpperCase() + type.slice(1);
    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'btn-close ms-2';
    closeButton.setAttribute('aria-label', 'Dismiss notification');
    toastHeader.append(iconElement, heading, closeButton);
    const toastBody = document.createElement('div');
    toastBody.className = 'toast-body';
    toastBody.textContent = message;
    if (action && typeof action.callback === 'function') {
        const actionButton = document.createElement('button');
        actionButton.type = 'button';
        actionButton.className = 'btn btn-sm btn-outline-primary ms-2';
        actionButton.textContent = action.label || 'Undo';
        actionButton.addEventListener('click', function () {
            actionButton.disabled = true;
            action.callback();
            removeToast();
        });
        toastBody.append(actionButton);
    }
    toast.appendChild(toastHeader);
    toast.appendChild(toastBody);
    
    container.appendChild(toast);
    
    // Trigger animation
    setTimeout(() => toast.classList.add('show'), 10);
    
    let remaining = duration;
    let startedAt = Date.now();
    let removalTimer = null;
    const removeToast = () => {
        toast.classList.remove('show');
        setTimeout(() => toast.remove(), 300);
    };
    const resumeTimer = () => {
        if (remaining <= 0 || duration === 0) return;
        startedAt = Date.now();
        removalTimer = setTimeout(removeToast, remaining);
    };
    const pauseTimer = () => {
        if (removalTimer === null) return;
        clearTimeout(removalTimer);
        removalTimer = null;
        remaining -= Date.now() - startedAt;
    };
    closeButton.addEventListener('click', removeToast);
    toast.addEventListener('mouseenter', pauseTimer);
    toast.addEventListener('mouseleave', resumeTimer);
    toast.addEventListener('focusin', pauseTimer);
    toast.addEventListener('focusout', resumeTimer);
    resumeTimer();
}

// Save individual assessment via AJAX
function saveAssessment(enrollmentId, achievementLevel, previousLevel = '') {
    // Get UI elements for this specific enrollment
    const indicator = document.getElementById('indicator_' + enrollmentId);
    
    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('action', 'save_assessments');
    formData.append('section_pk', selectedSectionPk);
    formData.append('slo_id', selectedSloId);
    formData.append('outcome[' + enrollmentId + ']', achievementLevel);
    
    // Send AJAX request
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // Parse JSON regardless of status code
        return response.json().then(data => ({
            ok: response.ok,
            status: response.status,
            data: data
        })).catch(jsonError => {
            // If JSON parsing fails, return error info
            return {
                ok: false,
                status: response.status,
                data: { success: false, message: 'Invalid server response' }
            };
        });
    })
    .then(({ok, status, data}) => {
        if (ok && data.success) {
            const group = document.querySelector(`.outcome-buttons[data-enrollment="${CSS.escape(String(enrollmentId))}"]`);
            if (group) group.dataset.currentValue = achievementLevel;
            if (indicator) {
                indicator.innerHTML = '<i class="fas fa-check text-success" aria-hidden="true"></i><span class="visually-hidden">Assessment saved</span>';
                setTimeout(() => {
                    indicator.innerHTML = '';
                }, 2000);
            }
            showToast('Assessment saved successfully', 'success', 8000, previousLevel ? {
                label: 'Undo assessment change',
                callback: function () {
                    const previousRadio = document.getElementById(previousLevel + '_' + enrollmentId);
                    if (previousRadio) previousRadio.checked = true;
                    saveAssessment(enrollmentId, previousLevel, achievementLevel);
                }
            } : null);
        } else {
            throw new Error(data.message || 'Save failed');
        }
    })
    .catch(error => {
        console.error('Error saving assessment:', error);
        if (indicator) {
            indicator.innerHTML = '<i class="fas fa-exclamation-triangle text-danger" aria-hidden="true"></i><span class="visually-hidden">Assessment was not saved</span>';
            setTimeout(() => {
                indicator.innerHTML = '';
            }, 3000);
        }
        const previousRadio = document.getElementById(previousLevel + '_' + enrollmentId);
        if (previousRadio) previousRadio.checked = true;
        showToast(error.message || 'Error saving assessment', 'error', 0);
    });
}

// Save primary assessment type when changed
let savedAssessmentMethod = document.getElementById('assessment_method')?.value || '';
function saveAssessmentMethod(assessmentMethod, previousMethod = savedAssessmentMethod) {
    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('action', 'save_method');
    formData.append('section_pk', selectedSectionPk);
    formData.append('slo_id', selectedSloId);
    formData.append('assessment_method', assessmentMethod);
    
    // Also send current improvement strategies from checkboxes
    const checkboxes = document.querySelectorAll('.improvement-strategy-checkbox:checked');
    checkboxes.forEach(checkbox => {
        formData.append('improvement_strategies[]', checkbox.value);
    });

    showToast('Saving primary assessment type...', 'info');

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // Parse JSON regardless of status code
        return response.json().then(data => ({
            ok: response.ok,
            status: response.status,
            data: data
        })).catch(jsonError => {
            // If JSON parsing fails, return error info
            return {
                ok: false,
                status: response.status,
                data: { success: false, message: 'Invalid server response' }
            };
        });
    })
    .then(({ok, status, data}) => {
        if (ok && data.success) {
            const message = assessmentMethod ? `Primary assessment type saved: ${assessmentMethod}` : 'Primary assessment type cleared';
            savedAssessmentMethod = assessmentMethod;
            showToast(message, 'success', 8000, {
                label: 'Undo assessment type change',
                callback: function () {
                    const select = document.getElementById('assessment_method');
                    if (select) select.value = previousMethod;
                    saveAssessmentMethod(previousMethod, assessmentMethod);
                }
            });
        } else {
            showToast('Error: ' + (data.message || 'Failed to save'), 'error');
        }
    })
    .catch(error => {
        console.error('Error saving method:', error);
        const select = document.getElementById('assessment_method');
        if (select) select.value = previousMethod;
        showToast('Error saving primary assessment type', 'error');
    });
}

// Save improvement strategies separately
let savedStrategyValues = Array.from(document.querySelectorAll('.improvement-strategy-checkbox:checked'), checkbox => checkbox.value);
function saveImprovementStrategies(previousValues = savedStrategyValues) {
    const formData = new FormData();
    formData.append('csrf_token', csrfToken);
    formData.append('action', 'save_strategies');
    formData.append('section_pk', selectedSectionPk);
    formData.append('slo_id', selectedSloId);
    
    // Send current assessment method from dropdown (ID is 'assessment_method')
    const methodSelect = document.getElementById('assessment_method');
    if (methodSelect) {
        formData.append('assessment_method', methodSelect.value);
    }
    
    // Add improvement strategies from checkboxes
    const checkboxes = document.querySelectorAll('.improvement-strategy-checkbox:checked');
    const currentValues = Array.from(checkboxes, checkbox => checkbox.value);
    checkboxes.forEach(checkbox => {
        formData.append('improvement_strategies[]', checkbox.value);
    });

    showToast('Saving improvement strategies...', 'info');

    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // Parse JSON regardless of status code
        return response.json().then(data => ({
            ok: response.ok,
            status: response.status,
            data: data
        })).catch(jsonError => {
            // If JSON parsing fails, return error info
            return {
                ok: false,
                status: response.status,
                data: { success: false, message: 'Invalid server response' }
            };
        });
    })
    .then(({ok, status, data}) => {
        if (ok && data.success) {
            savedStrategyValues = currentValues;
            showToast(data.message || 'Improvement strategies saved', 'success', 8000, {
                label: 'Undo strategy changes',
                callback: function () {
                    document.querySelectorAll('.improvement-strategy-checkbox').forEach(function (checkbox) {
                        checkbox.checked = previousValues.includes(checkbox.value);
                    });
                    saveImprovementStrategies(currentValues);
                }
            });
        } else {
            showToast('Error: ' + (data.message || 'Failed to save'), 'error');
        }
    })
    .catch(error => {
        console.error('Error saving strategies:', error);
        document.querySelectorAll('.improvement-strategy-checkbox').forEach(function (checkbox) {
            checkbox.checked = previousValues.includes(checkbox.value);
        });
        showToast('Error saving improvement strategies', 'error');
    });
}

// Attach event listeners to all radio buttons
document.addEventListener('DOMContentLoaded', function() {
    // Fade in SLO content on page load
    const sloContent = document.getElementById('sloContent');
    if (sloContent) {
        setTimeout(() => {
            sloContent.classList.add('fade-in');
        }, 100);
    }
    
    // Collapsible card accessibility - sync aria-expanded with card state
    const collapseButton = document.querySelector('[data-lte-toggle="card-collapse"]');
    const instructionsCard = document.getElementById('instructions-card');
    if (collapseButton && instructionsCard) {
        // Listen for AdminLTE card events
        instructionsCard.addEventListener('collapsed.lte.cardwidget', function() {
            collapseButton.setAttribute('aria-expanded', 'false');
            collapseButton.setAttribute('aria-label', 'Expand instructions section');
        });
        instructionsCard.addEventListener('expanded.lte.cardwidget', function() {
            collapseButton.setAttribute('aria-expanded', 'true');
            collapseButton.setAttribute('aria-label', 'Collapse instructions section');
        });
        
        // Fallback: manual click handler if AdminLTE doesn't fire events
        collapseButton.addEventListener('click', function() {
            setTimeout(() => {
                const isCollapsed = instructionsCard.classList.contains('collapsed-card');
                collapseButton.setAttribute('aria-expanded', String(!isCollapsed));
                collapseButton.setAttribute('aria-label', isCollapsed ? 'Expand instructions section' : 'Collapse instructions section');
            }, 100);
        });
    }
    
    document.querySelectorAll('.outcome-radio').forEach(function(radio) {
        const group = radio.closest('.outcome-buttons');
        if (group && !group.dataset.currentValue) {
            group.dataset.currentValue = group.querySelector('.outcome-radio:checked')?.value || '';
        }
        radio.addEventListener('change', function() {
            if (this.checked) {
                const enrollmentId = this.getAttribute('data-enrollment');
                const achievementLevel = this.value;
                const previousLevel = this.closest('.outcome-buttons')?.dataset.currentValue || '';
                saveAssessment(enrollmentId, achievementLevel, previousLevel);
            }
        });
    });
    
    // Primary assessment type dropdown change handler
    const methodSelect = document.getElementById('assessment_method');
    if (methodSelect) {
        methodSelect.addEventListener('change', function() {
            // Always save, even if empty (allows deselecting)
            saveAssessmentMethod(this.value);
        });
    }
    
    // Improvement strategies checkbox change handler
    document.querySelectorAll('.improvement-strategy-checkbox').forEach(function(checkbox) {
        checkbox.addEventListener('change', function() {
            saveImprovementStrategies();
        });
    });
});

// Set all outcomes with confirmation (WCAG 3.3.6 Level AAA - Error Prevention)
function setAllOutcomes(outcome) {
    const outcomeText = outcome === 'met' ? 'Met' :
                       outcome === 'partially_met' ? 'Partially Met' :
                       outcome === 'not_met' ? 'Not Met' : 'Not Assessed';
    const studentCount = document.querySelectorAll('.outcome-buttons').length;
    
    // Confirmation dialog for bulk actions
    if (!confirm(`Mark all ${studentCount} students as "${outcomeText}"?\n\nEach saved change can be undone from its confirmation message.`)) {
        return; // User cancelled
    }
    
    document.querySelectorAll('.outcome-buttons').forEach(function(btnGroup) {
        const enrollmentId = btnGroup.getAttribute('data-enrollment');
        const radio = document.getElementById(outcome + '_' + enrollmentId);
        if (radio && !radio.checked) {
            const previousLevel = btnGroup.dataset.currentValue || '';
            radio.checked = true;
            saveAssessment(enrollmentId, outcome, previousLevel);
        }
    });
}

// Select all improvement strategies
document.getElementById('selectAllStrategies')?.addEventListener('click', function(e) {
    e.preventDefault();
    document.querySelectorAll('.improvement-strategy-checkbox').forEach(function(checkbox) {
        checkbox.checked = true;
    });
    saveImprovementStrategies();
});

// Select none improvement strategies
document.getElementById('selectNoneStrategies')?.addEventListener('click', function(e) {
    e.preventDefault();
    document.querySelectorAll('.improvement-strategy-checkbox').forEach(function(checkbox) {
        checkbox.checked = false;
    });
    saveImprovementStrategies();
});

// Session keepalive — pings every 4 minutes.
// If the server reports the session is gone, wipes the page immediately
// rather than letting the instructor submit into a dead context.
(function () {
    const PING_INTERVAL = 4 * 60 * 1000;
    const TIMEOUT = 8 * 60 * 60 * 1000;
    const WARNING = 2 * 60 * 1000;
    const KEEPALIVE_URL = '<?= htmlspecialchars(BASE_URL . 'lti/keepalive.php?lt=' . urlencode($ltiToken ?? '')) ?>';
    let consecutiveFailures = 0;
    let expired = false;
    let activitySincePing = false;
    let warningTimer = null;
    let countdownTimer = null;
    let deadline = Date.now() + TIMEOUT;

    const dialog = document.createElement('dialog');
    dialog.className = 'a11y-session-dialog';
    dialog.setAttribute('aria-labelledby', 'lti-session-title');
    dialog.setAttribute('aria-describedby', 'lti-session-description');
    dialog.innerHTML = '<h2 id="lti-session-title">Your assessment session is about to expire</h2>'
        + '<p id="lti-session-description">Inactive assessment sessions end after eight hours. Continue your session to keep working.</p>'
        + '<p><strong id="lti-session-countdown" role="timer" aria-live="polite"></strong></p>'
        + '<div class="a11y-session-actions"><button type="button" class="btn btn-primary" data-lti-continue>Continue session</button>'
        + '<button type="button" class="btn btn-secondary" data-lti-expire>End session</button></div>';
    document.body.append(dialog);

    const title = dialog.querySelector('#lti-session-title');
    const description = dialog.querySelector('#lti-session-description');
    const countdown = dialog.querySelector('#lti-session-countdown');
    const continueButton = dialog.querySelector('[data-lti-continue]');

    function updateCountdown() {
        const seconds = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        const minutes = Math.floor(seconds / 60);
        countdown.textContent = `Session expires in ${minutes}:${String(seconds % 60).padStart(2, '0')}.`;
        if (seconds <= 0) showExpired();
    }

    function scheduleWarning() {
        clearTimeout(warningTimer);
        deadline = Date.now() + TIMEOUT;
        warningTimer = setTimeout(function () {
            if (expired) return;
            dialog.showModal();
            updateCountdown();
            countdownTimer = setInterval(updateCountdown, 1000);
            continueButton.focus();
        }, TIMEOUT - WARNING);
    }

    function showExpired() {
        if (expired) { return; }
        expired = true;
        clearTimeout(warningTimer);
        clearInterval(countdownTimer);
        title.textContent = 'Assessment session expired';
        description.textContent = 'Return to your Learning Management System and relaunch the assessment tool.';
        countdown.textContent = '';
        continueButton.remove();
        dialog.querySelector('[data-lti-expire]').remove();
        if (!dialog.open) dialog.showModal();
        dialog.setAttribute('tabindex', '-1');
        dialog.focus();
    }

    function ping(force) {
        if (expired || (!force && !activitySincePing)) return Promise.resolve(false);
        return fetch(KEEPALIVE_URL, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', Accept: 'application/json' },
            body: new URLSearchParams({ csrf_token: csrfToken })
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                consecutiveFailures = 0;
                if (!data.alive) {
                    showExpired();
                    return false;
                }
                activitySincePing = false;
                return true;
            })
            .catch(function () {
                consecutiveFailures++;
                if (consecutiveFailures === 1) {
                    showToast('Cannot reach the server. Your changes may not be saving.', 'error', 8000);
                } else if (consecutiveFailures >= 3) {
                    showToast('Server is unreachable. Save your work and reload the page.', 'error', 0);
                }
                return false;
            });
    }

    ['keydown', 'pointerdown', 'input'].forEach(function (eventName) {
        document.addEventListener(eventName, function () {
            if (!expired && !dialog.open) {
                activitySincePing = true;
                scheduleWarning();
            }
        }, { passive: true });
    });
    continueButton.addEventListener('click', function () {
        continueButton.disabled = true;
        ping(true).then(function (alive) {
            if (alive) {
                clearInterval(countdownTimer);
                dialog.close();
                scheduleWarning();
            }
            continueButton.disabled = false;
        });
    });
    dialog.querySelector('[data-lti-expire]').addEventListener('click', showExpired);
    dialog.addEventListener('cancel', function (event) { event.preventDefault(); });
    setInterval(function () { ping(false); }, PING_INTERVAL);
    scheduleWarning();
}());
</script>

<?php
// Get custom scripts and add to context
$customScripts = ob_get_clean();
$context->set('customScripts', $customScripts);

// Load theme footer (loads jQuery, Bootstrap, AdminLTE, then outputs customScripts, then closes body/html)
$theme->showFooter($context);
?>
