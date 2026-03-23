<?php
/**
 * WealthDash — Goal Buckets API (t139)
 * Actions: goals_list, goals_add, goals_delete, goals_link_fund, goals_unlink_fund
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

if ($action === 'goals_list') {
    $rows = DB::fetchAll(
        "SELECT g.*,
                COUNT(DISTINCT gfl.id) AS linked_count
         FROM goal_buckets g
         LEFT JOIN goal_fund_links gfl ON gfl.goal_id = g.id
         WHERE g.user_id = ?
         GROUP BY g.id
         ORDER BY g.target_date ASC, g.created_at DESC",
        [$userId]
    );
    json_response(true, '', $rows);
}

if ($action === 'goals_add') {
    $name   = clean($_POST['name']          ?? '');
    $emoji  = clean($_POST['emoji']         ?? '🎯');
    $color  = clean($_POST['color']         ?? '#6366f1');
    $target = (float)($_POST['target_amount']?? 0);
    $date   = clean($_POST['target_date']   ?? '') ?: null;
    $notes  = clean($_POST['notes']         ?? '');
    if (!$name) json_response(false, 'Goal name required.');
    DB::run(
        "INSERT INTO goal_buckets (user_id,name,emoji,color,target_amount,target_date,notes) VALUES (?,?,?,?,?,?,?)",
        [$userId, $name, $emoji, $color, $target, $date, $notes]
    );
    json_response(true, 'Goal created.', ['id' => DB::conn()->lastInsertId()]);
}

if ($action === 'goals_delete') {
    $id = (int)($_POST['id'] ?? 0);
    DB::run("DELETE FROM goal_buckets WHERE id=? AND user_id=?", [$id, $userId]);
    json_response(true, 'Goal deleted.');
}

if ($action === 'goals_link_fund') {
    $goalId = (int)($_POST['goal_id'] ?? 0);
    $fundId = (int)($_POST['fund_id'] ?? 0);
    $sipId  = (int)($_POST['sip_id']  ?? 0);
    $type   = $sipId ? 'sip' : 'holding';
    // Verify goal belongs to user
    $goal = DB::fetchOne("SELECT id FROM goal_buckets WHERE id=? AND user_id=?", [$goalId, $userId]);
    if (!$goal) json_response(false, 'Goal not found.');
    // Remove existing link for this fund/sip
    if ($fundId) DB::run("DELETE FROM goal_fund_links WHERE goal_id=? AND fund_id=?", [$goalId, $fundId]);
    if ($sipId)  DB::run("DELETE FROM goal_fund_links WHERE goal_id=? AND sip_id=?",  [$goalId, $sipId]);
    DB::run(
        "INSERT INTO goal_fund_links (goal_id,fund_id,sip_id,link_type) VALUES (?,?,?,?)",
        [$goalId, $fundId ?: null, $sipId ?: null, $type]
    );
    json_response(true, 'Fund linked to goal.');
}

if ($action === 'goals_unlink_fund') {
    $id = (int)($_POST['link_id'] ?? 0);
    DB::run("DELETE FROM goal_fund_links WHERE id=? AND goal_id IN (SELECT id FROM goal_buckets WHERE user_id=?)", [$id, $userId]);
    json_response(true, 'Unlinked.');
}

if ($action === 'goals_with_values') {
    // Return goals with current value from linked holdings
    $rows = DB::fetchAll(
        "SELECT g.*,
                COALESCE(SUM(CASE WHEN gfl.link_type='holding' THEN mh.value_now ELSE 0 END), 0) AS current_value,
                COUNT(DISTINCT gfl.id) AS linked_count
         FROM goal_buckets g
         LEFT JOIN goal_fund_links gfl ON gfl.goal_id = g.id
         LEFT JOIN mf_holdings mh ON mh.fund_id = gfl.fund_id AND mh.portfolio_id IN
               (SELECT id FROM portfolios WHERE user_id = ?)
         WHERE g.user_id = ?
         GROUP BY g.id
         ORDER BY g.target_date ASC",
        [$userId, $userId]
    );
    json_response(true, '', $rows);
}
