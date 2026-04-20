<?php
/**
 * WealthDash — Holding Calculator
 * Tasks: t73 (Portfolio XIRR), t93 (Alpha/Beta), t94 (Rolling Returns)
 * Functions: xirr(), portfolio_xirr(), cagr(), mwrr()
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

class HoldingCalculator
{
    // ── XIRR ─────────────────────────────────────────────────────────────
    /**
     * Calculate XIRR for a series of cash flows with dates.
     * @param array $amounts  Negative = investment, Positive = current value
     * @param array $dates    Corresponding dates (Y-m-d strings or timestamps)
     * @param float $guess    Initial guess rate (default 10%)
     * @return float|null     Annualised IRR as decimal (0.15 = 15%), null on failure
     */
    public static function xirr(array $amounts, array $dates, float $guess = 0.10): ?float
    {
        if (count($amounts) !== count($dates) || count($amounts) < 2) return null;

        // Convert dates to day offsets from first date
        $timestamps = array_map(fn($d) => is_numeric($d) ? (int)$d : strtotime($d), $dates);
        $t0 = $timestamps[0];
        $days = array_map(fn($ts) => ($ts - $t0) / 86400, $timestamps);

        $f = function(float $r) use ($amounts, $days): float {
            $npv = 0.0;
            foreach ($amounts as $i => $cf) {
                $npv += $cf / pow(1 + $r, $days[$i] / 365);
            }
            return $npv;
        };

        $df = function(float $r) use ($amounts, $days): float {
            $d = 0.0;
            foreach ($amounts as $i => $cf) {
                if ($days[$i] == 0) continue;
                $d -= ($days[$i] / 365) * $cf / pow(1 + $r, $days[$i] / 365 + 1);
            }
            return $d;
        };

        // Newton-Raphson iteration
        $rate = $guess;
        for ($iter = 0; $iter < 100; $iter++) {
            $fv  = $f($rate);
            $dfv = $df($rate);
            if (abs($dfv) < 1e-12) break;
            $newRate = $rate - $fv / $dfv;
            if (abs($newRate - $rate) < 1e-7) return round($newRate * 100, 4);
            $rate = $newRate;
            if ($rate < -0.999 || $rate > 100) break;
        }

        // Bisection fallback
        $lo = -0.999; $hi = 50.0;
        if ($f($lo) * $f($hi) > 0) return null;
        for ($iter = 0; $iter < 200; $iter++) {
            $mid = ($lo + $hi) / 2;
            if ($f($mid) === 0 || ($hi - $lo) / 2 < 1e-8) return round($mid * 100, 4);
            ($f($mid) * $f($lo) < 0) ? $hi = $mid : $lo = $mid;
        }
        return null;
    }

    // ── CAGR ─────────────────────────────────────────────────────────────
    /**
     * @param float $beginValue
     * @param float $endValue
     * @param float $years
     * @return float|null  CAGR % (15.34 = 15.34%)
     */
    public static function cagr(float $beginValue, float $endValue, float $years): ?float
    {
        if ($beginValue <= 0 || $years <= 0) return null;
        return round((pow($endValue / $beginValue, 1 / $years) - 1) * 100, 4);
    }

    // ── Absolute Return % ─────────────────────────────────────────────────
    public static function absReturn(float $invested, float $currentValue): ?float
    {
        if ($invested <= 0) return null;
        return round(($currentValue - $invested) / $invested * 100, 4);
    }

    // ── Portfolio XIRR ────────────────────────────────────────────────────
    /**
     * Calculate portfolio-level XIRR from all MF transactions for a user.
     * @param int $userId
     * @return array ['xirr' => float|null, 'abs_return' => float|null, 'total_invested' => float, 'current_value' => float]
     */
    public static function portfolioXirr(int $userId): array
    {
        try {
            $db = DB::conn();

            // All buy/invest transactions (negative cash flows)
            $txns = $db->prepare("
                SELECT tx_date, tx_type,
                       CASE WHEN tx_type IN ('buy','sip','lumpsum','switch_in') THEN -amount
                            ELSE amount END AS cf
                FROM mf_transactions
                WHERE user_id = ? AND tx_date IS NOT NULL
                ORDER BY tx_date ASC
            ");
            $txns->execute([$userId]);
            $rows = $txns->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) return ['xirr' => null, 'abs_return' => null, 'total_invested' => 0, 'current_value' => 0];

            // Current portfolio value (positive cash flow today)
            $currentValue = (float)$db->prepare("
                SELECT COALESCE(SUM(current_value), 0)
                FROM mf_holdings mh
                JOIN portfolios p ON p.id = mh.portfolio_id
                WHERE p.user_id = ? AND mh.is_active = 1
            ")->execute([$userId]) ? $db->lastInsertId() : 0;

            // Re-fetch properly
            $stmt = $db->prepare("
                SELECT COALESCE(SUM(mh.current_value), 0) AS cv,
                       COALESCE(SUM(mh.invested_amount), 0) AS inv
                FROM mf_holdings mh
                JOIN portfolios p ON p.id = mh.portfolio_id
                WHERE p.user_id = ? AND mh.is_active = 1
            ");
            $stmt->execute([$userId]);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);
            $currentValue  = (float)($summary['cv']  ?? 0);
            $totalInvested = (float)($summary['inv'] ?? 0);

            if ($currentValue <= 0) return ['xirr' => null, 'abs_return' => null,
                                             'total_invested' => $totalInvested, 'current_value' => 0];

            $amounts = [];
            $dates   = [];
            foreach ($rows as $r) {
                $amounts[] = (float)$r['cf'];
                $dates[]   = $r['tx_date'];
            }
            // Add current value as final positive cash flow
            $amounts[] = $currentValue;
            $dates[]   = date('Y-m-d');

            $xirr = self::xirr($amounts, $dates);

            return [
                'xirr'           => $xirr,
                'abs_return'     => $totalInvested > 0 ? round(($currentValue - $totalInvested) / $totalInvested * 100, 4) : null,
                'total_invested' => $totalInvested,
                'current_value'  => $currentValue,
                'gain_loss'      => $currentValue - $totalInvested,
            ];
        } catch (\Exception $e) {
            error_log('portfolioXirr error: ' . $e->getMessage());
            return ['xirr' => null, 'abs_return' => null, 'total_invested' => 0, 'current_value' => 0];
        }
    }

    // ── Per-holding XIRR ─────────────────────────────────────────────────
    public static function holdingXirr(int $userId, int $fundId): ?float
    {
        try {
            $db = DB::conn();
            $stmt = $db->prepare("
                SELECT t.tx_date,
                       CASE WHEN t.tx_type IN ('buy','sip','lumpsum','switch_in') THEN -t.amount
                            ELSE t.amount END AS cf
                FROM mf_transactions t
                JOIN mf_holdings h ON h.id = t.holding_id
                JOIN portfolios p   ON p.id = h.portfolio_id
                WHERE p.user_id = ? AND h.fund_id = ?
                ORDER BY t.tx_date ASC
            ");
            $stmt->execute([$userId, $fundId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) return null;

            // Current NAV * units
            $curr = $db->prepare("
                SELECT mh.current_value
                FROM mf_holdings mh
                JOIN portfolios p ON p.id = mh.portfolio_id
                WHERE p.user_id = ? AND mh.fund_id = ? AND mh.is_active = 1
                LIMIT 1
            ");
            $curr->execute([$userId, $fundId]);
            $cv = (float)($curr->fetchColumn() ?? 0);
            if ($cv <= 0) return null;

            $amounts = array_column($rows, 'cf');
            $dates   = array_column($rows, 'tx_date');
            $amounts[] = $cv;
            $dates[]   = date('Y-m-d');

            return self::xirr(array_map('floatval', $amounts), $dates);
        } catch (\Exception $e) {
            return null;
        }
    }

    // ── Sharpe Ratio ─────────────────────────────────────────────────────
    /**
     * @param array $dailyReturns  Array of daily return % values
     * @param float $riskFreeRate  Annual risk-free rate % (default 6.5%)
     * @return float|null
     */
    public static function sharpeRatio(array $dailyReturns, float $riskFreeRate = 6.5): ?float
    {
        if (count($dailyReturns) < 30) return null;
        $dailyRf  = $riskFreeRate / 252 / 100;
        $excess   = array_map(fn($r) => $r / 100 - $dailyRf, $dailyReturns);
        $mean     = array_sum($excess) / count($excess);
        $variance = array_sum(array_map(fn($x) => pow($x - $mean, 2), $excess)) / (count($excess) - 1);
        $std      = sqrt($variance);
        if ($std == 0) return null;
        return round($mean / $std * sqrt(252), 4);
    }

    // ── Sortino Ratio ────────────────────────────────────────────────────
    public static function sortinoRatio(array $dailyReturns, float $riskFreeRate = 6.5): ?float
    {
        if (count($dailyReturns) < 30) return null;
        $dailyRf      = $riskFreeRate / 252 / 100;
        $excess       = array_map(fn($r) => $r / 100 - $dailyRf, $dailyReturns);
        $mean         = array_sum($excess) / count($excess);
        $negExcess    = array_filter($excess, fn($x) => $x < 0);
        if (empty($negExcess)) return null;
        $downDev      = sqrt(array_sum(array_map(fn($x) => $x * $x, $negExcess)) / count($negExcess));
        if ($downDev == 0) return null;
        return round($mean / $downDev * sqrt(252), 4);
    }

    // ── Max Drawdown ─────────────────────────────────────────────────────
    public static function maxDrawdown(array $navValues): ?float
    {
        if (count($navValues) < 2) return null;
        $peak = $navValues[0];
        $maxDD = 0.0;
        foreach ($navValues as $nav) {
            if ($nav > $peak) $peak = $nav;
            $dd = ($peak - $nav) / $peak * 100;
            if ($dd > $maxDD) $maxDD = $dd;
        }
        return round($maxDD, 4);
    }

    // ── Beta vs Benchmark ────────────────────────────────────────────────
    public static function beta(array $fundReturns, array $benchmarkReturns): ?float
    {
        $n = min(count($fundReturns), count($benchmarkReturns));
        if ($n < 30) return null;
        $fundR  = array_slice($fundReturns, 0, $n);
        $benchR = array_slice($benchmarkReturns, 0, $n);

        $meanF  = array_sum($fundR)  / $n;
        $meanB  = array_sum($benchR) / $n;

        $cov = $var = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $cov += ($fundR[$i] - $meanF) * ($benchR[$i] - $meanB);
            $var += ($benchR[$i] - $meanB) ** 2;
        }
        if ($var == 0) return null;
        return round($cov / $var, 4);
    }

    // ── Alpha ────────────────────────────────────────────────────────────
    public static function alpha(float $annualisedReturn, float $beta, float $benchmarkReturn, float $riskFreeRate = 6.5): float
    {
        return round($annualisedReturn - ($riskFreeRate + $beta * ($benchmarkReturn - $riskFreeRate)), 4);
    }

    // ── Portfolio Health Score (0-100) ───────────────────────────────────
    /**
     * Tasks: tmfi01
     * Components: Returns(30) + Diversification(25) + Risk(20) + Cost(15) + Consistency(10)
     */
    public static function portfolioHealthScore(int $userId): array
    {
        try {
            $db = DB::conn();
            $score = 0;
            $breakdown = [];

            // Fetch active holdings
            $holdings = $db->prepare("
                SELECT mh.fund_id, mh.current_value, mh.xirr, mh.invested_amount,
                       f.expense_ratio, f.category, f.sharpe_ratio, f.returns_1y,
                       f.category_avg_1y, f.standard_deviation
                FROM mf_holdings mh
                JOIN portfolios p ON p.id = mh.portfolio_id
                JOIN funds f ON f.id = mh.fund_id
                WHERE p.user_id = ? AND mh.is_active = 1
            ");
            $holdings->execute([$userId]);
            $funds = $holdings->fetchAll(PDO::FETCH_ASSOC);

            if (empty($funds)) return ['score' => 0, 'breakdown' => [], 'color' => 'red'];

            $totalValue = array_sum(array_column($funds, 'current_value'));

            // 1. Returns Score (30 pts) — XIRR vs category avg
            $returnsScore = 0;
            $returnsFunds = array_filter($funds, fn($f) => $f['xirr'] !== null);
            if (!empty($returnsFunds)) {
                $aboveAvg = count(array_filter($returnsFunds, fn($f) =>
                    (float)$f['xirr'] > (float)($f['category_avg_1y'] ?? 10)));
                $returnsScore = round(($aboveAvg / count($returnsFunds)) * 30);
            }
            $breakdown['returns'] = $returnsScore;

            // 2. Diversification Score (25 pts)
            $fundCount = count($funds);
            $catCount  = count(array_unique(array_column($funds, 'category')));
            $divScore  = 0;
            if ($fundCount >= 3 && $fundCount <= 8)  $divScore += 15;
            elseif ($fundCount >= 9 && $fundCount <= 12) $divScore += 10;
            elseif ($fundCount > 12) $divScore += 5;
            else $divScore += 8;
            if ($catCount >= 3) $divScore += 10;
            elseif ($catCount >= 2) $divScore += 6;
            $breakdown['diversification'] = min(25, $divScore);

            // 3. Risk Score (20 pts) — weighted portfolio volatility
            $weightedVol = 0;
            foreach ($funds as $f) {
                $w = $totalValue > 0 ? (float)$f['current_value'] / $totalValue : 0;
                $weightedVol += $w * (float)($f['standard_deviation'] ?? 15);
            }
            $riskScore = $weightedVol < 10 ? 20 : ($weightedVol < 15 ? 15 : ($weightedVol < 20 ? 10 : 5));
            $breakdown['risk'] = $riskScore;

            // 4. Cost Score (15 pts) — weighted expense ratio
            $weightedER = 0;
            foreach ($funds as $f) {
                $w = $totalValue > 0 ? (float)$f['current_value'] / $totalValue : 0;
                $weightedER += $w * (float)($f['expense_ratio'] ?? 1.0);
            }
            $costScore = $weightedER < 0.3 ? 15 : ($weightedER < 0.7 ? 12 : ($weightedER < 1.0 ? 8 : 4));
            $breakdown['cost'] = $costScore;

            // 5. Consistency Score (10 pts) — Sharpe avg
            $sharpeVals = array_filter(array_column($funds, 'sharpe_ratio'), fn($v) => $v !== null);
            $avgSharpe  = empty($sharpeVals) ? 0 : array_sum($sharpeVals) / count($sharpeVals);
            $conScore   = $avgSharpe >= 1.5 ? 10 : ($avgSharpe >= 1.0 ? 8 : ($avgSharpe >= 0.5 ? 5 : 2));
            $breakdown['consistency'] = $conScore;

            $score = array_sum($breakdown);
            $color = $score >= 71 ? 'green' : ($score >= 41 ? 'yellow' : 'red');

            return compact('score', 'breakdown', 'color', 'weightedER', 'weightedVol', 'avgSharpe', 'fundCount', 'catCount');
        } catch (\Exception $e) {
            return ['score' => 0, 'breakdown' => [], 'color' => 'red', 'error' => $e->getMessage()];
        }
    }
}
