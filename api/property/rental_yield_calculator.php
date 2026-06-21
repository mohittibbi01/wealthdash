<?php
/**
 * WealthDash — t124: Real Estate Portfolio — Property + Rental Yield
 * File: api/property/rental_yield_calculator.php
 *
 * ⚠️ NOTE FOR MASTER DEV: Core property CRUD is ALREADY BUILT in t463
 * (this same session) — api/property/property.php + properties table.
 * t463's property_summary action already returns rental_yield.
 *
 * This file adds the ONE missing piece from t124: a standalone
 * "what-if" rental yield CALCULATOR (compare potential properties
 * before buying — not tied to an existing saved property).
 *
 * Action: rental_yield_calculate
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');

if ($action !== 'rental_yield_calculate') {
    json_response(false, 'Use property_summary (t463) for saved properties, or rental_yield_calculate for what-if analysis.', [], 400);
}

$purchasePrice  = (float)($_POST['purchase_price']   ?? 0);
$monthlyRental  = (float)($_POST['monthly_rental']   ?? 0);
$annualExpenses = (float)($_POST['annual_expenses']  ?? 0); // maintenance, property tax, etc.
$loanAmount     = (float)($_POST['loan_amount']      ?? 0);
$loanRate       = (float)($_POST['loan_rate']        ?? 8.5);
$loanTenureYrs  = (int)($_POST['loan_tenure_years']  ?? 20);
$appreciationPct= (float)($_POST['appreciation_pct'] ?? 6.0); // assumed annual property appreciation

if ($purchasePrice <= 0) json_response(false, 'Purchase price required.');

$annualRental = $monthlyRental * 12;
$grossYield   = $purchasePrice > 0 ? round($annualRental / $purchasePrice * 100, 2) : 0;
$netAnnualIncome = $annualRental - $annualExpenses;
$netYield     = $purchasePrice > 0 ? round($netAnnualIncome / $purchasePrice * 100, 2) : 0;

// EMI calculation if loan involved
$emi = 0; $totalInterest = 0;
if ($loanAmount > 0 && $loanRate > 0 && $loanTenureYrs > 0) {
    $r = $loanRate / 12 / 100;
    $n = $loanTenureYrs * 12;
    $emi = round($loanAmount * $r * pow(1+$r, $n) / (pow(1+$r, $n) - 1), 2);
    $totalInterest = round(($emi * $n) - $loanAmount, 2);
}

$monthlyEmi = $emi;
$monthlyCashFlow = $monthlyRental - $monthlyEmi - ($annualExpenses / 12);
$cashOnCashReturn = ($purchasePrice - $loanAmount) > 0
    ? round(($monthlyCashFlow * 12) / ($purchasePrice - $loanAmount) * 100, 2)
    : 0;

// 10-year projection
$projection = [];
$propValue = $purchasePrice;
$cumulativeRental = 0;
for ($y = 1; $y <= 10; $y++) {
    $propValue *= (1 + $appreciationPct/100);
    $cumulativeRental += $annualRental * pow(1.05, $y-1); // assume 5% annual rent escalation
    $projection[] = [
        'year' => $y,
        'property_value' => round($propValue),
        'cumulative_rental' => round($cumulativeRental),
        'total_return' => round($propValue + $cumulativeRental - $purchasePrice),
    ];
}

json_response(true, 'ok', [
    'gross_yield_pct'    => $grossYield,
    'net_yield_pct'      => $netYield,
    'annual_rental'      => round($annualRental),
    'net_annual_income'  => round($netAnnualIncome),
    'monthly_emi'        => $monthlyEmi,
    'monthly_cash_flow'  => round($monthlyCashFlow),
    'cash_on_cash_return'=> $cashOnCashReturn,
    'total_loan_interest'=> $totalInterest,
    'projection_10yr'     => $projection,
    'verdict' => match(true) {
        $netYield >= 4 => 'Good rental yield — above typical Indian residential average (2-3%)',
        $netYield >= 2 => 'Average rental yield — typical for Indian residential property',
        default        => 'Below-average yield — consider commercial property or other locations',
    },
]);
