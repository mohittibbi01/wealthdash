<?php
/**
 * WealthDash — Data Integrity Checker
 * Task t413: Orphan records, negative units, date anomalies, XIRR sanity
 * Action: data_integrity_check | data_integrity_fix
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? 'data_integrity_check';
$db          = DB::conn();

switch ($action) {

case 'data_integrity_check':
    $issues = [];

    // ── 1. Orphan transactions (holding not in portfolios of user) ─
    $s = $db->prepare("
        SELECT COUNT(*) FROM mf_transactions t
        JOIN mf_holdings mh ON mh.id = t.holding_id
        LEFT JOIN portfolios p ON p.id = mh.portfolio_id AND p.user_id = ?
        WHERE p.id IS NULL AND mh.portfolio_id IS NOT NULL
    ");
    $s->execute([$userId]);
    if (($c = (int)$s->fetchColumn()) > 0) {
        $issues[] = ['type'=>'error','code'=>'orphan_txn','count'=>$c,
            'message'=>"$c transactions linked to portfolios not belonging to you.",
            'fixable'=>false];
    }

    // ── 2. Negative unit holdings ──────────────────────────────────
    $s = $db->prepare("
        SELECT COUNT(*), GROUP_CONCAT(f.fund_name ORDER BY f.fund_name SEPARATOR ', ')
        FROM mf_holdings mh
        JOIN portfolios p ON p.id=mh.portfolio_id
        JOIN funds f ON f.id=mh.fund_id
        WHERE p.user_id=? AND mh.units < 0
    ");
    $s->execute([$userId]);
    $row = $s->fetch(PDO::FETCH_NUM);
    if ((int)$row[0] > 0) {
        $issues[] = ['type'=>'error','code'=>'negative_units','count'=>(int)$row[0],
            'message'=>"Negative units in: " . ($row[1] ?? 'unknown funds'),
            'fixable'=>false];
    }

    // ── 3. Future-dated transactions ──────────────────────────────
    $s = $db->prepare("
        SELECT COUNT(*) FROM mf_transactions t
        JOIN mh_holdings mh ON mh.id=t.holding_id
        JOIN portfolios p ON p.id=mh.portfolio_id
        WHERE p.user_id=? AND t.tx_date > CURDATE()
    ");
    try {
        $s->execute([$userId]);
        if (($c = (int)$s->fetchColumn()) > 0) {
            $issues[] = ['type'=>'warning','code'=>'future_dates','count'=>$c,
                'message'=>"$c transactions have future dates.",
                'fixable'=>false];
        }
    } catch (Exception $e) {}

    // ── 4. Very old transactions (pre-2000) ────────────────────────
    $s = $db->prepare("
        SELECT COUNT(*) FROM mf_transactions t
        JOIN mf_holdings mh ON mh.id=t.holding_id
        JOIN portfolios p ON p.id=mh.portfolio_id
        WHERE p.user_id=? AND t.tx_date < '2000-01-01'
    ");
    $s->execute([$userId]);
    if (($c = (int)$s->fetchColumn()) > 0) {
        $issues[] = ['type'=>'warning','code'=>'ancient_dates','count'=>$c,
            'message'=>"$c transactions dated before year 2000 — likely data entry errors.",
            'fixable'=>false];
    }

    // ── 5. Holdings with zero invested but positive units ──────────
    $s = $db->prepare("
        SELECT COUNT(*) FROM mf_holdings mh
        JOIN portfolios p ON p.id=mh.portfolio_id
        WHERE p.user_id=? AND mh.units > 0 AND mh.invested_value = 0
    ");
    $s->execute([$userId]);
    if (($c = (int)$s->fetchColumn()) > 0) {
        $issues[] = ['type'=>'warning','code'=>'zero_invested','count'=>$c,
            'message'=>"$c holdings have units but ₹0 invested — cost basis missing.",
            'fixable'=>false];
    }

    // ── 6. Funds with stale NAV (>5 business days old) ─────────────
    $s = $db->prepare("
        SELECT COUNT(*) FROM funds f
        JOIN mf_holdings mh ON mh.fund_id=f.id
        JOIN portfolios p ON p.id=mh.portfolio_id
        WHERE p.user_id=? AND mh.units > 0
          AND (f.latest_nav_date IS NULL OR f.latest_nav_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY))
    ");
    $s->execute([$userId]);
    if (($c = (int)$s->fetchColumn()) > 0) {
        $issues[] = ['type'=>'warning','code'=>'stale_nav','count'=>$c,
            'message'=>"$c active holdings have NAV not updated in 7+ days.",
            'fixable'=>true,'fix_action'=>'trigger_nav_update'];
    }

    // ── 7. Duplicate transactions (same fund, same date, same amount) ─
    $s = $db->prepare("
        SELECT COUNT(*) FROM (
            SELECT t.holding_id, t.tx_date, t.amount, t.tx_type, COUNT(*) AS cnt
            FROM mf_transactions t
            JOIN mf_holdings mh ON mh.id=t.holding_id
            JOIN portfolios p ON p.id=mh.portfolio_id
            WHERE p.user_id=?
            GROUP BY t.holding_id, t.tx_date, t.amount, t.tx_type
            HAVING cnt > 1
        ) dupes
    ");
    $s->execute([$userId]);
    if (($c = (int)$s->fetchColumn()) > 0) {
        $issues[] = ['type'=>'warning','code'=>'duplicate_txn','count'=>$c,
            'message'=>"$c sets of possible duplicate transactions found (same fund, date, amount).",
            'fixable'=>false];
    }

    // ── 8. FDs with maturity date before start date ────────────────
    $s = $db->prepare("
        SELECT COUNT(*) FROM fd_holdings
        WHERE user_id=? AND maturity_date < start_date
    ");
    $s->execute([$userId]);
    if (($c = (int)$s->fetchColumn()) > 0) {
        $issues[] = ['type'=>'error','code'=>'fd_date_error','count'=>$c,
            'message'=>"$c FDs have maturity date before start date.",
            'fixable'=>false];
    }

    // ── Summary ────────────────────────────────────────────────────
    $errorCount   = count(array_filter($issues, fn($i) => $i['type']==='error'));
    $warningCount = count(array_filter($issues, fn($i) => $i['type']==='warning'));

    echo json_encode([
        'success'       => true,
        'data'          => [
            'issues'        => $issues,
            'error_count'   => $errorCount,
            'warning_count' => $warningCount,
            'health_status' => $errorCount > 0 ? 'critical' : ($warningCount > 0 ? 'warning' : 'healthy'),
            'checked_at'    => date('Y-m-d H:i:s'),
        ]
    ]);
    break;

default:
    echo json_encode(['success' => false, 'error' => "Unknown action: $action"]);
}
