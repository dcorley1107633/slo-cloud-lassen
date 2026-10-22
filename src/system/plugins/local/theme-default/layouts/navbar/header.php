<?php
/**
 * Default Theme - Navbar Layout Header
 * Layout with top navigation bar
 */

// Explicit UTF-8 output encoding
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require dirname(__DIR__, 5) . '/includes/session_timeout_meta.php'; ?>
    <title><?= htmlspecialchars($pageTitle ?? 'SLOCloud') ?></title>
    
    <!-- Bootstrap 5 -->
    <link href="<?= BASE_URL ?>assets/vendor/bootstrap/5.3.8/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>system/accessibility.css">
    <script src="<?= BASE_URL ?>system/accessibility.js" defer></script>
    
    <style>
        :root {
            --primary-dark: #0D47A1;
            --accent-blue: #1976D2;
            --brand-teal: #1565C0;
        }
        .navbar-brand {
            font-weight: 700;
            color: var(--primary-dark) !important;
        }
        main {
            padding-top: 2rem;
            padding-bottom: 2rem;
        }
    </style>
    
    <?php if (isset($customCss)): ?>
    <?= $customCss ?>
    <?php endif; ?>
    <?php require dirname(__DIR__, 5) . '/includes/color_scheme_style.php'; ?>
</head>
<body>
<a href="#main" class="skip-link">Skip to main content</a>
<?php
$themeDb = $GLOBALS['db'] ?? null;
$themeDbPrefix = (string)($GLOBALS['dbPrefix'] ?? '');
$themeCanAdminister = $themeDb instanceof \SLOCloud\Core\Database
    && \SLOCloud\Core\Authorization::isGlobalAdmin($themeDb, $themeDbPrefix);
?>
<!-- Navigation -->
<nav class="navbar navbar-expand-lg navbar-light bg-light border-bottom" aria-label="Primary navigation">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?= BASE_URL ?>">
            <i class="bi bi-graph-up"></i> SLOCloud
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>">
                        <i class="bi bi-house"></i> Home
                    </a>
                </li>
                <?php if (isset($_SESSION['user_id'])): ?>
                <li class="nav-item">
                    <a class="nav-link" href="<?= BASE_URL ?>administration/">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($currentPage ?? '') === 'admin_dynamic_reports' ? 'active' : '' ?>" href="<?= BASE_URL ?>administration/dynamic_reports.php">
                        <i class="bi bi-table"></i> Reports
                    </a>
                </li>
                <?php if ($themeCanAdminister): ?><li class="nav-item">
                    <a class="nav-link <?= ($currentPage ?? '') === 'admin_activity_log' ? 'active' : '' ?>" href="<?= BASE_URL ?>administration/activity_log.php">
                        <i class="bi bi-clipboard-data" aria-hidden="true"></i> Activity Log
                    </a>
                </li><?php endif; ?>
                <li class="nav-item">
                    <a class="nav-link <?= ($currentPage ?? '') === 'admin_site_map' ? 'active' : '' ?>" href="<?= BASE_URL ?>administration/site_map.php" <?= ($currentPage ?? '') === 'admin_site_map' ? 'aria-current="page"' : '' ?>>
                        <i class="bi bi-diagram-2" aria-hidden="true"></i> Site Map
                    </a>
                </li>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li>
                            <form method="post" action="<?= BASE_URL ?>logout.php">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="dropdown-item">Logout</button>
                            </form>
                        </li>
                    </ul>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>

<main id="main" class="container" tabindex="-1">
