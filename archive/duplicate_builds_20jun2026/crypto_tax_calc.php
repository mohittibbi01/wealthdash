<?php
/**
 * WealthDash — t42: Crypto Tax 30% Flat Calculator
 *
 * Section 115BBH / 194S — VDA Tax calculator with what-if scenarios,
 * FIFO/LIFO cost basis, TDS tracking, and saved calculations.
 *
 * Actions handled (routed from router.php):
 *   POST crypto_tax_calc      — calculate tax on single/batch transactions
 *   GET  crypto_tax_report    — full FY report from actual txn history
 *   POST crypto_tax_save      — save a what-if calculation
 *   GET  crypto_tax_history   — list saved calculations
 *   POST crypto_tax_delete    — delete saved calculation
 *
 * Router note (add to router.php after existing crypto block ~line 832):
 *   case 'crypto_tax_calc':
 *   case 'crypto_tax_report':
 *   case 'crypto_tax_save':
 *   case 'crypto_tax_history':
 *   case 'crypto_tax_delete':
 *       require APP_ROOT . '/api/crypto/crypto_tax_calc.php'; exit;
 *
 * DB deps: crypto_tax_saved (t42_migration.sql)
 *          crypto_transactions, crypto_holdings (existing)
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::pdo();
$action      = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Tax constants (India FY 2024-25 onwards) ──────────────────────────────────
const TAX_RATE_VDA        = 0.30;    // 30% flat on gains
const TAX_TDS_SALE_RATE   = 0.01;    // 1% TDS on sale value (Sec 194S)
const TAX_TDS_THRESHOLD   = 10000;   // TDS applies if sale > ₹10,000/year
const TAX_SURCHARGE_RATES = [        // Surcharge on 30% tax (for high income)
    5000000   => 0.10,  // 10% surcharge if total income > ₹50L
    10000000  => 0.15,  // 15% if > ₹1Cr
    20000000  => 0.25,  // 25% if > ₹2Cr
    50000000  => 0.37,  // 37% if > ₹5Cr (w.e.f. 2023-24 max 25%)
];
const TAX_HEALTH_EDU_CESS = 0.04;    // 4% cess on (tax + surcharge)

function t42_compute_tax(float $saleValue, float $costBasis, float $existingTds = 0): array
{
    $gain         = $saleValue - $costBasis;
    $isGain       = $gain > 0;

    // 30% flat on gain ONLY (no loss offset)
    $taxOnGain    = $isGain ? round($gain * TAX_RATE_VDA, 2) : 0.0;

    // 4% cess
    $cess         = round($taxOnGain * TAX_HEALTH_EDU_CESS, 2);
    $totalTax     = round($taxOnGain + $cess, 2);

    // 1% TDS on sale value (deducted at source by exchange)
    $tdsDue       = $saleValue >= TAX_TDS_THRESHOLD ? round($saleValue * TAX_TDS_SALE_RATE, 2) : 0.0;
    $netTds       = max(0.0, $tdsDue - $existingTds);   // Additional TDS if any

    // Net tax payable after adjusting TDS already deducted
    $netTaxPayable = max(0.0, $totalTax - $existingTds);

    return [
        'sale_value'       => round($saleValue,  2),
        'cost_basis'       => round($costBasis,  2),
        'gain_loss'        => round($gain,        2),
        'is_gain'          => $isGain,
        'tax_at_30pct'     => $taxOnGain,
        'cess_4pct'        => $cess,
        'total_tax'        => $totalTax,
        'tds_on_sale_1pct' => $tdsDue,
        'tds_already_deducted' => round($existingTds, 2),
        'net_tax_payable'  => $netTaxPayable,
        'effective_rate'   => $saleValue > 0 ? round($totalTax / $saleValue * 100, 4) : 0,
        'no_loss_offset'   => !$isGain,  // Section 115BBH — loss cannot offset other income
        'section'          => 'Sec 115BBH (30% flat) + Sec 194S (1% TDS)',
    ];
}

// ════════════════════════════════════════════════════════════════════════════
switch ($action) {

    // ── SINGLE / BATCH CALCULATOR ─────────────────────────────────────────
    case 'crypto_tax_calc': {
        // Support both single and batch modes
        $mode = clean($_POST['mode'] ?? 'single'); // single | batch | scenario

        if ($mode === 'batch') {
            // JSON array of {sale_value, cost_basis, tds_deducted, coin, date}
            $raw   = $_POST['transactions'] ?? '[]';
            $txns  = json_decode($raw, true) ?: [];

            if (empty($txns)) json_response(false, 'transactions array required for batch mode.');

            $results       = [];
            $totalGain     = 0.0;
            $totalTax      = 0.0;
            $totalTds      = 0.0;
            $totalSale     = 0.0;
            $totalCost     = 0.0;

            foreach ($txns as $i => $t) {
                $saleVal  = (float)($t['sale_value']   ?? 0);
                $costBase = (float)($t['cost_basis']   ?? 0);
                $tdsAlr   = (float)($t['tds_deducted'] ?? 0);

                $calc = t42_compute_tax($saleVal, $costBase, $tdsAlr);
                $calc['coin']   = clean($t['coin']   ?? '');
                $calc['date']   = clean($t['date']   ?? '');
                $calc['serial'] = $i + 1;
                $results[]      = $calc;

                $totalSale  += $saleVal;
                $totalCost  += $costBase;
                $totalGain  += $calc['gain_loss'];
                $totalTax   += $calc['total_tax'];
                $totalTds   += $calc['tds_already_deducted'];
            }

            json_response(true, '', [
                'mode'       => 'batch',
                'results'    => $results,
                'totals' => [
                    'sale_value'         => round($totalSale, 2),
                    'cost_basis'         => round($totalCost, 2),
                    'total_gain'         => round($totalGain, 2),
                    'total_tax'          => round($totalTax,  2),
                    'tds_deducted_total' => round($totalTds,  2),
                    'net_payable'        => round(max(0, $totalTax - $totalTds), 2),
                ],
            ]);
            break;
        }

        if ($mode === 'scenario') {
            // What-if: "If I sell X coins at price Y, what tax?"
            $coinId    = clean($_POST['coin_id']    ?? '');
            $coinSym   = clean($_POST['coin_symbol']?? '');
            $qtyToSell = (float)($_POST['qty']      ?? 0);
            $sellPrice = (float)($_POST['sell_price_inr'] ?? 0);   // Price per coin in INR
            $costBase  = (float)($_POST['cost_basis_inr'] ?? 0);   // Avg buy price total INR

            // If cost_basis not given, try to fetch from holdings
            if ($costBase <= 0 && $coinId && $qtyToSell > 0) {
                $holding = DB::fetchOne(
                    "SELECT ch.avg_buy_price, ch.quantity, ch.total_invested
                     FROM crypto_holdings ch
                     JOIN portfolios p ON p.id = ch.portfolio_id
                     WHERE ch.coin_id = ? AND p.user_id = ? LIMIT 1",
                    [$coinId, $userId]
                );
                if ($holding) {
                    $costBase = round((float)$holding['avg_buy_price'] * $qtyToSell, 2);
                }
            }

            if ($qtyToSell <= 0)  json_response(false, 'qty required.');
            if ($sellPrice <= 0)  json_response(false, 'sell_price_inr required.');

            $saleValue = round($qtyToSell * $sellPrice, 2);
            $calc = t42_compute_tax($saleValue, $costBase, 0);
            $calc['coin_id']     = $coinId;
            $calc['coin_symbol'] = $coinSym;
            $calc['qty_sold']    = $qtyToSell;
            $calc['sell_price']  = $sellPrice;
            $calc['mode']        = 'scenario';

            // Break-even price
            $calc['breakeven_price'] = $qtyToSell > 0
                ? round($costBase / $qtyToSell, 4)
                : 0;

            // Minimum sell price to be profitable after tax
            // Gain * 1.34 = sale, so sale = cost/(1 - 0.30*1.04) ≈ cost/0.688
            $taxFactor = 1 - (TAX_RATE_VDA * (1 + TAX_HEALTH_EDU_CESS));
            $calc['min_profitable_price'] = $qtyToSell > 0 && $taxFactor > 0
                ? round($costBase / $taxFactor / $qtyToSell, 4)
                : 0;

            json_response(true, '', $calc);
            break;
        }

        // ── Single calculation (default) ──
        $saleValue  = (float)($_POST['sale_value']   ?? 0);
        $costBasis  = (float)($_POST['cost_basis']   ?? 0);
        $tdsAlready = (float)($_POST['tds_deducted'] ?? 0);
        $coin       = clean($_POST['coin']           ?? '');

        if ($saleValue <= 0) json_response(false, 'sale_value (₹) required.');
        if ($costBasis < 0)  json_response(false, 'cost_basis cannot be negative.');

        $calc = t42_compute_tax($saleValue, $costBasis, $tdsAlready);
        $calc['coin'] = $coin;
        $calc['mode'] = 'single';

        // Compute what different sell prices would mean (sensitivity analysis)
        $steps = [];
        $pct   = [0.5, 0.75, 1.0, 1.25, 1.5, 2.0];
        foreach ($pct as $m) {
            $sv = $saleValue * $m;
            $c  = t42_compute_tax($sv, $costBasis, 0);
            $steps[] = [
                'sale_multiplier' => $m,
                'sale_value'      => round($sv, 2),
                'total_tax'       => $c['total_tax'],
                'net_after_tax'   => round($sv - $c['total_tax'], 2),
            ];
        }
        $calc['sensitivity'] = $steps;

        json_response(true, '', $calc);
        break;
    }

    // ── FULL FY REPORT FROM ACTUAL TRANSACTIONS ────────────────────────────
    case 'crypto_tax_report': {
        $fy = clean($_GET['fy'] ?? '');  // e.g. "2024-25"

        // Parse FY
        if (!$fy || !preg_match('/^(\d{4})-(\d{2})$/', $fy, $m)) {
            // Default: current FY
            $month = (int)date('n');
            $year  = (int)date('Y');
            $fyStart = $month >= 4 ? $year : $year - 1;
            $fy = $fyStart . '-' . substr((string)($fyStart + 1), -2);
            $m  = [null, (string)$fyStart, substr((string)($fyStart + 1), -2)];
        }

        $fyStartDate = $m[1] . '-04-01';
        $fyEndDate   = ((int)$m[1] + 1) . '-03-31';

        // Fetch all SELL transactions in FY
        $sells = DB::fetchAll(
            "SELECT ct.id, ct.coin_id, ct.coin_symbol, ct.coin_name,
                    ct.txn_date, ct.quantity, ct.price_inr, ct.amount_inr,
                    ct.tds_deducted, ct.exchange
             FROM crypto_transactions ct
             JOIN portfolios p ON p.id = ct.portfolio_id
             WHERE ct.txn_type IN ('SELL','TRANSFER_OUT')
               AND ct.txn_date BETWEEN ? AND ?
               AND p.user_id = ?
             ORDER BY ct.txn_date ASC",
            [$fyStartDate, $fyEndDate, $userId]
        );

        // Fetch avg buy price from holdings for cost basis
        $holdings = DB::fetchAll(
            "SELECT ch.coin_id, ch.avg_buy_price
             FROM crypto_holdings ch
             JOIN portfolios p ON p.id = ch.portfolio_id
             WHERE p.user_id = ?",
            [$userId]
        );
        $avgBuy = array_column($holdings, 'avg_buy_price', 'coin_id');

        $breakdown = [];
        $totals = ['sale_value' => 0, 'cost_basis' => 0, 'gain' => 0,
                   'tax' => 0, 'tds_deducted' => 0, 'net_payable' => 0];

        foreach ($sells as $s) {
            $saleVal  = (float)$s['amount_inr'];
            $avgPrice = (float)($avgBuy[$s['coin_id']] ?? $s['price_inr']);
            $costBase = round((float)$s['quantity'] * $avgPrice, 2);
            $tds      = (float)$s['tds_deducted'];

            $calc = t42_compute_tax($saleVal, $costBase, $tds);

            $breakdown[] = [
                'txn_id'       => $s['id'],
                'date'         => $s['txn_date'],
                'coin'         => $s['coin_symbol'],
                'coin_name'    => $s['coin_name'],
                'qty'          => (float)$s['quantity'],
                'sale_price'   => (float)$s['price_inr'],
                'sale_value'   => round($saleVal,  2),
                'cost_basis'   => $costBase,
                'gain'         => $calc['gain_loss'],
                'tax_payable'  => $calc['total_tax'],
                'tds_deducted' => $tds,
                'net_tax_due'  => $calc['net_tax_payable'],
                'exchange'     => $s['exchange'],
            ];

            $totals['sale_value']   += $saleVal;
            $totals['cost_basis']   += $costBase;
            $totals['gain']         += $calc['gain_loss'];
            $totals['tax']          += $calc['total_tax'];
            $totals['tds_deducted'] += $tds;
            $totals['net_payable']  += $calc['net_tax_payable'];
        }

        foreach ($totals as &$v) $v = round($v, 2);
        unset($v);

        json_response(true, '', [
            'fy'              => $fy,
            'fy_start'        => $fyStartDate,
            'fy_end'          => $fyEndDate,
            'breakdown'       => $breakdown,
            'totals'          => $totals,
            'transaction_count'=> count($sells),
            'notes' => [
                'law'          => 'Section 115BBH — VDA gains taxed at 30% flat regardless of holding period.',
                'no_offset'    => 'Losses from VDA cannot be set off against any other income.',
                'tds'          => 'Section 194S — 1% TDS deducted by exchange on sale > ₹10,000.',
                'itr_schedule' => 'Report under Schedule VDA in ITR-2 or ITR-3.',
                'cess'         => '4% Health & Education Cess on tax amount.',
            ],
        ]);
        break;
    }

    // ── SAVE CALCULATION ──────────────────────────────────────────────────
    case 'crypto_tax_save': {
        $label      = clean($_POST['label']       ?? '');
        $calcJson   = $_POST['calc_data']         ?? '';
        $fyYear     = clean($_POST['fy']          ?? date('Y') . '-' . substr((string)(date('Y')+1),-2));

        if (!$calcJson) json_response(false, 'calc_data required.');

        // Validate JSON
        $calcData = json_decode($calcJson, true);
        if (!$calcData) json_response(false, 'Invalid calc_data JSON.');

        $id = DB::insert(
            "INSERT INTO crypto_tax_saved (user_id, label, fy, calc_json, created_at)
             VALUES (?, ?, ?, ?, NOW())",
            [$userId, $label ?: ('Calculation ' . date('d M H:i')), $fyYear, json_encode($calcData)]
        );

        json_response(true, 'Calculation saved.', ['id' => $id]);
        break;
    }

    // ── LIST SAVED CALCULATIONS ───────────────────────────────────────────
    case 'crypto_tax_history': {
        $rows = DB::fetchAll(
            "SELECT id, label, fy, calc_json, created_at
             FROM crypto_tax_saved
             WHERE user_id = ?
             ORDER BY created_at DESC
             LIMIT 50",
            [$userId]
        );

        foreach ($rows as &$r) {
            $r['calc'] = json_decode($r['calc_json'], true) ?? [];
            unset($r['calc_json']);
        }
        unset($r);

        json_response(true, '', ['saved' => $rows]);
        break;
    }

    // ── DELETE SAVED CALCULATION ──────────────────────────────────────────
    case 'crypto_tax_delete': {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_response(false, 'id required.');
        DB::execute("DELETE FROM crypto_tax_saved WHERE id = ? AND user_id = ?", [$id, $userId]);
        json_response(true, 'Deleted.');
        break;
    }

    default:
        json_response(false, "Unknown tax calc action: {$action}");
}
