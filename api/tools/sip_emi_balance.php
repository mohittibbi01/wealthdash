<?php
/**
 * WealthDash — t474: SIP vs EMI Monthly Load Analysis
 * File: api/tools/sip_emi_balance.php
 * Actions: sip_emi_summary, sip_emi_monthly_breakdown
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];
$portfolioId = get_user_portfolio_id($userId);

switch ($action) {

    // ── SUMMARY: total SIP + EMI load this month ──────────────────────
    case 'sip_emi_summary': {
        $today = date('Y-m-d');
        $month = date('Y-m');

        // Active SIPs
        $sips = DB::fetchAll(
            "SELECT mf_id, sip_amount, sip_date, sip_frequency, fund_name
             FROM mf_sips
             WHERE user_id = ? AND portfolio_id = ?
               AND status = 'active'
               AND (end_date IS NULL OR end_date >= ?)
             ORDER BY sip_date ASC",
            [$userId, $portfolioId, $today]
        );

        // Active EMIs (home loan, personal loan, etc.)
        $emis = DB::fetchAll(
            "SELECT id, loan_name, loan_type, emi_amount, emi_date,
                    outstanding_principal, interest_rate, end_date
             FROM loans
             WHERE user_id = ?
               AND status = 'active'
               AND (end_date IS NULL OR end_date >= ?)
             ORDER BY emi_date ASC",
            [$userId, $today]
        );

        // Monthly SIP total (consider frequency)
        $sipMonthly = 0;
        foreach ($sips as &$s) {
            $s['sip_amount'] = (float)$s['sip_amount'];
            $freq = strtolower($s['sip_frequency'] ?? 'monthly');
            $multiplier = match($freq) {
                'weekly'     => 4.33,
                'fortnightly'=> 2,
                'quarterly'  => 0.33,
                'yearly'     => 0.083,
                default      => 1, // monthly
            };
            $s['monthly_equivalent'] = round($s['sip_amount'] * $multiplier, 2);
            $sipMonthly += $s['monthly_equivalent'];
        }
        unset($s);

        // Monthly EMI total
        $emiMonthly = 0;
        foreach ($emis as &$e) {
            $e['emi_amount'] = (float)$e['emi_amount'];
            $emiMonthly += $e['emi_amount'];
        }
        unset($e);

        $totalLoad  = $sipMonthly + $emiMonthly;
        $sipRatio   = $totalLoad > 0 ? round(($sipMonthly / $totalLoad) * 100, 1) : 0;
        $emiRatio   = $totalLoad > 0 ? round(($emiMonthly / $totalLoad) * 100, 1) : 0;

        // Next 12 months projection
        $projection = _sip_emi_projection($sips, $emis, 12);

        json_response(true, 'ok', [
            'sips'          => $sips,
            'emis'          => $emis,
            'sip_monthly'   => round($sipMonthly, 2),
            'emi_monthly'   => round($emiMonthly, 2),
            'total_load'    => round($totalLoad, 2),
            'sip_ratio'     => $sipRatio,
            'emi_ratio'     => $emiRatio,
            'projection'    => $projection,
            'month'         => $month,
        ]);
        break;
    }

    // ── MONTHLY BREAKDOWN: per-month SIP + EMI for a given FY ─────────
    case 'sip_emi_monthly_breakdown': {
        $fy = clean($_GET['fy'] ?? $_POST['fy'] ?? date('Y') . '-' . substr(date('Y')+1, -2));
        if (!preg_match('/^(\d{4})-(\d{2})$/', $fy, $m)) {
            json_response(false, 'Invalid FY format. Expected YYYY-YY');
        }
        $fyStart = $m[1] . '-04-01';
        $fyEnd   = '20' . $m[2] . '-03-31';

        $sips = DB::fetchAll(
            "SELECT sip_amount, sip_date, sip_frequency, fund_name, start_date, end_date
             FROM mf_sips
             WHERE user_id = ? AND portfolio_id = ? AND status = 'active'",
            [$userId, $portfolioId]
        );
        $emis = DB::fetchAll(
            "SELECT emi_amount, emi_date, loan_name, loan_type, start_date, end_date
             FROM loans WHERE user_id = ? AND status = 'active'",
            [$userId]
        );

        $months = [];
        $d = new DateTime($fyStart);
        $end = new DateTime($fyEnd);
        while ($d <= $end) {
            $key = $d->format('Y-m');
            $sipTotal = 0;
            foreach ($sips as $s) {
                $sStart = $s['start_date'] ?? '2000-01-01';
                $sEnd   = $s['end_date']   ?? '2099-12-31';
                if ($key >= substr($sStart,0,7) && $key <= substr($sEnd,0,7)) {
                    $freq = strtolower($s['sip_frequency'] ?? 'monthly');
                    $mul  = match($freq) {
                        'weekly' => 4.33, 'fortnightly' => 2,
                        'quarterly' => 0.33, 'yearly' => 0.083,
                        default => 1
                    };
                    $sipTotal += (float)$s['sip_amount'] * $mul;
                }
            }
            $emiTotal = 0;
            foreach ($emis as $e) {
                $eStart = $e['start_date'] ?? '2000-01-01';
                $eEnd   = $e['end_date']   ?? '2099-12-31';
                if ($key >= substr($eStart,0,7) && $key <= substr($eEnd,0,7)) {
                    $emiTotal += (float)$e['emi_amount'];
                }
            }
            $months[] = [
                'month'     => $key,
                'label'     => $d->format('M Y'),
                'sip'       => round($sipTotal, 2),
                'emi'       => round($emiTotal, 2),
                'total'     => round($sipTotal + $emiTotal, 2),
            ];
            $d->modify('+1 month');
        }

        json_response(true, 'ok', ['months' => $months, 'fy' => $fy]);
        break;
    }

    default:
        json_response(false, 'Unknown action.', [], 400);
}

// ── Next N months projection ─────────────────────────────────────────────
function _sip_emi_projection(array $sips, array $emis, int $months): array {
    $result = [];
    $d = new DateTime();
    for ($i = 0; $i < $months; $i++) {
        $key = $d->format('Y-m');
        $sipTotal = 0;
        foreach ($sips as $s) {
            $eDate = $s['end_date'] ?? '2099-12-31';
            if ($key <= substr($eDate, 0, 7)) {
                $sipTotal += $s['monthly_equivalent'] ?? (float)$s['sip_amount'];
            }
        }
        $emiTotal = 0;
        foreach ($emis as $e) {
            $eDate = $e['end_date'] ?? '2099-12-31';
            if ($key <= substr($eDate, 0, 7)) {
                $emiTotal += (float)$e['emi_amount'];
            }
        }
        $result[] = [
            'month' => $key,
            'label' => $d->format('M Y'),
            'sip'   => round($sipTotal, 2),
            'emi'   => round($emiTotal, 2),
            'total' => round($sipTotal + $emiTotal, 2),
        ];
        $d->modify('+1 month');
    }
    return $result;
}
