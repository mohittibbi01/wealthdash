<?php
/**
 * WealthDash — t50: Multi-User Management
 * File: api/admin/users.php
 * Actions: admin_users, admin_user_detail, admin_user_create,
 *          admin_user_update, admin_user_toggle, admin_user_delete,
 *          admin_user_reset_password
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
if (!$isAdmin) json_response(false, 'Admin access required.', [], 403);

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');

switch ($action) {

    case 'admin_users': {
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 25;
        $offset = ($page - 1) * $limit;
        $search = clean($_GET['search'] ?? '');
        $role   = clean($_GET['role']   ?? '');
        $status = clean($_GET['status'] ?? '');

        $where = ['1=1']; $params = [];
        if ($search) { $where[] = '(u.name LIKE ? OR u.email LIKE ?)'; $params[] = "%$search%"; $params[] = "%$search%"; }
        if ($role)   { $where[] = 'u.role=?';   $params[] = $role;   }
        if ($status) { $where[] = 'u.status=?'; $params[] = $status; }
        $w = implode(' AND ', $where);

        $total = (int)DB::fetchVal("SELECT COUNT(*) FROM users u WHERE $w", $params);
        $rows  = DB::fetchAll(
            "SELECT u.id, u.name, u.email, u.role, u.status, u.created_at,
                    u.last_login_at, COALESCE(u.totp_enabled,0) AS totp_enabled,
                    COUNT(DISTINCT p.id) AS portfolio_count
             FROM users u
             LEFT JOIN portfolios p ON p.user_id = u.id
             WHERE $w
             GROUP BY u.id
             ORDER BY u.created_at DESC
             LIMIT $limit OFFSET $offset",
            $params
        );
        json_response(true, 'ok', [
            'users'       => $rows,
            'total'       => $total,
            'page'        => $page,
            'total_pages' => (int)ceil($total / $limit),
        ]);
        break;
    }

    case 'admin_user_detail': {
        $uid = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
        $user = DB::fetchRow(
            "SELECT id,name,email,role,status,theme,created_at,last_login_at,
                    COALESCE(totp_enabled,0) AS totp_enabled
             FROM users WHERE id=?", [$uid]
        );
        if (!$user) json_response(false, 'User not found.');
        $portfolios = DB::fetchAll("SELECT id,name,is_default,created_at FROM portfolios WHERE user_id=?", [$uid]);
        $auditLog   = DB::fetchAll("SELECT action,detail,created_at FROM audit_log WHERE user_id=? ORDER BY created_at DESC LIMIT 20", [$uid]);
        json_response(true, 'ok', ['user'=>$user, 'portfolios'=>$portfolios, 'audit_log'=>$auditLog]);
        break;
    }

    case 'admin_user_create': {
        csrf_verify();
        $name     = clean($_POST['name']     ?? '');
        $email    = clean($_POST['email']    ?? '');
        $password = $_POST['password']       ?? '';
        $role     = clean($_POST['role']     ?? 'user');

        if (!$name || !$email || !$password) json_response(false, 'Name, email and password required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(false, 'Invalid email.');
        if (strlen($password) < 8) json_response(false, 'Password must be at least 8 characters.');
        if (!in_array($role, ['user', 'admin'])) $role = 'user';

        $exists = DB::fetchVal("SELECT id FROM users WHERE email=?", [$email]);
        if ($exists) json_response(false, 'Email already registered.');

        $hash = password_hash($password, PASSWORD_DEFAULT);
        DB::execute(
            "INSERT INTO users(name,email,password_hash,role,status,created_at) VALUES(?,?,?,?,'active',NOW())",
            [$name, $email, $hash, $role]
        );
        $newId = DB::lastInsertId();
        DB::execute(
            "INSERT INTO portfolios(user_id,name,is_default,created_at) VALUES(?,'Default',1,NOW())",
            [$newId]
        );
        audit_log($userId, 'admin_user_create', "Created user $email (ID $newId)");
        json_response(true, 'User created.', ['id' => $newId]);
        break;
    }

    case 'admin_user_update': {
        csrf_verify();
        $uid  = (int)($_POST['user_id'] ?? 0);
        $name = clean($_POST['name']    ?? '');
        $role = clean($_POST['role']    ?? '');
        if (!$uid) json_response(false, 'user_id required.');
        $sets = []; $params = [];
        if ($name) { $sets[] = 'name=?'; $params[] = $name; }
        if ($role && in_array($role, ['user','admin'])) { $sets[] = 'role=?'; $params[] = $role; }
        if (!$sets) json_response(false, 'Nothing to update.');
        $params[] = $uid;
        DB::execute("UPDATE users SET ".implode(',',$sets)." WHERE id=?", $params);
        audit_log($userId, 'admin_user_update', "Updated user ID $uid");
        json_response(true, 'User updated.');
        break;
    }

    case 'admin_user_toggle': {
        csrf_verify();
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid === $userId) json_response(false, 'Cannot change your own status.');
        $current = DB::fetchVal("SELECT status FROM users WHERE id=?", [$uid]);
        if (!$current) json_response(false, 'User not found.');
        $new = ($current === 'active') ? 'suspended' : 'active';
        DB::execute("UPDATE users SET status=? WHERE id=?", [$new, $uid]);
        audit_log($userId, 'admin_user_toggle', "Set user $uid to $new");
        json_response(true, "User $new.", ['status' => $new]);
        break;
    }

    case 'admin_user_delete': {
        csrf_verify();
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid === $userId) json_response(false, 'Cannot delete your own account.');
        $exists = DB::fetchVal("SELECT id FROM users WHERE id=?", [$uid]);
        if (!$exists) json_response(false, 'User not found.');
        // Soft-delete: anonymise email, set status
        DB::execute(
            "UPDATE users SET status='deleted', email=CONCAT('del_',id,'_',email), name=CONCAT('[Deleted] ',name) WHERE id=?",
            [$uid]
        );
        audit_log($userId, 'admin_user_delete', "Soft-deleted user ID $uid");
        json_response(true, 'User deleted.');
        break;
    }

    case 'admin_user_reset_password': {
        csrf_verify();
        $uid     = (int)($_POST['user_id']      ?? 0);
        $newPass = $_POST['new_password']        ?? '';
        if (!$uid || strlen($newPass) < 8) json_response(false, 'user_id and new_password (min 8 chars) required.');
        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        DB::execute(
            "UPDATE users SET password_hash=?, totp_enabled=0, totp_secret=NULL WHERE id=?",
            [$hash, $uid]
        );
        audit_log($userId, 'admin_reset_password', "Reset password for user ID $uid");
        json_response(true, 'Password reset. 2FA also cleared.');
        break;
    }

    default:
        json_response(false, 'Unknown action.', [], 400);
}
