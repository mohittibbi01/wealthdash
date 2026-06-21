<?php
/**
 * WealthDash — t122: Insurance Portfolio
 * File: api/insurance/insurance.php
 * Actions: insurance_list, insurance_add, insurance_update, insurance_delete,
 *          insurance_summary, insurance_premium_calendar
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];

switch ($action) {

    case 'insurance_list': {
        $type = clean($_GET['type'] ?? '');
        $where = ['user_id = ?']; $params = [$userId];
        if ($type) { $where[] = 'policy_type = ?'; $params[] = $type; }
        $rows = DB::fetchAll(
            "SELECT * FROM insurance_policies WHERE " . implode(' AND ', $where) . " ORDER BY next_premium_date ASC",
            $params
        );
        foreach ($rows as &$r) {
            $r['sum_assured']    = (float)$r['sum_assured'];
            $r['premium_amount'] = (float)$r['premium_amount'];
            $r['maturity_amount']= (float)($r['maturity_amount'] ?? 0);
            $r['days_to_premium']= $r['next_premium_date']
                ? (int)ceil((strtotime($r['next_premium_date']) - time()) / 86400)
                : null;
        }
        json_response(true, 'ok', ['policies' => $rows]);
        break;
    }

    case 'insurance_add': {
        csrf_verify();
        $fields = [
            'policy_name'       => clean($_POST['policy_name']     ?? ''),
            'policy_type'       => clean($_POST['policy_type']     ?? 'term'),
            'insurer'           => clean($_POST['insurer']         ?? ''),
            'policy_number'     => clean($_POST['policy_number']   ?? ''),
            'sum_assured'       => (float)($_POST['sum_assured']   ?? 0),
            'premium_amount'    => (float)($_POST['premium_amount']?? 0),
            'premium_frequency' => clean($_POST['premium_frequency']?? 'annual'),
            'start_date'        => clean($_POST['start_date']      ?? ''),
            'maturity_date'     => clean($_POST['maturity_date']   ?? '') ?: null,
            'next_premium_date' => clean($_POST['next_premium_date']?? '') ?: null,
            'maturity_amount'   => (float)($_POST['maturity_amount']?? 0) ?: null,
            'nominee'           => clean($_POST['nominee']         ?? ''),
            'status'            => 'active',
            'notes'             => clean($_POST['notes']           ?? ''),
        ];
        if (!$fields['policy_name'] || !$fields['sum_assured']) json_response(false, 'Policy name and sum assured required.');
        $cols = implode(',', array_keys($fields)) . ',user_id,created_at';
        $ph   = implode(',', array_fill(0, count($fields), '?')) . ',?,NOW()';
        $params = array_values($fields); $params[] = $userId;
        DB::execute("INSERT INTO insurance_policies($cols) VALUES($ph)", $params);
        json_response(true, 'Policy added.', ['id' => DB::lastInsertId()]);
        break;
    }

    case 'insurance_update': {
        csrf_verify();
        $id = (int)($_POST['id'] ?? 0);
        $own = DB::fetchVal("SELECT id FROM insurance_policies WHERE id=? AND user_id=?", [$id, $userId]);
        if (!$own) json_response(false, 'Policy not found.');
        $allowed = ['policy_name','insurer','sum_assured','premium_amount','premium_frequency',
                    'next_premium_date','maturity_date','maturity_amount','nominee','status','notes'];
        $sets = []; $params = [];
        foreach ($allowed as $f) {
            if (isset($_POST[$f])) { $sets[] = "$f=?"; $params[] = clean($_POST[$f]); }
        }
        if (!$sets) json_response(false, 'Nothing to update.');
        $params[] = $id;
        DB::execute("UPDATE insurance_policies SET " . implode(',', $sets) . ",updated_at=NOW() WHERE id=?", $params);
        json_response(true, 'Policy updated.');
        break;
    }

    case 'insurance_delete': {
        csrf_verify();
        $id = (int)($_POST['id'] ?? 0);
        $own = DB::fetchVal("SELECT id FROM insurance_policies WHERE id=? AND user_id=?", [$id, $userId]);
        if (!$own) json_response(false, 'Policy not found.');
        DB::execute("DELETE FROM insurance_policies WHERE id=?", [$id]);
        json_response(true, 'Policy deleted.');
        break;
    }

    case 'insurance_summary': {
        $rows = DB::fetchAll(
            "SELECT policy_type,
                    COUNT(*)              AS count,
                    SUM(sum_assured)      AS total_cover,
                    SUM(premium_amount)   AS total_premium
             FROM insurance_policies
             WHERE user_id=? AND status='active'
             GROUP BY policy_type",
            [$userId]
        );
        $grandCover   = array_sum(array_column($rows, 'total_cover'));
        $grandPremium = array_sum(array_column($rows, 'total_premium'));
        // Upcoming premiums in next 30 days
        $upcoming = DB::fetchAll(
            "SELECT policy_name, insurer, premium_amount, next_premium_date
             FROM insurance_policies
             WHERE user_id=? AND status='active'
               AND next_premium_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
             ORDER BY next_premium_date ASC",
            [$userId]
        );
        json_response(true, 'ok', [
            'by_type'        => $rows,
            'grand_cover'    => (float)$grandCover,
            'grand_premium'  => (float)$grandPremium,
            'upcoming'       => $upcoming,
        ]);
        break;
    }

    case 'insurance_premium_calendar': {
        $months = (int)($_GET['months'] ?? 12);
        $events = DB::fetchAll(
            "SELECT policy_name, insurer, policy_type, premium_amount,
                    next_premium_date, premium_frequency
             FROM insurance_policies
             WHERE user_id=? AND status='active'
               AND next_premium_date IS NOT NULL
             ORDER BY next_premium_date ASC",
            [$userId]
        );
        json_response(true, 'ok', ['events' => $events]);
        break;
    }

    default: json_response(false, 'Unknown action.', [], 400);
}
