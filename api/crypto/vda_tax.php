<?php
/**
 * WealthDash — VDA Tax Calculator (t42)
 * Section 115BBH — 30% flat tax on crypto gains
 * Section 194S  — 1% TDS on sell transactions
 *
 * GET  /api/?action=crypto_vda_tax   &portfolio_id=X
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$portfolioId = (int)($_GET['portfolio_id'] ?? 0);
$pWhere = $portfolioId
    ? "AND p.id = {$portfolioId} AND p.user_id = {$userId}"
    : "AND p.user_id = {$userId}";

switch ($action) {

    case 'crypto_vda_tax': {
        $fy      = (int)date('n') >= 4 ? (int)date('Y') : (int)date('Y') - 1;
        $fyStart = "{$fy}-04-01";
        $fyEnd   = ($fy + 1) . "-03-31";

        // All sell transactions this FY
        $sells = DB::fetchAll(
            "SELECT ct.coin_id, ct.coin_symbol, ct.txn_type, ct.quantity, ct.price_inr,
                    ct.amount_inr, ct.tds_deducted, ct.txn_date
             FROM crypto_transactions ct
             JOIN portfolios p ON p.id = ct.portfolio_id
             WHERE ct.txn_type = 'SELL' AND ct.txn_date BETWEEN ? AND ? {$pWhere}
             ORDER BY ct.txn_date ASC",
            [$fyStart, $fyEnd]
        );

        // For each sell, find matching buy avg (simplified FIFO using avg cost)
        $coinAvgs = DB::fetchAll(
            "SELECT ch.coin_id, ch.avg_buy_price FROM crypto_holdings ch
             JOIN portfolios p ON p.id = ch.portfolio_id WHERE {$pWhere}"
        );
        $avgMap = [];
        foreach ($coinAvgs as $r) $avgMap[$r['coin_id']] = (float)$r['avg_buy_price'];

        $totalGain   = 0; $totalTds = 0; $totalSaleValue = 0;
        $breakdown   = [];

        foreach ($sells as $s) {
            $saleVal    = (float)$s['amount_inr'];
            $costBasis  = ($avgMap[$s['coin_id']] ?? 0) * (float)$s['quantity'];
            $gain       = $saleVal - $costBasis;
            $taxPayable = max(0, $gain * 0.30);   // 30% flat — no deductions, no loss offset
            $tds        = (float)$s['tds_deducted'];

            $totalGain       += $gain;
            $totalTds        += $tds;
            $totalSaleValue  += $saleVal;

            $breakdown[] = [
                'date'         => $s['txn_date'],
                'coin'         => $s['coin_symbol'],
                'qty'          => $s['quantity'],
                'sale_price'   => $s['price_inr'],
                'sale_value'   => round($saleVal, 2),
                'cost_basis'   => round($costBasis, 2),
                'gain'         => round($gain, 2),
                'tax_payable'  => round($taxPayable, 2),
                'tds_deducted' => round($tds, 2),
                'net_tax_due'  => round(max(0, $taxPayable - $tds), 2),
            ];
        }

        $totalTaxPayable = max(0, $totalGain * 0.30);
        $netTaxDue       = max(0, $totalTaxPayable - $totalTds);

        // All buy transactions TDS (applicable when buyer is specified entity; shown for info)
        $totalBuyTds = (float)DB::fetchOne(
            "SELECT COALESCE(SUM(ct.tds_deducted),0) FROM crypto_transactions ct
             JOIN portfolios p ON p.id = ct.portfolio_id
             WHERE ct.txn_type = 'BUY' AND ct.txn_date BETWEEN ? AND ? {$pWhere}",
            [$fyStart, $fyEnd]
        )['COALESCE(SUM(ct.tds_deducted),0)'] ?? 0;

        json_response(true, '', [
            'fy'                  => "FY {$fy}-" . ($fy + 1),
            'total_sale_value'    => round($totalSaleValue, 2),
            'total_gain'          => round($totalGain, 2),
            'total_loss'          => round(min(0, $totalGain), 2),  // losses CANNOT offset
            'tax_rate'            => '30% flat (Sec 115BBH)',
            'tax_payable'         => round($totalTaxPayable, 2),
            'tds_deducted_sell'   => round($totalTds, 2),
            'net_tax_due'         => round($netTaxDue, 2),
            'no_loss_offset_note' => 'Crypto losses CANNOT be set off against any other income (Sec 115BBH)',
            'breakdown'           => $breakdown,
            'itr_schedule'        => 'Schedule VDA in ITR-2/ITR-3',
        ]);
        break;
    }

    default:
        json_response(false, "Unknown VDA tax action: {$action}");
}
