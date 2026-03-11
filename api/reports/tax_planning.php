<?php
/**
 * WealthDash — Tax Planning / Harvest Suggestions
 * Tells user WHAT to sell to stay within LTCG ₹1.25L exemption
 * POST /api/?action=report_tax_planning
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

require_once APP_ROOT . '/includes/tax_engine.php';

$portfolioId = (int)($_POST['portfolio_id'] ?? $_SESSION['selected_portfolio_id'] ?? 0);
$targetFy    = clean($_POST['fy'] ?? '');

if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
    json_response(false, 'Invalid or inaccessible portfolio.');
}

/* ─── Helper: current FY ────────────────────────────────────────────────── */
function current_fy(): string {
    $m = (int)date('n');
    $y = (int)date('Y');
    return $m >= 4 ? "{$y}-" . substr((string)($y+1),-2) : ($y-1) . '-' . substr((string)$y,-2);
}

$fy = $targetFy ?: current_fy();

/* ─── 1. How much LTCG already realised this FY? ─────────────────────────── */
// Get FY date range
[$fyStartY, $fyEndY2] = explode('-', $fy);
$fyStart = $fyStartY . '-04-01';
$fyEnd   = ('20' . $fyEndY2) . '-03-31';

// MF LTCG already realised
$mfLtcgRealised = 0.0;
$mfSells = DB::fetchAll(
    "SELECT t.txn_date, t.units AS sell_units, t.nav AS sell_nav,
            t.fund_id, t.folio_number, f.category
     FROM mf_transactions t
     JOIN funds f ON f.id = t.fund_id
     WHERE t.portfolio_id = ?
       AND t.transaction_type IN ('SELL','SWITCH_OUT','SWP')
       AND t.txn_date BETWEEN ? AND ?",
    [$portfolioId, $fyStart, $fyEnd]
);

foreach ($mfSells as $sell) {
    $buys = DB::fetchAll(
        "SELECT txn_date, units, nav FROM mf_transactions
         WHERE portfolio_id = ? AND fund_id = ?
           AND (folio_number = ? OR folio_number IS NULL)
           AND transaction_type IN ('BUY','SWITCH_IN','STP_IN','DIV_REINVEST')
           AND txn_date <= ?
         ORDER BY txn_date ASC",
        [$portfolioId, $sell['fund_id'], $sell['folio_number'], $sell['txn_date']]
    );
    $remain = (float)$sell['sell_units'];
    $cost = 0.0;
    foreach ($buys as $b) {
        if ($remain <= 0) break;
        $used = min((float)$b['units'], $remain);
        $cost += $used * (float)$b['nav'];
        $remain -= $used;
    }
    $gain = ((float)$sell['sell_units'] * (float)$sell['sell_nav']) - $cost;
    if ($gain <= 0) continue;
    $firstBuy = $buys[0]['txn_date'] ?? $sell['txn_date'];
    $days = (int)(new DateTime($sell['txn_date']))->diff(new DateTime($firstBuy))->days;
    $cat = strtolower($sell['category'] ?? '');
    $assetType = (strpos($cat,'debt') !== false) ? 'debt' : 'equity';
    $ltcgDays = ($assetType === 'debt') ? DEBT_LTCG_DAYS : EQUITY_LTCG_DAYS;
    if ($days >= $ltcgDays) $mfLtcgRealised += $gain;
}

// Stock LTCG already realised
$stockLtcgRealised = 0.0;
$stSells = DB::fetchAll(
    "SELECT t.txn_date, t.quantity AS sell_qty, t.price AS sell_price,
            t.brokerage, t.stt, t.exchange_charges, t.stock_id
     FROM stock_transactions t
     WHERE t.portfolio_id = ? AND t.txn_type = 'SELL'
       AND t.txn_date BETWEEN ? AND ?",
    [$portfolioId, $fyStart, $fyEnd]
);
foreach ($stSells as $sell) {
    $buys = DB::fetchAll(
        "SELECT txn_date, quantity, price FROM stock_transactions
         WHERE portfolio_id = ? AND stock_id = ?
           AND txn_type IN ('BUY','BONUS','SPLIT') AND txn_date <= ?
         ORDER BY txn_date ASC",
        [$portfolioId, $sell['stock_id'], $sell['txn_date']]
    );
    $remain = (float)$sell['sell_qty'];
    $cost = 0.0;
    foreach ($buys as $b) {
        if ($remain <= 0) break;
        $used = min((float)$b['quantity'], $remain);
        $cost += $used * (float)$b['price'];
        $remain -= $used;
    }
    $charges = (float)$sell['brokerage'] + (float)$sell['stt'] + (float)$sell['exchange_charges'];
    $gain = ((float)$sell['sell_qty'] * (float)$sell['sell_price']) - $cost - $charges;
    if ($gain <= 0) continue;
    $firstBuy = $buys[0]['txn_date'] ?? $sell['txn_date'];
    $days = (int)(new DateTime($sell['txn_date']))->diff(new DateTime($firstBuy))->days;
    if ($days >= EQUITY_LTCG_DAYS) $stockLtcgRealised += $gain;
}

$totalLtcgRealised = $mfLtcgRealised + $stockLtcgRealised;
$exemptionRemaining = max(0.0, LTCG_EXEMPTION_LIMIT - $totalLtcgRealised);
$exemptionUsed = min($totalLtcgRealised, LTCG_EXEMPTION_LIMIT);

/* ─── 2. Current holdings eligible for LTCG harvest ─────────────────────── */
$today = date('Y-m-d');

// MF holdings eligible for LTCG
$mfHoldings = DB::fetchAll(
    "SELECT h.id, h.fund_id, h.folio_number, h.total_units, h.avg_cost_nav,
            h.total_invested, h.value_now, h.gain_loss, h.first_purchase_date,
            h.ltcg_date, h.gain_type, h.cagr,
            f.scheme_name, f.category, f.sub_category, f.latest_nav,
            fh.name AS fund_house
     FROM mf_holdings h
     JOIN funds f ON f.id = h.fund_id
     JOIN fund_houses fh ON fh.id = f.fund_house_id
     WHERE h.portfolio_id = ? AND h.is_active = 1 AND h.gain_loss > 0
     ORDER BY h.gain_loss DESC",
    [$portfolioId]
);

$harvestSuggestions = [];
foreach ($mfHoldings as $h) {
    if (!$h['ltcg_date']) continue;
    if ($h['ltcg_date'] > $today) continue; // not yet LTCG eligible
    $ltcgGain = (float)$h['gain_loss'];
    if ($ltcgGain <= 0) continue;

    // How many units to sell to use remaining exemption?
    $gainPerUnit = (float)$h['latest_nav'] - (float)$h['avg_cost_nav'];
    if ($gainPerUnit <= 0) continue;
    $unitsToSell  = $exemptionRemaining > 0 ? min((float)$h['total_units'], $exemptionRemaining / $gainPerUnit) : 0;
    $gainIfSell   = round($unitsToSell * $gainPerUnit, 2);
    $valueIfSell  = round($unitsToSell * (float)$h['latest_nav'], 2);

    $harvestSuggestions[] = [
        'type'            => 'MF',
        'name'            => $h['scheme_name'],
        'fund_house'      => $h['fund_house'],
        'category'        => $h['category'],
        'folio'           => $h['folio_number'],
        'total_units'     => round((float)$h['total_units'], 4),
        'avg_cost_nav'    => round((float)$h['avg_cost_nav'], 4),
        'latest_nav'      => round((float)$h['latest_nav'], 4),
        'total_gain'      => round($ltcgGain, 2),
        'ltcg_date'       => format_date($h['ltcg_date']),
        'days_since_ltcg' => (int)(new DateTime($today))->diff(new DateTime($h['ltcg_date']))->days,
        'units_to_sell'   => round($unitsToSell, 4),
        'gain_if_sell'    => $gainIfSell,
        'value_if_sell'   => $valueIfSell,
        'cagr'            => round((float)$h['cagr'], 2),
        'priority'        => $ltcgGain >= $exemptionRemaining ? 'partial' : 'full',
    ];
}

// Stock holdings eligible for LTCG
$stockHoldings = DB::fetchAll(
    "SELECT h.id, h.stock_id, h.quantity, h.avg_buy_price, h.total_invested,
            h.current_value, h.gain_loss, h.first_buy_date, h.ltcg_date, h.cagr,
            sm.symbol, sm.company_name, sm.exchange, sm.latest_price
     FROM stock_holdings h
     JOIN stock_master sm ON sm.id = h.stock_id
     WHERE h.portfolio_id = ? AND h.is_active = 1 AND h.gain_loss > 0
     ORDER BY h.gain_loss DESC",
    [$portfolioId]
);

foreach ($stockHoldings as $h) {
    if (!$h['ltcg_date']) continue;
    if ($h['ltcg_date'] > $today) continue;
    $ltcgGain = (float)$h['gain_loss'];
    if ($ltcgGain <= 0) continue;

    $gainPerShare = (float)$h['latest_price'] - (float)$h['avg_buy_price'];
    if ($gainPerShare <= 0) continue;
    $sharesToSell = $exemptionRemaining > 0 ? min((float)$h['quantity'], $exemptionRemaining / $gainPerShare) : 0;
    $sharesToSell = floor($sharesToSell); // whole shares only
    $gainIfSell   = round($sharesToSell * $gainPerShare, 2);
    $valueIfSell  = round($sharesToSell * (float)$h['latest_price'], 2);

    $harvestSuggestions[] = [
        'type'            => 'Stock',
        'name'            => $h['company_name'],
        'symbol'          => $h['symbol'],
        'exchange'        => $h['exchange'],
        'quantity'        => (float)$h['quantity'],
        'avg_buy_price'   => round((float)$h['avg_buy_price'], 2),
        'latest_price'    => round((float)$h['latest_price'], 2),
        'total_gain'      => round($ltcgGain, 2),
        'ltcg_date'       => format_date($h['ltcg_date']),
        'days_since_ltcg' => (int)(new DateTime($today))->diff(new DateTime($h['ltcg_date']))->days,
        'units_to_sell'   => $sharesToSell,
        'gain_if_sell'    => $gainIfSell,
        'value_if_sell'   => $valueIfSell,
        'cagr'            => round((float)$h['cagr'], 2),
        'priority'        => $ltcgGain >= $exemptionRemaining ? 'partial' : 'full',
    ];
}

// Sort by gain descending (best harvest candidates first)
usort($harvestSuggestions, fn($a,$b) => $b['total_gain'] <=> $a['total_gain']);

/* ─── 3. STCG summary ────────────────────────────────────────────────────── */
$stcgHoldings = [];
foreach ($mfHoldings as $h) {
    if ($h['gain_type'] !== 'STCG' && $h['ltcg_date'] > $today) {
        // Holdings not yet LTCG eligible — warn about STCG tax if sold now
        $daysToLtcg = $h['ltcg_date'] ? (int)(new DateTime($h['ltcg_date']))->diff(new DateTime($today))->days : null;
        if ($daysToLtcg && $daysToLtcg <= 90) {
            $stcgHoldings[] = [
                'type'         => 'MF',
                'name'         => $h['scheme_name'],
                'category'     => $h['category'],
                'gain'         => round((float)$h['gain_loss'], 2),
                'ltcg_date'    => format_date($h['ltcg_date']),
                'days_to_ltcg' => $daysToLtcg,
                'message'      => "Wait {$daysToLtcg} more days to qualify for LTCG (save approx ₹" . number_format((float)$h['gain_loss'] * 0.05, 0) . " in tax)",
            ];
        }
    }
}

/* ─── 4. FD TDS summary for FY ───────────────────────────────────────────── */
$fdTds = DB::fetchAll(
    "SELECT SUM(interest_amount) AS total_interest, SUM(tds_amount) AS total_tds
     FROM fd_interest_accruals
     WHERE portfolio_id = ? AND accrual_fy = ?",
    [$portfolioId, $fy]
);
$fdInterest = round((float)($fdTds[0]['total_interest'] ?? 0), 2);
$fdTdsAmt   = round((float)($fdTds[0]['total_tds'] ?? 0), 2);

/* ─── 5. Savings 80TTA ───────────────────────────────────────────────────── */
$savings80tta = DB::fetchVal(
    "SELECT COALESCE(SUM(interest_amount),0) FROM savings_interest_log
     WHERE portfolio_id = ? AND interest_fy = ?",
    [$portfolioId, $fy]
);
$savingsInterest = round((float)$savings80tta, 2);
$tta80Exemption  = min($savingsInterest, SAVINGS_80TTA_LIMIT);

json_response(true, 'Tax planning data loaded.', [
    'fy'                   => $fy,
    'ltcg_exemption_limit' => LTCG_EXEMPTION_LIMIT,
    'ltcg_realised'        => round($totalLtcgRealised, 2),
    'ltcg_exemption_used'  => round($exemptionUsed, 2),
    'ltcg_exemption_remaining' => round($exemptionRemaining, 2),
    'harvest_suggestions'  => $harvestSuggestions,
    'wait_for_ltcg'        => $stcgHoldings,
    'fd_interest_fy'       => $fdInterest,
    'fd_tds_fy'            => $fdTdsAmt,
    'savings_interest_fy'  => $savingsInterest,
    'savings_80tta_benefit'=> round($tta80Exemption, 2),
    'savings_80tta_limit'  => SAVINGS_80TTA_LIMIT,
]);

