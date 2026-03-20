<?php
declare(strict_types=1); // MUST be first statement in PHP

/**
 * WealthDash — Post Office Schemes API (Standalone)
 * Direct URL: /api/post_office/po_schemes.php
 */
if (!defined('WEALTHDASH')) define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

// Discard anything that leaked before our code, send clean JSON headers
ob_clean();
header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

// ── Auth ────────────────────────────────────────────────────────────────────
if (!is_logged_in()) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit;
}

$userId  = (int)$_SESSION['user_id'];
$isAdmin = is_admin();

// ── CSRF (check header or post body) ───────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'POST') {
    $submitted = $_POST['_csrf_token']
              ?? $_POST['csrf_token']
              ?? $_SERVER['HTTP_X_CSRF_TOKEN']
              ?? '';
    if (!hash_equals(csrf_token(), $submitted)) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token. Please refresh the page.']);
        exit;
    }
}

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');

// ── Wrap everything in try/catch ─────────────────────────────────────────────
try {

// ── Auto-create table ───────────────────────────────────────────────────────
try {
    DB::conn()->query("SELECT 1 FROM po_schemes LIMIT 1");
} catch (\PDOException $tableErr) {
    DB::conn()->exec("
        CREATE TABLE IF NOT EXISTS `po_schemes` (
          `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
          `portfolio_id`    INT UNSIGNED NOT NULL,
          `scheme_type`     VARCHAR(30) NOT NULL,
          `account_number`  VARCHAR(50)  DEFAULT NULL,
          `holder_name`     VARCHAR(100) NOT NULL,
          `principal`       DECIMAL(14,2) NOT NULL DEFAULT 0.00,
          `interest_rate`   DECIMAL(6,3)  NOT NULL,
          `open_date`       DATE          NOT NULL,
          `maturity_date`   DATE          DEFAULT NULL,
          `maturity_amount` DECIMAL(14,2) DEFAULT NULL,
          `current_value`   DECIMAL(14,2) DEFAULT NULL,
          `deposit_amount`  DECIMAL(14,2) DEFAULT NULL,
          `interest_freq`   VARCHAR(20) NOT NULL DEFAULT 'yearly',
          `compounding`     VARCHAR(10) NOT NULL DEFAULT 'compound',
          `status`          VARCHAR(20) NOT NULL DEFAULT 'active',
          `is_joint`        TINYINT(1) NOT NULL DEFAULT 0,
          `nominee`         VARCHAR(100) DEFAULT NULL,
          `post_office`     VARCHAR(150) DEFAULT NULL,
          `notes`           TEXT DEFAULT NULL,
          `investment_fy`   VARCHAR(7)   DEFAULT NULL,
          `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_po_portfolio` (`portfolio_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

// ── Scheme metadata ─────────────────────────────────────────────────────────
$PO_META = [
    'savings_account' => ['label'=>'Post Office Savings Account','short'=>'PO Savings','rate'=>4.0,'icon'=>'🏦','color'=>'#0369a1','has_maturity'=>false,'desc'=>'Basic savings @ 4% p.a.','compounding'=>'simple','freq'=>'yearly'],
    'rd'   => ['label'=>'Post Office Recurring Deposit','short'=>'PO RD','rate'=>6.7,'icon'=>'🔄','color'=>'#7c3aed','has_maturity'=>true,'tenure_years'=>5,'desc'=>'5yr RD @ 6.7% quarterly compounding','compounding'=>'compound','freq'=>'quarterly'],
    'td'   => ['label'=>'Post Office Time Deposit','short'=>'PO TD','rate'=>7.5,'icon'=>'📅','color'=>'#0891b2','has_maturity'=>true,'desc'=>'TD up to 7.5% p.a. (quarterly compounding)','compounding'=>'compound','freq'=>'quarterly',
               'sub_tenures'=>[
                   ['years'=>1,'rate'=>6.9,'label'=>'1 Year'],
                   ['years'=>2,'rate'=>7.0,'label'=>'2 Years'],
                   ['years'=>3,'rate'=>7.1,'label'=>'3 Years'],
                   ['years'=>5,'rate'=>7.5,'label'=>'5 Years'],
               ]],
    'mis'  => ['label'=>'Post Office Monthly Income Scheme','short'=>'MIS','rate'=>7.4,'icon'=>'💰','color'=>'#059669','has_maturity'=>true,'tenure_years'=>5,'desc'=>'5yr MIS @ 7.4% monthly payouts','compounding'=>'simple','freq'=>'monthly'],
    'scss' => ['label'=>'Senior Citizen Savings Scheme','short'=>'SCSS','rate'=>8.2,'icon'=>'👴','color'=>'#d97706','has_maturity'=>true,'tenure_years'=>5,'desc'=>'5yr SCSS @ 8.2% quarterly (60+ yrs)','compounding'=>'simple','freq'=>'quarterly'],
    'ppf'  => ['label'=>'Public Provident Fund','short'=>'PPF','rate'=>7.1,'icon'=>'🛡️','color'=>'#1d4ed8','has_maturity'=>true,'tenure_years'=>15,'desc'=>'15yr PPF @ 7.1% p.a. tax-free','compounding'=>'compound','freq'=>'yearly'],
    'ssy'  => ['label'=>'Sukanya Samriddhi Yojana','short'=>'SSY','rate'=>8.2,'icon'=>'👧','color'=>'#be185d','has_maturity'=>true,'tenure_years'=>21,'desc'=>'21yr SSY @ 8.2% for girl child','compounding'=>'compound','freq'=>'yearly'],
    'nsc'  => ['label'=>'National Savings Certificate','short'=>'NSC','rate'=>7.7,'icon'=>'📜','color'=>'#0f766e','has_maturity'=>true,'tenure_years'=>5,'desc'=>'5yr NSC @ 7.7% annually compounded','compounding'=>'compound','freq'=>'yearly'],
    'kvp'  => ['label'=>'Kisan Vikas Patra','short'=>'KVP','rate'=>7.5,'icon'=>'🌾','color'=>'#15803d','has_maturity'=>true,'desc'=>'Doubles in ~115 months @ 7.5% p.a.','compounding'=>'compound','freq'=>'yearly'],
];

// ── Maturity calculator ─────────────────────────────────────────────────────
$calcMat = function(string $type, float $principal, float $rate, string $open, string $mat, float $dep=0.0): float {
    $d1    = new DateTime($open);
    $d2    = new DateTime($mat);
    $days  = (int)$d2->diff($d1)->days;
    $years = $days / 365;
    if ($years <= 0) return $principal;
    if ($type === 'rd') {
        $P      = ($dep > 0) ? $dep : $principal;
        $r      = $rate / 100;
        // Use exact calendar months (not days/365) for accuracy
        $months = ($d2->format('Y') - $d1->format('Y')) * 12 + ($d2->format('n') - $d1->format('n'));
        $n      = $months / 12;
        $den    = 1 - pow(1 + $r/4, -1.0/3.0);
        return ($den != 0 && $months > 0) ? round($P * ((pow(1+$r/4, 4*$n)-1) / $den), 2) : round($P * $months, 2);
    }
    if (in_array($type, ['mis','scss','savings_account'], true))
        return round($principal + $principal*($rate/100)*$years, 2);
    if ($type === 'td') {
        // TD: quarterly compounding within each year, but interest PAID OUT annually (not reinvested)
        // Annual interest = P × [(1 + r/4)^4 - 1]  (same every year, no inter-year compounding)
        // Maturity = P + (annual_interest × n_years)
        $annualInterest = $principal * (pow(1 + $rate/100/4, 4) - 1);
        return round($principal + $annualInterest * $years, 2);
    }
    return round($principal * pow(1+$rate/100, $years), 2);
};

// ════════════════════════════════════════════════
// po_list
// ════════════════════════════════════════════════
if ($action === 'po_list') {
    $pid    = (int)($_GET['portfolio_id'] ?? 0);
    $status = clean($_GET['status']      ?? '');
    $type   = clean($_GET['scheme_type'] ?? '');

    // Auto-mature: flip any active scheme whose maturity_date has passed
    DB::run(
        "UPDATE po_schemes po
         JOIN portfolios p ON p.id = po.portfolio_id
         SET po.status = 'matured', po.updated_at = NOW()
         WHERE p.user_id = ?
           AND po.status = 'active'
           AND po.maturity_date IS NOT NULL
           AND po.maturity_date < CURDATE()",
        [$userId]
    );

    $where  = 'p.user_id = ?';
    $params = [$userId];
    if ($pid)    { $where .= ' AND po.portfolio_id = ?'; $params[] = $pid; }
    if ($status) { $where .= ' AND po.status = ?';       $params[] = $status; }
    if ($type)   { $where .= ' AND po.scheme_type = ?';  $params[] = $type; }
    $rows = DB::fetchAll("SELECT po.*, p.name AS portfolio_name, DATEDIFF(po.maturity_date, CURDATE()) AS days_left
        FROM po_schemes po JOIN portfolios p ON p.id=po.portfolio_id WHERE {$where} ORDER BY po.status ASC, po.open_date DESC", $params);
    foreach ($rows as &$r) {
        $sm = $PO_META[$r['scheme_type']] ?? [];
        $r['scheme_label'] = $sm['label'] ?? $r['scheme_type'];
        $r['scheme_short'] = $sm['short'] ?? $r['scheme_type'];
        $r['scheme_icon']  = $sm['icon']  ?? '📋';
        $r['scheme_color'] = $sm['color'] ?? '#64748b';
        $r['interest_earned'] = round(max(0.0, (float)$r['maturity_amount'] - (float)$r['principal']), 2);
    } unset($r);
    ob_clean(); echo json_encode(['success'=>true,'message'=>'','data'=>$rows]); exit;
}

// ════════════════════════════════════════════════
// po_meta
// ════════════════════════════════════════════════
if ($action === 'po_meta') {
    ob_clean(); echo json_encode(['success'=>true,'message'=>'','data'=>$PO_META]); exit;
}

// ════════════════════════════════════════════════
// po_add
// ════════════════════════════════════════════════
if ($action === 'po_add') {
    $pid        = (int)($_POST['portfolio_id']     ?? 0);
    $schemeType = clean($_POST['scheme_type']      ?? '');
    $accountNum = clean($_POST['account_number']   ?? '');
    $holder     = clean($_POST['holder_name']      ?? '');
    $principal  = (float)($_POST['principal']      ?? 0);
    $deposit    = (float)($_POST['deposit_amount'] ?? 0);
    $rate       = (float)($_POST['interest_rate']  ?? 0);
    $openDate   = clean($_POST['open_date']        ?? '');
    $matDate    = trim(clean($_POST['maturity_date'] ?? '')) ?: null;
    $postOffice = clean($_POST['post_office']      ?? '');
    $nominee    = clean($_POST['nominee']          ?? '');
    $isJoint    = (int)($_POST['is_joint']         ?? 0);
    $notes      = clean($_POST['notes']            ?? '');

    if (!$pid || !can_access_portfolio($pid, $userId, $isAdmin)) { ob_clean(); echo json_encode(['success'=>false,'message'=>'Invalid portfolio.']); exit; }
    if (!array_key_exists($schemeType, $PO_META))                { ob_clean(); echo json_encode(['success'=>false,'message'=>'Invalid scheme type: '.$schemeType]); exit; }
    if (!$holder)                                                 { ob_clean(); echo json_encode(['success'=>false,'message'=>'Holder name is required.']); exit; }
    if ($schemeType === 'rd' && $deposit <= 0)                   { ob_clean(); echo json_encode(['success'=>false,'message'=>'Monthly deposit is required for RD.']); exit; }
    if ($schemeType !== 'rd' && $principal <= 0)                 { ob_clean(); echo json_encode(['success'=>false,'message'=>'Principal amount must be positive.']); exit; }
    if ($schemeType === 'td' && $principal < 1000)               { ob_clean(); echo json_encode(['success'=>false,'message'=>'Minimum deposit for TD is ₹1,000.']); exit; }
    if ($schemeType === 'td' && fmod($principal, 100) != 0)      { ob_clean(); echo json_encode(['success'=>false,'message'=>'TD deposit must be in multiples of ₹100.']); exit; }
    if ($rate <= 0 || $rate > 15)                                { ob_clean(); echo json_encode(['success'=>false,'message'=>'Interest rate must be between 0.01% and 15%.']); exit; }
    if (!$openDate || !validate_date($openDate))                 { ob_clean(); echo json_encode(['success'=>false,'message'=>'Invalid open date.']); exit; }
    if ($matDate !== null && (!validate_date($matDate) || $matDate <= $openDate)) { ob_clean(); echo json_encode(['success'=>false,'message'=>'Maturity date must be a valid date after open date.']); exit; }

    $sm = $PO_META[$schemeType];

    // Auto-fill maturity
    if ($matDate === null && isset($sm['tenure_years'])) {
        $matDate = (new DateTime($openDate))->modify('+'.$sm['tenure_years'].' years')->format('Y-m-d');
    }

    $matAmt = ($matDate !== null) ? $calcMat($schemeType, $principal, $rate, $openDate, $matDate, $deposit) : null;
    $fy     = calculate_fy($openDate);

    $id = DB::insert(
        "INSERT INTO po_schemes
           (portfolio_id,scheme_type,account_number,holder_name,
            principal,deposit_amount,interest_rate,
            open_date,maturity_date,maturity_amount,current_value,
            interest_freq,compounding,
            post_office,nominee,is_joint,notes,investment_fy,status)
         VALUES (?,?,?,?, ?,?,?, ?,?,?,?, ?,?, ?,?,?,?,?,'active')",
        [$pid,$schemeType,$accountNum?:null,$holder,
         $principal,$deposit>0?$deposit:null,$rate,
         $openDate,$matDate,$matAmt,$matAmt,
         $sm['freq'],$sm['compounding'],
         $postOffice?:null,$nominee?:null,$isJoint,$notes?:null,$fy]
    );

    audit_log('po_add', 'po_schemes', (int)$id);
    ob_clean(); echo json_encode(['success'=>true,'message'=>'Scheme added successfully.','data'=>['id'=>(int)$id,'maturity_amount'=>$matAmt,'maturity_date'=>$matDate]]); exit;
}

// ════════════════════════════════════════════════
// po_edit
// ════════════════════════════════════════════════
if ($action === 'po_edit') {
    $id        = (int)($_POST['id'] ?? 0);
    $holder    = clean($_POST['holder_name']      ?? '');
    $principal = (float)($_POST['principal']      ?? 0);
    $deposit   = (float)($_POST['deposit_amount'] ?? 0);
    $rate      = (float)($_POST['interest_rate']  ?? 0);
    $openDate  = clean($_POST['open_date']        ?? '');
    $matDate   = trim(clean($_POST['maturity_date'] ?? '')) ?: null;

    if (!$id) { ob_clean(); echo json_encode(['success'=>false,'message'=>'Invalid ID.']); exit; }
    $row = DB::fetchOne("SELECT po.*, p.user_id FROM po_schemes po JOIN portfolios p ON p.id=po.portfolio_id WHERE po.id=?", [$id]);
    if (!$row || (!$isAdmin && (int)$row['user_id'] !== $userId)) { ob_clean(); echo json_encode(['success'=>false,'message'=>'Access denied.']); exit; }

    $matAmt = ($matDate && $openDate && $matDate > $openDate)
        ? $calcMat($row['scheme_type'], $principal, $rate, $openDate, $matDate, $deposit)
        : null;

    DB::run("UPDATE po_schemes SET
        account_number=?,holder_name=?,principal=?,deposit_amount=?,
        interest_rate=?,open_date=?,maturity_date=?,maturity_amount=?,current_value=?,
        post_office=?,nominee=?,is_joint=?,notes=?,updated_at=NOW() WHERE id=?",
        [clean($_POST['account_number']??'')?: null,$holder,$principal,$deposit>0?$deposit:null,
         $rate,$openDate,$matDate,$matAmt,$matAmt,
         clean($_POST['post_office']??'')?:null,clean($_POST['nominee']??'')?:null,
         (int)($_POST['is_joint']??0),clean($_POST['notes']??'')?:null,$id]);

    audit_log('po_edit','po_schemes',$id);
    ob_clean(); echo json_encode(['success'=>true,'message'=>'Scheme updated.','data'=>['maturity_amount'=>$matAmt]]); exit;
}

// ════════════════════════════════════════════════
// po_close
// ════════════════════════════════════════════════
if ($action === 'po_close') {
    $id     = (int)($_POST['id'] ?? 0);
    $status = clean($_POST['status'] ?? 'matured');
    if (!in_array($status, ['matured','closed','partial_withdrawn'], true)) $status = 'matured';
    if (!$id) { ob_clean(); echo json_encode(['success'=>false,'message'=>'Invalid ID.']); exit; }
    $row = DB::fetchOne("SELECT po.*, p.user_id FROM po_schemes po JOIN portfolios p ON p.id=po.portfolio_id WHERE po.id=?", [$id]);
    if (!$row || (!$isAdmin && (int)$row['user_id'] !== $userId)) { ob_clean(); echo json_encode(['success'=>false,'message'=>'Access denied.']); exit; }
    DB::run("UPDATE po_schemes SET status=?,updated_at=NOW() WHERE id=?", [$status,$id]);
    audit_log('po_close','po_schemes',$id);
    ob_clean(); echo json_encode(['success'=>true,'message'=>ucfirst($status).' successfully.','data'=>[]]); exit;
}

// ════════════════════════════════════════════════
// po_delete
// ════════════════════════════════════════════════
if ($action === 'po_delete') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { ob_clean(); echo json_encode(['success'=>false,'message'=>'Invalid ID.']); exit; }
    $row = DB::fetchOne("SELECT po.*, p.user_id FROM po_schemes po JOIN portfolios p ON p.id=po.portfolio_id WHERE po.id=?", [$id]);
    if (!$row || (!$isAdmin && (int)$row['user_id'] !== $userId)) { ob_clean(); echo json_encode(['success'=>false,'message'=>'Access denied.']); exit; }
    DB::run("DELETE FROM po_schemes WHERE id=?", [$id]);
    audit_log('po_delete','po_schemes',$id);
    ob_clean(); echo json_encode(['success'=>true,'message'=>'Scheme deleted.','data'=>[]]); exit;
}

ob_clean(); echo json_encode(['success'=>false,'message'=>'Unknown action: '.$action,'data'=>[]]); exit;

} catch (\Throwable $e) {
    ob_clean();
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage(),
        'data'    => [],
    ]);
    exit;
}