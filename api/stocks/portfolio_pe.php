<?php
defined('WEALTHDASH') or die();

/**
 * t432: Portfolio P/E vs Market P/E
 * Routes (add to router.php):
 *   GET  /api/stocks/pe-analysis           -> getPEAnalysis()
 *   GET  /api/stocks/market-pe             -> getMarketPE()
 *   POST /api/stocks/fundamentals-refresh  -> refreshFundamentals()
 */
class PortfolioPE {

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * GET /api/stocks/pe-analysis?user_id=X
     * Weighted portfolio P/E vs Nifty 50 P/E with commentary
     */
    public function getPEAnalysis(): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        if (!$userId) { $this->error('user_id required', 400); return; }

        // Current holdings (unrealized lots only)
        $holdings = $this->db->prepare("
            SELECT tl.stock_symbol, tl.stock_name, SUM(tl.quantity) AS qty, AVG(tl.buy_price) AS avg_price
            FROM tax_lots tl
            WHERE tl.user_id = :uid AND tl.tax_category = 'UNREALIZED'
            GROUP BY tl.stock_symbol, tl.stock_name
        ");
        $holdings->execute([':uid' => $userId]);
        $rows = $holdings->fetchAll(PDO::FETCH_ASSOC);

        $totalValue = 0;
        foreach ($rows as &$r) {
            $fund = $this->getFundamentals($r['stock_symbol']);
            $r['pe']     = $fund['pe_ratio'] ?? null;
            $r['pb']     = $fund['pb_ratio'] ?? null;
            $r['sector'] = $fund['sector'] ?? null;
            $r['market_cap_category'] = $fund['market_cap_category'] ?? null;
            $price = $fund['current_price'] ?? (float)$r['avg_price'];
            $r['current_price'] = $price;
            $r['value'] = round((float)$r['qty'] * $price, 2);
            $totalValue += $r['value'];
        }

        // Weighted P/E (by portfolio value weight)
        $weightedPE = 0; $weightedPB = 0; $peCount = 0;
        foreach ($rows as &$r) {
            if ($totalValue > 0) $r['weight_pct'] = round(($r['value'] / $totalValue) * 100, 2);
            if ($r['pe'] !== null && $r['pe'] > 0) {
                $w = $totalValue > 0 ? $r['value'] / $totalValue : 0;
                $weightedPE += $r['pe'] * $w;
                $peCount++;
            }
            if ($r['pb'] !== null && $r['pb'] > 0 && $totalValue > 0) {
                $weightedPB += $r['pb'] * ($r['value'] / $totalValue);
            }
        }

        // Market PE (latest from cache)
        $marketPE = $this->getLatestMarketPE('NIFTY50');

        $this->json([
            'status'       => 'success',
            'as_of'        => date('Y-m-d'),
            'portfolio'    => [
                'total_value'  => round($totalValue, 2),
                'weighted_pe'  => $peCount > 0 ? round($weightedPE, 2) : null,
                'weighted_pb'  => round($weightedPB, 2),
                'stocks_with_pe' => $peCount,
                'total_stocks'  => count($rows),
            ],
            'market'       => [
                'nifty50_pe'  => $marketPE['pe_ratio'] ?? null,
                'nifty50_pb'  => $marketPE['pb_ratio'] ?? null,
                'nifty50_div_yield' => $marketPE['div_yield'] ?? null,
                'data_date'   => $marketPE['data_date'] ?? null,
            ],
            'valuation'    => $this->getValuationComment($weightedPE, $marketPE['pe_ratio'] ?? null),
            'by_sector'    => $this->groupBySector($rows, $totalValue),
            'holdings'     => $rows,
        ]);
    }

    /**
     * GET /api/stocks/market-pe?index=NIFTY50&days=365
     * Historical market P/E trend
     */
    public function getMarketPE(): void {
        $index = strtoupper($_GET['index'] ?? 'NIFTY50');
        $days  = (int)($_GET['days'] ?? 365);

        $stmt = $this->db->prepare("
            SELECT index_name, data_date, pe_ratio, pb_ratio, div_yield
            FROM market_pe_history
            WHERE index_name = :idx AND data_date >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            ORDER BY data_date ASC
        ");
        $stmt->execute([':idx' => $index, ':days' => $days]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $pe_values = array_filter(array_column($history, 'pe_ratio'), fn($v) => $v !== null && $v > 0);
        $this->json([
            'status'  => 'success',
            'index'   => $index,
            'history' => $history,
            'stats'   => [
                'min_pe' => $pe_values ? round(min($pe_values), 2) : null,
                'max_pe' => $pe_values ? round(max($pe_values), 2) : null,
                'avg_pe' => $pe_values ? round(array_sum($pe_values) / count($pe_values), 2) : null,
                'latest_pe' => $history ? end($history)['pe_ratio'] : null,
            ],
        ]);
    }

    /**
     * POST /api/stocks/fundamentals-refresh
     * Upsert fundamentals for one or many symbols
     */
    public function refreshFundamentals(): void {
        $d    = $this->input();
        $rows = $d['stocks'] ?? [];
        if (empty($rows)) { $this->error('stocks array required', 400); return; }

        $updated = 0;
        foreach ($rows as $s) {
            if (empty($s['stock_symbol'])) continue;
            $stmt = $this->db->prepare("
                INSERT INTO stock_fundamentals_cache
                    (stock_symbol, isin, pe_ratio, pb_ratio, eps, market_cap, sector, industry, data_date, source)
                VALUES (:sym,:isin,:pe,:pb,:eps,:mc,:sector,:ind,:date,:src)
                ON DUPLICATE KEY UPDATE
                    pe_ratio   = VALUES(pe_ratio),
                    pb_ratio   = VALUES(pb_ratio),
                    eps        = VALUES(eps),
                    market_cap = VALUES(market_cap),
                    sector     = VALUES(sector),
                    industry   = VALUES(industry),
                    source     = VALUES(source),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([
                ':sym'    => strtoupper($s['stock_symbol']),
                ':isin'   => $s['isin'] ?? null,
                ':pe'     => isset($s['pe_ratio']) ? (float)$s['pe_ratio'] : null,
                ':pb'     => isset($s['pb_ratio']) ? (float)$s['pb_ratio'] : null,
                ':eps'    => isset($s['eps']) ? (float)$s['eps'] : null,
                ':mc'     => isset($s['market_cap']) ? (float)$s['market_cap'] : null,
                ':sector' => $s['sector'] ?? null,
                ':ind'    => $s['industry'] ?? null,
                ':date'   => $s['data_date'] ?? date('Y-m-d'),
                ':src'    => $s['source'] ?? 'NSE',
            ]);
            $updated++;
        }

        // Market PE rows
        if (!empty($d['market_pe'])) {
            $mp = $d['market_pe'];
            $this->db->prepare("
                INSERT INTO market_pe_history (index_name, data_date, pe_ratio, pb_ratio, div_yield)
                VALUES (:idx,:date,:pe,:pb,:dy)
                ON DUPLICATE KEY UPDATE pe_ratio=VALUES(pe_ratio), pb_ratio=VALUES(pb_ratio), div_yield=VALUES(div_yield)
            ")->execute([
                ':idx' => $mp['index_name'] ?? 'NIFTY50',
                ':date'=> $mp['data_date']  ?? date('Y-m-d'),
                ':pe'  => (float)($mp['pe_ratio'] ?? 0),
                ':pb'  => isset($mp['pb_ratio']) ? (float)$mp['pb_ratio'] : null,
                ':dy'  => isset($mp['div_yield']) ? (float)$mp['div_yield'] : null,
            ]);
        }

        $this->json(['status' => 'success', 'updated' => $updated]);
    }

    // ------- helpers -------

    private function getFundamentals(string $symbol): array {
        $stmt = $this->db->prepare("
            SELECT pe_ratio, pb_ratio, sector, industry, market_cap,
                   CASE
                       WHEN market_cap >= 200000000000 THEN 'LARGE'
                       WHEN market_cap >= 50000000000  THEN 'MID'
                       WHEN market_cap >= 5000000000   THEN 'SMALL'
                       ELSE 'MICRO'
                   END AS market_cap_category,
                   NULL AS current_price
            FROM stock_fundamentals_cache
            WHERE stock_symbol = :sym
            ORDER BY data_date DESC LIMIT 1
        ");
        $stmt->execute([':sym' => $symbol]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function getLatestMarketPE(string $index): array {
        $stmt = $this->db->prepare("
            SELECT * FROM market_pe_history WHERE index_name = :idx ORDER BY data_date DESC LIMIT 1
        ");
        $stmt->execute([':idx' => $index]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function getValuationComment(?float $portfolioPE, ?float $marketPE): string {
        if ($portfolioPE === null || $marketPE === null || $marketPE === 0) return 'Insufficient data for valuation comment';
        $ratio = $portfolioPE / $marketPE;
        if ($ratio > 1.3)  return 'Portfolio is expensive relative to market (PE premium >30%). Consider value stocks.';
        if ($ratio > 1.1)  return 'Portfolio trades at a slight premium to market — moderate growth tilt.';
        if ($ratio >= 0.9) return 'Portfolio is fairly valued relative to the market.';
        if ($ratio >= 0.7) return 'Portfolio trades at a discount — value-oriented or beaten-down stocks.';
        return 'Portfolio has very low PE — check for value traps or poor-quality earnings.';
    }

    private function groupBySector(array $rows, float $totalValue): array {
        $sectors = [];
        foreach ($rows as $r) {
            $s = $r['sector'] ?? 'Unknown';
            if (!isset($sectors[$s])) $sectors[$s] = ['sector' => $s, 'value' => 0, 'weight_pct' => 0, 'pe_sum' => 0, 'pe_count' => 0];
            $sectors[$s]['value'] += $r['value'];
            if ($r['pe'] !== null && $r['pe'] > 0) { $sectors[$s]['pe_sum'] += $r['pe']; $sectors[$s]['pe_count']++; }
        }
        foreach ($sectors as &$s) {
            $s['weight_pct'] = $totalValue > 0 ? round(($s['value'] / $totalValue) * 100, 2) : 0;
            $s['avg_pe']     = $s['pe_count'] > 0 ? round($s['pe_sum'] / $s['pe_count'], 2) : null;
            unset($s['pe_sum'], $s['pe_count']);
        }
        usort($sectors, fn($a, $b) => $b['value'] <=> $a['value']);
        return array_values($sectors);
    }

    private function input(): array { return json_decode(file_get_contents('php://input'), true) ?? []; }
    private function json(mixed $d, int $c = 200): void { http_response_code($c); header('Content-Type: application/json'); echo json_encode($d); }
    private function error(string $m, int $c = 400): void { $this->json(['status' => 'error', 'message' => $m], $c); }
}
