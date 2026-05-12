<?php
/**
 * WealthDash — TOTP (RFC 6238) Helper
 * Zero external dependencies — pure PHP.
 * t386: 2FA Google Authenticator
 */
defined('WEALTHDASH') or die();

class TOTP
{
    private const DIGITS  = 6;
    private const PERIOD  = 30;  // seconds
    private const ALGO    = 'sha1';
    private const WINDOW  = 1;   // ±1 period tolerance

    // ── Base32 alphabet ──────────────────────────────────────
    private const B32CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Generate a random 20-byte (160-bit) Base32 secret */
    public static function generateSecret(): string
    {
        $bytes  = random_bytes(20);
        return self::base32Encode($bytes);
    }

    /** Generate 8 single-use backup codes */
    public static function generateBackupCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            // Format: XXXX-XXXX (easy to read)
            $codes[] = strtoupper(bin2hex(random_bytes(4)));
        }
        return $codes;
    }

    /** Hash backup codes for storage */
    public static function hashBackupCodes(array $codes): string
    {
        $hashed = array_map(fn($c) => password_hash(strtoupper($c), PASSWORD_BCRYPT), $codes);
        return json_encode($hashed);
    }

    /** Verify a backup code (consumes it from the JSON list) */
    public static function verifyBackupCode(string $input, string &$storedJson): bool
    {
        $hashed = json_decode($storedJson, true);
        if (!is_array($hashed)) return false;
        $input = strtoupper(trim($input));
        foreach ($hashed as $i => $hash) {
            if (password_verify($input, $hash)) {
                unset($hashed[$i]);
                $storedJson = json_encode(array_values($hashed));
                return true;
            }
        }
        return false;
    }

    /** Verify a 6-digit TOTP code */
    public static function verify(string $secret, string $code): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== self::DIGITS) return false;
        $key  = self::base32Decode($secret);
        $time = (int) floor(time() / self::PERIOD);
        for ($t = $time - self::WINDOW; $t <= $time + self::WINDOW; $t++) {
            if (hash_equals(self::hotp($key, $t), $code)) return true;
        }
        return false;
    }

    /** Build the otpauth:// URI for QR code rendering */
    public static function getUri(string $secret, string $accountName, string $issuer = 'WealthDash'): string
    {
        return 'otpauth://totp/'
            . rawurlencode($issuer) . ':' . rawurlencode($accountName)
            . '?secret=' . $secret
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    // ── Internal ─────────────────────────────────────────────

    private static function hotp(string $key, int $counter): string
    {
        $msg  = pack('J', $counter);          // big-endian 64-bit
        $hash = hash_hmac(self::ALGO, $msg, $key, true);
        $off  = ord($hash[19]) & 0x0F;
        $otp  = (
            ((ord($hash[$off])   & 0x7F) << 24) |
            ((ord($hash[$off+1]) & 0xFF) << 16) |
            ((ord($hash[$off+2]) & 0xFF) <<  8) |
             (ord($hash[$off+3]) & 0xFF)
        ) % (10 ** self::DIGITS);
        return str_pad((string)$otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $data): string
    {
        $chars  = self::B32CHARS;
        $out    = '';
        $bits   = 0;
        $val    = 0;
        foreach (str_split($data) as $c) {
            $val  = ($val << 8) | ord($c);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out  .= $chars[($val >> $bits) & 0x1F];
            }
        }
        if ($bits > 0) $out .= $chars[($val << (5 - $bits)) & 0x1F];
        while (strlen($out) % 8 !== 0) $out .= '=';
        return $out;
    }

    private static function base32Decode(string $data): string
    {
        $data  = strtoupper(rtrim($data, '='));
        $chars = self::B32CHARS;
        $out   = '';
        $bits  = 0;
        $val   = 0;
        foreach (str_split($data) as $c) {
            $pos = strpos($chars, $c);
            if ($pos === false) continue;
            $val  = ($val << 5) | $pos;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out  .= chr(($val >> $bits) & 0xFF);
            }
        }
        return $out;
    }
}
