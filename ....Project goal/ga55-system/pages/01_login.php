<?php
// ============================================================
// GA-55A SYSTEM — pages/01_login.php
// Login page — no auth required
// ============================================================
require_once '../config.php';
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// Already logged in → dashboard
if (isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/pages/02_dashboard.php');
    exit;
}

$error   = '';
$timeout = isset($_GET['reason']) && $_GET['reason'] === 'timeout';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please refresh and try again.';
    } else {
        $username = clean($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Username aur password dono zaroori hain.';
        } else {
            $stmt = $conn->prepare("SELECT id, name, password_hash, role, is_active FROM users WHERE username = ?");
            $stmt->bind_param('s', $username);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$user) {
                $error = 'Username ya password galat hai.';
            } elseif (!$user['is_active']) {
                $error = 'Yeh account deactivate hai. Admin se contact karein.';
            } elseif (!password_verify($password, $user['password_hash'])) {
                $error = 'Username ya password galat hai.';
            } else {
                // Login success
                session_regenerate_id(true);
                $_SESSION['user_id']    = $user['id'];
                $_SESSION['user_name']  = $user['name'];
                $_SESSION['user_role']  = $user['role'];
                $_SESSION['last_active']= time();
                header('Location: ' . BASE_URL . '/pages/02_dashboard.php');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="hi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | <?= APP_NAME ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/01_variables.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/02_reset.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/05_forms.css">
    <style>
        body { background: var(--clr-bg-main); display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .login-wrap { width: 100%; max-width: 400px; padding: 20px; }
        .login-logo { text-align: center; margin-bottom: 28px; }
        .login-logo i { font-size: 44px; color: var(--clr-primary-mid); display: block; margin-bottom: 8px; }
        .login-logo h1 { font-size: 20px; color: var(--clr-primary-dark); font-weight: 700; }
        .login-logo p  { font-size: 12px; color: var(--clr-text-muted); margin-top: 2px; }
        .login-card { background: #fff; border-radius: var(--br-lg); box-shadow: var(--shadow-lg); padding: 28px; border: 1px solid var(--clr-border); }
        .login-card h2 { font-size: 16px; font-weight: 600; color: var(--clr-text-main); margin-bottom: 20px; }
        .form-group { margin-bottom: 14px; }
        .input-wrap { position: relative; }
        .input-wrap i { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--clr-text-muted); font-size: 16px; }
        .input-wrap .form-control { padding-left: 34px; }
        .btn-login { width: 100%; padding: 10px; font-size: 14px; margin-top: 6px; background: var(--clr-accent); color: #fff; border: none; border-radius: var(--br-sm); cursor: pointer; font-weight: 600; transition: background 0.15s; }
        .btn-login:hover { background: var(--clr-accent-hover); }
        .alert { padding: 10px 12px; border-radius: var(--br-sm); font-size: 12px; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
        .alert-error { background: var(--clr-error-bg); color: var(--clr-error); border: 1px solid var(--clr-error-border); }
        .alert-warning { background: var(--clr-warning-bg); color: var(--clr-warning); border: 1px solid var(--clr-warning-border); }
        .login-footer { text-align: center; margin-top: 16px; font-size: 11px; color: var(--clr-text-muted); }
    </style>
</head>
<body>
<div class="login-wrap">
    <div class="login-logo">
        <i class="ti ti-building-bank"></i>
        <h1><?= APP_NAME ?></h1>
        <p>Government Salary Management System</p>
    </div>

    <div class="login-card">
        <h2>Login karein</h2>

        <?php if ($timeout): ?>
        <div class="alert alert-warning">
            <i class="ti ti-clock"></i>
            Session timeout ho gaya. Dobara login karein.
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="ti ti-alert-circle"></i>
            <?= clean($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" novalidate id="loginForm">
            <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">

            <div class="form-group">
                <label class="form-label" for="username">Username <span class="required">*</span></label>
                <div class="input-wrap">
                    <i class="ti ti-user"></i>
                    <input type="text" id="username" name="username"
                           class="form-control" placeholder="Username dalein"
                           value="<?= clean($_POST['username'] ?? '') ?>"
                           autocomplete="username" required>
                </div>
                <span class="field-error" id="err-username" style="display:none">
                    <i class="ti ti-alert-circle"></i> Username zaroori hai
                </span>
            </div>

            <div class="form-group">
                <label class="form-label" for="password">Password <span class="required">*</span></label>
                <div class="input-wrap">
                    <i class="ti ti-lock"></i>
                    <input type="password" id="password" name="password"
                           class="form-control" placeholder="Password dalein"
                           autocomplete="current-password" required>
                </div>
                <span class="field-error" id="err-password" style="display:none">
                    <i class="ti ti-alert-circle"></i> Password zaroori hai
                </span>
            </div>

            <button type="submit" class="btn-login" id="loginBtn">
                <i class="ti ti-login" style="vertical-align:-2px;margin-right:5px"></i>
                Login
            </button>
        </form>
    </div>

    <div class="login-footer">
        &copy; <?= date('Y') ?> <?= APP_NAME ?> &mdash; v<?= APP_VERSION ?>
    </div>
</div>

<script>
document.getElementById('loginForm').addEventListener('submit', function(e) {
    let ok = true;
    const u = document.getElementById('username');
    const p = document.getElementById('password');

    if (!u.value.trim()) {
        u.classList.add('is-invalid');
        document.getElementById('err-username').style.display = 'flex';
        ok = false;
    } else {
        u.classList.remove('is-invalid');
        document.getElementById('err-username').style.display = 'none';
    }

    if (!p.value.trim()) {
        p.classList.add('is-invalid');
        document.getElementById('err-password').style.display = 'flex';
        ok = false;
    } else {
        p.classList.remove('is-invalid');
        document.getElementById('err-password').style.display = 'none';
    }

    if (!ok) e.preventDefault();
});
</script>
</body>
</html>
