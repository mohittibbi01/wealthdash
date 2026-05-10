<?php
/**
 * WealthDash — t443: Morning Briefing
 * Daily digest: portfolio pulse, today's SIPs, top movers, FD/goal alerts, market summary.
 * Cached per user for 4 hours (refreshes at ~9AM via cache TTL).
 *
 * GET  /api/router.php?action=morning_briefing
 * GET  /api/router.php?action=morning_briefing&refresh=1  — force refresh
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$forceRefresh = (int)($_GET['refresh'] ?? 0) === 1;

// ── Cache: 4 hours (14400s) — one fresh digest per morning ───────────────
$cacheKey = "morning_briefing:{$userId}:" . date('Y-m-d');
if (!$forceRefresh) {
    $cached = WdCache::get($cacheKey);
    if ($cached !== null) {
        json_response(true, '', array_merge($cached, ['_cached' => true]));
    }
}

$pids    = [];
$pidRows = DB::fetchAll("SELECT id FROM portfolios WHERE user_id = ?", [$userId]);
foreach ($pidRows as $r) $pids[] = (int)$r['id'];
$pidList = $pids ? implode(',', $pids) : '0';

$today   = date('Y-m-d');
$fyStart = date('m') >= 4 ? date('Y') . '-04-01' : (date('Y') - 1) . '-04-01';
$user    = DB::fetchOne("SELECT name, email FROM users WHERE id = ?", [$userId]);
$hour    = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

$brief = [
    'greeting'   => $greeting . ', ' . ($user['name'] ?: 'there') . '!',
    'date'       => date('l, j F Y'),
    'sections'   => [],
    '_cached'    => false,
];

if (empty($pids)) {
    $brief['sections'][] = [
        'id'    => 'empty',
        'title' => 'No portfolio yet',
        'icon'  => '📂',
        'items' => [['text' => 'Add your first investment to get a personalised morning briefing.', 'type' => 'neutral']],
    ];
    json_response(true, '', $brief);
}

// ── 1. PORTFOLIO PULSE (net worth + 1D change) ───────────────────────────
$pulse = DB::fetchOne(
    "SELECT
        COALESCE(SUM(mh.current_value), 0)                                    AS mf_val,
        COALESCE((SELECT SUM(sh.current_value) FROM stock_holdings sh
                  JOIN portfolios sp ON sp.id = sh.portfolio_id
                  WHERE sp.user_id = ?), 0)                                   AS stk_val,
        COALESCE((SELECT SUM(principal_amount) FROM fd_accounts fa
                  JOIN portfolios fp ON fp.id = fa.portfolio_id
                  WHERE fp.user_id = ? AND fa.status='active'), 0)            AS fd_val,
        COALESCE((SELECT SUM(units * current_nav) FROM mf_holdings mh2
                  JOIN portfolios p2 ON p2.id = mh2.portfolio_id
                  WHERE p2.user_id = ?
                    AND mh2.prev_nav > 0
                ), 0)                                                          AS mf_prev
     FROM mf_holdings mh
     JOIN portfolios p ON p.id = mh.portfolio_id
     WHERE p.user_id = ?",
    [$userId, $userId, $userId, $userId]
) ?: [];

$mfVal   = (float)($pulse['mf_val']  ?? 0);
$stkVal  = (float)($pulse['stk_val'] ?? 0);
$fdVal   = (float)($pulse['fd_val']  ?? 0);
$netWorth = $mfVal + $stkVal + $fdVal;
$mfPrev  = (float)($pulse['mf_prev'] ?? 0);
$mfDay   = $mfVal - $mfPrev;
$mfDayPct = $mfPrev > 0 ? round($mfDay / $mfPrev * 100, 2) : 0;

$pulseItems = [
    ['label' => 'Net Worth',   'value' => '₹' . _fmtAmt($netWorth),  'type' => 'stat'],
    ['label' => 'MF Value',    'value' => '₹' . _fmtAmt($mfVal),     'type' => 'stat'],
    ['label' => 'MF 1D Chg',  'value' => ($mfDay >= 0 ? '+' : '') . '₹' . _fmtAmt($mfDay) . ' (' . ($mfDayPct >= 0 ? '+' : '') . $mfDayPct . '%)',
     'type' => $mfDay >= 0 ? 'positive' : 'negative'],
    ['label' => 'Stocks',      'value' => '₹' . _fmtAmt($stkVal),    'type' => 'stat'],
    ['label' => 'FDs',         'value' => '₹' . _fmtAmt($fdVal),     'type' => 'stat'],
];
$brief['sections'][] = ['id' => 'pulse', 'title' => '📊 Portfolio Pulse', 'icon' => '📊', 'items' => $pulseItems];

// ── 2. TODAY'S SIPs ──────────────────────────────────────────────────────
$sipToday = DB::fetchAll(
    "SELECT s.amount, f.scheme_name
     FROM sip_registrations s
     JOIN mf_holdings h ON h.id = s.holding_id
     JOIN funds f ON f.id = h.fund_id
     WHERE h.portfolio_id IN ($pidList)
       AND s.status = 'active'
       AND s.next_debit_date = ?
     ORDER BY s.amount DESC LIMIT 5",
    [$today]
);
$sipThisWeek = DB::fetchAll(
    "SELECT s.amount, f.scheme_name, s.next_debit_date
     FROM sip_registrations s
     JOIN mf_holdings h ON h.id = s.holding_id
     JOIN funds f ON f.id = h.fund_id
     WHERE h.portfolio_id IN ($pidList)
       AND s.status = 'active'
       AND s.next_debit_date BETWEEN ? AND DATE_ADD(?, INTERVAL 7 DAY)
       AND s.next_debit_date != ?
     ORDER BY s.next_debit_date ASC LIMIT 5",
    [$today, $today, $today]
);
$sipItems = [];
foreach ($sipToday    as $s) $sipItems[] = ['text' => '🟢 TODAY — ₹' . number_format($s['amount'], 0, '.', ',') . ' · ' . mb_substr($s['scheme_name'], 0, 45), 'type' => 'highlight'];
foreach ($sipThisWeek as $s) $sipItems[] = ['text' => date('D jM', strtotime($s['next_debit_date'])) . ' — ₹' . number_format($s['amount'], 0, '.', ',') . ' · ' . mb_substr($s['scheme_name'], 0, 40), 'type' => 'neutral'];
if ($sipItems) {
    $brief['sections'][] = ['id' => 'sips', 'title' => '🔄 SIPs This Week', 'icon' => '🔄', 'items' => $sipItems];
}

// ── 3. TOP MOVERS (best & worst NAV change today) ────────────────────────
$movers = DB::fetchAll(
    "SELECT f.scheme_name,
            ROUND(((mh.current_nav - mh.prev_nav) / mh.prev_nav) * 100, 2) AS chg_pct,
            mh.current_value, mh.units
     FROM mf_holdings mh
     JOIN funds f ON f.id = mh.fund_id
     WHERE mh.portfolio_id IN ($pidList)
       AND mh.prev_nav > 0 AND mh.current_nav > 0
     ORDER BY chg_pct DESC
     LIMIT 10",
    []
);
$moverItems = [];
if (count($movers) >= 2) {
    $top  = $movers[0];
    $bot  = $movers[count($movers) - 1];
    $topPct = (float)$top['chg_pct'];
    $botPct = (float)$bot['chg_pct'];
    $moverItems[] = ['text' => '▲ Best: ' . mb_substr($top['scheme_name'], 0, 40) . ' (' . ($topPct >= 0 ? '+' : '') . $topPct . '%)', 'type' => 'positive'];
    $moverItems[] = ['text' => '▼ Worst: ' . mb_substr($bot['scheme_name'], 0, 40) . ' (' . ($botPct >= 0 ? '+' : '') . $botPct . '%)', 'type' => $botPct < -2 ? 'negative' : 'neutral'];
    // Notable drops
    foreach ($movers as $m) {
        if ((float)$m['chg_pct'] < -3 && mb_substr($m['scheme_name'], 0, 1) !== mb_substr($bot['scheme_name'], 0, 1)) {
            $moverItems[] = ['text' => '⚠️ Drop: ' . mb_substr($m['scheme_name'], 0, 40) . ' (' . $m['chg_pct'] . '%)', 'type' => 'warning'];
        }
    }
}
if ($moverItems) {
    $brief['sections'][] = ['id' => 'movers', 'title' => '📈 Top Movers Today', 'icon' => '📈', 'items' => $moverItems];
}

// ── 4. UPCOMING MATURITIES (next 30 days) ───────────────────────────────
$maturities = [];
$fds = DB::fetchAll(
    "SELECT 'FD' AS type, bank_name AS name, principal_amount AS amount, maturity_date,
            DATEDIFF(maturity_date, CURDATE()) AS days_left
     FROM fd_accounts WHERE portfolio_id IN ($pidList) AND status='active'
       AND maturity_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
     ORDER BY maturity_date LIMIT 3",
    []
);
$pos = DB::fetchAll(
    "SELECT scheme_type AS type, CONCAT('PO ', scheme_type) AS name,
            principal_amount AS amount, maturity_date,
            DATEDIFF(maturity_date, CURDATE()) AS days_left
     FROM po_schemes WHERE portfolio_id IN ($pidList) AND status='active'
       AND maturity_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
     ORDER BY maturity_date LIMIT 2",
    []
);
foreach (array_merge($fds, $pos) as $r) {
    $dl = (int)$r['days_left'];
    $maturities[] = ['text' => ($dl === 0 ? '🔴 TODAY' : ($dl <= 7 ? '🟠 ' . $dl . 'd' : '🟡 ' . $dl . 'd')) . ' — ' . $r['name'] . ' ₹' . _fmtAmt($r['amount']),
                     'type' => $dl <= 7 ? 'warning' : 'neutral'];
}
if ($maturities) {
    usort($maturities, fn($a, $b) => strcmp($a['text'], $b['text']));
    $brief['sections'][] = ['id' => 'maturities', 'title' => '⏰ Upcoming Maturities', 'icon' => '⏰', 'items' => $maturities];
}

// ── 5. GOAL PROGRESS ────────────────────────────────────────────────────
$goals = DB::fetchAll(
    "SELECT name, target_amount, current_amount, target_date,
            ROUND((current_amount / target_amount) * 100, 1) AS progress_pct
     FROM goals WHERE user_id = ? AND status='active' AND target_amount > 0
     ORDER BY progress_pct ASC LIMIT 4",
    [$userId]
);
$goalItems = [];
foreach ($goals as $g) {
    $pct  = (float)$g['progress_pct'];
    $bar  = str_repeat('█', (int)($pct / 10)) . str_repeat('░', 10 - (int)($pct / 10));
    $emoji = $pct >= 80 ? '🟢' : ($pct >= 50 ? '🟡' : '🔴');
    $goalItems[] = ['text' => $emoji . ' ' . $g['name'] . ' · ' . $pct . '% · ' . $bar, 'type' => 'neutral'];
}
if ($goalItems) {
    $brief['sections'][] = ['id' => 'goals', 'title' => '🎯 Goal Progress', 'icon' => '🎯', 'items' => $goalItems];
}

// ── 6. FY SNAPSHOT (LTCG / STCG so far) ─────────────────────────────────
$fyGains = DB::fetchOne(
    "SELECT
        COALESCE(SUM(CASE WHEN holding_period_days >= 365 AND txn_type='SELL'
                         THEN (sell_price - buy_price) * quantity ELSE 0 END), 0) AS ltcg,
        COALESCE(SUM(CASE WHEN holding_period_days < 365 AND txn_type='SELL'
                         THEN (sell_price - buy_price) * quantity ELSE 0 END), 0) AS stcg,
        COUNT(CASE WHEN txn_type='SELL' THEN 1 END) AS sell_count
     FROM stock_transactions
     WHERE portfolio_id IN ($pidList) AND txn_date >= ?",
    [$fyStart]
) ?: [];
$ltcg = (float)($fyGains['ltcg'] ?? 0);
$stcg = (float)($fyGains['stcg'] ?? 0);
if ($ltcg != 0 || $stcg != 0) {
    $fyItems = [
        ['text' => 'LTCG: ' . ($ltcg >= 0 ? '+' : '') . '₹' . _fmtAmt($ltcg) . ($ltcg > 125000 ? ' ⚠️ Exceeds ₹1.25L limit' : ' ✅ Within free limit'), 'type' => $ltcg > 125000 ? 'warning' : 'positive'],
        ['text' => 'STCG (20%): ' . ($stcg >= 0 ? '+' : '') . '₹' . _fmtAmt($stcg), 'type' => $stcg > 0 ? 'neutral' : 'positive'],
        ['text' => (int)($fyGains['sell_count'] ?? 0) . ' sell transactions this FY', 'type' => 'neutral'],
    ];
    $brief['sections'][] = ['id' => 'fy', 'title' => '🧾 FY Tax Snapshot', 'icon' => '🧾', 'items' => $fyItems];
}

// ── Cache and respond ─────────────────────────────────────────────────────
WdCache::set($cacheKey, $brief, ttl: 14400, tags: ["user:{$userId}"]);  // 4h
json_response(true, '', $brief);

// ── Helper ───────────────────────────────────────────────────────────────
function _fmtAmt(float $n): string {
    if ($n >= 10000000) return number_format($n / 10000000, 2) . 'Cr';
    if ($n >= 100000)   return number_format($n / 100000,   2) . 'L';
    if ($n >= 1000)     return number_format($n / 1000,     1) . 'K';
    return number_format($n, 0);
}
