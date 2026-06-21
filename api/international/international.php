<?php
defined('WEALTHDASH') or die();

/**
 * t121: International Stocks / LRS Tracker
 * Routes (add to router.php):
 *   GET    /api/international                -> getHoldings()
 *   POST   /api/international                -> addHolding()
 *   PUT    /api/international/{id}           -> updateHolding()
 *   DELETE /api/international/{id}           -> deleteHolding()
 *   GET    /api/international/summary        -> getSummary()
 *   POST   /api/international/{id}/transaction -> addTransaction()
 *   GET    /api/international/lrs            -> getLRS()
 *   POST   /api/international/lrs            -> addLRS()
 *   DELETE /api/international/lrs/{id}       -> deleteLRS()
 *   POST   /api/international/refresh-prices -> refreshPrices()
 */
class InternationalStocks {

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // GET /api/international?user_id=X&exchange=NYSE
    public function getHoldings(): void {
        $userId   = (int)($_GET['user_id'] ?? 0);
        $exchange = $_GET['exchange'] ?? null;
        $currency = $_GET['currency'] ?? null;
        if (!$userId) { $this->error('user_id required', 400); return; }

        $where = ['user_id = :uid', 'is_active = 1'];
        $params = [':uid' => $userId];
        if ($exchange) { $where[] = 'exchange = :exch'; $params[':exch'] = strtoupper($exchange); }
        if ($currency) { $where[] = 'currency = :curr'; $params[':curr'] = strtoupper($currency); }

        $stmt = $this->db->prepare('SELECT * FROM international_stocks WHERE ' . implode(' AND ', $where) . ' ORDER BY ticker');
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $invested_foreign = (float)$row['avg_buy_price_foreign'] * (float)$row['quantity'];
            $invested_inr     = (float)$row['avg_buy_price_inr'] * (float)$row['quantity'];
            $curr_foreign     = ($row['current_price_foreign'] ?? 0) * (float)$row['quantity'];
            $curr_inr         = ($row['current_price_inr'] ?? 0) * (float)$row['quantity'];

            $row['invested_foreign']  = round($invested_foreign, 4);
            $row['invested_inr']      = round($invested_inr, 2);
            $row['current_value_foreign'] = round($curr_foreign, 4);
            $row['current_value_inr']     = round($curr_inr, 2);
            $row['gain_loss_foreign'] = round($curr_foreign - $invested_foreign, 4);
            $row['gain_loss_inr']     = round($curr_inr - $invested_inr, 2);
            $row['gain_pct']          = $invested_foreign > 0 ? round((($curr_foreign - $invested_foreign) / $invested_foreign) * 100, 2) : null;
        }

        $this->json(['status' => 'success', 'data' => $rows]);
    }

    // GET /api/international/summary?user_id=X
    public function getSummary(): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        if (!$userId) { $this->error('user_id required', 400); return; }

        $stmt = $this->db->prepare("
            SELECT
                currency,
                exchange,
                COUNT(*) AS stocks,
                SUM(quantity * avg_buy_price_inr) AS total_invested_inr,
                SUM(quantity * COALESCE(current_price_inr, avg_buy_price_inr)) AS current_value_inr
            FROM international_stocks
            WHERE user_id = :uid AND is_active = 1
            GROUP BY currency, exchange
        ");
        $stmt->execute([':uid' => $userId]);
        $byCurrency = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // LRS utilization for current FY
        $fy = $this->currentFY();
        [$fyStart, $fyEnd] = $this->fyDates($fy);
        $lrsStmt = $this->db->prepare("
            SELECT
                SUM(amount_inr) AS total_remitted_inr,
                SUM(tcs_paid) AS total_tcs_paid
            FROM lrs_remittances
            WHERE user_id = :uid AND remittance_date BETWEEN :start AND :end
        ");
        $lrsStmt->execute([':uid' => $userId, ':start' => $fyStart, ':end' => $fyEnd]);
        $lrs = $lrsStmt->fetch(PDO::FETCH_ASSOC);

        $lrsLimit    = 25000000; // ₹2.5 Cr LRS limit
        $tcsThreshold= 700000;  // ₹7L TCS threshold
        $remitted    = (float)($lrs['total_remitted_inr'] ?? 0);

        $this->json([
            'status'       => 'success',
            'by_currency'  => $byCurrency,
            'lrs_fy'       => $fy,
            'lrs_utilization' => [
                'remitted_inr'   => $remitted,
                'limit_inr'      => $lrsLimit,
                'remaining_inr'  => max(0, $lrsLimit - $remitted),
                'utilized_pct'   => round(($remitted / $lrsLimit) * 100, 2),
                'tcs_threshold'  => $tcsThreshold,
                'tcs_applicable' => $remitted > $tcsThreshold,
                'total_tcs_paid' => (float)($lrs['total_tcs_paid'] ?? 0),
            ],
        ]);
    }

    // POST /api/international
    public function addHolding(): void {
        $d = $this->input();
        $req = ['user_id','ticker','stock_name','exchange','currency','quantity','avg_buy_price_foreign','avg_buy_price_inr'];
        foreach ($req as $f) {
            if (!isset($d[$f]) || $d[$f] === '') { $this->error("$f is required", 400); return; }
        }

        $stmt = $this->db->prepare("
            INSERT INTO international_stocks
                (user_id, ticker, stock_name, exchange, country, currency, quantity,
                 avg_buy_price_foreign, avg_buy_price_inr, broker_platform, sector, notes)
            VALUES (:uid,:ticker,:name,:exch,:country,:curr,:qty,:bpf,:bpi,:broker,:sector,:notes)
        ");
        $stmt->execute([
            ':uid'    => (int)$d['user_id'],
            ':ticker' => strtoupper($d['ticker']),
            ':name'   => $d['stock_name'],
            ':exch'   => strtoupper($d['exchange']),
            ':country'=> strtoupper($d['country'] ?? 'US'),
            ':curr'   => strtoupper($d['currency']),
            ':qty'    => (float)$d['quantity'],
            ':bpf'    => (float)$d['avg_buy_price_foreign'],
            ':bpi'    => (float)$d['avg_buy_price_inr'],
            ':broker' => $d['broker_platform'] ?? null,
            ':sector' => $d['sector'] ?? null,
            ':notes'  => $d['notes'] ?? null,
        ]);
        $id = (int)$this->db->lastInsertId();

        // Log buy transaction
        $txDate = $d['transaction_date'] ?? date('Y-m-d');
        $rate   = $d['exchange_rate'] ?? round((float)$d['avg_buy_price_inr'] / (float)$d['avg_buy_price_foreign'], 4);
        $this->db->prepare("
            INSERT INTO intl_transactions (user_id, stock_id, transaction_type, quantity, price_foreign, price_inr, exchange_rate, transaction_date)
            VALUES (:uid, :sid, 'BUY', :qty, :pf, :pi, :rate, :date)
        ")->execute([':uid' => (int)$d['user_id'], ':sid' => $id, ':qty' => (float)$d['quantity'],
                     ':pf' => (float)$d['avg_buy_price_foreign'], ':pi' => (float)$d['avg_buy_price_inr'],
                     ':rate' => (float)$rate, ':date' => $txDate]);

        $this->json(['status' => 'success', 'id' => $id, 'message' => 'International holding added'], 201);
    }

    // PUT /api/international/{id}
    public function updateHolding(int $id): void {
        $d = $this->input();
        $userId = (int)($d['user_id'] ?? 0);
        $allowed = ['stock_name','exchange','country','currency','quantity','avg_buy_price_foreign','avg_buy_price_inr',
                    'current_price_foreign','current_price_inr','broker_platform','sector','notes'];
        $fields = []; $params = [':id' => $id, ':uid' => $userId];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $d)) { $fields[] = "$f = :$f"; $params[":$f"] = $d[$f]; }
        }
        if (empty($fields)) { $this->error('No fields', 400); return; }
        $this->db->prepare('UPDATE international_stocks SET ' . implode(', ', $fields) . ' WHERE id = :id AND user_id = :uid')->execute($params);
        $this->json(['status' => 'success', 'message' => 'Updated']);
    }

    // DELETE /api/international/{id}
    public function deleteHolding(int $id): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $stmt = $this->db->prepare('UPDATE international_stocks SET is_active = 0 WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        if ($stmt->rowCount() === 0) { $this->error('Not found', 404); return; }
        $this->json(['status' => 'success', 'message' => 'Deleted']);
    }

    // POST /api/international/{id}/transaction
    public function addTransaction(int $stockId): void {
        $d = $this->input();
        $userId = (int)($d['user_id'] ?? 0);
        $req = ['transaction_type','quantity','price_foreign','price_inr','exchange_rate','transaction_date'];
        foreach ($req as $f) {
            if (empty($d[$f])) { $this->error("$f is required", 400); return; }
        }

        $check = $this->db->prepare('SELECT id, quantity, avg_buy_price_foreign, avg_buy_price_inr FROM international_stocks WHERE id = :id AND user_id = :uid');
        $check->execute([':id' => $stockId, ':uid' => $userId]);
        $holding = $check->fetch(PDO::FETCH_ASSOC);
        if (!$holding) { $this->error('Holding not found', 404); return; }

        $type = strtoupper($d['transaction_type']);
        $qty  = (float)$d['quantity'];
        $pf   = (float)$d['price_foreign'];
        $pi   = (float)$d['price_inr'];

        if ($type === 'BUY') {
            $oldQty  = (float)$holding['quantity'];
            $newQty  = $oldQty + $qty;
            $newAvgF = (($oldQty * (float)$holding['avg_buy_price_foreign']) + ($qty * $pf)) / $newQty;
            $newAvgI = (($oldQty * (float)$holding['avg_buy_price_inr']) + ($qty * $pi)) / $newQty;
            $this->db->prepare('UPDATE international_stocks SET quantity = :qty, avg_buy_price_foreign = :apf, avg_buy_price_inr = :api WHERE id = :id')
                     ->execute([':qty' => $newQty, ':apf' => round($newAvgF, 4), ':api' => round($newAvgI, 4), ':id' => $stockId]);
        } elseif ($type === 'SELL') {
            $newQty = max(0, (float)$holding['quantity'] - $qty);
            $this->db->prepare('UPDATE international_stocks SET quantity = :qty, is_active = :active WHERE id = :id')
                     ->execute([':qty' => $newQty, ':active' => $newQty > 0 ? 1 : 0, ':id' => $stockId]);
        }

        $this->db->prepare("
            INSERT INTO intl_transactions (user_id, stock_id, lrs_id, transaction_type, quantity, price_foreign, price_inr, exchange_rate, transaction_date, charges_foreign, notes)
            VALUES (:uid, :sid, :lrs, :type, :qty, :pf, :pi, :rate, :date, :charges, :notes)
        ")->execute([
            ':uid'     => $userId, ':sid' => $stockId,
            ':lrs'     => $d['lrs_id'] ?? null, ':type' => $type,
            ':qty'     => $qty, ':pf' => $pf, ':pi' => $pi,
            ':rate'    => (float)$d['exchange_rate'],
            ':date'    => $d['transaction_date'],
            ':charges' => (float)($d['charges_foreign'] ?? 0),
            ':notes'   => $d['notes'] ?? null,
        ]);
        $this->json(['status' => 'success', 'message' => 'Transaction recorded'], 201);
    }

    // GET /api/international/lrs?user_id=X&fy=2024-25
    public function getLRS(): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $fy     = $_GET['fy'] ?? $this->currentFY();
        if (!$userId) { $this->error('user_id required', 400); return; }

        [$fyStart, $fyEnd] = $this->fyDates($fy);
        $stmt = $this->db->prepare('SELECT * FROM lrs_remittances WHERE user_id = :uid AND remittance_date BETWEEN :start AND :end ORDER BY remittance_date DESC');
        $stmt->execute([':uid' => $userId, ':start' => $fyStart, ':end' => $fyEnd]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = array_sum(array_column($rows, 'amount_inr'));
        $tcs   = array_sum(array_column($rows, 'tcs_paid'));

        $this->json([
            'status' => 'success',
            'fy'     => $fy,
            'data'   => $rows,
            'totals' => ['remitted_inr' => $total, 'tcs_paid' => $tcs],
        ]);
    }

    // POST /api/international/lrs
    public function addLRS(): void {
        $d = $this->input();
        $req = ['user_id','remittance_date','amount_inr','amount_foreign','currency','exchange_rate'];
        foreach ($req as $f) {
            if (!isset($d[$f]) || $d[$f] === '') { $this->error("$f is required", 400); return; }
        }

        $fy = $this->dateFY($d['remittance_date']);
        $stmt = $this->db->prepare("
            INSERT INTO lrs_remittances
                (user_id, remittance_date, amount_inr, amount_foreign, currency, exchange_rate, purpose, bank_name, forex_charges, tcs_paid, financial_year, notes)
            VALUES (:uid,:date,:inr,:foreign,:curr,:rate,:purpose,:bank,:forex,:tcs,:fy,:notes)
        ");
        $stmt->execute([
            ':uid'    => (int)$d['user_id'],
            ':date'   => $d['remittance_date'],
            ':inr'    => (float)$d['amount_inr'],
            ':foreign'=> (float)$d['amount_foreign'],
            ':curr'   => strtoupper($d['currency']),
            ':rate'   => (float)$d['exchange_rate'],
            ':purpose'=> $d['purpose'] ?? 'Investment',
            ':bank'   => $d['bank_name'] ?? null,
            ':forex'  => (float)($d['forex_charges'] ?? 0),
            ':tcs'    => (float)($d['tcs_paid'] ?? 0),
            ':fy'     => $fy,
            ':notes'  => $d['notes'] ?? null,
        ]);
        $this->json(['status' => 'success', 'id' => (int)$this->db->lastInsertId(), 'message' => 'LRS entry added'], 201);
    }

    // DELETE /api/international/lrs/{id}
    public function deleteLRS(int $id): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $stmt = $this->db->prepare('DELETE FROM lrs_remittances WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        if ($stmt->rowCount() === 0) { $this->error('Not found', 404); return; }
        $this->json(['status' => 'success', 'message' => 'Deleted']);
    }

    // POST /api/international/refresh-prices
    public function refreshPrices(): void {
        // Hook: implement with Yahoo Finance / Alpha Vantage / Polygon.io
        $this->json([
            'status' => 'success',
            'message' => 'Connect external price feed (Yahoo Finance / Alpha Vantage) here',
            'updated' => 0,
        ]);
    }

    private function currentFY(): string {
        $m = (int)date('n'); $y = (int)date('Y');
        return $m >= 4 ? "$y-" . ($y + 1 - 2000) : ($y - 1) . "-" . ($y - 2000);
    }
    private function fyDates(string $fy): array {
        [$y1] = explode('-', $fy);
        return ["$y1-04-01", ($y1 + 1) . "-03-31"];
    }
    private function dateFY(string $date): string {
        $m = (int)date('n', strtotime($date)); $y = (int)date('Y', strtotime($date));
        return $m >= 4 ? "$y-" . ($y + 1 - 2000) : ($y - 1) . "-" . ($y - 2000);
    }
    private function input(): array { return json_decode(file_get_contents('php://input'), true) ?? []; }
    private function json(mixed $d, int $c = 200): void { http_response_code($c); header('Content-Type: application/json'); echo json_encode($d); }
    private function error(string $m, int $c = 400): void { $this->json(['status' => 'error', 'message' => $m], $c); }
}
