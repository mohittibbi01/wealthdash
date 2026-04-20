<?php
/**
 * WealthDash — Behavioral Nudge Engine
 * Task t499: Right message at right time — market drop, FY end, idle cash, etc.
 * Actions: nudge_get | nudge_dismiss | nudge_list_all
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'nudge_get';
$db          = DB::conn();

// ── Ensure nudge_dismissals table ──────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS nudge_dismissals (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    nudge_id VARCHAR(60) NOT NULL,
    dismissed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_nudge (user_id, nudge_id),
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Dismissed nudges for this user ────────────────────────────────────
$dimStmt = $db->prepare("
    SELECT nudge_id FROM nudge_dismissals
    WHERE user_id=? AND dismissed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$dimStmt->execute([$userId]);
$dismissed = $dimStmt->fetchAll(PDO::FETCH_COLUMN, 0);
$dismissed = array_flip($dismissed);

// ══════════════════════════════════════════════════════════════════════════
// NUDGE EVALUATION ENGINE
// ══════════════════════════════════════════════════════════════════════════
function evaluateNudges(PDO $db, int $userId, array $dismissed): array {
    $nudges = [];

    // ── 1. FY End — LTCG booking opportunity (Jan–Mar) ─────────────────
    $month = (int)date('n');
    if ($month >= 1 && $month <= 3 && !isset($dismissed['ltcg_fy_end'])) {
        $ltcgStmt = $db->prepare("
            SELECT COALESCE(SUM(
              CASE WHEN DATEDIFF(CURDATE(), t.tx_date) > 365
                   THEN (f.latest_nav - t.price_per_unit) * t.units
                   ELSE 0 END
            ), 0) AS unrealised_ltcg
            FROM mf_transactions t
            JOIN mf_holdings mh ON mh.id = t.holding_id
            JOIN portfolios p   ON p.id  = mh.portfolio_id
            JOIN funds f        ON f.id  = mh.fund_id
            WHERE p.user_id=? AND t.tx_type IN ('buy','sip','lumpsum')
        ");
        $ltcgStmt->execute([$userId]);
        $unrealisedLtcg = (float)$ltcgStmt->fetchColumn();

        $ltcgFree = 125000; // ₹1.25L free from Budget 2024
        if ($unrealisedLtcg > 0 && $unrealisedLtcg <= $ltcgFree * 1.5) {
            $nudges[] = [
                'id'       => 'ltcg_fy_end',
                'type'     => 'tax',
                'priority' => 1,
                'icon'     => '🧾',
                'title'    => 'Book LTCG Before 31 March',
                'message'  => sprintf(
                    'You have ₹%s in unrealised LTCG. Book profits under ₹1.25L limit — tax-free! FY ends March 31.',
                    number_format($unrealisedLtcg, 0)
                ),
                'action_label' => 'View Tax Report',
                'action_url'   => '?page=report_fy',
                'color'        => '#f59e0b',
            ];
        }
    }

    // ── 2. Idle Cash — Savings balance high vs investments ──────────────
    if (!isset($dismissed['idle_cash'])) {
        $cashStmt = $db->prepare("
            SELECT COALESCE(SUM(balance),0) FROM savings_accounts WHERE user_id=?
        ");
        $cashStmt->execute([$userId]);
        $totalCash = (float)$cashStmt->fetchColumn();

        $mfStmt = $db->prepare("
            SELECT COALESCE(SUM(mh.latest_value),0)
            FROM mf_holdings mh JOIN portfolios p ON p.id=mh.portfolio_id
            WHERE p.user_id=?
        ");
        $mfStmt->execute([$userId]);
        $mfValue = (float)$mfStmt->fetchColumn();

        if ($totalCash > 100000 && $mfValue > 0 && ($totalCash / ($mfValue + $totalCash)) > 0.3) {
            $nudges[] = [
                'id'       => 'idle_cash',
                'type'     => 'opportunity',
                'priority' => 2,
                'icon'     => '💸',
                'title'    => 'Idle Cash Detected',
                'message'  => sprintf(
                    '₹%s sitting in savings at ~3.5%%. A liquid MF could earn 6-7%% with similar liquidity.',
                    number_format($totalCash, 0)
                ),
                'action_label' => 'Add MF Investment',
                'action_url'   => '?page=mf_holdings',
                'color'        => '#0891b2',
            ];
        }
    }

    // ── 3. Long Inactive — 30+ days no login activity ───────────────────
    if (!isset($dismissed['inactive_return'])) {
        $loginStmt = $db->prepare("
            SELECT MAX(created_at) FROM audit_log WHERE user_id=? AND action='user_login'
        ");
        $loginStmt->execute([$userId]);
        $lastLogin = $loginStmt->fetchColumn();
        if ($lastLogin && strtotime($lastLogin) < strtotime('-30 days')) {
            $nudges[] = [
                'id'       => 'inactive_return',
                'type'     => 'reminder',
                'priority' => 3,
                'icon'     => '👋',
                'title'    => 'Welcome Back!',
                'message'  => 'You haven\'t checked your portfolio in a while. A lot may have changed — let\'s review!',
                'action_label' => 'View Dashboard',
                'action_url'   => '?page=dashboard',
                'color'        => '#8b5cf6',
            ];
        }
    }

    // ── 4. SIP Streak Risk — Missed SIP in last 45 days ─────────────────
    if (!isset($dismissed['sip_missed'])) {
        $sipStmt = $db->prepare("
            SELECT COUNT(*) FROM mf_transactions t
            JOIN mf_holdings mh ON mh.id = t.holding_id
            JOIN portfolios p   ON p.id  = mh.portfolio_id
            WHERE p.user_id=? AND t.tx_type='sip'
              AND t.tx_date >= DATE_SUB(CURDATE(), INTERVAL 45 DAY)
        ");
        $sipStmt->execute([$userId]);
        $recentSips = (int)$sipStmt->fetchColumn();

        // Check if they had SIPs before
        $prevSipStmt = $db->prepare("
            SELECT COUNT(*) FROM mf_transactions t
            JOIN mf_holdings mh ON mh.id = t.holding_id
            JOIN portfolios p ON p.id=mh.portfolio_id
            WHERE p.user_id=? AND t.tx_type='sip'
              AND t.tx_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 90 DAY) AND DATE_SUB(CURDATE(), INTERVAL 46 DAY)
        ");
        $prevSipStmt->execute([$userId]);
        $prevSips = (int)$prevSipStmt->fetchColumn();

        if ($prevSips > 0 && $recentSips == 0) {
            $nudges[] = [
                'id'       => 'sip_missed',
                'type'     => 'alert',
                'priority' => 1,
                'icon'     => '⚠️',
                'title'    => 'No SIP in Last 45 Days',
                'message'  => 'Your SIP streak may be broken. Consistent SIPs beat market timing — check if auto-debit is active.',
                'action_label' => 'Check SIPs',
                'action_url'   => '?page=mf_holdings',
                'color'        => '#ef4444',
            ];
        }
    }

    // ── 5. FD Maturing Soon ──────────────────────────────────────────────
    if (!isset($dismissed['fd_maturing'])) {
        $fdStmt = $db->prepare("
            SELECT COUNT(*), COALESCE(SUM(principal_amount),0) AS amt
            FROM fd_holdings
            WHERE user_id=? AND maturity_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
              AND status='active'
        ");
        $fdStmt->execute([$userId]);
        $fd = $fdStmt->fetch(PDO::FETCH_ASSOC);
        if ((int)($fd[0] ?? 0) > 0) {
            $nudges[] = [
                'id'       => 'fd_maturing',
                'type'     => 'reminder',
                'priority' => 2,
                'icon'     => '🏦',
                'title'    => 'FD Maturing in 30 Days',
                'message'  => sprintf(
                    '₹%s in FDs maturing soon. Plan your reinvestment to avoid idle cash.',
                    number_format((float)$fd['amt'], 0)
                ),
                'action_label' => 'View FDs',
                'action_url'   => '?page=fd',
                'color'        => '#0891b2',
            ];
        }
    }

    // ── 6. 80C Gap — March approaching ──────────────────────────────────
    if ($month >= 1 && $month <= 3 && !isset($dismissed['80c_gap'])) {
        $elssStmt = $db->prepare("
            SELECT COALESCE(SUM(t.amount),0) AS invested
            FROM mf_transactions t
            JOIN mf_holdings mh ON mh.id=t.holding_id
            JOIN funds f        ON f.id=mh.fund_id
            JOIN portfolios p   ON p.id=mh.portfolio_id
            WHERE p.user_id=? AND f.category LIKE '%ELSS%'
              AND t.tx_type IN ('buy','sip','lumpsum')
              AND t.tx_date BETWEEN CONCAT(
                  CASE WHEN MONTH(CURDATE())>=4 THEN YEAR(CURDATE()) ELSE YEAR(CURDATE())-1 END,
                  '-04-01') AND CONCAT(
                  CASE WHEN MONTH(CURDATE())>=4 THEN YEAR(CURDATE())+1 ELSE YEAR(CURDATE()) END,
                  '-03-31')
        ");
        $elssStmt->execute([$userId]);
        $elssInvested = (float)$elssStmt->fetchColumn();
        $limit80c = 150000;
        if ($elssInvested < $limit80c * 0.8) {
            $remaining = $limit80c - $elssInvested;
            $nudges[] = [
                'id'       => '80c_gap',
                'type'     => 'tax',
                'priority' => 1,
                'icon'     => '💡',
                'title'    => '80C Not Fully Utilised',
                'message'  => sprintf(
                    '₹%s remaining in 80C limit. Invest in ELSS before March 31 to save up to ₹%s in tax.',
                    number_format($remaining, 0),
                    number_format($remaining * 0.3, 0)
                ),
                'action_label' => 'Add ELSS',
                'action_url'   => '?page=mf_holdings',
                'color'        => '#16a34a',
            ];
        }
    }

    // ── 7. Diversification Alert — Too concentrated ──────────────────────
    if (!isset($dismissed['concentration_risk'])) {
        $concStmt = $db->prepare("
            SELECT
              f.category,
              COALESCE(SUM(mh.latest_value),0) AS val,
              COALESCE(SUM(SUM(mh.latest_value)) OVER (), 0) AS total_val
            FROM mf_holdings mh
            JOIN portfolios p ON p.id=mh.portfolio_id
            JOIN funds f ON f.id=mh.fund_id
            WHERE p.user_id=?
            GROUP BY f.category
            ORDER BY val DESC
            LIMIT 1
        ");
        $concStmt->execute([$userId]);
        $topCat = $concStmt->fetch(PDO::FETCH_ASSOC);
        if ($topCat && $topCat['total_val'] > 0) {
            $pct = ($topCat['val'] / $topCat['total_val']) * 100;
            if ($pct > 60) {
                $nudges[] = [
                    'id'       => 'concentration_risk',
                    'type'     => 'risk',
                    'priority' => 2,
                    'icon'     => '📊',
                    'title'    => 'High Concentration Risk',
                    'message'  => sprintf(
                        '%.0f%% of your MF portfolio is in %s. Consider diversifying across asset classes.',
                        $pct,
                        $topCat['category'] ?: 'one category'
                    ),
                    'action_label' => 'View Screener',
                    'action_url'   => '?page=mf_screener',
                    'color'        => '#f97316',
                ];
            }
        }
    }

    // Sort by priority
    usort($nudges, fn($a, $b) => $a['priority'] <=> $b['priority']);

    return $nudges;
}

// ══════════════════════════════════════════════════════════════════════════
switch ($action) {

case 'nudge_get':
    // Return top 3 active nudges for the user
    $nudges = evaluateNudges($db, $userId, $dismissed);
    echo json_encode(['success'=>true, 'data'=>array_slice($nudges, 0, 3)]);
    break;

case 'nudge_list_all':
    // Return all nudges (for settings/preview)
    $nudges = evaluateNudges($db, $userId, $dismissed);
    echo json_encode(['success'=>true, 'data'=>$nudges, 'dismissed_count'=>count($dismissed)]);
    break;

case 'nudge_dismiss':
    $nudgeId = trim($_POST['nudge_id'] ?? '');
    if (!$nudgeId || strlen($nudgeId) > 60) {
        echo json_encode(['success'=>false,'error'=>'Invalid nudge_id']);
        break;
    }
    $stmt = $db->prepare("
        INSERT INTO nudge_dismissals (user_id, nudge_id, dismissed_at)
        VALUES (?,?, NOW())
        ON DUPLICATE KEY UPDATE dismissed_at=NOW()
    ");
    $stmt->execute([$userId, $nudgeId]);
    echo json_encode(['success'=>true]);
    break;

default:
    echo json_encode(['success'=>false,'error'=>"Unknown action: $action"]);
}
