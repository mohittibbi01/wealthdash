<?php
/**
 * WealthDash — t386: 2FA TOTP (Google Authenticator)
 * Handles: 2fa_status, 2fa_setup, 2fa_verify_setup, 2fa_disable, 2fa_verify_login
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

// ── Tiny pure-PHP TOTP (no composer dep) ──────────────────────────────────
class WD_TOTP {
    const DIGITS = 6;
    const PERIOD = 30;
    const ALGO   = 'sha1';
    const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    static function generateSecret(int $bytes = 20): string {
        $raw = random_bytes($bytes);
        return self::base32Encode($raw);
    }

    static function getCode(string $secret, int $offset = 0): string {
        $key   = self::base32Decode($secret);
        $time  = intdiv(time(), self::PERIOD) + $offset;
        $msg   = pack('J', $time);
        $hash  = hash_hmac(self::ALGO, $msg, $key, true);
        $off   = ord($hash[strlen($hash) - 1]) & 0x0F;
        $val   = ((ord($hash[$off]) & 0x7F) << 24)
               | ((ord($hash[$off+1]) & 0xFF) << 16)
               | ((ord($hash[$off+2]) & 0xFF) << 8)
               |  (ord($hash[$off+3]) & 0xFF);
        return str_pad((string)($val % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    static function verify(string $secret, string $code, int $window = 1): bool {
        $code = trim($code);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::getCode($secret, $i), $code)) return true;
        }
        return false;
    }

    static function qrUri(string $secret, string $user, string $issuer = 'WealthDash'): string {
        $label = rawurlencode($issuer) . ':' . rawurlencode($user);
        return 'otpauth://totp/' . $label
             . '?secret=' . $secret
             . '&issuer=' . rawurlencode($issuer)
             . '&algorithm=SHA1&digits=6&period=30';
    }

    // ── Base32 ──────────────────────────────────────────────
    private static function base32Encode(string $data): string {
        $b32 = self::BASE32;
        $out = '';
        $len = strlen($data);
        $buf = 0; $bits = 0;
        for ($i = 0; $i < $len; $i++) {
            $buf = ($buf << 8) | ord($data[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out .= $b32[($buf >> $bits) & 0x1F];
            }
        }
        if ($bits) $out .= $b32[($buf << (5 - $bits)) & 0x1F];
        while (strlen($out) % 8) $out .= '=';
        return $out;
    }

    private static function base32Decode(string $data): string {
        $data = strtoupper(str_replace('=', '', $data));
        $b32  = self::BASE32;
        $out  = '';
        $buf  = 0; $bits = 0;
        for ($i = 0; $i < strlen($data); $i++) {
            $pos = strpos($b32, $data[$i]);
            if ($pos === false) continue;
            $buf = ($buf << 5) | $pos;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out .= chr(($buf >> $bits) & 0xFF);
            }
        }
        return $out;
    }
}

// ── Route dispatcher ──────────────────────────────────────────────────────
$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];
$db     = DB::getInstance();

switch ($action) {

    // ── STATUS: is 2FA enabled? ──────────────────────────────────────────
    case '2fa_status': {
        csrf_check_exempt();
        $row = DB::fetchRow(
            "SELECT totp_enabled, totp_setup_at FROM users WHERE id = ?",
            [$userId]
        );
        json_response(true, 'ok', [
            'enabled'    => (bool)($row['totp_enabled'] ?? false),
            'setup_at'   => $row['totp_setup_at'] ?? null,
        ]);
        break;
    }

    // ── SETUP: generate secret + QR URI ─────────────────────────────────
    case '2fa_setup': {
        csrf_verify();
        RateLimit::check('2fa_setup');

        $user = DB::fetchRow("SELECT email, totp_enabled FROM users WHERE id = ?", [$userId]);
        if ($user['totp_enabled']) {
            json_response(false, '2FA is already enabled. Disable first to re-setup.');
        }

        $secret = WD_TOTP::generateSecret();
        $qrUri  = WD_TOTP::qrUri($secret, $user['email']);

        // Store pending secret (not active until verified)
        DB::execute(
            "UPDATE users SET totp_secret_pending = ? WHERE id = ?",
            [$secret, $userId]
        );

        // QR as Google Charts URL (no JS lib needed)
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data='
               . rawurlencode($qrUri);

        json_response(true, 'Secret generated', [
            'secret'  => $secret,
            'qr_url'  => $qrUrl,
            'otp_uri' => $qrUri,
        ]);
        break;
    }

    // ── VERIFY SETUP: confirm TOTP code before activating ───────────────
    case '2fa_verify_setup': {
        csrf_verify();
        RateLimit::check('2fa_setup');

        $code = clean($_POST['code'] ?? '');
        if (!$code) json_response(false, 'Code required.');

        $row = DB::fetchRow(
            "SELECT totp_secret_pending FROM users WHERE id = ?",
            [$userId]
        );
        if (empty($row['totp_secret_pending'])) {
            json_response(false, 'No pending 2FA setup. Start setup first.');
        }

        if (!WD_TOTP::verify($row['totp_secret_pending'], $code)) {
            json_response(false, 'Invalid code. Check your authenticator app.');
        }

        // Generate backup codes
        $backupCodes = [];
        for ($i = 0; $i < 8; $i++) {
            $backupCodes[] = strtoupper(bin2hex(random_bytes(4)));
        }
        $backupHash = json_encode(array_map('password_hash', $backupCodes, array_fill(0, 8, PASSWORD_DEFAULT)));

        DB::execute(
            "UPDATE users SET
                totp_enabled = 1,
                totp_secret  = totp_secret_pending,
                totp_secret_pending = NULL,
                totp_setup_at = NOW(),
                totp_backup_codes = ?
             WHERE id = ?",
            [$backupHash, $userId]
        );

        audit_log($userId, '2fa_enabled', '2FA TOTP enabled');
        json_response(true, '2FA enabled successfully', ['backup_codes' => $backupCodes]);
        break;
    }

    // ── DISABLE ──────────────────────────────────────────────────────────
    case '2fa_disable': {
        csrf_verify();
        RateLimit::check('2fa_setup');

        $code     = clean($_POST['code'] ?? '');
        $password = $_POST['password'] ?? '';

        if (!$code || !$password) {
            json_response(false, 'Code and password required.');
        }

        $row = DB::fetchRow(
            "SELECT password_hash, totp_secret, totp_enabled FROM users WHERE id = ?",
            [$userId]
        );
        if (!$row['totp_enabled']) {
            json_response(false, '2FA is not enabled.');
        }
        if (!password_verify($password, $row['password_hash'])) {
            json_response(false, 'Incorrect password.');
        }
        if (!WD_TOTP::verify($row['totp_secret'], $code)) {
            json_response(false, 'Invalid 2FA code.');
        }

        DB::execute(
            "UPDATE users SET totp_enabled=0, totp_secret=NULL,
             totp_secret_pending=NULL, totp_backup_codes=NULL,
             totp_setup_at=NULL WHERE id=?",
            [$userId]
        );

        audit_log($userId, '2fa_disabled', '2FA TOTP disabled');
        json_response(true, '2FA disabled successfully.');
        break;
    }

    // ── VERIFY LOGIN: used during login flow ─────────────────────────────
    case '2fa_verify_login': {
        // No full CSRF needed here — we check pending_2fa_userid in session
        RateLimit::check('2fa_verify_login');

        $pendingId = (int)($_SESSION['pending_2fa_userid'] ?? 0);
        if (!$pendingId) {
            json_response(false, 'No pending 2FA session.', [], 403);
        }

        $code = clean($_POST['code'] ?? '');
        if (!$code) json_response(false, 'Code required.');

        $row = DB::fetchRow(
            "SELECT totp_secret, totp_backup_codes FROM users WHERE id = ? AND totp_enabled = 1",
            [$pendingId]
        );
        if (!$row) json_response(false, 'User not found or 2FA not enabled.', [], 403);

        $valid = WD_TOTP::verify($row['totp_secret'], $code);

        // Backup code fallback
        if (!$valid && strlen($code) === 8) {
            $backups = json_decode($row['totp_backup_codes'] ?? '[]', true);
            foreach ($backups as $idx => $hash) {
                if (password_verify(strtoupper($code), $hash)) {
                    // Consume backup code (one-time use)
                    unset($backups[$idx]);
                    DB::execute(
                        "UPDATE users SET totp_backup_codes = ? WHERE id = ?",
                        [json_encode(array_values($backups)), $pendingId]
                    );
                    $valid = true;
                    break;
                }
            }
        }

        if (!$valid) {
            json_response(false, 'Invalid code. Try again.');
        }

        // Complete login
        $_SESSION['user_id']          = $pendingId;
        $_SESSION['2fa_verified']     = true;
        unset($_SESSION['pending_2fa_userid']);
        session_regenerate_id(true);

        audit_log($pendingId, '2fa_login', '2FA login verified');
        json_response(true, '2FA verified. Logging in…', ['redirect' => APP_URL . '?page=dashboard']);
        break;
    }

    default:
        json_response(false, 'Unknown 2FA action.', [], 400);
}
