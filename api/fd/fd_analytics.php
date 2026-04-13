<?php
/**
 * WealthDash — FD Enhancement APIs
 *
 * t276 — FD Interest Rate Tracker (best rates comparison)
 * t277 — TDS Tracker on FD (26AS reconciliation)
 * t278 — Recurring Deposit (RD) Module
 *
 * GET /api/fd/fd_analytics.php
 *   ?action=rate_tracker     &portfolio_id=X
 *   ?action=tds_tracker      &portfolio_id=X
 *   ?action=rd_list          &portfolio_id=X
 *   ?action=rd_add           (POST)
 *   ?action=full_dashboard   &portfolio_id=X
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
    $action      = $_GET['action'] ?? 'full_dashboard';
    $portfolioId = (int)($_GET['portfolio_id'] ?? 0);
    $userId      = (int)$currentUser['id'];
    $method      = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    $response = ['success' => true, 'action' => $action];

    // POST actions for RD
    if ($method === 'POST') {
        ensure_rd_table($db);
        switch ($action) {
            case 'rd_add':    $response += rd_add($db, $userId, $portfolioId); break;
            case 'rd_update': $response += rd_update($db, $userId); break;
            case 'rd_delete': $response += rd_delete($db, $userId); break;
            default: throw new InvalidArgumentException("Unknown POST action: $action");
        }
    } else {
        switch ($action) {
            case 'rate_tracker':
                $response['rate_tracker'] = fd_rate_tracker($db, $userId, $portfolioId);
                break;
            case 'tds_tracker':
                $response['tds_tracker']  = fd_tds_tracker($db, $userId, $portfolioId);
                break;
            case 'rd_list':
                ensure_rd_table($db);
                $response['rd_list'] = rd_list($db, $userId, $portfolioId);
                break;
            case 'full_dashboard':
            default:
                ensure_rd_table($db);
                $response['rate_tracker'] = fd_rate_tracker($db, $userId, $portfolioId);
                $response['tds_tracker']  = fd_tds_tracker($db, $userId, $portfolioId);
                $response['rd_list']      = rd_list($db, $userId, $portfolioId);
                break;
        }
    }

    ob_clean();
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    ob_clean(); http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

// ═══════════════════════════════════════════════════════════════════════════
// t276 — FD INTEREST RATE TRACKER
// ═══════════════════════════════════════════════════════════════════════════
function fd_rate_tracker(PDO $db, int $userId, int $portfolioId): array
{
    $pWhere = $portfolioId > 0 ? 'AND p.id = ?' : 'AND p.user_id = ?';
    $pParam = $portfolioId > 0 ? $portfolioId : $userId;

    // User's active FDs
    $userFDs = [];
    try {
        $stmt = $db->prepare("
            SELECT fa.id, fa.bank_name, fa.principal, fa.interest_rate, fa.tenure_days,
                   fa.start_date, fa.maturity_date, fa.maturity_amount,
                   DATEDIFF(fa.maturity_date, CURDATE()) AS days_left
            FROM fd_accounts fa JOIN portfolios p ON p.id = fa.portfolio_id
            WHERE fa.status = 'active' $pWhere
            ORDER BY fa.maturity_date ASC
        ");
        $stmt->execute([$pParam]);
        $userFDs = $stmt->fetchAll();
    } catch (Exception $e) {}

    // Best available rates (curated — should be updated via admin)
    $bestRates = get_best_fd_rates();

    // Compare user FDs vs best available
    $opportunities = [];
    foreach ($userFDs as $fd) {
        $tenure_months = round($fd['tenure_days'] / 30);
        $bestForTenure = find_best_rate($bestRates, $tenure_months);

        $gap = round($bestForTenure['rate'] - (float)$fd['interest_rate'], 2);
        if ($gap >= 0.25) { // meaningful difference
            $opportunityCost = round((float)$fd['principal'] * $gap / 100, 0);
            $opportunities[] = [
                'fd_id'            => (int)$fd['id'],
                'bank'             => $fd['bank_name'],
                'your_rate'        => (float)$fd['interest_rate'],
                'best_rate'        => $bestForTenure['rate'],
                'best_bank'        => $bestForTenure['bank'],
                'gap_pct'          => $gap,
                'annual_loss'      => $opportunityCost,
                'tenure_label'     => "{$tenure_months} months",
                'maturity_date'    => $fd['maturity_date'],
                'days_to_maturity' => (int)$fd['days_left'],
                'action'           => $fd['days_left'] <= 30
                    ? "FD maturing soon — reinvest at {$bestForTenure['bank']} ({$bestForTenure['rate']}%)"
                    : "Consider: at maturity, switch to {$bestForTenure['bank']} for +{$gap}% more",
            ];
        }
    }

    return [
        'user_fds'         => $userFDs,
        'best_market_rates'=> $bestRates,
        'opportunities'    => $opportunities,
        'total_opportunity_cost' => array_sum(array_column($opportunities, 'annual_loss')),
        'last_updated'     => date('Y-m-d'),
        'note'             => 'Market rates are approximate and updated periodically. Verify with respective bank before investing.',
        'update_rates_api' => '/api/admin/settings.php?action=update_fd_rates (admin only)',
    ];
}

function get_best_fd_rates(): array
{
    // Curated rates as of early 2025 (update periodically via admin)
    return [
        ['bank' => 'Unity Small Finance Bank',  'rate' => 9.00, 'tenure_months' => 12,  'type' => 'small_finance', 'min_amount' => 10000],
        ['bank' => 'Suryoday Small Finance',    'rate' => 8.60, 'tenure_months' => 12,  'type' => 'small_finance', 'min_amount' => 5000],
        ['bank' => 'Utkarsh Small Finance',     'rate' => 8.50, 'tenure_months' => 24,  'type' => 'small_finance', 'min_amount' => 5000],
        ['bank' => 'IDFC First Bank',           'rate' => 7.90, 'tenure_months' => 12,  'type' => 'private',       'min_amount' => 10000],
        ['bank' => 'RBL Bank',                  'rate' => 8.00, 'tenure_months' => 15,  'type' => 'private',       'min_amount' => 10000],
        ['bank' => 'Yes Bank',                  'rate' => 8.00, 'tenure_months' => 36,  'type' => 'private',       'min_amount' => 10000],
        ['bank' => 'IndusInd Bank',             'rate' => 7.75, 'tenure_months' => 12,  'type' => 'private',       'min_amount' => 10000],
        ['bank' => 'DCB Bank',                  'rate' => 7.90, 'tenure_months' => 19,  'type' => 'private',       'min_amount' => 10000],
        ['bank' => 'HDFC Bank',                 'rate' => 7.40, 'tenure_months' => 12,  'type' => 'private_large', 'min_amount' => 5000],
        ['bank' => 'ICICI Bank',                'rate' => 7.25, 'tenure_months' => 12,  'type' => 'private_large', 'min_amount' => 10000],
        ['bank' => 'Axis Bank',                 'rate' => 7.20, 'tenure_months' => 12,  'type' => 'private_large', 'min_amount' => 10000],
        ['bank' => 'Kotak Bank',                'rate' => 7.40, 'tenure_months' => 12,  'type' => 'private_large', 'min_amount' => 5000],
        ['bank' => 'SBI',                       'rate' => 7.10, 'tenure_months' => 12,  'type' => 'public',        'min_amount' => 1000],
        ['bank' => 'Bank of Baroda',            'rate' => 7.25, 'tenure_months' => 12,  'type' => 'public',        'min_amount' => 1000],
        ['bank' => 'Canara Bank',               'rate' => 7.25, 'tenure_months' => 12,  'type' => 'public',        'min_amount' => 1000],
        ['bank' => 'Post Office',               'rate' => 7.50, 'tenure_months' => 60,  'type' => 'government',    'min_amount' => 1000],
    ];
}

function find_best_rate(array $rates, int $tenureMonths): array
{
    $best = null;
    foreach ($rates as $r) {
        if (abs($r['tenure_months'] - $tenureMonths) <= 6) {
            if (!$best || $r['rate'] > $best['rate']) $best = $r;
        }
    }
    return $best ?? ['rate' => 7.5, 'bank' => 'Post Office'];
}

// ═══════════════════════════════════════════════════════════════════════════
// t277 — TDS TRACKER ON FD
// ═══════════════════════════════════════════════════════════════════════════
function fd_tds_tracker(PDO $db, int $userId, int $portfolioId): array
{
    $pWhere   = $portfolioId > 0 ? 'AND p.id = ?' : 'AND p.user_id = ?';
    $pParam   = $portfolioId > 0 ? $portfolioId : $userId;
    $fyStart  = fy_start();
    $fyEnd    = fy_end();
    $isSenior = false; // TODO: pull from user profile
    $tdsThreshold = $isSenior ? 50000 : 40000;
    $tdsRate  = 0.10; // 10% TDS on FD interest

    $userFDs = [];
    try {
        $stmt = $db->prepare("
            SELECT fa.id, fa.bank_name, fa.principal, fa.interest_rate,
                   fa.start_date, fa.maturity_date, fa.status,
                   ROUND(fa.principal * (fa.interest_rate/100) *
                   DATEDIFF(LEAST(fa.maturity_date, ?), GREATEST(fa.start_date, ?)) / 365, 2) AS fy_interest
            FROM fd_accounts fa JOIN portfolios p ON p.id = fa.portfolio_id
            WHERE fa.status IN ('active','matured') $pWhere
        ");
        $stmt->execute([$fyEnd, $fyStart, $pParam]);
        $userFDs = $stmt->fetchAll();
    } catch (Exception $e) {}

    // Group by bank for TDS calculation (TDS threshold is per bank)
    $byBank = [];
    foreach ($userFDs as $fd) {
        $bank = $fd['bank_name'] ?? 'Unknown';
        if (!isset($byBank[$bank])) $byBank[$bank] = ['fds' => [], 'total_interest' => 0];
        $byBank[$bank]['fds'][] = $fd;
        $byBank[$bank]['total_interest'] += (float)$fd['fy_interest'];
    }

    $tdsBreakdown = [];
    $totalTdsDeductible = 0;
    $totalInterest = 0;

    foreach ($byBank as $bank => $data) {
        $interest = round($data['total_interest'], 2);
        $totalInterest += $interest;
        $tdsApplicable = $interest > $tdsThreshold;
        $tdsAmount = $tdsApplicable ? round($interest * $tdsRate, 0) : 0;
        $totalTdsDeductible += $tdsAmount;

        $tdsBreakdown[] = [
            'bank'             => $bank,
            'fy_interest'      => $interest,
            'tds_threshold'    => $tdsThreshold,
            'tds_applicable'   => $tdsApplicable,
            'tds_deducted'     => $tdsAmount,
            'form_15g_needed'  => !$tdsApplicable && $interest > 10000, // Optional but advisable
            'status_badge'     => $tdsApplicable
                ? "⚠️ TDS deductible: ₹{$tdsAmount}"
                : "✅ Below threshold — No TDS",
        ];
    }

    $form15GDeadline = fy_start(); // Submit at start of FY

    return [
        'fy'                    => current_fy(),
        'tds_threshold'         => $tdsThreshold,
        'is_senior_citizen'     => $isSenior,
        'total_fy_interest'     => round($totalInterest, 2),
        'total_tds_deductible'  => $totalTdsDeductible,
        'bank_breakdown'        => $tdsBreakdown,
        'form_15g_h' => [
            'applicable'    => $totalTdsDeductible === 0 && $totalInterest > 0,
            'deadline'      => $form15GDeadline,
            'note'          => 'Form 15G (non-senior) or 15H (senior) can be submitted to bank at start of FY to avoid TDS deduction if total income is below taxable limit.',
        ],
        'reconciliation_note' => 'Match TDS amounts with Form 26AS from income tax portal. Any discrepancy needs to be reported to the bank.',
        'tds_credit' => [
            'how_to_claim' => 'TDS deducted by bank appears in Form 26AS. Claim credit while filing ITR under "Tax Credits".',
            'refund'       => 'If total tax < TDS deducted, the excess is refunded by IT department (usually within 3-6 months of filing).',
        ],
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// t278 — RECURRING DEPOSIT MODULE
// ═══════════════════════════════════════════════════════════════════════════
function ensure_rd_table(PDO $db): void
{
    try { $db->query("SELECT 1 FROM rd_accounts LIMIT 1"); }
    catch (Exception $e) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS rd_accounts (
                id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                portfolio_id    INT UNSIGNED NOT NULL,
                bank_name       VARCHAR(100) NOT NULL,
                monthly_amount  DECIMAL(12,2) NOT NULL,
                interest_rate   DECIMAL(5,2) NOT NULL,
                tenure_months   SMALLINT UNSIGNED NOT NULL,
                start_date      DATE NOT NULL,
                maturity_date   DATE NOT NULL,
                maturity_amount DECIMAL(14,2) DEFAULT NULL,
                status          ENUM('active','matured','closed') DEFAULT 'active',
                account_number  VARCHAR(50)  DEFAULT NULL,
                branch          VARCHAR(100) DEFAULT NULL,
                notes           TEXT,
                created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_portfolio (portfolio_id),
                INDEX idx_status (status),
                INDEX idx_maturity (maturity_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

function rd_list(PDO $db, int $userId, int $portfolioId): array
{
    $pWhere = $portfolioId > 0 ? 'AND p.id = ?' : 'AND p.user_id = ?';
    $pParam = $portfolioId > 0 ? $portfolioId : $userId;

    try {
        $stmt = $db->prepare("
            SELECT rd.*, p.name AS portfolio_name,
                   DATEDIFF(rd.maturity_date, CURDATE()) AS days_left,
                   rd.monthly_amount * rd.tenure_months AS total_invested
            FROM rd_accounts rd JOIN portfolios p ON p.id = rd.portfolio_id
            WHERE rd.status = 'active' $pWhere
            ORDER BY rd.maturity_date ASC
        ");
        $stmt->execute([$pParam]);
        $rds = $stmt->fetchAll();
    } catch (Exception $e) { $rds = []; }

    // Calculate accrued maturity for each RD
    foreach ($rds as &$rd) {
        $months    = (int)$rd['tenure_months'];
        $monthly   = (float)$rd['monthly_amount'];
        $rate      = (float)$rd['interest_rate'] / 100 / 4; // quarterly compounding
        // RD maturity formula: M = R × [(1+i)^n - 1] / (1-(1+i)^(-1/3))
        // Simplified: use standard RD formula
        $maturity  = rd_maturity($monthly, (float)$rd['interest_rate'], $months);
        $rd['calculated_maturity'] = round($maturity, 0);
        $rd['total_interest']      = round($maturity - $monthly * $months, 0);
        $rd['days_left']           = max(0, (int)$rd['days_left']);
        $rd['months_completed']    = max(0, $months - (int)ceil($rd['days_left'] / 30));
        $rd['amount_invested_so_far'] = round($monthly * $rd['months_completed'], 0);
    }

    $totalMonthlyCommitment = array_sum(array_column($rds, 'monthly_amount'));
    $totalMaturityValue     = array_sum(array_column($rds, 'calculated_maturity'));

    return [
        'rds'                      => $rds,
        'count'                    => count($rds),
        'total_monthly_commitment' => round($totalMonthlyCommitment, 0),
        'total_maturity_value'     => round($totalMaturityValue, 0),
        'upcoming_maturities'      => array_values(array_filter($rds, fn($r) => $r['days_left'] <= 60)),
    ];
}

function rd_maturity(float $monthly, float $annualRate, int $months): float
{
    // Standard RD maturity with quarterly compounding
    $r = $annualRate / 400; // quarterly rate as decimal
    $maturity = 0;
    for ($i = $months; $i >= 1; $i--) {
        $quarters = $i / 3;
        $maturity += $monthly * ((1 + $r) ** $quarters);
    }
    return $maturity;
}

function rd_add(PDO $db, int $userId, int $portfolioId): array
{
    $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;

    $pId       = (int)($body['portfolio_id'] ?? $portfolioId);
    $bank      = trim($body['bank_name']      ?? '');
    $monthly   = (float)($body['monthly_amount'] ?? 0);
    $rate      = (float)($body['interest_rate']  ?? 0);
    $tenure    = (int)($body['tenure_months']    ?? 0);
    $startDate = $body['start_date'] ?? date('Y-m-d');

    if (!$bank || $monthly <= 0 || $rate <= 0 || $tenure <= 0) {
        throw new InvalidArgumentException('bank_name, monthly_amount, interest_rate, tenure_months required');
    }

    // Verify portfolio ownership
    $port = $db->prepare("SELECT id FROM portfolios WHERE id = ? AND user_id = ?");
    $port->execute([$pId, $userId]);
    if (!$port->fetch()) throw new RuntimeException('Portfolio not found');

    $maturityDate   = date('Y-m-d', strtotime("+{$tenure} months", strtotime($startDate)));
    $maturityAmount = round(rd_maturity($monthly, $rate, $tenure), 2);

    $stmt = $db->prepare("
        INSERT INTO rd_accounts
            (portfolio_id, bank_name, monthly_amount, interest_rate, tenure_months,
             start_date, maturity_date, maturity_amount, account_number, branch, notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $pId, $bank, $monthly, $rate, $tenure,
        $startDate, $maturityDate, $maturityAmount,
        $body['account_number'] ?? null,
        $body['branch']         ?? null,
        $body['notes']          ?? null,
    ]);

    return [
        'added'           => true,
        'id'              => (int)$db->lastInsertId(),
        'maturity_date'   => $maturityDate,
        'maturity_amount' => $maturityAmount,
        'total_invested'  => $monthly * $tenure,
        'total_interest'  => round($maturityAmount - $monthly * $tenure, 2),
    ];
}

function rd_update(PDO $db, int $userId): array
{
    $body   = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $rdId   = (int)($body['id'] ?? 0);
    $status = $body['status'] ?? null;

    if (!$rdId) throw new InvalidArgumentException('id required');

    // Verify ownership via portfolio
    $check = $db->prepare("
        SELECT rd.id FROM rd_accounts rd JOIN portfolios p ON p.id = rd.portfolio_id
        WHERE rd.id = ? AND p.user_id = ?
    ");
    $check->execute([$rdId, $userId]);
    if (!$check->fetch()) throw new RuntimeException('RD not found');

    $fields = [];
    $params = [];
    if ($status)                        { $fields[] = 'status = ?';            $params[] = $status; }
    if (isset($body['notes']))          { $fields[] = 'notes = ?';             $params[] = $body['notes']; }
    if (isset($body['account_number'])) { $fields[] = 'account_number = ?';   $params[] = $body['account_number']; }

    if (empty($fields)) throw new InvalidArgumentException('Nothing to update');

    $params[] = $rdId;
    $db->prepare("UPDATE rd_accounts SET " . implode(', ', $fields) . " WHERE id = ?")
       ->execute($params);

    return ['updated' => true, 'id' => $rdId];
}

function rd_delete(PDO $db, int $userId): array
{
    $body = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $rdId = (int)($body['id'] ?? 0);
    if (!$rdId) throw new InvalidArgumentException('id required');

    $check = $db->prepare("
        SELECT rd.id FROM rd_accounts rd JOIN portfolios p ON p.id = rd.portfolio_id
        WHERE rd.id = ? AND p.user_id = ?
    ");
    $check->execute([$rdId, $userId]);
    if (!$check->fetch()) throw new RuntimeException('RD not found');

    $db->prepare("DELETE FROM rd_accounts WHERE id = ?")->execute([$rdId]);
    return ['deleted' => true, 'id' => $rdId];
}

function current_fy(): string {
    $m = (int)date('n'); $y = (int)date('Y');
    $fy = $m >= 4 ? $y : $y - 1;
    return "FY {$fy}-" . ($fy + 1);
}
function fy_start(): string {
    $m = (int)date('n'); $y = (int)date('Y');
    return ($m >= 4 ? $y : $y - 1) . '-04-01';
}
function fy_end(): string {
    $m = (int)date('n'); $y = (int)date('Y');
    return ($m >= 4 ? $y + 1 : $y) . '-03-31';
}
