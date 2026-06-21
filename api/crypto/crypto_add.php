<?php
/**
 * WealthDash — t40: CoinGecko Full Integration
 *
 * Actions handled (routed from router.php):
 *   GET  coingecko_search      — search coins by name/symbol (with cache)
 *   GET  coingecko_trending    — trending coins (CoinGecko trending API)
 *   GET  coingecko_top_coins   — top N coins by market cap
 *   GET  coingecko_coin_detail — single coin full market data
 *
 * Router note (add to router.php after existing crypto cases ~line 832):
 *   case 'coingecko_search':
 *   case 'coingecko_trending':
 *   case 'coingecko_top_coins':
 *   case 'coingecko_coin_detail':
 *       require APP_ROOT . '/api/crypto/crypto_add.php'; exit;
 *
 * DB deps: crypto_price_cache (existing from migration 38)
 *          coingecko_search_cache (t40_migration.sql)
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::pdo();
$action      = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Rate-limit guard (CoinGecko free: 30 req/min) ────────────────────────────
define('CG_BASE', 'https://api.coingecko.com/api/v3');
define('CG_CACHE_SEARCH_TTL', 3600);   // 1 hour for search/meta
define('CG_CACHE_PRICE_TTL',  300);    // 5 min for market data

function t40_cg_request(string $path, array $params = []): array
{
    $url = CG_BASE . $path;
    if (!empty($params)) $url .= '?' . http_build_query($params);

    $ctx = stream_context_create(['http' => [
        'timeout' => 8,
        'header'  => "User-Agent: WealthDash/1.0\r\nAccept: application/json\r\n",
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) throw new RuntimeException('CoinGecko API unreachable');

    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException('CoinGecko returned invalid JSON');

    // Handle rate limit / errors
    if (isset($data['status']['error_code'])) {
        $code = $data['status']['error_code'];
        $msg  = $data['status']['error_message'] ?? 'CoinGecko error';
        throw new RuntimeException("CoinGecko ({$code}): {$msg}");
    }

    return $data;
}

/**
 * Try cache first, then hit API and cache result.
 */
function t40_cached(PDO $db, string $cacheKey, callable $fetcher, int $ttl = CG_CACHE_SEARCH_TTL): array
{
    // Check cache
    $row = $db->prepare(
        "SELECT payload, fetched_at FROM coingecko_search_cache
         WHERE cache_key = ? AND fetched_at >= DATE_SUB(NOW(), INTERVAL {$ttl} SECOND)
         LIMIT 1"
    );
    $row->execute([$cacheKey]);
    $cached = $row->fetch(PDO::FETCH_ASSOC);

    if ($cached) {
        $data = json_decode($cached['payload'], true);
        if (is_array($data)) return $data;
    }

    // Fetch fresh
    $data = $fetcher();

    // Upsert cache
    $db->prepare(
        "INSERT INTO coingecko_search_cache (cache_key, payload)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE payload = VALUES(payload), fetched_at = NOW()"
    )->execute([$cacheKey, json_encode($data)]);

    return $data;
}

// ════════════════════════════════════════════════════════════════════════════
// ACTIONS
// ════════════════════════════════════════════════════════════════════════════
switch ($action) {

    // ── COIN SEARCH ──────────────────────────────────────────────────────
    case 'coingecko_search': {
        $q = trim(clean($_GET['q'] ?? ''));
        if (strlen($q) < 2) json_response(false, 'Query must be at least 2 characters.');

        $cacheKey = 'search:' . strtolower(substr($q, 0, 60));

        try {
            $results = t40_cached($db, $cacheKey, function () use ($q) {
                $raw = t40_cg_request('/search', ['query' => $q]);
                $coins = $raw['coins'] ?? [];

                // Normalise to our shape (top 20 results)
                return array_map(fn($c) => [
                    'id'             => $c['id'],
                    'symbol'         => strtoupper($c['symbol'] ?? ''),
                    'name'           => $c['name']   ?? '',
                    'thumb'          => $c['thumb']  ?? null,
                    'market_cap_rank'=> $c['market_cap_rank'] ?? null,
                ], array_slice($coins, 0, 20));
            }, CG_CACHE_SEARCH_TTL);

        } catch (RuntimeException $e) {
            // Fallback: search our local price cache
            $like = '%' . $q . '%';
            $fallback = DB::fetchAll(
                "SELECT coin_id AS id, '' AS symbol, '' AS name, NULL AS thumb, NULL AS market_cap_rank
                 FROM crypto_price_cache WHERE coin_id LIKE ? LIMIT 10",
                [$like]
            );
            json_response(true, 'CoinGecko offline — showing cached results.', [
                'results'   => $fallback,
                'source'    => 'cache_fallback',
                'query'     => $q,
            ]);
            break;
        }

        json_response(true, '', [
            'results' => $results,
            'query'   => $q,
            'count'   => count($results),
            'source'  => 'coingecko',
        ]);
        break;
    }

    // ── TRENDING COINS ───────────────────────────────────────────────────
    case 'coingecko_trending': {
        try {
            $data = t40_cached($db, 'trending', function () {
                $raw    = t40_cg_request('/search/trending');
                $coins  = $raw['coins'] ?? [];

                return array_map(function ($item) {
                    $c = $item['item'] ?? $item;
                    return [
                        'id'             => $c['id']     ?? '',
                        'symbol'         => strtoupper($c['symbol'] ?? ''),
                        'name'           => $c['name']   ?? '',
                        'thumb'          => $c['small']  ?? $c['thumb'] ?? null,
                        'market_cap_rank'=> $c['market_cap_rank'] ?? null,
                        'price_btc'      => $c['price_btc'] ?? null,
                        'score'          => $c['score'] ?? 0,
                    ];
                }, array_slice($coins, 0, 10));
            }, 900); // 15 min cache for trending

        } catch (RuntimeException $e) {
            json_response(false, 'CoinGecko unavailable: ' . $e->getMessage());
            break;
        }

        json_response(true, '', [
            'trending' => $data,
            'count'    => count($data),
        ]);
        break;
    }

    // ── TOP COINS BY MARKET CAP ───────────────────────────────────────────
    case 'coingecko_top_coins': {
        $perPage = min(50, max(10, (int)($_GET['per_page'] ?? 25)));
        $page    = max(1, (int)($_GET['page'] ?? 1));

        $cacheKey = "top:{$perPage}:{$page}";

        try {
            $coins = t40_cached($db, $cacheKey, function () use ($perPage, $page) {
                $raw = t40_cg_request('/coins/markets', [
                    'vs_currency'           => 'inr',
                    'order'                 => 'market_cap_desc',
                    'per_page'              => $perPage,
                    'page'                  => $page,
                    'sparkline'             => 'false',
                    'price_change_percentage'=> '24h,7d',
                    'locale'                => 'en',
                ]);

                return array_map(fn($c) => [
                    'id'                  => $c['id'],
                    'symbol'              => strtoupper($c['symbol'] ?? ''),
                    'name'                => $c['name']  ?? '',
                    'image'               => $c['image'] ?? null,
                    'price_inr'           => round((float)($c['current_price'] ?? 0), 4),
                    'market_cap_inr'      => (int)($c['market_cap'] ?? 0),
                    'market_cap_rank'     => $c['market_cap_rank'] ?? null,
                    'change_24h'          => round((float)($c['price_change_percentage_24h'] ?? 0), 2),
                    'change_7d'           => round((float)($c['price_change_percentage_7d_in_currency'] ?? 0), 2),
                    'volume_24h_inr'      => (int)($c['total_volume'] ?? 0),
                    'ath_inr'             => round((float)($c['ath'] ?? 0), 4),
                    'ath_change_pct'      => round((float)($c['ath_change_percentage'] ?? 0), 2),
                    'circulating_supply'  => (float)($c['circulating_supply'] ?? 0),
                    'total_supply'        => $c['total_supply'] ? (float)$c['total_supply'] : null,
                ], $raw);
            }, CG_CACHE_PRICE_TTL);

            // Also update price_cache for coins we got fresh data for
            $upsert = $db->prepare(
                "INSERT INTO crypto_price_cache (coin_id, price_inr, change_24h, market_cap)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE price_inr=VALUES(price_inr),
                     change_24h=VALUES(change_24h), market_cap=VALUES(market_cap), fetched_at=NOW()"
            );
            foreach ($coins as $c) {
                if ($c['price_inr'] > 0) {
                    $upsert->execute([$c['id'], $c['price_inr'], $c['change_24h'],
                                      $c['market_cap_inr'] > 0 ? $c['market_cap_inr'] : null]);
                }
            }

        } catch (RuntimeException $e) {
            json_response(false, 'CoinGecko unavailable: ' . $e->getMessage());
            break;
        }

        json_response(true, '', [
            'coins'    => $coins,
            'page'     => $page,
            'per_page' => $perPage,
            'count'    => count($coins),
        ]);
        break;
    }

    // ── SINGLE COIN DETAIL ───────────────────────────────────────────────
    case 'coingecko_coin_detail': {
        $coinId = clean($_GET['coin_id'] ?? '');
        if (!$coinId) json_response(false, 'coin_id required.');

        $cacheKey = 'detail:' . $coinId;

        try {
            $coin = t40_cached($db, $cacheKey, function () use ($coinId) {
                $raw = t40_cg_request("/coins/{$coinId}", [
                    'localization'    => 'false',
                    'tickers'         => 'false',
                    'market_data'     => 'true',
                    'community_data'  => 'false',
                    'developer_data'  => 'false',
                    'sparkline'       => 'false',
                ]);

                $md = $raw['market_data'] ?? [];

                return [
                    'id'                  => $raw['id'],
                    'symbol'              => strtoupper($raw['symbol'] ?? ''),
                    'name'                => $raw['name'] ?? '',
                    'description'         => substr(strip_tags($raw['description']['en'] ?? ''), 0, 400),
                    'image'               => $raw['image']['small'] ?? null,
                    'homepage'            => $raw['links']['homepage'][0] ?? null,
                    'whitepaper'          => $raw['links']['whitepaper'] ?? null,
                    // Market data in INR
                    'price_inr'           => round((float)($md['current_price']['inr']  ?? 0), 4),
                    'price_usd'           => round((float)($md['current_price']['usd']  ?? 0), 6),
                    'market_cap_inr'      => (int)($md['market_cap']['inr']             ?? 0),
                    'volume_24h_inr'      => (int)($md['total_volume']['inr']           ?? 0),
                    'change_24h'          => round((float)($md['price_change_percentage_24h']  ?? 0), 2),
                    'change_7d'           => round((float)($md['price_change_percentage_7d']   ?? 0), 2),
                    'change_30d'          => round((float)($md['price_change_percentage_30d']  ?? 0), 2),
                    'change_1y'           => round((float)($md['price_change_percentage_1y']   ?? 0), 2),
                    'ath_inr'             => round((float)($md['ath']['inr'] ?? 0), 4),
                    'ath_date'            => isset($md['ath_date']['inr'])
                                              ? date('Y-m-d', strtotime($md['ath_date']['inr'])) : null,
                    'ath_change_pct'      => round((float)($md['ath_change_percentage']['inr'] ?? 0), 2),
                    'atl_inr'             => round((float)($md['atl']['inr'] ?? 0), 4),
                    'atl_date'            => isset($md['atl_date']['inr'])
                                              ? date('Y-m-d', strtotime($md['atl_date']['inr'])) : null,
                    'circulating_supply'  => (float)($md['circulating_supply'] ?? 0),
                    'total_supply'        => $md['total_supply'] ? (float)$md['total_supply'] : null,
                    'max_supply'          => $md['max_supply']   ? (float)$md['max_supply']   : null,
                    'market_cap_rank'     => $raw['market_cap_rank'] ?? null,
                    'genesis_date'        => $raw['genesis_date'] ?? null,
                    // Holding context: do I own this?
                    '_fetched_at'         => date('Y-m-d H:i:s'),
                ];
            }, CG_CACHE_PRICE_TTL);

        } catch (RuntimeException $e) {
            // Fallback to price cache
            $cached = DB::fetchOne(
                "SELECT coin_id, price_inr, price_usd, change_24h, market_cap, fetched_at
                 FROM crypto_price_cache WHERE coin_id = ? LIMIT 1",
                [$coinId]
            );
            if ($cached) {
                json_response(true, 'CoinGecko offline — showing cached price.', array_merge(
                    ['id' => $coinId, 'symbol' => '', 'name' => $coinId,
                     'description' => '', 'source' => 'cache_fallback'],
                    $cached
                ));
            } else {
                json_response(false, 'CoinGecko unavailable and no cached data: ' . $e->getMessage());
            }
            break;
        }

        json_response(true, '', $coin);
        break;
    }

    default:
        json_response(false, "Unknown action: {$action}");
}
