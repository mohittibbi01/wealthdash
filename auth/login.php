<?php
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once APP_ROOT . '/config/oauth.php';
require_once APP_ROOT . '/includes/auth_check.php';

// Already logged in?
if (is_logged_in()) redirect('index.php');

$errors  = [];
$success = '';

// Handle POST login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $email    = clean($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // reCAPTCHA
    if (RECAPTCHA_ENABLED) {
        $captchaToken = $_POST['g-recaptcha-response'] ?? '';
        if (!verify_recaptcha($captchaToken)) {
            $errors[] = 'reCAPTCHA verification failed. Please try again.';
        }
    }

    if (empty($errors)) {
        // Rate limiting
        if (!check_rate_limit($ip, $email)) {
            $lockMin = env('LOGIN_LOCKOUT_MINUTES', 15);
            $errors[] = "Too many failed attempts. Please wait {$lockMin} minutes.";
        } else {
            // Validate input
            if (!valid_email($email)) $errors[] = 'Please enter a valid email address.';
            if (empty($password))     $errors[] = 'Password is required.';

            if (empty($errors)) {
                $user = DB::fetchOne('SELECT * FROM users WHERE email = ?', [$email]);

                if (!$user || !$user['password_hash'] || !password_verify($password, $user['password_hash'])) {
                    log_login_attempt($ip, $email, false);
                    $errors[] = 'Invalid email or password.';
                } elseif ($user['status'] !== 'active') {
                    $errors[] = 'Your account has been ' . $user['status'] . '. Contact admin.';
                } else {
                    // SUCCESS
                    log_login_attempt($ip, $email, true);

                    // Regenerate session
                    session_regenerate_id(true);

                    $_SESSION['user_id']    = $user['id'];
                    $_SESSION['user_name']  = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role']  = $user['role'];
                    $_SESSION['user_theme'] = $user['theme'];
                    $_SESSION['_last_activity'] = time();

                    // Update last login
                    DB::run(
                        'UPDATE users SET last_login_at = NOW(), login_count = login_count + 1 WHERE id = ?',
                        [$user['id']]
                    );

                    audit_log('login', 'user', (int)$user['id']);

                    $redirect = $_SESSION['_redirect_after_login'] ?? 'index.php';
                    unset($_SESSION['_redirect_after_login']);
                    redirect($redirect);
                }
            }
        }
    }
}

$pageTitle    = 'Login';
$googleUrl    = google_auth_url();
$flashMsgs    = flash_get();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e(APP_NAME) ?> — Login</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/public/css/app.css">
  <?php if (RECAPTCHA_ENABLED && RECAPTCHA_SITE_KEY): ?>
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <?php endif; ?>
</head>
<body class="auth-body">

<div class="auth-container">
  <div class="auth-card">

    <!-- Logo -->
    <div class="auth-logo">
      <svg width="40" height="40" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
        <rect width="40" height="40" rx="10" fill="#2563EB"/>
        <path d="M10 28L18 16L24 22L30 12" stroke="white" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
        <circle cx="30" cy="12" r="2" fill="#34D399"/>
      </svg>
      <span class="auth-logo-text"><?= e(APP_NAME) ?></span>
    </div>

    <h1 class="auth-title">Welcome back</h1>
    <p class="auth-subtitle">Sign in to your account</p>

    <!-- Flash messages -->
    <?php foreach ($flashMsgs as $type => $msgs): ?>
      <?php foreach ($msgs as $msg): ?>
        <div class="alert alert-<?= e($type) ?>"><?= e($msg) ?></div>
      <?php endforeach; ?>
    <?php endforeach; ?>

    <!-- Errors -->
    <?php if ($errors): ?>
      <div class="alert alert-error">
        <?php foreach ($errors as $err): ?>
          <div><?= e($err) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Login Form -->
    <form method="POST" action="" class="auth-form" novalidate>
      <?= csrf_field() ?>

      <div class="form-group">
        <label for="email" class="form-label">Email address</label>
        <input
          type="email"
          id="email"
          name="email"
          class="form-input"
          value="<?= e($_POST['email'] ?? '') ?>"
          placeholder="you@example.com"
          required
          autocomplete="email"
          autofocus
        >
      </div>

      <div class="form-group">
        <label for="password" class="form-label">
          Password
          <a href="<?= APP_URL ?>/auth/forgot_password.php" class="form-label-link">Forgot password?</a>
        </label>
        <div class="input-with-icon">
          <input
            type="password"
            id="password"
            name="password"
            class="form-input"
            placeholder="Enter your password"
            required
            autocomplete="current-password"
          >
          <button type="button" class="toggle-password" aria-label="Show password" onclick="togglePassword('password')">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
      </div>

      <?php if (RECAPTCHA_ENABLED && RECAPTCHA_SITE_KEY): ?>
      <div class="form-group">
        <div class="g-recaptcha" data-sitekey="<?= e(RECAPTCHA_SITE_KEY) ?>"></div>
      </div>
      <?php endif; ?>

      <button type="submit" class="btn btn-primary btn-full">
        Sign in
      </button>
    </form>

    <!-- Divider -->
    <div class="auth-divider">
      <span>or continue with</span>
    </div>

    <!-- Google OAuth -->
    <?php if (GOOGLE_CLIENT_ID): ?>
    <a href="<?= e($googleUrl) ?>" class="btn btn-google btn-full">
      <svg width="18" height="18" viewBox="0 0 18 18" xmlns="http://www.w3.org/2000/svg">
        <path d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.716v2.259h2.908c1.702-1.567 2.684-3.875 2.684-6.615z" fill="#4285F4"/>
        <path d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332C2.438 15.983 5.482 18 9 18z" fill="#34A853"/>
        <path d="M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z" fill="#FBBC05"/>
        <path d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0 5.482 0 2.438 2.017.957 4.958L3.964 6.29C4.672 4.163 6.656 3.58 9 3.58z" fill="#EA4335"/>
      </svg>
      Sign in with Google
    </a>
    <?php endif; ?>

    <!-- Mobile OTP -->
    <div class="auth-otp-link">
      <a href="<?= APP_URL ?>/auth/otp_send.php?action=login">Sign in with Mobile OTP</a>
    </div>

    <!-- Register link -->
    <p class="auth-footer">
      Don't have an account?
      <a href="<?= APP_URL ?>/auth/register.php">Create account</a>
    </p>

  </div>
</div>

<script>
function togglePassword(fieldId) {
  const field = document.getElementById(fieldId);
  field.type = field.type === 'password' ? 'text' : 'password';
}
</script>
</body>
</html>

