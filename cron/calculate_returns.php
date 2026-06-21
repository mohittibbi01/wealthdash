<?php
/**
 * WealthDash — Returns Calculator Cron
 * Tasks: t161, t164, t165, t166, t167, t270
 * Run: php cron/calculate_returns.php [--fund=123] [--limit=500]
 * Schedule: daily after update_nav_daily.php
 */

define('WEALTHDASH', true);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/cron_logger.php';
$_cronLog = new CronLogger('calculate_returns');
$_cronLog->start();

require_once __DIR__ . '/../includes/holding_calculator.php';

$fundFilter = null;
$limit      = 500;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--fund='))  $fundFilter = (int)substr($arg, 7);
    if (str_starts_with($arg, '--limit=')) $limit      = (int)substr($arg, 8);
}

$db  = DB::conn();
$log = fn(string $msg) => print('[' . date('H:i:s') . '] ' . $msg . PHP_EOL);

$log("WealthDash Returns Calculator starting...");

// ── Ensure required columns ──────────────────────────────────────────────
try {
    $db->exec("
        ALTER TABLE funds
          ADD COLUMN IF NOT EXISTS returns_1y       DECIMAL(8,4) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS returns_3y       DECIMAL(8,4) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS returns_5y       DECIMAL(8,4) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS returns_6m       DECIMAL(8,4) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS returns_1m       DECIMAL(8,4) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS returns_3m       DECIMAL(8,4) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS sharpe_ratio     DECIMAL(8,4) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS sortino_ratio    DECIMAL(8,4) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS calmar_ratio     DECIMAL(8,4) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS max_drawdown     DECIMAL(8,4) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS standard_deviation DECIMAL(8,4) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS alpha            DECIMAL(8,4) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS beta             DECIMAL(8,4) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS momentum_score   DECIMAL(5,2) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS category_avg_1y  DECIMAL(8,4) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS category_avg_3y  DECIMAL(8,4) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS category_avg_5y  DECIMAL(8,4) DEFAULT NULL,
          ADD COLUMN IF NOT EXISTS returns_updated_at DATETIME   DEFAULT NULL
    ");
} catch (Exception $e) { /* columns may already exist */ }

// ── Get funds to process ─────────────────────────────────────────────────
$fundWhere = $fundFilter ? "AND f.id = $fundFilter" : '';
$funds = $db->query("
    SELECT f.id, f.scheme_code, f.fund_name, f.category, f.current_nav
    FROM funds f
    WHERE f.is_active = 1 $fundWhere
    ORDER BY f.id
    LIMIT $limit
")->fetchAll(PDO::FETCH_ASSOC);

$log("Processing " . count($funds) . " funds...");
$done = $skip = $fail = 0;

foreach ($funds as $fund) {
    $fid  = (int)$fund['id'];
    $code = $fund['scheme_code'];

    try {
        // Fetch NAV history
        $navRows = $db->prepare("
            SELECT nav_date, nav
            FROM nav_history
            WHERE fund_id = ?
            ORDER BY nav_date DESC
            LIMIT 1826
        ");
        $navRows->execute([$fid]);
        $navData = $navRows->fetchAll(PDO::FETCH_ASSOC);

        if (count($navData) < 30) { $skip++; continue; }

        // Reverse to chronological order
        $navData  = array_reverse($navData);
        $navVals  = array_column($navData, 'nav', 'nav_date');
        $latestNav = (float)end($navData)['nav'];
        $dates    = array_keys($navVals);

        // Helper: get NAV ~N days ago
        $navNDaysAgo = function(int $days) use ($navData, $latestNav): ?float {
            $target = strtotime("-$days days");
            $best = null; $bestDiff = PHP_INT_MAX;
            foreach ($navData as $row) {
                $ts   = strtotime($row['nav_date']);
                $diff = abs($ts - $target);
                if ($diff < $bestDiff) { $bestDiff = $diff; $best = (float)$row['nav']; }
            }
            return $best;
        };

        // Daily returns array
        $dailyReturns = [];
        for ($i = 1; $i < count($navData); $i++) {
            $prev = (float)$navData[$i-1]['nav'];
            $curr = (float)$navData[$i]['nav'];
            if ($prev > 0) $dailyReturns[] = ($curr - $prev) / $prev * 100;
        }

        // CAGR calculations
        $nav1m  = $navNDaysAgo(30);
        $nav3m  = $navNDaysAgo(90);
        $nav6m  = $navNDaysAgo(180);
        $nav1y  = $navNDaysAgo(365);
        $nav3y  = $navNDaysAgo(1095);
        $nav5y  = $navNDaysAgo(1825);

        $r1m  = $nav1m  > 0 ? round(($latestNav/$nav1m  - 1)*100, 4) : null;
        $r3m  = $nav3m  > 0 ? round(($latestNav/$nav3m  - 1)*100, 4) : null;
        $r6m  = $nav6m  > 0 ? round(($latestNav/$nav6m  - 1)*100, 4) : null;
        $r1y  = $nav1y  > 0 ? HoldingCalculator::cagr($nav1y,  $latestNav, 1)   : null;
        $r3y  = $nav3y  > 0 ? HoldingCalculator::cagr($nav3y,  $latestNav, 3)   : null;
        $r5y  = $nav5y  > 0 ? HoldingCalculator::cagr($nav5y,  $latestNav, 5)   : null;

        // Risk metrics
        $sharpe  = HoldingCalculator::sharpeRatio($dailyReturns);
        $sortino = HoldingCalculator::sortinoRatio($dailyReturns);
        $allNavs = array_column($navData, 'nav');
        $maxDD   = HoldingCalculator::maxDrawdown(array_map('floatval', $allNavs));
        $calmar  = ($maxDD && $maxDD > 0 && $r1y) ? round($r1y / $maxDD, 4) : null;

        // Standard deviation (annualised)
        $mean   = array_sum($dailyReturns) / count($dailyReturns);
        $var    = array_sum(array_map(fn($x) => pow($x - $mean, 2), $dailyReturns)) / (count($dailyReturns) - 1);
        $stdDev = round(sqrt($var) * sqrt(252), 4);

        // Momentum score: 40%×1M + 30%×3M + 20%×6M + 10%×1Y normalised 0-100
        $momentum = null;
        if ($r1m !== null && $r3m !== null) {
            $raw  = ($r1m * 0.4) + ($r3m * 0.3) + (($r6m ?? 0) * 0.2) + (($r1y ?? 0) * 0.1);
            $momentum = round(max(0, min(100, ($raw + 30) / 90 * 100)), 2);
        }

        // Update funds table
        $stmt = $db->prepare("
            UPDATE funds SET
              returns_1m = ?, returns_3m = ?, returns_6m = ?,
              returns_1y = ?, returns_3y = ?, returns_5y = ?,
              sharpe_ratio = ?, sortino_ratio = ?, calmar_ratio = ?,
              max_drawdown = ?, standard_deviation = ?,
              momentum_score = ?, returns_updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$r1m, $r3m, $r6m, $r1y, $r3y, $r5y,
                        $sharpe, $sortino, $calmar,
                        $maxDD, $stdDev, $momentum, $fid]);
        $done++;

    } catch (Exception $e) {
        $log("  ERROR fund $fid ($code): " . $e->getMessage());
        $fail++;
    }
}

$log("Pass 1 done. Done=$done, Skipped=$skip, Failed=$fail");

// ── Category Averages ────────────────────────────────────────────────────
$log("Computing category averages...");
$categories = $db->query("SELECT DISTINCT category FROM funds WHERE category IS NOT NULL AND returns_1y IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);

foreach ($categories as $cat) {
    $avgs = $db->prepare("
        SELECT AVG(returns_1y) AS a1y, AVG(returns_3y) AS a3y, AVG(returns_5y) AS a5y
        FROM funds WHERE category = ? AND returns_1y IS NOT NULL
    ");
    $avgs->execute([$cat]);
    $a = $avgs->fetch(PDO::FETCH_ASSOC);

    $db->prepare("
        UPDATE funds SET category_avg_1y = ?, category_avg_3y = ?, category_avg_5y = ?
        WHERE category = ?
    ")->execute([$a['a1y'], $a['a3y'], $a['a5y'], $cat]);
}
$log("Category averages updated for " . count($categories) . " categories.");

// ── Fund Ratings ─────────────────────────────────────────────────────────
$log("Computing fund ratings (1-5 stars)...");
$allFunds = $db->query("
    SELECT id, returns_1y, returns_3y, category_avg_1y, sharpe_ratio,
           sortino_ratio, expense_ratio, standard_deviation, max_drawdown, manager_since
    FROM funds WHERE is_active = 1 AND returns_1y IS NOT NULL
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($allFunds as $f) {
    $fid = (int)$f['id'];
    $returnScore  = 0; $consistScore = 0; $riskScore = 0; $costScore = 0; $mgrScore = 0;

    // Returns (0-30): vs category avg
    if ($f['returns_1y'] && $f['category_avg_1y']) {
        $diff = (float)$f['returns_1y'] - (float)$f['category_avg_1y'];
        $returnScore = $diff > 5 ? 30 : ($diff > 2 ? 24 : ($diff > 0 ? 18 : ($diff > -2 ? 12 : 6)));
    }
    // Consistency (0-25): Sharpe
    $sh = (float)($f['sharpe_ratio'] ?? 0);
    $consistScore = $sh >= 1.5 ? 25 : ($sh >= 1 ? 20 : ($sh >= 0.5 ? 14 : 7));
    // Risk (0-20): max drawdown
    $dd = (float)($f['max_drawdown'] ?? 30);
    $riskScore = $dd < 10 ? 20 : ($dd < 20 ? 15 : ($dd < 30 ? 10 : 5));
    // Cost (0-15): expense ratio
    $er = (float)($f['expense_ratio'] ?? 1.5);
    $costScore = $er < 0.3 ? 15 : ($er < 0.7 ? 12 : ($er < 1.0 ? 9 : ($er < 1.5 ? 6 : 3)));
    // Manager tenure (0-10)
    if ($f['manager_since']) {
        $yrs = (time() - strtotime($f['manager_since'])) / 31536000;
        $mgrScore = $yrs >= 5 ? 10 : ($yrs >= 3 ? 7 : ($yrs >= 1 ? 4 : 2));
    } else { $mgrScore = 5; }

    $total = $returnScore + $consistScore + $riskScore + $costScore + $mgrScore;
    $stars = $total >= 85 ? 5 : ($total >= 70 ? 4 : ($total >= 55 ? 3 : ($total >= 40 ? 2 : 1)));

    // Upsert fund_ratings
    $db->prepare("
        INSERT INTO fund_ratings (fund_id, rating_stars, return_score, consistency_score,
                                   risk_score, cost_score, manager_score, total_score, calc_date)
        VALUES (?,?,?,?,?,?,?,?,CURDATE())
        ON DUPLICATE KEY UPDATE
          rating_stars=VALUES(rating_stars), return_score=VALUES(return_score),
          consistency_score=VALUES(consistency_score), risk_score=VALUES(risk_score),
          cost_score=VALUES(cost_score), manager_score=VALUES(manager_score),
          total_score=VALUES(total_score), calc_date=CURDATE()
    ")->execute([$fid, $stars, $returnScore, $consistScore, $riskScore, $costScore, $mgrScore, $total]);

    // Update denormalised columns on funds table
    $db->prepare("UPDATE funds SET rating_stars=?, health_score=? WHERE id=?")->execute([$stars, $total, $fid]);
}
$log("Fund ratings computed.");
$log("All done. Total: done=$done skip=$skip fail=$fail");
$_cronLog->finish($fail > 0 ? 'warning' : 'success', "Updated $done funds, skipped $skip, failed $fail", $done);

