<?php
/**
 * WealthDash — MF Add Transaction API
 * Tasks: tv02 (Fund Finder Quick Add), t09 (SIP/SWP management)
 * Actions: mf_add | mf_add_transaction | mf_delete | mf_update
 *          mf_import_check | holding_by_fund
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'mf_add';
$db          = DB::conn();

verify_csrf();

switch ($action) {

// ══════════════════════════════════════════════════════════════════════════
// mf_add — Add new holding or transaction (buy/sell/SIP/SWP)
// ══════════════════════════════════════════════════════════════════════════
case 'mf_add':
    $fundId      = (int)clean($_POST['fund_id']      ?? 0);
    $txType      = clean($_POST['tx_type']      ?? 'buy');
    $units       = (float)($_POST['units']       ?? 0);
    $navAtBuy    = (float)($_POST['nav']          ?? 0);
    $amount      = (float)($_POST['amount']       ?? 0);
    $txDate      = clean($_POST['tx_date']       ?? date('Y-m-d'));
    $folioNo     = clean($_POST['folio_number']  ?? '');
    $planType    = clean($_POST['plan_type']     ?? 'Direct');

    // Validation
    if (!$fundId) { echo json_encode(['success' => false, 'msg' => 'fund_id required']); break; }
    if ($units <= 0 && $amount <= 0) { echo json_encode(['success' => false, 'msg' => 'units or amount required']); break; }
    if (!in_array($txType, ['buy','sell','sip','swp','lumpsum','switch_in','switch_out','redemption'])) {
        echo json_encode(['success' => false, 'msg' => 'Invalid tx_type']); break;
    }

    // Get or create portfolio
    $portfolioId = getOrCreatePortfolio($db, $userId);

    // Get current NAV if not provided
    if ($navAtBuy <= 0) {
        $navStmt = $db->prepare("SELECT current_nav FROM funds WHERE id=?");
        $navStmt->execute([$fundId]);
        $navAtBuy = (float)$navStmt->fetchColumn();
    }

    // Calculate units from amount if not provided
    if ($units <= 0 && $navAtBuy > 0) $units = round($amount / $navAtBuy, 4);
    if ($amount <= 0 && $navAtBuy > 0) $amount = round($units * $navAtBuy, 2);

    // Get or create holding
    $holdingStmt = $db->prepare("
        SELECT id, units, invested_amount, avg_buy_nav FROM mf_holdings
        WHERE portfolio_id=? AND fund_id=? AND is_active=1 LIMIT 1
    ");
    $holdingStmt->execute([$portfolioId, $fundId]);
    $holding = $holdingStmt->fetch(PDO::FETCH_ASSOC);

    $db->beginTransaction();
    try {
        $isBuy = in_array($txType, ['buy','sip','lumpsum','switch_in']);

        if (!$holding) {
            // Create new holding
            $ins = $db->prepare("
                INSERT INTO mf_holdings (portfolio_id, fund_id, units, avg_buy_nav, current_nav,
                    invested_amount, current_value, plan_type, folio_number, first_investment_date, is_active, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,1,NOW())
            ");
            $ins->execute([$portfolioId, $fundId, $units, $navAtBuy, $navAtBuy,
                           $isBuy ? $amount : 0, $units * $navAtBuy, $planType, $folioNo, $txDate]);
            $holdingId = (int)$db->lastInsertId();
        } else {
            $holdingId = (int)$holding['id'];
            $newUnits  = $isBuy
                ? (float)$holding['units'] + $units
                : max(0, (float)$holding['units'] - $units);
            $newInvested = $isBuy
                ? (float)$holding['invested_amount'] + $amount
                : max(0, (float)$holding['invested_amount'] - $amount);
            // Weighted average NAV for buys
            $newAvgNav = $isBuy && ($newUnits > 0)
                ? (((float)$holding['units'] * (float)$holding['avg_buy_nav']) + ($units * $navAtBuy)) / $newUnits
                : (float)$holding['avg_buy_nav'];

            $db->prepare("
                UPDATE mf_holdings SET units=?, avg_buy_nav=?, invested_amount=?,
                       current_value=?*current_nav, updated_at=NOW()
                WHERE id=?
            ")->execute([$newUnits, round($newAvgNav, 4), $newInvested, $newUnits, $holdingId]);
        }

        // Record transaction
        $txIns = $db->prepare("
            INSERT INTO mf_transactions (holding_id, user_id, tx_type, units, price_per_unit, amount, tx_date, created_at)
            VALUES (?,?,?,?,?,?,?,NOW())
        ");
        $txIns->execute([$holdingId, $userId, $txType, $units, $navAtBuy, $amount, $txDate]);

        $db->commit();
        DB::invalidateCache("user:{$userId}", "mf_holdings");  // tp001
        echo json_encode(['success' => true, 'holding_id' => $holdingId, 'tx_id' => $db->lastInsertId()]);
    } catch (Exception $e) {
        $db->rollBack();
        echo json_encode(['success' => false, 'msg' => $e->getMessage()]);
    }
    break;

// ══════════════════════════════════════════════════════════════════════════
// holding_by_fund — Quick check if user already holds this fund (tv02)
// ══════════════════════════════════════════════════════════════════════════
case 'holding_by_fund':
    $fundId      = (int)($_GET['fund_id'] ?? 0);
    $portfolioId = getOrCreatePortfolio($db, $userId);
    $stmt = $db->prepare("
        SELECT mh.id, mh.units, mh.current_value, mh.invested_amount, f.fund_name
        FROM mf_holdings mh JOIN funds f ON f.id=mh.fund_id
        WHERE mh.portfolio_id=? AND mh.fund_id=? AND mh.is_active=1 LIMIT 1
    ");
    $stmt->execute([$portfolioId, $fundId]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'holding' => $data ?: null, 'already_tracking' => !empty($data)]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// mf_delete — Remove a holding
// ══════════════════════════════════════════════════════════════════════════
case 'mf_delete':
    $holdingId = (int)($_POST['holding_id'] ?? 0);
    $confirm   = $_POST['confirm'] ?? 'no';
    if (!$holdingId || $confirm !== 'yes') {
        echo json_encode(['success' => false, 'msg' => 'holding_id and confirm=yes required']); break;
    }
    // Verify ownership
    $check = $db->prepare("SELECT 1 FROM mf_holdings mh JOIN portfolios p ON p.id=mh.portfolio_id WHERE mh.id=? AND p.user_id=?");
    $check->execute([$holdingId, $userId]);
    if (!$check->fetchColumn()) { echo json_encode(['success' => false, 'msg' => 'Not found']); break; }

    $db->prepare("UPDATE mf_holdings SET is_active=0, updated_at=NOW() WHERE id=?")->execute([$holdingId]);
    echo json_encode(['success' => true]);
    break;

default:
    echo json_encode(['success' => false, 'msg' => "Unknown action: $action"]);
}

function getOrCreatePortfolio(PDO $db, int $userId): int {
    $id = $db->prepare("SELECT id FROM portfolios WHERE user_id=? AND is_default=1 LIMIT 1");
    $id->execute([$userId]);
    $pid = $id->fetchColumn();
    if ($pid) return (int)$pid;
    $db->prepare("INSERT INTO portfolios (user_id, name, is_default, created_at) VALUES (?,?,1,NOW())")->execute([$userId, 'My Portfolio']);
    return (int)$db->lastInsertId();
}
