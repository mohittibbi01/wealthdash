<?php
/**
 * DevVault Pro — Encryption Tests
 * Run: ./vendor/bin/phpunit tests/EncryptionTest.php
 */

use PHPUnit\Framework\TestCase;

// Bootstrap: load config without starting web session
$_SERVER['PHP_SELF']    = '/tests/run.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once __DIR__ . '/../config.php';

class EncryptionTest extends TestCase
{
    // ── Round-trip ────────────────────────────────────────────────────────────
    public function test_encrypt_decrypt_roundtrip(): void
    {
        $original = 'MyS3cur3P@ssw0rd!';
        $cipher   = encrypt_val($original);
        $decoded  = decrypt_val($cipher);
        $this->assertSame($original, $decoded, 'Decrypted value must match original');
    }

    // ── Empty string ──────────────────────────────────────────────────────────
    public function test_empty_string_returns_empty(): void
    {
        $this->assertSame('', encrypt_val(''), 'encrypt_val("") must return ""');
        $this->assertSame('', decrypt_val(''), 'decrypt_val("") must return ""');
    }

    // ── Random IV — same plaintext, different ciphertext each time ────────────
    public function test_same_plaintext_produces_different_ciphertext(): void
    {
        $plain  = 'SamePlaintext';
        $cipher1 = encrypt_val($plain);
        $cipher2 = encrypt_val($plain);
        $this->assertNotSame($cipher1, $cipher2, 'Each encryption must produce unique ciphertext (random IV)');
    }

    // ── v2 format marker ──────────────────────────────────────────────────────
    public function test_new_ciphertext_uses_v2_format(): void
    {
        $cipher = encrypt_val('test');
        $raw    = base64_decode($cipher);
        $this->assertStringStartsWith('v2:', $raw, 'New ciphertext must start with "v2:" marker');
    }

    // ── Corrupt cipher returns empty string (no crash) ────────────────────────
    public function test_corrupt_cipher_returns_empty(): void
    {
        $result = decrypt_val('totallynotvalidbase64!!!@@##');
        $this->assertSame('', $result, 'Corrupt cipher must return empty string, not throw');
    }

    // ── Partial corrupt base64 ────────────────────────────────────────────────
    public function test_partial_corrupt_cipher(): void
    {
        $result = decrypt_val(base64_encode('v2:badhex:badenc'));
        $this->assertSame('', $result, 'Bad hex IV must return empty string');
    }

    // ── Unicode passphrase ────────────────────────────────────────────────────
    public function test_unicode_value_roundtrip(): void
    {
        $original = 'पासवर्ड123 — Sécurité!';
        $this->assertSame($original, decrypt_val(encrypt_val($original)));
    }

    // ── Long value ───────────────────────────────────────────────────────────
    public function test_long_value_roundtrip(): void
    {
        $original = str_repeat('AbCd1234!@#$', 50); // 600 chars
        $this->assertSame($original, decrypt_val(encrypt_val($original)));
    }

    // ── Legacy format backward compatibility ──────────────────────────────────
    public function test_legacy_format_decrypts_correctly(): void
    {
        // Create a legacy-format cipher manually (old method)
        $plain = 'LegacyPassword123';
        $iv    = random_bytes(16);
        $enc   = openssl_encrypt($plain, 'AES-256-CBC', ENCRYPT_KEY, 0, $iv);
        $legacy_cipher = base64_encode($iv . '::' . $enc);

        $decoded = decrypt_val($legacy_cipher);
        $this->assertSame($plain, $decoded, 'Legacy format must still decrypt correctly');
    }
}
