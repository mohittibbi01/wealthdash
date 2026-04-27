<?php
/**
 * WealthDash — t343: Live NAV Widget API
 * Estimates intraday NAV from benchmark (Nifty/Sensex) movement
 *
 * Action: live_nav_estimate
 * Returns per-holding estimated NAV + portfolio-level estimated value
 *
 * Logic:
 *   estimated_nav = yesterday_nav × (1 + benchmark_change_pct / 100)
 *   Benchmark mapped by fund category (Equity Large Cap → Nifty50, etc.)
 *   Clearly labelled "Estimated" — NOT live official NAV
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();

header('Content-Type: application/json');

// ── Market Hours Check (IST = UTC+5:30) ──────────────────────────────────
function isMarketOpen(): bool {
    $ist = new DateTimeZone('Asia/Kolkata');
    $now = new DateTime('now', $ist);
    $day = (int)$now->format('N'); // 1=Mon ... 7=Sun
    if ($day >= 6) return false;   // Weekend

    $hhmm = (int)$now->format('Hi');
    return ($hhmm >= 915 && $hhmm <= 1530);
}

function isNavUpdateTime(): bool {
    $ist  = new DateTimeZone('Asia/Kolkata');
    $hhmm = (int)(new DateTime('now', $ist))->format('Hi');
    return ($hhmm >= 1900); // After 7 PM — official NAVs likely published
}

// ── Benchmark to Nifty symbol map (stooq) ────────────────────────────────
$BENCHMARK_MAP = [
    // Large Cap / Index / Flexi
    'Large Cap Fund'        => ['^nsei', 'Nifty 50'],
    'Index Fund'            => ['^nsei', 'Nifty 50'],
    'Flexi Cap Fund'        => ['^nsei', 'Nifty 50'],
    'Large & Mid Cap Fund'  => ['^nsei', 'Nifty 50'],
    'Value Fund'            => ['^nsei', 'Nifty 50'],
    'Dividend Yield Fund'   => ['^nsei', 'Nifty 50'],
    'Contra Fund'           => ['^nsei', 'Nifty 50'],
    'Focused Fund'          => ['^nsei', 'Nifty 50'],
    // Mid / Small Cap
    'Mid Cap Fund'          => ['^nsmidcp', 'Nifty Midcap 150'],
    'Small Cap Fund'        => ['^nsmidcp', 'Nifty Midcap 150'],
    'Multi Cap Fund'        => ['^nsmidcp', 'Nifty Midcap 150'],
    // Hybrid
    'Aggressive Hybrid Fund'=> ['^nsei', 'Nifty 50'],
    'Balanced Advantage Fund'=> ['^nsei', 'Nifty 50'],
    'Equity Savings Fund'   => ['^nsei', 'Nifty 50'],
    // Debt / Liquid — no intraday estimation (returns 0 change)
    'Liquid Fund'           => [null, 'n/a'],
    'Overnight Fund'        => [null, 'n/a'],
    'Ultra Short Duration Fund' => [null, 'n/a'],
    'Short Duration Fund'   => [null, 'n/a'],
    'Medium Duration Fund'  => [null, 'n/a'],
    'Long Duration Fund'    => [null, 'n/a'],
    'Gilt Fund'             => [null, 'n/a'],
    'Credit Risk Fund'      => [null, 'n/a'],
    'Corporate Bond Fund'   => [null, 'n/a'],
    'Money Market Fund'     => [null, 'n/a'],
    'Banking and PSU Fund'  => [null, 'n/a'],
    // ELSS
    'ELSS'                  => ['^nsei', 'Nifty 50'],
    'Tax Saver'             => ['^nsei', 'Nifty 50'],
];

// ── Fetch benchmark change% from stooq (with short cache) ────────────────
function fetchBenchmarkChange(string $symbol, PDO $db): ?float {
    if (!$symbol) return null;

    // Cache key in temp (5-min TTL during market hours, 1hr otherwise)
    $cacheKey  = 'live_bench_' . preg_replace('/[^a-z0-9]/', '_', $symbol);
    $cacheFile = sys_get_temp_dir() . '/' . $cacheKey . '.json';
    $ttl       = isMarketOpen() ? 300 : 3600;

    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $c = json_decode(file_get_contents($cacheFile), true);
        if (isset($c['change_pct'])) return (float)$c['change_pct'];
    }

    // Fetch last 2 trading days from stooq CSV
    $from = date('Ymd', strtotime('-5 days')); // buffer for weekends
    $to   = date('Ymd');
    $url  = "https://stooq.com/q/d/l/?s={$symbol}&d1={$from}&d2={$to}&i=d";

    $ctx  = stream_context_create(['http' => [
        'timeout' => 6,
        'user_agent' => 'Mozilla/5.0 WealthDash/1.0',
    ]]);
    $csv  = @file_get_contents($url, false, $ctx);
    if (!$csv) return null;

    $lines = array_filter(explode("\n", trim($csv)));
    if (count($lines) < 3) return null; // need header + at least 2 rows

    array_shift($lines); // remove header
    $rows = array_values($lines);
    $last = count($rows) - 1;

    $today_parts = str_getcsv($rows[$last]);
    $prev_parts  = str_getcsv($rows[$last - 1]);
    if (count($today_parts) < 5 || count($prev_parts) < 5) return null;

    $todayClose = (float)$today_parts[4]; // Close column
    $prevClose  = (float)$prev_parts[4];
    if ($prevClose == 0) return null;

    $change = ($todayClose - $prevClose) / $prevClose * 100;

    file_put_contents($cacheFile, json_encode([
        'change_pct'  => $change,
        'today_close' => $todayClose,
        'prev_close'  => $prevClose,
        'fetched_at'  => date('c'),
        'symbol'      => $symbol,
    ]));

    return $change;
}

// ── Main action ───────────────────────────────────────────────────────────
switch ($action) {

case 'live_nav_estimate': {
    $portfolioId = DB::fetchVal(
        "SELECT id FROM portfolios WHERE user_id = ? ORDER BY is_default DESC, id ASC LIMIT 1",
        [$userId]
    );
    if (!$portfolioId) { json_response(true, 'ok', ['holdings' => [], 'market_open' => false]); break; }

    $holdings = $db->prepare("
        SELECT mh.id AS holding_id, mh.fund_id, mh.units,
               mh.current_nav AS official_nav,
               mh.current_value AS official_value,
               mh.invested_amount,
               f.scheme_name, f.category, f.nav_date
        FROM mf_holdings mh
        JOIN funds f ON f.id = mh.fund_id
        WHERE mh.portfolio_id = ? AND mh.is_active = 1 AND mh.units > 0
        ORDER BY mh.current_value DESC
    ");
    $holdings->execute([$portfolioId]);
    $rows = $holdings->fetchAll(PDO::FETCH_ASSOC);

    $marketOpen   = isMarketOpen();
    $navUpdated   = isNavUpdateTime();
    $ist          = new DateTimeZone('Asia/Kolkata');
    $nowStr       = (new DateTime('now', $ist))->format('h:i A');

    // Fetch benchmark changes (cache reused across funds with same benchmark)
    $benchCache = [];
    $result     = [];
    $estPortfolioValue = 0;
    $offPortfolioValue = 0;

    foreach ($rows as $h) {
        $cat     = trim($h['category'] ?? '');
        $mapping = $BENCHMARK_MAP[$cat] ?? ['^nsei', 'Nifty 50'];
        [$benchSym, $benchName] = $mapping;

        $changePct   = null;
        $isEstimated = false;

        if ($benchSym && $marketOpen && !$navUpdated) {
            if (!isset($benchCache[$benchSym])) {
                $benchCache[$benchSym] = fetchBenchmarkChange($benchSym, $db);
            }
            $changePct   = $benchCache[$benchSym];
            $isEstimated = ($changePct !== null);
        }

        $officialNav   = (float)$h['official_nav'];
        $units         = (float)$h['units'];

        if ($isEstimated && $changePct !== null) {
            // Equity funds have ~0.6–0.85 beta to index; use 0.75 as conservative default
            $beta        = in_array($cat, ['Large Cap Fund','Index Fund','Flexi Cap Fund']) ? 0.90 : 0.75;
            $estNav      = $officialNav * (1 + ($changePct * $beta / 100));
            $estValue    = $units * $estNav;
        } else {
            $estNav   = $officialNav;
            $estValue = (float)$h['official_value'];
            $isEstimated = false;
        }

        $estPortfolioValue += $estValue;
        $offPortfolioValue += (float)$h['official_value'];

        $result[] = [
            'holding_id'   => (int)$h['holding_id'],
            'fund_id'      => (int)$h['fund_id'],
            'scheme_name'  => $h['scheme_name'],
            'category'     => $cat,
            'units'        => $units,
            'official_nav' => $officialNav,
            'nav_date'     => $h['nav_date'],
            'est_nav'      => round($estNav, 4),
            'est_value'    => round($estValue, 2),
            'official_value'=> (float)$h['official_value'],
            'change_pct'   => $changePct !== null ? round($changePct, 3) : null,
            'is_estimated' => $isEstimated,
            'benchmark'    => $benchName,
            'is_debt'      => !$benchSym,
        ];
    }

    $portfolioChangePct = $offPortfolioValue > 0
        ? ($estPortfolioValue - $offPortfolioValue) / $offPortfolioValue * 100
        : 0;

    // Nifty 50 current change for display
    $niftyChange = $benchCache['^nsei'] ?? null;

    json_response(true, 'ok', [
        'market_open'           => $marketOpen,
        'nav_updated'           => $navUpdated,
        'time_ist'              => $nowStr,
        'nifty_change_pct'      => $niftyChange !== null ? round($niftyChange, 3) : null,
        'est_portfolio_value'   => round($estPortfolioValue, 2),
        'off_portfolio_value'   => round($offPortfolioValue, 2),
        'portfolio_change_pct'  => round($portfolioChangePct, 3),
        'holdings'              => $result,
        'disclaimer'            => 'Estimated NAV based on ' . ($niftyChange !== null ? 'Nifty 50' : 'index') . ' movement. Not official AMFI NAV.',
    ]);
    break;
}

default:
    json_response(false, 'Unknown action');
}
