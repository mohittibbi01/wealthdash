<?php
/**
 * WealthDash — 2FA / TOTP API Handler
 * t386: Google Authenticator TOTP
 * Actions: 2fa_status | 2fa_setup | 2fa_enable | 2fa_disable | 2fa_verify_login | 2fa_backup_login
 * Included by api/router.php
 */
defined('WEALTHDASH') or die();

require_once APP_ROOT . '/includes/totp.php';

$sub = $action; // already set by router

// ── Helpers ──────────────────────────────────────────────────────────────────

function t386_log(int $userId, string $event, ?string $detail = null): void
{
    DB::run(
        'INSERT INTO totp_audit (user_id, event, ip, user_agent, detail) VALUES (?,?,?,?,?)',
        [
            $userId,
            $event,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            $detail,
        ]
    );
}

function t386_getUser(int $userId): array
{
    $u = DB::fetchRow('SELECT id, email, totp_secret, totp_enabled, totp_backup_codes FROM users WHERE id = ?', [$userId]);
    if (!$u) json_response(false, 'User not found.', [], 404);
    return $u;
}

// ── Route ────────────────────────────────────────────────────────────────────

switch ($sub) {

    // ── GET: current 2FA status ───────────────────────────────────────────────
    case '2fa_status':
        $u = t386_getUser($userId);
        $backupLeft = 0;
        if ($u['totp_backup_codes']) {
            $arr = json_decode($u['totp_backup_codes'], true);
            $backupLeft = is_array($arr) ? count($arr) : 0;
        }
        json_response(true, '', [
            'enabled'      => (bool) $u['totp_enabled'],
            'backup_left'  => $backupLeft,
        ]);

    // ── POST: begin setup — generate secret + QR URI ─────────────────────────
    case '2fa_setup':
        $u = t386_getUser($userId);
        if ($u['totp_enabled']) {
            json_response(false, '2FA is already enabled. Disable it first.');
        }
        // Generate fresh secret each setup attempt
        $secret = TOTP::generateSecret();
        DB::run('UPDATE users SET totp_secret = ? WHERE id = ?', [$secret, $userId]);
        $uri = TOTP::getUri($secret, $u['email']);
        t386_log($userId, 'setup');
        json_response(true, '', [
            'secret' => $secret,
            'uri'    => $uri,
        ]);

    // ── POST: confirm setup with first valid code → enable 2FA ───────────────
    case '2fa_enable':
        $u    = t386_getUser($userId);
        $code = clean($_POST['code'] ?? '');
        if (!$u['totp_secret']) {
            json_response(false, 'Run 2fa_setup first.');
        }
        if (!TOTP::verify($u['totp_secret'], $code)) {
            t386_log($userId, 'login_fail', 'enable_verify');
            json_response(false, 'Invalid code. Try again.');
        }
        // Generate backup codes
        $plainCodes = TOTP::generateBackupCodes();
        $hashedJson = TOTP::hashBackupCodes($plainCodes);
        DB::run(
            'UPDATE users SET totp_enabled = 1, totp_verified_at = NOW(), totp_backup_codes = ? WHERE id = ?',
            [$hashedJson, $userId]
        );
        t386_log($userId, 'verify');
        json_response(true, '2FA enabled successfully.', [
            'backup_codes' => $plainCodes,  // shown ONCE to user
        ]);

    // ── POST: disable 2FA (requires valid TOTP code OR backup code) ──────────
    case '2fa_disable':
        $u    = t386_getUser($userId);
        $code = clean($_POST['code'] ?? '');
        if (!$u['totp_enabled']) {
            json_response(false, '2FA is not enabled.');
        }
        $ok = TOTP::verify($u['totp_secret'], $code);
        if (!$ok) {
            // Try backup
            if ($u['totp_backup_codes']) {
                $bj = $u['totp_backup_codes'];
                $ok = TOTP::verifyBackupCode($code, $bj);
            }
        }
        if (!$ok) {
            t386_log($userId, 'login_fail', 'disable_verify');
            json_response(false, 'Invalid code.');
        }
        DB::run(
            'UPDATE users SET totp_enabled = 0, totp_secret = NULL, totp_backup_codes = NULL, totp_verified_at = NULL WHERE id = ?',
            [$userId]
        );
        t386_log($userId, 'disable');
        json_response(true, '2FA disabled.');

    // ── POST: verify TOTP during login challenge ──────────────────────────────
    // Called from the login 2FA challenge page (user NOT yet fully authed)
    case '2fa_verify_login':
        // At this stage user_id comes from a pending session token, not full auth
        $token  = clean($_POST['challenge_token'] ?? '');
        $code   = clean($_POST['code'] ?? '');

        $row = DB::fetchRow(
            'SELECT user_id FROM totp_sessions WHERE token = ? AND expires_at > NOW()',
            [$token]
        );
        if (!$row) {
            json_response(false, 'Session expired. Please log in again.', [], 401);
        }
        $pendingUid = (int) $row['user_id'];
        $u = DB::fetchRow('SELECT totp_secret, totp_enabled FROM users WHERE id = ?', [$pendingUid]);

        if (!$u || !$u['totp_enabled'] || !$u['totp_secret']) {
            json_response(false, 'Invalid 2FA state.');
        }
        if (!TOTP::verify($u['totp_secret'], $code)) {
            t386_log($pendingUid, 'login_fail', 'totp');
            json_response(false, 'Invalid code. Try again.');
        }
        // Code valid → promote to fully authenticated session
        DB::run('DELETE FROM totp_sessions WHERE token = ?', [$token]);
        $_SESSION['user_id']     = $pendingUid;
        $_SESSION['2fa_passed']  = true;
        t386_log($pendingUid, 'login_ok', 'totp');
        json_response(true, 'Verified.', ['redirect' => APP_URL . '?page=dashboard']);

    // ── POST: verify backup code during login ─────────────────────────────────
    case '2fa_backup_login':
        $token = clean($_POST['challenge_token'] ?? '');
        $code  = strtoupper(trim(clean($_POST['code'] ?? '')));

        $row = DB::fetchRow(
            'SELECT user_id FROM totp_sessions WHERE token = ? AND expires_at > NOW()',
            [$token]
        );
        if (!$row) {
            json_response(false, 'Session expired. Please log in again.', [], 401);
        }
        $pendingUid = (int) $row['user_id'];
        $u = DB::fetchRow('SELECT totp_backup_codes FROM users WHERE id = ?', [$pendingUid]);

        if (!$u || !$u['totp_backup_codes']) {
            json_response(false, 'No backup codes available.');
        }
        $bj = $u['totp_backup_codes'];
        if (!TOTP::verifyBackupCode($code, $bj)) {
            t386_log($pendingUid, 'login_fail', 'backup');
            json_response(false, 'Invalid backup code.');
        }
        // Save consumed backup codes
        DB::run('UPDATE users SET totp_backup_codes = ? WHERE id = ?', [$bj, $pendingUid]);
        DB::run('DELETE FROM totp_sessions WHERE token = ?', [$token]);
        $_SESSION['user_id']    = $pendingUid;
        $_SESSION['2fa_passed'] = true;
        t386_log($pendingUid, 'backup_used');
        json_response(true, 'Verified via backup code.', ['redirect' => APP_URL . '?page=dashboard']);

    default:
        json_response(false, 'Unknown 2FA action.');
}
