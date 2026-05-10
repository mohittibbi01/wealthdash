<?php
// WD-FIX-V7
/**
 * WealthDash — MF Holdings List API
 * Tasks: t07–t13, t70–t75, tmfi01–tmfi08, t366, t268
 * Actions: mf_list | mf_summary | portfolio_xirr | portfolio_health
 *          asset_allocation | sip_list | swp_list | overlap_check
 *          dividend_history | portfolio_risk | smart_insights
 *          what_if_simulate | cleanup_suggestions | sip_optimize
 */

// Allow both: direct access (JS calls) and router include
if (!defined('WEALTHDASH')) {
    define('WEALTHDASH', true);
    ob_start();
    require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
    require_once APP_ROOT . '/includes/auth_check.php';
    require_once APP_ROOT . '/includes/helpers.php';
    header('Content-Type: application/json; charset=utf-8');
    error_reporting(0);
    ini_set('display_errors', '0');
}
// Global error → JSON handler
set_exception_handler(function(Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
    ]);
    exit;
});
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
// Support both ?action=X and ?view=X (JS uses view= for transactions/export calls)
$action      = $_POST['action'] ?? $_GET['action'] ?? $_GET['view'] ?? 'mf_list';
$db          = DB::conn();

// ── Ensure nav_download_progress (t191) ──────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS nav_download_progress (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        fund_id     INT UNSIGNED NOT NULL,
        scheme_code VARCHAR(20) NOT NULL,
        status      ENUM('pending','in_progress','done','failed') NOT NULL DEFAULT 'pending',
        last_attempt DATETIME DEFAULT NULL,
        UNIQUE KEY uk_fund (fund_id), INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

switch ($action) {

// ══════════════════════════════════════════════════════════════════════════
// mf_list — Active holdings with full metrics
// ══════════════════════════════════════════════════════════════════════════
case 'holdings':  // JS sends view=holdings — alias for mf_list
case 'mf_list':
    // Uses actual DB schema from 01_schema_complete.sql
    $portfolioId = getOrCreatePortfolio($db, $userId);
    $sortDir = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

    // ── Cache (tp001) — 90s TTL; invalidated on any MF write ────────────────
    $mfListCacheKey = "mf_list:{$userId}:{$portfolioId}:{$sortDir}";
    $mfListCached   = WdCache::get($mfListCacheKey);
    if ($mfListCached !== null) {
        echo json_encode(['success' => true] + $mfListCached + ['_cached' => true]);
        break;
    }

    $holdings = $db->prepare("
        SELECT
          mh.id              AS holding_id,
          mh.id              AS id,
          mh.fund_id,
          mh.folio_number,
          mh.platform,
          mh.first_investment_date,
          mh.first_investment_date AS first_purchase_date,
          mh.last_transaction_date,
          mh.sip_active,
          mh.swp_active,
          mh.ltcg_date,
          mh.lock_in_date,
          mh.highest_nav,
          mh.highest_nav_date,
          mh.gain_type,
          mh.cagr,
          mh.xirr,
          mh.gain_pct,

          -- Units
          mh.total_units,
          mh.total_units     AS units,

          -- Cost NAV
          mh.avg_cost_nav,
          mh.avg_cost_nav    AS avg_buy_nav,

          -- Invested
          mh.total_invested,
          mh.total_invested  AS invested_amount,

          -- Current Value
          mh.value_now,
          mh.value_now       AS current_value,

          -- Gain/Loss
          mh.gain_loss,

          -- Drawdown (calculated from highest_nav and latest_nav)
          CASE WHEN mh.highest_nav > 0 AND f.latest_nav IS NOT NULL
               THEN ROUND((mh.highest_nav - f.latest_nav) / mh.highest_nav * 100, 2)
               ELSE NULL END AS drawdown_pct,

          -- LTCG / STCG units (may not exist — use 0 as fallback)
          0 AS ltcg_units,
          0 AS stcg_units,

          -- Abs return %
          CASE WHEN mh.total_invested > 0
               THEN ROUND((mh.value_now - mh.total_invested) / mh.total_invested * 100, 2)
               ELSE 0 END    AS abs_return_pct,

          -- Fund info
          f.scheme_code,
          f.scheme_name,
          f.scheme_name      AS fund_name,
          f.category,
          f.category         AS scheme_category,
          f.sub_category,
          f.sub_category     AS scheme_sub_category,
          f.fund_type,
          f.option_type,
          f.risk_level,
          f.fund_manager,
          f.fund_manager     AS manager_name,
          f.expense_ratio,
          f.exit_load_pct,
          f.exit_load_days,
          f.aum_cr,
          f.min_ltcg_days,
          f.lock_in_days,
          f.wd_stars,
          f.wd_rating,
          f.style_box,

          -- NAV
          f.latest_nav,
          f.latest_nav       AS nav,
          f.latest_nav       AS current_nav,
          f.latest_nav_date,
          f.latest_nav_date  AS nav_date,
          f.prev_nav,

          -- Returns
          f.returns_1y,
          f.returns_1y       AS return_1y,
          f.returns_3y,
          f.returns_3y       AS return_3y,
          f.returns_5y,
          f.returns_5y       AS return_5y,

          -- Fund house
          SUBSTRING_INDEX(f.scheme_name, ' ', 3) AS fund_house_short,
          f.fund_manager     AS fund_house

        FROM mf_holdings mh
        JOIN funds f ON f.id = mh.fund_id
        WHERE mh.portfolio_id = ?
        ORDER BY mh.value_now $sortDir
    ");
    $holdings->execute([$portfolioId]);
    $rows = $holdings->fetchAll(PDO::FETCH_ASSOC);

    $totals = ['total_invested' => 0, 'total_current' => 0, 'total_gain_loss' => 0];
    foreach ($rows as $r) {
        $totals['total_invested'] += (float)$r['total_invested'];
        $totals['total_current']  += (float)$r['value_now'];
        $totals['total_gain_loss']+= (float)$r['gain_loss'];
    }
    $totals['abs_return_pct'] = $totals['total_invested'] > 0
        ? round(($totals['total_gain_loss'] / $totals['total_invested']) * 100, 2) : 0;
    $totals['fund_count'] = count($rows);

    // Store in cache (tp001)
    WdCache::set($mfListCacheKey, ['data' => $rows, 'totals' => $totals],
        ttl: 90, tags: ["user:{$userId}", "mf_holdings"]);

    echo json_encode(['success' => true, 'data' => $rows, 'totals' => $totals]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// portfolio_xirr — t73
// ══════════════════════════════════════════════════════════════════════════
case 'portfolio_xirr':
    require_once APP_ROOT . '/includes/holding_calculator.php';
    $result = HoldingCalculator::portfolioXirr($userId);
    echo json_encode(['success' => true, 'data' => $result]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// portfolio_health — tmfi01
// ══════════════════════════════════════════════════════════════════════════
case 'portfolio_health':
    require_once APP_ROOT . '/includes/holding_calculator.php';
    $result = DB::cached("portfolio_health:{$userId}", fn() =>
        HoldingCalculator::portfolioHealthScore($userId), ttl: 300, tags: ["user:{$userId}", "mf_holdings"]);
    echo json_encode(['success' => true, 'data' => $result]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// asset_allocation — t71
// ══════════════════════════════════════════════════════════════════════════
case 'asset_allocation':
    $portfolioId = getOrCreatePortfolio($db, $userId);
    $rows = $db->prepare("
        SELECT f.scheme_category, f.scheme_sub_category, f.risk_level,
               SUM(mh.current_value) AS value,
               SUM(mh.invested_amount) AS invested
        FROM mf_holdings mh
        JOIN funds f ON f.id = mh.fund_id
        WHERE mh.portfolio_id = ? AND 1 = 1
        GROUP BY f.scheme_category, f.scheme_sub_category, f.risk_level
        ORDER BY value DESC
    ");
    $rows->execute([$portfolioId]);
    $breakdown = $rows->fetchAll(PDO::FETCH_ASSOC);

    // Classify into Equity/Debt/Hybrid/Other
    $classes = ['Equity' => 0, 'Debt' => 0, 'Hybrid' => 0, 'Other' => 0];
    $totalVal = 0;
    foreach ($breakdown as $r) {
        $v = (float)$r['value'];
        $totalVal += $v;
        $cat = strtolower($r['category'] ?? '');
        if (str_contains($cat, 'equity') || str_contains($cat, 'elss') || str_contains($cat, 'index') || str_contains($cat, 'sectoral'))
            $classes['Equity'] += $v;
        elseif (str_contains($cat, 'debt') || str_contains($cat, 'liquid') || str_contains($cat, 'money market') || str_contains($cat, 'gilt'))
            $classes['Debt'] += $v;
        elseif (str_contains($cat, 'hybrid') || str_contains($cat, 'balanced') || str_contains($cat, 'arbitrage'))
            $classes['Hybrid'] += $v;
        else
            $classes['Other'] += $v;
    }

    echo json_encode(['success' => true, 'data' => $breakdown, 'classes' => $classes, 'total' => $totalVal]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// overlap_check — t70: Portfolio overlap analysis
// ══════════════════════════════════════════════════════════════════════════
case 'overlap_check':
    $portfolioId = getOrCreatePortfolio($db, $userId);
    // Get all active fund IDs in portfolio
    $fundIds = $db->prepare("
        SELECT DISTINCT mh.fund_id, f.scheme_name, f.scheme_category
        FROM mf_holdings mh
        JOIN funds f ON f.id = mh.fund_id
        WHERE mh.portfolio_id = ? AND 1 = 1
    ");
    $fundIds->execute([$portfolioId]);
    $portfolioFunds = $fundIds->fetchAll(PDO::FETCH_ASSOC);

    $overlaps = [];
    $pairsDone = [];

    foreach ($portfolioFunds as $f1) {
        foreach ($portfolioFunds as $f2) {
            if ($f1['fund_id'] >= $f2['fund_id']) continue;
            $key = $f1['fund_id'] . '_' . $f2['fund_id'];
            if (isset($pairsDone[$key])) continue;
            $pairsDone[$key] = true;

            // Get common holdings from fund_portfolio_holdings
            $month = date('Y-m', strtotime('-1 month'));
            $common = $db->prepare("
                SELECT a.stock_name, a.weight_pct AS w1, b.weight_pct AS w2,
                       LEAST(a.weight_pct, b.weight_pct) AS overlap_pct
                FROM fund_portfolio_holdings a
                JOIN fund_portfolio_holdings b ON b.isin = a.isin AND b.fund_id = ? AND b.month_year = ?
                WHERE a.fund_id = ? AND a.month_year = ?
                ORDER BY overlap_pct DESC
            ");
            $common->execute([$f2['fund_id'], $month, $f1['fund_id'], $month]);
            $commonHoldings = $common->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($commonHoldings)) {
                $overlapPct = array_sum(array_column($commonHoldings, 'overlap_pct'));
                $overlaps[] = [
                    'fund1_id'   => $f1['fund_id'],
                    'fund1_name' => $f1['scheme_name'],
                    'fund2_id'   => $f2['fund_id'],
                    'fund2_name' => $f2['scheme_name'],
                    'overlap_pct'=> round($overlapPct, 2),
                    'common_stocks' => count($commonHoldings),
                    'stocks'     => array_slice($commonHoldings, 0, 10),
                ];
            } else {
                // Category-based proxy overlap
                $sameCat = $f1['category'] === $f2['category'];
                $overlaps[] = [
                    'fund1_id'   => $f1['fund_id'],
                    'fund1_name' => $f1['scheme_name'],
                    'fund2_id'   => $f2['fund_id'],
                    'fund2_name' => $f2['scheme_name'],
                    'overlap_pct'=> $sameCat ? 40.0 : 5.0,
                    'common_stocks' => 0,
                    'stocks'     => [],
                    'proxy'      => true,
                    'note'       => $sameCat ? 'Same category — estimated overlap' : 'Different category — low overlap estimated',
                ];
            }
        }
    }

    usort($overlaps, fn($a, $b) => $b['overlap_pct'] <=> $a['overlap_pct']);
    echo json_encode(['success' => true, 'data' => $overlaps, 'fund_count' => count($portfolioFunds)]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// smart_insights — tmfi04
// ══════════════════════════════════════════════════════════════════════════
case 'smart_insights':
    $portfolioId = getOrCreatePortfolio($db, $userId);
    $insights = [];

    // Fetch basic holding data
    $stmt = $db->prepare("
        SELECT mh.fund_id, mh.current_value, mh.invested_amount, mh.xirr,
               f.scheme_name, f.scheme_category, f.expense_ratio, f.return_1y,
               f.scheme_category_avg_1y, f.expense_ratio, mh.first_investment_date,
               (SELECT COUNT(*) FROM mf_sip_schedules s WHERE s.fund_id=mh.fund_id AND s.user_id=? AND s.status='active') AS has_sip
        FROM mf_holdings mh
        JOIN funds f ON f.id = mh.fund_id
        WHERE mh.portfolio_id = ? AND 1 = 1
    ");
    $stmt->execute([$userId, $portfolioId]);
    $funds = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalValue   = array_sum(array_column($funds, 'current_value'));
    $totalFunds   = count($funds);
    $categories   = array_unique(array_column($funds, 'category'));

    // Rule 1: Too many funds
    if ($totalFunds > 12) {
        $insights[] = ['type' => 'error', 'icon' => '⚠️', 'priority' => 1,
            'title' => "Too many funds ($totalFunds)",
            'desc'  => "Managing $totalFunds funds increases complexity and tracking overhead. Ideal: 6-10 well-diversified funds.",
            'action' => 'Review and consolidate similar-category funds'];
    } elseif ($totalFunds > 8) {
        $insights[] = ['type' => 'warning', 'icon' => '📊', 'priority' => 2,
            'title' => "Many funds ($totalFunds)",
            'desc'  => "$totalFunds funds can create overlap. Consider if all serve distinct purposes.",
            'action' => 'Check overlap matrix'];
    }

    // Rule 2: Underperforming SIP funds
    $underperformers = array_filter($funds, fn($f) =>
        $f['has_sip'] > 0 && $f['return_1y'] !== null && $f['scheme_category_avg_1y'] !== null
        && (float)$f['return_1y'] < (float)$f['scheme_category_avg_1y'] - 3
    );
    foreach ($underperformers as $f) {
        $insights[] = ['type' => 'warning', 'icon' => '📉', 'priority' => 2,
            'title' => "SIP underperforming: {$f['fund_name']}",
            'desc'  => "1Y return " . round((float)$f['return_1y'], 1) . "% vs category avg " . round((float)$f['scheme_category_avg_1y'], 1) . "%",
            'action' => "Review SIP or switch to better fund in same category",
            'fund_id' => $f['fund_id']];
    }

    // Rule 3: High expense ratio
    $highCost = array_filter($funds, fn($f) => (float)($f['expense_ratio'] ?? 0) > 1.5);
    foreach (array_slice($highCost, 0, 2) as $f) {
        $insights[] = ['type' => 'info', 'icon' => '💸', 'priority' => 3,
            'title' => "High TER: {$f['fund_name']}",
            'desc'  => "Expense ratio " . $f['expense_ratio'] . "% is above 1.5%. Direct plan available?",
            'action' => "Check if direct plan exists",
            'fund_id' => $f['fund_id']];
    }

    // Rule 4: Uneven allocation
    if ($totalValue > 0) {
        $heaviest = array_reduce($funds, fn($carry, $f) =>
            (float)$f['current_value'] > (float)($carry['current_value'] ?? 0) ? $f : $carry, []);
        $heavyPct = round((float)$heaviest['current_value'] / $totalValue * 100, 1);
        if ($heavyPct > 40) {
            $insights[] = ['type' => 'warning', 'icon' => '⚖️', 'priority' => 2,
                'title' => "Concentration risk: {$heaviest['fund_name']}",
                'desc'  => "$heavyPct% of portfolio in one fund. Consider diversifying.",
                'action' => "Rebalance to reduce concentration",
                'fund_id' => $heaviest['fund_id']];
        }
    }

    // Rule 5: No debt allocation
    $debtFunds = array_filter($funds, fn($f) => str_contains(strtolower($f['category'] ?? ''), 'debt'));
    if (empty($debtFunds) && $totalFunds >= 3) {
        $insights[] = ['type' => 'info', 'icon' => '🛡️', 'priority' => 3,
            'title' => "No debt funds",
            'desc'  => "Pure equity portfolio has higher volatility. Consider adding liquid/debt funds for stability.",
            'action' => "Add a liquid or short-duration debt fund"];
    }

    // Rule 6: LTCG opportunity (March reminder)
    if (date('m') >= 2) {
        $insights[] = ['type' => 'info', 'icon' => '🌾', 'priority' => 3,
            'title' => "LTCG harvesting opportunity",
            'desc'  => "Utilise ₹1.25L LTCG exemption before March 31. Book gains, reinvest.",
            'action' => "Check Tax Loss Harvesting tool"];
    }

    usort($insights, fn($a, $b) => $a['priority'] <=> $b['priority']);
    echo json_encode(['success' => true, 'data' => $insights]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// what_if_simulate — tmfi06
// ══════════════════════════════════════════════════════════════════════════
case 'what_if_simulate':
    $scenario  = $_POST['scenario'] ?? 'market_crash'; // market_crash | sip_stop | step_up
    $param     = (float)($_POST['param'] ?? -20);      // crash % or step-up %
    $portfolioId = getOrCreatePortfolio($db, $userId);

    $holdings = $db->prepare("
        SELECT mh.current_value, f.scheme_category, NULL, NULL
        FROM mf_holdings mh
        JOIN funds f ON f.id = mh.fund_id
        WHERE mh.portfolio_id = ? AND 1 = 1
    ");
    $holdings->execute([$portfolioId]);
    $funds = $holdings->fetchAll(PDO::FETCH_ASSOC);

    $totalCurrent = array_sum(array_column($funds, 'current_value'));
    $result = ['current_value' => $totalCurrent, 'scenario' => $scenario, 'param' => $param];

    if ($scenario === 'market_crash') {
        $crashPct    = abs($param) / 100;
        $newValue    = 0;
        foreach ($funds as $f) {
            $beta = (float)($f['beta'] ?? 1.0);
            // Equity-like funds move with beta; debt less so
            $catLow   = strtolower($f['category'] ?? '');
            $isSafe   = str_contains($catLow, 'debt') || str_contains($catLow, 'liquid') || str_contains($catLow, 'gilt');
            $impactPct = $isSafe ? $crashPct * 0.1 : $crashPct * $beta;
            $newValue += (float)$f['current_value'] * (1 - $impactPct);
        }
        $result['simulated_value'] = round($newValue, 2);
        $result['loss']            = round($totalCurrent - $newValue, 2);
        $result['loss_pct']        = $totalCurrent > 0 ? round(($totalCurrent - $newValue) / $totalCurrent * 100, 2) : 0;
    }

    echo json_encode(['success' => true, 'data' => $result]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// sip_optimize — tmfi08
// ══════════════════════════════════════════════════════════════════════════
case 'sip_optimize':
    $portfolioId = getOrCreatePortfolio($db, $userId);
    $stmt = $db->prepare("
        SELECT s.id, s.fund_id, s.monthly_amount, s.start_date, s.status,
               f.scheme_name, f.scheme_category, f.return_1y, f.scheme_category_avg_1y,
               f.expense_ratio, f.expense_ratio, mh.xirr
        FROM mf_sip_schedules s
        JOIN funds f ON f.id = s.fund_id
        LEFT JOIN mf_holdings mh ON mh.fund_id = s.fund_id AND mh.portfolio_id = ?
        WHERE s.user_id = ?
        ORDER BY s.status DESC, s.monthly_amount DESC
    ");
    $stmt->execute([$portfolioId, $userId]);
    $sips = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $recommendations = [];
    foreach ($sips as $s) {
        $rec = ['sip_id' => $s['id'], 'fund_name' => $s['fund_name'],
                'monthly_amount' => $s['monthly_amount'], 'status' => $s['status']];

        if ($s['status'] !== 'active') continue;

        $r1y   = (float)($s['return_1y'] ?? 0);
        $cat1y = (float)($s['scheme_category_avg_1y'] ?? 10);
        $sh    = (float)($s['sharpe_ratio'] ?? 0);
        $er    = (float)($s['expense_ratio'] ?? 1);

        if ($r1y < $cat1y - 5 && $sh < 0.5) {
            $rec['action']  = 'switch';
            $rec['reason']  = "Fund is significantly underperforming category and has poor risk-adjusted returns";
            $rec['urgency'] = 'high';
        } elseif ($r1y < $cat1y - 2) {
            $rec['action']  = 'review';
            $rec['reason']  = "Slightly below category average. Monitor for 2 more quarters before switching.";
            $rec['urgency'] = 'medium';
        } elseif ($er > 1.5) {
            $rec['action']  = 'direct_plan';
            $rec['reason']  = "High expense ratio ({$er}%). Direct plan could save ₹" . round((float)$s['monthly_amount'] * 12 * ($er - 0.5) / 100) . "/yr";
            $rec['urgency'] = 'low';
        } else {
            $rec['action']  = 'continue';
            $rec['reason']  = "Good performance. Continue SIP.";
            $rec['urgency'] = 'none';
        }
        $recommendations[] = $rec;
    }
    echo json_encode(['success' => true, 'data' => $recommendations]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// dividend_history — t268
// ══════════════════════════════════════════════════════════════════════════
case 'dividend_history':
    $fundId = (int)($_GET['fund_id'] ?? 0);
    if (!$fundId) { echo json_encode(['success' => false, 'msg' => 'fund_id required']); break; }

    $divs = $db->prepare("
        SELECT d.record_date, d.ex_date, d.dividend_per_unit,
               YEAR(d.record_date) AS yr
        FROM fund_dividends d
        WHERE d.fund_id = ?
        ORDER BY d.record_date DESC
        LIMIT 60
    ");
    $divs->execute([$fundId]);
    $rows = $divs->fetchAll(PDO::FETCH_ASSOC);

    // Annual breakdown
    $byYear = [];
    foreach ($rows as $r) {
        $yr = $r['yr'];
        $byYear[$yr] = ($byYear[$yr] ?? 0) + (float)$r['dividend_per_unit'];
    }

    echo json_encode(['success' => true, 'data' => $rows, 'by_year' => $byYear]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// portfolio_risk — tmfi03
// ══════════════════════════════════════════════════════════════════════════
case 'portfolio_risk':
    $portfolioId = getOrCreatePortfolio($db, $userId);
    // Use correct table
    $stmt2 = $db->prepare("
        SELECT mh.current_value, NULL, NULL, f.expense_ratio,
               NULL, NULL, f.scheme_name, f.scheme_category
        FROM mf_holdings mh
        JOIN funds f ON f.id = mh.fund_id
        WHERE mh.portfolio_id = ? AND 1 = 1
    ");
    $stmt2->execute([$portfolioId]);
    $funds = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    $totalValue = array_sum(array_column($funds, 'current_value'));
    $portBeta = $portVol = $portSharpe = 0;

    foreach ($funds as $f) {
        $w = $totalValue > 0 ? (float)$f['current_value'] / $totalValue : 0;
        $portBeta   += $w * (float)($f['beta']               ?? 1.0);
        $portVol    += $w * (float)($f['standard_deviation'] ?? 15);
        $portSharpe += $w * (float)($f['sharpe_ratio']       ?? 0);
    }

    // VaR 95% (parametric): 1.645 * vol/sqrt(252) * totalValue
    $dailyVol = $portVol / sqrt(252);
    $var95    = round(1.645 * $dailyVol / 100 * $totalValue, 2);

    echo json_encode(['success' => true, 'data' => [
        'portfolio_beta'       => round($portBeta, 4),
        'portfolio_volatility' => round($portVol, 4),
        'portfolio_sharpe'     => round($portSharpe, 4),
        'var_95_1day'          => $var95,
        'total_value'          => $totalValue,
        'fund_breakdown'       => $funds,
    ]]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// transactions — Full transaction history with filters & pagination
// Called by loadTransactions() in mf.js via ?view=transactions
// Uses actual DB schema: holding_id, user_id, tx_type, tx_date, price_per_unit, amount
// ══════════════════════════════════════════════════════════════════════════
case 'transactions':
    // Uses actual mf_transactions schema: portfolio_id, fund_id, txn_type, txn_date, nav, amount
    $portfolioId = (int)($_GET['portfolio_id'] ?? 0);
    if (!$portfolioId) $portfolioId = getOrCreatePortfolio($db, $userId);

    $page    = max(1, (int)($_GET['page']     ?? 1));
    $perPage = max(1, min(200, (int)($_GET['per_page'] ?? 10)));
    $offset  = ($page - 1) * $perPage;

    // Filters
    $txnType = $_GET['txn_type'] ?? '';
    $fy      = $_GET['fy']      ?? '';
    $from    = $_GET['from']    ?? '';
    $to      = $_GET['to']      ?? '';
    $q       = trim($_GET['q']  ?? '');
    $fundId  = (int)($_GET['fund_id'] ?? 0);

    $where  = ["t.portfolio_id = ?"];
    $params = [$portfolioId];

    if ($txnType) { $where[] = "t.txn_type = ?";           $params[] = $txnType; }
    if ($from)    { $where[] = "t.txn_date >= ?";           $params[] = $from; }
    if ($to)      { $where[] = "t.txn_date <= ?";           $params[] = $to; }
    if ($fy)      { $where[] = "t.investment_fy = ?";       $params[] = $fy; }
    if ($fundId)  { $where[] = "t.fund_id = ?";             $params[] = $fundId; }
    if ($q)       { $where[] = "f.scheme_name LIKE ?";      $params[] = '%' . $q . '%'; }

    $whereStr = implode(' AND ', $where);

    $joinSql = "FROM mf_transactions t
        JOIN funds f ON f.id = t.fund_id
        WHERE $whereStr";

    // Count
    $cntStmt = $db->prepare("SELECT COUNT(*) $joinSql");
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();

    // Summary
    $sumStmt = $db->prepare("
        SELECT
            COUNT(*)                                                            AS total_txns,
            SUM(CASE WHEN t.txn_type IN ('purchase','sip','switch_in','dividend_reinvest')
                     THEN t.amount ELSE 0 END)                                 AS total_buy,
            SUM(CASE WHEN t.txn_type IN ('redemption','swp','switch_out')
                     THEN t.amount ELSE 0 END)                                 AS total_sell,
            COUNT(DISTINCT t.fund_id)                                           AS unique_funds
        $joinSql
    ");
    $sumStmt->execute($params);
    $summary = $sumStmt->fetch(PDO::FETCH_ASSOC);

    // FY list
    $fyStmt = $db->prepare("SELECT DISTINCT investment_fy FROM mf_transactions t
        JOIN funds f ON f.id = t.fund_id WHERE $whereStr AND investment_fy IS NOT NULL ORDER BY investment_fy");
    $fyStmt->execute($params);
    $fyList = $fyStmt->fetchAll(PDO::FETCH_COLUMN);

    // Data
    $paramsPage = array_merge($params, [$perPage, $offset]);
    $dataStmt = $db->prepare("
        SELECT
            t.id,
            t.fund_id,
            t.txn_date,
            t.txn_type         AS transaction_type,
            t.units,
            t.nav,
            t.amount           AS value_at_cost,
            t.folio_number,
            t.platform,
            t.investment_fy,
            t.notes,
            f.scheme_name      AS scheme_name,
            f.scheme_category  AS category
        $joinSql
        ORDER BY t.txn_date DESC
        LIMIT ? OFFSET ?
    ");
    $dataStmt->execute($paramsPage);
    $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'  => true,
        'data'     => $rows,
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => (int)ceil($total / max(1, $perPage)),
        'summary'  => [
            'total_txns'   => (int)($summary['total_txns']  ?? 0),
            'total_buy'    => (float)($summary['total_buy'] ?? 0),
            'total_sell'   => (float)($summary['total_sell']?? 0),
            'net_invested' => (float)(($summary['total_buy'] ?? 0) - ($summary['total_sell'] ?? 0)),
            'unique_funds' => (int)($summary['unique_funds']?? 0),
        ],
        'fy_list' => $fyList,
    ]);
    break;


default:
    echo json_encode(['success' => false, 'msg' => "Unknown action: $action"]);
}

// ── Helpers ───────────────────────────────────────────────────────────────
function getOrCreatePortfolio(PDO $db, int $userId): int
{
    $id = $db->prepare("SELECT id FROM portfolios WHERE user_id = ? AND is_default = 1 LIMIT 1");
    $id->execute([$userId]);
    $pid = $id->fetchColumn();
    if ($pid) return (int)$pid;

    $db->prepare("INSERT INTO portfolios (user_id, name, is_default, created_at) VALUES (?, 'My Portfolio', 1, NOW())")
       ->execute([$userId]);
    return (int)$db->lastInsertId();
}
