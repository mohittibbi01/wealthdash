<?php
/**
 * WealthDash — t396: Net Worth Projection (5/10/20 yr)
 * t139 / t355: Goal-Based Buckets — Holdings ko goal se tag karo
 * t295: Net Worth Trend — Month-on-month chart
 * Actions: networth_projection | networth_trend | goal_buckets_list | goal_bucket_assign | goal_bucket_progress
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'networth_projection';

// Ensure goal tables
try {
    DB::conn()->exec("
        CREATE TABLE IF NOT EXISTS goals (
            id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id          INT UNSIGNED NOT NULL,
            goal_name        VARCHAR(120) NOT NULL,
            goal_type        VARCHAR(50)  DEFAULT 'custom',
            target_amount    DECIMAL(15,2) NOT NULL,
            current_amount   DECIMAL(15,2) NOT NULL DEFAULT 0,
            monthly_sip      DECIMAL(10,2) DEFAULT 0,
            target_date      DATE NOT NULL,
            expected_return  DECIMAL(5,2)  DEFAULT 12.00,
            status           ENUM('active','completed','paused') DEFAULT 'active',
            notes            TEXT,
            created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS goal_holdings (
            id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            goal_id     INT UNSIGNED NOT NULL,
            user_id     INT UNSIGNED NOT NULL,
            asset_type  ENUM('mf','fd','nps','stock','crypto','other') NOT NULL,
            asset_id    INT UNSIGNED NOT NULL,
            allocated_pct DECIMAL(5,2) DEFAULT 100.00,
            notes       VARCHAR(200),
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_goal_asset (goal_id, asset_type, asset_id),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS networth_snapshots (
            id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id       INT UNSIGNED NOT NULL,
            snapshot_date DATE NOT NULL,
            mf_value      DECIMAL(15,2) DEFAULT 0,
            fd_value      DECIMAL(15,2) DEFAULT 0,
            nps_value     DECIMAL(15,2) DEFAULT 0,
            stock_value   DECIMAL(15,2) DEFAULT 0,
            crypto_value  DECIMAL(15,2) DEFAULT 0,
            other_value   DECIMAL(15,2) DEFAULT 0,
            total_value   DECIMAL(15,2) NOT NULL,
            UNIQUE KEY uk_user_date (user_id, snapshot_date),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {}

// ── Current net worth ──────────────────────────────────────────
function getCurrentNetWorth(int $userId): array {
    try {
        $mf = (float)(DB::fetchVal(
            "SELECT COALESCE(SUM(mh.current_value),0) FROM mf_holdings mh
             JOIN portfolios p ON p.id=mh.portfolio_id
             WHERE p.user_id=? AND mh.is_active=1", [$userId]) ?? 0);

        $fd = (float)(DB::fetchVal(
            "SELECT COALESCE(SUM(maturity_amount),0) FROM fd_investments
             WHERE user_id=? AND status='active'", [$userId]) ?? 0);

        $nps = (float)(DB::fetchVal(
            "SELECT COALESCE(SUM(current_value),0) FROM nps_accounts
             WHERE user_id=? AND is_active=1", [$userId]) ?? 0);

        $stocks = (float)(DB::fetchVal(
            "SELECT COALESCE(SUM(current_value),0) FROM stock_holdings
             WHERE user_id=? AND is_active=1", [$userId]) ?? 0);

        $crypto = (float)(DB::fetchVal(
            "SELECT COALESCE(SUM(current_value_inr),0) FROM crypto_holdings
             WHERE user_id=? AND is_active=1", [$userId]) ?? 0);

        return [
            'mf'     => $mf,
            'fd'     => $fd,
            'nps'    => $nps,
            'stocks' => $stocks,
            'crypto' => $crypto,
            'total'  => $mf + $fd + $nps + $stocks + $crypto,
        ];
    } catch (Exception $e) {
        return ['mf'=>0,'fd'=>0,'nps'=>0,'stocks'=>0,'crypto'=>0,'total'=>0];
    }
}

// ══════════════════════════════════════════════════════════════
switch ($action) {

    // ── NET WORTH PROJECTION ───────────────────────────────────
    case 'networth_projection':
        $current   = getCurrentNetWorth($userId);
        $totalNow  = $current['total'];

        // Active SIP amount
        $monthlySip = (float)(DB::fetchVal(
            "SELECT COALESCE(SUM(s.amount),0) FROM sip_swp s
             WHERE s.user_id=? AND s.type='SIP' AND s.status='active'", [$userId]) ?? 0);

        // Projection parameters
        $scenarios = [
            'conservative' => ['return' => 0.08, 'label' => 'Conservative (8% p.a.)'],
            'moderate'     => ['return' => 0.12, 'label' => 'Moderate (12% p.a.)'],
            'aggressive'   => ['return' => 0.15, 'label' => 'Aggressive (15% p.a.)'],
        ];

        $years    = [1, 3, 5, 10, 15, 20, 25, 30];
        $projections = [];

        foreach ($scenarios as $key => $scenario) {
            $r      = $scenario['return'];
            $monthly = $r / 12;
            $data    = [];

            foreach ($years as $yr) {
                $months = $yr * 12;
                // FV of current corpus
                $fvCorpus = $totalNow * pow(1 + $r, $yr);
                // FV of monthly SIP (annuity)
                $fvSip = $monthlySip > 0
                    ? $monthlySip * ((pow(1 + $monthly, $months) - 1) / $monthly) * (1 + $monthly)
                    : 0;
                $total = $fvCorpus + $fvSip;

                $data[] = [
                    'year'     => $yr,
                    'year_label' => date('Y') + $yr,
                    'corpus'   => round($fvCorpus),
                    'sip_fv'   => round($fvSip),
                    'total'    => round($total),
                    'in_crore' => round($total / 10000000, 2),
                ];
            }

            $projections[$key] = array_merge($scenario, ['data' => $data]);
        }

        // Save snapshot for today
        try {
            DB::run(
                "INSERT INTO networth_snapshots (user_id, snapshot_date, mf_value, fd_value, nps_value, stock_value, crypto_value, total_value)
                 VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE mf_value=VALUES(mf_value), fd_value=VALUES(fd_value),
                   nps_value=VALUES(nps_value), stock_value=VALUES(stock_value),
                   crypto_value=VALUES(crypto_value), total_value=VALUES(total_value)",
                [$userId, $current['mf'], $current['fd'], $current['nps'], $current['stocks'], $current['crypto'], $current['total']]
            );
        } catch (Exception $e) {}

        json_response(true, '', [
            'current_networth' => $current,
            'monthly_sip'      => $monthlySip,
            'projections'      => $projections,
            'note'             => 'Returns compounded annually. SIP assumed monthly. Inflation not adjusted.',
        ]);

    // ── NET WORTH TREND ────────────────────────────────────────
    case 'networth_trend':
        $months = min((int)($_GET['months'] ?? 12), 60);

        $snapshots = DB::fetchAll(
            "SELECT snapshot_date, mf_value, fd_value, nps_value, stock_value, crypto_value, total_value
             FROM networth_snapshots
             WHERE user_id = ?
               AND snapshot_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
             ORDER BY snapshot_date ASC",
            [$userId, $months]
        );

        // Calculate month-on-month change
        $trend = [];
        for ($i = 0; $i < count($snapshots); $i++) {
            $s = $snapshots[$i];
            $prev = $i > 0 ? (float)$snapshots[$i-1]['total_value'] : null;
            $curr = (float)$s['total_value'];
            $trend[] = array_merge($s, [
                'change_abs' => $prev !== null ? round($curr - $prev, 2) : null,
                'change_pct' => $prev && $prev > 0 ? round(($curr - $prev)/$prev*100, 2) : null,
            ]);
        }

        // Overall growth
        $first = count($snapshots) > 0 ? (float)$snapshots[0]['total_value'] : 0;
        $last  = count($snapshots) > 0 ? (float)end($snapshots)['total_value'] : 0;

        json_response(true, '', [
            'snapshots'    => $trend,
            'period_start' => $first,
            'period_end'   => $last,
            'total_growth' => round($last - $first, 2),
            'growth_pct'   => $first > 0 ? round(($last - $first)/$first*100, 2) : 0,
        ]);

    // ── GOAL BUCKETS LIST ──────────────────────────────────────
    case 'goal_buckets_list':
        $goals = DB::fetchAll(
            "SELECT g.*,
                    ROUND(g.current_amount / g.target_amount * 100, 1) AS progress_pct,
                    DATEDIFF(g.target_date, CURDATE()) AS days_left,
                    (SELECT COUNT(*) FROM goal_holdings gh WHERE gh.goal_id = g.id) AS linked_assets
             FROM goals g
             WHERE g.user_id = ? AND g.status != 'completed'
             ORDER BY g.target_date ASC",
            [$userId]
        );

        foreach ($goals as &$g) {
            // Calculate required monthly SIP to reach goal
            $months   = max(1, (int)$g['days_left'] / 30);
            $r        = (float)$g['expected_return'] / 100 / 12;
            $remaining = (float)$g['target_amount'] - (float)$g['current_amount'];

            if ($r > 0 && $months > 0 && $remaining > 0) {
                // PMT formula
                $pmt = $remaining * $r / (pow(1 + $r, $months) - 1);
                $g['required_monthly_sip'] = round($pmt, 0);
            } else {
                $g['required_monthly_sip'] = $months > 0 ? round($remaining / $months, 0) : 0;
            }

            $g['on_track'] = ($g['monthly_sip'] ?? 0) >= $g['required_monthly_sip'];
        }
        unset($g);

        json_response(true, '', ['goals' => $goals, 'total' => count($goals)]);

    // ── ADD GOAL ───────────────────────────────────────────────
    case 'goal_add':
        csrf_verify();
        $name     = clean($_POST['goal_name'] ?? '');
        $target   = (float)($_POST['target_amount'] ?? 0);
        $date     = clean($_POST['target_date'] ?? '');
        $type     = clean($_POST['goal_type'] ?? 'custom');
        $sip      = (float)($_POST['monthly_sip'] ?? 0);
        $ret      = (float)($_POST['expected_return'] ?? 12);
        $current  = (float)($_POST['current_amount'] ?? 0);

        if (!$name || !$target || !$date) json_response(false, 'Goal name, target amount aur date required hai.');

        DB::run(
            "INSERT INTO goals (user_id, goal_name, goal_type, target_amount, current_amount, monthly_sip, target_date, expected_return)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$userId, $name, $type, $target, $current, $sip, $date, $ret]
        );

        json_response(true, "Goal '{$name}' add ho gaya!", ['goal_id' => DB::lastInsertId()]);

    // ── ASSIGN HOLDING TO GOAL ─────────────────────────────────
    case 'goal_bucket_assign':
        csrf_verify();
        $goalId    = (int)($_POST['goal_id'] ?? 0);
        $assetType = clean($_POST['asset_type'] ?? '');
        $assetId   = (int)($_POST['asset_id'] ?? 0);
        $allocPct  = min(100, max(1, (float)($_POST['allocated_pct'] ?? 100)));

        if (!$goalId || !$assetType || !$assetId) {
            json_response(false, 'Goal ID, asset type aur asset ID required hai.');
        }

        // Verify goal belongs to user
        $goal = DB::fetchRow("SELECT id, goal_name FROM goals WHERE id = ? AND user_id = ?", [$goalId, $userId]);
        if (!$goal) json_response(false, 'Goal nahi mili.');

        DB::run(
            "INSERT INTO goal_holdings (goal_id, user_id, asset_type, asset_id, allocated_pct)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE allocated_pct = VALUES(allocated_pct)",
            [$goalId, $userId, $assetType, $assetId, $allocPct]
        );

        // Recalculate goal current_amount
        $mfValue = (float)(DB::fetchVal(
            "SELECT COALESCE(SUM(mh.current_value * gh.allocated_pct/100), 0)
             FROM goal_holdings gh
             JOIN mf_holdings mh ON mh.id = gh.asset_id
             WHERE gh.goal_id = ? AND gh.asset_type = 'mf'",
            [$goalId]) ?? 0);

        $fdValue = (float)(DB::fetchVal(
            "SELECT COALESCE(SUM(fd.maturity_amount * gh.allocated_pct/100), 0)
             FROM goal_holdings gh
             JOIN fd_investments fd ON fd.id = gh.asset_id
             WHERE gh.goal_id = ? AND gh.asset_type = 'fd'",
            [$goalId]) ?? 0);

        DB::run("UPDATE goals SET current_amount = ? WHERE id = ?", [$mfValue + $fdValue, $goalId]);

        json_response(true, "Asset '{$assetType}' linked to goal '{$goal['goal_name']}'.");

    default:
        json_response(false, 'Unknown net worth action.', [], 400);
}
