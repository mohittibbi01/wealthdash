<?php
defined('WEALTHDASH') or die();

/**
 * t38: Stocks Screener — Basic Filter + Sort
 * Routes (add to router.php):
 *   GET    /api/screener                    -> screen()
 *   GET    /api/screener/filters            -> getSavedFilters()
 *   POST   /api/screener/filters            -> saveFilter()
 *   DELETE /api/screener/filters/{id}       -> deleteFilter()
 *   POST   /api/screener/universe/refresh   -> refreshUniverse()
 */
class StocksScreener {

    private PDO $db;

    // Allowed filter fields => [type, column]
    private const FILTER_FIELDS = [
        'pe_ratio'           => ['numeric', 'su.pe_ratio'],
        'pb_ratio'           => ['numeric', 'su.pb_ratio'],
        'roe'                => ['numeric', 'su.roe'],
        'roce'               => ['numeric', 'su.roce'],
        'debt_to_equity'     => ['numeric', 'su.debt_to_equity'],
        'current_ratio'      => ['numeric', 'su.current_ratio'],
        'dividend_yield'     => ['numeric', 'su.dividend_yield'],
        'revenue_growth_1y'  => ['numeric', 'su.revenue_growth_1y'],
        'profit_growth_1y'   => ['numeric', 'su.profit_growth_1y'],
        'market_cap'         => ['numeric', 'su.market_cap'],
        'current_price'      => ['numeric', 'su.current_price'],
        'price_52w_high'     => ['numeric', 'su.price_52w_high'],
        'price_52w_low'      => ['numeric', 'su.price_52w_low'],
        'avg_volume_30d'     => ['numeric', 'su.avg_volume_30d'],
        'sector'             => ['enum',    'su.sector'],
        'market_cap_category'=> ['enum',    'su.market_cap_category'],
        'exchange'           => ['enum',    'su.exchange'],
    ];

    private const SORT_FIELDS = [
        'pe_ratio','pb_ratio','roe','roce','debt_to_equity','dividend_yield',
        'revenue_growth_1y','profit_growth_1y','market_cap','current_price',
        'stock_symbol','stock_name','avg_volume_30d',
    ];

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * GET /api/screener?pe_ratio[min]=5&pe_ratio[max]=25&sector=Technology&sort=roe&dir=DESC&page=1
     */
    public function screen(): void {
        $params    = [];
        $conditions= ['1=1'];
        $i = 0;

        foreach (self::FILTER_FIELDS as $field => [$type, $col]) {
            $input = $_GET[$field] ?? null;
            if ($input === null) continue;

            if ($type === 'numeric') {
                if (is_array($input)) {
                    if (isset($input['min']) && $input['min'] !== '') {
                        $conditions[] = "$col >= :f{$i}min";
                        $params[":f{$i}min"] = (float)$input['min'];
                    }
                    if (isset($input['max']) && $input['max'] !== '') {
                        $conditions[] = "$col <= :f{$i}max";
                        $params[":f{$i}max"] = (float)$input['max'];
                    }
                } else {
                    $conditions[] = "$col = :f{$i}";
                    $params[":f{$i}"] = (float)$input;
                }
            } elseif ($type === 'enum') {
                $vals = is_array($input) ? $input : [$input];
                $vals = array_filter(array_map('trim', $vals));
                if (!empty($vals)) {
                    $phs = [];
                    foreach ($vals as $v) { $phs[] = ":f{$i}v$i"; $params[":f{$i}v$i"] = $v; $i++; }
                    $conditions[] = "$col IN (" . implode(',', $phs) . ")";
                }
            }
            $i++;
        }

        // Search by name/symbol
        if (!empty($_GET['q'])) {
            $conditions[] = '(su.stock_symbol LIKE :q OR su.stock_name LIKE :q)';
            $params[':q'] = '%' . $_GET['q'] . '%';
        }

        // Exclude null PE (common filter)
        if (isset($_GET['exclude_null_pe']) && $_GET['exclude_null_pe']) {
            $conditions[] = 'su.pe_ratio IS NOT NULL AND su.pe_ratio > 0';
        }

        $sort    = in_array($_GET['sort'] ?? '', self::SORT_FIELDS) ? $_GET['sort'] : 'market_cap';
        $dir     = strtoupper($_GET['dir'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $page    = max(1, (int)($_GET['page'] ?? 1));
        $limit   = min(200, (int)($_GET['limit'] ?? 50));
        $offset  = ($page - 1) * $limit;

        $where = implode(' AND ', $conditions);
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM screener_universe su WHERE $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql = "SELECT su.* FROM screener_universe su WHERE $where ORDER BY su.$sort $dir LIMIT :limit OFFSET :offset";
        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->json([
            'status'  => 'success',
            'data'    => $rows,
            'total'   => $total,
            'page'    => $page,
            'pages'   => (int)ceil($total / $limit),
            'sort'    => $sort,
            'dir'     => $dir,
        ]);
    }

    // GET /api/screener/filters?user_id=X
    public function getSavedFilters(): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        if (!$userId) { $this->error('user_id required', 400); return; }
        $stmt = $this->db->prepare('SELECT * FROM screener_filters WHERE user_id = :uid ORDER BY is_default DESC, filter_name');
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) $r['filter_config'] = json_decode($r['filter_config'], true);
        $this->json(['status' => 'success', 'data' => $rows]);
    }

    // POST /api/screener/filters
    public function saveFilter(): void {
        $d = $this->input();
        $req = ['user_id','filter_name','filter_config'];
        foreach ($req as $f) {
            if (empty($d[$f])) { $this->error("$f is required", 400); return; }
        }
        $userId = (int)$d['user_id'];
        $config = is_array($d['filter_config']) ? json_encode($d['filter_config']) : $d['filter_config'];

        if (!empty($d['is_default'])) {
            $this->db->prepare('UPDATE screener_filters SET is_default = 0 WHERE user_id = :uid')->execute([':uid' => $userId]);
        }

        $stmt = $this->db->prepare("
            INSERT INTO screener_filters (user_id, filter_name, filter_config, is_default)
            VALUES (:uid, :name, :config, :def)
            ON DUPLICATE KEY UPDATE filter_config = VALUES(filter_config), is_default = VALUES(is_default)
        ");
        $stmt->execute([':uid' => $userId, ':name' => $d['filter_name'], ':config' => $config, ':def' => (int)($d['is_default'] ?? 0)]);
        $this->json(['status' => 'success', 'id' => (int)$this->db->lastInsertId(), 'message' => 'Filter saved'], 201);
    }

    // DELETE /api/screener/filters/{id}
    public function deleteFilter(int $id): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $stmt = $this->db->prepare('DELETE FROM screener_filters WHERE id = :id AND user_id = :uid');
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        if ($stmt->rowCount() === 0) { $this->error('Not found', 404); return; }
        $this->json(['status' => 'success', 'message' => 'Filter deleted']);
    }

    // POST /api/screener/universe/refresh — bulk upsert stock data
    public function refreshUniverse(): void {
        $d    = $this->input();
        $rows = $d['stocks'] ?? [];
        if (empty($rows)) { $this->error('stocks array required', 400); return; }

        $updated = 0;
        foreach ($rows as $s) {
            if (empty($s['stock_symbol'])) continue;
            $stmt = $this->db->prepare("
                INSERT INTO screener_universe
                    (stock_symbol, stock_name, isin, exchange, sector, industry, market_cap, market_cap_category,
                     pe_ratio, pb_ratio, eps, roe, roce, debt_to_equity, current_ratio, dividend_yield,
                     revenue_growth_1y, profit_growth_1y, price_52w_high, price_52w_low, current_price, avg_volume_30d, data_date)
                VALUES
                    (:sym,:name,:isin,:exch,:sector,:ind,:mc,:mcat,
                     :pe,:pb,:eps,:roe,:roce,:de,:cr,:dy,
                     :rev,:prof,:p52h,:p52l,:cp,:vol,:date)
                ON DUPLICATE KEY UPDATE
                    stock_name=VALUES(stock_name), sector=VALUES(sector), industry=VALUES(industry),
                    market_cap=VALUES(market_cap), market_cap_category=VALUES(market_cap_category),
                    pe_ratio=VALUES(pe_ratio), pb_ratio=VALUES(pb_ratio), eps=VALUES(eps),
                    roe=VALUES(roe), roce=VALUES(roce), debt_to_equity=VALUES(debt_to_equity),
                    current_ratio=VALUES(current_ratio), dividend_yield=VALUES(dividend_yield),
                    revenue_growth_1y=VALUES(revenue_growth_1y), profit_growth_1y=VALUES(profit_growth_1y),
                    price_52w_high=VALUES(price_52w_high), price_52w_low=VALUES(price_52w_low),
                    current_price=VALUES(current_price), avg_volume_30d=VALUES(avg_volume_30d),
                    data_date=VALUES(data_date), updated_at=CURRENT_TIMESTAMP
            ");
            $stmt->execute([
                ':sym'    => strtoupper($s['stock_symbol']),
                ':name'   => $s['stock_name'] ?? null,
                ':isin'   => $s['isin'] ?? null,
                ':exch'   => $s['exchange'] ?? 'NSE',
                ':sector' => $s['sector'] ?? null,
                ':ind'    => $s['industry'] ?? null,
                ':mc'     => isset($s['market_cap']) ? (float)$s['market_cap'] : null,
                ':mcat'   => $s['market_cap_category'] ?? null,
                ':pe'     => isset($s['pe_ratio']) ? (float)$s['pe_ratio'] : null,
                ':pb'     => isset($s['pb_ratio']) ? (float)$s['pb_ratio'] : null,
                ':eps'    => isset($s['eps']) ? (float)$s['eps'] : null,
                ':roe'    => isset($s['roe']) ? (float)$s['roe'] : null,
                ':roce'   => isset($s['roce']) ? (float)$s['roce'] : null,
                ':de'     => isset($s['debt_to_equity']) ? (float)$s['debt_to_equity'] : null,
                ':cr'     => isset($s['current_ratio']) ? (float)$s['current_ratio'] : null,
                ':dy'     => isset($s['dividend_yield']) ? (float)$s['dividend_yield'] : null,
                ':rev'    => isset($s['revenue_growth_1y']) ? (float)$s['revenue_growth_1y'] : null,
                ':prof'   => isset($s['profit_growth_1y']) ? (float)$s['profit_growth_1y'] : null,
                ':p52h'   => isset($s['price_52w_high']) ? (float)$s['price_52w_high'] : null,
                ':p52l'   => isset($s['price_52w_low']) ? (float)$s['price_52w_low'] : null,
                ':cp'     => isset($s['current_price']) ? (float)$s['current_price'] : null,
                ':vol'    => isset($s['avg_volume_30d']) ? (int)$s['avg_volume_30d'] : null,
                ':date'   => $s['data_date'] ?? date('Y-m-d'),
            ]);
            $updated++;
        }
        $this->json(['status' => 'success', 'updated' => $updated]);
    }

    private function input(): array { return json_decode(file_get_contents('php://input'), true) ?? []; }
    private function json(mixed $d, int $c = 200): void { http_response_code($c); header('Content-Type: application/json'); echo json_encode($d); }
    private function error(string $m, int $c = 400): void { $this->json(['status' => 'error', 'message' => $m], $c); }
}
