<?php
/**
 * WealthDash — Stock Live Price via Yahoo Finance (unofficial)
 * POST /api/?action=stocks_price
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');
require_once APP_ROOT . '/includes/holding_calculator.php';

$symbol    = strtoupper(clean($_POST['symbol'] ?? ''));
$exchange  = strtoupper(clean($_POST['exchange'] ?? 'NSE'));
$stockId   = (int)($_POST['stock_id'] ?? 0);
$updateAll = isset($_POST['update_all']);

/* ─── Build Yahoo Finance symbol ─────────────────────────────────────── */
function yahoo_symbol(string $sym, string $exchange): string {
    // NSE: RELIANCE.NS, BSE: RELIANCE.BO
    $suffix = $exchange === 'BSE' ? '.BO' : '.NS';
    return $sym . $suffix;
}

function fetch_yahoo_price(string $ySym): ?array {
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$ySym}?interval=1d&range=1d";
    $ctx = stream_context_create([
        'http' => [
            'timeout'       => 5,
            'user_agent'    => 'Mozilla/5.0 WealthDash/1.0',
            'ignore_errors' => true,
        ]
    ]);
    $response = @file_get_contents($url, false, $ctx);
    if (!$response) return null;

    $data = json_decode($response, true);
    if (!isset($data['chart']['result'][0])) return null;

    $result = $data['chart']['result'][0];
    $meta   = $result['meta'] ?? [];

    return [
        'price'     => round((float)($meta['regularMarketPrice'] ?? 0), 2),
        'prev_close'=> round((float)($meta['previousClose'] ?? 0), 2),
        'currency'  => $meta['currency'] ?? 'INR',
        'timestamp' => $meta['regularMarketTime'] ?? time(),
    ];
}

/* ─── Single stock price ─────────────────────────────────────────────── */
if ($symbol && !$updateAll) {
    $ySym = yahoo_symbol($symbol, $exchange);
    $price = fetch_yahoo_price($ySym);

    if (!$price || $price['price'] <= 0) {
        json_response(false, "Could not fetch price for {$symbol}. Please update manually.", [
            'symbol'  => $symbol,
            'yahoo'   => $ySym,
        ]);
    }

    // Update in DB if stock_id provided
    if ($stockId) {
        DB::run(
            "UPDATE stock_master SET latest_price = ?, latest_price_date = CURDATE() WHERE id = ?",
            [$price['price'], $stockId]
        );
        // Recalculate all holdings for this stock
        $portfolios = DB::fetchAll("SELECT DISTINCT portfolio_id FROM stock_holdings WHERE stock_id = ? AND is_active = 1", [$stockId]);
        foreach ($portfolios as $p) {
            HoldingCalculator::recalculate_stock_holding($p['portfolio_id'], $stockId);
        }
    }

    json_response(true, "Price fetched for {$symbol}.", [
        'symbol'     => $symbol,
        'price'      => $price['price'],
        'prev_close' => $price['prev_close'],
        'change'     => round($price['price'] - $price['prev_close'], 2),
        'change_pct' => $price['prev_close'] > 0 ? round((($price['price'] - $price['prev_close']) / $price['prev_close']) * 100, 2) : 0,
    ]);
}

/* ─── Bulk update all stocks ─────────────────────────────────────────── */
if ($updateAll) {
    if (!is_admin()) json_response(false, 'Admin only for bulk update.', [], 403);

    $stocks = DB::fetchAll("SELECT id, symbol, exchange FROM stock_master");
    $updated = 0;
    $failed  = [];

    foreach ($stocks as $stock) {
        $ySym  = yahoo_symbol($stock['symbol'], $stock['exchange']);
        $price = fetch_yahoo_price($ySym);

        if ($price && $price['price'] > 0) {
            DB::run(
                "UPDATE stock_master SET latest_price = ?, latest_price_date = CURDATE() WHERE id = ?",
                [$price['price'], $stock['id']]
            );
            $updated++;
        } else {
            $failed[] = $stock['symbol'];
        }
        usleep(200000); // 0.2s delay between requests (rate limiting)
    }

    // Recalculate all stock holdings
    $portfolioIds = array_column(DB::fetchAll("SELECT DISTINCT id FROM portfolios"), 'id');
    foreach ($portfolioIds as $pid) {
        HoldingCalculator::recalculate_stocks($pid);
    }

    json_response(true, "Updated {$updated} stocks. " . count($failed) . " failed.", [
        'updated' => $updated,
        'failed'  => $failed,
    ]);
}

json_response(false, 'Provide symbol or update_all.');

