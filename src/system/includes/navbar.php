<?php
/**
 * AdminLTE 4 Top Navbar
 * 
 * Usage:
 *   require_once __DIR__ . '/../system/includes/navbar.php';
 */
?>
<!-- Navbar -->
<nav class="main-header navbar navbar-expand navbar-white navbar-light">
    <!-- Left navbar links -->
    <ul class="navbar-nav">
        <li class="nav-item">
            <a class="nav-link" data-lte-toggle="sidebar" href="#" role="button"><i class="fas fa-bars"></i></a>
        </li>
        <li class="nav-item d-none d-sm-inline-block">
            <a href="<?= BASE_URL ?>" class="nav-link"><i class="fas fa-home"></i> Home</a>
        </li>
    </ul>
    
    <!-- Right navbar links -->
    <ul class="navbar-nav ml-auto">
        <li class="nav-item">
            <span class="nav-link">
                <strong><?= htmlspecialchars(SITE_NAME) ?></strong>
            </span>
        </li>
    </ul>
</nav>
<!-- /.navbar -->
