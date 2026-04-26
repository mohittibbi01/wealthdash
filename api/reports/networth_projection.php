<?php
/**
 * WealthDash — Net Worth Projection 5/10/20yr
 * t396 — Projects current net worth over 5, 10, 20 years with scenario modelling
 * GET  /api/?action=nw_projection
 * POST /api/?action=nw_projection_save  (save custom assumptions)
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$portfolioId = (int)($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ?? 0);
if (!$portfolioId) $portfolioId = get_user_portfolio_id((int)($currentUser['id'] ?? 0));

if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
    json_response(false, 'Invalid or inaccessible portfolio.');
}

/* ─── Current Net Worth Snapshot ─────────────────────────────────────────── */

// MF
$mfVal = (float)(DB::fetchRow(
    "SELECT COALESCE(SUM(value_now),0) AS v FROM mf_holdings WHERE portfolio_id=? AND is_active=1",
    [$portfolioId]
)['v'] ?? 0);

// NPS
$npsVal = (float)(DB::fetchRow(
    "SELECT COALESCE(SUM(latest_value),0) AS v FROM nps_holdings WHERE portfolio_id=?",
    [$portfolioId]
)['v'] ?? 0);

// Stocks
$stockVal = (float)(DB::fetchRow(
    "SELECT COALESCE(SUM(current_value),0) AS v FROM stock_holdings WHERE portfolio_id=? AND is_active=1",
    [$portfolioId]
)['v'] ?? 0);

// FD — principal + accrued
$fdRow = DB::fetchRow(
    "SELECT COALESCE(SUM(principal),0) AS p, COALESCE(SUM(interest_earned_to_date),0) AS i
     FROM fd_accounts WHERE portfolio_id=? AND status='active'",
    [$portfolioId]
);
$fdVal = (float)$fdRow['p'] + (float)$fdRow['i'];

// Savings
$savVal = (float)(DB::fetchRow(
    "SELECT COALESCE(SUM(balance),0) AS v FROM savings_accounts WHERE portfolio_id=?",
    [$portfolioId]
)['v'] ?? 0);

// Post-office / Govt schemes
$poVal = (float)(DB::fetchRow(
    "SELECT COALESCE(SUM(current_value),0) AS v FROM po_investments WHERE portfolio_id=? AND status='active'",
    [$portfolioId]
)['v'] ?? 0);

// Crypto
$cryptoVal = (float)(DB::fetchRow(
    "SELECT COALESCE(SUM(current_value_inr),0) AS v FROM crypto_holdings WHERE portfolio_id=? AND is_active=1",
    [$portfolioId]
)['v'] ?? 0);

$totalAssets = $mfVal + $npsVal + $stockVal + $fdVal + $savVal + $poVal + $cryptoVal;

// Liabilities
$loanVal = (float)(DB::fetchRow(
    "SELECT COALESCE(SUM(outstanding_balance),0) AS v FROM loan_accounts WHERE portfolio_id=? AND status='active'",
    [$portfolioId]
)['v'] ?? 0);

$netWorth = $totalAssets - $loanVal;

/* ─── Monthly SIP / Savings Flow ─────────────────────────────────────────── */
$monthlySip = (float)(DB::fetchRow(
    "SELECT COALESCE(SUM(amount),0) AS v FROM sip_swp WHERE portfolio_id=? AND type='SIP' AND status='active'",
    [$portfolioId]
)['v'] ?? 0);

/* ─── Asset Allocation for blended return ────────────────────────────────── */
$equityVal   = $mfVal * 0.65 + $stockVal + $npsVal * 0.50; // rough equity portion
$debtVal     = $mfVal * 0.35 + $fdVal + $savVal + $poVal + $npsVal * 0.50;
$otherVal    = $cryptoVal;

$totalForAlloc = max(1, $equityVal + $debtVal + $otherVal);
$equityPct     = $equityVal / $totalForAlloc;
$debtPct       = $debtVal   / $totalForAlloc;
$otherPct      = $otherVal  / $totalForAlloc;

/* ─── Default Assumptions ─────────────────────────────────────────────────── */
// Conservative / Base / Optimistic return rates per asset
$scenarios = [
    'conservative' => [
        'equity_return' => 0.10,   // 10% pa
        'debt_return'   => 0.065,  // 6.5%
        'other_return'  => 0.05,   // 5%
        'inflation'     => 0.06,   // 6%
        'sip_stepup'    => 0.05,   // 5% annual SIP step-up
        'loan_emi_rate' => 0.08,
    ],
    'base' => [
        'equity_return' => 0.13,
        'debt_return'   => 0.075,
        'other_return'  => 0.10,
        'inflation'     => 0.055,
        'sip_stepup'    => 0.10,
        'loan_emi_rate' => 0.08,
    ],
    'optimistic' => [
        'equity_return' => 0.17,
        'debt_return'   => 0.085,
        'other_return'  => 0.20,
        'inflation'     => 0.05,
        'sip_stepup'    => 0.15,
        'loan_emi_rate' => 0.08,
    ],
];

/* ─── Project Helper ──────────────────────────────────────────────────────── */
function projectNW(float $nw, float $monthly, array $sc, float $eqPct, float $dPct, float $otPct, int $years): array {
    $blendedReturn = ($eqPct * $sc['equity_return']) + ($dPct * $sc['debt_return']) + ($otPct * $sc['other_return']);
    $monthlyReturn = $blendedReturn / 12;
    $annualStepup  = 1 + $sc['sip_stepup'];

    $series = [];
    $current = $nw;
    $sip     = $monthly;

    for ($y = 1; $y <= $years; $y++) {
        // Compound existing NW for 12 months
        $existing = $current * pow(1 + $monthlyReturn, 12);
        // SIP FV for the year (step-up applied at year start)
        $sipFv = $sip * (pow(1 + $monthlyReturn, 12) - 1) / $monthlyReturn * (1 + $monthlyReturn);
        $current = $existing + $sipFv;
        $sip    *= $annualStepup;

        $series[] = [
            'year'           => $y,
            'nominal'        => round($current, 0),
            'real'           => round($current / pow(1 + $sc['inflation'], $y), 0),
            'blended_return' => round($blendedReturn * 100, 2),
        ];
    }
    return $series;
}

/* ─── Run 3 scenarios × 3 horizons ───────────────────────────────────────── */
$horizons    = [5, 10, 20];
$projections = [];

foreach ($scenarios as $name => $sc) {
    $series20 = projectNW($netWorth, $monthlySip, $sc, $equityPct, $debtPct, $otherPct, 20);
    $byYear   = array_column($series20, null, 'year');

    $milestones = [];
    foreach ($horizons as $h) {
        $milestones[$h] = $byYear[$h] ?? end($series20);
    }

    $projections[$name] = [
        'assumptions'  => [
            'equity_return' => $sc['equity_return'] * 100,
            'debt_return'   => $sc['debt_return']   * 100,
            'inflation'     => $sc['inflation']      * 100,
            'sip_stepup'    => $sc['sip_stepup']     * 100,
        ],
        'milestones'   => $milestones,
        'series'       => $series20,
    ];
}

/* ─── CAGR implied from current portfolio ────────────────────────────────── */
$totalInvested = (float)(DB::fetchRow(
    "SELECT COALESCE(SUM(total_invested),0) AS v FROM mf_holdings WHERE portfolio_id=? AND is_active=1",
    [$portfolioId]
)['v'] ?? 0);
// rough historical CAGR placeholder — 3yr if invested > 0
$historicalCagr = null;
if ($totalInvested > 0 && $mfVal > $totalInvested) {
    // Assume avg 3yr horizon as estimate
    $historicalCagr = round((pow($mfVal / $totalInvested, 1/3) - 1) * 100, 2);
}

/* ─── SIP corpus milestones (standalone) ─────────────────────────────────── */
$sipMilestones = [];
if ($monthlySip > 0) {
    foreach ([5, 10, 20] as $h) {
        $r = 0.12 / 12; // base 12%
        $sipCorpus = $monthlySip * (pow(1 + $r, $h * 12) - 1) / $r * (1 + $r);
        $sipMilestones[$h] = round($sipCorpus, 0);
    }
}

json_response(true, 'Net worth projection calculated.', [
    'current' => [
        'net_worth'        => round($netWorth, 2),
        'total_assets'     => round($totalAssets, 2),
        'total_liabilities'=> round($loanVal, 2),
        'monthly_sip'      => round($monthlySip, 2),
        'allocation' => [
            'equity_pct' => round($equityPct * 100, 1),
            'debt_pct'   => round($debtPct   * 100, 1),
            'other_pct'  => round($otherPct  * 100, 1),
        ],
        'asset_breakup' => [
            'mutual_funds' => round($mfVal, 2),
            'stocks'       => round($stockVal, 2),
            'nps'          => round($npsVal, 2),
            'fd'           => round($fdVal, 2),
            'savings'      => round($savVal, 2),
            'post_office'  => round($poVal, 2),
            'crypto'       => round($cryptoVal, 2),
        ],
        'historical_cagr'  => $historicalCagr,
    ],
    'projections'    => $projections,
    'sip_milestones' => $sipMilestones,
    'horizons'       => $horizons,
]);
