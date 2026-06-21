<?php
/**
 * WealthDash — 54EC Bond Tracker API (t134)
 * Actions: bonds54ec_list, bonds54ec_add, bonds54ec_edit, bonds54ec_delete
 */
defined('WEALTHDASH') or die('Direct access not allowed');

$db   = DB::conn();
$uid  = $currentUser['id'];

// ── Helper ───────────────────────────────────────────────────────────────────
function calc54ECInterest(array $b): array {
    $invested  = (float)$b['total_invested'];
    $rate      = (float)$b['interest_rate'] / 100;
    $start     = new DateTime($b['investment_date']);
    $maturity  = new DateTime($b['maturity_date']);
    $today     = new DateTime();

    $totalYears   = $start->diff($maturity)->days / 365.25;
    $elapsedYears = min($start->diff($today)->days / 365.25, $totalYears);
    $remainDays   = max(0, $today->diff($maturity)->days);

    // Simple interest (54EC bonds typically pay simple interest)
    $interestEarned = $invested * $rate * $elapsedYears;
    $totalInterest  = $invested * $rate * $totalYears;
    $maturityValue  = $invested + $totalInterest;

    $isMatured = $today >= $maturity;
    $lockStatus = '';
    if (!$isMatured) {
        $daysElapsed = $start->diff($today)->days;
        $lockDays    = 1825; // 5 years = 1825 days
        if ($daysElapsed < $lockDays) {
            $lockRemaining = $lockDays - $daysElapsed;
            $lockStatus = "Lock-in: {$lockRemaining} days remaining";
        }
    }

    return [
        'interest_earned'  => round($interestEarned, 2),
        'total_interest'   => round($totalInterest, 2),
        'maturity_value'   => round($maturityValue, 2),
        'elapsed_years'    => round($elapsedYears, 2),
        'total_years'      => round($totalYears, 2),
        'remain_days'      => $remainDays,
        'is_matured'       => $isMatured,
        'lock_status'      => $lockStatus,
    ];
}

// ── LIST ─────────────────────────────────────────────────────────────────────
if ($action === 'bonds54ec_list') {
    $rows = $db->prepare("
        SELECT * FROM bonds_54ec
        WHERE user_id = ?
        ORDER BY maturity_date ASC
    ");
    $rows->execute([$uid]);
    $bonds = $rows->fetchAll();

    $totalInvested    = 0;
    $totalMaturity    = 0;
    $totalLtcgSaved   = 0;
    $totalInterest    = 0;

    foreach ($bonds as &$b) {
        $calc = calc54ECInterest($b);
        $b['calc'] = $calc;
        $totalInvested  += (float)$b['total_invested'];
        $totalMaturity  += $calc['maturity_value'];
        $totalLtcgSaved += (float)$b['ltcg_exempted'];
        $totalInterest  += $calc['interest_earned'];
    }
    unset($b);

    json_response(true, '', [
        'bonds'           => $bonds,
        'summary' => [
            'total_invested'  => $totalInvested,
            'total_maturity'  => $totalMaturity,
            'total_ltcg_saved'=> $totalLtcgSaved,
            'total_interest'  => $totalInterest,
            'count'           => count($bonds),
        ]
    ]);
    exit;
}

// ── ADD ──────────────────────────────────────────────────────────────────────
if ($action === 'bonds54ec_add') {
    $issuer   = clean($_POST['bond_issuer']     ?? 'REC');
    $iName    = clean($_POST['issuer_name']     ?? '');
    $invDate  = clean($_POST['investment_date'] ?? '');
    $matDate  = clean($_POST['maturity_date']   ?? '');
    $face     = (float)($_POST['face_value']    ?? 10000);
    $num      = (int)($_POST['num_bonds']       ?? 1);
    $rate     = (float)($_POST['interest_rate'] ?? 5);
    $freq     = clean($_POST['interest_freq']   ?? 'annual');
    $ltcg     = (float)($_POST['ltcg_exempted'] ?? 0);
    $saleDate = clean($_POST['sale_asset_date'] ?? '') ?: null;
    $folio    = clean($_POST['folio_number']    ?? '') ?: null;
    $notes    = clean($_POST['notes']           ?? '') ?: null;

    if (!$invDate || !$matDate || $num < 1) {
        json_response(false, 'Investment date, maturity date and number of bonds are required.', [], 422);
        exit;
    }

    // Auto-compute maturity if not provided (5 years from investment)
    if (!$matDate) {
        $matDate = date('Y-m-d', strtotime($invDate . ' +5 years'));
    }

    $total = $face * $num;

    $stmt = $db->prepare("
        INSERT INTO bonds_54ec
          (user_id, bond_issuer, issuer_name, investment_date, maturity_date,
           face_value, num_bonds, total_invested, interest_rate, interest_freq,
           ltcg_exempted, sale_asset_date, folio_number, notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $uid, $issuer, $iName, $invDate, $matDate,
        $face, $num, $total, $rate, $freq,
        $ltcg, $saleDate, $folio, $notes
    ]);

    json_response(true, '54EC Bond added successfully.', ['id' => $db->lastInsertId()]);
    exit;
}

// ── EDIT ─────────────────────────────────────────────────────────────────────
if ($action === 'bonds54ec_edit') {
    $id      = (int)($_POST['id'] ?? 0);
    $issuer  = clean($_POST['bond_issuer']     ?? 'REC');
    $iName   = clean($_POST['issuer_name']     ?? '');
    $invDate = clean($_POST['investment_date'] ?? '');
    $matDate = clean($_POST['maturity_date']   ?? '');
    $face    = (float)($_POST['face_value']    ?? 10000);
    $num     = (int)($_POST['num_bonds']       ?? 1);
    $rate    = (float)($_POST['interest_rate'] ?? 5);
    $freq    = clean($_POST['interest_freq']   ?? 'annual');
    $ltcg    = (float)($_POST['ltcg_exempted'] ?? 0);
    $saleDate= clean($_POST['sale_asset_date'] ?? '') ?: null;
    $folio   = clean($_POST['folio_number']    ?? '') ?: null;
    $notes   = clean($_POST['notes']           ?? '') ?: null;

    $total = $face * $num;

    $stmt = $db->prepare("
        UPDATE bonds_54ec SET
          bond_issuer=?, issuer_name=?, investment_date=?, maturity_date=?,
          face_value=?, num_bonds=?, total_invested=?, interest_rate=?, interest_freq=?,
          ltcg_exempted=?, sale_asset_date=?, folio_number=?, notes=?
        WHERE id=? AND user_id=?
    ");
    $stmt->execute([
        $issuer, $iName, $invDate, $matDate,
        $face, $num, $total, $rate, $freq,
        $ltcg, $saleDate, $folio, $notes,
        $id, $uid
    ]);

    json_response(true, 'Bond updated.', []);
    exit;
}

// ── DELETE ───────────────────────────────────────────────────────────────────
if ($action === 'bonds54ec_delete') {
    $id = (int)($_POST['id'] ?? 0);
    $db->prepare("DELETE FROM bonds_54ec WHERE id=? AND user_id=?")->execute([$id, $uid]);
    json_response(true, 'Bond deleted.', []);
    exit;
}

json_response(false, 'Unknown 54EC action.', [], 400);
