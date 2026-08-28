
</main>

<!-- jQuery -->
<script src="<?= BASE_URL ?>assets/vendor/jquery/3.7.0/jquery.min.js"></script>
<!-- Bootstrap 5 -->
<script src="<?= BASE_URL ?>assets/vendor/bootstrap/5.3.8/js/bootstrap.bundle.min.js"></script>
<!-- AdminLTE 4 -->
<script src="<?= BASE_URL ?>assets/vendor/admin-lte/4.1.0/js/adminlte.min.js"></script>

<?php if (isset($customScripts)): ?>
<?= $customScripts ?>
<?php endif; ?>

</body>
</html>
