<?php
/**
 * WealthDash — t404: Smart Alerts v2 — Context-Aware Notifications
 * File: api/alerts/smart_alerts.php
 * Actions: smart_alerts_check, smart_alerts_list, smart_alert_dismiss,
 *          smart_alerts_unread_count, smart_alert_settings_get, smart_alert_settings_save
 *
 * Broader than t358 (goal milestones only) — covers:
 *  - SIP due reminders (3 days before)
 *  - Insurance premium due (30 days)
 *  - Loan EMI due (5 days)
 *  - Portfolio drawdown alerts (>10% drop)
 *  - Large gain alerts (single fund +20%)
 *  - Goal milestones (reuses logic from t358 pattern, separate table)
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action      = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId      = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

switch ($action) {

    // ── Run all alert checks and create new alerts ──────────────────
    case 'smart_alerts_check': {
        $today = date('Y-m-d');
        $created = 0;

        // Get user preferences
        $settings = DB::fetchRow("SELECT * FROM smart_alert_settings WHERE user_id=?", [$userId]);
        $enabled = fn($key) => !$settings || (bool)($settings[$key] ?? 1);

        // 1. SIP due in next 3 days
        if ($enabled('sip_due')) {
            $sips = DB::fetchAll("SELECT fund_name, sip_amount, sip_date FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'", [$userId, $portfolioId]);
            foreach ($sips as $s) {
                $dom = (int)($s['sip_date'] ?? 1);
                $thisMonth = date('Y-m');
                $sipDate = $thisMonth . '-' . str_pad((string)$dom, 2, '0', STR_PAD_LEFT);
                $daysTo = (int)((strtotime($sipDate) - strtotime($today)) / 86400);
                if ($daysTo >= 0 && $daysTo <= 3) {
                    $created += _create_alert($userId, 'sip_due', "sip_{$s['fund_name']}_{$sipDate}",
                        '🔁', "SIP due in {$daysTo} day(s): {$s['fund_name']} — ₹" . number_format((float)$s['sip_amount'],0), 'medium', $sipDate);
                }
            }
        }

        // 2. Insurance premium due in 30 days
        if ($enabled('insurance_due')) {
            $policies = DB::fetchAll("SELECT policy_name, premium_amount, next_premium_date FROM insurance_policies WHERE user_id=? AND status='active' AND next_premium_date BETWEEN ? AND DATE_ADD(?,INTERVAL 30 DAY)", [$userId, $today, $today]);
            foreach ($policies as $p) {
                $daysTo = (int)((strtotime($p['next_premium_date']) - strtotime($today)) / 86400);
                $created += _create_alert($userId, 'insurance_due', "ins_{$p['policy_name']}_{$p['next_premium_date']}",
                    '🛡', "Insurance premium due in {$daysTo} days: {$p['policy_name']} — ₹" . number_format((float)$p['premium_amount'],0), $daysTo <= 7 ? 'high' : 'medium', $p['next_premium_date']);
            }
        }

        // 3. Loan EMI due in 5 days
        if ($enabled('loan_emi_due')) {
            $loans = DB::fetchAll("SELECT loan_name, emi_amount, emi_date FROM loans WHERE user_id=? AND status='active'", [$userId]);
            foreach ($loans as $l) {
                $dom = (int)($l['emi_date'] ?? 5);
                $thisMonth = date('Y-m');
                $emiDate = $thisMonth . '-' . str_pad((string)$dom, 2, '0', STR_PAD_LEFT);
                $daysTo = (int)((strtotime($emiDate) - strtotime($today)) / 86400);
                if ($daysTo >= 0 && $daysTo <= 5) {
                    $created += _create_alert($userId, 'loan_emi_due', "emi_{$l['loan_name']}_{$emiDate}",
                        '🏦', "EMI due in {$daysTo} day(s): {$l['loan_name']} — ₹" . number_format((float)$l['emi_amount'],0), 'high', $emiDate);
                }
            }
        }

        // 4. Portfolio drawdown >10%
        if ($enabled('drawdown_alert')) {
            $portfolioValue = (float)(DB::fetchVal(
                "SELECT COALESCE(SUM(h.units * COALESCE(n.nav, h.avg_cost_per_unit)),0) FROM mf_holdings h LEFT JOIN mf_nav_latest n ON n.mf_id=h.mf_id WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0",
                [$userId, $portfolioId]
            ) ?? 0);
            $invested = (float)(DB::fetchVal(
                "SELECT COALESCE(SUM(h.units * h.avg_cost_per_unit),0) FROM mf_holdings h WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0",
                [$userId, $portfolioId]
            ) ?? 0);
            if ($invested > 0) {
                $pct = (($portfolioValue - $invested) / $invested) * 100;
                if ($pct <= -10) {
                    $created += _create_alert($userId, 'drawdown', "drawdown_{$today}",
                        '📉', "Portfolio down " . round(abs($pct),1) . "% from invested value. Market volatility — stay invested, don't panic sell.", 'medium', $today);
                }
            }
        }

        // 5. Large single fund gain (>20%)
        if ($enabled('gain_alert')) {
            $holdings = DB::fetchAll(
                "SELECT h.fund_name, h.units * COALESCE(n.nav, h.avg_cost_per_unit) AS cv, h.units * h.avg_cost_per_unit AS iv
                 FROM mf_holdings h LEFT JOIN mf_nav_latest n ON n.mf_id=h.mf_id
                 WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0", [$userId, $portfolioId]
            );
            foreach ($holdings as $h) {
                $iv = (float)$h['iv'];
                if ($iv > 0) {
                    $gp = (((float)$h['cv'] - $iv) / $iv) * 100;
                    if ($gp >= 20) {
                        $created += _create_alert($userId, 'large_gain', "gain_{$h['fund_name']}_" . date('Y-m'),
                            '🎉', "{$h['fund_name']} is up " . round($gp,1) . "%! Consider rebalancing profits.", 'low', $today);
                    }
                }
            }
        }

        json_response(true,'ok',['new_alerts'=>$created]);
        break;
    }

    // ── List alerts ────────────────────────────────────────────────
    case 'smart_alerts_list': {
        $unreadOnly = (int)($_GET['unread'] ?? 0);
        $where = "user_id=?"; $params = [$userId];
        if ($unreadOnly) { $where .= " AND is_read=0"; }
        $rows = DB::fetchAll("SELECT * FROM smart_alerts WHERE $where ORDER BY created_at DESC LIMIT 50", $params);
        json_response(true,'ok',['alerts'=>$rows]);
        break;
    }

    // ── Unread count (for badge) ─────────────────────────────────────
    case 'smart_alerts_unread_count': {
        $count = (int)(DB::fetchVal("SELECT COUNT(*) FROM smart_alerts WHERE user_id=? AND is_read=0", [$userId]) ?? 0);
        json_response(true,'ok',['count'=>$count]);
        break;
    }

    // ── Dismiss alert(s) ──────────────────────────────────────────────
    case 'smart_alert_dismiss': {
        csrf_verify();
        $id = (int)($_POST['id'] ?? 0);
        $all = (int)($_POST['all'] ?? 0);
        if ($all) DB::execute("UPDATE smart_alerts SET is_read=1 WHERE user_id=?", [$userId]);
        else      DB::execute("UPDATE smart_alerts SET is_read=1 WHERE id=? AND user_id=?", [$id,$userId]);
        json_response(true,'ok');
        break;
    }

    // ── Alert preference settings ────────────────────────────────────
    case 'smart_alert_settings_get': {
        $row = DB::fetchRow("SELECT * FROM smart_alert_settings WHERE user_id=?", [$userId]);
        json_response(true,'ok',['settings' => $row ?: [
            'sip_due'=>1,'insurance_due'=>1,'loan_emi_due'=>1,'drawdown_alert'=>1,'gain_alert'=>1,'goal_milestone'=>1
        ]]);
        break;
    }

    case 'smart_alert_settings_save': {
        csrf_verify();
        $fields = ['sip_due','insurance_due','loan_emi_due','drawdown_alert','gain_alert','goal_milestone'];
        $vals = array_map(fn($f) => (int)($_POST[$f] ?? 0), $fields);
        $existing = DB::fetchVal("SELECT id FROM smart_alert_settings WHERE user_id=?", [$userId]);
        if ($existing) {
            $sets = implode(',', array_map(fn($f) => "$f=?", $fields));
            DB::execute("UPDATE smart_alert_settings SET $sets WHERE id=?", [...$vals, $existing]);
        } else {
            $cols = implode(',', $fields);
            $ph   = implode(',', array_fill(0, count($fields), '?'));
            DB::execute("INSERT INTO smart_alert_settings(user_id,$cols) VALUES(?,$ph)", [$userId, ...$vals]);
        }
        json_response(true,'Preferences saved.');
        break;
    }

    default: json_response(false,'Unknown action.',[],400);
}

// ── Helper: create alert if not exists (dedup by dedup_key) ─────────
function _create_alert(int $userId, string $type, string $dedupKey, string $icon, string $message, string $severity, string $relevantDate): int {
    $exists = DB::fetchVal("SELECT id FROM smart_alerts WHERE user_id=? AND dedup_key=?", [$userId, $dedupKey]);
    if ($exists) return 0;
    DB::execute(
        "INSERT INTO smart_alerts(user_id,alert_type,dedup_key,icon,message,severity,relevant_date,is_read,created_at)
         VALUES(?,?,?,?,?,?,?,0,NOW())",
        [$userId, $type, $dedupKey, $icon, $message, $severity, $relevantDate]
    );
    return 1;
}
