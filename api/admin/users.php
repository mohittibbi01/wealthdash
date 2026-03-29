<?php
/**
 * WealthDash — Admin: User Management API
 * Actions: admin_users | admin_add_user | admin_toggle_user | admin_delete_user | admin_change_role
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');
if (!is_admin()) json_response(false, 'Admin access required.', [], 403);

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (!function_exists('safe_count')) {
    function safe_count($sql) { try { return (int) DB::fetchVal($sql); } catch (Exception $e) { return 0; } }
    function safe_val($sql)   { try { return DB::fetchVal($sql); }       catch (Exception $e) { return null; } }
}

switch ($action) {

    // ── List all users ────────────────────────────────────────
    case 'admin_users':
        $users = DB::fetchAll(
            "SELECT u.id, u.name, u.email, u.mobile, u.role, u.status,
                    u.is_senior_citizen, u.theme, u.last_login_at, u.login_count,
                    u.created_at,
                    COUNT(DISTINCT p.id) AS portfolio_count,
                    COUNT(DISTINCT mh.id) AS mf_holdings_count
             FROM users u
             LEFT JOIN portfolios p ON p.user_id = u.id
             LEFT JOIN mf_holdings mh ON mh.portfolio_id = p.id AND mh.is_active = 1
             GROUP BY u.id
             ORDER BY u.created_at DESC"
        );
        json_response(true, '', ['users' => $users]);

    // ── Add new user ──────────────────────────────────────────
    case 'admin_add_user':
        csrf_verify();
        $name   = clean($_POST['name']   ?? '');
        $email  = clean($_POST['email']  ?? '');
        $mobile = clean($_POST['mobile'] ?? '');
        $role   = in_array($_POST['role'] ?? '', ['admin','member']) ? $_POST['role'] : 'member';
        $isSenior = (int) ($_POST['is_senior_citizen'] ?? 0);
        $password = $_POST['password'] ?? '';

        if (!$name || !$email || !$password) {
            json_response(false, 'Name, email, and password are required.');
        }
        if (!valid_email($email)) {
            json_response(false, 'Invalid email address.');
        }
        if (strlen($password) < 8) {
            json_response(false, 'Password must be at least 8 characters.');
        }

        $exists = DB::fetchVal('SELECT id FROM users WHERE email = ?', [$email]);
        if ($exists) json_response(false, 'Email already registered.');

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        DB::run(
            'INSERT INTO users (name, email, password_hash, mobile, role, is_senior_citizen, status)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$name, $email, $hash, $mobile ?: null, $role, $isSenior, 'active']
        );
        $newId = (int) DB::lastInsertId();

        // Create default portfolio for new user
        DB::run(
            "INSERT INTO portfolios (user_id, name) VALUES (?, 'My Portfolio')",
            [$newId]
        );

        audit_log('admin_add_user', 'user', $newId, [], ['email' => $email, 'role' => $role]);
        json_response(true, "User '{$name}' created successfully.", ['user_id' => $newId]);

    // ── Toggle user active/inactive ───────────────────────────
    case 'admin_toggle_user':
        csrf_verify();
        $targetId = (int) ($_POST['user_id'] ?? 0);
        if (!$targetId) json_response(false, 'Invalid user ID.');

        // Cannot disable yourself
        if ($targetId === (int) $_SESSION['user_id']) {
            json_response(false, 'You cannot disable your own account.');
        }

        $user = DB::fetchOne('SELECT id, name, status FROM users WHERE id = ?', [$targetId]);
        if (!$user) json_response(false, 'User not found.');

        $newStatus = $user['status'] === 'active' ? 'inactive' : 'active';
        DB::run('UPDATE users SET status = ? WHERE id = ?', [$newStatus, $targetId]);

        $actionLabel = $newStatus === 'active' ? 'enabled' : 'disabled';
        audit_log('admin_toggle_user', 'user', $targetId,
            ['status' => $user['status']], ['status' => $newStatus]);
        json_response(true, "User {$actionLabel} successfully.",
            ['new_status' => $newStatus]);

    // ── Change user role ──────────────────────────────────────
    case 'admin_change_role':
        csrf_verify();
        $targetId = (int) ($_POST['user_id'] ?? 0);
        $newRole  = in_array($_POST['role'] ?? '', ['admin','member']) ? $_POST['role'] : null;
        if (!$targetId || !$newRole) json_response(false, 'Invalid parameters.');
        if ($targetId === (int) $_SESSION['user_id']) {
            json_response(false, 'You cannot change your own role.');
        }

        $old = DB::fetchOne('SELECT role FROM users WHERE id = ?', [$targetId]);
        if (!$old) json_response(false, 'User not found.');

        DB::run('UPDATE users SET role = ? WHERE id = ?', [$newRole, $targetId]);
        audit_log('admin_change_role', 'user', $targetId,
            ['role' => $old['role']], ['role' => $newRole]);
        json_response(true, 'Role updated.', ['new_role' => $newRole]);

    // ── Reset user password ───────────────────────────────────
    case 'admin_reset_password':
        csrf_verify();
        $targetId    = (int) ($_POST['user_id'] ?? 0);
        $newPassword = $_POST['new_password'] ?? '';
        if (!$targetId || strlen($newPassword) < 8) {
            json_response(false, 'User ID and password (min 8 chars) required.');
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
        DB::run('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $targetId]);
        audit_log('admin_reset_password', 'user', $targetId);
        json_response(true, 'Password reset successfully.');

    // ── Delete user (soft – set status=deleted) ───────────────
    case 'admin_delete_user':
        csrf_verify();
        $targetId = (int) ($_POST['user_id'] ?? 0);
        if (!$targetId) json_response(false, 'Invalid user ID.');
        if ($targetId === (int) $_SESSION['user_id']) {
            json_response(false, 'You cannot delete your own account.');
        }

        // Check if last admin
        $adminCount = (int) DB::fetchVal(
            "SELECT COUNT(*) FROM users WHERE role='admin' AND status='active' AND id != ?",
            [$targetId]
        );
        $targetRole = DB::fetchVal('SELECT role FROM users WHERE id = ?', [$targetId]);
        if ($targetRole === 'admin' && $adminCount === 0) {
            json_response(false, 'Cannot delete the last admin user.');
        }

        $suffix = '_del_' . time();
        DB::run("UPDATE users SET status='deleted', email=CONCAT(email, ?) WHERE id=?", [$suffix, $targetId]);
        audit_log('admin_delete_user', 'user', $targetId);
        json_response(true, 'User deleted.');

    // ── System stats ──────────────────────────────────────────
    case 'admin_stats':
        $stats = [
            'users'            => safe_count("SELECT COUNT(*) FROM users WHERE status='active'"),
            'admin_count'      => safe_count("SELECT COUNT(*) FROM users WHERE status='active' AND role='admin'"),
            'member_count'     => safe_count("SELECT COUNT(*) FROM users WHERE status='active' AND role='member'"),
            'total_users'      => safe_count("SELECT COUNT(*) FROM users"),
            'portfolios'       => safe_count("SELECT COUNT(*) FROM portfolios"),
            'funds'            => safe_count("SELECT COUNT(*) FROM funds"),
            'fund_houses'      => safe_count("SELECT COUNT(*) FROM fund_houses"),
            'mf_holdings'      => safe_count("SELECT COUNT(*) FROM mf_holdings WHERE is_active=1"),
            'mf_txns'          => safe_count("SELECT COUNT(*) FROM mf_transactions"),
            'stock_holdings'   => safe_count("SELECT COUNT(*) FROM stock_holdings WHERE is_active=1"),
            'fd_accounts'      => safe_count("SELECT COUNT(*) FROM fd_accounts WHERE status='active'"),
            'savings_accs'     => safe_count("SELECT COUNT(*) FROM savings_accounts WHERE is_active=1"),
            'nav_last_updated'        => safe_val("SELECT setting_val FROM app_settings WHERE setting_key='nav_last_updated'"),
            'ter_last_updated'        => safe_val("SELECT setting_val FROM app_settings WHERE setting_key='ter_last_updated'"),
            'exit_load_last_updated'  => safe_val("SELECT setting_val FROM app_settings WHERE setting_key='exit_load_last_updated'"),
            'stocks_last_updated'     => safe_val("SELECT setting_val FROM app_settings WHERE setting_key='stocks_last_updated'"),
            'last_recalc_holdings'    => safe_val("SELECT setting_val FROM app_settings WHERE setting_key='last_recalc_holdings'"),
            'import_amfi_last'        => safe_val("SELECT setting_val FROM app_settings WHERE setting_key='import_amfi_last_updated'"),
            'peak_nav_last'           => safe_val("SELECT setting_val FROM app_settings WHERE setting_key='peak_nav_last_completed'"),
            'nav_dl_last'             => safe_val("SELECT setting_val FROM app_settings WHERE setting_key='nav_history_last_run'"),
            'nps_nav_last'            => safe_val("SELECT setting_val FROM app_settings WHERE setting_key='nps_nav_last_run'"),
            'nps_nav_status'          => safe_val("SELECT setting_val FROM app_settings WHERE setting_key='nps_nav_last_status'"),
            'audit_log_count'         => safe_count("SELECT COUNT(*) FROM audit_log"),
            'nps_holdings'            => safe_count("SELECT COUNT(*) FROM nps_holdings WHERE is_active=1"),
            'goals'                   => safe_count("SELECT COUNT(*) FROM investment_goals"),
            'insurance'               => safe_count("SELECT COUNT(*) FROM insurance_policies WHERE status='active'"),
            'post_office'             => safe_count("SELECT COUNT(*) FROM post_office_investments WHERE is_active=1"),
        ];
        json_response(true, '', ['stats' => $stats]);

    default:
        json_response(false, 'Unknown admin action.', [], 400);
}