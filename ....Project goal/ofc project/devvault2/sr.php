<?php
require_once __DIR__ . '/auth.php';
require_login();
$db = get_db();

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$accent  = user_pref('accent','#00d4ff');
$theme   = user_pref('theme','dark');
$font    = user_pref('font_family','Rajdhani');
$fs      = user_pref('font_size','14');

// ── POST: add/edit/delete SR ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF error');
    $act = $_POST['_action'] ?? '';

    if ($act === 'save_sr' && can_edit()) {
        $sr_id       = intval($_POST['sr_id'] ?? 0);
        $project_id  = intval($_POST['project_id'] ?? 0);
        $sr_number   = trim($_POST['sr_number'] ?? '');
        $sr_date     = trim($_POST['sr_date'] ?? '');
        $purpose     = trim($_POST['purpose'] ?? '');
        $raised_by   = trim($_POST['raised_by'] ?? '');
        $status      = trim($_POST['current_status'] ?? 'Open');
        $res_date    = trim($_POST['resolution_date'] ?? '') ?: null;
        $remarks     = trim($_POST['remarks'] ?? '');

        if ($sr_number && $sr_date && $purpose && $project_id) {
            if ($sr_id) {
                $db->prepare("UPDATE service_requests SET sr_number=?,sr_date=?,purpose=?,raised_by=?,current_status=?,resolution_date=?,remarks=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                   ->execute([$sr_number,$sr_date,$purpose,$raised_by,$status,$res_date,$remarks,$sr_id]);
                log_activity('edit_sr', $project_id, "SR#$sr_number");
                $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ SR updated.'];
            } else {
                $db->prepare("INSERT INTO service_requests (project_id,sr_number,sr_date,purpose,raised_by,current_status,resolution_date,remarks,created_by) VALUES (?,?,?,?,?,?,?,?,?)")
                   ->execute([$project_id,$sr_number,$sr_date,$purpose,$raised_by,$status,$res_date,$remarks,$_SESSION['user_id']]);
                log_activity('add_sr', $project_id, "SR#$sr_number");
                $_SESSION['flash'] = ['type'=>'success','msg'=>'✅ SR added.'];
            }
        }
        $redir = $_POST['return_url'] ?? 'sr.php';
        header('Location: ' . $redir); exit;
    }

    if ($act === 'delete_sr' && is_admin()) {
        $sr_id = intval($_POST['sr_id'] ?? 0);
        $row = $db->prepare("SELECT * FROM service_requests WHERE id=?");
        $row->execute([$sr_id]); $row = $row->fetch();
        if ($row) {
            $db->prepare("DELETE FROM service_requests WHERE id=?")->execute([$sr_id]);
            log_activity('delete_sr', $row['project_id'], "SR#".$row['sr_number']);
            $_SESSION['flash'] = ['type'=>'success','msg'=>'🗑 SR deleted.'];
        }
        header('Location: sr.php'); exit;
    }
}

// ── GET: filter parameters ───────────────────────────────────────────────────
$f_project = intval($_GET['project_id'] ?? 0);
$f_status  = $_GET['status'] ?? '';
$f_from    = $_GET['from_date'] ?? '';
$f_to      = $_GET['to_date'] ?? '';

$where = ['1=1']; $params = [];
if ($f_project) { $where[] = 'sr.project_id=?'; $params[] = $f_project; }
if ($f_status)  { $where[] = 'sr.current_status=?'; $params[] = $f_status; }
if ($f_from)    { $where[] = 'sr.sr_date >= ?'; $params[] = $f_from; }
if ($f_to)      { $where[] = 'sr.sr_date <= ?'; $params[] = $f_to; }

$srs = $db->prepare("SELECT sr.*, p.project_name, p.technology
    FROM service_requests sr LEFT JOIN projects p ON sr.project_id=p.id
    WHERE ".implode(' AND ',$where)." ORDER BY sr.sr_date DESC, sr.id DESC");
$srs->execute($params);
$srs = $srs->fetchAll();

// Load projects for filter dropdown
$all_projects = $db->query("SELECT id, project_name FROM projects ORDER BY project_name")->fetchAll();

// Edit mode
$edit_sr = null;
if (isset($_GET['edit'])) {
    $st = $db->prepare("SELECT * FROM service_requests WHERE id=?");
    $st->execute([intval($_GET['edit'])]); $edit_sr = $st->fetch();
}

// Status badge helper
function sr_badge(string $s): string {
    $map = ['Open'=>'#f59e0b','In Progress'=>'#3b82f6','Resolved'=>'#10b981','Closed'=>'#6b7280'];
    $c = $map[$s] ?? '#6b7280';
    return "<span style=\"background:{$c}22;color:{$c};font-size:10px;padding:2px 8px;border-radius:4px;font-weight:600;\">{$s}</span>";
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?=$theme?>">
<head>
<meta charset="UTF-8">
<title>Service Requests — DevVault Pro</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#070b14;--surface:#0d1526;--surface2:#111c30;--border:#1e2d45;
  --text:#e8edf5;--muted:#7a8fa8;--accent:<?=$accent?>;
}
[data-theme="light"]{--bg:#f0f4f8;--surface:#fff;--surface2:#f5f7fa;--border:#d0dae8;--text:#1a2535;--muted:#5a7394;}
body{background:var(--bg);color:var(--text);font-family:'<?=$font?>',sans-serif;font-size:<?=$fs?>px;min-height:100vh;}
.topbar{background:var(--surface);border-bottom:1px solid var(--border);padding:0 20px;height:50px;display:flex;align-items:center;gap:16px;}
.topbar a{color:var(--muted);text-decoration:none;font-size:13px;}
.topbar a:hover{color:var(--accent);}
.logo{font-weight:700;font-size:16px;color:var(--accent);margin-right:8px;}
.wrap{max-width:1400px;margin:0 auto;padding:24px 20px;}
h1{font-size:20px;font-weight:700;color:var(--accent);margin-bottom:20px;}
.card{background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:20px;}
.card h2{font-size:14px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:16px;}
.filter-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:20px;}
.filter-row select,.filter-row input{background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:6px 10px;border-radius:6px;font-size:13px;}
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:6px;font-size:13px;font-weight:500;cursor:pointer;border:none;text-decoration:none;}
.btn-primary{background:var(--accent);color:#000;}
.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--muted);}
.btn-danger{background:#ef444422;border:1px solid #ef4444;color:#ef4444;}
.btn-sm{padding:4px 10px;font-size:12px;}
table{width:100%;border-collapse:collapse;font-size:13px;}
th{text-align:left;padding:10px 12px;color:var(--muted);font-weight:500;font-size:11px;text-transform:uppercase;border-bottom:1px solid var(--border);}
td{padding:10px 12px;border-bottom:1px solid rgba(255,255,255,0.04);}
tr:hover td{background:rgba(255,255,255,0.02);}
.f{display:flex;flex-direction:column;gap:4px;flex:1;}
.f label{font-size:11px;color:var(--muted);font-weight:500;}
.f input,.f select,.f textarea{background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:7px 10px;border-radius:6px;font-size:13px;font-family:inherit;}
.row{display:flex;gap:12px;margin-bottom:10px;flex-wrap:wrap;}
.w2{flex:2;}
.flash{padding:10px 16px;border-radius:8px;margin-bottom:16px;font-size:13px;}
.flash.success{background:#10b98122;border:1px solid #10b981;color:#10b981;}
.flash.error{background:#ef444422;border:1px solid #ef4444;color:#ef4444;}
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:100;display:none;align-items:center;justify-content:center;}
.modal-backdrop.open{display:flex;}
.modal{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:24px;width:600px;max-width:95vw;max-height:90vh;overflow-y:auto;}
.modal h3{font-size:16px;font-weight:600;margin-bottom:16px;color:var(--accent);}
</style>
</head>
<body>
<div class="topbar">
  <span class="logo">🔐 DevVault Pro</span>
  <a href="index.php">🏠 Dashboard</a>
  <a href="sr.php" style="color:var(--accent)">📋 Service Requests</a>
  <a href="findings.php">🔍 Audit Findings</a>
  <a href="workorders.php">📝 Work Orders</a>
  <a href="report.php">📊 Reports</a>
  <div style="margin-left:auto;display:flex;gap:12px;align-items:center;">
    <span id="session-timer" style="font-size:12px;color:var(--muted)"></span>
    <a href="logout.php" class="btn btn-ghost btn-sm">Logout</a>
  </div>
</div>
<div class="wrap">
  <h1>📋 Service Requests</h1>

  <?php if ($flash): ?>
  <div class="flash <?=$flash['type']?>"><?=htmlspecialchars($flash['msg'])?></div>
  <?php endif; ?>

  <!-- Filter Bar -->
  <form method="get" style="margin-bottom:20px;">
    <div class="filter-row">
      <select name="project_id" style="min-width:200px;">
        <option value="">All Projects</option>
        <?php foreach ($all_projects as $pr): ?>
        <option value="<?=$pr['id']?>" <?=$f_project==$pr['id']?'selected':''?>><?=htmlspecialchars($pr['project_name'])?></option>
        <?php endforeach; ?>
      </select>
      <select name="status">
        <option value="">All Statuses</option>
        <?php foreach (['Open','In Progress','Resolved','Closed'] as $s): ?>
        <option value="<?=$s?>" <?=$f_status===$s?'selected':''?>><?=$s?></option>
        <?php endforeach; ?>
      </select>
      <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--muted);">
        From: <input type="date" name="from_date" value="<?=htmlspecialchars($f_from)?>" style="width:140px;">
        To: <input type="date" name="to_date" value="<?=htmlspecialchars($f_to)?>" max="<?=date('Y-m-d')?>" style="width:140px;">
      </div>
      <button type="submit" class="btn btn-primary">🔍 Filter</button>
      <a href="sr.php" class="btn btn-ghost">✕ Clear</a>
      <?php if (can_edit()): ?>
      <button type="button" class="btn btn-primary" onclick="openModal()" style="margin-left:auto;">＋ Add SR</button>
      <?php endif; ?>
    </div>
  </form>

  <!-- SR Table -->
  <div class="card" style="padding:0;overflow:hidden;">
    <table>
      <thead>
        <tr>
          <th>SR No.</th><th>Project</th><th>Date</th><th>Purpose</th>
          <th>Raised By</th><th>Status</th><th>Resolution Date</th><th>Remarks</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$srs): ?>
        <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:30px;">No service requests found.</td></tr>
        <?php endif; ?>
        <?php foreach ($srs as $sr): ?>
        <tr>
          <td><strong><?=htmlspecialchars($sr['sr_number'])?></strong></td>
          <td><a href="project_form.php?id=<?=$sr['project_id']?>" style="color:var(--accent);text-decoration:none;"><?=htmlspecialchars($sr['project_name']??'?')?></a></td>
          <td><?=htmlspecialchars($sr['sr_date'])?></td>
          <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?=htmlspecialchars($sr['purpose'])?>"><?=htmlspecialchars($sr['purpose'])?></td>
          <td><?=htmlspecialchars($sr['raised_by']??'')?></td>
          <td><?=sr_badge($sr['current_status'])?></td>
          <td><?=htmlspecialchars($sr['resolution_date']??'-')?></td>
          <td style="max-width:150px;font-size:12px;color:var(--muted);"><?=htmlspecialchars($sr['remarks']??'')?></td>
          <td style="white-space:nowrap;">
            <?php if (can_edit()): ?>
            <button class="btn btn-ghost btn-sm" onclick='editSR(<?=json_encode($sr)?>)'>✏</button>
            <?php endif; ?>
            <?php if (is_admin()): ?>
            <form method="post" style="display:inline;" onsubmit="return confirm('Delete SR #<?=htmlspecialchars($sr['sr_number'])?>?')">
              <input type="hidden" name="csrf" value="<?=csrf_token()?>">
              <input type="hidden" name="_action" value="delete_sr">
              <input type="hidden" name="sr_id" value="<?=$sr['id']?>">
              <button type="submit" class="btn btn-danger btn-sm">🗑</button>
            </form>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div style="padding:10px 16px;font-size:12px;color:var(--muted);">
      Showing <?=count($srs)?> record(s)
    </div>
  </div>
</div>

<!-- Add/Edit SR Modal -->
<div class="modal-backdrop" id="sr-modal">
  <div class="modal">
    <h3 id="modal-title">➕ Add Service Request</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="_action" value="save_sr">
      <input type="hidden" name="sr_id" id="sr_id" value="0">
      <input type="hidden" name="return_url" value="<?=htmlspecialchars($_SERVER['REQUEST_URI'])?>">
      <div class="row">
        <div class="f">
          <label>Project *</label>
          <select name="project_id" id="modal_project_id" required>
            <option value="">— Select Project —</option>
            <?php foreach ($all_projects as $pr): ?>
            <option value="<?=$pr['id']?>" <?=$f_project==$pr['id']?'selected':''?>><?=htmlspecialchars($pr['project_name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="f">
          <label>SR Number *</label>
          <input type="text" name="sr_number" id="modal_sr_number" placeholder="e.g. SR-2025-001" required>
        </div>
        <div class="f">
          <label>SR Date *</label>
          <input type="date" name="sr_date" id="modal_sr_date" value="<?=date('Y-m-d')?>" required>
        </div>
      </div>
      <div class="row">
        <div class="f w2">
          <label>Purpose / Description *</label>
          <textarea name="purpose" id="modal_purpose" rows="3" placeholder="What is this SR for?" required style="resize:vertical;"></textarea>
        </div>
        <div class="f">
          <label>Raised By</label>
          <input type="text" name="raised_by" id="modal_raised_by" placeholder="Name / department">
          <label style="margin-top:10px;">Status</label>
          <select name="current_status" id="modal_status">
            <?php foreach (['Open','In Progress','Resolved','Closed'] as $s): ?>
            <option value="<?=$s?>"><?=$s?></option>
            <?php endforeach; ?>
          </select>
          <label style="margin-top:10px;">Resolution Date</label>
          <input type="date" name="resolution_date" id="modal_res_date">
        </div>
      </div>
      <div class="row">
        <div class="f">
          <label>Remarks</label>
          <input type="text" name="remarks" id="modal_remarks" placeholder="Optional notes">
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
function openModal(){
  document.getElementById('modal-title').textContent='➕ Add Service Request';
  document.getElementById('sr_id').value='0';
  document.getElementById('modal_sr_number').value='';
  document.getElementById('modal_sr_date').value='<?=date('Y-m-d')?>';
  document.getElementById('modal_purpose').value='';
  document.getElementById('modal_raised_by').value='';
  document.getElementById('modal_status').value='Open';
  document.getElementById('modal_res_date').value='';
  document.getElementById('modal_remarks').value='';
  document.getElementById('sr-modal').classList.add('open');
}
function editSR(sr){
  document.getElementById('modal-title').textContent='✏ Edit SR #'+sr.sr_number;
  document.getElementById('sr_id').value=sr.id;
  document.getElementById('modal_project_id').value=sr.project_id;
  document.getElementById('modal_sr_number').value=sr.sr_number;
  document.getElementById('modal_sr_date').value=sr.sr_date;
  document.getElementById('modal_purpose').value=sr.purpose;
  document.getElementById('modal_raised_by').value=sr.raised_by||'';
  document.getElementById('modal_status').value=sr.current_status;
  document.getElementById('modal_res_date').value=sr.resolution_date||'';
  document.getElementById('modal_remarks').value=sr.remarks||'';
  document.getElementById('sr-modal').classList.add('open');
}
function closeModal(){document.getElementById('sr-modal').classList.remove('open');}
document.getElementById('sr-modal').addEventListener('click',function(e){if(e.target===this)closeModal();});
</script>
</body>
</html>
