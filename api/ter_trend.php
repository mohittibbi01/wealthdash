<?php
/**
 * WealthDash — t169: Expense Ratio (TER) Trend API
 * GET ?action=ter_trend&fund_id=XXX[&months=12]
 *
 * Returns last N months of TER history + SEBI limit check + trend alert
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', '0');
ob_start();
require_auth();

set_exception_handler(function(Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit;
});

try {
    $db      = DB::conn();
    $fundId  = (int)($_GET['fund_id'] ?? 0);
    $months  = min(24, max(3, (int)($_GET['months'] ?? 12)));

    if (!$fundId) throw new InvalidArgumentException('fund_id required');

    // ── Fetch fund info ──────────────────────────────────────────────
    $fund = $db->prepare("SELECT id, scheme_name, category, expense_ratio, option_type, scheme_name FROM funds WHERE id = ?");
    $fund->execute([$fundId]);
    $fund = $fund->fetch();
    if (!$fund) throw new RuntimeException('Fund not found');

    $planType = stripos($fund['scheme_name'], 'direct') !== false ? 'direct' : 'regular';

    // ── Check if expense_ratio_history table exists ──────────────────
    $tableExists = false;
    try {
        $db->query("SELECT 1 FROM expense_ratio_history LIMIT 1");
        $tableExists = true;
    } catch (Exception $e) {}

    $history = [];
    if ($tableExists) {
        $stmt = $db->prepare("
            SELECT recorded_date, expense_ratio, plan_type
            FROM expense_ratio_history
            WHERE fund_id = ?
              AND plan_type = ?
              AND recorded_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
            ORDER BY recorded_date ASC
        ");
        $stmt->execute([$fundId, $planType, $months]);
        $history = $stmt->fetchAll();
    }

    // If no history, synthesise from current expense_ratio as single point
    if (empty($history) && $fund['expense_ratio']) {
        $history = [[
            'recorded_date' => date('Y-m-01'),
            'expense_ratio' => $fund['expense_ratio'],
            'plan_type'     => $planType,
        ]];
    }

    // ── SEBI TER limits ──────────────────────────────────────────────
    $cat = strtolower($fund['category'] ?? '');
    $sebiLimit = null;
    $sebiLabel = null;
    if (str_contains($cat, 'large cap') || str_contains($cat, 'largecap')) {
        $sebiLimit = 1.05; $sebiLabel = 'Large Cap';
    } elseif (str_contains($cat, 'mid cap') || str_contains($cat, 'midcap')) {
        $sebiLimit = 1.20; $sebiLabel = 'Mid Cap';
    } elseif (str_contains($cat, 'small cap') || str_contains($cat, 'smallcap')) {
        $sebiLimit = 1.35; $sebiLabel = 'Small Cap';
    } elseif (str_contains($cat, 'index') || str_contains($cat, 'etf')) {
        $sebiLimit = 0.50; $sebiLabel = 'Index/ETF';
    } elseif (str_contains($cat, 'debt') || str_contains($cat, 'liquid') || str_contains($cat, 'overnight')) {
        $sebiLimit = 1.00; $sebiLabel = 'Debt';
    } elseif (str_contains($cat, 'equity') || str_contains($cat, 'flexi') || str_contains($cat, 'multi')) {
        $sebiLimit = 1.05; $sebiLabel = 'Equity';
    }

    // ── Trend analysis ───────────────────────────────────────────────
    $trend     = null;
    $alert     = null;
    $trendPct  = null;

    if (count($history) >= 2) {
        $latest  = (float)end($history)['expense_ratio'];
        $oldest  = (float)reset($history)['expense_ratio'];
        $trendPct = round($latest - $oldest, 4);

        if ($trendPct > 0.0001) {
            $trend = 'rising';
        } elseif ($trendPct < -0.0001) {
            $trend = 'falling';
        } else {
            $trend = 'stable';
        }

        // 6-month alert: check last 6 months specifically
        $cutoff6m = date('Y-m-01', strtotime('-6 months'));
        $recent6m = array_filter($history, fn($r) => $r['recorded_date'] >= $cutoff6m);
        if (count($recent6m) >= 2) {
            $r6Latest  = (float)end($recent6m)['expense_ratio'];
            $r6Oldest  = (float)reset($recent6m)['expense_ratio'];
            $delta6m   = round($r6Latest - $r6Oldest, 4);
            if ($delta6m >= 0.2) {
                $alert = "⚠️ TER last 6 months mein +{$delta6m}% badha hai";
            } elseif ($delta6m <= -0.2) {
                $alert = "✅ TER last 6 months mein {$delta6m}% gira hai (achha sign)";
            }
        }
    }

    // ── SEBI breach check ────────────────────────────────────────────
    $currentTer  = (float)($fund['expense_ratio'] ?? 0);
    $sebiBreach  = ($sebiLimit !== null && $currentTer > $sebiLimit);
    $sebiMsg     = null;
    if ($sebiLimit !== null) {
        $sebiMsg = $sebiBreach
            ? "⚠️ SEBI limit breach: {$sebiLabel} max {$sebiLimit}%, current {$currentTer}%"
            : "✅ SEBI limit OK: {$sebiLabel} max {$sebiLimit}%, current {$currentTer}%";
    }

    ob_clean();
    echo json_encode([
        'success'      => true,
        'fund_id'      => $fundId,
        'plan_type'    => $planType,
        'current_ter'  => $currentTer ?: null,
        'history'      => array_values(array_map(fn($r) => [
            'date'          => $r['recorded_date'],
            'expense_ratio' => (float)$r['expense_ratio'],
        ], $history)),
        'trend'        => $trend,
        'trend_pct'    => $trendPct,
        'alert'        => $alert,
        'sebi_limit'   => $sebiLimit,
        'sebi_label'   => $sebiLabel,
        'sebi_msg'     => $sebiMsg,
        'sebi_breach'  => $sebiBreach,
        'table_exists' => $tableExists,
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
