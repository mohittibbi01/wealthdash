<?php
/**
 * WealthDash — SIP Step-Up Nudge API
 * Task: t144
 * Path: api/sip/sip_stepup.php
 * Actions:
 *   stepup_list | stepup_save | stepup_delete | stepup_history
 *   stepup_apply | stepup_preview | stepup_nudges | stepup_nudge_dismiss
 *   stepup_simulate
 */
defined('WEALTHDASH') or die();

$db          = DB::conn();
$userId      = (int)$_SESSION['user_id'];
$portfolioId = (int)($_POST['portfolio_id'] ?? $_GET['portfolio_id'] ?? 0);
if (!$portfolioId) {
    $r = $db->prepare("SELECT id FROM portfolios WHERE user_id=? AND is_default=1 LIMIT 1");
    $r->execute([$userId]);
    $portfolioId = (int)($r->fetchColumn() ?: 0);
}

switch ($action) {

// ── LIST STEP-UP CONFIGS ─────────────────────────────────────
case 'stepup_list':
    $rows = $db->prepare("
        SELECT sc.*, s.fund_id, s.sip_amount, s.frequency, s.start_date, s.end_date,
               f.fund_name, f.scheme_code
        FROM sip_stepup_config sc
        JOIN sip_schedules s ON s.id = sc.sip_id
        JOIN funds f ON f.id = s.fund_id
        WHERE sc.user_id = ? AND sc.portfolio_id = ?
        ORDER BY sc.next_stepup_date ASC
    ");
    $rows->execute([$userId, $portfolioId]);
    $configs = $rows->fetchAll(PDO::FETCH_ASSOC);

    // Upcoming step-ups (next 90 days)
    $upcoming = array_filter($configs, function($c) {
        return $c['next_stepup_date'] && $c['next_stepup_date'] <= date('Y-m-d', strtotime('+90 days')) && $c['is_active'];
    });

    json_response(true, '', [
        'configs'  => $configs,
        'upcoming' => array_values($upcoming),
    ]);
    break;

// ── SAVE (add / edit) ────────────────────────────────────────
case 'stepup_save':
    $configId       = (int)($_POST['id'] ?? 0);
    $sipId          = (int)($_POST['sip_id'] ?? 0);
    $stepupType     = clean($_POST['stepup_type'] ?? 'percentage');
    $stepupValue    = (float)($_POST['stepup_value'] ?? 0);
    $stepupFreq     = clean($_POST['stepup_frequency'] ?? 'yearly');
    $stepupMonth    = (int)($_POST['stepup_month'] ?? 4);
    $customInterval = (int)($_POST['custom_interval_months'] ?? 0) ?: null;
    $maxAmount      = (float)($_POST['max_sip_amount'] ?? 0) ?: null;
    $notes          = clean($_POST['notes'] ?? '');
    $isActive       = (int)($_POST['is_active'] ?? 1);

    if (!$sipId || $stepupValue <= 0) json_response(false, 'sip_id and stepup_value required');

    // Verify SIP belongs to user
    $sipCheck = $db->prepare("SELECT id, sip_amount, start_date FROM sip_schedules WHERE id=? AND portfolio_id=?");
    $sipCheck->execute([$sipId, $portfolioId]);
    $sipRow = $sipCheck->fetch(PDO::FETCH_ASSOC);
    if (!$sipRow) json_response(false, 'SIP not found in this portfolio');

    $nextDate = _calc_next_stepup_date($stepupFreq, $stepupMonth, $customInterval);

    if ($configId) {
        $db->prepare("
            UPDATE sip_stepup_config SET
                stepup_type=?, stepup_value=?, stepup_frequency=?, stepup_month=?,
                custom_interval_months=?, max_sip_amount=?, is_active=?,
                next_stepup_date=?, notes=?, updated_at=NOW()
            WHERE id=? AND user_id=?
        ")->execute([$stepupType, $stepupValue, $stepupFreq, $stepupMonth,
                     $customInterval, $maxAmount, $isActive, $nextDate, $notes ?: null,
                     $configId, $userId]);
    } else {
        // Check not already configured for this SIP
        $dup = $db->prepare("SELECT id FROM sip_stepup_config WHERE sip_id=? AND user_id=?");
        $dup->execute([$sipId, $userId]);
        if ($dup->fetchColumn()) json_response(false, 'Step-up already configured for this SIP');

        $db->prepare("
            INSERT INTO sip_stepup_config
                (sip_id, user_id, portfolio_id, stepup_type, stepup_value, stepup_frequency,
                 stepup_month, custom_interval_months, max_sip_amount, is_active, next_stepup_date, notes)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([$sipId, $userId, $portfolioId, $stepupType, $stepupValue, $stepupFreq,
                     $stepupMonth, $customInterval, $maxAmount, $isActive, $nextDate, $notes ?: null]);
        $configId = (int)$db->lastInsertId();
    }

    json_response(true, 'Step-up config saved', ['id' => $configId, 'next_stepup_date' => $nextDate]);
    break;

// ── DELETE ───────────────────────────────────────────────────
case 'stepup_delete':
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_response(false, 'ID required');
    $db->prepare("DELETE FROM sip_stepup_config WHERE id=? AND user_id=?")->execute([$id, $userId]);
    json_response(true, 'Step-up config deleted');
    break;

// ── HISTORY ──────────────────────────────────────────────────
case 'stepup_history':
    $sipId = (int)($_GET['sip_id'] ?? 0);
    $where  = "h.user_id=?";
    $params = [$userId];
    if ($sipId) { $where .= " AND h.sip_id=?"; $params[] = $sipId; }

    $rows = $db->prepare("
        SELECT h.*, f.fund_name
        FROM sip_stepup_history h
        JOIN sip_schedules s ON s.id = h.sip_id
        JOIN funds f ON f.id = s.fund_id
        WHERE $where ORDER BY h.applied_date DESC LIMIT 100
    ");
    $rows->execute($params);
    json_response(true, '', ['history' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
    break;

// ── PREVIEW (what will step-up look like) ───────────────────
case 'stepup_preview':
    $sipId = (int)($_GET['sip_id'] ?? 0);
    if (!$sipId) json_response(false, 'sip_id required');

    $sip = $db->prepare("SELECT s.*, f.fund_name FROM sip_schedules s JOIN funds f ON f.id=s.fund_id WHERE s.id=? AND s.portfolio_id=?");
    $sip->execute([$sipId, $portfolioId]);
    $sipRow = $sip->fetch(PDO::FETCH_ASSOC);
    if (!$sipRow) json_response(false, 'SIP not found');

    $cfg = $db->prepare("SELECT * FROM sip_stepup_config WHERE sip_id=? AND user_id=?");
    $cfg->execute([$sipId, $userId]);
    $config = $cfg->fetch(PDO::FETCH_ASSOC);

    if (!$config) {
        json_response(true, '', ['sip' => $sipRow, 'config' => null, 'projections' => []]);
    }

    // Build 10-year projection
    $projections = _build_stepup_projection(
        (float)$sipRow['sip_amount'],
        $config,
        10
    );

    json_response(true, '', [
        'sip'         => $sipRow,
        'config'      => $config,
        'projections' => $projections,
    ]);
    break;

// ── APPLY STEP-UP (manual trigger) ──────────────────────────
case 'stepup_apply':
    $configId = (int)($_POST['config_id'] ?? 0);
    if (!$configId) json_response(false, 'config_id required');

    $cfg = $db->prepare("
        SELECT sc.*, s.sip_amount, s.id AS sip_id_val
        FROM sip_stepup_config sc
        JOIN sip_schedules s ON s.id = sc.sip_id
        WHERE sc.id=? AND sc.user_id=?
    ");
    $cfg->execute([$configId, $userId]);
    $config = $cfg->fetch(PDO::FETCH_ASSOC);
    if (!$config) json_response(false, 'Config not found');

    $oldAmount = (float)$config['sip_amount'];
    $newAmount = _calc_new_sip_amount($oldAmount, $config);

    // Update SIP amount
    $db->prepare("UPDATE sip_schedules SET sip_amount=?, updated_at=NOW() WHERE id=? AND portfolio_id=?")
       ->execute([$newAmount, $config['sip_id'], $portfolioId]);

    // Record history
    $db->prepare("
        INSERT INTO sip_stepup_history
            (stepup_config_id, sip_id, user_id, applied_date, old_amount, new_amount,
             stepup_value, stepup_type, applied_by)
        VALUES (?,?,?,CURDATE(),?,?,?,?,'manual')
    ")->execute([$configId, $config['sip_id'], $userId, $oldAmount, $newAmount,
                 $config['stepup_value'], $config['stepup_type']]);

    // Update next step-up date
    $nextDate = _calc_next_stepup_date(
        $config['stepup_frequency'],
        $config['stepup_month'],
        $config['custom_interval_months'],
        date('Y-m-d') // from today
    );

    $db->prepare("
        UPDATE sip_stepup_config SET
            last_stepup_date=CURDATE(), last_stepup_from=?, last_stepup_to=?,
            next_stepup_date=?, updated_at=NOW()
        WHERE id=?
    ")->execute([$oldAmount, $newAmount, $nextDate, $configId]);

    json_response(true, "SIP stepped up: ₹{$oldAmount} → ₹{$newAmount}", [
        'old_amount' => $oldAmount,
        'new_amount' => $newAmount,
        'next_date'  => $nextDate,
    ]);
    break;

// ── SIMULATE — compound growth projection ─────────────────
case 'stepup_simulate':
    $sipAmount   = (float)($_POST['sip_amount'] ?? 5000);
    $stepupValue = (float)($_POST['stepup_value'] ?? 10);
    $stepupType  = clean($_POST['stepup_type'] ?? 'percentage');
    $years       = min((int)($_POST['years'] ?? 10), 30);
    $xirr        = (float)($_POST['expected_xirr'] ?? 12);
    $maxAmount   = (float)($_POST['max_sip_amount'] ?? 0) ?: null;

    $config = [
        'stepup_type'    => $stepupType,
        'stepup_value'   => $stepupValue,
        'max_sip_amount' => $maxAmount,
        'stepup_frequency' => 'yearly',
    ];

    $projections  = _build_stepup_projection($sipAmount, $config, $years);
    $monthlyRate  = $xirr / 100 / 12;

    // Also compute corpus at each year assuming XIRR
    $corpus       = 0;
    $curAmount    = $sipAmount;
    $corpusData   = [];

    foreach ($projections as &$p) {
        // 12 months of SIP at this amount
        for ($m = 0; $m < 12; $m++) {
            $corpus = $corpus * (1 + $monthlyRate) + $curAmount;
        }
        $curAmount        = $p['new_amount'];
        $p['corpus']      = round($corpus);
        $p['total_invested'] = $p['cumulative_invested'];
    }

    json_response(true, '', ['projections' => $projections, 'xirr' => $xirr]);
    break;

// ── NUDGES ───────────────────────────────────────────────────
case 'stepup_nudges':
    // Return undismissed nudges + upcoming step-ups
    $nudges = $db->prepare("
        SELECT * FROM sip_stepup_nudges
        WHERE user_id=? AND is_dismissed=0
        ORDER BY nudge_date DESC LIMIT 5
    ");
    $nudges->execute([$userId]);
    $nudgeList = $nudges->fetchAll(PDO::FETCH_ASSOC);

    // Upcoming step-ups in next 30 days
    $upcoming = $db->prepare("
        SELECT sc.*, s.sip_amount, f.fund_name
        FROM sip_stepup_config sc
        JOIN sip_schedules s ON s.id = sc.sip_id
        JOIN funds f ON f.id = s.fund_id
        WHERE sc.user_id=? AND sc.portfolio_id=?
          AND sc.is_active=1
          AND sc.next_stepup_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY sc.next_stepup_date
    ");
    $upcoming->execute([$userId, $portfolioId]);
    $upcomingList = $upcoming->fetchAll(PDO::FETCH_ASSOC);

    json_response(true, '', ['nudges' => $nudgeList, 'upcoming_stepups' => $upcomingList]);
    break;

case 'stepup_nudge_dismiss':
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_response(false, 'ID required');
    $db->prepare("UPDATE sip_stepup_nudges SET is_dismissed=1 WHERE id=? AND user_id=?")->execute([$id, $userId]);
    json_response(true, 'Nudge dismissed');
    break;

// ── CREATE SALARY HIKE NUDGE ─────────────────────────────────
case 'stepup_nudge_salary_hike':
    $db->prepare("
        INSERT INTO sip_stepup_nudges (user_id, nudge_type, nudge_date)
        VALUES (?, 'salary_hike', CURDATE())
    ")->execute([$userId]);
    json_response(true, 'Nudge created — review your SIPs now!');
    break;

default:
    json_response(false, "Unknown action: $action");
}

// ── Local Helpers ─────────────────────────────────────────────

function _calc_next_stepup_date(string $freq, int $month = 4, ?int $customMonths = null, ?string $from = null): string {
    $from = $from ? new DateTime($from) : new DateTime();

    if ($freq === 'custom' && $customMonths) {
        $next = clone $from;
        $next->modify("+{$customMonths} months");
        return $next->format('Y-m-d');
    }

    $intervalMonths = match($freq) {
        'half_yearly' => 6,
        'yearly'      => 12,
        default       => 12,
    };

    // Snap to configured month of next year
    $year = (int)$from->format('Y');
    $cur  = (int)$from->format('n');

    // If this month or later → next year's same month, else this year's
    $targetYear = ($cur >= $month) ? $year + 1 : $year;
    if ($freq === 'half_yearly') {
        // Next occurrence 6 months away
        $next = clone $from;
        $next->modify("+6 months");
        $next->setDate((int)$next->format('Y'), (int)$next->format('n'), 1);
        return $next->format('Y-m-01');
    }

    return sprintf('%04d-%02d-01', $targetYear, $month);
}

function _calc_new_sip_amount(float $current, array $config): float {
    $type     = $config['stepup_type'];
    $value    = (float)$config['stepup_value'];
    $maxAmount = isset($config['max_sip_amount']) && $config['max_sip_amount'] > 0
                 ? (float)$config['max_sip_amount'] : null;

    if ($type === 'percentage') {
        $new = $current * (1 + $value / 100);
    } else {
        $new = $current + $value;
    }

    // Round to nearest 100
    $new = ceil($new / 100) * 100;

    if ($maxAmount && $new > $maxAmount) $new = $maxAmount;

    return $new;
}

function _build_stepup_projection(float $startAmount, array $config, int $years): array {
    $amount     = $startAmount;
    $cumInvested = 0;
    $result     = [];

    for ($y = 1; $y <= $years; $y++) {
        $cumInvested += $amount * 12;
        $newAmount    = _calc_new_sip_amount($amount, $config);

        $result[] = [
            'year'               => $y,
            'sip_amount'         => $amount,
            'new_amount'         => $newAmount,
            'stepup_amount'      => round($newAmount - $amount, 2),
            'cumulative_invested'=> round($cumInvested),
        ];

        $amount = $newAmount;
    }

    return $result;
}
