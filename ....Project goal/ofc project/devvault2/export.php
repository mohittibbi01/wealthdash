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
<html>
<head>
<meta charset="UTF-8">
<title>DevVault Report — <?=date('d M Y')?></title>
<style>
body{font-family:Arial,sans-serif;font-size:12px;color:#000;margin:0;padding:20px;background:#fff}
h1{font-size:20px;margin-bottom:4px}
.meta{font-size:11px;color:#666;margin-bottom:20px}
table{width:100%;border-collapse:collapse;margin-bottom:24px;font-size:11px}
th{background:#1a1a2e;color:#fff;padding:7px 8px;text-align:left;font-size:10px;text-transform:uppercase;letter-spacing:.5px}
td{padding:6px 8px;border-bottom:1px solid #e0e0e0;vertical-align:top}
tr:nth-child(even) td{background:#f8f9fa}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:bold;border:1px solid currentColor}
h2{font-size:14px;border-bottom:2px solid #1a1a2e;padding-bottom:4px;margin:20px 0 10px}
@media print{
  .no-print{display:none}
  body{padding:0}
}
</style>
</head>
<body>
<div class="no-print" style="margin-bottom:16px">
  <button onclick="window.print()" style="padding:8px 16px;background:#1a1a2e;color:#fff;border:none;border-radius:6px;cursor:pointer;margin-right:8px">🖨 Print</button>
  <a href="export.php" style="color:#1a1a2e;font-size:12px">← Back</a>
</div>

<h1>DevVault Pro — Project Report</h1>
<div class="meta">Generated: <?=date('d M Y, H:i:s')?> | By: <?=htmlspecialchars($_SESSION['username'])?> | Total: <?=count($allProjects)?> projects</div>

<h2>Status Summary</h2>
<table>
  <tr><th>Status</th><th>Count</th></tr>
  <?php
  $sc=[];
  foreach($allProjects as $p) $sc[$p['current_status']]=($sc[$p['current_status']]??0)+1;
  foreach($statusLabels as $k=>$l):?>
  <tr>
    <td><span class="badge" style="color:<?=$statusColors[$k]?>"><?=$l?></span></td>
    <td><?=$sc[$k]??0?></td>
  </tr>
  <?php endforeach;?>
</table>

<h2>All Projects</h2>
<table>
  <tr>
    <th>#</th><th>Project</th><th>Department</th><th>Technology</th>
    <th>Status</th><th>Nodal Officer</th><th>App IP</th><th>DB IP</th>
    <th>Production URL</th><th>Live Date</th>
  </tr>
  <?php foreach($allProjects as $i=>$p):
    $tech=$p['technology']==='Other'?$p['technology_other']:$p['technology'];?>
  <tr>
    <td><?=$i+1?></td>
    <td><strong><?=htmlspecialchars($p['project_name'])?></strong></td>
    <td><?=htmlspecialchars($p['department_name']??'')?></td>
    <td><?=htmlspecialchars($tech??'')?></td>
    <td><span class="badge" style="color:<?=$statusColors[$p['current_status']]??'#666'?>"><?=$statusLabels[$p['current_status']]??$p['current_status']?></span></td>
    <td><?=htmlspecialchars($p['nodal_officer_name']??'')?><br><small><?=htmlspecialchars($p['nodal_contact']??'')?></small></td>
    <td><?=htmlspecialchars($p['app_ip']??'')?></td>
    <td><?=htmlspecialchars($p['db_ip']??'')?></td>
    <td style="word-break:break-all"><?=htmlspecialchars($p['env_production_url']??'')?></td>
    <td><?=htmlspecialchars($p['live_date']??'')?></td>
  </tr>
  <?php endforeach;?>
</table>
</body>
</html>
<?php exit; }

// ── Export UI ─────────────────────────────────────────────────────────────
$total = count($allProjects);
$lastBackup = file_exists(__DIR__.'/data/vault_backup.json') ? date('d M Y, H:i',filemtime(__DIR__.'/data/vault_backup.json')) : 'Never';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DevVault Pro — Export</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;600;700&family=Orbitron:wght@700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--acc:<?=$accent?>;
  --user-bg:<?=$bg?$bg:'var(--bg)'?>;
  --bg:#070b14;--surface:#0d1422;--surface2:#111a2e;--border:#1e2d4a;
  --text:#e8edf5;--muted:#5a7a9a;--accent:#00d4ff;--success:#00e676;--amber:#ffd740}
body{font-family:'Rajdhani',sans-serif;background:var(--user-bg);color:var(--text);
  min-height:100vh;padding:24px}
.wrap{max-width:680px;margin:0 auto}
.page-header{display:flex;align-items:center;gap:12px;margin-bottom:24px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:8px;
  font-size:14px;font-weight:600;font-family:'Rajdhani',sans-serif;cursor:pointer;
  border:none;text-decoration:none;transition:all .15s}
.btn-ghost{background:var(--surface2);color:var(--muted);border:1px solid var(--border)}
.btn-ghost:hover{color:var(--text)}
h1{font-family:'Orbitron',monospace;font-size:18px;color:var(--accent)}
.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:14px}
.card h2{font-family:'Share Tech Mono',monospace;font-size:11px;text-transform:uppercase;
  letter-spacing:1.5px;color:var(--muted);margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid var(--border)}
.info-row{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid rgba(30,45,74,.5);font-size:13px}
.info-row:last-child{border-bottom:none}
.info-row .k{color:var(--muted);font-family:'Share Tech Mono',monospace;font-size:12px}
.info-row .v{font-family:'Share Tech Mono',monospace;font-weight:600}
.exp-opt{display:flex;align-items:center;gap:16px;padding:14px;background:var(--surface2);
  border:1px solid var(--border);border-radius:10px;margin-bottom:10px;
  text-decoration:none;color:var(--text);transition:all .2s}
.exp-opt:hover{border-color:var(--accent);transform:translateX(4px)}
.exp-opt:last-child{margin-bottom:0}
.exp-icon{font-size:30px}
.exp-info h3{font-size:15px;font-weight:700;margin-bottom:2px}
.exp-info p{font-size:11px;font-family:'Share Tech Mono',monospace;color:var(--muted)}
.badge{margin-left:auto;font-size:10px;font-family:'Share Tech Mono',monospace;
  padding:3px 9px;border-radius:20px;flex-shrink:0}
.badge-g{background:rgba(0,230,118,.12);color:var(--success);border:1px solid rgba(0,230,118,.25)}
.badge-a{background:rgba(255,215,64,.12);color:var(--amber);border:1px solid rgba(255,215,64,.25)}
.badge-b{background:rgba(0,212,255,.12);color:var(--accent);border:1px solid rgba(0,212,255,.25)}
.portable-steps{font-family:'Share Tech Mono',monospace;font-size:12px;color:var(--muted);line-height:2}
.portable-steps strong{color:var(--text)}
</style>
</head>
<body>
<div class="wrap">
  <div class="page-header">
    <a href="index.php" class="btn btn-ghost">← Back</a>
    <h1>📤 EXPORT & BACKUP</h1>
  </div>

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
</body>
</html>
