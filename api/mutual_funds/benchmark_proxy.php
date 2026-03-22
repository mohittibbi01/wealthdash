<?php
/**
 * WealthDash — Benchmark Proxy (Nifty 50 / Sensex)
 * GET /api/mutual_funds/benchmark_proxy.php?symbol=^NSEI&from=YYYY-MM-DD&to=YYYY-MM-DD
 * Fetches index data from stooq.com (free, no API key needed)
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

header('Content-Type: application/json');
$currentUser = require_auth();

$symbol  = strtoupper(trim($_GET['symbol'] ?? '^NSEI'));
$from    = trim($_GET['from']   ?? date('Y-m-d', strtotime('-1 year')));
$to      = trim($_GET['to']     ?? date('Y-m-d'));

// Validate symbol whitelist
$allowed = ['^NSEI' => 'n225', '^BSESN' => 'bsesn', 'NIFTY' => 'n225'];
// Map Yahoo symbols to stooq
$stooqMap = ['^NSEI' => '^nsei', '^BSESN' => '^bsesn'];
$stooqSym = $stooqMap[$symbol] ?? '^nsei';

// Cache in DB/files for 1 day to avoid hammering external API
$cacheKey  = 'benchmark_' . preg_replace('/[^a-z0-9]/', '_', strtolower($stooqSym)) . '_' . $from . '_' . $to;
$cacheFile = sys_get_temp_dir() . '/' . $cacheKey . '.json';

if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 86400) {
    $cached = file_get_contents($cacheFile);
    if ($cached) {
        echo $cached;
        exit;
    }
}

// Fetch from stooq
$url = "https://stooq.com/q/d/l/?s={$stooqSym}&d1=" . str_replace('-', '', $from) . "&d2=" . str_replace('-', '', $to) . "&i=d";

$ctx = stream_context_create([
    'http' => [
        'timeout'    => 8,
        'user_agent' => 'Mozilla/5.0 WealthDash/1.0',
        'header'     => "Accept: text/csv\r\n",
    ],
    'ssl' => ['verify_peer' => false],
]);

$csv = @file_get_contents($url, false, $ctx);

if (!$csv || strpos($csv, 'Date') === false || strpos($csv, 'No data') !== false) {
    // Fallback: return empty but success so JS handles gracefully
    $resp = json_encode(['success' => false, 'message' => 'Benchmark data unavailable', 'data' => []]);
    file_put_contents($cacheFile, $resp);
    echo $resp;
    exit;
}

// Parse CSV: Date,Open,High,Low,Close,Volume
$lines = array_filter(explode("\n", trim($csv)));
array_shift($lines); // remove header

$data = [];
foreach ($lines as $line) {
    $cols = str_getcsv($line);
    if (count($cols) < 5 || !$cols[0] || !is_numeric($cols[4])) continue;
    $data[] = [
        'date'  => $cols[0],
        'close' => (float)$cols[4],
    ];
}

// Sort ascending
usort($data, fn($a, $b) => strcmp($a['date'], $b['date']));

$resp = json_encode(['success' => true, 'symbol' => $symbol, 'data' => $data]);
file_put_contents($cacheFile, $resp);
echo $resp;
