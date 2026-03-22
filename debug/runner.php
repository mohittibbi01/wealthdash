<?php
/**
 * WealthDash Debug Runner — debug/runner.php
 * ============================================================
 * Returns JSON: { results:[], summary:{pass,warn,fail,total} }
 *
 * Usage:
 *   Browser:  http://localhost/wealthdash/debug/runner.php
 *   Dev tasks: fetch('/wealthdash/debug/runner.php').then(r=>r.json())
 *
 * Optional query params:
 *   ?suite=db,files,ui,api,cron   run specific suites only
 *   ?format=html                  human-readable HTML instead of JSON
 * ============================================================
 */

// ── CORS headers — send FIRST, before session_start() or any output ───────
// Required: tasks HTML opens as file:// and fetches http://localhost
// These must be sent before config.php starts the session.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Bootstrap ─────────────────────────────────────────────────────────────
define('WD_DEBUG_RUNNER', true);
$BASE = dirname(__DIR__);

// Only allow from localhost for safety
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$allowed = ['127.0.0.1', '::1', '::ffff:127.0.0.1'];
if (!in_array($ip, $allowed) && !str_starts_with($ip, '192.168.') && !str_starts_with($ip, '10.')) {
    http_response_code(403);
    die(json_encode(['error' => 'Debug runner: localhost only']));
}

// ── Result collectors (defined FIRST so bootstrap errors can be recorded) ─
$WD_RESULTS   = [];
$WD_STARTTIME = microtime(true);

function wd_pass(string $cat, string $name, string $msg = ''): void {
    global $WD_RESULTS;
    $WD_RESULTS[] = ['status'=>'pass','category'=>$cat,'name'=>$name,'message'=>$msg];
}
function wd_fail(string $cat, string $name, string $msg = ''): void {
    global $WD_RESULTS;
    $WD_RESULTS[] = ['status'=>'fail','category'=>$cat,'name'=>$name,'message'=>$msg];
}
function wd_warn(string $cat, string $name, string $msg = ''): void {
    global $WD_RESULTS;
    $WD_RESULTS[] = ['status'=>'warn','category'=>$cat,'name'=>$name,'message'=>$msg];
}

// config/config.php has a guard: defined('WEALTHDASH') or die(...)
// Define it here so the runner is treated as a trusted internal entry point.
if (!defined('WEALTHDASH')) define('WEALTHDASH', true);

// config/config.php defines env(), loads .env file, starts session,
// and already require_once's database.php + helpers.php itself.
// So one include is all we need.
$bootstrapOk = false;
$configPath   = $BASE . '/config/config.php';
if (file_exists($configPath)) {
    require_once $configPath;
    $bootstrapOk = true;
} else {
    wd_fail('Bootstrap', 'config/config.php',
            'Not found — check XAMPP path. Expected: ' . $configPath);
}

// ── Suite selection ────────────────────────────────────────────────────────
$requestedSuites = isset($_GET['suite'])
    ? array_map('trim', explode(',', strtolower($_GET['suite'])))
    : ['db', 'files', 'ui', 'api', 'cron'];

$suites = [
    'db'    => 'tests/test_db.php',
    'files' => 'tests/test_api_files.php',
    'ui'    => 'tests/test_ui_elements.php',
    'api'   => 'tests/test_api_responses.php',
    'cron'  => 'tests/test_cron_health.php',
];

// Run a quick bootstrap check first
if (!$bootstrapOk) {
    wd_fail('Bootstrap', 'config/database.php', 'Cannot load DB config — check XAMPP + file paths');
    wd_fail('Bootstrap', 'includes/helpers.php', 'Cannot load helpers — check file exists');
}

// ── Run suites ────────────────────────────────────────────────────────────
foreach ($requestedSuites as $key) {
    if (!isset($suites[$key])) continue;
    $file = __DIR__ . '/' . $suites[$key];
    if (!file_exists($file)) {
        wd_warn("Suite: $key", $suites[$key], 'Test file not found');
        continue;
    }
    try {
        include $file;
    } catch (Throwable $e) {
        wd_fail("Suite: $key", $suites[$key], 'Suite crashed: ' . $e->getMessage() . ' on line ' . $e->getLine());
    }
}

// ── Summary ───────────────────────────────────────────────────────────────
$summary = ['pass'=>0,'warn'=>0,'fail'=>0,'total'=>count($WD_RESULTS)];
foreach ($WD_RESULTS as $r) $summary[$r['status']]++;
$elapsed = round((microtime(true) - $WD_STARTTIME) * 1000);

// ── Output ────────────────────────────────────────────────────────────────
if (isset($_GET['format']) && $_GET['format'] === 'html') {
    // Human-readable HTML report
    $title   = 'WealthDash Debug Report — ' . date('d M Y H:i:s');
    $sIco    = ['pass'=>'✅','warn'=>'⚠️','fail'=>'❌'];
    $sColor  = ['pass'=>'#16a34a','warn'=>'#ca8a04','fail'=>'#dc2626'];
    $sBg     = ['pass'=>'#f0fdf4','warn'=>'#fefce8','fail'=>'#fef2f2'];
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= $title ?></title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f0f4f8;color:#1e293b;padding:20px}
  h1{font-size:16px;font-weight:800;margin-bottom:14px;color:#1e293b}
  .summary{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap}
  .s-box{padding:10px 18px;border-radius:8px;font-size:13px;font-weight:700;border:1px solid}
  .s-pass{background:#f0fdf4;color:#16a34a;border-color:#bbf7d0}
  .s-warn{background:#fefce8;color:#ca8a04;border-color:#fde68a}
  .s-fail{background:#fef2f2;color:#dc2626;border-color:#fecaca}
  .s-info{background:#eff6ff;color:#2563eb;border-color:#bfdbfe}
  table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08)}
  th{background:#6366f1;color:#fff;padding:9px 14px;font-size:11px;text-align:left;text-transform:uppercase;letter-spacing:.4px}
  td{padding:7px 14px;font-size:12px;border-bottom:1px solid #f1f5f9;vertical-align:top}
  tr:last-child td{border-bottom:none}
  .pass{color:#16a34a}.warn{color:#ca8a04;font-weight:600}.fail{color:#dc2626;font-weight:700}
  .cat{font-size:10px;font-weight:700;background:#eef2ff;color:#6366f1;padding:1px 6px;border-radius:4px}
  .elapsed{font-size:11px;color:#94a3b8;margin-top:10px}
  .copy-btn{margin-top:14px;padding:8px 18px;background:#6366f1;color:#fff;border:none;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer}
  .copy-btn:hover{background:#4f46e5}
</style>
</head>
<body>
<h1>🔧 <?= htmlspecialchars($title) ?></h1>
<div class="summary">
  <div class="s-box s-pass">✅ Passed: <?= $summary['pass'] ?></div>
  <div class="s-box s-warn">⚠️ Warnings: <?= $summary['warn'] ?></div>
  <div class="s-box s-fail">❌ Failed: <?= $summary['fail'] ?></div>
  <div class="s-box s-info">📋 Total: <?= $summary['total'] ?> checks</div>
</div>
<table>
<tr><th>Status</th><th>Category</th><th>Check</th><th>Detail</th></tr>
<?php foreach ($WD_RESULTS as $r): ?>
<tr>
  <td class="<?= $r['status'] ?>"><?= $sIco[$r['status']] ?></td>
  <td><span class="cat"><?= htmlspecialchars($r['category']) ?></span></td>
  <td style="font-family:monospace;font-size:11px"><?= htmlspecialchars($r['name']) ?></td>
  <td><?= htmlspecialchars($r['message']) ?></td>
</tr>
<?php endforeach; ?>
</table>
<div class="elapsed">⏱ Completed in <?= $elapsed ?>ms</div>
<button class="copy-btn" id="copyJsonBtn" onclick="copyReport(this)">📋 Copy JSON Report (Claude ke liye)</button>
<button class="copy-btn" onclick="window.open(location.href.replace('format=html','format=txt'),'_blank')" style="background:#16a34a;margin-left:8px;">⬇️ Download .txt</button>
<script>
function copyReport(btn){
  const data = <?= json_encode([
    'generated' => date('Y-m-d H:i:s'),
    'summary'   => $summary,
    'elapsed_ms'=> $elapsed,
    'results'   => $WD_RESULTS,
  ]) ?>;
  // Build plain text report for Claude
  let out = 'WealthDash Debug Report — ' + data.generated + '\n';
  out += '='.repeat(60) + '\n';
  out += 'SUMMARY: Passed=' + data.summary.pass + ' | Warnings=' + data.summary.warn + ' | Failed=' + data.summary.fail + ' | Total=' + data.summary.total + '\n\n';
  const fails = data.results.filter(r=>r.status==='fail');
  const warns = data.results.filter(r=>r.status==='warn');
  if(fails.length){ out+='ERRORS:\n'; fails.forEach(r=>{ out+='  ❌ ['+r.category+'] '+r.name+(r.message?' — '+r.message:'')+'\n'; }); out+='\n'; }
  if(warns.length){ out+='WARNINGS:\n'; warns.forEach(r=>{ out+='  ⚠️ ['+r.category+'] '+r.name+(r.message?' — '+r.message:'')+'\n'; }); out+='\n'; }
  out += '---\nYeh report Claude ko do.\n';
  if(navigator.clipboard && navigator.clipboard.writeText){
    navigator.clipboard.writeText(out).then(()=>{
      btn.textContent='✅ Copied!'; btn.style.background='#16a34a';
      setTimeout(()=>{ btn.textContent='📋 Copy JSON Report (Claude ke liye)'; btn.style.background=''; },2500);
    }).catch(()=>{ fallbackCopy(out,btn); });
  } else { fallbackCopy(out,btn); }
}
function fallbackCopy(text,btn){
  const ta=document.createElement('textarea');
  ta.value=text; ta.style.position='fixed'; ta.style.opacity='0';
  document.body.appendChild(ta); ta.focus(); ta.select();
  try{ document.execCommand('copy'); btn.textContent='✅ Copied!'; setTimeout(()=>btn.textContent='📋 Copy JSON Report (Claude ke liye)',2500); }
  catch(e){ alert('Manual copy karo:\n\n'+text.slice(0,500)+'...'); }
  document.body.removeChild(ta);
}
</script>
</body>
</html>
<?php
} elseif (isset($_GET['format']) && $_GET['format'] === 'txt') {
    // Plain text download — easy to paste into Claude
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="wd_debug_' . date('Y-m-d') . '.txt"');
    $out  = "WealthDash Debug Report — " . date('d M Y H:i:s') . "\n";
    $out .= str_repeat('=', 60) . "\n";
    $out .= "SUMMARY: Passed={$summary['pass']} | Warnings={$summary['warn']} | Failed={$summary['fail']} | Total={$summary['total']}\n\n";
    $fails = array_filter($WD_RESULTS, fn($r) => $r['status'] === 'fail');
    $warns = array_filter($WD_RESULTS, fn($r) => $r['status'] === 'warn');
    $passes= array_filter($WD_RESULTS, fn($r) => $r['status'] === 'pass');
    if ($fails) { $out .= "ERRORS:\n"; foreach ($fails as $r) $out .= "  ❌ [{$r['category']}] {$r['name']}" . ($r['message'] ? " — {$r['message']}" : '') . "\n"; $out .= "\n"; }
    if ($warns) { $out .= "WARNINGS:\n"; foreach ($warns as $r) $out .= "  ⚠️  [{$r['category']}] {$r['name']}" . ($r['message'] ? " — {$r['message']}" : '') . "\n"; $out .= "\n"; }
    $out .= "PASSED (" . count(array_values($passes)) . "):\n";
    foreach ($passes as $r) $out .= "  ✅ [{$r['category']}] {$r['name']}\n";
    $out .= "\n---\nYeh report Claude ko do. Likho: \"WealthDash debug report hai, errors fix karo\"\n";
    echo $out;
} else {
    // JSON (default — used by tasks dashboard)
    header('Content-Type: application/json');
    // Note: Access-Control-Allow-Origin already sent at top of file
    echo json_encode([
        'generated'  => date('Y-m-d H:i:s'),
        'elapsed_ms' => $elapsed,
        'summary'    => $summary,
        'results'    => $WD_RESULTS,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}