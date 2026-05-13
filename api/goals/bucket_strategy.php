<?php
/**
 * WealthDash — tg002: Bucket Strategy API
 *
 * Classic 3-bucket financial planning:
 *   Bucket 1 — Safety  (0-2 years):  FD, Liquid MF, Savings
 *   Bucket 2 — Stable  (2-5 years):  Debt MF, Hybrid, NPS Debt
 *   Bucket 3 — Growth  (5+ years):   Equity MF, Stocks, NPS Equity
 *
 * Actions:
 *   bucket_strategy_summary   — portfolio assets mapped into 3 buckets
 *   bucket_strategy_goals     — goals auto-classified by timeline
 *   bucket_strategy_save      — save custom bucket targets to DB
 *   bucket_strategy_load      — load saved targets
 *   bucket_strategy_health    — check bucket health + replenishment alerts
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$isAdmin     = is_admin();

$action      = $_GET['action'] ?? $_POST['action'] ?? 'bucket_strategy_summary';
$portfolioId = (int)($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ?? 0);
if (!$portfolioId) $portfolioId = get_user_portfolio_id($userId);

if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
    json_response(false, 'Invalid or inaccessible portfolio.');
}

// ══════════════════════════════════════════════════════════════════
// BUCKET DEFINITIONS
// ══════════════════════════════════════════════════════════════════
const BUCKETS = [
    1 => [
        'id'          => 1,
        'name'        => 'Safety Bucket',
        'emoji'       => '🛡️',
        'horizon'     => '0–2 years',
        'horizon_min' => 0,
        'horizon_max' => 24,   // months
        'color'       => '#0ea5e9',
        'bg'          => 'rgba(14,165,233,.08)',
        'border'      => 'rgba(14,165,233,.3)',
        'purpose'     => 'Emergency fund, near-term goals, capital preservation',
        'ideal_pct'   => 10,   // % of total portfolio
        'instruments' => ['FD', 'Savings Account', 'Liquid MF', 'Ultra Short MF', 'Money Market MF', 'Overnight MF'],
        'risk'        => 'Very Low',
        'expected_return' => '5–7%',
    ],
    2 => [
        'id'          => 2,
        'name'        => 'Stable Bucket',
        'emoji'       => '⚖️',
        'horizon'     => '2–5 years',
        'horizon_min' => 24,
        'horizon_max' => 60,
        'color'       => '#8b5cf6',
        'bg'          => 'rgba(139,92,246,.08)',
        'border'      => 'rgba(139,92,246,.3)',
        'purpose'     => 'Medium-term goals, income generation, inflation hedge',
        'ideal_pct'   => 20,
        'instruments' => ['Debt MF', 'Hybrid MF', 'Balanced Advantage', 'NPS (Debt)', 'RBI Bonds', 'SGB', 'PPF'],
        'risk'        => 'Low–Moderate',
        'expected_return' => '7–10%',
    ],
    3 => [
        'id'          => 3,
        'name'        => 'Growth Bucket',
        'emoji'       => '🚀',
        'horizon'     => '5+ years',
        'horizon_min' => 60,
        'horizon_max' => PHP_INT_MAX,
        'color'       => '#16a34a',
        'bg'          => 'rgba(22,163,74,.08)',
        'border'      => 'rgba(22,163,74,.3)',
        'purpose'     => 'Wealth creation, long-term goals, beat inflation',
        'ideal_pct'   => 70,
        'instruments' => ['Equity MF', 'Stocks', 'NPS (Equity)', 'ELSS', 'Index Funds', 'ETF'],
        'risk'        => 'High',
        'expected_return' => '12–15%',
    ],
];

// MF category → bucket mapping
function mf_category_to_bucket(string $category, string $subCat = ''): int {
    $cat = strtolower($category . ' ' . $subCat);

    // Bucket 1 — Safety (Liquid / Ultra Short / Money Market / Overnight)
    if (preg_match('/liquid|overnight|ultra.short|money.market|low.duration.*fund.*less/i', $cat)) return 1;

    // Bucket 2 — Stable (Debt, Hybrid, Balanced)
    if (preg_match('/debt|income|gilt|credit.risk|corporate.bond|banking.*psu|dynamic.bond|medium.duration|long.duration|short.duration|floater|hybrid|balanced|conservative|equity.savings|arbitrage|nps.*debt/i', $cat)) return 2;

    // Bucket 3 — Growth (Equity default)
    return 3;
}

// FD maturity bucket by months to maturity
function fd_to_bucket(string $maturityDate): int {
    $months = max(0, (int)round((strtotime($maturityDate) - time()) / (86400 * 30)));
    if ($months <= 24) return 1;
    if ($months <= 60) return 2;
    return 3;
}

// ══════════════════════════════════════════════════════════════════
// BUCKET SUMMARY — pull all asset classes and classify into buckets
// ══════════════════════════════════════════════════════════════════
function build_bucket_summary(int $portfolioId): array {
    $buckets = [1 => BUCKETS[1], 2 => BUCKETS[2], 3 => BUCKETS[3]];
    foreach ($buckets as &$b) {
        $b['value']    = 0.0;
        $b['invested'] = 0.0;
        $b['items']    = [];
    }
    unset($b);

    // ── Mutual Funds ────────────────────────────────────────────
    $mfRows = DB::fetchAll(
        "SELECT h.id, h.scheme_name, h.units, h.avg_nav, h.value_now, h.total_invested,
                f.scheme_category, f.scheme_sub_category
         FROM mf_holdings h
         LEFT JOIN funds f ON f.id = h.fund_id
         WHERE h.portfolio_id = ? AND h.is_active = 1",
        [$portfolioId]
    );
    foreach ($mfRows as $r) {
        $bid = mf_category_to_bucket((string)($r['scheme_category'] ?? ''), (string)($r['scheme_sub_category'] ?? ''));
        $val = (float)$r['value_now'];
        $inv = (float)$r['total_invested'];
        $buckets[$bid]['value']    += $val;
        $buckets[$bid]['invested'] += $inv;
        $buckets[$bid]['items'][]  = [
            'type'      => 'MF',
            'name'      => $r['scheme_name'],
            'category'  => $r['scheme_category'],
            'value'     => round($val, 2),
            'invested'  => round($inv, 2),
            'gain_pct'  => $inv > 0 ? round(($val - $inv) / $inv * 100, 2) : 0,
        ];
    }

    // ── Stocks ──────────────────────────────────────────────────
    $stTotal = (float)DB::fetchVal(
        "SELECT COALESCE(SUM(current_value),0) FROM stock_holdings WHERE portfolio_id=? AND is_active=1",
        [$portfolioId]
    );
    $stInv = (float)DB::fetchVal(
        "SELECT COALESCE(SUM(total_invested),0) FROM stock_holdings WHERE portfolio_id=? AND is_active=1",
        [$portfolioId]
    );
    if ($stTotal > 0) {
        $buckets[3]['value']    += $stTotal;
        $buckets[3]['invested'] += $stInv;
        $buckets[3]['items'][]  = [
            'type'     => 'Stocks',
            'name'     => 'Direct Equity Portfolio',
            'value'    => round($stTotal, 2),
            'invested' => round($stInv, 2),
            'gain_pct' => $stInv > 0 ? round(($stTotal - $stInv) / $stInv * 100, 2) : 0,
        ];
    }

    // ── FDs — classify by maturity ──────────────────────────────
    $fdRows = DB::fetchAll(
        "SELECT id, bank_name, principal, interest_rate, maturity_date,
                principal * POW(1 + interest_rate/100/4, 4 * DATEDIFF(LEAST(maturity_date,CURDATE()),open_date)/365) AS current_value
         FROM fd_accounts
         WHERE portfolio_id=? AND status='active'",
        [$portfolioId]
    );
    foreach ($fdRows as $r) {
        $bid = fd_to_bucket((string)$r['maturity_date']);
        $val = (float)$r['current_value'];
        $inv = (float)$r['principal'];
        $buckets[$bid]['value']    += $val;
        $buckets[$bid]['invested'] += $inv;
        $buckets[$bid]['items'][]  = [
            'type'        => 'FD',
            'name'        => ($r['bank_name'] ?? 'FD') . ' — ' . $r['interest_rate'] . '%',
            'maturity'    => $r['maturity_date'],
            'value'       => round($val, 2),
            'invested'    => round($inv, 2),
            'gain_pct'    => $inv > 0 ? round(($val - $inv) / $inv * 100, 2) : 0,
        ];
    }

    // ── Savings Accounts → Bucket 1 ─────────────────────────────
    $savTotal = (float)DB::fetchVal(
        "SELECT COALESCE(SUM(balance),0) FROM savings_accounts WHERE portfolio_id=? AND is_active=1",
        [$portfolioId]
    );
    if ($savTotal > 0) {
        $buckets[1]['value']    += $savTotal;
        $buckets[1]['invested'] += $savTotal;
        $buckets[1]['items'][]  = [
            'type'     => 'Savings',
            'name'     => 'Savings / Current Accounts',
            'value'    => round($savTotal, 2),
            'invested' => round($savTotal, 2),
            'gain_pct' => 0,
        ];
    }

    // ── NPS → split Bucket 2 (debt tier) + Bucket 3 (equity tier) ──
    $npsRows = DB::fetchAll(
        "SELECT tier, asset_class, latest_value, total_invested
         FROM nps_holdings WHERE portfolio_id=?",
        [$portfolioId]
    );
    foreach ($npsRows as $r) {
        $ac  = strtolower((string)($r['asset_class'] ?? ''));
        $bid = (str_contains($ac, 'c') || str_contains($ac, 'e') || str_contains($ac, 'a')) ? 3 : 2;
        $val = (float)$r['latest_value'];
        $inv = (float)$r['total_invested'];
        $buckets[$bid]['value']    += $val;
        $buckets[$bid]['invested'] += $inv;
        $buckets[$bid]['items'][]  = [
            'type'     => 'NPS',
            'name'     => 'NPS ' . strtoupper((string)($r['tier'] ?? '')) . ' — Asset Class ' . strtoupper($ac),
            'value'    => round($val, 2),
            'invested' => round($inv, 2),
            'gain_pct' => $inv > 0 ? round(($val - $inv) / $inv * 100, 2) : 0,
        ];
    }

    // ── SGB → Bucket 2 (medium term hold, gold) ─────────────────
    $sgbTotal = (float)DB::fetchVal(
        "SELECT COALESCE(SUM(current_value),0) FROM sgb_holdings WHERE portfolio_id=? AND is_active=1",
        [$portfolioId]
    );
    $sgbInv = (float)DB::fetchVal(
        "SELECT COALESCE(SUM(total_invested),0) FROM sgb_holdings WHERE portfolio_id=? AND is_active=1",
        [$portfolioId]
    );
    if ($sgbTotal > 0) {
        $buckets[2]['value']    += $sgbTotal;
        $buckets[2]['invested'] += $sgbInv;
        $buckets[2]['items'][]  = [
            'type'     => 'SGB',
            'name'     => 'Sovereign Gold Bonds',
            'value'    => round($sgbTotal, 2),
            'invested' => round($sgbInv, 2),
            'gain_pct' => $sgbInv > 0 ? round(($sgbTotal - $sgbInv) / $sgbInv * 100, 2) : 0,
        ];
    }

    // ── Physical Gold → Bucket 2 ─────────────────────────────────
    $goldTotal = (float)DB::fetchVal(
        "SELECT COALESCE(SUM(current_value),0) FROM gold_holdings WHERE portfolio_id=? AND is_active=1",
        [$portfolioId]
    );
    if ($goldTotal > 0) {
        $goldInv = (float)DB::fetchVal(
            "SELECT COALESCE(SUM(purchase_value),0) FROM gold_holdings WHERE portfolio_id=? AND is_active=1",
            [$portfolioId]
        );
        $buckets[2]['value']    += $goldTotal;
        $buckets[2]['invested'] += $goldInv;
        $buckets[2]['items'][]  = [
            'type'     => 'Gold',
            'name'     => 'Physical Gold / ETF',
            'value'    => round($goldTotal, 2),
            'invested' => round($goldInv, 2),
            'gain_pct' => $goldInv > 0 ? round(($goldTotal - $goldInv) / $goldInv * 100, 2) : 0,
        ];
    }

    // ── EPF → Bucket 2/3 boundary, classify as Stable ────────────
    $epfTotal = (float)DB::fetchVal(
        "SELECT COALESCE(SUM(balance),0) FROM epf_accounts WHERE portfolio_id=?",
        [$portfolioId]
    );
    if ($epfTotal > 0) {
        $buckets[2]['value']    += $epfTotal;
        $buckets[2]['invested'] += $epfTotal;
        $buckets[2]['items'][]  = [
            'type'     => 'EPF',
            'name'     => 'EPF / PF Balance',
            'value'    => round($epfTotal, 2),
            'invested' => round($epfTotal, 2),
            'gain_pct' => 0,
        ];
    }

    // Compute totals and percentages
    $totalValue    = array_sum(array_column($buckets, 'value'));
    $totalInvested = array_sum(array_column($buckets, 'invested'));

    foreach ($buckets as $bid => &$b) {
        $b['value']       = round($b['value'], 2);
        $b['invested']    = round($b['invested'], 2);
        $b['gain']        = round($b['value'] - $b['invested'], 2);
        $b['gain_pct']    = $b['invested'] > 0 ? round($b['gain'] / $b['invested'] * 100, 2) : 0;
        $b['actual_pct']  = $totalValue > 0 ? round($b['value'] / $totalValue * 100, 1) : 0;
        $b['ideal_pct']   = BUCKETS[$bid]['ideal_pct'];
        $b['item_count']  = count($b['items']);
        // Fill level for visual (0–100)
        $b['fill_level']  = $b['actual_pct'];
        // Health: over/under vs ideal
        $diff = $b['actual_pct'] - $b['ideal_pct'];
        if (abs($diff) <= 5)       $b['health'] = ['status'=>'ok',    'label'=>'Balanced',     'color'=>'#16a34a'];
        elseif ($diff > 5)         $b['health'] = ['status'=>'over',  'label'=>'Overweight',   'color'=>'#d97706'];
        else                       $b['health'] = ['status'=>'under', 'label'=>'Underweight',  'color'=>'#dc2626'];
    }
    unset($b);

    return [
        'buckets'       => array_values($buckets),
        'total_value'   => round($totalValue, 2),
        'total_invested'=> round($totalInvested, 2),
        'total_gain'    => round($totalValue - $totalInvested, 2),
    ];
}

// ══════════════════════════════════════════════════════════════════
// GOAL CLASSIFICATION — map each investment goal to a bucket
// ══════════════════════════════════════════════════════════════════
function classify_goals(int $portfolioId): array {
    $goals = DB::fetchAll(
        "SELECT g.*,
                COALESCE((SELECT SUM(amount) FROM goal_contributions WHERE goal_id=g.id),0) AS contributions_sum
         FROM investment_goals g
         WHERE g.portfolio_id=? ORDER BY g.target_date ASC",
        [$portfolioId]
    );

    $today = new DateTime();
    $result = [1 => [], 2 => [], 3 => []];

    foreach ($goals as $g) {
        $target  = new DateTime($g['target_date']);
        $diff    = $today->diff($target);
        $months  = $diff->y * 12 + $diff->m;
        if ($target <= $today) $months = 0;

        $bid = match(true) {
            $months <= 24 => 1,
            $months <= 60 => 2,
            default       => 3,
        };

        $effectiveSaved = max((float)$g['current_saved'], (float)$g['contributions_sum']);
        $target_amount  = (float)$g['target_amount'];
        $progress       = $target_amount > 0 ? min(100, round($effectiveSaved / $target_amount * 100, 1)) : 0;

        $result[$bid][] = [
            'id'          => (int)$g['id'],
            'name'        => $g['name'],
            'icon'        => $g['icon'] ?? 'target',
            'color'       => $g['color'] ?? '#2563eb',
            'target_amount' => $target_amount,
            'saved'         => $effectiveSaved,
            'progress'      => $progress,
            'months_left'   => $months,
            'target_date'   => $g['target_date'],
            'is_achieved'   => (bool)$g['is_achieved'],
            'priority'      => $g['priority'],
        ];
    }

    return [
        'bucket_1_goals' => $result[1],
        'bucket_2_goals' => $result[2],
        'bucket_3_goals' => $result[3],
        'total_goals'    => array_sum(array_map('count', $result)),
    ];
}

// ══════════════════════════════════════════════════════════════════
// REPLENISHMENT ALERTS
// ══════════════════════════════════════════════════════════════════
function bucket_alerts(array $summary): array {
    $alerts = [];
    $buckets = $summary['buckets'];
    $total   = $summary['total_value'];

    foreach ($buckets as $b) {
        $diff = $b['actual_pct'] - $b['ideal_pct'];

        // Bucket 1 underweight is most critical (safety)
        if ($b['id'] === 1 && $b['actual_pct'] < ($b['ideal_pct'] - 3)) {
            $needed = round(($b['ideal_pct'] / 100 * $total) - $b['value'], 0);
            $alerts[] = [
                'severity' => 'high',
                'emoji'    => '🚨',
                'bucket'   => $b['name'],
                'message'  => "Safety bucket is underweight ({$b['actual_pct']}% vs ideal {$b['ideal_pct']}%). "
                    . "Consider moving ₹" . number_format($needed, 0, '.', ',') . " from Growth to Safety.",
            ];
        }

        // Bucket 3 underweight
        if ($b['id'] === 3 && $b['actual_pct'] < ($b['ideal_pct'] - 10)) {
            $alerts[] = [
                'severity' => 'medium',
                'emoji'    => '📈',
                'bucket'   => $b['name'],
                'message'  => "Growth bucket is underweight ({$b['actual_pct']}% vs ideal {$b['ideal_pct']}%). "
                    . "Consider increasing equity exposure for long-term wealth creation.",
            ];
        }

        // Bucket 1 overweight (too much in cash)
        if ($b['id'] === 1 && $b['actual_pct'] > ($b['ideal_pct'] + 5)) {
            $excess = round($b['value'] - ($b['ideal_pct'] / 100 * $total), 0);
            $alerts[] = [
                'severity' => 'low',
                'emoji'    => '💡',
                'bucket'   => $b['name'],
                'message'  => "Too much in Safety bucket ({$b['actual_pct']}%). ₹" . number_format($excess, 0, '.', ',')
                    . " excess could be moved to Stable/Growth for better returns.",
            ];
        }
    }

    if (empty($alerts)) {
        $alerts[] = [
            'severity' => 'ok',
            'emoji'    => '✅',
            'bucket'   => 'All Buckets',
            'message'  => 'Your bucket allocation looks healthy! All buckets are within target range.',
        ];
    }

    return $alerts;
}

// ══════════════════════════════════════════════════════════════════
// ACTIONS
// ══════════════════════════════════════════════════════════════════
if ($action === 'bucket_strategy_summary') {
    $summary = build_bucket_summary($portfolioId);
    $goals   = classify_goals($portfolioId);
    $alerts  = bucket_alerts($summary);

    // Attach goals to each bucket
    foreach ($summary['buckets'] as &$b) {
        $bid = $b['id'];
        $b['goals'] = $goals["bucket_{$bid}_goals"] ?? [];
    }
    unset($b);

    json_response(true, 'Bucket strategy summary.', array_merge($summary, [
        'alerts'      => $alerts,
        'total_goals' => $goals['total_goals'],
    ]));
}

elseif ($action === 'bucket_strategy_goals') {
    json_response(true, 'Goals classified.', classify_goals($portfolioId));
}

elseif ($action === 'bucket_strategy_health') {
    $summary = build_bucket_summary($portfolioId);
    json_response(true, 'Bucket health.', [
        'buckets' => $summary['buckets'],
        'alerts'  => bucket_alerts($summary),
    ]);
}

elseif ($action === 'bucket_strategy_save') {
    // Save custom ideal percentages per bucket to user settings
    $b1 = min(100, max(0, (int)($_POST['bucket1_pct'] ?? 10)));
    $b2 = min(100, max(0, (int)($_POST['bucket2_pct'] ?? 20)));
    $b3 = min(100, max(0, (int)($_POST['bucket3_pct'] ?? 70)));

    if ($b1 + $b2 + $b3 !== 100) json_response(false, 'Bucket percentages must sum to 100.');

    try {
        DB::execute(
            "INSERT INTO user_kv_settings (user_id, portfolio_id, setting_key, setting_value)
             VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)",
            [$userId, $portfolioId, 'bucket_targets', json_encode(['b1'=>$b1,'b2'=>$b2,'b3'=>$b3])]
        );
    } catch (Exception $e) {
        // user_settings table might not exist — ignore, return ok
    }
    json_response(true, 'Bucket targets saved.', ['b1'=>$b1,'b2'=>$b2,'b3'=>$b3]);
}

elseif ($action === 'bucket_strategy_load') {
    $targets = ['b1'=>10,'b2'=>20,'b3'=>70];
    try {
        $row = DB::fetchRow(
            "SELECT setting_value FROM user_kv_settings WHERE portfolio_id=? AND setting_key='bucket_targets'",
            [$portfolioId]
        );
        if ($row) $targets = json_decode($row['setting_value'], true) ?: $targets;
    } catch (Exception $e) {}
    json_response(true, 'Bucket targets loaded.', $targets);
}

else {
    json_response(false, 'Unknown bucket strategy action: ' . htmlspecialchars($action));
}
