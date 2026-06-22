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
    <button class="btn btn-accent" data-action="print">🖨 Print / Save PDF</button>
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
$page_title = 'Export & Backup';
$nav_active = 'export';
require_once __DIR__ . '/includes/sidebar.php';
?>
<div class="dv-content">

<?php if ($err === 'noperm'): ?>
<div class="flash flash-error">⛔ Access denied. Export requires Member or Admin role.</div>
<?php endif; ?>

<?php
// Handle manual backup trigger
$backup_result = '';
if (isset($_POST['action']) && $_POST['action'] === 'manual_backup' && is_admin()) {
    if (verify_csrf()) {
        $backup_dir = __DIR__ . '/data/backups';
        $db_path    = __DIR__ . '/data/vault.db';
        if (!is_dir($backup_dir)) mkdir($backup_dir, 0755, true);
        $ts   = date('Ymd_His');
        $dest = $backup_dir . "/vault_{$ts}.db";
        try {
            $sq = new SQLite3($db_path);
            if (version_compare(SQLite3::version()['versionString'], '3.27.0', '>=')) {
                $sq->exec("VACUUM INTO '" . str_replace("'","''",$dest) . "'");
                $sq->close();
            } else { $sq->close(); copy($db_path, $dest); }
            // Rotate: keep max 30
            $files = glob($backup_dir . '/vault_*.db');
            if ($files) {
                sort($files);
                while (count($files) > 30) { unlink(array_shift($files)); }
            }
            file_put_contents($backup_dir.'/.last_backup', date('Y-m-d H:i:s'));
            $backup_result = 'ok:Backup created: vault_'.$ts.'.db ('.round(filesize($dest)/1024,1).' KB). Total: '.count(glob($backup_dir.'/vault_*.db')).'/30';
        } catch (Exception $e) { $backup_result = 'err:'.$e->getMessage(); }
    }
}
if ($backup_result) {
    [$br_t,$br_m] = explode(':',$backup_result,2);
    echo '<div class="flash flash-'.($br_t==='ok'?'success':'error').'">'.($br_t==='ok'?'✅':'❌').' '.htmlspecialchars($br_m).'</div>';
}
?>

<div class="card" style="margin-bottom:14px">
  <div class="card-title">💾 Backup Status</div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px">
    <div style="background:var(--sur2);border-radius:8px;padding:10px 14px">
      <div style="font-size:10px;color:var(--tx2);font-family:'JetBrains Mono',monospace;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">Total Projects</div>
      <div style="font-size:22px;font-weight:700;color:var(--acc);font-family:'JetBrains Mono',monospace"><?=(int)$total?></div>
    </div>
    <div style="background:var(--sur2);border-radius:8px;padding:10px 14px">
      <div style="font-size:10px;color:var(--tx2);font-family:'JetBrains Mono',monospace;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">Backups Stored</div>
      <div style="font-size:22px;font-weight:700;color:var(--ok);font-family:'JetBrains Mono',monospace"><?=intval($backupCount)?> <span style="font-size:13px;color:var(--tx2)">/ 30</span></div>
    </div>
  </div>
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
    <div>
      <div style="font-size:11px;color:var(--tx2)">📅 Last backup: <strong style="color:var(--tx)"><?=htmlspecialchars($lastBackup)?></strong></div>
      <div style="font-size:11px;color:var(--tx3);margin-top:2px">📁 Location: <span style="font-family:'JetBrains Mono',monospace">data/backups/vault_YYYYMMDD_HHiiss.db</span></div>
      <div style="font-size:11px;color:var(--tx3)">🔄 Auto: Windows Task Scheduler — <span style="font-family:'JetBrains Mono',monospace">php backup.php</span> daily · Max 30 files (circular)</div>
    </div>
    <?php if(is_admin()):?>
    <form method="POST" style="display:inline">
      <input type="hidden" name="action" value="manual_backup">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <button type="submit" class="btn btn-primary" data-confirm="Create manual backup now?">💾 Manual Backup Now</button>
    </form>
    <?php endif;?>
  </div>
</div>

<div class="card" style="margin-bottom:14px">
  <div class="card-title">📤 Export Options</div>
  <?php if (can_edit()): ?>
  <div style="display:flex;flex-direction:column;gap:8px">
    <a href="<?=htmlspecialchars($urlJson)?>" style="display:flex;align-items:center;gap:14px;padding:13px 16px;background:var(--sur2);border:1px solid var(--bdr);border-radius:10px;text-decoration:none;color:var(--tx);transition:all .14s" onmouseover="this.style.borderColor='var(--acc)'" onmouseout="this.style.borderColor='var(--bdr)'">
      <span style="font-size:28px;flex-shrink:0">📄</span>
      <div style="flex:1"><div style="font-weight:600;margin-bottom:2px">JSON Export</div><div style="font-size:11px;color:var(--tx2);font-family:'JetBrains Mono',monospace">Full backup — all fields, best for restore</div></div>
      <span class="badge badge-member">Recommended</span>
    </a>
    <a href="<?=htmlspecialchars($urlCsv)?>" style="display:flex;align-items:center;gap:14px;padding:13px 16px;background:var(--sur2);border:1px solid var(--bdr);border-radius:10px;text-decoration:none;color:var(--tx);transition:all .14s" onmouseover="this.style.borderColor='var(--warn)'" onmouseout="this.style.borderColor='var(--bdr)'">
      <span style="font-size:28px;flex-shrink:0">📊</span>
      <div style="flex:1"><div style="font-weight:600;margin-bottom:2px">CSV / Excel Export</div><div style="font-size:11px;color:var(--tx2);font-family:'JetBrains Mono',monospace">Excel-compatible — UTF-8 BOM included</div></div>
      <span class="badge badge-redev">Excel Ready</span>
    </a>
    <a href="<?=htmlspecialchars($urlRpt)?>" target="_blank" style="display:flex;align-items:center;gap:14px;padding:13px 16px;background:var(--sur2);border:1px solid var(--bdr);border-radius:10px;text-decoration:none;color:var(--tx);transition:all .14s" onmouseover="this.style.borderColor='var(--info)'" onmouseout="this.style.borderColor='var(--bdr)'">
      <span style="font-size:28px;flex-shrink:0">🖨</span>
      <div style="flex:1"><div style="font-weight:600;margin-bottom:2px">Printable Report</div><div style="font-size:11px;color:var(--tx2);font-family:'JetBrains Mono',monospace">HTML report — print ya PDF save karo</div></div>
      <span class="badge badge-dev">Printable</span>
    </a>
  </div>
  <?php if (!is_admin()): ?>
  <div class="flash flash-warn" style="margin-top:10px;margin-bottom:0">⚠ Non-admin: passwords exports mein hidden honge</div>
  <?php endif; ?>
  <?php else: ?>
  <div class="flash flash-error">⛔ Viewer role: Export access denied. Contact admin.</div>
  <?php endif; ?>
</div>

<div class="card">
  <div class="card-title">📱 Portable Backup Guide</div>
  <div style="display:flex;flex-direction:column;gap:6px;font-size:13px;color:var(--tx2)">
    <div>1. Puri <strong style="color:var(--tx);font-family:'JetBrains Mono',monospace">devvault/</strong> folder copy karo (USB ya Google Drive)</div>
    <div>2. Naye PC pe paste karo</div>
    <div>3. <strong style="color:var(--tx);font-family:'JetBrains Mono',monospace">data/vault.db</strong> — sab kuch yahan hai</div>
    <div>4. <strong style="color:var(--tx);font-family:'JetBrains Mono',monospace">php -S 0.0.0.0:8080</strong> run karo XAMPP se</div>
    <div style="color:var(--ok);margin-top:4px;font-weight:600">✅ Done — koi data loss nahi, koi install nahi</div>
  </div>
</div>

<script nonce="<?=csp_nonce()?>">
window.DEVVAULT_CSRF='<?=csrf_token()?>';
document.addEventListener('click',function(e){
  var b=e.target.closest('[data-confirm]');
  if(b&&b.type==='submit'){if(!confirm(b.dataset.confirm))e.preventDefault();}
  var pr=e.target.closest('[data-action="print"]');if(pr){window.print();return;}
});
</script>

</div><!-- /.dv-content -->
<?php require_once __DIR__ . '/includes/sidebar_footer.php'; ?>
