<?php
require_once __DIR__ . '/config.php';

// ── Session settings (must be before session_start) ──────────────────────────
ini_set('session.gc_maxlifetime', 360); // 6 min server-side (5 min idle + 1 min buffer)
ini_set('session.cookie_httponly', 1);
ini_set('session.use_strict_mode', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── IP Whitelist check ────────────────────────────────────────────────────────
// Runs on every request (except login.php and blocked.php themselves)
function check_ip_whitelist(): void {
    $current_file = basename($_SERVER['PHP_SELF'] ?? '');
    // Skip check for these pages
    $skip = ['login.php', 'blocked.php', 'logout.php'];
    if (in_array($current_file, $skip)) return;

    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';

    // Always allow localhost
    if (in_array($client_ip, ['127.0.0.1', '::1', 'localhost'])) return;

    $db = get_db();

    // If whitelist table doesn't exist yet, skip
    try {
        $count = $db->query("SELECT COUNT(*) FROM ip_whitelist WHERE is_active=1")->fetchColumn();
    } catch (Exception $e) {
        return; // Table not yet created — allow all
    }

    // If whitelist is empty, allow all (fresh install safety)
    if ((int)$count === 0) return;

    $st = $db->prepare("SELECT id FROM ip_whitelist WHERE ip_address=? AND is_active=1");
    $st->execute([$client_ip]);
    if (!$st->fetch()) {
        header('Location: blocked.php');
        exit;
    }
}
check_ip_whitelist();

// ── Auth helpers ──────────────────────────────────────────────────────────────
function is_logged_in(): bool {
    return isset($_SESSION['user_id'], $_SESSION['username']);
}

function is_admin(): bool {
    return is_logged_in() && ($_SESSION['role'] ?? '') === 'admin';
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }

    // Force password change if admin hasn't changed default password yet
    $current_file = basename($_SERVER['PHP_SELF'] ?? '');
    $skip = ['change_password.php', 'logout.php', 'blocked.php'];
    if (!in_array($current_file, $skip)) {
        if (isset($_SESSION['password_changed']) && $_SESSION['password_changed'] == 0) {
            header('Location: change_password.php?force=1');
            exit;
        }
    }
}

function require_admin(): void {
    if (!is_admin()) {
        header('Location: index.php?err=access');
        exit;
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function verify_csrf(): bool {
    return isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
}

// User theme/font preferences
function user_pref(string $key, string $default = ''): string {
    return $_SESSION['prefs'][$key] ?? $default;
}
