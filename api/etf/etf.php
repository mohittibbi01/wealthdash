<?php
/**
 * WealthDash — t37: ETF — Separate Module
 * Actions: etf_list, etf_add, etf_edit, etf_delete,
 *          etf_txn_add, etf_txns, etf_summary,
 *          etf_prices_refresh, etf_search,
 *          etf_sip_list, etf_sip_add, etf_sip_edit, etf_sip_delete,
 *          etf_compare, etf_tax_report
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();
$action      = clean($_GET['action'] ?? $_POST['action'] ?? '');

// ── Helpers ───────────────────────────────────────────────────────────────────

function etf_portfolio(int $userId): ?int
{
    $pid = (int)($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ?? 0);
    if ($pid && can_access_portfolio($pid, $userId, is_admin())) return $pid;
    return get_user_portfolio_id($userId) ?: null;
}

/**
 * Fetch ETF price from Yahoo Finance (NSE symbol → symbol.NS).
 */
function etf_fetch_price(string $symbol, string $exchange = 'NSE'): ?array
{
    $suffix = match (strtoupper($exchange)) {
        'NSE'   => '.NS',
        'BSE'   => '.BO',
        default => '.NS',
    };
    $ySym = $symbol . $suffix;
    $ctx  = stream_context_create(['http' => [
        'timeout'       => 8,
        'user_agent'    => 'Mozilla/5.0 (compatible; WealthDash/2.0)',
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents(
        "https://query1.finance.yahoo.com/v8/finance/chart/{$ySym}?interval=1d&range=1d",
        false, $ctx
    );
    if (!$raw) return null;

    $data = json_decode($raw, true);
    $meta = $data['chart']['result'][0]['meta'] ?? null;
    if (!$meta) return null;

    $price     = (float)($meta['regularMarketPrice'] ?? 0);
    $prevClose = (float)($meta['chartPreviousClose'] ?? 0);
    $chgPct    = $prevClose > 0 ? round(($price - $prevClose) / $prevClose * 100, 4) : 0;

    return [
        'price'      => $price,
        'prev_close' => $prevClose,
        'change_pct' => $chgPct,
        'high_52'    => (float)($meta['fiftyTwoWeekHigh'] ?? 0) ?: null,
        'low_52'     => (float)($meta['fiftyTwoWeekLow']  ?? 0) ?: null,
        'short_name' => $meta['shortName'] ?? $symbol,
    ];
}

/**
 * Resolve or create etf_master record. Returns etf_id.
 */
function etf_resolve(string $symbol, string $exchange, string $schemeName = '', array $extra = []): int
{
    $symbol   = strtoupper(trim($symbol));
    $exchange = strtoupper(trim($exchange));

    $existing = DB::fetchOne("SELECT id FROM etf_master WHERE symbol=? AND exchange=?", [$symbol, $exchange]);
    if ($existing) return (int)$existing['id'];

    // Auto-fetch price
    $priceData = etf_fetch_price($symbol, $exchange);
    $price     = $priceData ? $priceData['price'] : 0;

    DB::run("
        INSERT INTO etf_master
        (symbol, exchange, scheme_name, amc, category, sub_category, underlying_index,
         expense_ratio, latest_price, high_52, low_52, price_updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())
    ", [
        $symbol, $exchange,
        $schemeName ?: $extra['scheme_name'] ?? $symbol,
        $extra['amc']              ?? null,
        $extra['category']         ?? null,
        $extra['sub_category']     ?? null,
        $extra['underlying_index'] ?? null,
        $extra['expense_ratio']    ?? null,
        $price,
        $priceData['high_52'] ?? null,
        $priceData['low_52']  ?? null,
    ]);

    return (int)DB::conn()->lastInsertId();
}

// ── Routes ────────────────────────────────────────────────────────────────────
switch ($action) {

    // ─── LIST HOLDINGS ────────────────────────────────────────────────────────
    case 'etf_list':
        $pid = etf_portfolio($userId);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $rows = DB::fetchAll("
            SELECT h.*,
                   m.symbol, m.scheme_name, m.amc, m.category, m.sub_category,
                   m.underlying_index, m.expense_ratio, m.latest_price,
                   m.price_change_1d_pct, m.high_52, m.low_52,
                   ROUND(h.quantity * m.latest_price, 2)              AS current_value,
                   ROUND(h.quantity * m.latest_price - h.total_invested, 2) AS gain_loss,
                   ROUND(CASE WHEN h.total_invested > 0
                         THEN ((h.quantity * m.latest_price - h.total_invested)
                               / h.total_invested) * 100
                         ELSE 0 END, 2)                                AS gain_pct,
                   ROUND(((m.latest_price - h.avg_buy_price) / NULLIF(h.avg_buy_price,0)) * 100, 2) AS price_return_pct
            FROM etf_holdings h
            JOIN etf_master m ON m.id = h.etf_id
            WHERE h.portfolio_id=?
              AND h.quantity > 0
            ORDER BY (h.quantity * m.latest_price) DESC
        ", [$pid]);

        json_response(true, '', ['data' => $rows]);


    // ─── SUMMARY ──────────────────────────────────────────────────────────────
    case 'etf_summary':
        $pid = etf_portfolio($userId);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $sum = DB::fetchOne("
            SELECT
                COUNT(DISTINCT h.etf_id)                          AS total_etfs,
                COALESCE(SUM(h.total_invested), 0)                AS total_invested,
                COALESCE(SUM(h.quantity * m.latest_price), 0)     AS total_current_value,
                COALESCE(SUM(h.quantity * m.latest_price
                             - h.total_invested), 0)              AS total_gain_loss
            FROM etf_holdings h
            JOIN etf_master m ON m.id = h.etf_id
            WHERE h.portfolio_id=? AND h.quantity > 0
        ", [$pid]);

        $gainPct = ($sum['total_invested'] ?? 0) > 0
            ? round(($sum['total_gain_loss'] ?? 0) / $sum['total_invested'] * 100, 2)
            : 0;

        // By category
        $byCategory = DB::fetchAll("
            SELECT m.category, COUNT(*) AS count,
                   SUM(h.quantity * m.latest_price) AS value
            FROM etf_holdings h
            JOIN etf_master m ON m.id = h.etf_id
            WHERE h.portfolio_id=? AND h.quantity > 0
            GROUP BY m.category
            ORDER BY value DESC
        ", [$pid]);

        // Active SIPs
        $activeSips = DB::fetchOne("
            SELECT COUNT(*) AS count,
                   COALESCE(SUM(monthly_amount), 0) AS monthly_total
            FROM etf_sip WHERE portfolio_id=? AND is_active=1
        ", [$pid]);

        json_response(true, '', [
            'summary'     => array_merge($sum, ['gain_pct' => $gainPct]),
            'by_category' => $byCategory,
            'active_sips' => $activeSips,
        ]);


    // ─── ADD HOLDING ──────────────────────────────────────────────────────────
    case 'etf_add':
        $pid        = etf_portfolio($userId);
        $symbol     = strtoupper(clean($_POST['symbol']     ?? ''));
        $exchange   = strtoupper(clean($_POST['exchange']   ?? 'NSE'));
        $qty        = (float)($_POST['quantity']            ?? 0);
        $price      = (float)($_POST['price']               ?? 0);
        $buyDate    = clean($_POST['buy_date']              ?? date('Y-m-d'));
        $broker     = clean($_POST['broker']                ?? '');
        $brokerage  = (float)($_POST['brokerage']           ?? 0);
        $notes      = clean($_POST['notes']                 ?? '');

        if (!$pid || !$symbol || $qty <= 0 || $price <= 0) {
            json_response(false, 'symbol, quantity, and price required.');
        }

        $etfId = etf_resolve($symbol, $exchange, clean($_POST['scheme_name'] ?? ''), [
            'amc'              => clean($_POST['amc']              ?? ''),
            'category'         => clean($_POST['category']         ?? ''),
            'sub_category'     => clean($_POST['sub_category']     ?? ''),
            'underlying_index' => clean($_POST['underlying_index'] ?? ''),
            'expense_ratio'    => isset($_POST['expense_ratio']) ? (float)$_POST['expense_ratio'] : null,
        ]);

        $totalInvested = round($qty * $price + $brokerage, 4);

        // Existing holding → weighted avg
        $existing = DB::fetchOne("SELECT * FROM etf_holdings WHERE portfolio_id=? AND etf_id=?", [$pid, $etfId]);
        if ($existing) {
            $newQty   = $existing['quantity'] + $qty;
            $newTotal = $existing['total_invested'] + $totalInvested;
            $newAvg   = $newQty > 0 ? $newTotal / $newQty : 0;
            DB::run("UPDATE etf_holdings SET quantity=?, avg_buy_price=?, total_invested=?, updated_at=NOW() WHERE id=?",
                [$newQty, round($newAvg, 4), $newTotal, $existing['id']]);
            $holdingId = $existing['id'];
        } else {
            DB::run("INSERT INTO etf_holdings (portfolio_id, etf_id, quantity, avg_buy_price, total_invested, first_buy_date, broker, notes)
                     VALUES (?,?,?,?,?,?,?,?)",
                [$pid, $etfId, $qty, $price, $totalInvested, $buyDate, $broker ?: null, $notes ?: null]);
            $holdingId = (int)$db->lastInsertId();
        }

        // Log transaction
        DB::run("INSERT INTO etf_transactions (portfolio_id, etf_id, type, quantity, price, total_value, brokerage, broker, txn_date, notes)
                 VALUES (?,?,'buy',?,?,?,?,?,?,?)",
            [$pid, $etfId, $qty, $price, $totalInvested, $brokerage, $broker ?: null, $buyDate, $notes ?: null]);

        json_response(true, 'ETF holding added.', ['holding_id' => $holdingId, 'etf_id' => $etfId]);


    // ─── EDIT HOLDING ─────────────────────────────────────────────────────────
    case 'etf_edit':
        $id  = (int)($_POST['id'] ?? 0);
        $pid = etf_portfolio($userId);
        if (!$id || !$pid) { json_response(false, 'Invalid request.'); }

        $allowed = ['quantity','avg_buy_price','total_invested','first_buy_date','broker','notes'];
        $sets = $params = [];
        foreach ($allowed as $f) {
            if (isset($_POST[$f])) { $sets[] = "`{$f}`=?"; $params[] = clean($_POST[$f]); }
        }
        if (!$sets) { json_response(false, 'Nothing to update.'); }
        $params[] = $id;
        DB::run("UPDATE etf_holdings SET " . implode(',', $sets) . ", updated_at=NOW() WHERE id=?", $params);
        json_response(true, 'Updated.');


    // ─── DELETE ───────────────────────────────────────────────────────────────
    case 'etf_delete':
        $id  = (int)($_POST['id'] ?? 0);
        $pid = etf_portfolio($userId);
        if (!$id || !$pid) { json_response(false, 'Invalid request.'); }
        DB::run("DELETE FROM etf_holdings WHERE id=? AND portfolio_id=?", [$id, $pid]);
        json_response(true, 'Holding removed.');


    // ─── ADD TRANSACTION ──────────────────────────────────────────────────────
    case 'etf_txn_add':
        $pid   = etf_portfolio($userId);
        $etfId = (int)($_POST['etf_id'] ?? 0);
        $type  = in_array($_POST['type'] ?? '', ['buy','sell','dividend']) ? $_POST['type'] : 'buy';
        $qty   = (float)($_POST['quantity'] ?? 0);
        $price = (float)($_POST['price']    ?? 0);
        $txnDate = clean($_POST['txn_date'] ?? date('Y-m-d'));

        if (!$pid || !$etfId || $qty <= 0 || $price <= 0) {
            json_response(false, 'etf_id, quantity, and price required.');
        }

        $total     = round($qty * $price, 4);
        $brokerage = (float)($_POST['brokerage'] ?? 0);

        DB::run("INSERT INTO etf_transactions (portfolio_id, etf_id, type, quantity, price, total_value, brokerage, broker, txn_date, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?)",
            [$pid, $etfId, $type, $qty, $price, $total + $brokerage, $brokerage,
             clean($_POST['broker'] ?? ''), $txnDate, clean($_POST['notes'] ?? '')]);

        // Update holding for sell
        if ($type === 'sell') {
            $h = DB::fetchOne("SELECT * FROM etf_holdings WHERE portfolio_id=? AND etf_id=?", [$pid, $etfId]);
            if ($h) {
                $newQty = max(0, $h['quantity'] - $qty);
                DB::run("UPDATE etf_holdings SET quantity=?, updated_at=NOW() WHERE id=?", [$newQty, $h['id']]);
            }
        }

        json_response(true, 'Transaction logged.');


    // ─── TRANSACTIONS LIST ────────────────────────────────────────────────────
    case 'etf_txns':
        $pid   = etf_portfolio($userId);
        $etfId = (int)($_GET['etf_id'] ?? 0);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $where  = ['t.portfolio_id=?'];
        $params = [$pid];
        if ($etfId) { $where[] = 't.etf_id=?'; $params[] = $etfId; }

        $rows = DB::fetchAll("
            SELECT t.*, m.symbol, m.scheme_name, m.category
            FROM etf_transactions t
            JOIN etf_master m ON m.id = t.etf_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY t.txn_date DESC, t.id DESC LIMIT 200
        ", $params);

        json_response(true, '', ['data' => $rows]);


    // ─── REFRESH PRICES ───────────────────────────────────────────────────────
    case 'etf_prices_refresh':
        $pid = etf_portfolio($userId);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $etfs = DB::fetchAll("
            SELECT DISTINCT m.id, m.symbol, m.exchange
            FROM etf_holdings h
            JOIN etf_master m ON m.id = h.etf_id
            WHERE h.portfolio_id=? AND h.quantity>0
        ", [$pid]);

        $updated = 0;
        foreach ($etfs as $e) {
            $data = etf_fetch_price($e['symbol'], $e['exchange']);
            if (!$data || $data['price'] <= 0) continue;

            DB::run("UPDATE etf_master SET latest_price=?, price_change_1d_pct=?,
                     high_52=COALESCE(?,high_52), low_52=COALESCE(?,low_52), price_updated_at=NOW()
                     WHERE id=?",
                [$data['price'], $data['change_pct'], $data['high_52'], $data['low_52'], $e['id']]);
            $updated++;
            usleep(150000);
        }

        // Update current_value in holdings
        DB::run("
            UPDATE etf_holdings h
            JOIN etf_master m ON m.id = h.etf_id
            SET h.current_value = ROUND(h.quantity * m.latest_price, 2)
            WHERE h.portfolio_id=?
        ", [$pid]);

        json_response(true, "Prices updated: {$updated} ETFs.", ['updated' => $updated]);


    // ─── SEARCH ───────────────────────────────────────────────────────────────
    case 'etf_search':
        $q = clean($_GET['q'] ?? '');
        if (strlen($q) < 1) { json_response(false, 'Query required.'); }

        // Local cache
        $local = DB::fetchAll("
            SELECT id, symbol, exchange, scheme_name, amc, category, latest_price, expense_ratio
            FROM etf_master WHERE symbol LIKE ? OR scheme_name LIKE ? LIMIT 10
        ", ["{$q}%", "%{$q}%"]);

        // Yahoo search for NSE ETFs
        $ctx  = stream_context_create(['http' => ['timeout' => 6, 'user_agent' => 'Mozilla/5.0', 'ignore_errors' => true]]);
        $raw  = @file_get_contents("https://query2.finance.yahoo.com/v1/finance/search?q=" . urlencode($q) . "+ETF+NSE&lang=en-IN&region=IN&quotesCount=8", false, $ctx);
        $yRes = [];
        if ($raw) {
            $data = json_decode($raw, true);
            $yRes = array_filter($data['quotes'] ?? [], fn($c) => str_ends_with($c['symbol'] ?? '', '.NS') && ($c['typeDisp'] ?? '') === 'ETF');
            $yRes = array_values(array_slice($yRes, 0, 8));
        }

        // Known popular ETFs list
        $popular = [
            ['symbol'=>'NIFTYBEES',  'scheme_name'=>'Nippon India ETF Nifty BeES',       'category'=>'Equity','underlying_index'=>'Nifty 50'],
            ['symbol'=>'JUNIORBEES', 'scheme_name'=>'Nippon India ETF Junior BeES',       'category'=>'Equity','underlying_index'=>'Nifty Next 50'],
            ['symbol'=>'BANKBEES',   'scheme_name'=>'Nippon India ETF Bank BeES',         'category'=>'Equity','underlying_index'=>'Nifty Bank'],
            ['symbol'=>'GOLDBEES',   'scheme_name'=>'Nippon India ETF Gold BeES',         'category'=>'Gold',  'underlying_index'=>'Domestic Gold Price'],
            ['symbol'=>'LIQUIDBEES', 'scheme_name'=>'Nippon India ETF Liquid BeES',       'category'=>'Debt',  'underlying_index'=>'CRISIL Liquid Fund Index'],
            ['symbol'=>'NETFIT',     'scheme_name'=>'Nippon India ETF Nifty IT',          'category'=>'Equity','underlying_index'=>'Nifty IT'],
            ['symbol'=>'ITBEES',     'scheme_name'=>'Nippon India ETF Infra BeES',        'category'=>'Equity','underlying_index'=>'Nifty Infrastructure'],
            ['symbol'=>'HNGSNGBEES', 'scheme_name'=>'Nippon India ETF Hang Seng BeES',   'category'=>'International','underlying_index'=>'Hang Seng Index'],
            ['symbol'=>'MOM100',     'scheme_name'=>'Motilal Oswal Nifty Momentum 100',  'category'=>'Equity','underlying_index'=>'Nifty Momentum 100'],
            ['symbol'=>'CPSEETF',    'scheme_name'=>'CPSE ETF',                           'category'=>'Equity','underlying_index'=>'Nifty CPSE Index'],
            ['symbol'=>'KOTAKGOLD',  'scheme_name'=>'Kotak Gold ETF',                     'category'=>'Gold',  'underlying_index'=>'Domestic Gold Price'],
            ['symbol'=>'SILVERBEES', 'scheme_name'=>'Nippon India ETF Silver BeES',      'category'=>'Commodity','underlying_index'=>'Silver Price'],
            ['symbol'=>'MAFANG',     'scheme_name'=>'Mirae Asset NYSE FANG+ ETF',        'category'=>'International','underlying_index'=>'NYSE FANG+ Index'],
            ['symbol'=>'NIFTYIETF',  'scheme_name'=>'ICICI Prudential Nifty ETF',        'category'=>'Equity','underlying_index'=>'Nifty 50'],
            ['symbol'=>'BSLNIFTY',   'scheme_name'=>'Aditya Birla Sun Life Nifty ETF',   'category'=>'Equity','underlying_index'=>'Nifty 50'],
        ];

        $filtered = array_filter($popular, fn($p) =>
            str_contains(strtolower($p['symbol']), strtolower($q)) ||
            str_contains(strtolower($p['scheme_name']), strtolower($q))
        );

        json_response(true, '', [
            'local'   => $local,
            'yahoo'   => $yRes,
            'popular' => array_values($filtered),
        ]);


    // ─── SIP LIST ─────────────────────────────────────────────────────────────
    case 'etf_sip_list':
        $pid = etf_portfolio($userId);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $rows = DB::fetchAll("
            SELECT s.*, m.symbol, m.scheme_name, m.category, m.latest_price
            FROM etf_sip s
            JOIN etf_master m ON m.id = s.etf_id
            WHERE s.portfolio_id=?
            ORDER BY s.is_active DESC, s.start_date DESC
        ", [$pid]);

        json_response(true, '', ['data' => $rows]);


    // ─── SIP ADD ──────────────────────────────────────────────────────────────
    case 'etf_sip_add':
        $pid    = etf_portfolio($userId);
        $etfId  = (int)($_POST['etf_id']        ?? 0);
        $amount = (float)($_POST['monthly_amount']?? 0);
        $sipDay = min(28, max(1, (int)($_POST['sip_date'] ?? 1)));
        $start  = clean($_POST['start_date']     ?? date('Y-m-d'));
        $broker = clean($_POST['broker']          ?? '');
        $notes  = clean($_POST['notes']           ?? '');

        if (!$pid || !$etfId || $amount <= 0) {
            json_response(false, 'etf_id and monthly_amount required.');
        }

        DB::run("INSERT INTO etf_sip (portfolio_id, etf_id, monthly_amount, sip_date, start_date, broker, notes)
                 VALUES (?,?,?,?,?,?,?)",
            [$pid, $etfId, $amount, $sipDay, $start, $broker ?: null, $notes ?: null]);

        json_response(true, 'ETF SIP created.', ['id' => (int)$db->lastInsertId()]);


    // ─── SIP EDIT ─────────────────────────────────────────────────────────────
    case 'etf_sip_edit':
        $id  = (int)($_POST['id'] ?? 0);
        $pid = etf_portfolio($userId);
        if (!$id || !$pid) { json_response(false, 'Invalid request.'); }

        $allowed = ['monthly_amount','sip_date','start_date','end_date','is_active','broker','notes'];
        $sets = $params = [];
        foreach ($allowed as $f) {
            if (isset($_POST[$f])) { $sets[] = "`{$f}`=?"; $params[] = clean($_POST[$f]); }
        }
        if (!$sets) { json_response(false, 'Nothing to update.'); }
        $params[] = $id;
        DB::run("UPDATE etf_sip SET " . implode(',', $sets) . " WHERE id=? AND portfolio_id={$pid}", $params);
        json_response(true, 'SIP updated.');


    // ─── SIP DELETE ───────────────────────────────────────────────────────────
    case 'etf_sip_delete':
        $id  = (int)($_POST['id'] ?? 0);
        $pid = etf_portfolio($userId);
        if (!$id || !$pid) { json_response(false, 'Invalid request.'); }
        DB::run("DELETE FROM etf_sip WHERE id=? AND portfolio_id=?", [$id, $pid]);
        json_response(true, 'SIP deleted.');


    // ─── COMPARE ──────────────────────────────────────────────────────────────
    case 'etf_compare':
        $etfIds = array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')));
        if (count($etfIds) < 2 || count($etfIds) > 5) {
            json_response(false, 'Provide 2-5 ETF IDs to compare.');
        }
        $placeholders = implode(',', array_fill(0, count($etfIds), '?'));
        $rows = DB::fetchAll("
            SELECT * FROM etf_master WHERE id IN ({$placeholders})
        ", $etfIds);
        json_response(true, '', ['data' => $rows]);


    // ─── TAX REPORT ───────────────────────────────────────────────────────────
    case 'etf_tax_report':
        $pid  = etf_portfolio($userId);
        $year = (int)($_GET['year'] ?? date('Y'));
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $sells = DB::fetchAll("
            SELECT t.*, m.symbol, m.scheme_name, m.category,
                   m.is_gold_etf, m.is_international
            FROM etf_transactions t
            JOIN etf_master m ON m.id = t.etf_id
            WHERE t.portfolio_id=? AND t.type='sell' AND YEAR(t.txn_date)=?
            ORDER BY t.txn_date ASC
        ", [$pid, $year]);

        // Group by type for tax treatment
        $equityGain = $goldGain = $debtGain = 0;
        foreach ($sells as $s) {
            // ETF category based tax (post Budget 2024 — debt ETFs taxed at slab rate)
            if ($s['is_gold_etf']) {
                $goldGain += (float)$s['total_value'];
            } elseif (strtolower($s['category']) === 'debt') {
                $debtGain += (float)$s['total_value'];
            } else {
                $equityGain += (float)$s['total_value'];
            }
        }

        json_response(true, '', [
            'year'        => $year,
            'sells'       => $sells,
            'equity_gain' => $equityGain,
            'gold_gain'   => $goldGain,
            'debt_gain'   => $debtGain,
            'tax_notes'   => [
                'equity'  => 'Equity ETFs: STCG 20% (<12m), LTCG 12.5% on gains >₹1.25L (>=12m).',
                'gold'    => 'Gold ETFs: STCG at slab (<24m), LTCG 12.5% (>=24m, post Jul 2024).',
                'debt'    => 'Debt ETFs: Taxed at slab rate regardless of holding period (post Apr 2023).',
                'intl'    => 'International ETFs: Same as Debt ETF treatment.',
            ],
        ]);


    default:
        json_response(false, "Unknown ETF action: {$action}", [], 400);
}
