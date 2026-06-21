<?php
/**
 * WealthDash — t442: Unified Dashboard — All Assets
 * Actions:
 *   unified_summary      — Net worth + all asset class totals + allocation
 *   unified_activity     — Recent transactions / events across all modules
 *   unified_alerts       — Active alerts: FD maturity, SIP dues, goal gaps
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'unified_summary';
$db          = DB::conn();

// ── Portfolio IDs helper ──────────────────────────────────────────────────────
function ud_get_portfolio_ids(int $userId): array {
    $rows = DB::fetchAll('SELECT id FROM portfolios WHERE user_id = ?', [$userId]);
    return array_column($rows, 'id');
}

function ud_pid_list(array $ids): string {
    return empty($ids) ? '0' : implode(',', array_map('intval', $ids));
}

// ── Safe float helper ─────────────────────────────────────────────────────────
function ud_f(mixed $v): float { return round((float)($v ?? 0), 2); }

switch ($action) {

// ════════════════════════════════════════════════════════════════════════════
// unified_summary — Master asset summary across all modules
// ════════════════════════════════════════════════════════════════════════════
case 'unified_summary':
    $pids    = ud_get_portfolio_ids($userId);
    $pidList = ud_pid_list($pids);

    // ── Cache check (tp001) ── invalidated on any asset write ────────────────
    $cacheKey = "unified_summary:{$userId}";
    $cached   = WdCache::get($cacheKey);
    if ($cached !== null) {
        json_response(true, '', $cached);
        break;
    }

    $assets = []; // keyed by asset type

    // ── Mutual Funds ──────────────────────────────────────────────────────────
    if (!empty($pids)) {
        $mf = DB::fetchRow(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(h.invested_amount), 0) AS invested,
                    COALESCE(SUM(h.value_now), 0) AS current_value,
                    COALESCE(SUM(h.value_now - h.invested_amount), 0) AS gain_loss,
                    COALESCE(SUM(h.daily_change), 0) AS daily_change
             FROM mf_holdings h
             WHERE h.portfolio_id IN ($pidList) AND h.is_active = 1",
            []
        ) ?: [];
        if (!empty($mf) && ud_f($mf['current_value']) > 0) {
            $assets['mf'] = [
                'key'           => 'mf',
                'label'         => 'Mutual Funds',
                'icon'          => '📊',
                'color'         => '#6366f1',
                'count'         => (int)($mf['cnt'] ?? 0),
                'invested'      => ud_f($mf['invested']),
                'current_value' => ud_f($mf['current_value']),
                'gain_loss'     => ud_f($mf['gain_loss']),
                'daily_change'  => ud_f($mf['daily_change']),
                'url'           => 'mf_holdings',
                'add_label'     => 'Add MF',
                'add_action'    => 'mf_add',
            ];
        }
    }

    // ── Stocks ────────────────────────────────────────────────────────────────
    if (!empty($pids)) {
        $stk = DB::fetchRow(
            "SELECT COUNT(DISTINCT stock_id) AS cnt,
                    COALESCE(SUM(invested_amount), 0) AS invested,
                    COALESCE(SUM(current_value), 0) AS current_value,
                    COALESCE(SUM(gain_loss), 0) AS gain_loss
             FROM stock_holdings
             WHERE portfolio_id IN ($pidList) AND is_active = 1",
            []
        ) ?: [];
        if (!empty($stk) && ud_f($stk['current_value']) > 0) {
            $assets['stocks'] = [
                'key'           => 'stocks',
                'label'         => 'Stocks & ETF',
                'icon'          => '📈',
                'color'         => '#22c55e',
                'count'         => (int)($stk['cnt'] ?? 0),
                'invested'      => ud_f($stk['invested']),
                'current_value' => ud_f($stk['current_value']),
                'gain_loss'     => ud_f($stk['gain_loss']),
                'daily_change'  => 0,
                'url'           => 'stocks',
                'add_label'     => 'Add Stock',
                'add_action'    => 'stocks_add',
            ];
        }
    }

    // ── NPS ───────────────────────────────────────────────────────────────────
    if (!empty($pids)) {
        $nps = DB::fetchRow(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(invested_amount), 0) AS invested,
                    COALESCE(SUM(current_value), 0) AS current_value,
                    COALESCE(SUM(gain_loss), 0) AS gain_loss
             FROM nps_holdings
             WHERE portfolio_id IN ($pidList)",
            []
        ) ?: [];
        if (!empty($nps) && ud_f($nps['current_value']) > 0) {
            $assets['nps'] = [
                'key'           => 'nps',
                'label'         => 'NPS',
                'icon'          => '🏦',
                'color'         => '#f59e0b',
                'count'         => (int)($nps['cnt'] ?? 0),
                'invested'      => ud_f($nps['invested']),
                'current_value' => ud_f($nps['current_value']),
                'gain_loss'     => ud_f($nps['gain_loss']),
                'daily_change'  => 0,
                'url'           => 'nps',
                'add_label'     => 'Add NPS',
                'add_action'    => 'nps_add',
            ];
        }
    }

    // ── Fixed Deposits ────────────────────────────────────────────────────────
    if (!empty($pids)) {
        $fd = DB::fetchRow(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(principal_amount), 0) AS invested,
                    COALESCE(SUM(maturity_amount), 0) AS current_value,
                    COALESCE(SUM(maturity_amount - principal_amount), 0) AS gain_loss
             FROM fd_accounts
             WHERE portfolio_id IN ($pidList) AND status = 'active'",
            []
        ) ?: [];
        if (!empty($fd) && ud_f($fd['current_value']) > 0) {
            $assets['fd'] = [
                'key'           => 'fd',
                'label'         => 'Fixed Deposits',
                'icon'          => '🏛️',
                'color'         => '#3b82f6',
                'count'         => (int)($fd['cnt'] ?? 0),
                'invested'      => ud_f($fd['invested']),
                'current_value' => ud_f($fd['current_value']),
                'gain_loss'     => ud_f($fd['gain_loss']),
                'daily_change'  => 0,
                'url'           => 'fd',
                'add_label'     => 'Add FD',
                'add_action'    => 'fd_add',
            ];
        }
    }

    // ── Post Office Schemes ───────────────────────────────────────────────────
    if (!empty($pids)) {
        $po = DB::fetchRow(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(principal_amount), 0) AS invested,
                    COALESCE(SUM(current_value), 0) AS current_value
             FROM po_schemes
             WHERE portfolio_id IN ($pidList) AND status = 'active'",
            []
        ) ?: [];
        if (!empty($po) && ud_f($po['current_value']) > 0) {
            $assets['post_office'] = [
                'key'           => 'post_office',
                'label'         => 'Post Office',
                'icon'          => '📮',
                'color'         => '#ef4444',
                'count'         => (int)($po['cnt'] ?? 0),
                'invested'      => ud_f($po['invested']),
                'current_value' => ud_f($po['current_value']),
                'gain_loss'     => ud_f($po['current_value']) - ud_f($po['invested']),
                'daily_change'  => 0,
                'url'           => 'post_office',
                'add_label'     => 'Add Scheme',
                'add_action'    => 'po_add',
            ];
        }
    }

    // ── Savings Accounts ──────────────────────────────────────────────────────
    if (!empty($pids)) {
        $sav = DB::fetchRow(
            "SELECT COUNT(*) AS cnt, COALESCE(SUM(current_balance), 0) AS current_value
             FROM savings_accounts
             WHERE portfolio_id IN ($pidList)",
            []
        ) ?: [];
        if (!empty($sav) && ud_f($sav['current_value']) > 0) {
            $assets['savings'] = [
                'key'           => 'savings',
                'label'         => 'Savings',
                'icon'          => '💰',
                'color'         => '#06b6d4',
                'count'         => (int)($sav['cnt'] ?? 0),
                'invested'      => ud_f($sav['current_value']),
                'current_value' => ud_f($sav['current_value']),
                'gain_loss'     => 0,
                'daily_change'  => 0,
                'url'           => 'savings',
                'add_label'     => 'Add Account',
                'add_action'    => 'savings_add',
            ];
        }
    }

    // ── Crypto ────────────────────────────────────────────────────────────────
    if (!empty($pids)) {
        $crypto = DB::fetchRow(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(invested_amount_inr), 0) AS invested,
                    COALESCE(SUM(current_value), 0) AS current_value
             FROM crypto_holdings
             WHERE portfolio_id IN ($pidList) AND is_active = 1",
            []
        ) ?: [];
        if (!empty($crypto) && ud_f($crypto['current_value']) > 0) {
            $cv  = ud_f($crypto['current_value']);
            $inv = ud_f($crypto['invested']);
            $assets['crypto'] = [
                'key'           => 'crypto',
                'label'         => 'Crypto',
                'icon'          => '₿',
                'color'         => '#f97316',
                'count'         => (int)($crypto['cnt'] ?? 0),
                'invested'      => $inv,
                'current_value' => $cv,
                'gain_loss'     => $cv - $inv,
                'daily_change'  => 0,
                'url'           => 'crypto',
                'add_label'     => 'Add Crypto',
                'add_action'    => 'crypto_add',
            ];
        }
    }

    // ── Physical Gold ─────────────────────────────────────────────────────────
    if (!empty($pids)) {
        $gold = DB::fetchRow(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(purchase_price), 0) AS invested,
                    COALESCE(SUM(current_value), 0) AS current_value
             FROM physical_gold
             WHERE portfolio_id IN ($pidList) AND is_active = 1",
            []
        ) ?: [];
        // Also SGB
        $sgb = DB::fetchRow(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(units * issue_price), 0) AS invested,
                    COALESCE(SUM(current_value), 0) AS current_value
             FROM sgb_holdings
             WHERE portfolio_id IN ($pidList) AND status = 'active'",
            []
        ) ?: [];
        $goldCv  = ud_f($gold['current_value'] ?? 0) + ud_f($sgb['current_value'] ?? 0);
        $goldInv = ud_f($gold['invested'] ?? 0) + ud_f($sgb['invested'] ?? 0);
        $goldCnt = (int)($gold['cnt'] ?? 0) + (int)($sgb['cnt'] ?? 0);
        if ($goldCv > 0) {
            $assets['gold'] = [
                'key'           => 'gold',
                'label'         => 'Gold & SGB',
                'icon'          => '🥇',
                'color'         => '#eab308',
                'count'         => $goldCnt,
                'invested'      => $goldInv,
                'current_value' => $goldCv,
                'gain_loss'     => $goldCv - $goldInv,
                'daily_change'  => 0,
                'url'           => 'gold',
                'add_label'     => 'Add Gold',
                'add_action'    => 'gold_add',
            ];
        }
    }

    // ── Real Estate ───────────────────────────────────────────────────────────
    if (!empty($pids)) {
        $re = DB::fetchRow(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(purchase_price), 0) AS invested,
                    COALESCE(SUM(current_value * ownership_pct / 100), 0) AS current_value,
                    COALESCE(SUM(outstanding_loan), 0) AS total_loan
             FROM real_estate
             WHERE portfolio_id IN ($pidList) AND is_active = 1",
            []
        ) ?: [];
        if (!empty($re) && ud_f($re['current_value']) > 0) {
            $cv  = ud_f($re['current_value']);
            $inv = ud_f($re['invested']);
            $assets['real_estate'] = [
                'key'           => 're',
                'label'         => 'Real Estate',
                'icon'          => '🏠',
                'color'         => '#8b5cf6',
                'count'         => (int)($re['cnt'] ?? 0),
                'invested'      => $inv,
                'current_value' => $cv,
                'gain_loss'     => $cv - $inv,
                'daily_change'  => 0,
                'outstanding_loan' => ud_f($re['total_loan']),
                'url'           => 'realestate',
                'add_label'     => 'Add Property',
                'add_action'    => 're_add',
            ];
        }
    }

    // ── EPF / PF ──────────────────────────────────────────────────────────────
    if (!empty($pids)) {
        $epf = DB::fetchRow(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(current_balance), 0) AS current_value,
                    COALESCE(SUM(employee_contribution + employer_contribution), 0) AS invested
             FROM epf_accounts
             WHERE portfolio_id IN ($pidList) AND is_active = 1",
            []
        ) ?: [];
        if (!empty($epf) && ud_f($epf['current_value']) > 0) {
            $cv  = ud_f($epf['current_value']);
            $inv = ud_f($epf['invested']);
            $assets['epf'] = [
                'key'           => 'epf',
                'label'         => 'EPF / PF',
                'icon'          => '👷',
                'color'         => '#10b981',
                'count'         => (int)($epf['cnt'] ?? 0),
                'invested'      => $inv > 0 ? $inv : $cv,
                'current_value' => $cv,
                'gain_loss'     => $cv - ($inv > 0 ? $inv : $cv),
                'daily_change'  => 0,
                'url'           => 'epf',
                'add_label'     => 'Update EPF',
                'add_action'    => 'epf_update',
            ];
        }
    }

    // ── Insurance (annual premium tracker) ───────────────────────────────────
    if (!empty($pids)) {
        $ins = DB::fetchRow(
            "SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(annual_premium), 0) AS annual_premium,
                    COALESCE(SUM(sum_assured), 0) AS sum_assured
             FROM insurance_policies
             WHERE portfolio_id IN ($pidList) AND status = 'active'",
            []
        ) ?: [];
        if (!empty($ins) && (int)($ins['cnt'] ?? 0) > 0) {
            $assets['insurance'] = [
                'key'           => 'insurance',
                'label'         => 'Insurance',
                'icon'          => '🛡️',
                'color'         => '#64748b',
                'count'         => (int)($ins['cnt'] ?? 0),
                'invested'      => ud_f($ins['annual_premium']),
                'current_value' => ud_f($ins['sum_assured']),
                'gain_loss'     => 0,
                'daily_change'  => 0,
                'sum_assured'   => ud_f($ins['sum_assured']),
                'annual_premium'=> ud_f($ins['annual_premium']),
                'is_protection' => true,
                'url'           => 'insurance',
                'add_label'     => 'Add Policy',
                'add_action'    => 'insurance_add',
            ];
        }
    }

    // ── Totals & allocation ───────────────────────────────────────────────────
    // Insurance sum_assured is protection, exclude from NW; use invested (premium) only
    $totalValue    = 0.0;
    $totalInvested = 0.0;
    $totalGain     = 0.0;
    $totalDaily    = 0.0;
    $allocationData = [];

    foreach ($assets as $key => $a) {
        $cv   = $a['current_value'];
        $inv  = $a['invested'];
        $gain = $a['gain_loss'];
        $daily= $a['daily_change'];

        if (!($a['is_protection'] ?? false)) {
            $totalValue    += $cv;
            $totalInvested += $inv;
            $totalGain     += $gain;
            $totalDaily    += $daily;
        }
        if ($cv > 0) {
            $allocationData[] = [
                'key'   => $key,
                'label' => $a['label'],
                'value' => $cv,
                'color' => $a['color'],
            ];
        }
    }

    // Sort allocation by value desc
    usort($allocationData, fn($a, $b) => $b['value'] <=> $a['value']);

    if ($totalValue > 0) {
        foreach ($allocationData as &$ad) {
            $ad['pct'] = round($ad['value'] / $totalValue * 100, 1);
        }
        unset($ad);
    }

    // ── Liabilities ───────────────────────────────────────────────────────────
    $totalLoan = 0.0;
    if (!empty($pids)) {
        $loanRow = DB::fetchRow(
            "SELECT COALESCE(SUM(outstanding_amount), 0) AS total
             FROM loan_accounts
             WHERE portfolio_id IN ($pidList) AND status = 'active'",
            []
        );
        $totalLoan = ud_f($loanRow['total'] ?? 0);
    }

    $netWorth    = $totalValue - $totalLoan;
    $gainPct     = $totalInvested > 0 ? round($totalGain / $totalInvested * 100, 2) : 0.0;
    $dailyPct    = ($totalValue - $totalDaily) > 0 ? round($totalDaily / ($totalValue - $totalDaily) * 100, 2) : 0.0;

    json_response(true, '', [
        'net_worth'       => round($netWorth, 2),
        'total_assets'    => round($totalValue, 2),
        'total_invested'  => round($totalInvested, 2),
        'total_gain'      => round($totalGain, 2),
        'gain_pct'        => $gainPct,
        'daily_change'    => round($totalDaily, 2),
        'daily_pct'       => $dailyPct,
        'total_loan'      => round($totalLoan, 2),
        'assets'          => array_values($assets),
        'allocation'      => $allocationData,
        'portfolio_count' => count($pids),
        '_cached'         => false,
    ]);
    // Store in cache — 2 min TTL; tagged so any asset write can purge it
    WdCache::set($cacheKey, [
        'net_worth'       => round($netWorth, 2),
        'total_assets'    => round($totalValue, 2),
        'total_invested'  => round($totalInvested, 2),
        'total_gain'      => round($totalGain, 2),
        'gain_pct'        => $gainPct,
        'daily_change'    => round($totalDaily, 2),
        'daily_pct'       => $dailyPct,
        'total_loan'      => round($totalLoan, 2),
        'assets'          => array_values($assets),
        'allocation'      => $allocationData,
        'portfolio_count' => count($pids),
        '_cached'         => true,
    ], ttl: 120, tags: ["user:{$userId}"]);
    break;

// ════════════════════════════════════════════════════════════════════════════
// unified_activity — Recent transactions / events across all modules
// ════════════════════════════════════════════════════════════════════════════
case 'unified_activity':
    $pids    = ud_get_portfolio_ids($userId);
    $pidList = ud_pid_list($pids);
    $limit   = max(1, min(50, (int)($_GET['limit'] ?? $_POST['limit'] ?? 20)));

    $activity = [];

    if (!empty($pids)) {
        // MF transactions
        $mfTxns = DB::fetchAll(
            "SELECT t.txn_date AS date, f.scheme_name AS name, t.transaction_type AS type,
                    t.units, t.nav, t.value_at_cost AS amount, 'mf' AS module,
                    fh.short_name AS sub_name
             FROM mf_transactions t
             JOIN mf_holdings h ON h.id = t.holding_id
             JOIN funds f ON f.id = h.fund_id
             JOIN fund_houses fh ON fh.id = f.fund_house_id
             WHERE h.portfolio_id IN ($pidList)
             ORDER BY t.txn_date DESC LIMIT 10",
            []
        );
        foreach ($mfTxns as $r) {
            $activity[] = [
                'date'    => $r['date'],
                'module'  => 'Mutual Fund',
                'icon'    => '📊',
                'color'   => '#6366f1',
                'name'    => $r['name'],
                'sub'     => $r['sub_name'],
                'type'    => strtoupper($r['type']),
                'amount'  => ud_f($r['amount']),
                'detail'  => $r['units'] ? round($r['units'], 4) . ' units @ ₹' . $r['nav'] : '',
                'is_positive' => in_array(strtoupper($r['type']), ['BUY','SIP','LUMPSUM','SWITCH_IN','DIV_REINVEST']),
            ];
        }

        // Stock transactions
        $stkTxns = DB::fetchAll(
            "SELECT t.txn_date AS date, sm.symbol AS name, t.txn_type AS type,
                    t.quantity, t.price, (t.quantity * t.price) AS amount, t.brokerage,
                    sm.company_name AS sub_name
             FROM stock_transactions t
             JOIN stock_master sm ON sm.id = t.stock_id
             WHERE t.portfolio_id IN ($pidList)
             ORDER BY t.txn_date DESC LIMIT 5",
            []
        );
        foreach ($stkTxns as $r) {
            $activity[] = [
                'date'    => $r['date'],
                'module'  => 'Stock',
                'icon'    => '📈',
                'color'   => '#22c55e',
                'name'    => $r['name'],
                'sub'     => $r['sub_name'],
                'type'    => strtoupper($r['type']),
                'amount'  => ud_f($r['amount']),
                'detail'  => $r['quantity'] . ' shares @ ₹' . $r['price'],
                'is_positive' => strtoupper($r['type']) === 'BUY',
            ];
        }

        // FD additions
        $fdAdded = DB::fetchAll(
            "SELECT open_date AS date, bank_name AS name, 'OPENED' AS type,
                    principal_amount AS amount, interest_rate, maturity_date,
                    tenure_days, 'fd' AS module
             FROM fd_accounts
             WHERE portfolio_id IN ($pidList) AND status = 'active'
             ORDER BY created_at DESC LIMIT 5",
            []
        );
        foreach ($fdAdded as $r) {
            $activity[] = [
                'date'    => $r['date'],
                'module'  => 'FD',
                'icon'    => '🏛️',
                'color'   => '#3b82f6',
                'name'    => $r['name'],
                'sub'     => 'Matures ' . (new DateTime($r['maturity_date']))->format('d M Y'),
                'type'    => 'OPENED',
                'amount'  => ud_f($r['amount']),
                'detail'  => $r['interest_rate'] . '% p.a.',
                'is_positive' => true,
            ];
        }
    }

    // Sort all by date descending
    usort($activity, fn($a, $b) => strcmp($b['date'], $a['date']));
    $activity = array_slice($activity, 0, $limit);

    json_response(true, '', ['activity' => $activity]);
    break;

// ════════════════════════════════════════════════════════════════════════════
// unified_alerts — t404: Smart Alerts v2
// Alerts: FD/SIP/PO maturity, NAV drop, LTCG grandfathering, goal gap, large loss, ITR deadline
// ════════════════════════════════════════════════════════════════════════════
case 'unified_alerts':
    $pids    = ud_get_portfolio_ids($userId);
    $pidList = ud_pid_list($pids);
    $alerts  = [];
    $today   = new DateTime();

    // ── Cache (tp001): 15 min TTL — alerts don't need to be real-time ──────
    $alertCacheKey = "unified_alerts:{$userId}";
    $cachedAlerts  = WdCache::get($alertCacheKey);
    if ($cachedAlerts !== null) {
        json_response(true, '', $cachedAlerts);
        break;
    }

    if (!empty($pids)) {
        // ── 1. FD maturing in 60 days ─────────────────────────────────────
        $fdMature = DB::fetchAll(
            "SELECT bank_name, principal_amount, maturity_amount, maturity_date, interest_rate,
                    DATEDIFF(maturity_date, CURDATE()) AS days_left
             FROM fd_accounts
             WHERE portfolio_id IN ($pidList) AND status = 'active'
               AND maturity_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
             ORDER BY maturity_date ASC LIMIT 5",
            []
        );
        foreach ($fdMature as $r) {
            $dl = (int)$r['days_left'];
            $matAmt = $r['maturity_amount'] ?: $r['principal_amount'];
            $alerts[] = [
                'type'    => 'fd_maturity',
                'level'   => $dl <= 7 ? 'critical' : ($dl <= 30 ? 'warning' : 'info'),
                'icon'    => '🏛️',
                'title'   => 'FD Maturing: ' . $r['bank_name'],
                'message' => '₹' . number_format($r['principal_amount'], 0, '.', ',') . ' @ ' . $r['interest_rate'] . '% → ₹' . number_format($matAmt, 0, '.', ',') . ' in ' . $dl . ' day' . ($dl === 1 ? '' : 's'),
                'date'    => $r['maturity_date'],
                'url'     => 'fd',
                'action'  => 'Renew FD',
            ];
        }

        // ── 2. SIPs due in next 7 days ────────────────────────────────────
        $sipsDue = DB::fetchAll(
            "SELECT s.sip_name, s.amount, s.next_debit_date, f.scheme_name,
                    DATEDIFF(s.next_debit_date, CURDATE()) AS days_left
             FROM sip_registrations s
             JOIN mf_holdings h ON h.id = s.holding_id
             JOIN funds f ON f.id = h.fund_id
             WHERE h.portfolio_id IN ($pidList) AND s.status = 'active'
               AND s.next_debit_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
             ORDER BY s.next_debit_date ASC LIMIT 5",
            []
        );
        foreach ($sipsDue as $r) {
            $dl = (int)$r['days_left'];
            $alerts[] = [
                'type'    => 'sip_due',
                'level'   => 'info',
                'icon'    => '📅',
                'title'   => 'SIP Due: ' . mb_substr($r['scheme_name'], 0, 40),
                'message' => '₹' . number_format($r['amount'], 0, '.', ',') . ' debit ' . ($dl === 0 ? 'today' : 'in ' . $dl . ' day' . ($dl === 1 ? '' : 's')),
                'date'    => $r['next_debit_date'],
                'url'     => 'report_sip',
            ];
        }

        // ── 3. Post Office maturing in 90 days ────────────────────────────
        $poMature = DB::fetchAll(
            "SELECT scheme_type, principal_amount, maturity_date,
                    DATEDIFF(maturity_date, CURDATE()) AS days_left
             FROM po_schemes
             WHERE portfolio_id IN ($pidList) AND status = 'active'
               AND maturity_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
             ORDER BY maturity_date ASC LIMIT 3",
            []
        );
        foreach ($poMature as $r) {
            $dl = (int)$r['days_left'];
            $alerts[] = [
                'type'    => 'po_maturity',
                'level'   => $dl <= 30 ? 'warning' : 'info',
                'icon'    => '📮',
                'title'   => strtoupper($r['scheme_type']) . ' Maturing',
                'message' => '₹' . number_format($r['principal_amount'], 0, '.', ',') . ' matures in ' . $dl . ' days',
                'date'    => $r['maturity_date'],
                'url'     => 'post_office',
            ];
        }

        // ── 4. NAV drop alert: MF holdings down >3% in 1 day ─────────────
        // Uses 1-day NAV change from mf_holdings.current_nav vs mf_holdings.prev_nav
        $navDrops = DB::fetchAll(
            "SELECT f.scheme_name, h.current_nav, h.prev_nav, h.current_value,
                    ROUND(((h.current_nav - h.prev_nav) / h.prev_nav) * 100, 2) AS nav_chg_pct
             FROM mf_holdings h
             JOIN funds f ON f.id = h.fund_id
             WHERE h.portfolio_id IN ($pidList)
               AND h.prev_nav > 0 AND h.current_nav > 0
               AND ((h.current_nav - h.prev_nav) / h.prev_nav) * 100 < -3
             ORDER BY nav_chg_pct ASC LIMIT 4",
            []
        );
        foreach ($navDrops as $r) {
            $pct = abs((float)$r['nav_chg_pct']);
            $alerts[] = [
                'type'    => 'nav_drop',
                'level'   => $pct >= 5 ? 'critical' : 'warning',
                'icon'    => '📉',
                'title'   => 'NAV Drop: ' . mb_substr($r['scheme_name'], 0, 38),
                'message' => number_format($pct, 2) . '% drop today · Current NAV ₹' . number_format($r['current_nav'], 4) . ' · Value ₹' . number_format($r['current_value'], 0, '.', ','),
                'date'    => date('Y-m-d'),
                'url'     => 'mf_holdings',
                'action'  => 'View Holdings',
            ];
        }

        // ── 5. Goal gap alert: goals with corpus < 70% of target ──────────
        $goalGaps = DB::fetchAll(
            "SELECT g.name, g.target_amount, g.current_amount, g.target_date,
                    ROUND((g.current_amount / g.target_amount) * 100, 1) AS progress_pct,
                    DATEDIFF(g.target_date, CURDATE()) AS days_left
             FROM goals g
             WHERE g.user_id = ?
               AND g.status = 'active'
               AND g.target_date > CURDATE()
               AND g.target_amount > 0
               AND (g.current_amount / g.target_amount) < 0.7
               AND DATEDIFF(g.target_date, CURDATE()) < 365
             ORDER BY days_left ASC LIMIT 3",
            [$userId]
        );
        foreach ($goalGaps as $r) {
            $pct  = (float)$r['progress_pct'];
            $days = (int)$r['days_left'];
            $need = $r['target_amount'] - $r['current_amount'];
            $alerts[] = [
                'type'    => 'goal_gap',
                'level'   => $pct < 40 ? 'critical' : 'warning',
                'icon'    => '🎯',
                'title'   => 'Goal Behind: ' . $r['name'],
                'message' => number_format($pct, 1) . '% achieved · Need ₹' . number_format($need, 0, '.', ',') . ' more · ' . $days . ' days left',
                'date'    => $r['target_date'],
                'url'     => 'goals',
                'action'  => 'View Goals',
            ];
        }

        // ── 6. LTCG tax-free limit nearing — stocks gains close to ₹1.25L ─
        $currFy = date('m') >= 4 ? date('Y') . '-' . (date('Y') + 1) : (date('Y') - 1) . '-' . date('Y');
        [$fyStart] = explode('-', $currFy);
        $ltcgGains = DB::fetchOne(
            "SELECT COALESCE(SUM(
                CASE WHEN txn_type='SELL' AND holding_period_days >= 365
                     THEN (sell_price - buy_price) * quantity ELSE 0 END), 0) AS ltcg_gains
             FROM stock_transactions
             WHERE portfolio_id IN ($pidList)
               AND txn_date >= '{$fyStart}-04-01'
               AND txn_type = 'SELL'",
            []
        );
        $ltcgAmt = (float)($ltcgGains['ltcg_gains'] ?? 0);
        $ltcgLimit = 125000;
        if ($ltcgAmt > $ltcgLimit * 0.8 && $ltcgAmt < $ltcgLimit * 1.5) {
            $remaining = max(0, $ltcgLimit - $ltcgAmt);
            $alerts[] = [
                'type'    => 'ltcg_limit',
                'level'   => $ltcgAmt > $ltcgLimit ? 'critical' : 'warning',
                'icon'    => '🌾',
                'title'   => $ltcgAmt > $ltcgLimit ? 'LTCG Limit Crossed ₹1.25L' : 'LTCG Approaching ₹1.25L Limit',
                'message' => 'FY gains: ₹' . number_format($ltcgAmt, 0, '.', ',') . ($remaining > 0 ? ' · ₹' . number_format($remaining, 0, '.', ',') . ' tax-free room left' : ' · Pay 12.5% on gains above limit'),
                'date'    => $fyStart . '-03-31',
                'url'     => 'ltcg_harvesting',
                'action'  => 'View LTCG Report',
            ];
        }

        // ── 7. SGB maturing in 180 days (8-year tenure) ───────────────────
        $sgbMature = DB::fetchAll(
            "SELECT issue_name, units, issue_price,
                    DATEDIFF(maturity_date, CURDATE()) AS days_left,
                    maturity_date
             FROM sgb_holdings
             WHERE portfolio_id IN ($pidList)
               AND maturity_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 180 DAY)
             ORDER BY maturity_date ASC LIMIT 3",
            []
        );
        foreach ($sgbMature as $r) {
            $dl = (int)$r['days_left'];
            $val = round($r['units'] * $r['issue_price'], 0);
            $alerts[] = [
                'type'    => 'sgb_maturity',
                'level'   => $dl <= 30 ? 'warning' : 'info',
                'icon'    => '🪙',
                'title'   => 'SGB Maturing: ' . $r['issue_name'],
                'message' => $r['units'] . ' units · Face ₹' . number_format($val, 0, '.', ',') . ' · Maturity tax-free in ' . $dl . ' days',
                'date'    => $r['maturity_date'],
                'url'     => 'sgb',
            ];
        }

        // ── 8. ITR deadline reminder (31 July) ────────────────────────────
        $itrDeadline = new DateTime(date('Y') . '-07-31');
        $itrDays     = $today->diff($itrDeadline)->days;
        $itrPast     = $today > $itrDeadline;
        if (!$itrPast && $itrDays <= 60) {
            $alerts[] = [
                'type'    => 'itr_deadline',
                'level'   => $itrDays <= 14 ? 'critical' : ($itrDays <= 30 ? 'warning' : 'info'),
                'icon'    => '📝',
                'title'   => 'ITR Filing Deadline',
                'message' => 'Income Tax Return due 31 July · ' . $itrDays . ' days remaining · Check your capital gains report',
                'date'    => date('Y') . '-07-31',
                'url'     => 'report_fy',
                'action'  => 'View FY Report',
            ];
        }
    }

    // ── Sort: critical → warning → info, then by date ─────────────────────
    $order = ['critical' => 0, 'warning' => 1, 'info' => 2];
    usort($alerts, function ($a, $b) use ($order) {
        $lv = ($order[$a['level']] ?? 9) <=> ($order[$b['level']] ?? 9);
        return $lv !== 0 ? $lv : strcmp($a['date'] ?? '', $b['date'] ?? '');
    });

    $payload = ['alerts' => $alerts, 'count' => count($alerts)];
    WdCache::set($alertCacheKey, $payload, ttl: 900, tags: ["user:{$userId}"]);  // 15 min
    json_response(true, '', $payload);
    break;

default:
    json_response(false, 'Unknown action.', [], 400);
}
