<?php
/**
 * WealthDash — Multi-user Management API [t50]
 * File: api/admin/multi_user.php
 * Worker: ID-M
 * OVERWRITE: YES (new actions extend existing admin/users.php pattern)
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');
require_auth(ROLE_ADMIN);

$actingUserId = (int) $_SESSION['user_id'];

// ── Helpers ──────────────────────────────────────────────────────────────────
function audit(string $action, string $entity, int $entityId, array $old = [], array $new = []): void {
    DB::run(
        'INSERT INTO audit_log (user_id, action, entity_type, entity_id, old_values, new_values, ip_address)
         VALUES (?,?,?,?,?,?,?)',
        [
            (int)$_SESSION['user_id'], $action, $entity, $entityId,
            $old ? json_encode($old) : null,
            $new  ? json_encode($new)  : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]
    );
}

function get_user_or_fail(int $id): array {
    $row = DB::fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$row) json_response(false, 'User not found.', [], 404);
    return $row;
}

// ── Routing ──────────────────────────────────────────────────────────────────
switch ($action) {

    // ── LIST USERS ────────────────────────────────────────────────────────────
    case 'mu_list': {
        $search  = clean($_GET['search'] ?? '');
        $role    = clean($_GET['role'] ?? '');
        $status  = clean($_GET['status'] ?? '');
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $perPage = 25;
        $offset  = ($page - 1) * $perPage;

        $where  = 'WHERE 1=1';
        $params = [];
        if ($search) {
            $where   .= ' AND (u.name LIKE ? OR u.email LIKE ?)';
            $params[] = "%{$search}%";
            $params[] = "%{$search}%";
        }
        if ($role && in_array($role, ['user','admin'])) {
            $where .= ' AND u.role = ?'; $params[] = $role;
        }
        if ($status && in_array($status, ['active','suspended','pending'])) {
            $where .= ' AND u.status = ?'; $params[] = $status;
        }

        $total = (int) DB::fetchVal("SELECT COUNT(*) FROM users u $where", $params);

        $rows = DB::fetchAll(
            "SELECT u.id, u.name, u.email, u.role,
                    COALESCE(u.status,'active') as status,
                    u.last_login, u.login_count, u.created_at,
                    u.email_verified, u.theme,
                    (SELECT COUNT(*) FROM portfolios p WHERE p.user_id = u.id) as portfolio_count,
                    (SELECT COUNT(*) FROM sessions s WHERE s.user_id = u.id
                     AND s.last_activity > DATE_SUB(NOW(), INTERVAL 30 MINUTE)) as active_sessions
             FROM users u
             $where
             ORDER BY u.created_at DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, $offset])
        );

        // Stats
        $stats = DB::fetchOne(
            "SELECT
                COUNT(*) as total,
                SUM(role='admin') as admins,
                SUM(COALESCE(status,'active')='active') as active,
                SUM(COALESCE(status,'active')='suspended') as suspended,
                SUM(last_login > DATE_SUB(NOW(), INTERVAL 30 DAY)) as active_30d
             FROM users"
        );

        json_response(true, '', [
            'users'      => $rows,
            'total'      => $total,
            'page'       => $page,
            'per_page'   => $perPage,
            'total_pages'=> (int) ceil($total / $perPage),
            'stats'      => $stats,
        ]);
    }

    // ── GET SINGLE USER ───────────────────────────────────────────────────────
    case 'mu_get': {
        $id   = (int)($_GET['id'] ?? 0);
        $user = get_user_or_fail($id);
        unset($user['password_hash']);

        // Recent activity
        $activity = DB::fetchAll(
            'SELECT action, ip_address, created_at FROM user_activity_log
             WHERE user_id = ? ORDER BY created_at DESC LIMIT 20',
            [$id]
        );

        // Portfolio summary
        $portfolio = DB::fetchOne(
            'SELECT p.id, p.name, p.created_at,
                    (SELECT COUNT(*) FROM mf_holdings mh WHERE mh.portfolio_id = p.id) as mf_count,
                    (SELECT COUNT(*) FROM stock_holdings sh WHERE sh.portfolio_id = p.id) as stock_count
             FROM portfolios p WHERE p.user_id = ?',
            [$id]
        );

        // Active sessions
        $sessions = DB::fetchAll(
            'SELECT id, ip_address, user_agent, last_activity, created_at
             FROM sessions WHERE user_id = ? ORDER BY last_activity DESC LIMIT 10',
            [$id]
        );

        json_response(true, '', [
            'user'     => $user,
            'activity' => $activity,
            'portfolio'=> $portfolio,
            'sessions' => $sessions,
        ]);
    }

    // ── ADD USER ──────────────────────────────────────────────────────────────
    case 'mu_add': {
        csrf_verify();
        $name  = clean($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $role  = in_array($_POST['role'] ?? '', ['user','admin']) ? $_POST['role'] : 'user';
        $pass  = $_POST['password'] ?? '';

        if (!$name || !$email) json_response(false, 'Name and email are required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(false, 'Invalid email address.');
        if (strlen($pass) < 8) json_response(false, 'Password must be at least 8 characters.');
        if (DB::fetchVal('SELECT id FROM users WHERE email = ?', [$email])) {
            json_response(false, 'Email already registered.');
        }

        DB::beginTransaction();
        try {
            $userId = DB::insert(
                'INSERT INTO users (name, email, password_hash, role, status, email_verified, theme, invited_by, invited_at)
                 VALUES (?,?,?,?,\'active\',1,\'dark\',?,NOW())',
                [$name, $email, password_hash($pass, PASSWORD_BCRYPT), $role, $actingUserId]
            );
            // Create default portfolio
            DB::run(
                'INSERT INTO portfolios (user_id, name) VALUES (?,?)',
                [(int)$userId, $name . "'s Portfolio"]
            );
            audit('user_created', 'users', (int)$userId, [], ['name'=>$name,'email'=>$email,'role'=>$role]);
            DB::commit();
        } catch (Exception $e) {
            DB::rollback(); throw $e;
        }

        json_response(true, 'User created successfully.', ['id' => (int)$userId]);
    }

    // ── EDIT USER ─────────────────────────────────────────────────────────────
    case 'mu_edit': {
        csrf_verify();
        $id   = (int)($_POST['id'] ?? 0);
        $old  = get_user_or_fail($id);
        $name = clean($_POST['name'] ?? $old['name']);

        // Prevent self-demotion
        if ($id === $actingUserId && isset($_POST['role']) && $_POST['role'] !== 'admin') {
            json_response(false, 'You cannot change your own role.');
        }

        $role   = in_array($_POST['role'] ?? '', ['user','admin']) ? $_POST['role'] : $old['role'];
        $status = in_array($_POST['status'] ?? '', ['active','suspended','pending']) ? $_POST['status'] : ($old['status'] ?? 'active');
        $notes  = clean($_POST['notes'] ?? $old['notes'] ?? '');

        DB::run(
            'UPDATE users SET name=?, role=?, status=?, notes=? WHERE id=?',
            [$name, $role, $status, $notes, $id]
        );

        // Optional password change
        if (!empty($_POST['new_password'])) {
            if (strlen($_POST['new_password']) < 8) json_response(false, 'Password must be at least 8 characters.');
            DB::run('UPDATE users SET password_hash=? WHERE id=?',
                [password_hash($_POST['new_password'], PASSWORD_BCRYPT), $id]);
        }

        audit('user_updated', 'users', $id,
            ['name'=>$old['name'],'role'=>$old['role'],'status'=>$old['status']??'active'],
            ['name'=>$name,'role'=>$role,'status'=>$status]
        );

        json_response(true, 'User updated.');
    }

    // ── SUSPEND / ACTIVATE ────────────────────────────────────────────────────
    case 'mu_toggle_status': {
        csrf_verify();
        $id   = (int)($_POST['id'] ?? 0);
        $user = get_user_or_fail($id);
        if ($id === $actingUserId) json_response(false, 'Cannot suspend your own account.');

        $newStatus = (($user['status'] ?? 'active') === 'active') ? 'suspended' : 'active';
        DB::run('UPDATE users SET status=? WHERE id=?', [$newStatus, $id]);

        // Kill sessions if suspending
        if ($newStatus === 'suspended') {
            DB::run('DELETE FROM sessions WHERE user_id=?', [$id]);
        }

        audit('user_status_changed', 'users', $id, ['status'=>$user['status']??'active'], ['status'=>$newStatus]);
        json_response(true, 'User status updated.', ['new_status' => $newStatus]);
    }

    // ── DELETE USER ───────────────────────────────────────────────────────────
    case 'mu_delete': {
        csrf_verify();
        $id = (int)($_POST['id'] ?? 0);
        get_user_or_fail($id);
        if ($id === $actingUserId) json_response(false, 'Cannot delete your own account.');

        $adminCount = (int) DB::fetchVal("SELECT COUNT(*) FROM users WHERE role='admin' AND id != ?", [$id]);
        if ($adminCount === 0 && DB::fetchVal('SELECT role FROM users WHERE id=?', [$id]) === 'admin') {
            json_response(false, 'Cannot delete the last admin account.');
        }

        DB::run('DELETE FROM users WHERE id=?', [$id]);
        audit('user_deleted', 'users', $id);
        json_response(true, 'User deleted.');
    }

    // ── CHANGE ROLE ───────────────────────────────────────────────────────────
    case 'mu_change_role': {
        csrf_verify();
        $id   = (int)($_POST['id'] ?? 0);
        $role = in_array($_POST['role'] ?? '', ['user','admin']) ? $_POST['role'] : null;
        if (!$role) json_response(false, 'Invalid role.');
        if ($id === $actingUserId) json_response(false, 'Cannot change your own role.');
        $old = get_user_or_fail($id);
        DB::run('UPDATE users SET role=? WHERE id=?', [$role, $id]);
        audit('role_changed', 'users', $id, ['role'=>$old['role']], ['role'=>$role]);
        json_response(true, 'Role updated.');
    }

    // ── RESET PASSWORD ────────────────────────────────────────────────────────
    case 'mu_reset_password': {
        csrf_verify();
        $id       = (int)($_POST['id'] ?? 0);
        get_user_or_fail($id);
        $newPass  = $_POST['new_password'] ?? '';
        if (strlen($newPass) < 8) json_response(false, 'Password must be at least 8 characters.');
        DB::run('UPDATE users SET password_hash=? WHERE id=?',
            [password_hash($newPass, PASSWORD_BCRYPT), $id]);
        DB::run('DELETE FROM sessions WHERE user_id=?', [$id]);
        audit('password_reset', 'users', $id);
        json_response(true, 'Password reset. All sessions terminated.');
    }

    // ── TERMINATE SESSIONS ────────────────────────────────────────────────────
    case 'mu_kill_sessions': {
        csrf_verify();
        $id = (int)($_POST['id'] ?? 0);
        get_user_or_fail($id);
        $count = DB::fetchVal('SELECT COUNT(*) FROM sessions WHERE user_id=?', [$id]);
        DB::run('DELETE FROM sessions WHERE user_id=?', [$id]);
        audit('sessions_terminated', 'users', $id);
        json_response(true, "Terminated {$count} session(s).");
    }

    // ── INVITE USER ───────────────────────────────────────────────────────────
    case 'mu_invite': {
        csrf_verify();
        $email = strtolower(trim($_POST['email'] ?? ''));
        $role  = in_array($_POST['role'] ?? '', ['user','admin']) ? $_POST['role'] : 'user';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_response(false, 'Invalid email.');
        if (DB::fetchVal('SELECT id FROM users WHERE email=?', [$email])) {
            json_response(false, 'Email already registered.');
        }

        $token   = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+48 hours'));

        // Delete any existing invite for this email
        DB::run('DELETE FROM user_invitations WHERE email=?', [$email]);
        DB::run(
            'INSERT INTO user_invitations (email, token, role, invited_by, expires_at) VALUES (?,?,?,?,?)',
            [$email, $token, $role, $actingUserId, $expires]
        );

        $inviteUrl = APP_URL . '/auth/register.php?invite=' . $token;
        json_response(true, 'Invitation created.', ['invite_url' => $inviteUrl, 'token' => $token, 'expires_at' => $expires]);
    }

    // ── INVITATIONS LIST ──────────────────────────────────────────────────────
    case 'mu_invitations': {
        $rows = DB::fetchAll(
            'SELECT i.*, u.name as invited_by_name
             FROM user_invitations i
             JOIN users u ON u.id = i.invited_by
             ORDER BY i.created_at DESC LIMIT 50'
        );
        json_response(true, '', ['invitations' => $rows]);
    }

    // ── ACTIVITY LOG ──────────────────────────────────────────────────────────
    case 'mu_activity': {
        $userId = (int)($_GET['user_id'] ?? 0);
        $limit  = min((int)($_GET['limit'] ?? 50), 200);
        $where  = $userId ? 'WHERE user_id = ?' : 'WHERE 1=1';
        $params = $userId ? [$userId] : [];
        $rows   = DB::fetchAll(
            "SELECT ual.*, u.name, u.email FROM user_activity_log ual
             JOIN users u ON u.id = ual.user_id
             $where ORDER BY ual.created_at DESC LIMIT {$limit}",
            $params
        );
        json_response(true, '', ['activity' => $rows]);
    }

    default:
        json_response(false, "Unknown action: {$action}", [], 400);
}
