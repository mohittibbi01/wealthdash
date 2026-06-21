<?php
/**
 * WealthDash — External REST API Gateway [t335]
 * File: api/external/rest_gateway.php
 * Worker: ID-M
 *
 * Entry point: GET /api/external/v1/{endpoint}
 * Auth:        Bearer token (wdx_... key) in Authorization header
 *              OR ?api_key=wdx_... query param
 *
 * Supported endpoints:
 *   GET /v1/portfolio          — Net worth summary
 *   GET /v1/networth           — Net worth + breakdown
 *   GET /v1/mf/holdings        — MF holdings list
 *   GET /v1/stocks/holdings    — Stock holdings
 *   GET /v1/fd/accounts        — FD accounts
 *   GET /v1/savings/accounts   — Savings accounts
 *   GET /v1/banks/accounts     — Bank accounts
 *   GET /v1/nps/holdings       — NPS holdings
 *   GET /v1/tax/summary        — Tax summary
 *   GET /v1/goals              — Investment goals
 */
define('WEALTHDASH', true);
require_once dirname(__DIR__, 2) . '/config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('X-Content-Type-Options: nosniff');

// ── Helpers ──────────────────────────────────────────────────────────────────
function ext_resp(bool $ok, mixed $data = null, string $msg = '', int $code = 200): never {
    http_response_code($code);
    echo json_encode([
        'success'   => $ok,
        'message'   => $msg,
        'data'      => $data,
        'ts'        => date('c'),
        'api_ver'   => '1.0',
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function ext_error(string $msg, int $code = 400): never {
    ext_resp(false, null, $msg, $code);
}

function log_ext_request(int $keyId, string $endpoint, int $status, float $startMs): void {
    try {
        DB::run(
            'INSERT INTO external_api_log (key_id, endpoint, method, status, duration_ms, ip_address)
             VALUES (?,?,?,?,?,?)',
            [
                $keyId, $endpoint,
                $_SERVER['REQUEST_METHOD'] ?? 'GET',
                $status,
                round((microtime(true) - $startMs) * 1000, 2),
                $_SERVER['REMOTE_ADDR'] ?? null,
            ]
        );
    } catch (Exception $e) {}
}

// ── Auth ──────────────────────────────────────────────────────────────────────
$startMs = microtime(true);

// Extract raw key from Bearer header or query param
$rawKey = '';
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (str_starts_with($authHeader, 'Bearer ')) {
    $rawKey = trim(substr($authHeader, 7));
} elseif (!empty($_GET['api_key'])) {
    $rawKey = trim($_GET['api_key']);
}

if (!$rawKey || !str_starts_with($rawKey, 'wdx_')) {
    ext_error('Missing or invalid API key. Use Authorization: Bearer wdx_... header.', 401);
}

$prefix = substr($rawKey, 0, 12);
$keyRow = DB::fetchOne(
    'SELECT * FROM external_api_keys WHERE key_prefix=? AND is_active=1',
    [$prefix]
);

if (!$keyRow || !password_verify($rawKey, $keyRow['key_hash'])) {
    ext_error('Invalid or inactive API key.', 401);
}
if ($keyRow['expires_at'] && $keyRow['expires_at'] < date('Y-m-d')) {
    ext_error('API key has expired.', 401);
}

// Rate limit check
$window = date('Y-m-d H:i:00');
DB::run(
    'INSERT INTO external_api_rate (key_id, window, hits) VALUES (?,?,1)
     ON DUPLICATE KEY UPDATE hits = hits + 1',
    [(int)$keyRow['id'], $window]
);
$hits = (int) DB::fetchVal(
    'SELECT hits FROM external_api_rate WHERE key_id=? AND window=?',
    [(int)$keyRow['id'], $window]
);
if ($hits > (int)$keyRow['rate_limit']) {
    log_ext_request((int)$keyRow['id'], $_SERVER['REQUEST_URI'] ?? '', 429, $startMs);
    ext_error('Rate limit exceeded (' . $keyRow['rate_limit'] . ' req/min).', 429);
}

// Update usage
DB::run(
    'UPDATE external_api_keys SET last_used_at=NOW(), last_ip=?, use_count=use_count+1 WHERE id=?',
    [$_SERVER['REMOTE_ADDR'] ?? null, $keyRow['id']]
);

$keyScopes = json_decode($keyRow['scopes'], true) ?? [];
$userId    = (int)$keyRow['user_id'];
$portfolioId = (int)(DB::fetchVal('SELECT id FROM portfolios WHERE user_id=?', [$userId]) ?: 0);

function has_scope(string $scope, array $scopes): bool {
    return in_array($scope, $scopes);
}
function require_scope(string $scope, array $scopes, int $keyId, string $uri, float $start): void {
    if (!has_scope($scope, $scopes)) {
        log_ext_request($keyId, $uri, 403, $start);
        ext_error("Scope required: {$scope}", 403);
    }
}

// ── Route parsing ─────────────────────────────────────────────────────────────
$uri      = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
$uri      = preg_replace('#^.*/api/external/v1#', '', $uri);
$uri      = '/' . trim($uri, '/');
$keyId    = (int)$keyRow['id'];

// ── Endpoints ─────────────────────────────────────────────────────────────────

// GET /v1/portfolio — full net worth summary
if ($uri === '/portfolio' || $uri === '') {
    require_scope('portfolio:read', $keyScopes, $keyId, $uri, $startMs);

    $rows = DB::fetchAll(
        "SELECT asset_type, SUM(current_value) as total
         FROM (
             SELECT 'mf' as asset_type, SUM(units * nav) as current_value
             FROM mf_holdings WHERE portfolio_id=?
             UNION ALL
             SELECT 'fd', SUM(amount) FROM fd_accounts WHERE portfolio_id=? AND status='active'
             UNION ALL
             SELECT 'savings', SUM(balance) FROM savings_accounts WHERE portfolio_id=? AND status='active'
             UNION ALL
             SELECT 'bank', SUM(current_balance) FROM bank_accounts WHERE user_id=? AND status='active'
         ) t GROUP BY asset_type",
        [$portfolioId, $portfolioId, $portfolioId, $userId]
    );

    $byType = [];
    $total  = 0;
    foreach ($rows as $r) {
        $byType[$r['asset_type']] = (float)$r['total'];
        $total += (float)$r['total'];
    }

    log_ext_request($keyId, $uri, 200, $startMs);
    ext_resp(true, [
        'net_worth'  => $total,
        'breakdown'  => $byType,
        'currency'   => 'INR',
        'as_of'      => date('Y-m-d'),
    ]);
}

// GET /v1/networth — with history
if ($uri === '/networth') {
    require_scope('networth:read', $keyScopes, $keyId, $uri, $startMs);
    $latest  = DB::fetchOne('SELECT * FROM net_worth_snapshots WHERE user_id=? ORDER BY snap_date DESC LIMIT 1', [$userId]);
    $history = DB::fetchAll(
        'SELECT snap_date, total_value FROM net_worth_snapshots WHERE user_id=? ORDER BY snap_date DESC LIMIT 24',
        [$userId]
    );
    log_ext_request($keyId, $uri, 200, $startMs);
    ext_resp(true, ['latest' => $latest, 'history' => $history]);
}

// GET /v1/mf/holdings
if ($uri === '/mf/holdings') {
    require_scope('mf:read', $keyScopes, $keyId, $uri, $startMs);
    $rows = DB::fetchAll(
        'SELECT mh.id, f.fund_name, f.amfi_code, mh.units, mh.avg_nav,
                mh.current_nav, ROUND(mh.units * mh.current_nav, 2) as current_value,
                ROUND((mh.current_nav - mh.avg_nav) / mh.avg_nav * 100, 2) as gain_pct
         FROM mf_holdings mh
         JOIN funds f ON f.id = mh.fund_id
         WHERE mh.portfolio_id=? AND mh.units > 0
         ORDER BY current_value DESC',
        [$portfolioId]
    );
    log_ext_request($keyId, $uri, 200, $startMs);
    ext_resp(true, ['holdings' => $rows, 'count' => count($rows)]);
}

// GET /v1/stocks/holdings
if ($uri === '/stocks/holdings') {
    require_scope('stocks:read', $keyScopes, $keyId, $uri, $startMs);
    $rows = DB::fetchAll(
        'SELECT sh.symbol, sm.company_name, sh.quantity, sh.avg_price,
                sh.current_price, ROUND(sh.quantity * sh.current_price, 2) as current_value,
                ROUND((sh.current_price - sh.avg_price) / sh.avg_price * 100, 2) as gain_pct
         FROM stock_holdings sh
         LEFT JOIN stock_master sm ON sm.symbol = sh.symbol
         WHERE sh.portfolio_id=? AND sh.quantity > 0
         ORDER BY current_value DESC',
        [$portfolioId]
    );
    log_ext_request($keyId, $uri, 200, $startMs);
    ext_resp(true, ['holdings' => $rows, 'count' => count($rows)]);
}

// GET /v1/fd/accounts
if ($uri === '/fd/accounts') {
    require_scope('fd:read', $keyScopes, $keyId, $uri, $startMs);
    $rows = DB::fetchAll(
        "SELECT id, bank_name, account_number, principal_amount as amount,
                interest_rate, tenure_months, start_date, maturity_date,
                maturity_amount, status
         FROM fd_accounts WHERE portfolio_id=? ORDER BY maturity_date ASC",
        [$portfolioId]
    );
    log_ext_request($keyId, $uri, 200, $startMs);
    ext_resp(true, ['accounts' => $rows, 'count' => count($rows)]);
}

// GET /v1/savings/accounts
if ($uri === '/savings/accounts') {
    require_scope('savings:read', $keyScopes, $keyId, $uri, $startMs);
    $rows = DB::fetchAll(
        'SELECT id, bank_name, account_type, balance, interest_rate, status
         FROM savings_accounts WHERE portfolio_id=? ORDER BY balance DESC',
        [$portfolioId]
    );
    log_ext_request($keyId, $uri, 200, $startMs);
    ext_resp(true, ['accounts' => $rows, 'count' => count($rows)]);
}

// GET /v1/banks/accounts
if ($uri === '/banks/accounts') {
    require_scope('banks:read', $keyScopes, $keyId, $uri, $startMs);
    $rows = DB::fetchAll(
        "SELECT id, bank_name, nickname, account_type, current_balance, balance_date, status
         FROM bank_accounts WHERE user_id=? AND status='active' ORDER BY current_balance DESC",
        [$userId]
    );
    log_ext_request($keyId, $uri, 200, $startMs);
    ext_resp(true, ['accounts' => $rows, 'total' => array_sum(array_column($rows, 'current_balance'))]);
}

// GET /v1/nps/holdings
if ($uri === '/nps/holdings') {
    require_scope('nps:read', $keyScopes, $keyId, $uri, $startMs);
    $rows = DB::fetchAll(
        'SELECT nh.*, ns.scheme_name, ns.fund_manager
         FROM nps_holdings nh
         JOIN nps_schemes ns ON ns.id = nh.scheme_id
         WHERE nh.portfolio_id=?',
        [$portfolioId]
    );
    log_ext_request($keyId, $uri, 200, $startMs);
    ext_resp(true, ['holdings' => $rows]);
}

// GET /v1/tax/summary
if ($uri === '/tax/summary') {
    require_scope('tax:read', $keyScopes, $keyId, $uri, $startMs);
    $fyStart = date('Y') . '-04-01';
    if (date('m') < 4) $fyStart = (date('Y') - 1) . '-04-01';
    // Simplified LTCG/STCG from MF sells since FY start
    $gains = DB::fetchAll(
        "SELECT txn_type, SUM(amount) as total
         FROM mf_transactions WHERE portfolio_id=? AND txn_date >= ?
         AND txn_type IN ('SELL','DIV_PAYOUT')
         GROUP BY txn_type",
        [$portfolioId, $fyStart]
    );
    log_ext_request($keyId, $uri, 200, $startMs);
    ext_resp(true, ['fy_start' => $fyStart, 'gains' => $gains]);
}

// GET /v1/goals
if ($uri === '/goals') {
    require_scope('goals:read', $keyScopes, $keyId, $uri, $startMs);
    $rows = DB::fetchAll(
        'SELECT id, name, target_amount, current_amount, target_date, status
         FROM investment_goals WHERE user_id=? ORDER BY target_date ASC',
        [$userId]
    );
    log_ext_request($keyId, $uri, 200, $startMs);
    ext_resp(true, ['goals' => $rows]);
}

// ── 404 fallback ──────────────────────────────────────────────────────────────
log_ext_request($keyId, $uri, 404, $startMs);
ext_error("Unknown endpoint: {$uri}. See /api/external/v1/docs for available endpoints.", 404);
