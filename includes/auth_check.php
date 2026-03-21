<?php
/**
 * WealthDash — Auth Check Middleware
 * Include at top of every protected page
 */
declare(strict_types=1);

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

/**
 * Require authenticated user.
 * Optionally require a specific role.
 * Redirects to login if not authenticated.
 */
function require_auth(string $requiredRole = ''): array {
    if (empty($_SESSION['user_id'])) {
        $_SESSION['_redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        flash_set('error', 'Please log in to continue.');
        redirect('auth/login.php');
    }

    // Check session expiry
    $sessionTimeout = (int) env('SESSION_LIFETIME', 86400);
    if (isset($_SESSION['_last_activity']) && (time() - $_SESSION['_last_activity']) > $sessionTimeout) {
        session_unset();
        session_destroy();
        flash_set('error', 'Your session has expired. Please log in again.');
        redirect('auth/login.php');
    }
    $_SESSION['_last_activity'] = time();

    // Fetch fresh user data
    $user = DB::fetchOne('SELECT * FROM users WHERE id = ? AND status = ?', [
        $_SESSION['user_id'],
        'active',
    ]);

    if (!$user) {
        session_unset();
        session_destroy();
        redirect('auth/login.php');
    }

    // Role check
    if ($requiredRole && $user['role'] !== $requiredRole && $user['role'] !== ROLE_ADMIN) {
        http_response_code(403);
        include APP_ROOT . '/templates/pages/403.php';
        exit;
    }

    // Update session with current user data
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_name']  = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['user_theme'] = $user['theme'];

    return $user;
}

/**
 * Require admin role
 */
function require_admin(): array {
    return require_auth(ROLE_ADMIN);
}

/**
 * Check if logged in (without redirect)
 */
function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

/**
 * Check if current user is admin
 */
function is_admin(): bool {
    return ($_SESSION['user_role'] ?? '') === ROLE_ADMIN;
}

/**
 * Get the single portfolio for a user.
 * Returns array with one portfolio row, or empty array.
 */
function get_user_portfolios(int $userId, bool $isAdmin = false): array {
    $row = DB::fetchOne(
        'SELECT p.id, p.user_id, p.name, p.created_at, u.name as owner_name, 1 as is_owner, 1 as can_edit
         FROM portfolios p
         JOIN users u ON u.id = p.user_id
         WHERE p.user_id = ?',
        [$userId]
    );
    return $row ? [$row] : [];
}

/**
 * Get the portfolio_id for a user directly.
 */
function get_user_portfolio_id(int $userId): int {
    $id = DB::fetchVal('SELECT id FROM portfolios WHERE user_id = ?', [$userId]);
    return $id ? (int)$id : 0;
}

/**
 * Verify user can access a specific portfolio (must own it).
 */
function can_access_portfolio(int $portfolioId, int $userId, bool $isAdmin = false): bool {
    if ($isAdmin) {
        return (bool) DB::fetchOne('SELECT id FROM portfolios WHERE id = ?', [$portfolioId]);
    }
    $row = DB::fetchOne(
        'SELECT id FROM portfolios WHERE id = ? AND user_id = ?',
        [$portfolioId, $userId]
    );
    return $row !== false;
}

/**
 * Verify user can edit a specific portfolio (must own it).
 */
function can_edit_portfolio(int $portfolioId, int $userId, bool $isAdmin = false): bool {
    if ($isAdmin) return true;
    return can_access_portfolio($portfolioId, $userId, false);
}

/**
 * Rate limiting — check login attempts
 */
function check_rate_limit(string $ip, string $email): bool {
    $maxAttempts = (int) env('LOGIN_MAX_ATTEMPTS', 5);
    $window      = (int) env('LOGIN_LOCKOUT_MINUTES', 15) * 60;
    $since       = date('Y-m-d H:i:s', time() - $window);

    $count = (int) DB::fetchVal(
        'SELECT COUNT(*) FROM login_attempts
         WHERE (ip_address = ? OR email = ?) AND attempted_at > ? AND success = 0',
        [$ip, $email, $since]
    );

    return $count < $maxAttempts;
}

/**
 * Log login attempt
 */
function log_login_attempt(string $ip, string $email, bool $success): void {
    DB::run(
        'INSERT INTO login_attempts (ip_address, email, success) VALUES (?, ?, ?)',
        [$ip, $email, $success ? 1 : 0]
    );
}