<?php
defined('WEALTHDASH') or die();

/**
 * t436: Stock SIP — Regular Stock Purchase Tracker
 * Routes (add to router.php):
 *   GET    /api/stocks/sip               -> getSIPs()
 *   POST   /api/stocks/sip               -> createSIP()
 *   PUT    /api/stocks/sip/{id}          -> updateSIP()
 *   DELETE /api/stocks/sip/{id}          -> deleteSIP()
 *   GET    /api/stocks/sip/{id}/installments -> getInstallments()
 *   POST   /api/stocks/sip/{id}/installments -> recordInstallment()
 *   GET    /api/stocks/sip/summary       -> getSummary()
 *   GET    /api/stocks/sip/due-today     -> getDueToday()
 */
class StockSIP {

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // GET /api/stocks/sip?user_id=X&active=1
    public function getSIPs(): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $active = $_GET['active'] ?? null;
        if (!$userId) { $this->error('user_id required', 400); return; }

        $where = ['s.user_id = :uid'];
        $params = [':uid' => $userId];
        if ($active !== null) { $where[] = 's.is_active = :active'; $params[':active'] = (int)$active; }

        $stmt = $this->db->prepare("
            SELECT s.*,
                   COUNT(i.id) AS total_installments,
                   SUM(CASE WHEN i.status = 'EXECUTED' THEN i.amount ELSE 0 END) AS total_invested,
                   SUM(CASE WHEN i.status = 'EXECUTED' THEN i.quantity ELSE 0 END) AS total_quantity,
                   AVG(CASE WHEN i.status = 'EXECUTED' THEN i.price ELSE NULL END) AS avg_price
            FROM stock_sip s
            LEFT JOIN stock_sip_installments i ON i.sip_id = s.id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY s.id
            ORDER BY s.created_at DESC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $r['next_due_date'] = $this->getNextDueDate($r);
        }
        $this->json(['status' => 'success', 'data' => $rows]);
    }

    // GET /api/stocks/sip/summary?user_id=X
    public function getSummary(): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        if (!$userId) { $this->error('user_id required', 400); return; }

        $stmt = $this->db->prepare("
            SELECT
                s.stock_symbol, s.stock_name, s.frequency,
                s.sip_amount,
                COUNT(i.id) AS installments_done,
                SUM(CASE WHEN i.status='EXECUTED' THEN i.amount ELSE 0 END) AS total_invested,
                SUM(CASE WHEN i.status='EXECUTED' THEN i.quantity ELSE 0 END) AS total_units,
                AVG(CASE WHEN i.status='EXECUTED' THEN i.price ELSE NULL END) AS avg_cost,
                MIN(CASE WHEN i.status='EXECUTED' THEN i.price ELSE NULL END) AS min_price,
                MAX(CASE WHEN i.status='EXECUTED' THEN i.price ELSE NULL END) AS max_price
            FROM stock_sip s
            LEFT JOIN stock_sip_installments i ON i.sip_id = s.id
            WHERE s.user_id = :uid AND s.is_active = 1
            GROUP BY s.id, s.stock_symbol, s.stock_name, s.frequency, s.sip_amount
        ");
        $stmt->execute([':uid' => $userId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->json([
            'status'          => 'success',
            'data'            => $data,
            'total_monthly_commitment' => $this->calcMonthlyCommitment($userId),
        ]);
    }

    // POST /api/stocks/sip
    public function createSIP(): void {
        $d = $this->input();
        $req = ['user_id','stock_symbol','stock_name','sip_amount','frequency','start_date'];
        foreach ($req as $f) {
            if (empty($d[$f])) { $this->error("$f is required", 400); return; }
        }

        $stmt = $this->db->prepare("
            INSERT INTO stock_sip
                (user_id, stock_symbol, stock_name, exchange, sip_amount, frequency, sip_day, start_date, end_date, broker, notes)
            VALUES (:uid,:sym,:name,:exch,:amount,:freq,:day,:start,:end,:broker,:notes)
        ");
        $stmt->execute([
            ':uid'    => (int)$d['user_id'],
            ':sym'    => strtoupper($d['stock_symbol']),
            ':name'   => $d['stock_name'],
            ':exch'   => $d['exchange'] ?? 'NSE',
            ':amount' => (float)$d['sip_amount'],
            ':freq'   => strtoupper($d['frequency']),
            ':day'    => isset($d['sip_day']) ? (int)$d['sip_day'] : null,
            ':start'  => $d['start_date'],
            ':end'    => $d['end_date'] ?? null,
            ':broker' => $d['broker'] ?? null,
            ':notes'  => $d['notes'] ?? null,
        ]);
        $id = (int)$this->db->lastInsertId();
        $this->json(['status' => 'success', 'id' => $id, 'message' => 'SIP created'], 201);
    }

    // PUT /api/stocks/sip/{id}
    public function updateSIP(int $id): void {
        $d = $this->input();
        $userId = (int)($d['user_id'] ?? 0);
        $allowed = ['stock_name','exchange','sip_amount','frequency','sip_day','start_date','end_date','broker','is_active','notes'];
        $fields = []; $params = [':id' => $id, ':uid' => $userId];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $d)) { $fields[] = "$f = :$f"; $params[":$f"] = $d[$f]; }
        }
        if (empty($fields)) { $this->error('No fields', 400); return; }
        $this->db->prepare('UPDATE stock_sip SET ' . implode(', ', $fields) . ' WHERE id = :id AND user_id = :uid')->execute($params);
        $this->json(['status' => 'success', 'message' => 'Updated']);
    }

    // DELETE /api/stocks/sip/{id}
    public function deleteSIP(int $id): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $stmt = $this->db->prepare('UPDATE stock_sip SET is_active = 0 WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        if ($stmt->rowCount() === 0) { $this->error('Not found', 404); return; }
        $this->json(['status' => 'success', 'message' => 'SIP stopped']);
    }

    // GET /api/stocks/sip/{id}/installments
    public function getInstallments(int $sipId): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $stmt = $this->db->prepare("
            SELECT i.*, s.stock_symbol, s.stock_name
            FROM stock_sip_installments i
            JOIN stock_sip s ON s.id = i.sip_id
            WHERE i.sip_id = :sid AND i.user_id = :uid
            ORDER BY i.installment_date DESC
        ");
        $stmt->execute([':sid' => $sipId, ':uid' => $userId]);
        $this->json(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // POST /api/stocks/sip/{id}/installments
    public function recordInstallment(int $sipId): void {
        $d = $this->input();
        $userId = (int)($d['user_id'] ?? 0);
        $req = ['quantity','price','installment_date'];
        foreach ($req as $f) {
            if (empty($d[$f])) { $this->error("$f is required", 400); return; }
        }

        // Validate SIP belongs to user
        $sip = $this->db->prepare('SELECT id, sip_amount FROM stock_sip WHERE id = :id AND user_id = :uid');
        $sip->execute([':id' => $sipId, ':uid' => $userId]);
        $sipRow = $sip->fetch(PDO::FETCH_ASSOC);
        if (!$sipRow) { $this->error('SIP not found', 404); return; }

        $qty    = (float)$d['quantity'];
        $price  = (float)$d['price'];
        $amount = isset($d['amount']) ? (float)$d['amount'] : round($qty * $price, 2);
        $status = strtoupper($d['status'] ?? 'EXECUTED');

        $stmt = $this->db->prepare("
            INSERT INTO stock_sip_installments (sip_id, user_id, installment_date, quantity, price, amount, status, notes)
            VALUES (:sid, :uid, :date, :qty, :price, :amount, :status, :notes)
        ");
        $stmt->execute([
            ':sid'    => $sipId, ':uid' => $userId,
            ':date'   => $d['installment_date'],
            ':qty'    => $qty, ':price' => $price, ':amount' => $amount,
            ':status' => $status, ':notes' => $d['notes'] ?? null,
        ]);
        $instId = (int)$this->db->lastInsertId();

        // Auto-add to stock_transactions if EXECUTED
        if ($status === 'EXECUTED') {
            $sipSymbol = $this->db->prepare('SELECT stock_symbol, stock_name, exchange FROM stock_sip WHERE id = :id');
            $sipSymbol->execute([':id' => $sipId]);
            $s = $sipSymbol->fetch(PDO::FETCH_ASSOC);
            if ($s) {
                $this->db->prepare("
                    INSERT INTO stock_transactions (user_id, stock_symbol, stock_name, transaction_type, quantity, price, transaction_date, exchange, notes)
                    VALUES (:uid, :sym, :name, 'BUY', :qty, :price, :date, :exch, 'SIP installment')
                ")->execute([
                    ':uid'  => $userId, ':sym' => $s['stock_symbol'], ':name' => $s['stock_name'],
                    ':qty'  => $qty, ':price' => $price, ':date' => $d['installment_date'], ':exch' => $s['exchange'],
                ]);
            }
        }
        $this->json(['status' => 'success', 'id' => $instId, 'amount' => $amount], 201);
    }

    // GET /api/stocks/sip/due-today?user_id=X
    public function getDueToday(): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        if (!$userId) { $this->error('user_id required', 400); return; }

        $today     = date('Y-m-d');
        $dayOfWeek = (int)date('N'); // 1=Mon...7=Sun
        $dayOfMonth= (int)date('j');

        $stmt = $this->db->prepare("
            SELECT s.*
            FROM stock_sip s
            WHERE s.user_id = :uid AND s.is_active = 1
              AND s.start_date <= :today
              AND (s.end_date IS NULL OR s.end_date >= :today)
        ");
        $stmt->execute([':uid' => $userId, ':today' => $today]);
        $sips = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $due = [];
        foreach ($sips as $s) {
            if ($this->isDueToday($s, $dayOfMonth, $dayOfWeek)) {
                // Check if already executed today
                $chk = $this->db->prepare("SELECT id FROM stock_sip_installments WHERE sip_id = :sid AND installment_date = :today AND status = 'EXECUTED'");
                $chk->execute([':sid' => $s['id'], ':today' => $today]);
                if (!$chk->fetch()) $due[] = $s;
            }
        }
        $this->json(['status' => 'success', 'due' => $due, 'count' => count($due)]);
    }

    // ------- helpers -------

    private function isDueToday(array $sip, int $dom, int $dow): bool {
        $sipDay = (int)($sip['sip_day'] ?? 1);
        return match(strtoupper($sip['frequency'])) {
            'DAILY'       => true,
            'WEEKLY'      => $dow === ($sipDay ?: 1),
            'FORTNIGHTLY' => $dom === ($sipDay ?: 1) || $dom === ($sipDay ?: 1) + 14,
            'MONTHLY'     => $dom === ($sipDay ?: 1),
            'QUARTERLY'   => in_array((int)date('n'), [4, 7, 10, 1]) && $dom === ($sipDay ?: 1),
            default       => false,
        };
    }

    private function getNextDueDate(array $sip): ?string {
        if (!$sip['is_active']) return null;
        $today = new DateTime();
        $start = new DateTime($sip['start_date']);
        $base  = $today > $start ? $today : $start;
        $day   = (int)($sip['sip_day'] ?? 1);

        return match(strtoupper($sip['frequency'])) {
            'DAILY'       => $today->format('Y-m-d'),
            'WEEKLY'      => (clone $base)->modify("next " . ['', 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'][$day] ?? 'Monday')->format('Y-m-d'),
            'MONTHLY'     => date('Y-m-' . str_pad($day, 2, '0', STR_PAD_LEFT), strtotime($today->format('Y-m-01') . " +1 month")),
            default       => null,
        };
    }

    private function calcMonthlyCommitment(int $userId): float {
        $stmt = $this->db->prepare("
            SELECT frequency, SUM(sip_amount) AS total
            FROM stock_sip WHERE user_id = :uid AND is_active = 1
            GROUP BY frequency
        ");
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $monthly = 0;
        foreach ($rows as $r) {
            $multiplier = match(strtoupper($r['frequency'])) {
                'DAILY'       => 22,
                'WEEKLY'      => 4.33,
                'FORTNIGHTLY' => 2,
                'MONTHLY'     => 1,
                'QUARTERLY'   => 0.333,
                default       => 1,
            };
            $monthly += (float)$r['total'] * $multiplier;
        }
        return round($monthly, 2);
    }

    private function input(): array { return json_decode(file_get_contents('php://input'), true) ?? []; }
    private function json(mixed $d, int $c = 200): void { http_response_code($c); header('Content-Type: application/json'); echo json_encode($d); }
    private function error(string $m, int $c = 400): void { $this->json(['status' => 'error', 'message' => $m], $c); }
}
