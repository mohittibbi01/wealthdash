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

// t133: Lot selection method — FIFO (default) | HIFO | LIFO
// User can pass lot_method in POST, or we read from user settings
$lotMethod = strtoupper(clean($_POST['lot_method'] ?? 'FIFO'));
if (!in_array($lotMethod, ['FIFO','HIFO','LIFO'])) $lotMethod = 'FIFO';

// t132: Grandfathering — Jan 31 2018 NAV as cost for pre-2018 equity MF lots
// Applies to equity MFs purchased before Jan 31 2018
// Cost = max(actual_purchase_nav, jan31_2018_nav)
const GRANDFATHERING_DATE = '2018-01-31';
$applyGrandfathering = (bool)($_POST['apply_grandfathering'] ?? true);

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

    // t132+t133+t135: Lot queue — supports FIFO/HIFO/LIFO + Grandfathering
    $queue = []; // ['units'=>float,'nav'=>float,'date'=>string,'gf_nav'=>float|null]

    foreach ($allTxns as $t) {
        $type  = $t['transaction_type'];
        $units = (float)$t['units'];
        $nav   = (float)$t['nav'];
        $date  = $t['txn_date'];

        if (in_array($type, ['BUY','DIV_REINVEST','SWITCH_IN','STP_IN'])) {
            // t132: Grandfathering for equity bought before Jan 31 2018
            $gfNav = null;
            $cat   = strtolower($t['category'] ?? '');
            $isEq  = !(strpos($cat,'debt')!==false||strpos($cat,'liquid')!==false||
                       strpos($cat,'money market')!==false||strpos($cat,'credit')!==false||strpos($cat,'gilt')!==false);
            if ($applyGrandfathering && $isEq && $date <= GRANDFATHERING_DATE) {
                $gfRow = DB::fetchOne(
                    "SELECT nav FROM nav_history WHERE fund_id=? AND nav_date<=? AND nav>0 ORDER BY nav_date DESC LIMIT 1",
                    [$fundId, GRANDFATHERING_DATE]
                );
                if ($gfRow && (float)$gfRow['nav'] > $nav) $gfNav = (float)$gfRow['nav'];
            }
            $queue[] = ['units'=>$units,'nav'=>$nav,'date'=>$date,'gf_nav'=>$gfNav];

        } elseif (in_array($type, ['SELL','SWITCH_OUT','SWP'])) {
            $sellFy = get_fy($date);
            if ($filterFy && $sellFy !== $filterFy) {
                // Consume FIFO for state even when filtered out
                $rem2 = $units;
                while ($rem2>0.0001 && !empty($queue)) {
                    if ($queue[0]['units']<=$rem2){$rem2-=$queue[0]['units'];array_shift($queue);}
                    else{$queue[0]['units']-=$rem2;$rem2=0;}
                }
                continue;
            }

            // t133: Apply lot method for this sell
            $workQ = $queue;
            if ($lotMethod==='HIFO') {
                usort($workQ, fn($a,$b)=>($b['gf_nav']??$b['nav'])<=>($a['gf_nav']??$a['nav']));
            } elseif ($lotMethod==='LIFO') {
                $workQ = array_reverse($workQ);
            }

            $remaining  = $units;
            $totalCost  = 0.0;
            $proceeds   = round($units * $nav, 2);
            $firstBuyDate = !empty($workQ) ? $workQ[0]['date'] : $date;
            $grandfatheredSavings = 0.0;

            while ($remaining > 0.0001 && !empty($workQ)) {
                $lot = &$workQ[0];
                $effNav = $lot['gf_nav'] ?? $lot['nav'];
                if ($lot['gf_nav']!==null) $grandfatheredSavings += min($lot['units'],$remaining)*($lot['gf_nav']-$lot['nav']);
                if ($lot['units']<=$remaining) {
                    $totalCost += $lot['units'] * $effNav;
                    $remaining -= $lot['units'];
                    array_shift($workQ);
                } else {
                    $totalCost    += $remaining * $effNav;
                    $lot['units'] -= $remaining;
                    $remaining     = 0;
                }
                unset($lot);
            }

            // Sync canonical FIFO queue (consume units regardless of lot method)
            $consumed = $units;
            $newQ = [];
            foreach ($queue as $ql) {
                if ($consumed<=0.0001){$newQ[]=$ql;continue;}
                if ($ql['units']<=$consumed){$consumed-=$ql['units'];}
                else{$newQ[]=array_merge($ql,['units'=>$ql['units']-$consumed]);$consumed=0;}
            }
            $queue = $newQ;

            $gain     = round($proceeds - $totalCost, 2);
            $days     = (int)(new DateTime($date))->diff(new DateTime($firstBuyDate))->days;

            $cat      = strtolower($t['category'] ?? '');
            $assetType = (strpos($cat,'debt')!==false||strpos($cat,'liquid')!==false||
                          strpos($cat,'money market')!==false||strpos($cat,'credit')!==false)
                         ? 'debt' : (strpos($cat,'elss')!==false?'elss':'equity');

            $fundMinLtcgDays = (int)($t['min_ltcg_days'] ?? 0);
            $taxInfo = TaxEngine::mf_gain_tax($gain, $firstBuyDate, $date, $assetType, $fundMinLtcgDays);

            $mfGains[] = [
                'asset_class'          => 'Mutual Fund',
                'name'                 => $t['scheme_name'],
                'fund_house'           => $t['fund_house'],
                'category'             => $t['category'],
                'folio'                => $combo['folio_number'],
                'sell_date'            => format_date($date),
                'units'                => $units,
                'sell_nav'             => $nav,
                'proceeds'             => $proceeds,
                'cost'                 => round($totalCost, 2),
                'gain'                 => $gain,
                'days_held'            => $days,
                'gain_type'            => $taxInfo['gain_type'],
                'tax_rate'             => $taxInfo['tax_rate'],
                'tax_amount'           => $taxInfo['tax_amount'],
                'fy'                   => $sellFy,
                'grandfathered_savings'=> round($grandfatheredSavings, 2), // t132
                'lot_method'           => $lotMethod,                      // t133
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
            $taxInfo = TaxEngine::stock_gain_tax($gain, $firstBuyDate, $sellDate);

            $stockGains[] = [
                'asset_class' => 'Stock',
                'name'        => $t['company_name'],
                'symbol'      => $t['symbol'],
                'exchange'    => $t['exchange'],
                'sell_date'   => format_date($sellDate),
                'quantity'    => $qty,
                'sell_price'  => (float)$t['price'],
                'proceeds'    => $proceeds,
                'cost'        => round($totalCost, 2),
                'charges'     => round($charges, 2),
                'gain'        => round($gain, 2),
                'days_held'   => $days,
                'gain_type'   => $taxInfo['gain_type'],
                'tax_rate'    => $taxInfo['tax_rate'],
                'tax_amount'  => $taxInfo['tax_amount'],
                'fy'          => $sellFy,
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

/* ─── t135: Loss Set-off & Carry Forward ─────────────────────────────────── */
// Build FY-wise loss/gain summary for carry-forward analysis
// Rules: STCG loss can be set off against STCG or LTCG gains
//        LTCG loss can only be set off against LTCG gains
//        Unabsorbed losses carry forward 8 assessment years
$lossCF = [];
$allGains = array_merge($mfGains, $stockGains);

// Group net gains/losses by FY and type
$fyNetMap = []; // fy => ['stcg'=>float, 'ltcg'=>float]
foreach ($allGains as $g) {
    $fy = $g['fy'];
    if (!isset($fyNetMap[$fy])) $fyNetMap[$fy] = ['stcg'=>0.0,'ltcg'=>0.0,'total'=>0.0];
    $gt = strtolower($g['gain_type']??'');
    $gn = (float)$g['gain'];
    if (strpos($gt,'stcg')!==false)      $fyNetMap[$fy]['stcg'] += $gn;
    elseif (strpos($gt,'ltcg')!==false)  $fyNetMap[$fy]['ltcg'] += $gn;
    $fyNetMap[$fy]['total'] += $gn;
}

// Identify loss FYs and compute carry-forward
foreach ($fyNetMap as $fy => $nets) {
    $fyParts = explode('-', $fy);
    if (count($fyParts) === 2) {
        $expiryYear = (int)('20'.$fyParts[1]) + 8;
        $expiry = ($expiryYear-1).'-'.substr((string)$expiryYear,-2);
    } else { $expiry = 'Unknown'; }

    if ($nets['stcg'] < 0) {
        $lossCF[] = [
            'fy'          => $fy,
            'type'        => 'STCG',
            'loss_amount' => round(abs($nets['stcg']), 2),
            'remaining'   => round(abs($nets['stcg']), 2),
            'expiry_fy'   => $expiry,
            'can_set_off' => 'STCG or LTCG gains',
        ];
    }
    if ($nets['ltcg'] < 0) {
        $lossCF[] = [
            'fy'          => $fy,
            'type'        => 'LTCG',
            'loss_amount' => round(abs($nets['ltcg']), 2),
            'remaining'   => round(abs($nets['ltcg']), 2),
            'expiry_fy'   => $expiry,
            'can_set_off' => 'LTCG gains only',
        ];
    }
}

// Total grandfathering tax savings this report
$totalGfSavings = array_sum(array_column($mfGains, 'grandfathered_savings'));

json_response(true, 'FY Gains report loaded.', [
    'fy_summary'              => array_values($fyData),
    'mf_gains_detail'         => $mfGains,
    'stock_gains_detail'      => $stockGains,
    'mf_dividends'            => $mfDividends,
    'stock_dividends'         => $stockDividends,
    'fy_list'                 => $allFyList,
    'loss_carry_forward'      => $lossCF,              // t135
    'lot_method'              => $lotMethod,            // t133
    'grandfathering_applied'  => $applyGrandfathering, // t132
    'total_gf_tax_savings'    => round($totalGfSavings, 2), // t132
    'filter_fy'          => $filterFy,
    'ltcg_exemption'     => LTCG_EXEMPTION_LIMIT,
]);