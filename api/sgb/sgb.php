<?php
/**
 * WealthDash — t394: Sovereign Gold Bond (SGB) API
 * Live price fetch from RBI/IBJA/MCX + full CRUD for sgb_holdings
 *
 * Actions:
 *   sgb_list            — list user holdings with current value
 *   sgb_add             — add new SGB holding
 *   sgb_update          — update holding fields
 *   sgb_delete          — soft-delete holding
 *   sgb_summary         — portfolio-level summary
 *   sgb_live_price      — fetch live gold spot price
 *   sgb_refresh_nav     — refresh NAV for all holdings using live price
 *   sgb_interest_add    — log an interest payout
 *   sgb_interest_list   — list interest payouts for a holding
 *   sgb_series_list     — known SGB series reference data
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();

$action = $_GET['action'] ?? $_POST['action'] ?? 'sgb_list';
if (!str_starts_with($action, 'sgb_')) $action = 'sgb_' . $action;

$portfolioId = (int)($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ?? 0);
if (!$portfolioId) $portfolioId = get_user_portfolio_id($userId);

if (!in_array($action, ['sgb_live_price', 'sgb_series_list'])) {
    if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin ?? false)) {
        json_response(false, 'Invalid or inaccessible portfolio.');
    }
}

// ──────────────────────────────────────────────────────────────
// HELPER: Fetch live 24K gold price per gram (INR)
// Sources tried in order: GoldAPI.io → metals.live → stooq → cache
// ──────────────────────────────────────────────────────────────
function sgb_fetch_live_gold_price(): array {
    $today = date('Y-m-d');
    // Check cache (30 min during market hours, 2h otherwise)
    $cacheRow = DB::fetchRow(
        "SELECT price_24k_gram, source, fetched_at FROM gold_price_cache
         WHERE date_for = ? ORDER BY fetched_at DESC LIMIT 1",
        [$today]
    );
    if ($cacheRow) {
        $ageMin = (time() - strtotime($cacheRow['fetched_at'])) / 60;
        $maxAge = (date('H') >= 9 && date('H') < 17) ? 30 : 120;
        if ($ageMin < $maxAge) {
            return [
                'price_24k_gram' => (float)$cacheRow['price_24k_gram'],
                'source'         => $cacheRow['source'] . ' (cached)',
                'cached'         => true,
                'fetched_at'     => $cacheRow['fetched_at'],
            ];
        }
    }

    $price24k = null;
    $source   = 'fallback';

    // 1) GoldAPI.io (key optional)
    $goldApiKey = env('GOLDAPI_KEY', '');
    if ($goldApiKey) {
        $ctx = stream_context_create(['http' => [
            'timeout' => 6,
            'header'  => "x-access-token: {$goldApiKey}\r\nContent-Type: application/json\r\n",
        ]]);
        $resp = @file_get_contents('https://www.goldapi.io/api/XAU/INR', false, $ctx);
        if ($resp) {
            $data = json_decode($resp, true);
            if (isset($data['price']) && $data['price'] > 0) {
                $price24k = round($data['price'] / 31.1035, 2);
                $source = 'goldapi.io';
            }
        }
    }

    // 2) metals.live (no key, USD-based)
    if (!$price24k) {
        $resp = @file_get_contents('https://metals.live/api/spot', false,
            stream_context_create(['http' => ['timeout' => 5]]));
        if ($resp) {
            $data = json_decode($resp, true);
            if (isset($data['gold']) && $data['gold'] > 0) {
                $usdInr = sgb_get_usd_inr();
                $price24k = round(($data['gold'] * $usdInr) / 31.1035, 2);
                $source = 'metals.live';
            }
        }
    }

    // 3) Stooq XAUUSD CSV
    if (!$price24k) {
        $resp = @file_get_contents('https://stooq.com/q/l/?s=xauusd&f=sd2t2ohlcv&h&e=csv', false,
            stream_context_create(['http' => ['timeout' => 5]]));
        if ($resp) {
            $lines = explode("\n", trim($resp));
            if (count($lines) >= 2) {
                $cols = str_getcsv($lines[1]);
                $xauUsd = isset($cols[4]) ? (float)$cols[4] : 0;
                if ($xauUsd > 0) {
                    $usdInr = sgb_get_usd_inr();
                    $price24k = round(($xauUsd * $usdInr) / 31.1035, 2);
                    $source = 'stooq';
                }
            }
        }
    }

    // 4) Stale cache
    if (!$price24k) {
        $lastRow = DB::fetchRow(
            "SELECT price_24k_gram, source, fetched_at FROM gold_price_cache
             ORDER BY fetched_at DESC LIMIT 1"
        );
        if ($lastRow) {
            return [
                'price_24k_gram' => (float)$lastRow['price_24k_gram'],
                'source'         => $lastRow['source'] . ' (stale)',
                'cached'         => true,
                'fetched_at'     => $lastRow['fetched_at'],
            ];
        }
        $price24k = 9200.00;
        $source   = 'hardcoded_fallback';
    }

    $price22k = round($price24k * 22 / 24, 2);
    $price18k = round($price24k * 18 / 24, 2);
    try {
        DB::execute(
            "INSERT INTO gold_price_cache (source, price_24k_gram, price_22k_gram, price_18k_gram, date_for)
             VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
             price_24k_gram=VALUES(price_24k_gram),
             price_22k_gram=VALUES(price_22k_gram),
             price_18k_gram=VALUES(price_18k_gram),
             source=VALUES(source),
             fetched_at=NOW()",
            [$source, $price24k, $price22k, $price18k, $today]
        );
    } catch (Exception $e) {
        // cache table might not exist yet; not fatal
    }

    return [
        'price_24k_gram' => $price24k,
        'price_22k_gram' => $price22k,
        'price_18k_gram' => $price18k,
        'source'         => $source,
        'cached'         => false,
        'fetched_at'     => date('Y-m-d H:i:s'),
    ];
}

function sgb_get_usd_inr(): float {
    $resp = @file_get_contents('https://open.er-api.com/v6/latest/USD', false,
        stream_context_create(['http' => ['timeout' => 4]]));
    if ($resp) {
        $d = json_decode($resp, true);
        if (isset($d['rates']['INR']) && $d['rates']['INR'] > 50) {
            return (float)$d['rates']['INR'];
        }
    }
    return 83.50;
}

function sgb_compute_value(float $units, float $navPerGram): float {
    return round($units * $navPerGram, 2);
}

function sgb_next_interest(string $issueDate, string $payoutType = 'semi-annual'): string {
    $issue = new DateTime($issueDate);
    $now   = new DateTime();
    $month1 = (int)$issue->format('m');
    $day1   = (int)$issue->format('d');
    $year   = (int)$now->format('Y');
    $dates  = [];
    for ($y = $year - 1; $y <= $year + 1; $y++) {
        $d1 = new DateTime(sprintf('%d-%02d-%02d', $y, $month1, $day1));
        $dates[] = $d1;
        if ($payoutType === 'semi-annual') {
            $m2 = $month1 + 6; $y2 = $y;
            if ($m2 > 12) { $m2 -= 12; $y2++; }
            $d2 = new DateTime(sprintf('%d-%02d-%02d', $y2, $m2, $day1));
            $dates[] = $d2;
        }
    }
    foreach ($dates as $d) {
        if ($d > $now) return $d->format('Y-m-d');
    }
    $next = clone $issue;
    while ($next <= $now) $next->modify('+1 year');
    return $next->format('Y-m-d');
}

function sgb_get_known_series(): array {
    return [
        ['code'=>'SGB2015-16S1', 'name'=>'SGB 2015-16 Series I',   'issue_date'=>'2015-11-30','maturity_date'=>'2023-11-30','issue_price'=>2684],
        ['code'=>'SGB2016-17S1', 'name'=>'SGB 2016-17 Series I',   'issue_date'=>'2016-08-05','maturity_date'=>'2024-08-05','issue_price'=>3119],
        ['code'=>'SGB2017-18S1', 'name'=>'SGB 2017-18 Series I',   'issue_date'=>'2017-10-23','maturity_date'=>'2025-10-23','issue_price'=>2987],
        ['code'=>'SGB2017-18S2', 'name'=>'SGB 2017-18 Series II',  'issue_date'=>'2017-11-06','maturity_date'=>'2025-11-06','issue_price'=>2961],
        ['code'=>'SGB2017-18S3', 'name'=>'SGB 2017-18 Series III', 'issue_date'=>'2017-12-11','maturity_date'=>'2025-12-11','issue_price'=>2890],
        ['code'=>'SGB2018-19S1', 'name'=>'SGB 2018-19 Series I',   'issue_date'=>'2018-04-23','maturity_date'=>'2026-04-23','issue_price'=>3114],
        ['code'=>'SGB2018-19S2', 'name'=>'SGB 2018-19 Series II',  'issue_date'=>'2018-05-07','maturity_date'=>'2026-05-07','issue_price'=>3119],
        ['code'=>'SGB2018-19S3', 'name'=>'SGB 2018-19 Series III', 'issue_date'=>'2018-06-04','maturity_date'=>'2026-06-04','issue_price'=>3114],
        ['code'=>'SGB2019-20S1', 'name'=>'SGB 2019-20 Series I',   'issue_date'=>'2019-06-11','maturity_date'=>'2027-06-11','issue_price'=>3196],
        ['code'=>'SGB2019-20S2', 'name'=>'SGB 2019-20 Series II',  'issue_date'=>'2019-07-16','maturity_date'=>'2027-07-16','issue_price'=>3443],
        ['code'=>'SGB2019-20S3', 'name'=>'SGB 2019-20 Series III', 'issue_date'=>'2019-08-12','maturity_date'=>'2027-08-12','issue_price'=>3499],
        ['code'=>'SGB2019-20S4', 'name'=>'SGB 2019-20 Series IV',  'issue_date'=>'2019-09-09','maturity_date'=>'2027-09-09','issue_price'=>3890],
        ['code'=>'SGB2019-20S5', 'name'=>'SGB 2019-20 Series V',   'issue_date'=>'2019-10-14','maturity_date'=>'2027-10-14','issue_price'=>3788],
        ['code'=>'SGB2019-20S6', 'name'=>'SGB 2019-20 Series VI',  'issue_date'=>'2019-11-18','maturity_date'=>'2027-11-18','issue_price'=>3795],
        ['code'=>'SGB2019-20S7', 'name'=>'SGB 2019-20 Series VII', 'issue_date'=>'2019-12-16','maturity_date'=>'2027-12-16','issue_price'=>3795],
        ['code'=>'SGB2019-20S8', 'name'=>'SGB 2019-20 Series VIII','issue_date'=>'2020-01-20','maturity_date'=>'2028-01-20','issue_price'=>4016],
        ['code'=>'SGB2019-20S9', 'name'=>'SGB 2019-20 Series IX',  'issue_date'=>'2020-02-10','maturity_date'=>'2028-02-10','issue_price'=>4070],
        ['code'=>'SGB2019-20S10','name'=>'SGB 2019-20 Series X',   'issue_date'=>'2020-03-11','maturity_date'=>'2028-03-11','issue_price'=>4261],
        ['code'=>'SGB2020-21S1', 'name'=>'SGB 2020-21 Series I',   'issue_date'=>'2020-04-28','maturity_date'=>'2028-04-28','issue_price'=>4590],
        ['code'=>'SGB2020-21S2', 'name'=>'SGB 2020-21 Series II',  'issue_date'=>'2020-05-19','maturity_date'=>'2028-05-19','issue_price'=>4590],
        ['code'=>'SGB2020-21S3', 'name'=>'SGB 2020-21 Series III', 'issue_date'=>'2020-06-23','maturity_date'=>'2028-06-23','issue_price'=>4677],
        ['code'=>'SGB2020-21S4', 'name'=>'SGB 2020-21 Series IV',  'issue_date'=>'2020-07-28','maturity_date'=>'2028-07-28','issue_price'=>5334],
        ['code'=>'SGB2020-21S5', 'name'=>'SGB 2020-21 Series V',   'issue_date'=>'2020-08-25','maturity_date'=>'2028-08-25','issue_price'=>5177],
        ['code'=>'SGB2020-21S6', 'name'=>'SGB 2020-21 Series VI',  'issue_date'=>'2020-09-22','maturity_date'=>'2028-09-22','issue_price'=>5051],
        ['code'=>'SGB2020-21S7', 'name'=>'SGB 2020-21 Series VII', 'issue_date'=>'2020-10-27','maturity_date'=>'2028-10-27','issue_price'=>5051],
        ['code'=>'SGB2020-21S8', 'name'=>'SGB 2020-21 Series VIII','issue_date'=>'2020-11-24','maturity_date'=>'2028-11-24','issue_price'=>5177],
        ['code'=>'SGB2020-21S9', 'name'=>'SGB 2020-21 Series IX',  'issue_date'=>'2020-12-29','maturity_date'=>'2028-12-29','issue_price'=>5000],
        ['code'=>'SGB2020-21S10','name'=>'SGB 2020-21 Series X',   'issue_date'=>'2021-01-19','maturity_date'=>'2029-01-19','issue_price'=>5104],
        ['code'=>'SGB2020-21S11','name'=>'SGB 2020-21 Series XI',  'issue_date'=>'2021-02-16','maturity_date'=>'2029-02-16','issue_price'=>4912],
        ['code'=>'SGB2020-21S12','name'=>'SGB 2020-21 Series XII', 'issue_date'=>'2021-03-16','maturity_date'=>'2029-03-16','issue_price'=>4662],
        ['code'=>'SGB2021-22S1', 'name'=>'SGB 2021-22 Series I',   'issue_date'=>'2021-05-28','maturity_date'=>'2029-05-28','issue_price'=>4777],
        ['code'=>'SGB2021-22S2', 'name'=>'SGB 2021-22 Series II',  'issue_date'=>'2021-06-01','maturity_date'=>'2029-06-01','issue_price'=>4842],
        ['code'=>'SGB2021-22S3', 'name'=>'SGB 2021-22 Series III', 'issue_date'=>'2021-08-10','maturity_date'=>'2029-08-10','issue_price'=>4790],
        ['code'=>'SGB2021-22S4', 'name'=>'SGB 2021-22 Series IV',  'issue_date'=>'2021-09-07','maturity_date'=>'2029-09-07','issue_price'=>4732],
        ['code'=>'SGB2021-22S5', 'name'=>'SGB 2021-22 Series V',   'issue_date'=>'2021-10-05','maturity_date'=>'2029-10-05','issue_price'=>4765],
        ['code'=>'SGB2021-22S6', 'name'=>'SGB 2021-22 Series VI',  'issue_date'=>'2021-11-02','maturity_date'=>'2029-11-02','issue_price'=>4791],
        ['code'=>'SGB2021-22S7', 'name'=>'SGB 2021-22 Series VII', 'issue_date'=>'2021-11-30','maturity_date'=>'2029-11-30','issue_price'=>4791],
        ['code'=>'SGB2021-22S8', 'name'=>'SGB 2021-22 Series VIII','issue_date'=>'2022-03-01','maturity_date'=>'2030-03-01','issue_price'=>5109],
        ['code'=>'SGB2022-23S1', 'name'=>'SGB 2022-23 Series I',   'issue_date'=>'2022-06-28','maturity_date'=>'2030-06-28','issue_price'=>5091],
        ['code'=>'SGB2022-23S2', 'name'=>'SGB 2022-23 Series II',  'issue_date'=>'2022-08-30','maturity_date'=>'2030-08-30','issue_price'=>5197],
        ['code'=>'SGB2022-23S3', 'name'=>'SGB 2022-23 Series III', 'issue_date'=>'2022-12-20','maturity_date'=>'2030-12-20','issue_price'=>5409],
        ['code'=>'SGB2022-23S4', 'name'=>'SGB 2022-23 Series IV',  'issue_date'=>'2023-03-06','maturity_date'=>'2031-03-06','issue_price'=>5611],
        ['code'=>'SGB2023-24S1', 'name'=>'SGB 2023-24 Series I',   'issue_date'=>'2023-06-27','maturity_date'=>'2031-06-27','issue_price'=>5926],
        ['code'=>'SGB2023-24S2', 'name'=>'SGB 2023-24 Series II',  'issue_date'=>'2023-09-18','maturity_date'=>'2031-09-18','issue_price'=>5923],
        ['code'=>'SGB2023-24S3', 'name'=>'SGB 2023-24 Series III', 'issue_date'=>'2023-12-28','maturity_date'=>'2031-12-28','issue_price'=>6199],
        ['code'=>'SGB2023-24S4', 'name'=>'SGB 2023-24 Series IV',  'issue_date'=>'2024-02-26','maturity_date'=>'2032-02-26','issue_price'=>6263],
    ];
}

// ══════════════════════════════════════════════════════════════════════════
// ACTIONS
// ══════════════════════════════════════════════════════════════════════════

if ($action === 'sgb_series_list') {
    json_response(true, 'SGB series list.', ['series' => sgb_get_known_series()]);
}

elseif ($action === 'sgb_live_price') {
    $priceData = sgb_fetch_live_gold_price();
    json_response(true, 'Gold price fetched.', $priceData);
}

elseif ($action === 'sgb_list') {
    $rows = DB::fetchAll(
        "SELECT id, series_name, tranche_code, issue_date, maturity_date,
                units, issue_price, total_invested, coupon_rate,
                current_nav, current_value, nav_updated_at,
                nse_symbol, nse_price, interest_payout, last_interest_date,
                total_interest_received, notes
         FROM sgb_holdings
         WHERE portfolio_id = ? AND is_active = 1
         ORDER BY maturity_date ASC",
        [$portfolioId]
    );

    $today = date('Y-m-d');
    foreach ($rows as &$r) {
        $r['units']                   = (float)$r['units'];
        $r['issue_price']             = (float)$r['issue_price'];
        $r['total_invested']          = (float)$r['total_invested'];
        $r['coupon_rate']             = (float)$r['coupon_rate'];
        $r['current_nav']             = $r['current_nav'] !== null ? (float)$r['current_nav'] : null;
        $r['current_value']           = $r['current_value'] !== null ? (float)$r['current_value'] : null;
        $r['total_interest_received'] = (float)$r['total_interest_received'];
        $r['gain_loss']               = $r['current_value'] !== null
                                        ? round($r['current_value'] - $r['total_invested'], 2) : null;
        $r['gain_pct']                = ($r['current_value'] && $r['total_invested'] > 0)
                                        ? round(($r['current_value'] - $r['total_invested']) / $r['total_invested'] * 100, 2) : null;
        $r['is_matured']              = $r['maturity_date'] < $today;
        $r['days_to_maturity']        = (int)round((strtotime($r['maturity_date']) - time()) / 86400);
        $r['next_interest_date']      = !$r['is_matured']
                                        ? sgb_next_interest($r['issue_date'], $r['interest_payout']) : null;
        $days = max(1, (int)round((time() - strtotime($r['issue_date'])) / 86400));
        if ($r['current_nav'] && $r['issue_price'] > 0) {
            $ratio = $r['current_nav'] / $r['issue_price'];
            $r['cagr_pct'] = round((pow($ratio, 365 / $days) - 1) * 100, 2);
        } else {
            $r['cagr_pct'] = null;
        }
        $r['annual_interest_amount'] = round($r['units'] * $r['issue_price'] * $r['coupon_rate'] / 100, 2);
        $r['total_return_with_interest'] = $r['current_value'] !== null
            ? round($r['current_value'] - $r['total_invested'] + $r['total_interest_received'], 2) : null;
    }
    unset($r);

    $summary = [
        'count'          => count($rows),
        'total_invested' => round(array_sum(array_column($rows, 'total_invested')), 2),
        'total_value'    => round(array_sum(array_filter(array_column($rows, 'current_value'))), 2),
        'total_gain'     => round(array_sum(array_filter(array_column($rows, 'gain_loss'))), 2),
        'total_interest' => round(array_sum(array_column($rows, 'total_interest_received')), 2),
        'total_units'    => round(array_sum(array_column($rows, 'units')), 4),
    ];

    json_response(true, 'SGB holdings loaded.', ['holdings' => $rows, 'summary' => $summary]);
}

elseif ($action === 'sgb_summary') {
    $row = DB::fetchRow(
        "SELECT COUNT(*) AS count,
                COALESCE(SUM(units),0) AS total_units,
                COALESCE(SUM(total_invested),0) AS total_invested,
                COALESCE(SUM(current_value),0) AS total_value,
                COALESCE(SUM(total_interest_received),0) AS total_interest
         FROM sgb_holdings WHERE portfolio_id = ? AND is_active = 1",
        [$portfolioId]
    );
    $row['total_gain'] = round((float)$row['total_value'] - (float)$row['total_invested'], 2);
    json_response(true, 'SGB summary.', $row);
}

elseif ($action === 'sgb_add') {
    $seriesName    = trim($_POST['series_name'] ?? '');
    $trancheCode   = trim($_POST['tranche_code'] ?? '');
    $issueDate     = $_POST['issue_date'] ?? null;
    $maturityDate  = $_POST['maturity_date'] ?? null;
    $units         = (float)($_POST['units'] ?? 0);
    $issuePrice    = (float)($_POST['issue_price'] ?? 0);
    $couponRate    = (float)($_POST['coupon_rate'] ?? 2.50);
    $interestPayout= $_POST['interest_payout'] ?? 'semi-annual';
    $nseSymbol     = trim($_POST['nse_symbol'] ?? '');
    $notes         = trim($_POST['notes'] ?? '');

    // Auto-fill from known series
    if ($trancheCode) {
        foreach (sgb_get_known_series() as $s) {
            if ($s['code'] === $trancheCode) {
                if (!$seriesName)   $seriesName   = $s['name'];
                if (!$issueDate)    $issueDate    = $s['issue_date'];
                if (!$maturityDate) $maturityDate = $s['maturity_date'];
                if (!$issuePrice)   $issuePrice   = $s['issue_price'];
                break;
            }
        }
    }

    if (!$seriesName || !$issueDate || !$maturityDate || $units <= 0 || $issuePrice <= 0) {
        json_response(false, 'Required: series_name, issue_date, maturity_date, units > 0, issue_price > 0.');
    }

    $totalInvested = round($units * $issuePrice, 2);
    $priceData     = sgb_fetch_live_gold_price();
    $currentNav    = $priceData['price_24k_gram'];
    $currentValue  = sgb_compute_value($units, $currentNav);

    DB::execute(
        "INSERT INTO sgb_holdings
            (portfolio_id, series_name, tranche_code, issue_date, maturity_date,
             units, issue_price, total_invested, coupon_rate,
             current_nav, current_value, nav_updated_at,
             nse_symbol, interest_payout, notes)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),?,?,?)",
        [$portfolioId, $seriesName, $trancheCode ?: null, $issueDate, $maturityDate,
         $units, $issuePrice, $totalInvested, $couponRate,
         $currentNav, $currentValue,
         $nseSymbol ?: null, $interestPayout, $notes ?: null]
    );
    $newId = $db->lastInsertId();
    json_response(true, 'SGB holding added.', [
        'id' => (int)$newId,
        'current_nav'   => $currentNav,
        'current_value' => $currentValue,
        'price_source'  => $priceData['source'],
    ]);
}

elseif ($action === 'sgb_update') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_response(false, 'id required.');
    $row = DB::fetchRow("SELECT id FROM sgb_holdings WHERE id = ? AND portfolio_id = ?", [$id, $portfolioId]);
    if (!$row) json_response(false, 'Holding not found.');

    $allowed = ['series_name','tranche_code','issue_date','maturity_date','units','issue_price',
                'coupon_rate','current_nav','current_value','nse_symbol','nse_price',
                'interest_payout','last_interest_date','total_interest_received','notes'];
    $fields = $params = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $_POST)) {
            $fields[] = "`$f` = ?";
            $params[] = $_POST[$f] === '' ? null : $_POST[$f];
        }
    }
    if (empty($fields)) json_response(false, 'Nothing to update.');
    $params[] = $id;
    DB::execute("UPDATE sgb_holdings SET " . implode(', ', $fields) . " WHERE id = ?", $params);
    json_response(true, 'SGB holding updated.');
}

elseif ($action === 'sgb_delete') {
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    if (!$id) json_response(false, 'id required.');
    $row = DB::fetchRow("SELECT id FROM sgb_holdings WHERE id = ? AND portfolio_id = ?", [$id, $portfolioId]);
    if (!$row) json_response(false, 'Holding not found.');
    DB::execute("UPDATE sgb_holdings SET is_active = 0 WHERE id = ?", [$id]);
    json_response(true, 'SGB holding removed.');
}

elseif ($action === 'sgb_refresh_nav') {
    $priceData = sgb_fetch_live_gold_price();
    $nav = $priceData['price_24k_gram'];
    $holdings = DB::fetchAll(
        "SELECT id, units FROM sgb_holdings WHERE portfolio_id = ? AND is_active = 1", [$portfolioId]
    );
    $updated = 0;
    foreach ($holdings as $h) {
        $val = sgb_compute_value((float)$h['units'], $nav);
        DB::execute(
            "UPDATE sgb_holdings SET current_nav=?, current_value=?, nav_updated_at=NOW() WHERE id=?",
            [$nav, $val, $h['id']]
        );
        $updated++;
    }
    json_response(true, "NAV refreshed for {$updated} holdings.", [
        'updated'      => $updated,
        'current_nav'  => $nav,
        'price_source' => $priceData['source'],
        'fetched_at'   => $priceData['fetched_at'],
    ]);
}

elseif ($action === 'sgb_interest_add') {
    $sgbId      = (int)($_POST['sgb_id'] ?? 0);
    $payoutDate = $_POST['payout_date'] ?? date('Y-m-d');
    $amount     = (float)($_POST['amount'] ?? 0);
    $notes      = trim($_POST['notes'] ?? '');
    if (!$sgbId || $amount <= 0) json_response(false, 'sgb_id and amount required.');
    $holding = DB::fetchRow(
        "SELECT id, units, coupon_rate FROM sgb_holdings WHERE id = ? AND portfolio_id = ?",
        [$sgbId, $portfolioId]
    );
    if (!$holding) json_response(false, 'Holding not found.');
    DB::execute(
        "INSERT INTO sgb_interest_log (sgb_id, payout_date, units, rate_pct, amount, notes)
         VALUES (?,?,?,?,?,?)",
        [$sgbId, $payoutDate, $holding['units'], $holding['coupon_rate'], $amount, $notes ?: null]
    );
    DB::execute(
        "UPDATE sgb_holdings SET total_interest_received = total_interest_received + ?,
         last_interest_date = ? WHERE id = ?",
        [$amount, $payoutDate, $sgbId]
    );
    json_response(true, 'Interest payout logged.', ['id' => (int)$db->lastInsertId()]);
}

elseif ($action === 'sgb_interest_list') {
    $sgbId = (int)($_GET['sgb_id'] ?? $_POST['sgb_id'] ?? 0);
    if (!$sgbId) json_response(false, 'sgb_id required.');
    $holding = DB::fetchRow("SELECT id FROM sgb_holdings WHERE id = ? AND portfolio_id = ?", [$sgbId, $portfolioId]);
    if (!$holding) json_response(false, 'Holding not found.');
    $rows = DB::fetchAll(
        "SELECT id, payout_date, units, rate_pct, amount, notes, created_at
         FROM sgb_interest_log WHERE sgb_id = ? ORDER BY payout_date DESC",
        [$sgbId]
    );
    json_response(true, 'Interest log loaded.', ['payouts' => $rows, 'total' => count($rows)]);
}

else {
    json_response(false, 'Unknown SGB action: ' . htmlspecialchars($action));
}
