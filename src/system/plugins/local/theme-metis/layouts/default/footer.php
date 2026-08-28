
</main>

<footer class="container mt-5 mb-3">
    <div class="text-center text-muted">
        <?php
        global $config;
        $copyrightYearStart = $config->get('app.copyright_year_start', 2024);
        $copyrightOrg = $config->get('app.copyright_organization', 'SLOCloud');
        $copyrightTagline = $config->get('app.copyright_tagline', 'Student Learning Outcomes Assessment');
        $currentYear = date('Y');
        $yearRange = ($copyrightYearStart == $currentYear) ? $currentYear : "$copyrightYearStart-$currentYear";
        ?>
        <small>&copy; <?= htmlspecialchars($yearRange) ?> <?= htmlspecialchars($copyrightOrg) ?><?= $copyrightTagline ? ' - ' . htmlspecialchars($copyrightTagline) : '' ?></small>
    </div>
</footer>

<!-- Bootstrap 5 JS -->
<script src="<?= BASE_URL ?>assets/vendor/bootstrap/5.3.8/js/bootstrap.bundle.min.js"></script>

<?php if (isset($customScripts)): ?>
<?= $customScripts ?>
<?php endif; ?>

</body>
</html>
