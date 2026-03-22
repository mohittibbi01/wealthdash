<?php
/**
 * WealthDash — Debug Health Check (t23)
 * Admin-only: checks DB, APIs, missing tables, PHP errors
 * Access: localhost/wealthdash/debug_check.php
 */
define('WEALTHDASH', true);
require_once dirname(__FILE__) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

$currentUser = require_auth();
// Admin only
if ($currentUser['role'] !== 'admin') {
    http_response_code(403);
    die('<h1>403 Forbidden — Admin only</h1>');
}

$checks = [];
$pass = 0; $fail = 0; $warn = 0;

function addCheck($cat, $label, $status, $detail = '') {
    global $checks, $pass, $fail, $warn;
    $checks[] = ['cat'=>$cat,'label'=>$label,'status'=>$status,'detail'=>$detail];
    if ($status === 'pass') $pass++;
    elseif ($status === 'fail') $fail++;
    else $warn++;
}

// ── 1. DB Connection ────────────────────────────────────────
try {
    $pdo = DB::conn();
    addCheck('Database', 'DB Connection', 'pass', 'Connected successfully');
} catch (Exception $e) {
    addCheck('Database', 'DB Connection', 'fail', $e->getMessage());
}

// ── 2. Required Tables ──────────────────────────────────────
$requiredTables = [
    'users','portfolios','funds','fund_houses','mf_transactions','mf_holdings',
    'nav_history','sip_schedules','fd_accounts','nps_holdings','nps_schemes',
    'nps_transactions','stock_master','stock_holdings','stock_transactions',
    'post_office_schemes','savings_accounts',
];
$optionalTables = [
    'insurance_policies','loan_accounts','bank_accounts','bank_balance_history',
    'epf_accounts',
];
try {
    $existingRaw = DB::fetchAll("SHOW TABLES");
    $existing    = array_map(fn($r) => array_values($r)[0], $existingRaw);
    foreach ($requiredTables as $t) {
        if (in_array($t, $existing)) addCheck('Database', "Table: $t", 'pass');
        else addCheck('Database', "Table: $t", 'fail', 'Table missing — run migration');
    }
    foreach ($optionalTables as $t) {
        if (in_array($t, $existing)) addCheck('Database', "Optional: $t", 'pass');
        else addCheck('Database', "Optional: $t", 'warn', 'Run migration to enable this feature');
    }
} catch (Exception $e) {
    addCheck('Database', 'Table Check', 'fail', $e->getMessage());
}

// ── 3. Key Files ────────────────────────────────────────────
$keyFiles = [
    APP_ROOT . '/config/config.php'                         => 'Config file',
    APP_ROOT . '/api/router.php'                            => 'API Router',
    APP_ROOT . '/includes/auth_check.php'                   => 'Auth check',
    APP_ROOT . '/templates/layout.php'                      => 'Layout template',
    APP_ROOT . '/public/js/app.js'                          => 'App JS',
    APP_ROOT . '/public/js/mf.js'                           => 'MF JS',
    APP_ROOT . '/public/css/app.css'                        => 'App CSS',
    APP_ROOT . '/templates/pages/mf_holdings.php'           => 'MF Holdings page',
    APP_ROOT . '/templates/pages/mf_screener.php'           => 'MF Screener page',
    APP_ROOT . '/templates/pages/fd.php'                    => 'FD page',
    APP_ROOT . '/templates/pages/nps.php'                   => 'NPS page',
    APP_ROOT . '/templates/pages/stocks.php'                => 'Stocks page',
    APP_ROOT . '/templates/pages/goals.php'                 => 'Goals page',
    APP_ROOT . '/templates/pages/report_tax.php'            => 'Tax report',
];
foreach ($keyFiles as $path => $label) {
    if (file_exists($path)) addCheck('Files', $label, 'pass', basename($path));
    else addCheck('Files', $label, 'fail', "Missing: $path");
}

// ── 4. PHP Configuration ────────────────────────────────────
$phpChecks = [
    ['pdo_mysql', 'PDO MySQL extension'],
    ['json',      'JSON extension'],
    ['mbstring',  'mbstring extension'],
    ['openssl',   'OpenSSL extension'],
    ['curl',      'cURL extension'],
];
foreach ($phpChecks as [$ext, $label]) {
    if (extension_loaded($ext)) addCheck('PHP', $label, 'pass');
    else addCheck('PHP', $label, 'fail', "Install php-{$ext}");
}
// PHP version
$phpVer = phpversion();
if (version_compare($phpVer, '8.0', '>=')) addCheck('PHP', 'PHP Version', 'pass', "PHP $phpVer");
elseif (version_compare($phpVer, '7.4', '>=')) addCheck('PHP', 'PHP Version', 'warn', "PHP $phpVer — upgrade to 8.0+ recommended");
else addCheck('PHP', 'PHP Version', 'fail', "PHP $phpVer — upgrade required");

// ── 5. API Endpoint Tests ───────────────────────────────────
$apiTests = [
    ['action' => 'fd_list',     'label' => 'FD API'],
    ['action' => 'nps_list',    'label' => 'NPS API', 'params' => 'type=holdings'],
    ['action' => 'stocks_list', 'label' => 'Stocks API', 'params' => 'type=holdings'],
    ['action' => 'po_list',     'label' => 'Post Office API'],
];
foreach ($apiTests as $test) {
    $url = APP_URL . '/api/router.php?action=' . $test['action'] . '&' . ($test['params'] ?? '');
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true, 'header' => 'Cookie: ' . ($_SERVER['HTTP_COOKIE'] ?? '')]]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) addCheck('APIs', $test['label'], 'warn', 'Could not reach (check from browser)');
    else {
        $json = json_decode($resp, true);
        if ($json && isset($json['success'])) addCheck('APIs', $test['label'], 'pass', $json['success'] ? 'OK' : 'Returned: ' . ($json['message'] ?? ''));
        else addCheck('APIs', $test['label'], 'warn', 'Unexpected response');
    }
}

// ── 6. Net Worth Components ─────────────────────────────────
try {
    $uid   = $currentUser['id'];
    $counts = [];
    foreach (['mf_holdings'=>'MF Holdings','fd_accounts'=>'FD Accounts','stock_holdings'=>'Stock Holdings','nps_holdings'=>'NPS Holdings'] as $tbl => $label) {
        try {
            $c = DB::fetchOne("SELECT COUNT(*) AS c FROM {$tbl} t JOIN portfolios p ON p.id=t.portfolio_id WHERE p.user_id=?", [$uid]);
            $counts[$label] = (int)($c['c'] ?? 0);
            addCheck('Data', $label, $counts[$label] > 0 ? 'pass' : 'warn', $counts[$label] . ' records');
        } catch (Exception $e) {
            addCheck('Data', $label, 'fail', $e->getMessage());
        }
    }
} catch (Exception $e) {}

// ── Render HTML Report ──────────────────────────────────────
$total = $pass + $fail + $warn;
$health = $fail === 0 ? ($warn === 0 ? '🟢 Excellent' : '🟡 Good (warnings)') : '🔴 Issues found';
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>WealthDash Debug Check</title>
<style>
body { font-family: system-ui, sans-serif; background: #f8fafc; color: #1e293b; max-width: 900px; margin: 0 auto; padding: 20px; }
h1 { font-size: 22px; margin-bottom: 4px; }
.summary { display: flex; gap: 16px; margin: 16px 0; flex-wrap: wrap; }
.badge { padding: 6px 16px; border-radius: 8px; font-weight: 700; font-size: 13px; }
.badge-pass { background: #dcfce7; color: #15803d; }
.badge-fail { background: #fee2e2; color: #dc2626; }
.badge-warn { background: #fef3c7; color: #b45309; }
.section { margin-bottom: 20px; }
.section h2 { font-size: 14px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: #64748b; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; margin-bottom: 10px; }
.row { display: flex; align-items: center; gap: 10px; padding: 7px 10px; border-radius: 6px; margin-bottom: 4px; background: white; border: 1px solid #e2e8f0; font-size: 13px; }
.icon { width: 20px; flex-shrink: 0; text-align: center; font-size: 15px; }
.label { flex: 1; }
.detail { color: #94a3b8; font-size: 11px; }
.status-pass .icon::after { content: '✅'; }
.status-fail .icon::after { content: '❌'; }
.status-warn .icon::after { content: '⚠️'; }
.status-fail { border-color: #fca5a5; background: #fff5f5; }
.status-warn { border-color: #fde68a; background: #fffbeb; }
</style>
</head>
<body>
<h1>🔍 WealthDash Health Check</h1>
<p style="color:#64748b;font-size:13px;">Run at <?= date('d M Y H:i:s') ?> · User: <?= e($currentUser['email'] ?? 'admin') ?></p>
<div class="summary">
  <span class="badge badge-pass">✅ <?= $pass ?> Passed</span>
  <?php if ($fail): ?><span class="badge badge-fail">❌ <?= $fail ?> Failed</span><?php endif; ?>
  <?php if ($warn): ?><span class="badge badge-warn">⚠️ <?= $warn ?> Warnings</span><?php endif; ?>
  <span class="badge" style="background:#e2e8f0;color:#475569;">Overall: <?= $health ?></span>
</div>

<?php
$grouped = [];
foreach ($checks as $c) $grouped[$c['cat']][] = $c;
foreach ($grouped as $cat => $items):
?>
<div class="section">
  <h2><?= htmlspecialchars($cat) ?></h2>
  <?php foreach ($items as $item): ?>
  <div class="row status-<?= $item['status'] ?>">
    <span class="icon"></span>
    <span class="label"><?= htmlspecialchars($item['label']) ?></span>
    <?php if ($item['detail']): ?>
    <span class="detail"><?= htmlspecialchars($item['detail']) ?></span>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php endforeach; ?>

<?php if ($fail > 0): ?>
<div style="padding:12px;background:#fee2e2;border-radius:8px;font-size:13px;color:#dc2626;margin-top:10px;">
  <strong>❌ <?= $fail ?> critical issues found.</strong> Fix failed checks before using WealthDash.
</div>
<?php elseif ($warn > 0): ?>
<div style="padding:12px;background:#fef3c7;border-radius:8px;font-size:13px;color:#b45309;margin-top:10px;">
  <strong>⚠️ <?= $warn ?> warnings found.</strong> Optional features may not work. Run pending migrations.
</div>
<?php else: ?>
<div style="padding:12px;background:#dcfce7;border-radius:8px;font-size:13px;color:#15803d;margin-top:10px;">
  <strong>✅ All checks passed!</strong> WealthDash is healthy.
</div>
<?php endif; ?>
</body>
</html>
