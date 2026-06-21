<?php
/**
 * WealthDash — Market Pulse API
 * Task t298: Nifty50, Sensex, Gold, USD/INR — free public sources
 * Action: market_pulse
 * Cache: 5 minutes in PHP session to reduce external calls
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$currentUser = require_auth();

// ── 5-minute cache in session ──────────────────────────────────────
$cacheKey = 'market_pulse_cache';
$cacheTs  = 'market_pulse_ts';
if (!empty($_SESSION[$cacheKey]) && !empty($_SESSION[$cacheTs])) {
    if (time() - $_SESSION[$cacheTs] < 300) {
        echo json_encode(['success' => true, 'data' => $_SESSION[$cacheKey], 'cached' => true]);
        return;
    }
}

$tickers = [];
$errors  = [];

// ── Helper: safe curl fetch ────────────────────────────────────────
function mp_fetch(string $url, array $headers = [], int $timeout = 8): ?string {
    if (!function_exists('curl_init')) {
        $ctx = stream_context_create(['http' => [
            'timeout' => $timeout,
            'header'  => implode("\r\n", $headers),
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]]);
        $r = @file_get_contents($url, false, $ctx);
        return $r !== false ? $r : null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code === 200 && $body) ? $body : null;
}

// ── 1. Nifty 50 & Sensex via NSE free API ─────────────────────────
try {
    // NSE requires cookie — first hit homepage to get session cookie
    $nseBody = mp_fetch('https://www.nseindia.com/api/allIndices', [
        'Accept: application/json',
        'Referer: https://www.nseindia.com',
        'Accept-Language: en-US,en;q=0.9',
    ]);
    if ($nseBody) {
        $nseData = json_decode($nseBody, true);
        $indices = $nseData['data'] ?? [];
        foreach ($indices as $idx) {
            $key = $idx['indexSymbol'] ?? '';
            if ($key === 'NIFTY 50') {
                $tickers[] = [
                    'symbol'     => 'NIFTY',
                    'name'       => 'Nifty 50',
                    'value'      => number_format((float)$idx['last'], 2),
                    'change_pct' => round((float)($idx['percentChange'] ?? 0), 2),
                    'change_abs' => round((float)($idx['change'] ?? 0), 2),
                ];
            }
            if ($key === 'NIFTY BANK') {
                $tickers[] = [
                    'symbol'     => 'BANKNIFTY',
                    'name'       => 'Bank Nifty',
                    'value'      => number_format((float)$idx['last'], 2),
                    'change_pct' => round((float)($idx['percentChange'] ?? 0), 2),
                    'change_abs' => round((float)($idx['change'] ?? 0), 2),
                ];
            }
        }
    }
} catch (Exception $e) {
    $errors[] = 'NSE: ' . $e->getMessage();
}

// ── 2. Fallback / Sensex via Yahoo Finance ─────────────────────────
if (count($tickers) < 2) {
    try {
        $symbols = ['^NSEI' => 'NIFTY', '^BSESN' => 'SENSEX'];
        foreach ($symbols as $sym => $label) {
            $already = array_filter($tickers, fn($t) => $t['symbol'] === $label);
            if ($already) continue;
            $url  = "https://query1.finance.yahoo.com/v8/finance/chart/{$sym}?interval=1d&range=2d";
            $body = mp_fetch($url);
            if (!$body) continue;
            $d    = json_decode($body, true);
            $meta = $d['chart']['result'][0]['meta'] ?? null;
            if (!$meta) continue;
            $price  = (float)($meta['regularMarketPrice'] ?? 0);
            $prev   = (float)($meta['chartPreviousClose'] ?? $meta['previousClose'] ?? $price);
            $chgPct = $prev > 0 ? round(($price - $prev) / $prev * 100, 2) : 0;
            $tickers[] = [
                'symbol'     => $label,
                'name'       => $label === 'NIFTY' ? 'Nifty 50' : 'Sensex',
                'value'      => number_format($price, 2),
                'change_pct' => $chgPct,
                'change_abs' => round($price - $prev, 2),
            ];
        }
    } catch (Exception $e) {
        $errors[] = 'Yahoo: ' . $e->getMessage();
    }
}

// ── 3. Gold price (INR per 10g) via goldapi.io free tier ──────────
try {
    // Check if API key is set in config
    $goldApiKey = defined('GOLD_API_KEY') ? GOLD_API_KEY : (getenv('GOLD_API_KEY') ?: '');
    if ($goldApiKey) {
        $goldBody = mp_fetch('https://www.goldapi.io/api/XAU/INR', [
            "x-access-token: {$goldApiKey}",
            'Content-Type: application/json',
        ]);
        if ($goldBody) {
            $g = json_decode($goldBody, true);
            $pricePerGram = ($g['price'] ?? 0) / 31.1035; // troy oz → gram
            $per10g = $pricePerGram * 10;
            $chg = $g['ch'] ?? 0;
            $tickers[] = [
                'symbol'     => 'GOLD',
                'name'       => 'Gold (10g)',
                'value'      => '₹' . number_format($per10g, 0),
                'change_pct' => round((float)($g['chp'] ?? 0), 2),
                'change_abs' => round((float)$chg / 31.1035 * 10, 0),
            ];
        }
    } else {
        // Fallback: Yahoo Finance XAU/INR
        $url  = 'https://query1.finance.yahoo.com/v8/finance/chart/GC=F?interval=1d&range=2d';
        $body = mp_fetch($url);
        if ($body) {
            $d    = json_decode($body, true);
            $meta = $d['chart']['result'][0]['meta'] ?? null;
            if ($meta) {
                $priceUSD  = (float)($meta['regularMarketPrice'] ?? 0);
                $prevUSD   = (float)($meta['chartPreviousClose'] ?? $priceUSD);
                // Approx USD/INR = 84 (update from RBI if available)
                $usdInr    = 84.0;
                $per10gInr = ($priceUSD / 31.1035) * 10 * $usdInr;
                $chgPct    = $prevUSD > 0 ? round(($priceUSD - $prevUSD) / $prevUSD * 100, 2) : 0;
                $tickers[] = [
                    'symbol'     => 'GOLD',
                    'name'       => 'Gold (10g ~)',
                    'value'      => '₹' . number_format($per10gInr, 0),
                    'change_pct' => $chgPct,
                    'change_abs' => 0,
                ];
            }
        }
    }
} catch (Exception $e) {
    $errors[] = 'Gold: ' . $e->getMessage();
}

// ── 4. USD/INR via Yahoo Finance ──────────────────────────────────
try {
    $url  = 'https://query1.finance.yahoo.com/v8/finance/chart/INR=X?interval=1d&range=2d';
    $body = mp_fetch($url);
    if ($body) {
        $d    = json_decode($body, true);
        $meta = $d['chart']['result'][0]['meta'] ?? null;
        if ($meta) {
            $rate = (float)($meta['regularMarketPrice'] ?? 0);
            $prev = (float)($meta['chartPreviousClose'] ?? $rate);
            $chg  = $prev > 0 ? round(($rate - $prev) / $prev * 100, 2) : 0;
            $tickers[] = [
                'symbol'     => 'USD/INR',
                'name'       => 'Dollar',
                'value'      => '₹' . number_format($rate, 2),
                'change_pct' => $chg,
                'change_abs' => round($rate - $prev, 4),
            ];
        }
    }
} catch (Exception $e) {
    $errors[] = 'USD/INR: ' . $e->getMessage();
}

// ── If all failed, return sensible defaults ────────────────────────
if (empty($tickers)) {
    $tickers = [
        ['symbol'=>'NIFTY',   'name'=>'Nifty 50',  'value'=>'—', 'change_pct'=>0,'change_abs'=>0],
        ['symbol'=>'SENSEX',  'name'=>'Sensex',     'value'=>'—', 'change_pct'=>0,'change_abs'=>0],
        ['symbol'=>'GOLD',    'name'=>'Gold (10g)', 'value'=>'—', 'change_pct'=>0,'change_abs'=>0],
        ['symbol'=>'USD/INR', 'name'=>'Dollar',     'value'=>'—', 'change_pct'=>0,'change_abs'=>0],
    ];
}

// ── Cache result ───────────────────────────────────────────────────
$_SESSION[$cacheKey] = $tickers;
$_SESSION[$cacheTs]  = time();

echo json_encode([
    'success'    => true,
    'data'       => $tickers,
    'errors'     => $errors,
    'updated_at' => date('H:i:s'),
]);
