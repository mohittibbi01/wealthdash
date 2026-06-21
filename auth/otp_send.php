<?php
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

if (is_logged_in()) redirect('index.php');

$action  = $_GET['action'] ?? 'login';
$errors  = [];
$step    = 'send'; // 'send' or 'verify'

// Step 2: verify OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['otp'])) {
    csrf_verify();
    $otp    = clean($_POST['otp'] ?? '');
    $userId = (int) ($_SESSION['_otp_user_id'] ?? 0);
    $mobile = $_SESSION['_otp_mobile'] ?? '';
    $purpose = $_SESSION['_otp_purpose'] ?? 'login';

    if (!$userId || !$mobile) {
        flash_set('error', 'Session expired. Please start again.');
        redirect('auth/otp_send.php?action=' . $action);
    }

    if (Notification::verify_otp($userId, $mobile, $otp, $purpose)) {
        // OTP verified — log in
        $user = DB::fetchOne('SELECT * FROM users WHERE id = ? AND status = ?', [$userId, 'active']);
        if (!$user) {
            $errors[] = 'Account not found or deactivated.';
        } else {
            unset($_SESSION['_otp_user_id'], $_SESSION['_otp_mobile'], $_SESSION['_otp_purpose']);

            session_regenerate_id(true);
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['user_name']  = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role']  = $user['role'];
            $_SESSION['user_theme'] = $user['theme'];
            $_SESSION['_last_activity'] = time();

            DB::run('UPDATE users SET last_login_at = NOW(), mobile_verified = 1, login_count = login_count + 1 WHERE id = ?', [$user['id']]);
            audit_log('otp_login', 'user', (int)$user['id']);
            redirect('index.php');
        }
    } else {
        $errors[] = 'Invalid or expired OTP. Please try again.';
        $step = 'verify';
    }
}

// Step 1: send OTP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mobile']) && !isset($_POST['otp'])) {
    csrf_verify();
    $mobile = clean($_POST['mobile'] ?? '');
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (!valid_mobile($mobile)) {
        $errors[] = 'Enter a valid 10-digit Indian mobile number.';
    } else {
        $user = DB::fetchOne('SELECT * FROM users WHERE mobile = ? AND status = ?', [$mobile, 'active']);

        if (!$user) {
            $errors[] = 'No account found with this mobile number. Please register first.';
        } elseif (!check_rate_limit($ip, $mobile)) {
            $errors[] = 'Too many attempts. Please wait 15 minutes.';
        } else {
            $otp = Notification::generate_and_store_otp((int)$user['id'], $mobile, 'login');

            $sent = Notification::send_sms_otp($mobile, $otp);
            if (!$sent) {
                // Fallback: send via email
                Notification::send_otp_email($user['email'], $user['name'], $otp, 'login');
            }

            $_SESSION['_otp_user_id'] = $user['id'];
            $_SESSION['_otp_mobile']  = $mobile;
            $_SESSION['_otp_purpose'] = 'login';
            $step = 'verify';
        }
    }
}

if (isset($_SESSION['_otp_user_id'])) $step = 'verify';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e(APP_NAME) ?> — OTP Login</title>
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

    <?php if ($step === 'send'): ?>
      <h1 class="auth-title">Sign in with OTP</h1>
      <p class="auth-subtitle">We'll send a 6-digit code to your mobile</p>

      <form method="POST" class="auth-form" novalidate>
        <?= csrf_field() ?>
        <div class="form-group">
          <label for="mobile" class="form-label">Mobile Number</label>
          <div class="input-group">
            <span class="input-prefix">+91</span>
            <input type="tel" id="mobile" name="mobile" class="form-input"
              value="<?= e($_POST['mobile'] ?? '') ?>"
              placeholder="9876543210" maxlength="10" pattern="[6-9][0-9]{9}" required autofocus>
          </div>
        </div>
        <button type="submit" class="btn btn-primary btn-full">Send OTP</button>
      </form>

    <?php else: ?>
      <h1 class="auth-title">Enter OTP</h1>
      <p class="auth-subtitle">6-digit code sent to +91 <?= e($_SESSION['_otp_mobile'] ?? '') ?></p>

      <form method="POST" class="auth-form" novalidate>
        <?= csrf_field() ?>
        <div class="form-group">
          <label for="otp" class="form-label">Enter OTP</label>
          <input type="text" id="otp" name="otp" class="form-input otp-input"
            placeholder="• • • • • •" maxlength="6" pattern="[0-9]{6}"
            inputmode="numeric" autocomplete="one-time-code" autofocus required>
        </div>
        <button type="submit" class="btn btn-primary btn-full">Verify OTP</button>
      </form>

      <div class="auth-otp-link" style="margin-top:12px">
        <form method="POST" action="?action=<?= e($action) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="mobile" value="<?= e($_SESSION['_otp_mobile'] ?? '') ?>">
          <button type="submit" class="btn-link">Resend OTP</button>
        </form>
      </div>
    <?php endif; ?>

    <p class="auth-footer">
      <a href="<?= APP_URL ?>/auth/login.php">← Back to Login</a>
    </p>

  </div>
</div>
</body>
</html>

