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
 * Get portfolios accessible to current user
 * Admin sees all; member sees own + shared
 */
function get_user_portfolios(int $userId, bool $isAdmin = false): array {
    if ($isAdmin) {
        return DB::fetchAll(
            'SELECT p.*, u.name as owner_name
             FROM portfolios p
             JOIN users u ON u.id = p.user_id
             ORDER BY p.user_id, p.is_default DESC, p.name',
        );
    }

    return DB::fetchAll(
        'SELECT DISTINCT p.*, u.name as owner_name,
                pm.can_edit,
                (p.user_id = :uid) as is_owner
         FROM portfolios p
         JOIN users u ON u.id = p.user_id
         LEFT JOIN portfolio_members pm ON pm.portfolio_id = p.id AND pm.user_id = :uid2
         WHERE p.user_id = :uid3 OR pm.user_id = :uid4
         ORDER BY is_owner DESC, p.is_default DESC, p.name',
        [':uid' => $userId, ':uid2' => $userId, ':uid3' => $userId, ':uid4' => $userId]
    );
}

/**
 * Verify user can access a specific portfolio
 */
function can_access_portfolio(int $portfolioId, int $userId, bool $isAdmin = false): bool {
    if ($isAdmin) return true;

    $row = DB::fetchOne(
        'SELECT p.id FROM portfolios p
         LEFT JOIN portfolio_members pm ON pm.portfolio_id = p.id AND pm.user_id = ?
         WHERE p.id = ? AND (p.user_id = ? OR pm.user_id = ?)',
        [$userId, $portfolioId, $userId, $userId]
    );
    return $row !== false;
}

/**
 * Verify user can EDIT a specific portfolio
 */
function can_edit_portfolio(int $portfolioId, int $userId, bool $isAdmin = false): bool {
    if ($isAdmin) return true;

    $row = DB::fetchOne(
        'SELECT p.id, (p.user_id = :uid) as is_owner, pm.can_edit
         FROM portfolios p
         LEFT JOIN portfolio_members pm ON pm.portfolio_id = p.id AND pm.user_id = :uid2
         WHERE p.id = :pid',
        [':uid' => $userId, ':uid2' => $userId, ':pid' => $portfolioId]
    );

    if (!$row) return false;
    return $row['is_owner'] || $row['can_edit'];
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

