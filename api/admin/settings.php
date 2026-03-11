<?php
/**
 * WealthDash — Admin: App Settings API
 * Actions: admin_settings_get | admin_settings_save | admin_nav_update | admin_import_amfi
 */
declare(strict_types=1);

if (!defined('WEALTHDASH')) die('Direct access not allowed.');
if (!is_admin()) json_response(false, 'Admin access required.', [], 403);

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ── Get all settings ──────────────────────────────────────
    case 'admin_settings_get':
        $rows = DB::fetchAll('SELECT setting_key, setting_val FROM app_settings ORDER BY setting_key');
        $settings = [];
        foreach ($rows as $r) {
            $settings[$r['setting_key']] = $r['setting_val'];
        }
        json_response(true, '', ['settings' => $settings]);

    // ── Save settings ─────────────────────────────────────────
    case 'admin_settings_save':
        csrf_verify();

        $allowed = [
            'app_name', 'tax_year_start_month',
            'ltcg_exemption_limit', 'equity_ltcg_rate', 'equity_stcg_rate',
            'debt_ltcg_rate', 'fd_tds_rate', 'fd_tds_senior_rate',
            'fd_tds_threshold', 'fd_tds_threshold_senior',
            'sip_reminder_enabled', 'sip_reminder_days_before',
            'goal_default_return_pct',
        ];

        $saved = 0;
        foreach ($allowed as $key) {
            if (!isset($_POST[$key])) continue;
            $val = clean($_POST[$key]);
            DB::run(
                'INSERT INTO app_settings (setting_key, setting_val, updated_by)
                 VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE setting_val = VALUES(setting_val), updated_by = VALUES(updated_by)',
                [$key, $val, $_SESSION['user_id']]
            );
            $saved++;
        }

        audit_log('admin_settings_save', 'app_settings', 0);
        json_response(true, "Saved {$saved} setting(s).");

    // ── Audit log viewer ──────────────────────────────────────
    case 'admin_audit_log':
        $limit  = min((int) ($_GET['limit']  ?? 100), 500);
        $offset = max((int) ($_GET['offset'] ?? 0), 0);
        $filter = clean($_GET['filter'] ?? '');

        $where = $filter ? "WHERE al.action LIKE ?" : '';
        $params = $filter ? ["%{$filter}%"] : [];
        $params[] = $limit;
        $params[] = $offset;

        $logs = DB::fetchAll(
            "SELECT al.id, al.action, al.entity_type, al.entity_id,
                    al.ip_address, al.created_at,
                    u.name AS user_name, u.email AS user_email
             FROM audit_log al
             LEFT JOIN users u ON u.id = al.user_id
             {$where}
             ORDER BY al.created_at DESC
             LIMIT ? OFFSET ?",
            $params
        );
        $total = (int) DB::fetchVal("SELECT COUNT(*) FROM audit_log {$where}",
            $filter ? ["%{$filter}%"] : []);

        json_response(true, '', ['logs' => $logs, 'total' => $total]);

    // ── Portfolio member management ───────────────────────────
    case 'admin_add_portfolio_member':
        csrf_verify();
        $portfolioId = (int) ($_POST['portfolio_id'] ?? 0);
        $memberEmail = clean($_POST['member_email'] ?? '');
        $canEdit     = (int) ($_POST['can_edit'] ?? 0);

        if (!$portfolioId || !$memberEmail) {
            json_response(false, 'Portfolio ID and member email are required.');
        }

        $member = DB::fetchOne('SELECT id, name FROM users WHERE email = ? AND status = ?',
            [$memberEmail, 'active']);
        if (!$member) json_response(false, 'No active user found with that email.');

        $portfolio = DB::fetchOne('SELECT id, name, user_id FROM portfolios WHERE id = ?', [$portfolioId]);
        if (!$portfolio) json_response(false, 'Portfolio not found.');

        if ($portfolio['user_id'] === $member['id']) {
            json_response(false, 'User is the portfolio owner.');
        }

        $exists = DB::fetchOne(
            'SELECT id FROM portfolio_members WHERE portfolio_id = ? AND user_id = ?',
            [$portfolioId, $member['id']]
        );
        if ($exists) {
            // Update can_edit
            DB::run('UPDATE portfolio_members SET can_edit = ? WHERE portfolio_id = ? AND user_id = ?',
                [$canEdit, $portfolioId, $member['id']]);
            json_response(true, 'Member access updated.');
        }

        DB::run(
            'INSERT INTO portfolio_members (portfolio_id, user_id, can_edit) VALUES (?, ?, ?)',
            [$portfolioId, $member['id'], $canEdit]
        );
        audit_log('admin_add_portfolio_member', 'portfolio_members', $portfolioId,
            [], ['member_id' => $member['id'], 'can_edit' => $canEdit]);
        json_response(true, "{$member['name']} added to portfolio.");

    case 'admin_remove_portfolio_member':
        csrf_verify();
        $portfolioId = (int) ($_POST['portfolio_id'] ?? 0);
        $memberId    = (int) ($_POST['member_id']    ?? 0);
        if (!$portfolioId || !$memberId) json_response(false, 'Invalid parameters.');

        DB::run('DELETE FROM portfolio_members WHERE portfolio_id = ? AND user_id = ?',
            [$portfolioId, $memberId]);
        audit_log('admin_remove_portfolio_member', 'portfolio_members', $portfolioId);
        json_response(true, 'Member removed from portfolio.');

    default:
        json_response(false, 'Unknown settings action.', [], 400);
}

