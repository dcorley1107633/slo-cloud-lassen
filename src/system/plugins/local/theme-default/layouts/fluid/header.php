<?php
/**
 * Default Theme - Fluid Layout Header
 * Full-width fluid container layout
 */
// Explicit UTF-8 output encoding
header('Content-Type: text/html; charset=UTF-8');?>
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
            padding-top: 1rem;
        }
        .page-header {
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: linear-gradient(135deg, var(--primary-dark), var(--brand-teal));
            color: white;
        }
    </style>
    
    <?php if (isset($customCss)): ?>
    <?= $customCss ?>
    <?php endif; ?>
</head>
<body>
<a href="#main" class="skip-link">Skip to main content</a>
<main id="main" class="container-fluid" tabindex="-1">
    <?php if (isset($pageTitle)): ?>
    <header class="page-header rounded">
        <h1><?= htmlspecialchars($pageTitle) ?></h1>
    </header>
    <?php endif; ?>
