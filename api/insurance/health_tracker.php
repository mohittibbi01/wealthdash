<?php
/**
 * WealthDash — t460: Health Insurance Tracker (Extended API)
 * Claims CRUD, family members, health summary, utilization tracker
 * Actions: health_summary, health_members_list, health_member_add,
 *          health_member_edit, health_member_delete,
 *          health_claims_list, health_claim_add, health_claim_edit,
 *          health_claim_delete, health_claim_settle,
 *          health_update_details, health_ncb_update
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

// ── HEALTH SUMMARY ─────────────────────────────────────────────────────────
if ($action === 'health_summary') {
    // All active health policies for user
    $policies = DB::fetchAll(
        "SELECT ip.id, ip.insurer_name, ip.policy_number, ip.health_type,
                ip.sum_assured, ip.premium_amount, ip.premium_frequency,
                ip.no_claim_bonus, ip.copay_pct, ip.deductible,
                ip.restore_benefit, ip.next_premium_date, ip.start_date, ip.end_date,
                ip.waiting_period_initial, ip.waiting_period_pd,
                ip.tpa_name, ip.tpa_contact, ip.maternity_covered,
                p.name AS portfolio_name,
                -- Sum assured after NCB
                ROUND(ip.sum_assured * (1 + COALESCE(ip.no_claim_bonus,0)/100), 2) AS effective_cover,
                -- Days policy active
                DATEDIFF(CURDATE(), ip.start_date) AS days_active,
                -- Days until expiry
                DATEDIFF(ip.end_date, CURDATE()) AS days_to_expiry,
                -- Member count
                (SELECT COUNT(*) FROM health_insurance_members him WHERE him.policy_id = ip.id) AS member_count,
                -- Claims this FY
                (SELECT COUNT(*) FROM health_insurance_claims hic
                 WHERE hic.policy_id = ip.id
                   AND hic.claim_date >= DATE(CONCAT(IF(MONTH(CURDATE())>=4, YEAR(CURDATE()), YEAR(CURDATE())-1), '-04-01'))) AS claims_fy,
                -- Amount claimed this FY
                (SELECT COALESCE(SUM(hic.claimed_amount),0) FROM health_insurance_claims hic
                 WHERE hic.policy_id = ip.id
                   AND hic.claim_date >= DATE(CONCAT(IF(MONTH(CURDATE())>=4, YEAR(CURDATE()), YEAR(CURDATE())-1), '-04-01'))) AS claimed_fy,
                -- Amount settled this FY
                (SELECT COALESCE(SUM(hic.settled_amount),0) FROM health_insurance_claims hic
                 WHERE hic.policy_id = ip.id AND hic.status = 'settled'
                   AND hic.claim_date >= DATE(CONCAT(IF(MONTH(CURDATE())>=4, YEAR(CURDATE()), YEAR(CURDATE())-1), '-04-01'))) AS settled_fy
         FROM insurance_policies ip
         JOIN portfolios p ON p.id = ip.portfolio_id
         WHERE ip.policy_type = 'health' AND ip.status = 'active' AND p.user_id = ?
         ORDER BY ip.sum_assured DESC",
        [$userId]
    );

    // Aggregate totals
    $totalCover      = 0;
    $totalPremium    = 0;
    $totalClaimedFY  = 0;
    $totalSettledFY  = 0;
    $totalMembers    = 0;
    foreach ($policies as $p) {
        $totalCover     += (float)$p['effective_cover'];
        $totalPremium   += (float)$p['premium_amount'];
        $totalClaimedFY += (float)$p['claimed_fy'];
        $totalSettledFY += (float)$p['settled_fy'];
        $totalMembers   += (int)$p['member_count'];
    }

    // Expiring soon (within 60 days)
    $expiringSoon = array_filter($policies, fn($p) => isset($p['days_to_expiry']) && $p['days_to_expiry'] >= 0 && $p['days_to_expiry'] <= 60);

    json_response(true, '', [
        'policies'       => $policies,
        'total_cover'    => $totalCover,
        'total_premium'  => $totalPremium,
        'claimed_fy'     => $totalClaimedFY,
        'settled_fy'     => $totalSettledFY,
        'total_members'  => $totalMembers,
        'policy_count'   => count($policies),
        'expiring_soon'  => array_values($expiringSoon),
        'fy_label'       => date('n') >= 4 ? 'FY ' . date('Y') . '-' . (date('Y')+1) : 'FY ' . (date('Y')-1) . '-' . date('Y'),
    ]);
    exit;
}

// ── FAMILY MEMBERS ──────────────────────────────────────────────────────────
if ($action === 'health_members_list') {
    $policyId = (int)($_GET['policy_id'] ?? $_POST['policy_id'] ?? 0);
    if (!$policyId) { json_response(false, 'policy_id required.', [], 422); exit; }

    // Verify policy belongs to user
    $own = DB::fetchOne(
        "SELECT ip.id FROM insurance_policies ip JOIN portfolios p ON p.id=ip.portfolio_id WHERE ip.id=? AND p.user_id=?",
        [$policyId, $userId]
    );
    if (!$own) { json_response(false, 'Access denied.', [], 403); exit; }

    $rows = DB::fetchAll(
        "SELECT him.*, TIMESTAMPDIFF(YEAR, him.dob, CURDATE()) AS computed_age
         FROM health_insurance_members him WHERE him.policy_id=? ORDER BY him.relation='self' DESC, him.member_name ASC",
        [$policyId]
    );
    json_response(true, '', $rows);
    exit;
}

if ($action === 'health_member_add') {
    $policyId   = (int)($_POST['policy_id'] ?? 0);
    $memberName = clean($_POST['member_name'] ?? '');
    $relation   = clean($_POST['relation'] ?? 'self');
    $dob        = clean($_POST['dob'] ?? '') ?: null;
    $gender     = clean($_POST['gender'] ?? '') ?: null;
    $preExist   = clean($_POST['pre_existing'] ?? '') ?: null;
    $sumIns     = strlen($_POST['sum_insured'] ?? '') ? (float)$_POST['sum_insured'] : null;
    $notes      = clean($_POST['notes'] ?? '') ?: null;

    if (!$memberName || !$policyId) { json_response(false, 'Policy ID and member name required.', [], 422); exit; }

    // Verify ownership
    $own = DB::fetchOne(
        "SELECT ip.id FROM insurance_policies ip JOIN portfolios p ON p.id=ip.portfolio_id WHERE ip.id=? AND p.user_id=?",
        [$policyId, $userId]
    );
    if (!$own) { json_response(false, 'Access denied.', [], 403); exit; }

    DB::run(
        "INSERT INTO health_insurance_members (policy_id, member_name, relation, dob, gender, pre_existing, sum_insured, notes)
         VALUES (?,?,?,?,?,?,?,?)",
        [$policyId, $memberName, $relation, $dob, $gender, $preExist, $sumIns, $notes]
    );
    json_response(true, 'Member added.');
    exit;
}

if ($action === 'health_member_edit') {
    $id         = (int)($_POST['id'] ?? 0);
    $memberName = clean($_POST['member_name'] ?? '');
    $relation   = clean($_POST['relation'] ?? 'self');
    $dob        = clean($_POST['dob'] ?? '') ?: null;
    $gender     = clean($_POST['gender'] ?? '') ?: null;
    $preExist   = clean($_POST['pre_existing'] ?? '') ?: null;
    $sumIns     = strlen($_POST['sum_insured'] ?? '') ? (float)$_POST['sum_insured'] : null;
    $notes      = clean($_POST['notes'] ?? '') ?: null;

    DB::run(
        "UPDATE health_insurance_members him
         INNER JOIN insurance_policies ip ON ip.id = him.policy_id
         INNER JOIN portfolios p ON p.id = ip.portfolio_id
         SET him.member_name=?, him.relation=?, him.dob=?, him.gender=?, him.pre_existing=?, him.sum_insured=?, him.notes=?
         WHERE him.id=? AND p.user_id=?",
        [$memberName, $relation, $dob, $gender, $preExist, $sumIns, $notes, $id, $userId]
    );
    json_response(true, 'Member updated.');
    exit;
}

if ($action === 'health_member_delete') {
    $id = (int)($_POST['id'] ?? 0);
    DB::run(
        "DELETE him FROM health_insurance_members him
         INNER JOIN insurance_policies ip ON ip.id = him.policy_id
         INNER JOIN portfolios p ON p.id = ip.portfolio_id
         WHERE him.id=? AND p.user_id=?",
        [$id, $userId]
    );
    json_response(true, 'Member removed.');
    exit;
}

// ── CLAIMS ─────────────────────────────────────────────────────────────────
if ($action === 'health_claims_list') {
    $policyId = (int)($_GET['policy_id'] ?? $_POST['policy_id'] ?? 0);
    $portCond2 = $policyId ? "AND hic.policy_id = {$policyId}" : '';

    $rows = DB::fetchAll(
        "SELECT hic.*,
                ip.insurer_name, ip.policy_number,
                him.member_name, him.relation,
                DATEDIFF(hic.discharge_date, hic.admission_date) AS hospital_days
         FROM health_insurance_claims hic
         JOIN insurance_policies ip ON ip.id = hic.policy_id
         JOIN portfolios p ON p.id = ip.portfolio_id
         LEFT JOIN health_insurance_members him ON him.id = hic.member_id
         WHERE p.user_id = ? {$portCond2}
         ORDER BY hic.claim_date DESC",
        [$userId]
    );
    json_response(true, '', $rows);
    exit;
}

if ($action === 'health_claim_add') {
    $policyId    = (int)($_POST['policy_id'] ?? 0);
    $memberId    = (int)($_POST['member_id'] ?? 0) ?: null;
    $claimNum    = clean($_POST['claim_number'] ?? '') ?: null;
    $claimType   = clean($_POST['claim_type'] ?? 'reimbursement');
    $claimDate   = clean($_POST['claim_date'] ?? date('Y-m-d'));
    $hospital    = clean($_POST['hospital_name'] ?? '') ?: null;
    $diagnosis   = clean($_POST['diagnosis'] ?? '') ?: null;
    $admDate     = clean($_POST['admission_date'] ?? '') ?: null;
    $disDate     = clean($_POST['discharge_date'] ?? '') ?: null;
    $claimedAmt  = (float)($_POST['claimed_amount'] ?? 0);
    $status      = clean($_POST['status'] ?? 'submitted');
    $notes       = clean($_POST['notes'] ?? '') ?: null;

    if (!$policyId || $claimedAmt <= 0) { json_response(false, 'Policy and claimed amount required.', [], 422); exit; }

    // Verify ownership
    $own = DB::fetchOne(
        "SELECT ip.id FROM insurance_policies ip JOIN portfolios p ON p.id=ip.portfolio_id WHERE ip.id=? AND p.user_id=?",
        [$policyId, $userId]
    );
    if (!$own) { json_response(false, 'Access denied.', [], 403); exit; }

    DB::run(
        "INSERT INTO health_insurance_claims
           (policy_id, member_id, claim_number, claim_type, claim_date, hospital_name,
            diagnosis, admission_date, discharge_date, claimed_amount, status, notes)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
        [$policyId, $memberId, $claimNum, $claimType, $claimDate, $hospital,
         $diagnosis, $admDate, $disDate, $claimedAmt, $status, $notes]
    );
    json_response(true, 'Claim added.', ['id' => DB::conn()->lastInsertId()]);
    exit;
}

if ($action === 'health_claim_edit') {
    $id          = (int)($_POST['id'] ?? 0);
    $claimNum    = clean($_POST['claim_number'] ?? '') ?: null;
    $claimType   = clean($_POST['claim_type'] ?? 'reimbursement');
    $claimDate   = clean($_POST['claim_date'] ?? date('Y-m-d'));
    $hospital    = clean($_POST['hospital_name'] ?? '') ?: null;
    $diagnosis   = clean($_POST['diagnosis'] ?? '') ?: null;
    $admDate     = clean($_POST['admission_date'] ?? '') ?: null;
    $disDate     = clean($_POST['discharge_date'] ?? '') ?: null;
    $claimedAmt  = (float)($_POST['claimed_amount'] ?? 0);
    $approvedAmt = strlen($_POST['approved_amount'] ?? '') ? (float)$_POST['approved_amount'] : null;
    $settledAmt  = strlen($_POST['settled_amount']  ?? '') ? (float)$_POST['settled_amount']  : null;
    $deductedAmt = strlen($_POST['deducted_amount'] ?? '') ? (float)$_POST['deducted_amount'] : null;
    $status      = clean($_POST['status'] ?? 'submitted');
    $settleDate  = clean($_POST['settlement_date'] ?? '') ?: null;
    $rejectRsn   = clean($_POST['rejection_reason'] ?? '') ?: null;
    $notes       = clean($_POST['notes'] ?? '') ?: null;
    $memberId    = (int)($_POST['member_id'] ?? 0) ?: null;

    DB::run(
        "UPDATE health_insurance_claims hic
         INNER JOIN insurance_policies ip ON ip.id = hic.policy_id
         INNER JOIN portfolios p ON p.id = ip.portfolio_id
         SET hic.claim_number=?, hic.claim_type=?, hic.claim_date=?, hic.hospital_name=?,
             hic.diagnosis=?, hic.admission_date=?, hic.discharge_date=?,
             hic.claimed_amount=?, hic.approved_amount=?, hic.settled_amount=?,
             hic.deducted_amount=?, hic.status=?, hic.settlement_date=?,
             hic.rejection_reason=?, hic.notes=?, hic.member_id=?
         WHERE hic.id=? AND p.user_id=?",
        [$claimNum, $claimType, $claimDate, $hospital,
         $diagnosis, $admDate, $disDate,
         $claimedAmt, $approvedAmt, $settledAmt,
         $deductedAmt, $status, $settleDate,
         $rejectRsn, $notes, $memberId,
         $id, $userId]
    );
    json_response(true, 'Claim updated.');
    exit;
}

if ($action === 'health_claim_delete') {
    $id = (int)($_POST['id'] ?? 0);
    DB::run(
        "DELETE hic FROM health_insurance_claims hic
         INNER JOIN insurance_policies ip ON ip.id = hic.policy_id
         INNER JOIN portfolios p ON p.id = ip.portfolio_id
         WHERE hic.id=? AND p.user_id=?",
        [$id, $userId]
    );
    json_response(true, 'Claim deleted.');
    exit;
}

// ── UPDATE HEALTH DETAILS ───────────────────────────────────────────────────
if ($action === 'health_update_details') {
    $id          = (int)($_POST['id'] ?? 0);
    $healthType  = clean($_POST['health_type']          ?? '') ?: null;
    $roomRent    = strlen($_POST['room_rent_limit']     ?? '') ? (float)$_POST['room_rent_limit'] : null;
    $copay       = (int)($_POST['copay_pct']            ?? 0);
    $deductible  = (float)($_POST['deductible']         ?? 0);
    $wpInit      = (int)($_POST['waiting_period_initial']?? 30);
    $wpPd        = (int)($_POST['waiting_period_pd']    ?? 1095);
    $tpaName     = clean($_POST['tpa_name']             ?? '') ?: null;
    $tpaContact  = clean($_POST['tpa_contact']          ?? '') ?: null;
    $restore     = (int)(($_POST['restore_benefit']     ?? 0) == '1');
    $daycare     = (int)(($_POST['daycare_covered']     ?? 1) == '1');
    $maternity   = (int)(($_POST['maternity_covered']   ?? 0) == '1');
    $matWait     = (int)($_POST['maternity_waiting']    ?? 730);
    $netHosp     = clean($_POST['network_hospitals']    ?? '') ?: null;
    $portDone    = (int)(($_POST['portability_done']    ?? 0) == '1');
    $portFrom    = clean($_POST['portability_from']     ?? '') ?: null;

    DB::run(
        "UPDATE insurance_policies ip
         INNER JOIN portfolios p ON p.id = ip.portfolio_id
         SET ip.health_type=?, ip.room_rent_limit=?, ip.copay_pct=?, ip.deductible=?,
             ip.waiting_period_initial=?, ip.waiting_period_pd=?,
             ip.tpa_name=?, ip.tpa_contact=?,
             ip.restore_benefit=?, ip.daycare_covered=?,
             ip.maternity_covered=?, ip.maternity_waiting=?,
             ip.network_hospitals=?, ip.portability_done=?, ip.portability_from=?
         WHERE ip.id=? AND p.user_id=?",
        [$healthType, $roomRent, $copay, $deductible,
         $wpInit, $wpPd,
         $tpaName, $tpaContact,
         $restore, $daycare,
         $maternity, $matWait,
         $netHosp, $portDone, $portFrom,
         $id, $userId]
    );
    json_response(true, 'Health details updated.');
    exit;
}

// ── NCB UPDATE ──────────────────────────────────────────────────────────────
if ($action === 'health_ncb_update') {
    $id  = (int)($_POST['id']  ?? 0);
    $ncb = (float)($_POST['ncb'] ?? 0);

    DB::run(
        "UPDATE insurance_policies ip
         INNER JOIN portfolios p ON p.id = ip.portfolio_id
         SET ip.no_claim_bonus = ?
         WHERE ip.id=? AND ip.policy_type='health' AND p.user_id=?",
        [$ncb, $id, $userId]
    );
    json_response(true, 'NCB updated.');
    exit;
}

json_response(false, 'Unknown health insurance action.', [], 400);
