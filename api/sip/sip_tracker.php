<?php
/**
 * WealthDash — SIP Nav Fetch + SIP/SWP Tracker API
 * Tasks: t11 (SIP past NAV fetch), t12, t13 (SIP Tracker + SWP)
 * Actions: sip_nav_fetch | sip_list | swp_list | sip_summary
 *          sip_add | sip_stop | swp_add | swp_stop
 *          sip_performance | sip_stepup_calc
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'sip_list';
$db          = DB::conn();

// Ensure SIP/SWP tables
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS mf_sip_schedules (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id         INT UNSIGNED NOT NULL,
            fund_id         INT UNSIGNED NOT NULL,
            portfolio_id    INT UNSIGNED NOT NULL,
            monthly_amount  DECIMAL(12,2) NOT NULL,
            frequency       ENUM('monthly','weekly','quarterly','daily') NOT NULL DEFAULT 'monthly',
            sip_day         TINYINT UNSIGNED DEFAULT 1 COMMENT 'Day of month',
            start_date      DATE NOT NULL,
            end_date        DATE DEFAULT NULL,
            step_up_pct     DECIMAL(5,2) DEFAULT 0 COMMENT 'Annual step-up percentage',
            status          ENUM('active','paused','stopped','completed') NOT NULL DEFAULT 'active',
            folio_number    VARCHAR(30) DEFAULT NULL,
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user   (user_id),
            INDEX idx_fund   (fund_id),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS mf_swp_schedules (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id         INT UNSIGNED NOT NULL,
            fund_id         INT UNSIGNED NOT NULL,
            portfolio_id    INT UNSIGNED NOT NULL,
            monthly_amount  DECIMAL(12,2) NOT NULL,
            frequency       ENUM('monthly','quarterly') NOT NULL DEFAULT 'monthly',
            swp_day         TINYINT UNSIGNED DEFAULT 1,
            start_date      DATE NOT NULL,
            end_date        DATE DEFAULT NULL,
            status          ENUM('active','paused','stopped','completed') NOT NULL DEFAULT 'active',
            created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id), INDEX idx_fund (fund_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
} catch (Exception $e) {}

switch ($action) {

// ══════════════════════════════════════════════════════════════════════════
// sip_nav_fetch — t11: Auto-download NAV history for new SIP fund
// ══════════════════════════════════════════════════════════════════════════
case 'sip_nav_fetch':
    $fundId = (int)($_POST['fund_id'] ?? 0);
    if (!$fundId) { echo json_encode(['success' => false, 'msg' => 'fund_id required']); break; }

    $fund = $db->prepare("SELECT scheme_code FROM funds WHERE id=? LIMIT 1");
    $fund->execute([$fundId]);
    $code = $fund->fetchColumn();
    if (!$code) { echo json_encode(['success' => false, 'msg' => 'Fund not found']); break; }

    // Check existing history
    $existCount = (int)$db->prepare("SELECT COUNT(*) FROM nav_history WHERE fund_id=?")->execute([$fundId]) ? 0 : 0;
    $stmt = $db->prepare("SELECT COUNT(*) FROM nav_history WHERE fund_id=?");
    $stmt->execute([$fundId]); $existCount = (int)$stmt->fetchColumn();

    if ($existCount >= 100) {
        echo json_encode(['success' => true, 'msg' => "Already have $existCount NAV entries", 'fetched' => 0]);
        break;
    }

    // Fetch from MFAPI
    $url  = "https://api.mfapi.in/mf/{$code}";
    $ch   = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $resp = curl_exec($ch);
    $code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code2 !== 200 || !$resp) {
        echo json_encode(['success' => false, 'msg' => 'MFAPI unreachable']); break;
    }

    $data    = json_decode($resp, true);
    $navData = $data['data'] ?? [];
    $inserted = 0;

    $db->beginTransaction();
    $ins = $db->prepare("INSERT IGNORE INTO nav_history (fund_id, nav_date, nav) VALUES (?,?,?)");
    foreach ($navData as $row) {
        if (isset($row['date'], $row['nav'])) {
            $parts = explode('-', $row['date']);
            if (count($parts) === 3) {
                $ins->execute([$fundId, "{$parts[2]}-{$parts[1]}-{$parts[0]}", (float)$row['nav']]);
                $inserted++;
            }
        }
    }
    $db->commit();

    echo json_encode(['success' => true, 'fetched' => $inserted, 'fund_id' => $fundId]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// sip_list — Active/all SIPs
// ══════════════════════════════════════════════════════════════════════════
case 'sip_list':
    $status = $_GET['status'] ?? 'active';
    $where  = $status !== 'all' ? "AND s.status = ?" : "";
    $params = [$userId];
    if ($status !== 'all') $params[] = $status;

    $stmt = $db->prepare("
        SELECT s.*, f.fund_name, f.fund_house, f.category, f.current_nav, f.returns_1y,
               f.category_avg_1y, f.sharpe_ratio,
               DATEDIFF(NOW(), s.start_date) AS days_running,
               TIMESTAMPDIFF(MONTH, s.start_date, NOW()) AS months_running,
               (SELECT SUM(t.amount) FROM mf_transactions t
                JOIN mf_holdings mh ON mh.id = t.holding_id
                WHERE mh.fund_id=s.fund_id AND mh.portfolio_id=s.portfolio_id
                  AND t.tx_type='sip' AND t.user_id=s.user_id) AS total_invested_via_sip
        FROM mf_sip_schedules s
        JOIN funds f ON f.id = s.fund_id
        WHERE s.user_id = ? $where
        ORDER BY s.status ASC, s.monthly_amount DESC
    ");
    $stmt->execute($params);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// swp_list — t13
// ══════════════════════════════════════════════════════════════════════════
case 'swp_list':
    $stmt = $db->prepare("
        SELECT sw.*, f.fund_name, f.category, f.current_nav,
               (SELECT SUM(t.amount) FROM mf_transactions t
                JOIN mf_holdings mh ON mh.id=t.holding_id
                WHERE mh.fund_id=sw.fund_id AND t.tx_type='swp' AND t.user_id=sw.user_id) AS total_withdrawn
        FROM mf_swp_schedules sw
        JOIN funds f ON f.id = sw.fund_id
        WHERE sw.user_id = ? ORDER BY sw.status, sw.monthly_amount DESC
    ");
    $stmt->execute([$userId]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// sip_add / swp_add
// ══════════════════════════════════════════════════════════════════════════
case 'sip_add':
    $fundId      = (int)($_POST['fund_id'] ?? 0);
    $amount      = (float)($_POST['amount'] ?? 0);
    $startDate   = $_POST['start_date'] ?? date('Y-m-d');
    $sipDay      = (int)($_POST['sip_day'] ?? 1);
    $stepUp      = (float)($_POST['step_up_pct'] ?? 0);
    $portfolioId = (int)($_POST['portfolio_id'] ?? 0);

    if (!$fundId || $amount <= 0) { echo json_encode(['success' => false, 'msg' => 'fund_id and amount required']); break; }
    if (!$portfolioId) {
        $p = $db->prepare("SELECT id FROM portfolios WHERE user_id=? AND is_default=1 LIMIT 1");
        $p->execute([$userId]); $portfolioId = (int)$p->fetchColumn();
    }

    $db->prepare("
        INSERT INTO mf_sip_schedules (user_id, fund_id, portfolio_id, monthly_amount, sip_day, start_date, step_up_pct, status)
        VALUES (?,?,?,?,?,?,?,'active')
    ")->execute([$userId, $fundId, $portfolioId, $amount, $sipDay, $startDate, $stepUp]);

    // Trigger NAV history fetch for this fund
    @exec('php ' . __DIR__ . '/../sip/sip_nav_fetch.php --fund=' . $fundId . ' > /dev/null 2>&1 &');

    echo json_encode(['success' => true, 'id' => $db->lastInsertId()]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// sip_stop — t10: Stop SIP/SWP
// ══════════════════════════════════════════════════════════════════════════
case 'sip_stop':
    $sipId = (int)($_POST['sip_id'] ?? 0);
    if (!$sipId) { echo json_encode(['success' => false, 'msg' => 'sip_id required']); break; }
    $db->prepare("UPDATE mf_sip_schedules SET status='stopped', end_date=CURDATE() WHERE id=? AND user_id=?")->execute([$sipId, $userId]);
    echo json_encode(['success' => true]);
    break;

case 'swp_stop':
    $swpId = (int)($_POST['swp_id'] ?? 0);
    $db->prepare("UPDATE mf_swp_schedules SET status='stopped', end_date=CURDATE() WHERE id=? AND user_id=?")->execute([$swpId, $userId]);
    echo json_encode(['success' => true]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// sip_performance — t72: Actual vs Expected XIRR per SIP
// ══════════════════════════════════════════════════════════════════════════
case 'sip_performance':
    require_once ROOT . '/includes/holding_calculator.php';

    $stmt = $db->prepare("
        SELECT s.id AS sip_id, s.fund_id, s.monthly_amount, s.start_date, s.step_up_pct,
               f.fund_name, f.category, f.returns_1y, f.category_avg_1y
        FROM mf_sip_schedules s
        JOIN funds f ON f.id = s.fund_id
        WHERE s.user_id = ? AND s.status IN ('active','stopped','completed')
        ORDER BY s.monthly_amount DESC
    ");
    $stmt->execute([$userId]);
    $sips = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $results = [];
    foreach ($sips as $sip) {
        // Get all SIP transactions for this fund
        $txns = $db->prepare("
            SELECT t.tx_date, -t.amount AS cf
            FROM mf_transactions t
            JOIN mf_holdings mh ON mh.id = t.holding_id
            WHERE mh.fund_id = ? AND t.user_id = ? AND t.tx_type = 'sip'
            ORDER BY t.tx_date ASC
        ");
        $txns->execute([$sip['fund_id'], $userId]);
        $sipTxns = $txns->fetchAll(PDO::FETCH_ASSOC);

        if (empty($sipTxns)) { $results[] = array_merge($sip, ['xirr' => null]); continue; }

        // Current value of SIP units
        $cv = $db->prepare("SELECT current_value FROM mf_holdings mh JOIN portfolios p ON p.id=mh.portfolio_id WHERE mh.fund_id=? AND p.user_id=? AND mh.is_active=1 LIMIT 1");
        $cv->execute([$sip['fund_id'], $userId]);
        $currentValue = (float)($cv->fetchColumn() ?? 0);

        $amounts = array_column($sipTxns, 'cf');
        $dates   = array_column($sipTxns, 'tx_date');
        if ($currentValue > 0) { $amounts[] = $currentValue; $dates[] = date('Y-m-d'); }

        $xirr = HoldingCalculator::xirr(array_map('floatval', $amounts), $dates);
        $totalInvested = array_sum(array_map('abs', array_map('floatval', array_column($sipTxns, 'cf'))));

        $results[] = array_merge($sip, [
            'xirr'          => $xirr,
            'total_invested'=> round($totalInvested, 2),
            'current_value' => round($currentValue, 2),
            'gain_loss'     => round($currentValue - $totalInvested, 2),
            'abs_return_pct'=> $totalInvested > 0 ? round(($currentValue - $totalInvested) / $totalInvested * 100, 2) : 0,
            'vs_category'   => ($xirr !== null && $sip['category_avg_1y']) ? round($xirr - (float)$sip['category_avg_1y'], 2) : null,
        ]);
    }

    echo json_encode(['success' => true, 'data' => $results]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// sip_stepup_calc — t174: Step-up SIP projection
// ══════════════════════════════════════════════════════════════════════════
case 'sip_stepup_calc':
    $initialSip = (float)($_POST['initial_sip'] ?? 5000);
    $stepUpPct  = (float)($_POST['step_up_pct'] ?? 10);
    $years      = min(40, max(1, (int)($_POST['years'] ?? 10)));
    $rate       = (float)($_POST['expected_return_pct'] ?? 12) / 100 / 12; // monthly rate

    $regularFV = 0; $stepUpFV = 0;
    $sipAmt     = $initialSip;
    $regularAmt = $initialSip;
    $totalInvestedRegular = 0; $totalInvestedStepup = 0;

    for ($yr = 0; $yr < $years; $yr++) {
        $sipAmt = ($yr > 0) ? $sipAmt * (1 + $stepUpPct / 100) : $sipAmt;
        for ($m = 0; $m < 12; $m++) {
            $monthsLeft     = ($years - $yr) * 12 - $m;
            $regularFV     += $regularAmt * pow(1 + $rate, $monthsLeft);
            $stepUpFV      += $sipAmt    * pow(1 + $rate, $monthsLeft);
            $totalInvestedRegular += $regularAmt;
            $totalInvestedStepup  += $sipAmt;
        }
    }

    echo json_encode(['success' => true, 'data' => [
        'regular_fv'     => round($regularFV, 2),
        'stepup_fv'      => round($stepUpFV, 2),
        'extra_gain'     => round($stepUpFV - $regularFV, 2),
        'total_invested_regular' => round($totalInvestedRegular, 2),
        'total_invested_stepup'  => round($totalInvestedStepup, 2),
        'initial_sip'   => $initialSip,
        'step_up_pct'   => $stepUpPct,
        'years'         => $years,
        'rate_pct'      => (float)($_POST['expected_return_pct'] ?? 12),
    ]]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// sip_summary — Dashboard summary card
// ══════════════════════════════════════════════════════════════════════════
case 'sip_summary':
    $stmt = $db->prepare("
        SELECT
          COUNT(CASE WHEN status='active' THEN 1 END)            AS active_sips,
          COALESCE(SUM(CASE WHEN status='active' THEN monthly_amount END), 0) AS monthly_total,
          COUNT(CASE WHEN status='stopped' THEN 1 END)           AS stopped_sips
        FROM mf_sip_schedules WHERE user_id = ?
    ");
    $stmt->execute([$userId]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    $swpStmt = $db->prepare("SELECT COUNT(*) AS active_swps, COALESCE(SUM(monthly_amount),0) AS swp_monthly FROM mf_swp_schedules WHERE user_id=? AND status='active'");
    $swpStmt->execute([$userId]);
    $swpSum = $swpStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => array_merge($summary, $swpSum)]);
    break;

default:
    echo json_encode(['success' => false, 'msg' => "Unknown action: $action"]);
}
