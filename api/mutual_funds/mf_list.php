<?php
/**
 * WealthDash — MF Holdings List API
 * Tasks: t07–t13, t70–t75, tmfi01–tmfi08, t366, t268
 * Actions: mf_list | mf_summary | portfolio_xirr | portfolio_health
 *          asset_allocation | sip_list | swp_list | overlap_check
 *          dividend_history | portfolio_risk | smart_insights
 *          what_if_simulate | cleanup_suggestions | sip_optimize
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

require_once ROOT . '/includes/holding_calculator.php';

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'mf_list';
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
case 'mf_list':
    $portfolioId = getOrCreatePortfolio($db, $userId);
    $sortBy  = $_GET['sort']    ?? 'current_value';
    $sortDir = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';

    $allowed = ['current_value','invested_amount','abs_return_pct','xirr',
                'fund_name','category','expense_ratio','returns_1y','sharpe_ratio'];
    if (!in_array($sortBy, $allowed)) $sortBy = 'current_value';

    $holdings = $db->prepare("
        SELECT
          mh.id            AS holding_id,
          mh.fund_id,
          mh.units,
          mh.avg_buy_nav,
          mh.current_nav,
          mh.current_value,
          mh.invested_amount,
          mh.abs_return_pct,
          mh.xirr,
          mh.first_investment_date,
          mh.folio_number,
          mh.is_active,
          mh.plan_type,
          f.scheme_code,
          f.fund_name,
          f.fund_house,
          f.category,
          f.sub_category,
          f.risk_level,
          f.expense_ratio,
          f.returns_1y,
          f.returns_3y,
          f.returns_5y,
          f.sharpe_ratio,
          f.sortino_ratio,
          f.max_drawdown,
          f.standard_deviation,
          f.alpha,
          f.beta,
          f.momentum_score,
          f.rating_stars,
          f.health_score,
          f.category_avg_1y,
          f.nav_date,
          f.exit_load_percent,
          f.lock_in_months,
          (SELECT COUNT(*) FROM mf_sip_schedules s
           WHERE s.fund_id = mh.fund_id AND s.user_id = ? AND s.status = 'active') AS active_sip_count,
          (SELECT COUNT(*) FROM mf_swp_schedules sw
           WHERE sw.fund_id = mh.fund_id AND sw.user_id = ? AND sw.status = 'active') AS active_swp_count,
          (SELECT monthly_amount FROM mf_sip_schedules s2
           WHERE s2.fund_id = mh.fund_id AND s2.user_id = ? AND s2.status = 'active'
           ORDER BY s2.id DESC LIMIT 1) AS sip_amount
        FROM mf_holdings mh
        JOIN funds f ON f.id = mh.fund_id
        WHERE mh.portfolio_id = ? AND mh.is_active = 1
        ORDER BY mh.$sortBy $sortDir
    ");
    $holdings->execute([$userId, $userId, $userId, $portfolioId]);
    $rows = $holdings->fetchAll(PDO::FETCH_ASSOC);

    // Totals
    $totals = [
        'total_invested'  => 0,
        'total_current'   => 0,
        'total_gain_loss' => 0,
    ];
    foreach ($rows as $r) {
        $totals['total_invested'] += (float)$r['invested_amount'];
        $totals['total_current']  += (float)$r['current_value'];
    }
    $totals['total_gain_loss']   = $totals['total_current'] - $totals['total_invested'];
    $totals['abs_return_pct']    = $totals['total_invested'] > 0
        ? round(($totals['total_gain_loss'] / $totals['total_invested']) * 100, 2) : 0;
    $totals['fund_count']        = count($rows);

    echo json_encode(['success' => true, 'data' => $rows, 'totals' => $totals]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// portfolio_xirr — t73
// ══════════════════════════════════════════════════════════════════════════
case 'portfolio_xirr':
    $result = HoldingCalculator::portfolioXirr($userId);
    echo json_encode(['success' => true, 'data' => $result]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// portfolio_health — tmfi01
// ══════════════════════════════════════════════════════════════════════════
case 'portfolio_health':
    $result = HoldingCalculator::portfolioHealthScore($userId);
    echo json_encode(['success' => true, 'data' => $result]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// asset_allocation — t71
// ══════════════════════════════════════════════════════════════════════════
case 'asset_allocation':
    $portfolioId = getOrCreatePortfolio($db, $userId);
    $rows = $db->prepare("
        SELECT f.category, f.sub_category, f.risk_level,
               SUM(mh.current_value) AS value,
               SUM(mh.invested_amount) AS invested
        FROM mf_holdings mh
        JOIN funds f ON f.id = mh.fund_id
        WHERE mh.portfolio_id = ? AND mh.is_active = 1
        GROUP BY f.category, f.sub_category, f.risk_level
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
        SELECT DISTINCT mh.fund_id, f.fund_name, f.category
        FROM mf_holdings mh
        JOIN funds f ON f.id = mh.fund_id
        WHERE mh.portfolio_id = ? AND mh.is_active = 1
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
                    'fund1_name' => $f1['fund_name'],
                    'fund2_id'   => $f2['fund_id'],
                    'fund2_name' => $f2['fund_name'],
                    'overlap_pct'=> round($overlapPct, 2),
                    'common_stocks' => count($commonHoldings),
                    'stocks'     => array_slice($commonHoldings, 0, 10),
                ];
            } else {
                // Category-based proxy overlap
                $sameCat = $f1['category'] === $f2['category'];
                $overlaps[] = [
                    'fund1_id'   => $f1['fund_id'],
                    'fund1_name' => $f1['fund_name'],
                    'fund2_id'   => $f2['fund_id'],
                    'fund2_name' => $f2['fund_name'],
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
               f.fund_name, f.category, f.expense_ratio, f.returns_1y,
               f.category_avg_1y, f.sharpe_ratio, mh.first_investment_date,
               (SELECT COUNT(*) FROM mf_sip_schedules s WHERE s.fund_id=mh.fund_id AND s.user_id=? AND s.status='active') AS has_sip
        FROM mf_holdings mh
        JOIN funds f ON f.id = mh.fund_id
        WHERE mh.portfolio_id = ? AND mh.is_active = 1
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
        $f['has_sip'] > 0 && $f['returns_1y'] !== null && $f['category_avg_1y'] !== null
        && (float)$f['returns_1y'] < (float)$f['category_avg_1y'] - 3
    );
    foreach ($underperformers as $f) {
        $insights[] = ['type' => 'warning', 'icon' => '📉', 'priority' => 2,
            'title' => "SIP underperforming: {$f['fund_name']}",
            'desc'  => "1Y return " . round((float)$f['returns_1y'], 1) . "% vs category avg " . round((float)$f['category_avg_1y'], 1) . "%",
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
        SELECT mh.current_value, f.category, f.beta, f.standard_deviation
        FROM mf_holdings mh
        JOIN funds f ON f.id = mh.fund_id
        WHERE mh.portfolio_id = ? AND mh.is_active = 1
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
               f.fund_name, f.category, f.returns_1y, f.category_avg_1y,
               f.sharpe_ratio, f.expense_ratio, mh.xirr
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

        $r1y   = (float)($s['returns_1y'] ?? 0);
        $cat1y = (float)($s['category_avg_1y'] ?? 10);
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
    $stmt = $db->prepare("
        SELECT mh.current_value, f.beta, f.standard_deviation, f.sharpe_ratio,
               f.sortino_ratio, f.max_drawdown, f.fund_name, f.category
        FROM mh_holdings mh
        JOIN funds f ON f.id = mh.fund_id
        WHERE mh.portfolio_id = ? AND mh.is_active = 1
    ");
    // Use correct table
    $stmt2 = $db->prepare("
        SELECT mh.current_value, f.beta, f.standard_deviation, f.sharpe_ratio,
               f.sortino_ratio, f.max_drawdown, f.fund_name, f.category
        FROM mf_holdings mh
        JOIN funds f ON f.id = mh.fund_id
        WHERE mh.portfolio_id = ? AND mh.is_active = 1
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
