<?php
/**
 * Default Theme - Default Layout Header
 * Minimal layout with container
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
        body {
            padding-top: 2rem;
            padding-bottom: 2rem;
        }
        .page-header {
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--primary-dark);
        }
    </style>
    
    <?php if (isset($customCss)): ?>
    <?= $customCss ?>
    <?php endif; ?>
    <?php require dirname(__DIR__, 5) . '/includes/color_scheme_style.php'; ?>
</head>
<body>
<a href="#main" class="skip-link">Skip to main content</a>
<nav class="container py-2 border-bottom" aria-label="Primary navigation">
    <a class="me-3" href="<?= BASE_URL ?>">Home</a>
    <?php if (isset($_SESSION['user_id'])): ?>
        <a class="me-3" href="<?= BASE_URL ?>administration/">Dashboard</a>
        <a href="<?= BASE_URL ?>administration/site_map.php">Site Map</a>
    <?php endif; ?>
</nav>
<main id="main" class="container" tabindex="-1">
    <?php if (isset($pageTitle)): ?>
    <header class="page-header">
        <h1 class="h4"><?= htmlspecialchars($pageTitle) ?></h1>
    </header>
    <?php endif; ?>
