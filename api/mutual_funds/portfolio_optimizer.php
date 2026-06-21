<?php
/**
 * WealthDash — t365: Fund Overlap Portfolio Optimizer
 *
 * Identifies redundant funds, suggests consolidation.
 * Uses category-based overlap from mf_holdings.php existing system.
 *
 * GET /api/mutual_funds/portfolio_optimizer.php
 *   ?portfolio_id=X   (optional)
 *   ?action=overlap_matrix    ← Redundant fund pairs
 *   ?action=consolidation     ← Consolidation suggestions
 *   ?action=optimal_count     ← How many funds is ideal?
 *   ?action=full              ← All (default)
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
    $action      = $_GET['action'] ?? 'full';
    $portfolioId = (int)($_GET['portfolio_id'] ?? 0);
    $userId      = (int)$currentUser['id'];

    // ── Fetch holdings ──────────────────────────────────────────────────
    $holdings = fetch_holdings($db, $userId, $portfolioId);
    if (empty($holdings)) {
        ob_clean();
        echo json_encode(['success' => true, 'message' => 'No holdings found', 'data' => []]);
        exit;
    }

    // ── Category overlap matrix ─────────────────────────────────────────
    $overlapMatrix  = build_overlap_matrix($holdings);
    $redundantPairs = find_redundant_pairs($overlapMatrix);

    // ── Consolidation suggestions ───────────────────────────────────────
    $consolidation = build_consolidation_plan($redundantPairs, $holdings);

    // ── Optimal fund count ──────────────────────────────────────────────
    $optimalCount = calc_optimal_count($holdings);

    // ── Small holdings ──────────────────────────────────────────────────
    $smallHoldings = array_filter($holdings, fn($h) => $h['current_value'] < 5000);

    // ── Underperformers ─────────────────────────────────────────────────
    $underperformers = find_underperformers($holdings);

    // ── Response ────────────────────────────────────────────────────────
    $response = ['success' => true, 'total_funds' => count($holdings)];

    switch ($action) {
        case 'overlap_matrix':
            $response['overlap_matrix']  = $overlapMatrix;
            $response['redundant_pairs'] = $redundantPairs;
            break;
        case 'consolidation':
            $response['consolidation'] = $consolidation;
            break;
        case 'optimal_count':
            $response['optimal_count'] = $optimalCount;
            break;
        case 'full':
        default:
            $response['overlap_matrix']   = $overlapMatrix;
            $response['redundant_pairs']  = $redundantPairs;
            $response['consolidation']    = $consolidation;
            $response['optimal_count']    = $optimalCount;
            $response['small_holdings']   = array_values($smallHoldings);
            $response['underperformers']  = $underperformers;
            $response['cleanup_plan']     = build_cleanup_plan($redundantPairs, $smallHoldings, $underperformers, $holdings);
            break;
    }

    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean(); http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ═══════════════════════════════════════════════════════════════════════════
// FETCH HOLDINGS
// ═══════════════════════════════════════════════════════════════════════════
function fetch_holdings(PDO $db, int $userId, int $portfolioId): array
{
    $pWhere  = $portfolioId > 0 ? 'AND h.portfolio_id = ?' : 'AND p.user_id = ?';
    $pParam  = $portfolioId > 0 ? $portfolioId : $userId;

    try {
        $stmt = $db->prepare("
            SELECT h.fund_id, h.units, h.avg_cost_nav,
                   f.scheme_name, f.category, f.option_type,
                   f.latest_nav, f.returns_1y, f.returns_3y, f.returns_5y,
                   f.expense_ratio, f.sharpe_ratio,
                   COALESCE(fh.short_name, fh.name) AS fund_house,
                   p.id AS portfolio_id, p.name AS portfolio_name
            FROM mf_holdings h
            JOIN funds f ON f.id = h.fund_id
            LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
            JOIN portfolios p ON p.id = h.portfolio_id
            WHERE h.units > 0.001 $pWhere
            ORDER BY f.category, h.units * f.latest_nav DESC
        ");
        $stmt->execute([$pParam]);
        $rows = $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }

    $totalValue = 0;
    $result = [];
    foreach ($rows as $r) {
        $cv = (float)$r['units'] * (float)($r['latest_nav'] ?? 0);
        $invested = (float)$r['units'] * (float)($r['avg_cost_nav'] ?? 0);
        $totalValue += $cv;
        $result[] = [
            'fund_id'       => (int)$r['fund_id'],
            'scheme_name'   => $r['scheme_name'],
            'category'      => $r['category'] ?? 'Unknown',
            'option_type'   => $r['option_type'],
            'fund_house'    => $r['fund_house'],
            'units'         => (float)$r['units'],
            'avg_cost_nav'  => (float)$r['avg_cost_nav'],
            'current_nav'   => (float)($r['latest_nav'] ?? 0),
            'current_value' => round($cv, 2),
            'invested'      => round($invested, 2),
            'gain_pct'      => $invested > 0 ? round(($cv - $invested) / $invested * 100, 2) : 0,
            'returns_1y'    => isset($r['returns_1y']) ? (float)$r['returns_1y'] : null,
            'returns_3y'    => isset($r['returns_3y']) ? (float)$r['returns_3y'] : null,
            'expense_ratio' => isset($r['expense_ratio']) ? (float)$r['expense_ratio'] : null,
            'sharpe_ratio'  => isset($r['sharpe_ratio'])  ? (float)$r['sharpe_ratio']  : null,
            'is_direct'     => stripos($r['scheme_name'] ?? '', 'direct') !== false,
            'portfolio_name'=> $r['portfolio_name'],
        ];
    }

    // Add allocation %
    foreach ($result as &$h) {
        $h['allocation_pct'] = $totalValue > 0 ? round($h['current_value'] / $totalValue * 100, 1) : 0;
    }

    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════
// OVERLAP MATRIX (category-based)
// ═══════════════════════════════════════════════════════════════════════════
function build_overlap_matrix(array $holdings): array
{
    $matrix = [];

    // Category-based overlap scores (Indian MF categories)
    $overlapRules = [
        // Same category
        'same_category' => 85,
        // High overlap pairs
        'large_cap+flexi_cap'       => 70,
        'large_cap+nifty_index'     => 80,
        'nifty50+nifty_next50'      => 50,
        'mid_cap+small_cap'         => 45,
        'flexi_cap+multi_cap'       => 65,
        'flexi_cap+large_mid_cap'   => 60,
        'large_cap+multi_cap'       => 65,
    ];

    $n = count($holdings);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $a = $holdings[$i];
            $b = $holdings[$j];

            $overlap = estimate_category_overlap($a['category'], $b['category'], $a['scheme_name'], $b['scheme_name']);

            if ($overlap >= 40) { // Only show meaningful overlaps
                $matrix[] = [
                    'fund_a'         => ['id' => $a['fund_id'], 'name' => $a['scheme_name'], 'category' => $a['category']],
                    'fund_b'         => ['id' => $b['fund_id'], 'name' => $b['scheme_name'], 'category' => $b['category']],
                    'overlap_pct'    => $overlap,
                    'overlap_label'  => overlap_label($overlap),
                    'color'          => overlap_color($overlap),
                    'is_redundant'   => $overlap >= 70,
                ];
            }
        }
    }

    usort($matrix, fn($a, $b) => $b['overlap_pct'] <=> $a['overlap_pct']);
    return $matrix;
}

function estimate_category_overlap(string $catA, string $catB, string $nameA, string $nameB): int
{
    $a = strtolower($catA);
    $b = strtolower($catB);
    $na = strtolower($nameA);
    $nb = strtolower($nameB);

    // Same category = high overlap
    if ($a === $b) return 85;

    // Known high-overlap pairs
    $pairs = [
        ['large cap', 'flexi cap', 72],
        ['large cap', 'multi cap', 68],
        ['large cap', 'large & mid cap', 65],
        ['flexi cap', 'multi cap', 70],
        ['flexi cap', 'large & mid cap', 65],
        ['mid cap', 'large & mid cap', 60],
        ['mid cap', 'flexi cap', 50],
        ['small cap', 'mid cap', 45],
        ['sectoral', 'thematic', 50],
        ['balanced advantage', 'aggressive hybrid', 55],
        ['aggressive hybrid', 'equity savings', 40],
    ];

    foreach ($pairs as [$p1, $p2, $score]) {
        if ((str_contains($a, $p1) && str_contains($b, $p2)) ||
            (str_contains($a, $p2) && str_contains($b, $p1))) {
            return $score;
        }
    }

    // Index vs passive check
    $isIndexA = str_contains($na, 'index') || str_contains($na, 'nifty') || str_contains($na, 'sensex');
    $isIndexB = str_contains($nb, 'index') || str_contains($nb, 'nifty') || str_contains($nb, 'sensex');
    if ($isIndexA && $isIndexB) return 80;

    // Debt + equity = no overlap
    $isDebtA = str_contains($a, 'debt') || str_contains($a, 'liquid') || str_contains($a, 'gilt');
    $isDebtB = str_contains($b, 'debt') || str_contains($b, 'liquid') || str_contains($b, 'gilt');
    if ($isDebtA !== $isDebtB) return 0;

    return 25; // default low overlap for uncategorized pairs
}

function overlap_label(int $pct): string
{
    return match (true) {
        $pct >= 80 => 'Very High Overlap — Redundant',
        $pct >= 65 => 'High Overlap — Review needed',
        $pct >= 50 => 'Moderate Overlap',
        $pct >= 40 => 'Some Overlap',
        default    => 'Low Overlap',
    };
}

function overlap_color(int $pct): string
{
    return match (true) {
        $pct >= 80 => '#dc2626',
        $pct >= 65 => '#ea580c',
        $pct >= 50 => '#d97706',
        default    => '#65a30d',
    };
}

// ═══════════════════════════════════════════════════════════════════════════
// REDUNDANT PAIRS
// ═══════════════════════════════════════════════════════════════════════════
function find_redundant_pairs(array $matrix): array
{
    return array_values(array_filter($matrix, fn($m) => $m['overlap_pct'] >= 70));
}

// ═══════════════════════════════════════════════════════════════════════════
// CONSOLIDATION PLAN
// ═══════════════════════════════════════════════════════════════════════════
function build_consolidation_plan(array $redundantPairs, array $holdings): array
{
    $suggestions = [];

    foreach ($redundantPairs as $pair) {
        $fundA = $pair['fund_a'];
        $fundB = $pair['fund_b'];

        // Find holding details
        $hA = find_holding($holdings, $fundA['id']);
        $hB = find_holding($holdings, $fundB['id']);

        if (!$hA || !$hB) continue;

        // Decide which to keep (better returns, lower cost, direct plan preferred)
        $scoreA = holding_score($hA);
        $scoreB = holding_score($hB);
        $keepA  = $scoreA >= $scoreB;

        $keep   = $keepA ? $hA : $hB;
        $exit   = $keepA ? $hB : $hA;

        // Tax impact of consolidation
        $exitGain = $exit['gain_pct'] > 0;
        $taxNote  = $exitGain
            ? "Note: Exiting {$exit['scheme_name']} may trigger STCG/LTCG tax on ₹" . number_format($exit['current_value'] - $exit['invested'], 0) . " gain."
            : "Tax efficient exit — {$exit['scheme_name']} is at a loss, exiting saves tax.";

        $suggestions[] = [
            'overlap_pct'    => $pair['overlap_pct'],
            'keep'           => ['fund_id' => $keep['fund_id'], 'name' => $keep['scheme_name'],
                                  'value' => $keep['current_value'], 'score' => round($scoreA > $scoreB ? $scoreA : $scoreB, 1)],
            'exit'           => ['fund_id' => $exit['fund_id'], 'name' => $exit['scheme_name'],
                                  'value' => $exit['current_value'], 'gain_pct' => $exit['gain_pct']],
            'keep_reason'    => keep_reason($keep, $exit),
            'action'         => "Redeem {$exit['scheme_name']} and invest proceeds into {$keep['scheme_name']}",
            'tax_note'       => $taxNote,
            'amount_to_move' => $exit['current_value'],
        ];
    }

    return $suggestions;
}

function holding_score(array $h): float
{
    $score = 0;
    if ($h['is_direct'])         $score += 25; // direct plan is much better
    if ($h['returns_3y'] !== null) $score += min(30, $h['returns_3y']); // 3Y returns
    if ($h['expense_ratio'] !== null) $score += max(0, 10 - $h['expense_ratio'] * 5); // lower TER better
    if ($h['sharpe_ratio'] !== null)  $score += min(10, $h['sharpe_ratio'] * 5);
    return $score;
}

function keep_reason(array $keep, array $exit): string
{
    $reasons = [];
    if ($keep['is_direct'] && !$exit['is_direct']) $reasons[] = 'Direct plan (lower expense ratio)';
    if ($keep['returns_3y'] !== null && $exit['returns_3y'] !== null && $keep['returns_3y'] > $exit['returns_3y']) {
        $diff = round($keep['returns_3y'] - $exit['returns_3y'], 1);
        $reasons[] = "Better 3Y returns by {$diff}%";
    }
    if ($keep['expense_ratio'] !== null && $exit['expense_ratio'] !== null && $keep['expense_ratio'] < $exit['expense_ratio']) {
        $reasons[] = 'Lower expense ratio';
    }
    return empty($reasons) ? 'Higher composite score (returns + cost + risk)' : implode(', ', $reasons);
}

function find_holding(array $holdings, int $fundId): ?array
{
    foreach ($holdings as $h) {
        if ($h['fund_id'] === $fundId) return $h;
    }
    return null;
}

// ═══════════════════════════════════════════════════════════════════════════
// OPTIMAL FUND COUNT
// ═══════════════════════════════════════════════════════════════════════════
function calc_optimal_count(array $holdings): array
{
    $count = count($holdings);
    $totalValue = array_sum(array_column($holdings, 'current_value'));

    $categories = array_unique(array_column($holdings, 'category'));
    $catCount   = count($categories);

    [$status, $color, $recommendation] = match (true) {
        $count <= 3 => ['Underdiversified', '#d97706', 'Add 2-3 more funds across different categories for better diversification.'],
        $count <= 6 => ['Optimal', '#16a34a', 'You have an ideal number of funds. Focus on quality over quantity.'],
        $count <= 10 => ['Slightly Over', '#ea580c', 'Consider consolidating 2-3 overlapping funds. 4-6 well-chosen funds beat 10+ overlapping ones.'],
        default     => ['Over-diversified', '#dc2626', "Too many funds ({$count}) — diversification benefit diminishes beyond 8 funds. High overlap likely. Consolidate aggressively."],
    };

    // Category breakdown
    $byCat = [];
    foreach ($holdings as $h) {
        $cat = $h['category'];
        if (!isset($byCat[$cat])) $byCat[$cat] = ['count' => 0, 'value' => 0];
        $byCat[$cat]['count']++;
        $byCat[$cat]['value'] += $h['current_value'];
    }
    arsort($byCat);

    $warnings = [];
    foreach ($byCat as $cat => $info) {
        if ($info['count'] >= 3) {
            $warnings[] = "You have {$info['count']} funds in '{$cat}' — high overlap. Keep best 1-2.";
        }
    }

    return [
        'fund_count'      => $count,
        'category_count'  => $catCount,
        'status'          => $status,
        'color'           => $color,
        'recommendation'  => $recommendation,
        'ideal_range'     => '4-6 funds for most investors',
        'category_breakdown' => $byCat,
        'warnings'        => $warnings,
        'total_value'     => round($totalValue, 2),
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// UNDERPERFORMERS
// ═══════════════════════════════════════════════════════════════════════════
function find_underperformers(array $holdings): array
{
    // Category avg approximations
    $catAvg = [
        'large cap'   => 13.0,
        'mid cap'     => 16.0,
        'small cap'   => 18.0,
        'flexi cap'   => 14.0,
        'multi cap'   => 15.0,
        'index fund'  => 13.0,
        'elss'        => 14.0,
        'debt'        =>  7.0,
        'hybrid'      => 10.0,
    ];

    $underperformers = [];
    foreach ($holdings as $h) {
        if ($h['returns_3y'] === null) continue;
        $cat     = strtolower($h['category'] ?? '');
        $catAvgR = 14.0; // default
        foreach ($catAvg as $key => $avg) {
            if (str_contains($cat, $key)) { $catAvgR = $avg; break; }
        }
        $gap = $h['returns_3y'] - $catAvgR;
        if ($gap <= -3) { // underperforming by 3%+ for 3Y
            $underperformers[] = array_merge($h, [
                'category_avg_3y' => $catAvgR,
                'underperformance'=> round($gap, 1),
                'action'          => abs($gap) >= 5 ? 'Consider switching' : 'Monitor closely',
            ]);
        }
    }

    usort($underperformers, fn($a, $b) => $a['underperformance'] <=> $b['underperformance']);
    return $underperformers;
}

// ═══════════════════════════════════════════════════════════════════════════
// CLEANUP PLAN
// ═══════════════════════════════════════════════════════════════════════════
function build_cleanup_plan(array $redundant, array $small, array $underperformers, array $all): array
{
    $steps = [];
    $priority = 1;

    foreach ($redundant as $r) {
        $steps[] = [
            'priority'    => $priority++,
            'type'        => 'consolidate',
            'emoji'       => '🔄',
            'action'      => "Consolidate: Exit \"{$r['fund_b']['name']}\" → Move to \"{$r['fund_a']['name']}\"",
            'reason'      => "{$r['overlap_pct']}% overlap — redundant holding",
            'impact'      => 'Reduces fund count, improves clarity, reduces overlap',
        ];
    }

    foreach ($small as $h) {
        $steps[] = [
            'priority'    => $priority++,
            'type'        => 'exit_small',
            'emoji'       => '🗑️',
            'action'      => "Exit small holding: \"{$h['scheme_name']}\" (₹" . number_format($h['current_value'], 0) . ")",
            'reason'      => 'Holding < ₹5,000 — not worth tracking',
            'impact'      => 'Reduces complexity without meaningful portfolio impact',
        ];
    }

    foreach ($underperformers as $u) {
        if (abs($u['underperformance']) >= 5) {
            $steps[] = [
                'priority'    => $priority++,
                'type'        => 'switch',
                'emoji'       => '↩️',
                'action'      => "Switch underperformer: \"{$u['scheme_name']}\" to a better fund in same category",
                'reason'      => "{$u['underperformance']}% below category average (3Y)",
                'impact'      => "Could add {$u['underperformance']}%+ CAGR over time by switching",
            ];
        }
    }

    // Regular to Direct plan upgrades
    $regularFunds = array_filter($all, fn($h) => !$h['is_direct']);
    foreach ($regularFunds as $h) {
        if ($h['current_value'] >= 10000) {
            $steps[] = [
                'priority'    => $priority++,
                'type'        => 'direct_plan',
                'emoji'       => '💸',
                'action'      => "Switch to Direct plan: \"{$h['scheme_name']}\"",
                'reason'      => 'Regular plan costs 0.5-1.5% extra per year vs Direct',
                'impact'      => "Saves ₹" . number_format($h['current_value'] * 0.01, 0) . "/year in expense ratio",
            ];
        }
    }

    return [
        'steps'             => $steps,
        'total_steps'       => count($steps),
        'estimated_savings' => 'Consolidating to 4-6 direct funds can improve CAGR by 0.5-2% via lower TER + less overlap.',
    ];
}
