<?php
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

if (is_logged_in()) redirect('index.php');

$action  = $_GET['action'] ?? 'request';
$token   = $_GET['token'] ?? '';
$errors  = [];
$success = '';

// ---- STEP 1: Request reset ----
if ($action === 'request' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $email = strtolower(clean($_POST['email'] ?? ''));

    if (!valid_email($email)) {
        $errors[] = 'Please enter a valid email address.';
    } else {
        $user = DB::fetchOne('SELECT * FROM users WHERE email = ? AND status = ?', [$email, 'active']);

        if ($user) {
            // Invalidate old tokens
            DB::run('UPDATE password_resets SET used = 1 WHERE email = ?', [$email]);

            $resetToken = bin2hex(random_bytes(32));
            $tokenHash  = password_hash($resetToken, PASSWORD_BCRYPT);
            $expires    = date('Y-m-d H:i:s', strtotime('+1 hour'));

            DB::run(
                'INSERT INTO password_resets (email, token_hash, expires_at) VALUES (?, ?, ?)',
                [$email, $tokenHash, $expires]
            );

            Notification::send_password_reset($email, $user['name'], $resetToken);
        }

        // Always show success (don't reveal if email exists)
        $success = 'If an account with that email exists, you will receive a password reset link shortly.';
    }
}

// ---- STEP 2: Reset password ----
if ($action === 'reset' && $token) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $password = $_POST['password'] ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';

        if (strlen($password) < 8)  $errors[] = 'Password must be at least 8 characters.';
        if (!preg_match('/[A-Z]/', $password)) $errors[] = 'Password must have at least one uppercase letter.';
        if (!preg_match('/[0-9]/', $password)) $errors[] = 'Password must have at least one number.';
        if ($password !== $confirm) $errors[] = 'Passwords do not match.';

        if (empty($errors)) {
            // Find valid token
            $reset = DB::fetchOne(
                'SELECT * FROM password_resets WHERE used = 0 AND expires_at > NOW() ORDER BY id DESC LIMIT 1'
            );

            if (!$reset || !password_verify($token, $reset['token_hash'])) {
                $errors[] = 'This reset link is invalid or has expired. Please request a new one.';
            } else {
                DB::run('UPDATE users SET password_hash = ? WHERE email = ?', [
                    password_hash($password, PASSWORD_BCRYPT, ['cost' => (int) env('BCRYPT_COST', 12)]),
                    $reset['email'],
                ]);
                DB::run('UPDATE password_resets SET used = 1 WHERE id = ?', [$reset['id']]);

                audit_log('password_reset', 'user', 0, [], ['email' => $reset['email']]);
                flash_set('success', 'Password reset successful. Please sign in with your new password.');
                redirect('auth/login.php');
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e(APP_NAME) ?> — Forgot Password</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/public/css/app.css">
</head>
<body class="auth-body">
<div class="auth-container">
  <div class="auth-card">

    <div class="auth-logo">
      <svg width="40" height="40" viewBox="0 0 40 40" fill="none">
        <rect width="40" height="40" rx="10" fill="#2563EB"/>
        <path d="M10 28L18 16L24 22L30 12" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        <circle cx="30" cy="12" r="2" fill="#34D399"/>
      </svg>
      <span class="auth-logo-text"><?= e(APP_NAME) ?></span>
    </div>

    <?php if ($errors): ?>
      <div class="alert alert-error">
        <?php foreach ($errors as $e_): ?><div><?= e($e_) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($action === 'request' && !$success): ?>
      <h1 class="auth-title">Forgot Password</h1>
      <p class="auth-subtitle">Enter your email and we'll send a reset link</p>

      <form method="POST" class="auth-form">
        <?= csrf_field() ?>
        <div class="form-group">
          <label for="email" class="form-label">Email address</label>
          <input type="email" id="email" name="email" class="form-input"
            value="<?= e($_POST['email'] ?? '') ?>" placeholder="you@example.com" required autofocus>
        </div>
        <button type="submit" class="btn btn-primary btn-full">Send Reset Link</button>
      </form>

    <?php elseif ($action === 'reset' && $token && !$success): ?>
      <h1 class="auth-title">Reset Password</h1>
      <p class="auth-subtitle">Enter your new password below</p>

      <form method="POST" class="auth-form">
        <?= csrf_field() ?>
        <div class="form-group">
          <label for="password" class="form-label">New Password</label>
          <input type="password" id="password" name="password" class="form-input"
            placeholder="Min 8 chars, 1 uppercase, 1 number" required autofocus>
        </div>
        <div class="form-group">
          <label for="password_confirm" class="form-label">Confirm New Password</label>
          <input type="password" id="password_confirm" name="password_confirm" class="form-input"
            placeholder="Repeat new password" required>
        </div>
        <button type="submit" class="btn btn-primary btn-full">Reset Password</button>
      </form>
    <?php endif; ?>

    <p class="auth-footer">
      <a href="<?= APP_URL ?>/auth/login.php">← Back to Login</a>
    </p>

  </div>
</div>
</body>
</html>

