<?php
/**
 * WealthDash — PMS / AIF Tracker API
 * Task: t119
 * Actions: pms_list | pms_detail | pms_summary | pms_txns | pms_nav_history
 *          pms_add  | pms_edit  | pms_delete
 *          pms_txn_add | pms_txn_delete
 *          pms_nav_add | pms_update_value
 */

if (!defined('WEALTHDASH')) {
    define('WEALTHDASH', true);
    ob_start();
    require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
    require_once APP_ROOT . '/includes/auth_check.php';
    require_once APP_ROOT . '/includes/helpers.php';
    header('Content-Type: application/json; charset=utf-8');
    error_reporting(0);
    ini_set('display_errors', '0');
}

set_exception_handler(function (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
});

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'pms_list';
$db          = DB::conn();

// ── Helper: resolve portfolio_id ─────────────────────────────────────────
function pms_get_portfolio(PDO $db, int $userId): int {
    $stmt = $db->prepare("SELECT id FROM portfolios WHERE user_id = ? AND is_default = 1 LIMIT 1");
    $stmt->execute([$userId]);
    $pid = $stmt->fetchColumn();
    if ($pid) return (int)$pid;
    $db->prepare("INSERT INTO portfolios (user_id, name, is_default, created_at) VALUES (?, 'My Portfolio', 1, NOW())")
       ->execute([$userId]);
    return (int)$db->lastInsertId();
}

// ── Helper: validate ownership ───────────────────────────────────────────
function pms_own(PDO $db, int $holdingId, int $userId): bool {
    $stmt = $db->prepare("SELECT id FROM pms_holdings WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$holdingId, $userId]);
    return (bool)$stmt->fetchColumn();
}

switch ($action) {

// ══════════════════════════════════════════════════════════════════════════
// pms_list — all holdings for user
// ══════════════════════════════════════════════════════════════════════════
case 'pms_list':
    $assetFilter = clean($_GET['asset_class'] ?? '');
    $activeOnly  = (int)($_GET['active'] ?? 1);

    $where  = ['h.user_id = ?'];
    $params = [$userId];

    if ($assetFilter) {
        $where[]  = 'h.asset_class = ?';
        $params[] = $assetFilter;
    }
    if ($activeOnly) {
        $where[]  = 'h.is_active = 1';
    }

    $stmt = $db->prepare("
        SELECT h.*,
               (SELECT SUM(t.amount) FROM pms_transactions t
                WHERE t.holding_id = h.id AND t.txn_type = 'investment')   AS total_invested_txn,
               (SELECT SUM(t.amount) FROM pms_transactions t
                WHERE t.holding_id = h.id AND t.txn_type = 'withdrawal')   AS total_withdrawn,
               (SELECT MAX(t.txn_date) FROM pms_transactions t
                WHERE t.holding_id = h.id)                                  AS last_txn_date,
               (SELECT COUNT(*) FROM pms_transactions t
                WHERE t.holding_id = h.id)                                  AS txn_count
        FROM pms_holdings h
        WHERE " . implode(' AND ', $where) . "
        ORDER BY h.invested_amount DESC
    ");
    $stmt->execute($params);
    $holdings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Compute lock-in status
    foreach ($holdings as &$h) {
        $h['locked'] = false;
        if ($h['lock_in_end_date']) {
            $h['locked']            = strtotime($h['lock_in_end_date']) > time();
            $h['days_to_unlock']    = max(0, (int)((strtotime($h['lock_in_end_date']) - time()) / 86400));
        }
        $h['xirr_display'] = $h['xirr'] !== null ? round((float)$h['xirr'], 2) : null;
    }
    unset($h);

    echo json_encode(['success' => true, 'data' => $holdings, 'count' => count($holdings)]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// pms_summary — aggregate stats
// ══════════════════════════════════════════════════════════════════════════
case 'pms_summary':
    $stmt = $db->prepare("
        SELECT
            COUNT(*)                              AS total_holdings,
            SUM(CASE WHEN is_active=1 THEN 1 ELSE 0 END) AS active_count,
            SUM(invested_amount)                  AS total_invested,
            SUM(current_value)                    AS total_current,
            SUM(current_value - invested_amount)  AS total_gain_loss,
            SUM(CASE WHEN asset_class='PMS'       THEN invested_amount ELSE 0 END) AS pms_invested,
            SUM(CASE WHEN asset_class LIKE 'AIF%' THEN invested_amount ELSE 0 END) AS aif_invested,
            AVG(CASE WHEN xirr IS NOT NULL THEN xirr END) AS avg_xirr,
            SUM(management_fee_pct * invested_amount / 100) AS est_annual_mgmt_fee
        FROM pms_holdings
        WHERE user_id = ? AND is_active = 1
    ");
    $stmt->execute([$userId]);
    $summary = $stmt->fetch(PDO::FETCH_ASSOC);

    $totalInvested = (float)($summary['total_invested'] ?? 0);
    $totalCurrent  = (float)($summary['total_current']  ?? 0);
    $summary['gain_pct'] = $totalInvested > 0
        ? round(($totalCurrent - $totalInvested) / $totalInvested * 100, 2)
        : null;

    // Recent activity
    $recentStmt = $db->prepare("
        SELECT t.*, h.pms_name
        FROM pms_transactions t
        JOIN pms_holdings h ON h.id = t.holding_id
        WHERE h.user_id = ?
        ORDER BY t.txn_date DESC
        LIMIT 5
    ");
    $recentStmt->execute([$userId]);
    $summary['recent_txns'] = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    // Breakdown by asset class
    $brkStmt = $db->prepare("
        SELECT asset_class,
               COUNT(*)          AS count,
               SUM(invested_amount) AS invested,
               SUM(current_value)   AS current_val
        FROM pms_holdings
        WHERE user_id = ? AND is_active = 1
        GROUP BY asset_class
    ");
    $brkStmt->execute([$userId]);
    $summary['breakdown'] = $brkStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $summary]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// pms_detail — single holding with transactions + NAV history
// ══════════════════════════════════════════════════════════════════════════
case 'pms_detail':
    $hid = (int)($_GET['id'] ?? 0);
    if (!$hid || !pms_own($db, $hid, $userId)) {
        echo json_encode(['success' => false, 'msg' => 'Holding not found']); break;
    }
    $stmt = $db->prepare("SELECT * FROM pms_holdings WHERE id = ?");
    $stmt->execute([$hid]);
    $holding = $stmt->fetch(PDO::FETCH_ASSOC);

    $txnStmt = $db->prepare("SELECT * FROM pms_transactions WHERE holding_id = ? ORDER BY txn_date DESC");
    $txnStmt->execute([$hid]);
    $holding['transactions'] = $txnStmt->fetchAll(PDO::FETCH_ASSOC);

    $navStmt = $db->prepare("SELECT * FROM pms_nav_history WHERE holding_id = ? ORDER BY nav_date DESC LIMIT 120");
    $navStmt->execute([$hid]);
    $holding['nav_history'] = $navStmt->fetchAll(PDO::FETCH_ASSOC);

    // Lock-in status
    $holding['locked'] = $holding['lock_in_end_date']
        ? strtotime($holding['lock_in_end_date']) > time()
        : false;

    echo json_encode(['success' => true, 'data' => $holding]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// pms_txns — paginated transactions list
// ══════════════════════════════════════════════════════════════════════════
case 'pms_txns':
    $hid     = (int)($_GET['holding_id'] ?? 0);
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
    $offset  = ($page - 1) * $perPage;

    $where  = ['t.holding_id IN (SELECT id FROM pms_holdings WHERE user_id = ?)'];
    $params = [$userId];

    if ($hid) {
        $where[]  = 't.holding_id = ?';
        $params[] = $hid;
    }

    $countStmt = $db->prepare("SELECT COUNT(*) FROM pms_transactions t WHERE " . implode(' AND ', $where));
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $params[] = $perPage;
    $params[] = $offset;
    $stmt = $db->prepare("
        SELECT t.*, h.pms_name, h.asset_class
        FROM pms_transactions t
        JOIN pms_holdings h ON h.id = t.holding_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY t.txn_date DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($params);
    $txns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true, 'data' => $txns,
        'total' => $total, 'page' => $page, 'per_page' => $perPage
    ]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// pms_nav_history — NAV series for chart
// ══════════════════════════════════════════════════════════════════════════
case 'pms_nav_history':
    $hid    = (int)($_GET['id'] ?? 0);
    $period = in_array($_GET['period'] ?? '1y', ['6m','1y','3y','all']) ? ($_GET['period'] ?? '1y') : '1y';
    if (!$hid || !pms_own($db, $hid, $userId)) {
        echo json_encode(['success' => false, 'msg' => 'Not found']); break;
    }
    $from = match($period) {
        '6m'  => date('Y-m-d', strtotime('-6 months')),
        '1y'  => date('Y-m-d', strtotime('-1 year')),
        '3y'  => date('Y-m-d', strtotime('-3 years')),
        'all' => '2000-01-01',
    };
    $stmt = $db->prepare("SELECT nav_date, nav FROM pms_nav_history WHERE holding_id = ? AND nav_date >= ? ORDER BY nav_date ASC");
    $stmt->execute([$hid, $from]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'data' => $history, 'period' => $period]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// pms_add — add a new PMS / AIF holding
// ══════════════════════════════════════════════════════════════════════════
case 'pms_add':
    $portfolioId  = pms_get_portfolio($db, $userId);
    $pmsName      = trim(clean($_POST['pms_name'] ?? ''));
    $managerName  = trim(clean($_POST['manager_name'] ?? ''));
    $strategyName = trim(clean($_POST['strategy_name'] ?? ''));
    $assetClass   = in_array($_POST['asset_class'] ?? '', ['PMS','AIF_CAT1','AIF_CAT2','AIF_CAT3'])
                    ? $_POST['asset_class'] : 'PMS';
    $investDate   = clean($_POST['investment_date'] ?? date('Y-m-d'));
    $invested     = (float)($_POST['invested_amount'] ?? 0);
    $currentVal   = !empty($_POST['current_value']) ? (float)$_POST['current_value'] : null;
    $nav          = !empty($_POST['nav_current'])   ? (float)$_POST['nav_current']   : null;
    $navDate      = !empty($_POST['nav_date'])       ? clean($_POST['nav_date'])       : null;
    $units        = !empty($_POST['units'])          ? (float)$_POST['units']          : null;
    $folio        = trim(clean($_POST['folio_number'] ?? '')) ?: null;
    $lockMonths   = !empty($_POST['lock_in_months']) ? (int)$_POST['lock_in_months']   : null;
    $mgmtFee      = !empty($_POST['management_fee_pct']) ? (float)$_POST['management_fee_pct'] : null;
    $perfFee      = !empty($_POST['performance_fee_pct']) ? (float)$_POST['performance_fee_pct'] : null;
    $hurdleRate   = !empty($_POST['hurdle_rate_pct'])     ? (float)$_POST['hurdle_rate_pct']     : null;
    $benchmark    = trim(clean($_POST['benchmark'] ?? '')) ?: null;
    $platform     = trim(clean($_POST['platform'] ?? ''))  ?: null;
    $notes        = trim(clean($_POST['notes'] ?? ''))     ?: null;

    if (!$pmsName || $invested <= 0) {
        echo json_encode(['success' => false, 'msg' => 'pms_name and invested_amount are required']); break;
    }

    // Lock-in end date
    $lockEndDate = null;
    if ($lockMonths) {
        $lockEndDate = date('Y-m-d', strtotime($investDate . " + {$lockMonths} months"));
    }

    $stmt = $db->prepare("
        INSERT INTO pms_holdings
            (user_id, portfolio_id, pms_name, manager_name, strategy_name, asset_class,
             investment_date, invested_amount, current_value, nav_current, nav_date, units,
             folio_number, lock_in_months, lock_in_end_date, management_fee_pct,
             performance_fee_pct, hurdle_rate_pct, benchmark, platform, notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $userId, $portfolioId, $pmsName, $managerName ?: null, $strategyName ?: null, $assetClass,
        $investDate, $invested, $currentVal, $nav, $navDate, $units,
        $folio, $lockMonths, $lockEndDate, $mgmtFee,
        $perfFee, $hurdleRate, $benchmark, $platform, $notes,
    ]);
    $hid = (int)$db->lastInsertId();

    // Auto-create initial investment transaction
    $db->prepare("
        INSERT INTO pms_transactions (holding_id, user_id, portfolio_id, txn_type, txn_date, amount, nav, units, notes)
        VALUES (?,?,?,'investment',?,?,?,?,?)
    ")->execute([$hid, $userId, $portfolioId, $investDate, $invested, $nav, $units, 'Initial investment']);

    // Save first NAV if provided
    if ($nav && $navDate) {
        $db->prepare("INSERT IGNORE INTO pms_nav_history (holding_id, nav_date, nav) VALUES (?,?,?)")
           ->execute([$hid, $navDate, $nav]);
    }

    echo json_encode(['success' => true, 'id' => $hid, 'msg' => 'PMS/AIF holding added']);
    break;

// ══════════════════════════════════════════════════════════════════════════
// pms_edit — update holding details
// ══════════════════════════════════════════════════════════════════════════
case 'pms_edit':
    $hid = (int)($_POST['id'] ?? 0);
    if (!$hid || !pms_own($db, $hid, $userId)) {
        echo json_encode(['success' => false, 'msg' => 'Holding not found']); break;
    }

    $fields = [];
    $params = [];
    $editable = [
        'pms_name', 'manager_name', 'strategy_name', 'folio_number',
        'management_fee_pct', 'performance_fee_pct', 'hurdle_rate_pct',
        'benchmark', 'platform', 'notes',
    ];
    foreach ($editable as $col) {
        if (isset($_POST[$col])) {
            $fields[] = "$col = ?";
            $params[] = trim(clean($_POST[$col])) ?: null;
        }
    }
    // Numeric editable
    foreach (['invested_amount', 'current_value', 'nav_current', 'units', 'lock_in_months'] as $col) {
        if (isset($_POST[$col]) && $_POST[$col] !== '') {
            $fields[] = "$col = ?";
            $params[] = (float)$_POST[$col];
        }
    }
    if (isset($_POST['nav_date']) && $_POST['nav_date']) {
        $fields[] = 'nav_date = ?'; $params[] = clean($_POST['nav_date']);
    }
    if (isset($_POST['xirr'])) {
        $fields[] = 'xirr = ?'; $params[] = (float)$_POST['xirr'];
    }

    if (empty($fields)) { echo json_encode(['success' => false, 'msg' => 'Nothing to update']); break; }

    $params[] = $hid;
    $db->prepare("UPDATE pms_holdings SET " . implode(', ', $fields) . " WHERE id = ?")->execute($params);
    echo json_encode(['success' => true, 'msg' => 'Updated']);
    break;

// ══════════════════════════════════════════════════════════════════════════
// pms_delete — soft delete (set is_active=0)
// ══════════════════════════════════════════════════════════════════════════
case 'pms_delete':
    $hid = (int)($_POST['id'] ?? 0);
    if (!$hid || !pms_own($db, $hid, $userId)) {
        echo json_encode(['success' => false, 'msg' => 'Holding not found']); break;
    }
    $db->prepare("UPDATE pms_holdings SET is_active = 0 WHERE id = ?")->execute([$hid]);
    echo json_encode(['success' => true, 'msg' => 'Deleted']);
    break;

// ══════════════════════════════════════════════════════════════════════════
// pms_txn_add — add transaction (additional investment / withdrawal / dividend / fee)
// ══════════════════════════════════════════════════════════════════════════
case 'pms_txn_add':
    $hid     = (int)($_POST['holding_id'] ?? 0);
    $txnType = in_array($_POST['txn_type'] ?? '', ['investment','withdrawal','dividend','management_fee','performance_fee','nav_update','switch'])
               ? $_POST['txn_type'] : null;
    $txnDate = clean($_POST['txn_date'] ?? date('Y-m-d'));
    $amount  = (float)($_POST['amount'] ?? 0);
    $nav     = !empty($_POST['nav'])   ? (float)$_POST['nav']   : null;
    $units   = !empty($_POST['units']) ? (float)$_POST['units'] : null;
    $notes   = trim(clean($_POST['notes'] ?? '')) ?: null;

    if (!$hid || !$txnType || !pms_own($db, $hid, $userId)) {
        echo json_encode(['success' => false, 'msg' => 'holding_id and txn_type required']); break;
    }

    $portfolioId = pms_get_portfolio($db, $userId);
    $stmt = $db->prepare("
        INSERT INTO pms_transactions (holding_id, user_id, portfolio_id, txn_type, txn_date, amount, nav, units, notes)
        VALUES (?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([$hid, $userId, $portfolioId, $txnType, $txnDate, $amount, $nav, $units, $notes]);
    $txnId = (int)$db->lastInsertId();

    // Update holding: current_value on withdrawal, invested_amount on investment
    if ($txnType === 'investment') {
        $db->prepare("UPDATE pms_holdings SET invested_amount = invested_amount + ?, updated_at = NOW() WHERE id = ?")
           ->execute([$amount, $hid]);
    } elseif ($txnType === 'withdrawal') {
        $db->prepare("UPDATE pms_holdings SET current_value = GREATEST(0, current_value - ?), updated_at = NOW() WHERE id = ?")
           ->execute([$amount, $hid]);
    } elseif ($txnType === 'nav_update' && $nav) {
        $db->prepare("UPDATE pms_holdings SET nav_current = ?, nav_date = ?, updated_at = NOW() WHERE id = ?")
           ->execute([$nav, $txnDate, $hid]);
        $db->prepare("INSERT IGNORE INTO pms_nav_history (holding_id, nav_date, nav) VALUES (?,?,?)")
           ->execute([$hid, $txnDate, $nav]);
    }

    echo json_encode(['success' => true, 'id' => $txnId, 'msg' => 'Transaction added']);
    break;

// ══════════════════════════════════════════════════════════════════════════
// pms_txn_delete — delete a transaction
// ══════════════════════════════════════════════════════════════════════════
case 'pms_txn_delete':
    $tid = (int)($_POST['txn_id'] ?? 0);
    if (!$tid) { echo json_encode(['success' => false, 'msg' => 'txn_id required']); break; }
    // Verify ownership via holding
    $stmt = $db->prepare("SELECT t.holding_id FROM pms_transactions t JOIN pms_holdings h ON h.id = t.holding_id WHERE t.id = ? AND h.user_id = ?");
    $stmt->execute([$tid, $userId]);
    if (!$stmt->fetchColumn()) { echo json_encode(['success' => false, 'msg' => 'Not found']); break; }
    $db->prepare("DELETE FROM pms_transactions WHERE id = ?")->execute([$tid]);
    echo json_encode(['success' => true, 'msg' => 'Deleted']);
    break;

// ══════════════════════════════════════════════════════════════════════════
// pms_nav_add — manually add a NAV data point
// ══════════════════════════════════════════════════════════════════════════
case 'pms_nav_add':
    $hid     = (int)($_POST['holding_id'] ?? 0);
    $navDate = clean($_POST['nav_date'] ?? '');
    $nav     = (float)($_POST['nav'] ?? 0);
    if (!$hid || !$navDate || !$nav || !pms_own($db, $hid, $userId)) {
        echo json_encode(['success' => false, 'msg' => 'holding_id, nav_date, nav required']); break;
    }
    $db->prepare("INSERT INTO pms_nav_history (holding_id, nav_date, nav) VALUES (?,?,?) ON DUPLICATE KEY UPDATE nav = VALUES(nav)")
       ->execute([$hid, $navDate, $nav]);
    // Also update current nav/date on holding if this is latest
    $db->prepare("UPDATE pms_holdings SET nav_current = ?, nav_date = ?, updated_at = NOW() WHERE id = ? AND (nav_date IS NULL OR nav_date <= ?)")
       ->execute([$nav, $navDate, $hid, $navDate]);
    echo json_encode(['success' => true, 'msg' => 'NAV saved']);
    break;

// ══════════════════════════════════════════════════════════════════════════
// pms_update_value — quick update current_value + optional xirr
// ══════════════════════════════════════════════════════════════════════════
case 'pms_update_value':
    $hid     = (int)($_POST['id'] ?? 0);
    $curVal  = (float)($_POST['current_value'] ?? 0);
    $xirr    = !empty($_POST['xirr']) ? (float)$_POST['xirr'] : null;
    if (!$hid || !$curVal || !pms_own($db, $hid, $userId)) {
        echo json_encode(['success' => false, 'msg' => 'id and current_value required']); break;
    }
    $db->prepare("UPDATE pms_holdings SET current_value = ?" . ($xirr !== null ? ", xirr = $xirr" : '') . ", updated_at = NOW() WHERE id = ?")
       ->execute([$curVal, $hid]);
    echo json_encode(['success' => true, 'msg' => 'Value updated']);
    break;

default:
    echo json_encode(['success' => false, 'msg' => 'Unknown action: ' . htmlspecialchars($action)]);
}
