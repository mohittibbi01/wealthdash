<?php
defined('WEALTHDASH') or die();

/**
 * t118: RBI Floating Rate Bonds & G-Secs / T-Bills
 * Security types: RBI_FRB | GSEC | TBILL | SDL
 * Routes (add to router.php):
 *   GET    /api/rbi                         -> getHoldings()
 *   POST   /api/rbi                         -> addHolding()
 *   PUT    /api/rbi/{id}                    -> updateHolding()
 *   DELETE /api/rbi/{id}                    -> deleteHolding()
 *   GET    /api/rbi/summary                 -> getSummary()
 *   GET    /api/rbi/{id}/cashflows          -> getCashflows()
 *   PUT    /api/rbi/cashflows/{id}          -> markReceived()
 *   POST   /api/rbi/{id}/floating-rate      -> updateFloatingRate()
 *   GET    /api/rbi/upcoming                -> getUpcoming()
 */
class RBISecurities {

    private PDO $db;

    // RBI FRB resets semi-annually; reference = NSS Rate declared by GoI
    private const FRB_REFERENCE_SPREAD = 0.35; // 35 bps over NSS rate (current FRB 2020-35 terms)

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // GET /api/rbi?user_id=X&type=RBI_FRB
    public function getHoldings(): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $type   = $_GET['type'] ?? null;
        if (!$userId) { $this->error('user_id required', 400); return; }

        $where = ['user_id = :uid', 'is_active = 1'];
        $params = [':uid' => $userId];
        if ($type) { $where[] = 'security_type = :type'; $params[':type'] = strtoupper($type); }

        $stmt = $this->db->prepare('SELECT * FROM govt_securities WHERE ' . implode(' AND ', $where) . ' ORDER BY maturity_date ASC');
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['days_to_maturity']  = max(0, (int)((strtotime($row['maturity_date']) - time()) / 86400));
            $row['years_to_maturity'] = round($row['days_to_maturity'] / 365, 2);
            $row['invested_value']    = (float)$row['quantity'] * (float)$row['purchase_price'];
            $row['maturity_value']    = (float)$row['quantity'] * (float)($row['redemption_price'] ?? $row['face_value']);
            $row['current_coupon']    = $this->getCurrentCoupon($row);

            if (strtoupper($row['security_type']) === 'TBILL') {
                // T-Bills: discount instrument — show annualized yield
                $days  = max(1, (int)((strtotime($row['maturity_date']) - strtotime($row['purchase_date'])) / 86400));
                $ytm   = (($row['maturity_value'] - $row['invested_value']) / $row['invested_value']) * (365 / $days) * 100;
                $row['yield_pct'] = round($ytm, 4);
            } else {
                $row['yield_pct'] = $row['is_floating'] ? $row['current_coupon'] : (float)$row['coupon_rate'];
            }
        }

        $this->json(['status' => 'success', 'data' => $rows]);
    }

    // GET /api/rbi/summary?user_id=X
    public function getSummary(): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        if (!$userId) { $this->error('user_id required', 400); return; }

        $stmt = $this->db->prepare("
            SELECT
                security_type,
                COUNT(*) AS count,
                SUM(quantity * purchase_price) AS total_invested,
                SUM(quantity * face_value) AS total_face_value,
                AVG(coupon_rate) AS avg_coupon,
                MIN(maturity_date) AS earliest_maturity,
                MAX(maturity_date) AS latest_maturity
            FROM govt_securities
            WHERE user_id = :uid AND is_active = 1
            GROUP BY security_type
        ");
        $stmt->execute([':uid' => $userId]);
        $byType = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Pending cashflows next 180 days
        $upcoming = $this->db->prepare("
            SELECT gs.security_name, gs.security_type, gc.cashflow_type, gc.scheduled_date, gc.amount
            FROM gsec_cashflows gc
            JOIN govt_securities gs ON gs.id = gc.security_id
            WHERE gc.user_id = :uid AND gc.received = 0
              AND gc.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 180 DAY)
            ORDER BY gc.scheduled_date
        ");
        $upcoming->execute([':uid' => $userId]);

        $this->json([
            'status'        => 'success',
            'by_type'       => $byType,
            'upcoming_180d' => $upcoming->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    // POST /api/rbi
    public function addHolding(): void {
        $d = $this->input();
        $req = ['user_id','security_type','security_name','face_value','quantity','purchase_price','purchase_date','maturity_date'];
        foreach ($req as $f) {
            if (!isset($d[$f]) || $d[$f] === '') { $this->error("$f is required", 400); return; }
        }

        $isTBill   = strtoupper($d['security_type']) === 'TBILL';
        $isFRB     = strtoupper($d['security_type']) === 'RBI_FRB';

        $stmt = $this->db->prepare("
            INSERT INTO govt_securities
                (user_id, security_type, security_name, isin, face_value, quantity, purchase_price,
                 purchase_date, maturity_date, coupon_rate, coupon_frequency, is_floating,
                 floating_reference, floating_spread, platform, redemption_price, notes)
            VALUES
                (:uid,:stype,:sname,:isin,:fv,:qty,:pp,
                 :pd,:md,:cr,:cf,:isfloat,
                 :fref,:fspread,:platform,:rprice,:notes)
        ");
        $stmt->execute([
            ':uid'      => (int)$d['user_id'],
            ':stype'    => strtoupper($d['security_type']),
            ':sname'    => $d['security_name'],
            ':isin'     => $d['isin'] ?? null,
            ':fv'       => (float)$d['face_value'],
            ':qty'      => (int)$d['quantity'],
            ':pp'       => (float)$d['purchase_price'],
            ':pd'       => $d['purchase_date'],
            ':md'       => $d['maturity_date'],
            ':cr'       => $isTBill ? null : (float)($d['coupon_rate'] ?? 0),
            ':cf'       => $isTBill ? null : ($d['coupon_frequency'] ?? ($isFRB ? 'SEMI_ANNUAL' : 'SEMI_ANNUAL')),
            ':isfloat'  => $isFRB ? 1 : 0,
            ':fref'     => $isFRB ? ($d['floating_reference'] ?? 'NSS Rate') : null,
            ':fspread'  => $isFRB ? (float)($d['floating_spread'] ?? self::FRB_REFERENCE_SPREAD) : null,
            ':platform' => $d['platform'] ?? null,
            ':rprice'   => $isTBill ? (float)$d['face_value'] : null,
            ':notes'    => $d['notes'] ?? null,
        ]);
        $id = (int)$this->db->lastInsertId();

        if (!$isTBill) {
            $this->generateCashflows((int)$d['user_id'], $id);
        } else {
            // T-Bill: only maturity cashflow
            $fv  = (float)$d['face_value'] * (int)$d['quantity'];
            $this->db->prepare("
                INSERT INTO gsec_cashflows (user_id, security_id, cashflow_type, scheduled_date, amount)
                VALUES (:uid, :sid, 'MATURITY', :date, :amount)
            ")->execute([':uid' => (int)$d['user_id'], ':sid' => $id, ':date' => $d['maturity_date'], ':amount' => $fv]);
        }

        $this->json(['status' => 'success', 'id' => $id, 'message' => 'Security added and cashflows scheduled'], 201);
    }

    // PUT /api/rbi/{id}
    public function updateHolding(int $id): void {
        $d = $this->input();
        $userId = (int)($d['user_id'] ?? 0);
        $allowed = ['security_name','isin','face_value','quantity','purchase_price','purchase_date',
                    'maturity_date','coupon_rate','coupon_frequency','floating_reference','floating_spread','platform','notes'];
        $fields = []; $params = [':id' => $id, ':uid' => $userId];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $d)) { $fields[] = "$f = :$f"; $params[":$f"] = $d[$f]; }
        }
        if (empty($fields)) { $this->error('No fields', 400); return; }
        $this->db->prepare('UPDATE govt_securities SET ' . implode(', ', $fields) . ' WHERE id = :id AND user_id = :uid')->execute($params);

        if (array_intersect(['coupon_rate','coupon_frequency','maturity_date'], array_keys($d))) {
            $this->db->prepare('DELETE FROM gsec_cashflows WHERE security_id = :id AND received = 0')->execute([':id' => $id]);
            $this->generateCashflows($userId, $id);
        }
        $this->json(['status' => 'success', 'message' => 'Updated']);
    }

    // DELETE /api/rbi/{id}
    public function deleteHolding(int $id): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $stmt = $this->db->prepare('UPDATE govt_securities SET is_active = 0 WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        if ($stmt->rowCount() === 0) { $this->error('Not found', 404); return; }
        $this->json(['status' => 'success', 'message' => 'Deleted']);
    }

    // GET /api/rbi/{id}/cashflows
    public function getCashflows(int $secId): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $stmt = $this->db->prepare("
            SELECT gc.*, gs.security_name, gs.security_type
            FROM gsec_cashflows gc
            JOIN govt_securities gs ON gs.id = gc.security_id
            WHERE gc.security_id = :sid AND gc.user_id = :uid
            ORDER BY gc.scheduled_date
        ");
        $stmt->execute([':sid' => $secId, ':uid' => $userId]);
        $this->json(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // PUT /api/rbi/cashflows/{id}
    public function markReceived(int $cfId): void {
        $d = $this->input();
        $userId = (int)($d['user_id'] ?? 0);
        $tds    = (float)($d['tds_deducted'] ?? 0);

        $cf = $this->db->prepare('SELECT * FROM gsec_cashflows WHERE id = :id AND user_id = :uid');
        $cf->execute([':id' => $cfId, ':uid' => $userId]);
        $row = $cf->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $this->error('Not found', 404); return; }

        $net = (float)$row['amount'] - $tds;
        $this->db->prepare("
            UPDATE gsec_cashflows SET received = 1, received_date = :rd, tds_deducted = :tds, net_amount = :net
            WHERE id = :id
        ")->execute([':rd' => $d['received_date'] ?? date('Y-m-d'), ':tds' => $tds, ':net' => $net, ':id' => $cfId]);

        $this->json(['status' => 'success', 'message' => 'Marked received', 'net_amount' => $net]);
    }

    /**
     * POST /api/rbi/{id}/floating-rate
     * Update coupon rate for next period (FRB rate reset)
     */
    public function updateFloatingRate(int $id): void {
        $d = $this->input();
        $userId     = (int)($d['user_id'] ?? 0);
        $newRate    = (float)($d['new_coupon_rate'] ?? 0);
        $effectiveFrom = $d['effective_from'] ?? date('Y-m-d');

        if (!$newRate) { $this->error('new_coupon_rate required', 400); return; }

        $sec = $this->db->prepare('SELECT * FROM govt_securities WHERE id = :id AND user_id = :uid AND is_floating = 1');
        $sec->execute([':id' => $id, ':uid' => $userId]);
        $row = $sec->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $this->error('FRB holding not found', 404); return; }

        // Update rate
        $this->db->prepare('UPDATE govt_securities SET coupon_rate = :rate, updated_at = NOW() WHERE id = :id')
                 ->execute([':rate' => $newRate, ':id' => $id]);

        // Regenerate future (unreceived) coupons with new rate
        $this->db->prepare("DELETE FROM gsec_cashflows WHERE security_id = :id AND received = 0 AND scheduled_date >= :from")
                 ->execute([':id' => $id, ':from' => $effectiveFrom]);
        $this->generateCashflows($userId, $id, $effectiveFrom, $newRate);

        $this->json(['status' => 'success', 'message' => "Rate updated to $newRate% from $effectiveFrom"]);
    }

    // GET /api/rbi/upcoming?user_id=X&days=90
    public function getUpcoming(): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $days   = (int)($_GET['days'] ?? 90);
        if (!$userId) { $this->error('user_id required', 400); return; }

        $stmt = $this->db->prepare("
            SELECT gs.security_name, gs.security_type, gs.is_floating,
                   gc.cashflow_type, gc.scheduled_date, gc.amount,
                   gc.coupon_rate_applied, gc.received
            FROM gsec_cashflows gc
            JOIN govt_securities gs ON gs.id = gc.security_id
            WHERE gc.user_id = :uid AND gc.received = 0
              AND gc.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)
            ORDER BY gc.scheduled_date
        ");
        $stmt->execute([':uid' => $userId, ':days' => $days]);
        $this->json(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ------- helpers -------

    private function generateCashflows(int $userId, int $secId, string $from = null, float $overrideRate = null): void {
        $stmt = $this->db->prepare('SELECT * FROM govt_securities WHERE id = :id');
        $stmt->execute([':id' => $secId]);
        $s = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$s || strtoupper($s['security_type']) === 'TBILL') return;

        $fv   = (float)$s['face_value'] * (int)$s['quantity'];
        $rate = $overrideRate ?? (float)$s['coupon_rate'];
        $freq = $s['coupon_frequency'];
        $start= $from ?? $s['purchase_date'];
        $end  = $s['maturity_date'];

        $monthsMap = ['SEMI_ANNUAL' => 6, 'QUARTERLY' => 3, 'ANNUAL' => 12, 'FLOATING' => 6];
        $months    = $monthsMap[$freq] ?? 6;
        $periodic  = $fv * ($rate / 100) * ($months / 12);

        $current = strtotime($start);
        $endTs   = strtotime($end);

        while (true) {
            $current = strtotime("+$months months", $current);
            if ($current > $endTs) break;
            $date = date('Y-m-d', $current);
            $this->db->prepare("
                INSERT INTO gsec_cashflows (user_id, security_id, cashflow_type, scheduled_date, amount, coupon_rate_applied)
                VALUES (:uid, :sid, 'COUPON', :date, :amount, :rate)
            ")->execute([':uid' => $userId, ':sid' => $secId, ':date' => $date, ':amount' => round($periodic, 2), ':rate' => $rate]);
        }

        // Maturity / principal
        $this->db->prepare("
            INSERT INTO gsec_cashflows (user_id, security_id, cashflow_type, scheduled_date, amount)
            VALUES (:uid, :sid, 'MATURITY', :date, :amount)
            ON DUPLICATE KEY UPDATE amount = VALUES(amount)
        ")->execute([':uid' => $userId, ':sid' => $secId, ':date' => $end, ':amount' => $fv]);
    }

    private function getCurrentCoupon(array $row): ?float {
        if (!$row['is_floating']) return (float)$row['coupon_rate'];
        // Floating: coupon_rate column stores the latest applied rate
        return (float)($row['coupon_rate'] ?? 0);
    }

    private function input(): array { return json_decode(file_get_contents('php://input'), true) ?? []; }
    private function json(mixed $d, int $c = 200): void { http_response_code($c); header('Content-Type: application/json'); echo json_encode($d); }
    private function error(string $m, int $c = 400): void { $this->json(['status' => 'error', 'message' => $m], $c); }
}
