<?php
/**
 * WealthDash — 403 Forbidden
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');
$pageTitle = '403 — Access Denied';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= e($_SESSION['user_theme'] ?? 'light') ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e(APP_NAME) ?> — Access Denied</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/public/css/app.css">
</head>
<body class="auth-body">
<div class="auth-container">
  <div class="auth-card" style="text-align:center; padding: 3rem 2rem;">
    <div style="font-size: 4rem; margin-bottom: 1rem;">🔒</div>
    <h1 style="font-size: 1.75rem; margin-bottom: 0.5rem;">Access Denied</h1>
    <p style="color: var(--text-muted); margin-bottom: 2rem;">
      You don't have permission to view this page.
    </p>
    <a href="<?= APP_URL ?>/index.php" class="btn btn-primary">
      ← Back to Dashboard
    </a>
  </div>
</div>
</body>
</html>

