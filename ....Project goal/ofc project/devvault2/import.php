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

$accent  = user_pref('accent','#00d4ff');
$bg      = user_pref('bg_color','');
$theme   = user_pref('theme','dark');
$fsize   = user_pref('font_size','14');
$ffamily = user_pref('font_family','Rajdhani');
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?=$theme?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DevVault — Import Projects</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;500;600;700&family=Orbitron:wght@700;900&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --acc:<?=$accent?>;--user-bg:<?=$bg?$bg:'var(--bg)'?>;
  --bg:#070b14;--sur:#0d1422;--sur2:#111a2e;--bdr:#1e2d4a;
  --tx:#e8edf5;--mt:#5a7a9a;--ok:#00e676;--err:#ff3d5a;--amb:#ffd740;
}
[data-theme="light"]{--bg:#f0f4f8;--sur:#ffffff;--sur2:#e8edf5;--bdr:#c8d4e0;--tx:#0d1422;--mt:#6b7f96;}
html{font-size:<?=$fsize?>px}
body{font-family:'<?=$ffamily?>',sans-serif;background:var(--user-bg);color:var(--tx);min-height:100vh}
.bar{position:sticky;top:0;z-index:200;height:50px;background:color-mix(in srgb,var(--user-bg) 92%,transparent);
  border-bottom:1px solid var(--bdr);backdrop-filter:blur(14px);display:flex;align-items:center;padding:0 18px;gap:12px}
.logo{font-family:'Orbitron',monospace;font-size:14px;font-weight:900;color:var(--acc);letter-spacing:2px;text-shadow:0 0 14px var(--acc)}
.bar-r{margin-left:auto;display:flex;gap:8px}
.btn{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:7px;font-size:13px;font-weight:700;
  font-family:inherit;cursor:pointer;border:none;text-decoration:none;transition:all .15s}
.btn:active{transform:scale(.97)}
.btn-ghost{background:var(--sur2);color:var(--mt);border:1px solid var(--bdr)}
.btn-ghost:hover{color:var(--tx)}
.btn-acc{background:var(--acc);color:#000;padding:9px 18px}
.btn-acc:hover{opacity:.85}
.wrap{max-width:760px;margin:0 auto;padding:20px 16px}
h1{font-family:'Orbitron',monospace;font-size:16px;color:var(--acc);margin-bottom:16px;letter-spacing:1.5px}
.sec{background:var(--sur);border:1px solid var(--bdr);border-radius:12px;padding:18px;margin-bottom:14px}
.sec h2{font-family:'Share Tech Mono',monospace;font-size:11px;text-transform:uppercase;letter-spacing:1.5px;color:var(--mt);margin-bottom:12px}
.field{margin-bottom:12px}
label{display:block;font-family:'Share Tech Mono',monospace;font-size:10px;text-transform:uppercase;letter-spacing:1.2px;color:var(--mt);margin-bottom:5px}
input,select{background:var(--sur2);border:1px solid var(--bdr);border-radius:7px;padding:9px 12px;color:var(--tx);
  font-size:13px;font-family:'Share Tech Mono',monospace;outline:none;width:100%}
input:focus,select:focus{border-color:var(--acc)}
.flash{padding:10px 14px;border-radius:8px;font-size:12px;font-family:'Share Tech Mono',monospace;margin-bottom:14px}
.flash-success{background:color-mix(in srgb,var(--ok) 8%,transparent);border:1px solid color-mix(in srgb,var(--ok) 25%,transparent);color:var(--ok)}
.flash-error{background:color-mix(in srgb,var(--err) 8%,transparent);border:1px solid color-mix(in srgb,var(--err) 25%,transparent);color:var(--err)}
.hint{font-family:'Share Tech Mono',monospace;font-size:11px;color:var(--mt);line-height:1.8;margin-top:8px}
.hint code{background:var(--sur2);padding:2px 6px;border-radius:4px;color:var(--acc)}
.radio-row{display:flex;gap:14px;flex-wrap:wrap}
.radio-opt{display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer}
.radio-opt input{width:auto}
</style>
</head>
<body>
<div class="bar">
  <span class="logo">DEVVAULT</span>
  <span style="color:var(--bdr);font-size:18px">|</span>
  <span style="font-size:14px;font-weight:700">📥 Import Projects</span>
  <div class="bar-r"><a href="index.php" class="btn btn-ghost">← Back</a></div>
</div>

<div class="wrap">
<?php if($result):?>
<div class="flash flash-<?=$result['type']?>"><?=htmlspecialchars($result['msg'])?></div>
<?php endif;?>

<div class="sec">
  <h2>Upload File</h2>
  <form method="POST" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?=csrf_token()?>">
    <div class="field">
      <label>JSON or CSV File</label>
      <input type="file" name="import_file" accept=".json,.csv,.txt" required>
    </div>
    <div class="field">
      <label>If project name already exists</label>
      <div class="radio-row">
        <label class="radio-opt"><input type="radio" name="mode" value="add" checked> ➕ Add as new (duplicate)</label>
        <label class="radio-opt"><input type="radio" name="mode" value="skip_existing"> ⏭ Skip existing</label>
        <label class="radio-opt"><input type="radio" name="mode" value="update"> 🔄 Update existing</label>
      </div>
    </div>
    <button type="submit" class="btn btn-acc">📥 Import Now</button>
  </form>
</div>

<div class="sec">
  <h2>Format Guide</h2>
  <div class="hint">
    <strong style="color:var(--tx)">JSON format</strong> — Export se generated JSON seedha import ho sakta hai:<br>
    <code>{"projects": [{"project_name": "...", "department_name": "...", ...}]}</code><br><br>
    <strong style="color:var(--tx)">CSV format</strong> — First row column headers honi chahiye, matching field names jaise:<br>
    <code>project_name, department_name, technology, current_status, app_ip, db_ip, env_production_url, ...</code><br><br>
    Required field: <code>project_name</code>. Baaki fields optional hain — jo column nahi milega wo blank rehega.<br>
    💡 Pehle <a href="export.php" style="color:var(--acc)">Export</a> se JSON download karo, format dekhne ke liye.
  </div>
</div>
</div>
</body>
</html>
