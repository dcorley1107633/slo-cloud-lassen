<?php
/**
 * AdminLTE 4 Theme - Embedded Layout Header
 * Minimal layout for LTI/iframe embedding - no navigation
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
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap/5.3.8/css/bootstrap.min.css">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/fontawesome-free/6.4.0/css/all.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css">
    <!-- AdminLTE 4 (minimal components) -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/admin-lte/4.1.0/css/adminlte.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>system/accessibility.css">
    <script src="<?= BASE_URL ?>system/accessibility.js" defer></script>
    
    <style>
        :root {
            --primary-dark: #0D47A1;
            --accent-blue: #1976D2;
            --brand-teal: #1565C0;
        }
        body {
            background: transparent;
            padding: 1rem;
        }
        
        /* Skip link for keyboard navigation (WCAG 2.4.1) */
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
    </style>
    
    <?php if (isset($customCss)): ?>
    <?= $customCss ?>
    <?php endif; ?>
</head>
<body>

<!-- Skip Navigation Link for Keyboard Users -->
<div class="skip-links"><a href="#main" class="skip-link">Skip to main content</a></div>

<div class="container-fluid">
