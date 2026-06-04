<?php
/**
 * WealthDash — Debug Dashboard [t23]
 * All pages/APIs health check — IS_LOCAL only
 * Worker: ID-M
 */
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

if (!IS_LOCAL && !is_admin()) {
    http_response_code(403);
    die('Debug access denied.');
}

$user = require_auth(ROLE_ADMIN);

// ── Helpers ─────────────────────────────────────────────────────────────────

function check_file(string $path): array {
    $full = APP_ROOT . '/' . ltrim($path, '/');
    $exists = file_exists($full);
    $size   = $exists ? filesize($full) : 0;
    $mtime  = $exists ? date('d M Y H:i', filemtime($full)) : '—';
    return ['path' => $path, 'exists' => $exists, 'size' => $size, 'mtime' => $mtime];
}

function check_db_table(string $table): array {
    try {
        $count = (int) DB::fetchVal("SELECT COUNT(*) FROM `{$table}`");
        return ['table' => $table, 'ok' => true, 'rows' => $count, 'error' => ''];
    } catch (Exception $e) {
        return ['table' => $table, 'ok' => false, 'rows' => 0, 'error' => $e->getMessage()];
    }
}

function check_api_route(string $action, string $method = 'GET'): array {
    $url = APP_URL . '/api/router.php?action=' . urlencode($action);
    $ctx = stream_context_create([
        'http' => [
            'method'  => $method,
            'timeout' => 4,
            'header'  => [
                'Cookie: ' . session_name() . '=' . session_id(),
                'X-Requested-With: XMLHttpRequest',
            ],
            'ignore_errors' => true,
        ],
    ]);
    $start = microtime(true);
    $body  = @file_get_contents($url, false, $ctx);
    $ms    = round((microtime(true) - $start) * 1000);

    $http_response_header = $http_response_header ?? [];
    $status_line = $http_response_header[0] ?? 'HTTP/1.1 000 Unknown';
    preg_match('/HTTP\/\S+ (\d+)/', $status_line, $m);
    $code = (int)($m[1] ?? 0);

    $json = json_decode($body ?? '', true);
    $isJson = is_array($json);
    $apiOk  = $isJson && isset($json['success']);

    return [
        'action' => $action,
        'code'   => $code,
        'ms'     => $ms,
        'isJson' => $isJson,
        'ok'     => ($code < 500 && $apiOk),
        'msg'    => $isJson ? ($json['message'] ?? '') : substr($body ?? '', 0, 80),
    ];
}

// ── Checks ───────────────────────────────────────────────────────────────────

// 1. Core files
$coreFiles = [
    'config/config.php', 'config/constants.php', 'config/database.php',
    'includes/auth_check.php', 'includes/helpers.php', 'includes/cache.php',
    'api/router.php', 'index.php',
    '.env', '.htaccess',
    'public/css/app.css', 'public/js/app.js',
];

// 2. Template pages
$templatePages = [
    'templates/pages/dashboard.php', 'templates/pages/mf_holdings.php',
    'templates/pages/mf_transactions.php', 'templates/pages/stocks.php',
    'templates/pages/fd.php', 'templates/pages/savings.php',
    'templates/pages/nps.php', 'templates/pages/bonds.php',
    'templates/pages/gold.php', 'templates/pages/crypto.php',
    'templates/pages/insurance.php', 'templates/pages/loans.php',
    'templates/pages/realestate.php', 'templates/pages/post_office.php',
    'templates/pages/epf_accounts.php',
    'templates/pages/tax_calculator.php', 'templates/pages/report_tax.php',
    'templates/pages/report_fy.php', 'templates/pages/settings.php',
    'templates/pages/admin.php', 'templates/pages/banks.php',
];

// 3. API files
$apiFiles = [
    'api/dashboard/unified.php', 'api/mutual_funds/mf_holdings.php',
    'api/fd/fd_accounts.php', 'api/savings/savings.php',
    'api/stocks/stocks_list.php', 'api/nps/nps_list.php',
    'api/bonds/bonds.php', 'api/gold/gold.php',
    'api/crypto/crypto_list.php', 'api/insurance/insurance.php',
    'api/loan/loans.php', 'api/epf/epf_list.php',
    'api/banks/banks.php', 'api/tax/tax_calc.php',
    'api/reports/report_fy.php', 'api/user/profile.php',
    'api/admin/settings.php',
];

// 4. DB tables
$tables = [
    'users', 'portfolios', 'mf_holdings', 'mf_transactions', 'funds',
    'nav_history', 'sip_schedules', 'fd_accounts', 'savings_accounts',
    'stock_holdings', 'stock_transactions', 'stock_master',
    'nps_holdings', 'nps_schemes', 'nps_transactions',
    'bank_accounts', 'bank_transactions',
    'insurance_policies', 'loan_accounts', 'epf_accounts',
    'notifications', 'login_attempts', 'sessions', 'app_settings',
    'net_worth_snapshots', 'investment_goals',
];

// 5. API route smoke tests (read-only, safe)
$routeTests = [
    'get_dashboard_data', 'unified_summary', 'fd_list', 'savings_list',
    'nps_list', 'stocks_list', 'sip_list', 'indexes_fetch',
    'bank_list', 'insurance_list', 'loans_list', 'goal_list',
];

$coreResults  = array_map('check_file', $coreFiles);
$tmplResults  = array_map('check_file', $templatePages);
$apiResults   = array_map('check_file', $apiFiles);
$dbResults    = array_map('check_db_table', $tables);

// Route tests — only run if requested
$routeResults = [];
$runRoutes    = isset($_GET['routes']);
if ($runRoutes) {
    $routeResults = array_map('check_api_route', $routeTests);
}

// ── PHP/Server Info ───────────────────────────────────────────────────────────
$phpInfo = [
    'PHP Version'       => PHP_VERSION,
    'PHP SAPI'          => PHP_SAPI,
    'MySQL'             => DB::fetchVal('SELECT VERSION()'),
    'APP_ENV'           => APP_ENV,
    'APP_URL'           => APP_URL,
    'Timezone'          => date_default_timezone_get(),
    'Session Status'    => session_status() === PHP_SESSION_ACTIVE ? 'Active' : 'Inactive',
    'max_execution_time'=> ini_get('max_execution_time') . 's',
    'memory_limit'      => ini_get('memory_limit'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size'     => ini_get('post_max_size'),
];

// ── Counts ────────────────────────────────────────────────────────────────────
$coreOk  = count(array_filter($coreResults, fn($r) => $r['exists']));
$tmplOk  = count(array_filter($tmplResults, fn($r) => $r['exists']));
$apiOk   = count(array_filter($apiResults, fn($r) => $r['exists']));
$dbOk    = count(array_filter($dbResults, fn($r) => $r['ok']));

$totalFiles  = count($coreResults) + count($tmplResults) + count($apiResults);
$totalOkFiles= $coreOk + $tmplOk + $apiOk;

$overallHealth = $totalFiles > 0 ? round(($totalOkFiles / $totalFiles) * 100) : 0;

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>WealthDash Debug — t23</title>
<style>
:root { --bg:#0d0f18; --surface:#161922; --surface2:#1e2235; --surface3:#252a42;
        --border:#2a2f4a; --text:#e2e5f5; --muted:#7b84a8;
        --accent:#7c6fcd; --accent2:#4fc3a1; --warn:#e6a817;
        --danger:#e05c5c; --done:#4fc3a1; --radius:8px; }
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Segoe UI',sans-serif; background:var(--bg); color:var(--text); font-size:13px; }
.hdr { padding:14px 20px; border-bottom:1px solid var(--border); background:var(--surface);
       display:flex; align-items:center; gap:12px; }
.hdr h1 { font-size:16px; font-weight:700; }
.badge { font-size:10px; padding:2px 8px; border-radius:20px; font-weight:600;
         background:color-mix(in srgb,var(--accent) 18%,transparent); color:var(--accent); }
.health-bar { height:4px; background:var(--surface2); margin:0; }
.health-inner { height:4px; transition:width .8s; }
.wrap { padding:16px 20px; max-width:1400px; }
.grid-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:10px; margin-bottom:16px; }
.stat-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius);
             padding:12px 14px; }
.stat-val { font-size:22px; font-weight:700; font-family:'Courier New',monospace; }
.stat-lbl { font-size:10px; color:var(--muted); margin-top:3px; text-transform:uppercase; letter-spacing:.06em; }
.section { margin-bottom:20px; }
.section-title { font-size:11px; font-weight:700; color:var(--muted); text-transform:uppercase;
                 letter-spacing:.08em; margin-bottom:8px; display:flex; align-items:center; gap:8px; }
.section-title::after { content:''; flex:1; height:1px; background:var(--border); }
.tbl-wrap { overflow-x:auto; border:1px solid var(--border); border-radius:var(--radius); }
table { width:100%; border-collapse:collapse; font-size:12px; }
th { text-align:left; padding:7px 10px; font-size:10px; font-weight:600; color:var(--muted);
     background:var(--surface2); border-bottom:1px solid var(--border); }
td { padding:6px 10px; border-bottom:1px solid color-mix(in srgb,var(--border) 50%,transparent);
     vertical-align:middle; }
tr:last-child td { border-bottom:none; }
tr:hover td { background:var(--surface2); }
.ok { color:var(--done); font-weight:600; }
.fail { color:var(--danger); font-weight:600; }
.warn { color:var(--warn); font-weight:600; }
.pill { display:inline-block; font-size:10px; padding:1px 7px; border-radius:20px; font-weight:600; }
.p-ok { background:color-mix(in srgb,var(--done) 16%,transparent); color:var(--done); }
.p-fail { background:color-mix(in srgb,var(--danger) 16%,transparent); color:var(--danger); }
.p-warn { background:color-mix(in srgb,var(--warn) 16%,transparent); color:var(--warn); }
.mono { font-family:'Courier New',monospace; font-size:11px; }
.info-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:6px; }
.info-row { background:var(--surface); border:1px solid var(--border); border-radius:6px;
            padding:7px 12px; display:flex; justify-content:space-between; align-items:center; }
.info-key { color:var(--muted); font-size:11px; }
.info-val { font-family:'Courier New',monospace; font-size:11px; color:var(--accent2); }
.btn { padding:6px 14px; border-radius:6px; border:1px solid var(--border); background:var(--surface2);
       color:var(--text); cursor:pointer; font-size:12px; font-weight:600; text-decoration:none;
       display:inline-block; transition:.15s; }
.btn:hover { background:var(--accent); border-color:var(--accent); color:#fff; }
.btn-sm { font-size:11px; padding:3px 10px; }
.row-ok td { /* subtle */ }
.row-fail td:first-child { color:var(--danger); }
.tabs { display:flex; border-bottom:1px solid var(--border); margin-bottom:14px; gap:0; }
.tab { padding:8px 16px; font-size:12px; font-weight:600; color:var(--muted); cursor:pointer;
       border-bottom:2px solid transparent; margin-bottom:-1px; transition:.15s; }
.tab.active { color:var(--accent); border-bottom-color:var(--accent); }
.tab-pane { display:none; }
.tab-pane.active { display:block; }
.path-cell { max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
</style>
</head>
<body>

<div class="hdr">
    <div>
        <h1>WealthDash Debug <span class="badge">t23</span></h1>
        <div style="font-size:11px;color:var(--muted);margin-top:2px;">
            <?= date('d M Y H:i:s') ?> · ID-M
        </div>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
        <a href="?routes=1" class="btn btn-sm">▶ Run Route Tests</a>
        <a href="?" class="btn btn-sm">↺ Refresh</a>
    </div>
</div>

<?php
$barColor = $overallHealth >= 90 ? '#4fc3a1' : ($overallHealth >= 70 ? '#e6a817' : '#e05c5c');
?>
<div class="health-bar">
    <div class="health-inner" style="width:<?= $overallHealth ?>%;background:<?= $barColor ?>;"></div>
</div>

<div class="wrap">

<!-- Stats -->
<div class="grid-stats">
    <div class="stat-card">
        <div class="stat-val" style="color:<?= $overallHealth >= 80 ? 'var(--done)' : 'var(--warn)' ?>">
            <?= $overallHealth ?>%
        </div>
        <div class="stat-lbl">Overall Health</div>
    </div>
    <div class="stat-card">
        <div class="stat-val"><?= $totalOkFiles ?>/<?= $totalFiles ?></div>
        <div class="stat-lbl">Files OK</div>
    </div>
    <div class="stat-card">
        <div class="stat-val" style="color:<?= $dbOk === count($dbResults) ? 'var(--done)' : 'var(--warn)' ?>">
            <?= $dbOk ?>/<?= count($dbResults) ?>
        </div>
        <div class="stat-lbl">DB Tables OK</div>
    </div>
    <div class="stat-card">
        <div class="stat-val"><?= PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION ?></div>
        <div class="stat-lbl">PHP Version</div>
    </div>
    <div class="stat-card">
        <div class="stat-val"><?= $phpInfo['MySQL'] ?></div>
        <div class="stat-lbl">MySQL Version</div>
    </div>
    <div class="stat-card">
        <div class="stat-val"><?= APP_ENV ?></div>
        <div class="stat-lbl">Environment</div>
    </div>
</div>

<!-- PHP / Server Info -->
<div class="section">
    <div class="section-title">Server &amp; PHP Info</div>
    <div class="info-grid">
        <?php foreach ($phpInfo as $k => $v): ?>
        <div class="info-row">
            <span class="info-key"><?= htmlspecialchars($k) ?></span>
            <span class="info-val"><?= htmlspecialchars((string)$v) ?></span>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Tabs -->
<div class="tabs">
    <div class="tab active" onclick="showTab('core')">Core Files (<?= $coreOk ?>/<?= count($coreResults) ?>)</div>
    <div class="tab" onclick="showTab('tmpl')">Templates (<?= $tmplOk ?>/<?= count($tmplResults) ?>)</div>
    <div class="tab" onclick="showTab('api')">API Files (<?= $apiOk ?>/<?= count($apiResults) ?>)</div>
    <div class="tab" onclick="showTab('db')">DB Tables (<?= $dbOk ?>/<?= count($dbResults) ?>)</div>
    <?php if ($runRoutes): ?>
    <div class="tab" onclick="showTab('routes')">Route Tests (<?= count($routeResults) ?>)</div>
    <?php endif; ?>
</div>

<!-- Core Files -->
<div id="tab-core" class="tab-pane active section">
    <div class="tbl-wrap">
        <table>
            <thead><tr><th>File</th><th>Status</th><th>Size</th><th>Modified</th></tr></thead>
            <tbody>
            <?php foreach ($coreResults as $r): ?>
            <tr class="<?= $r['exists'] ? 'row-ok' : 'row-fail' ?>">
                <td class="path-cell mono"><?= htmlspecialchars($r['path']) ?></td>
                <td><?php if ($r['exists']): ?>
                    <span class="pill p-ok">✓ Exists</span>
                <?php else: ?>
                    <span class="pill p-fail">✗ Missing</span>
                <?php endif; ?></td>
                <td class="mono"><?= $r['exists'] ? number_format($r['size']) . ' B' : '—' ?></td>
                <td class="mono" style="color:var(--muted)"><?= $r['mtime'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Templates -->
<div id="tab-tmpl" class="tab-pane section">
    <div class="tbl-wrap">
        <table>
            <thead><tr><th>Template</th><th>Status</th><th>Size</th><th>Modified</th></tr></thead>
            <tbody>
            <?php foreach ($tmplResults as $r): ?>
            <tr>
                <td class="path-cell mono"><?= htmlspecialchars($r['path']) ?></td>
                <td><?php if ($r['exists']): ?>
                    <span class="pill p-ok">✓ Exists</span>
                <?php else: ?>
                    <span class="pill p-fail">✗ Missing</span>
                <?php endif; ?></td>
                <td class="mono"><?= $r['exists'] ? number_format($r['size']) . ' B' : '—' ?></td>
                <td class="mono" style="color:var(--muted)"><?= $r['mtime'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- API Files -->
<div id="tab-api" class="tab-pane section">
    <div class="tbl-wrap">
        <table>
            <thead><tr><th>API File</th><th>Status</th><th>Size</th><th>Modified</th></tr></thead>
            <tbody>
            <?php foreach ($apiResults as $r): ?>
            <tr>
                <td class="path-cell mono"><?= htmlspecialchars($r['path']) ?></td>
                <td><?php if ($r['exists']): ?>
                    <span class="pill p-ok">✓ Exists</span>
                <?php else: ?>
                    <span class="pill p-fail">✗ Missing</span>
                <?php endif; ?></td>
                <td class="mono"><?= $r['exists'] ? number_format($r['size']) . ' B' : '—' ?></td>
                <td class="mono" style="color:var(--muted)"><?= $r['mtime'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- DB Tables -->
<div id="tab-db" class="tab-pane section">
    <div class="tbl-wrap">
        <table>
            <thead><tr><th>Table</th><th>Status</th><th>Row Count</th><th>Error</th></tr></thead>
            <tbody>
            <?php foreach ($dbResults as $r): ?>
            <tr>
                <td class="mono"><?= htmlspecialchars($r['table']) ?></td>
                <td><?php if ($r['ok']): ?>
                    <span class="pill p-ok">✓ OK</span>
                <?php else: ?>
                    <span class="pill p-fail">✗ Error</span>
                <?php endif; ?></td>
                <td class="mono"><?= $r['ok'] ? number_format($r['rows']) : '—' ?></td>
                <td style="color:var(--danger);font-size:11px"><?= htmlspecialchars($r['error']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($runRoutes): ?>
<!-- Route Tests -->
<div id="tab-routes" class="tab-pane section">
    <div class="tbl-wrap">
        <table>
            <thead><tr><th>Action</th><th>HTTP</th><th>JSON</th><th>Time</th><th>Message</th></tr></thead>
            <tbody>
            <?php foreach ($routeResults as $r): ?>
            <tr>
                <td class="mono"><?= htmlspecialchars($r['action']) ?></td>
                <td>
                    <?php $c = $r['code']; $cls = $c < 400 ? 'p-ok' : ($c < 500 ? 'p-warn' : 'p-fail'); ?>
                    <span class="pill <?= $cls ?>"><?= $c ?></span>
                </td>
                <td><?= $r['isJson'] ? '<span class="pill p-ok">JSON</span>' : '<span class="pill p-fail">Non-JSON</span>' ?></td>
                <td class="mono"><?= $r['ms'] ?>ms</td>
                <td style="color:var(--muted);font-size:11px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= htmlspecialchars($r['msg']) ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Missing Files Summary -->
<?php
$missing = array_merge(
    array_filter($coreResults, fn($r) => !$r['exists']),
    array_filter($tmplResults, fn($r) => !$r['exists']),
    array_filter($apiResults,  fn($r) => !$r['exists'])
);
$failedTables = array_filter($dbResults, fn($r) => !$r['ok']);
if (count($missing) > 0 || count($failedTables) > 0):
?>
<div class="section">
    <div class="section-title" style="color:var(--danger)">Issues Found (<?= count($missing) + count($failedTables) ?>)</div>
    <div class="tbl-wrap">
        <table>
            <thead><tr><th>Type</th><th>Name</th><th>Fix</th></tr></thead>
            <tbody>
            <?php foreach ($missing as $r): ?>
            <tr>
                <td><span class="pill p-warn">Missing File</span></td>
                <td class="mono path-cell"><?= htmlspecialchars($r['path']) ?></td>
                <td style="color:var(--muted);font-size:11px;">Create file or check git status</td>
            </tr>
            <?php endforeach; ?>
            <?php foreach ($failedTables as $r): ?>
            <tr>
                <td><span class="pill p-fail">DB Table</span></td>
                <td class="mono"><?= htmlspecialchars($r['table']) ?></td>
                <td style="color:var(--muted);font-size:11px;">Run migration SQL</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

</div><!-- /wrap -->

<script>
function showTab(name) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    event.currentTarget.classList.add('active');
}
</script>
</body>
</html>
