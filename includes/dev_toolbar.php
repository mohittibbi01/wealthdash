<?php
/**
 * WealthDash — Dev Toolbar [t416]
 * File: includes/dev_toolbar.php
 * Worker: ID-M
 *
 * Usage: In templates/layout.php just before </body>:
 *   <?php if (IS_LOCAL) include APP_ROOT . '/includes/dev_toolbar.php'; ?>
 *
 * Shows: DB query count, memory, execution time, session info,
 *        recent errors, cache stats, current user, last SQL queries.
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');
if (!IS_LOCAL) return; // Hard guard — never render on production

$_wd_tb_start = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
$_wd_tb_mem   = memory_get_usage(true);
$_wd_tb_peak  = memory_get_peak_usage(true);
$_wd_tb_ms    = round((microtime(true) - $_wd_tb_start) * 1000, 2);

// Gather quick stats
$_wd_tb_errors = 0;
try {
    $_wd_tb_errors = (int) DB::fetchVal("SELECT COUNT(*) FROM error_events WHERE is_resolved=0");
} catch (Exception $e) {}

$_wd_tb_session = [
    'user_id'   => $_SESSION['user_id']   ?? null,
    'user_name' => $_SESSION['user_name'] ?? null,
    'user_role' => $_SESSION['user_role'] ?? null,
    'theme'     => $_SESSION['user_theme'] ?? null,
    'last_act'  => isset($_SESSION['_last_activity'])
                   ? date('H:i:s', $_SESSION['_last_activity']) : null,
];

$_wd_tb_db_status = 'OK';
try { DB::fetchVal('SELECT 1'); } catch (Exception $e) { $_wd_tb_db_status = 'ERROR'; }

$_wd_tb_php = PHP_VERSION;
$_wd_tb_env = APP_ENV;
$_wd_tb_uri = $_SERVER['REQUEST_URI'] ?? '';
?>
<style>
#wdtb{position:fixed;bottom:0;left:0;right:0;z-index:99999;font-family:'Courier New',monospace;
      font-size:11px;line-height:1;background:#0d0f18;border-top:2px solid #7c6fcd;
      color:#e2e5f5;transition:height .2s}
#wdtb.wdtb-collapsed{height:28px;overflow:hidden}
#wdtb-bar{display:flex;align-items:center;gap:0;height:28px;padding:0 8px;cursor:pointer;
          background:#161922;border-bottom:1px solid #2a2f4a}
.wdtb-chip{padding:3px 10px;border-right:1px solid #2a2f4a;white-space:nowrap;
           display:flex;align-items:center;gap:4px;height:28px}
.wdtb-chip span{color:#7b84a8}
.wdtb-ok{color:#4fc3a1}
.wdtb-warn{color:#e6a817}
.wdtb-err{color:#e05c5c}
.wdtb-acc{color:#7c6fcd}
#wdtb-body{padding:12px 16px;overflow:auto;max-height:320px;display:grid;
           grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}
.wdtb-section{background:#1e2235;border:1px solid #2a2f4a;border-radius:6px;padding:10px 12px}
.wdtb-title{font-size:10px;font-weight:700;color:#7b84a8;text-transform:uppercase;
            letter-spacing:.07em;margin-bottom:8px}
.wdtb-row{display:flex;justify-content:space-between;padding:3px 0;
          border-bottom:1px solid #1a1e30;font-size:11px}
.wdtb-row:last-child{border-bottom:none}
.wdtb-key{color:#7b84a8}
.wdtb-val{color:#e2e5f5;max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
#wdtb-toggle-btn{margin-left:auto;padding:2px 10px;border:1px solid #2a2f4a;
                  border-radius:4px;background:transparent;color:#7b84a8;cursor:pointer;font-size:10px}
#wdtb-toggle-btn:hover{background:#252a42;color:#e2e5f5}
</style>

<div id="wdtb" class="wdtb-collapsed">
    <div id="wdtb-bar" onclick="wdtbToggle()">
        <div class="wdtb-chip">
            <span>WD DEV</span>
            <span style="color:#7c6fcd;font-weight:700"><?= APP_ENV ?></span>
        </div>
        <div class="wdtb-chip">
            <span>Time:</span>
            <span class="<?= $_wd_tb_ms > 500 ? 'wdtb-err' : ($_wd_tb_ms > 200 ? 'wdtb-warn' : 'wdtb-ok') ?>">
                <?= $_wd_tb_ms ?>ms
            </span>
        </div>
        <div class="wdtb-chip">
            <span>Mem:</span>
            <span class="<?= $_wd_tb_mem > 32*1048576 ? 'wdtb-warn' : 'wdtb-ok' ?>">
                <?= round($_wd_tb_mem/1048576, 1) ?>MB
            </span>
        </div>
        <div class="wdtb-chip">
            <span>DB:</span>
            <span class="<?= $_wd_tb_db_status === 'OK' ? 'wdtb-ok' : 'wdtb-err' ?>"><?= $_wd_tb_db_status ?></span>
        </div>
        <div class="wdtb-chip">
            <span>PHP:</span>
            <span><?= $_wd_tb_php ?></span>
        </div>
        <?php if ($_wd_tb_errors > 0): ?>
        <div class="wdtb-chip">
            <span>Errors:</span>
            <span class="wdtb-err"><?= $_wd_tb_errors ?> open</span>
        </div>
        <?php endif; ?>
        <div class="wdtb-chip">
            <span>User:</span>
            <span class="wdtb-acc"><?= htmlspecialchars($_wd_tb_session['user_name'] ?? 'guest') ?></span>
        </div>
        <div class="wdtb-chip" style="flex:1;overflow:hidden">
            <span>URI:</span>
            <span style="color:#7b84a8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:300px">
                <?= htmlspecialchars($_wd_tb_uri) ?>
            </span>
        </div>
        <button id="wdtb-toggle-btn" onclick="event.stopPropagation();wdtbToggle()">▲</button>
    </div>

    <div id="wdtb-body">
        <!-- PHP & Request -->
        <div class="wdtb-section">
            <div class="wdtb-title">Request</div>
            <div class="wdtb-row"><span class="wdtb-key">Time</span><span class="wdtb-val"><?= $_wd_tb_ms ?>ms</span></div>
            <div class="wdtb-row"><span class="wdtb-key">Memory</span><span class="wdtb-val"><?= round($_wd_tb_mem/1048576, 2) ?> MB</span></div>
            <div class="wdtb-row"><span class="wdtb-key">Peak Mem</span><span class="wdtb-val"><?= round($_wd_tb_peak/1048576, 2) ?> MB</span></div>
            <div class="wdtb-row"><span class="wdtb-key">Method</span><span class="wdtb-val"><?= htmlspecialchars($_SERVER['REQUEST_METHOD'] ?? '') ?></span></div>
            <div class="wdtb-row"><span class="wdtb-key">URI</span><span class="wdtb-val" title="<?= htmlspecialchars($_wd_tb_uri) ?>"><?= htmlspecialchars(substr($_wd_tb_uri, 0, 40)) ?></span></div>
            <div class="wdtb-row"><span class="wdtb-key">PHP</span><span class="wdtb-val"><?= PHP_VERSION ?></span></div>
            <div class="wdtb-row"><span class="wdtb-key">Environment</span><span class="wdtb-val wdtb-acc"><?= APP_ENV ?></span></div>
        </div>

        <!-- Session -->
        <div class="wdtb-section">
            <div class="wdtb-title">Session</div>
            <div class="wdtb-row"><span class="wdtb-key">User ID</span><span class="wdtb-val"><?= $_wd_tb_session['user_id'] ?? 'guest' ?></span></div>
            <div class="wdtb-row"><span class="wdtb-key">Name</span><span class="wdtb-val"><?= htmlspecialchars($_wd_tb_session['user_name'] ?? '—') ?></span></div>
            <div class="wdtb-row"><span class="wdtb-key">Role</span><span class="wdtb-val wdtb-acc"><?= htmlspecialchars($_wd_tb_session['user_role'] ?? '—') ?></span></div>
            <div class="wdtb-row"><span class="wdtb-key">Theme</span><span class="wdtb-val"><?= htmlspecialchars($_wd_tb_session['theme'] ?? '—') ?></span></div>
            <div class="wdtb-row"><span class="wdtb-key">Last Activity</span><span class="wdtb-val"><?= $_wd_tb_session['last_act'] ?? '—' ?></span></div>
            <div class="wdtb-row"><span class="wdtb-key">Session ID</span><span class="wdtb-val" style="font-size:9px"><?= substr(session_id(), 0, 16) ?>...</span></div>
        </div>

        <!-- Database -->
        <div class="wdtb-section">
            <div class="wdtb-title">Database</div>
            <?php
            try {
                $dbVer  = DB::fetchVal('SELECT VERSION()');
                $dbSize = DB::fetchVal("SELECT ROUND(SUM(data_length+index_length)/1048576,1) FROM information_schema.tables WHERE table_schema=DATABASE()");
                $dbTbl  = DB::fetchVal("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()");
                $dbConn = DB::fetchOne("SHOW STATUS LIKE 'Threads_connected'")['Value'] ?? '?';
                $dbSlow = DB::fetchOne("SHOW STATUS LIKE 'Slow_queries'")['Value'] ?? '?';
            } catch (Exception $e) { $dbVer = 'Error'; $dbSize=$dbTbl=$dbConn=$dbSlow='—'; }
            ?>
            <div class="wdtb-row"><span class="wdtb-key">Status</span><span class="wdtb-val wdtb-ok"><?= $_wd_tb_db_status ?></span></div>
            <div class="wdtb-row"><span class="wdtb-key">Version</span><span class="wdtb-val"><?= htmlspecialchars((string)$dbVer) ?></span></div>
            <div class="wdtb-row"><span class="wdtb-key">Size</span><span class="wdtb-val"><?= $dbSize ?> MB</span></div>
            <div class="wdtb-row"><span class="wdtb-key">Tables</span><span class="wdtb-val"><?= $dbTbl ?></span></div>
            <div class="wdtb-row"><span class="wdtb-key">Connections</span><span class="wdtb-val"><?= $dbConn ?></span></div>
            <div class="wdtb-row"><span class="wdtb-key">Slow Queries</span><span class="wdtb-val <?= (int)$dbSlow > 5 ? 'wdtb-warn' : '' ?>"><?= $dbSlow ?></span></div>
        </div>

        <!-- Error Monitor -->
        <div class="wdtb-section">
            <div class="wdtb-title">Error Monitor</div>
            <?php
            try {
                $errOpen  = (int) DB::fetchVal("SELECT COUNT(*) FROM error_events WHERE is_resolved=0");
                $errTotal = (int) DB::fetchVal("SELECT COUNT(*) FROM error_events");
                $errLast  = DB::fetchVal("SELECT last_seen FROM error_events ORDER BY last_seen DESC LIMIT 1");
            } catch (Exception $e) { $errOpen=$errTotal=0; $errLast='—'; }
            ?>
            <div class="wdtb-row"><span class="wdtb-key">Open Errors</span>
                <span class="wdtb-val <?= $errOpen > 0 ? 'wdtb-err' : 'wdtb-ok' ?>"><?= $errOpen ?></span></div>
            <div class="wdtb-row"><span class="wdtb-key">Total Events</span><span class="wdtb-val"><?= $errTotal ?></span></div>
            <div class="wdtb-row"><span class="wdtb-key">Last Seen</span><span class="wdtb-val"><?= $errLast ? date('H:i:s', strtotime((string)$errLast)) : '—' ?></span></div>
            <div class="wdtb-row" style="margin-top:6px">
                <a href="/debug/runner.php?suite=all" style="color:#7c6fcd;text-decoration:none;font-size:10px">▶ Run Tests</a>
                <a href="?suite=perf" style="color:#4fc3a1;text-decoration:none;font-size:10px">⚡ Perf Tests</a>
            </div>
        </div>

        <!-- Quick Links -->
        <div class="wdtb-section">
            <div class="wdtb-title">Quick Links</div>
            <?php $links = [
                ['Debug Dashboard',   '/debug/debug.php'],
                ['Test Runner',       '/debug/runner.php'],
                ['Perf Tests',        '/debug/runner.php?suite=perf'],
                ['System Health',     '/?page=admin_health'],
                ['DB Manager',        '/?page=admin_db'],
                ['Audit Log',         '/?page=admin_users'],
                ['Error Monitor API', '/api/router.php?action=err_list'],
            ]; foreach ($links as [$label, $url]): ?>
            <div class="wdtb-row">
                <a href="<?= htmlspecialchars($url) ?>" style="color:#7c6fcd;text-decoration:none" target="_blank">
                    <?= htmlspecialchars($label) ?>
                </a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
function wdtbToggle() {
    const tb  = document.getElementById('wdtb');
    const btn = document.getElementById('wdtb-toggle-btn');
    const collapsed = tb.classList.toggle('wdtb-collapsed');
    btn.textContent = collapsed ? '▲' : '▼';
    localStorage.setItem('wdtb_open', collapsed ? '0' : '1');
}
// Restore state
if (localStorage.getItem('wdtb_open') === '1') {
    document.getElementById('wdtb').classList.remove('wdtb-collapsed');
    document.getElementById('wdtb-toggle-btn').textContent = '▼';
}
</script>
