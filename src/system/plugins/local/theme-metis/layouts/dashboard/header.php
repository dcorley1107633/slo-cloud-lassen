<?php
/**
 * Metis Theme - Dashboard Layout Header
 * Full sidebar with material design
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
    
    <!-- Google Font: Roboto -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/roboto/5.3.0/roboto.css">
    <!-- Material Icons -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/material-icons/1.13.14/material-icons.css">
    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap/5.3.8/css/bootstrap.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>system/accessibility.css">
    <script src="<?= BASE_URL ?>system/accessibility.js" defer></script>
    
    <style>
        :root {
            --primary-dark: #0D47A1;
            --accent-blue: #1976D2;
            --brand-teal: #1565C0;
            --sidebar-width: 260px;
        }
        * {
            font-family: 'Roboto', sans-serif;
        }
        body {
            background: #f5f5f5;
            margin: 0;
            padding: 0;
        }
        .metis-wrapper {
            display: flex;
            min-height: 100vh;
        }
        .metis-sidebar {
            width: var(--sidebar-width);
            background: linear-gradient(180deg, var(--primary-dark), var(--brand-teal));
            color: white;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            z-index: 1000;
        }
        .metis-sidebar .brand {
            padding: 1.5rem;
            font-size: 1.5rem;
            font-weight: 500;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .metis-sidebar .user-panel {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .metis-sidebar .nav-menu {
            padding: 1rem 0;
        }
        .metis-sidebar .nav-menu a {
            color: rgba(255,255,255,0.8);
            display: block;
            padding: 0.75rem 1.5rem;
            text-decoration: none;
            transition: all 0.3s;
        }
        .metis-sidebar .nav-menu a:hover,
        .metis-sidebar .nav-menu a.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        .metis-sidebar .nav-menu i {
            margin-right: 0.5rem;
            width: 20px;
        }
        .metis-sidebar .nav-header {
            padding: 1rem 1.5rem 0.5rem;
            font-size: 0.75rem;
            text-transform: uppercase;
            color: rgba(255,255,255,0.5);
            font-weight: 500;
        }
        .metis-main {
            margin-left: var(--sidebar-width);
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        .metis-topbar {
            background: white;
            padding: 1rem 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .metis-content {
            padding: 2rem;
            flex: 1;
        }
        .page-title {
            font-size: 2rem;
            font-weight: 300;
            margin-bottom: 1.5rem;
            color: #333;
        }
        .card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
    </style>
    
    <?php if (isset($customCss)): ?>
    <?= $customCss ?>
    <?php endif; ?>
    <?php require dirname(__DIR__, 5) . '/includes/color_scheme_style.php'; ?>
</head>
<body>
<a href="#main" class="skip-link">Skip to main content</a>
<div class="metis-wrapper">
    <!-- Sidebar -->
    <aside class="metis-sidebar">
        <div class="brand">
            <i class="bi bi-graph-up"></i> SLOCloud
        </div>
        <div class="user-panel">
            <i class="bi bi-person-circle"></i>
            <?= htmlspecialchars($_SESSION['user_name'] ?? 'Administrator') ?>
        </div>
        <nav class="nav-menu" aria-label="Administration navigation">
            <a href="<?= BASE_URL ?>administration/" class="<?= ($currentPage ?? '') === 'admin_dashboard' ? 'active' : '' ?>">
                <i class="bi bi-speedometer2"></i> Dashboard
            </a>
            <div class="nav-header">Terms</div>
            <a href="<?= BASE_URL ?>administration/terms.php" class="<?= ($currentPage ?? '') === 'admin_terms' ? 'active' : '' ?>">
                <i class="bi bi-calendar-week"></i> Terms
            </a>
            <div class="nav-header">Administration</div>
            <a href="<?= BASE_URL ?>administration/institutional_outcomes.php" class="<?= ($currentPage ?? '') === 'admin_institutional_outcomes' ? 'active' : '' ?>">
                <i class="bi bi-diagram-3"></i> Outcome Hierarchy
            </a>
            <div class="nav-header">Reports</div>
            <a href="<?= BASE_URL ?>administration/dynamic_reports.php" class="<?= ($currentPage ?? '') === 'admin_dynamic_reports' ? 'active' : '' ?>">
                <i class="bi bi-table"></i> Custom Report
            </a>
            <?php if ($themeCanAdminister): ?><a href="<?= BASE_URL ?>administration/activity_log.php" class="<?= ($currentPage ?? '') === 'admin_activity_log' ? 'active' : '' ?>">
                <i class="bi bi-clipboard-data" aria-hidden="true"></i> Activity Log
            </a><?php endif; ?>
            <div class="nav-header">System</div>
            <?php if ($themeCanAdminister): ?><a href="<?= BASE_URL ?>administration/config.php" class="<?= ($currentPage ?? '') === 'admin_config' ? 'active' : '' ?>">
                <i class="bi bi-gear"></i> Configuration
            </a>
            <a href="<?= BASE_URL ?>administration/color_scheme.php" class="<?= ($currentPage ?? '') === 'admin_color_scheme' ? 'active' : '' ?>" <?= ($currentPage ?? '') === 'admin_color_scheme' ? 'aria-current="page"' : '' ?>>
                <i class="bi bi-palette" aria-hidden="true"></i> Color Scheme
            </a>
            <a href="<?= BASE_URL ?>lti/" class="<?= ($currentPage ?? '') === 'admin_lti' ? 'active' : '' ?>">
                <i class="bi bi-plug"></i> LTI Integration
            </a>
            <a href="<?= BASE_URL ?>administration/canvas_deploy.php" class="<?= ($currentPage ?? '') === 'admin_canvas_deploy' ? 'active' : '' ?>" <?= ($currentPage ?? '') === 'admin_canvas_deploy' ? 'aria-current="page"' : '' ?>>
                <i class="bi bi-cloud-upload" aria-hidden="true"></i> Deploy to Canvas
            </a><?php endif; ?>
            <a href="<?= BASE_URL ?>administration/site_map.php" class="<?= ($currentPage ?? '') === 'admin_site_map' ? 'active' : '' ?>" <?= ($currentPage ?? '') === 'admin_site_map' ? 'aria-current="page"' : '' ?>>
                <i class="bi bi-diagram-2" aria-hidden="true"></i> Site Map
            </a>
        </nav>
    </aside>
    
    <!-- Main Content -->
    <main id="main" class="metis-main" tabindex="-1">
        <div class="metis-topbar">
            <h1 class="h5 mb-0"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></h1>
            <div>
                <span class="text-muted"><?= htmlspecialchars(SITE_NAME ?? 'SLOCloud') ?></span>
            </div>
        </div>
        <div class="metis-content">
