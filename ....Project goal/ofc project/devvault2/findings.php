<?php
require_once __DIR__ . '/auth.php';
require_login();
$db = get_db();

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$accent  = user_pref('accent','#00d4ff');
$theme   = user_pref('theme','dark');
$font    = user_pref('font_family','Rajdhani');
$fs      = user_pref('font_size','14');
$today   = date('Y-m-d');

// ── POST actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF error');
    $act = $_POST['_action'] ?? '';

    if ($act === 'save_finding' && can_edit()) {
        $fid        = intval($_POST['finding_id'] ?? 0);
        $project_id = intval($_POST['project_id'] ?? 0);
        $desc       = trim($_POST['finding_description'] ?? '');
        $severity   = trim($_POST['severity'] ?? 'Minor');
        $found_by   = trim($_POST['found_by'] ?? '');
        $found_date = trim($_POST['found_date'] ?? '');
        $assigned   = trim($_POST['assigned_to'] ?? '');
        $target     = trim($_POST['target_date'] ?? '') ?: null;
        $status     = trim($_POST['current_status'] ?? 'Open');
        $cl_date    = trim($_POST['closure_date'] ?? '') ?: null;
        $cl_rem     = trim($_POST['closure_remarks'] ?? '');

        if ($desc && $found_date && $project_id) {
            if ($fid) {
                $db->prepare("UPDATE audit_findings SET project_id=?,finding_description=?,severity=?,found_by=?,found_date=?,assigned_to=?,target_date=?,current_status=?,closure_date=?,closure_remarks=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                   ->execute([$project_id,$desc,$severity,$found_by,$found_date,$assigned,$target,$status,$cl_date,$cl_rem,$fid]);
                log_activity('edit_finding',$project_id,"[$severity] ".substr($desc,0,50));
                $_SESSION['flash']=['type'=>'success','msg'=>'✅ Finding updated.'];
            } else {
                $db->prepare("INSERT INTO audit_findings (project_id,finding_description,severity,found_by,found_date,assigned_to,target_date,current_status,closure_date,closure_remarks,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$project_id,$desc,$severity,$found_by,$found_date,$assigned,$target,$status,$cl_date,$cl_rem,$_SESSION['user_id']]);
                log_activity('add_finding',$project_id,"[$severity] ".substr($desc,0,50));
                $_SESSION['flash']=['type'=>'success','msg'=>'✅ Finding logged.'];
            }
        }
        header('Location: '.($_POST['return_url']??'findings.php')); exit;
    }

    if ($act === 'delete_finding' && is_admin()) {
        $fid = intval($_POST['finding_id'] ?? 0);
        $row = $db->prepare("SELECT * FROM audit_findings WHERE id=?");
        $row->execute([$fid]); $row = $row->fetch();
        if ($row) {
            $db->prepare("DELETE FROM audit_findings WHERE id=?")->execute([$fid]);
            log_activity('delete_finding',$row['project_id'],"ID $fid");
            $_SESSION['flash']=['type'=>'success','msg'=>'🗑 Finding deleted.'];
        }
        header('Location: findings.php'); exit;
    }

    if ($act === 'bulk_close' && can_edit()) {
        $ids = array_map('intval', $_POST['finding_ids'] ?? []);
        if ($ids) {
            $in = implode(',', $ids);
            $db->exec("UPDATE audit_findings SET current_status='Closed', closure_date='$today', updated_at=CURRENT_TIMESTAMP WHERE id IN ($in)");
            log_activity('bulk_close_findings', null, "IDs: ".implode(',',$ids));
            $_SESSION['flash']=['type'=>'success','msg'=>'✅ '.count($ids).' finding(s) closed.'];
        }
        header('Location: findings.php'); exit;
    }
}

// ── GET filters ───────────────────────────────────────────────────────────────
$f_project  = intval($_GET['project_id'] ?? 0);
$f_severity = $_GET['severity'] ?? '';
$f_status   = $_GET['status'] ?? '';
$f_from     = $_GET['from_date'] ?? '';
$f_to       = $_GET['to_date'] ?? '';

$where = ['1=1']; $params = [];
if ($f_project)  { $where[] = 'af.project_id=?';    $params[] = $f_project; }
if ($f_severity) { $where[] = 'af.severity=?';       $params[] = $f_severity; }
if ($f_status)   { $where[] = 'af.current_status=?'; $params[] = $f_status; }
if ($f_from)     { $where[] = 'af.found_date >= ?';  $params[] = $f_from; }
if ($f_to)       { $where[] = 'af.found_date <= ?';  $params[] = $f_to; }

$findings = $db->prepare("SELECT af.*, p.project_name,
    CASE WHEN af.target_date < ? AND af.current_status != 'Closed'
         THEN CAST(julianday(?) - julianday(af.target_date) AS INTEGER) ELSE 0 END AS days_overdue
    FROM audit_findings af LEFT JOIN projects p ON af.project_id=p.id
    WHERE ".implode(' AND ',$where)." ORDER BY af.found_date DESC, af.id DESC");
$findings->execute(array_merge([$today, $today], $params));
$findings = $findings->fetchAll();

$all_projects = $db->query("SELECT id, project_name FROM projects ORDER BY project_name")->fetchAll();

// Dashboard counts
$open_count    = (int)$db->query("SELECT COUNT(*) FROM audit_findings WHERE current_status != 'Closed'")->fetchColumn();
$overdue_count = (int)$db->query("SELECT COUNT(*) FROM audit_findings WHERE current_status != 'Closed' AND target_date < '$today'")->fetchColumn();

function sev_badge(string $s): string {
    $map = ['Critical'=>'#ef4444','Major'=>'#f59e0b','Minor'=>'#3b82f6'];
    $c = $map[$s] ?? '#6b7280';
    return "<span style=\"background:{$c}22;color:{$c};font-size:10px;padding:2px 8px;border-radius:4px;font-weight:700;\">{$s}</span>";
}
function status_badge(string $s): string {
    $map = ['Open'=>'#ef4444','In Progress'=>'#f59e0b','Closed'=>'#10b981'];
    $c = $map[$s] ?? '#6b7280';
    return "<span style=\"background:{$c}22;color:{$c};font-size:10px;padding:2px 8px;border-radius:4px;font-weight:600;\">{$s}</span>";
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?=$theme?>">
<head>
<meta charset="UTF-8">
<title>Audit Findings — DevVault Pro</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#070b14;--surface:#0d1526;--surface2:#111c30;--border:#1e2d45;--text:#e8edf5;--muted:#7a8fa8;--accent:<?=$accent?>;}
[data-theme="light"]{--bg:#f0f4f8;--surface:#fff;--surface2:#f5f7fa;--border:#d0dae8;--text:#1a2535;--muted:#5a7394;}
body{background:var(--bg);color:var(--text);font-family:'<?=$font?>',sans-serif;font-size:<?=$fs?>px;min-height:100vh;}
.topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:0 20px;height:50px;display:flex;align-items:center;gap:16px;}
.topbar a{color:var(--muted);text-decoration:none;font-size:13px;}.topbar a:hover{color:var(--accent);}
.logo{font-weight:700;font-size:16px;color:var(--accent);margin-right:8px;}
.wrap{max-width:1500px;margin:0 auto;padding:24px 20px;}
h1{font-size:20px;font-weight:700;color:var(--accent);margin-bottom:16px;}
.stat-chips{display:flex;gap:12px;margin-bottom:20px;}
.chip{background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:10px 16px;font-size:13px;display:flex;flex-direction:column;gap:2px;}
.chip .num{font-size:22px;font-weight:700;}
.chip .lbl{color:var(--muted);font-size:11px;}
.chip.danger .num{color:#ef4444;}
.card{background:var(--surface);border:1px solid var(--border);border-radius:10px;overflow:hidden;margin-bottom:20px;}
.filter-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;padding:16px;background:var(--surface2);border-bottom:1px solid var(--border);}
.filter-row select,.filter-row input{background:var(--surface);border:1px solid var(--border);color:var(--text);padding:6px 10px;border-radius:6px;font-size:13px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:6px;font-size:13px;font-weight:500;cursor:pointer;border:none;text-decoration:none;}
.btn-primary{background:var(--accent);color:#000;}.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--muted);}
.btn-danger{background:#ef444422;border:1px solid #ef4444;color:#ef4444;}.btn-warn{background:#f59e0b22;border:1px solid #f59e0b;color:#f59e0b;}
.btn-sm{padding:4px 10px;font-size:12px;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th{text-align:left;padding:10px 12px;color:var(--muted);font-weight:500;font-size:11px;text-transform:uppercase;border-bottom:1px solid var(--border);}
td{padding:9px 12px;border-bottom:1px solid rgba(255,255,255,0.04);}
tr:hover td{background:rgba(255,255,255,0.02);}
.overdue{color:#ef4444;font-size:11px;font-weight:600;}
.f{display:flex;flex-direction:column;gap:4px;flex:1;}
.f label{font-size:11px;color:var(--muted);font-weight:500;}
.f input,.f select,.f textarea{background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:7px 10px;border-radius:6px;font-size:13px;font-family:inherit;}
.row{display:flex;gap:12px;margin-bottom:10px;flex-wrap:wrap;}.w2{flex:2;}.w3{flex:3;}
.flash{padding:10px 16px;border-radius:8px;margin-bottom:16px;font-size:13px;}
.flash.success{background:#10b98122;border:1px solid #10b981;color:#10b981;}
.flash.error{background:#ef444422;border:1px solid #ef4444;color:#ef4444;}
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:100;display:none;align-items:center;justify-content:center;}
.modal-backdrop.open{display:flex;}
.modal{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:24px;width:680px;max-width:95vw;max-height:90vh;overflow-y:auto;}
.modal h3{font-size:16px;font-weight:600;margin-bottom:16px;color:var(--accent);}
</style>
</head>
<body>
<div class="topbar">
  <span class="logo">🔐 DevVault Pro</span>
  <a href="index.php">🏠 Dashboard</a>
  <a href="sr.php">📋 Service Requests</a>
  <a href="findings.php" style="color:var(--accent)">🔍 Audit Findings</a>
  <a href="workorders.php">📝 Work Orders</a>
  <a href="report.php">📊 Reports</a>
  <div style="margin-left:auto;display:flex;gap:12px;align-items:center;">
    <span id="session-timer" style="font-size:12px;color:var(--muted)"></span>
    <a href="logout.php" class="btn btn-ghost btn-sm">Logout</a>
  </div>
</div>

<div class="wrap">
  <h1>🔍 Audit Findings & Punch List</h1>

  <?php if ($flash): ?>
  <div class="flash <?=$flash['type']?>"><?=htmlspecialchars($flash['msg'])?></div>
  <?php endif; ?>

  <!-- Dashboard stats -->
  <div class="stat-chips">
    <div class="chip <?=$open_count>0?'danger':''?>">
      <span class="num"><?=$open_count?></span>
      <span class="lbl">Open Findings</span>
    </div>
    <div class="chip <?=$overdue_count>0?'danger':''?>">
      <span class="num"><?=$overdue_count?></span>
      <span class="lbl">Overdue Findings</span>
    </div>
    <div class="chip">
      <span class="num"><?=count($findings)?></span>
      <span class="lbl">Showing (filtered)</span>
    </div>
  </div>

  <div class="card">
    <!-- Filter Bar -->
    <form method="get">
      <div class="filter-row">
        <select name="project_id" style="min-width:200px;">
          <option value="">All Projects</option>
          <?php foreach ($all_projects as $pr): ?>
          <option value="<?=$pr['id']?>" <?=$f_project==$pr['id']?'selected':''?>><?=htmlspecialchars($pr['project_name'])?></option>
          <?php endforeach; ?>
        </select>
        <select name="severity">
          <option value="">All Severities</option>
          <?php foreach (['Critical','Major','Minor'] as $s): ?>
          <option value="<?=$s?>" <?=$f_severity===$s?'selected':''?>><?=$s?></option>
          <?php endforeach; ?>
        </select>
        <select name="status">
          <option value="">All Statuses</option>
          <?php foreach (['Open','In Progress','Closed'] as $s): ?>
          <option value="<?=$s?>" <?=$f_status===$s?'selected':''?>><?=$s?></option>
          <?php endforeach; ?>
        </select>
        <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);">
          From: <input type="date" name="from_date" value="<?=htmlspecialchars($f_from)?>" style="width:140px;">
          To: <input type="date" name="to_date" value="<?=htmlspecialchars($f_to)?>" max="<?=$today?>" style="width:140px;">
        </div>
        <button type="submit" class="btn btn-primary">🔍 Filter</button>
        <a href="findings.php" class="btn btn-ghost">✕ Clear</a>
        <?php if (can_edit()): ?>
        <button type="button" class="btn btn-primary" onclick="openModal()" style="margin-left:auto;">＋ Add Finding</button>
        <?php endif; ?>
      </div>
    </form>

    <!-- Bulk close form -->
    <form method="post" id="bulk-form">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="_action" value="bulk_close">
      <div style="padding:10px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;">
        <label style="font-size:12px;color:var(--muted);">
          <input type="checkbox" id="select-all" onchange="toggleAll(this)"> Select All
        </label>
        <button type="submit" class="btn btn-warn btn-sm" onclick="return confirm('Close selected findings?') && document.querySelectorAll('.f-check:checked').length>0">
          ✓ Bulk Close Selected
        </button>
      </div>

    <table>
      <thead>
        <tr>
          <th style="width:36px;"></th>
          <th>Project</th>
          <th style="max-width:260px;">Description</th>
          <th>Severity</th>
          <th>Found Date</th>
          <th>Found By</th>
          <th>Assigned To</th>
          <th>Target Date</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$findings): ?>
        <tr><td colspan="10" style="text-align:center;color:var(--muted);padding:30px;">No findings found.</td></tr>
        <?php endif; ?>
        <?php foreach ($findings as $f): ?>
        <tr>
          <td><input type="checkbox" name="finding_ids[]" class="f-check" value="<?=$f['id']?>" <?=$f['current_status']==='Closed'?'disabled':''?>></td>
          <td><a href="project_form.php?id=<?=$f['project_id']?>" style="color:var(--accent);text-decoration:none;font-size:12px;"><?=htmlspecialchars($f['project_name']??'?')?></a></td>
          <td style="max-width:260px;" title="<?=htmlspecialchars($f['finding_description'])?>">
            <span style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?=htmlspecialchars($f['finding_description'])?></span>
          </td>
          <td><?=sev_badge($f['severity'])?></td>
          <td><?=htmlspecialchars($f['found_date'])?></td>
          <td style="font-size:12px;color:var(--muted);"><?=htmlspecialchars($f['found_by']??'')?></td>
          <td style="font-size:12px;"><?=htmlspecialchars($f['assigned_to']??'')?></td>
          <td>
            <?php if ($f['target_date']): ?>
              <?=htmlspecialchars($f['target_date'])?>
              <?php if ($f['days_overdue'] > 0): ?>
              <br><span class="overdue">⚠ <?=$f['days_overdue']?>d overdue</span>
              <?php endif; ?>
            <?php else: echo '-'; endif; ?>
          </td>
          <td><?=status_badge($f['current_status'])?></td>
          <td style="white-space:nowrap;">
            <?php if (can_edit()): ?>
            <button class="btn btn-ghost btn-sm" onclick='editFinding(<?=json_encode($f)?>)'>✏</button>
            <?php endif; ?>
            <?php if (is_admin()): ?>
            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this finding?')">
              <input type="hidden" name="csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="_action" value="delete_finding">
              <input type="hidden" name="finding_id" value="<?=$f['id']?>">
              <button type="submit" class="btn btn-danger btn-sm">🗑</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </form>
    <div style="padding:10px 16px;font-size:12px;color:var(--muted);">Showing <?=count($findings)?> record(s)</div>
  </div>
</div>

<!-- Add/Edit Finding Modal -->
<div class="modal-backdrop" id="finding-modal">
  <div class="modal">
    <h3 id="modal-title">➕ Log Audit Finding</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="_action" value="save_finding">
      <input type="hidden" name="finding_id" id="finding_id" value="0">
      <input type="hidden" name="return_url" value="<?=htmlspecialchars($_SERVER['REQUEST_URI'])?>">
      <div class="row">
        <div class="f w2">
          <label>Project *</label>
          <select name="project_id" id="m_project_id" required>
            <option value="">— Select Project —</option>
            <?php foreach ($all_projects as $pr): ?>
            <option value="<?=$pr['id']?>" <?=$f_project==$pr['id']?'selected':''?>><?=htmlspecialchars($pr['project_name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="f">
          <label>Severity</label>
          <select name="severity" id="m_severity">
            <option value="Minor">Minor</option>
            <option value="Major">Major</option>
            <option value="Critical">Critical</option>
          </select>
        </div>
        <div class="f">
          <label>Status</label>
          <select name="current_status" id="m_status">
            <option value="Open">Open</option>
            <option value="In Progress">In Progress</option>
            <option value="Closed">Closed</option>
          </select>
        </div>
      </div>
      <div class="row">
        <div class="f w3">
          <label>Finding Description *</label>
          <textarea name="finding_description" id="m_desc" rows="3" required placeholder="Describe the issue found..." style="resize:vertical;"></textarea>
        </div>
      </div>
      <div class="row">
        <div class="f">
          <label>Found Date *</label>
          <input type="date" name="found_date" id="m_found_date" value="<?=$today?>" required>
        </div>
        <div class="f">
          <label>Found By</label>
          <input type="text" name="found_by" id="m_found_by" placeholder="Auditor / team name">
        </div>
        <div class="f">
          <label>Assigned To</label>
          <input type="text" name="assigned_to" id="m_assigned" placeholder="Responsible person">
        </div>
        <div class="f">
          <label>Target Date</label>
          <input type="date" name="target_date" id="m_target">
        </div>
      </div>
      <div class="row" id="closure-row" style="display:none;">
        <div class="f">
          <label>Closure Date</label>
          <input type="date" name="closure_date" id="m_closure_date">
        </div>
        <div class="f w2">
          <label>Closure Remarks</label>
          <input type="text" name="closure_remarks" id="m_closure_rem" placeholder="How was it resolved?">
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:16px;">
        <button type="submit" class="btn btn-primary">💾 Save</button>
        <button type="button" class="btn btn-ghost" onclick="closeModal()">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script src="session_timer.js"></script>
<script>
function toggleAll(cb){document.querySelectorAll('.f-check:not(:disabled)').forEach(c=>c.checked=cb.checked);}
function openModal(){
  document.getElementById('modal-title').textContent='➕ Log Audit Finding';
  document.getElementById('finding_id').value='0';
  document.getElementById('m_desc').value='';
  document.getElementById('m_found_date').value='<?=$today?>';
  document.getElementById('m_found_by').value='';
  document.getElementById('m_assigned').value='';
  document.getElementById('m_target').value='';
  document.getElementById('m_status').value='Open';
  document.getElementById('m_severity').value='Minor';
  document.getElementById('m_closure_date').value='';
  document.getElementById('m_closure_rem').value='';
  document.getElementById('closure-row').style.display='none';
  document.getElementById('finding-modal').classList.add('open');
}
function editFinding(f){
  document.getElementById('modal-title').textContent='✏ Edit Finding';
  document.getElementById('finding_id').value=f.id;
  document.getElementById('m_project_id').value=f.project_id;
  document.getElementById('m_desc').value=f.finding_description;
  document.getElementById('m_found_date').value=f.found_date;
  document.getElementById('m_found_by').value=f.found_by||'';
  document.getElementById('m_assigned').value=f.assigned_to||'';
  document.getElementById('m_target').value=f.target_date||'';
  document.getElementById('m_status').value=f.current_status;
  document.getElementById('m_severity').value=f.severity;
  document.getElementById('m_closure_date').value=f.closure_date||'';
  document.getElementById('m_closure_rem').value=f.closure_remarks||'';
  document.getElementById('closure-row').style.display=f.current_status==='Closed'?'flex':'none';
  document.getElementById('finding-modal').classList.add('open');
}
function closeModal(){document.getElementById('finding-modal').classList.remove('open');}
document.getElementById('finding-modal').addEventListener('click',function(e){if(e.target===this)closeModal();});
document.getElementById('m_status').addEventListener('change',function(){
  document.getElementById('closure-row').style.display=this.value==='Closed'?'flex':'none';
});
</script>
</body>
</html>
