<?php
/**
 * WealthDash — Spending Discipline Score
 * Task tj003: Investment-to-income ratio tracking, 12-month trend, badges
 * Actions: discipline_get | discipline_set_income | discipline_history
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'discipline_get';
$db          = DB::conn();

// ── Ensure discipline table ───────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS spending_discipline (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    monthly_income DECIMAL(15,2) NOT NULL DEFAULT 0,
    target_pct DECIMAL(5,2) NOT NULL DEFAULT 20.00,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// ── Helper: get FY string for a date ─────────────────────────────────
function getFyForDate(string $date): string {
    $m = (int)date('n', strtotime($date));
    $y = (int)date('Y', strtotime($date));
    return $m >= 4 ? "{$y}-" . substr($y + 1, 2) : ($y - 1) . "-" . substr($y, 2);
}

// ── Helper: monthly invested amount ───────────────────────────────────
function getMonthlyInvested(PDO $db, int $userId, string $yearMonth): float {
    // MF investments
    $mfStmt = $db->prepare("
        SELECT COALESCE(SUM(t.amount), 0)
        FROM mf_transactions t
        JOIN mf_holdings mh ON mh.id = t.holding_id
        JOIN portfolios p ON p.id = mh.portfolio_id
        WHERE p.user_id = ?
          AND t.tx_type IN ('buy','sip','lumpsum','switch_in')
          AND DATE_FORMAT(t.tx_date,'%Y-%m') = ?
    ");
    $mfStmt->execute([$userId, $yearMonth]);
    $mf = (float)$mfStmt->fetchColumn();

    // FD investments
    $fdStmt = $db->prepare("
        SELECT COALESCE(SUM(principal_amount), 0)
        FROM fd_holdings
        WHERE user_id=? AND DATE_FORMAT(start_date,'%Y-%m')=? AND status='active'
    ");
    $fdStmt->execute([$userId, $yearMonth]);
    $fd = (float)$fdStmt->fetchColumn();

    // NPS contributions
    $npsStmt = $db->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM nps_transactions
        WHERE user_id=? AND DATE_FORMAT(tx_date,'%Y-%m')=?
    ");
    $npsStmt->execute([$userId, $yearMonth]);
    $nps = (float)$npsStmt->fetchColumn();

    return $mf + $fd + $nps;
}

switch ($action) {

// ══════════════════════════════════════════════════════════════════════════
// discipline_set_income — save monthly income + target
// ══════════════════════════════════════════════════════════════════════════
case 'discipline_set_income':
    $income = (float)($_POST['monthly_income'] ?? 0);
    $target = min(100, max(1, (float)($_POST['target_pct'] ?? 20)));

    if ($income <= 0) {
        echo json_encode(['success'=>false,'error'=>'Monthly income must be > 0']);
        break;
    }

    $stmt = $db->prepare("
        INSERT INTO spending_discipline (user_id, monthly_income, target_pct)
        VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE monthly_income=VALUES(monthly_income), target_pct=VALUES(target_pct)
    ");
    $stmt->execute([$userId, $income, $target]);
    echo json_encode(['success'=>true]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// discipline_get — current score + last 12 months trend
// ══════════════════════════════════════════════════════════════════════════
case 'discipline_get':
    // Load settings
    $setStmt = $db->prepare("SELECT monthly_income, target_pct FROM spending_discipline WHERE user_id=?");
    $setStmt->execute([$userId]);
    $settings = $setStmt->fetch(PDO::FETCH_ASSOC);

    $income    = (float)($settings['monthly_income'] ?? 0);
    $targetPct = (float)($settings['target_pct']    ?? 20);
    $hasIncome = $income > 0;

    // Last 12 months data
    $months = [];
    for ($i = 11; $i >= 0; $i--) {
        $ym      = date('Y-m', strtotime("-$i months"));
        $invested = getMonthlyInvested($db, $userId, $ym);
        $rate     = $income > 0 ? round($invested / $income * 100, 1) : null;
        $months[] = [
            'month'     => $ym,
            'label'     => date('M Y', strtotime($ym . '-01')),
            'invested'  => round($invested, 2),
            'income'    => $income,
            'rate_pct'  => $rate,
            'on_target' => $rate !== null ? $rate >= $targetPct : null,
        ];
    }

    // Overall score (0-100)
    $validMonths   = array_filter($months, fn($m) => $m['rate_pct'] !== null && $m['invested'] > 0);
    $aboveTarget   = count(array_filter($validMonths, fn($m) => $m['on_target']));
    $totalValid    = count($validMonths);
    $consistencyScore = $totalValid > 0 ? round($aboveTarget / $totalValid * 100) : 0;

    $avgRate = $totalValid > 0
        ? round(array_sum(array_column(array_filter($validMonths, fn($m)=>$m['rate_pct']!==null), 'rate_pct')) / $totalValid, 1)
        : 0;

    // Streak — consecutive months hitting target (most recent first)
    $streak = 0;
    foreach (array_reverse($months) as $m) {
        if ($m['on_target']) $streak++;
        else break;
    }

    // Personal best streak
    $bestStreak = 0; $cur = 0;
    foreach ($months as $m) {
        if ($m['on_target']) { $cur++; $bestStreak = max($bestStreak, $cur); }
        else $cur = 0;
    }

    // Badge logic
    $badges = [];
    if ($streak >= 3)  $badges[] = ['icon'=>'🎯','label'=>"$streak months on target!"];
    if ($streak >= 6)  $badges[] = ['icon'=>'🔥','label'=>'6-month streak — incredible!'];
    if ($streak >= 12) $badges[] = ['icon'=>'🏆','label'=>'Full year consistency — legend!'];
    if ($avgRate > $targetPct * 1.5) $badges[] = ['icon'=>'📈','label'=>'Overachiever — investing '.round($avgRate).'%'];
    if ($bestStreak > $streak && $bestStreak >= 3) $badges[] = ['icon'=>'🌟','label'=>"Personal best: $bestStreak months"];

    // Grade
    $grade = match(true) {
        $consistencyScore >= 90 => ['letter'=>'A+', 'label'=>'Exceptional', 'color'=>'#16a34a'],
        $consistencyScore >= 75 => ['letter'=>'A',  'label'=>'Excellent',   'color'=>'#22c55e'],
        $consistencyScore >= 60 => ['letter'=>'B',  'label'=>'Good',        'color'=>'#84cc16'],
        $consistencyScore >= 40 => ['letter'=>'C',  'label'=>'Average',     'color'=>'#f59e0b'],
        $consistencyScore >= 20 => ['letter'=>'D',  'label'=>'Needs Work',  'color'=>'#f97316'],
        default                 => ['letter'=>'F',  'label'=>'Just Started','color'=>'#ef4444'],
    };

    echo json_encode([
        'success'  => true,
        'data'     => [
            'has_income'        => $hasIncome,
            'monthly_income'    => $income,
            'target_pct'        => $targetPct,
            'consistency_score' => $consistencyScore,
            'avg_rate_pct'      => $avgRate,
            'current_streak'    => $streak,
            'best_streak'       => $bestStreak,
            'months_above_target' => $aboveTarget,
            'total_months'      => $totalValid,
            'grade'             => $grade,
            'badges'            => $badges,
            'monthly_data'      => $months,
        ]
    ]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// discipline_history — FY-wise invested totals
// ══════════════════════════════════════════════════════════════════════════
case 'discipline_history':
    $stmt = $db->prepare("
        SELECT
          CASE WHEN MONTH(t.tx_date) >= 4
               THEN CONCAT(YEAR(t.tx_date),'-',LPAD(SUBSTR(YEAR(t.tx_date)+1,3,2),2,'0'))
               ELSE CONCAT(YEAR(t.tx_date)-1,'-',LPAD(SUBSTR(YEAR(t.tx_date),3,2),2,'0'))
          END AS fy,
          COALESCE(SUM(t.amount), 0) AS invested
        FROM mf_transactions t
        JOIN mf_holdings mh ON mh.id = t.holding_id
        JOIN portfolios p ON p.id = mh.portfolio_id
        WHERE p.user_id = ?
          AND t.tx_type IN ('buy','sip','lumpsum','switch_in')
        GROUP BY fy
        ORDER BY fy
    ");
    $stmt->execute([$userId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success'=>true,'data'=>$history]);
    break;

default:
    echo json_encode(['success'=>false,'error'=>"Unknown action: $action"]);
}
