<?php
defined('WEALTHDASH') or die();

/**
 * t116: Corporate Bonds / NCDs — Listed and Unlisted
 * Routes (add to router.php):
 *   GET    /api/bonds                   -> getHoldings()
 *   POST   /api/bonds                   -> addHolding()
 *   PUT    /api/bonds/{id}              -> updateHolding()
 *   DELETE /api/bonds/{id}              -> deleteHolding()
 *   GET    /api/bonds/summary           -> getSummary()
 *   GET    /api/bonds/{id}/cashflows    -> getCashflows()
 *   POST   /api/bonds/{id}/cashflows    -> generateCashflows()
 *   PUT    /api/bonds/cashflows/{id}    -> markReceived()
 *   GET    /api/bonds/upcoming          -> getUpcoming()
 */
class BondsTracker {

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // GET /api/bonds?user_id=X&type=NCD&listing=LISTED
    public function getHoldings(): void {
        $userId  = (int)($_GET['user_id'] ?? 0);
        $type    = $_GET['type'] ?? null;
        $listing = $_GET['listing'] ?? null;
        if (!$userId) { $this->error('user_id required', 400); return; }

        $where = ['user_id = :uid', 'is_active = 1'];
        $params = [':uid' => $userId];
        if ($type)    { $where[] = 'bond_type = :type';         $params[':type'] = strtoupper($type); }
        if ($listing) { $where[] = 'listing_type = :listing';   $params[':listing'] = strtoupper($listing); }

        $stmt = $this->db->prepare('SELECT * FROM bonds WHERE ' . implode(' AND ', $where) . ' ORDER BY maturity_date ASC');
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['current_value']   = $this->calcCurrentValue($row);
            $row['days_to_maturity']= max(0, (int)((strtotime($row['maturity_date']) - time()) / 86400));
            $row['ytm']             = $this->calcYTM($row);
            $row['accrued_interest']= $this->calcAccruedInterest($row);
        }

        $this->json(['status' => 'success', 'data' => $rows]);
    }

    // GET /api/bonds/summary?user_id=X
    public function getSummary(): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        if (!$userId) { $this->error('user_id required', 400); return; }

        $stmt = $this->db->prepare("
            SELECT
                bond_type,
                listing_type,
                COUNT(*) AS count,
                SUM(quantity * purchase_price) AS total_invested,
                SUM(quantity * face_value) AS total_face_value,
                AVG(coupon_rate) AS avg_coupon_rate,
                MIN(maturity_date) AS earliest_maturity,
                MAX(maturity_date) AS latest_maturity
            FROM bonds
            WHERE user_id = :uid AND is_active = 1
            GROUP BY bond_type, listing_type
        ");
        $stmt->execute([':uid' => $userId]);
        $summary = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Upcoming interest in next 90 days
        $upcoming = $this->db->prepare("
            SELECT b.issuer_name, bc.scheduled_date, bc.amount, bc.cashflow_type
            FROM bond_cashflows bc
            JOIN bonds b ON b.id = bc.bond_id
            WHERE bc.user_id = :uid AND bc.received = 0
              AND bc.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)
            ORDER BY bc.scheduled_date
        ");
        $upcoming->execute([':uid' => $userId]);

        $this->json([
            'status'   => 'success',
            'by_type'  => $summary,
            'upcoming_cashflows_90d' => $upcoming->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    // POST /api/bonds
    public function addHolding(): void {
        $d = $this->input();
        $req = ['user_id','bond_type','listing_type','issuer_name','face_value','quantity','purchase_price','purchase_date','maturity_date','coupon_rate'];
        foreach ($req as $f) {
            if (!isset($d[$f]) || $d[$f] === '') { $this->error("$f is required", 400); return; }
        }

        $stmt = $this->db->prepare("
            INSERT INTO bonds
                (user_id, bond_type, listing_type, issuer_name, isin, series, face_value, quantity,
                 purchase_price, purchase_date, maturity_date, coupon_rate, coupon_frequency,
                 credit_rating, rating_agency, secured, broker, dp_id, redemption_type, notes)
            VALUES
                (:uid,:btype,:ltype,:issuer,:isin,:series,:fv,:qty,
                 :pp,:pd,:md,:cr,:cf,
                 :rating,:agency,:secured,:broker,:dp,:rtype,:notes)
        ");
        $stmt->execute([
            ':uid'     => (int)$d['user_id'],
            ':btype'   => strtoupper($d['bond_type']),
            ':ltype'   => strtoupper($d['listing_type']),
            ':issuer'  => $d['issuer_name'],
            ':isin'    => $d['isin'] ?? null,
            ':series'  => $d['series'] ?? null,
            ':fv'      => (float)$d['face_value'],
            ':qty'     => (int)$d['quantity'],
            ':pp'      => (float)$d['purchase_price'],
            ':pd'      => $d['purchase_date'],
            ':md'      => $d['maturity_date'],
            ':cr'      => (float)$d['coupon_rate'],
            ':cf'      => $d['coupon_frequency'] ?? 'ANNUAL',
            ':rating'  => $d['credit_rating'] ?? null,
            ':agency'  => $d['rating_agency'] ?? null,
            ':secured' => isset($d['secured']) ? (int)$d['secured'] : 1,
            ':broker'  => $d['broker'] ?? null,
            ':dp'      => $d['dp_id'] ?? null,
            ':rtype'   => $d['redemption_type'] ?? 'BULLET',
            ':notes'   => $d['notes'] ?? null,
        ]);
        $id = (int)$this->db->lastInsertId();
        $this->generateCashflowsForBond((int)$d['user_id'], $id);

        $this->json(['status' => 'success', 'id' => $id, 'message' => 'Bond added and cashflows generated'], 201);
    }

    // PUT /api/bonds/{id}
    public function updateHolding(int $id): void {
        $d = $this->input();
        $userId = (int)($d['user_id'] ?? 0);
        $allowed = ['issuer_name','isin','series','face_value','quantity','purchase_price','purchase_date',
                    'maturity_date','coupon_rate','coupon_frequency','credit_rating','rating_agency',
                    'secured','broker','dp_id','redemption_type','notes'];
        $fields = []; $params = [':id' => $id, ':uid' => $userId];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $d)) { $fields[] = "$f = :$f"; $params[":$f"] = $d[$f]; }
        }
        if (empty($fields)) { $this->error('No valid fields', 400); return; }

        $this->db->prepare('UPDATE bonds SET ' . implode(', ', $fields) . ' WHERE id = :id AND user_id = :uid')->execute($params);

        // Regenerate cashflows if coupon/date changed
        if (array_intersect(['coupon_rate','coupon_frequency','maturity_date','purchase_date'], array_keys($d))) {
            $this->db->prepare('DELETE FROM bond_cashflows WHERE bond_id = :id AND received = 0')->execute([':id' => $id]);
            $this->generateCashflowsForBond($userId, $id);
        }
        $this->json(['status' => 'success', 'message' => 'Updated']);
    }

    // DELETE /api/bonds/{id}
    public function deleteHolding(int $id): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $stmt = $this->db->prepare('UPDATE bonds SET is_active = 0 WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        if ($stmt->rowCount() === 0) { $this->error('Not found', 404); return; }
        $this->json(['status' => 'success', 'message' => 'Deleted']);
    }

    // GET /api/bonds/{id}/cashflows
    public function getCashflows(int $bondId): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $stmt = $this->db->prepare('SELECT * FROM bond_cashflows WHERE bond_id = :bid AND user_id = :uid ORDER BY scheduled_date');
        $stmt->execute([':bid' => $bondId, ':uid' => $userId]);
        $this->json(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // PUT /api/bonds/cashflows/{id} — mark as received
    public function markReceived(int $cfId): void {
        $d = $this->input();
        $userId = (int)($d['user_id'] ?? 0);
        $tds    = (float)($d['tds_deducted'] ?? 0);

        $cf = $this->db->prepare('SELECT * FROM bond_cashflows WHERE id = :id AND user_id = :uid');
        $cf->execute([':id' => $cfId, ':uid' => $userId]);
        $row = $cf->fetch(PDO::FETCH_ASSOC);
        if (!$row) { $this->error('Not found', 404); return; }

        $net = (float)$row['amount'] - $tds;
        $this->db->prepare("
            UPDATE bond_cashflows SET received = 1, received_date = :rd, tds_deducted = :tds, net_amount = :net
            WHERE id = :id
        ")->execute([':rd' => $d['received_date'] ?? date('Y-m-d'), ':tds' => $tds, ':net' => $net, ':id' => $cfId]);

        $this->json(['status' => 'success', 'message' => 'Marked received', 'net_amount' => $net]);
    }

    // GET /api/bonds/upcoming?user_id=X&days=30
    public function getUpcoming(): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $days   = (int)($_GET['days'] ?? 30);
        if (!$userId) { $this->error('user_id required', 400); return; }

        $stmt = $this->db->prepare("
            SELECT b.issuer_name, b.bond_type, b.credit_rating,
                   bc.cashflow_type, bc.scheduled_date, bc.amount, bc.received
            FROM bond_cashflows bc
            JOIN bonds b ON b.id = bc.bond_id
            WHERE bc.user_id = :uid AND bc.received = 0
              AND bc.scheduled_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL :days DAY)
            ORDER BY bc.scheduled_date
        ");
        $stmt->execute([':uid' => $userId, ':days' => $days]);
        $this->json(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // Auto-generate coupon cashflows for a bond
    private function generateCashflowsForBond(int $userId, int $bondId): void {
        $stmt = $this->db->prepare('SELECT * FROM bonds WHERE id = :id');
        $stmt->execute([':id' => $bondId]);
        $b = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$b) return;

        $fv    = (float)$b['face_value'];
        $qty   = (int)$b['quantity'];
        $rate  = (float)$b['coupon_rate'] / 100;
        $freq  = $b['coupon_frequency'];
        $start = $b['purchase_date'];
        $end   = $b['maturity_date'];

        if ($freq === 'CUMULATIVE' || $freq === 'ON_MATURITY') {
            // Single payout at maturity
            $total = $fv * $qty * $rate * (strtotime($end) - strtotime($start)) / (365 * 86400);
            $this->insertCashflow($userId, $bondId, 'COUPON', $end, round($total, 2));
        } else {
            $monthsMap = ['MONTHLY' => 1, 'QUARTERLY' => 3, 'SEMI_ANNUAL' => 6, 'ANNUAL' => 12];
            $months    = $monthsMap[$freq] ?? 12;
            $periodic  = $fv * $qty * $rate * ($months / 12);

            $current = strtotime($start);
            $endTs   = strtotime($end);
            while (true) {
                $current = strtotime("+$months months", $current);
                if ($current > $endTs) break;
                $date = date('Y-m-d', min($current, $endTs));
                $this->insertCashflow($userId, $bondId, 'COUPON', $date, round($periodic, 2));
            }
        }
        // Principal at maturity
        $this->insertCashflow($userId, $bondId, 'PRINCIPAL', $end, $fv * $qty);
    }

    private function insertCashflow(int $userId, int $bondId, string $type, string $date, float $amount): void {
        $this->db->prepare("
            INSERT INTO bond_cashflows (user_id, bond_id, cashflow_type, scheduled_date, amount)
            VALUES (:uid, :bid, :type, :date, :amount)
        ")->execute([':uid' => $userId, ':bid' => $bondId, ':type' => $type, ':date' => $date, ':amount' => $amount]);
    }

    private function calcCurrentValue(array $b): float {
        // Simplified: face value * qty (use YTM pricing for accuracy)
        return (float)$b['face_value'] * (int)$b['quantity'];
    }

    private function calcAccruedInterest(array $b): float {
        $lastCoupon = $b['purchase_date'];
        $daysAccrued = (time() - strtotime($lastCoupon)) / 86400;
        return round((float)$b['face_value'] * (int)$b['quantity'] * ((float)$b['coupon_rate'] / 100) * ($daysAccrued / 365), 2);
    }

    private function calcYTM(array $b): ?float {
        // Newton-Raphson approximation of YTM
        $price  = (float)$b['purchase_price'] * (int)$b['quantity'];
        $fv     = (float)$b['face_value'] * (int)$b['quantity'];
        $coupon = $fv * (float)$b['coupon_rate'] / 100;
        $n      = max(1, (strtotime($b['maturity_date']) - strtotime($b['purchase_date'])) / (365 * 86400));
        if ($price <= 0) return null;
        // Simple approximation
        return round((($coupon + ($fv - $price) / $n) / (($fv + $price) / 2)) * 100, 4);
    }

    private function input(): array { return json_decode(file_get_contents('php://input'), true) ?? []; }
    private function json(mixed $d, int $c = 200): void { http_response_code($c); header('Content-Type: application/json'); echo json_encode($d); }
    private function error(string $m, int $c = 400): void { $this->json(['status' => 'error', 'message' => $m], $c); }
}
