<?php
declare(strict_types=1);

namespace SLOCloud\Core;

/** Central deny-by-default authorization for local administration routes. */
final class Authorization
{
    /** Explicit route-to-permission matrix. Unlisted administration routes are denied. */
    private const ADMIN_ROUTES = [
        'activity_log.php', 'index.php', 'assessments.php', 'assessments_data.php',
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
        'site_map.php', 'system_status.php', 'term_imports_normalized.php', 'terms.php',
        'terms_data.php', 'users.php', 'users_data.php', 'vpat.php', 'vpat_view.php', 'xlist.php',
        'xlist_data.php',
    ];

    /** Pages that expose security-sensitive administration data or operations. */
    private const ADMIN_ONLY_ROUTES = [
        'activity_log.php', 'config.php', 'system_status.php',
        'term_imports_normalized.php', 'users.php', 'users_data.php',
    ];

    /**
     * Pages whose POST handlers mutate application data.
     *
     * Standard users are intentionally read/export-only. Their DataTables POST
     * endpoints are not listed because those requests only read data.
     */
    private const ADMIN_WRITE_ROUTES = [
        'assessments.php', 'courses.php', 'disciplines.php', 'enrollment.php',
        'institutional_outcomes.php', 'program_outcomes.php', 'programs.php',
        'lifecycle_status.php',
        'sections.php', 'student_learning_outcomes.php', 'students.php',
        'terms.php', 'xlist.php',
    ];

    /** Routes that change credentials, roles, keys, or security configuration. */
    private const RECENT_AUTH_ROUTES = ['config.php', 'users.php', 'users_data.php'];

    private const ROUTE_POLICIES = [
        'index.php' => 'front_controller',
        'login.php' => 'public',
        'logout.php' => 'authenticated_csrf',
        'session.php' => 'authenticated_csrf',
        'api/api.php' => 'api_key_or_admin',
        'api/index.php' => 'api_key_or_admin',
        'api/rotate_key.php' => 'admin_recent_auth',
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

    public static function requireAdministrationRoute(
        Database $db,
        string $prefix,
        Logger $logger,
        string $route,
        string $method = 'GET'
    ): void
    {
        $filename = basename($route);
        if (!in_array($filename, self::ADMIN_ROUTES, true)) {
            self::deny($logger, $route, 'unclassified_route');
        }
        if (!self::isGlobalAdmin($db, $prefix)) {
            self::deny($logger, $route, 'admin_required');
        }
        if (strtoupper($method) === 'POST'
            && in_array($filename, self::RECENT_AUTH_ROUTES, true)
            && time() - (int)($_SESSION['authenticated_at'] ?? 0) > 900) {
            self::deny($logger, $route, 'recent_authentication_required');
        }
    }

    public static function requireGlobalAdmin(Database $db, string $prefix, Logger $logger, string $route): void
    {
        if (!self::isGlobalAdmin($db, $prefix)) {
            self::deny($logger, $route, 'admin_required');
        }
    }

    public static function requireRecentAuthentication(Logger $logger, string $route, int $maxAgeSeconds = 900): void
    {
        $authenticatedAt = (int)($_SESSION['authenticated_at'] ?? 0);
        if ($authenticatedAt <= 0 || time() - $authenticatedAt > $maxAgeSeconds) {
            self::deny($logger, $route, 'recent_authentication_required');
        }
    }

    private static function deny(Logger $logger, string $route, string $reason): never
    {
        http_response_code(403);
        $logger->security(
            Logger::EVENT_PERMISSION_DENIED,
            'Administration authorization denied',
            isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null,
            null,
            Logger::WARNING,
            true,
            ['route' => $route, 'reason' => $reason]
        );
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Forbidden';
        exit;
    }
}
