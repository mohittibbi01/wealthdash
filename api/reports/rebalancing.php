<?php
/**
 * WealthDash — t312: Portfolio Rebalancing Report
 *
 * Actions (all via action param):
 *   report_rebalancing        — full drift + suggestions
 *   rebalancing_save_targets  — save target allocation to app_settings
 *   rebalancing_load_targets  — load saved targets
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$isAdmin     = is_admin();
$action      = $_GET['action'] ?? $_POST['action'] ?? 'report_rebalancing';

$portfolioId = (int)($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ?? 0);
if (!$portfolioId) $portfolioId = get_user_portfolio_id($userId);

if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
    json_response(false, 'Invalid or inaccessible portfolio.');
}

// ── Settings key ─────────────────────────────────────────────────────────
$SETTINGS_KEY = "rebal_targets_p{$portfolioId}";
const THRESHOLD_DEFAULT = 5.0;

// ── Load saved targets ────────────────────────────────────────────────────
function load_rebal_targets(string $key): array {
    $defaults = [
        'equity' => 60.0, 'debt' => 25.0, 'gold' => 5.0,
        'nps'    => 5.0,  'real_estate' => 5.0,
        'threshold' => THRESHOLD_DEFAULT,
    ];
    try {
        $row = DB::fetchRow(
            "SELECT setting_val FROM app_settings WHERE setting_key = ?", [$key]
        );
        if ($row && $row['setting_val']) {
            $saved = json_decode($row['setting_val'], true);
            if (is_array($saved)) return array_merge($defaults, $saved);
        }
    } catch (Exception $e) {}
    return $defaults;
}

// ══════════════════════════════════════════════════════════════════
// ACTION: LOAD TARGETS
// ══════════════════════════════════════════════════════════════════
if ($action === 'rebalancing_load_targets') {
    json_response(true, 'Targets loaded.', load_rebal_targets($SETTINGS_KEY));
}

// ══════════════════════════════════════════════════════════════════
// ACTION: SAVE TARGETS
// ══════════════════════════════════════════════════════════════════
if ($action === 'rebalancing_save_targets') {
    $equity      = (float)($_POST['equity']      ?? 60);
    $debt        = (float)($_POST['debt']        ?? 25);
    $gold        = (float)($_POST['gold']        ?? 5);
    $nps         = (float)($_POST['nps']         ?? 5);
    $real_estate = (float)($_POST['real_estate'] ?? 5);
    $threshold   = max(1.0, min(20.0, (float)($_POST['threshold'] ?? THRESHOLD_DEFAULT)));

    $total = $equity + $debt + $gold + $nps + $real_estate;
    if (abs($total - 100) > 0.1) {
        json_response(false, "Targets must sum to 100. Got {$total}.");
    }

    $payload = json_encode(compact('equity','debt','gold','nps','real_estate','threshold'));
    try {
        DB::execute(
            "INSERT INTO app_settings (setting_key, setting_val) VALUES (?,?)
             ON DUPLICATE KEY UPDATE setting_val=VALUES(setting_val), updated_at=NOW()",
            [$SETTINGS_KEY, $payload]
        );
    } catch (Exception $e) {
        json_response(false, 'Could not save targets: ' . $e->getMessage());
    }
    json_response(true, 'Targets saved.', compact('equity','debt','gold','nps','real_estate','threshold'));
}

// ══════════════════════════════════════════════════════════════════
// ACTION: FULL REBALANCING REPORT
// ══════════════════════════════════════════════════════════════════
$targets   = load_rebal_targets($SETTINGS_KEY);
$threshold = (float)($_POST['threshold'] ?? $targets['threshold']);

// Accept overrides from POST (live slider usage)
$tEquity  = (float)($_POST['equity']      ?? $targets['equity']);
$tDebt    = (float)($_POST['debt']        ?? $targets['debt']);
$tGold    = (float)($_POST['gold']        ?? $targets['gold']);
$tNPS     = (float)($_POST['nps']         ?? $targets['nps']);
$tRE      = (float)($_POST['real_estate'] ?? $targets['real_estate']);

// ── Fetch current values ──────────────────────────────────────────────────

// Equity MF (excludes Debt/Liquid/Gold/Money Market)
$equityMf = (float)DB::fetchVal(
    "SELECT COALESCE(SUM(h.value_now),0)
     FROM mf_holdings h LEFT JOIN funds f ON f.id = h.fund_id
     WHERE h.portfolio_id=? AND h.is_active=1
       AND (f.scheme_category IS NULL OR (
            f.scheme_category NOT REGEXP 'Debt|Liquid|Money Market|Credit Risk|Gilt|Banking and PSU|Corporate Bond|Short Duration|Medium Duration|Low Duration|Ultra Short|Overnight|Floater'
            AND f.scheme_category NOT LIKE '%Gold%'
       ))",
    [$portfolioId]
);

// Debt MF
$debtMf = (float)DB::fetchVal(
    "SELECT COALESCE(SUM(h.value_now),0)
     FROM mf_holdings h LEFT JOIN funds f ON f.id = h.fund_id
     WHERE h.portfolio_id=? AND h.is_active=1
       AND f.scheme_category REGEXP 'Debt|Liquid|Money Market|Credit Risk|Gilt|Banking and PSU|Corporate Bond|Short Duration|Medium Duration|Low Duration|Ultra Short|Overnight|Floater|Arbitrage|Equity Savings'",
    [$portfolioId]
);

// Gold MF
$goldMf = (float)DB::fetchVal(
    "SELECT COALESCE(SUM(h.value_now),0)
     FROM mf_holdings h LEFT JOIN funds f ON f.id = h.fund_id
     WHERE h.portfolio_id=? AND h.is_active=1 AND f.scheme_category LIKE '%Gold%'",
    [$portfolioId]
);

// Direct stocks
$stocks = (float)DB::fetchVal(
    "SELECT COALESCE(SUM(current_value),0) FROM stock_holdings WHERE portfolio_id=? AND is_active=1",
    [$portfolioId]
);
$stocksInv = (float)DB::fetchVal(
    "SELECT COALESCE(SUM(total_invested),0) FROM stock_holdings WHERE portfolio_id=? AND is_active=1",
    [$portfolioId]
);

// FD + Savings
$fdRow = DB::fetchRow(
    "SELECT COALESCE(SUM(principal),0) AS p, COALESCE(SUM(accrued_interest),0) AS ai
     FROM fd_accounts WHERE portfolio_id=? AND status='active'",
    [$portfolioId]
);
$fd       = (float)($fdRow['p'] ?? 0) + (float)($fdRow['ai'] ?? 0);
$savings  = (float)DB::fetchVal(
    "SELECT COALESCE(SUM(current_balance),0) FROM savings_accounts WHERE portfolio_id=? AND is_active=1",
    [$portfolioId]
);

// NPS
$npsTotal = (float)DB::fetchVal(
    "SELECT COALESCE(SUM(latest_value),0) FROM nps_holdings WHERE portfolio_id=?", [$portfolioId]
);

// Physical Gold + SGB
$physGold = (float)DB::fetchVal(
    "SELECT COALESCE(SUM(current_value),0) FROM gold_holdings WHERE portfolio_id=? AND is_active=1",
    [$portfolioId]
);
$sgbTotal = (float)DB::fetchVal(
    "SELECT COALESCE(SUM(current_value),0) FROM sgb_holdings WHERE portfolio_id=? AND is_active=1",
    [$portfolioId]
);

// Real Estate (if module exists)
$reTotal = 0.0;
try {
    $reTotal = (float)DB::fetchVal(
        "SELECT COALESCE(SUM(current_value),0) FROM real_estate_holdings WHERE portfolio_id=? AND is_active=1",
        [$portfolioId]
    );
} catch (Exception $e) {}

// ── Aggregate asset classes ───────────────────────────────────────────────
$curEquity = $equityMf + $stocks;
$curDebt   = $debtMf + $fd + $savings;
$curGold   = $goldMf + $physGold + $sgbTotal;
$curNPS    = $npsTotal;
$curRE     = $reTotal;
$total     = $curEquity + $curDebt + $curGold + $curNPS + $curRE;

if ($total <= 0) {
    json_response(true, 'No holdings found.', [
        'total_portfolio' => 0, 'suggestions' => [], 'classes' => [],
        'is_balanced' => true, 'targets' => $targets,
    ]);
}

// Pct helper
$pct = fn(float $v): float => round($v / $total * 100, 2);

// ── Build asset class map ─────────────────────────────────────────────────
$classes = [
    [
        'key'         => 'equity',
        'label'       => 'Equity',
        'emoji'       => '📈',
        'color'       => '#2563eb',
        'current'     => round($curEquity, 2),
        'current_pct' => $pct($curEquity),
        'target_pct'  => $tEquity,
        'breakdown'   => [
            ['name'=>'Equity MF',     'value'=>round($equityMf,2)],
            ['name'=>'Direct Stocks', 'value'=>round($stocks,2)],
        ],
        'tax_note'    => 'LTCG 10% above ₹1L (MF/Stocks, held >1yr). STCG 15% if <1yr.',
        'rebal_hint'  => 'Prefer redeeming highest-gain equity funds first. Avoid STCG if possible.',
    ],
    [
        'key'         => 'debt',
        'label'       => 'Debt',
        'emoji'       => '🏦',
        'color'       => '#d97706',
        'current'     => round($curDebt, 2),
        'current_pct' => $pct($curDebt),
        'target_pct'  => $tDebt,
        'breakdown'   => [
            ['name'=>'Debt MF',           'value'=>round($debtMf,2)],
            ['name'=>'Fixed Deposits',    'value'=>round($fd,2)],
            ['name'=>'Savings Accounts',  'value'=>round($savings,2)],
        ],
        'tax_note'    => 'Debt MF: gains taxed at slab rate (post Apr 2023 rules). FD interest: slab rate.',
        'rebal_hint'  => 'Increase via new FD or liquid/short-duration MF SIP. Avoid premature FD break (penalty).',
    ],
    [
        'key'         => 'gold',
        'label'       => 'Gold',
        'emoji'       => '🥇',
        'color'       => '#f59e0b',
        'current'     => round($curGold, 2),
        'current_pct' => $pct($curGold),
        'target_pct'  => $tGold,
        'breakdown'   => [
            ['name'=>'Gold MF / ETF',    'value'=>round($goldMf,2)],
            ['name'=>'Physical Gold',    'value'=>round($physGold,2)],
            ['name'=>'SGB',              'value'=>round($sgbTotal,2)],
        ],
        'tax_note'    => 'SGB on maturity: fully tax-free. Gold ETF LTCG: slab rate (held >3yr). Physical gold: slab rate.',
        'rebal_hint'  => 'Buy SGB during RBI windows for tax-free maturity. Avoid selling SGB before 5yr early-exit window.',
    ],
    [
        'key'         => 'nps',
        'label'       => 'NPS / Pension',
        'emoji'       => '🏛️',
        'color'       => '#7c3aed',
        'current'     => round($curNPS, 2),
        'current_pct' => $pct($curNPS),
        'target_pct'  => $tNPS,
        'breakdown'   => [['name'=>'NPS (All tiers)', 'value'=>round($curNPS,2)]],
        'tax_note'    => '60% lump sum at 60: tax-free. 40% must be annuity. Contributions: 80C + 80CCD(1B) ₹50K extra.',
        'rebal_hint'  => 'Rebalance via asset class switch inside NPS (E→C→G). No capital gains tax on internal switch.',
    ],
    [
        'key'         => 'real_estate',
        'label'       => 'Real Estate',
        'emoji'       => '🏠',
        'color'       => '#0f766e',
        'current'     => round($curRE, 2),
        'current_pct' => $pct($curRE),
        'target_pct'  => $tRE,
        'breakdown'   => [['name'=>'Property Portfolio', 'value'=>round($curRE,2)]],
        'tax_note'    => 'LTCG 20% with indexation (held >2yr). 54/54EC exemption available on reinvestment.',
        'rebal_hint'  => 'Illiquid asset — difficult to rebalance quickly. REITs are liquid alternative for RE exposure.',
    ],
];

// ── Compute drift & suggestions ───────────────────────────────────────────
$suggestions = [];
foreach ($classes as &$c) {
    $drift = round($c['current_pct'] - $c['target_pct'], 2);
    $c['drift_pct']     = $drift;
    $c['target_value']  = round($total * $c['target_pct'] / 100, 2);
    $c['drift_value']   = round($c['current'] - $c['target_value'], 2);
    $c['status']        = abs($drift) <= $threshold ? 'ok'
                          : ($drift > 0 ? 'over' : 'under');

    if (abs($drift) > $threshold) {
        $action2 = $drift > 0 ? 'REDUCE' : 'INCREASE';
        $amt     = abs($c['drift_value']);
        $suggestions[] = [
            'asset_class'   => $c['label'],
            'emoji'         => $c['emoji'],
            'color'         => $c['color'],
            'action'        => $action2,
            'current_pct'   => $c['current_pct'],
            'target_pct'    => $c['target_pct'],
            'drift_pct'     => $drift,
            'amount'        => round($amt, 2),
            'message'       => $action2 === 'REDUCE'
                ? "{$c['label']} is {$c['current_pct']}% vs target {$c['target_pct']}%. "
                  . "Reduce by ₹" . format_inr($amt) . "."
                : "{$c['label']} is {$c['current_pct']}% vs target {$c['target_pct']}%. "
                  . "Add ₹" . format_inr($amt) . " more.",
            'tax_note'      => $c['tax_note'],
            'rebal_hint'    => $c['rebal_hint'],
        ];
    }
}
unset($c);

// Sort: biggest drift first
usort($suggestions, fn($a,$b) => abs($b['drift_pct']) <=> abs($a['drift_pct']));

// ── Concentration risk ────────────────────────────────────────────────────
$concentration = [];
$topMF = DB::fetchAll(
    "SELECT COALESCE(f.scheme_name, h.scheme_name) AS name,
            h.value_now,
            ROUND((h.value_now / ?) * 100, 2) AS pct
     FROM mf_holdings h LEFT JOIN funds f ON f.id = h.fund_id
     WHERE h.portfolio_id=? AND h.is_active=1 AND (h.value_now/?) >= 0.15
     ORDER BY h.value_now DESC LIMIT 5",
    [$total, $portfolioId, $total]
);
foreach ($topMF as $f) {
    if ((float)$f['pct'] >= 15) {
        $concentration[] = [
            'type'    => 'MF',
            'name'    => $f['name'],
            'value'   => round((float)$f['value_now'], 2),
            'pct'     => (float)$f['pct'],
            'warning' => "Fund is {$f['pct']}% of total portfolio — high concentration.",
        ];
    }
}
$topStocks = DB::fetchAll(
    "SELECT sm.symbol, sm.company_name, h.current_value,
            ROUND((h.current_value / ?) * 100, 2) AS pct
     FROM stock_holdings h JOIN stock_master sm ON sm.id = h.stock_id
     WHERE h.portfolio_id=? AND h.is_active=1 AND (h.current_value/?) >= 0.10
     ORDER BY h.current_value DESC LIMIT 5",
    [$total, $portfolioId, $total]
);
foreach ($topStocks as $s) {
    if ((float)$s['pct'] >= 10) {
        $concentration[] = [
            'type'    => 'Stock',
            'name'    => ($s['company_name'] ?? '') . ' (' . $s['symbol'] . ')',
            'value'   => round((float)$s['current_value'], 2),
            'pct'     => (float)$s['pct'],
            'warning' => "Stock is {$s['pct']}% of total — concentration risk.",
        ];
    }
}

// ── Smart rebalancing method ──────────────────────────────────────────────
// Determine if SIP-based rebalancing (no tax) is feasible
$sipRebalFeasible = false;
$monthlySip = (float)DB::fetchVal(
    "SELECT COALESCE(SUM(amount),0) FROM sip_registrations WHERE portfolio_id=? AND status='active'",
    [$portfolioId]
);
if ($monthlySip > 0) $sipRebalFeasible = true;

json_response(true, 'Rebalancing report loaded.', [
    'total_portfolio'    => round($total, 2),
    'classes'            => $classes,
    'suggestions'        => $suggestions,
    'concentration_risks'=> $concentration,
    'is_balanced'        => count($suggestions) === 0,
    'threshold_used'     => $threshold,
    'targets'            => [
        'equity'=>$tEquity,'debt'=>$tDebt,'gold'=>$tGold,
        'nps'=>$tNPS,'real_estate'=>$tRE,'threshold'=>$threshold,
    ],
    'sip_monthly'        => $monthlySip,
    'sip_rebal_feasible' => $sipRebalFeasible,
    'breakdown' => [
        'equity_mf'    => round($equityMf,2),
        'stocks'       => round($stocks,2),
        'debt_mf'      => round($debtMf,2),
        'fd'           => round($fd,2),
        'savings'      => round($savings,2),
        'gold_mf'      => round($goldMf,2),
        'phys_gold'    => round($physGold,2),
        'sgb'          => round($sgbTotal,2),
        'nps'          => round($npsTotal,2),
        'real_estate'  => round($curRE,2),
    ],
    'generated_at' => date('Y-m-d H:i:s'),
]);
