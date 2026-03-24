<?php
/**
 * WealthDash — FY Gains Report
 * LTCG + STCG + Dividends broken down by Financial Year
 * POST /api/?action=report_fy_gains
 *
 * ✅ FIX: Proper FIFO lot tracking across multiple SELL transactions
 *         Each buy lot is consumed only once — remaining units carry forward
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

require_once APP_ROOT . '/includes/tax_engine.php';

$portfolioId = (int)($_POST['portfolio_id'] ?? 0);
if (!$portfolioId) $portfolioId = get_user_portfolio_id((int)($currentUser['id'] ?? 0));
$filterFy    = clean($_POST['fy'] ?? '');   // e.g. "2024-25" or "" for all

if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
    json_response(false, 'Invalid or inaccessible portfolio.');
}

/* ─── Helper: financial year from a date ─────────────────────────────────── */
function get_fy(string $date): string {
    $d = new DateTime($date);
    $y = (int) $d->format('Y');
    $m = (int) $d->format('n');
    return $m >= 4 ? "{$y}-" . substr((string)($y + 1), -2) : ($y - 1) . '-' . substr((string)$y, -2);
}

/* ─── 1. MF SELL transactions → realised gains (PROPER FIFO) ─────────────── */

// Get all distinct fund+folio combos that have sells
$mfCombos = DB::fetchAll(
    "SELECT DISTINCT fund_id, folio_number
     FROM mf_transactions
     WHERE portfolio_id = ?
       AND transaction_type IN ('SELL','SWITCH_OUT','SWP')",
    [$portfolioId]
);

$mfGains = [];

foreach ($mfCombos as $combo) {
    $fundId    = (int) $combo['fund_id'];
    $folio     = ($combo['folio_number'] !== '' && $combo['folio_number'] !== null)
                 ? $combo['folio_number'] : null;

    // Get ALL transactions for this fund+folio in chronological order
    // Fix: use proper folio matching — exact match if folio set, else NULL/empty
    if ($folio !== null) {
        $allTxns = DB::fetchAll(
            "SELECT t.id, t.transaction_type, t.txn_date, t.units, t.nav, t.value_at_cost,
                    f.scheme_name, f.category, f.sub_category, fh.name AS fund_house,
                    f.min_ltcg_days, f.lock_in_days
             FROM mf_transactions t
             JOIN funds f ON f.id = t.fund_id
             JOIN fund_houses fh ON fh.id = f.fund_house_id
             WHERE t.portfolio_id = ?
               AND t.fund_id = ?
               AND t.folio_number = ?
             ORDER BY t.txn_date ASC, t.id ASC",
            [$portfolioId, $fundId, $folio]
        );
    } else {
        $allTxns = DB::fetchAll(
            "SELECT t.id, t.transaction_type, t.txn_date, t.units, t.nav, t.value_at_cost,
                    f.scheme_name, f.category, f.sub_category, fh.name AS fund_house,
                    f.min_ltcg_days, f.lock_in_days
             FROM mf_transactions t
             JOIN funds f ON f.id = t.fund_id
             JOIN fund_houses fh ON fh.id = f.fund_house_id
             WHERE t.portfolio_id = ?
               AND t.fund_id = ?
               AND (t.folio_number IS NULL OR t.folio_number = '')
             ORDER BY t.txn_date ASC, t.id ASC",
            [$portfolioId, $fundId]
        );
    }

    // Build FIFO queue from BUY transactions, process SELLs in order
    $queue = []; // ['units' => float, 'nav' => float, 'date' => string]

    foreach ($allTxns as $t) {
        $type  = $t['transaction_type'];
        $units = (float) $t['units'];
        $nav   = (float) $t['nav'];
        $date  = $t['txn_date'];

        if (in_array($type, ['BUY', 'DIV_REINVEST', 'SWITCH_IN', 'STP_IN'])) {
            // Add to FIFO queue
            $queue[] = ['units' => $units, 'nav' => $nav, 'date' => $date];

        } elseif (in_array($type, ['SELL', 'SWITCH_OUT', 'SWP'])) {
            $sellFy = get_fy($date);
            if ($filterFy && $sellFy !== $filterFy) {
                // Still consume from queue even if filtered out, to keep FIFO state correct
                $remaining = $units;
                while ($remaining > 0.0001 && !empty($queue)) {
                    $lot = &$queue[0];
                    if ($lot['units'] <= $remaining) {
                        $remaining -= $lot['units'];
                        array_shift($queue);
                    } else {
                        $lot['units'] -= $remaining;
                        $remaining = 0;
                    }
                    unset($lot);
                }
                continue;
            }

            $remaining  = $units;
            $totalCost  = 0.0;
            $proceeds   = round($units * $nav, 2);
            $firstBuyDate = !empty($queue) ? $queue[0]['date'] : $date;

            // FIFO: consume from earliest lots
            while ($remaining > 0.0001 && !empty($queue)) {
                $lot = &$queue[0];
                if ($lot['units'] <= $remaining) {
                    $totalCost += $lot['units'] * $lot['nav'];
                    $remaining -= $lot['units'];
                    array_shift($queue);
                } else {
                    $totalCost    += $remaining * $lot['nav'];
                    $lot['units'] -= $remaining;
                    $remaining     = 0;
                }
                unset($lot);
            }

            $gain     = round($proceeds - $totalCost, 2);
            $days     = (int)(new DateTime($date))->diff(new DateTime($firstBuyDate))->days;

            $cat      = strtolower($t['category'] ?? '');
            // Detect asset type from category for debt-slab rule
            $assetType = (strpos($cat, 'debt') !== false || strpos($cat, 'liquid') !== false
                          || strpos($cat, 'money market') !== false || strpos($cat, 'credit') !== false)
                         ? 'debt' : (strpos($cat, 'elss') !== false ? 'elss' : 'equity');

            // Use fund-specific LTCG days from DB (set correctly by fetch_amfi_funds.php)
            $fundMinLtcgDays = (int)($t['min_ltcg_days'] ?? 0);

            $taxInfo = TaxEngine::mf_gain_tax($gain, $firstBuyDate, $date, $assetType, $fundMinLtcgDays);

            $mfGains[] = [
                'asset_class'  => 'Mutual Fund',
                'name'         => $t['scheme_name'],
                'fund_house'   => $t['fund_house'],
                'category'     => $t['category'],
                'folio'        => $combo['folio_number'],
                'sell_date'    => format_date($date),
                'units'        => $units,
                'sell_nav'     => $nav,
                'proceeds'     => $proceeds,
                'cost'         => round($totalCost, 2),
                'gain'         => $gain,
                'days_held'    => $days,
                'gain_type'    => $taxInfo['gain_type'],
                'tax_rate'     => $taxInfo['tax_rate'],
                'tax_amount'   => $taxInfo['tax_amount'],
                'fy'           => $sellFy,
            ];
        }
    }
}

/* ─── 2. STOCK SELL transactions → realised gains (PROPER FIFO) ─────────── */
$stockTxns = DB::fetchAll(
    "SELECT t.id, t.stock_id, t.txn_date, t.txn_type,
            t.quantity, t.price, t.value_at_cost,
            t.brokerage, t.stt, t.exchange_charges,
            sm.symbol, sm.company_name, sm.exchange
     FROM stock_transactions t
     JOIN stock_master sm ON sm.id = t.stock_id
     WHERE t.portfolio_id = ?
     ORDER BY t.stock_id ASC, t.txn_date ASC, t.id ASC",
    [$portfolioId]
);

// Group by stock and run FIFO per stock
$stockTxnsByStock = [];
foreach ($stockTxns as $t) {
    $stockTxnsByStock[$t['stock_id']][] = $t;
}

$stockGains = [];
foreach ($stockTxnsByStock as $stockId => $txns) {
    $queue = []; // FIFO: ['qty'=>float, 'price'=>float, 'date'=>string]

    foreach ($txns as $t) {
        $type = $t['txn_type'];
        $qty  = (float)$t['quantity'];

        if (in_array($type, ['BUY', 'BONUS', 'SPLIT'])) {
            $queue[] = ['qty' => $qty, 'price' => (float)$t['price'], 'date' => $t['txn_date']];

        } elseif ($type === 'SELL') {
            $sellDate = $t['txn_date'];
            $sellFy   = get_fy($sellDate);
            $proceeds = (float)$t['value_at_cost'];
            $charges  = (float)$t['brokerage'] + (float)$t['stt'] + (float)$t['exchange_charges'];

            $remaining  = $qty;
            $totalCost  = 0.0;
            $firstBuyDate = !empty($queue) ? $queue[0]['date'] : $sellDate;

            // FIFO consume
            while ($remaining > 0.0001 && !empty($queue)) {
                $lot = &$queue[0];
                if ($lot['qty'] <= $remaining) {
                    $totalCost += $lot['qty'] * $lot['price'];
                    $remaining -= $lot['qty'];
                    array_shift($queue);
                } else {
                    $totalCost   += $remaining * $lot['price'];
                    $lot['qty']  -= $remaining;
                    $remaining    = 0;
                }
                unset($lot);
            }

            if ($filterFy && $sellFy !== $filterFy) continue;

            $gain    = $proceeds - $totalCost - $charges;
            $days    = (int)(new DateTime($sellDate))->diff(new DateTime($firstBuyDate))->days;

            // t39: Grandfathering — for stocks bought before Jan 31 2018
            // FMV lookup not implemented (would need NSE historical data)
            // Using 0 for now — user can manually override in UI
            $taxInfo = TaxEngine::stock_gain_tax($gain, $firstBuyDate, $sellDate, $proceeds, 0);

            $stockGains[] = [
                'asset_class'    => 'Stock',
                'name'           => $t['company_name'],
                'symbol'         => $t['symbol'],
                'exchange'       => $t['exchange'],
                'buy_date'       => $firstBuyDate,       // t39: for grandfathering display
                'sell_date'      => format_date($sellDate),
                'quantity'       => $qty,
                'sell_price'     => (float)$t['price'],
                'proceeds'       => $proceeds,
                'cost'           => round($totalCost, 2),
                'charges'        => round($charges, 2),
                'gain'           => round($gain, 2),
                'adjusted_gain'  => $taxInfo['adjusted_gain'] ?? round($gain, 2), // t39
                'days_held'      => $days,
                'gain_type'      => $taxInfo['gain_type'],
                'tax_rate'       => $taxInfo['tax_rate'],
                'tax_amount'     => $taxInfo['tax_amount'],
                'grandfathered'  => $taxInfo['grandfathered'] ?? false, // t39
                'fy'             => $sellFy,
            ];
        }
    }
}

/* ─── 3. MF Dividends ────────────────────────────────────────────────────── */
$mfDivRows = DB::fetchAll(
    "SELECT d.div_date, d.total_amount, d.dividend_fy,
            f.scheme_name, fh.name AS fund_house
     FROM mf_dividends d
     JOIN funds f ON f.id = d.fund_id
     JOIN fund_houses fh ON fh.id = f.fund_house_id
     WHERE d.portfolio_id = ?
     ORDER BY d.div_date ASC",
    [$portfolioId]
);
$mfDividends = [];
foreach ($mfDivRows as $row) {
    $fy = $row['dividend_fy'] ?: get_fy($row['div_date']);
    if ($filterFy && $fy !== $filterFy) continue;
    $mfDividends[] = [
        'asset_class' => 'Mutual Fund',
        'name'        => $row['scheme_name'],
        'fund_house'  => $row['fund_house'],
        'date'        => format_date($row['div_date']),
        'amount'      => (float) $row['total_amount'],
        'fy'          => $fy,
    ];
}

/* ─── 4. Stock Dividends ─────────────────────────────────────────────────── */
$stDivRows = DB::fetchAll(
    "SELECT d.div_date, d.total_amount, d.dividend_fy,
            sm.symbol, sm.company_name
     FROM stock_dividends d
     JOIN stock_master sm ON sm.id = d.stock_id
     WHERE d.portfolio_id = ?
     ORDER BY d.div_date ASC",
    [$portfolioId]
);
$stockDividends = [];
foreach ($stDivRows as $row) {
    $fy = $row['dividend_fy'] ?: get_fy($row['div_date']);
    if ($filterFy && $fy !== $filterFy) continue;
    $stockDividends[] = [
        'asset_class' => 'Stock',
        'name'        => $row['company_name'],
        'symbol'      => $row['symbol'],
        'date'        => format_date($row['div_date']),
        'amount'      => (float) $row['total_amount'],
        'fy'          => $fy,
    ];
}

/* ─── 5. Aggregate by FY ─────────────────────────────────────────────────── */
$allGains = array_merge($mfGains, $stockGains);
$fyData   = [];

foreach ($allGains as $g) {
    $fy = $g['fy'];
    if (!isset($fyData[$fy])) {
        $fyData[$fy] = [
            'fy'               => $fy,
            'ltcg_equity'      => 0.0,
            'ltcg_debt'        => 0.0,
            'stcg_equity'      => 0.0,
            'stcg_debt'        => 0.0,
            'slab_gains'       => 0.0,
            'total_gains'      => 0.0,
            'mf_dividends'     => 0.0,
            'stock_dividends'  => 0.0,
            'total_tax_approx' => 0.0,
            'ltcg_exemption_remaining' => LTCG_EXEMPTION_LIMIT,
        ];
    }
    $gainAmt = (float) $g['gain'];
    $gt      = $g['gain_type'];
    $assetCl = $g['asset_class'];

    if ($gt === 'LTCG') {
        if ($assetCl === 'Stock' || strpos(strtolower($g['category'] ?? ''), 'debt') === false) {
            $fyData[$fy]['ltcg_equity'] += $gainAmt;
        } else {
            $fyData[$fy]['ltcg_debt'] += $gainAmt;
        }
    } elseif ($gt === 'STCG') {
        if ($assetCl === 'Stock' || strpos(strtolower($g['category'] ?? ''), 'debt') === false) {
            $fyData[$fy]['stcg_equity'] += $gainAmt;
        } else {
            $fyData[$fy]['stcg_debt'] += $gainAmt;
        }
    } elseif ($gt === 'SLAB') {
        $fyData[$fy]['slab_gains'] += $gainAmt;
    }
    $fyData[$fy]['total_gains'] += $gainAmt;
    $fyData[$fy]['total_tax_approx'] += (float)($g['tax_amount'] ?? 0);
}

foreach ($mfDividends as $d) {
    $fy = $d['fy'];
    if (!isset($fyData[$fy])) $fyData[$fy] = ['fy' => $fy, 'mf_dividends' => 0.0, 'stock_dividends' => 0.0, 'ltcg_equity' => 0.0, 'ltcg_debt' => 0.0, 'stcg_equity' => 0.0, 'stcg_debt' => 0.0, 'slab_gains' => 0.0, 'total_gains' => 0.0, 'total_tax_approx' => 0.0];
    $fyData[$fy]['mf_dividends'] += (float)$d['amount'];
}
foreach ($stockDividends as $d) {
    $fy = $d['fy'];
    if (!isset($fyData[$fy])) $fyData[$fy] = ['fy' => $fy, 'mf_dividends' => 0.0, 'stock_dividends' => 0.0, 'ltcg_equity' => 0.0, 'ltcg_debt' => 0.0, 'stcg_equity' => 0.0, 'stcg_debt' => 0.0, 'slab_gains' => 0.0, 'total_gains' => 0.0, 'total_tax_approx' => 0.0];
    $fyData[$fy]['stock_dividends'] += (float)$d['amount'];
}

foreach ($fyData as &$fy) {
    foreach (['ltcg_equity','ltcg_debt','stcg_equity','stcg_debt','slab_gains','total_gains','mf_dividends','stock_dividends','total_tax_approx'] as $k) {
        $fy[$k] = round($fy[$k] ?? 0.0, 2);
    }
    $fy['total_dividends'] = round(($fy['mf_dividends'] ?? 0) + ($fy['stock_dividends'] ?? 0), 2);
}
unset($fy);

krsort($fyData);

// ✅ FIX: Only show FYs where actual SELL transactions happened
$sellFySet = [];
foreach ($mfGains    as $g) { $sellFySet[$g['fy']] = true; }
foreach ($stockGains as $g) { $sellFySet[$g['fy']] = true; }
krsort($sellFySet);
$allFyList = array_keys($sellFySet);

// t39: Build stock-specific LTCG/STCG summary per FY
$stockSummary = [];
foreach ($stockGains as $g) {
    $fy = $g['fy'];
    if (!isset($stockSummary[$fy])) {
        $stockSummary[$fy] = [
            'fy'          => $fy,
            'ltcg_gain'   => 0, 'ltcg_tax'   => 0, 'ltcg_count' => 0,
            'stcg_gain'   => 0, 'stcg_tax'   => 0, 'stcg_count' => 0,
            'total_gain'  => 0, 'total_tax'  => 0,
            'grandfathered_count' => 0,
            'exchanges'   => [],
        ];
    }
    $gt = $g['gain_type'];
    $gain = (float)($g['adjusted_gain'] ?? $g['gain']);
    $tax  = (float)($g['tax_amount'] ?? 0);
    if ($gt === 'LTCG') {
        $stockSummary[$fy]['ltcg_gain']  += $gain;
        $stockSummary[$fy]['ltcg_tax']   += $tax;
        $stockSummary[$fy]['ltcg_count'] ++;
    } else {
        $stockSummary[$fy]['stcg_gain']  += $gain;
        $stockSummary[$fy]['stcg_tax']   += $tax;
        $stockSummary[$fy]['stcg_count'] ++;
    }
    $stockSummary[$fy]['total_gain'] += $gain;
    $stockSummary[$fy]['total_tax']  += $tax;
    if (!empty($g['grandfathered'])) $stockSummary[$fy]['grandfathered_count']++;
    if ($g['exchange'] && !in_array($g['exchange'], $stockSummary[$fy]['exchanges'])) {
        $stockSummary[$fy]['exchanges'][] = $g['exchange'];
    }
}
// Round all values
foreach ($stockSummary as &$s) {
    $s['ltcg_gain'] = round($s['ltcg_gain'], 2);
    $s['stcg_gain'] = round($s['stcg_gain'], 2);
    $s['total_gain']= round($s['total_gain'], 2);
    $s['ltcg_tax']  = round($s['ltcg_tax'],  2);
    $s['stcg_tax']  = round($s['stcg_tax'],  2);
    $s['total_tax'] = round($s['total_tax'],  2);
}
unset($s);

json_response(true, 'FY Gains report loaded.', [
    'fy_summary'         => array_values($fyData),
    'mf_gains_detail'    => $mfGains,
    'stock_gains_detail' => $stockGains,
    'stock_summary'      => array_values($stockSummary), // t39
    'mf_dividends'       => $mfDividends,
    'stock_dividends'    => $stockDividends,
    'fy_list'            => $allFyList,
    'filter_fy'          => $filterFy,
    'ltcg_exemption'     => LTCG_EXEMPTION_LIMIT,
]);