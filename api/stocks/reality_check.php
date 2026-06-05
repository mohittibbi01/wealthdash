<?php
defined('WEALTHDASH') or die();

/**
 * t145: Stock Picker Reality Check — vs Nifty 50
 * Routes (add to router.php):
 *   GET  /api/stocks/reality-check       -> getRealityCheck()
 *   GET  /api/stocks/alpha               -> getAlphaReport()
 *   POST /api/stocks/snapshot            -> takeSnapshot()
 *   GET  /api/stocks/snapshot-history    -> getSnapshotHistory()
 */
class StockPickerRealityCheck {

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * GET /api/stocks/reality-check?user_id=X&from=2023-04-01&to=2024-03-31
     * Compares portfolio returns vs Nifty 50 for same period
     */
    public function getRealityCheck(): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        $from   = $_GET['from'] ?? null;
        $to     = $_GET['to'] ?? date('Y-m-d');
        if (!$userId) { $this->error('user_id required', 400); return; }

        // Portfolio investment cashflows for XIRR
        $txns = $this->getPortfolioCashflows($userId, $from, $to);
        $xirr = $this->calculateXIRR($txns);

        // Nifty 50 equivalent CAGR for same period
        $niftyCAGR = $this->getNiftyCAGR($from, $to);

        // Per-stock contribution to alpha
        $stockContribs = $this->getPerStockContribution($userId, $from, $to);

        // Winners vs Losers vs Benchmark
        $winners   = array_filter($stockContribs, fn($s) => (float)$s['returns_pct'] > ($niftyCAGR ?? 0));
        $losers    = array_filter($stockContribs, fn($s) => (float)$s['returns_pct'] <= ($niftyCAGR ?? 0));

        $this->json([
            'status'       => 'success',
            'period'       => ['from' => $from, 'to' => $to],
            'portfolio'    => [
                'xirr_pct'         => $xirr ? round($xirr * 100, 2) : null,
                'total_invested'   => array_sum(array_column($txns, 'invested')),
                'current_value'    => $this->getCurrentPortfolioValue($userId),
            ],
            'benchmark'    => [
                'name'         => 'NIFTY 50',
                'cagr_pct'     => $niftyCAGR ? round($niftyCAGR, 2) : null,
            ],
            'alpha_pct'    => ($xirr !== null && $niftyCAGR !== null) ? round(($xirr * 100) - $niftyCAGR, 2) : null,
            'verdict'      => $this->getVerdict($xirr, $niftyCAGR),
            'per_stock'    => array_values($stockContribs),
            'winners_count'=> count($winners),
            'losers_count' => count($losers),
        ]);
    }

    /**
     * GET /api/stocks/alpha?user_id=X&fy=2024-25
     * FY-wise alpha table
     */
    public function getAlphaReport(): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        if (!$userId) { $this->error('user_id required', 400); return; }

        $stmt = $this->db->prepare("
            SELECT ps.snapshot_date, ps.portfolio_value, ps.invested_value,
                   ps.xirr, ps.nifty50_returns, ps.alpha
            FROM portfolio_snapshots ps
            WHERE ps.user_id = :uid
            ORDER BY ps.snapshot_date DESC
            LIMIT 36
        ");
        $stmt->execute([':uid' => $userId]);
        $snapshots = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->json(['status' => 'success', 'data' => $snapshots]);
    }

    /**
     * POST /api/stocks/snapshot — take a portfolio snapshot now
     */
    public function takeSnapshot(): void {
        $d = $this->input();
        $userId = (int)($d['user_id'] ?? 0);
        if (!$userId) { $this->error('user_id required', 400); return; }

        $portfolioValue  = (float)($d['portfolio_value'] ?? $this->getCurrentPortfolioValue($userId));
        $investedValue   = (float)($d['invested_value']  ?? $this->getTotalInvested($userId));
        $nifty50Value    = (float)($d['nifty50_value']   ?? 0);
        $nifty50Returns  = (float)($d['nifty50_returns'] ?? 0);
        $xirr            = $d['xirr'] ?? null;
        $alpha           = ($xirr !== null && $nifty50Returns > 0)
                         ? round((float)$xirr - $nifty50Returns, 4) : null;

        $stmt = $this->db->prepare("
            INSERT INTO portfolio_snapshots
                (user_id, snapshot_date, portfolio_value, invested_value, xirr, nifty50_value, nifty50_returns, alpha)
            VALUES (:uid, :date, :pv, :iv, :xirr, :n50v, :n50r, :alpha)
            ON DUPLICATE KEY UPDATE
                portfolio_value = VALUES(portfolio_value),
                invested_value  = VALUES(invested_value),
                xirr            = VALUES(xirr),
                nifty50_value   = VALUES(nifty50_value),
                nifty50_returns = VALUES(nifty50_returns),
                alpha           = VALUES(alpha)
        ");
        $stmt->execute([
            ':uid'  => $userId, ':date' => date('Y-m-d'),
            ':pv'   => $portfolioValue, ':iv' => $investedValue,
            ':xirr' => $xirr, ':n50v' => $nifty50Value,
            ':n50r' => $nifty50Returns, ':alpha' => $alpha,
        ]);
        $this->json(['status' => 'success', 'message' => 'Snapshot saved', 'alpha' => $alpha]);
    }

    // GET /api/stocks/snapshot-history?user_id=X
    public function getSnapshotHistory(): void {
        $userId = (int)($_GET['user_id'] ?? 0);
        if (!$userId) { $this->error('user_id required', 400); return; }

        $stmt = $this->db->prepare('SELECT * FROM portfolio_snapshots WHERE user_id = :uid ORDER BY snapshot_date ASC');
        $stmt->execute([':uid' => $userId]);
        $this->json(['status' => 'success', 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    // ------- helpers -------

    private function getPortfolioCashflows(int $userId, ?string $from, string $to): array {
        $where = ['user_id = :uid', 'transaction_date <= :to'];
        $params = [':uid' => $userId, ':to' => $to];
        if ($from) { $where[] = 'transaction_date >= :from'; $params[':from'] = $from; }

        $stmt = $this->db->prepare("
            SELECT transaction_date AS date, transaction_type,
                   quantity * price AS invested
            FROM stock_transactions
            WHERE " . implode(' AND ', $where) . "
            ORDER BY transaction_date
        ");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function getCurrentPortfolioValue(int $userId): float {
        $stmt = $this->db->prepare("
            SELECT SUM(tl.quantity * COALESCE(fu.current_price, tl.buy_price)) AS val
            FROM tax_lots tl
            LEFT JOIN stock_fundamentals_cache fu ON fu.stock_symbol = tl.stock_symbol
                AND fu.data_date = (SELECT MAX(data_date) FROM stock_fundamentals_cache WHERE stock_symbol = tl.stock_symbol)
            WHERE tl.user_id = :uid AND tl.tax_category = 'UNREALIZED'
        ");
        $stmt->execute([':uid' => $userId]);
        return (float)($stmt->fetchColumn() ?? 0);
    }

    private function getTotalInvested(int $userId): float {
        $stmt = $this->db->prepare("
            SELECT SUM(quantity * price) FROM stock_transactions
            WHERE user_id = :uid AND transaction_type = 'BUY'
        ");
        $stmt->execute([':uid' => $userId]);
        return (float)($stmt->fetchColumn() ?? 0);
    }

    private function getNiftyCAGR(?string $from, string $to): ?float {
        if (!$from) return null;
        $stmt = $this->db->prepare("
            SELECT b1.close_value AS start_val, b2.close_value AS end_val
            FROM benchmark_data b1, benchmark_data b2
            WHERE b1.benchmark_name = 'NIFTY50' AND b1.data_date = :from
              AND b2.benchmark_name = 'NIFTY50' AND b2.data_date = :to
        ");
        $stmt->execute([':from' => $from, ':to' => $to]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !$row['start_val']) return null;

        $years = (strtotime($to) - strtotime($from)) / (365.25 * 86400);
        if ($years <= 0) return null;
        return (pow((float)$row['end_val'] / (float)$row['start_val'], 1 / $years) - 1) * 100;
    }

    private function getPerStockContribution(int $userId, ?string $from, string $to): array {
        $params = [':uid' => $userId, ':to' => $to];
        $dateFilter = $from ? " AND transaction_date >= :from" : '';
        if ($from) $params[':from'] = $from;

        $stmt = $this->db->prepare("
            SELECT stock_symbol, stock_name,
                   SUM(CASE WHEN transaction_type='BUY' THEN quantity*price ELSE 0 END) AS invested,
                   SUM(CASE WHEN transaction_type='SELL' THEN quantity*price ELSE 0 END) AS realised
            FROM stock_transactions
            WHERE user_id = :uid AND transaction_date <= :to $dateFilter
            GROUP BY stock_symbol, stock_name
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$r) {
            $invested  = (float)$r['invested'];
            $realised  = (float)$r['realised'];
            $r['returns_pct'] = $invested > 0 ? round((($realised - $invested) / $invested) * 100, 2) : null;
        }
        return $rows;
    }

    /**
     * Newton-Raphson XIRR computation
     */
    private function calculateXIRR(array $cashflows): ?float {
        if (empty($cashflows)) return null;
        $cfList = [];
        foreach ($cashflows as $cf) {
            $sign   = $cf['transaction_type'] === 'BUY' ? -1 : 1;
            $cfList[] = ['amount' => $sign * (float)$cf['invested'], 'date' => $cf['date']];
        }
        if (count($cfList) < 2) return null;

        $baseDate = strtotime($cfList[0]['date']);
        $rate = 0.1;
        for ($iter = 0; $iter < 100; $iter++) {
            $f = 0; $df = 0;
            foreach ($cfList as $cf) {
                $t = (strtotime($cf['date']) - $baseDate) / (365.25 * 86400);
                $f  += $cf['amount'] / pow(1 + $rate, $t);
                $df -= $t * $cf['amount'] / pow(1 + $rate, $t + 1);
            }
            if (abs($df) < 1e-10) break;
            $newRate = $rate - $f / $df;
            if (abs($newRate - $rate) < 1e-8) { $rate = $newRate; break; }
            $rate = $newRate;
        }
        return (is_nan($rate) || is_infinite($rate)) ? null : $rate;
    }

    private function getVerdict(?float $xirr, ?float $niftyCAGR): string {
        if ($xirr === null || $niftyCAGR === null) return 'Insufficient data';
        $xirrPct = $xirr * 100;
        $diff = $xirrPct - $niftyCAGR;
        if ($diff > 5)  return "Excellent — beating Nifty 50 by {$diff}%";
        if ($diff > 2)  return "Good — marginally ahead of Nifty 50";
        if ($diff > -2) return "Neutral — roughly matching Nifty 50";
        if ($diff > -5) return "Below benchmark — consider index funds";
        return "Significantly underperforming — index fund would have done better";
    }

    private function input(): array { return json_decode(file_get_contents('php://input'), true) ?? []; }
    private function json(mixed $d, int $c = 200): void { http_response_code($c); header('Content-Type: application/json'); echo json_encode($d); }
    private function error(string $m, int $c = 400): void { $this->json(['status' => 'error', 'message' => $m], $c); }
}
