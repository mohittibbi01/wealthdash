<?php
/**
 * WealthDash — Debug v6 — Direct API caller
 * Upload to: /wealthdash/wd_debug.php
 * DELETE after use!
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
$db = DB::conn();

function ok($msg)  { return ['status'=>'ok',  'msg'=>$msg]; }
function err($msg) { return ['status'=>'err', 'msg'=>$msg]; }
function info($msg){ return ['status'=>'info','msg'=>$msg]; }

$results = [];
$cookie = $_SERVER['HTTP_COOKIE'] ?? '';

function callApi(string $url, string $cookie): array {
    $ctx = stream_context_create(['http' => [
        'method'         => 'GET',
        'header'         => "Cookie: $cookie\r\nAccept: application/json\r\n",
        'timeout'        => 10,
        'ignore_errors'  => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return ['reachable'=>false, 'raw'=>''];
    return ['reachable'=>true, 'raw'=>$raw, 'json'=>json_decode($raw, true)];
}

// ══════════════════════════════════════════════════════
// 1. router.php — mf_list (Holdings)
// ══════════════════════════════════════════════════════
$r1 = callApi('http://localhost/wealthdash/api/router.php?action=mf_list&view=holdings', $cookie);
if (!$r1['reachable']) {
    $results['1. Holdings API (router.php)'] = info("file_get_contents disabled — see step 3 for direct test");
} elseif (!empty($r1['json']['success'])) {
    $cnt = count($r1['json']['data'] ?? []);
    $results['1. Holdings API (router.php)'] = ok("SUCCESS — $cnt holdings returned");
} else {
    $msg  = $r1['json']['error'] ?? $r1['json']['msg'] ?? '';
    $file = $r1['json']['file'] ?? '';
    $line = $r1['json']['line'] ?? '';
    $raw  = substr($r1['raw'], 0, 500);
    $results['1. Holdings API (router.php)'] = err("Error: $msg | File: $file | Line: $line | Raw: $raw");
}

// ══════════════════════════════════════════════════════
// 2. mf_list.php — transactions
// ══════════════════════════════════════════════════════
$pid = $db->query("SELECT id FROM portfolios WHERE is_default=1 LIMIT 1")->fetchColumn();
$r2 = callApi("http://localhost/wealthdash/api/mutual_funds/mf_list.php?view=transactions&portfolio_id=$pid&page=1&per_page=10&sort_col=txn_date&sort_dir=desc", $cookie);
if (!$r2['reachable']) {
    $results['2. Transactions API'] = info("file_get_contents disabled — see step 4");
} elseif (!empty($r2['json']['success'])) {
    $cnt = $r2['json']['total'] ?? 0;
    $results['2. Transactions API'] = ok("SUCCESS — $cnt total transactions");
} else {
    $msg  = $r2['json']['error'] ?? $r2['json']['msg'] ?? '';
    $file = $r2['json']['file'] ?? '';
    $line = $r2['json']['line'] ?? '';
    $raw  = substr($r2['raw'], 0, 500);
    $results['2. Transactions API'] = err("Error: $msg | File: $file | Line: $line | Raw: $raw");
}

// ══════════════════════════════════════════════════════
// 3. Direct include — mf_list holdings (bypass router)
// ══════════════════════════════════════════════════════
try {
    // Simulate what router does
    $userId = (int)$db->query("SELECT id FROM users LIMIT 1")->fetchColumn();
    $portfolioId = (int)$db->prepare("SELECT id FROM portfolios WHERE user_id=? AND is_default=1 LIMIT 1")->execute([$userId]) ? 
        $db->query("SELECT id FROM portfolios WHERE is_default=1 LIMIT 1")->fetchColumn() : 0;

    $stmt = $db->prepare("
        SELECT mh.id, mh.fund_id, mh.total_units AS units,
          mh.avg_cost_nav AS avg_nav,
          mh.total_invested AS invested_amount,
          mh.value_now AS current_value,
          mh.gain_loss, mh.xirr, mh.folio_number, mh.platform,
          mh.first_investment_date, mh.last_transaction_date,
          mh.sip_active, mh.swp_active,
          f.scheme_code, f.scheme_name AS fund_name,
          f.category AS scheme_category,
          f.sub_category AS scheme_sub_category,
          f.risk_level,
          f.latest_nav AS nav,
          f.nav_date,
          f.returns_1y AS return_1y,
          f.returns_3y AS return_3y,
          f.returns_5y AS return_5y,
          f.expense_ratio,
          f.exit_load_pct,
          f.aum_crore AS aum_cr,
          f.fund_manager
        FROM mf_holdings mh
        JOIN funds f ON f.id = mh.fund_id
        WHERE mh.portfolio_id = ?
        ORDER BY mh.value_now DESC
    ");
    $stmt->execute([$portfolioId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $results['3. Direct query with REAL col names'] = ok(count($rows) . " rows — this is what your query SHOULD look like!");

    // Show first row sample
    if ($rows) {
        $sample = array_map(fn($v) => substr((string)$v, 0, 15), $rows[0]);
        $results['3b. First holding sample'] = info(implode(' | ', array_map(fn($k,$v)=>"$k=$v", array_keys($sample), $sample)));
    }
} catch (Throwable $e) {
    $results['3. Direct query with REAL col names'] = err($e->getMessage() . " Line:" . $e->getLine());
}

// ══════════════════════════════════════════════════════
// 4. transactions query with REAL column names
// ══════════════════════════════════════════════════════
try {
    $tCols = $db->query("SHOW COLUMNS FROM mf_transactions")->fetchAll(PDO::FETCH_COLUMN);
    $results['4. mf_transactions columns'] = info(implode(', ', $tCols));

    $cnt = $db->query("SELECT COUNT(*) FROM mf_transactions t JOIN funds f ON f.id=t.fund_id WHERE t.portfolio_id=$pid")->fetchColumn();
    $results['4b. transactions count'] = ok("$cnt transactions for portfolio_id=$pid");
} catch (Throwable $e) {
    $results['4. transactions test'] = err($e->getMessage());
}

// ══════════════════════════════════════════════════════
// 5. Check what mf_list.php ACTUALLY uses vs real cols
// ══════════════════════════════════════════════════════
$hCols = $db->query("SHOW COLUMNS FROM mf_holdings")->fetchAll(PDO::FETCH_COLUMN);
$fCols = $db->query("SHOW COLUMNS FROM funds")->fetchAll(PDO::FETCH_COLUMN);

// What mf_list.php queries:
$mfListUsed = ['units'=>'total_units', 'avg_nav'=>'avg_cost_nav',
               'invested_amount'=>'total_invested', 'current_value'=>'value_now'];
$mfMismatch = [];
foreach ($mfListUsed as $used => $actual) {
    $usedExists   = in_array($used,   $hCols);
    $actualExists = in_array($actual, $hCols);
    if (!$usedExists) {
        $mfMismatch[] = "mf_list uses '$used' but table has '$actual'";
    }
}
$results['5. mf_list col mismatch'] = $mfMismatch
    ? err(implode("\n", $mfMismatch))
    : ok("No mismatches — aliases working");

// funds mismatch
$fListUsed = ['scheme_category'=>'category', 'nav'=>'latest_nav'];
$fMismatch = [];
foreach ($fListUsed as $used => $actual) {
    if (!in_array($used, $fCols)) {
        $fMismatch[] = "mf_list uses '$used' but table has '$actual'";
    }
}
$results['6. funds col mismatch'] = $fMismatch
    ? err(implode("\n", $fMismatch))
    : ok("No mismatches");

// ══════════════════════════════════════════════════════
// 7. FIX mf_list.php — patch the SQL to use real names
//    Add virtual generated columns with correct aliases
// ══════════════════════════════════════════════════════
$fixes = [];
$toAdd = [
    // mf_holdings virtual aliases
    'mf_holdings' => [
        'units'          => ['DECIMAL(15,4)', 'total_units'],
        'avg_nav'        => ['DECIMAL(15,4)', 'avg_cost_nav'],
        'invested_amount'=> ['DECIMAL(15,2)', 'total_invested'],
        'current_value'  => ['DECIMAL(15,2)', 'value_now'],
    ],
    // funds virtual aliases
    'funds' => [
        'scheme_category' => ['VARCHAR(200)', 'category'],
        'nav'             => ['DECIMAL(15,4)', 'latest_nav'],
    ],
];
foreach ($toAdd as $table => $cols) {
    $existingCols = $db->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cols as $alias => $info) {
        [$type, $source] = $info;
        if (in_array($alias, $existingCols)) {
            $fixes[] = ok("$table.$alias already exists");
            continue;
        }
        if (!in_array($source, $existingCols)) {
            $fixes[] = err("$table.$alias: source col '$source' also missing!");
            continue;
        }
        try {
            $db->exec("ALTER TABLE `$table` ADD COLUMN `$alias` $type GENERATED ALWAYS AS (`$source`) VIRTUAL");
            $fixes[] = ['status'=>'fixed','msg'=>"$table.$alias → virtual alias of $source ✓"];
        } catch (Exception $e) {
            $fixes[] = err("$table.$alias: " . $e->getMessage());
        }
    }
}
$results['7. AUTO-FIX virtual aliases'] = $fixes ?: [info("Nothing to fix")];

// ══════════════════════════════════════════════════════
// 8. FINAL test with mf_list.php exact query
// ══════════════════════════════════════════════════════
try {
    $stmt2 = $db->prepare("
        SELECT mh.id, mh.fund_id, mh.units, mh.avg_nav AS avg_buy_nav,
          mh.invested_amount, mh.current_value, mh.gain_loss, mh.xirr,
          f.scheme_name AS fund_name, f.scheme_category AS category,
          f.nav AS current_nav
        FROM mf_holdings mh
        JOIN funds f ON f.id = mh.fund_id
        WHERE mh.portfolio_id = ?
        ORDER BY mh.current_value DESC LIMIT 3
    ");
    $stmt2->execute([$pid]);
    $rows2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    $results['8. FINAL mf_list query test'] = ok("SUCCESS! " . count($rows2) . " holdings — exact mf_list.php query works!");
} catch (Throwable $e) {
    $results['8. FINAL mf_list query test'] = err($e->getMessage() . " Line:" . $e->getLine());
}

?><!DOCTYPE html>
<html><head><meta charset="utf-8"><title>Debug v6</title>
<style>
body{font-family:monospace;background:#0f172a;color:#e2e8f0;padding:20px;margin:0;font-size:12px}
h1{color:#a78bfa;margin-bottom:2px}.sub{color:#64748b;font-size:11px;margin-bottom:20px}
.card{background:#1e293b;border-radius:8px;padding:12px 16px;margin-bottom:8px}
.title{font-weight:bold;color:#94a3b8;font-size:10px;text-transform:uppercase;margin-bottom:5px}
.ok{color:#4ade80}.err{color:#f87171}.info{color:#facc15}.fixed{color:#38bdf8}
pre{margin:4px 0 0;font-size:10px;white-space:pre-wrap;word-break:break-all}
.warn{background:#7c3aed22;border:1px solid #7c3aed;border-radius:6px;padding:10px;margin-top:12px;color:#c4b5fd;font-size:11px}
</style></head><body>
<h1>🔍 WealthDash Debug v6</h1>
<div class="sub"><?= date('d M Y H:i:s') ?> — DELETE after use!</div>
<?php foreach ($results as $section => $result):
  $items = isset($result['status']) ? [$result] : $result;
?><div class="card"><div class="title"><?= htmlspecialchars($section) ?></div>
<?php foreach ($items as $r):
  $cls = $r['status']??'info';
  $icon= $cls==='ok'?'✅':($cls==='err'?'❌':($cls==='fixed'?'🔧':'ℹ️'));
?><div class="<?=$cls?>"><?=$icon?> <?= strlen($r['msg'])>100 ? '<pre>'.htmlspecialchars($r['msg']).'</pre>' : htmlspecialchars($r['msg']) ?></div>
<?php endforeach;?></div><?php endforeach;?>
<div class="warn">⚠️ Delete: <code>del C:\xampp\htdocs\wealthdash\wd_debug.php</code></div>
</body></html>
