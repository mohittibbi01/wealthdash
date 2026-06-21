<?php
/**
 * WealthDash — t268: Dividend History Tracker (IDCW Payout History)
 *
 * Tracks IDCW (dividend) payouts received per fund:
 *  - Total dividends received since first purchase
 *  - Annual dividend payout bar chart data
 *  - Per-holding: actual dividend income based on units held
 *
 * DB: fund_dividends — fund_id, record_date, dividend_per_unit
 * (migration 027_dividends.sql)
 *
 * GET /api/mutual_funds/dividend_history.php
 *   ?fund_id=X                  ← Dividend history for one fund
 *   ?portfolio_id=X             ← All IDCW dividends received in portfolio
 *   ?action=portfolio_summary   ← Total dividends received across all funds
 *   ?action=annual_chart        ← Annual bar chart data
 *   ?action=holdings_income     ← Per-holding dividend income
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
error_reporting(0);
ini_set('display_errors', '0');
ob_start();

$currentUser = require_auth();

set_exception_handler(function (Throwable $e) {
    ob_clean(); http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]); exit;
});

try {
    $db          = DB::conn();
    $action      = $_GET['action'] ?? 'fund_history';
    $fundId      = (int)($_GET['fund_id'] ?? 0);
    $portfolioId = (int)($_GET['portfolio_id'] ?? 0);
    $userId      = (int)$currentUser['id'];

    // ── Ensure table exists ──────────────────────────────────────────────
    ensure_dividend_table($db);

    $response = ['success' => true, 'action' => $action];

    switch ($action) {
        case 'fund_history':
            if (!$fundId) throw new InvalidArgumentException('fund_id required');
            $response += fund_dividend_history($db, $fundId);
            break;

        case 'portfolio_summary':
            $response['summary'] = portfolio_dividend_summary($db, $userId, $portfolioId);
            break;

        case 'annual_chart':
            $pId = $portfolioId ?: null;
            $response['annual_data'] = annual_dividend_chart($db, $userId, $pId, $fundId ?: null);
            break;

        case 'holdings_income':
            $response['holdings_income'] = holdings_dividend_income($db, $userId, $portfolioId);
            break;

        default:
            // Default: if fund_id given, show fund history, else portfolio summary
            if ($fundId) {
                $response += fund_dividend_history($db, $fundId);
            } else {
                $response['summary']      = portfolio_dividend_summary($db, $userId, $portfolioId);
                $response['annual_data']  = annual_dividend_chart($db, $userId, $portfolioId ?: null);
                $response['holdings_income'] = holdings_dividend_income($db, $userId, $portfolioId);
            }
    }

    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean(); http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ═══════════════════════════════════════════════════════════════════════════
// TABLE SETUP
// ═══════════════════════════════════════════════════════════════════════════
function ensure_dividend_table(PDO $db): void
{
    try { $db->query("SELECT 1 FROM fund_dividends LIMIT 1"); }
    catch (Exception $e) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS fund_dividends (
                id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                fund_id           INT UNSIGNED NOT NULL,
                record_date       DATE         NOT NULL,
                dividend_per_unit DECIMAL(10,4) NOT NULL DEFAULT 0,
                face_value        DECIMAL(10,4) NULL,
                payout_type       VARCHAR(20)  DEFAULT 'idcw',
                source            VARCHAR(30)  DEFAULT 'amfi',
                created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_fund_date (fund_id, record_date),
                INDEX idx_fund (fund_id),
                INDEX idx_date (record_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// FUND DIVIDEND HISTORY
// ═══════════════════════════════════════════════════════════════════════════
function fund_dividend_history(PDO $db, int $fundId): array
{
    $stmt = $db->prepare("SELECT scheme_name, option_type, category, latest_nav FROM funds WHERE id = ?");
    $stmt->execute([$fundId]);
    $fund = $stmt->fetch();
    if (!$fund) throw new RuntimeException('Fund not found');

    // Fetch dividend records
    $stmt = $db->prepare("
        SELECT record_date, dividend_per_unit, face_value, payout_type
        FROM fund_dividends
        WHERE fund_id = ?
        ORDER BY record_date DESC
    ");
    $stmt->execute([$fundId]);
    $history = $stmt->fetchAll();

    $totalPaid     = array_sum(array_column($history, 'dividend_per_unit'));
    $count         = count($history);
    $latest        = !empty($history) ? $history[0] : null;

    // Annual breakdown
    $byYear = [];
    foreach ($history as $row) {
        $year = substr($row['record_date'], 0, 4);
        $byYear[$year] = ($byYear[$year] ?? 0) + (float)$row['dividend_per_unit'];
    }
    krsort($byYear);

    // Frequency detection
    $frequency = detect_frequency($history);

    // Yield calculation
    $currentNav = (float)$fund['latest_nav'];
    $trailingYield = 0;
    if ($currentNav > 0 && !empty($byYear)) {
        $lastYear = array_key_first($byYear);
        $trailingYield = round($byYear[$lastYear] / $currentNav * 100, 2);
    }

    $note = null;
    if (empty($history)) {
        $note = "No dividend records found for this fund. To populate: either import from AMFI data, " .
                "or run the dividend import cron. This data requires migration 027_dividends.sql.";
    }

    return [
        'fund_id'           => $fundId,
        'scheme_name'       => $fund['scheme_name'],
        'option_type'       => $fund['option_type'],
        'category'          => $fund['category'],
        'history'           => $history,
        'total_per_unit'    => round($totalPaid, 4),
        'payout_count'      => $count,
        'frequency'         => $frequency,
        'annual_breakdown'  => $byYear,
        'trailing_yield_pct'=> $trailingYield,
        'latest_payout'     => $latest,
        'note'              => $note,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// PORTFOLIO DIVIDEND SUMMARY
// ═══════════════════════════════════════════════════════════════════════════
function portfolio_dividend_summary(PDO $db, int $userId, int $portfolioId): array
{
    $pWhere = $portfolioId > 0 ? 'AND h.portfolio_id = ?' : 'AND p.user_id = ?';
    $pParam = $portfolioId > 0 ? $portfolioId : $userId;

    // Get all IDCW fund holdings
    try {
        $stmt = $db->prepare("
            SELECT h.fund_id, h.units, h.avg_cost_nav,
                   f.scheme_name, f.option_type, f.category, f.latest_nav
            FROM mf_holdings h
            JOIN funds f ON f.id = h.fund_id
            JOIN portfolios p ON p.id = h.portfolio_id
            WHERE h.units > 0.001 $pWhere
              AND f.option_type IN ('idcw', 'dividend')
        ");
        $stmt->execute([$pParam]);
        $holdings = $stmt->fetchAll();
    } catch (Exception $e) {
        return ['error' => 'Holdings fetch failed'];
    }

    if (empty($holdings)) {
        return [
            'idcw_fund_count' => 0,
            'total_dividends_received' => 0,
            'message' => 'No IDCW funds in portfolio. All on Growth plan. ✅',
        ];
    }

    $totalDividendsReceived = 0;
    $fundSummaries = [];

    foreach ($holdings as $h) {
        $fundId = (int)$h['fund_id'];
        $units  = (float)$h['units'];

        // Get first purchase date for this holding
        $firstBuy = null;
        try {
            $b = $db->prepare("
                SELECT MIN(txn_date) FROM mf_transactions t
                JOIN portfolios p ON p.id = t.portfolio_id
                WHERE t.fund_id = ? AND p.user_id = ?
                  AND t.transaction_type IN ('buy','sip')
            ");
            $b->execute([$fundId, $userId]);
            $firstBuy = $b->fetchColumn();
        } catch (Exception $e) {}

        // Get dividends since first purchase
        try {
            $stmt = $db->prepare("
                SELECT SUM(d.dividend_per_unit) AS total_dpu,
                       COUNT(*) AS payouts
                FROM fund_dividends d
                WHERE d.fund_id = ?
                  " . ($firstBuy ? "AND d.record_date >= ?" : "") . "
            ");
            if ($firstBuy) $stmt->execute([$fundId, $firstBuy]);
            else $stmt->execute([$fundId]);
            $divRow = $stmt->fetch();
        } catch (Exception $e) {
            $divRow = ['total_dpu' => 0, 'payouts' => 0];
        }

        $totalDpu        = (float)($divRow['total_dpu'] ?? 0);
        $dividendReceived = round($totalDpu * $units, 2);
        $totalDividendsReceived += $dividendReceived;
        $currentValue    = round($units * (float)$h['latest_nav'], 2);

        $fundSummaries[] = [
            'fund_id'            => $fundId,
            'scheme_name'        => $h['scheme_name'],
            'category'           => $h['category'],
            'units_held'         => $units,
            'current_value'      => $currentValue,
            'total_dpu_received' => round($totalDpu, 4),
            'dividend_received'  => $dividendReceived,
            'payouts_count'      => (int)($divRow['payouts'] ?? 0),
            'first_purchase'     => $firstBuy,
        ];
    }

    return [
        'idcw_fund_count'         => count($holdings),
        'total_dividends_received'=> round($totalDividendsReceived, 2),
        'funds'                   => $fundSummaries,
        'note'                    => 'Dividend income based on units held at time of payout. Actual amounts may vary if units changed.',
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// ANNUAL DIVIDEND CHART DATA
// ═══════════════════════════════════════════════════════════════════════════
function annual_dividend_chart(PDO $db, int $userId, ?int $portfolioId, ?int $fundId): array
{
    // Per-year, sum dividends received across all IDCW holdings
    $years  = [];
    $curYear = (int)date('Y');
    for ($y = $curYear - 5; $y <= $curYear; $y++) $years[$y] = 0.0;

    $pWhere = $portfolioId ? 'AND h.portfolio_id = ?' : 'AND p.user_id = ?';
    $pParam = $portfolioId ?? $userId;

    try {
        $fundFilter = $fundId ? 'AND d.fund_id = ?' : '';
        $params = [$pParam];
        if ($fundId) $params[] = $fundId;

        $stmt = $db->prepare("
            SELECT YEAR(d.record_date) AS yr,
                   SUM(d.dividend_per_unit * h.units) AS total_income
            FROM fund_dividends d
            JOIN mf_holdings h ON h.fund_id = d.fund_id
            JOIN portfolios p ON p.id = h.portfolio_id
            WHERE d.record_date >= DATE_SUB(CURDATE(), INTERVAL 6 YEAR)
              AND h.units > 0 $pWhere $fundFilter
            GROUP BY YEAR(d.record_date)
            ORDER BY yr ASC
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            $years[(int)$r['yr']] = round((float)$r['total_income'], 2);
        }
    } catch (Exception $e) {
        // Table may be empty
    }

    return [
        'labels'  => array_keys($years),
        'values'  => array_values($years),
        'total_6y'=> round(array_sum($years), 2),
        'unit'    => '₹',
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// PER-HOLDING DIVIDEND INCOME
// ═══════════════════════════════════════════════════════════════════════════
function holdings_dividend_income(PDO $db, int $userId, int $portfolioId): array
{
    $pWhere = $portfolioId > 0 ? 'AND h.portfolio_id = ?' : 'AND p.user_id = ?';
    $pParam = $portfolioId > 0 ? $portfolioId : $userId;

    try {
        $stmt = $db->prepare("
            SELECT h.fund_id, h.units, h.avg_cost_nav,
                   f.scheme_name, f.option_type, f.latest_nav,
                   (SELECT SUM(d.dividend_per_unit)
                    FROM fund_dividends d WHERE d.fund_id = h.fund_id) AS total_dpu,
                   (SELECT COUNT(*) FROM fund_dividends d WHERE d.fund_id = h.fund_id) AS payout_count,
                   (SELECT MAX(d.record_date) FROM fund_dividends d WHERE d.fund_id = h.fund_id) AS latest_payout_date,
                   (SELECT d.dividend_per_unit FROM fund_dividends d WHERE d.fund_id = h.fund_id
                    ORDER BY d.record_date DESC LIMIT 1) AS latest_dpu
            FROM mf_holdings h
            JOIN funds f ON f.id = h.fund_id
            JOIN portfolios p ON p.id = h.portfolio_id
            WHERE h.units > 0.001 $pWhere
              AND f.option_type IN ('idcw','dividend')
        ");
        $stmt->execute([$pParam]);
        $rows = $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }

    return array_map(function ($r) {
        $units   = (float)$r['units'];
        $totalDpu= (float)($r['total_dpu'] ?? 0);
        $invsted = $units * (float)$r['avg_cost_nav'];
        $cv      = $units * (float)$r['latest_nav'];
        $divIncome = round($totalDpu * $units, 2);
        $yieldPct  = $cv > 0 ? round(($r['latest_dpu'] ?? 0) / $cv * 12 * 100, 2) : 0; // annualized

        return [
            'fund_id'           => (int)$r['fund_id'],
            'scheme_name'       => $r['scheme_name'],
            'units'             => $units,
            'invested'          => round($invsted, 2),
            'current_value'     => round($cv, 2),
            'total_dpu'         => round($totalDpu, 4),
            'total_dividend_income' => $divIncome,
            'payout_count'      => (int)($r['payout_count'] ?? 0),
            'latest_payout_date'=> $r['latest_payout_date'],
            'latest_dpu'        => round((float)($r['latest_dpu'] ?? 0), 4),
            'annualized_yield'  => $yieldPct,
            'total_return_incl_div' => round(($cv - $invsted + $divIncome) / $invsted * 100, 2),
        ];
    }, $rows);
}

// ═══════════════════════════════════════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════════════════════════════════════
function detect_frequency(array $history): string
{
    if (count($history) < 2) return 'Unknown';
    $dates = array_column($history, 'record_date');
    $diffs = [];
    for ($i = 0; $i < count($dates) - 1; $i++) {
        $diffs[] = abs((new DateTime($dates[$i]))->diff(new DateTime($dates[$i+1]))->days);
    }
    $avg = array_sum($diffs) / count($diffs);
    return match (true) {
        $avg <= 45  => 'Monthly',
        $avg <= 100 => 'Quarterly',
        $avg <= 200 => 'Half-yearly',
        $avg <= 380 => 'Annual',
        default     => 'Irregular',
    };
}
