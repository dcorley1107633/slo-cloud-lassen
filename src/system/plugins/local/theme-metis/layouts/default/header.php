<?php
/**
 * Metis Theme - Default Layout Header
 * Clean layout without sidebar
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
    
    <!-- Google Font: Roboto -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/roboto/5.3.0/roboto.css">
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
        }
        * {
            font-family: 'Roboto', sans-serif;
        }
        body {
            background: #f5f5f5;
        }
        .metis-topbar {
            background: linear-gradient(135deg, var(--primary-dark), var(--brand-teal));
            color: white;
            padding: 1.5rem 0;
            margin-bottom: 2rem;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .metis-topbar h1 {
            font-weight: 300;
            margin: 0;
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

<div class="metis-topbar">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center">
            <h1 class="h3 mb-0">
                <i class="bi bi-graph-up"></i> <?= htmlspecialchars($pageTitle ?? 'SLOCloud') ?>
            </h1>
            <?php if (isset($_SESSION['user_id'])): ?>
            <nav aria-label="Primary navigation" class="d-flex align-items-center gap-3">
                <a href="<?= BASE_URL ?>administration/">Dashboard</a>
                <a href="<?= BASE_URL ?>administration/site_map.php">Site Map</a>
                <span><i class="bi bi-person-circle" aria-hidden="true"></i> <?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?></span>
            </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<main id="main" class="container" tabindex="-1">
