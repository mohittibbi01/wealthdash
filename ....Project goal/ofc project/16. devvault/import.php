<?php
require_once __DIR__ . '/auth.php';
require_login();
require_edit();

$db = get_db();
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && verify_csrf()) {
    if (empty($_FILES['import_file']['name'])) {
        $result = ['type'=>'error','msg'=>'No file selected.'];
    } else {
        $ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
        $tmp = $_FILES['import_file']['tmp_name'];
        $rows = [];

        if ($ext === 'json') {
            $json = json_decode(file_get_contents($tmp), true);
            $rows = $json['projects'] ?? (is_array($json) ? $json : []);
        } elseif (in_array($ext, ['csv','txt'])) {
            $fh = fopen($tmp, 'r');
            $header = fgetcsv($fh);
            // Normalize header keys
            $header = array_map(fn($h)=>strtolower(trim(str_replace([' ','/','-'],'_',$h))), $header);
            while (($r = fgetcsv($fh)) !== false) {
                if (count($r) !== count($header)) continue;
                $rows[] = array_combine($header, $r);
            }
            fclose($fh);
        } else {
            $result = ['type'=>'error','msg'=>'Unsupported file type. Use .json or .csv'];
        }

        if (!$result) {
            // Allowed columns (subset that makes sense to import)
            $allowedCols = [
                'parent_admin_dept','department_name','nodal_officer_name','nodal_designation','nodal_contact','dept_email',
                'technology','technology_other','website_app','project_name','description','current_status',
                'last_audit_date','live_date','total_visitor_counter','last_update_date',
                'env_local_url','env_local_admin_url','env_local_id','env_local_remark',
                'env_staging_url','env_staging_admin_url','env_staging_id','env_staging_remark',
                'env_production_url','env_production_admin_url','env_production_id','env_production_remark',
                'env_audit_url','env_audit_admin_url','env_audit_id','env_audit_remark',
                'env_other_url','env_other_admin_url','env_other_id','env_other_remark',
                'app_ip','app_lb_ip','app_os','app_os_other','app_core','app_ram',
                'app_primary_storage','app_secondary_storage','app_hosting_type','app_infra_remark',
                'db_ip','db_name','db_technology','db_technology_other','db_version','db_version_other',
                'db_os','db_os_other','db_hosting_type','db_remark','general_remark',
            ];

            $mode = $_POST['mode'] ?? 'add'; // add | skip_existing | update
            $imported = 0; $skipped = 0; $updated = 0; $errors = 0;

            foreach ($rows as $row) {
                $row = array_change_key_case($row, CASE_LOWER);
                $pname = trim($row['project_name'] ?? '');
                if (!$pname) { $errors++; continue; }

                $data = [];
                foreach ($allowedCols as $c) {
                    if (isset($row[$c])) $data[$c] = trim((string)$row[$c]);
                }
                $data['project_name'] = $pname;
                if (empty($data['current_status'])) $data['current_status'] = 'under_development';

                // Check existing
                $st = $db->prepare("SELECT id FROM projects WHERE project_name=?");
                $st->execute([$pname]);
                $existing = $st->fetchColumn();

                if ($existing) {
                    if ($mode === 'skip_existing') { $skipped++; continue; }
                    if ($mode === 'update') {
                        $sets = implode(',', array_map(fn($c)=>"`$c`=?", array_keys($data))) . ',updated_at=CURRENT_TIMESTAMP';
                        $db->prepare("UPDATE projects SET $sets WHERE id=?")->execute([...array_values($data), $existing]);
                        log_activity('import_update', $existing, $pname);
                        $updated++;
                        continue;
                    }
                    // mode add => insert duplicate anyway (allowed)
                }

                $data['created_by'] = $_SESSION['user_id'];
                $cols = implode(',', array_map(fn($c)=>"`$c`", array_keys($data)));
                $phs  = implode(',', array_fill(0, count($data), '?'));
                $db->prepare("INSERT INTO projects ($cols) VALUES ($phs)")->execute(array_values($data));
                $newId = (int)$db->lastInsertId();
                log_activity('import_add', $newId, $pname);
                $imported++;
            }

            backup_json();
            $result = [
                'type' => 'success',
                'msg'  => "✅ Import done: $imported added, $updated updated, $skipped skipped, $errors errors."
            ];
        }
    }
}

$_theme_i = user_pref('theme','teal-dark');
$_fs_i    = max(11,min(18,(int)user_pref('font_size','14')));
$_acc_i   = preg_replace('/[^#a-fA-F0-9]/','',user_pref('accent','#00d4aa'));
if(strlen($_acc_i)<4) $_acc_i='#00d4aa';
$_cb_i    = user_pref('colorblind','none');
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?=htmlspecialchars($_theme_i)?>"<?=$_cb_i!=='none'?' data-colorblind="'.htmlspecialchars($_cb_i).'"':''?> style="font-size:<?=$_fs_i?>px">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DevVault — Import Projects</title>
<link rel="stylesheet" href="assets/theme.css">
<?php if($_acc_i !== '#00d4aa'): ?>
<style>:root{--acc:<?=htmlspecialchars($_acc_i)?>;--acc-dim:color-mix(in srgb,<?=htmlspecialchars($_acc_i)?> 13%,transparent)}</style>
<?php endif; ?>
</head>
<body style="padding:20px">

<!-- Minimal topbar matching project_form -->
<div style="position:sticky;top:0;z-index:200;height:50px;background:color-mix(in srgb,var(--bg) 94%,transparent);border-bottom:1px solid var(--bdr);backdrop-filter:blur(14px);display:flex;align-items:center;padding:0 18px;gap:12px;margin:-20px -20px 20px">
  <a href="index.php" style="font-family:'JetBrains Mono',monospace;font-size:13px;font-weight:700;color:var(--acc);text-decoration:none;letter-spacing:2px">🔐 DEVVAULT</a>
  <span style="color:var(--bdr);font-size:18px">|</span>
  <span style="font-size:13px;font-weight:600;color:var(--tx)">📥 Import Projects</span>
  <a href="index.php" class="btn btn-ghost btn-sm" style="margin-left:auto">← Back</a>
</div>

<div style="max-width:680px;margin:0 auto">
<?php if($result):?>
<div class="flash flash-<?=$result['type']?>"><?=htmlspecialchars($result['msg'])?></div>
<?php endif;?>

<div class="card" style="margin-bottom:14px">
  <div class="card-title">📤 Upload File</div>
  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?=csrf_token()?>">
    <div class="field" style="margin-bottom:12px">
      <label class="field-label">JSON or CSV File</label>
      <input type="file" name="import_file" accept=".json,.csv,.txt" required>
    </div>
    <div class="field" style="margin-bottom:14px">
      <label class="field-label">If project name already exists</label>
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:6px">
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer"><input type="radio" name="mode" value="add" checked> ➕ Add as new (duplicate)</label>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer"><input type="radio" name="mode" value="skip_existing"> ⏭ Skip existing</label>
        <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer"><input type="radio" name="mode" value="update"> 🔄 Update existing</label>
      </div>
    </div>
    <button type="submit" class="btn btn-primary">📥 Import Now</button>
  </form>
</div>

<div class="card">
  <div class="card-title">📖 Format Guide</div>
  <div style="font-size:13px;color:var(--tx2);line-height:1.8">
    <strong style="color:var(--tx)">JSON format</strong> — Export se generated JSON seedha import ho sakta hai:<br>
    <code style="font-family:'JetBrains Mono',monospace;font-size:11px;background:var(--sur2);padding:3px 8px;border-radius:5px;color:var(--acc)">{"projects": [{"project_name": "...", "department_name": "...", ...}]}</code><br><br>
    <strong style="color:var(--tx)">CSV format</strong> — First row column headers honi chahiye:<br>
    <code style="font-family:'JetBrains Mono',monospace;font-size:11px;background:var(--sur2);padding:3px 8px;border-radius:5px;color:var(--acc)">project_name, department_name, technology, current_status, app_ip, db_ip, ...</code><br><br>
    Required field: <code style="font-family:'JetBrains Mono',monospace;color:var(--warn)">project_name</code>. Baaki fields optional hain.<br>
    💡 Pehle <a href="export.php" style="color:var(--acc)">Export</a> se JSON download karo, format dekhne ke liye.
  </div>
</div>
</div>

<script>window.DEVVAULT_CSRF='<?=csrf_token()?>';</script>
<script src="session_timer.js"></script>
</body>
</html>
