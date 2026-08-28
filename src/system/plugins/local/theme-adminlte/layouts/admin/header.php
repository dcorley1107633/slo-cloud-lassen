<?php
/**
 * AdminLTE 4 Theme - Admin Layout Header
 * Full sidebar navigation for administration
 */

// Explicit UTF-8 output encoding
header('Content-Type: text/html; charset=UTF-8');
$themeDb = $GLOBALS['db'] ?? null;
$themeDbPrefix = (string)($GLOBALS['dbPrefix'] ?? '');
$themeCanAdminister = $themeDb instanceof \SLOCloud\Core\Database
    && \SLOCloud\Core\Authorization::isGlobalAdmin($themeDb, $themeDbPrefix);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require dirname(__DIR__, 5) . '/includes/session_timeout_meta.php'; ?>
    <title><?= htmlspecialchars($pageTitle ?? 'SLOCloud') ?></title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/source-sans-pro/5.3.0/source-sans-pro.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/fontawesome-free/6.4.0/css/all.min.css">
    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap/5.3.8/css/bootstrap.min.css">
    <!-- AdminLTE 4 -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/admin-lte/4.1.0/css/adminlte.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>system/accessibility.css">
    <script src="<?= BASE_URL ?>system/accessibility.js" defer></script>
    
    <style>
        :root {
            --primary-dark: #0D47A1;
            --accent-blue: #1976D2;
            --brand-teal: #1565C0;
        }
        
        /* ==================== WCAG 2.2 AA/AAA Accessibility Styles ==================== */
        
        /* Skip link - visible on focus for keyboard navigation */
        .skip-link {
            position: absolute;
            top: 0;
            left: 0;
            z-index: 10000;
            padding: 0.75rem 1.5rem;
            transform: translateY(-100%);
            background: var(--accent-blue);
            color: white;
        }
        .skip-link:focus {
            transform: translateY(0);
        }
        
        /* Enhanced focus indicators (WCAG 2.4.7, 2.4.11, 2.4.13 Level AAA) */
        a:focus,
        button:focus,
        input:focus,
        select:focus,
        textarea:focus,
        .nav-link:focus,
        [tabindex]:focus {
            outline: 3px solid #0D47A1;
            outline-offset: 2px;
            box-shadow: 0 0 0 4px rgba(13, 71, 161, 0.2);
        }
        
        /* Focus visible for :focus-visible support */
        *:focus-visible {
            outline: 3px solid #0D47A1;
            outline-offset: 2px;
            box-shadow: 0 0 0 4px rgba(13, 71, 161, 0.2);
        }
        
        /*Enhanced line spacing for readability (WCAG 1.4.8 Level AAA) */
        body {
            line-height: 1.5;
        }
        p {
            margin-bottom: 1.5em;
        }
        
        /* Respect user preference for reduced motion (WCAG 2.3.3 Level AAA) */
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
                scroll-behavior: auto !important;
            }
        }
        
        /* Enhanced contrast for muted text (WCAG 1.4.6 Level AAA - 7:1) */
        .text-muted {
            color: #495057 !important;
        }
        
        /* Minimum target sizes (WCAG 2.5.8 Level AA - 24x24px) */
        .btn, .nav-link, .dropdown-item, .form-select, .form-control {
            min-height: 24px;
            padding: 0.5rem 1rem;
        }
        .btn-sm {
            min-height: 24px;
            min-width: 24px;
        }

        .sidebar-menu button.nav-link {
            width: 100%;
            text-align: left;
        }
        
        /* ==================== End Accessibility Styles ==================== */
    </style>
    
    <?php if (isset($customCss)): ?>
    <?= $customCss ?>
    <?php endif; ?>
</head>
<body class="layout-fixed sidebar-expand-lg">

<!-- Skip Navigation Link -->
<div class="skip-links"><a href="#main" class="skip-link">Skip to main content</a></div>

<div class="app-wrapper">

<!-- Navbar -->
<nav class="app-header navbar navbar-expand bg-body" role="navigation" aria-label="Main navigation">
    <ul class="navbar-nav">
        <li class="nav-item">
            <button class="nav-link border-0 bg-transparent" type="button" data-lte-toggle="sidebar" aria-label="Toggle sidebar navigation" aria-expanded="true" aria-controls="sidebar-nav"><i class="fas fa-bars" aria-hidden="true"></i></button>
        </li>
        <li class="nav-item d-none d-sm-inline-block">
            <a href="<?= BASE_URL ?>" class="nav-link"><i class="fas fa-home" aria-hidden="true"></i> Home</a>
        </li>
    </ul>
    <ul class="navbar-nav ms-auto"  role="menubar" aria-label="User menu">
        <?php
        // Get database and config from global scope
        $db = $GLOBALS['db'] ?? null;
        $dbPrefix = $GLOBALS['dbPrefix'] ?? '';
        $headerTerms = [];
        $headerSelectedTerm = null;
        
        // Fetch active terms for header dropdown
        if ($db && $dbPrefix) {
            try {
                $headerTermsResult = $db->query("
                    SELECT terms_pk, term_code, term_name, academic_year
                    FROM {$dbPrefix}terms 
                    ORDER BY term_code ASC
                ");
                $headerTerms = $headerTermsResult->fetchAll();
                
                // Get selected term from session/GET
                $headerSelectedTerm = function_exists('getSelectedTermFk') ? getSelectedTermFk() : null;
            } catch (\Exception $e) {
                // Silently fail if database not available
                error_log("Header term selector error: " . $e->getMessage());
            }
        }
        ?>
        <li class="nav-item me-3 d-flex align-items-center" role="none">
            <label for="headerTermSelector" class="me-2 mb-0"><strong>Term filter:</strong></label>
            <span id="termFilterHelp" class="visually-hidden">Select a term, then choose Apply term filter.</span>
            <select id="headerTermSelector" class="form-select form-select-sm" style="min-width: 200px;" aria-describedby="termFilterHelp">
                <option value="">All Terms</option>
                <?php if (empty($headerTerms)): ?>
                    <option disabled>No terms available</option>
                <?php else: ?>
                    <?php foreach ($headerTerms as $headerTerm): ?>
                        <option value="<?= $headerTerm['terms_pk'] ?>" <?= $headerTerm['terms_pk'] == $headerSelectedTerm ? 'selected' : '' ?>>
                            <?= htmlspecialchars($headerTerm['term_code'] . ' - ' . $headerTerm['term_name']) ?>
                        </option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
            <button type="button" class="btn btn-sm btn-primary ms-2" id="applyTermFilter" disabled>Apply term filter</button>
        </li>
        <li class="nav-item dropdown">
            <a class="nav-link" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="true" aria-expanded="false" aria-label="User menu">
                <i class="bi bi-person-circle" aria-hidden="true"></i>
                <span class="d-none d-md-inline ms-1">
                    <?php
                    // Get logged in user info from session
                    $loggedInUser = $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'Administrator';
                    echo htmlspecialchars($loggedInUser);
                    ?>
                </span>
            </a>
            <div class="dropdown-menu dropdown-menu-end" role="menu">
                <?php if ($themeCanAdminister): ?>
                    <a href="<?= BASE_URL ?>administration/users.php" class="dropdown-item" role="menuitem">
                        <i class="bi bi-people me-2" aria-hidden="true"></i> User management
                    </a>
                    <a href="<?= BASE_URL ?>administration/config.php" class="dropdown-item" role="menuitem">
                        <i class="bi bi-gear me-2" aria-hidden="true"></i> Configuration
                    </a>
                    <div class="dropdown-divider" role="separator"></div>
                <?php endif; ?>
                <form method="post" action="<?= BASE_URL ?>logout.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <button type="submit" class="dropdown-item" role="menuitem">
                        <i class="bi bi-box-arrow-right me-2" aria-hidden="true"></i> Logout
                    </button>
                </form>
            </div>
        </li>
    </ul>
</nav>

<!-- Sidebar -->
<aside id="sidebar-nav" class="app-sidebar bg-body-secondary shadow" data-bs-theme="dark" role="navigation" aria-label="Sidebar navigation">
    <div class="sidebar-brand">
        <a href="<?= BASE_URL ?>" class="brand-link">
            <i class="bi bi-graph-up ms-3" aria-hidden="true"></i>
            <span class="brand-text fw-light"><?= htmlspecialchars(SITE_NAME ?? 'SLOCloud') ?></span>
        </a>
    </div>
    <div class="sidebar-wrapper">
        <nav class="mt-2" aria-label="Administration menu">
            <ul class="nav sidebar-menu flex-column" data-lte-toggle="treeview" role="menu" data-accordion="false">
                <li class="nav-item" role="none">
                    <a href="<?= BASE_URL ?>administration/" class="nav-link <?= ($currentPage ?? '') === 'admin_dashboard' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_dashboard' ? 'aria-current="page"' : '' ?>>
                        <i class="nav-icon bi bi-speedometer2" aria-hidden="true"></i>
                        <p>Dashboard</p>
                    </a>
                </li>
                
                <!-- TERMS Section -->
                <li class="nav-item" role="none">
                    <button type="button" class="nav-link" aria-expanded="false" aria-controls="sidebar-terms">
                        <i class="nav-icon bi bi-calendar-week" aria-hidden="true"></i>
                        <p>
                            Terms
                            <i class="nav-arrow bi bi-chevron-right" aria-hidden="true"></i>
                        </p>
                    </button>
                    <ul id="sidebar-terms" class="nav nav-treeview" role="menu">
                        <li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>administration/terms.php" class="nav-link <?= ($currentPage ?? '') === 'admin_terms' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_terms' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-circle" aria-hidden="true"></i>
                                <p>Terms</p>
                            </a>
                        </li>
                    </ul>
                </li>
                
                <!-- OUTCOMES Section -->
                <li class="nav-item" role="none">
                    <button type="button" class="nav-link" aria-expanded="false" aria-controls="sidebar-outcomes">
                        <i class="nav-icon bi bi-flag" aria-hidden="true"></i>
                        <p>
                            Outcomes
                            <i class="nav-arrow bi bi-chevron-right" aria-hidden="true"></i>
                        </p>
                    </button>
                    <ul id="sidebar-outcomes" class="nav nav-treeview" role="menu">
                        <li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>administration/institutional_outcomes.php" class="nav-link <?= ($currentPage ?? '') === 'admin_institutional_outcomes' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_institutional_outcomes' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-circle" aria-hidden="true"></i>
                                <p>ISLO</p>
                            </a>
                        </li>
                    </ul>
                </li>
                
                <!-- PROGRAMS Section -->
                <li class="nav-item" role="none">
                    <button type="button" class="nav-link" aria-expanded="false" aria-controls="sidebar-programs">
                        <i class="nav-icon bi bi-mortarboard" aria-hidden="true"></i>
                        <p>
                            Programs
                            <i class="nav-arrow bi bi-chevron-right" aria-hidden="true"></i>
                        </p>
                    </button>
                    <ul id="sidebar-programs" class="nav nav-treeview" role="menu">
                        <li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>administration/disciplines.php" class="nav-link <?= ($currentPage ?? '') === 'admin_disciplines' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_disciplines' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-circle" aria-hidden="true"></i>
                                <p>Disciplines</p>
                            </a>
                        </li>
                        <li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>administration/programs.php" class="nav-link <?= ($currentPage ?? '') === 'admin_programs' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_programs' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-circle" aria-hidden="true"></i>
                                <p>Programs</p>
                            </a>
                        </li>
                        <li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>administration/program_outcomes.php" class="nav-link <?= ($currentPage ?? '') === 'admin_program_outcomes' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_program_outcomes' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-circle" aria-hidden="true"></i>
                                <p>PSLO</p>
                            </a>
                        </li>
                    </ul>
                </li>
                
                <!-- COURSES Section -->
                <li class="nav-item" role="none">
                    <button type="button" class="nav-link" aria-expanded="false" aria-controls="sidebar-courses">
                        <i class="nav-icon bi bi-book" aria-hidden="true"></i>
                        <p>
                            Courses
                            <i class="nav-arrow bi bi-chevron-right" aria-hidden="true"></i>
                        </p>
                    </button>
                    <ul id="sidebar-courses" class="nav nav-treeview" role="menu">
                        <li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>administration/courses.php" class="nav-link <?= ($currentPage ?? '') === 'admin_courses' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_courses' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-circle" aria-hidden="true"></i>
                                <p>Courses</p>
                            </a>
                        </li>
                        <li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>administration/sections.php" class="nav-link <?= ($currentPage ?? '') === 'admin_sections' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_sections' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-circle" aria-hidden="true"></i>
                                <p>Sections</p>
                            </a>
                        </li>
                        <li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>administration/xlist.php" class="nav-link <?= ($currentPage ?? '') === 'admin_xlist' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_xlist' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-circle" aria-hidden="true"></i>
                                <p>Cross-Listings</p>
                            </a>
                        </li>
                        <li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>administration/student_learning_outcomes.php" class="nav-link <?= ($currentPage  ?? '') === 'admin_slos' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_slos' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-circle" aria-hidden="true"></i>
                                <p>CSLO</p>
                            </a>
                        </li>
                    </ul>
                </li>
                
                <!-- STUDENTS Section -->
                <li class="nav-item" role="none">
                    <button type="button" class="nav-link" aria-expanded="false" aria-controls="sidebar-students">
                        <i class="nav-icon bi bi-person" aria-hidden="true"></i>
                        <p>
                            Students
                            <i class="nav-arrow bi bi-chevron-right" aria-hidden="true"></i>
                        </p>
                    </button>
                    <ul id="sidebar-students" class="nav nav-treeview" role="menu">
                        <li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>administration/students.php" class="nav-link <?= ($currentPage ?? '') === 'admin_students' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_students' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-circle" aria-hidden="true"></i>
                                <p>Students</p>
                            </a>
                        </li>
                        <li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>administration/enrollment.php" class="nav-link <?= ($currentPage ?? '') === 'admin_enrollment' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_enrollment' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-circle" aria-hidden="true"></i>
                                <p>Enrollment</p>
                            </a>
                        </li>
                        <li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>administration/assessments.php" class="nav-link <?= ($currentPage ?? '') === 'admin_assessments' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_assessments' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-circle" aria-hidden="true"></i>
                                <p>Assessments</p>
                            </a>
                        </li>
                    </ul>
                </li>
                
                <?php if ($themeCanAdminister): ?>
                <!-- DATA Section -->
                <li class="nav-item" role="none">
                    <button type="button" class="nav-link" aria-expanded="false" aria-controls="sidebar-data">
                        <i class="nav-icon bi bi-database" aria-hidden="true"></i>
                        <p>
                            Data
                            <i class="nav-arrow bi bi-chevron-right" aria-hidden="true"></i>
                        </p>
                    </button>
                    <ul id="sidebar-data" class="nav nav-treeview" role="menu">
                        <li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>administration/term_imports_normalized.php" class="nav-link <?= ($currentPage ?? '') === 'admin_term_imports_normalized' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_term_imports_normalized' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-circle" aria-hidden="true"></i>
                                <p>Term Imports (Normalized)</p>
                            </a>
                        </li>
                    </ul>
                </li>
                <?php endif; ?>
                
                <!-- REPORTS Section -->
                <li class="nav-item" role="none">
                    <button type="button" class="nav-link" aria-expanded="false" aria-controls="sidebar-reports">
                        <i class="nav-icon bi bi-bar-chart" aria-hidden="true"></i>
                        <p>
                            Reports
                            <i class="nav-arrow bi bi-chevron-right" aria-hidden="true"></i>
                        </p>
                    </button>
                    <ul id="sidebar-reports" class="nav nav-treeview" role="menu">
                        <li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>administration/dynamic_reports.php" class="nav-link <?= ($currentPage ?? '') === 'admin_dynamic_reports' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_dynamic_reports' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-circle" aria-hidden="true"></i>
                                <p>Custom Report</p>
                            </a>
                        </li>
                        <?php if ($themeCanAdminister): ?><li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>administration/activity_log.php" class="nav-link <?= ($currentPage ?? '') === 'admin_activity_log' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_activity_log' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-circle" aria-hidden="true"></i>
                                <p>Activity Log</p>
                            </a>
                        </li><?php endif; ?>
                    </ul>
                </li>
                
                <!-- SYSTEM Section -->
                <li class="nav-item" role="none">
                    <button type="button" class="nav-link" aria-expanded="false" aria-controls="sidebar-system">
                        <i class="nav-icon bi bi-gear" aria-hidden="true"></i>
                        <p>
                            System
                            <i class="nav-arrow bi bi-chevron-right" aria-hidden="true"></i>
                        </p>
                    </button>
                    <ul id="sidebar-system" class="nav nav-treeview" role="menu">
                        <?php if ($themeCanAdminister): ?><li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>administration/users.php" class="nav-link <?= ($currentPage ?? '') === 'admin_users' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_users' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-circle" aria-hidden="true"></i>
                                <p>Users</p>
                            </a>
                        </li>
                        <li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>administration/config.php" class="nav-link <?= ($currentPage ?? '') === 'admin_config' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_config' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-circle" aria-hidden="true"></i>
                                <p>Configuration</p>
                            </a>
                        </li>
                        <li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>lti/" class="nav-link <?= ($currentPage ?? '') === 'admin_lti' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_lti' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-circle" aria-hidden="true"></i>
                                <p>LTI Integration</p>
                            </a>
                        </li><?php endif; ?>
                        <li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>administration/vpat.php" class="nav-link <?= ($currentPage ?? '') === 'admin_vpat' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_vpat' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-circle" aria-hidden="true"></i>
                                <p>Accessibility (VPAT)</p>
                            </a>
                        </li>
                        <li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>administration/site_map.php" class="nav-link <?= ($currentPage ?? '') === 'admin_site_map' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_site_map' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-diagram-2" aria-hidden="true"></i>
                                <p>Site Map</p>
                            </a>
                        </li>
                        <?php if ($themeCanAdminister): ?><li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>administration/system_status.php" class="nav-link <?= ($currentPage ?? '') === 'admin_system_status' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_system_status' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-circle" aria-hidden="true"></i>
                                <p>System Status</p>
                            </a>
                        </li><?php endif; ?>
                        <li class="nav-item" role="none">
                            <a href="<?= BASE_URL ?>administration/patch_notes.php" class="nav-link <?= ($currentPage ?? '') === 'admin_patch_notes' ? 'active' : '' ?>" role="menuitem" <?= ($currentPage ?? '') === 'admin_patch_notes' ? 'aria-current="page"' : '' ?>>
                                <i class="nav-icon bi bi-circle" aria-hidden="true"></i>
                                <p>Patch Notes</p>
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
        </nav>
    </div>
</aside>

<!-- Content Wrapper -->
<main id="main" class="app-main" tabindex="-1">
    <?php if (isset($pageTitle)): ?>
    <div class="app-content-header">
        <div class="container-fluid">
            <h1 class="mb-0"><?= htmlspecialchars($pageTitle) ?></h1>
        </div>
    </div>
    <?php endif; ?>
    <div class="app-content">
        <div class="container-fluid">
