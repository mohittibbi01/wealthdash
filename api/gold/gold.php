<?php
defined('WEALTHDASH') or die();

/**
 * t114: Gold Tracker — Physical, Digital, ETF Unified
 * Routes (add to router.php):
 *   GET    /api/gold                  -> getHoldings()
 *   POST   /api/gold                  -> addHolding()
 *   PUT    /api/gold/{id}             -> updateHolding()
 *   DELETE /api/gold/{id}             -> deleteHolding()
 *   GET    /api/gold/summary          -> getSummary()
 *   POST   /api/gold/{id}/transaction -> addTransaction()
 *   GET    /api/gold/price            -> getLivePrice()
 */
class GoldTracker {

    private PDO $db;

    // Approx purity multipliers for INR price calculation
    private const PURITY = ['24K' => 1.0, '22K' => 0.9167, '18K' => 0.75, '14K' => 0.585];

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // GET /api/gold?user_id=X&type=PHYSICAL
    public function getHoldings(): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $type   = $_GET['type'] ?? null;
        if (!$userId) { $this->error('user_id required', 400); return; }

        $where = ['user_id = :uid', 'is_active = 1'];
        $params = [':uid' => $userId];
        if ($type) { $where[] = 'gold_type = :type'; $params[':type'] = strtoupper($type); }

        $stmt = $this->db->prepare('SELECT * FROM gold_holdings WHERE ' . implode(' AND ', $where) . ' ORDER BY gold_type, buy_date');
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->json(['status' => 'success', 'data' => $rows]);
    }

    // GET /api/gold/summary?user_id=X
    public function getSummary(): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        if (!$userId) { $this->error('user_id required', 400); return; }

        $stmt = $this->db->prepare("
            SELECT
                gold_type,
                COUNT(*) AS items,
                SUM(CASE WHEN gold_type IN ('PHYSICAL','DIGITAL') THEN quantity ELSE 0 END) AS total_grams,
                SUM(CASE WHEN gold_type IN ('ETF','FUND') THEN quantity ELSE 0 END) AS total_units,
                SUM(quantity * buy_price) AS total_invested,
                AVG(buy_price) AS avg_buy_price
            FROM gold_holdings
            WHERE user_id = :uid AND is_active = 1
            GROUP BY gold_type
        ");
        $stmt->execute([':uid' => $userId]);
        $byType = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Total physical gold in grams (all purities normalized to 24K equivalent)
        $normStmt = $this->db->prepare("
            SELECT purity, SUM(quantity) AS grams
            FROM gold_holdings
            WHERE user_id = :uid AND is_active = 1 AND gold_type = 'PHYSICAL'
            GROUP BY purity
        ");
        $normStmt->execute([':uid' => $userId]);
        $physRows = $normStmt->fetchAll(PDO::FETCH_ASSOC);

        $pure24k = 0;
        foreach ($physRows as $r) {
            $multiplier = self::PURITY[$r['purity']] ?? 1.0;
            $pure24k += $r['grams'] * $multiplier;
        }

        $this->json([
            'status'  => 'success',
            'by_type' => $byType,
            'physical_24k_equivalent_grams' => round($pure24k, 4),
        ]);
    }

    // POST /api/gold
    public function addHolding(): void {
        $d = $this->input();
        $req = ['user_id','gold_type','name','quantity','buy_price','buy_date'];
        foreach ($req as $f) {
            if (empty($d[$f])) { $this->error("$f is required", 400); return; }
        }

        $stmt = $this->db->prepare("
            INSERT INTO gold_holdings
                (user_id, gold_type, sub_type, name, quantity, buy_price, buy_date,
                 making_charges, purity, folio_number, dp_id, custodian, notes)
            VALUES
                (:uid,:type,:sub,:name,:qty,:price,:date,:mc,:purity,:folio,:dp,:cust,:notes)
        ");
        $stmt->execute([
            ':uid'   => (int)$d['user_id'],
            ':type'  => strtoupper($d['gold_type']),
            ':sub'   => $d['sub_type'] ?? null,
            ':name'  => $d['name'],
            ':qty'   => (float)$d['quantity'],
            ':price' => (float)$d['buy_price'],
            ':date'  => $d['buy_date'],
            ':mc'    => (float)($d['making_charges'] ?? 0),
            ':purity'=> $d['purity'] ?? '24K',
            ':folio' => $d['folio_number'] ?? null,
            ':dp'    => $d['dp_id'] ?? null,
            ':cust'  => $d['custodian'] ?? null,
            ':notes' => $d['notes'] ?? null,
        ]);
        $id = $this->db->lastInsertId();

        // Log as BUY transaction
        $this->db->prepare("
            INSERT INTO gold_transactions (user_id, holding_id, transaction_type, quantity, price, transaction_date)
            VALUES (:uid, :hid, 'BUY', :qty, :price, :date)
        ")->execute([':uid' => (int)$d['user_id'], ':hid' => $id, ':qty' => (float)$d['quantity'], ':price' => (float)$d['buy_price'], ':date' => $d['buy_date']]);

        $this->json(['status' => 'success', 'id' => (int)$id, 'message' => 'Gold holding added'], 201);
    }

    // PUT /api/gold/{id}
    public function updateHolding(int $id): void {
        $d = $this->input();
        $userId = (int)($d['user_id'] ?? 0);
        if (!$userId) { $this->error('user_id required', 400); return; }

        $allowed = ['gold_type','sub_type','name','quantity','buy_price','buy_date','making_charges','purity','folio_number','dp_id','custodian','notes'];
        $fields = []; $params = [':id' => $id, ':uid' => $userId];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $d)) { $fields[] = "$f = :$f"; $params[":$f"] = $d[$f]; }
        }
        if (empty($fields)) { $this->error('No valid fields', 400); return; }

        $stmt = $this->db->prepare('UPDATE gold_holdings SET ' . implode(', ', $fields) . ' WHERE id = :id AND user_id = :uid');
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) { $this->error('Not found', 404); return; }

        $this->json(['status' => 'success', 'message' => 'Updated']);
    }

    // DELETE /api/gold/{id}
    public function deleteHolding(int $id): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $stmt = $this->db->prepare('UPDATE gold_holdings SET is_active = 0 WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        if ($stmt->rowCount() === 0) { $this->error('Not found', 404); return; }
        $this->json(['status' => 'success', 'message' => 'Deleted']);
    }

    // POST /api/gold/{id}/transaction
    public function addTransaction(int $holdingId): void {
        $d = $this->input();
        $userId = (int)($d['user_id'] ?? 0);
        $req = ['transaction_type','quantity','price','transaction_date'];
        foreach ($req as $f) {
            if (empty($d[$f])) { $this->error("$f is required", 400); return; }
        }

        // Validate holding belongs to user
        $check = $this->db->prepare('SELECT id, quantity FROM gold_holdings WHERE id = :id AND user_id = :uid AND is_active = 1');
        $check->execute([':id' => $holdingId, ':uid' => $userId]);
        $holding = $check->fetch(PDO::FETCH_ASSOC);
        if (!$holding) { $this->error('Holding not found', 404); return; }

        $txType = strtoupper($d['transaction_type']);

        // For SELL: deduct quantity; if 0 mark inactive
        if ($txType === 'SELL') {
            $newQty = (float)$holding['quantity'] - (float)$d['quantity'];
            if ($newQty < 0) { $this->error('Sell quantity exceeds holding', 400); return; }
            $upd = $this->db->prepare('UPDATE gold_holdings SET quantity = :qty, is_active = :active WHERE id = :id');
            $upd->execute([':qty' => $newQty, ':active' => $newQty > 0 ? 1 : 0, ':id' => $holdingId]);
        }

        $stmt = $this->db->prepare("
            INSERT INTO gold_transactions (user_id, holding_id, transaction_type, quantity, price, transaction_date, charges, notes)
            VALUES (:uid, :hid, :type, :qty, :price, :date, :charges, :notes)
        ");
        $stmt->execute([
            ':uid'     => $userId,
            ':hid'     => $holdingId,
            ':type'    => $txType,
            ':qty'     => (float)$d['quantity'],
            ':price'   => (float)$d['price'],
            ':date'    => $d['transaction_date'],
            ':charges' => (float)($d['charges'] ?? 0),
            ':notes'   => $d['notes'] ?? null,
        ]);
        $this->json(['status' => 'success', 'id' => (int)$this->db->lastInsertId()], 201);
    }

    // GET /api/gold/price  — returns today's gold price (mock; replace with live feed)
    public function getLivePrice(): void {
        // In production: fetch from MCX/ibapi/goldprice.org
        $this->json([
            'status'    => 'success',
            'note'      => 'Replace with live MCX/gold API feed',
            'rates'     => [
                '24K_per_gram' => null,
                '22K_per_gram' => null,
                'ETF_nav'      => null,
            ],
            'as_of' => date('Y-m-d H:i:s'),
        ]);
    }

    private function input(): array { return json_decode(file_get_contents('php://input'), true) ?? []; }
    private function json(mixed $d, int $c = 200): void { http_response_code($c); header('Content-Type: application/json'); echo json_encode($d); }
    private function error(string $m, int $c = 400): void { $this->json(['status' => 'error', 'message' => $m], $c); }
}
