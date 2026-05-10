<?php
/**
 * WealthDash — Net Worth Report
 * All asset classes combined: MF + NPS + Stocks + FD + Savings
 * POST /api/?action=report_net_worth
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$portfolioId = (int)($_POST['portfolio_id'] ?? 0);
if (!$portfolioId) $portfolioId = get_user_portfolio_id((int)($currentUser['id'] ?? 0));

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

/* ─── 6. Real Estate (t466) ───────────────────────────────────────────────── */
$reRow = DB::fetchRow(
    "SELECT COUNT(*) AS property_count,
            COALESCE(SUM(purchase_price), 0) AS invested,
            COALESCE(SUM(current_value * ownership_pct / 100), 0) AS current_value,
            COALESCE(SUM(CASE WHEN outstanding_loan IS NOT NULL THEN outstanding_loan ELSE 0 END), 0) AS outstanding_loan,
            COALESCE(SUM(CASE WHEN monthly_rental IS NOT NULL THEN monthly_rental * 12 ELSE 0 END), 0) AS annual_rental,
            COALESCE(SUM(CASE WHEN is_self_occupied = 1 THEN current_value * ownership_pct / 100 ELSE 0 END), 0) AS self_occupied_value,
            COALESCE(SUM(CASE WHEN is_self_occupied = 0 THEN current_value * ownership_pct / 100 ELSE 0 END), 0) AS investment_property_value
     FROM real_estate WHERE portfolio_id = ? AND is_active = 1",
    [$portfolioId]
);
$reByType = DB::fetchAll(
    "SELECT property_type,
            COUNT(*) AS count,
            COALESCE(SUM(current_value * ownership_pct / 100), 0) AS current_value
     FROM real_estate WHERE portfolio_id = ? AND is_active = 1
     GROUP BY property_type ORDER BY current_value DESC",
    [$portfolioId]
);
$reCurrentValue = (float)($reRow['current_value'] ?? 0);
$reInvested     = (float)($reRow['invested'] ?? 0);

/* ─── 7. Physical Gold (t465) ─────────────────────────────────────────────── */
$goldRow = DB::fetchRow(
    "SELECT COUNT(*) AS count,
            COALESCE(SUM(weight_grams), 0) AS total_weight_grams,
            COALESCE(SUM(purchase_price), 0) AS invested,
            COALESCE(SUM(current_value), 0) AS current_value
     FROM physical_gold WHERE portfolio_id = ? AND is_active = 1",
    [$portfolioId]
);
$goldByType = DB::fetchAll(
    "SELECT gold_type,
            COUNT(*) AS count,
            COALESCE(SUM(weight_grams), 0) AS weight_grams,
            COALESCE(SUM(current_value), 0) AS current_value
     FROM physical_gold WHERE portfolio_id = ? AND is_active = 1
     GROUP BY gold_type ORDER BY current_value DESC",
    [$portfolioId]
);
$goldCurrentValue = (float)($goldRow['current_value'] ?? 0);
$goldInvested     = (float)($goldRow['invested'] ?? 0);

/* ─── 8. Loans / Liabilities ──────────────────────────────────────────────── */
$loanRow = DB::fetchRow(
    "SELECT COUNT(*) AS count,
            COALESCE(SUM(principal_amount), 0) AS total_principal,
            COALESCE(SUM(outstanding_balance), 0) AS total_outstanding,
            COALESCE(SUM(CASE WHEN loan_type = 'home'     THEN outstanding_balance ELSE 0 END), 0) AS home_loan,
            COALESCE(SUM(CASE WHEN loan_type = 'personal' THEN outstanding_balance ELSE 0 END), 0) AS personal_loan,
            COALESCE(SUM(CASE WHEN loan_type = 'vehicle'  THEN outstanding_balance ELSE 0 END), 0) AS vehicle_loan,
            COALESCE(SUM(CASE WHEN loan_type = 'education'THEN outstanding_balance ELSE 0 END), 0) AS education_loan
     FROM loan_accounts WHERE portfolio_id = ? AND status = 'active'",
    [$portfolioId]
);
$loanByType = DB::fetchAll(
    "SELECT loan_type, lender_name,
            outstanding_balance, interest_rate, emi_amount, end_date
     FROM loan_accounts WHERE portfolio_id = ? AND status = 'active'
     ORDER BY outstanding_balance DESC",
    [$portfolioId]
);
$totalLiabilities = (float)($loanRow['total_outstanding'] ?? 0);

/* ─── 9. Totals (including real estate, gold; net of liabilities) ─────────── */
$totalInvested = (float)$mfRow['invested']
               + (float)$npsRow['invested']
               + (float)$stockRow['invested']
               + (float)$fdRow['invested']
               + (float)$savingsRow['invested']
               + $reInvested
               + $goldInvested;

$totalGrossAssets = (float)$mfRow['current_value']
                  + (float)$npsRow['current_value']
                  + (float)$stockRow['current_value']
                  + $fdCurrentValue
                  + (float)$savingsRow['current_value']
                  + $reCurrentValue
                  + $goldCurrentValue;

$totalCurrentValue = $totalGrossAssets - $totalLiabilities; // Net Worth = Assets - Liabilities

$totalGainLoss = $totalCurrentValue - $totalInvested;
$totalGainPct  = $totalInvested > 0 ? round(($totalGainLoss / $totalInvested) * 100, 2) : 0;

/* ─── 10. Asset allocation percentages ───────────────────────────────────── */
function alloc(float $val, float $total): float {
    return $total > 0 ? round(($val / $total) * 100, 2) : 0.0;
}

$allocation = [
    ['label' => 'Mutual Funds',  'value' => round((float)$mfRow['current_value'],2),    'color' => '#2563EB', 'pct' => alloc((float)$mfRow['current_value'], $totalGrossAssets)],
    ['label' => 'Stocks',        'value' => round((float)$stockRow['current_value'],2),  'color' => '#059669', 'pct' => alloc((float)$stockRow['current_value'], $totalGrossAssets)],
    ['label' => 'NPS',           'value' => round((float)$npsRow['current_value'],2),    'color' => '#7C3AED', 'pct' => alloc((float)$npsRow['current_value'], $totalGrossAssets)],
    ['label' => 'Fixed Deposits','value' => round($fdCurrentValue,2),                    'color' => '#D97706', 'pct' => alloc($fdCurrentValue, $totalGrossAssets)],
    ['label' => 'Savings',       'value' => round((float)$savingsRow['current_value'],2),'color' => '#0891B2', 'pct' => alloc((float)$savingsRow['current_value'], $totalGrossAssets)],
    ['label' => 'Real Estate',   'value' => round($reCurrentValue, 2),                   'color' => '#DC2626', 'pct' => alloc($reCurrentValue, $totalGrossAssets)],
    ['label' => 'Physical Gold', 'value' => round($goldCurrentValue, 2),                 'color' => '#F59E0B', 'pct' => alloc($goldCurrentValue, $totalGrossAssets)],
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
    ['label' => 'Equity (MF+Stocks)',      'value' => round($equityTotal, 2),      'color' => '#2563EB', 'pct' => alloc($equityTotal, $totalGrossAssets)],
    ['label' => 'Debt (MF+FD+Savings)',    'value' => round($debtTotal, 2),        'color' => '#D97706', 'pct' => alloc($debtTotal, $totalGrossAssets)],
    ['label' => 'NPS (Hybrid)',             'value' => round($hybridOther, 2),      'color' => '#7C3AED', 'pct' => alloc($hybridOther, $totalGrossAssets)],
    ['label' => 'Real Estate',             'value' => round($reCurrentValue, 2),   'color' => '#DC2626', 'pct' => alloc($reCurrentValue, $totalGrossAssets)],
    ['label' => 'Physical Gold',           'value' => round($goldCurrentValue, 2), 'color' => '#F59E0B', 'pct' => alloc($goldCurrentValue, $totalGrossAssets)],
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
        'total_invested'      => round($totalInvested, 2),
        'total_gross_assets'  => round($totalGrossAssets, 2),
        'total_liabilities'   => round($totalLiabilities, 2),
        'total_current_value' => round($totalCurrentValue, 2),
        'total_gain_loss'     => round($totalGainLoss, 2),
        'total_gain_pct'      => $totalGainPct,
        'as_of_date'          => format_date($today),
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
        'real_estate' => [
            'invested'              => round($reInvested, 2),
            'current_value'         => round($reCurrentValue, 2),
            'gain_loss'             => round($reCurrentValue - $reInvested, 2),
            'gain_pct'              => $reInvested > 0 ? round(($reCurrentValue - $reInvested) / $reInvested * 100, 2) : 0,
            'property_count'        => (int)($reRow['property_count'] ?? 0),
            'outstanding_loan'      => round((float)($reRow['outstanding_loan'] ?? 0), 2),
            'net_equity'            => round($reCurrentValue - (float)($reRow['outstanding_loan'] ?? 0), 2),
            'annual_rental_income'  => round((float)($reRow['annual_rental'] ?? 0), 2),
            'self_occupied_value'   => round((float)($reRow['self_occupied_value'] ?? 0), 2),
            'investment_value'      => round((float)($reRow['investment_property_value'] ?? 0), 2),
            'by_type'               => $reByType,
        ],
        'physical_gold' => [
            'invested'            => round($goldInvested, 2),
            'current_value'       => round($goldCurrentValue, 2),
            'gain_loss'           => round($goldCurrentValue - $goldInvested, 2),
            'gain_pct'            => $goldInvested > 0 ? round(($goldCurrentValue - $goldInvested) / $goldInvested * 100, 2) : 0,
            'count'               => (int)($goldRow['count'] ?? 0),
            'total_weight_grams'  => round((float)($goldRow['total_weight_grams'] ?? 0), 3),
            'by_type'             => $goldByType,
        ],
    ],
    'liabilities' => [
        'total_outstanding'  => round($totalLiabilities, 2),
        'loan_count'         => (int)($loanRow['count'] ?? 0),
        'home_loan'          => round((float)($loanRow['home_loan'] ?? 0), 2),
        'personal_loan'      => round((float)($loanRow['personal_loan'] ?? 0), 2),
        'vehicle_loan'       => round((float)($loanRow['vehicle_loan'] ?? 0), 2),
        'education_loan'     => round((float)($loanRow['education_loan'] ?? 0), 2),
        'by_loan'            => $loanByType,
    ],
    'allocation'       => $allocation,
    'equity_debt_split'=> $equityDebtSplit,
    'mf_category_split'=> $mfCatBreakdown,
]);

