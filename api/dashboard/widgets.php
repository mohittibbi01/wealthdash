<?php
/**
 * WealthDash — Dashboard Widgets API
 * t254: FY Summary Card (current FY P&L at a glance)
 * t291: Goal Progress Rings (SVG circular progress)
 * t295: Net Worth Trend (monthly history)
 * t400: Wealth Milestone Tracker
 * Actions: fy_summary_card | goal_rings | networth_trend | milestone_status
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'fy_summary_card';
$db          = DB::conn();

// ── FY helpers ────────────────────────────────────────────────────
function currentFyDates(): array {
    $m = (int)date('n'); $y = (int)date('Y');
    $fyStart = ($m >= 4 ? $y : $y - 1);
    return [
        'fy'    => $fyStart . '-' . substr($fyStart + 1, 2),
        'from'  => $fyStart . '-04-01',
        'to'    => ($fyStart + 1) . '-03-31',
        'label' => 'FY ' . $fyStart . '-' . ($fyStart + 1),
    ];
}

switch ($action) {

// ══════════════════════════════════════════════════════════════════════
// t254: fy_summary_card
// ══════════════════════════════════════════════════════════════════════
case 'fy_summary_card':
    $pid = (int)($_POST['portfolio_id'] ?? 0);
    if (!$pid) {
        $r = $db->prepare("SELECT id FROM portfolios WHERE user_id=? AND is_default=1 LIMIT 1");
        $r->execute([$userId]);
        $pid = (int)($r->fetchColumn() ?: 0);
    }

    $fy = currentFyDates();

    // MF LTCG + STCG from sell transactions this FY
    $gainStmt = $db->prepare("
        SELECT
          COALESCE(SUM(CASE
            WHEN DATEDIFF(t.tx_date,
              (SELECT MIN(b.tx_date) FROM mf_transactions b
               WHERE b.holding_id=t.holding_id
                 AND b.tx_type IN ('buy','sip','lumpsum','switch_in')
                 AND b.tx_date <= t.tx_date)) > 365
            THEN (t.price_per_unit - COALESCE(
              (SELECT b2.price_per_unit FROM mf_transactions b2
               WHERE b2.holding_id=t.holding_id
                 AND b2.tx_type IN ('buy','sip','lumpsum','switch_in')
                 AND b2.tx_date <= t.tx_date
               ORDER BY b2.tx_date ASC LIMIT 1),0)) * t.units
            ELSE 0 END), 0) AS ltcg_equity,
          COALESCE(SUM(CASE
            WHEN DATEDIFF(t.tx_date,
              (SELECT MIN(b.tx_date) FROM mf_transactions b
               WHERE b.holding_id=t.holding_id
                 AND b.tx_type IN ('buy','sip','lumpsum','switch_in')
                 AND b.tx_date <= t.tx_date)) <= 365
            THEN (t.price_per_unit - COALESCE(
              (SELECT b2.price_per_unit FROM mf_transactions b2
               WHERE b2.holding_id=t.holding_id
                 AND b2.tx_type IN ('buy','sip','lumpsum','switch_in')
                 AND b2.tx_date <= t.tx_date
               ORDER BY b2.tx_date ASC LIMIT 1),0)) * t.units
            ELSE 0 END), 0) AS stcg_equity,
          COALESCE(SUM(t.amount), 0) AS total_redeemed
        FROM mf_transactions t
        JOIN mf_holdings mh ON mh.id = t.holding_id
        JOIN portfolios p   ON p.id  = mh.portfolio_id
        WHERE p.user_id = ?
          AND t.tx_type IN ('sell','swp','switch_out','redemption')
          AND t.tx_date BETWEEN ? AND ?
    ");
    $gainStmt->execute([$userId, $fy['from'], $fy['to']]);
    $gains = $gainStmt->fetch(PDO::FETCH_ASSOC);

    // Invested this FY
    $invStmt = $db->prepare("
        SELECT COALESCE(SUM(t.amount),0) AS invested_this_fy
        FROM mf_transactions t
        JOIN mf_holdings mh ON mh.id = t.holding_id
        JOIN portfolios p ON p.id = mh.portfolio_id
        WHERE p.user_id=? AND t.tx_type IN ('buy','sip','lumpsum','switch_in')
          AND t.tx_date BETWEEN ? AND ?
    ");
    $invStmt->execute([$userId, $fy['from'], $fy['to']]);
    $invested = (float)$invStmt->fetchColumn();

    // SIP count this FY
    $sipStmt = $db->prepare("
        SELECT COUNT(*) FROM mf_transactions t
        JOIN mf_holdings mh ON mh.id=t.holding_id
        JOIN portfolios p ON p.id=mh.portfolio_id
        WHERE p.user_id=? AND t.tx_type='sip' AND t.tx_date BETWEEN ? AND ?
    ");
    $sipStmt->execute([$userId, $fy['from'], $fy['to']]);
    $sipCount = (int)$sipStmt->fetchColumn();

    // LTCG tax (12.5% above ₹1.25L exemption from Jul 2024)
    $ltcg = (float)($gains['ltcg_equity'] ?? 0);
    $stcg = (float)($gains['stcg_equity'] ?? 0);
    $ltcgTaxable = max(0, $ltcg - 125000);
    $ltcgTax = $ltcgTaxable * 0.125;
    $stcgTax = max(0, $stcg) * 0.20;
    $totalTax = ($ltcgTax + $stcgTax) * 1.04; // +4% cess

    echo json_encode([
        'success' => true,
        'data'    => [
            'fy'               => $fy['label'],
            'invested_this_fy' => round($invested),
            'ltcg_equity'      => round($ltcg),
            'stcg_equity'      => round($stcg),
            'total_redeemed'   => round((float)($gains['total_redeemed'] ?? 0)),
            'ltcg_exemption'   => 125000,
            'ltcg_taxable'     => round($ltcgTaxable),
            'estimated_tax'    => round($totalTax),
            'sip_count'        => $sipCount,
            'ltcg_used_pct'    => min(100, round($ltcg / 125000 * 100)),
        ]
    ]);
    break;

// ══════════════════════════════════════════════════════════════════════
// t291: goal_rings — circular progress for each goal
// ══════════════════════════════════════════════════════════════════════
case 'goal_rings':
    $stmt = $db->prepare("
        SELECT
          g.id, g.goal_name, g.target_amount, g.target_date,
          g.current_amount, g.monthly_sip, g.priority, g.status, g.icon,
          COALESCE(
            (SELECT SUM(mh.latest_value)
             FROM mf_holdings mh
             JOIN portfolios p ON p.id=mh.portfolio_id
             WHERE p.user_id=g.user_id
               AND mh.goal_id=g.id),
            g.current_amount, 0
          ) AS linked_value
        FROM goals g
        WHERE g.user_id = ? AND g.status = 'active'
        ORDER BY g.priority, g.target_date
        LIMIT 6
    ");
    $stmt->execute([$userId]);
    $goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($goals as $g) {
        $target  = (float)$g['target_amount'];
        $current = max((float)$g['linked_value'], (float)$g['current_amount']);
        $pct     = $target > 0 ? min(100, round($current / $target * 100, 1)) : 0;

        // Months remaining
        $monthsLeft = 0;
        if ($g['target_date']) {
            $monthsLeft = max(0, (int)((strtotime($g['target_date']) - time()) / 86400 / 30.5));
        }

        // Required monthly SIP to reach goal
        $requiredSip = 0;
        if ($monthsLeft > 0 && $target > $current) {
            $gap = $target - $current;
            $r   = 0.12 / 12; // assume 12% annual return
            $requiredSip = $gap * $r / (pow(1 + $r, $monthsLeft) - 1);
        }

        // SVG circle params (r=40, circumference=251.3)
        $circumference = 2 * M_PI * 40;
        $dashOffset    = $circumference * (1 - $pct / 100);
        $color = match(true) {
            $pct >= 100 => '#f59e0b',   // gold — achieved
            $pct >= 70  => '#16a34a',   // green
            $pct >= 30  => '#3b82f6',   // blue
            default     => '#ef4444',   // red
        };

        $result[] = [
            'id'             => $g['id'],
            'goal_name'      => $g['goal_name'],
            'target_amount'  => round($target),
            'current_amount' => round($current),
            'pct'            => $pct,
            'monthly_sip'    => round((float)$g['monthly_sip']),
            'required_sip'   => round($requiredSip),
            'months_left'    => $monthsLeft,
            'target_date'    => $g['target_date'],
            'priority'       => $g['priority'],
            'icon'           => $g['icon'] ?: '🎯',
            'svg_dash_offset'  => round($dashOffset, 2),
            'svg_circumference'=> round($circumference, 2),
            'color'          => $color,
        ];
    }
    echo json_encode(['success' => true, 'data' => $result]);
    break;

// ══════════════════════════════════════════════════════════════════════
// t295: networth_trend — monthly net worth history
// ══════════════════════════════════════════════════════════════════════
case 'networth_trend':
    // Use net_worth_timeline table if it exists
    $months = (int)($_POST['months'] ?? 12);
    $months = max(3, min(36, $months));

    try {
        $stmt = $db->prepare("
            SELECT
              DATE_FORMAT(recorded_at, '%Y-%m') AS month,
              MAX(total_value) AS net_worth
            FROM net_worth_timeline
            WHERE user_id = ?
              AND recorded_at >= DATE_SUB(NOW(), INTERVAL ? MONTH)
            GROUP BY DATE_FORMAT(recorded_at, '%Y-%m')
            ORDER BY month
        ");
        $stmt->execute([$userId, $months]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $rows = [];
    }

    // If no history, build approximate from current holdings
    if (count($rows) < 2) {
        // Current value
        $curStmt = $db->prepare("
            SELECT COALESCE(SUM(mh.latest_value),0) AS mf_val
            FROM mf_holdings mh
            JOIN portfolios p ON p.id=mh.portfolio_id
            WHERE p.user_id=?
        ");
        $curStmt->execute([$userId]);
        $curMf = (float)$curStmt->fetchColumn();

        // Simulate monthly values using 12% CAGR backwards
        $rows = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $factor = pow(1.01, $i); // 1% monthly growth
            $rows[] = [
                'month'     => date('Y-m', strtotime("-{$i} months")),
                'net_worth' => round($curMf / $factor),
                'simulated' => true,
            ];
        }
    }

    // Month-over-month change
    foreach ($rows as $idx => &$row) {
        $prev = $idx > 0 ? (float)$rows[$idx - 1]['net_worth'] : 0;
        $row['mom_change'] = $prev > 0 ? round(((float)$row['net_worth'] - $prev) / $prev * 100, 2) : 0;
        $row['label'] = date('M y', strtotime($row['month'] . '-01'));
    }

    $vals   = array_column($rows, 'net_worth');
    $latest = end($vals) ?: 0;
    $oldest = reset($vals) ?: 0;
    $growth = $oldest > 0 ? round(($latest - $oldest) / $oldest * 100, 1) : 0;

    echo json_encode([
        'success' => true,
        'data'    => [
            'trend'        => $rows,
            'current'      => round($latest),
            'oldest'       => round($oldest),
            'growth_pct'   => $growth,
            'period_months'=> $months,
            'is_simulated' => !empty($rows[0]['simulated']),
        ]
    ]);
    break;

// ══════════════════════════════════════════════════════════════════════
// t400: milestone_status
// ══════════════════════════════════════════════════════════════════════
case 'milestone_status':
    // Get total net worth
    $nwStmt = $db->prepare("
        SELECT
          COALESCE((SELECT SUM(mh.latest_value) FROM mf_holdings mh
            JOIN portfolios p ON p.id=mh.portfolio_id WHERE p.user_id=?),0)
          + COALESCE((SELECT SUM(latest_value) FROM nps_holdings nh
            JOIN portfolios p ON p.id=nh.portfolio_id WHERE p.user_id=?),0)
          + COALESCE((SELECT SUM(maturity_value) FROM fd_holdings WHERE user_id=? AND status='active'),0)
          + COALESCE((SELECT SUM(current_value) FROM savings_accounts WHERE user_id=?),0)
          AS total_nw
    ");
    $nwStmt->execute([$userId, $userId, $userId, $userId]);
    $netWorth = (float)$nwStmt->fetchColumn();

    $milestones = [
        ['value'=>100000,    'label'=>'₹1 Lakh',   'emoji'=>'🌱'],
        ['value'=>500000,    'label'=>'₹5 Lakh',   'emoji'=>'🌿'],
        ['value'=>1000000,   'label'=>'₹10 Lakh',  'emoji'=>'🌳'],
        ['value'=>2500000,   'label'=>'₹25 Lakh',  'emoji'=>'⭐'],
        ['value'=>5000000,   'label'=>'₹50 Lakh',  'emoji'=>'🌟'],
        ['value'=>10000000,  'label'=>'₹1 Crore',  'emoji'=>'🏆'],
        ['value'=>50000000,  'label'=>'₹5 Crore',  'emoji'=>'💎'],
        ['value'=>100000000, 'label'=>'₹10 Crore', 'emoji'=>'🚀'],
    ];

    $achieved = array_filter($milestones, fn($m) => $netWorth >= $m['value']);
    $next     = array_values(array_filter($milestones, fn($m) => $netWorth < $m['value']));
    $last     = $achieved ? end($achieved) : null;

    $nextPct = 0;
    if ($next && $last) {
        $nextPct = round(($netWorth - $last['value']) / ($next[0]['value'] - $last['value']) * 100, 1);
    } elseif ($next) {
        $nextPct = round($netWorth / $next[0]['value'] * 100, 1);
    }

    echo json_encode([
        'success'        => true,
        'data'           => [
            'net_worth'      => round($netWorth),
            'last_achieved'  => $last,
            'next_milestone' => $next[0] ?? null,
            'next_pct'       => $nextPct,
            'achieved_count' => count($achieved),
            'all_milestones' => array_map(fn($m) => array_merge($m, [
                'achieved' => $netWorth >= $m['value'],
                'pct_done' => $netWorth >= $m['value'] ? 100 : round($netWorth/$m['value']*100,1),
            ]), $milestones),
        ]
    ]);
    break;

default:
    echo json_encode(['success' => false, 'error' => "Unknown action: $action"]);
}
