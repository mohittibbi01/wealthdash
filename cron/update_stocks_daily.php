<?php
/**
 * WealthDash — Daily Stock Price Update Cron
 * Runs at: 6:30 PM IST on market days (Mon–Fri)
 * Command: php /path/to/wealthdash/cron/update_stocks_daily.php
 */
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';

$startTime = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] Starting stock price update...\n";

$stocks = DB::fetchAll(
    "SELECT DISTINCT sm.id, sm.symbol, sm.exchange
     FROM stock_master sm
     JOIN stock_holdings sh ON sh.stock_id = sm.id
     WHERE sh.quantity > 0"
);

if (empty($stocks)) { echo "No active stock holdings. Exiting.\n"; exit(0); }

echo "Updating " . count($stocks) . " stocks...\n";

$updated = 0; $failed = 0;

foreach ($stocks as $stock) {
    $suffix  = $stock['exchange'] === 'BSE' ? '.BO' : '.NS';
    $url     = "https://query1.finance.yahoo.com/v8/finance/chart/{$stock['symbol']}{$suffix}?interval=1d&range=1d";
    $ctx     = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'Mozilla/5.0 WealthDash/1.0', 'header' => "Accept: application/json\r\n"]]);
    $raw     = @file_get_contents($url, false, $ctx);

    if (!$raw) {
        echo "  ✗ {$stock['symbol']}: fetch failed\n";
        $failed++; sleep(1); continue;
    }

    $json  = json_decode($raw, true);
    $price = $json['chart']['result'][0]['meta']['regularMarketPrice'] ?? null;
    $ts    = $json['chart']['result'][0]['meta']['regularMarketTime']  ?? null;

    if ($price && $price > 0) {
        $priceDate = $ts ? date('Y-m-d', (int)$ts) : date('Y-m-d');
        DB::query("UPDATE stock_master SET latest_price=?, latest_price_date=? WHERE id=?", [$price, $priceDate, $stock['id']]);
        // Update all portfolio holdings
        $ports = DB::fetchAll("SELECT DISTINCT portfolio_id FROM stock_holdings WHERE stock_id=?", [$stock['id']]);
        foreach ($ports as $port) {
            DB::query(
                "UPDATE stock_holdings SET current_value=ROUND(quantity*?,2), gain_loss=ROUND(quantity*?-total_invested,2), gain_pct=ROUND((quantity*?-total_invested)/total_invested*100,2) WHERE stock_id=? AND portfolio_id=? AND quantity>0",
                [$price, $price, $price, $stock['id'], $port['portfolio_id']]
            );
        }
        echo "  ✓ {$stock['symbol']}: ₹{$price}\n";
        $updated++;
    } else {
        echo "  ✗ {$stock['symbol']}: no price in response\n";
        $failed++;
    }
    usleep(500000); // 0.5s between requests
}

$elapsed = round(microtime(true) - $startTime, 2);
echo "\n[" . date('Y-m-d H:i:s') . "] Done. Updated: {$updated}, Failed: {$failed}. Time: {$elapsed}s\n";

