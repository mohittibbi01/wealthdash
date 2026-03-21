<?php
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once APP_ROOT . '/config/oauth.php';
require_once APP_ROOT . '/includes/auth_check.php';

if (is_logged_in()) redirect('index.php');

// Check if registration is open
$regOpen = (bool) DB::fetchVal("SELECT setting_val FROM app_settings WHERE setting_key = 'registration_open'");
if (!$regOpen) {
    die('Registration is currently closed. Contact admin.');
}

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $name     = clean($_POST['name'] ?? '');
    $email    = strtolower(clean($_POST['email'] ?? ''));
    $mobile   = clean($_POST['mobile'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';
    $ip       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // reCAPTCHA
    if (RECAPTCHA_ENABLED) {
        if (!verify_recaptcha($_POST['g-recaptcha-response'] ?? '')) {
            $errors[] = 'reCAPTCHA verification failed.';
        }
    }

    // Validate
    if (strlen($name) < 2)        $errors[] = 'Name must be at least 2 characters.';
    if (strlen($name) > 100)      $errors[] = 'Name too long.';
    if (!valid_email($email))     $errors[] = 'Please enter a valid email address.';
    if ($mobile && !valid_mobile($mobile)) $errors[] = 'Enter a valid 10-digit Indian mobile number.';
    if (strlen($password) < 8)   $errors[] = 'Password must be at least 8 characters.';
    if ($password !== $confirm)   $errors[] = 'Passwords do not match.';

    // Password strength
    if (strlen($password) >= 8) {
        if (!preg_match('/[A-Z]/', $password)) $errors[] = 'Password must contain at least one uppercase letter.';
        if (!preg_match('/[0-9]/', $password)) $errors[] = 'Password must contain at least one number.';
    }

    if (empty($errors)) {
        // Check duplicate email
        $exists = DB::fetchVal('SELECT id FROM users WHERE email = ?', [$email]);
        if ($exists) {
            $errors[] = 'An account with this email already exists. <a href="login.php">Sign in</a> instead.';
        }
    }

    if (empty($errors)) {
        DB::beginTransaction();
        try {
            // First user becomes admin
            $userCount = (int) DB::fetchVal('SELECT COUNT(*) FROM users');
            $role      = $userCount === 0 ? 'admin' : 'member';

            $userId = DB::insert(
                'INSERT INTO users (name, email, password_hash, mobile, role, email_verified, mobile_verified)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $name,
                    $email,
                    password_hash($password, PASSWORD_BCRYPT, ['cost' => (int) env('BCRYPT_COST', 12)]),
                    $mobile ?: null,
                    $role,
                    0,
                    0,
                ]
            );

            // Create default portfolio
            DB::run(
                'INSERT INTO portfolios (user_id, name) VALUES (?, ?)',
                [(int)$userId, $name . "'s Portfolio"]
            );

            DB::commit();

            audit_log('register', 'user', (int)$userId);

            // Auto login
            session_regenerate_id(true);
            $_SESSION['user_id']    = (int)$userId;
            $_SESSION['user_name']  = $name;
            $_SESSION['user_email'] = $email;
            $_SESSION['user_role']  = $role;
            $_SESSION['user_theme'] = 'light';
            $_SESSION['_last_activity'] = time();

            DB::run('UPDATE users SET last_login_at = NOW(), login_count = 1 WHERE id = ?', [(int)$userId]);

            flash_set('success', 'Welcome to ' . APP_NAME . '! Your account has been created.');
            redirect('index.php');

        } catch (Exception $e) {
            DB::rollback();
            error_log('Register error: ' . $e->getMessage());
            $errors[] = 'Registration failed. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e(APP_NAME) ?> — Create Account</title>
  <link rel="stylesheet" href="<?= APP_URL ?>/public/css/app.css">
  <?php if (RECAPTCHA_ENABLED && RECAPTCHA_SITE_KEY): ?>
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  <?php endif; ?>
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

    <h1 class="auth-title">Create account</h1>
    <p class="auth-subtitle">Track your wealth — free forever</p>

    <?php if ($errors): ?>
      <div class="alert alert-error">
        <?php foreach ($errors as $err): ?>
          <div><?= $err /* may contain HTML link */ ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="" class="auth-form" novalidate>
      <?= csrf_field() ?>

      <div class="form-group">
        <label for="name" class="form-label">Full Name</label>
        <input type="text" id="name" name="name" class="form-input"
          value="<?= e($_POST['name'] ?? '') ?>" placeholder="Rajan Sharma" required autofocus>
      </div>

      <div class="form-group">
        <label for="email" class="form-label">Email address</label>
        <input type="email" id="email" name="email" class="form-input"
          value="<?= e($_POST['email'] ?? '') ?>" placeholder="you@example.com" required autocomplete="email">
      </div>

      <div class="form-group">
        <label for="mobile" class="form-label">Mobile (optional — for OTP login)</label>
        <div class="input-group">
          <span class="input-prefix">+91</span>
          <input type="tel" id="mobile" name="mobile" class="form-input"
            value="<?= e($_POST['mobile'] ?? '') ?>" placeholder="9876543210" maxlength="10" pattern="[6-9][0-9]{9}">
        </div>
      </div>

      <div class="form-group">
        <label for="password" class="form-label">Password</label>
        <div class="input-with-icon">
          <input type="password" id="password" name="password" class="form-input"
            placeholder="Min 8 chars, 1 uppercase, 1 number" required autocomplete="new-password">
          <button type="button" class="toggle-password" onclick="togglePassword('password')">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
              <circle cx="12" cy="12" r="3"/>
            </svg>
          </button>
        </div>
        <div id="pwd-strength" class="pwd-strength"></div>
      </div>

      <div class="form-group">
        <label for="password_confirm" class="form-label">Confirm Password</label>
        <div class="input-with-icon">
          <input type="password" id="password_confirm" name="password_confirm" class="form-input"
            placeholder="Repeat your password" required autocomplete="new-password">
          <button type="button" class="toggle-password" onclick="togglePassword('password_confirm')">
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

      <button type="submit" class="btn btn-primary btn-full">Create Account</button>
    </form>

    <?php if (GOOGLE_CLIENT_ID): ?>
    <div class="auth-divider"><span>or</span></div>
    <a href="<?= e(google_auth_url()) ?>" class="btn btn-google btn-full">
      <svg width="18" height="18" viewBox="0 0 18 18">
        <path d="M17.64 9.2c0-.637-.057-1.251-.164-1.84H9v3.481h4.844c-.209 1.125-.843 2.078-1.796 2.716v2.259h2.908c1.702-1.567 2.684-3.875 2.684-6.615z" fill="#4285F4"/>
        <path d="M9 18c2.43 0 4.467-.806 5.956-2.18l-2.908-2.259c-.806.54-1.837.86-3.048.86-2.344 0-4.328-1.584-5.036-3.711H.957v2.332C2.438 15.983 5.482 18 9 18z" fill="#34A853"/>
        <path d="M3.964 10.71A5.41 5.41 0 0 1 3.682 9c0-.593.102-1.17.282-1.71V4.958H.957A8.996 8.996 0 0 0 0 9c0 1.452.348 2.827.957 4.042l3.007-2.332z" fill="#FBBC05"/>
        <path d="M9 3.58c1.321 0 2.508.454 3.44 1.345l2.582-2.58C13.463.891 11.426 0 9 0 5.482 0 2.438 2.017.957 4.958L3.964 6.29C4.672 4.163 6.656 3.58 9 3.58z" fill="#EA4335"/>
      </svg>
      Sign up with Google
    </a>
    <?php endif; ?>

    <p class="auth-footer">
      Already have an account? <a href="<?= APP_URL ?>/auth/login.php">Sign in</a>
    </p>

  </div>
</div>

<script>
function togglePassword(id) {
  const f = document.getElementById(id);
  f.type = f.type === 'password' ? 'text' : 'password';
}

// Password strength indicator
document.getElementById('password').addEventListener('input', function() {
  const val = this.value;
  const el  = document.getElementById('pwd-strength');
  let score = 0;
  if (val.length >= 8) score++;
  if (/[A-Z]/.test(val)) score++;
  if (/[0-9]/.test(val)) score++;
  if (/[^A-Za-z0-9]/.test(val)) score++;

  const labels = ['', 'Weak', 'Fair', 'Good', 'Strong'];
  const classes = ['', 'strength-weak', 'strength-fair', 'strength-good', 'strength-strong'];
  el.className = 'pwd-strength ' + (classes[score] || '');
  el.textContent = val.length > 0 ? (labels[score] || 'Strong') : '';
});
</script>
</body>
</html>