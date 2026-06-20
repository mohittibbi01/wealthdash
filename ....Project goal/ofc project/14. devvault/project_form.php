<?php
require_once __DIR__ . '/auth.php';
require_login();
require_edit();

$db = get_db();
$id = intval($_GET['id'] ?? 0);
$p  = null; $error = '';

if ($id) {
    $st = $db->prepare("SELECT * FROM projects WHERE id=?");
    $st->execute([$id]); $p = $st->fetch();
    if (!$p) { header('Location: index.php'); exit; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF error');
    foreach (['technology','app_os','db_version','db_os','db_technology'] as $grp)
        if (($_POST[$grp]??'')==='Other' && !empty($_POST[$grp.'_other']))
            add_option($grp, $_POST[$grp.'_other']);

    $fields = ['parent_admin_dept','department_name','nodal_officer_name','nodal_designation','nodal_contact','dept_email',
        'technology','technology_other','website_app','project_name','description',
        'current_status','last_audit_date','live_date','total_visitor_counter','last_update_date',
        'env_local_url','env_local_admin_url','env_local_id','env_local_remark',
        'env_staging_url','env_staging_admin_url','env_staging_id','env_staging_remark',
        'env_production_url','env_production_admin_url','env_production_id','env_production_remark',
        'env_audit_url','env_audit_admin_url','env_audit_id','env_audit_remark',
        'env_other_url','env_other_admin_url','env_other_id','env_other_remark',
        'parent_sectoral_portal',
        'app_ip','app_lb_ip','app_os','app_os_other','app_core','app_ram',
        'app_primary_storage','app_secondary_storage','app_hosting_type','app_infra_remark',
        'db_ip','db_name','db_technology','db_technology_other','db_version','db_version_other','db_os','db_os_other',
        'db_hosting_type','db_remark','general_remark',
        'tech_subtype','amc_amount','amc_type','amc_start_date','amc_end_date','amc_remarks','closed_date'];

    $data = [];
    foreach ($fields as $f) $data[$f] = trim($_POST[$f] ?? '');
    foreach (['local','staging','production','audit','other'] as $env) {
        $key = "env_{$env}_password"; $raw = trim($_POST[$key] ?? '');
        $data[$key] = $raw !== '' ? encrypt_val($raw) : ($p[$key] ?? '');
    }

    // T-06: Capture old technology before save (for change detection)
    $old_tech = ''; $old_subtype = '';
    if ($id) {
        $old_row = $db->prepare("SELECT technology, tech_subtype FROM projects WHERE id=?");
        $old_row->execute([$id]);
        $old_data = $old_row->fetch();
        $old_tech    = $old_data['technology'] ?? '';
        $old_subtype = $old_data['tech_subtype'] ?? '';
    }

    // ── Server-side input validation ────────────────────────────────────────────
    $val_errors = [];

    // Email fields
    foreach (['dept_email'] as $ef) {
        if (!empty($data[$ef]) && !filter_var($data[$ef], FILTER_VALIDATE_EMAIL)) {
            $val_errors[] = "Invalid email format: {$ef}";
        }
    }

    // Phone: 7–15 digits, spaces, +, -, () allowed
    if (!empty($data['nodal_contact'])) {
        $phone_clean = preg_replace('/[\s\-\(\)\+]/', '', $data['nodal_contact']);
        if (!preg_match('/^\d{7,15}$/', $phone_clean)) {
            $val_errors[] = 'Nodal contact: valid phone number daalo (7-15 digits)';
        }
    }

    // IP address fields
    foreach (['app_ip', 'app_lb_ip', 'db_ip'] as $ipf) {
        if (!empty($data[$ipf])) {
            // Allow comma-separated IPs or CIDR
            $ips = array_map('trim', explode(',', $data[$ipf]));
            foreach ($ips as $ip) {
                $ip_clean = preg_replace('/\/\d+$/', '', $ip); // strip CIDR
                if (!filter_var($ip_clean, FILTER_VALIDATE_IP)) {
                    $val_errors[] = "Invalid IP address in {$ipf}: " . htmlspecialchars($ip);
                }
            }
        }
    }

    // Date fields
    foreach (['live_date', 'last_audit_date', 'amc_start_date', 'amc_end_date', 'closed_date'] as $df) {
        if (!empty($data[$df])) {
            $d = DateTime::createFromFormat('Y-m-d', $data[$df]);
            if (!$d || $d->format('Y-m-d') !== $data[$df]) {
                $val_errors[] = "Invalid date in {$df}: use YYYY-MM-DD format";
            }
        }
    }

    // URL fields — must start with http/https if not empty
    foreach (['env_local_url','env_local_admin_url','env_staging_url','env_staging_admin_url',
              'env_production_url','env_production_admin_url','env_audit_url','env_audit_admin_url',
              'env_other_url','env_other_admin_url','website_app'] as $uf) {
        if (!empty($data[$uf]) && !filter_var($data[$uf], FILTER_VALIDATE_URL)) {
            $val_errors[] = "Invalid URL in {$uf}: http:// ya https:// se shuru hona chahiye";
        }
    }

    // AMC amount — numeric
    if (!empty($data['amc_amount']) && !is_numeric($data['amc_amount'])) {
        $val_errors[] = 'AMC amount sirf number hona chahiye';
    }

    if ($val_errors) {
        $error = implode('<br>', $val_errors);
    }

    if (!$data['project_name']) { $error = ($error ? $error . '<br>' : '') . 'Project name is required.'; }
    elseif ($error) { /* validation failed — fall through to show error */ }
    else {
        if ($id) {
            $sets = implode(',', array_map(fn($f)=>"`$f`=?", array_keys($data))).', updated_at=CURRENT_TIMESTAMP';
            $db->prepare("UPDATE projects SET $sets WHERE id=?")->execute([...array_values($data),$id]);
            log_activity('edit_project',$id,$data['project_name']);

            // T-06: Log technology change if technology or subtype changed
            $new_tech    = $data['technology'] ?? '';
            $new_subtype = $data['tech_subtype'] ?? '';
            if ($old_tech !== '' && ($old_tech !== $new_tech || $old_subtype !== $new_subtype)) {
                $tech_reason = trim($_POST['tech_change_reason'] ?? '');
                $db->prepare("INSERT INTO technology_change_log
                    (project_id, from_technology, from_subtype, to_technology, to_subtype, change_date, reason, changed_by)
                    VALUES (?,?,?,?,?,date('now'),?,?)")
                   ->execute([$id, $old_tech, $old_subtype, $new_tech, $new_subtype, $tech_reason, $_SESSION['user_id']]);
                log_activity('tech_change', $id, "$old_tech -> $new_tech");
            }
        } else {
            $cols = implode(',', array_map(fn($f)=>"`$f`", array_keys($data)));
            $phs  = implode(',', array_fill(0,count($data),'?'));
            $db->prepare("INSERT INTO projects ($cols,created_by) VALUES ($phs,?)")->execute([...array_values($data),$_SESSION['user_id']]);
            $id = (int)$db->lastInsertId();
            log_activity('add_project',$id,$data['project_name']);
        }

        // Save additional contact persons
        $db->prepare("DELETE FROM project_contacts WHERE project_id=?")->execute([$id]);
        $cNames    = $_POST['contact_name'] ?? [];
        $cDesigs   = $_POST['contact_designation'] ?? [];
        $cContacts = $_POST['contact_contact'] ?? [];
        $cEmails   = $_POST['contact_email'] ?? [];
        foreach ($cNames as $i => $cn) {
            $cn = trim($cn);
            if (!$cn) continue;
            $db->prepare("INSERT INTO project_contacts (project_id,name,designation,contact,email,sort_order) VALUES (?,?,?,?,?,?)")
               ->execute([$id, $cn, trim($cDesigs[$i]??''), trim($cContacts[$i]??''), trim($cEmails[$i]??''), $i]);
        }

        // T-05: Save visitor log entry if provided
        $vl_count = trim($_POST['vl_visitor_count'] ?? '');
        $vl_date  = trim($_POST['vl_entry_date'] ?? '');
        if ($vl_count !== '' && $vl_date !== '' && $vl_date <= date('Y-m-d')) {
            $vl_site_upd = trim($_POST['vl_site_last_update_date'] ?? '') ?: null;
            $vl_remarks  = trim($_POST['vl_remarks'] ?? '');
            $db->prepare("INSERT INTO project_visitor_log
                (project_id, entry_date, visitor_count, site_last_update_date, entered_by, remarks)
                VALUES (?,?,?,?,?,?)")
               ->execute([$id, $vl_date, (int)$vl_count, $vl_site_upd, $_SESSION['user_id'], $vl_remarks]);
            // Sync back to main projects table for backward compatibility
            $db->prepare("UPDATE projects SET total_visitor_counter=?, last_update_date=? WHERE id=?")
               ->execute([$vl_count, $vl_site_upd ?? $data['last_update_date'], $id]);
            log_activity('visitor_log_entry', $id, "Count: $vl_count on $vl_date");
        }

        // Save checklist responses
        $allItems = $db->query("SELECT id FROM checklist_items")->fetchAll();
        foreach ($allItems as $item) {
            $iid = $item['id'];
            $checked = isset($_POST["chk_item_$iid"]) ? 1 : 0;
            $notes = trim($_POST["chk_note_$iid"] ?? '');
            $db->prepare("INSERT INTO checklist_responses (project_id,item_id,checked,notes) VALUES (?,?,?,?)
                ON CONFLICT(project_id,item_id) DO UPDATE SET checked=excluded.checked, notes=excluded.notes")
               ->execute([$id, $iid, $checked, $notes]);
        }

        // Handle document uploads
        if (!empty($_FILES['doc_file']['name'][0] ?? '')) {
            $types  = $_POST['doc_type'] ?? [];
            $titles = $_POST['doc_title'] ?? [];
            foreach ($_FILES['doc_file']['name'] as $i => $fname) {
                if (!$fname) continue;
                $file = [
                    'name'     => $_FILES['doc_file']['name'][$i],
                    'type'     => $_FILES['doc_file']['type'][$i],
                    'tmp_name' => $_FILES['doc_file']['tmp_name'][$i],
                    'error'    => $_FILES['doc_file']['error'][$i],
                    'size'     => $_FILES['doc_file']['size'][$i],
                ];
                $res = safe_upload($file, $id, $types[$i] ?? 'Other', $titles[$i] ?? '', $_SESSION['user_id']);
                if (!$res['ok']) {
                    $_SESSION['flash'] = ['type'=>'error','msg'=>'⚠ Upload issue: '.$res['err']];
                }
            }
        }

        backup_json();
        if (empty($_SESSION['flash'])) {
            $_SESSION['flash']=['type'=>'success','msg'=>"✅ Project \"{$data['project_name']}\" saved."];
        }
        header('Location: index.php'); exit;
    }
}

// Load existing contacts, checklist responses, documents (for edit mode)
$contacts = [];
$checklistItems = $db->query("SELECT * FROM checklist_items ORDER BY sort_order, id")->fetchAll();
$checklistResp = [];
$documents = [];
if ($id) {
    $st = $db->prepare("SELECT * FROM project_contacts WHERE project_id=? ORDER BY sort_order, id");
    $st->execute([$id]); $contacts = $st->fetchAll();

    $st = $db->prepare("SELECT item_id,checked,notes FROM checklist_responses WHERE project_id=?");
    $st->execute([$id]);
    foreach ($st->fetchAll() as $r) $checklistResp[$r['item_id']] = $r;

    $st = $db->prepare("SELECT * FROM project_documents WHERE project_id=? ORDER BY doc_type, title, version DESC");
    $st->execute([$id]); $documents = $st->fetchAll();
}
$dbTechOpts = get_options_arr('db_technology');

$techOpts  = get_options_arr('technology');
$appOsOpts = get_options_arr('app_os');
$dbVerOpts = get_options_arr('db_version');
$dbOsOpts  = get_options_arr('db_os');

$v   = fn($f) => htmlspecialchars($p[$f] ?? $_POST[$f] ?? '');
$sel = fn($f,$val) => ($p[$f] ?? $_POST[$f] ?? '') === $val ? 'selected' : '';

$accent  = user_pref('accent','#00d4ff');
$bg      = user_pref('bg_color','');
$theme   = user_pref('theme','dark');
$fsize   = user_pref('font_size','14');
$ffamily = user_pref('font_family','Rajdhani');

// Section div colors — slightly different for easy recognition
$secColors = [
    'proj' => ['bg'=>'rgba(0,212,255,0.04)',  'bdr'=>'rgba(0,212,255,0.25)',  'hd'=>'rgba(0,212,255,0.08)'],
    'dept' => ['bg'=>'rgba(0,230,118,0.04)',  'bdr'=>'rgba(0,230,118,0.25)',  'hd'=>'rgba(0,230,118,0.08)'],
    'app'  => ['bg'=>'rgba(255,215,64,0.04)', 'bdr'=>'rgba(255,215,64,0.25)', 'hd'=>'rgba(255,215,64,0.08)'],
    'db'   => ['bg'=>'rgba(234,128,252,0.04)','bdr'=>'rgba(234,128,252,0.25)','hd'=>'rgba(234,128,252,0.08)'],
    'env'  => ['bg'=>'rgba(140,158,255,0.04)','bdr'=>'rgba(140,158,255,0.25)','hd'=>'rgba(140,158,255,0.08)'],
    'doc'  => ['bg'=>'rgba(255,110,64,0.04)', 'bdr'=>'rgba(255,110,64,0.22)', 'hd'=>'rgba(255,110,64,0.08)'],
    'chk'  => ['bg'=>'rgba(100,221,23,0.04)', 'bdr'=>'rgba(100,221,23,0.22)', 'hd'=>'rgba(100,221,23,0.08)'],
    'rem'  => ['bg'=>'rgba(255,61,90,0.03)',  'bdr'=>'rgba(255,61,90,0.20)',  'hd'=>'rgba(255,61,90,0.07)'],
];
// Light theme overrides
$secColorsLight = [
    'proj' => ['bg'=>'#f0fbff', 'bdr'=>'#b3e8f7', 'hd'=>'#ddf4fd'],
    'dept' => ['bg'=>'#f0fff6', 'bdr'=>'#b3f0d0', 'hd'=>'#ddfff0'],
    'app'  => ['bg'=>'#fffdf0', 'bdr'=>'#f7e8b3', 'hd'=>'#fff8dd'],
    'db'   => ['bg'=>'#fdf0ff', 'bdr'=>'#e8b3f7', 'hd'=>'#f8ddff'],
    'env'  => ['bg'=>'#f2f0ff', 'bdr'=>'#c4b3f7', 'hd'=>'#ebe0ff'],
    'doc'  => ['bg'=>'#fff3ed', 'bdr'=>'#ffc4ab', 'hd'=>'#ffe4d8'],
    'chk'  => ['bg'=>'#f3ffe8', 'bdr'=>'#c0f0a0', 'hd'=>'#e6ffd4'],
    'rem'  => ['bg'=>'#fff0f2', 'bdr'=>'#f7b3be', 'hd'=>'#ffdde0'],
];
$sc = $theme === 'light' ? $secColorsLight : $secColors;
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?=$theme?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DevVault — <?=$p?'Edit':'Add'?> Project</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --acc:<?=$accent?>;
  --user-bg:<?=$bg?:'unset'?>;
  --bg:#070b14;--sur:#0d1422;--sur2:#111a2e;--bdr:#1e2d4a;
  --tx:#e8edf5;--mt:#5a7a9a;--ok:#00e676;--err:#ff3d5a;--amb:#ffd740;
}
[data-theme="light"]{
  --bg:#f0f4f8;--sur:#ffffff;--sur2:#e8edf5;--bdr:#c8d4e0;--tx:#0d1422;--mt:#6b7f96;
}
html{font-size:<?=$fsize?>px}
body{font-family:'<?=$ffamily?>',sans-serif;color:var(--tx);min-height:100vh;
  background:var(--user-bg,var(--bg))}
body::before{content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:linear-gradient(var(--bdr) 1px,transparent 1px),
  linear-gradient(90deg,var(--bdr) 1px,transparent 1px);
  background-size:44px 44px;opacity:.13}

/* TOPBAR */
.bar{position:sticky;top:0;z-index:200;height:50px;
  background:color-mix(in srgb,var(--bg) 92%,transparent);
  border-bottom:1px solid var(--bdr);backdrop-filter:blur(14px);
  display:flex;align-items:center;padding:0 18px;gap:12px}
.logo{font-family:'Courier New',Consolas,monospace;font-size:14px;font-weight:900;
  color:var(--acc);letter-spacing:2px;text-shadow:0 0 14px var(--acc)}
.bar-r{margin-left:auto;display:flex;gap:8px}
.btn{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:7px;
  font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;border:none;
  text-decoration:none;transition:all .15s;letter-spacing:.3px}
.btn:active{transform:scale(.97)}
.btn-ghost{background:var(--sur2);color:var(--mt);border:1px solid var(--bdr)}
.btn-ghost:hover{color:var(--tx);border-color:var(--mt)}
.btn-save{background:var(--acc);color:#000;padding:8px 22px;font-size:14px}
.btn-save:hover{opacity:.85}
.btn-cancel{background:var(--sur2);color:var(--mt);border:1px solid var(--bdr);padding:8px 16px;font-size:14px}
.btn-cancel:hover{color:var(--tx)}

/* LAYOUT */
.wrap{padding:14px 16px 80px;position:relative;z-index:1;max-width:1200px;margin:0 auto}

/* SECTION CARDS — each with unique tint */
.sec{border-radius:12px;overflow:hidden;margin-bottom:14px;transition:box-shadow .2s}
.sec:focus-within{box-shadow:0 0 0 2px var(--acc)30}
.sec-hd{display:flex;align-items:center;gap:8px;padding:10px 16px;cursor:pointer;user-select:none}
.sec-hd h2{font-family:'Courier New',Consolas,monospace;font-size:11px;font-weight:700;
  color:var(--acc);letter-spacing:1.8px;text-transform:uppercase;flex:1}
.chev{color:var(--mt);font-size:11px;transition:transform .2s}
.sec-hd.closed .chev{transform:rotate(-90deg)}
.sec-bd{padding:14px 16px}
.sec-bd.gone{display:none}

/* ROWS */
.row{display:flex;gap:10px;align-items:end;flex-wrap:wrap;margin-bottom:10px}
.row:last-child{margin-bottom:0}
.f{flex:1;min-width:130px;display:flex;flex-direction:column;gap:4px}
.f.w15{flex:1.5}
.f.w2{flex:2}
.f.w3{flex:3}

/* FIELDS */
label,.lbl{display:block;font-family:'Courier New',Consolas,monospace;font-size:9.5px;
  text-transform:uppercase;letter-spacing:1.3px;color:var(--mt)}
.req{color:var(--err)}
input,select,textarea{
  background:var(--sur2);border:1px solid var(--bdr);border-radius:7px;
  padding:7px 10px;color:var(--tx);font-size:13px;font-family:'Segoe UI',Tahoma,Arial,sans-serif;
  font-weight:500;outline:none;width:100%;transition:border-color .18s,box-shadow .18s}
input:focus,select:focus,textarea:focus{
  border-color:var(--acc);box-shadow:0 0 0 2px color-mix(in srgb,var(--acc) 12%,transparent)}
input::placeholder,textarea::placeholder{color:var(--mt);font-size:12px}
select option{background:var(--sur2)}
textarea{resize:vertical;min-height:70px;line-height:1.5}

/* PASSWORD */
.pw{position:relative}
.pw input{padding-right:34px;font-family:'Courier New',Consolas,monospace;letter-spacing:.5px}
.pw-eye{position:absolute;right:8px;top:50%;transform:translateY(-50%);
  background:none;border:none;cursor:pointer;color:var(--mt);font-size:13px;padding:2px;line-height:1}
.pw-eye:hover{color:var(--acc)}

.hint{font-family:'Courier New',Consolas,monospace;font-size:9px;color:var(--mt);margin-top:2px}
.cond{display:none!important}
.cond.on{display:flex!important;flex-direction:column;gap:4px}

/* ENV TABLE */
.etbl{width:100%;border-collapse:collapse;min-width:660px}
.etbl th{font-family:'Courier New',Consolas,monospace;font-size:9px;text-transform:uppercase;
  letter-spacing:1px;color:var(--mt);padding:5px 7px 8px;text-align:left;border-bottom:1px solid var(--bdr)}
.etbl td{padding:4px 5px;border-bottom:1px solid color-mix(in srgb,var(--bdr) 60%,transparent);vertical-align:middle}
.etbl tr:last-child td{border-bottom:none}
.etbl input{padding:6px 8px;font-size:12px}
.env-nm{font-family:'Courier New',Consolas,monospace;font-size:11px;font-weight:700;
  display:flex;align-items:center;gap:5px;white-space:nowrap;padding:4px 8px}
.dot{width:7px;height:7px;border-radius:50%;flex-shrink:0}

/* STICKY FOOTER — only once at bottom */
.form-footer{position:fixed;bottom:0;left:0;right:0;z-index:100;
  background:color-mix(in srgb,var(--bg) 96%,transparent);
  border-top:1px solid var(--bdr);backdrop-filter:blur(14px);
  padding:10px 20px;display:flex;justify-content:flex-end;gap:10px}

/* ERR */
.err{background:color-mix(in srgb,var(--err) 8%,transparent);
  border:1px solid color-mix(in srgb,var(--err) 30%,transparent);
  color:var(--err);padding:9px 14px;border-radius:8px;
  font-size:12px;font-family:'Courier New',Consolas,monospace;margin:10px 0}

@media(max-width:600px){.row{flex-direction:column}.f,.f.w15,.f.w2,.f.w3{min-width:0;flex:1}}

/* NEW: badge pill, checklist, contact rows, doc rows */
.badge-pill{display:inline-block;padding:2px 9px;border-radius:20px;font-size:10px;
  font-weight:700;text-transform:uppercase;letter-spacing:.6px;
  background:var(--sur2);border:1px solid var(--bdr);color:var(--mt);
  font-family:'Courier New',Consolas,monospace}
.btn-xs{padding:4px 10px;font-size:11px;border-radius:6px}
.contact-row{padding-top:4px}
.checklist-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:8px 14px}
.chk-item{display:flex;flex-direction:column;gap:4px;padding:8px 10px;
  background:var(--sur2);border:1px solid var(--bdr);border-radius:8px}
.chk-label{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600;cursor:pointer}
.chk-label input[type=checkbox]{width:16px;height:16px;accent-color:var(--acc);cursor:pointer;flex-shrink:0}
.chk-note{font-size:11px!important;padding:5px 8px!important;background:var(--sur)!important}
.doc-row{display:flex;gap:8px;align-items:end;margin-bottom:8px;flex-wrap:wrap}
.doc-row .f{min-width:120px}
</style>
</head>
<body>

<div class="bar">
  <span class="logo">DEVVAULT</span>
  <span style="color:var(--bdr);font-size:18px">|</span>
  <span style="font-size:14px;font-weight:700"><?=$p?'✏ Edit':'＋ Add'?> Project</span>
  <div class="bar-r">
    <a href="index.php" class="btn btn-ghost">← Back</a>
    <span id="session-timer-display" title="Session timer">⏱ 05:00</span>
    <a href="logout.php" class="btn btn-ghost">⏏ Logout</a>
  </div>
</div>

<div class="wrap">
<?php if($error):?><div class="err">⚠ <?= nl2br(htmlspecialchars($error)) ?></div><?php endif;?>

<form method="POST" id="pf" enctype="multipart/form-data">
<input type="hidden" name="csrf" value="<?=csrf_token()?>">

<!-- ═══════════════════════════════════════════════════════
     1. PROJECT INFORMATION  (cyan tint)
════════════════════════════════════════════════════════════ -->
<div class="sec" style="background:<?=$sc['proj']['bg']?>;border:1px solid <?=$sc['proj']['bdr']?>">
  <div class="sec-hd" style="background:<?=$sc['proj']['hd']?>" onclick="tog(this)">
    <h2>🗂 Project Information</h2><span class="chev">▾</span>
  </div>
  <div class="sec-bd">
    <!-- Row 1: Technology + Website/App (dropdown) + Project Name -->
    <div class="row">
      <div class="f w15">
        <label>Technology <span class="req">*</span></label>
        <select name="technology" id="technology_sel" onchange="showCond(this,'tech-oth');updateSubtype(this.value)">
          <option value="">— Select Technology —</option>
          <?php foreach($techOpts as $t):?>
          <option value="<?=htmlspecialchars($t)?>" <?=$sel('technology',$t)?>><?=htmlspecialchars($t)?></option>
          <?php endforeach;?>
        </select>
      </div>
      <div class="f cond <?=($p['technology']??'')==='Other'?'on':''?>" id="tech-oth">
        <label>Specify Technology</label>
        <input type="text" name="technology_other" placeholder="Enter tech name" value="<?=$v('technology_other')?>">
        <span class="hint">Auto-saved to dropdown</span>
      </div>
      <div class="f w15" id="tech-subtype-wrap">
        <label>Technology Sub-Type</label>
        <select name="tech_subtype" id="tech_subtype">
          <option value="">— Select Sub-Type —</option>
        </select>
        <span class="hint">Auto-updates based on Technology selected</span>
      </div>
      <div class="f w15">
        <label>Website / Application</label>
        <select name="website_app" id="website_app_sel" onchange="handleWebsiteApp(this.value)">
          <option value="">— Select Type —</option>
          <?php foreach(['Website','Application','Both','Mobile App','Portal','API','Microsite','Other'] as $wa):?>
          <option value="<?=$wa?>" <?=$sel('website_app',$wa)?>><?=$wa?></option>
          <?php endforeach;?>
        </select>
      </div>
      <!-- Microsite: Parent Portal field (shown only when Microsite selected) -->
      <div class="f" id="parent-portal-f" style="<?= ($p['website_app']??'')==='Microsite' ? '' : 'display:none' ?>">
        <label>Parent Department / Sectoral Portal</label>
        <input type="text" name="parent_sectoral_portal"
               placeholder="e.g. NIC Portal, State IT Portal"
               value="<?= htmlspecialchars($p['parent_sectoral_portal'] ?? '') ?>">
        <span class="hint">Microsite kis portal ke under hai?</span>
      </div>
      <div class="f w3">
        <label>Project Name <span class="req">*</span></label>
        <input type="text" name="project_name" placeholder="e.g. Employee Portal" required value="<?=$v('project_name')?>">
      </div>
    </div>
    <!-- Row 2: Description -->
    <div class="row">
      <div class="f">
        <label>Description</label>
        <textarea name="description" rows="2" placeholder="Short description of the project..."><?=$v('description')?></textarea>
      </div>
    </div>
    <!-- Row 3: Status + Audit Date + Live Date + Visitors + Last Update (all in one row) -->
    <div class="row">
      <div class="f w15">
        <label>Current Status</label>
        <select name="current_status" id="ssel" onchange="handleStatus(this.value)">
          <option value="request_received" <?=$sel('current_status','request_received')?>>📨 Request Received</option>
          <option value="live" <?=$sel('current_status','live')?>>🟢 Live</option>
          <option value="under_development" <?=$sel('current_status','under_development')?>>🟡 Under Development</option>
          <option value="redevelopment" <?=$sel('current_status','redevelopment')?>>🔵 Redevelopment</option>
          <option value="hold_by_department" <?=$sel('current_status','hold_by_department')?>>⏸ Hold by Department</option>
          <option value="content_updation" <?=$sel('current_status','content_updation')?>>📝 Content Updation in Progress</option>
          <option value="closed" <?=$sel('current_status','closed')?>>🔴 Closed</option>
        </select>
      </div>
      <div class="f">
        <label>Last Audit Date</label>
        <input type="date" name="last_audit_date" value="<?=$v('last_audit_date')?>">
      </div>
      <div class="f" id="live-date-f" style="<?=($p['current_status']??'live')!=='live'?'display:none':''?>">
        <label>Live Date</label>
        <input type="date" name="live_date" value="<?=$v('live_date')?>">
      </div>
      <div class="f" id="closed-date-f" style="<?=($p['current_status']??'')!=='closed'?'display:none':''?>">
        <label>Closed Date</label>
        <input type="date" name="closed_date" value="<?=$v('closed_date')?>">
      </div>
    </div>
    <?php
    // T-05: Load last 5 visitor log entries for this project
    $vl_entries = [];
    if ($id) {
        $st_vl = $db->prepare("SELECT * FROM project_visitor_log WHERE project_id=? ORDER BY entry_date DESC, entered_at DESC LIMIT 5");
        $st_vl->execute([$id]);
        $vl_entries = $st_vl->fetchAll();
    }
    ?>
    <!-- T-05: Visitor Log Entry Section -->
    <div id="visitor-log-section" style="<?=($p['current_status']??'live')!=='live'?'display:none':''?>; margin-top:12px;">
      <div style="background:rgba(0,180,120,0.06);border:1px solid rgba(0,180,120,0.2);border-radius:8px;padding:14px 16px;">
        <div style="font-size:13px;font-weight:600;color:var(--c-accent);margin-bottom:10px;">📊 Add Visitor Log Entry</div>
        <div class="row">
          <div class="f">
            <label>Entry Date <span style="color:#f55">*</span></label>
            <input type="date" name="vl_entry_date" id="vl_entry_date" max="<?=date('Y-m-d')?>" value="<?=date('Y-m-d')?>">
          </div>
          <div class="f">
            <label>Visitor Count <span style="color:#f55">*</span></label>
            <input type="number" name="vl_visitor_count" min="0" placeholder="e.g. 125000">
          </div>
          <div class="f">
            <label>Site Last Update Date</label>
            <input type="date" name="vl_site_last_update_date" max="<?=date('Y-m-d')?>">
          </div>
          <div class="f w2">
            <label>Remarks</label>
            <input type="text" name="vl_remarks" placeholder="Optional note">
          </div>
        </div>
        <span class="hint">Leave blank to skip adding a new log entry (existing data retained)</span>
        <?php if ($vl_entries): ?>
        <div style="margin-top:10px;font-size:12px;color:var(--c-muted);">Recent entries:</div>
        <table style="width:100%;font-size:12px;margin-top:6px;border-collapse:collapse;">
          <tr style="color:var(--c-muted);text-align:left;">
            <th style="padding:3px 6px">Date</th><th style="padding:3px 6px">Visitors</th>
            <th style="padding:3px 6px">Site Updated</th><th style="padding:3px 6px">Remarks</th>
          </tr>
          <?php foreach ($vl_entries as $ve): ?>
          <tr style="border-top:1px solid rgba(255,255,255,0.07)">
            <td style="padding:3px 6px"><?=htmlspecialchars($ve['entry_date'])?></td>
            <td style="padding:3px 6px"><?=number_format((int)$ve['visitor_count'])?></td>
            <td style="padding:3px 6px"><?=htmlspecialchars($ve['site_last_update_date']??'-')?></td>
            <td style="padding:3px 6px"><?=htmlspecialchars($ve['remarks']??'')?></td>
          </tr>
          <?php endforeach; ?>
        </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     2. DEPARTMENT INFO  (green tint)
════════════════════════════════════════════════════════════ -->
<div class="sec" style="background:<?=$sc['dept']['bg']?>;border:1px solid <?=$sc['dept']['bdr']?>">
  <div class="sec-hd" style="background:<?=$sc['dept']['hd']?>" onclick="tog(this)">
    <h2>🏢 Department Info</h2><span class="chev">▾</span>
  </div>
  <div class="sec-bd">
    <!-- Row 0: Parent Admin Department + Department Name -->
    <div class="row">
      <div class="f w2">
        <label>Parent / Admin Department</label>
        <input type="text" name="parent_admin_dept" placeholder="e.g. Dept of IT & Electronics (Parent)" value="<?=$v('parent_admin_dept')?>">
        <span class="hint">Overarching administrative department</span>
      </div>
      <div class="f w2">
        <label>Department Name</label>
        <input type="text" name="department_name" placeholder="e.g. IT Department" value="<?=$v('department_name')?>">
      </div>
    </div>
    <!-- Row 1: Primary nodal officer -->
    <div class="row">
      <div class="f w2">
        <label>Nodal Officer Name</label>
        <input type="text" name="nodal_officer_name" placeholder="Full name" value="<?=$v('nodal_officer_name')?>">
      </div>
      <div class="f">
        <label>Designation</label>
        <input type="text" name="nodal_designation" placeholder="Sr. Developer" value="<?=$v('nodal_designation')?>">
      </div>
      <div class="f">
        <label>Contact No.</label>
        <input type="tel" name="nodal_contact" placeholder="10-digit mobile" value="<?=$v('nodal_contact')?>">
      </div>
      <div class="f w2">
        <label>Department Email</label>
        <input type="email" name="dept_email" placeholder="dept@gov.in" value="<?=$v('dept_email')?>">
      </div>
    </div>

    <!-- Additional Contact Persons -->
    <div class="row" style="margin-top:4px;border-top:1px dashed var(--bdr);padding-top:10px">
      <div class="f" style="flex:none">
        <label style="margin-bottom:0">👥 Additional Contact Persons</label>
      </div>
      <div class="f" style="flex:none;margin-left:auto">
        <button type="button" class="btn btn-ghost btn-xs" onclick="addContactRow()">➕ Add Contact</button>
      </div>
    </div>
    <div id="contacts-wrap">
      <?php foreach($contacts as $i=>$c):?>
      <div class="row contact-row">
        <div class="f w2"><label>Name</label>
          <input type="text" name="contact_name[]" placeholder="Full name" value="<?=htmlspecialchars($c['name'])?>"></div>
        <div class="f"><label>Designation</label>
          <input type="text" name="contact_designation[]" placeholder="Designation" value="<?=htmlspecialchars($c['designation'])?>"></div>
        <div class="f"><label>Contact No.</label>
          <input type="tel" name="contact_contact[]" placeholder="Mobile" value="<?=htmlspecialchars($c['contact'])?>"></div>
        <div class="f w2"><label>Email</label>
          <input type="email" name="contact_email[]" placeholder="email@gov.in" value="<?=htmlspecialchars($c['email'])?>"></div>
        <div class="f" style="flex:none;align-self:center">
          <button type="button" class="btn btn-danger btn-xs" onclick="this.closest('.contact-row').remove()" style="margin-top:14px">🗑</button>
        </div>
      </div>
      <?php endforeach;?>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     3. APP INFRASTRUCTURE  (amber tint)
════════════════════════════════════════════════════════════ -->
<div class="sec" style="background:<?=$sc['app']['bg']?>;border:1px solid <?=$sc['app']['bdr']?>">
  <div class="sec-hd" style="background:<?=$sc['app']['hd']?>" onclick="tog(this)">
    <h2>🖥 App Infrastructure</h2><span class="chev">▾</span>
  </div>
  <div class="sec-bd">
    <!-- Row 1: App IP + LB IP + OS + OS-Other -->
    <div class="row">
      <div class="f"><label>App IP</label>
        <input type="text" name="app_ip" placeholder="192.168.x.x" value="<?=$v('app_ip')?>"></div>
      <div class="f"><label>LB IP</label>
        <input type="text" name="app_lb_ip" placeholder="Load Balancer IP" value="<?=$v('app_lb_ip')?>"></div>
      <div class="f w15"><label>OS Version</label>
        <select name="app_os" onchange="showCond(this,'aosos')">
          <option value="">— Select OS —</option>
          <?php foreach($appOsOpts as $o):?>
          <option value="<?=htmlspecialchars($o)?>" <?=$sel('app_os',$o)?>><?=htmlspecialchars($o)?></option>
          <?php endforeach;?>
        </select>
      </div>
      <div class="f cond <?=($p['app_os']??'')==='Other'?'on':''?>" id="aosos">
        <label>Specify OS</label>
        <input type="text" name="app_os_other" placeholder="e.g. Win 2022" value="<?=$v('app_os_other')?>">
        <span class="hint">Auto-saved</span>
      </div>
    </div>
    <!-- Row 2: Core + RAM + Primary + Secondary + Hosting -->
    <div class="row">
      <div class="f"><label>Core</label>
        <input type="text" name="app_core" placeholder="4 vCPU" value="<?=$v('app_core')?>"></div>
      <div class="f"><label>RAM</label>
        <input type="text" name="app_ram" placeholder="8 GB" value="<?=$v('app_ram')?>"></div>
      <div class="f w15"><label>Primary Storage</label>
        <input type="text" name="app_primary_storage" placeholder="100 GB SSD" value="<?=$v('app_primary_storage')?>"></div>
      <div class="f w15"><label>Secondary Storage</label>
        <input type="text" name="app_secondary_storage" placeholder="500 GB HDD" value="<?=$v('app_secondary_storage')?>"></div>
      <div class="f"><label>Hosting</label>
        <select name="app_hosting_type">
          <option value="individual" <?=$sel('app_hosting_type','individual')?>>Individual</option>
          <option value="shared" <?=$sel('app_hosting_type','shared')?>>Shared</option>
        </select>
      </div>
    </div>
    <!-- Row 3: Remark -->
    <div class="row">
      <div class="f"><label>App Infra Remark</label>
        <textarea name="app_infra_remark" rows="2" placeholder="Notes about application infrastructure..."><?=$v('app_infra_remark')?></textarea>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     4. DB INFRASTRUCTURE  (purple tint)
════════════════════════════════════════════════════════════ -->
<div class="sec" style="background:<?=$sc['db']['bg']?>;border:1px solid <?=$sc['db']['bdr']?>">
  <div class="sec-hd" style="background:<?=$sc['db']['hd']?>" onclick="tog(this)">
    <h2>🗄 DB Infrastructure</h2><span class="chev">▾</span>
  </div>
  <div class="sec-bd">
    <!-- Row 1: DB IP + DB Name + DB Technology + DB Version -->
    <div class="row">
      <div class="f"><label>DB IP</label>
        <input type="text" name="db_ip" placeholder="192.168.x.x" value="<?=$v('db_ip')?>"></div>
      <div class="f"><label>DB Name</label>
        <input type="text" name="db_name" placeholder="myapp_db" value="<?=$v('db_name')?>"></div>
      <div class="f w15"><label>DB Technology</label>
        <select name="db_technology" onchange="showCond(this,'dbtechoth')">
          <option value="">— Select Technology —</option>
          <?php foreach($dbTechOpts as $o):?>
          <option value="<?=htmlspecialchars($o)?>" <?=$sel('db_technology',$o)?>><?=htmlspecialchars($o)?></option>
          <?php endforeach;?>
        </select>
        <span class="hint">e.g. MySQL, MongoDB, NoSQL...</span>
      </div>
      <div class="f cond <?=($p['db_technology']??'')==='Other'?'on':''?>" id="dbtechoth">
        <label>Specify Technology</label>
        <input type="text" name="db_technology_other" placeholder="e.g. Redis, Cassandra" value="<?=$v('db_technology_other')?>">
        <span class="hint">Auto-saved</span>
      </div>
    </div>
    <!-- Row 1b: DB Version + Version-Other -->
    <div class="row">
      <div class="f w15"><label>DB Version</label>
        <select name="db_version" onchange="showCond(this,'dbvoth')">
          <option value="">— Select Version —</option>
          <?php foreach($dbVerOpts as $o):?>
          <option value="<?=htmlspecialchars($o)?>" <?=$sel('db_version',$o)?>><?=htmlspecialchars($o)?></option>
          <?php endforeach;?>
        </select>
      </div>
      <div class="f cond <?=($p['db_version']??'')==='Other'?'on':''?>" id="dbvoth">
        <label>Specify Version</label>
        <input type="text" name="db_version_other" placeholder="e.g. MongoDB 6.0" value="<?=$v('db_version_other')?>">
        <span class="hint">Auto-saved</span>
      </div>
    </div>
    <!-- Row 2: DB OS + OS-Other + Hosting -->
    <div class="row">
      <div class="f w15"><label>DB Server OS</label>
        <select name="db_os" onchange="showCond(this,'dbosos')">
          <option value="">— Select OS —</option>
          <?php foreach($dbOsOpts as $o):?>
          <option value="<?=htmlspecialchars($o)?>" <?=$sel('db_os',$o)?>><?=htmlspecialchars($o)?></option>
          <?php endforeach;?>
        </select>
      </div>
      <div class="f cond <?=($p['db_os']??'')==='Other'?'on':''?>" id="dbosos">
        <label>Specify DB OS</label>
        <input type="text" name="db_os_other" placeholder="e.g. RHEL 9" value="<?=$v('db_os_other')?>">
        <span class="hint">Auto-saved</span>
      </div>
      <div class="f"><label>Hosting</label>
        <select name="db_hosting_type">
          <option value="individual" <?=$sel('db_hosting_type','individual')?>>Individual</option>
          <option value="shared" <?=$sel('db_hosting_type','shared')?>>Shared</option>
        </select>
      </div>
    </div>
    <!-- Row 3: DB Remark -->
    <div class="row">
      <div class="f"><label>DB Remark</label>
        <textarea name="db_remark" rows="2" placeholder="Notes about database..."><?=$v('db_remark')?></textarea>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     5. ENVIRONMENT DETAILS  (indigo tint)
════════════════════════════════════════════════════════════ -->
<div class="sec" style="background:<?=$sc['env']['bg']?>;border:1px solid <?=$sc['env']['bdr']?>">
  <div class="sec-hd" style="background:<?=$sc['env']['hd']?>" onclick="tog(this)">
    <h2>🌐 Environment Details</h2><span class="chev">▾</span>
  </div>
  <div class="sec-bd" style="padding:10px 12px;overflow-x:auto">
    <table class="etbl">
      <thead>
        <tr>
          <th style="width:96px">Environment</th>
          <th>URL</th><th>Admin URL</th><th>Login ID</th>
          <th style="width:168px">Password</th><th>Remark</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach(['local'=>['#40c4ff','🔵'],'staging'=>['#ffd740','🟡'],
                     'production'=>['#00e676','🟢'],'audit'=>['#ea80fc','🟣'],
                     'other'=>['#8c9eff','⚪']] as $env=>[$clr,$ico]):
        $ep = fn($f) => htmlspecialchars($p["env_{$env}_{$f}"] ?? '');
        $hasPw = !empty($p["env_{$env}_password"]);
      ?>
      <tr>
        <td><div class="env-nm" style="color:<?=$clr?>">
          <span class="dot" style="background:<?=$clr?>"></span><?=ucfirst($env)?>
        </div></td>
        <td><input type="url" name="env_<?=$env?>_url" placeholder="https://..." value="<?=$ep('url')?>"></td>
        <td><input type="url" name="env_<?=$env?>_admin_url" placeholder="https://.../admin" value="<?=$ep('admin_url')?>"></td>
        <td><input type="text" name="env_<?=$env?>_id" placeholder="username/email" autocomplete="off" value="<?=$ep('id')?>"></td>
        <td><div class="pw">
          <input type="password" name="env_<?=$env?>_password" id="pw<?=$env?>"
            placeholder="<?=$hasPw?'(keep existing)':'password'?>" autocomplete="new-password">
          <button type="button" class="pw-eye" onclick="tpw('pw<?=$env?>')">👁</button>
        </div></td>
        <td><input type="text" name="env_<?=$env?>_remark" placeholder="remark..." value="<?=$ep('remark')?>"></td>
      </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     6. DOCUMENTS  (orange tint)
════════════════════════════════════════════════════════════ -->
<div class="sec" style="background:<?=$sc['doc']['bg']?>;border:1px solid <?=$sc['doc']['bdr']?>">
  <div class="sec-hd" style="background:<?=$sc['doc']['hd']?>" onclick="tog(this)">
    <h2>📎 Documents (SOE / UAT / Audit / Other)</h2><span class="chev">▾</span>
  </div>
  <div class="sec-bd">
    <?php if($documents):?>
    <div style="margin-bottom:12px">
      <table class="etbl" style="min-width:0">
        <thead><tr><th>Type</th><th>Title / File</th><th>Size</th><th>Uploaded</th><th style="width:70px">Action</th></tr></thead>
        <tbody>
        <?php foreach($documents as $d):?>
        <tr>
          <td><span class="badge-pill"><?=htmlspecialchars($d['doc_type'])?></span></td>
          <td>
            <a href="api.php?action=download_doc&id=<?=$d['id']?>&csrf=<?=csrf_token()?>" style="color:var(--acc);text-decoration:none">
              📄 <?=htmlspecialchars($d['title']?:$d['filename'])?>
            </a>
            <?php if(($d['version']??1) > 1): ?>
              <span style="font-size:9px;font-weight:700;color:var(--acc);background:rgba(0,212,255,.1);border:1px solid rgba(0,212,255,.2);border-radius:4px;padding:1px 5px;margin-left:3px">v<?=intval($d['version'])?></span>
            <?php endif; ?>
            <?php if(($d['is_latest']??1) == 0): ?>
              <span style="font-size:9px;color:var(--muted);border:1px solid var(--border);border-radius:4px;padding:1px 4px;margin-left:2px">old</span>
            <?php endif; ?>
          </td>
          <td style="font-family:'Courier New',Consolas,monospace;font-size:11px"><?=round($d['file_size']/1024,1)?> KB</td>
          <td style="font-family:'Courier New',Consolas,monospace;font-size:11px"><?=date('d M Y',strtotime($d['uploaded_at']))?></td>
          <td>
            <button type="button" class="btn btn-danger btn-xs" onclick="delDoc(<?=$d['id']?>,'<?=csrf_token()?>')">🗑</button>
          </td>
        </tr>
        <?php endforeach;?>
        </tbody>
      </table>
    </div>
    <?php endif;?>

    <div class="row" style="margin-bottom:4px">
      <div class="f" style="flex:none">
        <label style="margin-bottom:0">📤 Upload New Documents</label>
      </div>
      <div class="f" style="flex:none;margin-left:auto">
        <button type="button" class="btn btn-ghost btn-xs" onclick="addDocRow()">➕ Add File</button>
      </div>
    </div>
    <div id="docs-wrap"></div>
    <span class="hint">Allowed: pdf, doc, docx, xls, xlsx, jpg, png, zip, txt, csv — max 10MB each</span>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     7. WEBSITE CHECKLIST  (lime tint)
════════════════════════════════════════════════════════════ -->
<div class="sec" style="background:<?=$sc['chk']['bg']?>;border:1px solid <?=$sc['chk']['bdr']?>">
  <div class="sec-hd" style="background:<?=$sc['chk']['hd']?>" onclick="tog(this)">
    <h2>✅ Website Compliance Checklist</h2><span class="chev">▾</span>
  </div>
  <div class="sec-bd">
    <div class="checklist-grid">
      <?php foreach($checklistItems as $item):
        $r = $checklistResp[$item['id']] ?? ['checked'=>0,'notes'=>''];
      ?>
      <div class="chk-item">
        <label class="chk-label">
          <input type="checkbox" name="chk_item_<?=$item['id']?>" <?=$r['checked']?'checked':''?>>
          <span><?=htmlspecialchars($item['item_name'])?></span>
        </label>
        <input type="text" name="chk_note_<?=$item['id']?>" placeholder="note (optional)" value="<?=htmlspecialchars($r['notes'])?>" class="chk-note">
      </div>
      <?php endforeach;?>
    </div>
    <?php if(empty($checklistItems)):?>
    <p class="hint">No checklist items configured. Admin can add items in Admin Panel.</p>
    <?php endif;?>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     6. GENERAL REMARKS  (red tint)
════════════════════════════════════════════════════════════ -->
<div class="sec" style="background:<?=$sc['rem']['bg']?>;border:1px solid <?=$sc['rem']['bdr']?>">
  <div class="sec-hd" style="background:<?=$sc['rem']['hd']?>" onclick="tog(this)">
    <h2>💰 AMC / Financial Details</h2><span class="chev">▾</span>
  </div>
  <div class="sec-bd">
    <div class="row">
      <div class="f w15">
        <label>AMC Type</label>
        <select name="amc_type">
          <option value="">— Select —</option>
          <?php foreach(['Paid','Exemption','Free','NA'] as $at):?>
          <option value="<?=$at?>" <?=$sel('amc_type',$at)?>><?=$at?></option>
          <?php endforeach;?>
        </select>
      </div>
      <div class="f w15">
        <label>AMC Amount (₹)</label>
        <input type="number" name="amc_amount" step="0.01" min="0"
          placeholder="e.g. 150000" value="<?=$v('amc_amount')?>">
      </div>
      <div class="f w15">
        <label>AMC Start Date</label>
        <input type="date" name="amc_start_date" value="<?=$v('amc_start_date')?>">
      </div>
      <div class="f w15">
        <label>AMC End Date</label>
        <input type="date" name="amc_end_date" value="<?=$v('amc_end_date')?>">
      </div>
    </div>
    <div class="row">
      <div class="f">
        <label>AMC Remarks</label>
        <input type="text" name="amc_remarks"
          placeholder="e.g. Renewed via GFR, Vendor NIC, PO No. 123"
          value="<?=$v('amc_remarks')?>">
      </div>
    </div>
  </div>
</div>

<div class="sec" style="background:<?=$sc['rem']['bg']?>;border:1px solid <?=$sc['rem']['bdr']?>">
  <div class="sec-hd" style="background:<?=$sc['rem']['hd']?>" onclick="tog(this)">
    <h2>📝 General Remarks</h2><span class="chev">▾</span>
  </div>
  <div class="sec-bd">
    <div class="row">
      <div class="f"><label>Notes / Remarks</label>
        <textarea name="general_remark" rows="5"
          placeholder="SSH commands, deployment steps, API key locations, config details, important notes..."><?=$v('general_remark')?></textarea>
      </div>
    </div>
  </div>
</div>

<input type="hidden" name="tech_change_reason" id="tech_change_reason_input" value="">
</form>
</div><!-- /wrap -->

<!-- SINGLE sticky footer -->
<div class="form-footer">
  <a href="index.php" class="btn btn-cancel">✕ Cancel</a>
  <button type="submit" form="pf" class="btn btn-save">💾 Save Project</button>
</div>

<script nonce="<?= csp_nonce() ?>">
function tog(hd){hd.classList.toggle('closed');hd.nextElementSibling.classList.toggle('gone')}
function showCond(sel,id){
  const el=document.getElementById(id);if(!el)return;
  if(sel.value==='Other'){el.classList.add('on')}else{el.classList.remove('on')}}
function tpw(id){const f=document.getElementById(id);if(f)f.type=f.type==='password'?'text':'password'}
function handleStatus(v){
  const isLive=v==='live';
  const isClosed=v==='closed';
  const el=id=>document.getElementById(id);
  ['live-date-f','visitor-log-section'].forEach(id=>{const e=el(id);if(e)e.style.display=isLive?'':'none';});
  const cdf=el('closed-date-f');if(cdf)cdf.style.display=isClosed?'':'none';
  // Detect tech change - show reason prompt
}
document.addEventListener('DOMContentLoaded',()=>handleStatus(document.getElementById('ssel').value))

function handleWebsiteApp(v){
  var f=document.getElementById('parent-portal-f');
  if(f) f.style.display=(v==='Microsite')?'':'none';
}
document.addEventListener('DOMContentLoaded',function(){
  var s=document.getElementById('website_app_sel');
  if(s) handleWebsiteApp(s.value);
});

// T-06: Tech change reason modal
var _origTech='', _origSubtype='';
document.addEventListener('DOMContentLoaded',function(){
  var ts=document.getElementById('technology');var ss=document.getElementById('tech_subtype');
  if(ts){_origTech=ts.value;}if(ss){_origSubtype=ss.value;}
});
document.getElementById('pf')&&document.getElementById('pf').addEventListener('submit',function(e){
  var ts=document.getElementById('technology');var ss=document.getElementById('tech_subtype');
  if(!ts||!_origTech)return;
  if(ts.value!==_origTech||(ss&&ss.value!==_origSubtype)){
    var reason=prompt('⚠ Technology change detected ('+_origTech+' → '+ts.value+'). Reason? (optional, press Cancel to skip)','');
    if(reason===null)reason='';
    var inp=document.getElementById('tech_change_reason_input');
    if(inp)inp.value=reason;
  }
});

// ── Dynamic contact persons ──
function addContactRow(){
  const wrap=document.getElementById('contacts-wrap');
  const row=document.createElement('div');
  row.className='row contact-row';
  row.innerHTML=`
    <div class="f w2"><label>Name</label><input type="text" name="contact_name[]" placeholder="Full name"></div>
    <div class="f"><label>Designation</label><input type="text" name="contact_designation[]" placeholder="Designation"></div>
    <div class="f"><label>Contact No.</label><input type="tel" name="contact_contact[]" placeholder="Mobile"></div>
    <div class="f w2"><label>Email</label><input type="email" name="contact_email[]" placeholder="email@gov.in"></div>
    <div class="f" style="flex:none;align-self:center">
      <button type="button" class="btn btn-danger btn-xs" onclick="this.closest('.contact-row').remove()" style="margin-top:14px">🗑</button>
    </div>`;
  wrap.appendChild(row);
}

// ── Dynamic document upload rows ──
let docRowIdx=0;
function addDocRow(){
  const wrap=document.getElementById('docs-wrap');
  const row=document.createElement('div');
  row.className='doc-row';
  row.innerHTML=`
    <div class="f" style="flex:0 0 130px"><label>Type</label>
      <select name="doc_type[]">
        <option value="SOE">SOE</option>
        <option value="UAT">UAT</option>
        <option value="Audit">Audit</option>
        <option value="Other">Other</option>
      </select>
    </div>
    <div class="f w2"><label>Title</label><input type="text" name="doc_title[]" placeholder="Document title (optional)"></div>
    <div class="f w2"><label>File</label><input type="file" name="doc_file[]"></div>
    <div class="f" style="flex:none;align-self:center">
      <button type="button" class="btn btn-danger btn-xs" onclick="this.closest('.doc-row').remove()" style="margin-top:14px">🗑</button>
    </div>`;
  wrap.appendChild(row);
}

// ── Delete document via API ──
function delDoc(id,csrf){
  if(!confirm('Delete this document?'))return;
  fetch('api.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:`action=delete_document&id=${id}&csrf=${csrf}`})
    .then(r=>r.json()).then(d=>{if(d.ok)location.reload();else alert('Failed: '+(d.error||''))});
}

// ── Tech sub-type dynamic dropdown ─────────────────────────────────────────
var TECH_SUBTYPES = {
  'Dot Net':    ['Classic ASP','ASP.NET WebForms','ASP.NET MVC','ASP.NET Core','Static','Dynamic','Other'],
  'WebMyWay':  ['Standard','Custom','Static','Dynamic','Other'],
  'AEM':        ['AEM 6.x','AEM Cloud','Other'],
  'WordPress':  ['Standard','Custom Theme','Multisite','Other'],
  'Other':      ['Static','Dynamic','CMS Based','Other'],
};
var SAVED_SUBTYPE = <?= json_encode($v('tech_subtype')) ?>;

function updateSubtype(tech) {
  var sel   = document.getElementById('tech_subtype');
  var wrap  = document.getElementById('tech-subtype-wrap');
  var opts  = TECH_SUBTYPES[tech] || ['Static','Dynamic','Other'];
  sel.innerHTML = '<option value="">— Select Sub-Type —</option>';
  opts.forEach(function(o) {
    var opt = document.createElement('option');
    opt.value = o; opt.textContent = o;
    if (o === SAVED_SUBTYPE) opt.selected = true;
    sel.appendChild(opt);
  });
  wrap.style.display = tech ? '' : 'none';
}

// Init on page load with saved technology
(function(){
  var techSel = document.getElementById('technology_sel');
  if (techSel && techSel.value) updateSubtype(techSel.value);
  else document.getElementById('tech-subtype-wrap').style.display = 'none';
})();

</script>
<script nonce="<?= csp_nonce() ?>">
window.DEVVAULT_CSRF   = '<?= csrf_token() ?>';
window.DEVVAULT_LOGOUT = 'logout.php';
</script>
<script src="session_timer.js"></script>
</body>
</html>
