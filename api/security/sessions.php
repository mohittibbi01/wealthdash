<?php
/**
 * WealthDash — t387: Session Security — Advanced Session Management
 * File: api/security/sessions.php
 * Actions: sessions_list, session_revoke, session_revoke_all,
 *          session_touch (auto-called), session_current
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];
$currentSessionId = session_id();

switch ($action) {

    // ── Touch / register current session (call on login + periodically) ──
    case 'session_touch': {
        $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $device = _parse_device($ua);

        $existing = DB::fetchVal("SELECT id FROM user_sessions WHERE session_id=?", [$currentSessionId]);
        if ($existing) {
            DB::execute("UPDATE user_sessions SET last_active=NOW(), ip_address=? WHERE id=?", [$ip, $existing]);
        } else {
            DB::execute(
                "INSERT INTO user_sessions(user_id,session_id,ip_address,user_agent,device_label,created_at,last_active)
                 VALUES(?,?,?,?,?,NOW(),NOW())",
                [$userId, $currentSessionId, $ip, $ua, $device]
            );
        }
        json_response(true, 'ok');
        break;
    }

    // ── List all active sessions for this user ─────────────────────
    case 'sessions_list': {
        // Clean up sessions inactive > 30 days
        DB::execute("DELETE FROM user_sessions WHERE user_id=? AND last_active < DATE_SUB(NOW(), INTERVAL 30 DAY)", [$userId]);

        $rows = DB::fetchAll(
            "SELECT id, session_id, ip_address, user_agent, device_label, created_at, last_active
             FROM user_sessions WHERE user_id=? ORDER BY last_active DESC",
            [$userId]
        );
        foreach ($rows as &$r) {
            $r['is_current'] = ($r['session_id'] === $currentSessionId);
            $r['ip_masked']  = preg_replace('/\.\d+$/', '.xxx', $r['ip_address'] ?? '');
            unset($r['session_id']); // don't expose raw session id
        }
        json_response(true, 'ok', ['sessions' => $rows, 'current_count' => count($rows)]);
        break;
    }

    // ── Current session info ────────────────────────────────────────
    case 'session_current': {
        $row = DB::fetchRow("SELECT created_at, last_active, ip_address, device_label FROM user_sessions WHERE session_id=?", [$currentSessionId]);
        $timeout = 120 * 60; // 2 hours in seconds (matches app_settings session_timeout_min default)
        $lastActivity = $_SESSION['last_activity'] ?? time();
        $remaining = max(0, $timeout - (time() - $lastActivity));
        json_response(true, 'ok', [
            'session' => $row,
            'timeout_seconds' => $timeout,
            'remaining_seconds' => $remaining,
        ]);
        break;
    }

    // ── Revoke a specific session ────────────────────────────────────
    case 'session_revoke': {
        csrf_verify();
        $id = (int)($_POST['id'] ?? 0);
        $row = DB::fetchRow("SELECT session_id FROM user_sessions WHERE id=? AND user_id=?", [$id, $userId]);
        if (!$row) json_response(false, 'Session not found.');

        if ($row['session_id'] === $currentSessionId) {
            json_response(false, 'Cannot revoke your current session. Use logout instead.');
        }

        // Delete session file (PHP session storage) + DB record
        $savePath = session_save_path() ?: sys_get_temp_dir();
        $sessFile = $savePath . '/sess_' . $row['session_id'];
        if (file_exists($sessFile)) @unlink($sessFile);

        DB::execute("DELETE FROM user_sessions WHERE id=?", [$id]);
        audit_log($userId, 'session_revoke', "Revoked session ID $id");
        json_response(true, 'Session revoked.');
        break;
    }

    // ── Revoke all sessions except current ───────────────────────────
    case 'session_revoke_all': {
        csrf_verify();

        $sessions = DB::fetchAll("SELECT session_id FROM user_sessions WHERE user_id=? AND session_id != ?", [$userId, $currentSessionId]);
        $savePath = session_save_path() ?: sys_get_temp_dir();
        foreach ($sessions as $s) {
            $sessFile = $savePath . '/sess_' . $s['session_id'];
            if (file_exists($sessFile)) @unlink($sessFile);
        }

        DB::execute("DELETE FROM user_sessions WHERE user_id=? AND session_id != ?", [$userId, $currentSessionId]);
        audit_log($userId, 'session_revoke_all', "Revoked " . count($sessions) . " other sessions");
        json_response(true, count($sessions) . ' other session(s) revoked. You remain logged in here.');
        break;
    }

    default: json_response(false, 'Unknown action.', [], 400);
}

// ── Helper: parse user agent into friendly device label ────────────
function _parse_device(string $ua): string {
    $os = 'Unknown OS';
    if (str_contains($ua, 'Windows'))      $os = 'Windows';
    elseif (str_contains($ua, 'Mac OS'))   $os = 'macOS';
    elseif (str_contains($ua, 'Android'))  $os = 'Android';
    elseif (str_contains($ua, 'iPhone'))   $os = 'iPhone';
    elseif (str_contains($ua, 'iPad'))     $os = 'iPad';
    elseif (str_contains($ua, 'Linux'))    $os = 'Linux';

    $browser = 'Unknown Browser';
    if (str_contains($ua, 'Edg/'))         $browser = 'Edge';
    elseif (str_contains($ua, 'Chrome/'))  $browser = 'Chrome';
    elseif (str_contains($ua, 'Firefox/'))$browser = 'Firefox';
    elseif (str_contains($ua, 'Safari/')) $browser = 'Safari';

    return "$browser on $os";
}
