<?php
require_once __DIR__ . '/auth.php';
require_login();

$db     = get_db();
$accent  = user_pref('accent','#00d4ff');
$bg      = user_pref('bg_color','');
$theme   = user_pref('theme','dark');
$fsize   = user_pref('font_size','14');
$ffamily = user_pref('font_family','Rajdhani');

$format = $_GET['format'] ?? '';

// Viewer cannot export
if (!can_edit() && $format) {
    header('Location: export.php?err=noperm'); exit;
}

$allProjects = $db->query("SELECT p.*,u.username as creator FROM projects p
    LEFT JOIN users u ON p.created_by=u.id ORDER BY p.project_name")->fetchAll();

// ── JSON ──────────────────────────────────────────────────────────────────
if ($format === 'json') {
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

// ── CSV ───────────────────────────────────────────────────────────────────
if ($format === 'csv') {
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

// ── Report HTML (printable) ───────────────────────────────────────────────
if ($format === 'report') {
    log_activity('export_report');
    $statusLabels = ['live'=>'Live','under_development'=>'Under Dev','redevelopment'=>'Redevelopment','closed'=>'Closed'];
    $statusColors = ['live'=>'#00e676','under_development'=>'#ffd740','redevelopment'=>'#40c4ff','closed'=>'#ff3d5a'];
    ?>
<!DOCTYPE html>
<html lang="en" data-theme="<?=$theme?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DevVault Pro — Export & Backup</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;600;700&family=Orbitron:wght@700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--accent:<?=$accent?>;--fs:<?=$fsize?>px;--bg:#070b14;--surface:#0d1422;--surface2:#111a2e;--surface3:#16213e;
  --border:#1e2d4a;--text:#e8edf5;--muted:#5a7a9a;--success:#00e676;--danger:#ff3d5a;--amber:#ffd740;--blue:#40c4ff;}
[data-theme="light"]{--bg:#f0f4f8;--surface:#fff;--surface2:#e8edf5;--surface3:#dde3ed;--border:#c8d4e0;--text:#0d1422;--muted:#5a7a9a;}
html{font-size:var(--fs)}
body{font-family:'<?=$ffamily?>',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
body::before{content:'';position:fixed;inset:0;
  background-image:linear-gradient(rgba(0,212,255,.018) 1px,transparent 1px),linear-gradient(90deg,rgba(0,212,255,.018) 1px,transparent 1px);
  background-size:40px 40px;pointer-events:none;z-index:0}
[data-theme="light"] body::before{opacity:.3}
.topbar{position:sticky;top:0;z-index:100;background:rgba(7,11,20,.95);border-bottom:1px solid var(--border);
  backdrop-filter:blur(12px);padding:0 20px;height:52px;display:flex;align-items:center;gap:10px}
[data-theme="light"] .topbar{background:rgba(240,244,248,.95)}
.logo-txt{font-family:'Orbitron',monospace;font-size:14px;font-weight:900;letter-spacing:2px;color:var(--accent);text-shadow:0 0 16px var(--accent)}
.btn{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:7px;font-size:12px;font-weight:600;
  font-family:'Rajdhani',sans-serif;cursor:pointer;border:none;text-decoration:none;transition:all .15s;white-space:nowrap}
.btn:active{transform:scale(.97)}
.btn-ghost{background:var(--surface2);color:var(--muted);border:1px solid var(--border)}.btn-ghost:hover{color:var(--text)}
.btn-accent{background:var(--accent);color:#000}.btn-accent:hover{opacity:.85}
.btn-sm{padding:4px 9px;font-size:11px}
.wrap{max-width:700px;margin:0 auto;padding:20px;position:relative;z-index:1}
.page-title{font-family:'Orbitron',monospace;font-size:16px;font-weight:700;color:var(--accent);text-shadow:0 0 12px var(--accent);margin-bottom:16px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:14px}
.card h2{font-family:'Share Tech Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:1.5px;
  color:var(--muted);padding:10px 16px;border-bottom:1px solid var(--border);background:var(--surface2)}
.info-row{display:flex;justify-content:space-between;padding:10px 16px;border-bottom:1px solid rgba(30,45,74,.4);font-size:13px}
.info-row:last-child{border-bottom:none}
.info-row .k{color:var(--muted);font-family:'Share Tech Mono',monospace;font-size:11px}
.info-row .v{font-family:'Share Tech Mono',monospace;font-weight:600}
.exp-opt{display:flex;align-items:center;gap:16px;padding:14px 16px;background:var(--surface2);
  border-bottom:1px solid var(--border);text-decoration:none;color:var(--text);transition:all .2s}
.exp-opt:last-child{border-bottom:none}
.exp-opt:hover{background:var(--surface3);padding-left:20px}
.exp-icon{font-size:28px;flex-shrink:0}
.exp-info h3{font-size:14px;font-weight:700;margin-bottom:3px}
.exp-info p{font-size:11px;font-family:'Share Tech Mono',monospace;color:var(--muted)}
.badge{margin-left:auto;font-size:9px;font-family:'Share Tech Mono',monospace;padding:3px 9px;border-radius:20px;font-weight:700;flex-shrink:0;border:1px solid currentColor}
.badge-g{background:rgba(0,230,118,.10);color:var(--success)}
.badge-a{background:rgba(255,215,64,.10);color:var(--amber)}
.badge-b{background:rgba(0,212,255,.10);color:var(--accent)}
.portable-steps{font-family:'Share Tech Mono',monospace;font-size:12px;color:var(--muted);line-height:2;padding:14px 16px}
.portable-steps strong{color:var(--text)}
</style>
</head>
<body>
<div class="topbar">
  <span class="logo-txt">DEVVAULT</span>
  <span style="color:var(--border);font-size:18px">|</span>
  <span style="font-size:14px;font-weight:700;font-family:'Rajdhani',sans-serif">📤 Export & Backup</span>
  <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
    <a href="index.php" class="btn btn-ghost btn-sm">← Dashboard</a>
    <span id="session-timer-display" title="Session timer">⏱ 05:00</span>
    <a href="logout.php" class="btn btn-ghost btn-sm">⏏ Logout</a>
  </div>
</div>
<div class="wrap">
  <div class="page-title">📤 EXPORT & BACKUP</div>

  <div class="card">
    <h2>Current Data</h2>
    <div class="info-row"><span class="k">Total Projects</span><span class="v"><?=$total?></span></div>
    <div class="info-row"><span class="k">Last Auto-Backup</span><span class="v"><?=$lastBackup?></span></div>
    <div class="info-row"><span class="k">Backup File</span><span class="v">data/vault_backup.json</span></div>
    <div class="info-row"><span class="k">Exported By</span><span class="v"><?=htmlspecialchars($_SESSION['username'])?></span></div>
  </div>

  <div class="card">
    <h2>Download Options</h2>
    <a class="exp-opt" href="export.php?format=json">
      <span class="exp-icon">📄</span>
      <div class="exp-info">
        <h3>JSON Export</h3>
        <p>Full backup — all fields, best for restore</p>
      </div>
      <span class="badge badge-g">Recommended</span>
    </a>
    <a class="exp-opt" href="export.php?format=csv">
      <span class="exp-icon">📊</span>
      <div class="exp-info">
        <h3>CSV / Excel Export</h3>
        <p>Excel-compatible — UTF-8 BOM included</p>
      </div>
      <span class="badge badge-a">Excel Ready</span>
    </a>
    <a class="exp-opt" href="export.php?format=report" target="_blank">
      <span class="exp-icon">🖨</span>
      <div class="exp-info">
        <h3>Printable Report</h3>
        <p>HTML report — print ya PDF save karo</p>
      </div>
      <span class="badge badge-b">Printable</span>
    </a>
    <?php if(!can_edit()):?>
    <div style="background:rgba(255,61,90,.08);border:1px solid rgba(255,61,90,.25);color:var(--danger);
      padding:10px;border-radius:8px;font-family:'Share Tech Mono',monospace;font-size:11px;margin-bottom:10px">
      ⛔ Viewer role: Export access denied. Contact admin.
    </div>
    <?php elseif(!is_admin()):?>
    <div style="font-family:'Share Tech Mono',monospace;font-size:11px;color:var(--amber);
      margin-top:10px;padding:10px;background:rgba(255,215,64,.05);border-radius:6px;
      border:1px solid rgba(255,215,64,.15)">
      ⚠ Non-admin: passwords exports mein hidden honge
    </div>
    <?php endif;?>
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
