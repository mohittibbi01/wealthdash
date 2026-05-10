<?php
/**
 * WealthDash — tc001: Live Crypto Price Stream (SSE)
 * Server-Sent Events endpoint — pushes CoinGecko prices every 30s.
 * Works on standard Apache/Nginx without WebSocket config.
 *
 * GET /api/router.php?action=crypto_price_stream&coins=bitcoin,ethereum,solana
 *
 * Client usage:
 *   const es = new EventSource('/api/router.php?action=crypto_price_stream&coins=bitcoin,ethereum');
 *   es.addEventListener('prices', e => { const prices = JSON.parse(e.data); ... });
 *   es.addEventListener('error',  e => { console.warn('SSE error', e); });
 *   es.addEventListener('ping',   e => { /* keepalive */ });
 *
 * Events emitted:
 *   prices  — JSON object: { bitcoin: { inr, usd, chg24h, mcap }, ... }
 *   ping    — keepalive every 25s
 *   error   — { message } on CoinGecko failure
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

// ── SSE headers ──────────────────────────────────────────────────────────────
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');   // Disable Nginx buffering
header('Connection: keep-alive');

// Disable output buffering completely
while (ob_get_level()) ob_end_flush();

// ── Validate coins param ──────────────────────────────────────────────────────
$rawCoins = $_GET['coins'] ?? '';
$coinIds  = array_slice(
    array_filter(
        array_map('trim', explode(',', $rawCoins)),
        fn($c) => preg_match('/^[a-z0-9\-]{2,50}$/', $c)
    ),
    0, 25  // max 25 coins per stream
);

if (empty($coinIds)) {
    echo "event: error\ndata: " . json_encode(['message' => 'No valid coin IDs provided']) . "\n\n";
    flush();
    exit;
}

// ── Stream loop ───────────────────────────────────────────────────────────────
$maxRuntime  = 180;   // Close after 3 min (client auto-reconnects via EventSource)
$interval    = 30;    // Fetch new prices every 30s
$pingEvery   = 25;    // Keepalive ping every 25s
$startTime   = time();
$lastFetch   = 0;
$lastPing    = time();

function sse_send(string $event, mixed $data): void {
    echo "event: {$event}\n";
    echo "data: " . json_encode($data) . "\n\n";
    flush();
}

function sse_fetch_prices(array $coinIds): ?array {
    $ids  = implode(',', array_map('rawurlencode', $coinIds));
    $url  = "https://api.coingecko.com/api/v3/simple/price"
           . "?ids={$ids}&vs_currencies=inr,usd"
           . "&include_24hr_change=true&include_market_cap=true&include_24hr_vol=true";
    $ctx  = stream_context_create(['http' => [
        'timeout'    => 8,
        'header'     => "User-Agent: WealthDash/1.0\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
    ]]);
    $raw  = @file_get_contents($url, false, $ctx);
    if (!$raw) return null;
    $data = json_decode($raw, true);
    if (!is_array($data)) return null;

    $result = [];
    foreach ($data as $coinId => $info) {
        $result[$coinId] = [
            'inr'    => round((float)($info['inr'] ?? 0), 2),
            'usd'    => round((float)($info['usd'] ?? 0), 4),
            'chg24h' => round((float)($info['inr_24h_change'] ?? $info['usd_24h_change'] ?? 0), 2),
            'mcap'   => (int)($info['inr_market_cap'] ?? 0),
            'vol24h' => (int)($info['inr_24h_vol'] ?? 0),
            'ts'     => time(),
        ];
    }
    return $result;
}

// Send initial ping immediately
sse_send('ping', ['t' => time()]);

while (true) {
    // Check client disconnect
    if (connection_aborted()) break;

    $now = time();

    // Stop after maxRuntime (EventSource auto-reconnects)
    if ($now - $startTime >= $maxRuntime) {
        sse_send('ping', ['reconnect' => true, 't' => $now]);
        break;
    }

    // Fetch prices on interval
    if ($now - $lastFetch >= $interval) {
        $prices = sse_fetch_prices($coinIds);
        if ($prices !== null) {
            sse_send('prices', $prices);
        } else {
            sse_send('error', ['message' => 'CoinGecko unavailable', 't' => $now]);
        }
        $lastFetch = $now;
        $lastPing  = $now;
    }

    // Keepalive ping
    if ($now - $lastPing >= $pingEvery) {
        sse_send('ping', ['t' => $now]);
        $lastPing = $now;
    }

    sleep(5);  // check every 5s
}
