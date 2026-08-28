
        </div>
    </div>
</main>

<footer class="app-footer" role="contentinfo">
    <div class="float-end d-none d-sm-inline">v<?= htmlspecialchars(APP_VERSION) ?></div>
    <?php
    global $config;
    $copyrightYearStart = $config->get('app.copyright_year_start', 2024);
    $copyrightOrg = $config->get('app.copyright_organization', 'SLOCloud');
    $copyrightTagline = $config->get('app.copyright_tagline', 'Student Learning Outcomes Assessment');
    $currentYear = date('Y');
    $yearRange = ($copyrightYearStart == $currentYear) ? $currentYear : "$copyrightYearStart-$currentYear";
    ?>
    <strong>&copy; <?= htmlspecialchars($yearRange) ?> <?= htmlspecialchars($copyrightOrg) ?></strong><?= $copyrightTagline ? ' - ' . htmlspecialchars($copyrightTagline) : '' ?>
</footer>

</div><!-- /.app-wrapper -->

<!-- jQuery -->
<script src="<?= BASE_URL ?>assets/vendor/jquery/3.7.0/jquery.min.js"></script>
<!-- Bootstrap 5 -->
<script src="<?= BASE_URL ?>assets/vendor/bootstrap/5.3.8/js/bootstrap.bundle.min.js"></script>
<!-- AdminLTE 4 -->
<script src="<?= BASE_URL ?>assets/vendor/admin-lte/4.1.0/js/adminlte.min.js"></script>

<!-- Global term selector handler -->
<script>
$(document).ready(function() {
    $('#headerTermSelector').on('change', function() {
        $('#applyTermFilter').prop('disabled', false);
    });
    $('#applyTermFilter').on('click', function() {
        var termFk = $('#headerTermSelector').val();
        var currentUrl = window.location.pathname;
        
        // Build new URL with term_fk parameter
        if (termFk) {
            window.location.href = currentUrl + '?term_fk=' + termFk;
        } else {
            window.location.href = currentUrl + '?term_fk=';
        }
    });
});
</script>

<?php if (isset($customScripts)): ?>
<?= $customScripts ?>
<?php endif; ?>
<script src="<?= BASE_URL ?>assets/js/lifecycle-admin.js"></script>

</body>
</html>
