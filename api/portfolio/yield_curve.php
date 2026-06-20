<?php
/**
 * WealthDash — t488: Yield Curve — FD Rates Visualization
 * File: api/portfolio/yield_curve.php
 * Actions: yield_curve_get, yield_curve_compare_banks, yield_curve_save_rate
 *
 * Visualizes interest rate vs tenure relationship (the "yield curve")
 * across user-tracked FDs and reference bank rates. Helps users see
 * if they're getting optimal rates for their tenure.
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];

// Reference rates (typical Indian bank FD rates by tenure — admin can update via yield_curve_save_rate)
function _default_reference_rates(): array {
    return [
        ['tenure_months'=>3,  'tenure_label'=>'3M',  'rate'=>6.50],
        ['tenure_months'=>6,  'tenure_label'=>'6M',  'rate'=>7.00],
        ['tenure_months'=>12, 'tenure_label'=>'1Y',  'rate'=>7.10],
        ['tenure_months'=>24, 'tenure_label'=>'2Y',  'rate'=>7.25],
        ['tenure_months'=>36, 'tenure_label'=>'3Y',  'rate'=>7.00],
        ['tenure_months'=>60, 'tenure_label'=>'5Y',  'rate'=>6.75],
        ['tenure_months'=>120,'tenure_label'=>'10Y', 'rate'=>6.50],
    ];
}

switch ($action) {

    // ── Get yield curve: reference rates + user's own FDs plotted ────
    case 'yield_curve_get': {
        // Reference curve (from DB if customized, else defaults)
        $refRows = DB::fetchAll("SELECT tenure_months, tenure_label, rate FROM yield_curve_reference ORDER BY tenure_months ASC");
        $reference = $refRows ?: _default_reference_rates();

        // User's own FDs (if fd_investments table exists from another module — gracefully handle absence)
        $userFds = [];
        try {
            $userFds = DB::fetchAll(
                "SELECT bank_name, principal_amount, interest_rate,
                        DATEDIFF(maturity_date, created_at) AS tenure_days
                 FROM fd_investments WHERE user_id=? AND status='active'",
                [$userId]
            );
        } catch (\Throwable $e) {
            $userFds = []; // table may not exist in this deployment
        }

        $userPoints = array_map(function($fd) {
            $months = round((float)$fd['tenure_days'] / 30.44);
            return [
                'bank'          => $fd['bank_name'],
                'tenure_months' => $months,
                'rate'          => (float)$fd['interest_rate'],
                'amount'        => (float)$fd['principal_amount'],
            ];
        }, $userFds);

        // Compute average rate user is getting vs reference at similar tenure
        $comparisons = [];
        foreach ($userPoints as $up) {
            $closestRef = null; $minDiff = PHP_INT_MAX;
            foreach ($reference as $ref) {
                $diff = abs($ref['tenure_months'] - $up['tenure_months']);
                if ($diff < $minDiff) { $minDiff = $diff; $closestRef = $ref; }
            }
            if ($closestRef) {
                $comparisons[] = [
                    'bank' => $up['bank'],
                    'your_rate' => $up['rate'],
                    'reference_rate' => $closestRef['rate'],
                    'diff' => round($up['rate'] - $closestRef['rate'], 2),
                    'tenure_label' => $closestRef['tenure_label'],
                ];
            }
        }

        json_response(true,'ok',[
            'reference_curve' => $reference,
            'your_fds'        => $userPoints,
            'comparisons'     => $comparisons,
            'curve_shape'     => _analyze_curve_shape($reference),
        ]);
        break;
    }

    // ── Compare rates across multiple banks (manual entry comparison tool) ──
    case 'yield_curve_compare_banks': {
        // This is a stateless calculator — frontend sends array of {bank, rate, tenure_months}
        $banksJson = $_POST['banks'] ?? '[]';
        $banks = json_decode($banksJson, true);
        if (!is_array($banks)) json_response(false, 'Invalid banks data.');

        $amount = (float)($_POST['amount'] ?? 100000);
        $results = [];
        foreach ($banks as $b) {
            $rate = (float)($b['rate'] ?? 0);
            $months = (int)($b['tenure_months'] ?? 12);
            $maturity = $amount * pow(1 + $rate/400, 4 * $months/12); // quarterly compounding approximation
            $results[] = [
                'bank' => clean($b['bank'] ?? ''),
                'rate' => $rate,
                'tenure_months' => $months,
                'maturity_value' => round($maturity, 2),
                'interest_earned' => round($maturity - $amount, 2),
            ];
        }
        usort($results, fn($a,$b) => $b['maturity_value'] <=> $a['maturity_value']);

        json_response(true,'ok',['results'=>$results,'principal'=>$amount]);
        break;
    }

    // ── Admin/user: save/update a reference rate point ────────────────
    case 'yield_curve_save_rate': {
        csrf_verify();
        $months = (int)($_POST['tenure_months'] ?? 0);
        $label  = clean($_POST['tenure_label']  ?? '');
        $rate   = (float)($_POST['rate']         ?? 0);
        if (!$months || !$label || $rate <= 0) json_response(false, 'All fields required.');

        $existing = DB::fetchVal("SELECT id FROM yield_curve_reference WHERE tenure_months=?", [$months]);
        if ($existing) DB::execute("UPDATE yield_curve_reference SET tenure_label=?,rate=?,updated_at=NOW() WHERE id=?", [$label,$rate,$existing]);
        else DB::execute("INSERT INTO yield_curve_reference(tenure_months,tenure_label,rate,updated_at) VALUES(?,?,?,NOW())", [$months,$label,$rate]);

        json_response(true,'Rate updated.');
        break;
    }

    default: json_response(false,'Unknown action.',[],400);
}

function _analyze_curve_shape(array $reference): string {
    if (count($reference) < 2) return 'insufficient_data';
    $first = $reference[0]['rate'];
    $last  = $reference[count($reference)-1]['rate'];
    $mid   = $reference[intdiv(count($reference),2)]['rate'];

    if ($last > $first && $mid > $first) return 'normal'; // upward sloping
    if ($last < $first && $mid < $first) return 'inverted'; // downward sloping
    if (abs($last - $first) < 0.3) return 'flat';
    return 'humped'; // peaks in middle (common for Indian FD rates)
}
