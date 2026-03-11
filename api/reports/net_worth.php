<?php
/**
 * WealthDash — Net Worth Report
 * All asset classes combined: MF + NPS + Stocks + FD + Savings
 * POST /api/?action=report_net_worth
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$portfolioId = (int)($_POST['portfolio_id'] ?? $_SESSION['selected_portfolio_id'] ?? 0);

if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
    json_response(false, 'Invalid or inaccessible portfolio.');
}

/* ─── 1. Mutual Funds ─────────────────────────────────────────────────────── */
$mfRow = DB::fetchRow(
    "SELECT COALESCE(SUM(total_invested),0) AS invested,
            COALESCE(SUM(value_now),0) AS current_value,
            COALESCE(SUM(gain_loss),0) AS gain_loss,
            COUNT(*) AS holding_count
     FROM mf_holdings WHERE portfolio_id = ? AND is_active = 1",
    [$portfolioId]
);
$mfCategory = DB::fetchAll(
    "SELECT f.category, f.sub_category,
            COALESCE(SUM(h.total_invested),0) AS invested,
            COALESCE(SUM(h.value_now),0) AS current_value,
            COUNT(*) AS count
     FROM mf_holdings h
     JOIN funds f ON f.id = h.fund_id
     WHERE h.portfolio_id = ? AND h.is_active = 1
     GROUP BY f.category, f.sub_category
     ORDER BY current_value DESC",
    [$portfolioId]
);

/* ─── 2. NPS ─────────────────────────────────────────────────────────────── */
$npsRow = DB::fetchRow(
    "SELECT COALESCE(SUM(total_invested),0) AS invested,
            COALESCE(SUM(latest_value),0) AS current_value,
            COALESCE(SUM(gain_loss),0) AS gain_loss,
            COUNT(*) AS holding_count
     FROM nps_holdings WHERE portfolio_id = ?",
    [$portfolioId]
);
$npsByTier = DB::fetchAll(
    "SELECT h.tier,
            COALESCE(SUM(h.total_invested),0) AS invested,
            COALESCE(SUM(h.latest_value),0) AS current_value,
            COALESCE(SUM(h.gain_loss),0) AS gain_loss
     FROM nps_holdings h
     WHERE h.portfolio_id = ?
     GROUP BY h.tier",
    [$portfolioId]
);

/* ─── 3. Stocks ───────────────────────────────────────────────────────────── */
$stockRow = DB::fetchRow(
    "SELECT COALESCE(SUM(total_invested),0) AS invested,
            COALESCE(SUM(current_value),0) AS current_value,
            COALESCE(SUM(gain_loss),0) AS gain_loss,
            COUNT(*) AS holding_count
     FROM stock_holdings WHERE portfolio_id = ? AND is_active = 1",
    [$portfolioId]
);
$stockBySector = DB::fetchAll(
    "SELECT sm.sector,
            COALESCE(SUM(h.total_invested),0) AS invested,
            COALESCE(SUM(h.current_value),0) AS current_value,
            COUNT(*) AS count
     FROM stock_holdings h
     JOIN stock_master sm ON sm.id = h.stock_id
     WHERE h.portfolio_id = ? AND h.is_active = 1
     GROUP BY sm.sector
     ORDER BY current_value DESC",
    [$portfolioId]
);

/* ─── 4. Fixed Deposits ───────────────────────────────────────────────────── */
$today = date('Y-m-d');
$fdRow = DB::fetchRow(
    "SELECT COALESCE(SUM(principal),0) AS invested,
            COALESCE(SUM(CASE WHEN status = 'active' THEN maturity_amount ELSE 0 END),0) AS maturity_value,
            COALESCE(SUM(CASE WHEN status = 'active' THEN accrued_interest ELSE 0 END),0) AS accrued_interest,
            COUNT(CASE WHEN status='active' THEN 1 END) AS active_count,
            COUNT(CASE WHEN status='matured' THEN 1 END) AS matured_count,
            COALESCE(SUM(CASE WHEN status='active' AND maturity_date <= DATE_ADD(?, INTERVAL 90 DAY) THEN principal END),0) AS maturing_soon
     FROM fd_accounts WHERE portfolio_id = ?",
    [$today, $portfolioId]
);
// Current value of FD = principal + accrued interest
$fdCurrentValue = (float)$fdRow['invested'] + (float)$fdRow['accrued_interest'];
$fdGain = (float)$fdRow['accrued_interest'];

$fdByBank = DB::fetchAll(
    "SELECT bank_name, COUNT(*) AS count,
            SUM(principal) AS total_principal,
            SUM(CASE WHEN status='active' THEN accrued_interest ELSE 0 END) AS accrued
     FROM fd_accounts WHERE portfolio_id = ? AND status = 'active'
     GROUP BY bank_name
     ORDER BY total_principal DESC",
    [$portfolioId]
);

/* ─── 5. Savings Accounts ─────────────────────────────────────────────────── */
$savingsRow = DB::fetchRow(
    "SELECT COALESCE(SUM(current_balance),0) AS current_value,
            COALESCE(SUM(current_balance),0) AS invested,
            COUNT(*) AS count
     FROM savings_accounts WHERE portfolio_id = ? AND is_active = 1",
    [$portfolioId]
);
$savingsByBank = DB::fetchAll(
    "SELECT bank_name, account_type,
            SUM(current_balance) AS balance,
            AVG(interest_rate) AS avg_rate
     FROM savings_accounts WHERE portfolio_id = ? AND is_active = 1
     GROUP BY bank_name, account_type
     ORDER BY balance DESC",
    [$portfolioId]
);

/* ─── 6. Totals ───────────────────────────────────────────────────────────── */
$totalInvested = (float)$mfRow['invested']
               + (float)$npsRow['invested']
               + (float)$stockRow['invested']
               + (float)$fdRow['invested']
               + (float)$savingsRow['invested'];

$totalCurrentValue = (float)$mfRow['current_value']
                   + (float)$npsRow['current_value']
                   + (float)$stockRow['current_value']
                   + $fdCurrentValue
                   + (float)$savingsRow['current_value'];

$totalGainLoss = $totalCurrentValue - $totalInvested;
$totalGainPct  = $totalInvested > 0 ? round(($totalGainLoss / $totalInvested) * 100, 2) : 0;

/* ─── 7. Asset allocation percentages ────────────────────────────────────── */
function alloc(float $val, float $total): float {
    return $total > 0 ? round(($val / $total) * 100, 2) : 0.0;
}

$allocation = [
    ['label' => 'Mutual Funds', 'value' => round((float)$mfRow['current_value'],2),    'color' => '#2563EB', 'pct' => alloc((float)$mfRow['current_value'], $totalCurrentValue)],
    ['label' => 'Stocks',       'value' => round((float)$stockRow['current_value'],2),  'color' => '#059669', 'pct' => alloc((float)$stockRow['current_value'], $totalCurrentValue)],
    ['label' => 'NPS',          'value' => round((float)$npsRow['current_value'],2),    'color' => '#7C3AED', 'pct' => alloc((float)$npsRow['current_value'], $totalCurrentValue)],
    ['label' => 'Fixed Deposits','value' => round($fdCurrentValue,2),                   'color' => '#D97706', 'pct' => alloc($fdCurrentValue, $totalCurrentValue)],
    ['label' => 'Savings',      'value' => round((float)$savingsRow['current_value'],2),'color' => '#0891B2', 'pct' => alloc((float)$savingsRow['current_value'], $totalCurrentValue)],
];

/* ─── 8. MF category breakdown for pie ────────────────────────────────────── */
$mfCatBreakdown = [];
foreach ($mfCategory as $cat) {
    $catLabel = $cat['sub_category'] ?: $cat['category'] ?: 'Other';
    $mfCatBreakdown[] = [
        'label' => $catLabel,
        'value' => round((float)$cat['current_value'], 2),
        'pct'   => alloc((float)$cat['current_value'], (float)$mfRow['current_value']),
    ];
}

/* ─── 9. Equity vs Debt split ─────────────────────────────────────────────── */
$equityTotal = (float)$mfRow['current_value'] * 0.0; // will recalc
$debtTotal   = 0.0;
// More precise: query by category
$equityDebt = DB::fetchAll(
    "SELECT
        SUM(CASE WHEN f.category NOT LIKE '%Debt%' AND f.category NOT LIKE '%Liquid%' AND f.category NOT LIKE '%Money Market%' AND f.category NOT LIKE '%Credit%' AND f.category NOT LIKE '%Banking%' AND f.category NOT LIKE '%Gilt%' THEN h.value_now ELSE 0 END) AS equity_mf,
        SUM(CASE WHEN f.category LIKE '%Debt%' OR f.category LIKE '%Liquid%' OR f.category LIKE '%Money Market%' OR f.category LIKE '%Credit%' OR f.category LIKE '%Banking%' OR f.category LIKE '%Gilt%' THEN h.value_now ELSE 0 END) AS debt_mf
     FROM mf_holdings h
     JOIN funds f ON f.id = h.fund_id
     WHERE h.portfolio_id = ? AND h.is_active = 1",
    [$portfolioId]
);
$equityMf = (float)($equityDebt[0]['equity_mf'] ?? 0);
$debtMf   = (float)($equityDebt[0]['debt_mf'] ?? 0);

$equityTotal = $equityMf + (float)$stockRow['current_value'];
$debtTotal   = $debtMf + $fdCurrentValue + (float)$savingsRow['current_value'];
$hybridOther = (float)$npsRow['current_value'];

$equityDebtSplit = [
    ['label' => 'Equity (MF+Stocks)',      'value' => round($equityTotal, 2), 'color' => '#2563EB', 'pct' => alloc($equityTotal, $totalCurrentValue)],
    ['label' => 'Debt (MF+FD+Savings)',    'value' => round($debtTotal, 2),   'color' => '#D97706', 'pct' => alloc($debtTotal, $totalCurrentValue)],
    ['label' => 'NPS (Hybrid)',             'value' => round($hybridOther, 2), 'color' => '#7C3AED', 'pct' => alloc($hybridOther, $totalCurrentValue)],
];

/* ─── 10. FD maturing in next 90 days ────────────────────────────────────── */
$fdMaturingSoon = DB::fetchAll(
    "SELECT id, bank_name, principal, interest_rate, maturity_date, maturity_amount,
            DATEDIFF(maturity_date, ?) AS days_left
     FROM fd_accounts
     WHERE portfolio_id = ? AND status = 'active'
       AND maturity_date <= DATE_ADD(?, INTERVAL 90 DAY)
     ORDER BY maturity_date ASC",
    [$today, $portfolioId, $today]
);

json_response(true, 'Net worth report loaded.', [
    'summary' => [
        'total_invested'     => round($totalInvested, 2),
        'total_current_value'=> round($totalCurrentValue, 2),
        'total_gain_loss'    => round($totalGainLoss, 2),
        'total_gain_pct'     => $totalGainPct,
        'as_of_date'         => format_date($today),
    ],
    'by_asset' => [
        'mutual_funds' => [
            'invested'      => round((float)$mfRow['invested'], 2),
            'current_value' => round((float)$mfRow['current_value'], 2),
            'gain_loss'     => round((float)$mfRow['gain_loss'], 2),
            'gain_pct'      => $mfRow['invested'] > 0 ? round(((float)$mfRow['gain_loss']/(float)$mfRow['invested'])*100, 2) : 0,
            'holding_count' => (int)$mfRow['holding_count'],
            'by_category'   => $mfCategory,
        ],
        'nps' => [
            'invested'      => round((float)$npsRow['invested'], 2),
            'current_value' => round((float)$npsRow['current_value'], 2),
            'gain_loss'     => round((float)$npsRow['gain_loss'], 2),
            'gain_pct'      => $npsRow['invested'] > 0 ? round(((float)$npsRow['gain_loss']/(float)$npsRow['invested'])*100, 2) : 0,
            'by_tier'       => $npsByTier,
        ],
        'stocks' => [
            'invested'      => round((float)$stockRow['invested'], 2),
            'current_value' => round((float)$stockRow['current_value'], 2),
            'gain_loss'     => round((float)$stockRow['gain_loss'], 2),
            'gain_pct'      => $stockRow['invested'] > 0 ? round(((float)$stockRow['gain_loss']/(float)$stockRow['invested'])*100, 2) : 0,
            'holding_count' => (int)$stockRow['holding_count'],
            'by_sector'     => $stockBySector,
        ],
        'fd' => [
            'invested'        => round((float)$fdRow['invested'], 2),
            'current_value'   => round($fdCurrentValue, 2),
            'accrued_interest'=> round((float)$fdRow['accrued_interest'], 2),
            'active_count'    => (int)$fdRow['active_count'],
            'maturing_soon'   => $fdMaturingSoon,
            'by_bank'         => $fdByBank,
        ],
        'savings' => [
            'current_value' => round((float)$savingsRow['current_value'], 2),
            'account_count' => (int)$savingsRow['count'],
            'by_bank'       => $savingsByBank,
        ],
    ],
    'allocation'       => $allocation,
    'equity_debt_split'=> $equityDebtSplit,
    'mf_category_split'=> $mfCatBreakdown,
]);

