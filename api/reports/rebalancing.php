<?php
/**
 * WealthDash — Portfolio Rebalancing Suggestions
 * POST /api/?action=report_rebalancing
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$portfolioId  = (int)($_POST['portfolio_id'] ?? 0);
if (!$portfolioId) $portfolioId = get_user_portfolio_id((int)($currentUser['id'] ?? 0));
// Target allocation from POST (user can customise), else use defaults
$targetEquity = (float)($_POST['target_equity'] ?? 60.0);
$targetDebt   = (float)($_POST['target_debt']   ?? 30.0);
$targetGold   = (float)($_POST['target_gold']   ?? 5.0);
$targetOther  = 100.0 - $targetEquity - $targetDebt - $targetGold; // NPS + Savings

if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
    json_response(false, 'Invalid or inaccessible portfolio.');
}

/* ─── Get current values ─────────────────────────────────────────────────── */
$equityMf = (float)DB::fetchVal(
    "SELECT COALESCE(SUM(h.value_now),0)
     FROM mf_holdings h JOIN funds f ON f.id = h.fund_id
     WHERE h.portfolio_id = ? AND h.is_active = 1
       AND f.category NOT LIKE '%Debt%' AND f.category NOT LIKE '%Liquid%'
       AND f.category NOT LIKE '%Money Market%' AND f.category NOT LIKE '%Credit%'
       AND f.category NOT LIKE '%Gilt%' AND f.category NOT LIKE '%Gold%'
       AND f.category NOT LIKE '%Banking and PSU%'",
    [$portfolioId]
);
$debtMf = (float)DB::fetchVal(
    "SELECT COALESCE(SUM(h.value_now),0)
     FROM mf_holdings h JOIN funds f ON f.id = h.fund_id
     WHERE h.portfolio_id = ? AND h.is_active = 1
       AND (f.category LIKE '%Debt%' OR f.category LIKE '%Liquid%'
            OR f.category LIKE '%Money Market%' OR f.category LIKE '%Credit%'
            OR f.category LIKE '%Gilt%' OR f.category LIKE '%Banking and PSU%')",
    [$portfolioId]
);
$goldMf = (float)DB::fetchVal(
    "SELECT COALESCE(SUM(h.value_now),0)
     FROM mf_holdings h JOIN funds f ON f.id = h.fund_id
     WHERE h.portfolio_id = ? AND h.is_active = 1
       AND f.category LIKE '%Gold%'",
    [$portfolioId]
);
$stocks  = (float)DB::fetchVal("SELECT COALESCE(SUM(current_value),0) FROM stock_holdings WHERE portfolio_id=? AND is_active=1", [$portfolioId]);
$nps     = (float)DB::fetchVal("SELECT COALESCE(SUM(latest_value),0) FROM nps_holdings WHERE portfolio_id=?", [$portfolioId]);
$fdRow   = DB::fetchRow("SELECT COALESCE(SUM(principal),0) AS p, COALESCE(SUM(accrued_interest),0) AS ai FROM fd_accounts WHERE portfolio_id=? AND status='active'", [$portfolioId]);
$savings = (float)DB::fetchVal("SELECT COALESCE(SUM(current_balance),0) FROM savings_accounts WHERE portfolio_id=? AND is_active=1", [$portfolioId]);
$fd      = (float)$fdRow['p'] + (float)$fdRow['ai'];

$currentEquity = $equityMf + $stocks;
$currentDebt   = $debtMf + $fd + $savings;
$currentGold   = $goldMf;
$currentOther  = $nps;  // NPS is hybrid, counted as "other"

$totalPortfolio = $currentEquity + $currentDebt + $currentGold + $currentOther;

if ($totalPortfolio <= 0) {
    json_response(true, 'No holdings found.', ['total' => 0, 'suggestions' => []]);
}

/* ─── Current allocation ─────────────────────────────────────────────────── */
$actualEquityPct = round(($currentEquity / $totalPortfolio) * 100, 2);
$actualDebtPct   = round(($currentDebt   / $totalPortfolio) * 100, 2);
$actualGoldPct   = round(($currentGold   / $totalPortfolio) * 100, 2);
$actualOtherPct  = round(($currentOther  / $totalPortfolio) * 100, 2);

/* ─── Target values ──────────────────────────────────────────────────────── */
$targetEquityVal = round($totalPortfolio * $targetEquity / 100, 2);
$targetDebtVal   = round($totalPortfolio * $targetDebt   / 100, 2);
$targetGoldVal   = round($totalPortfolio * $targetGold   / 100, 2);
$targetOtherVal  = round($totalPortfolio * $targetOther  / 100, 2);

/* ─── Deviation ─────────────────────────────────────────────────────────── */
$equityDeviation = round($currentEquity - $targetEquityVal, 2);
$debtDeviation   = round($currentDebt   - $targetDebtVal,   2);
$goldDeviation   = round($currentGold   - $targetGoldVal,   2);
$otherDeviation  = round($currentOther  - $targetOtherVal,  2);

$REBALANCE_THRESHOLD = 5.0; // Only suggest if deviation > 5%

$suggestions = [];

// Equity overweight?
if (abs($actualEquityPct - $targetEquity) >= $REBALANCE_THRESHOLD) {
    $action = $equityDeviation > 0 ? 'REDUCE' : 'INCREASE';
    $amount = abs($equityDeviation);
    $suggestions[] = [
        'asset_class'    => 'Equity (MF + Stocks)',
        'action'         => $action,
        'current_value'  => round($currentEquity, 2),
        'current_pct'    => $actualEquityPct,
        'target_pct'     => $targetEquity,
        'target_value'   => $targetEquityVal,
        'deviation_pct'  => round($actualEquityPct - $targetEquity, 2),
        'amount'         => round($amount, 2),
        'message'        => $action === 'REDUCE'
            ? "Equity is {$actualEquityPct}% vs target {$targetEquity}%. Consider redeeming ₹" . format_inr($amount) . " from equity funds/stocks."
            : "Equity is {$actualEquityPct}% vs target {$targetEquity}%. Consider investing ₹" . format_inr($amount) . " more in equity.",
    ];
}

// Debt underweight/overweight?
if (abs($actualDebtPct - $targetDebt) >= $REBALANCE_THRESHOLD) {
    $action = $debtDeviation > 0 ? 'REDUCE' : 'INCREASE';
    $amount = abs($debtDeviation);
    $suggestions[] = [
        'asset_class'    => 'Debt (MF + FD + Savings)',
        'action'         => $action,
        'current_value'  => round($currentDebt, 2),
        'current_pct'    => $actualDebtPct,
        'target_pct'     => $targetDebt,
        'target_value'   => $targetDebtVal,
        'deviation_pct'  => round($actualDebtPct - $targetDebt, 2),
        'amount'         => round($amount, 2),
        'message'        => $action === 'REDUCE'
            ? "Debt allocation is {$actualDebtPct}% vs target {$targetDebt}%. Consider reducing FD/debt MF by ₹" . format_inr($amount) . "."
            : "Debt is underweight at {$actualDebtPct}% vs target {$targetDebt}%. Add ₹" . format_inr($amount) . " to FD or debt MF.",
    ];
}

// Gold underweight/overweight?
if ($targetGold > 0 && abs($actualGoldPct - $targetGold) >= $REBALANCE_THRESHOLD) {
    $action = $goldDeviation > 0 ? 'REDUCE' : 'INCREASE';
    $amount = abs($goldDeviation);
    $suggestions[] = [
        'asset_class'    => 'Gold (Gold Funds/ETF)',
        'action'         => $action,
        'current_value'  => round($currentGold, 2),
        'current_pct'    => $actualGoldPct,
        'target_pct'     => $targetGold,
        'target_value'   => $targetGoldVal,
        'deviation_pct'  => round($actualGoldPct - $targetGold, 2),
        'amount'         => round($amount, 2),
        'message'        => $action === 'REDUCE'
            ? "Gold is {$actualGoldPct}% vs target {$targetGold}%. Trim ₹" . format_inr($amount) . " from gold funds."
            : "Gold is only {$actualGoldPct}% vs target {$targetGold}%. Add ₹" . format_inr($amount) . " in gold ETF/fund.",
    ];
}

/* ─── Top holdings concentration risk ────────────────────────────────────── */
$concentration = [];
$topMfFunds = DB::fetchAll(
    "SELECT f.scheme_name, h.value_now,
            ROUND((h.value_now / ?) * 100, 2) AS pct_of_portfolio
     FROM mf_holdings h JOIN funds f ON f.id = h.fund_id
     WHERE h.portfolio_id = ? AND h.is_active = 1
     HAVING pct_of_portfolio >= 15
     ORDER BY h.value_now DESC LIMIT 5",
    [$totalPortfolio, $portfolioId]
);
foreach ($topMfFunds as $fund) {
    $concentration[] = [
        'type'    => 'MF',
        'name'    => $fund['scheme_name'],
        'value'   => round((float)$fund['value_now'], 2),
        'pct'     => (float)$fund['pct_of_portfolio'],
        'warning' => "This fund is {$fund['pct_of_portfolio']}% of total portfolio. Consider diversifying.",
    ];
}

$topStocks = DB::fetchAll(
    "SELECT sm.symbol, sm.company_name, h.current_value,
            ROUND((h.current_value / ?) * 100, 2) AS pct_of_portfolio
     FROM stock_holdings h JOIN stock_master sm ON sm.id = h.stock_id
     WHERE h.portfolio_id = ? AND h.is_active = 1
     HAVING pct_of_portfolio >= 15
     ORDER BY h.current_value DESC LIMIT 5",
    [$totalPortfolio, $portfolioId]
);
foreach ($topStocks as $stock) {
    $concentration[] = [
        'type'    => 'Stock',
        'name'    => $stock['company_name'] . ' (' . $stock['symbol'] . ')',
        'value'   => round((float)$stock['current_value'], 2),
        'pct'     => (float)$stock['pct_of_portfolio'],
        'warning' => "This stock is {$stock['pct_of_portfolio']}% of total portfolio. High concentration risk.",
    ];
}

json_response(true, 'Rebalancing report loaded.', [
    'total_portfolio' => round($totalPortfolio, 2),
    'current' => [
        ['asset' => 'Equity', 'value' => round($currentEquity,2), 'pct' => $actualEquityPct, 'color' => '#2563EB'],
        ['asset' => 'Debt',   'value' => round($currentDebt,2),   'pct' => $actualDebtPct,   'color' => '#D97706'],
        ['asset' => 'Gold',   'value' => round($currentGold,2),   'pct' => $actualGoldPct,   'color' => '#F59E0B'],
        ['asset' => 'Other (NPS)', 'value' => round($currentOther,2), 'pct' => $actualOtherPct, 'color' => '#7C3AED'],
    ],
    'target' => [
        ['asset' => 'Equity', 'pct' => $targetEquity, 'value' => $targetEquityVal],
        ['asset' => 'Debt',   'pct' => $targetDebt,   'value' => $targetDebtVal],
        ['asset' => 'Gold',   'pct' => $targetGold,   'value' => $targetGoldVal],
        ['asset' => 'Other',  'pct' => $targetOther,  'value' => $targetOtherVal],
    ],
    'suggestions'        => $suggestions,
    'concentration_risks'=> $concentration,
    'is_balanced'        => count($suggestions) === 0,
    'threshold_used'     => $REBALANCE_THRESHOLD,
]);

