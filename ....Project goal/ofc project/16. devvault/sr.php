<?php
require_once __DIR__ . '/auth.php';
require_login();
$db = get_db();

$flash   = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$accent  = user_pref('accent','#00d4ff');
$theme   = user_pref('theme','dark');
$font    = user_pref('font_family','Rajdhani');
$fs      = user_pref('font_size','14');
// ── Sanitize user preferences at read time (CSS injection prevention) ─────────
$accent = preg_replace('/[^#a-fA-F0-9]/', '', $accent);
if (empty($accent)) $accent = '#00d4ff';
$theme  = in_array($theme, ['dark', 'light']) ? $theme : 'dark';
$fs     = max(11, min(18, (int)$fs));
$font   = in_array($font, ['Rajdhani', 'Share Tech Mono', 'Orbitron']) ? $font : 'Rajdhani';

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF error');
    $act = $_POST['_action'] ?? '';

    if ($act === 'save_sr' && can_edit()) {
        $sr_id      = intval($_POST['sr_id'] ?? 0);
        $project_id = intval($_POST['project_id'] ?? 0);
        $sr_number  = trim($_POST['sr_number'] ?? '');
        $sr_date    = trim($_POST['sr_date'] ?? '');
        $purpose    = trim($_POST['purpose'] ?? '');
        $raised_by  = trim($_POST['raised_by'] ?? '');
        $status     = trim($_POST['current_status'] ?? 'Open');
        $res_date   = trim($_POST['resolution_date'] ?? '') ?: null;
        $remarks    = trim($_POST['remarks'] ?? '');
        if ($sr_number && $sr_date && $purpose && $project_id) {
            if ($sr_id) {
                $db->prepare("UPDATE service_requests SET sr_number=?,sr_date=?,purpose=?,raised_by=?,current_status=?,resolution_date=?,remarks=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")
                   ->execute([$sr_number,$sr_date,$purpose,$raised_by,$status,$res_date,$remarks,$sr_id]);
                log_activity('edit_sr',$project_id,"SR#$sr_number");
                $_SESSION['flash']=['type'=>'success','msg'=>'✅ SR updated.'];
            } else {
                $db->prepare("INSERT INTO service_requests (project_id,sr_number,sr_date,purpose,raised_by,current_status,resolution_date,remarks,created_by) VALUES (?,?,?,?,?,?,?,?,?)")
                   ->execute([$project_id,$sr_number,$sr_date,$purpose,$raised_by,$status,$res_date,$remarks,$_SESSION['user_id']]);
                log_activity('add_sr',$project_id,"SR#$sr_number");
                $_SESSION['flash']=['type'=>'success','msg'=>'✅ SR added.'];
            }
        }
        header('Location: '.($_POST['return_url']??'sr.php')); exit;
    }
    if ($act === 'delete_sr' && is_admin()) {
        $sr_id = intval($_POST['sr_id'] ?? 0);
        $row = $db->prepare("SELECT * FROM service_requests WHERE id=?");
        $row->execute([$sr_id]); $row = $row->fetch();
        if ($row) {
            $db->prepare("DELETE FROM service_requests WHERE id=?")->execute([$sr_id]);
            log_activity('delete_sr',$row['project_id'],"SR#".$row['sr_number']);
            $_SESSION['flash']=['type'=>'success','msg'=>'🗑 SR deleted.'];
        }
        header('Location: sr.php'); exit;
    }
}

// ── GET filters ───────────────────────────────────────────────────────────────
$f_project = intval($_GET['project_id'] ?? 0);
$f_status  = $_GET['status'] ?? '';
$f_from    = $_GET['from_date'] ?? '';
$f_to      = $_GET['to_date'] ?? '';
$where = ['1=1']; $params = [];
if ($f_project) { $where[] = 'sr.project_id=?'; $params[] = $f_project; }
if ($f_status)  { $where[] = 'sr.current_status=?'; $params[] = $f_status; }
if ($f_from)    { $where[] = 'sr.sr_date >= ?'; $params[] = $f_from; }
if ($f_to)      { $where[] = 'sr.sr_date <= ?'; $params[] = $f_to; }

$srs = $db->prepare("SELECT sr.*, p.project_name FROM service_requests sr
    LEFT JOIN projects p ON sr.project_id=p.id
    WHERE ".implode(' AND ',$where)." ORDER BY sr.sr_date DESC, sr.id DESC");
$srs->execute($params); $srs = $srs->fetchAll();
$all_projects = $db->query("SELECT id, project_name FROM projects ORDER BY project_name")->fetchAll();

$status_cfg = [
    'Open'       => ['#ffd740','amber'],
    'In Progress'=> ['#40c4ff','blue'],
    'Resolved'   => ['#00e676','success'],
    'Closed'     => ['#5a7a9a','muted'],
];
?>
<?php
$page_title = 'Service Requests';
$nav_active = 'sr';
require_once __DIR__ . '/includes/sidebar.php';
?>
<div class="dv-content">

<div class="wrap">
  <div class="page-title">📋 SERVICE REQUESTS</div>

  <?php if ($flash): ?>
  <div class="flash flash-<?=$flash['type']?>"><?=htmlspecialchars($flash['msg'])?></div>
  <?php endif; ?>

  <div class="card">
    <form method="get">
      <div class="filter-bar">
        <div class="fg">
          <label>Project</label>
          <select name="project_id" style="min-width:180px">
            <option value="">All Projects</option>
            <?php foreach ($all_projects as $pr): ?>
            <option value="<?=$pr['id']?>" <?=$f_project==$pr['id']?'selected':''?>><?=htmlspecialchars($pr['project_name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg">
          <label>Status</label>
          <select name="status">
            <option value="">All Statuses</option>
            <?php foreach (array_keys($status_cfg) as $s): ?>
            <option value="<?=$s?>" <?=$f_status===$s?'selected':''?>><?=$s?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg"><label>From Date</label><input type="date" name="from_date" value="<?=htmlspecialchars($f_from)?>" style="width:150px"></div>
        <div class="fg"><label>To Date</label><input type="date" name="to_date" value="<?=htmlspecialchars($f_to)?>" max="<?=date('Y-m-d')?>" style="width:150px"></div>
        <button type="submit" class="btn btn-accent" style="margin-top:auto">🔍 Filter</button>
        <a href="sr.php" class="btn btn-ghost" style="margin-top:auto">✕ Clear</a>
        <?php if (can_edit()): ?>
        <button type="button" class="btn btn-accent" style="margin-top:auto;margin-left:auto" data-action="open-sr-modal">＋ Add SR</button>
        <?php endif; ?>
      </div>
    </form>
    <table>
      <thead><tr>
        <th>SR No.</th><th>Project</th><th>SR Date</th><th>Purpose</th>
        <th>Raised By</th><th>Status</th><th>Resolution Date</th><th>Remarks</th><th>Actions</th>
      </tr></thead>
      <tbody>
        <?php if (!$srs): ?>
        <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:32px;font-family:'Courier New',Consolas,monospace;font-size:11px">No service requests found.</td></tr>
        <?php endif; ?>
        <?php foreach ($srs as $sr):
          [$sc,$sn] = $status_cfg[$sr['current_status']] ?? ['#5a7a9a','muted'];
        ?>
        <tr>
          <td style="font-weight:700;color:var(--accent)"><?=htmlspecialchars($sr['sr_number'])?></td>
          <td><a href="project_form.php?id=<?=$sr['project_id']?>" style="color:var(--blue);text-decoration:none"><?=htmlspecialchars($sr['project_name']??'?')?></a></td>
          <td><?=htmlspecialchars($sr['sr_date'])?></td>
          <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?=htmlspecialchars($sr['purpose'])?>"><?=htmlspecialchars($sr['purpose'])?></td>
          <td><?=htmlspecialchars($sr['raised_by']??'')?></td>
          <td><span class="badge" style="color:<?=$sc?>;border-color:<?=$sc?>40"><?=$sr['current_status']?></span></td>
          <td><?=htmlspecialchars($sr['resolution_date']??'-')?></td>
          <td style="max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--muted)" title="<?=htmlspecialchars($sr['remarks']??'')?>"><?=htmlspecialchars($sr['remarks']??'')?></td>
          <td style="white-space:nowrap">
            <?php if (can_edit()): ?>
            <button class="btn btn-ghost btn-sm" data-action="edit-sr" data-sr='<?=htmlspecialchars(json_encode($sr), ENT_QUOTES)?>'>✏</button>
            <?php endif; ?>
            <?php if (is_admin()): ?>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete SR #<?=htmlspecialchars($sr['sr_number'])?>?')">
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
    <div class="tbl-footer">Showing <?=count($srs)?> record(s)</div>
  </div>
</div>

<!-- Modal -->
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
          <select name="project_id" id="m_proj" required>
            <option value="">— Select —</option>
            <?php foreach ($all_projects as $pr): ?>
            <option value="<?=$pr['id']?>"><?=htmlspecialchars($pr['project_name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="f">
          <label>SR Number *</label>
          <input type="text" name="sr_number" id="m_srnum" placeholder="e.g. SR-2025-001" required>
        </div>
        <div class="f">
          <label>SR Date *</label>
          <input type="date" name="sr_date" id="m_srdate" value="<?=date('Y-m-d')?>" required>
        </div>
      </div>
      <div class="row">
        <div class="f w2">
          <label>Purpose / Description *</label>
          <textarea name="purpose" id="m_purpose" rows="3" placeholder="What is this SR for?" required style="resize:vertical"></textarea>
        </div>
        <div class="f">
          <label>Raised By</label>
          <input type="text" name="raised_by" id="m_raised" placeholder="Name / dept">
          <label style="margin-top:8px">Status</label>
          <select name="current_status" id="m_status">
            <?php foreach (array_keys($status_cfg) as $s): ?><option value="<?=$s?>"><?=$s?></option><?php endforeach; ?>
          </select>
          <label style="margin-top:8px">Resolution Date</label>
          <input type="date" name="resolution_date" id="m_resdate">
        </div>
      </div>
      <div class="row">
        <div class="f"><label>Remarks</label><input type="text" name="remarks" id="m_remarks" placeholder="Optional notes"></div>
      </div>
      <div style="display:flex;gap:8px;margin-top:14px">
        <button type="submit" class="btn btn-accent">💾 Save</button>
        <button type="button" class="btn btn-ghost" data-action="close-sr-modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script src="session_timer.js"></script>
<script nonce="<?= csp_nonce() ?>">
function openModal(){
  document.getElementById('modal-title').textContent='➕ Add Service Request';
  document.getElementById('sr_id').value='0';
  ['m_proj','m_srnum','m_purpose','m_raised','m_remarks'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('m_srdate').value='<?=date('Y-m-d')?>';
  document.getElementById('m_status').value='Open';
  document.getElementById('m_resdate').value='';
  document.getElementById('sr-modal').classList.add('open');
}
function editSR(sr){
  document.getElementById('modal-title').textContent='✏ Edit SR #'+sr.sr_number;
  document.getElementById('sr_id').value=sr.id;
  document.getElementById('m_proj').value=sr.project_id;
  document.getElementById('m_srnum').value=sr.sr_number;
  document.getElementById('m_srdate').value=sr.sr_date;
  document.getElementById('m_purpose').value=sr.purpose;
  document.getElementById('m_raised').value=sr.raised_by||'';
  document.getElementById('m_status').value=sr.current_status;
  document.getElementById('m_resdate').value=sr.resolution_date||'';
  document.getElementById('m_remarks').value=sr.remarks||'';
  document.getElementById('sr-modal').classList.add('open');
}
function closeModal(){document.getElementById('sr-modal').classList.remove('open');}

// ── Event delegation (CSP fix — no inline onclick) ────────────────────────────
document.addEventListener('click', function(e) {
  if (e.target.closest('[data-action="open-sr-modal"]')) { openModal(); return; }
  if (e.target.closest('[data-action="close-sr-modal"]')) { closeModal(); return; }
  const editBtn = e.target.closest('[data-action="edit-sr"]');
  if (editBtn) { editSR(JSON.parse(editBtn.dataset.sr)); return; }
  // Click on modal backdrop to close
  if (e.target === document.getElementById('sr-modal')) { closeModal(); }
});

</div><!-- /.dv-content -->
<?php require_once __DIR__ . '/includes/sidebar_footer.php'; ?>
