<?php
/**
 * WealthDash — Tax Slab Calculator 2024-25
 * Task t496: Old vs New regime comparison with all deductions
 * Action: tax_slab_calc
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$currentUser = require_auth();

$action = $_POST['action'] ?? 'tax_slab_calc';

// ── NEW REGIME 2024-25 (Budget 2024) ──────────────────────────────
function calcNewRegimeTax(float $income): array {
    // Standard deduction ₹75,000 for salaried (Budget 2024)
    $stdDeduction = 75000;
    $taxable      = max(0, $income - $stdDeduction);

    $slabs = [
        [300000,  0.00],
        [700000,  0.05],
        [1000000, 0.10],
        [1200000, 0.15],
        [1500000, 0.20],
        [PHP_INT_MAX, 0.30],
    ];

    $tax = 0; $prev = 0;
    foreach ($slabs as [$limit, $rate]) {
        if ($taxable <= $prev) break;
        $chunk = min($taxable, $limit) - $prev;
        $tax  += $chunk * $rate;
        $prev  = $limit;
    }

    // Rebate 87A — if taxable income ≤ ₹7L, full rebate (new regime)
    $rebate = 0;
    if ($taxable <= 700000) {
        $rebate = min($tax, 25000);
        $tax    = max(0, $tax - $rebate);
    }

    // Surcharge
    $surcharge = 0;
    if ($taxable > 5000000 && $taxable <= 10000000)  $surcharge = $tax * 0.10;
    elseif ($taxable > 10000000 && $taxable <= 20000000) $surcharge = $tax * 0.15;
    elseif ($taxable > 20000000) $surcharge = $tax * 0.25;

    $total    = $tax + $surcharge;
    $cess     = $total * 0.04;
    $totalTax = $total + $cess;

    return [
        'regime'           => 'New (2024-25)',
        'gross_income'     => round($income),
        'std_deduction'    => $stdDeduction,
        'taxable_income'   => round($taxable),
        'tax_before_rebate'=> round($tax + $rebate),
        'rebate_87a'       => round($rebate),
        'income_tax'       => round($tax),
        'surcharge'        => round($surcharge),
        'health_edu_cess'  => round($cess),
        'total_tax'        => round($totalTax),
        'effective_rate'   => $income > 0 ? round($totalTax / $income * 100, 2) : 0,
        'take_home'        => round($income - $totalTax),
    ];
}

// ── OLD REGIME 2024-25 ────────────────────────────────────────────
function calcOldRegimeTax(float $income, float $deductions80c, float $hra,
                           float $homeLoanInt, float $nps80ccd, float $otherDeductions): array {
    $stdDeduction = 50000; // old regime std deduction

    $total80c  = min($deductions80c, 150000);           // 80C cap ₹1.5L
    $totalNps  = min($nps80ccd, 50000);                  // 80CCD(1B) ₹50K
    $total24b  = min($homeLoanInt, 200000);              // Section 24(b) ₹2L

    $taxable = max(0,
        $income - $stdDeduction - $total80c - $hra
        - $total24b - $totalNps - $otherDeductions
    );

    $slabs = [
        [250000,     0.00],
        [500000,     0.05],
        [1000000,    0.20],
        [PHP_INT_MAX,0.30],
    ];

    $tax = 0; $prev = 0;
    foreach ($slabs as [$limit, $rate]) {
        if ($taxable <= $prev) break;
        $chunk = min($taxable, $limit) - $prev;
        $tax  += $chunk * $rate;
        $prev  = $limit;
    }

    // Rebate 87A — if taxable ≤ ₹5L
    $rebate = 0;
    if ($taxable <= 500000) {
        $rebate = min($tax, 12500);
        $tax    = max(0, $tax - $rebate);
    }

    $surcharge = 0;
    if ($taxable > 5000000 && $taxable <= 10000000)  $surcharge = $tax * 0.10;
    elseif ($taxable > 10000000 && $taxable <= 20000000) $surcharge = $tax * 0.15;
    elseif ($taxable > 20000000) $surcharge = $tax * 0.25;

    $total    = $tax + $surcharge;
    $cess     = $total * 0.04;
    $totalTax = $total + $cess;

    $totalDeductions = $stdDeduction + $total80c + $hra + $total24b + $totalNps + $otherDeductions;

    return [
        'regime'            => 'Old Regime',
        'gross_income'      => round($income),
        'std_deduction'     => $stdDeduction,
        'deductions_80c'    => round($total80c),
        'hra_exemption'     => round($hra),
        'home_loan_int'     => round($total24b),
        'nps_80ccd'         => round($totalNps),
        'other_deductions'  => round($otherDeductions),
        'total_deductions'  => round($totalDeductions),
        'taxable_income'    => round($taxable),
        'tax_before_rebate' => round($tax + $rebate),
        'rebate_87a'        => round($rebate),
        'income_tax'        => round($tax),
        'surcharge'         => round($surcharge),
        'health_edu_cess'   => round($cess),
        'total_tax'         => round($totalTax),
        'effective_rate'    => $income > 0 ? round($totalTax / $income * 100, 2) : 0,
        'take_home'         => round($income - $totalTax),
    ];
}

// ── Calculate ─────────────────────────────────────────────────────
$income          = max(0, (float)($_POST['income']            ?? 0));
$deductions80c   = max(0, (float)($_POST['deductions_80c']    ?? 0));
$hra             = max(0, (float)($_POST['hra']               ?? 0));
$homeLoanInt     = max(0, (float)($_POST['home_loan_interest']?? 0));
$nps80ccd        = max(0, (float)($_POST['nps_80ccd']         ?? 0));
$otherDeductions = max(0, (float)($_POST['other_deductions']  ?? 0));

if ($income <= 0) {
    echo json_encode(['success' => false, 'error' => 'Income is required']);
    return;
}

$newRegime = calcNewRegimeTax($income);
$oldRegime = calcOldRegimeTax($income, $deductions80c, $hra, $homeLoanInt, $nps80ccd, $otherDeductions);

$savings = $oldRegime['total_tax'] - $newRegime['total_tax'];
$better  = $savings >= 0 ? 'new' : 'old';

echo json_encode([
    'success'    => true,
    'data'       => [
        'new_regime'        => $newRegime,
        'old_regime'        => $oldRegime,
        'savings'           => abs(round($savings)),
        'better_regime'     => $better,
        'recommendation'    => $savings > 0
            ? "New Regime saves ₹" . number_format(abs($savings), 0) . " more for you this FY."
            : ($savings < 0
                ? "Old Regime saves ₹" . number_format(abs($savings), 0) . " more — your deductions are significant."
                : "Both regimes give identical tax for your income."),
        'breakeven_deductions' => round($income * 0.15), // rough breakeven
    ]
]);
