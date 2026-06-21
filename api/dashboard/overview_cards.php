<?php
/**
 * WealthDash — t445: Customizable Overview Cards
 * File: api/dashboard/overview_cards.php
 * Actions: overview_cards_get, overview_cards_save, overview_cards_reset,
 *          overview_cards_data
 *
 * Different from t297 (full widget dashboard) — this is specifically
 * for the TOP ROW of small stat cards (KPI tiles) shown on every page header.
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action      = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId      = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

// Available overview card types
function _available_cards(): array {
    return [
        ['id'=>'total_value',   'title'=>'Total Portfolio',  'icon'=>'💰', 'format'=>'currency'],
        ['id'=>'total_gain',    'title'=>'Total Gain/Loss',  'icon'=>'📈', 'format'=>'currency_pct'],
        ['id'=>'invested',      'title'=>'Total Invested',   'icon'=>'💵', 'format'=>'currency'],
        ['id'=>'monthly_sip',   'title'=>'Monthly SIP',      'icon'=>'🔁', 'format'=>'currency'],
        ['id'=>'active_sips',   'title'=>'Active SIPs',      'icon'=>'📋', 'format'=>'count'],
        ['id'=>'holdings_count','title'=>'Holdings',         'icon'=>'📊', 'format'=>'count'],
        ['id'=>'active_goals',  'title'=>'Active Goals',     'icon'=>'🎯', 'format'=>'count'],
        ['id'=>'goals_on_track','title'=>'Goals On Track',   'icon'=>'✅', 'format'=>'fraction'],
        ['id'=>'insurance_cover','title'=>'Insurance Cover', 'icon'=>'🛡', 'format'=>'currency'],
        ['id'=>'loan_outstanding','title'=>'Loan Outstanding','icon'=>'🏦','format'=>'currency'],
        ['id'=>'net_worth',     'title'=>'Net Worth',        'icon'=>'💎', 'format'=>'currency'],
        ['id'=>'xirr',          'title'=>'Portfolio XIRR',   'icon'=>'📐', 'format'=>'percent'],
    ];
}

function _default_cards(): array {
    return ['total_value','total_gain','monthly_sip','active_goals'];
}

switch ($action) {

    case 'overview_cards_get': {
        $row = DB::fetchRow("SELECT card_order FROM overview_card_prefs WHERE user_id=?", [$userId]);
        $selected = $row ? json_decode($row['card_order'], true) : _default_cards();
        json_response(true,'ok',['selected'=>$selected,'available'=>_available_cards()]);
        break;
    }

    case 'overview_cards_save': {
        csrf_verify();
        $cards = $_POST['cards'] ?? '[]';
        $decoded = json_decode($cards, true);
        if (!is_array($decoded)) json_response(false,'Invalid card list.');
        if (count($decoded) > 6) json_response(false,'Maximum 6 cards allowed.');

        $validIds = array_column(_available_cards(), 'id');
        $decoded = array_values(array_filter($decoded, fn($c) => in_array($c, $validIds)));
        if (!$decoded) $decoded = _default_cards();

        $existing = DB::fetchVal("SELECT id FROM overview_card_prefs WHERE user_id=?", [$userId]);
        $json = json_encode($decoded);
        if ($existing) DB::execute("UPDATE overview_card_prefs SET card_order=?,updated_at=NOW() WHERE id=?", [$json,$existing]);
        else DB::execute("INSERT INTO overview_card_prefs(user_id,card_order,updated_at) VALUES(?,?,NOW())", [$userId,$json]);

        json_response(true,'Overview cards updated.');
        break;
    }

    case 'overview_cards_reset': {
        csrf_verify();
        $existing = DB::fetchVal("SELECT id FROM overview_card_prefs WHERE user_id=?", [$userId]);
        $json = json_encode(_default_cards());
        if ($existing) DB::execute("UPDATE overview_card_prefs SET card_order=?,updated_at=NOW() WHERE id=?", [$json,$existing]);
        else DB::execute("INSERT INTO overview_card_prefs(user_id,card_order,updated_at) VALUES(?,?,NOW())", [$userId,$json]);
        json_response(true,'Reset to default.');
        break;
    }

    // ── Fetch actual data values for selected cards ────────────────
    case 'overview_cards_data': {
        $row = DB::fetchRow("SELECT card_order FROM overview_card_prefs WHERE user_id=?", [$userId]);
        $selected = $row ? json_decode($row['card_order'], true) : _default_cards();

        $portfolioValue = (float)(DB::fetchVal("SELECT COALESCE(SUM(h.units * COALESCE(n.nav, h.avg_cost_per_unit)),0) FROM mf_holdings h LEFT JOIN mf_nav_latest n ON n.mf_id=h.mf_id WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0", [$userId,$portfolioId]) ?? 0);
        $invested = (float)(DB::fetchVal("SELECT COALESCE(SUM(h.units * h.avg_cost_per_unit),0) FROM mf_holdings h WHERE h.user_id=? AND h.portfolio_id=? AND h.units>0", [$userId,$portfolioId]) ?? 0);
        $gain = $portfolioValue - $invested;
        $gainPct = $invested > 0 ? round($gain/$invested*100,2) : 0;

        $activeSIPs = (int)(DB::fetchVal("SELECT COUNT(*) FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'", [$userId,$portfolioId]) ?? 0);
        $sipTotal = (float)(DB::fetchVal("SELECT COALESCE(SUM(sip_amount),0) FROM mf_sips WHERE user_id=? AND portfolio_id=? AND status='active'", [$userId,$portfolioId]) ?? 0);
        $holdingsCount = (int)(DB::fetchVal("SELECT COUNT(*) FROM mf_holdings WHERE user_id=? AND portfolio_id=? AND units>0", [$userId,$portfolioId]) ?? 0);

        $goalsData = DB::fetchAll("SELECT g.target_amount, g.target_date, COALESCE(SUM(gc.amount),0) AS invested FROM goals g LEFT JOIN goal_checkins gc ON gc.goal_id=g.id WHERE g.user_id=? AND g.status='active' GROUP BY g.id", [$userId]);
        $activeGoals = count($goalsData);
        $onTrack = 0;
        foreach ($goalsData as $g) {
            $pct = (float)$g['target_amount'] > 0 ? (float)$g['invested']/(float)$g['target_amount']*100 : 0;
            $totalDays = max(1,(strtotime($g['target_date'])-strtotime('2024-01-01'))/86400);
            $elapsed = max(0,(time()-strtotime('2024-01-01'))/86400);
            $timeProgress = min(100, $elapsed/$totalDays*100);
            if ($pct >= $timeProgress) $onTrack++;
        }

        $insuranceCover = (float)(DB::fetchVal("SELECT COALESCE(SUM(sum_assured),0) FROM insurance_policies WHERE user_id=? AND status='active'", [$userId]) ?? 0);
        $loanOutstanding = (float)(DB::fetchVal("SELECT COALESCE(SUM(outstanding_principal),0) FROM loans WHERE user_id=? AND status='active'", [$userId]) ?? 0);

        $netWorth = $portfolioValue + $insuranceCover * 0 - $loanOutstanding; // simple: investments - loans (insurance is protection, not asset value)
        $netWorth = $portfolioValue - $loanOutstanding;

        $values = [
            'total_value'    => ['value'=>round($portfolioValue), 'format'=>'currency'],
            'total_gain'     => ['value'=>round($gain), 'extra'=>$gainPct, 'format'=>'currency_pct'],
            'invested'       => ['value'=>round($invested), 'format'=>'currency'],
            'monthly_sip'    => ['value'=>round($sipTotal), 'format'=>'currency'],
            'active_sips'    => ['value'=>$activeSIPs, 'format'=>'count'],
            'holdings_count' => ['value'=>$holdingsCount, 'format'=>'count'],
            'active_goals'   => ['value'=>$activeGoals, 'format'=>'count'],
            'goals_on_track' => ['value'=>$onTrack, 'extra'=>$activeGoals, 'format'=>'fraction'],
            'insurance_cover'=> ['value'=>round($insuranceCover), 'format'=>'currency'],
            'loan_outstanding'=>['value'=>round($loanOutstanding), 'format'=>'currency'],
            'net_worth'      => ['value'=>round($netWorth), 'format'=>'currency'],
            'xirr'           => ['value'=>$gainPct, 'format'=>'percent'], // simplified approximation
        ];

        $cardDefs = array_column(_available_cards(), null, 'id');
        $result = [];
        foreach ($selected as $cardId) {
            if (isset($cardDefs[$cardId]) && isset($values[$cardId])) {
                $result[] = array_merge($cardDefs[$cardId], $values[$cardId]);
            }
        }

        json_response(true,'ok',['cards'=>$result]);
        break;
    }

    default: json_response(false,'Unknown action.',[],400);
}
