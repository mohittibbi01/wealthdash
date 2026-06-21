<?php
/**
 * WealthDash — REITs & InvITs API
 * Task: t115
 * Path: api/reits/reits.php
 * Actions: reits_list | reits_add | reits_edit | reits_delete | reits_summary
 *          reits_txn_add | reits_txn_list | reits_txn_delete
 *          reits_dist_add | reits_dist_list | reits_dist_delete
 *          reits_price_refresh | reits_master_search
 */
defined('WEALTHDASH') or die();

$db          = DB::conn();
$userId      = (int)$_SESSION['user_id'];
$portfolioId = (int)($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ?? 0);
if (!$portfolioId) {
    $r = $db->prepare("SELECT id FROM portfolios WHERE user_id=? AND is_default=1 LIMIT 1");
    $r->execute([$userId]);
    $portfolioId = (int)($r->fetchColumn() ?: 0);
}

switch ($action) {

// ── LIST ─────────────────────────────────────────────────────
case 'reits_list':
    $type   = clean($_GET['trust_type'] ?? '');
    $where  = "h.user_id=? AND h.portfolio_id=?";
    $params = [$userId, $portfolioId];
    if ($type) { $where .= " AND h.trust_type=?"; $params[] = $type; }

    $rows = $db->prepare("
        SELECT h.*,
               COALESCE(h.current_value, h.units * h.avg_buy_price) AS value_now,
               SUM(d.total_amount) AS total_distributions
        FROM reits_invits h
        LEFT JOIN reits_invits_distributions d ON d.holding_id = h.id
        WHERE $where
        GROUP BY h.id
        ORDER BY h.trust_type, h.name
    ");
    $rows->execute($params);
    $holdings = $rows->fetchAll(PDO::FETCH_ASSOC);

    $totalInvested = array_sum(array_column($holdings, 'total_invested'));
    $totalValue    = array_sum(array_column($holdings, 'current_value') ?: array_column($holdings, 'value_now'));
    $totalDist     = array_sum(array_column($holdings, 'total_distributions'));

    json_response(true, '', [
        'holdings'       => $holdings,
        'total_invested' => $totalInvested,
        'total_value'    => $totalValue,
        'total_distributions' => $totalDist,
        'gain_loss'      => $totalValue - $totalInvested,
    ]);
    break;

// ── SUMMARY ──────────────────────────────────────────────────
case 'reits_summary':
    $stmt = $db->prepare("
        SELECT
            trust_type,
            COUNT(*) AS count,
            SUM(total_invested) AS invested,
            SUM(current_value) AS value_now
        FROM reits_invits
        WHERE user_id=? AND portfolio_id=?
        GROUP BY trust_type
    ");
    $stmt->execute([$userId, $portfolioId]);
    $byType = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $distStmt = $db->prepare("
        SELECT SUM(d.total_amount) AS total_dist
        FROM reits_invits_distributions d
        JOIN reits_invits h ON h.id = d.holding_id
        WHERE h.user_id=? AND h.portfolio_id=?
    ");
    $distStmt->execute([$userId, $portfolioId]);
    $dist = $distStmt->fetchColumn() ?: 0;

    json_response(true, '', ['by_type' => $byType, 'total_distributions' => $dist]);
    break;

// ── ADD HOLDING ──────────────────────────────────────────────
case 'reits_add':
    $symbol    = strtoupper(clean($_POST['symbol'] ?? ''));
    $name      = clean($_POST['name'] ?? '');
    $trustType = clean($_POST['trust_type'] ?? 'REIT');
    $exchange  = clean($_POST['exchange'] ?? 'NSE');
    $isin      = clean($_POST['isin'] ?? '');
    $units     = (float)($_POST['units'] ?? 0);
    $avgPrice  = (float)($_POST['avg_buy_price'] ?? 0);
    $notes     = clean($_POST['notes'] ?? '');

    if (!$symbol || !$name || $units <= 0 || $avgPrice <= 0) {
        json_response(false, 'Symbol, name, units aur price required hain');
    }

    $totalInvested = round($units * $avgPrice, 2);

    // Check duplicate
    $dup = $db->prepare("SELECT id FROM reits_invits WHERE user_id=? AND portfolio_id=? AND symbol=?");
    $dup->execute([$userId, $portfolioId, $symbol]);
    if ($dup->fetchColumn()) {
        json_response(false, "$symbol already added in this portfolio");
    }

    $ins = $db->prepare("
        INSERT INTO reits_invits
            (user_id, portfolio_id, symbol, name, trust_type, exchange, isin, units, avg_buy_price, total_invested, notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");
    $ins->execute([$userId, $portfolioId, $symbol, $name, $trustType, $exchange, $isin ?: null, $units, $avgPrice, $totalInvested, $notes ?: null]);
    $holdingId = (int)$db->lastInsertId();

    // Auto-create BUY transaction
    $db->prepare("
        INSERT INTO reits_invits_transactions
            (holding_id, user_id, portfolio_id, symbol, transaction_type, txn_date, units, price, amount, notes)
        VALUES (?,?,?,?,'BUY',CURDATE(),?,?,?,?)
    ")->execute([$holdingId, $userId, $portfolioId, $symbol, $units, $avgPrice, $totalInvested, 'Initial holding entry']);

    json_response(true, "$symbol added successfully", ['id' => $holdingId]);
    break;

// ── EDIT HOLDING ─────────────────────────────────────────────
case 'reits_edit':
    $id        = (int)($_POST['id'] ?? 0);
    $units     = (float)($_POST['units'] ?? 0);
    $avgPrice  = (float)($_POST['avg_buy_price'] ?? 0);
    $notes     = clean($_POST['notes'] ?? '');
    $curPrice  = (float)($_POST['current_price'] ?? 0);

    if (!$id || $units <= 0 || $avgPrice <= 0) {
        json_response(false, 'Invalid data');
    }

    $totalInvested = round($units * $avgPrice, 2);
    $curValue      = $curPrice > 0 ? round($units * $curPrice, 2) : null;
    $gainLoss      = $curValue !== null ? $curValue - $totalInvested : null;
    $gainPct       = ($totalInvested > 0 && $gainLoss !== null) ? round($gainLoss / $totalInvested * 100, 4) : null;

    $db->prepare("
        UPDATE reits_invits SET
            units=?, avg_buy_price=?, total_invested=?,
            current_price=?, current_value=?, gain_loss=?, gain_loss_pct=?,
            notes=?, updated_at=NOW()
        WHERE id=? AND user_id=? AND portfolio_id=?
    ")->execute([$units, $avgPrice, $totalInvested, $curPrice ?: null, $curValue, $gainLoss, $gainPct, $notes ?: null, $id, $userId, $portfolioId]);

    json_response(true, 'Holding updated');
    break;

// ── DELETE HOLDING ───────────────────────────────────────────
case 'reits_delete':
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_response(false, 'ID required');

    $db->prepare("DELETE FROM reits_invits_distributions WHERE holding_id=? AND user_id=?")->execute([$id, $userId]);
    $db->prepare("DELETE FROM reits_invits_transactions WHERE holding_id=? AND user_id=?")->execute([$id, $userId]);
    $db->prepare("DELETE FROM reits_invits WHERE id=? AND user_id=? AND portfolio_id=?")->execute([$id, $userId, $portfolioId]);
    json_response(true, 'Holding deleted');
    break;

// ── TRANSACTION ADD ──────────────────────────────────────────
case 'reits_txn_add':
    $holdingId = (int)($_POST['holding_id'] ?? 0);
    $txnType   = clean($_POST['transaction_type'] ?? 'BUY');
    $txnDate   = clean($_POST['txn_date'] ?? date('Y-m-d'));
    $units     = (float)($_POST['units'] ?? 0);
    $price     = (float)($_POST['price'] ?? 0);
    $brokerage = (float)($_POST['brokerage'] ?? 0);
    $notes     = clean($_POST['notes'] ?? '');

    if (!$holdingId || $units <= 0 || $price <= 0) {
        json_response(false, 'Holding, units aur price required');
    }

    $amount = round($units * $price, 2);

    $db->prepare("
        INSERT INTO reits_invits_transactions
            (holding_id, user_id, portfolio_id, symbol, transaction_type, txn_date, units, price, amount, brokerage, notes)
        SELECT ?, ?, portfolio_id, symbol, ?, ?, ?, ?, ?, ?, ?
        FROM reits_invits WHERE id=? AND user_id=?
    ")->execute([$holdingId, $userId, $txnType, $txnDate, $units, $price, $amount, $brokerage, $notes ?: null, $holdingId, $userId]);

    // Recalc units and avg_price
    _reits_recalc_holding($db, $holdingId, $userId);

    json_response(true, 'Transaction added', ['amount' => $amount]);
    break;

// ── TRANSACTION LIST ─────────────────────────────────────────
case 'reits_txn_list':
    $holdingId = (int)($_GET['holding_id'] ?? 0);
    $where     = "t.user_id=?";
    $params    = [$userId];
    if ($holdingId) { $where .= " AND t.holding_id=?"; $params[] = $holdingId; }

    $rows = $db->prepare("
        SELECT t.*, h.name AS holding_name
        FROM reits_invits_transactions t
        JOIN reits_invits h ON h.id = t.holding_id
        WHERE $where ORDER BY t.txn_date DESC LIMIT 200
    ");
    $rows->execute($params);
    json_response(true, '', ['transactions' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
    break;

// ── TRANSACTION DELETE ───────────────────────────────────────
case 'reits_txn_delete':
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_response(false, 'ID required');
    $row = $db->prepare("SELECT holding_id FROM reits_invits_transactions WHERE id=? AND user_id=?");
    $row->execute([$id, $userId]);
    $hid = (int)($row->fetchColumn() ?: 0);
    $db->prepare("DELETE FROM reits_invits_transactions WHERE id=? AND user_id=?")->execute([$id, $userId]);
    if ($hid) _reits_recalc_holding($db, $hid, $userId);
    json_response(true, 'Transaction deleted');
    break;

// ── DISTRIBUTION ADD ─────────────────────────────────────────
case 'reits_dist_add':
    $holdingId    = (int)($_POST['holding_id'] ?? 0);
    $distType     = clean($_POST['dist_type'] ?? 'dividend');
    $exDate       = clean($_POST['ex_date'] ?? '');
    $payDate      = clean($_POST['pay_date'] ?? '');
    $perUnit      = (float)($_POST['per_unit_amount'] ?? 0);
    $unitsHeld    = (float)($_POST['units_held'] ?? 0);
    $tds          = (float)($_POST['tds_deducted'] ?? 0);
    $notes        = clean($_POST['notes'] ?? '');

    if (!$holdingId || !$exDate || $perUnit <= 0 || $unitsHeld <= 0) {
        json_response(false, 'Required fields missing');
    }

    $totalAmount = round($perUnit * $unitsHeld, 2);
    $netAmount   = round($totalAmount - $tds, 2);

    $sym = $db->prepare("SELECT symbol FROM reits_invits WHERE id=? AND user_id=?");
    $sym->execute([$holdingId, $userId]);
    $symbol = $sym->fetchColumn();

    $db->prepare("
        INSERT INTO reits_invits_distributions
            (holding_id, user_id, symbol, dist_type, ex_date, pay_date, per_unit_amount, units_held, total_amount, tds_deducted, net_amount, notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([$holdingId, $userId, $symbol, $distType, $exDate, $payDate ?: null, $perUnit, $unitsHeld, $totalAmount, $tds, $netAmount, $notes ?: null]);

    json_response(true, 'Distribution added', ['total' => $totalAmount, 'net' => $netAmount]);
    break;

// ── DISTRIBUTION LIST ────────────────────────────────────────
case 'reits_dist_list':
    $holdingId = (int)($_GET['holding_id'] ?? 0);
    $where     = "d.user_id=?";
    $params    = [$userId];
    if ($holdingId) { $where .= " AND d.holding_id=?"; $params[] = $holdingId; }

    $rows = $db->prepare("
        SELECT d.*, h.name AS holding_name, h.trust_type
        FROM reits_invits_distributions d
        JOIN reits_invits h ON h.id = d.holding_id
        WHERE $where ORDER BY d.ex_date DESC LIMIT 200
    ");
    $rows->execute($params);
    $list = $rows->fetchAll(PDO::FETCH_ASSOC);

    $totalDist = array_sum(array_column($list, 'total_amount'));
    $totalNet  = array_sum(array_column($list, 'net_amount'));
    json_response(true, '', ['distributions' => $list, 'total_gross' => $totalDist, 'total_net' => $totalNet]);
    break;

// ── DISTRIBUTION DELETE ──────────────────────────────────────
case 'reits_dist_delete':
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_response(false, 'ID required');
    $db->prepare("DELETE FROM reits_invits_distributions WHERE id=? AND user_id=?")->execute([$id, $userId]);
    json_response(true, 'Distribution deleted');
    break;

// ── PRICE REFRESH (via NSE quote if available) ───────────────
case 'reits_price_refresh':
    $holdingId = (int)($_POST['id'] ?? 0);
    $price     = (float)($_POST['price'] ?? 0);
    $priceDate = clean($_POST['price_date'] ?? date('Y-m-d'));

    if (!$holdingId || $price <= 0) json_response(false, 'ID and price required');

    $h = $db->prepare("SELECT units, total_invested FROM reits_invits WHERE id=? AND user_id=?");
    $h->execute([$holdingId, $userId]);
    $holding = $h->fetch(PDO::FETCH_ASSOC);
    if (!$holding) json_response(false, 'Holding not found');

    $curValue = round((float)$holding['units'] * $price, 2);
    $gainLoss = round($curValue - (float)$holding['total_invested'], 2);
    $gainPct  = (float)$holding['total_invested'] > 0
                ? round($gainLoss / (float)$holding['total_invested'] * 100, 4) : 0;

    $db->prepare("
        UPDATE reits_invits SET
            current_price=?, current_value=?, gain_loss=?, gain_loss_pct=?,
            last_price_date=?, updated_at=NOW()
        WHERE id=? AND user_id=?
    ")->execute([$price, $curValue, $gainLoss, $gainPct, $priceDate, $holdingId, $userId]);

    json_response(true, 'Price updated', [
        'current_value' => $curValue,
        'gain_loss'     => $gainLoss,
        'gain_loss_pct' => $gainPct,
    ]);
    break;

// ── MASTER SEARCH ────────────────────────────────────────────
case 'reits_master_search':
    $q    = clean($_GET['q'] ?? '');
    $type = clean($_GET['trust_type'] ?? '');
    if (strlen($q) < 1) { json_response(true, '', ['results' => []]); }

    $where  = "(symbol LIKE ? OR name LIKE ?)";
    $params = ["%$q%", "%$q%"];
    if ($type) { $where .= " AND trust_type=?"; $params[] = $type; }

    $rows = $db->prepare("SELECT * FROM reits_invits_master WHERE $where AND is_active=1 ORDER BY trust_type, name LIMIT 20");
    $rows->execute($params);
    json_response(true, '', ['results' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
    break;

default:
    json_response(false, "Unknown action: $action");
}

// ── Helper: recalculate holding units & avg price ─────────────
function _reits_recalc_holding(PDO $db, int $holdingId, int $userId): void {
    $stmt = $db->prepare("
        SELECT transaction_type, units, price, amount
        FROM reits_invits_transactions
        WHERE holding_id=? AND user_id=? ORDER BY txn_date, id
    ");
    $stmt->execute([$holdingId, $userId]);
    $txns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalUnits   = 0;
    $totalCost    = 0;

    foreach ($txns as $t) {
        if ($t['transaction_type'] === 'BUY') {
            $totalCost  += (float)$t['amount'];
            $totalUnits += (float)$t['units'];
        } elseif ($t['transaction_type'] === 'SELL') {
            $totalUnits -= (float)$t['units'];
            if ($totalUnits > 0 && $totalCost > 0) {
                $avgBeforeSell = $totalCost / ($totalUnits + (float)$t['units']);
                $totalCost    -= $avgBeforeSell * (float)$t['units'];
            }
        }
    }

    $avgPrice      = $totalUnits > 0 ? round($totalCost / $totalUnits, 4) : 0;
    $totalInvested = round($totalCost, 2);

    $db->prepare("
        UPDATE reits_invits SET units=?, avg_buy_price=?, total_invested=?, updated_at=NOW()
        WHERE id=? AND user_id=?
    ")->execute([round($totalUnits, 4), $avgPrice, $totalInvested, $holdingId, $userId]);
}
