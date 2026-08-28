<?php
declare(strict_types=1);

/** Browser configuration for the accessible inactivity warning. */
if (!empty($_SESSION['user_id']) || (($_SESSION['is_emergency_admin'] ?? false) === true)):
    $sessionTimeoutSeconds = 7200;
    $sessionWarningSeconds = 120;
?>
<meta name="slo-session-timeout" content="<?= $sessionTimeoutSeconds ?>">
<meta name="slo-session-warning" content="<?= $sessionWarningSeconds ?>">
<meta name="slo-session-extend-url" content="<?= htmlspecialchars(BASE_URL . 'session.php', ENT_QUOTES, 'UTF-8') ?>">
<meta name="slo-session-logout-url" content="<?= htmlspecialchars(BASE_URL . 'logout.php', ENT_QUOTES, 'UTF-8') ?>">
<meta name="slo-csrf-token" content="<?= htmlspecialchars((string)($_SESSION['csrf_token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
