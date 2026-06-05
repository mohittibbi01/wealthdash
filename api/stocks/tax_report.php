<?php
defined('WEALTHDASH') or die();

/**
 * t39: LTCG/STCG Stocks Report API
 * Routes (add to router.php):
 *   GET  /api/stocks/tax-report        -> getTaxReport()
 *   GET  /api/stocks/transactions       -> getTransactions()
 *   POST /api/stocks/transactions       -> addTransaction()
 *   PUT  /api/stocks/transactions/{id}  -> updateTransaction()
 *   DELETE /api/stocks/transactions/{id}-> deleteTransaction()
 *   POST /api/stocks/transactions/bulk-import -> bulkImport()
 */
class StocksTaxReport {

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // GET /api/stocks/tax-report?fy=2024-25&user_id=X
    public function getTaxReport(): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $fy     = $_GET['fy'] ?? $this->currentFY();

        if (!$userId) { $this->error('user_id required', 400); return; }

        [$fyStart, $fyEnd] = $this->fyDates($fy);

        // Realized gains (sells within FY)
        $stcg = $this->getRealizedGains($userId, $fyStart, $fyEnd, 'STCG');
        $ltcg = $this->getRealizedGains($userId, $fyStart, $fyEnd, 'LTCG');

        // Unrealized gains on current holdings
        $unrealized = $this->getUnrealizedGains($userId);

        // Summary totals
        $stcgTotal = array_sum(array_column($stcg, 'gain_loss'));
        $ltcgTotal = array_sum(array_column($ltcg, 'gain_loss'));
        $ltcgExempt  = min(max($ltcgTotal, 0), 125000); // 1.25L exemption FY25
        $ltcgTaxable = max($ltcgTotal - $ltcgExempt, 0);

        $this->json([
            'status'  => 'success',
            'fy'      => $fy,
            'summary' => [
                'stcg_total'       => round($stcgTotal, 2),
                'stcg_tax'         => round(max($stcgTotal, 0) * 0.20, 2), // 20% post Jul-2024 budget
                'ltcg_total'       => round($ltcgTotal, 2),
                'ltcg_exempt'      => round($ltcgExempt, 2),
                'ltcg_taxable'     => round($ltcgTaxable, 2),
                'ltcg_tax'         => round($ltcgTaxable * 0.125, 2), // 12.5% post Jul-2024 budget
            ],
            'stcg'       => $stcg,
            'ltcg'       => $ltcg,
            'unrealized' => $unrealized,
        ]);
    }

    private function getRealizedGains(int $userId, string $start, string $end, string $category): array {
        $stmt = $this->db->prepare("
            SELECT tl.*, st_buy.transaction_date AS buy_date_raw,
                   st_sell.transaction_date AS sell_date_raw,
                   DATEDIFF(tl.sell_date, tl.buy_date) AS holding_days,
                   (tl.sell_price - tl.buy_price) * tl.quantity AS gross_gain
            FROM tax_lots tl
            LEFT JOIN stock_transactions st_buy  ON st_buy.id  = tl.buy_transaction_id
            LEFT JOIN stock_transactions st_sell ON st_sell.id = tl.sell_transaction_id
            WHERE tl.user_id = :uid
              AND tl.tax_category = :cat
              AND tl.sell_date BETWEEN :start AND :end
            ORDER BY tl.sell_date DESC
        ");
        $stmt->execute([':uid' => $userId, ':cat' => $category, ':start' => $start, ':end' => $end]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getUnrealizedGains(int $userId): array {
        $stmt = $this->db->prepare("
            SELECT
                tl.stock_symbol,
                tl.stock_name,
                SUM(tl.quantity) AS total_qty,
                AVG(tl.buy_price) AS avg_buy_price,
                tl.buy_date,
                DATEDIFF(CURDATE(), tl.buy_date) AS holding_days,
                CASE WHEN DATEDIFF(CURDATE(), tl.buy_date) > 365 THEN 'LTCG' ELSE 'STCG' END AS projected_category
            FROM tax_lots tl
            WHERE tl.user_id = :uid
              AND tl.tax_category = 'UNREALIZED'
            GROUP BY tl.stock_symbol, tl.buy_date
            ORDER BY tl.stock_symbol, tl.buy_date
        ");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // GET /api/stocks/transactions
    public function getTransactions(): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $symbol = $_GET['symbol'] ?? null;
        $type   = $_GET['type'] ?? null;
        $from   = $_GET['from'] ?? null;
        $to     = $_GET['to'] ?? null;
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(100, (int)($_GET['limit'] ?? 25));
        $offset = ($page - 1) * $limit;

        if (!$userId) { $this->error('user_id required', 400); return; }

        $where = ['user_id = :uid'];
        $params = [':uid' => $userId];
        if ($symbol) { $where[] = 'stock_symbol = :symbol'; $params[':symbol'] = strtoupper($symbol); }
        if ($type)   { $where[] = 'transaction_type = :type'; $params[':type'] = strtoupper($type); }
        if ($from)   { $where[] = 'transaction_date >= :from'; $params[':from'] = $from; }
        if ($to)     { $where[] = 'transaction_date <= :to'; $params[':to'] = $to; }

        $sql = 'SELECT * FROM stock_transactions WHERE ' . implode(' AND ', $where)
             . ' ORDER BY transaction_date DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countStmt = $this->db->prepare('SELECT COUNT(*) FROM stock_transactions WHERE ' . implode(' AND ', $where));
        foreach ($params as $k => $v) $countStmt->bindValue($k, $v);
        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();

        $this->json(['status' => 'success', 'data' => $rows, 'total' => $total, 'page' => $page, 'pages' => ceil($total / $limit)]);
    }

    // POST /api/stocks/transactions
    public function addTransaction(): void {
        $data = $this->input();
        $required = ['user_id','stock_symbol','stock_name','transaction_type','quantity','price','transaction_date'];
        foreach ($required as $f) {
            if (empty($data[$f])) { $this->error("$f is required", 400); return; }
        }

        $stmt = $this->db->prepare("
            INSERT INTO stock_transactions
                (user_id, stock_symbol, stock_name, transaction_type, quantity, price, brokerage, stt, transaction_date, exchange, isin, grandfathered_price, notes)
            VALUES
                (:uid, :sym, :name, :type, :qty, :price, :brok, :stt, :date, :exch, :isin, :gprice, :notes)
        ");
        $stmt->execute([
            ':uid'    => (int)$data['user_id'],
            ':sym'    => strtoupper($data['stock_symbol']),
            ':name'   => $data['stock_name'],
            ':type'   => strtoupper($data['transaction_type']),
            ':qty'    => (float)$data['quantity'],
            ':price'  => (float)$data['price'],
            ':brok'   => (float)($data['brokerage'] ?? 0),
            ':stt'    => (float)($data['stt'] ?? 0),
            ':date'   => $data['transaction_date'],
            ':exch'   => $data['exchange'] ?? 'NSE',
            ':isin'   => $data['isin'] ?? null,
            ':gprice' => $data['grandfathered_price'] ?? null,
            ':notes'  => $data['notes'] ?? null,
        ]);

        $id = $this->db->lastInsertId();
        $this->computeTaxLots((int)$data['user_id'], strtoupper($data['stock_symbol']));

        $this->json(['status' => 'success', 'id' => (int)$id, 'message' => 'Transaction added'], 201);
    }

    // PUT /api/stocks/transactions/{id}
    public function updateTransaction(int $id): void {
        $data = $this->input();
        if (empty($data)) { $this->error('No data', 400); return; }
        $userId = (int)($data['user_id'] ?? 0);

        $fields = [];
        $params = [':id' => $id, ':uid' => $userId];
        $allowed = ['stock_symbol','stock_name','transaction_type','quantity','price','brokerage','stt','transaction_date','exchange','isin','grandfathered_price','notes'];
        foreach ($allowed as $f) {
            if (isset($data[$f])) { $fields[] = "$f = :$f"; $params[":$f"] = $data[$f]; }
        }
        if (empty($fields)) { $this->error('No valid fields', 400); return; }

        $stmt = $this->db->prepare('UPDATE stock_transactions SET ' . implode(', ', $fields) . ' WHERE id = :id AND user_id = :uid');
        $stmt->execute($params);

        if ($stmt->rowCount() === 0) { $this->error('Not found', 404); return; }
        $symbol = strtoupper($data['stock_symbol'] ?? $this->getSymbol($id));
        $this->computeTaxLots($userId, $symbol);

        $this->json(['status' => 'success', 'message' => 'Updated']);
    }

    // DELETE /api/stocks/transactions/{id}
    public function deleteTransaction(int $id): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $row = $this->db->prepare('SELECT stock_symbol, user_id FROM stock_transactions WHERE id = :id');
        $row->execute([':id' => $id]);
        $tx = $row->fetch(PDO::FETCH_ASSOC);
        if (!$tx || (int)$tx['user_id'] !== $userId) { $this->error('Not found', 404); return; }

        $this->db->prepare('DELETE FROM stock_transactions WHERE id = :id')->execute([':id' => $id]);
        $this->computeTaxLots($userId, $tx['stock_symbol']);
        $this->json(['status' => 'success', 'message' => 'Deleted']);
    }

    // POST /api/stocks/transactions/bulk-import (CSV rows)
    public function bulkImport(): void {
        $data   = $this->input();
        $userId = (int)($data['user_id'] ?? 0);
        $rows   = $data['transactions'] ?? [];
        if (!$userId || empty($rows)) { $this->error('user_id and transactions required', 400); return; }

        $inserted = 0; $errors = [];
        foreach ($rows as $i => $tx) {
            try {
                $tx['user_id'] = $userId;
                ob_start();
                $this->addTransaction();
                ob_end_clean();
                $inserted++;
            } catch (Exception $e) {
                $errors[] = ['row' => $i + 1, 'error' => $e->getMessage()];
            }
        }
        $this->json(['status' => 'success', 'inserted' => $inserted, 'errors' => $errors]);
    }

    /**
     * FIFO tax lot computation for a symbol per user.
     * Clears existing UNREALIZED/REALIZED lots and recomputes.
     */
    private function computeTaxLots(int $userId, string $symbol): void {
        $this->db->prepare('DELETE FROM tax_lots WHERE user_id = :uid AND stock_symbol = :sym')
                 ->execute([':uid' => $userId, ':sym' => $symbol]);

        $stmt = $this->db->prepare("
            SELECT * FROM stock_transactions
            WHERE user_id = :uid AND stock_symbol = :sym
            ORDER BY transaction_date ASC, id ASC
        ");
        $stmt->execute([':uid' => $userId, ':sym' => $symbol]);
        $txns = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $buyQueue = []; // FIFO queue of [qty, price, date, tx_id, name, gprice]

        foreach ($txns as $tx) {
            if ($tx['transaction_type'] === 'BUY') {
                $buyQueue[] = [
                    'qty'    => (float)$tx['quantity'],
                    'price'  => (float)$tx['price'],
                    'date'   => $tx['transaction_date'],
                    'tx_id'  => $tx['id'],
                    'name'   => $tx['stock_name'],
                    'gprice' => $tx['grandfathered_price'],
                ];
            } elseif ($tx['transaction_type'] === 'SELL') {
                $remainSell = (float)$tx['quantity'];
                while ($remainSell > 0 && !empty($buyQueue)) {
                    $buy = array_shift($buyQueue);
                    $matchQty = min($buy['qty'], $remainSell);

                    $buyPrice = $buy['gprice'] !== null
                        ? max((float)$buy['gprice'], (float)$buy['price'])
                        : (float)$buy['price'];

                    $gainLoss = ($tx['price'] - $buyPrice) * $matchQty;
                    $holdDays = (int)((strtotime($tx['transaction_date']) - strtotime($buy['date'])) / 86400);
                    $category = $holdDays > 365 ? 'LTCG' : 'STCG';
                    $fy = $this->dateFY($tx['transaction_date']);

                    $this->db->prepare("
                        INSERT INTO tax_lots
                            (user_id, stock_symbol, stock_name, buy_transaction_id, sell_transaction_id,
                             quantity, buy_price, sell_price, buy_date, sell_date, gain_loss, tax_category, financial_year, grandfathered_price)
                        VALUES (:uid,:sym,:name,:btid,:stid,:qty,:bp,:sp,:bd,:sd,:gl,:cat,:fy,:gp)
                    ")->execute([
                        ':uid'  => $userId,    ':sym'  => $symbol,
                        ':name' => $buy['name'],':btid' => $buy['tx_id'],
                        ':stid' => $tx['id'],  ':qty'  => $matchQty,
                        ':bp'   => $buyPrice,  ':sp'   => (float)$tx['price'],
                        ':bd'   => $buy['date'],':sd'  => $tx['transaction_date'],
                        ':gl'   => $gainLoss,  ':cat'  => $category,
                        ':fy'   => $fy,        ':gp'   => $buy['gprice'],
                    ]);

                    $buy['qty'] -= $matchQty;
                    if ($buy['qty'] > 0.0001) array_unshift($buyQueue, $buy);
                    $remainSell -= $matchQty;
                }
            }
        }

        // Remaining buys = unrealized
        foreach ($buyQueue as $buy) {
            if ($buy['qty'] < 0.0001) continue;
            $this->db->prepare("
                INSERT INTO tax_lots
                    (user_id, stock_symbol, stock_name, buy_transaction_id, quantity, buy_price, buy_date, tax_category, grandfathered_price)
                VALUES (:uid,:sym,:name,:btid,:qty,:bp,:bd,'UNREALIZED',:gp)
            ")->execute([
                ':uid'  => $userId,    ':sym'  => $symbol,
                ':name' => $buy['name'],':btid' => $buy['tx_id'],
                ':qty'  => $buy['qty'],':bp'   => (float)$buy['price'],
                ':bd'   => $buy['date'],':gp'  => $buy['gprice'],
            ]);
        }
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
        $m = (int)date('n', strtotime($date));
        $y = (int)date('Y', strtotime($date));
        return $m >= 4 ? "$y-" . ($y + 1 - 2000) : ($y - 1) . "-" . ($y - 2000);
    }

    private function getSymbol(int $id): string {
        $s = $this->db->prepare('SELECT stock_symbol FROM stock_transactions WHERE id = :id');
        $s->execute([':id' => $id]);
        return $s->fetchColumn() ?: '';
    }

    private function input(): array {
        return json_decode(file_get_contents('php://input'), true) ?? [];
    }

    private function json(mixed $data, int $code = 200): void {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    private function error(string $msg, int $code = 400): void {
        $this->json(['status' => 'error', 'message' => $msg], $code);
    }
}
