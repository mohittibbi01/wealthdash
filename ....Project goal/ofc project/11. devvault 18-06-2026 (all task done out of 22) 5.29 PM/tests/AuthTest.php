<?php
/**
 * DevVault Pro — Auth & CSRF Tests
 * Run: ./vendor/bin/phpunit tests/AuthTest.php
 */

use PHPUnit\Framework\TestCase;

$_SERVER['PHP_SELF']    = '/tests/run.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

// Start session for auth tests
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../config.php';

class AuthTest extends TestCase
{
    protected function setUp(): void
    {
        // Reset session before each test
        $_SESSION = [];
    }

    // ── can_edit: admin returns true ─────────────────────────────────────────
    public function test_can_edit_returns_true_for_admin(): void
    {
        $_SESSION['role'] = 'admin';
        $this->assertTrue(can_edit());
    }

    // ── can_edit: member returns true ────────────────────────────────────────
    public function test_can_edit_returns_true_for_member(): void
    {
        $_SESSION['role'] = 'member';
        $this->assertTrue(can_edit());
    }

    // ── can_edit: viewer returns false ───────────────────────────────────────
    public function test_can_edit_returns_false_for_viewer(): void
    {
        $_SESSION['role'] = 'viewer';
        $this->assertFalse(can_edit());
    }

    // ── can_edit: no role returns false ──────────────────────────────────────
    public function test_can_edit_returns_false_with_no_role(): void
    {
        $this->assertFalse(can_edit());
    }

    // ── CSRF token generation ─────────────────────────────────────────────────
    public function test_csrf_token_is_generated_and_stored(): void
    {
        $token = csrf_token();
        $this->assertNotEmpty($token, 'csrf_token() must return non-empty string');
        $this->assertEquals(64, strlen($token), 'CSRF token must be 64 hex chars (32 bytes)');
        $this->assertSame($token, $_SESSION['csrf'], 'Token must be stored in session');
    }

    // ── CSRF token same on repeated calls ────────────────────────────────────
    public function test_csrf_token_same_within_session(): void
    {
        $t1 = csrf_token();
        $t2 = csrf_token();
        $this->assertSame($t1, $t2, 'csrf_token() must return same token within session');
    }

    // ── verify_csrf: valid token passes ──────────────────────────────────────
    public function test_verify_csrf_passes_with_valid_token(): void
    {
        $token = csrf_token();
        $_POST['csrf'] = $token;
        $this->assertTrue(verify_csrf());
    }

    // ── verify_csrf: wrong token fails ───────────────────────────────────────
    public function test_verify_csrf_fails_with_wrong_token(): void
    {
        csrf_token(); // generate real token
        $_POST['csrf'] = 'wrongtoken';
        $this->assertFalse(verify_csrf());
    }

    // ── verify_csrf: missing token fails ─────────────────────────────────────
    public function test_verify_csrf_fails_with_missing_token(): void
    {
        csrf_token();
        unset($_POST['csrf']);
        $this->assertFalse(verify_csrf());
    }

    // ── user_pref: returns default when no prefs ──────────────────────────────
    public function test_user_pref_returns_default(): void
    {
        unset($_SESSION['prefs']);
        $this->assertSame('#00d4ff', user_pref('accent', '#00d4ff'));
    }

    // ── user_pref: returns stored value ───────────────────────────────────────
    public function test_user_pref_returns_stored_value(): void
    {
        $_SESSION['prefs']['accent'] = '#ff0000';
        $this->assertSame('#ff0000', user_pref('accent', '#00d4ff'));
    }
}
