<?php
/**
 * AdminLTE 4 Theme - Default Layout Header
 * Basic layout with top navbar, no sidebar
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

<div class="skip-links"><a href="#main" class="skip-link">Skip to main content</a></div>

<!-- Navbar -->
<nav class="navbar navbar-expand navbar-white navbar-light border-bottom" aria-label="Primary navigation">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?= BASE_URL ?>">
            <i class="bi bi-graph-up"></i> <strong>SLOCloud</strong>
        </a>
        <ul class="navbar-nav ms-auto">
            <?php if (isset($_SESSION['user_id'])): ?>
            <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>administration/">Dashboard</a></li>
            <li class="nav-item"><a class="nav-link" href="<?= BASE_URL ?>administration/site_map.php">Site Map</a></li>
            <li class="nav-item">
                <span class="nav-link">
                    <i class="bi bi-person-circle"></i> <?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?>
                </span>
            </li>
            <?php endif; ?>
        </ul>
    </div>
</nav>

<main id="main" class="container" tabindex="-1">
    <?php if (isset($pageTitle)): ?>
    <div class="mt-4 mb-4">
        <h1><?= htmlspecialchars($pageTitle) ?></h1>
    </div>
    <?php endif; ?>
