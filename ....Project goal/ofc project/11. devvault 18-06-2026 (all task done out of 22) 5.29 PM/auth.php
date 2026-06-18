<?php
require_once __DIR__ . '/config.php';

// ── Session settings (must be BEFORE session_start) ──────────────────────────
ini_set('session.gc_maxlifetime', 360); // 6 min server-side idle timeout
ini_set('session.cookie_httponly', 1);  // JS cannot access cookie
ini_set('session.use_strict_mode', 1);  // Reject uninitialized session IDs
ini_set('session.cookie_secure', 1);    // Send cookie over HTTPS only
                                         // NOTE: For local HTTP dev, set this to 0 temporarily
ini_set('session.cookie_samesite', 'Strict'); // Block cross-site cookie sending

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── HTTP Security Headers ─────────────────────────────────────────────────────
// Sent on every request. These protect against clickjacking, MIME sniffing,
// CSS/script injection and cross-site leaks.
if (!headers_sent()) {
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: same-origin");
    // CSP: inline styles/scripts needed for current app architecture
    // Tighten this when JS/CSS is moved to external files
    header("Content-Security-Policy: default-src 'self'; " .
           "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; " .
           "font-src 'self' https://fonts.gstatic.com; " .
           "script-src 'self' 'unsafe-inline'; " .
           "img-src 'self' data:; " .
           "connect-src 'self';");
}

// ── IP Whitelist check ────────────────────────────────────────────────────────
function check_ip_whitelist(): void {
    $current_file = basename($_SERVER['PHP_SELF'] ?? '');
    $skip = ['login.php', 'blocked.php', 'logout.php'];
    if (in_array($current_file, $skip)) return;

    $client_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (in_array($client_ip, ['127.0.0.1', '::1', 'localhost'])) return;

    $db = get_db();
    try {
        $count = $db->query("SELECT COUNT(*) FROM ip_whitelist WHERE is_active=1")->fetchColumn();
    } catch (Exception $e) {
        return;
    }
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

    // ── Absolute session timeout: 8 hours regardless of activity ─────────────
    $max_lifetime = 8 * 3600;
    if (isset($_SESSION['login_time']) && (time() - (int)$_SESSION['login_time']) > $max_lifetime) {
        session_unset();
        session_destroy();
        header('Location: login.php?err=session_expired');
        exit;
    }

    // ── Force password change if not done yet ─────────────────────────────────
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

// ── User theme/font preferences ───────────────────────────────────────────────
function user_pref(string $key, string $default = ''): string {
    return $_SESSION['prefs'][$key] ?? $default;
}
