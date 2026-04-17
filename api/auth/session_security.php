<?php
/**
 * WealthDash — t387: Session Security
 * Multiple device management, idle timeout, concurrent session warnings
 * Actions: sessions_list | session_revoke | sessions_revoke_all | session_heartbeat
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'sessions_list';

// ── Ensure user_sessions table exists ─────────────────────────
try {
    DB::conn()->exec("
        CREATE TABLE IF NOT EXISTS user_sessions (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id      INT UNSIGNED NOT NULL,
            session_token VARCHAR(128) NOT NULL UNIQUE,
            device_name  VARCHAR(120),
            device_type  ENUM('desktop','mobile','tablet','unknown') DEFAULT 'unknown',
            browser      VARCHAR(80),
            ip_address   VARCHAR(45),
            country      VARCHAR(50),
            last_active  DATETIME NOT NULL,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            is_current   TINYINT(1) NOT NULL DEFAULT 0,
            INDEX idx_user (user_id),
            INDEX idx_token (session_token),
            INDEX idx_active (last_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) { /* already exists */ }

// ── Helper: parse user agent ──────────────────────────────────
function parseUserAgent(string $ua): array {
    $device = 'unknown'; $browser = 'Unknown';

    // Device type
    if (preg_match('/Mobile|Android|iPhone|iPad/i', $ua)) {
        $device = preg_match('/iPad/i', $ua) ? 'tablet' : 'mobile';
    } else {
        $device = 'desktop';
    }

    // Browser
    if     (preg_match('/Edg\//i',      $ua)) $browser = 'Edge';
    elseif (preg_match('/OPR\//i',      $ua)) $browser = 'Opera';
    elseif (preg_match('/Chrome\//i',   $ua)) $browser = 'Chrome';
    elseif (preg_match('/Firefox\//i',  $ua)) $browser = 'Firefox';
    elseif (preg_match('/Safari\//i',   $ua)) $browser = 'Safari';

    // OS
    $os = 'Unknown';
    if     (preg_match('/Windows NT/i', $ua)) $os = 'Windows';
    elseif (preg_match('/Mac OS X/i',   $ua)) $os = 'macOS';
    elseif (preg_match('/Android/i',    $ua)) $os = 'Android';
    elseif (preg_match('/iPhone|iPad/i',$ua)) $os = 'iOS';
    elseif (preg_match('/Linux/i',      $ua)) $os = 'Linux';

    return [
        'device_type'  => $device,
        'browser'      => $browser . ' on ' . $os,
        'device_name'  => $browser . ' — ' . $os,
    ];
}

function getOrCreateSession(int $userId): string {
    $token = $_SESSION['wd_session_token'] ?? null;

    if (!$token) {
        $token = bin2hex(random_bytes(32));
        $_SESSION['wd_session_token'] = $token;
    }

    $ua   = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $ip   = $_SERVER['REMOTE_ADDR'] ?? '';
    $info = parseUserAgent($ua);

    // Upsert
    DB::run("
        INSERT INTO user_sessions (user_id, session_token, device_name, device_type, browser, ip_address, last_active, is_current)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), 1)
        ON DUPLICATE KEY UPDATE last_active = NOW(), is_current = 1
    ", [$userId, $token, $info['device_name'], $info['device_type'], $info['browser'], $ip]);

    return $token;
}

// Auto-register current session
$currentToken = getOrCreateSession($userId);

// ══════════════════════════════════════════════════════════════
switch ($action) {

    // ── LIST ALL SESSIONS ──────────────────────────────────────
    case 'sessions_list':
        // Clean up sessions inactive > 24 hours
        DB::run("DELETE FROM user_sessions WHERE user_id = ? AND last_active < DATE_SUB(NOW(), INTERVAL 24 HOUR)", [$userId]);

        $sessions = DB::fetchAll(
            "SELECT id, session_token, device_name, device_type, browser, ip_address, last_active, created_at
             FROM user_sessions
             WHERE user_id = ?
             ORDER BY last_active DESC",
            [$userId]
        );

        // Mark current session
        foreach ($sessions as &$s) {
            $s['is_current']       = ($s['session_token'] === $currentToken);
            $s['last_active_ago']  = time_ago($s['last_active']);
            $s['device_icon']      = match($s['device_type']) {
                'mobile'  => '📱',
                'tablet'  => '📱',
                'desktop' => '🖥️',
                default   => '❓',
            };
            unset($s['session_token']); // Don't expose token
        }
        unset($s);

        $idleMinutes = (int) (DB::fetchVal("SELECT setting_val FROM app_settings WHERE setting_key='session_idle_minutes'") ?? 30);

        json_response(true, '', [
            'sessions'     => $sessions,
            'total'        => count($sessions),
            'idle_minutes' => $idleMinutes,
        ]);

    // ── REVOKE ONE SESSION ─────────────────────────────────────
    case 'session_revoke':
        csrf_verify();
        $sessionId = (int) ($_POST['session_id'] ?? 0);
        if (!$sessionId) json_response(false, 'Invalid session ID.');

        $row = DB::fetchRow(
            'SELECT session_token FROM user_sessions WHERE id = ? AND user_id = ?',
            [$sessionId, $userId]
        );
        if (!$row) json_response(false, 'Session nahi mili.');

        // Don't allow revoking current session
        if ($row['session_token'] === $currentToken) {
            json_response(false, 'Current session revoke nahi kar sakte. Logout use karo.');
        }

        DB::run('DELETE FROM user_sessions WHERE id = ? AND user_id = ?', [$sessionId, $userId]);
        audit_log('session_revoked', 'user_sessions', $sessionId);
        json_response(true, 'Session remove kar di gayi.');

    // ── REVOKE ALL OTHER SESSIONS ──────────────────────────────
    case 'sessions_revoke_all':
        csrf_verify();
        $deleted = DB::run(
            'DELETE FROM user_sessions WHERE user_id = ? AND session_token != ?',
            [$userId, $currentToken]
        )->rowCount();

        audit_log('sessions_revoke_all', 'user_sessions', $userId);
        json_response(true, "{$deleted} session(s) remove kar di gayi. Sirf current device active hai.");

    // ── HEARTBEAT: keep session alive ─────────────────────────
    case 'session_heartbeat':
        DB::run(
            'UPDATE user_sessions SET last_active = NOW() WHERE session_token = ? AND user_id = ?',
            [$currentToken, $userId]
        );
        json_response(true, 'ok', ['ts' => time()]);

    // ── IDLE TIMEOUT SETTINGS (admin) ─────────────────────────
    case 'session_set_idle':
        if (!is_admin()) json_response(false, 'Admin only.', [], 403);
        csrf_verify();
        $minutes = max(5, min(480, (int) ($_POST['minutes'] ?? 30)));
        DB::run(
            "INSERT INTO app_settings (setting_key, setting_val) VALUES ('session_idle_minutes', ?)
             ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val)",
            [$minutes]
        );
        json_response(true, "Idle timeout {$minutes} minutes set ho gaya.");

    default:
        json_response(false, 'Unknown session action.', [], 400);
}

// ── Helper ─────────────────────────────────────────────────────
function time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60) . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600) . ' hr ago';
    return floor($diff / 86400) . ' days ago';
}
