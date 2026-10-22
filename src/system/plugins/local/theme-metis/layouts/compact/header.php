<?php
/**
 * Metis Theme - Compact Layout Header
 * Collapsed sidebar for more content space
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
            --sidebar-width: 70px;
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
            text-align: center;
            font-size: 1.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .metis-sidebar .nav-menu {
            padding: 1rem 0;
        }
        .metis-sidebar .nav-menu a {
            color: rgba(255,255,255,0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 1rem;
            text-decoration: none;
            transition: all 0.3s;
        }
        .metis-sidebar .nav-menu a:hover,
        .metis-sidebar .nav-menu a.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        .metis-sidebar .nav-menu i {
            font-size: 1.5rem;
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
    <!-- Compact Sidebar -->
    <aside class="metis-sidebar">
        <div class="brand">
            <i class="bi bi-graph-up" aria-hidden="true"></i>
        </div>
        <nav class="nav-menu" aria-label="Compact administration navigation">
            <a href="<?= BASE_URL ?>administration/" class="<?= ($currentPage ?? '') === 'admin_dashboard' ? 'active' : '' ?>" aria-label="Dashboard" <?= ($currentPage ?? '') === 'admin_dashboard' ? 'aria-current="page"' : '' ?>>
                <i class="bi bi-speedometer2" aria-hidden="true"></i>
            </a>
            <a href="<?= BASE_URL ?>administration/terms.php" class="<?= ($currentPage ?? '') === 'admin_terms' ? 'active' : '' ?>" aria-label="Terms" <?= ($currentPage ?? '') === 'admin_terms' ? 'aria-current="page"' : '' ?>>
                <i class="bi bi-calendar-week" aria-hidden="true"></i>
            </a>
            <a href="<?= BASE_URL ?>administration/institutional_outcomes.php" class="<?= ($currentPage ?? '') === 'admin_institutional_outcomes' ? 'active' : '' ?>" aria-label="Institutional outcomes" <?= ($currentPage ?? '') === 'admin_institutional_outcomes' ? 'aria-current="page"' : '' ?>>
                <i class="bi bi-diagram-3" aria-hidden="true"></i>
            </a>
            <a href="<?= BASE_URL ?>administration/dynamic_reports.php" class="<?= ($currentPage ?? '') === 'admin_dynamic_reports' ? 'active' : '' ?>" aria-label="Custom report" <?= ($currentPage ?? '') === 'admin_dynamic_reports' ? 'aria-current="page"' : '' ?>>
                <i class="bi bi-table" aria-hidden="true"></i>
            </a>
            <?php if ($themeCanAdminister): ?><a href="<?= BASE_URL ?>administration/config.php" class="<?= ($currentPage ?? '') === 'admin_config' ? 'active' : '' ?>" aria-label="Configuration" <?= ($currentPage ?? '') === 'admin_config' ? 'aria-current="page"' : '' ?>>
                <i class="bi bi-gear" aria-hidden="true"></i>
            </a>
            <a href="<?= BASE_URL ?>administration/color_scheme.php" class="<?= ($currentPage ?? '') === 'admin_color_scheme' ? 'active' : '' ?>" aria-label="Color scheme" <?= ($currentPage ?? '') === 'admin_color_scheme' ? 'aria-current="page"' : '' ?>>
                <i class="bi bi-palette" aria-hidden="true"></i>
            </a>
            <a href="<?= BASE_URL ?>lti/" class="<?= ($currentPage ?? '') === 'admin_lti' ? 'active' : '' ?>" aria-label="LTI integration" <?= ($currentPage ?? '') === 'admin_lti' ? 'aria-current="page"' : '' ?>>
                <i class="bi bi-plug" aria-hidden="true"></i>
            </a>
            <a href="<?= BASE_URL ?>administration/canvas_deploy.php" class="<?= ($currentPage ?? '') === 'admin_canvas_deploy' ? 'active' : '' ?>" aria-label="Deploy to Canvas" <?= ($currentPage ?? '') === 'admin_canvas_deploy' ? 'aria-current="page"' : '' ?>>
                <i class="bi bi-cloud-upload" aria-hidden="true"></i>
            </a><?php endif; ?>
            <a href="<?= BASE_URL ?>administration/site_map.php" class="<?= ($currentPage ?? '') === 'admin_site_map' ? 'active' : '' ?>" aria-label="Site map" <?= ($currentPage ?? '') === 'admin_site_map' ? 'aria-current="page"' : '' ?>>
                <i class="bi bi-diagram-2" aria-hidden="true"></i>
            </a>
        </nav>
    </aside>
    
    <!-- Main Content -->
    <main id="main" class="metis-main" tabindex="-1">
        <div class="metis-topbar">
            <h1 class="h5 mb-0"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></h1>
            <div>
                <span class="text-muted"><?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?></span>
            </div>
        </div>
        <div class="metis-content">
