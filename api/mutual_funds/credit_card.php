<?php
/**
 * WealthDash — Credit Card Optimizer
 * Task t503: Track cards, utilization, interest trap, debt avalanche, reward points
 * Actions: cc_list | cc_add | cc_edit | cc_delete | cc_summary | cc_avalanche
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'cc_list';
$db          = DB::conn();

// ── Ensure credit_cards table ─────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS credit_cards (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    card_name VARCHAR(100) NOT NULL,
    bank_name VARCHAR(100) NOT NULL DEFAULT '',
    card_type VARCHAR(50) NOT NULL DEFAULT 'credit' COMMENT 'credit|charge|co-branded',
    credit_limit DECIMAL(12,2) NOT NULL DEFAULT 0,
    outstanding DECIMAL(12,2) NOT NULL DEFAULT 0,
    min_payment DECIMAL(12,2) NOT NULL DEFAULT 0,
    due_date DATE DEFAULT NULL,
    interest_rate DECIMAL(5,2) NOT NULL DEFAULT 36.00 COMMENT 'Annual % — typical 36-42%',
    reward_type VARCHAR(50) NOT NULL DEFAULT 'cashback' COMMENT 'cashback|points|miles',
    reward_rate DECIMAL(5,2) NOT NULL DEFAULT 1.00 COMMENT '% cashback or points per ₹100',
    annual_fee DECIMAL(8,2) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

switch ($action) {

// ══════════════════════════════════════════════════════════════════════════
// cc_list — all credit cards
// ══════════════════════════════════════════════════════════════════════════
case 'cc_list':
    $stmt = $db->prepare("
        SELECT * FROM credit_cards
        WHERE user_id=? AND is_active=1
        ORDER BY outstanding DESC
    ");
    $stmt->execute([$userId]);
    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($cards as &$c) {
        $c['utilization_pct'] = $c['credit_limit'] > 0
            ? round($c['outstanding'] / $c['credit_limit'] * 100, 1) : 0;
        $c['utilization_status'] = match(true) {
            $c['utilization_pct'] <= 30  => 'good',
            $c['utilization_pct'] <= 50  => 'fair',
            default                       => 'high',
        };
        $c['days_to_due'] = $c['due_date']
            ? max(0, (int)((strtotime($c['due_date']) - time()) / 86400)) : null;
        $c['min_payment_trap'] = $c['outstanding'] > 0 && $c['min_payment'] > 0
            ? round($c['outstanding'] / ($c['min_payment'] * 0.15)) . ' months to clear at min payment'
            : null;
        // Annual reward value estimate (assume 2x monthly spending of outstanding/12 spend)
        $c['est_annual_reward'] = round(($c['outstanding'] ?: 30000) * $c['reward_rate'] / 100 * 12, 0);
    }

    echo json_encode(['success'=>true,'data'=>$cards]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// cc_add
// ══════════════════════════════════════════════════════════════════════════
case 'cc_add':
    $fields = [
        'card_name'     => trim($_POST['card_name'] ?? ''),
        'bank_name'     => trim($_POST['bank_name'] ?? ''),
        'card_type'     => $_POST['card_type'] ?? 'credit',
        'credit_limit'  => (float)($_POST['credit_limit'] ?? 0),
        'outstanding'   => (float)($_POST['outstanding'] ?? 0),
        'min_payment'   => (float)($_POST['min_payment'] ?? 0),
        'due_date'      => $_POST['due_date'] ?? null,
        'interest_rate' => (float)($_POST['interest_rate'] ?? 36),
        'reward_type'   => $_POST['reward_type'] ?? 'cashback',
        'reward_rate'   => (float)($_POST['reward_rate'] ?? 1),
        'annual_fee'    => (float)($_POST['annual_fee'] ?? 0),
        'notes'         => trim($_POST['notes'] ?? ''),
    ];
    if (!$fields['card_name']) { echo json_encode(['success'=>false,'error'=>'Card name required']); break; }

    $stmt = $db->prepare("
        INSERT INTO credit_cards
          (user_id,card_name,bank_name,card_type,credit_limit,outstanding,min_payment,
           due_date,interest_rate,reward_type,reward_rate,annual_fee,notes)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $userId, $fields['card_name'], $fields['bank_name'], $fields['card_type'],
        $fields['credit_limit'], $fields['outstanding'], $fields['min_payment'],
        $fields['due_date'] ?: null, $fields['interest_rate'], $fields['reward_type'],
        $fields['reward_rate'], $fields['annual_fee'], $fields['notes'],
    ]);
    echo json_encode(['success'=>true,'id'=>(int)$db->lastInsertId()]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// cc_edit
// ══════════════════════════════════════════════════════════════════════════
case 'cc_edit':
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'error'=>'ID required']); break; }

    $own = $db->prepare("SELECT id FROM credit_cards WHERE id=? AND user_id=?");
    $own->execute([$id, $userId]);
    if (!$own->fetch()) { echo json_encode(['success'=>false,'error'=>'Not found']); break; }

    $stmt = $db->prepare("
        UPDATE credit_cards
        SET card_name=?, bank_name=?, credit_limit=?, outstanding=?, min_payment=?,
            due_date=?, interest_rate=?, reward_type=?, reward_rate=?, annual_fee=?, notes=?
        WHERE id=? AND user_id=?
    ");
    $stmt->execute([
        trim($_POST['card_name'] ?? ''),
        trim($_POST['bank_name'] ?? ''),
        (float)($_POST['credit_limit'] ?? 0),
        (float)($_POST['outstanding'] ?? 0),
        (float)($_POST['min_payment'] ?? 0),
        $_POST['due_date'] ?: null,
        (float)($_POST['interest_rate'] ?? 36),
        $_POST['reward_type'] ?? 'cashback',
        (float)($_POST['reward_rate'] ?? 1),
        (float)($_POST['annual_fee'] ?? 0),
        trim($_POST['notes'] ?? ''),
        $id, $userId,
    ]);
    echo json_encode(['success'=>true]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// cc_delete
// ══════════════════════════════════════════════════════════════════════════
case 'cc_delete':
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $db->prepare("UPDATE credit_cards SET is_active=0 WHERE id=? AND user_id=?");
    $stmt->execute([$id, $userId]);
    echo json_encode(['success'=>true]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// cc_summary — portfolio-level credit card analytics
// ══════════════════════════════════════════════════════════════════════════
case 'cc_summary':
    $stmt = $db->prepare("SELECT * FROM credit_cards WHERE user_id=? AND is_active=1");
    $stmt->execute([$userId]);
    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$cards) { echo json_encode(['success'=>true,'data'=>['no_cards'=>true]]); break; }

    $totalLimit       = array_sum(array_column($cards, 'credit_limit'));
    $totalOutstanding = array_sum(array_column($cards, 'outstanding'));
    $overallUtil      = $totalLimit > 0 ? round($totalOutstanding / $totalLimit * 100, 1) : 0;

    // Monthly interest cost
    $monthlyInterest  = 0;
    foreach ($cards as $c) {
        $monthlyInterest += $c['outstanding'] * ($c['interest_rate'] / 100 / 12);
    }
    $annualInterest = $monthlyInterest * 12;

    // CIBIL impact
    $cibilImpact = match(true) {
        $overallUtil <= 30  => ['score'=>'Good', 'color'=>'#16a34a', 'msg'=>'Utilization ≤30% — positive CIBIL impact'],
        $overallUtil <= 50  => ['score'=>'Fair', 'color'=>'#f59e0b', 'msg'=>'Utilization 30-50% — slightly hurts CIBIL'],
        $overallUtil <= 70  => ['score'=>'Poor', 'color'=>'#f97316', 'msg'=>'Utilization >50% — hurts CIBIL significantly'],
        default             => ['score'=>'Bad',  'color'=>'#ef4444', 'msg'=>'Utilization >70% — major CIBIL damage risk'],
    };

    // Due soon (next 7 days)
    $dueSoon = array_filter($cards, fn($c) =>
        $c['due_date'] && strtotime($c['due_date']) <= strtotime('+7 days') && strtotime($c['due_date']) >= time()
    );

    // Total annual rewards
    $totalRewards = array_sum(array_column($cards, 'est_annual_reward'));
    // If not pre-calc'd
    if (!$totalRewards) {
        foreach ($cards as $c) {
            $totalRewards += ($c['outstanding'] ?: 30000) * $c['reward_rate'] / 100 * 12;
        }
    }

    $netBenefit = $totalRewards - $annualInterest - array_sum(array_column($cards, 'annual_fee'));

    echo json_encode(['success'=>true,'data'=>[
        'total_cards'       => count($cards),
        'total_limit'       => round($totalLimit, 2),
        'total_outstanding' => round($totalOutstanding, 2),
        'overall_util_pct'  => $overallUtil,
        'monthly_interest'  => round($monthlyInterest, 2),
        'annual_interest'   => round($annualInterest, 2),
        'cibil_impact'      => $cibilImpact,
        'due_soon_count'    => count($dueSoon),
        'total_annual_fees' => array_sum(array_column($cards, 'annual_fee')),
        'est_annual_rewards'=> round($totalRewards, 0),
        'net_annual_benefit'=> round($netBenefit, 0),
        'recommendation'    => $netBenefit < 0
            ? "You're paying more in interest/fees than earning in rewards. Pay off debt first!"
            : sprintf("Net annual benefit: ₹%s after fees and rewards.", number_format($netBenefit, 0)),
    ]]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// cc_avalanche — debt avalanche payoff plan (highest interest first)
// ══════════════════════════════════════════════════════════════════════════
case 'cc_avalanche':
    $extraPayment = max(0, (float)($_POST['extra_payment'] ?? 0));

    $stmt = $db->prepare("
        SELECT id, card_name, outstanding, min_payment, interest_rate
        FROM credit_cards
        WHERE user_id=? AND is_active=1 AND outstanding > 0
        ORDER BY interest_rate DESC
    ");
    $stmt->execute([$userId]);
    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$cards) { echo json_encode(['success'=>true,'data'=>['no_debt'=>true]]); break; }

    $totalMinPayment = array_sum(array_column($cards, 'min_payment'));
    $monthlyBudget   = $totalMinPayment + $extraPayment;

    // Simulate avalanche
    $balances  = array_column($cards, null, 'id');
    $months    = 0;
    $totalInterestPaid = 0;
    $payoffSchedule = [];
    $maxMonths = 360; // 30 years cap

    // Convert to working array
    $working = [];
    foreach ($cards as $c) {
        $working[$c['id']] = [
            'name'     => $c['card_name'],
            'balance'  => (float)$c['outstanding'],
            'rate_m'   => $c['interest_rate'] / 100 / 12,
            'min'      => (float)$c['min_payment'],
            'paid_off' => null,
        ];
    }

    while ($months < $maxMonths) {
        // Check if all paid
        $remaining = array_filter($working, fn($c) => $c['balance'] > 0.01);
        if (!$remaining) break;

        $months++;
        $monthBudget = $monthlyBudget;

        // Apply interest to all
        foreach ($working as &$c) {
            if ($c['balance'] > 0.01) {
                $interest = $c['balance'] * $c['rate_m'];
                $c['balance'] += $interest;
                $totalInterestPaid += $interest;
            }
        }

        // Pay minimum on all
        foreach ($working as &$c) {
            if ($c['balance'] > 0.01) {
                $pay = min($c['min'], $c['balance']);
                $c['balance'] -= $pay;
                $monthBudget  -= $pay;
                if ($c['balance'] <= 0.01 && !$c['paid_off']) {
                    $c['paid_off'] = $months;
                    $c['balance']  = 0;
                }
            }
        }

        // Extra payment goes to highest rate first (avalanche)
        if ($monthBudget > 0) {
            foreach ($working as &$c) {
                if ($c['balance'] > 0.01) {
                    $pay = min($monthBudget, $c['balance']);
                    $c['balance'] -= $pay;
                    $monthBudget  -= $pay;
                    if ($c['balance'] <= 0.01 && !$c['paid_off']) {
                        $c['paid_off'] = $months;
                        $c['balance']  = 0;
                    }
                    if ($monthBudget <= 0.01) break;
                }
            }
        }
    }

    $result = [];
    foreach ($working as $id => $c) {
        $result[] = [
            'card_name'   => $c['name'],
            'paid_off_months' => $c['paid_off'],
            'paid_off_label'  => $c['paid_off']
                ? date('M Y', strtotime("+{$c['paid_off']} months"))
                : 'Not paid off in 30 years',
        ];
    }

    echo json_encode(['success'=>true,'data'=>[
        'payoff_months'      => $months,
        'payoff_label'       => date('M Y', strtotime("+{$months} months")),
        'total_interest'     => round($totalInterestPaid, 0),
        'monthly_budget'     => round($monthlyBudget, 0),
        'schedule'           => $result,
        'tip'                => $extraPayment > 0
            ? "With ₹" . number_format($extraPayment, 0) . " extra/month, you save significant interest!"
            : "Even ₹1,000 extra/month on highest-interest card saves thousands in interest.",
    ]]);
    break;

default:
    echo json_encode(['success'=>false,'error'=>"Unknown action: $action"]);
}
