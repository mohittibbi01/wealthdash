<?php
/**
 * WealthDash — t434: Corporate Actions — Bonus, Split, Dividend Tracking
 * Actions: ca_list, ca_add, ca_delete, ca_apply_bonus, ca_apply_split,
 *          ca_dividends_list, ca_dividend_add, ca_summary,
 *          ca_pending, ca_history
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();
$action      = clean($_GET['action'] ?? $_POST['action'] ?? '');

function ca_portfolio(int $userId): ?int
{
    $pid = (int)($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ?? 0);
    if ($pid && can_access_portfolio($pid, $userId, is_admin())) return $pid;
    return get_user_portfolio_id($userId) ?: null;
}

switch ($action) {

    // ─── LIST CORPORATE ACTIONS ───────────────────────────────────────────────
    case 'ca_list':
        $pid     = ca_portfolio($userId);
        $stockId = (int)($_GET['stock_id'] ?? 0);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $where  = ['ca.portfolio_id = ?'];
        $params = [$pid];
        if ($stockId) { $where[] = 'ca.stock_id = ?'; $params[] = $stockId; }

        $rows = DB::fetchAll("
            SELECT ca.*, sm.symbol, sm.company_name
            FROM corporate_actions ca
            JOIN stock_master sm ON sm.id = ca.stock_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY ca.ex_date DESC
        ", $params);

        json_response(true, '', ['data' => $rows]);


    // ─── ADD CORPORATE ACTION ─────────────────────────────────────────────────
    case 'ca_add':
        $pid        = ca_portfolio($userId);
        $stockId    = (int)($_POST['stock_id']   ?? 0);
        $type       = clean($_POST['type']        ?? '');
        $exDate     = clean($_POST['ex_date']     ?? '');
        $recordDate = clean($_POST['record_date'] ?? '');
        $notes      = clean($_POST['notes']       ?? '');

        $validTypes = ['bonus','split','dividend','rights','buyback','merger','demerger'];
        if (!$pid || !$stockId || !in_array($type, $validTypes) || !$exDate) {
            json_response(false, 'portfolio_id, stock_id, type, and ex_date are required.');
        }

        // Type-specific fields
        $bonusRatio    = null; $splitOld = null; $splitNew = null;
        $dividendAmt   = null; $rightsRatio = null; $rightsPrice = null;

        if ($type === 'bonus') {
            // e.g. 1:1 bonus means for every 1 share, get 1 more
            $bonusRatio = clean($_POST['bonus_ratio'] ?? '');  // "1:1", "1:2"
        } elseif ($type === 'split') {
            $splitOld = (int)($_POST['split_old'] ?? 1);
            $splitNew = (int)($_POST['split_new'] ?? 1);
        } elseif ($type === 'dividend') {
            $dividendAmt = (float)($_POST['dividend_per_share'] ?? 0);
        } elseif ($type === 'rights') {
            $rightsRatio = clean($_POST['rights_ratio'] ?? '');
            $rightsPrice = (float)($_POST['rights_price'] ?? 0);
        }

        DB::run("
            INSERT INTO corporate_actions
            (portfolio_id, stock_id, type, ex_date, record_date,
             bonus_ratio, split_old, split_new, dividend_per_share,
             rights_ratio, rights_price, is_applied, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?)
        ", [$pid, $stockId, $type, $exDate, $recordDate ?: null,
            $bonusRatio, $splitOld, $splitNew, $dividendAmt,
            $rightsRatio, $rightsPrice, $notes ?: null]);

        $caId = (int)$db->lastInsertId();
        json_response(true, 'Corporate action recorded.', ['id' => $caId]);


    // ─── APPLY BONUS ──────────────────────────────────────────────────────────
    case 'ca_apply_bonus':
        $caId = (int)($_POST['ca_id'] ?? 0);
        $pid  = ca_portfolio($userId);
        if (!$caId || !$pid) { json_response(false, 'Invalid request.'); }

        $ca = DB::fetchOne("SELECT * FROM corporate_actions WHERE id=? AND portfolio_id=?", [$caId, $pid]);
        if (!$ca)              { json_response(false, 'Corporate action not found.'); }
        if ($ca['is_applied']) { json_response(false, 'Already applied.'); }
        if ($ca['type'] !== 'bonus') { json_response(false, 'Not a bonus action.'); }

        // Parse ratio "1:1" → bonus shares per 1 held = 1
        // "1:2" means 1 bonus for every 2 held
        $ratio      = $ca['bonus_ratio'] ?? '1:1';
        [$num, $den] = array_map('intval', explode(':', $ratio . ':1'));
        $den = max(1, $den);

        $holding = DB::fetchOne("
            SELECT * FROM stock_holdings WHERE stock_id=? AND portfolio_id=? AND quantity>0
        ", [$ca['stock_id'], $pid]);

        if (!$holding) { json_response(false, 'No holding found to apply bonus to.'); }

        $bonusShares   = (int)floor($holding['quantity'] * $num / $den);
        $newQty        = $holding['quantity'] + $bonusShares;
        $totalInvested = (float)$holding['total_invested'];
        // Avg price dilutes proportionally
        $newAvgPrice   = $newQty > 0 ? $totalInvested / $newQty : 0;

        DB::run("UPDATE stock_holdings SET quantity=?, avg_buy_price=?, updated_at=NOW() WHERE id=?",
            [$newQty, round($newAvgPrice, 4), $holding['id']]);

        // Log as transaction
        DB::run("INSERT INTO stock_transactions (portfolio_id, stock_id, type, quantity, price, total_value, notes, transaction_date)
                 VALUES (?,?,'bonus',?,0,0,?,?)",
            [$pid, $ca['stock_id'], $bonusShares, "Bonus {$ratio} applied (CA #{$caId})", $ca['ex_date']]);

        DB::run("UPDATE corporate_actions SET is_applied=1, applied_at=NOW() WHERE id=?", [$caId]);

        json_response(true, "Bonus applied: +{$bonusShares} shares. New qty: {$newQty}, new avg: ₹".round($newAvgPrice,2), [
            'bonus_shares'   => $bonusShares,
            'new_quantity'   => $newQty,
            'new_avg_price'  => round($newAvgPrice, 4),
        ]);


    // ─── APPLY SPLIT ──────────────────────────────────────────────────────────
    case 'ca_apply_split':
        $caId = (int)($_POST['ca_id'] ?? 0);
        $pid  = ca_portfolio($userId);
        if (!$caId || !$pid) { json_response(false, 'Invalid request.'); }

        $ca = DB::fetchOne("SELECT * FROM corporate_actions WHERE id=? AND portfolio_id=?", [$caId, $pid]);
        if (!$ca)              { json_response(false, 'Corporate action not found.'); }
        if ($ca['is_applied']) { json_response(false, 'Already applied.'); }
        if ($ca['type'] !== 'split') { json_response(false, 'Not a split action.'); }

        $splitOld = max(1, (int)$ca['split_old']); // old face value e.g. 10
        $splitNew = max(1, (int)$ca['split_new']); // new face value e.g. 1

        // 10→1 split: shares × 10, price ÷ 10
        $multiplier = $splitOld / $splitNew;

        $holding = DB::fetchOne("
            SELECT * FROM stock_holdings WHERE stock_id=? AND portfolio_id=? AND quantity>0
        ", [$ca['stock_id'], $pid]);

        if (!$holding) { json_response(false, 'No holding to apply split to.'); }

        $newQty      = (int)round($holding['quantity'] * $multiplier);
        $newAvgPrice = (float)$holding['avg_buy_price'] / $multiplier;

        DB::run("UPDATE stock_holdings SET quantity=?, avg_buy_price=?, updated_at=NOW() WHERE id=?",
            [$newQty, round($newAvgPrice, 4), $holding['id']]);

        // Also update stock_master face_value if exists
        DB::run("UPDATE stock_master SET face_value=?, latest_price=ROUND(latest_price/??, 4) WHERE id=?",
            [$splitNew, $multiplier, $ca['stock_id']]);

        DB::run("INSERT INTO stock_transactions (portfolio_id, stock_id, type, quantity, price, total_value, notes, transaction_date)
                 VALUES (?,?,'split',?,?,0,?,?)",
            [$pid, $ca['stock_id'], $newQty, round($newAvgPrice,4),
             "Stock split {$splitOld}:{$splitNew} applied (CA #{$caId})", $ca['ex_date']]);

        DB::run("UPDATE corporate_actions SET is_applied=1, applied_at=NOW() WHERE id=?", [$caId]);

        json_response(true, "Split {$splitOld}:{$splitNew} applied. New qty: {$newQty}, new avg: ₹".round($newAvgPrice,2), [
            'new_quantity'  => $newQty,
            'new_avg_price' => round($newAvgPrice, 4),
            'multiplier'    => $multiplier,
        ]);


    // ─── DIVIDEND LIST ────────────────────────────────────────────────────────
    case 'ca_dividends_list':
        $pid = ca_portfolio($userId);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $year = (int)($_GET['year'] ?? 0);
        $whereYear = $year ? "AND YEAR(ca.ex_date) = {$year}" : '';

        $rows = DB::fetchAll("
            SELECT ca.*, sm.symbol, sm.company_name,
                   sh.quantity AS qty_on_ex_date,
                   ROUND(ca.dividend_per_share * sh.quantity, 2) AS total_dividend_received
            FROM corporate_actions ca
            JOIN stock_master sm ON sm.id = ca.stock_id
            LEFT JOIN stock_holdings sh ON sh.stock_id = ca.stock_id AND sh.portfolio_id = ca.portfolio_id
            WHERE ca.portfolio_id=? AND ca.type='dividend' {$whereYear}
            ORDER BY ca.ex_date DESC
        ", [$pid]);

        $summary = DB::fetchOne("
            SELECT
                COALESCE(SUM(ca.dividend_per_share * sh.quantity), 0) AS total_received,
                COUNT(*) AS count
            FROM corporate_actions ca
            LEFT JOIN stock_holdings sh ON sh.stock_id=ca.stock_id AND sh.portfolio_id=ca.portfolio_id
            WHERE ca.portfolio_id=? AND ca.type='dividend' {$whereYear}
        ", [$pid]);

        json_response(true, '', ['data' => $rows, 'summary' => $summary]);


    // ─── ADD DIVIDEND ─────────────────────────────────────────────────────────
    case 'ca_dividend_add':
        $pid          = ca_portfolio($userId);
        $stockId      = (int)($_POST['stock_id']          ?? 0);
        $exDate       = clean($_POST['ex_date']            ?? '');
        $dividendAmt  = (float)($_POST['dividend_per_share']?? 0);
        $paymentDate  = clean($_POST['payment_date']       ?? '');
        $notes        = clean($_POST['notes']              ?? '');

        if (!$pid || !$stockId || !$exDate || $dividendAmt <= 0) {
            json_response(false, 'stock_id, ex_date, and dividend_per_share required.');
        }

        DB::run("
            INSERT INTO corporate_actions
            (portfolio_id, stock_id, type, ex_date, record_date, dividend_per_share, is_applied, notes)
            VALUES (?,?,'dividend',?,?,?,1,?)
        ", [$pid, $stockId, $exDate, $paymentDate ?: null, $dividendAmt, $notes ?: null]);

        // Auto-calculate total received
        $holding = DB::fetchOne("SELECT quantity FROM stock_holdings WHERE stock_id=? AND portfolio_id=?", [$stockId, $pid]);
        $totalReceived = $holding ? round($dividendAmt * (float)$holding['quantity'], 2) : 0;

        json_response(true, 'Dividend recorded.', [
            'id'              => (int)$db->lastInsertId(),
            'total_received'  => $totalReceived,
        ]);


    // ─── PENDING (unapplied) ACTIONS ──────────────────────────────────────────
    case 'ca_pending':
        $pid = ca_portfolio($userId);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $rows = DB::fetchAll("
            SELECT ca.*, sm.symbol, sm.company_name
            FROM corporate_actions ca
            JOIN stock_master sm ON sm.id = ca.stock_id
            WHERE ca.portfolio_id=? AND ca.is_applied=0
              AND ca.type IN ('bonus','split','rights')
              AND ca.ex_date <= CURDATE()
            ORDER BY ca.ex_date ASC
        ", [$pid]);

        json_response(true, '', ['data' => $rows, 'count' => count($rows)]);


    // ─── SUMMARY ──────────────────────────────────────────────────────────────
    case 'ca_summary':
        $pid = ca_portfolio($userId);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $byType = DB::fetchAll("
            SELECT type, COUNT(*) AS count,
                   SUM(CASE WHEN is_applied=1 THEN 1 ELSE 0 END) AS applied
            FROM corporate_actions WHERE portfolio_id=?
            GROUP BY type
        ", [$pid]);

        $dividendTotal = DB::fetchOne("
            SELECT COALESCE(SUM(ca.dividend_per_share * sh.quantity), 0) AS total
            FROM corporate_actions ca
            LEFT JOIN stock_holdings sh ON sh.stock_id=ca.stock_id AND sh.portfolio_id=ca.portfolio_id
            WHERE ca.portfolio_id=? AND ca.type='dividend'
        ", [$pid]);

        $pendingCount = DB::fetchOne("
            SELECT COUNT(*) AS count FROM corporate_actions
            WHERE portfolio_id=? AND is_applied=0 AND type IN ('bonus','split') AND ex_date <= CURDATE()
        ", [$pid]);

        json_response(true, '', [
            'by_type'       => $byType,
            'total_dividend'=> $dividendTotal['total'] ?? 0,
            'pending_count' => $pendingCount['count']  ?? 0,
        ]);


    // ─── HISTORY ──────────────────────────────────────────────────────────────
    case 'ca_history':
        $pid     = ca_portfolio($userId);
        $stockId = (int)($_GET['stock_id'] ?? 0);
        if (!$pid) { json_response(false, 'Invalid portfolio.'); }

        $where  = ['ca.portfolio_id=?', 'ca.is_applied=1'];
        $params = [$pid];
        if ($stockId) { $where[] = 'ca.stock_id=?'; $params[] = $stockId; }

        $rows = DB::fetchAll("
            SELECT ca.*, sm.symbol, sm.company_name
            FROM corporate_actions ca
            JOIN stock_master sm ON sm.id = ca.stock_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY ca.applied_at DESC LIMIT 100
        ", $params);

        json_response(true, '', ['data' => $rows]);


    // ─── DELETE ───────────────────────────────────────────────────────────────
    case 'ca_delete':
        $id  = (int)($_POST['id'] ?? 0);
        $pid = ca_portfolio($userId);
        if (!$id || !$pid) { json_response(false, 'Invalid request.'); }

        $ca = DB::fetchOne("SELECT id, is_applied FROM corporate_actions WHERE id=? AND portfolio_id=?", [$id, $pid]);
        if (!$ca) { json_response(false, 'Not found.'); }
        if ($ca['is_applied']) { json_response(false, 'Cannot delete an already-applied corporate action.'); }

        DB::run("DELETE FROM corporate_actions WHERE id=?", [$id]);
        json_response(true, 'Deleted.');


    default:
        json_response(false, "Unknown corporate action: {$action}", [], 400);
}
