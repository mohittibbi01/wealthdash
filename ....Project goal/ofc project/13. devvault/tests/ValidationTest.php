<?php
/**
 * DevVault Pro — Input Validation Tests
 * Run: ./vendor/bin/phpunit tests/ValidationTest.php
 */

use PHPUnit\Framework\TestCase;

$_SERVER['PHP_SELF']    = '/tests/run.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config.php';

class ValidationTest extends TestCase
{
    // ── IP validation helper ──────────────────────────────────────────────────
    private function is_valid_ip(string $ip): bool
    {
        $ip_clean = preg_replace('/\/\d+$/', '', $ip);
        return (bool)filter_var($ip_clean, FILTER_VALIDATE_IP);
    }

    public function test_valid_ipv4(): void
    {
        $this->assertTrue($this->is_valid_ip('192.168.1.1'));
    }

    public function test_valid_ipv4_with_cidr(): void
    {
        $this->assertTrue($this->is_valid_ip('10.0.0.0/24'));
    }

    public function test_invalid_ip(): void
    {
        $this->assertFalse($this->is_valid_ip('999.999.999.999'));
        $this->assertFalse($this->is_valid_ip('not-an-ip'));
    }

    // ── Email validation ──────────────────────────────────────────────────────
    public function test_valid_email(): void
    {
        $this->assertNotFalse(filter_var('admin@gov.in', FILTER_VALIDATE_EMAIL));
    }

    public function test_invalid_email(): void
    {
        $this->assertFalse(filter_var('notanemail', FILTER_VALIDATE_EMAIL));
        $this->assertFalse(filter_var('missing@', FILTER_VALIDATE_EMAIL));
    }

    // ── Phone validation ──────────────────────────────────────────────────────
    private function is_valid_phone(string $phone): bool
    {
        $clean = preg_replace('/[\s\-\(\)\+]/', '', $phone);
        return (bool)preg_match('/^\d{7,15}$/', $clean);
    }

    public function test_valid_phone(): void
    {
        $this->assertTrue($this->is_valid_phone('9876543210'));
        $this->assertTrue($this->is_valid_phone('+91-9876543210'));
        $this->assertTrue($this->is_valid_phone('(011) 2345-6789'));
    }

    public function test_invalid_phone(): void
    {
        $this->assertFalse($this->is_valid_phone('123'));       // too short
        $this->assertFalse($this->is_valid_phone('abcdefghij'));// not digits
    }

    // ── Date validation ───────────────────────────────────────────────────────
    private function is_valid_date(string $date): bool
    {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    public function test_valid_date(): void
    {
        $this->assertTrue($this->is_valid_date('2024-01-15'));
        $this->assertTrue($this->is_valid_date('2000-12-31'));
    }

    public function test_invalid_date(): void
    {
        $this->assertFalse($this->is_valid_date('15-01-2024')); // wrong format
        $this->assertFalse($this->is_valid_date('2024-13-01')); // invalid month
        $this->assertFalse($this->is_valid_date('notadate'));
    }

    // ── URL validation ────────────────────────────────────────────────────────
    public function test_valid_url(): void
    {
        $this->assertNotFalse(filter_var('https://gov.in/app', FILTER_VALIDATE_URL));
        $this->assertNotFalse(filter_var('http://192.168.1.1:8080', FILTER_VALIDATE_URL));
    }

    public function test_invalid_url(): void
    {
        $this->assertFalse(filter_var('notaurl', FILTER_VALIDATE_URL));
        $this->assertFalse(filter_var('ftp//missing-colon', FILTER_VALIDATE_URL));
    }

    // ── CSS injection sanitization ───────────────────────────────────────────
    public function test_accent_color_sanitized(): void
    {
        $malicious = '#00d4ff}body{background:red';
        $safe = preg_replace('/[^#a-fA-F0-9]/', '', $malicious);
        $this->assertSame('#00d4ffbodybgroundred', $safe, 'Non-hex chars must be stripped');
        $this->assertStringNotContainsString('}', $safe);
    }

    public function test_font_size_clamped(): void
    {
        $this->assertSame(18, max(11, min(18, 999)));  // too large → 18
        $this->assertSame(11, max(11, min(18, 1)));    // too small → 11
        $this->assertSame(14, max(11, min(18, 14)));   // valid → unchanged
    }

    public function test_font_family_whitelist(): void
    {
        $allowed = ['Rajdhani', 'Share Tech Mono', 'Orbitron'];
        $this->assertContains('Rajdhani', $allowed);
        $this->assertNotContains('Arial; background: red', $allowed);
    }
}
