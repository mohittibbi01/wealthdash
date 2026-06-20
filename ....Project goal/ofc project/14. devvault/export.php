<?php
require_once __DIR__ . '/auth.php';
require_login();

$db = get_db();

// ── Read and SANITIZE user preferences at read time (CSS injection fix) ───────
$accent  = preg_replace('/[^#a-fA-F0-9]/', '', user_pref('accent', '#00d4ff'));
if (empty($accent)) $accent = '#00d4ff';
$bg_raw  = user_pref('bg_color', '');
$bg      = $bg_raw ? '#' . preg_replace('/[^a-fA-F0-9]/', '', ltrim($bg_raw, '#')) : '';
$theme   = in_array(user_pref('theme', 'dark'), ['dark', 'light']) ? user_pref('theme', 'dark') : 'dark';
$fsize   = max(11, min(18, (int)user_pref('font_size', '14')));
$ffamily = in_array(user_pref('font_family', 'Rajdhani'), ['Rajdhani', 'Share Tech Mono', 'Orbitron'])
           ? user_pref('font_family', 'Rajdhani') : 'Rajdhani';

$format = $_GET['format'] ?? '';

// ── Viewer cannot export ──────────────────────────────────────────────────────
if (!can_edit() && $format) {
    header('Location: export.php?err=noperm'); exit;
}

$allProjects = $db->query("SELECT p.*,u.username as creator FROM projects p
    LEFT JOIN users u ON p.created_by=u.id ORDER BY p.project_name")->fetchAll();

// ── JSON export (CSRF protected) ─────────────────────────────────────────────
if ($format === 'json') {
    // CSRF check — must pass ?csrf=TOKEN in the URL
    $tok = $_GET['csrf'] ?? '';
    if (!$tok || !hash_equals(csrf_token(), $tok)) {
        http_response_code(403);
        exit('403 — CSRF validation failed. Use the export page buttons.');
    }
    foreach ($allProjects as &$p) {
        foreach (['local','staging','production','audit','other'] as $env) {
            $key = "env_{$env}_password";
            if (is_admin() && $p[$key]) $p[$key.'_plain'] = decrypt_val($p[$key]);
            unset($p[$key]);
        }
    }
    unset($p);
    log_activity('export_json');
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="devvault_'.date('Ymd_His').'.json"');
    echo json_encode(['exported_at'=>date('Y-m-d H:i:s'),'by'=>$_SESSION['username'],
                      'total'=>count($allProjects),'projects'=>$allProjects],
                     JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
    exit;
}

// ── CSV export (CSRF protected) ───────────────────────────────────────────────
if ($format === 'csv') {
    $tok = $_GET['csrf'] ?? '';
    if (!$tok || !hash_equals(csrf_token(), $tok)) {
        http_response_code(403);
        exit('403 — CSRF validation failed. Use the export page buttons.');
    }
    log_activity('export_csv');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="devvault_'.date('Ymd_His').'.csv"');
    $out = fopen('php://output','w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out,['#','Project Name','Department','Technology','Website/App','Status',
        'Nodal Officer','Contact','Email',
        'Local URL','Local Admin URL','Local ID','Local PW',
        'Staging URL','Staging Admin URL','Staging ID','Staging PW',
        'Production URL','Production Admin URL','Production ID','Production PW',
        'Audit URL','Audit Admin URL','Audit ID','Audit PW',
        'App IP','LB IP','App OS','Core','RAM','Primary Storage','Secondary Storage','Hosting',
        'DB IP','DB Name','DB Version','DB OS','DB Hosting',
        'Live Date','Last Audit','Visitors','General Remark','Creator']);
    $i=1;
    foreach ($allProjects as $p) {
        $pw = fn($env) => (is_admin()&&$p["env_{$env}_password"]) ? decrypt_val($p["env_{$env}_password"]) : '(hidden)';
        fputcsv($out,[
            $i++,$p['project_name'],$p['department_name'],
            $p['technology']==='Other'?$p['technology_other']:$p['technology'],
            $p['website_app'],$p['current_status'],
            $p['nodal_officer_name'],$p['nodal_contact'],$p['dept_email'],
            $p['env_local_url'],$p['env_local_admin_url'],$p['env_local_id'],$pw('local'),
            $p['env_staging_url'],$p['env_staging_admin_url'],$p['env_staging_id'],$pw('staging'),
            $p['env_production_url'],$p['env_production_admin_url'],$p['env_production_id'],$pw('production'),
            $p['env_audit_url'],$p['env_audit_admin_url'],$p['env_audit_id'],$pw('audit'),
            $p['app_ip'],$p['app_lb_ip'],
            $p['app_os']==='Other'?$p['app_os_other']:$p['app_os'],
            $p['app_core'],$p['app_ram'],$p['app_primary_storage'],$p['app_secondary_storage'],$p['app_hosting_type'],
            $p['db_ip'],$p['db_name'],
            $p['db_version']==='Other'?$p['db_version_other']:$p['db_version'],
            $p['db_os']==='Other'?$p['db_os_other']:$p['db_os'],$p['db_hosting_type'],
            $p['live_date'],$p['last_audit_date'],$p['total_visitor_counter'],
            $p['general_remark'],$p['creator']
        ]);
    }
    fclose($out); exit;
}

// ── Printable Report HTML ─────────────────────────────────────────────────────
if ($format === 'report') {
    $tok = $_GET['csrf'] ?? '';
    if (!$tok || !hash_equals(csrf_token(), $tok)) {
        http_response_code(403);
        exit('403 — CSRF validation failed. Use the export page buttons.');
    }
    log_activity('export_report');
    $statusLabels = ['request_received'=>'Request Received','live'=>'Live','under_development'=>'Under Dev','redevelopment'=>'Redevelopment','hold_by_department'=>'Hold by Dept','content_updation'=>'Content Updation','closed'=>'Closed'];
    $statusColors = ['request_received'=>'#90caf9','live'=>'#00e676','under_development'=>'#ffd740','redevelopment'=>'#40c4ff','hold_by_department'=>'#ff6e40','content_updation'=>'#bc8cff','closed'=>'#ff3d5a'];
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DevVault Pro — Export & Backup</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--accent:<?= $accent ?>;--fs:<?= $fsize ?>px;--bg:#070b14;--surface:#0d1422;--surface2:#111a2e;--surface3:#16213e;
  --border:#1e2d4a;--text:#e8edf5;--muted:#5a7a9a;--success:#00e676;--danger:#ff3d5a;--amber:#ffd740;--blue:#40c4ff;}
[data-theme="light"]{--bg:#f0f4f8;--surface:#fff;--surface2:#e8edf5;--surface3:#dde3ed;--border:#c8d4e0;--text:#0d1422;--muted:#5a7a9a;}
html{font-size:var(--fs)}
body{font-family:'<?= htmlspecialchars($ffamily) ?>',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
@media print{body{background:#fff;color:#000}.no-print{display:none}}
.wrap{max-width:900px;margin:0 auto;padding:20px}
h1{font-size:20px;margin-bottom:20px;color:var(--accent)}
table{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:20px}
th{background:var(--surface2);padding:8px;text-align:left;border:1px solid var(--border);font-family:'Courier New',Consolas,monospace;font-size:10px}
td{padding:7px 8px;border:1px solid var(--border);vertical-align:top}
tr:nth-child(even){background:rgba(255,255,255,.02)}
.btn{display:inline-flex;align-items:center;gap:5px;padding:8px 16px;border-radius:7px;font-size:13px;
  font-weight:600;font-family:'Segoe UI',Tahoma,Arial,sans-serif;cursor:pointer;border:none;text-decoration:none;transition:all .15s}
.btn-accent{background:var(--accent);color:#000}
.no-print{margin-bottom:16px}
</style>
</head>
<body>
<div class="wrap">
  <div class="no-print" style="display:flex;gap:10px;margin-bottom:20px">
    <button class="btn btn-accent" onclick="window.print()">🖨 Print / Save PDF</button>
    <a href="export.php" class="btn" style="background:var(--surface2);color:var(--text);border:1px solid var(--border)">← Back</a>
  </div>
  <h1>DevVault Pro — Project Report (<?= date('d M Y') ?>)</h1>
  <table>
    <tr>
      <th>#</th><th>Project</th><th>Department</th><th>Technology</th>
      <th>Status</th><th>Nodal Officer</th><th>Live Date</th><th>Last Audit</th>
    </tr>
    <?php foreach ($allProjects as $i => $p): ?>
    <tr>
      <td><?= $i+1 ?></td>
      <td><?= htmlspecialchars($p['project_name']) ?></td>
      <td><?= htmlspecialchars($p['department_name'] ?? '') ?></td>
      <td><?= htmlspecialchars($p['technology'] === 'Other' ? ($p['technology_other'] ?? '') : ($p['technology'] ?? '')) ?></td>
      <td style="color:<?= $statusColors[$p['current_status']] ?? '#888' ?>">
        <?= htmlspecialchars($statusLabels[$p['current_status']] ?? $p['current_status']) ?>
      </td>
      <td><?= htmlspecialchars($p['nodal_officer_name'] ?? '') ?></td>
      <td><?= htmlspecialchars($p['live_date'] ?? '') ?></td>
      <td><?= htmlspecialchars($p['last_audit_date'] ?? '') ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p style="font-size:11px;color:var(--muted);font-family:'Courier New',Consolas,monospace">
    Generated: <?= date('Y-m-d H:i:s') ?> by <?= htmlspecialchars($_SESSION['username']) ?> · Total: <?= count($allProjects) ?> projects
  </p>
</div>
</body>
</html>
<?php
    exit;
}

// ── Default landing page ──────────────────────────────────────────────────────
// Compute vars for the landing page info card
$total      = count($allProjects);
// Auto backup status (FE-07)
$backupDir   = __DIR__ . '/data/backups';
$lastBkFile  = $backupDir . '/.last_backup';
$lastBackup  = file_exists($lastBkFile)
               ? trim(file_get_contents($lastBkFile))
               : 'No auto-backup yet';
$backupCount = count(glob($backupDir . '/vault_*.db') ?: []);

// Build CSRF-signed export URLs
$csrf    = csrf_token();
$urlJson = 'export.php?format=json&csrf=' . urlencode($csrf);
$urlCsv  = 'export.php?format=csv&csrf='  . urlencode($csrf);
$urlRpt  = 'export.php?format=report&csrf=' . urlencode($csrf);

$err = $_GET['err'] ?? '';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?= htmlspecialchars($theme) ?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DevVault Pro — Export & Backup</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--accent:<?= $accent ?>;--fs:<?= $fsize ?>px;--bg:#070b14;--surface:#0d1422;--surface2:#111a2e;--surface3:#16213e;
  --border:#1e2d4a;--text:#e8edf5;--muted:#5a7a9a;--success:#00e676;--danger:#ff3d5a;--amber:#ffd740;--blue:#40c4ff;}
[data-theme="light"]{--bg:#f0f4f8;--surface:#fff;--surface2:#e8edf5;--surface3:#dde3ed;--border:#c8d4e0;--text:#0d1422;--muted:#5a7a9a;}
html{font-size:var(--fs)}
body{font-family:'<?= htmlspecialchars($ffamily) ?>',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
body::before{content:'';position:fixed;inset:0;
  background-image:linear-gradient(rgba(0,212,255,.018) 1px,transparent 1px),linear-gradient(90deg,rgba(0,212,255,.018) 1px,transparent 1px);
  background-size:40px 40px;pointer-events:none;z-index:0}
[data-theme="light"] body::before{opacity:.3}
.topbar{position:sticky;top:0;z-index:100;background:rgba(7,11,20,.95);border-bottom:1px solid var(--border);
  backdrop-filter:blur(12px);padding:0 20px;height:52px;display:flex;align-items:center;gap:10px}
[data-theme="light"] .topbar{background:rgba(240,244,248,.95)}
.logo-txt{font-family:'Courier New',Consolas,monospace;font-size:14px;font-weight:900;letter-spacing:2px;color:var(--accent);text-shadow:0 0 16px var(--accent)}
.btn{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:7px;font-size:12px;font-weight:600;
  font-family:'Segoe UI',Tahoma,Arial,sans-serif;cursor:pointer;border:none;text-decoration:none;transition:all .15s;white-space:nowrap}
.btn:active{transform:scale(.97)}
.btn-ghost{background:var(--surface2);color:var(--muted);border:1px solid var(--border)}.btn-ghost:hover{color:var(--text)}
.btn-accent{background:var(--accent);color:#000}.btn-accent:hover{opacity:.85}
.btn-sm{padding:4px 9px;font-size:11px}
.wrap{max-width:700px;margin:0 auto;padding:20px;position:relative;z-index:1}
.page-title{font-family:'Courier New',Consolas,monospace;font-size:16px;font-weight:700;color:var(--accent);text-shadow:0 0 12px var(--accent);margin-bottom:16px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:14px}
.card h2{font-family:'Courier New',Consolas,monospace;font-size:10px;text-transform:uppercase;letter-spacing:1.5px;
  color:var(--muted);padding:10px 16px;border-bottom:1px solid var(--border);background:var(--surface2)}
.info-row{display:flex;justify-content:space-between;padding:10px 16px;border-bottom:1px solid rgba(30,45,74,.4);font-size:13px}
.info-row:last-child{border-bottom:none}
.info-row .k{color:var(--muted);font-family:'Courier New',Consolas,monospace;font-size:11px}
.info-row .v{font-family:'Courier New',Consolas,monospace;font-weight:600}
.exp-opt{display:flex;align-items:center;gap:16px;padding:14px 16px;background:var(--surface2);
  border-bottom:1px solid var(--border);text-decoration:none;color:var(--text);transition:all .2s}
.exp-opt:last-child{border-bottom:none}
.exp-opt:hover{background:var(--surface3);padding-left:20px}
.exp-icon{font-size:28px;flex-shrink:0}
.exp-info h3{font-size:14px;font-weight:700;margin-bottom:3px}
.exp-info p{font-size:11px;font-family:'Courier New',Consolas,monospace;color:var(--muted)}
.badge{margin-left:auto;font-size:9px;font-family:'Courier New',Consolas,monospace;padding:3px 9px;border-radius:20px;font-weight:700;flex-shrink:0;border:1px solid currentColor}
.badge-g{background:rgba(0,230,118,.10);color:var(--success)}
.badge-a{background:rgba(255,215,64,.10);color:var(--amber)}
.badge-b{background:rgba(0,212,255,.10);color:var(--accent)}
.portable-steps{font-family:'Courier New',Consolas,monospace;font-size:12px;color:var(--muted);line-height:2;padding:14px 16px}
.portable-steps strong{color:var(--text)}
.err-bar{background:rgba(255,61,90,.08);border:1px solid rgba(255,61,90,.25);color:var(--danger);
  padding:10px 14px;border-radius:8px;font-size:12px;margin-bottom:14px;font-family:'Courier New',Consolas,monospace}
</style>
</head>
<body>
<?php $nav_active="export"; require_once __DIR__ . "/includes/navbar.php"; ?>
<div class="wrap">
  <div class="page-title">📤 EXPORT & BACKUP</div>

  <?php if ($err === 'noperm'): ?>
  <div class="err-bar">⛔ Access denied. Export requires Member or Admin role.</div>
  <?php endif; ?>

  <div class="card">
    <h2>Current Data</h2>
    <div class="info-row"><span class="k">Total Projects</span><span class="v"><?= (int)$total ?></span></div>
    <div class="info-row"><span class="k">Last Auto-Backup</span><span class="v"><?= htmlspecialchars($lastBackup) ?></span></div>
    <div class="info-row"><span class="k">Backup Files Kept</span><span class="v"><?= intval($backupCount) ?> / 7 daily</span></div>
    <div class="info-row"><span class="k">Backup Location</span><span class="v">data/backups/vault_YYYYMMDD.db</span></div>
    <div class="info-row" style="font-size:11px;color:var(--muted)"><span class="k">Schedule</span><span class="v">Windows Task Scheduler: php backup.php daily</span></div>
    <div class="info-row"><span class="k">Exported By</span><span class="v"><?= htmlspecialchars($_SESSION['username']) ?></span></div>
  </div>

  <div class="card">
    <h2>Download Options</h2>
    <?php if (can_edit()): ?>
    <a class="exp-opt" href="<?= htmlspecialchars($urlJson) ?>">
      <span class="exp-icon">📄</span>
      <div class="exp-info">
        <h3>JSON Export</h3>
        <p>Full backup — all fields, best for restore</p>
      </div>
      <span class="badge badge-g">Recommended</span>
    </a>
    <a class="exp-opt" href="<?= htmlspecialchars($urlCsv) ?>">
      <span class="exp-icon">📊</span>
      <div class="exp-info">
        <h3>CSV / Excel Export</h3>
        <p>Excel-compatible — UTF-8 BOM included</p>
      </div>
      <span class="badge badge-a">Excel Ready</span>
    </a>
    <a class="exp-opt" href="<?= htmlspecialchars($urlRpt) ?>" target="_blank">
      <span class="exp-icon">🖨</span>
      <div class="exp-info">
        <h3>Printable Report</h3>
        <p>HTML report — print ya PDF save karo</p>
      </div>
      <span class="badge badge-b">Printable</span>
    </a>
    <?php if (!is_admin()): ?>
    <div style="font-family:'Courier New',Consolas,monospace;font-size:11px;color:var(--amber);
      margin-top:10px;padding:10px;background:rgba(255,215,64,.05);border-radius:6px;
      border:1px solid rgba(255,215,64,.15)">
      ⚠ Non-admin: passwords exports mein hidden honge
    </div>
    <?php endif; ?>
    <?php else: ?>
    <div style="background:rgba(255,61,90,.08);border:1px solid rgba(255,61,90,.25);color:var(--danger);
      padding:10px;border-radius:8px;font-family:'Courier New',Consolas,monospace;font-size:11px">
      ⛔ Viewer role: Export access denied. Contact admin.
    </div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Portable Backup Guide</h2>
    <div class="portable-steps">
      <div>1. Puri <strong>devvault2/</strong> folder copy karo (USB ya Drive)</div>
      <div>2. Naye PC pe paste karo</div>
      <div>3. <strong>data/vault.db</strong> — sab kuch yahan hai</div>
      <div>4. <strong>php -S 0.0.0.0:8080</strong> run karo</div>
      <div style="color:var(--success);margin-top:8px">✅ Done — koi data loss nahi, koi install nahi</div>
    </div>
  </div>
</div>
<script src="session_timer.js"></script>
</body>
</html>
