<?php
/**
 * WealthDash — t386: 2FA TOTP (Google Authenticator)
 * Actions: 2fa_status | 2fa_setup | 2fa_verify_setup | 2fa_disable | 2fa_verify_login
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();
$action      = $_POST['action'] ?? $_GET['action'] ?? '2fa_status';

// ── Pure-PHP TOTP (no library needed) ─────────────────────────
class TOTP {
    const DIGITS   = 6;
    const PERIOD   = 30;
    const ALGO     = 'sha1';

    /** Generate a random Base32 secret (20 bytes = 160 bits) */
    static function generateSecret(): string {
        $bytes  = random_bytes(20);
        $base32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bin    = '';
        foreach (str_split($bytes) as $b) $bin .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
        $output = '';
        foreach (str_split($bin, 5) as $chunk)
            $output .= $base32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        return $output;
    }

    /** Decode Base32 to binary */
    static function base32Decode(string $secret): string {
        $base32  = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret  = strtoupper($secret);
        $bin     = '';
        foreach (str_split($secret) as $c) {
            $pos = strpos($base32, $c);
            if ($pos === false) continue;
            $bin .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bin, 8) as $chunk)
            if (strlen($chunk) === 8) $bytes .= chr(bindec($chunk));
        return $bytes;
    }

    /** Generate OTP for a given counter */
    static function hotp(string $secret, int $counter): string {
        $key     = self::base32Decode($secret);
        $counter = pack('N*', 0) . pack('N*', $counter);
        $hash    = hash_hmac(self::ALGO, $counter, $key, true);
        $offset  = ord($hash[strlen($hash) - 1]) & 0x0F;
        $code    = (
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
             (ord($hash[$offset + 3]) & 0xFF)
        ) % (10 ** self::DIGITS);
        return str_pad($code, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** Verify OTP — allows ±1 window for clock skew */
    static function verify(string $secret, string $otp): bool {
        $otp     = preg_replace('/\s+/', '', $otp);
        $counter = (int) floor(time() / self::PERIOD);
        for ($i = -1; $i <= 1; $i++) {
            if (hash_equals(self::hotp($secret, $counter + $i), $otp)) return true;
        }
        return false;
    }

    /** OTP Auth URI for QR code */
    static function otpauthUri(string $secret, string $email, string $issuer = 'WealthDash'): string {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($email)
             . '?secret=' . $secret
             . '&issuer=' . rawurlencode($issuer)
             . '&algorithm=SHA1&digits=6&period=30';
    }
}

// ── Ensure 2fa_settings column exists ─────────────────────────
// (Run once at startup — cheap no-op if already present)
try {
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_secret VARCHAR(64) DEFAULT NULL");
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_enabled TINYINT(1) NOT NULL DEFAULT 0");
    $db->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS totp_backup_codes TEXT DEFAULT NULL");
} catch (Exception $e) { /* already exists */ }

// ── Helpers ────────────────────────────────────────────────────
function get2faUser(int $userId): array {
    return DB::fetchRow('SELECT totp_enabled, totp_secret, totp_backup_codes FROM users WHERE id = ?', [$userId]) ?? [];
}

function generateBackupCodes(): array {
    $codes = [];
    for ($i = 0; $i < 8; $i++) {
        $codes[] = strtoupper(bin2hex(random_bytes(4)));
    }
    return $codes;
}

// ════════════════════════════════════════════════════════════════
switch ($action) {

    // ── STATUS ─────────────────────────────────────────────────
    case '2fa_status':
        $row = get2faUser($userId);
        json_response(true, '', [
            'enabled' => (bool) ($row['totp_enabled'] ?? false),
            'has_backup_codes' => !empty($row['totp_backup_codes']),
        ]);

    // ── SETUP: generate secret + QR ────────────────────────────
    case '2fa_setup':
        $row = get2faUser($userId);
        if (!empty($row['totp_enabled'])) {
            json_response(false, '2FA pehle se enabled hai. Pehle disable karo.');
        }

        $secret  = TOTP::generateSecret();
        $email   = $currentUser['email'];
        $uri     = TOTP::otpauthUri($secret, $email);

        // Store pending secret (not yet active until verified)
        DB::run('UPDATE users SET totp_secret = ?, totp_enabled = 0 WHERE id = ?', [$secret, $userId]);

        // QR code URL via Google Charts (free, no library needed)
        $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($uri);

        json_response(true, '', [
            'secret'   => $secret,
            'qr_url'   => $qrUrl,
            'otp_uri'  => $uri,
            'instructions' => 'Google Authenticator app mein + button dabaao → "Scan QR code" select karo. Phir neeche verify karo.',
        ]);

    // ── VERIFY SETUP: confirm OTP before activating ─────────────
    case '2fa_verify_setup':
        csrf_verify();
        $otp = clean($_POST['otp'] ?? '');
        if (!$otp) json_response(false, 'OTP required hai.');

        $row = get2faUser($userId);
        if (empty($row['totp_secret'])) {
            json_response(false, 'Pehle 2fa_setup karo.');
        }
        if (!empty($row['totp_enabled'])) {
            json_response(false, '2FA pehle se active hai.');
        }

        if (!TOTP::verify($row['totp_secret'], $otp)) {
            json_response(false, 'OTP galat hai ya expire ho gaya. Dobara try karo.');
        }

        // Activate + generate backup codes
        $backupCodes = generateBackupCodes();
        DB::run(
            'UPDATE users SET totp_enabled = 1, totp_backup_codes = ? WHERE id = ?',
            [json_encode($backupCodes), $userId]
        );

        audit_log('2fa_enabled', 'user', $userId);
        json_response(true, '✅ Two-Factor Authentication successfully enable ho gaya!', [
            'backup_codes' => $backupCodes,
            'backup_note'  => 'Ye codes safe jagah save karo — agar phone kho jaye to inhi se login hoga.',
        ]);

    // ── VERIFY LOGIN: called after password check ───────────────
    case '2fa_verify_login':
        $otp       = clean($_POST['otp'] ?? '');
        $loginId   = (int) ($_POST['user_id'] ?? $userId);

        if (!$otp) json_response(false, 'OTP required hai.');

        $row = DB::fetchRow(
            'SELECT totp_secret, totp_enabled, totp_backup_codes FROM users WHERE id = ?',
            [$loginId]
        );

        if (!$row || empty($row['totp_enabled'])) {
            json_response(false, '2FA is user ke liye enabled nahi hai.');
        }

        // Check TOTP
        if (TOTP::verify($row['totp_secret'], $otp)) {
            audit_log('2fa_login_success', 'user', $loginId);
            json_response(true, 'OTP verified.', ['verified' => true]);
        }

        // Check backup codes
        $codes = json_decode($row['totp_backup_codes'] ?? '[]', true);
        $otpUpper = strtoupper(preg_replace('/\s+/', '', $otp));
        if (in_array($otpUpper, $codes, true)) {
            // Remove used backup code
            $remaining = array_values(array_filter($codes, fn($c) => $c !== $otpUpper));
            DB::run('UPDATE users SET totp_backup_codes = ? WHERE id = ?',
                [json_encode($remaining), $loginId]);
            audit_log('2fa_backup_code_used', 'user', $loginId);
            json_response(true, 'Backup code se login hua. Baaki ' . count($remaining) . ' codes remaining.', [
                'verified'         => true,
                'used_backup_code' => true,
                'remaining_codes'  => count($remaining),
            ]);
        }

        audit_log('2fa_login_failed', 'user', $loginId);
        json_response(false, 'OTP galat hai. Authenticator app check karo ya backup code use karo.');

    // ── DISABLE ─────────────────────────────────────────────────
    case '2fa_disable':
        csrf_verify();
        $otp = clean($_POST['otp'] ?? '');
        if (!$otp) json_response(false, 'Current OTP required to disable 2FA.');

        $row = get2faUser($userId);
        if (empty($row['totp_enabled'])) {
            json_response(false, '2FA pehle se disabled hai.');
        }

        if (!TOTP::verify($row['totp_secret'], $otp)) {
            json_response(false, 'OTP galat hai. 2FA disable nahi hua.');
        }

        DB::run('UPDATE users SET totp_enabled = 0, totp_secret = NULL, totp_backup_codes = NULL WHERE id = ?', [$userId]);
        audit_log('2fa_disabled', 'user', $userId);
        json_response(true, '2FA disable ho gaya.');

    // ── REGENERATE BACKUP CODES ──────────────────────────────────
    case '2fa_regen_backup':
        csrf_verify();
        $otp = clean($_POST['otp'] ?? '');
        $row = get2faUser($userId);

        if (empty($row['totp_enabled'])) {
            json_response(false, '2FA enabled nahi hai.');
        }
        if (!TOTP::verify($row['totp_secret'], $otp)) {
            json_response(false, 'OTP galat hai.');
        }

        $newCodes = generateBackupCodes();
        DB::run('UPDATE users SET totp_backup_codes = ? WHERE id = ?', [json_encode($newCodes), $userId]);
        audit_log('2fa_backup_regenerated', 'user', $userId);
        json_response(true, 'Naye backup codes generate ho gaye.', ['backup_codes' => $newCodes]);

    default:
        json_response(false, 'Unknown 2FA action.', [], 400);
}
