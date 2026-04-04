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

$elapsed = round(microtime(true) - $start, 1);
echo "\n=== DONE ===\n";
echo "Funds processed : {$done}\n";
echo "Returns updated : {$updated}\n";
echo "Time taken      : {$elapsed}s\n";
echo "Finished        : " . date('Y-m-d H:i:s') . "\n";

$logMsg = date('Y-m-d H:i:s') . " | calculate_returns | Processed:{$done} Updated:{$updated} Time:{$elapsed}s\n";
@file_put_contents(APP_ROOT . '/logs/returns_calc_' . date('Y-m') . '.log', $logMsg, FILE_APPEND | LOCK_EX);