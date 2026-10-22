<?php
declare(strict_types=1);

namespace SLOCloud\Core;

/** Central deny-by-default authorization for local administration routes. */
final class Authorization
{
    /** Explicit route-to-permission matrix. Unlisted administration routes are denied. */
    private const ADMIN_ROUTES = [
        'activity_log.php', 'index.php', 'assessments.php', 'assessments_data.php',
        'canvas_deploy.php', 'canvas_deploy_api.php',
        'config.php', 'courses.php', 'courses_data.php', 'dashboard_courses_data.php',
        'dashboard_sections_data.php', 'disciplines.php', 'disciplines_data.php',
        'dynamic_reports.php', 'enrollment.php', 'enrollment_data.php',
        'institutional_outcomes.php', 'institutional_outcomes_data.php',
        'lifecycle_status.php',
        'patch_notes.php', 'program_outcomes.php', 'program_outcomes_data.php',
        'related_records_data.php',
        'programs.php', 'programs_data.php', 'report_options.php', 'sections.php',
        'sections_data.php', 'student_learning_outcomes.php',
        'student_learning_outcomes_data.php', 'students.php', 'students_data.php',
        'site_map.php', 'system_status.php', 'term_imports_normalized.php', 'terms.php', 'color_scheme.php',
        'terms_data.php', 'users.php', 'users_data.php', 'vpat.php', 'vpat_view.php', 'xlist.php',
        'xlist_data.php',
    ];

    /** Pages that expose security-sensitive administration data or operations. */
    private const ADMIN_ONLY_ROUTES = [
        'activity_log.php', 'canvas_deploy.php', 'canvas_deploy_api.php', 'color_scheme.php', 'config.php', 'system_status.php',
        'term_imports_normalized.php', 'users.php', 'users_data.php',
    ];

    /**
     * Pages whose POST handlers mutate application data.
     *
     * Standard users are intentionally read/export-only. Their DataTables POST
     * endpoints are not listed because those requests only read data.
     */
    private const ADMIN_WRITE_ROUTES = [
        'assessments.php', 'canvas_deploy_api.php', 'courses.php', 'disciplines.php', 'enrollment.php',
        'institutional_outcomes.php', 'program_outcomes.php', 'programs.php',
        'lifecycle_status.php',
        'sections.php', 'student_learning_outcomes.php', 'students.php',
        'terms.php', 'xlist.php',
    ];

    /** What a refused user is told; the reason code itself goes to the security log. */
    private const DENY_MESSAGES = [
        'admin_required' => 'Only administrators can do this. Ask an administrator if you need access.',
        'unclassified_route' => 'This page is not available.',
    ];

    private const ROUTE_POLICIES = [
        'index.php' => 'front_controller',
        'login.php' => 'public',
        'logout.php' => 'authenticated_csrf',
        'session.php' => 'authenticated_csrf',
        'api/api.php' => 'api_key_or_admin',
        'api/index.php' => 'api_key_or_admin',
        'api/rotate_key.php' => 'admin',
        'api/move_key_folder.php' => 'admin',
        'lti/index.php' => 'admin',
        'lti/launch.php' => 'lti_protocol',
        'lti/assessment.php' => 'lti_launch_context',
        'lti/autologin.php' => 'lti_launch_context',
        'lti/keepalive.php' => 'lti_launch_context',
        'lti/oauth_validate.php' => 'include_only',
        'lti/throttled_failure_reasons.php' => 'include_only',
        'saml/sso.php' => 'saml_protocol',
        'saml/acs.php' => 'saml_protocol',
        'setup/index.php' => 'installation_token',
    ];

    public static function policyForRoute(string $route): string
    {
        if (str_starts_with($route, 'administration/')) {
            $filename = basename($route);
            if (!in_array($filename, self::ADMIN_ROUTES, true)) {
                return 'deny';
            }
            return in_array($filename, self::ADMIN_ONLY_ROUTES, true) ? 'admin' : 'authenticated';
        }
        return self::ROUTE_POLICIES[$route] ?? 'deny';
    }

    public static function policyForRequest(string $route, string $method): string
    {
        $policy = self::policyForRoute($route);
        if ($policy === 'authenticated'
            && strtoupper($method) === 'POST'
            && in_array(basename($route), self::ADMIN_WRITE_ROUTES, true)) {
            return 'admin';
        }
        return $policy;
    }

    public static function routeMatrix(): array
    {
        $matrix = self::ROUTE_POLICIES;
        foreach (self::ADMIN_ROUTES as $route) {
            $matrix['administration/' . $route] = self::policyForRoute('administration/' . $route);
        }
        ksort($matrix);
        return $matrix;
    }

    public static function denyUnclassifiedRoute(Logger $logger, string $route): never
    {
        self::deny($logger, $route, 'unclassified_route');
    }

    public static function isGlobalAdmin(Database $db, string $prefix, ?int $userId = null): bool
    {
        if (($_SESSION['is_emergency_admin'] ?? false) === true) {
            return true;
        }
        $userId ??= (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        try {
            $result = $db->query(
                "SELECT COUNT(*) AS admin_count FROM {$prefix}user_roles ur "
                . "JOIN {$prefix}roles r ON r.roles_pk = ur.role_fk "
                . "WHERE ur.user_fk = ? AND r.role_name = 'admin' "
                . "AND ur.context_type IS NULL AND ur.context_id IS NULL",
                [$userId],
                'i'
            );
            $row = $result ? $result->fetch() : false;
            return (int)($row['admin_count'] ?? 0) > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function requireAdministrationRoute(Database $db, string $prefix, Logger $logger, string $route): void
    {
        if (!in_array(basename($route), self::ADMIN_ROUTES, true)) {
            self::deny($logger, $route, 'unclassified_route');
        }
        if (!self::isGlobalAdmin($db, $prefix)) {
            self::deny($logger, $route, 'admin_required');
        }
    }

    public static function requireGlobalAdmin(Database $db, string $prefix, Logger $logger, string $route): void
    {
        if (!self::isGlobalAdmin($db, $prefix)) {
            self::deny($logger, $route, 'admin_required');
        }
    }

    /**
     * Ends the session (idle timeout or maximum session length) and sends the user to
     * the login page with $message, returning afterwards to the administration page
     * they were viewing. Background requests get a 401 JSON reply instead of a redirect,
     * so tables and dialogs can react rather than receive the login page as data.
     */
    public static function endSession(string $message, string $route, string $method, ?string $reason = null): never
    {
        // A signed-in user's session ending is recorded on the Activity Log with its reason.
        if ($reason !== null && isset($_SESSION['user_id'])) {
            $userId = (int)$_SESSION['user_id'];
            Logger::getInstance()->security(
                Logger::EVENT_SESSION_ENDED,
                $message,
                $userId > 0 ? $userId : null,
                (string)($_SESSION['user_email'] ?? ''),
                'info',
                false,
                ['reason' => $reason, 'route' => $route]
            );
        }
        session_unset();
        session_regenerate_id(true);
        $_SESSION['flash_error'] = $message;
        // A background GET (a table's data, an export) is not a page to come back to.
        if (strtoupper($method) === 'GET' && !self::wantsJson()
            && preg_match('#^administration/[a-z_]+\.php$#', $route) === 1) {
            $_SESSION['login_return'] = $route;
        }
        $loginUrl = \BASE_URL . 'login.php';
        if (self::wantsJson()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => 'session_ended', 'message' => $message, 'login_url' => $loginUrl]);
            exit;
        }
        header('Location: ' . $loginUrl, true, 303);
        exit;
    }

    /** Where to go after signing in: the page the ended session was on, otherwise the dashboard. */
    public static function takeLoginReturn(): string
    {
        $route = (string)($_SESSION['login_return'] ?? '');
        unset($_SESSION['login_return']);
        if (preg_match('#^administration/[a-z_]+\.php$#', $route) === 1) {
            return \BASE_URL . $route;
        }
        return \BASE_URL . 'administration/';
    }

    /** True for jQuery/DataTables requests and fetch() calls that ask for JSON. */
    public static function wantsJson(): bool
    {
        return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
            || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
    }

    private static function deny(Logger $logger, string $route, string $reason): never
    {
        http_response_code(403);
        self::logDenied($logger, $route, $reason);
        $message = self::DENY_MESSAGES[$reason] ?? 'You do not have permission to do this.';
        if (self::wantsJson()) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['error' => $reason, 'message' => $message]);
            exit;
        }
        $home = htmlspecialchars((defined('BASE_URL') ? \BASE_URL : '/') . 'administration/', ENT_QUOTES, 'UTF-8');
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Access denied</title></head><body>'
            . '<main style="font-family: system-ui, sans-serif; max-width: 40rem; margin: 4rem auto; padding: 0 1rem;">'
            . '<h1>Access denied</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><a href="' . $home . '">Return to the dashboard</a></p></main></body></html>';
        exit;
    }

    private static function logDenied(Logger $logger, string $route, string $reason): void
    {
        $logger->security(
            Logger::EVENT_PERMISSION_DENIED,
            self::DENY_MESSAGES[$reason] ?? 'Administration authorization denied',
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
            null,
            Logger::WARNING,
            // A user reaching a page their role does not allow is an ordinary refusal;
            // requests for routes the application does not have look like probing.
            $reason === 'unclassified_route',
            ['route' => $route, 'reason' => $reason]
        );
    }
}
