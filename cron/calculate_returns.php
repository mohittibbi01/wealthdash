#!/usr/bin/env php
<?php
/**
 * WealthDash — t161: Calculate 1Y/3Y/5Y Returns + Sharpe + Max Drawdown
 * Run after populate_nav_history.php
 *
 * Usage:
 *   php cron/calculate_returns.php            → all funds
 *   php cron/calculate_returns.php --limit=100 → first 100
 *   php cron/calculate_returns.php --fund=119597 → single fund
 *
 * Add to daily cron AFTER nav update (runs in ~5-10 mins)
 */
define('WEALTHDASH', true);
define('RUNNING_AS_CRON', true);
require_once dirname(__FILE__) . '/../config/config.php';
require_once APP_ROOT . '/includes/helpers.php';

$start    = microtime(true);
$argv     = $argv ?? [];
$limit    = 0;
$onlyFund = '';

foreach ($argv as $arg) {
    if (preg_match('/--limit=(\d+)/', $arg, $m)) $limit    = (int)$m[1];
    if (preg_match('/--fund=(.+)/',   $arg, $m)) $onlyFund = $m[1];
}

$db = DB::conn();

// ── Ensure columns exist ────────────────────────────────────────────────────
$alterCols = [
    "ALTER TABLE funds ADD COLUMN IF NOT EXISTS returns_1y    DECIMAL(8,4) DEFAULT NULL",
    "ALTER TABLE funds ADD COLUMN IF NOT EXISTS returns_3y    DECIMAL(8,4) DEFAULT NULL",
    "ALTER TABLE funds ADD COLUMN IF NOT EXISTS returns_5y    DECIMAL(8,4) DEFAULT NULL",
    "ALTER TABLE funds ADD COLUMN IF NOT EXISTS returns_10y   DECIMAL(8,4) DEFAULT NULL",
    "ALTER TABLE funds ADD COLUMN IF NOT EXISTS sharpe_ratio  DECIMAL(8,4) DEFAULT NULL",
    "ALTER TABLE funds ADD COLUMN IF NOT EXISTS sortino_ratio DECIMAL(8,4) DEFAULT NULL",
    "ALTER TABLE funds ADD COLUMN IF NOT EXISTS max_drawdown  DECIMAL(8,4) DEFAULT NULL",
    "ALTER TABLE funds ADD COLUMN IF NOT EXISTS max_drawdown_date DATE DEFAULT NULL",
    "ALTER TABLE funds ADD COLUMN IF NOT EXISTS category_avg_1y DECIMAL(8,4) DEFAULT NULL",
    "ALTER TABLE funds ADD COLUMN IF NOT EXISTS category_avg_3y DECIMAL(8,4) DEFAULT NULL",
    "ALTER TABLE funds ADD COLUMN IF NOT EXISTS returns_updated_at DATETIME DEFAULT NULL",
];
foreach ($alterCols as $sql) {
    try { $db->exec($sql); } catch (Exception $e) { /* column might exist */ }
}

// ── Fetch funds with nav history ─────────────────────────────────────────────
$sql = "SELECT f.id, f.scheme_code, f.scheme_name
        FROM funds f
        WHERE f.is_active = 1
          AND (SELECT COUNT(*) FROM nav_history nh WHERE nh.fund_id = f.id) >= 30";
if ($onlyFund) $sql .= " AND f.scheme_code = " . $db->quote($onlyFund);
$sql .= " ORDER BY f.id ASC";
if ($limit) $sql .= " LIMIT $limit";

$funds = DB::fetchAll($sql);
$total = count($funds);

echo "=== WealthDash Returns Calculator ===\n";
echo "Funds with history: {$total}\n";
echo "Started: " . date('Y-m-d H:i:s') . "\n\n";

if (!$total) { echo "No funds with nav history found. Run populate_nav_history.php first.\n"; exit(1); }

// Prepare update statement
$updReturns = $db->prepare(
    "UPDATE funds SET
       returns_1y         = ?,
       returns_3y         = ?,
       returns_5y         = ?,
       returns_10y        = ?,
       sharpe_ratio       = ?,
       sortino_ratio      = ?,
       max_drawdown       = ?,
       max_drawdown_date  = ?,
       returns_updated_at = NOW()
     WHERE id = ?"
);

// Fetch NAV history for a fund (returns array of [date => nav])
$getHistory = $db->prepare(
    "SELECT nav_date, nav FROM nav_history
     WHERE fund_id = ? ORDER BY nav_date ASC"
);

const RISK_FREE_DAILY = 6.5 / 100 / 365; // 6.5% annual → daily

// ── CAGR helper (defined outside loop to avoid redeclaration) ────────────────
function _calc_cagr(float $startNav, float $endNav, float $years): ?float {
    if ($startNav <= 0 || $years <= 0) return null;
    return (pow($endNav / $startNav, 1 / $years) - 1) * 100;
}

$done = 0; $updated = 0;

foreach ($funds as $fund) {
    $done++;
    $pct = round($done/$total*100);
    if ($done % 50 === 0 || $done === 1) {
        echo "[{$pct}%] Processing fund {$done}/{$total}...\r";
    }

    $getHistory->execute([$fund['id']]);
    $rows = $getHistory->fetchAll(PDO::FETCH_KEY_PAIR); // [date => nav]
    if (count($rows) < 30) continue;

    $dates = array_keys($rows);
    $navs  = array_values($rows);
    $n     = count($navs);
    $today = end($dates);
    $todayNav = end($navs);

    // ── getNavOnOrBefore closure (uses $rows/$dates from current iteration) ──
    $getNavOnOrBefore = function(string $targetDate) use ($rows, $dates): ?float {
        // Binary search for closest date
        $target = $targetDate;
        if (isset($rows[$target])) return (float)$rows[$target];
        // Find closest date before target
        $closest = null;
        foreach ($dates as $d) {
            if ($d <= $target) $closest = $d;
            else break;
        }
        return $closest ? (float)$rows[$closest] : null;
    };

    // 1Y, 3Y, 5Y, 10Y returns
    $date1y  = date('Y-m-d', strtotime($today . ' -365 days'));
    $date3y  = date('Y-m-d', strtotime($today . ' -1095 days'));
    $date5y  = date('Y-m-d', strtotime($today . ' -1825 days'));
    $date10y = date('Y-m-d', strtotime($today . ' -3650 days'));

    $nav1y  = $getNavOnOrBefore($date1y);
    $nav3y  = $getNavOnOrBefore($date3y);
    $nav5y  = $getNavOnOrBefore($date5y);
    $nav10y = $getNavOnOrBefore($date10y);

    $r1y  = $nav1y  ? _calc_cagr($nav1y,  $todayNav, 1)  : null;
    $r3y  = $nav3y  ? _calc_cagr($nav3y,  $todayNav, 3)  : null;
    $r5y  = $nav5y  ? _calc_cagr($nav5y,  $todayNav, 5)  : null;
    $r10y = $nav10y ? _calc_cagr($nav10y, $todayNav, 10) : null;

    // ── Sharpe Ratio (annualised) ─────────────────────────────────────────
    // Using last 1Y daily returns
    $sharpe  = null;
    $sortino = null;
    if ($nav1y && $n >= 250) {
        // Get last 252 trading days
        $lastN   = array_slice($navs, max(0, $n-253), 253);
        $dailyRet= [];
        for ($i = 1; $i < count($lastN); $i++) {
            if ($lastN[$i-1] > 0) {
                $dailyRet[] = ($lastN[$i] - $lastN[$i-1]) / $lastN[$i-1];
            }
        }
        if (count($dailyRet) > 30) {
            $avgRet = array_sum($dailyRet) / count($dailyRet);
            $variance = array_sum(array_map(fn($r) => pow($r - $avgRet, 2), $dailyRet)) / count($dailyRet);
            $stdDev = sqrt($variance);
            if ($stdDev > 0) {
                $annualisedReturn = pow(1 + $avgRet, 252) - 1;
                $annualisedStdDev = $stdDev * sqrt(252);
                $sharpe = round(($annualisedReturn - 0.065) / $annualisedStdDev, 4);
            }

            // ── t165: Sortino Ratio — only downside deviation ──────────────
            $negReturns    = array_filter($dailyRet, fn($r) => $r < 0);
            if (count($negReturns) > 5) {
                $downVar       = array_sum(array_map(fn($r) => pow($r, 2), $negReturns)) / count($dailyRet);
                $downStdDev    = sqrt($downVar);
                if ($downStdDev > 0) {
                    $annualisedReturn = pow(1 + $avgRet, 252) - 1;
                    $annDownStdDev    = $downStdDev * sqrt(252);
                    $sortino = round(($annualisedReturn - 0.065) / $annDownStdDev, 4);
                }
            }
        }
    }

    // ── Maximum Drawdown ─────────────────────────────────────────────────
    $maxDrawdown = null;
    $maxDrawdownDate = null;
    $peakNav  = 0;
    $peakDate = '';
    foreach ($rows as $date => $nav) {
        $nav = (float)$nav;
        if ($nav > $peakNav) { $peakNav = $nav; $peakDate = $date; }
        if ($peakNav > 0) {
            $dd = ($peakNav - $nav) / $peakNav * 100;
            if ($maxDrawdown === null || $dd > $maxDrawdown) {
                $maxDrawdown     = $dd;
                $maxDrawdownDate = $date;
            }
        }
    }
    $maxDrawdown = $maxDrawdown ? round($maxDrawdown, 4) : null;

    // ── Update fund ───────────────────────────────────────────────────────
    $updReturns->execute([
        $r1y  !== null ? round($r1y, 4)  : null,
        $r3y  !== null ? round($r3y, 4)  : null,
        $r5y  !== null ? round($r5y, 4)  : null,
        $r10y !== null ? round($r10y, 4) : null,
        $sharpe,
        $sortino,
        $maxDrawdown,
        $maxDrawdownDate,
        $fund['id'],
    ]);
    $updated++;
}

echo "\n";

// ── Calculate category averages ─────────────────────────────────────────────
echo "Calculating category averages...\n";
try {
    $db->exec("
        UPDATE funds f
        JOIN (
            SELECT category,
                   AVG(CASE WHEN returns_1y IS NOT NULL THEN returns_1y END) AS avg_1y,
                   AVG(CASE WHEN returns_3y IS NOT NULL THEN returns_3y END) AS avg_3y
            FROM funds
            WHERE is_active=1 AND category IS NOT NULL
            GROUP BY category
        ) cat ON cat.category = f.category
        SET f.category_avg_1y = ROUND(cat.avg_1y, 4),
            f.category_avg_3y = ROUND(cat.avg_3y, 4)
        WHERE f.is_active = 1
    ");
    echo "✅ Category averages updated\n";
} catch (Exception $e) {
    echo "⚠️  Category averages failed: {$e->getMessage()}\n";
}

// ── t111: Fund Ratings — WealthDash internal star rating (1-5 ⭐) ──────────
// Formula: Returns(40%) + Consistency(25%) + Risk(20%) + Expense(15%)
echo "Calculating WD star ratings (t111)...\n";
try {
    // Check if fund_ratings table exists
    $db->query("SELECT 1 FROM fund_ratings LIMIT 1");

    $rateFunds = $db->query("
        SELECT f.id, f.scheme_name, f.returns_1y, f.returns_3y, f.returns_5y,
               f.expense_ratio, f.max_drawdown_pct, f.sharpe_ratio,
               f.category_avg_1y, f.category_avg_3y
        FROM funds f
        WHERE f.is_active = 1
    ")->fetchAll(PDO::FETCH_ASSOC);

    $rateStmt = $db->prepare("
        INSERT INTO fund_ratings (fund_id, stars, score_total, score_breakdown)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            stars=VALUES(stars),
            score_total=VALUES(score_total),
            score_breakdown=VALUES(score_breakdown),
            rated_at=NOW()
    ");
    $starStmt = $db->prepare("UPDATE funds SET wd_stars=? WHERE id=?");

    $rated = 0;
    foreach ($rateFunds as $f) {
        // -- Returns score (0–3): use 3Y if available, else 1Y
        $ret       = $f['returns_3y'] ?? $f['returns_1y'] ?? null;
        $retScore  = 0;
        $retLabel  = 'No data';
        if ($ret !== null) {
            if ($ret >= 20)     { $retScore = 3;   $retLabel = '≥20% excellent'; }
            elseif ($ret >= 15) { $retScore = 2.5; $retLabel = '≥15% very good'; }
            elseif ($ret >= 10) { $retScore = 2;   $retLabel = '≥10% good'; }
            elseif ($ret >= 5)  { $retScore = 1;   $retLabel = '≥5% below avg'; }
            else                { $retScore = 0.5; $retLabel = '<5% weak'; }
        }

        // -- Consistency score (0–1): vs category average, or sharpe proxy
        $conScore = 0.5; // neutral default
        $catAvg   = $f['returns_3y'] !== null ? ($f['category_avg_3y'] ?? null) : ($f['category_avg_1y'] ?? null);
        if ($catAvg !== null && $ret !== null) {
            $diff = $ret - $catAvg;
            if ($diff >= 5)       $conScore = 1;
            elseif ($diff >= 2)   $conScore = 0.75;
            elseif ($diff >= 0)   $conScore = 0.5;
            elseif ($diff >= -3)  $conScore = 0.25;
            else                  $conScore = 0;
        } elseif ($f['sharpe_ratio'] !== null) {
            $sh = (float)$f['sharpe_ratio'];
            if ($sh >= 1.5)      $conScore = 1;
            elseif ($sh >= 1)    $conScore = 0.75;
            elseif ($sh >= 0.5)  $conScore = 0.5;
            else                 $conScore = 0.25;
        }

        // -- Risk/Drawdown score (0–1): lower drawdown = better
        $ddScore = 0.5; // neutral
        if ($f['max_drawdown_pct'] !== null) {
            $dd = (float)$f['max_drawdown_pct'];
            if ($dd <= 5)        $ddScore = 1;
            elseif ($dd <= 10)   $ddScore = 0.75;
            elseif ($dd <= 20)   $ddScore = 0.5;
            elseif ($dd <= 35)   $ddScore = 0.25;
            else                 $ddScore = 0;
        }

        // -- Expense score (0–0.5): Direct plan bonus + low TER
        $expScore = 0.25; // neutral
        $isDirect = stripos($f['scheme_name'], 'direct') !== false;
        if ($f['expense_ratio'] !== null) {
            $exp = (float)$f['expense_ratio'];
            if ($exp < 0.5)      $expScore = 0.5;
            elseif ($exp < 1)    $expScore = 0.4;
            elseif ($exp < 1.5)  $expScore = 0.3;
            else                 $expScore = 0.15;
        }
        if ($isDirect) $expScore = min(0.5, $expScore + 0.1); // Direct plan bonus

        // -- Weighted total: Returns(40%→max3) + Consistency(25%→max1) + Risk(20%→max1) + Expense(15%→max0.5)
        // Normalised to 0–5 scale
        $total = ($retScore * (40/60)) + ($conScore * 1.25) + ($ddScore * 1.0) + ($expScore * 1.5);
        $stars = min(5, max(1, (int)round($total)));

        $breakdown = json_encode([
            'returns'     => round($retScore, 3),
            'consistency' => round($conScore, 3),
            'drawdown'    => round($ddScore, 3),
            'expense'     => round($expScore, 3),
            'ret_label'   => $retLabel,
            'is_direct'   => $isDirect,
        ], JSON_UNESCAPED_UNICODE);

        $rateStmt->execute([$f['id'], $stars, round($total, 4), $breakdown]);
        $starStmt->execute([$stars, $f['id']]);
        $rated++;
    }
    echo "✅ Fund ratings updated: {$rated} funds rated\n";
} catch (Exception $e) {
    echo "⚠️  Fund ratings skipped: {$e->getMessage()}\n";
    echo "   Run database/23_fund_ratings.sql first to create fund_ratings table\n";
}

$elapsed = round(microtime(true) - $start, 1);
echo "\n=== DONE ===\n";
echo "Funds processed : {$done}\n";
echo "Returns updated : {$updated}\n";
echo "Time taken      : {$elapsed}s\n";
echo "Finished        : " . date('Y-m-d H:i:s') . "\n";

$logMsg = date('Y-m-d H:i:s') . " | calculate_returns | Processed:{$done} Updated:{$updated} Time:{$elapsed}s\n";
@file_put_contents(APP_ROOT . '/logs/returns_calc_' . date('Y-m') . '.log', $logMsg, FILE_APPEND | LOCK_EX);
// ── t179: Style Box Classification ─────────────────────────────────────────
// Category-based heuristic (no AMFI holdings dependency)
echo "\nClassifying Style Boxes (t179)...\n";
try {
    // Ensure columns exist (migration 24_style_box.sql may not have run)
    $db->exec("ALTER TABLE funds ADD COLUMN IF NOT EXISTS style_size  ENUM('large','mid','small') DEFAULT NULL");
    $db->exec("ALTER TABLE funds ADD COLUMN IF NOT EXISTS style_value ENUM('value','blend','growth') DEFAULT NULL");
    $db->exec("ALTER TABLE funds ADD COLUMN IF NOT EXISTS style_drift_note VARCHAR(500) DEFAULT NULL");
    $db->exec("ALTER TABLE funds ADD COLUMN IF NOT EXISTS style_updated_at DATETIME DEFAULT NULL");

    $styleFunds = $db->query("SELECT id, scheme_name, category FROM funds WHERE is_active=1")->fetchAll(PDO::FETCH_ASSOC);

    $styleStmt = $db->prepare(
        "UPDATE funds SET style_size=?, style_value=?, style_drift_note=?, style_updated_at=NOW() WHERE id=?"
    );

    // ── Size classification ──────────────────────────────────────────────
    function classify_size(string $cat): ?string {
        $c = strtolower($cat);
        if (str_contains($c, 'large cap') || str_contains($c, 'large-cap'))          return 'large';
        if (str_contains($c, 'mid cap')   || str_contains($c, 'mid-cap'))             return 'mid';
        if (str_contains($c, 'small cap') || str_contains($c, 'small-cap'))           return 'small';
        if (str_contains($c, 'large & mid') || str_contains($c, 'large and mid'))     return 'large';
        // Index funds — guess from index name
        if (str_contains($c, 'nifty 50') || str_contains($c, 'sensex'))              return 'large';
        if (str_contains($c, 'midcap') || str_contains($c, 'nifty mid'))             return 'mid';
        if (str_contains($c, 'smallcap') || str_contains($c, 'nifty small'))         return 'small';
        if (str_contains($c, 'multi cap') || str_contains($c, 'flexi cap'))          return 'mid';
        if (str_contains($c, 'elss') || str_contains($c, 'tax sav'))                 return 'large';
        if (str_contains($c, 'focused'))                                              return 'large';
        if (str_contains($c, 'equity') || str_contains($c, 'hybrid'))                return 'large';
        return null; // Debt, Gold, FoF etc — no size
    }

    // ── Style (value/blend/growth) classification ───────────────────────
    function classify_style(string $cat, string $name): ?string {
        $c = strtolower($cat); $n = strtolower($name);
        if (str_contains($c, 'value') || str_contains($c, 'contra') ||
            str_contains($c, 'dividend yield'))                                        return 'value';
        if (str_contains($c, 'index') || str_contains($c, 'etf') ||
            str_contains($c, 'passive'))                                               return 'blend';
        if (str_contains($c, 'momentum') || str_contains($c, 'thematic') ||
            str_contains($c, 'sectoral') || str_contains($c, 'technology') ||
            str_contains($n, 'technology') || str_contains($n, 'innovation') ||
            str_contains($n, 'momentum') || str_contains($n, 'opportunities'))        return 'growth';
        if (str_contains($c, 'equity') || str_contains($c, 'hybrid') ||
            str_contains($c, 'elss') || str_contains($c, 'tax sav') ||
            str_contains($c, 'flexi') || str_contains($c, 'multi cap') ||
            str_contains($c, 'large') || str_contains($c, 'mid cap') ||
            str_contains($c, 'small cap') || str_contains($c, 'focused'))             return 'blend';
        return null;
    }

    // ── Drift detection ──────────────────────────────────────────────────
    function detect_drift(string $cat, string $name): ?string {
        $c = strtolower($cat); $n = strtolower($name);
        // Fund says Large Cap but name suggests mid/small exposure
        if (str_contains($c, 'large cap')) {
            if (str_contains($n, 'mid') || str_contains($n, 'small'))
                return 'Declared Large Cap but name suggests blended exposure — verify holdings';
        }
        // Index fund — no drift possible by definition
        if (str_contains($c, 'index') || str_contains($c, 'etf')) return null;
        // Regular plan of a value fund
        if (str_contains($c, 'value') && !str_contains($n, 'direct') &&
            str_contains($n, 'opportunities'))
            return 'Category: Value Fund but name suggests growth tilt';
        return null;
    }

    $styleUpdated = 0; $styleSkipped = 0;
    foreach ($styleFunds as $f) {
        $sz    = classify_size($f['category'] ?? '');
        $sv    = classify_style($f['category'] ?? '', $f['scheme_name'] ?? '');
        $drift = detect_drift($f['category'] ?? '', $f['scheme_name'] ?? '');

        if ($sz === null && $sv === null) { $styleSkipped++; continue; }

        $styleStmt->execute([$sz, $sv, $drift, $f['id']]);
        $styleUpdated++;
    }
    echo "✅ Style Box classified: {$styleUpdated} funds updated, {$styleSkipped} skipped (debt/other)\n";
} catch (Exception $e) {
    echo "⚠️  Style Box classification failed: {$e->getMessage()}\n";
}
