<?php
/**
 * WealthDash — t363: Tax Loss Harvesting Automation
 *
 * Identifies funds with unrealised losses to offset LTCG/STCG.
 * Special relevance: March 31 deadline (FY end).
 *
 * GET /api/mutual_funds/tax_loss_harvest.php
 *   ?portfolio_id=X        (optional; all portfolios if omitted)
 *   ?action=candidates     ← List funds eligible for harvesting
 *   ?action=impact         ← Tax saving calculation
 *   ?action=dashboard      ← Full harvest dashboard (default)
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
    $action      = $_GET['action'] ?? 'dashboard';
    $portfolioId = (int)($_GET['portfolio_id'] ?? 0);
    $userId      = (int)$currentUser['id'];

    // ── Build portfolio filter ───────────────────────────────────────────
    $pWhere  = $portfolioId > 0 ? ' AND h.portfolio_id = ?' : ' AND p.user_id = ?';
    $pParam  = $portfolioId > 0 ? $portfolioId : $userId;

    // ── Fetch holdings with current values ──────────────────────────────
    $holdings = fetch_holdings_with_gains($db, $pWhere, $pParam);

    // ── FY timeline ──────────────────────────────────────────────────────
    $fyInfo = fy_info();

    switch ($action) {
        case 'candidates':
            ob_clean();
            echo json_encode([
                'success'    => true,
                'candidates' => filter_loss_candidates($holdings),
                'fy_info'    => $fyInfo,
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'impact':
            $candidates = filter_loss_candidates($holdings);
            ob_clean();
            echo json_encode([
                'success' => true,
                'impact'  => calc_tax_impact($candidates, $holdings),
                'fy_info' => $fyInfo,
            ], JSON_UNESCAPED_UNICODE);
            break;

        case 'dashboard':
        default:
            $candidates   = filter_loss_candidates($holdings);
            $impact       = calc_tax_impact($candidates, $holdings);
            $gains        = fetch_fy_gains($db, $userId, $portfolioId);

            ob_clean();
            echo json_encode([
                'success'         => true,
                'fy_info'         => $fyInfo,
                'candidates'      => $candidates,
                'impact'          => $impact,
                'fy_gains'        => $gains,
                'wash_sale_warning' => [
                    'days'    => 30,
                    'message' => 'Do not repurchase the same fund within 30 days after harvesting — the loss benefit may be disallowed by tax authorities.',
                ],
                'alternatives'    => suggest_alternatives($candidates),
            ], JSON_UNESCAPED_UNICODE);
            break;
    }

} catch (Throwable $e) {
    ob_clean(); http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ═══════════════════════════════════════════════════════════════════════════
// HOLDINGS WITH GAINS/LOSSES
// ═══════════════════════════════════════════════════════════════════════════
function fetch_holdings_with_gains(PDO $db, string $pWhere, int $pParam): array
{
    try {
        $stmt = $db->prepare("
            SELECT
                h.id           AS holding_id,
                h.fund_id,
                h.units,
                h.avg_cost_nav,
                f.scheme_name,
                f.category,
                f.option_type,
                f.min_ltcg_days,
                f.latest_nav   AS current_nav,
                f.latest_nav_date,
                COALESCE(fh.short_name, fh.name) AS fund_house,
                p.id           AS portfolio_id,
                p.name         AS portfolio_name,
                (SELECT MIN(t.txn_date)
                 FROM mf_transactions t
                 WHERE t.fund_id = h.fund_id AND t.portfolio_id = h.portfolio_id
                   AND t.transaction_type IN ('buy','sip')) AS first_buy_date
            FROM mf_holdings h
            JOIN funds f ON f.id = h.fund_id
            LEFT JOIN fund_houses fh ON fh.id = f.fund_house_id
            JOIN portfolios p ON p.id = h.portfolio_id
            WHERE h.units > 0.001 $pWhere
        ");
        $stmt->execute([$pParam]);
        $rows = $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }

    $today = new DateTime();
    $result = [];

    foreach ($rows as $row) {
        $units      = (float)$row['units'];
        $avgCost    = (float)($row['avg_cost_nav'] ?? 0);
        $currentNav = (float)($row['current_nav'] ?? 0);

        if ($avgCost <= 0 || $currentNav <= 0 || $units <= 0) continue;

        $invested    = round($units * $avgCost, 2);
        $currentVal  = round($units * $currentNav, 2);
        $unrealisedPL = round($currentVal - $invested, 2);
        $gainPct      = round(($unrealisedPL / $invested) * 100, 2);

        // Holding period
        $firstBuyDate = $row['first_buy_date'] ?? null;
        $holdingDays  = 0;
        if ($firstBuyDate) {
            $buyDt       = new DateTime($firstBuyDate);
            $holdingDays = (int)$today->diff($buyDt)->days;
        }

        $ltcgDays  = (int)($row['min_ltcg_days'] ?? 365);
        $isLtcg    = $holdingDays >= $ltcgDays;

        // Tax type
        $taxType = $isLtcg ? 'LTCG' : 'STCG';
        $taxRate = tax_rate($row['category'] ?? '', $isLtcg);

        $result[] = [
            'holding_id'      => (int)$row['holding_id'],
            'fund_id'         => (int)$row['fund_id'],
            'scheme_name'     => $row['scheme_name'],
            'category'        => $row['category'],
            'option_type'     => $row['option_type'],
            'fund_house'      => $row['fund_house'],
            'portfolio_name'  => $row['portfolio_name'],
            'units'           => $units,
            'avg_cost_nav'    => $avgCost,
            'current_nav'     => $currentNav,
            'invested'        => $invested,
            'current_value'   => $currentVal,
            'unrealised_pl'   => $unrealisedPL,
            'gain_pct'        => $gainPct,
            'holding_days'    => $holdingDays,
            'first_buy_date'  => $firstBuyDate,
            'tax_type'        => $taxType,
            'tax_rate_pct'    => $taxRate,
            'is_loss'         => $unrealisedPL < 0,
            'is_gain'         => $unrealisedPL > 0,
        ];
    }

    return $result;
}

// ═══════════════════════════════════════════════════════════════════════════
// FILTER LOSS CANDIDATES
// ═══════════════════════════════════════════════════════════════════════════
function filter_loss_candidates(array $holdings): array
{
    $candidates = array_filter($holdings, fn($h) => $h['is_loss'] && abs($h['unrealised_pl']) >= 500);
    usort($candidates, fn($a, $b) => $a['unrealised_pl'] <=> $b['unrealised_pl']); // most loss first

    return array_values(array_map(function ($h) {
        $taxSaved = round(abs($h['unrealised_pl']) * ($h['tax_rate_pct'] / 100), 2);
        return array_merge($h, [
            'loss_amount'    => abs($h['unrealised_pl']),
            'tax_saved'      => $taxSaved,
            'action_label'   => "Sell {$h['units']} units → Book ₹" . number_format(abs($h['unrealised_pl']), 0) . " loss",
            'rebuy_after'    => date('Y-m-d', strtotime('+31 days')),
            'rebuy_warning'  => 'Wait 30+ days before repurchasing to avoid wash sale issues',
            'priority'       => abs($h['unrealised_pl']) >= 10000 ? 'high' : (abs($h['unrealised_pl']) >= 2000 ? 'medium' : 'low'),
        ]);
    }, $candidates));
}

// ═══════════════════════════════════════════════════════════════════════════
// TAX IMPACT CALCULATION
// ═══════════════════════════════════════════════════════════════════════════
function calc_tax_impact(array $candidates, array $allHoldings): array
{
    $totalLoss      = array_sum(array_column($candidates, 'loss_amount'));
    $totalTaxSaved  = array_sum(array_column($candidates, 'tax_saved'));

    // Total gains to offset against
    $gains = array_filter($allHoldings, fn($h) => $h['is_gain']);
    $totalStcgGains = array_sum(array_map(
        fn($h) => $h['tax_type'] === 'STCG' ? $h['unrealised_pl'] : 0, $gains
    ));
    $totalLtcgGains = array_sum(array_map(
        fn($h) => $h['tax_type'] === 'LTCG' ? max(0, $h['unrealised_pl'] - 125000) : 0, $gains // LTCG exempt 1.25L
    ));

    // Net taxable after offset
    $netStcg = max(0, $totalStcgGains - array_sum(array_map(
        fn($h) => $h['tax_type'] === 'STCG' ? $h['loss_amount'] : 0, $candidates
    )));
    $netLtcg = max(0, $totalLtcgGains - array_sum(array_map(
        fn($h) => $h['tax_type'] === 'LTCG' ? $h['loss_amount'] : 0, $candidates
    )));

    $stcgTaxSaved = round(($totalStcgGains - $netStcg) * 0.20, 2);
    $ltcgTaxSaved = round(($totalLtcgGains - $netLtcg) * 0.125, 2);

    return [
        'total_loss_harvestable'  => round($totalLoss, 2),
        'total_tax_saved'         => round($totalTaxSaved, 2),
        'total_stcg_gains'        => round($totalStcgGains, 2),
        'total_ltcg_gains'        => round($totalLtcgGains, 2),
        'net_stcg_after_offset'   => round($netStcg, 2),
        'net_ltcg_after_offset'   => round($netLtcg, 2),
        'stcg_tax_saved'          => $stcgTaxSaved,
        'ltcg_tax_saved'          => $ltcgTaxSaved,
        'ltcg_exemption'          => 125000,
        'tax_rates'               => ['stcg' => '20%', 'ltcg' => '12.5% (above ₹1.25L)'],
        'candidates_count'        => count($candidates),
        'net_impact_summary'      => "Harvesting losses from " . count($candidates) . " fund(s) can save ~₹" .
                                    number_format($stcgTaxSaved + $ltcgTaxSaved, 0) . " in taxes this FY.",
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// FY GAINS (realised this year)
// ═══════════════════════════════════════════════════════════════════════════
function fetch_fy_gains(PDO $db, int $userId, int $portfolioId): array
{
    $fyStart = fy_start();

    try {
        $pWhere = $portfolioId > 0 ? ' AND t.portfolio_id = ?' : ' AND p.user_id = ?';
        $pParam = $portfolioId > 0 ? $portfolioId : $userId;

        $stmt = $db->prepare("
            SELECT
                SUM(CASE WHEN t.gain_loss_amount > 0 AND t.gain_type = 'STCG' THEN t.gain_loss_amount ELSE 0 END) AS realised_stcg,
                SUM(CASE WHEN t.gain_loss_amount > 0 AND t.gain_type = 'LTCG' THEN t.gain_loss_amount ELSE 0 END) AS realised_ltcg,
                SUM(CASE WHEN t.gain_loss_amount < 0 THEN ABS(t.gain_loss_amount) ELSE 0 END) AS realised_losses
            FROM mf_transactions t
            JOIN portfolios p ON p.id = t.portfolio_id
            WHERE t.transaction_type IN ('sell','redeem')
              AND t.txn_date >= ? $pWhere
        ");
        $stmt->execute([$fyStart, $pParam]);
        $row = $stmt->fetch();
        return [
            'fy_start'       => $fyStart,
            'realised_stcg'  => round((float)($row['realised_stcg'] ?? 0), 2),
            'realised_ltcg'  => round((float)($row['realised_ltcg'] ?? 0), 2),
            'realised_losses'=> round((float)($row['realised_losses'] ?? 0), 2),
        ];
    } catch (Exception $e) {
        return ['fy_start' => $fyStart, 'realised_stcg' => 0, 'realised_ltcg' => 0, 'realised_losses' => 0];
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// ALTERNATIVE FUND SUGGESTIONS (same category, avoid wash sale)
// ═══════════════════════════════════════════════════════════════════════════
function suggest_alternatives(array $candidates): array
{
    if (empty($candidates)) return [];

    $suggestions = [];
    foreach ($candidates as $c) {
        $cat = $c['category'];
        $suggestions[] = [
            'for_fund'   => $c['scheme_name'],
            'category'   => $cat,
            'suggestion' => "After selling {$c['scheme_name']}, consider investing in a different fund of the same category ($cat) for 30 days, then switch back if preferred. This avoids wash sale issues while maintaining market exposure.",
            'tip'        => "Look for an index fund in the same category as a temporary placeholder — lower cost and same market exposure.",
        ];
    }
    return $suggestions;
}

// ═══════════════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════════════

function fy_info(): array
{
    $today = new DateTime();
    $year  = (int)$today->format('Y');
    $month = (int)$today->format('n');

    $fyYear   = $month >= 4 ? $year : $year - 1;
    $fyStart  = "{$fyYear}-04-01";
    $fyEnd    = ($fyYear + 1) . "-03-31";
    $fyEndDt  = new DateTime($fyEnd);
    $daysLeft = (int)$today->diff($fyEndDt)->days;

    $urgency = match (true) {
        $daysLeft <= 7  => 'critical',
        $daysLeft <= 30 => 'high',
        $daysLeft <= 90 => 'medium',
        default         => 'low',
    };

    return [
        'fy_label'   => "FY {$fyYear}-" . ($fyYear + 1),
        'fy_start'   => $fyStart,
        'fy_end'     => $fyEnd,
        'days_left'  => $daysLeft,
        'urgency'    => $urgency,
        'alert'      => $daysLeft <= 30
            ? "⚠️ Only {$daysLeft} days left in FY! Book losses by March 31 to offset this year's gains."
            : null,
    ];
}

function fy_start(): string
{
    $today = new DateTime();
    $year  = (int)$today->format('Y');
    $month = (int)$today->format('n');
    return ($month >= 4 ? $year : $year - 1) . '-04-01';
}

function tax_rate(string $category, bool $isLtcg): float
{
    $cat = strtolower($category);
    $isDebt = str_contains($cat, 'debt') || str_contains($cat, 'liquid') ||
              str_contains($cat, 'overnight') || str_contains($cat, 'gilt') ||
              str_contains($cat, 'credit');

    if ($isDebt) return $isLtcg ? 20.0 : 30.0; // slab rate approx
    return $isLtcg ? 12.5 : 20.0; // equity: LTCG 12.5%, STCG 20%
}
