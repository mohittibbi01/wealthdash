<?php
/**
 * WealthDash — GodMode Debug
 * Path: wealthdash/gmu_debug.php
 * Open this in browser to diagnose why processor won't spawn
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
if (!is_logged_in() || !is_admin()) { header('Location: /wealthdash/login.php'); exit; }

header('Content-Type: text/html; charset=utf-8');

$php     = PHP_BINARY;
$script  = __DIR__ . '/gmu_processor.php';
$logFile = __DIR__ . '/gmu_debug_output.txt';

$info = [
    'PHP_BINARY'     => $php,
    'PHP_OS_FAMILY'  => PHP_OS_FAMILY,
    'PHP_SAPI'       => php_sapi_name(),
    'script_exists'  => file_exists($script) ? 'YES ✓' : 'NO ✗',
    'script_path'    => $script,
    'cwd'            => getcwd(),
    'disable_functions' => ini_get('disable_functions') ?: '(none)',
    'exec_available' => function_exists('exec') ? 'YES ✓' : 'NO ✗ — BLOCKED',
    'popen_available'=> function_exists('popen') ? 'YES ✓' : 'NO ✗ — BLOCKED',
    'shell_exec_avail'=> function_exists('shell_exec') ? 'YES ✓' : 'NO ✗ — BLOCKED',
];

$action = $_GET['action'] ?? '';

if ($action === 'test_spawn') {
    // Try to spawn processor and check if it actually runs
    $cmd = "\"" . $php . "\" \"" . $script . "\" 4";
    
    // Write test marker before spawn
    file_put_contents($logFile, "[" . date('H:i:s') . "] Spawn attempt: {$cmd}\n");
    
    // Try different spawn methods
    $method = $_GET['method'] ?? 'exec';
    
    if ($method === 'exec') {
        exec("{$cmd} > \"" . $logFile . "\" 2>&1 &", $out, $ret);
        $result = "exec() called. Return code: {$ret}. Output: " . implode("\n", $out);
    } elseif ($method === 'popen') {
        $ph = popen("start /B {$cmd}", 'r');
        $result = $ph ? "popen() success, handle opened" : "popen() FAILED";
        if ($ph) pclose($ph);
    } elseif ($method === 'shell_exec') {
        $out = shell_exec("{$cmd} 2>&1");
        $result = "shell_exec output: " . ($out ?: '(empty)');
    } elseif ($method === 'proc_open') {
        $desc = [0=>['pipe','r'], 1=>['file',$logFile,'w'], 2=>['file',$logFile,'a']];
        $proc = proc_open($cmd, $desc, $pipes);
        if ($proc) {
            $status = proc_get_status($proc);
            $result = "proc_open() success! PID: " . $status['pid'];
            // Don't wait — let it run
        } else {
            $result = "proc_open() FAILED";
        }
    }
    
    // Wait 2s then check log
    sleep(2);
    $log = file_exists($logFile) ? file_get_contents($logFile) : '(no log file created)';
    
    echo "<h2>Spawn Test — Method: {$method}</h2>";
    echo "<pre style='background:#f5f5f5;padding:12px;border-radius:6px'>";
    echo "Command: " . htmlspecialchars($cmd) . "\n\n";
    echo "Result: " . htmlspecialchars($result) . "\n\n";
    echo "Log file output (after 2s):\n" . htmlspecialchars($log);
    echo "</pre>";
    echo "<hr><a href='gmu_debug.php'>← Back</a>";
    exit;
}

if ($action === 'test_db') {
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=wealthdash;charset=utf8mb4','root','',
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        
        $status = $pdo->query("SELECT setting_key, setting_val FROM app_settings WHERE setting_key IN ('gmu_stop','gmu_status')")->fetchAll(PDO::FETCH_KEY_PAIR);
        $cols   = [];
        foreach ($pdo->query("SHOW COLUMNS FROM nav_download_progress") as $c) $cols[] = $c['Field'];
        $counts = $pdo->query("SELECT status, COUNT(*) as cnt FROM nav_download_progress GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<h2>DB Status</h2><pre style='background:#f5f5f5;padding:12px;border-radius:6px'>";
        echo "app_settings:\n";
        foreach ($status as $k => $v) echo "  {$k} = {$v}\n";
        echo "\nnav_download_progress columns:\n  " . implode(', ', $cols) . "\n";
        echo "\nStatus counts:\n";
        foreach ($counts as $r) echo "  {$r['status']}: {$r['cnt']}\n";
        echo "</pre><hr><a href='gmu_debug.php'>← Back</a>";
    } catch (Exception $e) {
        echo "<pre style='color:red'>DB Error: " . $e->getMessage() . "</pre>";
    }
    exit;
}

if ($action === 'fix_status') {
    try {
        $pdo = new PDO('mysql:host=localhost;dbname=wealthdash;charset=utf8mb4','root','',
            [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("INSERT INTO app_settings (setting_key,setting_val) VALUES('gmu_status','idle') ON DUPLICATE KEY UPDATE setting_val='idle'");
        $pdo->exec("INSERT INTO app_settings (setting_key,setting_val) VALUES('gmu_stop','0') ON DUPLICATE KEY UPDATE setting_val='0'");
        $pdo->exec("UPDATE nav_download_progress SET status='needs_update' WHERE status='in_progress'");
        echo "<pre style='color:green'>✓ Status reset to idle. in_progress → needs_update.</pre>";
    } catch (Exception $e) {
        echo "<pre style='color:red'>Error: " . $e->getMessage() . "</pre>";
    }
    echo "<hr><a href='gmu_debug.php'>← Back</a>";
    exit;
}
?>
<!DOCTYPE html>
<html><head><meta charset="UTF-8">
<title>GMU Debug · WealthDash</title>
<style>
body{font-family:monospace;padding:20px;background:#f0f2f7;color:#111}
h1{color:#2563eb;margin-bottom:4px}
.card{background:#fff;border:1px solid #e4e7f0;border-radius:8px;padding:16px;margin:12px 0}
table{border-collapse:collapse;width:100%}
td,th{text-align:left;padding:6px 10px;border-bottom:1px solid #eee;font-size:13px}
th{background:#f7f8fc;font-weight:700;color:#666}
.ok{color:#059669;font-weight:700} .fail{color:#dc2626;font-weight:700}
.btn{display:inline-block;padding:8px 16px;border-radius:6px;text-decoration:none;font-weight:600;font-size:13px;margin:4px}
.btn-blue{background:#2563eb;color:#fff} .btn-green{background:#059669;color:#fff}
.btn-red{background:#dc2626;color:#fff} .btn-gray{background:#6b7280;color:#fff}
</style>
</head>
<body>
<h1>⚡ GodMode Debug Panel</h1>
<p style="color:#6b7280;font-size:12px">Diagnose processor spawn issues</p>

<div class="card">
<h3>System Info</h3>
<table>
<?php foreach ($info as $k => $v): ?>
<tr>
  <th><?= $k ?></th>
  <td class="<?= str_contains($v,'✓') ? 'ok' : (str_contains($v,'✗') ? 'fail' : '') ?>">
    <?= htmlspecialchars($v) ?>
  </td>
</tr>
<?php endforeach; ?>
</table>
</div>

<div class="card">
<h3>Spawn Tests — try each method</h3>
<p style="font-size:12px;color:#666">Each test spawns gmu_processor.php and checks if it actually runs</p>
<a class="btn btn-blue" href="?action=test_spawn&method=exec">Test exec()</a>
<a class="btn btn-blue" href="?action=test_spawn&method=popen">Test popen()</a>
<a class="btn btn-blue" href="?action=test_spawn&method=shell_exec">Test shell_exec()</a>
<a class="btn btn-blue" href="?action=test_spawn&method=proc_open">Test proc_open() ⭐</a>
</div>

<div class="card">
<h3>DB Status Check</h3>
<a class="btn btn-green" href="?action=test_db">Check DB Status</a>
<a class="btn btn-red" href="?action=fix_status">Fix Stale Status (reset to idle)</a>
</div>

<div class="card">
<h3>Log File</h3>
<?php
if (file_exists($logFile)) {
    echo "<pre style='background:#f5f5f5;padding:10px;border-radius:4px;max-height:200px;overflow-y:auto'>";
    echo htmlspecialchars(file_get_contents($logFile));
    echo "</pre>";
} else {
    echo "<p style='color:#999'>No log file yet. Run a spawn test first.</p>";
}
?>
</div>

<p><a href="godmode_unified.php">← Back to GodMode</a></p>
</body>
</html>
