<?php
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once APP_ROOT . '/config/oauth.php';
require_once APP_ROOT . '/includes/auth_check.php';

if (is_logged_in()) redirect('index.php');

$error = $_GET['error'] ?? '';
if ($error) {
    flash_set('error', 'Google login was cancelled or failed. Please try again.');
    redirect('auth/login.php');
}

$code  = $_GET['code']  ?? '';
$state = $_GET['state'] ?? '';

// CSRF state check
if (!$code || !hash_equals($_SESSION['_csrf_token'] ?? '', $state)) {
    flash_set('error', 'Invalid OAuth state. Please try again.');
    redirect('auth/login.php');
}

// Exchange code for token
$tokens = google_exchange_code($code);
if (!$tokens || empty($tokens['access_token'])) {
    flash_set('error', 'Google authentication failed. Please try again.');
    redirect('auth/login.php');
}

// Get user info
$gUser = google_get_userinfo($tokens['access_token']);
if (!$gUser || empty($gUser['email'])) {
    flash_set('error', 'Could not retrieve Google account info. Please try again.');
    redirect('auth/login.php');
}

$googleId = $gUser['id'];
$gEmail   = strtolower($gUser['email']);
$gName    = $gUser['name'] ?? $gEmail;

DB::beginTransaction();
try {
    // Check if Google account already linked
    $gaRow = DB::fetchOne('SELECT * FROM google_auth WHERE google_id = ?', [$googleId]);

    if ($gaRow) {
        // Existing Google login
        $user = DB::fetchOne('SELECT * FROM users WHERE id = ? AND status = ?', [$gaRow['user_id'], 'active']);
        if (!$user) {
            DB::rollback();
            flash_set('error', 'Your account has been deactivated. Contact admin.');
            redirect('auth/login.php');
        }
    } else {
        // Check if email exists
        $user = DB::fetchOne('SELECT * FROM users WHERE email = ?', [$gEmail]);

        if (!$user) {
            // Auto-register
            $userCount = (int) DB::fetchVal('SELECT COUNT(*) FROM users');
            $role      = $userCount === 0 ? 'admin' : 'member';

            $userId = DB::insert(
                'INSERT INTO users (name, email, role, email_verified, status) VALUES (?, ?, ?, 1, ?)',
                [$gName, $gEmail, $role, 'active']
            );

            // Default portfolio
            DB::run(
                'INSERT INTO portfolios (user_id, name, color, is_default) VALUES (?, ?, ?, 1)',
                [(int)$userId, $gName . "'s Portfolio", '#2563EB']
            );

            $user = DB::fetchOne('SELECT * FROM users WHERE id = ?', [(int)$userId]);
        } elseif ($user['status'] !== 'active') {
            DB::rollback();
            flash_set('error', 'Account is ' . $user['status'] . '. Contact admin.');
            redirect('auth/login.php');
        }

        // Link Google account
        DB::run(
            'INSERT INTO google_auth (user_id, google_id, email, access_token, token_expiry) VALUES (?, ?, ?, ?, ?)',
            [
                $user['id'], $googleId, $gEmail,
                $tokens['access_token'],
                isset($tokens['expires_in'])
                    ? date('Y-m-d H:i:s', time() + $tokens['expires_in'])
                    : null,
            ]
        );
    }

    DB::commit();

    // Login
    session_regenerate_id(true);
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['user_theme'] = $user['theme'];
    $_SESSION['_last_activity'] = time();

    DB::run('UPDATE users SET last_login_at = NOW(), login_count = login_count + 1 WHERE id = ?', [$user['id']]);
    audit_log('google_login', 'user', (int)$user['id']);

    flash_set('success', 'Logged in successfully via Google.');
    redirect('index.php');

} catch (Exception $e) {
    DB::rollback();
    error_log('Google OAuth error: ' . $e->getMessage());
    flash_set('error', 'Login failed due to a technical issue. Please try again.');
    redirect('auth/login.php');
}

