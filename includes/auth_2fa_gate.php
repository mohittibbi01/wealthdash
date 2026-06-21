<?php
/**
 * WealthDash — 2FA Auth Gate Middleware
 * t386: Include AFTER the normal auth_check.php in protected pages.
 *
 * If the user has 2FA enabled and hasn't passed the 2FA challenge
 * in this session, redirect them to the 2FA challenge page.
 *
 * Usage in auth_check.php (add after is_logged_in() check):
 *   require_once APP_ROOT . '/includes/auth_2fa_gate.php';
 */
defined('WEALTHDASH') or die();

if (is_logged_in()) {
    $uid    = (int) ($_SESSION['user_id'] ?? 0);
    $passed = $_SESSION['2fa_passed'] ?? false;

    if (!$passed) {
        // Check if this user has 2FA enabled (cache in session)
        if (!isset($_SESSION['2fa_required'])) {
            $enabled = (bool) DB::fetchVal('SELECT totp_enabled FROM users WHERE id = ?', [$uid]);
            $_SESSION['2fa_required'] = $enabled;
        }

        if ($_SESSION['2fa_required']) {
            // Create a totp_session challenge token
            require_once APP_ROOT . '/includes/totp.php';
            $token = bin2hex(random_bytes(32));
            DB::run(
                'INSERT INTO totp_sessions (user_id, token, ip, user_agent, expires_at)
                 VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))',
                [
                    $uid,
                    $token,
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                ]
            );
            // Destroy partial session — user must re-verify
            session_destroy();
            session_start();
            $redirect = APP_URL . '?page=2fa_challenge&t=' . urlencode($token);
            header('Location: ' . $redirect);
            exit;
        }

        // No 2FA required — mark as passed so we don't hit DB every request
        $_SESSION['2fa_passed'] = true;
    }
}
