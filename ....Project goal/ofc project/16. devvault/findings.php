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
$today   = date('Y-m-d');

// ── POST ──────────────────────────────────────────────────────────────────────
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
            } else {
                $db->prepare("INSERT INTO audit_findings (project_id,finding_description,severity,found_by,found_date,assigned_to,target_date,current_status,closure_date,closure_remarks,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$project_id,$desc,$severity,$found_by,$found_date,$assigned,$target,$status,$cl_date,$cl_rem,$_SESSION['user_id']]);
            }
            log_activity('save_finding',$project_id,"[$severity] ".substr($desc,0,50));
            $_SESSION['flash']=['type'=>'success','msg'=>$fid?'✅ Finding updated.':'✅ Finding logged.'];
        }
        header('Location: '.($_POST['return_url']??'findings.php')); exit;
    }
    if ($act === 'delete_finding' && is_admin()) {
        $fid = intval($_POST['finding_id'] ?? 0);
        $row = $db->prepare("SELECT * FROM audit_findings WHERE id=?");
        $row->execute([$fid]); $row = $row->fetch();
        if ($row) { $db->prepare("DELETE FROM audit_findings WHERE id=?")->execute([$fid]); }
        $_SESSION['flash']=['type'=>'success','msg'=>'🗑 Finding deleted.'];
        header('Location: findings.php'); exit;
    }
    if ($act === 'bulk_close' && can_edit()) {
        $ids = array_map('intval', $_POST['finding_ids'] ?? []);
        if ($ids) {
            $in = implode(',', $ids);
            $db->exec("UPDATE audit_findings SET current_status='Closed',closure_date='$today',updated_at=CURRENT_TIMESTAMP WHERE id IN ($in)");
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
$findings->execute(array_merge([$today,$today],$params));
$findings = $findings->fetchAll();

$all_projects  = $db->query("SELECT id, project_name FROM projects ORDER BY project_name")->fetchAll();
$open_count    = 0; $overdue_count = 0;
try {
    $open_count    = (int)$db->query("SELECT COUNT(*) FROM audit_findings WHERE current_status != 'Closed'")->fetchColumn();
    $overdue_count = (int)$db->query("SELECT COUNT(*) FROM audit_findings WHERE current_status != 'Closed' AND target_date < '$today'")->fetchColumn();
} catch (Exception $e) {}

$sev_cfg = ['Critical'=>'#ff3d5a','Major'=>'#ffd740','Minor'=>'#40c4ff'];
$sta_cfg = ['Open'=>'#ff3d5a','In Progress'=>'#ffd740','Closed'=>'#00e676'];
?>
<?php
$page_title = 'Audit Findings';
$nav_active = 'findings';
require_once __DIR__ . '/includes/sidebar.php';
?>
<div class="dv-content">

<div class="wrap">
  <div class="page-title">🔍 AUDIT FINDINGS & PUNCH LIST</div>
  <?php if ($flash): ?>
  <div class="flash flash-<?=$flash['type']?>"><?=htmlspecialchars($flash['msg'])?></div>
  <?php endif; ?>

  <div class="stat-chips">
    <div class="schip <?=$open_count>0?'danger':''?>">
      <div class="num"><?=$open_count?></div><div class="lbl">Open Findings</div>
    </div>
    <div class="schip <?=$overdue_count>0?'danger':''?>">
      <div class="num"><?=$overdue_count?></div><div class="lbl">Overdue</div>
    </div>
    <div class="schip">
      <div class="num"><?=count($findings)?></div><div class="lbl">Showing (filtered)</div>
    </div>
  </div>

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
        <div class="fg"><label>Severity</label>
          <select name="severity">
            <option value="">All Severities</option>
            <?php foreach (array_keys($sev_cfg) as $s): ?>
            <option value="<?=$s?>" <?=$f_severity===$s?'selected':''?>><?=$s?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg"><label>Status</label>
          <select name="status">
            <option value="">All Statuses</option>
            <?php foreach (array_keys($sta_cfg) as $s): ?>
            <option value="<?=$s?>" <?=$f_status===$s?'selected':''?>><?=$s?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fg"><label>From Date</label><input type="date" name="from_date" value="<?=htmlspecialchars($f_from)?>" style="width:145px"></div>
        <div class="fg"><label>To Date</label><input type="date" name="to_date" value="<?=htmlspecialchars($f_to)?>" max="<?=$today?>" style="width:145px"></div>
        <button type="submit" class="btn btn-accent" style="margin-top:auto">🔍 Filter</button>
        <a href="findings.php" class="btn btn-ghost" style="margin-top:auto">✕ Clear</a>
        <?php if (can_edit()): ?>
        <button type="button" class="btn btn-accent" style="margin-top:auto;margin-left:auto" data-action="open-finding-modal">＋ Add Finding</button>
        <?php endif; ?>
      </div>
    </form>

    <form method="post" id="bulk-form">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="_action" value="bulk_close">
      <div style="padding:8px 14px;background:var(--surface3);border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px">
        <label style="display:flex;align-items:center;gap:6px;font-size:11px;font-family:'Courier New',Consolas,monospace;color:var(--muted);cursor:pointer">
          <input type="checkbox" id="sel-all" onchange="toggleAll(this)"> SELECT ALL
        </label>
        <button type="submit" class="btn btn-warn btn-sm" data-confirm="Close selected findings?">✓ Bulk Close Selected</button>
      </div>
      <table>
        <thead><tr>
          <th style="width:32px"></th>
          <th>Project</th><th style="max-width:220px">Description</th>
          <th>Severity</th><th>Found Date</th><th>Found By</th>
          <th>Assigned To</th><th>Target Date</th><th>Status</th><th>Actions</th>
        </tr></thead>
        <tbody>
          <?php if (!$findings): ?>
          <tr><td colspan="10" style="text-align:center;color:var(--muted);padding:32px">No findings found.</td></tr>
          <?php endif; ?>
          <?php foreach ($findings as $f):
            $sc = $sev_cfg[$f['severity']] ?? '#5a7a9a';
            $stc = $sta_cfg[$f['current_status']] ?? '#5a7a9a';
          ?>
          <tr>
            <td><input type="checkbox" name="finding_ids[]" class="f-chk" value="<?=intval($f['id'])?>" <?=$f['current_status']==='Closed'?'disabled':''?>></td>
            <td><a href="project_form.php?id=<?=$f['project_id']?>" style="color:var(--blue);text-decoration:none;font-size:11px"><?=htmlspecialchars($f['project_name']??'?')?></a></td>
            <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?=htmlspecialchars($f['finding_description'])?>"><?=htmlspecialchars($f['finding_description'])?></td>
            <td><span class="badge" style="color:<?=$sc?>;border-color:<?=$sc?>40"><=htmlspecialchars($f['severity'])?></span></td>
            <td><?=htmlspecialchars($f['found_date'])?></td>
            <td style="color:var(--muted)"><?=htmlspecialchars($f['found_by']??'')?></td>
            <td><?=htmlspecialchars($f['assigned_to']??'')?></td>
            <td>
              <?php if ($f['target_date']): ?>
                <?=htmlspecialchars($f['target_date'])?>
                <?php if ($f['days_overdue']>0): ?><br><span class="overdue">⚠ <=intval($f['days_overdue'])?>d overdue</span><?php endif; ?>
              <?php else: ?>-<?php endif; ?>
            </td>
            <td><span class="badge" style="color:<?=$stc?>;border-color:<?=$stc?>40"><=htmlspecialchars($f['current_status'])?></span></td>
            <td style="white-space:nowrap">
              <?php if (can_edit()): ?>
              <button class="btn btn-ghost btn-sm" data-action="edit-finding" data-finding=<?=json_encode($f)?>>✏</button>
              <?php endif; ?>
              <?php if (is_admin()): ?>
              <form method="post" style="display:inline" onsubmit="return confirm('Delete?')">
                <input type="hidden" name="csrf" value="<?=csrf_token()?>">
                <input type="hidden" name="_action" value="delete_finding">
                <input type="hidden" name="finding_id" value="<?=intval($f['id'])?>">
                <button type="submit" class="btn btn-danger btn-sm">🗑</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="tbl-footer">Showing <?=count($findings)?> record(s)</div>
    </form>
  </div>
</div>

<!-- Modal -->
<div class="modal-backdrop" id="finding-modal">
  <div class="modal">
    <h3 id="m-title">➕ Log Audit Finding</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="_action" value="save_finding">
      <input type="hidden" name="finding_id" id="m_fid" value="0">
      <input type="hidden" name="return_url" value="<?=htmlspecialchars($_SERVER['REQUEST_URI'])?>">
      <div class="row">
        <div class="f w2"><label>Project *</label>
          <select name="project_id" id="m_proj" required>
            <option value="">— Select —</option>
            <?php foreach ($all_projects as $pr): ?>
            <option value="<?=$pr['id']?>"><?=htmlspecialchars($pr['project_name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="f"><label>Severity</label>
          <select name="severity" id="m_sev">
            <?php foreach (array_keys($sev_cfg) as $s): ?><option value="<?=$s?>"><?=$s?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="f"><label>Status</label>
          <select name="current_status" id="m_sta" onchange="toggleClosure(this.value)">
            <?php foreach (array_keys($sta_cfg) as $s): ?><option value="<?=$s?>"><?=$s?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="row">
        <div class="f w3"><label>Finding Description *</label>
          <textarea name="finding_description" id="m_desc" rows="3" required style="resize:vertical" placeholder="Describe the issue..."></textarea>
        </div>
      </div>
      <div class="row">
        <div class="f"><label>Found Date *</label><input type="date" name="found_date" id="m_fd" value="<?=$today?>" required></div>
        <div class="f"><label>Found By</label><input type="text" name="found_by" id="m_fb" placeholder="Auditor / team"></div>
        <div class="f"><label>Assigned To</label><input type="text" name="assigned_to" id="m_at" placeholder="Responsible person"></div>
        <div class="f"><label>Target Date</label><input type="date" name="target_date" id="m_td"></div>
      </div>
      <div class="row" id="closure-row" style="display:none">
        <div class="f"><label>Closure Date</label><input type="date" name="closure_date" id="m_cd"></div>
        <div class="f w2"><label>Closure Remarks</label><input type="text" name="closure_remarks" id="m_cr" placeholder="How resolved?"></div>
      </div>
      <div style="display:flex;gap:8px;margin-top:14px">
        <button type="submit" class="btn btn-accent">💾 Save</button>
        <button type="button" class="btn btn-ghost" data-action="close-finding-modal">Cancel</button>
      </div>
    </form>
  </div>
</div>

<script src="session_timer.js"></script>
<script nonce="<?= csp_nonce() ?>">
function toggleAll(cb){document.querySelectorAll('.f-chk:not(:disabled)').forEach(c=>c.checked=cb.checked);}
function toggleClosure(v){document.getElementById('closure-row').style.display=v==='Closed'?'flex':'none';}
function openModal(){
  document.getElementById('m-title').textContent='➕ Log Audit Finding';
  document.getElementById('m_fid').value='0';
  ['m_proj','m_desc','m_fb','m_at','m_td','m_cd','m_cr'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('m_fd').value='<?=$today?>';
  document.getElementById('m_sev').value='Minor';
  document.getElementById('m_sta').value='Open';
  document.getElementById('closure-row').style.display='none';
  document.getElementById('finding-modal').classList.add('open');
}
function editFinding(f){
  document.getElementById('m-title').textContent='✏ Edit Finding';
  document.getElementById('m_fid').value=f.id;
  document.getElementById('m_proj').value=f.project_id;
  document.getElementById('m_desc').value=f.finding_description;
  document.getElementById('m_fd').value=f.found_date;
  document.getElementById('m_fb').value=f.found_by||'';
  document.getElementById('m_at').value=f.assigned_to||'';
  document.getElementById('m_td').value=f.target_date||'';
  document.getElementById('m_sev').value=f.severity;
  document.getElementById('m_sta').value=f.current_status;
  document.getElementById('m_cd').value=f.closure_date||'';
  document.getElementById('m_cr').value=f.closure_remarks||'';
  toggleClosure(f.current_status);
  document.getElementById('finding-modal').classList.add('open');
}
function closeModal(){document.getElementById('finding-modal').classList.remove('open');}

// Event delegation — CSP fix
document.addEventListener('click',function(e){
  if(e.target.closest('[data-action="open-finding-modal"]')){openModal();return;}
  if(e.target.closest('[data-action="close-finding-modal"]')){closeModal();return;}
  var ef=e.target.closest('[data-action="edit-finding"]');
  if(ef){editFinding(JSON.parse(ef.dataset.finding));return;}
  var dc=e.target.closest('[data-confirm]');
  if(dc&&dc.type==='submit'){if(!confirm(dc.dataset.confirm))e.preventDefault();return;}
  if(e.target===document.getElementById('finding-modal'))closeModal();
});

</div><!-- /.dv-content -->
<?php require_once __DIR__ . '/includes/sidebar_footer.php'; ?>
