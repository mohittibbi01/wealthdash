<?php
/**
 * WealthDash — Stock Fundamentals (t281, t431, t285, t433, t344)
 * P/E Ratio, P/B Ratio, Market Cap, 52-Week High/Low, EPS, Dividend Yield
 *
 * Actions:
 *   fundamentals        — fetch & cache fundamentals for one stock (symbol required)
 *   fundamentals_all    — batch refresh all stocks user holds
 *   holdings_enriched   — holdings + fundamentals + 52w position merged
 *   week52_tracker      — 52-week high/low dashboard
 *
 * Source: Yahoo Finance (unofficial, no API key needed)
 * Cache : stock_master table, refreshed if >24h stale
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();

$action = $_GET['action'] ?? $_POST['action'] ?? 'fundamentals_all';

/* ── Ensure extra columns exist (idempotent) ─────────────────────────── */
try {
    $db->exec("
        ALTER TABLE stock_master
            ADD COLUMN IF NOT EXISTS `pb_ratio`               DECIMAL(10,4) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `high_52`                DECIMAL(14,4) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `low_52`                 DECIMAL(14,4) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `eps`                    DECIMAL(12,4) DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `dividend_yield`         DECIMAL(8,4)  DEFAULT NULL,
            ADD COLUMN IF NOT EXISTS `fundamentals_updated_at` DATETIME      DEFAULT NULL
    ");
} catch (Throwable) { /* columns already exist */ }

/* ── Yahoo Finance helpers ──────────────────────────────────────────── */
function wd_yahoo_sym(string $symbol, string $exchange): string {
    return $symbol . ($exchange === 'BSE' ? '.BO' : '.NS');
}

function wd_fetch_fundamentals(string $symbol, string $exchange): ?array {
    $ySym = wd_yahoo_sym($symbol, $exchange);

    $ctx = stream_context_create([
        'http' => [
            'timeout'       => 8,
            'user_agent'    => 'Mozilla/5.0 (compatible; WealthDash/2.0)',
            'ignore_errors' => true,
            'header'        => "Accept: application/json\r\n",
        ]
    ]);

    $result = [];

    // — quoteSummary: P/E, P/B, EPS, Market Cap, Dividend Yield, 52w —
    $summaryUrl = "https://query1.finance.yahoo.com/v10/finance/quoteSummary/{$ySym}"
                . "?modules=summaryDetail,defaultKeyStatistics,price";
    $raw = @file_get_contents($summaryUrl, false, $ctx);
    if ($raw) {
        $data = json_decode($raw, true);
        $res  = $data['quoteSummary']['result'][0] ?? [];
        $sd   = $res['summaryDetail']        ?? [];
        $ks   = $res['defaultKeyStatistics'] ?? [];
        $pr   = $res['price']                ?? [];

        $result['pe_ratio']       = ((float)($sd['trailingPE']['raw']    ?? $ks['forwardPE']['raw'] ?? 0)) ?: null;
        $result['pb_ratio']       = ((float)($ks['priceToBook']['raw']   ?? 0)) ?: null;
        $result['eps']            = ((float)($ks['trailingEps']['raw']   ?? 0)) ?: null;
        $result['market_cap']     = ((float)($pr['marketCap']['raw']     ?? $sd['marketCap']['raw'] ?? 0)) ?: null;
        $result['dividend_yield'] = ((float)($sd['dividendYield']['raw'] ?? 0)) ?: null;
        $result['high_52']        = ((float)($sd['fiftyTwoWeekHigh']['raw'] ?? 0)) ?: null;
        $result['low_52']         = ((float)($sd['fiftyTwoWeekLow']['raw']  ?? 0)) ?: null;
    }

    // — 1-year chart: fallback 52w high/low from actual price data —
    if (!$result['high_52']) {
        $chartUrl = "https://query1.finance.yahoo.com/v8/finance/chart/{$ySym}?interval=1d&range=1y";
        $chartRaw = @file_get_contents($chartUrl, false, $ctx);
        if ($chartRaw) {
            $cd     = json_decode($chartRaw, true);
            $meta   = $cd['chart']['result'][0]['meta'] ?? [];
            $result['high_52'] = ((float)($meta['fiftyTwoWeekHigh'] ?? 0)) ?: null;
            $result['low_52']  = ((float)($meta['fiftyTwoWeekLow']  ?? 0)) ?: null;

            if (!$result['high_52']) {
                $closes = array_filter(
                    $cd['chart']['result'][0]['indicators']['quote'][0]['close'] ?? [],
                    fn($v) => $v !== null && $v > 0
                );
                if ($closes) {
                    $result['high_52'] = round(max($closes), 2);
                    $result['low_52']  = round(min($closes), 2);
                }
            }
        }
    }

    return $result ?: null;
}

function wd_save_fundamentals(int $stockId, array $f): void {
    $cols   = ['pe_ratio','pb_ratio','eps','market_cap','dividend_yield','high_52','low_52'];
    $set    = [];
    $params = [];
    foreach ($cols as $col) {
        if (array_key_exists($col, $f)) {
            $set[]    = "`{$col}` = ?";
            $params[] = $f[$col];
        }
    }
    if (!$set) return;
    $set[]    = '`fundamentals_updated_at` = NOW()';
    $params[] = $stockId;
    DB::run("UPDATE stock_master SET " . implode(', ', $set) . " WHERE id = ?", $params);

    // — t281: Persist daily snapshot to stock_fundamental_history —
    $sm = DB::fetchOne("SELECT symbol, latest_price, current_price FROM stock_master WHERE id = ?", [$stockId]);
    if ($sm) {
        DB::run(
            "INSERT INTO stock_fundamental_history
                (stock_id, symbol, snapshot_date, pe_ratio, pb_ratio, eps,
                 market_cap, dividend_yield, high_52, low_52, price_on_date, source)
             VALUES (?,?,CURDATE(),?,?,?,?,?,?,?,?,'yahoo')
             ON DUPLICATE KEY UPDATE
                pe_ratio       = VALUES(pe_ratio),
                pb_ratio       = VALUES(pb_ratio),
                eps            = VALUES(eps),
                market_cap     = VALUES(market_cap),
                dividend_yield = VALUES(dividend_yield),
                high_52        = VALUES(high_52),
                low_52         = VALUES(low_52),
                price_on_date  = VALUES(price_on_date)",
            [
                $stockId,
                $sm['symbol'],
                $f['pe_ratio']       ?? null,
                $f['pb_ratio']       ?? null,
                $f['eps']            ?? null,
                $f['market_cap']     ?? null,
                $f['dividend_yield'] ?? null,
                $f['high_52']        ?? null,
                $f['low_52']         ?? null,
                $sm['latest_price'] ?? $sm['current_price'] ?? null,
            ]
        );
    }
}

/* ────────────────────────── ACTIONS ───────────────────────────────── */

/* Single stock fundamentals */
if ($action === 'fundamentals') {
    $symbol   = strtoupper(clean($_GET['symbol']   ?? $_POST['symbol']   ?? ''));
    $exchange = strtoupper(clean($_GET['exchange']  ?? $_POST['exchange'] ?? 'NSE'));
    $stockId  = (int)($_GET['stock_id'] ?? $_POST['stock_id'] ?? 0);

    if (!$symbol) json_response(false, 'symbol required');

    if (!$stockId) {
        $sm      = DB::fetchOne("SELECT id FROM stock_master WHERE symbol=? AND exchange=? LIMIT 1", [$symbol, $exchange]);
        $stockId = (int)($sm['id'] ?? 0);
    }

    // Return cache if fresh (<24h)
    if ($stockId) {
        $row = DB::fetchOne(
            "SELECT pe_ratio,pb_ratio,eps,market_cap,dividend_yield,high_52,low_52,
                    fundamentals_updated_at,latest_price,symbol,company_name,sector
             FROM stock_master WHERE id=?", [$stockId]
        );
        if ($row && $row['fundamentals_updated_at'] && (time()-strtotime($row['fundamentals_updated_at'])) < 86400) {
            json_response(true, 'cached', $row);
        }
    }

    $f = wd_fetch_fundamentals($symbol, $exchange);
    if ($f) {
        if ($stockId) wd_save_fundamentals($stockId, $f);
        json_response(true, 'fetched', array_merge(['symbol'=>$symbol], $f));
    }
    json_response(false, "Could not fetch fundamentals for {$symbol}");
}

/* Batch: all user's stocks */
if ($action === 'fundamentals_all') {
    $stocks = DB::fetchAll(
        "SELECT DISTINCT sm.id, sm.symbol, sm.exchange, sm.company_name, sm.sector,
                sm.latest_price, sm.pe_ratio, sm.pb_ratio, sm.eps,
                sm.market_cap, sm.dividend_yield, sm.high_52, sm.low_52,
                sm.fundamentals_updated_at
         FROM stock_master sm
         JOIN stock_holdings sh ON sh.stock_id=sm.id
         JOIN portfolios p      ON p.id=sh.portfolio_id
         WHERE p.user_id=? AND sh.quantity>0 ORDER BY sm.symbol",
        [$userId]
    );

    $refreshed = 0;
    foreach ($stocks as &$sm) {
        $stale = !$sm['fundamentals_updated_at'] || (time()-strtotime($sm['fundamentals_updated_at'])) > 86400;
        if ($stale) {
            $f = wd_fetch_fundamentals($sm['symbol'], $sm['exchange']);
            if ($f) { wd_save_fundamentals((int)$sm['id'], $f); $sm=array_merge($sm,$f); $refreshed++; }
            usleep(300000); // 300ms rate-limit
        }
    }
    unset($sm);
    json_response(true, "Fundamentals fetched ({$refreshed} refreshed)", $stocks);
}

/* Holdings with fundamentals + 52w position */
if ($action === 'holdings_enriched') {
    $holdings = DB::fetchAll(
        "SELECT h.*, sm.symbol, sm.company_name, sm.exchange, sm.sector,
                sm.latest_price AS current_price,
                sm.pe_ratio, sm.pb_ratio, sm.eps, sm.market_cap,
                sm.dividend_yield, sm.high_52, sm.low_52,
                ROUND((sm.latest_price-h.avg_buy_price)/NULLIF(h.avg_buy_price,0)*100, 2) AS gain_pct,
                CASE WHEN h.first_purchase_date IS NOT NULL
                          AND h.avg_buy_price > 0
                          AND DATEDIFF(CURDATE(),h.first_purchase_date)>30
                     THEN ROUND((POW(sm.latest_price/NULLIF(h.avg_buy_price,0),
                                     365.0/GREATEST(DATEDIFF(CURDATE(),h.first_purchase_date),1))-1)*100, 2)
                     ELSE NULL END AS cagr,
                ROUND(DATEDIFF(CURDATE(),h.first_purchase_date)/365.0,1) AS years_held,
                CASE WHEN (sm.high_52-sm.low_52)>0
                     THEN ROUND((sm.latest_price-sm.low_52)/(sm.high_52-sm.low_52)*100,1)
                     ELSE NULL END AS pct_from_52w_low
         FROM stock_holdings h
         JOIN portfolios p    ON p.id=h.portfolio_id
         JOIN stock_master sm ON sm.id=h.stock_id
         WHERE p.user_id=? AND h.quantity>0
         ORDER BY h.current_value DESC",
        [$userId]
    );
    json_response(true, '', $holdings);
}

/* t281: Historical fundamentals for a stock (P/E trend etc.) */
if ($action === 'fundamentals_history') {
    $symbol   = strtoupper(clean($_GET['symbol']   ?? $_POST['symbol']   ?? ''));
    $stockId  = (int)($_GET['stock_id'] ?? $_POST['stock_id'] ?? 0);
    $months   = min((int)($_GET['months'] ?? 12), 36); // max 36 months

    if (!$symbol && !$stockId) json_response(false, 'symbol or stock_id required');

    if (!$stockId && $symbol) {
        $sm      = DB::fetchOne("SELECT id FROM stock_master WHERE symbol = ? AND exchange IN ('NSE','BSE') LIMIT 1", [$symbol]);
        $stockId = (int)($sm['id'] ?? 0);
    }
    if (!$stockId) json_response(false, "Stock not found: {$symbol}");

    $history = DB::fetchAll(
        "SELECT snapshot_date, pe_ratio, pb_ratio, eps, market_cap,
                dividend_yield, high_52, low_52, price_on_date, source
         FROM stock_fundamental_history
         WHERE stock_id = ?
           AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
         ORDER BY snapshot_date ASC",
        [$stockId, $months]
    );

    $current = DB::fetchOne(
        "SELECT symbol, company_name, sector, pe_ratio, pb_ratio, eps,
                market_cap, dividend_yield, high_52, low_52, latest_price,
                fundamentals_updated_at
         FROM stock_master WHERE id = ?",
        [$stockId]
    );

    json_response(true, count($history) . ' history records.', [
        'current' => $current,
        'history' => $history,
    ]);
}

/* 52-week high/low tracker */
if ($action === 'week52_tracker') {
    $stocks = DB::fetchAll(
        "SELECT sm.symbol, sm.company_name, sm.sector, sm.latest_price,
                sm.high_52, sm.low_52,
                CASE WHEN sm.high_52>0 THEN ROUND((sm.high_52-sm.latest_price)/sm.high_52*100,1) ELSE NULL END AS pct_below_52h,
                CASE WHEN sm.low_52>0  THEN ROUND((sm.latest_price-sm.low_52)/sm.low_52*100,1)   ELSE NULL END AS pct_above_52l,
                sh.quantity, h_calc.gain_loss
         FROM stock_master sm
         JOIN stock_holdings sh  ON sh.stock_id=sm.id
         JOIN portfolios p       ON p.id=sh.portfolio_id
         LEFT JOIN stock_holdings h_calc ON h_calc.stock_id=sm.id AND h_calc.portfolio_id=p.id
         WHERE p.user_id=? AND sh.quantity>0 AND sm.high_52 IS NOT NULL
         GROUP BY sm.id
         ORDER BY pct_below_52h ASC",
        [$userId]
    );
    foreach ($stocks as &$s) {
        $s['near_52h'] = ($s['pct_below_52h'] !== null) && ((float)$s['pct_below_52h'] <= 5);
        $s['near_52l'] = ($s['pct_above_52l'] !== null) && ((float)$s['pct_above_52l'] <= 10);
        $s['signal']   = $s['near_52h'] ? 'near_high' : ($s['near_52l'] ? 'near_low' : 'neutral');
    }
    unset($s);
    json_response(true, '', $stocks);
}


/* ── t431: Extended fundamentals — ROE, ROCE, D/E, promoter holding ────── */
if ($action === 'stocks_fundamentals') {
    $stockId  = (int)($_GET['stock_id'] ?? $_POST['stock_id'] ?? 0);
    $symbol   = strtoupper(clean($_GET['symbol'] ?? $_POST['symbol'] ?? ''));
    $exchange = strtoupper(clean($_GET['exchange'] ?? 'NSE'));
    if (!$stockId && $symbol) {
        $sm      = DB::fetchOne("SELECT id FROM stock_master WHERE symbol=? AND exchange=? LIMIT 1", [$symbol, $exchange]);
        $stockId = (int)($sm['id'] ?? 0);
    }
    if (!$stockId) { json_response(false, 'stock_id or symbol required'); }

    $row = DB::fetchOne("SELECT * FROM stock_master WHERE id=?", [$stockId]);
    if (!$row) { json_response(false, 'Stock not found.'); }

    // Check cache
    $stale = !$row['fundamentals_updated_at'] || (time() - strtotime($row['fundamentals_updated_at'])) > 86400;
    if (!$stale) { json_response(true, 'cached', $row); }

    // Fetch from Yahoo
    $f = wd_fetch_fundamentals($row['symbol'], $row['exchange']);

    // Also try to get ROE, ROCE from Yahoo financialData module
    $ySym = wd_yahoo_sym($row['symbol'], $row['exchange']);
    $ctx  = stream_context_create(['http' => ['timeout'=>8,'user_agent'=>'Mozilla/5.0','ignore_errors'=>true]]);
    $fdUrl = "https://query1.finance.yahoo.com/v10/finance/quoteSummary/{$ySym}?modules=financialData,defaultKeyStatistics,assetProfile,summaryDetail";
    $fdRaw = @file_get_contents($fdUrl, false, $ctx);
    if ($fdRaw) {
        $fdData  = json_decode($fdRaw, true);
        $fdRes   = $fdData['quoteSummary']['result'][0] ?? [];
        $fin     = $fdRes['financialData']        ?? [];
        $ks      = $fdRes['defaultKeyStatistics']  ?? [];
        $profile = $fdRes['assetProfile']          ?? [];
        $sd      = $fdRes['summaryDetail']          ?? [];

        $f['roe']                  = ((float)($fin['returnOnEquity']['raw'] ?? 0)) * 100 ?: null;
        $f['roa']                  = ((float)($fin['returnOnAssets']['raw'] ?? 0)) * 100 ?: null;
        $f['debt_to_equity']       = ((float)($fin['debtToEquity']['raw']   ?? 0)) ?: null;
        $f['current_ratio']        = ((float)($fin['currentRatio']['raw']   ?? 0)) ?: null;
        $f['quick_ratio']          = ((float)($fin['quickRatio']['raw']     ?? 0)) ?: null;
        $f['revenue_ttm']          = ((float)($fin['totalRevenue']['raw']   ?? 0)) ?: null;
        $f['net_profit_ttm']       = ((float)($fin['netIncomeToCommon']['raw'] ?? 0)) ?: null;
        $f['ps_ratio']             = ((float)($ks['priceToSalesTrailing12Months']['raw'] ?? 0)) ?: null;
        $f['ev_ebitda']            = ((float)($ks['enterpriseToEbitda']['raw'] ?? 0)) ?: null;
        $f['enterprise_value']     = ((float)($ks['enterpriseValue']['raw'] ?? 0)) ?: null;
        $f['book_value']           = ((float)($ks['bookValue']['raw'] ?? 0)) ?: null;
        $f['shares_outstanding']   = isset($ks['sharesOutstanding']['raw']) ? (int)$ks['sharesOutstanding']['raw'] : null;
        $f['beta']                 = ((float)($sd['beta']['raw'] ?? 0)) ?: null;
        $f['sector']               = $profile['sector']   ?? null;
        $f['industry']             = $profile['industry'] ?? null;
        $f['fundamentals_source']  = 'yahoo';
    }

    if ($f && $stockId) {
        // Extended save
        $extCols = ['pe_ratio','pb_ratio','ps_ratio','ev_ebitda','eps','market_cap','enterprise_value',
                    'dividend_yield','high_52','low_52','roe','roa','debt_to_equity','current_ratio',
                    'quick_ratio','revenue_ttm','net_profit_ttm','beta','sector','industry',
                    'book_value','shares_outstanding','fundamentals_source'];
        $sets = $params = [];
        foreach ($extCols as $col) {
            if (array_key_exists($col, $f)) { $sets[] = "`{$col}`=?"; $params[] = $f[$col]; }
        }
        $sets[] = '`fundamentals_updated_at`=NOW()';
        $params[] = $stockId;
        DB::run("UPDATE stock_master SET " . implode(',', $sets) . " WHERE id=?", $params);

        // Save snapshot to history
        DB::run("INSERT INTO stock_fundamentals_history
            (stock_id, period, period_type, pe_ratio, pb_ratio, market_cap, eps, revenue, net_profit, roe, debt_to_equity, source, fetched_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,'yahoo',NOW())
            ON DUPLICATE KEY UPDATE pe_ratio=VALUES(pe_ratio), roe=VALUES(roe), fetched_at=NOW()",
            [$stockId, date('Q').'FY'.substr(date('Y'),2), 'quarterly',
             $f['pe_ratio']??null, $f['pb_ratio']??null, $f['market_cap']??null,
             $f['eps_ttm']??$f['eps']??null, $f['revenue_ttm']??null,
             $f['net_profit_ttm']??null, $f['roe']??null, $f['debt_to_equity']??null]);
    }

    $updated = DB::fetchOne("SELECT * FROM stock_master WHERE id=?", [$stockId]);
    json_response(true, 'fetched', $updated);
}

/* t431: Fundamentals history for a stock (quarterly snapshots) */
if ($action === 'fundamentals_history') {
    $stockId = (int)($_GET['stock_id'] ?? 0);
    $periods = min((int)($_GET['periods'] ?? 8), 20);
    if (!$stockId) { json_response(false, 'stock_id required'); }
    $rows = DB::fetchAll("SELECT * FROM stock_fundamentals_history WHERE stock_id=? ORDER BY fetched_at DESC LIMIT ?", [$stockId, $periods]);
    $current = DB::fetchOne("SELECT * FROM stock_master WHERE id=?", [$stockId]);
    json_response(true, '', ['current' => $current, 'history' => $rows]);
}

/* t431: Compare fundamentals across user's holdings */
if ($action === 'fundamentals_compare') {
    $holdings = DB::fetchAll("
        SELECT DISTINCT sm.*,
               sh.quantity, sh.avg_buy_price,
               ROUND((sm.latest_price - sh.avg_buy_price) / NULLIF(sh.avg_buy_price,0) * 100, 2) AS gain_pct
        FROM stock_master sm
        JOIN stock_holdings sh ON sh.stock_id=sm.id
        JOIN portfolios p ON p.id=sh.portfolio_id
        WHERE p.user_id=? AND sh.quantity>0
          AND sm.pe_ratio IS NOT NULL
        ORDER BY sm.pe_ratio ASC
    ", [$userId]);
    json_response(true, '', ['data' => $holdings]);
}

/* t431: Stock screener against user's holdings */
if ($action === 'screener') {
    $peMax     = isset($_GET['pe_max'])    ? (float)$_GET['pe_max']    : null;
    $peMin     = isset($_GET['pe_min'])    ? (float)$_GET['pe_min']    : null;
    $pbMax     = isset($_GET['pb_max'])    ? (float)$_GET['pb_max']    : null;
    $roeMin    = isset($_GET['roe_min'])   ? (float)$_GET['roe_min']   : null;
    $deMax     = isset($_GET['de_max'])    ? (float)$_GET['de_max']    : null;
    $sector    = clean($_GET['sector']    ?? '');
    $dyMin     = isset($_GET['dy_min'])    ? (float)$_GET['dy_min']    : null;
    $mcMin     = isset($_GET['mc_min'])    ? (float)$_GET['mc_min']    : null;

    $where  = ["sh.quantity > 0", "p.user_id = ?"];
    $params = [$userId];

    if ($peMax  !== null) { $where[] = "sm.pe_ratio <= ?";    $params[] = $peMax;  }
    if ($peMin  !== null) { $where[] = "sm.pe_ratio >= ?";    $params[] = $peMin;  }
    if ($pbMax  !== null) { $where[] = "sm.pb_ratio <= ?";    $params[] = $pbMax;  }
    if ($roeMin !== null) { $where[] = "sm.roe >= ?";         $params[] = $roeMin; }
    if ($deMax  !== null) { $where[] = "sm.debt_to_equity <= ?"; $params[] = $deMax; }
    if ($dyMin  !== null) { $where[] = "sm.dividend_yield >= ?"; $params[] = $dyMin; }
    if ($mcMin  !== null) { $where[] = "sm.market_cap >= ?";  $params[] = $mcMin;  }
    if ($sector)          { $where[] = "sm.sector = ?";       $params[] = $sector; }

    $rows = DB::fetchAll("
        SELECT sm.symbol, sm.company_name, sm.sector, sm.industry,
               sm.latest_price, sm.pe_ratio, sm.pb_ratio, sm.roe, sm.roce,
               sm.debt_to_equity, sm.dividend_yield, sm.market_cap,
               sm.high_52, sm.low_52, sh.quantity,
               ROUND((sm.latest_price - sh.avg_buy_price) / NULLIF(sh.avg_buy_price,0)*100, 2) AS gain_pct
        FROM stock_master sm
        JOIN stock_holdings sh ON sh.stock_id=sm.id
        JOIN portfolios p ON p.id=sh.portfolio_id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY sm.id
        ORDER BY sm.pe_ratio ASC
        LIMIT 100
    ", $params);

    json_response(true, '', ['data' => $rows, 'count' => count($rows)]);
}

json_response(false, 'Unknown action');
