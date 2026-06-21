<?php
/**
 * WealthDash — GODMODE Status Dashboard [t500]
 * File: debug/godmode.php
 * Worker: ID-M
 * Shows completion status of all major modules.
 */
define('WEALTHDASH', true);
require_once dirname(__DIR__) . '/config/config.php';

if (!IS_LOCAL && (!isset($_SESSION['user_role']) || $_SESSION['user_role'] !== 'admin')) {
    http_response_code(403); die('Admin only.');
}

// ── Module completion map ─────────────────────────────────────────────────────
$modules = [
    'Core Infrastructure' => [
        ['t23',  'Debug File — All Pages/API Check',         'api'],
        ['t50',  'Multi-user Management',                    'api'],
        ['t51',  'System Health Dashboard',                  'api'],
        ['t52',  'Global Settings Control',                  'api'],
        ['t53',  'DB Manager Tab',                           'api'],
    ],
    'Asset Trackers' => [
        ['t43',  'Bank Accounts Tracker',                    'api'],
        ['t307', 'Audit Log — User Action History',          'api'],
        ['t308', 'System Performance Monitor',               'api'],
        ['t335', 'API for External Tools',                   'api'],
        ['t336', 'Data Versioning — Undo Import',            'api'],
    ],
    'Testing & Quality' => [
        ['t352', 'Unit Tests — PHP + JS',                    'test'],
        ['t353', 'Integration Tests',                        'test'],
        ['t354', 'E2E Tests',                                'test'],
        ['t411', 'Automated Test Suite',                     'test'],
        ['t412', 'Performance Tests',                        'test'],
        ['t414', 'Error Monitoring',                         'api'],
        ['t415', 'Load Testing',                             'test'],
        ['t416', 'Dev Toolbar',                              'tool'],
        ['t418', 'API Documentation',                        'docs'],
        ['t419', 'Code Quality Tools',                       'tool'],
        ['t420', 'Deployment Guide & Docker',                'docs'],
    ],
    'Milestone' => [
        ['t500', 'WealthDash GODMODE — All Features',        'milestone'],
    ],
];

// ── File existence checks ─────────────────────────────────────────────────────
$fileChecks = [
    't23'  => 'debug/debug.php',
    't43'  => 'api/banks/banks.php',
    't50'  => 'api/admin/multi_user.php',
    't51'  => 'api/admin/system_health.php',
    't52'  => 'api/admin/global_settings.php',
    't53'  => 'api/admin/db_manager.php',
    't307' => 'api/admin/audit_log.php',
    't308' => 'api/admin/perf_monitor.php',
    't335' => 'api/external/api_key_manager.php',
    't336' => 'api/admin/data_versioning.php',
    't352' => 'tests/unit/test_helpers.php',
    't353' => 'tests/integration/test_crud_flows.php',
    't354' => 'tests/e2e/test_e2e.php',
    't411' => 'debug/runner.php',
    't412' => 'debug/tests/test_perf.php',
    't414' => 'includes/error_monitor.php',
    't415' => 'debug/load_test.php',
    't416' => 'includes/dev_toolbar.php',
    't418' => 'docs/API_REFERENCE.md',
    't419' => 'debug/code_quality.php',
    't420' => 'Dockerfile',
    't500' => 'debug/godmode.php',
];

$completedCount = 0;
$totalCount     = 0;
foreach ($modules as $group => $tasks) {
    foreach ($tasks as $task) {
        $totalCount++;
        $file = $fileChecks[$task[0]] ?? null;
        if ($file && file_exists(APP_ROOT . '/' . $file)) $completedCount++;
    }
}

$pct = $totalCount ? round($completedCount / $totalCount * 100) : 0;

// ── Live checks ───────────────────────────────────────────────────────────────
$liveChecks = [];
try {
    $liveChecks['db']           = (bool) DB::fetchVal('SELECT 1');
    $liveChecks['users']        = (int)  DB::fetchVal('SELECT COUNT(*) FROM users');
    $liveChecks['error_events'] = (int)  DB::fetchVal("SELECT COUNT(*) FROM error_events WHERE is_resolved=0");
    $liveChecks['audit_rows']   = (int)  DB::fetchVal('SELECT COUNT(*) FROM audit_log');
    $liveChecks['perf_rows']    = (int)  DB::fetchVal('SELECT COUNT(*) FROM perf_request_log');
    $liveChecks['api_keys']     = (int)  DB::fetchVal('SELECT COUNT(*) FROM external_api_keys WHERE is_active=1');
    $liveChecks['settings']     = (int)  DB::fetchVal('SELECT COUNT(*) FROM app_settings');
    $liveChecks['import_versions'] = (int) DB::fetchVal("SELECT COUNT(*) FROM import_versions WHERE status='active'");
    $liveChecks['load_test_runs']  = (int) DB::fetchVal('SELECT COUNT(*) FROM load_test_runs');
    $liveChecks['test_log_rows']   = (int) DB::fetchVal('SELECT COUNT(*) FROM test_run_log');
} catch (Exception $e) {
    $liveChecks['error'] = $e->getMessage();
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>WealthDash GODMODE ✦</title>
<style>
:root{--bg:#0d0f18;--s:#161922;--s2:#1e2235;--border:#2a2f4a;--text:#e2e5f5;
      --muted:#7b84a8;--done:#4fc3a1;--danger:#e05c5c;--warn:#e6a817;--accent:#7c6fcd;--r:8px}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui;background:var(--bg);color:var(--text);font-size:13px}
.hdr{padding:20px 24px;background:linear-gradient(135deg,#0d0f18 0%,#1a1232 100%);
     border-bottom:1px solid var(--accent);text-align:center}
.hdr h1{font-size:28px;font-weight:800;letter-spacing:.04em;
        background:linear-gradient(90deg,#7c6fcd,#4fc3a1,#e6a817);
        -webkit-background-clip:text;-webkit-text-fill-color:transparent}
.hdr p{color:var(--muted);font-size:12px;margin-top:6px}
.wrap{padding:20px 24px;max-width:1100px;margin:0 auto}
.progress-ring{text-align:center;margin-bottom:24px}
.big-pct{font-size:64px;font-weight:800;color:<?= $pct === 100 ? 'var(--done)' : 'var(--accent)' ?>;line-height:1}
.big-sub{font-size:14px;color:var(--muted);margin-top:4px}
.prog{height:8px;background:var(--s2);border-radius:4px;margin:12px 0;overflow:hidden}
.prog-i{height:8px;border-radius:4px;background:linear-gradient(90deg,var(--accent),var(--done));
        transition:width .8s}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:16px;margin-bottom:20px}
.module{background:var(--s);border:1px solid var(--border);border-radius:var(--r)}
.mod-hdr{padding:10px 14px;border-bottom:1px solid var(--border);font-weight:700;font-size:12px;
         display:flex;align-items:center;justify-content:space-between}
.task-row{display:flex;align-items:center;gap:8px;padding:6px 14px;
          border-bottom:1px solid color-mix(in srgb,var(--border) 40%,transparent);font-size:12px}
.task-row:last-child{border-bottom:none}
.tid{font-family:'Courier New',monospace;font-size:10px;color:var(--muted);min-width:38px}
.check{font-size:14px}
.live-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:8px;margin-bottom:20px}
.lc{background:var(--s);border:1px solid var(--border);border-radius:6px;padding:8px 12px}
.lc-v{font-size:18px;font-weight:700}
.lc-l{font-size:10px;color:var(--muted);text-transform:uppercase}
.godmode-banner{text-align:center;padding:24px;background:linear-gradient(135deg,#0d1a0d,#0d0f18);
               border:1px solid var(--done);border-radius:var(--r);margin-bottom:20px}
.godmode-banner h2{font-size:22px;color:var(--done);font-weight:800;letter-spacing:.06em}
.godmode-banner p{color:var(--muted);font-size:12px;margin-top:6px}
</style>
</head>
<body>
<div class="hdr">
    <h1>✦ WealthDash GODMODE ✦</h1>
    <p>All features milestone · ID-M Worker · Sessions t23 → t500</p>
</div>
<div class="wrap">

<!-- Overall progress -->
<div class="progress-ring">
    <div class="big-pct"><?= $pct ?>%</div>
    <div class="big-sub"><?= $completedCount ?> of <?= $totalCount ?> tasks complete</div>
    <div class="prog"><div class="prog-i" style="width:<?= $pct ?>%"></div></div>
</div>

<?php if ($pct === 100): ?>
<div class="godmode-banner">
    <h2>🏆 GODMODE ACHIEVED</h2>
    <p>All <?= $totalCount ?> tasks complete · WealthDash is fully operational</p>
</div>
<?php endif; ?>

<!-- Live stats -->
<div class="live-grid">
    <div class="lc"><div class="lc-v" style="color:<?= ($liveChecks['db'] ?? false) ? 'var(--done)' : 'var(--danger)' ?>">
        <?= ($liveChecks['db'] ?? false) ? 'OK' : 'ERR' ?></div><div class="lc-l">DB Status</div></div>
    <div class="lc"><div class="lc-v"><?= $liveChecks['users'] ?? '—' ?></div><div class="lc-l">Users</div></div>
    <div class="lc"><div class="lc-v" style="color:<?= ($liveChecks['error_events'] ?? 0) > 0 ? 'var(--danger)' : 'var(--done)' ?>">
        <?= $liveChecks['error_events'] ?? '—' ?></div><div class="lc-l">Open Errors</div></div>
    <div class="lc"><div class="lc-v"><?= number_format($liveChecks['audit_rows'] ?? 0) ?></div><div class="lc-l">Audit Rows</div></div>
    <div class="lc"><div class="lc-v"><?= number_format($liveChecks['perf_rows'] ?? 0) ?></div><div class="lc-l">Perf Samples</div></div>
    <div class="lc"><div class="lc-v"><?= $liveChecks['api_keys'] ?? 0 ?></div><div class="lc-l">Active API Keys</div></div>
    <div class="lc"><div class="lc-v"><?= $liveChecks['settings'] ?? 0 ?></div><div class="lc-l">App Settings</div></div>
    <div class="lc"><div class="lc-v"><?= $liveChecks['test_log_rows'] ?? 0 ?></div><div class="lc-l">Test Runs Logged</div></div>
</div>

<!-- Module breakdown -->
<div class="grid">
<?php foreach ($modules as $groupName => $tasks):
    $done = 0;
    foreach ($tasks as $t) {
        $f = $fileChecks[$t[0]] ?? null;
        if ($f && file_exists(APP_ROOT . '/' . $f)) $done++;
    }
    $gpct = count($tasks) ? round($done / count($tasks) * 100) : 0;
?>
<div class="module">
    <div class="mod-hdr">
        <span><?= htmlspecialchars($groupName) ?></span>
        <span style="color:<?= $gpct===100?'var(--done)':($gpct>50?'var(--warn)':'var(--muted)') ?>;font-size:11px">
            <?= $done ?>/<?= count($tasks) ?>
        </span>
    </div>
    <?php foreach ($tasks as [$tid, $label, $type]):
        $f      = $fileChecks[$tid] ?? null;
        $exists = $f && file_exists(APP_ROOT . '/' . $f);
    ?>
    <div class="task-row">
        <span class="check"><?= $exists ? '✅' : '⬜' ?></span>
        <span class="tid"><?= $tid ?></span>
        <span style="flex:1;<?= !$exists ? 'color:var(--muted)' : '' ?>"><?= htmlspecialchars($label) ?></span>
        <span style="font-size:9px;color:var(--muted)"><?= $type ?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>
</div>

<!-- Quick links -->
<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:20px">
    <?php $links = [
        ['Debug Dashboard', '/debug/debug.php'],
        ['Test Runner', '/debug/runner.php?suite=all'],
        ['Load Tests', '/debug/load_test.php'],
        ['Code Quality', '/debug/code_quality.php'],
        ['API Docs', '/docs/API_REFERENCE.md'],
        ['System Health', '/api/router.php?action=health_full'],
        ['Error Monitor', '/api/router.php?action=err_list'],
    ];
    foreach ($links as [$label, $url]): ?>
    <a href="<?= htmlspecialchars($url) ?>"
       style="padding:6px 14px;background:var(--s2);border:1px solid var(--border);border-radius:6px;
              color:var(--accent);text-decoration:none;font-size:12px">
        <?= htmlspecialchars($label) ?>
    </a>
    <?php endforeach; ?>
</div>

<div style="text-align:center;color:var(--muted);font-size:11px;padding-bottom:20px">
    WealthDash · Worker ID-M · <?= date('d M Y H:i') ?>
</div>
</div>
</body>
</html>
