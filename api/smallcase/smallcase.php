<?php
/**
 * WealthDash — Smallcase Portfolio Sync
 * Task: t120
 * Routes: smallcase_list, smallcase_summary, smallcase_add, smallcase_edit,
 *         smallcase_delete, smallcase_holdings, smallcase_sync,
 *         smallcase_add_txn, smallcase_txns, smallcase_performance
 */
defined('WEALTHDASH') or die();

$db     = DB::conn();
$uid    = (int)($_SESSION['user_id'] ?? 0);
$action = $_REQUEST['action'] ?? '';

header('Content-Type: application/json');

if (!$uid) { echo json_encode(['success' => false, 'error' => 'Unauthenticated']); exit; }

/* ── Helpers ──────────────────────────────────────────────── */
function sc_json(mixed $data, bool $ok = true): void {
    echo json_encode(['success' => $ok, 'data' => $data]);
    exit;
}
function sc_err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

/* ── Fetch live stock price (Yahoo) ─────────────────────────  */
function sc_fetch_price(string $symbol): ?float {
    $ySymbol = $symbol . '.NS';
    $url = "https://query1.finance.yahoo.com/v8/finance/chart/{$ySymbol}?interval=1d&range=1d";
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $res  = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http !== 200 || !$res) return null;
    $json  = json_decode($res, true);
    $price = $json['chart']['result'][0]['meta']['regularMarketPrice'] ?? null;
    return $price ? (float)$price : null;
}

/* ── Summary builder ─────────────────────────────────────── */
function sc_portfolio_summary(PDO $db, int $uid): array {
    $st = $db->prepare("
        SELECT p.*,
          (SELECT COUNT(*) FROM smallcase_holdings h WHERE h.portfolio_id = p.id) AS stock_count,
          (SELECT MAX(txn_date) FROM smallcase_transactions t WHERE t.portfolio_id = p.id) AS last_txn_date
        FROM smallcase_portfolios p
        WHERE p.user_id = ? AND p.is_active = 1
        ORDER BY p.current_value DESC
    ");
    $st->execute([$uid]);
    $portfolios = $st->fetchAll(PDO::FETCH_ASSOC);

    $total_invested = 0; $total_current = 0;
    foreach ($portfolios as &$p) {
        $total_invested += (float)$p['invested_amount'];
        $total_current  += (float)$p['current_value'];
        $gain = (float)$p['current_value'] - (float)$p['invested_amount'];
        $p['gain_loss']     = round($gain, 2);
        $p['gain_loss_pct'] = $p['invested_amount'] > 0
            ? round(($gain / (float)$p['invested_amount']) * 100, 2) : 0;
    }
    unset($p);

    return [
        'portfolios'     => $portfolios,
        'total_invested' => round($total_invested, 2),
        'total_current'  => round($total_current, 2),
        'total_gain'     => round($total_current - $total_invested, 2),
        'total_gain_pct' => $total_invested > 0
            ? round((($total_current - $total_invested) / $total_invested) * 100, 2) : 0,
    ];
}

/* ── Route ────────────────────────────────────────────────── */
switch ($action) {

    /* ---- List / summary ------------------------------------ */
    case 'smallcase_list':
    case 'smallcase_summary':
        sc_json(sc_portfolio_summary($db, $uid));

    /* ---- Add portfolio / basket ---------------------------- */
    case 'smallcase_add': {
        $sc_id    = trim($_POST['smallcase_id']  ?? '');
        $name     = trim($_POST['name']          ?? '');
        $pub      = trim($_POST['publisher']     ?? '');
        $stype    = $_POST['strategy_type']      ?? 'model';
        $freq     = $_POST['rebalance_freq']     ?? 'quarterly';
        $min_inv  = (float)($_POST['min_investment'] ?? 0);
        $desc     = trim($_POST['description']   ?? '');
        $invested = (float)($_POST['invested_amount'] ?? 0);

        if (!$sc_id || !$name) sc_err('smallcase_id and name required');

        $valid_types = ['model','thematic','quantamental','sectoral','smart_beta','other'];
        if (!in_array($stype, $valid_types)) $stype = 'model';

        $db->prepare("
            INSERT INTO smallcase_portfolios
              (user_id, smallcase_id, name, publisher, strategy_type,
               rebalance_freq, min_investment, description, invested_amount)
            VALUES (?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE name=VALUES(name), publisher=VALUES(publisher),
              strategy_type=VALUES(strategy_type), rebalance_freq=VALUES(rebalance_freq),
              description=VALUES(description), updated_at=NOW()
        ")->execute([$uid, $sc_id, $name, $pub, $stype, $freq, $min_inv ?: null, $desc, $invested]);

        sc_json(['message' => 'Portfolio added']);
    }

    /* ---- Edit -------------------------------------------- */
    case 'smallcase_edit': {
        $pid      = (int)($_POST['id']           ?? 0);
        $name     = trim($_POST['name']          ?? '');
        $pub      = trim($_POST['publisher']     ?? '');
        $freq     = $_POST['rebalance_freq']     ?? 'quarterly';
        $invested = (float)($_POST['invested_amount'] ?? 0);
        $current  = (float)($_POST['current_value']  ?? 0);
        $desc     = trim($_POST['description']   ?? '');
        $cagr1    = isset($_POST['cagr_1y'])  ? (float)$_POST['cagr_1y']  : null;
        $cagr3    = isset($_POST['cagr_3y'])  ? (float)$_POST['cagr_3y']  : null;
        $vol      = isset($_POST['volatility']) ? (float)$_POST['volatility'] : null;

        if (!$pid || !$name) sc_err('id and name required');

        $db->prepare("
            UPDATE smallcase_portfolios
            SET name=?, publisher=?, rebalance_freq=?, invested_amount=?,
                current_value=?, description=?, cagr_1y=?, cagr_3y=?, volatility=?, updated_at=NOW()
            WHERE id=? AND user_id=?
        ")->execute([$name, $pub, $freq, $invested, $current, $desc, $cagr1, $cagr3, $vol, $pid, $uid]);

        sc_json(['message' => 'Portfolio updated']);
    }

    /* ---- Delete ------------------------------------------- */
    case 'smallcase_delete': {
        $pid = (int)($_POST['id'] ?? 0);
        if (!$pid) sc_err('id required');
        // Soft delete
        $db->prepare("UPDATE smallcase_portfolios SET is_active=0 WHERE id=? AND user_id=?")
           ->execute([$pid, $uid]);
        sc_json(['message' => 'Portfolio removed']);
    }

    /* ---- Holdings for a portfolio -------------------------- */
    case 'smallcase_holdings': {
        $pid = (int)($_GET['portfolio_id'] ?? 0);
        if (!$pid) sc_err('portfolio_id required');

        // Verify ownership
        $chk = $db->prepare("SELECT id FROM smallcase_portfolios WHERE id=? AND user_id=?");
        $chk->execute([$pid, $uid]);
        if (!$chk->fetch()) sc_err('Portfolio not found', 404);

        $st = $db->prepare("SELECT * FROM smallcase_holdings WHERE portfolio_id=? ORDER BY weight_pct DESC");
        $st->execute([$pid]);
        $holdings = $st->fetchAll(PDO::FETCH_ASSOC);

        foreach ($holdings as &$h) {
            $cur = (float)($h['current_price'] ?? $h['avg_price']);
            $h['current_value']  = round($cur * (float)$h['quantity'], 2);
            $h['gain_loss']      = round($h['current_value'] - (float)$h['invested_value'], 2);
            $h['gain_loss_pct']  = $h['invested_value'] > 0
                ? round(($h['gain_loss'] / (float)$h['invested_value']) * 100, 2) : 0;
        }
        unset($h);
        sc_json($holdings);
    }

    /* ---- Sync holdings (manual JSON upload) ---------------- */
    case 'smallcase_sync': {
        $pid      = (int)($_POST['portfolio_id'] ?? 0);
        $raw      = $_POST['holdings_json'] ?? '';
        if (!$pid || !$raw) sc_err('portfolio_id and holdings_json required');

        $chk = $db->prepare("SELECT id FROM smallcase_portfolios WHERE id=? AND user_id=?");
        $chk->execute([$pid, $uid]);
        if (!$chk->fetch()) sc_err('Portfolio not found', 404);

        $holdings = json_decode($raw, true);
        if (!is_array($holdings)) sc_err('Invalid holdings_json');

        $ins = $db->prepare("
            INSERT INTO smallcase_holdings
              (portfolio_id, user_id, symbol, isin, stock_name, quantity, avg_price,
               current_price, invested_value, current_value, weight_pct, last_rebalanced)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              stock_name=VALUES(stock_name), quantity=VALUES(quantity),
              avg_price=VALUES(avg_price), current_price=VALUES(current_price),
              invested_value=VALUES(invested_value), current_value=VALUES(current_value),
              weight_pct=VALUES(weight_pct), last_rebalanced=VALUES(last_rebalanced),
              updated_at=NOW()
        ");

        $total_inv = 0; $total_cur = 0;
        foreach ($holdings as $h) {
            $qty    = (float)($h['quantity']      ?? 0);
            $avg    = (float)($h['avg_price']     ?? 0);
            $cur    = (float)($h['current_price'] ?? $avg);
            $inv    = round($qty * $avg, 2);
            $curVal = round($qty * $cur, 2);
            $ins->execute([
                $pid, $uid,
                $h['symbol']     ?? '',
                $h['isin']       ?? null,
                $h['stock_name'] ?? $h['symbol'] ?? '',
                $qty, $avg, $cur, $inv, $curVal,
                (float)($h['weight_pct'] ?? 0),
                $h['last_rebalanced'] ?? null,
            ]);
            $total_inv += $inv;
            $total_cur += $curVal;
        }

        // Update portfolio totals
        $db->prepare("
            UPDATE smallcase_portfolios
            SET invested_amount=?, current_value=?, last_synced_at=NOW()
            WHERE id=? AND user_id=?
        ")->execute([$total_inv, $total_cur, $pid, $uid]);

        // Snapshot
        $db->prepare("
            INSERT INTO smallcase_value_history (portfolio_id, snap_date, invested, current_value)
            VALUES (?,CURDATE(),?,?)
            ON DUPLICATE KEY UPDATE invested=VALUES(invested), current_value=VALUES(current_value)
        ")->execute([$pid, $total_inv, $total_cur]);

        sc_json(['synced' => count($holdings), 'total_invested' => $total_inv, 'total_current' => $total_cur]);
    }

    /* ---- Add transaction ----------------------------------- */
    case 'smallcase_add_txn': {
        $pid      = (int)($_POST['portfolio_id'] ?? 0);
        $txn_type = $_POST['txn_type']           ?? 'invest';
        $txn_date = $_POST['txn_date']           ?? date('Y-m-d');
        $amount   = (float)($_POST['amount']     ?? 0);
        $notes    = trim($_POST['notes']         ?? '');

        if (!$pid || $amount <= 0) sc_err('portfolio_id and amount required');
        if (!in_array($txn_type, ['invest','sip','rebalance','withdraw','dividend'])) sc_err('Invalid txn_type');

        $chk = $db->prepare("SELECT id FROM smallcase_portfolios WHERE id=? AND user_id=?");
        $chk->execute([$pid, $uid]);
        if (!$chk->fetch()) sc_err('Portfolio not found', 404);

        $db->prepare("
            INSERT INTO smallcase_transactions (portfolio_id, user_id, txn_type, txn_date, amount, notes)
            VALUES (?,?,?,?,?,?)
        ")->execute([$pid, $uid, $txn_type, $txn_date, $amount, $notes]);

        // Recompute invested amount from invest/sip/withdraw txns
        $inv = $db->prepare("
            SELECT SUM(CASE WHEN txn_type IN ('invest','sip') THEN amount
                            WHEN txn_type = 'withdraw' THEN -amount
                            ELSE 0 END)
            FROM smallcase_transactions WHERE portfolio_id=? AND user_id=?
        ");
        $inv->execute([$pid, $uid]);
        $newInv = max(0, (float)$inv->fetchColumn());
        $db->prepare("UPDATE smallcase_portfolios SET invested_amount=? WHERE id=? AND user_id=?")
           ->execute([$newInv, $pid, $uid]);

        sc_json(['message' => 'Transaction added']);
    }

    /* ---- Transaction list ---------------------------------- */
    case 'smallcase_txns': {
        $pid = (int)($_GET['portfolio_id'] ?? 0);
        if (!$pid) sc_err('portfolio_id required');

        $chk = $db->prepare("SELECT id FROM smallcase_portfolios WHERE id=? AND user_id=?");
        $chk->execute([$pid, $uid]);
        if (!$chk->fetch()) sc_err('Portfolio not found', 404);

        $st = $db->prepare("SELECT * FROM smallcase_transactions WHERE portfolio_id=? ORDER BY txn_date DESC LIMIT 200");
        $st->execute([$pid]);
        sc_json($st->fetchAll(PDO::FETCH_ASSOC));
    }

    /* ---- Performance chart data ---------------------------- */
    case 'smallcase_performance': {
        $pid = (int)($_GET['portfolio_id'] ?? 0);
        if (!$pid) sc_err('portfolio_id required');

        $chk = $db->prepare("SELECT id FROM smallcase_portfolios WHERE id=? AND user_id=?");
        $chk->execute([$pid, $uid]);
        if (!$chk->fetch()) sc_err('Portfolio not found', 404);

        $st = $db->prepare("
            SELECT snap_date, invested, current_value,
              ROUND(current_value - invested, 2) AS gain,
              CASE WHEN invested > 0 THEN ROUND((current_value - invested) / invested * 100, 2) ELSE 0 END AS gain_pct
            FROM smallcase_value_history
            WHERE portfolio_id=?
            ORDER BY snap_date
        ");
        $st->execute([$pid]);
        sc_json($st->fetchAll(PDO::FETCH_ASSOC));
    }

    default:
        sc_err("Unknown action: $action", 404);
}
