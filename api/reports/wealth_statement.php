<?php
/**
 * WealthDash — t313: Wealth Statement (CA-ready)
 * Comprehensive asset & liability snapshot for any given date
 * Actions: wealth_statement
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int) $currentUser['id'];
$action      = $_GET['action'] ?? $_POST['action'] ?? 'wealth_statement';

switch ($action) {

case 'wealth_statement':
    $asOf = $_GET['as_of'] ?? date('Y-m-d'); // default: today

    // ── Portfolio IDs for this user ───────────────────────────
    $portfolios   = DB::fetchAll('SELECT id, name FROM portfolios WHERE user_id = ?', [$userId]);
    $portfolioIds = array_column($portfolios, 'id');
    $pidList      = !empty($portfolioIds) ? implode(',', array_map('intval', $portfolioIds)) : '0';

    // ══════════════════════════════════════════════════════════
    // ASSETS
    // ══════════════════════════════════════════════════════════
    $assets = [];

    // ── Mutual Funds ──────────────────────────────────────────
    if (!empty($portfolioIds)) {
        $mfRows = DB::fetchAll(
            "SELECT
                CASE
                    WHEN f.category LIKE '%Equity%' OR f.category LIKE '%Index%' OR f.category LIKE '%ELSS%' THEN 'Equity MF'
                    WHEN f.category LIKE '%Debt%' OR f.category LIKE '%Liquid%' OR f.category LIKE '%Money Market%' OR f.category LIKE '%Bond%' THEN 'Debt MF'
                    WHEN f.category LIKE '%Hybrid%' OR f.category LIKE '%Balanced%' THEN 'Hybrid MF'
                    ELSE 'Other MF'
                END AS sub_category,
                COUNT(*) AS count,
                SUM(h.invested_amount) AS invested,
                SUM(h.value_now) AS current_value,
                SUM(h.value_now - h.invested_amount) AS gain_loss
             FROM mf_holdings h
             JOIN funds f ON f.id = h.fund_id
             WHERE h.portfolio_id IN ($pidList) AND h.is_active = 1
             GROUP BY sub_category",
            []
        );
        foreach ($mfRows as $r) {
            $assets[] = [
                'category'     => 'Mutual Funds',
                'sub_category' => $r['sub_category'],
                'count'        => (int)$r['count'],
                'invested'     => (float)($r['invested'] ?? 0),
                'current_value'=> (float)($r['current_value'] ?? 0),
                'gain_loss'    => (float)($r['gain_loss'] ?? 0),
                'type'         => 'equity',
            ];
        }
    }

    // ── Fixed Deposits ────────────────────────────────────────
    if (!empty($portfolioIds)) {
        $fdRow = DB::fetchRow(
            "SELECT COUNT(*) AS cnt,
                    SUM(principal_amount) AS invested,
                    SUM(maturity_amount) AS current_value,
                    SUM(maturity_amount - principal_amount) AS gain_loss
             FROM fd_accounts
             WHERE portfolio_id IN ($pidList) AND status = 'active'",
            []
        );
        if ($fdRow && $fdRow['cnt'] > 0) {
            $assets[] = [
                'category'     => 'Fixed Income',
                'sub_category' => 'Fixed Deposits',
                'count'        => (int)$fdRow['cnt'],
                'invested'     => (float)($fdRow['invested'] ?? 0),
                'current_value'=> (float)($fdRow['current_value'] ?? 0),
                'gain_loss'    => (float)($fdRow['gain_loss'] ?? 0),
                'type'         => 'debt',
            ];
        }
    }

    // ── Post Office Schemes ───────────────────────────────────
    if (!empty($portfolioIds)) {
        $poRows = DB::fetchAll(
            "SELECT scheme_type AS sub_category,
                    COUNT(*) AS cnt,
                    SUM(principal_amount) AS invested,
                    SUM(current_value) AS current_value
             FROM po_schemes
             WHERE portfolio_id IN ($pidList) AND status = 'active'
             GROUP BY scheme_type",
            []
        );
        foreach ($poRows as $r) {
            $assets[] = [
                'category'     => 'Govt / Post Office',
                'sub_category' => $r['sub_category'],
                'count'        => (int)$r['cnt'],
                'invested'     => (float)($r['invested'] ?? 0),
                'current_value'=> (float)($r['current_value'] ?? 0),
                'gain_loss'    => (float)($r['current_value'] ?? 0) - (float)($r['invested'] ?? 0),
                'type'         => 'debt',
            ];
        }
    }

    // ── NPS ────────────────────────────────────────────────────
    if (!empty($portfolioIds)) {
        $npsRow = DB::fetchRow(
            "SELECT COUNT(*) AS cnt,
                    SUM(invested_amount) AS invested,
                    SUM(current_value) AS current_value,
                    SUM(gain_loss) AS gain_loss
             FROM nps_holdings
             WHERE portfolio_id IN ($pidList)",
            []
        );
        if ($npsRow && $npsRow['cnt'] > 0) {
            $assets[] = [
                'category'     => 'Retirement',
                'sub_category' => 'NPS',
                'count'        => (int)$npsRow['cnt'],
                'invested'     => (float)($npsRow['invested'] ?? 0),
                'current_value'=> (float)($npsRow['current_value'] ?? 0),
                'gain_loss'    => (float)($npsRow['gain_loss'] ?? 0),
                'type'         => 'retirement',
            ];
        }
    }

    // ── Stocks ────────────────────────────────────────────────
    if (!empty($portfolioIds)) {
        $stkRow = DB::fetchRow(
            "SELECT COUNT(*) AS cnt,
                    SUM(invested_amount) AS invested,
                    SUM(current_value) AS current_value,
                    SUM(gain_loss) AS gain_loss
             FROM stock_holdings
             WHERE portfolio_id IN ($pidList) AND is_active = 1",
            []
        );
        if ($stkRow && $stkRow['cnt'] > 0) {
            $assets[] = [
                'category'     => 'Direct Equity',
                'sub_category' => 'Stocks',
                'count'        => (int)$stkRow['cnt'],
                'invested'     => (float)($stkRow['invested'] ?? 0),
                'current_value'=> (float)($stkRow['current_value'] ?? 0),
                'gain_loss'    => (float)($stkRow['gain_loss'] ?? 0),
                'type'         => 'equity',
            ];
        }
    }

    // ── Crypto ────────────────────────────────────────────────
    if (!empty($portfolioIds)) {
        $cryptoRow = DB::fetchRow(
            "SELECT COUNT(*) AS cnt,
                    SUM(invested_amount_inr) AS invested,
                    SUM(current_value) AS current_value
             FROM crypto_holdings
             WHERE portfolio_id IN ($pidList) AND is_active = 1",
            []
        );
        if ($cryptoRow && $cryptoRow['cnt'] > 0) {
            $cv = (float)($cryptoRow['current_value'] ?? 0);
            $inv = (float)($cryptoRow['invested'] ?? 0);
            $assets[] = [
                'category'     => 'Alternative',
                'sub_category' => 'Crypto',
                'count'        => (int)$cryptoRow['cnt'],
                'invested'     => $inv,
                'current_value'=> $cv,
                'gain_loss'    => $cv - $inv,
                'type'         => 'alternative',
            ];
        }
    }

    // ── SGB ────────────────────────────────────────────────────
    if (!empty($portfolioIds)) {
        $sgbRow = DB::fetchRow(
            "SELECT COUNT(*) AS cnt,
                    SUM(units * issue_price) AS invested,
                    SUM(current_value) AS current_value
             FROM sgb_holdings
             WHERE portfolio_id IN ($pidList) AND status = 'active'",
            []
        );
        if ($sgbRow && $sgbRow['cnt'] > 0) {
            $cv = (float)($sgbRow['current_value'] ?? 0);
            $inv = (float)($sgbRow['invested'] ?? 0);
            $assets[] = [
                'category'     => 'Alternative',
                'sub_category' => 'Sovereign Gold Bonds',
                'count'        => (int)$sgbRow['cnt'],
                'invested'     => $inv,
                'current_value'=> $cv,
                'gain_loss'    => $cv - $inv,
                'type'         => 'alternative',
            ];
        }
    }

    // ── Savings Accounts ─────────────────────────────────────
    if (!empty($portfolioIds)) {
        $savRow = DB::fetchRow(
            "SELECT COUNT(*) AS cnt, SUM(current_balance) AS current_value
             FROM savings_accounts
             WHERE portfolio_id IN ($pidList)",
            []
        );
        if ($savRow && $savRow['cnt'] > 0) {
            $assets[] = [
                'category'     => 'Liquid / Savings',
                'sub_category' => 'Savings Accounts',
                'count'        => (int)$savRow['cnt'],
                'invested'     => (float)($savRow['current_value'] ?? 0),
                'current_value'=> (float)($savRow['current_value'] ?? 0),
                'gain_loss'    => 0,
                'type'         => 'liquid',
            ];
        }
    }

    // ── ESOP / RSU ────────────────────────────────────────────
    if (!empty($portfolioIds)) {
        $esopRow = DB::fetchRow(
            "SELECT COUNT(*) AS cnt,
                    SUM(vested_options * exercise_price) AS invested,
                    SUM(vested_options * current_market_price) AS current_value
             FROM esop_grants
             WHERE portfolio_id IN ($pidList) AND status = 'active'",
            []
        );
        if ($esopRow && $esopRow['cnt'] > 0) {
            $cv = (float)($esopRow['current_value'] ?? 0);
            $inv = (float)($esopRow['invested'] ?? 0);
            $assets[] = [
                'category'     => 'Alternative',
                'sub_category' => 'ESOP / RSU',
                'count'        => (int)$esopRow['cnt'],
                'invested'     => $inv,
                'current_value'=> $cv,
                'gain_loss'    => $cv - $inv,
                'type'         => 'alternative',
            ];
        }
    }

    // ══════════════════════════════════════════════════════════
    // LIABILITIES
    // ══════════════════════════════════════════════════════════
    $liabilities = [];

    if (!empty($portfolioIds)) {
        $loanRows = DB::fetchAll(
            "SELECT loan_type AS sub_category,
                    COUNT(*) AS cnt,
                    SUM(outstanding_amount) AS outstanding,
                    SUM(original_amount) AS original
             FROM loan_accounts
             WHERE portfolio_id IN ($pidList) AND status = 'active'
             GROUP BY loan_type",
            []
        );
        foreach ($loanRows as $r) {
            $liabilities[] = [
                'sub_category' => ucfirst($r['sub_category']) . ' Loan',
                'count'        => (int)$r['cnt'],
                'original'     => (float)($r['original'] ?? 0),
                'outstanding'  => (float)($r['outstanding'] ?? 0),
            ];
        }
    }

    // ── Totals ────────────────────────────────────────────────
    $totalAssets      = array_sum(array_column($assets, 'current_value'));
    $totalInvested    = array_sum(array_column($assets, 'invested'));
    $totalGainLoss    = array_sum(array_column($assets, 'gain_loss'));
    $totalLiabilities = array_sum(array_column($liabilities, 'outstanding'));
    $netWorth         = $totalAssets - $totalLiabilities;
    $overallReturn    = $totalInvested > 0 ? ($totalGainLoss / $totalInvested * 100) : 0;

    // ── Group assets by category ──────────────────────────────
    $grouped = [];
    foreach ($assets as $a) {
        $cat = $a['category'];
        if (!isset($grouped[$cat])) {
            $grouped[$cat] = ['items' => [], 'total_current' => 0, 'total_invested' => 0, 'total_gain' => 0];
        }
        $grouped[$cat]['items'][]         = $a;
        $grouped[$cat]['total_current']  += $a['current_value'];
        $grouped[$cat]['total_invested'] += $a['invested'];
        $grouped[$cat]['total_gain']     += $a['gain_loss'];
    }

    // ── Asset allocation % ────────────────────────────────────
    $allocation = [];
    if ($totalAssets > 0) {
        foreach ($grouped as $cat => $g) {
            $allocation[] = [
                'label'   => $cat,
                'value'   => round($g['total_current'], 2),
                'pct'     => round($g['total_current'] / $totalAssets * 100, 1),
            ];
        }
        usort($allocation, fn($a, $b) => $b['value'] <=> $a['value']);
    }

    // ── Equity vs Debt split ──────────────────────────────────
    $equityTotal = 0; $debtTotal = 0; $altTotal = 0; $retirementTotal = 0; $liquidTotal = 0;
    foreach ($assets as $a) {
        match ($a['type']) {
            'equity'     => $equityTotal     += $a['current_value'],
            'debt'       => $debtTotal       += $a['current_value'],
            'alternative'=> $altTotal        += $a['current_value'],
            'retirement' => $retirementTotal += $a['current_value'],
            'liquid'     => $liquidTotal     += $a['current_value'],
            default      => null,
        };
    }

    json_response(true, '', [
        'as_of'          => $asOf,
        'user_name'      => $currentUser['name'] ?? '',
        'portfolio_count'=> count($portfolios),
        'assets'         => $grouped,
        'liabilities'    => $liabilities,
        'summary' => [
            'total_assets'      => round($totalAssets, 2),
            'total_invested'    => round($totalInvested, 2),
            'total_gain_loss'   => round($totalGainLoss, 2),
            'overall_return_pct'=> round($overallReturn, 2),
            'total_liabilities' => round($totalLiabilities, 2),
            'net_worth'         => round($netWorth, 2),
        ],
        'allocation'     => $allocation,
        'type_split' => [
            'equity'     => round($equityTotal, 2),
            'debt'       => round($debtTotal, 2),
            'alternative'=> round($altTotal, 2),
            'retirement' => round($retirementTotal, 2),
            'liquid'     => round($liquidTotal, 2),
        ],
    ]);
    break;

default:
    json_response(false, 'Unknown action.', [], 400);
}
