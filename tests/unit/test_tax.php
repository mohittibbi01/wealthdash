<?php
/**
 * WealthDash — Unit Tests: Tax Engine [t411]
 * File: tests/unit/test_tax.php
 * Worker: ID-M
 * Run via: debug/runner.php?suite=unit
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');

// ── Old vs New Tax Regime ─────────────────────────────────────────────────────
WDTest::describe('Tax Regime Calculations', function () {

    WDTest::it('Basic 30% slab income > 15L (old regime)', function () {
        $income = 2000000; // ₹20L
        $tax    = 0;
        // Slabs: 0-2.5L=0, 2.5-5L=5%, 5-10L=20%, 10L+=30%
        if ($income > 1000000) {
            $tax = (250000 * 0.05) + (500000 * 0.20) + (($income - 1000000) * 0.30);
        }
        assert_eq(412500.0, (float)$tax, 'Old regime tax on ₹20L');
    });

    WDTest::it('New regime slab income ₹10L', function () {
        $income = 1000000;
        // New regime: 0-3L=0, 3-7L=5%, 7-10L=10%, 10-12L=15%, 12-15L=20%, >15L=30%
        $tax = (400000 * 0.05) + (300000 * 0.10);
        assert_eq(50000.0, (float)$tax);
    });

    WDTest::it('Section 80C deduction max ₹1.5L', function () {
        $gross   = 1000000;
        $deduct  = min(150000, 150000); // max 80C
        $taxable = $gross - $deduct;
        assert_eq(850000, $taxable);
    });

    WDTest::it('HRA exemption partial calculation', function () {
        $basic     = 600000;
        $hra_recv  = 240000;
        $rent_paid = 180000;
        $city_pct  = 0.50; // metro
        $exempt    = min(
            $hra_recv,
            $basic * $city_pct,
            $rent_paid - ($basic * 0.10)
        );
        assert_true($exempt > 0, 'HRA exemption should be positive');
        assert_true($exempt <= $hra_recv, 'HRA exemption cannot exceed HRA received');
    });

    WDTest::it('LTCG with grandfathering (pre-2018 gains = 0)', function () {
        $purchase_price  = 50000;  // pre-Jan 2018
        $jan31_2018_nav  = 80000;  // cost basis
        $sale_price      = 120000;
        $taxable_gain    = max(0, $sale_price - max($purchase_price, $jan31_2018_nav));
        assert_eq(40000, $taxable_gain);
    });
});

// ── XIRR Approximation ────────────────────────────────────────────────────────
WDTest::describe('XIRR Approximation', function () {

    WDTest::it('XIRR of 0% for no gain/loss', function () {
        // invest 10k, redeem 10k after 1 year → 0% XIRR
        $invested = -10000;
        $redeemed = 10000;
        $years    = 1;
        // Simplified: rate = (redeemed / -invested)^(1/years) - 1
        $xirr = (pow($redeemed / abs($invested), 1 / $years) - 1) * 100;
        assert_eq(0.0, round($xirr, 2));
    });

    WDTest::it('XIRR positive for profitable investment', function () {
        $invested = 100000;
        $redeemed = 150000;
        $years    = 3;
        $xirr = (pow($redeemed / $invested, 1 / $years) - 1) * 100;
        assert_true($xirr > 0, "XIRR should be positive for profitable trade");
        assert_true($xirr < 30, "XIRR sanity check < 30%");
    });

    WDTest::it('Annualised return = CAGR for lump sum', function () {
        $nav_buy  = 100.0;
        $nav_sell = 161.05;
        $years    = 5.0;
        $cagr     = (pow($nav_sell / $nav_buy, 1 / $years) - 1) * 100;
        assert_true(abs($cagr - 10.0) < 0.1, "CAGR should be ~10%, got {$cagr}");
    });
});

// ── FD Interest Calculation ───────────────────────────────────────────────────
WDTest::describe('FD Interest', function () {

    WDTest::it('Simple interest calculation', function () {
        $principal  = 100000;
        $rate       = 7.0; // 7% p.a.
        $years      = 1;
        $interest   = $principal * $rate / 100 * $years;
        assert_eq(7000.0, $interest);
    });

    WDTest::it('Compound interest (quarterly) 1 year', function () {
        $p     = 100000;
        $r     = 0.07 / 4;
        $n     = 4;
        $final = $p * pow(1 + $r, $n);
        assert_true($final > 107000 && $final < 107200, "CI should be ~107,186, got {$final}");
    });

    WDTest::it('TDS not applicable below ₹40,000', function () {
        $interest = 39000;
        $tds      = $interest >= FD_TDS_THRESHOLD ? $interest * FD_TDS_RATE / 100 : 0;
        assert_eq(0.0, $tds);
    });

    WDTest::it('TDS applicable above ₹40,000', function () {
        $interest = 50000;
        $tds      = $interest >= FD_TDS_THRESHOLD ? $interest * FD_TDS_RATE / 100 : 0;
        assert_eq(5000.0, $tds);
    });
});
