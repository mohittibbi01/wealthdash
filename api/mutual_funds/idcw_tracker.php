<?php
/**
 * WealthDash — t366: IDCW vs Growth Dividend Reinvestment Tracker
 *
 * Tracks IDCW payouts received, calculates opportunity cost vs Growth plan,
 * provides switch recommendation and tax analysis.
 *
 * GET /api/mutual_funds/idcw_tracker.php
 *   ?fund_id=X                         ← Single fund IDCW vs Growth comparison
 *   ?portfolio_id=X                    ← Portfolio-wide IDCW analysis
 *   ?action=comparison&fund_id=X       ← Growth vs IDCW side-by-side
 *   ?action=dividends&fund_id=X        ← Dividend payout history
 *   ?action=tax_impact&fund_id=X       ← Tax implications
 *   ?action=portfolio_idcw&portfolio_id=X  ← All IDCW funds in portfolio
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', '0');
ob_start();

$currentUser = require_auth();

set_exception_handler(function (Throwable $e) {
    ob_clean(); http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit;
});

try {
    $db          = DB::conn();
    $action      = $_GET['action'] ?? 'comparison';
    $fundId      = (int)($_GET['fund_id'] ?? 0);
    $portfolioId = (int)($_GET['portfolio_id'] ?? 0);
    $userId      = (int)$currentUser['id'];

    $response = ['success' => true, 'action' => $action];

    switch ($action) {
        case 'dividends':
            if (!$fundId) throw new InvalidArgumentException('fund_id required');
            $response['dividends'] = get_dividend_history($db, $fundId);
            break;

        case 'tax_impact':
            if (!$fundId) throw new InvalidArgumentException('fund_id required');
            $response['tax_impact'] = get_tax_comparison($db, $fundId);
            break;

        case 'portfolio_idcw':
            $response['idcw_funds'] = get_portfolio_idcw($db, $userId, $portfolioId);
            break;

        case 'comparison':
        default:
            if ($fundId) {
                $response['comparison']    = get_growth_vs_idcw_comparison($db, $fundId);
                $response['dividends']     = get_dividend_history($db, $fundId);
                $response['tax_impact']    = get_tax_comparison($db, $fundId);
                $response['recommendation']= get_switch_recommendation($db, $fundId);
            } elseif ($portfolioId || true) {
                $response['idcw_funds'] = get_portfolio_idcw($db, $userId, $portfolioId);
            }
            break;
    }

    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean(); http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ═══════════════════════════════════════════════════════════════════════════
// GROWTH vs IDCW COMPARISON
// ═══════════════════════════════════════════════════════════════════════════
function get_growth_vs_idcw_comparison(PDO $db, int $fundId): array
{
    $stmt = $db->prepare("
        SELECT f.id, f.scheme_name, f.category, f.option_type, f.scheme_code,
               f.latest_nav, f.returns_1y, f.returns_3y, f.returns_5y,
               f.expense_ratio, f.aum_crore
        FROM funds f WHERE f.id = ?
    ");
    $stmt->execute([$fundId]);
    $fund = $stmt->fetch();
    if (!$fund) throw new RuntimeException('Fund not found');

    $isIdcw = in_array(strtolower($fund['option_type'] ?? ''), ['idcw', 'dividend']);

    // Try to find counterpart (Growth plan of same scheme or IDCW of Growth plan)
    $counterpart = find_counterpart($db, $fund);

    // Dividend history for IDCW fund
    $dividends    = get_dividend_history($db, $fundId);
    $totalDivPaid = array_sum(array_column($dividends['history'] ?? [], 'per_unit'));

    // Simulation: ₹1L invested in both for 5 years
    $simulation = simulate_growth_vs_idcw($fund, $counterpart, $dividends);

    return [
        'fund'                => [
            'id'          => $fundId,
            'name'        => $fund['scheme_name'],
            'option_type' => $fund['option_type'],
            'is_idcw'     => $isIdcw,
            'latest_nav'  => (float)$fund['latest_nav'],
            'returns_1y'  => (float)($fund['returns_1y'] ?? 0),
            'returns_3y'  => (float)($fund['returns_3y'] ?? 0),
            'returns_5y'  => (float)($fund['returns_5y'] ?? 0),
        ],
        'counterpart'         => $counterpart,
        'total_dividend_paid' => round($totalDivPaid, 4),
        'simulation'          => $simulation,
        'key_difference'      => "IDCW pays out earnings periodically (taxed as income at your slab rate). " .
                                 "Growth plan reinvests all earnings (taxed as LTCG/STCG only when you sell).",
    ];
}

function find_counterpart(PDO $db, array $fund): ?array
{
    $isIdcw    = in_array(strtolower($fund['option_type'] ?? ''), ['idcw', 'dividend']);
    $targetType = $isIdcw ? ['growth'] : ['idcw', 'dividend'];

    // Match on scheme_code prefix (first 6 chars)
    $codePrefix = substr($fund['scheme_code'] ?? '', 0, 6);
    if (strlen($codePrefix) < 4) {
        // Try name-based matching
        $baseName = preg_replace('/([-\s]+(IDCW|Dividend|Growth|Payout|Reinvestment)\s*.*)/i', '', $fund['scheme_name']);
        $baseName = trim($baseName) . '%';
        $phIn  = implode(',', array_fill(0, count($targetType), '?'));
        $params = array_merge([$baseName], $targetType, [$fund['id']]);
        $sql = "SELECT id, scheme_name, option_type, latest_nav, returns_3y, expense_ratio
                FROM funds WHERE scheme_name LIKE ? AND option_type IN ($phIn) AND id != ? LIMIT 1";
    } else {
        $phIn  = implode(',', array_fill(0, count($targetType), '?'));
        $params = array_merge([$codePrefix . '%'], $targetType, [$fund['id']]);
        $sql = "SELECT id, scheme_name, option_type, latest_nav, returns_3y, expense_ratio
                FROM funds WHERE scheme_code LIKE ? AND option_type IN ($phIn) AND id != ? LIMIT 1";
    }

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        if ($row) {
            return [
                'id'          => (int)$row['id'],
                'name'        => $row['scheme_name'],
                'option_type' => $row['option_type'],
                'latest_nav'  => (float)$row['latest_nav'],
                'returns_3y'  => (float)($row['returns_3y'] ?? 0),
            ];
        }
    } catch (Exception $e) {}

    return null;
}

// ═══════════════════════════════════════════════════════════════════════════
// DIVIDEND HISTORY
// ═══════════════════════════════════════════════════════════════════════════
function get_dividend_history(PDO $db, int $fundId): array
{
    $history = [];

    // Check if fund_dividends table exists
    $tableExists = false;
    try {
        $db->query("SELECT 1 FROM fund_dividends LIMIT 1");
        $tableExists = true;
    } catch (Exception $e) {}

    if ($tableExists) {
        try {
            $stmt = $db->prepare("
                SELECT record_date, dividend_per_unit, face_value, payout_type
                FROM fund_dividends
                WHERE fund_id = ?
                ORDER BY record_date DESC
                LIMIT 36
            ");
            $stmt->execute([$fundId]);
            $history = $stmt->fetchAll();
        } catch (Exception $e) {}
    }

    // If no DB history, use estimated/sample data as placeholder
    if (empty($history)) {
        // Return placeholder showing DB table is needed
        $history = [];
        $note = "Dividend history requires the fund_dividends table. Run migration 027_dividends.sql to enable. " .
                "Data is fetched from AMFI dividend announcements.";
    } else {
        $note = null;
    }

    $total = array_sum(array_column($history, 'dividend_per_unit'));
    $count = count($history);
    $freq  = 'Unknown';
    if ($count >= 2) {
        $dates     = array_column($history, 'record_date');
        $daysBetween = [];
        for ($i = 0; $i < $count - 1; $i++) {
            $d1 = new DateTime($dates[$i]);
            $d2 = new DateTime($dates[$i + 1]);
            $daysBetween[] = abs((int)$d1->diff($d2)->days);
        }
        $avgDays = array_sum($daysBetween) / count($daysBetween);
        $freq = match (true) {
            $avgDays <= 45  => 'Monthly',
            $avgDays <= 100 => 'Quarterly',
            $avgDays <= 200 => 'Half-yearly',
            default         => 'Annual',
        };
    }

    return [
        'fund_id'    => $fundId,
        'history'    => $history,
        'total_paid_per_unit' => round($total, 4),
        'payout_count' => $count,
        'frequency'  => $freq,
        'note'       => $note ?? null,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// TAX COMPARISON
// ═══════════════════════════════════════════════════════════════════════════
function get_tax_comparison(PDO $db, int $fundId): array
{
    $stmt = $db->prepare("SELECT category, option_type, scheme_name FROM funds WHERE id = ?");
    $stmt->execute([$fundId]);
    $fund = $stmt->fetch();
    if (!$fund) return [];

    $cat    = strtolower($fund['category'] ?? '');
    $isDebt = str_contains($cat, 'debt') || str_contains($cat, 'liquid') || str_contains($cat, 'gilt');
    $isEquity = !$isDebt;

    return [
        'idcw_tax' => [
            'rule'     => 'IDCW payouts are added to investor income and taxed at their income tax slab rate',
            'rate'     => 'Your slab rate (5% / 20% / 30%)',
            'timing'   => 'Tax applicable in year of payout',
            'tds'      => 'TDS deducted at 10% if dividend > ₹5,000 in a FY (Section 194K)',
            'impact'   => 'If in 30% slab, IDCW is taxed at 30% — very inefficient vs Growth plan',
        ],
        'growth_tax' => [
            'rule'     => $isEquity
                ? 'LTCG (holding > 1yr): 12.5% above ₹1.25L exemption. STCG (< 1yr): 20%.'
                : 'LTCG (holding > 3yr): 20% with indexation. STCG: slab rate.',
            'rate'     => $isEquity ? '12.5% LTCG / 20% STCG' : '20% LTCG (indexed) / Slab STCG',
            'timing'   => 'Tax only when you SELL — no tax during holding period',
            'tds'      => 'No TDS on growth plan (tax at your hands when you redeem)',
            'impact'   => 'Tax-efficient: compounding happens on pre-tax amount for years',
        ],
        'verdict' => match (true) {
            $isEquity => 'Growth plan is almost always better for equity funds for investors in 20%+ tax slab. ' .
                        'IDCW only makes sense for investors needing regular income.',
            $isDebt   => 'For debt funds: IDCW is taxed at slab (30% max), Growth LTCG at 20%. Growth wins long-term.',
            default   => 'Growth plan recommended for long-term wealth creation.',
        },
        'exception' => 'IDCW may suit: retirees needing regular income, very low income individuals (0-5% slab), or when the payout is < ₹5,000/yr (no TDS).',
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// SIMULATION — ₹1L for 5 years
// ═══════════════════════════════════════════════════════════════════════════
function simulate_growth_vs_idcw(array $fund, ?array $counterpart, array $dividends): array
{
    $invested   = 100000;
    $years      = 5;
    $returnRate = ((float)($fund['returns_3y'] ?? 12)) / 100;

    // Growth plan: pure compounding
    $growthFinal = round($invested * ((1 + $returnRate) ** $years), 0);
    $growthGain  = $growthFinal - $invested;

    // IDCW plan: NAV grows slower (pays out), tax on each payout
    // Assume 60% of return stays in NAV, 40% paid as dividend
    $idcwNavReturn = $returnRate * 0.60;
    $divYield      = $returnRate * 0.40;

    $idcwNavFinal  = round($invested * ((1 + $idcwNavReturn) ** $years), 0);
    $totalDivPaid  = 0;
    $totalDivTax   = 0;

    // Simplified: annual dividend payout
    $corpus = $invested;
    for ($y = 0; $y < $years; $y++) {
        $div  = round($corpus * $divYield, 0);
        $tax  = round($div * 0.30, 0); // assume 30% slab (worst case)
        $totalDivPaid += $div;
        $totalDivTax  += $tax;
        $corpus = $corpus * (1 + $idcwNavReturn);
    }
    $idcwFinalPostTax = round($idcwNavFinal + $totalDivPaid - $totalDivTax, 0);

    // Growth plan tax at exit (LTCG 12.5% above 1.25L)
    $ltcgExempt = 125000;
    $taxableGain = max(0, $growthGain - $ltcgExempt);
    $growthTax   = round($taxableGain * 0.125, 0);
    $growthFinalPostTax = $growthFinal - $growthTax;

    $winner = $growthFinalPostTax >= $idcwFinalPostTax ? 'growth' : 'idcw';
    $diff   = abs($growthFinalPostTax - $idcwFinalPostTax);

    return [
        'invested'              => $invested,
        'years'                 => $years,
        'assumed_return_pct'    => round($returnRate * 100, 1),
        'tax_slab_assumed'      => '30%',
        'growth' => [
            'final_value'     => $growthFinal,
            'gain'            => $growthGain,
            'tax_at_exit'     => $growthTax,
            'final_post_tax'  => $growthFinalPostTax,
            'cagr_post_tax'   => round((($growthFinalPostTax / $invested) ** (1 / $years) - 1) * 100, 1),
        ],
        'idcw' => [
            'final_nav_value' => $idcwNavFinal,
            'total_div_paid'  => $totalDivPaid,
            'total_div_tax'   => $totalDivTax,
            'final_post_tax'  => $idcwFinalPostTax,
            'cagr_post_tax'   => round((($idcwFinalPostTax / $invested) ** (1 / $years) - 1) * 100, 1),
        ],
        'winner'  => $winner,
        'you_gain'=> $diff,
        'verdict' => $winner === 'growth'
            ? "Growth plan gives ₹" . number_format($diff, 0) . " more over {$years} years (after tax) for an investor in 30% slab."
            : "IDCW plan gives ₹" . number_format($diff, 0) . " more — unusual, typically applies for very low tax brackets.",
        'note'    => 'Simulation assumes 30% tax slab on IDCW, 12.5% LTCG on Growth. Actual results depend on individual tax slab and dividend frequency.',
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// SWITCH RECOMMENDATION
// ═══════════════════════════════════════════════════════════════════════════
function get_switch_recommendation(PDO $db, int $fundId): array
{
    $stmt = $db->prepare("
        SELECT h.units, h.avg_cost_nav, f.latest_nav, f.scheme_name, f.option_type,
               f.category, f.min_ltcg_days
        FROM mf_holdings h
        JOIN funds f ON f.id = h.fund_id
        WHERE h.fund_id = ? AND h.units > 0
        LIMIT 1
    ");
    $stmt->execute([$fundId]);
    $holding = $stmt->fetch();

    if (!$holding) {
        return ['message' => 'Fund not currently held in portfolio.'];
    }

    $isIdcw = in_array(strtolower($holding['option_type'] ?? ''), ['idcw', 'dividend']);

    if (!$isIdcw) {
        return ['message' => 'Fund is already on Growth plan. No switch needed.', 'is_growth' => true];
    }

    $invested   = (float)$holding['units'] * (float)$holding['avg_cost_nav'];
    $currentVal = (float)$holding['units'] * (float)$holding['latest_nav'];
    $gain       = $currentVal - $invested;
    $isLtcg     = true; // simplified (would need txn dates)
    $taxOnSwitch = $gain > 125000 ? round(($gain - 125000) * 0.125, 0) : 0;

    return [
        'fund_name'       => $holding['scheme_name'],
        'current_plan'    => 'IDCW',
        'recommended_plan'=> 'Growth',
        'current_value'   => round($currentVal, 2),
        'unrealised_gain' => round($gain, 2),
        'tax_on_switch'   => $taxOnSwitch,
        'switch_type'     => 'Inter-scheme switch (IDCW → Growth of same fund)',
        'steps'           => [
            '1. Check if same fund has Growth option (same AMC, same scheme name)',
            '2. Place switch order on your broker/AMC portal',
            '3. Switch treated as redemption from IDCW + fresh purchase in Growth',
            '4. Tax applicable on any gain in IDCW plan at switch',
            '5. For large corpus (>₹10L), consider switching in tranches to manage LTCG',
        ],
        'annual_tax_saving' => round($currentVal * 0.01 * 0.30, 0),
        'verdict' => $gain > 0
            ? "Switching to Growth triggers ₹{$taxOnSwitch} tax now but saves ~₹" . number_format((int)round($currentVal * 0.01 * 0.30), 0) . "/year on dividend tax going forward."
            : "Good time to switch — fund is at a loss, so switch triggers no tax.",
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// PORTFOLIO-WIDE IDCW ANALYSIS
// ═══════════════════════════════════════════════════════════════════════════
function get_portfolio_idcw(PDO $db, int $userId, int $portfolioId): array
{
    $pWhere = $portfolioId > 0 ? 'AND h.portfolio_id = ?' : 'AND p.user_id = ?';
    $pParam = $portfolioId > 0 ? $portfolioId : $userId;

    try {
        $stmt = $db->prepare("
            SELECT h.fund_id, h.units, h.avg_cost_nav,
                   f.scheme_name, f.option_type, f.category, f.latest_nav,
                   f.returns_3y
            FROM mf_holdings h
            JOIN funds f ON f.id = h.fund_id
            JOIN portfolios p ON p.id = h.portfolio_id
            WHERE h.units > 0.001 $pWhere
              AND f.option_type IN ('idcw', 'dividend')
        ");
        $stmt->execute([$pParam]);
        $rows = $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }

    if (empty($rows)) {
        return ['message' => 'No IDCW funds found in portfolio. All funds are on Growth plan. ✅', 'count' => 0];
    }

    $result = array_map(function ($r) {
        $cv  = (float)$r['units'] * (float)$r['latest_nav'];
        $inv = (float)$r['units'] * (float)$r['avg_cost_nav'];
        return [
            'fund_id'       => (int)$r['fund_id'],
            'scheme_name'   => $r['scheme_name'],
            'category'      => $r['category'],
            'current_value' => round($cv, 2),
            'invested'      => round($inv, 2),
            'annual_div_estimate' => round($cv * 0.04, 0), // rough 4% yield estimate
            'annual_div_tax'      => round($cv * 0.04 * 0.30, 0), // 30% slab
            'action'        => 'Consider switching to Growth plan for tax efficiency',
        ];
    }, $rows);

    $totalValue    = array_sum(array_column($result, 'current_value'));
    $totalDivEst   = array_sum(array_column($result, 'annual_div_estimate'));
    $totalDivTax   = array_sum(array_column($result, 'annual_div_tax'));

    return [
        'count'              => count($result),
        'funds'              => $result,
        'total_idcw_value'   => round($totalValue, 2),
        'annual_div_estimate'=> round($totalDivEst, 0),
        'annual_div_tax_est' => round($totalDivTax, 0),
        'tax_savings_if_switched' => round($totalDivTax, 0),
        'message'            => count($result) . " IDCW fund(s) found. Switching to Growth could save ~₹" .
                               number_format((int)$totalDivTax, 0) . "/year in dividend tax.",
    ];
}
