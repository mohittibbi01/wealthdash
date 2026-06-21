<?php
/**
 * WealthDash — Smallcase Portfolio Sync API
 * Task: t120
 * Path: api/smallcase/smallcase.php
 * Actions:
 *   smallcase_list | smallcase_add | smallcase_edit | smallcase_delete | smallcase_summary
 *   smallcase_holding_list | smallcase_holding_save | smallcase_holding_delete | smallcase_holding_bulk_import
 *   smallcase_txn_list | smallcase_txn_add | smallcase_txn_delete
 *   smallcase_rebalance_add | smallcase_rebalance_list
 *   smallcase_price_update | smallcase_calc_xirr
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

// ── LIST SMALLCASES ──────────────────────────────────────────
case 'smallcase_list':
    $rows = $db->prepare("
        SELECT s.*,
               COUNT(h.id) AS stock_count,
               SUM(h.invested_amount) AS calc_invested,
               SUM(h.current_value) AS calc_value
        FROM smallcase_portfolios s
        LEFT JOIN smallcase_holdings h ON h.smallcase_id = s.id
        WHERE s.user_id=? AND s.portfolio_id=?
        GROUP BY s.id
        ORDER BY s.name
    ");
    $rows->execute([$userId, $portfolioId]);
    $list = $rows->fetchAll(PDO::FETCH_ASSOC);

    $totalInvested = array_sum(array_column($list, 'invested_amount'));
    $totalValue    = array_sum(array_column($list, 'calc_value'));

    json_response(true, '', [
        'smallcases'     => $list,
        'total_invested' => $totalInvested,
        'total_value'    => $totalValue ?: $totalInvested,
        'total_gain'     => ($totalValue ?: $totalInvested) - $totalInvested,
    ]);
    break;

// ── SUMMARY ──────────────────────────────────────────────────
case 'smallcase_summary':
    $stmt = $db->prepare("
        SELECT COUNT(*) AS count,
               SUM(invested_amount) AS invested,
               SUM(current_value) AS value_now,
               SUM(gain_loss) AS gain_loss
        FROM smallcase_portfolios
        WHERE user_id=? AND portfolio_id=? AND is_active=1
    ");
    $stmt->execute([$userId, $portfolioId]);
    json_response(true, '', $stmt->fetch(PDO::FETCH_ASSOC));
    break;

// ── ADD SMALLCASE ────────────────────────────────────────────
case 'smallcase_add':
    $name       = clean($_POST['name'] ?? '');
    $desc       = clean($_POST['description'] ?? '');
    $strategy   = clean($_POST['strategy_type'] ?? '');
    $manager    = clean($_POST['manager'] ?? '');
    $extId      = clean($_POST['external_id'] ?? '');
    $invested   = (float)($_POST['invested_amount'] ?? 0);
    $subFee     = (float)($_POST['subscription_fee'] ?? 0);
    $feeFreq    = clean($_POST['fee_frequency'] ?? '');
    $notes      = clean($_POST['notes'] ?? '');

    if (!$name) json_response(false, 'Smallcase name required');

    $db->prepare("
        INSERT INTO smallcase_portfolios
            (user_id, portfolio_id, name, description, strategy_type, manager, external_id,
             invested_amount, subscription_fee, fee_frequency, notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([$userId, $portfolioId, $name, $desc ?: null, $strategy ?: null, $manager ?: null,
                 $extId ?: null, $invested, $subFee, $feeFreq ?: null, $notes ?: null]);

    $id = (int)$db->lastInsertId();

    // Auto-create invest transaction if amount given
    if ($invested > 0) {
        $db->prepare("
            INSERT INTO smallcase_transactions (smallcase_id, user_id, txn_type, txn_date, amount, notes)
            VALUES (?,?,'invest',CURDATE(),?,'Initial investment')
        ")->execute([$id, $userId, $invested]);
    }

    json_response(true, 'Smallcase added', ['id' => $id]);
    break;

// ── EDIT SMALLCASE ───────────────────────────────────────────
case 'smallcase_edit':
    $id         = (int)($_POST['id'] ?? 0);
    $name       = clean($_POST['name'] ?? '');
    $desc       = clean($_POST['description'] ?? '');
    $strategy   = clean($_POST['strategy_type'] ?? '');
    $manager    = clean($_POST['manager'] ?? '');
    $extId      = clean($_POST['external_id'] ?? '');
    $invested   = (float)($_POST['invested_amount'] ?? 0);
    $subFee     = (float)($_POST['subscription_fee'] ?? 0);
    $feeFreq    = clean($_POST['fee_frequency'] ?? '');
    $lastReb    = clean($_POST['last_rebalanced'] ?? '');
    $nextReb    = clean($_POST['next_rebalance'] ?? '');
    $notes      = clean($_POST['notes'] ?? '');
    $isActive   = (int)($_POST['is_active'] ?? 1);

    if (!$id || !$name) json_response(false, 'ID and name required');

    $db->prepare("
        UPDATE smallcase_portfolios SET
            name=?, description=?, strategy_type=?, manager=?, external_id=?,
            invested_amount=?, subscription_fee=?, fee_frequency=?,
            last_rebalanced=?, next_rebalance=?, is_active=?, notes=?, updated_at=NOW()
        WHERE id=? AND user_id=? AND portfolio_id=?
    ")->execute([$name, $desc ?: null, $strategy ?: null, $manager ?: null, $extId ?: null,
                 $invested, $subFee, $feeFreq ?: null, $lastReb ?: null, $nextReb ?: null,
                 $isActive, $notes ?: null, $id, $userId, $portfolioId]);

    json_response(true, 'Updated');
    break;

// ── DELETE SMALLCASE ─────────────────────────────────────────
case 'smallcase_delete':
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_response(false, 'ID required');
    $db->prepare("DELETE FROM smallcase_rebalance_history WHERE smallcase_id=? AND user_id=?")->execute([$id, $userId]);
    $db->prepare("DELETE FROM smallcase_transactions WHERE smallcase_id=? AND user_id=?")->execute([$id, $userId]);
    $db->prepare("DELETE FROM smallcase_holdings WHERE smallcase_id=? AND user_id=?")->execute([$id, $userId]);
    $db->prepare("DELETE FROM smallcase_portfolios WHERE id=? AND user_id=? AND portfolio_id=?")->execute([$id, $userId, $portfolioId]);
    json_response(true, 'Smallcase deleted');
    break;

// ── HOLDING LIST ─────────────────────────────────────────────
case 'smallcase_holding_list':
    $scId = (int)($_GET['smallcase_id'] ?? 0);
    if (!$scId) json_response(false, 'smallcase_id required');

    $rows = $db->prepare("
        SELECT * FROM smallcase_holdings
        WHERE smallcase_id=? AND user_id=?
        ORDER BY weight_pct DESC, invested_amount DESC
    ");
    $rows->execute([$scId, $userId]);
    $list = $rows->fetchAll(PDO::FETCH_ASSOC);

    $totalInvested = array_sum(array_column($list, 'invested_amount'));
    $totalValue    = array_sum(array_column($list, 'current_value'));

    json_response(true, '', [
        'holdings'       => $list,
        'total_invested' => $totalInvested,
        'total_value'    => $totalValue ?: $totalInvested,
    ]);
    break;

// ── HOLDING SAVE (add/edit individual stock) ─────────────────
case 'smallcase_holding_save':
    $holdingId     = (int)($_POST['id'] ?? 0);
    $scId          = (int)($_POST['smallcase_id'] ?? 0);
    $symbol        = strtoupper(clean($_POST['symbol'] ?? ''));
    $companyName   = clean($_POST['company_name'] ?? '');
    $exchange      = clean($_POST['exchange'] ?? 'NSE');
    $isin          = clean($_POST['isin'] ?? '');
    $quantity      = (float)($_POST['quantity'] ?? 0);
    $avgBuyPrice   = (float)($_POST['avg_buy_price'] ?? 0);
    $weightPct     = (float)($_POST['weight_pct'] ?? 0);
    $targetWeight  = (float)($_POST['target_weight_pct'] ?? 0);
    $sector        = clean($_POST['sector'] ?? '');
    $curPrice      = (float)($_POST['current_price'] ?? 0);

    if (!$scId || !$symbol || $quantity <= 0 || $avgBuyPrice <= 0) {
        json_response(false, 'smallcase_id, symbol, quantity, avg_buy_price required');
    }

    $investedAmount = round($quantity * $avgBuyPrice, 2);
    $curValue       = $curPrice > 0 ? round($quantity * $curPrice, 2) : null;

    if ($holdingId) {
        $db->prepare("
            UPDATE smallcase_holdings SET
                symbol=?, company_name=?, exchange=?, isin=?, quantity=?, avg_buy_price=?,
                invested_amount=?, current_price=?, current_value=?,
                weight_pct=?, target_weight_pct=?, sector=?, updated_at=NOW()
            WHERE id=? AND user_id=? AND smallcase_id=?
        ")->execute([$symbol, $companyName, $exchange, $isin ?: null, $quantity, $avgBuyPrice,
                     $investedAmount, $curPrice ?: null, $curValue, $weightPct ?: null,
                     $targetWeight ?: null, $sector ?: null, $holdingId, $userId, $scId]);
    } else {
        $db->prepare("
            INSERT INTO smallcase_holdings
                (smallcase_id, user_id, symbol, company_name, exchange, isin, quantity, avg_buy_price,
                 invested_amount, current_price, current_value, weight_pct, target_weight_pct, sector)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([$scId, $userId, $symbol, $companyName, $exchange, $isin ?: null, $quantity,
                     $avgBuyPrice, $investedAmount, $curPrice ?: null, $curValue,
                     $weightPct ?: null, $targetWeight ?: null, $sector ?: null]);
    }

    _sc_recalc_portfolio($db, $scId, $userId);
    json_response(true, 'Holding saved');
    break;

// ── HOLDING DELETE ───────────────────────────────────────────
case 'smallcase_holding_delete':
    $holdingId = (int)($_POST['id'] ?? 0);
    $scId      = (int)($_POST['smallcase_id'] ?? 0);
    if (!$holdingId) json_response(false, 'ID required');
    $db->prepare("DELETE FROM smallcase_holdings WHERE id=? AND user_id=?")->execute([$holdingId, $userId]);
    if ($scId) _sc_recalc_portfolio($db, $scId, $userId);
    json_response(true, 'Stock removed');
    break;

// ── BULK IMPORT HOLDINGS (CSV / paste) ──────────────────────
case 'smallcase_holding_bulk_import':
    $scId = (int)($_POST['smallcase_id'] ?? 0);
    $rows = json_decode($_POST['rows'] ?? '[]', true);

    if (!$scId || empty($rows)) json_response(false, 'smallcase_id and rows required');

    $ins = $db->prepare("
        INSERT INTO smallcase_holdings
            (smallcase_id, user_id, symbol, company_name, exchange, quantity, avg_buy_price,
             invested_amount, weight_pct, sector)
        VALUES (?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            quantity=VALUES(quantity), avg_buy_price=VALUES(avg_buy_price),
            invested_amount=VALUES(invested_amount), updated_at=NOW()
    ");

    $saved = 0;
    foreach ($rows as $row) {
        $sym      = strtoupper(trim($row['symbol'] ?? ''));
        $qty      = (float)($row['quantity'] ?? 0);
        $avgPrice = (float)($row['avg_buy_price'] ?? 0);
        if (!$sym || $qty <= 0 || $avgPrice <= 0) continue;

        $ins->execute([
            $scId, $userId, $sym,
            clean($row['company_name'] ?? $sym),
            clean($row['exchange'] ?? 'NSE'),
            $qty, $avgPrice, round($qty * $avgPrice, 2),
            (float)($row['weight_pct'] ?? 0) ?: null,
            clean($row['sector'] ?? '') ?: null,
        ]);
        $saved++;
    }

    _sc_recalc_portfolio($db, $scId, $userId);
    json_response(true, "$saved stocks imported");
    break;

// ── TRANSACTION LIST ─────────────────────────────────────────
case 'smallcase_txn_list':
    $scId = (int)($_GET['smallcase_id'] ?? 0);
    $where  = "t.user_id=?";
    $params = [$userId];
    if ($scId) { $where .= " AND t.smallcase_id=?"; $params[] = $scId; }

    $rows = $db->prepare("
        SELECT t.*, s.name AS sc_name
        FROM smallcase_transactions t
        JOIN smallcase_portfolios s ON s.id = t.smallcase_id
        WHERE $where ORDER BY t.txn_date DESC LIMIT 200
    ");
    $rows->execute($params);
    $list = $rows->fetchAll(PDO::FETCH_ASSOC);

    $totalInvested  = array_sum(array_map(fn($r) => $r['txn_type'] === 'invest'  ? (float)$r['amount'] : 0, $list));
    $totalRedeemed  = array_sum(array_map(fn($r) => $r['txn_type'] === 'redeem'  ? (float)$r['amount'] : 0, $list));

    json_response(true, '', [
        'transactions'   => $list,
        'total_invested' => $totalInvested,
        'total_redeemed' => $totalRedeemed,
    ]);
    break;

// ── TRANSACTION ADD ──────────────────────────────────────────
case 'smallcase_txn_add':
    $scId    = (int)($_POST['smallcase_id'] ?? 0);
    $txnType = clean($_POST['txn_type'] ?? 'invest');
    $txnDate = clean($_POST['txn_date'] ?? date('Y-m-d'));
    $amount  = (float)($_POST['amount'] ?? 0);
    $notes   = clean($_POST['notes'] ?? '');

    if (!$scId || $amount <= 0) json_response(false, 'smallcase_id and amount required');

    $db->prepare("
        INSERT INTO smallcase_transactions (smallcase_id, user_id, txn_type, txn_date, amount, notes)
        VALUES (?,?,?,?,?,?)
    ")->execute([$scId, $userId, $txnType, $txnDate, $amount, $notes ?: null]);

    // Update invested_amount in portfolio for invest/redeem
    if (in_array($txnType, ['invest', 'redeem'])) {
        $sign = $txnType === 'invest' ? '+' : '-';
        $db->prepare("
            UPDATE smallcase_portfolios SET invested_amount = invested_amount $sign ?, updated_at=NOW()
            WHERE id=? AND user_id=?
        ")->execute([$amount, $scId, $userId]);
    }

    json_response(true, 'Transaction added');
    break;

// ── TRANSACTION DELETE ───────────────────────────────────────
case 'smallcase_txn_delete':
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_response(false, 'ID required');
    $db->prepare("DELETE FROM smallcase_transactions WHERE id=? AND user_id=?")->execute([$id, $userId]);
    json_response(true, 'Deleted');
    break;

// ── REBALANCE ADD ────────────────────────────────────────────
case 'smallcase_rebalance_add':
    $scId       = (int)($_POST['smallcase_id'] ?? 0);
    $rebDate    = clean($_POST['rebalance_date'] ?? date('Y-m-d'));
    $reason     = clean($_POST['reason'] ?? '');
    $added      = clean($_POST['stocks_added'] ?? '');
    $removed    = clean($_POST['stocks_removed'] ?? '');
    $changed    = clean($_POST['stocks_changed'] ?? '');
    $portValue  = (float)($_POST['portfolio_value'] ?? 0);
    $notes      = clean($_POST['notes'] ?? '');

    if (!$scId || !$rebDate) json_response(false, 'smallcase_id and date required');

    $db->prepare("
        INSERT INTO smallcase_rebalance_history
            (smallcase_id, user_id, rebalance_date, reason, stocks_added, stocks_removed, stocks_changed, portfolio_value, notes)
        VALUES (?,?,?,?,?,?,?,?,?)
    ")->execute([$scId, $userId, $rebDate, $reason ?: null, $added ?: null, $removed ?: null,
                 $changed ?: null, $portValue ?: null, $notes ?: null]);

    // Update last_rebalanced in portfolio
    $db->prepare("
        UPDATE smallcase_portfolios SET last_rebalanced=?, updated_at=NOW()
        WHERE id=? AND user_id=?
    ")->execute([$rebDate, $scId, $userId]);

    json_response(true, 'Rebalance recorded');
    break;

// ── REBALANCE LIST ───────────────────────────────────────────
case 'smallcase_rebalance_list':
    $scId = (int)($_GET['smallcase_id'] ?? 0);
    if (!$scId) json_response(false, 'smallcase_id required');
    $rows = $db->prepare("
        SELECT * FROM smallcase_rebalance_history
        WHERE smallcase_id=? AND user_id=?
        ORDER BY rebalance_date DESC LIMIT 50
    ");
    $rows->execute([$scId, $userId]);
    json_response(true, '', ['history' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
    break;

// ── PRICE UPDATE (bulk) ──────────────────────────────────────
case 'smallcase_price_update':
    $scId   = (int)($_POST['smallcase_id'] ?? 0);
    $prices = json_decode($_POST['prices'] ?? '[]', true); // [{symbol, price}]

    if (!$scId || empty($prices)) json_response(false, 'smallcase_id and prices required');

    $upd = $db->prepare("
        UPDATE smallcase_holdings SET
            current_price=?, current_value=ROUND(quantity*?,2),
            last_price_date=CURDATE(), updated_at=NOW()
        WHERE smallcase_id=? AND user_id=? AND symbol=?
    ");

    $updated = 0;
    foreach ($prices as $p) {
        $sym   = strtoupper(trim($p['symbol'] ?? ''));
        $price = (float)($p['price'] ?? 0);
        if (!$sym || $price <= 0) continue;
        $upd->execute([$price, $price, $scId, $userId, $sym]);
        $updated++;
    }

    _sc_recalc_portfolio($db, $scId, $userId);
    json_response(true, "$updated prices updated");
    break;

// ── CALC XIRR ────────────────────────────────────────────────
case 'smallcase_calc_xirr':
    $scId = (int)($_GET['smallcase_id'] ?? 0);
    if (!$scId) json_response(false, 'smallcase_id required');

    $txns = $db->prepare("
        SELECT txn_date, amount, txn_type
        FROM smallcase_transactions
        WHERE smallcase_id=? AND user_id=? AND txn_type IN('invest','redeem')
        ORDER BY txn_date
    ");
    $txns->execute([$scId, $userId]);
    $txnList = $txns->fetchAll(PDO::FETCH_ASSOC);

    $sc = $db->prepare("SELECT current_value, invested_amount FROM smallcase_portfolios WHERE id=? AND user_id=?");
    $sc->execute([$scId, $userId]);
    $scRow = $sc->fetch(PDO::FETCH_ASSOC);

    if (!$scRow || empty($txnList)) {
        json_response(true, 'Insufficient data for XIRR', ['xirr' => null]);
    }

    // Build cashflows
    $cashflows = [];
    foreach ($txnList as $t) {
        $cashflows[] = [
            'date'   => $t['txn_date'],
            'amount' => $t['txn_type'] === 'invest' ? -(float)$t['amount'] : (float)$t['amount'],
        ];
    }
    // Final value as positive cashflow today
    $cashflows[] = ['date' => date('Y-m-d'), 'amount' => (float)($scRow['current_value'] ?: $scRow['invested_amount'])];

    $xirr = _sc_xirr($cashflows);
    if ($xirr !== null) {
        $db->prepare("UPDATE smallcase_portfolios SET xirr=?, updated_at=NOW() WHERE id=? AND user_id=?")
           ->execute([round($xirr * 100, 4), $scId, $userId]);
    }

    json_response(true, '', ['xirr' => $xirr !== null ? round($xirr * 100, 2) : null]);
    break;

default:
    json_response(false, "Unknown action: $action");
}

// ── Helpers ───────────────────────────────────────────────────
function _sc_recalc_portfolio(PDO $db, int $scId, int $userId): void {
    $stmt = $db->prepare("
        SELECT SUM(invested_amount) AS invested, SUM(current_value) AS value_now
        FROM smallcase_holdings WHERE smallcase_id=? AND user_id=?
    ");
    $stmt->execute([$scId, $userId]);
    $agg = $stmt->fetch(PDO::FETCH_ASSOC);

    $invested = (float)($agg['invested'] ?? 0);
    $value    = (float)($agg['value_now'] ?? 0);
    $gl       = $value > 0 ? $value - $invested : null;
    $glPct    = ($invested > 0 && $gl !== null) ? round($gl / $invested * 100, 4) : null;

    $db->prepare("
        UPDATE smallcase_portfolios SET
            current_value=?, gain_loss=?, gain_loss_pct=?, updated_at=NOW()
        WHERE id=? AND user_id=?
    ")->execute([$value > 0 ? $value : null, $gl, $glPct, $scId, $userId]);
}

function _sc_xirr(array $cashflows, float $guess = 0.1, int $maxIter = 100): ?float {
    if (count($cashflows) < 2) return null;
    $baseDate = strtotime($cashflows[0]['date']);

    $npv = function (float $rate) use ($cashflows, $baseDate): float {
        $sum = 0;
        foreach ($cashflows as $cf) {
            $t    = (strtotime($cf['date']) - $baseDate) / 86400 / 365;
            $sum += $cf['amount'] / pow(1 + $rate, $t);
        }
        return $sum;
    };

    $rate = $guess;
    for ($i = 0; $i < $maxIter; $i++) {
        $f  = $npv($rate);
        $f2 = $npv($rate + 0.0001);
        $df = ($f2 - $f) / 0.0001;
        if (abs($df) < 1e-10) break;
        $newRate = $rate - $f / $df;
        if (abs($newRate - $rate) < 0.0000001) return $newRate;
        $rate = $newRate;
        if ($rate < -0.9999) $rate = -0.9999;
    }
    return abs($npv($rate)) < 0.01 ? $rate : null;
}
