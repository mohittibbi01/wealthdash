<?php
defined('WEALTHDASH') or die();

/**
 * t435: Watchlist with Price Targets
 * Routes (add to router.php):
 *   GET    /api/watchlist              -> getWatchlist()
 *   POST   /api/watchlist              -> addToWatchlist()
 *   PUT    /api/watchlist/{id}         -> updateWatchlist()
 *   DELETE /api/watchlist/{id}         -> removeFromWatchlist()
 *   POST   /api/watchlist/bulk-remove  -> bulkRemove()
 *   GET    /api/watchlist/alerts       -> getPriceAlerts()
 *   POST   /api/watchlist/refresh      -> refreshPrices()
 */
class WatchlistManager {

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // GET /api/watchlist?user_id=X&tag=growth&sort=added_date
    public function getWatchlist(): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $tag    = $_GET['tag'] ?? null;
        $sort   = $_GET['sort'] ?? 'added_date';
        $dir    = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        if (!$userId) { $this->error('user_id required', 400); return; }

        $allowed_sorts = ['added_date','stock_symbol','buy_target','current_price'];
        $sort = in_array($sort, $allowed_sorts) ? $sort : 'added_date';

        $where = ['user_id = :uid', 'is_active = 1'];
        $params = [':uid' => $userId];
        if ($tag) { $where[] = 'FIND_IN_SET(:tag, tags)'; $params[':tag'] = $tag; }

        $stmt = $this->db->prepare("
            SELECT * FROM watchlist
            WHERE " . implode(' AND ', $where) . "
            ORDER BY $sort $dir
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['buy_gap_pct']  = $this->gapPct($row['current_price'], $row['buy_target']);
            $row['sell_gap_pct'] = $this->gapPct($row['current_price'], $row['sell_target']);
            $row['sl_gap_pct']   = $this->gapPct($row['current_price'], $row['stop_loss']);
            $row['status']       = $this->priceStatus($row);
        }

        $this->json(['status' => 'success', 'data' => $rows, 'count' => count($rows)]);
    }

    // POST /api/watchlist
    public function addToWatchlist(): void {
        $d = $this->input();
        $req = ['user_id','stock_symbol','stock_name'];
        foreach ($req as $f) {
            if (empty($d[$f])) { $this->error("$f is required", 400); return; }
        }
        $userId = (int)$d['user_id'];
        $symbol = strtoupper($d['stock_symbol']);

        // Check duplicate
        $dup = $this->db->prepare('SELECT id FROM watchlist WHERE user_id = :uid AND stock_symbol = :sym');
        $dup->execute([':uid' => $userId, ':sym' => $symbol]);
        if ($dup->fetch()) { $this->error('Already in watchlist', 409); return; }

        $stmt = $this->db->prepare("
            INSERT INTO watchlist
                (user_id, stock_symbol, stock_name, exchange, isin, buy_target, sell_target, stop_loss,
                 current_price, rationale, sector, tags, alert_on_buy_target, alert_on_sell_target, alert_on_stop_loss, notes, added_date)
            VALUES
                (:uid,:sym,:name,:exch,:isin,:bt,:st,:sl,
                 :cp,:rationale,:sector,:tags,:abt,:ast,:asl,:notes,CURDATE())
        ");
        $stmt->execute([
            ':uid'      => $userId,
            ':sym'      => $symbol,
            ':name'     => $d['stock_name'],
            ':exch'     => $d['exchange'] ?? 'NSE',
            ':isin'     => $d['isin'] ?? null,
            ':bt'       => isset($d['buy_target']) ? (float)$d['buy_target'] : null,
            ':st'       => isset($d['sell_target']) ? (float)$d['sell_target'] : null,
            ':sl'       => isset($d['stop_loss']) ? (float)$d['stop_loss'] : null,
            ':cp'       => isset($d['current_price']) ? (float)$d['current_price'] : null,
            ':rationale'=> $d['rationale'] ?? null,
            ':sector'   => $d['sector'] ?? null,
            ':tags'     => $d['tags'] ?? null,
            ':abt'      => (int)($d['alert_on_buy_target'] ?? 1),
            ':ast'      => (int)($d['alert_on_sell_target'] ?? 1),
            ':asl'      => (int)($d['alert_on_stop_loss'] ?? 1),
            ':notes'    => $d['notes'] ?? null,
        ]);
        $id = (int)$this->db->lastInsertId();
        $this->json(['status' => 'success', 'id' => $id, 'message' => 'Added to watchlist'], 201);
    }

    // PUT /api/watchlist/{id}
    public function updateWatchlist(int $id): void {
        $d = $this->input();
        $userId = (int)($d['user_id'] ?? 0);
        $allowed = ['stock_name','exchange','isin','buy_target','sell_target','stop_loss','current_price',
                    'rationale','sector','tags','alert_on_buy_target','alert_on_sell_target','alert_on_stop_loss','notes'];
        $fields = []; $params = [':id' => $id, ':uid' => $userId];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $d)) { $fields[] = "$f = :$f"; $params[":$f"] = $d[$f]; }
        }
        if (empty($fields)) { $this->error('No valid fields', 400); return; }

        $stmt = $this->db->prepare('UPDATE watchlist SET ' . implode(', ', $fields) . ' WHERE id = :id AND user_id = :uid');
        $stmt->execute($params);
        if ($stmt->rowCount() === 0) { $this->error('Not found', 404); return; }

        // Save price to history if price updated
        if (isset($d['current_price'])) {
            $this->db->prepare('INSERT INTO watchlist_price_history (watchlist_id, price) VALUES (:id, :price)')
                     ->execute([':id' => $id, ':price' => (float)$d['current_price']]);
        }
        $this->json(['status' => 'success', 'message' => 'Updated']);
    }

    // DELETE /api/watchlist/{id}
    public function removeFromWatchlist(int $id): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $stmt = $this->db->prepare('UPDATE watchlist SET is_active = 0 WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        if ($stmt->rowCount() === 0) { $this->error('Not found', 404); return; }
        $this->json(['status' => 'success', 'message' => 'Removed']);
    }

    // POST /api/watchlist/bulk-remove
    public function bulkRemove(): void {
        $d = $this->input();
        $userId = (int)($d['user_id'] ?? 0);
        $ids    = array_filter(array_map('intval', $d['ids'] ?? []));
        if (!$userId || empty($ids)) { $this->error('user_id and ids required', 400); return; }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge([$userId], $ids);
        $stmt = $this->db->prepare("UPDATE watchlist SET is_active = 0 WHERE user_id = ? AND id IN ($placeholders)");
        $stmt->execute($params);
        $this->json(['status' => 'success', 'removed' => $stmt->rowCount()]);
    }

    // GET /api/watchlist/alerts?user_id=X
    public function getPriceAlerts(): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        if (!$userId) { $this->error('user_id required', 400); return; }

        $stmt = $this->db->prepare("
            SELECT id, stock_symbol, stock_name, current_price,
                   buy_target, sell_target, stop_loss,
                   alert_on_buy_target, alert_on_sell_target, alert_on_stop_loss
            FROM watchlist
            WHERE user_id = :uid AND is_active = 1 AND current_price IS NOT NULL
        ");
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $alerts = [];
        foreach ($rows as $r) {
            $cp = (float)$r['current_price'];
            if ($r['alert_on_buy_target'] && $r['buy_target'] !== null && $cp <= (float)$r['buy_target']) {
                $alerts[] = ['symbol' => $r['stock_symbol'], 'type' => 'BUY_TARGET_HIT', 'price' => $cp, 'target' => $r['buy_target']];
            }
            if ($r['alert_on_sell_target'] && $r['sell_target'] !== null && $cp >= (float)$r['sell_target']) {
                $alerts[] = ['symbol' => $r['stock_symbol'], 'type' => 'SELL_TARGET_HIT', 'price' => $cp, 'target' => $r['sell_target']];
            }
            if ($r['alert_on_stop_loss'] && $r['stop_loss'] !== null && $cp <= (float)$r['stop_loss']) {
                $alerts[] = ['symbol' => $r['stock_symbol'], 'type' => 'STOP_LOSS_HIT', 'price' => $cp, 'target' => $r['stop_loss']];
            }
        }
        $this->json(['status' => 'success', 'alerts' => $alerts, 'count' => count($alerts)]);
    }

    // POST /api/watchlist/refresh — bulk price update
    public function refreshPrices(): void {
        $d      = $this->input();
        $userId = (int)($d['user_id'] ?? 0);
        $prices = $d['prices'] ?? []; // [['symbol' => 'TCS', 'price' => 3450.50], ...]
        if (!$userId || empty($prices)) { $this->error('user_id and prices required', 400); return; }

        $updated = 0;
        foreach ($prices as $p) {
            if (empty($p['symbol']) || !isset($p['price'])) continue;
            $sym = strtoupper($p['symbol']);
            $stmt = $this->db->prepare('UPDATE watchlist SET current_price = :cp WHERE user_id = :uid AND stock_symbol = :sym AND is_active = 1');
            $stmt->execute([':cp' => (float)$p['price'], ':uid' => $userId, ':sym' => $sym]);

            if ($stmt->rowCount()) {
                // get watchlist id for history
                $wid = $this->db->prepare('SELECT id FROM watchlist WHERE user_id = :uid AND stock_symbol = :sym AND is_active = 1');
                $wid->execute([':uid' => $userId, ':sym' => $sym]);
                $wrow = $wid->fetch(PDO::FETCH_ASSOC);
                if ($wrow) {
                    $this->db->prepare('INSERT INTO watchlist_price_history (watchlist_id, price) VALUES (:id, :price)')
                             ->execute([':id' => $wrow['id'], ':price' => (float)$p['price']]);
                }
                $updated++;
            }
        }
        $this->json(['status' => 'success', 'updated' => $updated]);
    }

    // ------- helpers -------

    private function gapPct(?float $current, ?float $target): ?float {
        if ($current === null || $target === null || $current == 0) return null;
        return round((($target - $current) / $current) * 100, 2);
    }

    private function priceStatus(array $row): string {
        $cp = (float)($row['current_price'] ?? 0);
        if (!$cp) return 'no_price';
        if ($row['stop_loss'] !== null && $cp <= (float)$row['stop_loss']) return 'stop_loss_hit';
        if ($row['buy_target'] !== null && $cp <= (float)$row['buy_target']) return 'buy_zone';
        if ($row['sell_target'] !== null && $cp >= (float)$row['sell_target']) return 'sell_zone';
        return 'watching';
    }

    private function input(): array { return json_decode(file_get_contents('php://input'), true) ?? []; }
    private function json(mixed $d, int $c = 200): void { http_response_code($c); header('Content-Type: application/json'); echo json_encode($d); }
    private function error(string $m, int $c = 400): void { $this->json(['status' => 'error', 'message' => $m], $c); }
}
