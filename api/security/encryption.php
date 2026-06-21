<?php
/**
 * WealthDash — t388: Data Encryption
 * AES-256-GCM encryption for sensitive fields (account numbers, PAN, etc.)
 * Uses APP_SECRET from .env as master key.
 *
 * Usage:
 *   $enc = WDCrypt::encrypt('SBI12345');
 *   $dec = WDCrypt::decrypt($enc);
 *   $masked = WDCrypt::mask('ABCDE1234F');  // → ABCDE****F
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

class WDCrypt {

    const CIPHER = 'aes-256-gcm';
    const TAG_LEN = 16;

    /** Derive a 32-byte key from APP_SECRET via HKDF */
    private static function key(): string {
        $secret = $_ENV['APP_SECRET'] ?? getenv('APP_SECRET') ?? 'wealthdash_default_secret_change_me';
        return hash('sha256', 'wealthdash-encrypt-v1:' . $secret, true);
    }

    /**
     * Encrypt a string. Returns base64-encoded: iv(12) + tag(16) + ciphertext
     * Returns null on empty input.
     */
    static function encrypt(?string $plaintext): ?string {
        if ($plaintext === null || $plaintext === '') return null;

        $iv  = random_bytes(12);
        $tag = '';
        $enc = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);

        if ($enc === false) {
            throw new RuntimeException('WDCrypt: Encryption failed.');
        }

        return base64_encode($iv . $tag . $enc);
    }

    /**
     * Decrypt a base64-encoded ciphertext produced by encrypt().
     * Returns null on failure.
     */
    static function decrypt(?string $ciphertext): ?string {
        if ($ciphertext === null || $ciphertext === '') return null;

        $raw = base64_decode($ciphertext, true);
        if ($raw === false || strlen($raw) < 12 + self::TAG_LEN + 1) return null;

        $iv  = substr($raw, 0, 12);
        $tag = substr($raw, 12, self::TAG_LEN);
        $enc = substr($raw, 12 + self::TAG_LEN);

        $dec = openssl_decrypt($enc, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $dec === false ? null : $dec;
    }

    /**
     * Mask a string for display (shows first 3 and last 2 chars)
     * Examples:
     *   PAN:     ABCDE1234F → ABC*****4F
     *   IBAN:    IN12345678 → IN1*****78
     *   Account: 9876543210 → 987*****10
     */
    static function mask(?string $value, int $showStart = 3, int $showEnd = 2): string {
        if (!$value) return '••••••';
        $len = mb_strlen($value);
        if ($len <= $showStart + $showEnd) {
            return str_repeat('*', $len);
        }
        $stars = str_repeat('*', max(4, $len - $showStart - $showEnd));
        return mb_substr($value, 0, $showStart) . $stars . mb_substr($value, -$showEnd);
    }

    /**
     * Hash a value deterministically (for searching encrypted fields)
     * Use this to store a searchable hash alongside encrypted data.
     */
    static function searchHash(?string $value): ?string {
        if ($value === null || $value === '') return null;
        return hash_hmac('sha256', strtolower(trim($value)), self::key());
    }

    /**
     * Encrypt an associative array of fields.
     * Returns same array with specified fields encrypted.
     *
     * Example:
     *   $safe = WDCrypt::encryptFields($data, ['pan', 'account_number', 'ifsc']);
     */
    static function encryptFields(array $data, array $fields): array {
        foreach ($fields as $f) {
            if (isset($data[$f])) {
                $data[$f . '_search'] = self::searchHash($data[$f]);
                $data[$f]             = self::encrypt($data[$f]);
            }
        }
        return $data;
    }

    /**
     * Decrypt an associative array of fields.
     */
    static function decryptFields(array $data, array $fields): array {
        foreach ($fields as $f) {
            if (isset($data[$f])) {
                $data[$f] = self::decrypt($data[$f]) ?? $data[$f];
            }
        }
        return $data;
    }

    /**
     * Mask fields for API response (don't expose sensitive values)
     */
    static function maskFields(array $data, array $fields, int $showStart = 3, int $showEnd = 2): array {
        foreach ($fields as $f) {
            if (isset($data[$f])) {
                $plain    = self::decrypt($data[$f]) ?? $data[$f];
                $data[$f] = self::mask($plain, $showStart, $showEnd);
            }
        }
        return $data;
    }
}

// ── Fields to encrypt per table (reference) ────────────────────
/*
 TABLE: users
   - mobile          (encrypt + searchhash)
   - pan_number      (encrypt + searchhash)

 TABLE: bank_accounts
   - account_number  (encrypt + searchhash)
   - ifsc_code       (encrypt)

 TABLE: fd_investments
   - account_number  (encrypt)

 TABLE: insurance_policies
   - policy_number   (encrypt)
   - premium_account (encrypt)

 MIGRATION SQL (run once):
   ALTER TABLE users
     ADD COLUMN mobile_search VARCHAR(64) DEFAULT NULL,
     ADD COLUMN pan_number VARCHAR(500) DEFAULT NULL,
     ADD COLUMN pan_search VARCHAR(64) DEFAULT NULL;

   CREATE INDEX idx_pan_search ON users (pan_search);
   CREATE INDEX idx_mobile_search ON users (mobile_search);
*/
