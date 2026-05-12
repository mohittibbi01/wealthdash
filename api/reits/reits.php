<?php
/**
 * WealthDash — REITs & InvITs Portfolio
 * Task: t115
 * Routes: reit_list, reit_summary, reit_add_holding, reit_edit_holding,
 *         reit_delete_holding, reit_add_txn, reit_delete_txn, reit_txns,
 *         reit_add_dist, reit_distributions, reit_master_list, reit_update_price
 */
defined('WEALTHDASH') or die();

$db     = DB::conn();
$uid    = (int)($_SESSION['user_id'] ?? 0);
$action = $_REQUEST['action'] ?? '';

header('Content-Type: application/json');

if (!$uid) { echo json_encode(['success' => false, 'error' => 'Unauthenticated']); exit; }

/* ── Helpers ──────────────────────────────────────────────── */
function reit_json(mixed $data, bool $ok = true): void {
    echo json_encode(['success' => $ok, 'data' => $data]);
    exit;
}
function reit_err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

/* ── Fetch live price (Yahoo Finance fallback) ────────────── */
function fetch_reit_price(string $symbol): ?float {
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
    $json = json_decode($res, true);
    $price = $json['chart']['result'][0]['meta']['regularMarketPrice'] ?? null;
    return $price ? (float)$price : null;
}

/* ── Portfolio summary helpers ────────────────────────────── */
function reit_portfolio_summary(PDO $db, int $uid): array {
    $holdings = $db->prepare("
        SELECT h.*, m.name, m.symbol, m.type, m.distribution_freq,
               p.price AS current_price
        FROM reit_invit_holdings h
        JOIN reit_invit_master m ON m.id = h.trust_id
        LEFT JOIN reit_invit_prices p ON p.trust_id = h.trust_id
        WHERE h.user_id = ? AND h.units > 0
        ORDER BY h.total_invested DESC
    ");
    $holdings->execute([$uid]);
    $rows = $holdings->fetchAll(PDO::FETCH_ASSOC);

    $totalInvested = 0; $totalCurrent = 0; $totalGain = 0;
    $byType = ['REIT' => ['invested' => 0, 'current' => 0], 'InvIT' => ['invested' => 0, 'current' => 0]];

    foreach ($rows as &$r) {
        $cur = (float)($r['current_price'] ?? $r['avg_cost']);
        $curr_val = $cur * (float)$r['units'];
        $gain     = $curr_val - (float)$r['total_invested'];
        $gain_pct = $r['total_invested'] > 0 ? ($gain / (float)$r['total_invested']) * 100 : 0;

        $r['current_price']  = $cur;
        $r['current_value']  = round($curr_val, 2);
        $r['gain_loss']      = round($gain, 2);
        $r['gain_loss_pct']  = round($gain_pct, 2);

        $totalInvested += (float)$r['total_invested'];
        $totalCurrent  += $curr_val;
        $byType[$r['type']]['invested'] += (float)$r['total_invested'];
        $byType[$r['type']]['current']  += $curr_val;
    }
    unset($r);

    $totalGain = $totalCurrent - $totalInvested;
    return [
        'holdings'       => $rows,
        'total_invested' => round($totalInvested, 2),
        'total_current'  => round($totalCurrent, 2),
        'total_gain'     => round($totalGain, 2),
        'total_gain_pct' => $totalInvested > 0 ? round(($totalGain / $totalInvested) * 100, 2) : 0,
        'by_type'        => $byType,
    ];
}

/* ── Route ────────────────────────────────────────────────── */
switch ($action) {

    /* ---- Master list ---------------------------------------- */
    case 'reit_master_list':
        $type = $_GET['type'] ?? null;
        $sql  = "SELECT * FROM reit_invit_master WHERE is_active = 1";
        $params = [];
        if ($type && in_array($type, ['REIT', 'InvIT'])) {
            $sql .= " AND type = ?"; $params[] = $type;
        }
        $sql .= " ORDER BY type, name";
        $st = $db->prepare($sql); $st->execute($params);
        reit_json($st->fetchAll(PDO::FETCH_ASSOC));

    /* ---- Holdings list + summary --------------------------- */
    case 'reit_list':
    case 'reit_summary':
        reit_json(reit_portfolio_summary($db, $uid));

    /* ---- Add / edit holding -------------------------------- */
    case 'reit_add_holding':
    case 'reit_edit_holding': {
        $trust_id      = (int)($_POST['trust_id']      ?? 0);
        $units         = (float)($_POST['units']        ?? 0);
        $avg_cost      = (float)($_POST['avg_cost']     ?? 0);
        $total_invested = (float)($_POST['total_invested'] ?? ($units * $avg_cost));
        $broker        = trim($_POST['broker']         ?? '');
        $demat         = trim($_POST['demat_account']  ?? '');
        $notes         = trim($_POST['notes']          ?? '');

        if (!$trust_id || $units <= 0 || $avg_cost <= 0) reit_err('trust_id, units, avg_cost required');

        if ($action === 'reit_add_holding') {
            $db->prepare("
                INSERT INTO reit_invit_holdings (user_id, trust_id, units, avg_cost, total_invested, broker, demat_account, notes)
                VALUES (?,?,?,?,?,?,?,?)
                ON DUPLICATE KEY UPDATE units=VALUES(units), avg_cost=VALUES(avg_cost),
                  total_invested=VALUES(total_invested), broker=VALUES(broker),
                  demat_account=VALUES(demat_account), notes=VALUES(notes), updated_at=NOW()
            ")->execute([$uid, $trust_id, $units, $avg_cost, $total_invested, $broker, $demat, $notes]);
        } else {
            $hid = (int)($_POST['id'] ?? 0);
            if (!$hid) reit_err('id required for edit');
            $db->prepare("
                UPDATE reit_invit_holdings SET units=?, avg_cost=?, total_invested=?,
                  broker=?, demat_account=?, notes=?, updated_at=NOW()
                WHERE id=? AND user_id=?
            ")->execute([$units, $avg_cost, $total_invested, $broker, $demat, $notes, $hid, $uid]);
        }
        reit_json(['message' => 'Holding saved']);
    }

    /* ---- Delete holding ------------------------------------ */
    case 'reit_delete_holding': {
        $hid = (int)($_POST['id'] ?? 0);
        if (!$hid) reit_err('id required');
        $db->prepare("DELETE FROM reit_invit_holdings WHERE id=? AND user_id=?")->execute([$hid, $uid]);
        reit_json(['message' => 'Holding deleted']);
    }

    /* ---- Transactions ------------------------------------- */
    case 'reit_txns': {
        $trust_id = (int)($_GET['trust_id'] ?? 0);
        $sql  = "SELECT t.*, m.name, m.symbol FROM reit_invit_transactions t
                 JOIN reit_invit_master m ON m.id = t.trust_id
                 WHERE t.user_id = ?";
        $params = [$uid];
        if ($trust_id) { $sql .= " AND t.trust_id = ?"; $params[] = $trust_id; }
        $sql .= " ORDER BY t.txn_date DESC LIMIT 200";
        $st = $db->prepare($sql); $st->execute($params);
        reit_json($st->fetchAll(PDO::FETCH_ASSOC));
    }

    case 'reit_add_txn': {
        $trust_id   = (int)($_POST['trust_id']     ?? 0);
        $txn_type   = $_POST['txn_type']            ?? 'buy';
        $txn_date   = $_POST['txn_date']            ?? date('Y-m-d');
        $units      = (float)($_POST['units']       ?? 0);
        $price      = (float)($_POST['price_per_unit'] ?? 0);
        $brokerage  = (float)($_POST['brokerage']   ?? 0);
        $stt        = (float)($_POST['stt']         ?? 0);
        $total      = (float)($_POST['total_amount'] ?? ($units * $price + $brokerage + $stt));
        $notes      = trim($_POST['notes']          ?? '');

        if (!$trust_id || $units <= 0 || $price <= 0) reit_err('trust_id, units, price_per_unit required');
        if (!in_array($txn_type, ['buy','sell','bonus','rights'])) reit_err('Invalid txn_type');

        $db->prepare("
            INSERT INTO reit_invit_transactions
              (user_id, trust_id, txn_type, txn_date, units, price_per_unit, brokerage, stt, total_amount, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ")->execute([$uid, $trust_id, $txn_type, $txn_date, $units, $price, $brokerage, $stt, $total, $notes]);

        // Recalculate holding
        $agg = $db->prepare("
            SELECT
              SUM(CASE WHEN txn_type IN ('buy','bonus','rights') THEN units ELSE -units END) AS net_units,
              SUM(CASE WHEN txn_type = 'buy' THEN total_amount ELSE 0 END) AS total_inv,
              SUM(CASE WHEN txn_type = 'buy' THEN units ELSE 0 END) AS buy_units
            FROM reit_invit_transactions WHERE user_id=? AND trust_id=?
        ");
        $agg->execute([$uid, $trust_id]);
        $row = $agg->fetch(PDO::FETCH_ASSOC);
        $net_units = max(0, (float)$row['net_units']);
        $avg_cost  = $row['buy_units'] > 0 ? (float)$row['total_inv'] / (float)$row['buy_units'] : 0;

        $db->prepare("
            INSERT INTO reit_invit_holdings (user_id, trust_id, units, avg_cost, total_invested)
            VALUES (?,?,?,?,?)
            ON DUPLICATE KEY UPDATE units=VALUES(units), avg_cost=VALUES(avg_cost),
              total_invested=VALUES(total_invested), updated_at=NOW()
        ")->execute([$uid, $trust_id, $net_units, $avg_cost, (float)$row['total_inv']]);

        reit_json(['message' => 'Transaction added']);
    }

    case 'reit_delete_txn': {
        $tid = (int)($_POST['id'] ?? 0);
        if (!$tid) reit_err('id required');
        $db->prepare("DELETE FROM reit_invit_transactions WHERE id=? AND user_id=?")->execute([$tid, $uid]);
        reit_json(['message' => 'Transaction deleted']);
    }

    /* ---- Distributions ------------------------------------ */
    case 'reit_distributions': {
        $trust_id = (int)($_GET['trust_id'] ?? 0);
        $sql  = "SELECT d.*, m.name, m.symbol FROM reit_invit_distributions d
                 JOIN reit_invit_master m ON m.id = d.trust_id
                 WHERE d.user_id = ?";
        $params = [$uid];
        if ($trust_id) { $sql .= " AND d.trust_id = ?"; $params[] = $trust_id; }
        $sql .= " ORDER BY d.distribution_date DESC LIMIT 200";
        $st = $db->prepare($sql); $st->execute($params);
        reit_json($st->fetchAll(PDO::FETCH_ASSOC));
    }

    case 'reit_add_dist': {
        $trust_id   = (int)($_POST['trust_id']       ?? 0);
        $dist_date  = $_POST['distribution_date']    ?? date('Y-m-d');
        $dist_type  = $_POST['dist_type']            ?? 'interest';
        $amt_unit   = (float)($_POST['amount_per_unit'] ?? 0);
        $units_held = (float)($_POST['units_held']   ?? 0);
        $total      = (float)($_POST['total_received'] ?? ($amt_unit * $units_held));
        $tds        = (float)($_POST['tds_deducted'] ?? 0);
        $net        = $total - $tds;
        $reinvest   = (int)($_POST['is_reinvested']  ?? 0);
        $notes      = trim($_POST['notes']           ?? '');

        if (!$trust_id || $amt_unit <= 0) reit_err('trust_id, amount_per_unit required');

        $db->prepare("
            INSERT INTO reit_invit_distributions
              (user_id, trust_id, distribution_date, dist_type, amount_per_unit,
               units_held, total_received, tds_deducted, net_received, is_reinvested, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([$uid, $trust_id, $dist_date, $dist_type, $amt_unit,
                     $units_held, $total, $tds, $net, $reinvest, $notes]);
        reit_json(['message' => 'Distribution recorded']);
    }

    /* ---- Update live price --------------------------------- */
    case 'reit_update_price': {
        $trust_id = (int)($_POST['trust_id'] ?? 0);
        if (!$trust_id) reit_err('trust_id required');

        $trust = $db->prepare("SELECT symbol FROM reit_invit_master WHERE id=?");
        $trust->execute([$trust_id]);
        $row = $trust->fetch(PDO::FETCH_ASSOC);
        if (!$row) reit_err('Trust not found', 404);

        $price = fetch_reit_price($row['symbol']);
        if (!$price) reit_err('Could not fetch live price');

        $db->prepare("
            INSERT INTO reit_invit_prices (trust_id, price, price_date)
            VALUES (?,?,CURDATE())
            ON DUPLICATE KEY UPDATE price=VALUES(price), price_date=VALUES(price_date), updated_at=NOW()
        ")->execute([$trust_id, $price]);

        reit_json(['price' => $price, 'symbol' => $row['symbol']]);
    }

    default:
        reit_err("Unknown action: $action", 404);
}
