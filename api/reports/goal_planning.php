<?php
/**
 * WealthDash — Goal Planning API
 * Actions: goal_list | goal_add | goal_edit | goal_delete | goal_projection | goal_contribute
 */
declare(strict_types=1);

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$userId      = (int) $_SESSION['user_id'];
$isAdmin     = is_admin();
$portfolioId = (int)($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ?? 0);
if (!$portfolioId) $portfolioId = get_user_portfolio_id((int)($currentUser['id'] ?? 0));

if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
    json_response(false, 'Invalid or inaccessible portfolio.');
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ── List Goals ────────────────────────────────────────────
    case 'goal_list':
        $goals = DB::fetchAll(
            "SELECT g.*,
                    COALESCE((SELECT SUM(amount) FROM goal_contributions WHERE goal_id=g.id),0)
                        AS contributions_sum
             FROM investment_goals g
             WHERE g.portfolio_id = ?
             ORDER BY g.is_achieved ASC, g.priority DESC, g.target_date ASC",
            [$portfolioId]
        );

        foreach ($goals as &$goal) {
            $goal = _enrich_goal($goal);
        }
        unset($goal);

        // Summary
        $totalTargets  = array_sum(array_column($goals, 'target_amount'));
        $totalSaved    = array_sum(array_column($goals, 'effective_saved'));
        $achieved      = count(array_filter($goals, fn($g) => $g['is_achieved']));

        json_response(true, '', [
            'goals'         => $goals,
            'total_targets' => round($totalTargets, 2),
            'total_saved'   => round($totalSaved, 2),
            'achieved_count'=> $achieved,
        ]);

    // ── Goal Projection Calculator (standalone) ───────────────
    case 'goal_projection':
        $targetAmount  = (float) ($_POST['target_amount']   ?? $_GET['target_amount']   ?? 0);
        $returnPct     = (float) ($_POST['return_pct']      ?? $_GET['return_pct']      ?? 12);
        $months        = (int)   ($_POST['months']          ?? $_GET['months']          ?? 120);
        $currentSaved  = (float) ($_POST['current_saved']   ?? $_GET['current_saved']   ?? 0);

        if ($targetAmount <= 0 || $months <= 0) {
            json_response(false, 'target_amount and months are required.');
        }

        $result = _calculate_projection($targetAmount, $returnPct, $months, $currentSaved);
        json_response(true, '', $result);

    // ── Add Goal ──────────────────────────────────────────────
    case 'goal_add':
        if (!can_edit_portfolio($portfolioId, $userId, $isAdmin)) {
            json_response(false, 'Edit access required.');
        }
        csrf_verify();

        $name        = clean($_POST['name']        ?? '');
        $desc        = clean($_POST['description'] ?? '');
        $target      = (float) ($_POST['target_amount'] ?? 0);
        $targetDate  = date_to_db(clean($_POST['target_date'] ?? ''));
        $returnPct   = (float) ($_POST['expected_return_pct'] ?? 12);
        $priority    = in_array($_POST['priority'] ?? '', ['high','medium','low'])
                       ? $_POST['priority'] : 'medium';
        $color       = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['color'] ?? '')
                       ? $_POST['color'] : '#2563EB';
        $icon        = clean($_POST['icon'] ?? 'target');

        if (!$name || $target <= 0 || !$targetDate) {
            json_response(false, 'Name, target amount, and target date are required.');
        }

        // Auto-calculate monthly SIP needed
        $monthsLeft = _months_to_date($targetDate);
        $monthlySip = $monthsLeft > 0
            ? _sip_needed($target, $returnPct, $monthsLeft, 0)
            : null;

        DB::run(
            'INSERT INTO investment_goals
             (portfolio_id, name, description, target_amount, target_date,
              expected_return_pct, priority, color, icon, monthly_sip_needed)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$portfolioId, $name, $desc ?: null, $target, $targetDate,
             $returnPct, $priority, $color, $icon, $monthlySip]
        );
        $id = (int) DB::lastInsertId();
        audit_log('goal_add', 'investment_goals', $id);
        json_response(true, 'Goal created.', ['id' => $id]);

    // ── Edit Goal ─────────────────────────────────────────────
    case 'goal_edit':
        if (!can_edit_portfolio($portfolioId, $userId, $isAdmin)) {
            json_response(false, 'Edit access required.');
        }
        csrf_verify();

        $goalId     = (int) ($_POST['goal_id'] ?? 0);
        $name       = clean($_POST['name']     ?? '');
        $target     = (float) ($_POST['target_amount']      ?? 0);
        $targetDate = date_to_db(clean($_POST['target_date'] ?? ''));
        $returnPct  = (float) ($_POST['expected_return_pct'] ?? 12);
        $priority   = in_array($_POST['priority'] ?? '', ['high','medium','low'])
                      ? $_POST['priority'] : 'medium';
        $color      = preg_match('/^#[0-9A-Fa-f]{6}$/', $_POST['color'] ?? '')
                      ? $_POST['color'] : '#2563EB';

        $goal = DB::fetchOne(
            'SELECT id FROM investment_goals WHERE id = ? AND portfolio_id = ?',
            [$goalId, $portfolioId]
        );
        if (!$goal) json_response(false, 'Goal not found.');

        $monthsLeft = _months_to_date($targetDate);
        $currentSaved = (float) DB::fetchVal(
            'SELECT COALESCE(SUM(amount),0) FROM goal_contributions WHERE goal_id=?',
            [$goalId]
        );
        $monthlySip = $monthsLeft > 0
            ? _sip_needed($target, $returnPct, $monthsLeft, $currentSaved)
            : null;

        DB::run(
            'UPDATE investment_goals
             SET name=?, target_amount=?, target_date=?, expected_return_pct=?,
                 priority=?, color=?, monthly_sip_needed=?
             WHERE id=?',
            [$name, $target, $targetDate, $returnPct, $priority, $color, $monthlySip, $goalId]
        );
        audit_log('goal_edit', 'investment_goals', $goalId);
        json_response(true, 'Goal updated.');

    // ── Delete Goal ───────────────────────────────────────────
    case 'goal_delete':
        if (!can_edit_portfolio($portfolioId, $userId, $isAdmin)) {
            json_response(false, 'Edit access required.');
        }
        csrf_verify();

        $goalId = (int) ($_POST['goal_id'] ?? 0);
        $goal   = DB::fetchOne(
            'SELECT id FROM investment_goals WHERE id = ? AND portfolio_id = ?',
            [$goalId, $portfolioId]
        );
        if (!$goal) json_response(false, 'Goal not found.');

        DB::run('DELETE FROM investment_goals WHERE id = ?', [$goalId]);
        audit_log('goal_delete', 'investment_goals', $goalId);
        json_response(true, 'Goal deleted.');

    // ── Mark Goal Achieved ────────────────────────────────────
    case 'goal_mark_achieved':
        csrf_verify();
        $goalId = (int) ($_POST['goal_id'] ?? 0);
        $goal   = DB::fetchOne(
            'SELECT id FROM investment_goals WHERE id = ? AND portfolio_id = ?',
            [$goalId, $portfolioId]
        );
        if (!$goal) json_response(false, 'Goal not found.');

        DB::run(
            'UPDATE investment_goals SET is_achieved=1, achieved_at=CURDATE() WHERE id=?',
            [$goalId]
        );
        audit_log('goal_mark_achieved', 'investment_goals', $goalId);
        json_response(true, '🎉 Goal marked as achieved!');

    // ── Add Contribution ──────────────────────────────────────
    case 'goal_contribute':
        if (!can_edit_portfolio($portfolioId, $userId, $isAdmin)) {
            json_response(false, 'Edit access required.');
        }
        csrf_verify();

        $goalId  = (int)   ($_POST['goal_id'] ?? 0);
        $amount  = (float) ($_POST['amount']  ?? 0);
        $date    = date_to_db(clean($_POST['date'] ?? date('Y-m-d')));
        $note    = clean($_POST['note'] ?? '');

        if (!$goalId || $amount <= 0) json_response(false, 'Goal ID and amount required.');

        $goal = DB::fetchOne(
            'SELECT id, target_amount FROM investment_goals WHERE id = ? AND portfolio_id = ?',
            [$goalId, $portfolioId]
        );
        if (!$goal) json_response(false, 'Goal not found.');

        DB::run(
            'INSERT INTO goal_contributions (goal_id, amount, contribution_date, note)
             VALUES (?, ?, ?, ?)',
            [$goalId, $amount, $date, $note ?: null]
        );

        // Check if goal achieved now
        $totalSaved = (float) DB::fetchVal(
            'SELECT COALESCE(SUM(amount),0) FROM goal_contributions WHERE goal_id=?',
            [$goalId]
        );
        if ($totalSaved >= (float) $goal['target_amount']) {
            DB::run(
                'UPDATE investment_goals SET is_achieved=1, achieved_at=CURDATE() WHERE id=? AND is_achieved=0',
                [$goalId]
            );
        }

        json_response(true, 'Contribution recorded.', ['total_saved' => round($totalSaved, 2)]);

    // ── t292: Goal-Based Asset Allocation ────────────────────
    case 'goal_asset_allocation':
        // Returns every goal with its linked assets (MF + FD + Stocks) and current values
        $goals = DB::fetchAll(
            "SELECT id, name, color, icon, target_amount, current_saved, is_achieved,
                    linked_fund_ids, linked_stock_ids, linked_fd_ids
             FROM investment_goals
             WHERE portfolio_id = ?
             ORDER BY is_achieved ASC, target_amount DESC",
            [$portfolioId]
        );

        $result      = [];
        $totalLinked = 0.0;
        $unlinked    = [];

        // Fetch all MF holdings for this portfolio
        $mfHoldings = DB::fetchAll(
            "SELECT h.id, h.fund_id, h.current_value, h.invested_amount,
                    f.fund_name, f.category
             FROM mf_holdings h
             JOIN funds f ON f.id = h.fund_id
             WHERE h.portfolio_id = ? AND h.current_value > 0",
            [$portfolioId]
        );
        $mfById = [];
        foreach ($mfHoldings as $h) $mfById[$h['fund_id']] = $h;

        // Fetch all FD accounts for this portfolio
        $fdAccounts = DB::fetchAll(
            "SELECT id, bank_name, principal_amount, maturity_amount,
                    interest_earned, status, fd_type
             FROM fd_accounts
             WHERE portfolio_id = ? AND status = 'active'",
            [$portfolioId]
        );
        $fdById = [];
        foreach ($fdAccounts as $f) $fdById[$f['id']] = $f;

        // Fetch all stock holdings for this portfolio
        $stockHoldings = DB::fetchAll(
            "SELECT h.id, h.stock_id, h.current_value, h.invested_amount,
                    sm.symbol, sm.company_name
             FROM stock_holdings h
             JOIN stock_master sm ON sm.id = h.stock_id
             WHERE h.portfolio_id = ? AND h.quantity > 0",
            [$portfolioId]
        );
        $stockById = [];
        foreach ($stockHoldings as $s) $stockById[$s['stock_id']] = $s;

        // Track which asset IDs are linked to any goal
        $linkedMfIds    = [];
        $linkedFdIds    = [];
        $linkedStockIds = [];

        foreach ($goals as $goal) {
            $mfIds    = json_decode($goal['linked_fund_ids']  ?? '[]', true)  ?: [];
            $fdIds    = json_decode($goal['linked_fd_ids']    ?? '[]', true)  ?: [];
            $stIds    = json_decode($goal['linked_stock_ids'] ?? '[]', true)  ?: [];

            $assets       = [];
            $goalValue    = 0.0;
            $goalInvested = 0.0;

            foreach ($mfIds as $fid) {
                $fid = (int)$fid;
                if (isset($mfById[$fid])) {
                    $h = $mfById[$fid];
                    $assets[] = [
                        'type' => 'mf', 'id' => $fid,
                        'name' => $h['fund_name'], 'category' => $h['category'],
                        'current_value' => (float)$h['current_value'],
                        'invested'      => (float)$h['invested_amount'],
                    ];
                    $goalValue    += (float)$h['current_value'];
                    $goalInvested += (float)$h['invested_amount'];
                    $linkedMfIds[] = $fid;
                }
            }
            foreach ($fdIds as $fid) {
                $fid = (int)$fid;
                if (isset($fdById[$fid])) {
                    $f   = $fdById[$fid];
                    $val = (float)($f['principal_amount']) + (float)($f['interest_earned']);
                    $assets[] = [
                        'type' => 'fd', 'id' => $fid,
                        'name' => $f['bank_name'] . ' FD',
                        'category' => strtoupper($f['fd_type']),
                        'current_value' => $val,
                        'invested'      => (float)$f['principal_amount'],
                    ];
                    $goalValue    += $val;
                    $goalInvested += (float)$f['principal_amount'];
                    $linkedFdIds[] = $fid;
                }
            }
            foreach ($stIds as $sid) {
                $sid = (int)$sid;
                if (isset($stockById[$sid])) {
                    $s = $stockById[$sid];
                    $assets[] = [
                        'type' => 'stock', 'id' => $sid,
                        'name' => $s['company_name'] . ' (' . $s['symbol'] . ')',
                        'category' => 'Stock',
                        'current_value' => (float)$s['current_value'],
                        'invested'      => (float)$s['invested_amount'],
                    ];
                    $goalValue    += (float)$s['current_value'];
                    $goalInvested += (float)$s['invested_amount'];
                    $linkedStockIds[] = $sid;
                }
            }

            $target   = (float)$goal['target_amount'];
            $progress = $target > 0 ? min(round($goalValue / $target * 100, 1), 100) : 0;
            $totalLinked += $goalValue;

            $result[] = [
                'id'           => (int)$goal['id'],
                'name'         => $goal['name'],
                'color'        => $goal['color'],
                'icon'         => $goal['icon'],
                'target_amount'=> $target,
                'current_value'=> round($goalValue, 2),
                'invested'     => round($goalInvested, 2),
                'progress_pct' => $progress,
                'is_achieved'  => (bool)$goal['is_achieved'],
                'assets'       => $assets,
                'asset_count'  => count($assets),
            ];
        }

        // Unlinked MF holdings
        foreach ($mfHoldings as $h) {
            if (!in_array((int)$h['fund_id'], $linkedMfIds)) {
                $unlinked[] = [
                    'type' => 'mf', 'id' => (int)$h['fund_id'],
                    'name' => $h['fund_name'], 'category' => $h['category'],
                    'current_value' => (float)$h['current_value'],
                ];
            }
        }
        // Unlinked FDs
        foreach ($fdAccounts as $f) {
            if (!in_array((int)$f['id'], $linkedFdIds)) {
                $val = (float)$f['principal_amount'] + (float)$f['interest_earned'];
                $unlinked[] = [
                    'type' => 'fd', 'id' => (int)$f['id'],
                    'name' => $f['bank_name'] . ' FD',
                    'category' => 'FD',
                    'current_value' => $val,
                ];
            }
        }
        // Unlinked Stocks
        foreach ($stockHoldings as $s) {
            if (!in_array((int)$s['stock_id'], $linkedStockIds)) {
                $unlinked[] = [
                    'type' => 'stock', 'id' => (int)$s['stock_id'],
                    'name' => $s['company_name'],
                    'category' => 'Stock',
                    'current_value' => (float)$s['current_value'],
                ];
            }
        }

        json_response(true, '', [
            'goals'        => $result,
            'unlinked'     => $unlinked,
            'total_linked' => round($totalLinked, 2),
        ]);

    // ── t292: Link/Unlink asset to goal ──────────────────────
    case 'goal_link_asset':
    case 'goal_unlink_asset':
        if (!can_edit_portfolio($portfolioId, $userId, $isAdmin)) {
            json_response(false, 'Edit access required.');
        }
        csrf_verify();

        $goalId    = (int)($_POST['goal_id'] ?? 0);
        $assetType = clean($_POST['asset_type'] ?? '');  // mf | fd | stock
        $assetId   = (int)($_POST['asset_id']  ?? 0);

        if (!$goalId || !$assetId || !in_array($assetType, ['mf','fd','stock'])) {
            json_response(false, 'goal_id, asset_type, asset_id required.');
        }

        $goal = DB::fetchOne(
            'SELECT id, linked_fund_ids, linked_stock_ids, linked_fd_ids
             FROM investment_goals WHERE id = ? AND portfolio_id = ?',
            [$goalId, $portfolioId]
        );
        if (!$goal) json_response(false, 'Goal not found.');

        $col  = $assetType === 'mf'    ? 'linked_fund_ids'
              : ($assetType === 'fd'   ? 'linked_fd_ids'
              :                          'linked_stock_ids');
        $ids  = json_decode($goal[$col] ?? '[]', true) ?: [];
        $ids  = array_map('intval', $ids);

        if ($action === 'goal_link_asset') {
            if (!in_array($assetId, $ids)) $ids[] = $assetId;
            $msg = 'Asset linked to goal.';
        } else {
            $ids = array_values(array_filter($ids, fn($i) => $i !== $assetId));
            $msg = 'Asset unlinked from goal.';
        }

        DB::run(
            "UPDATE investment_goals SET {$col} = ? WHERE id = ?",
            [json_encode(array_values($ids)), $goalId]
        );
        json_response(true, $msg, ['linked_ids' => $ids]);

    default:
        json_response(false, 'Unknown goal action.', [], 400);
}

// ── Helpers ──────────────────────────────────────────────────

function _enrich_goal(array $goal): array {
    $effectiveSaved = max(
        (float) $goal['current_saved'],
        (float) $goal['contributions_sum']
    );
    $goal['effective_saved'] = $effectiveSaved;

    $target    = (float) $goal['target_amount'];
    $pct       = $target > 0 ? min(round(($effectiveSaved / $target) * 100, 1), 100) : 0;
    $remaining = max($target - $effectiveSaved, 0);

    $monthsLeft = _months_to_date($goal['target_date']);
    $projection = _calculate_projection($target, (float)$goal['expected_return_pct'], $monthsLeft, $effectiveSaved);

    $goal['progress_pct']    = $pct;
    $goal['amount_remaining']= round($remaining, 2);
    $goal['months_left']     = $monthsLeft;
    $goal['monthly_sip_needed'] = $projection['sip_needed'];
    $goal['on_track']        = $projection['on_track'];
    $goal['projected_value'] = $projection['projected_value'];
    return $goal;
}

function _months_to_date(string $targetDate): int {
    $today  = new DateTime();
    $target = new DateTime($targetDate);
    if ($target <= $today) return 0;
    $diff = $today->diff($target);
    return $diff->y * 12 + $diff->m;
}

/**
 * Calculate SIP needed to reach targetAmount in N months
 * given current savings and expected annual return.
 */
function _sip_needed(float $target, float $annualReturnPct, int $months, float $currentSaved): float {
    if ($months <= 0) return 0.0;
    $r = ($annualReturnPct / 100) / 12; // monthly rate

    // Future value of current savings
    $fvCurrent = $currentSaved * pow(1 + $r, $months);
    $remaining = $target - $fvCurrent;
    if ($remaining <= 0) return 0.0;

    if ($r == 0) return round($remaining / $months, 2);

    // SIP formula: SIP = FV * r / ((1+r)^n - 1)
    $sip = $remaining * $r / (pow(1 + $r, $months) - 1);
    return round($sip, 2);
}

function _calculate_projection(float $target, float $annualReturnPct, int $months, float $currentSaved): array {
    if ($months <= 0) {
        return [
            'sip_needed'       => 0,
            'projected_value'  => round($currentSaved, 2),
            'on_track'         => $currentSaved >= $target,
            'months'           => 0,
        ];
    }

    $r   = ($annualReturnPct / 100) / 12;
    $sipNeeded = _sip_needed($target, $annualReturnPct, $months, $currentSaved);

    // Project monthly chart data (12 points)
    $chartPoints = [];
    $step = max(1, (int) ceil($months / 12));
    $running = $currentSaved;
    for ($m = 0; $m <= $months; $m += $step) {
        $fv = $currentSaved * pow(1 + $r, $m);
        if ($sipNeeded > 0 && $r > 0) {
            $fv += $sipNeeded * (pow(1 + $r, $m) - 1) / $r;
        } elseif ($sipNeeded > 0) {
            $fv += $sipNeeded * $m;
        }
        $chartPoints[] = [
            'month'     => $m,
            'projected' => round($fv, 2),
            'target'    => round($target, 2),
        ];
    }

    // Final projected value with suggested SIP
    $projectedFinal = $currentSaved * pow(1 + $r, $months);
    if ($sipNeeded > 0 && $r > 0) {
        $projectedFinal += $sipNeeded * (pow(1 + $r, $months) - 1) / $r;
    } elseif ($sipNeeded > 0) {
        $projectedFinal += $sipNeeded * $months;
    }

    return [
        'sip_needed'      => $sipNeeded,
        'projected_value' => round($projectedFinal, 2),
        'on_track'        => $projectedFinal >= $target,
        'months'          => $months,
        'chart'           => $chartPoints,
        'return_pct'      => $annualReturnPct,
    ];
}

