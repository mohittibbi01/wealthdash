<?php
/**
 * WealthDash REST API — Entry Point
 * Task: t504 · Worker: ID-M
 *
 * Base URL:  /api/rest/v1/
 * Auth:      Authorization: Bearer <api_key>
 * Format:    JSON only
 *
 * Endpoints:
 *   GET  /api/rest/v1/portfolio         → portfolio summary
 *   GET  /api/rest/v1/holdings          → all holdings (MF + Stocks + FD + NPS + Savings)
 *   GET  /api/rest/v1/holdings/mf       → MF holdings only
 *   GET  /api/rest/v1/holdings/stocks   → Stock holdings only
 *   GET  /api/rest/v1/holdings/fd       → FD accounts
 *   GET  /api/rest/v1/holdings/nps      → NPS holdings
 *   GET  /api/rest/v1/holdings/savings  → Savings accounts
 *   GET  /api/rest/v1/transactions      → recent transactions (all types)
 *   GET  /api/rest/v1/transactions/mf   → MF transactions
 *   GET  /api/rest/v1/transactions/stocks → stock transactions
 *   GET  /api/rest/v1/net-worth         → net worth snapshot
 *
 * Usage example:
 *   curl -H "Authorization: Bearer <key>" https://yourdomain.com/api/rest/v1/portfolio
 */
define('WEALTHDASH', true);
require_once dirname(__DIR__, 2) . '/config/config.php';
require_once __DIR__ . '/middleware.php';

// CORS headers for REST consumers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    _rest_error(405, 'Only GET requests are supported on this API.');
}

// Parse endpoint from PATH_INFO or REQUEST_URI
$uri      = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$basePath = '/api/rest/v1';
$path     = '/' . trim(substr($uri, strlen($basePath)), '/');

// Authenticate + rate limit
$ctx    = rest_auth('portfolio:read');
$uid    = $ctx['user_id'];
$keyRow = $ctx['key'];

// Resolve portfolio
$portfolioId = (int)($_GET['portfolio_id'] ?? 0);
if (!$portfolioId) $portfolioId = get_user_portfolio_id($uid);
if (!$portfolioId || !can_access_portfolio($portfolioId, $uid, $keyRow['role'] === 'admin')) {
    rest_log($ctx, 403, $path);
    _rest_error(403, 'Invalid or inaccessible portfolio.');
}

// ── Route ──────────────────────────────────────────────────────
switch ($path) {

    // ─── Portfolio summary ────────────────────────────────────────
    case '/portfolio':
    case '/':
        $data = _rest_portfolio_summary($portfolioId, $uid);
        rest_log($ctx, 200, $path);
        rest_response($data);

    // ─── All holdings ─────────────────────────────────────────────
    case '/holdings':
        $data = [
            'mf'      => _rest_mf_holdings($portfolioId),
            'stocks'  => _rest_stock_holdings($portfolioId),
            'fd'      => _rest_fd_holdings($portfolioId),
            'nps'     => _rest_nps_holdings($portfolioId),
            'savings' => _rest_savings_holdings($portfolioId),
        ];
        rest_log($ctx, 200, $path);
        rest_response($data);

    case '/holdings/mf':
        rest_log($ctx, 200, $path);
        rest_response(['holdings' => _rest_mf_holdings($portfolioId)]);

    case '/holdings/stocks':
        rest_log($ctx, 200, $path);
        rest_response(['holdings' => _rest_stock_holdings($portfolioId)]);

    case '/holdings/fd':
        rest_log($ctx, 200, $path);
        rest_response(['holdings' => _rest_fd_holdings($portfolioId)]);

    case '/holdings/nps':
        rest_log($ctx, 200, $path);
        rest_response(['holdings' => _rest_nps_holdings($portfolioId)]);

    case '/holdings/savings':
        rest_log($ctx, 200, $path);
        rest_response(['holdings' => _rest_savings_holdings($portfolioId)]);

    // ─── Transactions ──────────────────────────────────────────────
    case '/transactions':
        $limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));
        $since = clean($_GET['since'] ?? '');
        $data  = [
            'mf'     => _rest_mf_transactions($portfolioId, $limit, $since),
            'stocks' => _rest_stock_transactions($portfolioId, $limit, $since),
        ];
        rest_log($ctx, 200, $path);
        rest_response($data, 200, ['limit' => $limit, 'since' => $since ?: null]);

    case '/transactions/mf':
        $limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));
        $since = clean($_GET['since'] ?? '');
        rest_log($ctx, 200, $path);
        rest_response(
            ['transactions' => _rest_mf_transactions($portfolioId, $limit, $since)],
            200,
            ['limit' => $limit]
        );

    case '/transactions/stocks':
        $limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));
        $since = clean($_GET['since'] ?? '');
        rest_log($ctx, 200, $path);
        rest_response(
            ['transactions' => _rest_stock_transactions($portfolioId, $limit, $since)],
            200,
            ['limit' => $limit]
        );

    // ─── Net Worth ─────────────────────────────────────────────────
    case '/net-worth':
        $data = _rest_net_worth($portfolioId);
        rest_log($ctx, 200, $path);
        rest_response($data);

    // ─── 404 ───────────────────────────────────────────────────────
    default:
        rest_log($ctx, 404, $path);
        _rest_error(404, "Endpoint '{$path}' not found. Available: /portfolio, /holdings, /holdings/mf, /holdings/stocks, /holdings/fd, /holdings/nps, /holdings/savings, /transactions, /transactions/mf, /transactions/stocks, /net-worth");
}

// ────────────────────────────────────────────────────────────────
// DATA FUNCTIONS
// ────────────────────────────────────────────────────────────────

function _rest_portfolio_summary(int $pid, int $uid): array {
    $portfolio = DB::fetchOne("SELECT id, name, description, created_at FROM portfolios WHERE id=?", [$pid]);

    $mfVal    = (float)DB::fetchVal("SELECT COALESCE(SUM(current_value),0) FROM mf_holdings WHERE portfolio_id=? AND is_active=1", [$pid]);
    $mfInv    = (float)DB::fetchVal("SELECT COALESCE(SUM(invested_amount),0) FROM mf_holdings WHERE portfolio_id=? AND is_active=1", [$pid]);
    $stVal    = (float)DB::fetchVal("SELECT COALESCE(SUM(current_value),0) FROM stock_holdings WHERE portfolio_id=? AND is_active=1", [$pid]);
    $stInv    = (float)DB::fetchVal("SELECT COALESCE(SUM(total_invested),0) FROM stock_holdings WHERE portfolio_id=? AND is_active=1", [$pid]);
    $npsVal   = (float)DB::fetchVal("SELECT COALESCE(SUM(latest_value),0) FROM nps_holdings WHERE portfolio_id=?", [$pid]);
    $npsInv   = (float)DB::fetchVal("SELECT COALESCE(SUM(total_invested),0) FROM nps_holdings WHERE portfolio_id=?", [$pid]);
    $fdVal    = (float)DB::fetchVal("SELECT COALESCE(SUM(principal),0) FROM fd_accounts WHERE portfolio_id=? AND status='active'", [$pid]);
    $savVal   = (float)DB::fetchVal("SELECT COALESCE(SUM(balance),0) FROM savings_accounts WHERE portfolio_id=? AND is_active=1", [$pid]);

    $totalValue    = $mfVal + $stVal + $npsVal + $fdVal + $savVal;
    $totalInvested = $mfInv + $stInv + $npsInv + $fdVal + $savVal;
    $totalGain     = $totalValue - $totalInvested;
    $gainPct       = $totalInvested > 0 ? round($totalGain / $totalInvested * 100, 2) : 0;

    return [
        'portfolio'     => $portfolio,
        'net_worth'     => round($totalValue, 2),
        'total_invested'=> round($totalInvested, 2),
        'total_gain'    => round($totalGain, 2),
        'gain_pct'      => $gainPct,
        'as_of'         => date('Y-m-d H:i:s'),
        'breakdown'     => [
            'mutual_funds' => ['value' => round($mfVal, 2), 'invested' => round($mfInv, 2)],
            'stocks'       => ['value' => round($stVal, 2), 'invested' => round($stInv, 2)],
            'nps'          => ['value' => round($npsVal, 2), 'invested' => round($npsInv, 2)],
            'fd'           => ['value' => round($fdVal, 2), 'invested' => round($fdVal, 2)],
            'savings'      => ['value' => round($savVal, 2), 'invested' => round($savVal, 2)],
        ],
    ];
}

function _rest_mf_holdings(int $pid): array {
    return DB::fetchAll(
        "SELECT h.id, f.scheme_name AS fund_name, f.isin, h.folio_number, h.platform,
                h.units, h.avg_nav, h.invested_amount, h.current_value,
                h.gain_loss,
                CASE WHEN h.invested_amount > 0
                     THEN ROUND((h.current_value - h.invested_amount)/h.invested_amount*100, 2)
                     ELSE 0 END AS gain_pct,
                h.xirr, h.first_investment_date, h.last_transaction_date
         FROM mf_holdings h
         JOIN funds f ON f.id = h.fund_id
         WHERE h.portfolio_id=? AND h.is_active=1
         ORDER BY h.current_value DESC",
        [$pid]
    );
}

function _rest_stock_holdings(int $pid): array {
    return DB::fetchAll(
        "SELECT h.id, s.symbol, s.company_name, s.exchange, h.quantity,
                h.avg_buy_price, h.total_invested, h.current_price, h.current_value,
                (h.current_value - h.total_invested) AS gain_loss,
                CASE WHEN h.total_invested > 0
                     THEN ROUND((h.current_value - h.total_invested)/h.total_invested*100, 2)
                     ELSE 0 END AS gain_pct
         FROM stock_holdings h
         JOIN stock_master s ON s.id = h.stock_id
         WHERE h.portfolio_id=? AND h.is_active=1
         ORDER BY h.current_value DESC",
        [$pid]
    );
}

function _rest_fd_holdings(int $pid): array {
    return DB::fetchAll(
        "SELECT id, bank_name, fd_number, principal, interest_rate,
                tenure_days, open_date, maturity_date, status, compound_frequency
         FROM fd_accounts WHERE portfolio_id=? AND status='active'
         ORDER BY maturity_date ASC",
        [$pid]
    );
}

function _rest_nps_holdings(int $pid): array {
    return DB::fetchAll(
        "SELECT h.id, s.scheme_name, s.scheme_code, s.fund_manager, s.asset_class,
                h.units, h.latest_nav, h.latest_value, h.total_invested,
                (h.latest_value - h.total_invested) AS gain_loss
         FROM nps_holdings h
         JOIN nps_schemes s ON s.id = h.scheme_id
         WHERE h.portfolio_id=?
         ORDER BY h.latest_value DESC",
        [$pid]
    );
}

function _rest_savings_holdings(int $pid): array {
    return DB::fetchAll(
        "SELECT id, bank_name, account_type, account_number_masked, balance,
                interest_rate, is_active
         FROM savings_accounts WHERE portfolio_id=? AND is_active=1
         ORDER BY balance DESC",
        [$pid]
    );
}

function _rest_mf_transactions(int $pid, int $limit, string $since): array {
    $params = [$pid];
    $sinceSQL = '';
    if ($since) {
        $sinceSQL = " AND t.txn_date >= ?";
        $params[] = $since;
    }
    $params[] = $limit;
    return DB::fetchAll(
        "SELECT t.id, f.scheme_name AS fund_name, f.isin, t.folio_number,
                t.txn_type, t.txn_date, t.units, t.nav, t.amount, t.platform
         FROM mf_transactions t
         JOIN funds f ON f.id = t.fund_id
         WHERE t.portfolio_id=? AND t.is_duplicate=0{$sinceSQL}
         ORDER BY t.txn_date DESC
         LIMIT ?",
        $params
    );
}

function _rest_stock_transactions(int $pid, int $limit, string $since): array {
    $params = [$pid];
    $sinceSQL = '';
    if ($since) {
        $sinceSQL = " AND t.txn_date >= ?";
        $params[] = $since;
    }
    $params[] = $limit;
    return DB::fetchAll(
        "SELECT t.id, s.symbol, s.company_name,
                t.txn_type, t.txn_date, t.quantity, t.price, t.brokerage, t.total_amount
         FROM stock_transactions t
         JOIN stock_master s ON s.id = t.stock_id
         WHERE t.portfolio_id=? AND t.is_duplicate=0{$sinceSQL}
         ORDER BY t.txn_date DESC
         LIMIT ?",
        $params
    );
}

function _rest_net_worth(int $pid): array {
    $summary = _rest_portfolio_summary($pid, 0);
    // Last 12 monthly snapshots if available
    $snapshots = DB::fetchAll(
        "SELECT snapshot_date, net_worth FROM nw_snapshots
         WHERE portfolio_id=? ORDER BY snapshot_date DESC LIMIT 12",
        [$pid]
    );
    $summary['snapshots'] = $snapshots;
    return $summary;
}
