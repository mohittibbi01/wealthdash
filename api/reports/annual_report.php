<?php
/**
 * WealthDash — t376: Annual Report FY-wise PDF
 * Action: annual_report_data
 *
 * Returns all data needed to render a comprehensive FY annual report:
 *   - MF activity (invested, redeemed, gains, SIP count)
 *   - Net worth snapshot (all asset classes)
 *   - FD interest earned
 *   - Capital gains summary (LTCG/STCG)
 *   - Top performing / worst performing funds
 *   - SIP summary
 *   - Tax summary
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();

$action = $_POST['action'] ?? $_GET['action'] ?? 'annual_report_data';

// ── Helpers ─────────────────────────────────────────────────────────────────
function arFyDates(string $fy): array {
    [$y1, $y2raw] = explode('-', $fy);
    $y2 = strlen($y2raw) === 2 ? '20' . $y2raw : $y2raw;
    return ['from' => "{$y1}-04-01", 'to' => "{$y2}-03-31", 'y1' => $y1, 'y2' => $y2];
}

function arCurrentFy(): string {
    $m = (int)date('n'); $y = (int)date('Y');
    return $m >= 4 ? "{$y}-" . substr((string)($y + 1), 2) : ($y - 1) . "-" . substr((string)$y, 2);
}

function arGetPortfolio(PDO $db, int $userId): int {
    $s = $db->prepare("SELECT id FROM portfolios WHERE user_id=? AND is_default=1 LIMIT 1");
    $s->execute([$userId]);
    $pid = (int)$s->fetchColumn();
    if (!$pid) {
        $s2 = $db->prepare("SELECT id FROM portfolios WHERE user_id=? LIMIT 1");
        $s2->execute([$userId]);
        $pid = (int)$s2->fetchColumn();
    }
    return $pid;
}

function arFetchOne(PDO $db, string $sql, array $params = []): array {
    $s = $db->prepare($sql);
    $s->execute($params);
    return $s->fetch(PDO::FETCH_ASSOC) ?: [];
}

function arFetchAll(PDO $db, string $sql, array $params = []): array {
    $s = $db->prepare($sql);
    $s->execute($params);
    return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

// ── Main: annual_report_data ─────────────────────────────────────────────────
if ($action !== 'annual_report_data') {
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    return;
}

$fy  = trim($_POST['fy'] ?? $_GET['fy'] ?? arCurrentFy());
$pid = arGetPortfolio($db, $userId);

if (!$pid) {
    echo json_encode(['success' => false, 'message' => 'No portfolio found']);
    return;
}

$d = arFyDates($fy);
$from = $d['from'];
$to   = $d['to'];

// ── 1. MF ACTIVITY ──────────────────────────────────────────────────────────
$mfActivity = arFetchOne($db, "
    SELECT
        COALESCE(SUM(CASE WHEN t.tx_type IN ('buy','sip','lumpsum','switch_in') THEN t.amount ELSE 0 END), 0) AS invested,
        COALESCE(SUM(CASE WHEN t.tx_type IN ('sell','swp','switch_out','redemption') THEN t.amount ELSE 0 END), 0) AS redeemed,
        COALESCE(COUNT(CASE WHEN t.tx_type = 'sip' THEN 1 END), 0) AS sip_count,
        COALESCE(COUNT(CASE WHEN t.tx_type IN ('buy','lumpsum') THEN 1 END), 0) AS lumpsum_count,
        COALESCE(COUNT(CASE WHEN t.tx_type IN ('sell','swp','redemption') THEN 1 END), 0) AS redemption_count,
        COALESCE(COUNT(DISTINCT mh.fund_id), 0) AS funds_traded
    FROM mf_transactions t
    JOIN mf_holdings mh ON mh.id = t.holding_id
    WHERE mh.portfolio_id = ? AND t.tx_date BETWEEN ? AND ?
", [$pid, $from, $to]);

// SIP breakdown by month
$sipByMonth = arFetchAll($db, "
    SELECT DATE_FORMAT(t.tx_date,'%b %Y') AS month_label,
           DATE_FORMAT(t.tx_date,'%Y-%m') AS month_key,
           COALESCE(SUM(t.amount),0) AS amount,
           COUNT(*) AS count
    FROM mf_transactions t
    JOIN mf_holdings mh ON mh.id = t.holding_id
    WHERE mh.portfolio_id = ? AND t.tx_type = 'sip'
      AND t.tx_date BETWEEN ? AND ?
    GROUP BY month_key ORDER BY month_key ASC
", [$pid, $from, $to]);

// ── 2. CAPITAL GAINS (LTCG / STCG) ─────────────────────────────────────────
// Simplified FIFO-based gains calculation
$sellRows = arFetchAll($db, "
    SELECT t.id, t.tx_date, t.units AS sold_units, t.price_per_unit AS sell_nav,
           t.amount AS sell_amount, t.holding_id,
           f.fund_name, f.category
    FROM mf_transactions t
    JOIN mf_holdings mh ON mh.id = t.holding_id
    JOIN funds f ON f.id = mh.fund_id
    WHERE mh.portfolio_id = ? AND t.tx_type IN ('sell','swp','redemption','switch_out')
      AND t.tx_date BETWEEN ? AND ?
    ORDER BY t.tx_date ASC
", [$pid, $from, $to]);

$ltcgEquity = $stcgEquity = $ltcgDebt = $stcgDebt = $totalTax = 0.0;
$gainRows = [];

foreach ($sellRows as $sell) {
    $buyRows = arFetchAll($db, "
        SELECT tx_date, units AS bought_units, price_per_unit AS buy_nav
        FROM mf_transactions
        WHERE holding_id = ? AND tx_type IN ('buy','sip','lumpsum','switch_in')
          AND tx_date <= ?
        ORDER BY tx_date ASC
    ", [$sell['holding_id'], $sell['tx_date']]);

    $unitsLeft = (float)$sell['sold_units'];
    $cost      = 0.0;
    $wDays     = 0.0;

    foreach ($buyRows as $b) {
        if ($unitsLeft <= 0) break;
        $used   = min((float)$b['bought_units'], $unitsLeft);
        $cost  += $used * (float)$b['buy_nav'];
        $wDays += $used * max(0, (strtotime($sell['tx_date']) - strtotime($b['tx_date'])) / 86400);
        $unitsLeft -= $used;
    }

    $avgDays  = (float)$sell['sold_units'] > 0 ? $wDays / (float)$sell['sold_units'] : 0;
    $gain     = (float)$sell['sell_amount'] - $cost;
    $isLtcg   = $avgDays >= 365;
    $cat      = strtolower($sell['category'] ?? '');
    $isEquity = str_contains($cat, 'equity') || str_contains($cat, 'elss') || str_contains($cat, 'index') || str_contains($cat, 'hybrid');

    if ($isEquity) {
        if ($isLtcg) { $ltcgEquity += $gain; }
        else          { $stcgEquity += $gain; }
    } else {
        if ($isLtcg) { $ltcgDebt += $gain; }
        else          { $stcgDebt += $gain; }
    }

    // Tax estimate
    $taxEst = 0.0;
    if ($isEquity && $isLtcg) {
        $taxable = max(0, $gain - 125000); // 1.25L exemption (shared — simplified)
        $taxEst  = $taxable * 0.125;
    } elseif ($isEquity && !$isLtcg) {
        $taxEst = max(0, $gain) * 0.20;
    } elseif (!$isEquity && $isLtcg) {
        $taxEst = max(0, $gain) * 0.125; // post-2024 budget
    }
    $totalTax += $taxEst;

    $gainRows[] = [
        'fund'      => substr($sell['fund_name'] ?? '', 0, 45),
        'date'      => $sell['tx_date'],
        'gain'      => round($gain, 2),
        'type'      => ($isEquity ? '' : 'Debt ') . ($isLtcg ? 'LTCG' : 'STCG'),
        'hold_days' => (int)round($avgDays),
        'tax_est'   => round($taxEst, 2),
    ];
}

$capGains = [
    'ltcg_equity'  => round($ltcgEquity, 2),
    'stcg_equity'  => round($stcgEquity, 2),
    'ltcg_debt'    => round($ltcgDebt, 2),
    'stcg_debt'    => round($stcgDebt, 2),
    'total_gains'  => round($ltcgEquity + $stcgEquity + $ltcgDebt + $stcgDebt, 2),
    'tax_estimate' => round($totalTax, 2),
    'detail'       => array_slice($gainRows, 0, 30),
];

// ── 3. CURRENT HOLDINGS SNAPSHOT ────────────────────────────────────────────
$holdingsSnap = arFetchAll($db, "
    SELECT f.fund_name, f.category, mh.total_invested, mh.value_now,
           mh.gain_loss,
           CASE WHEN mh.total_invested > 0 THEN (mh.gain_loss / mh.total_invested)*100 ELSE 0 END AS gain_pct,
           mh.total_units, mh.latest_nav
    FROM mf_holdings mh
    JOIN funds f ON f.id = mh.fund_id
    WHERE mh.portfolio_id = ? AND mh.is_active = 1
    ORDER BY mh.value_now DESC
", [$pid]);

$holdingsTotals = [
    'invested'    => round(array_sum(array_column($holdingsSnap, 'total_invested')), 2),
    'value_now'   => round(array_sum(array_column($holdingsSnap, 'value_now')), 2),
    'gain_loss'   => round(array_sum(array_column($holdingsSnap, 'gain_loss')), 2),
    'fund_count'  => count($holdingsSnap),
];
$holdingsTotals['gain_pct'] = $holdingsTotals['invested'] > 0
    ? round(($holdingsTotals['gain_loss'] / $holdingsTotals['invested']) * 100, 2)
    : 0;

// Top 5 / Bottom 3 performers
usort($holdingsSnap, fn($a,$b) => (float)$b['gain_pct'] <=> (float)$a['gain_pct']);
$topFunds    = array_slice($holdingsSnap, 0, 5);
$bottomFunds = array_slice($holdingsSnap, -3);

// Category allocation
$catAlloc = [];
foreach ($holdingsSnap as $h) {
    $cat = $h['category'] ?: 'Other';
    if (!isset($catAlloc[$cat])) $catAlloc[$cat] = 0;
    $catAlloc[$cat] += (float)$h['value_now'];
}
arsort($catAlloc);

// ── 4. FD INTEREST EARNED THIS FY ───────────────────────────────────────────
$fdInterest = arFetchOne($db, "
    SELECT
        COALESCE(SUM(amount),0) AS total_invested,
        COALESCE(COUNT(*),0) AS fd_count,
        COALESCE(SUM(CASE WHEN maturity_date BETWEEN ? AND ? THEN maturity_amount - amount ELSE 0 END),0) AS interest_earned,
        COALESCE(SUM(CASE WHEN maturity_date BETWEEN ? AND ? THEN 1 ELSE 0 END),0) AS matured_count
    FROM fd_investments
    WHERE user_id = ?
", [$from, $to, $from, $to, $userId]);

// ── 5. OTHER ASSETS SNAPSHOT ────────────────────────────────────────────────
$npsSnap = arFetchOne($db, "
    SELECT COALESCE(SUM(total_invested),0) AS invested,
           COALESCE(SUM(latest_value),0) AS value_now,
           COALESCE(SUM(gain_loss),0) AS gain_loss,
           COUNT(*) AS count
    FROM nps_holdings WHERE portfolio_id = ?
", [$pid]);

$stocksSnap = arFetchOne($db, "
    SELECT COALESCE(SUM(total_invested),0) AS invested,
           COALESCE(SUM(current_value),0) AS value_now,
           COALESCE(SUM(current_value - total_invested),0) AS gain_loss,
           COUNT(*) AS count
    FROM stock_holdings WHERE portfolio_id = ?
", [$pid]);

$savingsSnap = arFetchOne($db, "
    SELECT COALESCE(SUM(balance),0) AS balance, COUNT(*) AS count
    FROM savings_accounts WHERE user_id = ?
", [$userId]);

$fdSnap = arFetchOne($db, "
    SELECT COALESCE(SUM(amount),0) AS invested,
           COALESCE(SUM(maturity_amount),0) AS maturity_value,
           COUNT(*) AS count
    FROM fd_investments WHERE user_id = ? AND status = 'active'
", [$userId]);

// Net worth = all assets
$netWorth = round(
    (float)($holdingsTotals['value_now'])
  + (float)($npsSnap['value_now'] ?? 0)
  + (float)($stocksSnap['value_now'] ?? 0)
  + (float)($fdSnap['invested'] ?? 0)
  + (float)($savingsSnap['balance'] ?? 0),
    2
);

// ── 6. SIP ACTIVE SUMMARY ────────────────────────────────────────────────────
$activeSips = arFetchAll($db, "
    SELECT s.amount, s.frequency, s.start_date,
           f.fund_name, f.category
    FROM sip_swp s
    JOIN mf_holdings mh ON mh.id = s.holding_id
    JOIN funds f ON f.id = mh.fund_id
    WHERE mh.portfolio_id = ? AND s.status = 'active' AND s.type = 'SIP'
    ORDER BY s.amount DESC LIMIT 10
", [$pid]);

$totalSipAmt = array_sum(array_column($activeSips, 'amount'));

// ── 7. ALL AVAILABLE FYs (for FY selector in front-end) ─────────────────────
$allFys = arFetchAll($db, "
    SELECT DISTINCT
        CASE WHEN MONTH(t.tx_date) >= 4
             THEN CONCAT(YEAR(t.tx_date),'-',LPAD(SUBSTR(YEAR(t.tx_date)+1,3,2),2,'0'))
             ELSE CONCAT(YEAR(t.tx_date)-1,'-',LPAD(SUBSTR(YEAR(t.tx_date),3,2),2,'0'))
        END AS fy
    FROM mf_transactions t
    JOIN mf_holdings mh ON mh.id = t.holding_id
    WHERE mh.portfolio_id = ?
    ORDER BY fy DESC
", [$pid]);

// ── RESPONSE ─────────────────────────────────────────────────────────────────
echo json_encode([
    'success'       => true,
    'fy'            => $fy,
    'fy_label'      => 'FY ' . $fy,
    'generated_at'  => date('d M Y, g:i A'),
    'user_name'     => $currentUser['name'] ?? 'Investor',
    'all_fys'       => array_column($allFys, 'fy'),
    'mf_activity'   => $mfActivity,
    'sip_by_month'  => $sipByMonth,
    'cap_gains'     => $capGains,
    'holdings'      => [
        'totals'    => $holdingsTotals,
        'top'       => array_slice($topFunds, 0, 5),
        'bottom'    => $bottomFunds,
        'cat_alloc' => $catAlloc,
    ],
    'fd'            => [
        'active'         => $fdSnap,
        'interest_this_fy' => $fdInterest,
    ],
    'nps'           => $npsSnap,
    'stocks'        => $stocksSnap,
    'savings'       => $savingsSnap,
    'net_worth'     => $netWorth,
    'active_sips'   => [
        'list'         => $activeSips,
        'total_monthly' => round((float)$totalSipAmt, 2),
        'count'        => count($activeSips),
    ],
]);
