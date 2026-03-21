<?php
/**
 * WealthDash - Market Indexes Fetch
 * Yahoo Finance - price + 7-day sparkline
 * POST /api/?action=indexes_fetch
 */
defined('WEALTHDASH') or die('Direct access not permitted.');

$INDEX_MAP = [
    'india_broad' => [
        ['key' => 'nifty50',     'label' => 'NIFTY 50',          'symbol' => '^NSEI'],
        ['key' => 'sensex',      'label' => 'SENSEX',             'symbol' => '^BSESN'],
        ['key' => 'banknifty',   'label' => 'Bank NIFTY',         'symbol' => '^NSEBANK'],
        ['key' => 'niftynxt50',  'label' => 'NIFTY Next 50',      'symbol' => '^NSMIDCP100'],
        ['key' => 'niftymid150', 'label' => 'NIFTY Midcap 150',   'symbol' => '^NSEMDCP150'],
        ['key' => 'niftysmall',  'label' => 'NIFTY Smallcap 250', 'symbol' => '^CNXSC'],
        ['key' => 'indiavix',    'label' => 'India VIX',           'symbol' => '^INDIAVIX'],
    ],
    'india_sectoral' => [
        ['key' => 'niftyit',     'label' => 'NIFTY IT',           'symbol' => '^CNXIT'],
        ['key' => 'niftypharma', 'label' => 'NIFTY Pharma',       'symbol' => '^CNXPHARMA'],
        ['key' => 'niftyauto',   'label' => 'NIFTY Auto',         'symbol' => '^CNXAUTO'],
        ['key' => 'niftyfmcg',   'label' => 'NIFTY FMCG',         'symbol' => '^CNXFMCG'],
        ['key' => 'niftymetal',  'label' => 'NIFTY Metal',        'symbol' => '^CNXMETAL'],
        ['key' => 'niftyrealty', 'label' => 'NIFTY Realty',       'symbol' => '^CNXREALTY'],
        ['key' => 'niftypvtbnk', 'label' => 'NIFTY Pvt Bank',     'symbol' => '^NSPNB'],
        ['key' => 'niftyenergy', 'label' => 'NIFTY Energy',       'symbol' => '^CNXENERGY'],
    ],
    'world' => [
        ['key' => 'sp500',       'label' => 'S&P 500',            'symbol' => '^GSPC'],
        ['key' => 'nasdaq100',   'label' => 'NASDAQ 100',         'symbol' => '^NDX'],
        ['key' => 'dowjones',    'label' => 'Dow Jones',          'symbol' => '^DJI'],
        ['key' => 'ftse100',     'label' => 'FTSE 100 (UK)',      'symbol' => '^FTSE'],
        ['key' => 'dax',         'label' => 'DAX (Germany)',      'symbol' => '^GDAXI'],
        ['key' => 'nikkei',      'label' => 'Nikkei 225',         'symbol' => '^N225'],
        ['key' => 'hangseng',    'label' => 'Hang Seng (HK)',     'symbol' => '^HSI'],
        ['key' => 'shanghai',    'label' => 'Shanghai Comp.',     'symbol' => '000001.SS'],
    ],
    'commodities' => [
        ['key' => 'gold',        'label' => 'Gold',               'symbol' => 'GC=F'],
        ['key' => 'silver',      'label' => 'Silver',             'symbol' => 'SI=F'],
        ['key' => 'crudewti',    'label' => 'Crude Oil (WTI)',    'symbol' => 'CL=F'],
        ['key' => 'brent',       'label' => 'Brent Crude',        'symbol' => 'BZ=F'],
    ],
];

function fetch_index_data($symbol) {
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$symbol}?interval=1d&range=10d";
    $ctx = stream_context_create(['http' => [
        'timeout'       => 8,
        'user_agent'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'ignore_errors' => true,
    ]]);

    $response = @file_get_contents($url, false, $ctx);
    if (!$response) return null;

    $data = json_decode($response, true);
    if (!isset($data['chart']['result'][0])) return null;

    $result    = $data['chart']['result'][0];
    $meta      = $result['meta'] ?? [];
    $closes    = $result['indicators']['quote'][0]['close'] ?? [];

    $price = round((float)($meta['regularMarketPrice'] ?? 0), 2);
    if ($price <= 0) return null;

    // Build sparkline first: last 7 valid closes + today
    $valid = [];
    foreach ($closes as $v) {
        if ($v !== null && $v > 0) $valid[] = round((float)$v, 2);
    }
    $spark = array_slice($valid, -7);
    $spark[] = $price;

    // Use second-to-last close as prev_close (most accurate for indices)
    // Yahoo 'previousClose' can be stale; closes array is reliable
    $prevClose = 0;
    if (count($valid) >= 2) {
        // Last element of $valid is today's close (same as $price usually), use second-last
        $prevClose = round((float)$valid[count($valid) - 2], 2);
    }
    // Fallback to Yahoo fields if closes array insufficient
    if ($prevClose <= 0) {
        $prevClose = round((float)(
            $meta['regularMarketPreviousClose'] ??
            $meta['chartPreviousClose'] ??
            $meta['previousClose'] ?? 0
        ), 2);
    }

    $change    = round($price - $prevClose, 2);
    $changePct = $prevClose > 0 ? round(($change / $prevClose) * 100, 2) : 0;

    return [
        'price'      => $price,
        'prev_close' => $prevClose,
        'change'     => $change,
        'change_pct' => $changePct,
        'currency'   => $meta['currency'] ?? '',
        'sparkline'  => $spark,
    ];
}

$results = [];
$failed  = [];

foreach ($INDEX_MAP as $section => $indexes) {
    $results[$section] = [];
    foreach ($indexes as $idx) {
        $fetched = fetch_index_data($idx['symbol']);
        if ($fetched) {
            $results[$section][] = array_merge([
                'key'   => $idx['key'],
                'label' => $idx['label'],
            ], $fetched);
        } else {
            $failed[] = $idx['label'];
            $results[$section][] = [
                'key'        => $idx['key'],
                'label'      => $idx['label'],
                'price'      => null,
                'prev_close' => null,
                'change'     => null,
                'change_pct' => null,
                'currency'   => '',
                'sparkline'  => [],
            ];
        }
        usleep(150000);
    }
}

$ist     = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
$mins    = (int)$ist->format('H') * 60 + (int)$ist->format('i');
$dow     = (int)$ist->format('N');
$nseOpen = $dow <= 5 && $mins >= 555 && $mins <= 930;

json_response(true, count($failed) ? implode(', ', $failed) . ' could not be fetched.' : '', [
    'indexes'      => $results,
    'nse_open'     => $nseOpen,
    'fetched_at'   => $ist->format('d M Y, h:i A') . ' IST',
    'failed_count' => count($failed),
]);