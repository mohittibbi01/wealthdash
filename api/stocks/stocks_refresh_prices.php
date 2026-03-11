<?php
/**
 * WealthDash — Refresh All Stock Prices via Yahoo Finance
 * POST /api/?action=stocks_refresh_prices
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

// Get all stocks that have holdings
$stocks = DB::fetchAll(
    "SELECT DISTINCT sm.id, sm.symbol, sm.exchange
     FROM stock_master sm
     JOIN stock_holdings sh ON sh.stock_id = sm.id
     WHERE sh.quantity > 0"
);

if (empty($stocks)) json_response(true, 'No active holdings to update.');

$updated = 0;
$failed  = 0;

foreach ($stocks as $stock) {
    $suffix  = $stock['exchange'] === 'BSE' ? '.BO' : '.NS';
    $ySymbol = $stock['symbol'] . $suffix;
    $url     = "https://query1.finance.yahoo.com/v8/finance/chart/{$ySymbol}?interval=1d&range=1d";

    $ctx = stream_context_create(['http' => [
        'timeout'    => 8,
        'user_agent' => 'Mozilla/5.0 WealthDash/1.0',
        'header'     => "Accept: application/json\r\n",
    ]]);

    $raw = @file_get_contents($url, false, $ctx);
    if (!$raw) { $failed++; continue; }

    $json  = json_decode($raw, true);
    $price = $json['chart']['result'][0]['meta']['regularMarketPrice'] ?? null;
    $date  = $json['chart']['result'][0]['meta']['regularMarketTime']  ?? null;

    if ($price && $price > 0) {
        $priceDate = $date ? date('Y-m-d', (int)$date) : date('Y-m-d');
        DB::query(
            "UPDATE stock_master SET latest_price=?, latest_price_date=? WHERE id=?",
            [$price, $priceDate, $stock['id']]
        );
        // Recalculate all holdings for this stock
        $portfolios = DB::fetchAll("SELECT DISTINCT portfolio_id FROM stock_holdings WHERE stock_id=?", [$stock['id']]);
        foreach ($portfolios as $port) {
            DB::query(
                "UPDATE stock_holdings SET
                    current_value = ROUND(quantity * ?, 2),
                    gain_loss     = ROUND(quantity * ? - total_invested, 2),
                    gain_pct      = ROUND((quantity * ? - total_invested) / total_invested * 100, 2)
                 WHERE stock_id=? AND portfolio_id=? AND quantity>0",
                [$price, $price, $price, $stock['id'], $port['portfolio_id']]
            );
        }
        $updated++;
    } else {
        $failed++;
    }
    usleep(300000); // 300ms rate limit between calls
}

$msg = "Updated {$updated} stock(s).";
if ($failed) $msg .= " {$failed} failed (try again or check symbol).";
json_response(true, $msg, ['updated' => $updated, 'failed' => $failed]);

