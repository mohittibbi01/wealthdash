<?php
/**
 * WealthDash — SIP Step-Up Nudge
 * Task: t144 — Salary hike ke saath SIP badhao
 * Routes: stepup_dashboard, stepup_salary_add, stepup_salary_list,
 *         stepup_nudges, stepup_respond, stepup_calculate,
 *         stepup_projection_save, stepup_projection_get,
 *         stepup_apply, stepup_snooze
 */
defined('WEALTHDASH') or die();

$db     = DB::conn();
$uid    = (int)($_SESSION['user_id'] ?? 0);
$action = $_REQUEST['action'] ?? '';

header('Content-Type: application/json');

if (!$uid) { echo json_encode(['success' => false, 'error' => 'Unauthenticated']); exit; }

/* ── Helpers ──────────────────────────────────────────────── */
function su_json(mixed $data, bool $ok = true): void {
    echo json_encode(['success' => $ok, 'data' => $data]);
    exit;
}
function su_err(string $msg, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

/* ── Step-up corpus projection (step-up SIP formula) ──────── */
function su_project_corpus(float $base, float $stepup_pct, float $return_pct, int $years): array {
    $monthly_return = $return_pct / 12 / 100;
    $annual_stepup  = $stepup_pct / 100;
    $corpus         = 0.0;
    $amount         = $base;

    for ($y = 0; $y < $years; $y++) {
        for ($m = 0; $m < 12; $m++) {
            $corpus = ($corpus + $amount) * (1 + $monthly_return);
        }
        $amount *= (1 + $annual_stepup);
    }

    // Flat SIP for comparison
    $flat_corpus = 0.0;
    $flat_pmt    = $base;
    for ($m = 0; $m < $years * 12; $m++) {
        $flat_corpus = ($flat_corpus + $flat_pmt) * (1 + $monthly_return);
    }

    return [
        'step_up_corpus'   => round($corpus, 2),
        'flat_sip_corpus'  => round($flat_corpus, 2),
        'extra_wealth'     => round($corpus - $flat_corpus, 2),
        'extra_wealth_pct' => $flat_corpus > 0 ? round(($corpus - $flat_corpus) / $flat_corpus * 100, 2) : 0,
    ];
}

/* ── Generate nudges from a salary event ─────────────────── */
function su_generate_nudges(PDO $db, int $uid, int $salary_event_id, float $hike_pct): int {
    $sips = $db->prepare("
        SELECT id, amount, fund_id FROM sip_schedules
        WHERE user_id = ? AND status = 'active'
          AND (stepup_nudge_enabled IS NULL OR stepup_nudge_enabled = 1)
        ORDER BY amount DESC
    ");
    $sips->execute([$uid]);
    $sip_rows = $sips->fetchAll(PDO::FETCH_ASSOC);

    $ins = $db->prepare("
        INSERT IGNORE INTO sip_stepup_nudges
          (user_id, salary_event_id, sip_schedule_id, current_amount, suggested_amount, suggested_pct, basis)
        VALUES (?,?,?,?,?,?,?)
    ");

    // Suggest 50% of salary hike as SIP step-up
    $suggested_pct = min(round($hike_pct * 0.5, 2), 50.0);
    $suggested_pct = max($suggested_pct, 5.0); // min 5%
    $count = 0;

    foreach ($sip_rows as $sip) {
        $new_amount = round((float)$sip['amount'] * (1 + $suggested_pct / 100), 0);
        $ins->execute([$uid, $salary_event_id, $sip['id'],
                       (float)$sip['amount'], $new_amount, $suggested_pct, 'salary_hike_50pct']);
        $count++;
    }

    // Mark salary event nudge as sent
    $db->prepare("UPDATE user_salary_events SET nudge_sent=1, nudge_sent_at=NOW() WHERE id=?")
       ->execute([$salary_event_id]);

    return $count;
}

/* ── Route ────────────────────────────────────────────────── */
switch ($action) {

    /* ---- Dashboard ---------------------------------------- */
    case 'stepup_dashboard': {
        // Pending nudges
        $nudges = $db->prepare("
            SELECT n.*, s.amount AS sip_current_amount,
              f.scheme_name AS fund_name
            FROM sip_stepup_nudges n
            LEFT JOIN sip_schedules s ON s.id = n.sip_schedule_id
            LEFT JOIN funds f ON f.id = s.fund_id
            WHERE n.user_id = ? AND n.status = 'pending'
            ORDER BY n.created_at DESC
            LIMIT 10
        ");
        $nudges->execute([$uid]);
        $pending_nudges = $nudges->fetchAll(PDO::FETCH_ASSOC);

        // Latest salary event
        $sal = $db->prepare("SELECT * FROM user_salary_events WHERE user_id=? ORDER BY effective_date DESC LIMIT 1");
        $sal->execute([$uid]);
        $latest_salary = $sal->fetch(PDO::FETCH_ASSOC);

        // Step-up SIPs count
        $su_count = $db->prepare("
            SELECT COUNT(*) FROM sip_schedules
            WHERE user_id=? AND status='active'
              AND stepup_pct IS NOT NULL AND stepup_pct > 0
        ");
        $su_count->execute([$uid]);

        // Total active SIPs amount
        $sip_total = $db->prepare("SELECT SUM(amount) FROM sip_schedules WHERE user_id=? AND status='active'");
        $sip_total->execute([$uid]);

        su_json([
            'pending_nudges'     => $pending_nudges,
            'pending_count'      => count($pending_nudges),
            'latest_salary'      => $latest_salary,
            'step_up_sip_count'  => (int)$su_count->fetchColumn(),
            'total_monthly_sip'  => round((float)$sip_total->fetchColumn(), 2),
        ]);
    }

    /* ---- Log salary event & auto-generate nudges ----------- */
    case 'stepup_salary_add': {
        $event_type   = $_POST['event_type']    ?? 'appraisal';
        $eff_date     = $_POST['effective_date'] ?? date('Y-m-d');
        $old_salary   = isset($_POST['old_salary']) ? (float)$_POST['old_salary'] : null;
        $new_salary   = (float)($_POST['new_salary'] ?? 0);
        $notes        = trim($_POST['notes']    ?? '');

        if ($new_salary <= 0) su_err('new_salary required');

        $hike_pct = null;
        if ($old_salary && $old_salary > 0) {
            $hike_pct = round(($new_salary - $old_salary) / $old_salary * 100, 2);
        }

        $db->prepare("
            INSERT INTO user_salary_events (user_id, event_type, effective_date, old_salary, new_salary, hike_pct, notes)
            VALUES (?,?,?,?,?,?,?)
        ")->execute([$uid, $event_type, $eff_date, $old_salary, $new_salary, $hike_pct, $notes]);

        $event_id  = (int)$db->lastInsertId();
        $nudge_count = 0;

        // Auto-generate nudges if hike % known
        if ($hike_pct && $hike_pct >= 1) {
            $nudge_count = su_generate_nudges($db, $uid, $event_id, $hike_pct);
        }

        su_json([
            'event_id'     => $event_id,
            'hike_pct'     => $hike_pct,
            'nudges_created' => $nudge_count,
        ]);
    }

    /* ---- Salary event list --------------------------------- */
    case 'stepup_salary_list': {
        $st = $db->prepare("SELECT * FROM user_salary_events WHERE user_id=? ORDER BY effective_date DESC LIMIT 50");
        $st->execute([$uid]);
        su_json($st->fetchAll(PDO::FETCH_ASSOC));
    }

    /* ---- All nudges (with filter) -------------------------- */
    case 'stepup_nudges': {
        $status = $_GET['status'] ?? null;
        $sql    = "SELECT n.*, s.amount AS current_sip_amount, f.scheme_name AS fund_name
                   FROM sip_stepup_nudges n
                   LEFT JOIN sip_schedules s ON s.id = n.sip_schedule_id
                   LEFT JOIN funds f ON f.id = s.fund_id
                   WHERE n.user_id = ?";
        $params = [$uid];
        if ($status) { $sql .= " AND n.status = ?"; $params[] = $status; }
        $sql .= " ORDER BY n.created_at DESC LIMIT 100";
        $st = $db->prepare($sql); $st->execute($params);
        su_json($st->fetchAll(PDO::FETCH_ASSOC));
    }

    /* ---- Respond to nudge (accept/reject/snooze) ----------- */
    case 'stepup_respond': {
        $nid      = (int)($_POST['nudge_id']     ?? 0);
        $response = $_POST['response']           ?? '';
        $accepted_amount = isset($_POST['accepted_amount']) ? (float)$_POST['accepted_amount'] : null;
        $snooze_until    = $_POST['snooze_until'] ?? null;

        if (!$nid || !in_array($response, ['accepted','rejected','snoozed'])) {
            su_err('nudge_id and valid response required');
        }

        $nudge = $db->prepare("SELECT * FROM sip_stepup_nudges WHERE id=? AND user_id=?");
        $nudge->execute([$nid, $uid]);
        $n = $nudge->fetch(PDO::FETCH_ASSOC);
        if (!$n) su_err('Nudge not found', 404);
        if ($n['status'] !== 'pending') su_err('Nudge already responded');

        $db->prepare("
            UPDATE sip_stepup_nudges
            SET status=?, accepted_amount=?, snooze_until=?, responded_at=NOW()
            WHERE id=? AND user_id=?
        ")->execute([$response, $accepted_amount, $snooze_until, $nid, $uid]);

        // If accepted — apply to sip_schedules
        if ($response === 'accepted' && $n['sip_schedule_id']) {
            $new_amt = $accepted_amount ?? (float)$n['suggested_amount'];
            $db->prepare("
                UPDATE sip_schedules SET amount=?, stepup_last_applied=CURDATE(), updated_at=NOW()
                WHERE id=? AND user_id=?
            ")->execute([$new_amt, $n['sip_schedule_id'], $uid]);
        }

        su_json(['message' => "Nudge {$response}"]);
    }

    /* ---- Calculate step-up projection (calculator) --------- */
    case 'stepup_calculate': {
        $base        = (float)($_GET['base_amount']     ?? 0);
        $stepup_pct  = (float)($_GET['stepup_pct']      ?? 10);
        $return_pct  = (float)($_GET['expected_return'] ?? 12);
        $years       = (int)($_GET['years']             ?? 10);

        if ($base <= 0)  su_err('base_amount required');
        if ($years < 1 || $years > 40) su_err('years must be 1–40');

        $result = su_project_corpus($base, $stepup_pct, $return_pct, $years);

        // Add total invested for both scenarios
        $total_invested_flat   = $base * 12 * $years;
        $total_invested_stepup = 0.0;
        $amt = $base;
        for ($y = 0; $y < $years; $y++) {
            $total_invested_stepup += $amt * 12;
            $amt *= (1 + $stepup_pct / 100);
        }

        su_json(array_merge($result, [
            'inputs' => [
                'base_amount'     => $base,
                'stepup_pct'      => $stepup_pct,
                'expected_return' => $return_pct,
                'years'           => $years,
            ],
            'total_invested_flat'   => round($total_invested_flat, 2),
            'total_invested_stepup' => round($total_invested_stepup, 2),
        ]));
    }

    /* ---- Save projection config for a SIP ------------------ */
    case 'stepup_projection_save': {
        $sip_id  = (int)($_POST['sip_schedule_id'] ?? 0);
        $base    = (float)($_POST['base_amount']     ?? 0);
        $pct     = (float)($_POST['stepup_pct']      ?? 10);
        $years   = (int)($_POST['target_years']      ?? 10);
        $ret     = (float)($_POST['expected_return']  ?? 12);

        if (!$sip_id || $base <= 0) su_err('sip_schedule_id and base_amount required');

        $proj = su_project_corpus($base, $pct, $ret, $years);

        $db->prepare("
            INSERT INTO sip_stepup_projections
              (user_id, sip_schedule_id, base_amount, stepup_pct, target_years, expected_return_pct,
               projected_corpus, vs_flat_sip_corpus)
            VALUES (?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
              base_amount=VALUES(base_amount), stepup_pct=VALUES(stepup_pct),
              target_years=VALUES(target_years), expected_return_pct=VALUES(expected_return_pct),
              projected_corpus=VALUES(projected_corpus), vs_flat_sip_corpus=VALUES(vs_flat_sip_corpus),
              updated_at=NOW()
        ")->execute([$uid, $sip_id, $base, $pct, $years, $ret,
                     $proj['step_up_corpus'], $proj['flat_sip_corpus']]);

        su_json(array_merge(['message' => 'Projection saved'], $proj));
    }

    /* ---- Get projection for a SIP -------------------------- */
    case 'stepup_projection_get': {
        $sip_id = (int)($_GET['sip_schedule_id'] ?? 0);
        if (!$sip_id) su_err('sip_schedule_id required');

        $st = $db->prepare("SELECT * FROM sip_stepup_projections WHERE user_id=? AND sip_schedule_id=?");
        $st->execute([$uid, $sip_id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) su_err('No projection saved for this SIP', 404);
        su_json($row);
    }

    /* ---- Manually apply step-up to a SIP ------------------- */
    case 'stepup_apply': {
        $sip_id    = (int)($_POST['sip_schedule_id'] ?? 0);
        $new_amount = (float)($_POST['new_amount']    ?? 0);

        if (!$sip_id || $new_amount <= 0) su_err('sip_schedule_id and new_amount required');

        $chk = $db->prepare("SELECT id, amount FROM sip_schedules WHERE id=? AND user_id=? AND status='active'");
        $chk->execute([$sip_id, $uid]);
        $sip = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$sip) su_err('Active SIP not found', 404);

        $db->prepare("
            UPDATE sip_schedules SET amount=?, stepup_last_applied=CURDATE(), updated_at=NOW()
            WHERE id=? AND user_id=?
        ")->execute([$new_amount, $sip_id, $uid]);

        su_json([
            'message'        => 'SIP amount updated',
            'old_amount'     => (float)$sip['amount'],
            'new_amount'     => $new_amount,
            'increase'       => round($new_amount - (float)$sip['amount'], 2),
            'increase_pct'   => round(($new_amount - (float)$sip['amount']) / (float)$sip['amount'] * 100, 2),
        ]);
    }

    default:
        su_err("Unknown action: $action", 404);
}
