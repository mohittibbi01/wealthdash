<?php
/**
 * WealthDash — t123: Loan Tracker
 * File: api/loans/loans.php
 * Actions: loans_list, loan_add, loan_update, loan_delete,
 *          loan_summary, loan_amortization
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];

switch ($action) {

    case 'loans_list': {
        $type = clean($_GET['type'] ?? '');
        $where = ['user_id=?']; $params = [$userId];
        if ($type) { $where[] = 'loan_type=?'; $params[] = $type; }
        $rows = DB::fetchAll(
            "SELECT * FROM loans WHERE " . implode(' AND ', $where) . " ORDER BY emi_date ASC",
            $params
        );
        foreach ($rows as &$r) {
            $r['loan_amount']             = (float)$r['loan_amount'];
            $r['outstanding_principal']   = (float)$r['outstanding_principal'];
            $r['interest_rate']           = (float)$r['interest_rate'];
            $r['emi_amount']              = (float)$r['emi_amount'];
            $r['total_paid']              = (float)($r['total_paid'] ?? 0);
            $r['completion_pct']          = $r['loan_amount'] > 0
                ? round((1 - $r['outstanding_principal'] / $r['loan_amount']) * 100, 1)
                : 0;
        }
        json_response(true, 'ok', ['loans' => $rows]);
        break;
    }

    case 'loan_add': {
        csrf_verify();
        $loanAmount  = (float)($_POST['loan_amount']  ?? 0);
        $rate        = (float)($_POST['interest_rate']?? 0);
        $tenureMonths= (int)  ($_POST['tenure_months']?? 0);
        $emiDate     = (int)  ($_POST['emi_date']     ?? 1);

        // Auto-calculate EMI if not provided
        $emi = (float)($_POST['emi_amount'] ?? 0);
        if (!$emi && $loanAmount && $rate && $tenureMonths) {
            $r   = $rate / 12 / 100;
            $emi = $r > 0
                ? round($loanAmount * $r * pow(1+$r, $tenureMonths) / (pow(1+$r, $tenureMonths) - 1), 2)
                : round($loanAmount / $tenureMonths, 2);
        }

        DB::execute(
            "INSERT INTO loans(user_id,loan_name,loan_type,lender,loan_amount,outstanding_principal,
                interest_rate,tenure_months,emi_amount,emi_date,start_date,end_date,status,notes,created_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())",
            [
                $userId,
                clean($_POST['loan_name']  ?? ''),
                clean($_POST['loan_type']  ?? 'personal'),
                clean($_POST['lender']     ?? ''),
                $loanAmount,
                (float)($_POST['outstanding_principal'] ?? $loanAmount),
                $rate,
                $tenureMonths,
                $emi,
                $emiDate,
                clean($_POST['start_date'] ?? ''),
                clean($_POST['end_date']   ?? '') ?: null,
                'active',
                clean($_POST['notes']      ?? ''),
            ]
        );
        json_response(true, 'Loan added.', ['id' => DB::lastInsertId()]);
        break;
    }

    case 'loan_update': {
        csrf_verify();
        $id  = (int)($_POST['id'] ?? 0);
        $own = DB::fetchVal("SELECT id FROM loans WHERE id=? AND user_id=?", [$id, $userId]);
        if (!$own) json_response(false, 'Loan not found.');
        $allowed = ['loan_name','lender','outstanding_principal','interest_rate','emi_amount','emi_date','end_date','status','notes'];
        $sets=[]; $params=[];
        foreach ($allowed as $f) {
            if (isset($_POST[$f])) { $sets[]="$f=?"; $params[]=clean($_POST[$f]); }
        }
        if (!$sets) json_response(false,'Nothing to update.');
        $params[]=$id;
        DB::execute("UPDATE loans SET ".implode(',',$sets).",updated_at=NOW() WHERE id=?", $params);
        json_response(true, 'Loan updated.');
        break;
    }

    case 'loan_delete': {
        csrf_verify();
        $id = (int)($_POST['id'] ?? 0);
        $own = DB::fetchVal("SELECT id FROM loans WHERE id=? AND user_id=?", [$id, $userId]);
        if (!$own) json_response(false, 'Loan not found.');
        DB::execute("DELETE FROM loans WHERE id=?", [$id]);
        json_response(true, 'Loan deleted.');
        break;
    }

    case 'loan_summary': {
        $rows = DB::fetchAll(
            "SELECT loan_type, COUNT(*) AS count,
                    SUM(loan_amount) AS total_loan,
                    SUM(outstanding_principal) AS total_outstanding,
                    SUM(emi_amount) AS total_emi
             FROM loans WHERE user_id=? AND status='active'
             GROUP BY loan_type",
            [$userId]
        );
        $totalOutstanding = array_sum(array_column($rows, 'total_outstanding'));
        $totalEMI         = array_sum(array_column($rows, 'total_emi'));
        json_response(true, 'ok', [
            'by_type'          => $rows,
            'total_outstanding'=> round($totalOutstanding, 2),
            'total_emi'        => round($totalEMI, 2),
        ]);
        break;
    }

    case 'loan_amortization': {
        $id = (int)($_GET['loan_id'] ?? 0);
        $loan = DB::fetchRow("SELECT * FROM loans WHERE id=? AND user_id=?", [$id, $userId]);
        if (!$loan) json_response(false, 'Loan not found.');

        $principal = (float)$loan['outstanding_principal'];
        $rate      = (float)$loan['interest_rate'] / 12 / 100;
        $emi       = (float)$loan['emi_amount'];
        $months    = (int)$loan['tenure_months'];
        $schedule  = [];
        $d         = new DateTime($loan['start_date'] ?? date('Y-m-d'));

        for ($i = 1; $i <= $months && $principal > 0.01; $i++) {
            $interest   = round($principal * $rate, 2);
            $principalPaid = round(min($emi - $interest, $principal), 2);
            $principal  = round($principal - $principalPaid, 2);
            $schedule[] = [
                'month'          => $i,
                'date'           => $d->format('Y-m'),
                'emi'            => $emi,
                'interest'       => $interest,
                'principal_paid' => $principalPaid,
                'balance'        => max(0, $principal),
            ];
            $d->modify('+1 month');
            if ($i >= 120) break; // cap at 10 years for display
        }
        json_response(true, 'ok', ['schedule' => $schedule, 'loan' => $loan]);
        break;
    }

    default: json_response(false, 'Unknown action.', [], 400);
}
