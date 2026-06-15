<?php
require_once __DIR__ . '/auth.php';
require_login();
$db = get_db();

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$accent = user_pref('accent','#00d4ff');
$theme  = user_pref('theme','dark');
$font   = user_pref('font_family','Rajdhani');
$fs     = user_pref('font_size','14');
$today  = date('Y-m-d');

// ── POST: create work order ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf()) die('CSRF error');
    $act = $_POST['_action'] ?? '';

    // Create new work order
    if ($act === 'create_wo' && can_edit()) {
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $source      = trim($_POST['instruction_source'] ?? '');
        $techs       = $_POST['applicable_tech'] ?? [];
        $scope       = $_POST['scope'] ?? 'all';
        $priority    = $_POST['priority'] ?? 'Normal';
        $deadline    = trim($_POST['deadline'] ?? '') ?: null;
        $assigned    = trim($_POST['assigned_to'] ?? '');

        if ($title && $techs) {
            $tech_json = json_encode($techs);
            $db->prepare("INSERT INTO work_orders (title,description,instruction_source,applicable_tech,scope,priority,deadline,assigned_to,created_by) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$title,$description,$source,$tech_json,$scope,$priority,$deadline,$assigned,$_SESSION['user_id']]);
            $wo_id = (int)$db->lastInsertId();

            // Auto-populate sites
            $site_ids = [];
            if ($scope === 'specific') {
                $site_ids = array_map('intval', $_POST['specific_projects'] ?? []);
            } else {
                // all sites of selected tech
                if (in_array('ALL', $techs)) {
                    $site_ids = array_column($db->query("SELECT id FROM projects WHERE current_status NOT IN ('closed')")->fetchAll(), 'id');
                } else {
                    foreach ($techs as $t) {
                        $tlike = $t === 'NET' ? '%Net%' : "%$t%";
                        $st = $db->prepare("SELECT id FROM projects WHERE (technology LIKE ? OR technology_other LIKE ?) AND current_status NOT IN ('closed')");
                        $st->execute([$tlike, $tlike]);
                        foreach ($st->fetchAll() as $row) $site_ids[] = (int)$row['id'];
                    }
                    $site_ids = array_unique($site_ids);
                }
            }
            foreach ($site_ids as $pid) {
                try {
                    $db->prepare("INSERT OR IGNORE INTO work_order_sites (work_order_id, project_id) VALUES (?,?)")->execute([$wo_id, $pid]);
                } catch (Exception $e) {}
            }
            $db->prepare("INSERT INTO work_order_history (work_order_id,action,changed_by,detail) VALUES (?,?,?,?)")
               ->execute([$wo_id,'created',$_SESSION['user_id'],"Created with ".count($site_ids)." sites"]);
            log_activity('create_work_order', null, $title);
            $_SESSION['flash'] = ['type'=>'success','msg'=>"✅ Work Order \"$title\" created with ".count($site_ids)." sites."];
            header("Location: workorders.php?view=$wo_id"); exit;
        }
    }

    // Update WO status
    if ($act === 'update_wo_status' && can_edit()) {
        $wo_id  = intval($_POST['wo_id'] ?? 0);
        $status = $_POST['wo_status'] ?? '';
        if ($wo_id && in_array($status, ['Active','Completed','Cancelled'])) {
            $db->prepare("UPDATE work_orders SET status=?,updated_at=CURRENT_TIMESTAMP WHERE id=?")->execute([$status,$wo_id]);
            $db->prepare("INSERT INTO work_order_history (work_order_id,action,changed_by,detail) VALUES (?,?,?,?)")
               ->execute([$wo_id,'status_change',$_SESSION['user_id'],"Status → $status"]);
            $_SESSION['flash']=['type'=>'success','msg'=>"✅ Work Order status updated to $status."];
        }
        header("Location: workorders.php?view=$wo_id"); exit;
    }

    // Mark individual site done/pending
    if ($act === 'toggle_site' && can_edit()) {
        $wo_id    = intval($_POST['wo_id'] ?? 0);
        $proj_id  = intval($_POST['project_id'] ?? 0);
        $new_stat = $_POST['new_status'] ?? 'Done';
        $remarks  = trim($_POST['site_remarks'] ?? '');
        if ($wo_id && $proj_id) {
            if ($new_stat === 'Done') {
                $db->prepare("UPDATE work_order_sites SET site_status='Done',done_by=?,done_at=CURRENT_TIMESTAMP,remarks=? WHERE work_order_id=? AND project_id=?")
                   ->execute([$_SESSION['user_id'],$remarks,$wo_id,$proj_id]);
                $db->prepare("INSERT INTO work_order_history (work_order_id,project_id,action,changed_by,detail) VALUES (?,?,?,?,?)")
                   ->execute([$wo_id,$proj_id,'site_done',$_SESSION['user_id'],"Marked Done"]);
            } else {
                $db->prepare("UPDATE work_order_sites SET site_status='Pending',done_by=NULL,done_at=NULL,remarks=? WHERE work_order_id=? AND project_id=?")
                   ->execute([$remarks,$wo_id,$proj_id]);
                $db->prepare("INSERT INTO work_order_history (work_order_id,project_id,action,changed_by,detail) VALUES (?,?,?,?,?)")
                   ->execute([$wo_id,$proj_id,'site_undone',$_SESSION['user_id'],"Reverted to Pending"]);
            }
        }
        header("Location: workorders.php?view=$wo_id"); exit;
    }

    // Bulk complete by tech
    if ($act === 'bulk_done_tech' && can_edit()) {
        $wo_id = intval($_POST['wo_id'] ?? 0);
        $tech  = trim($_POST['tech'] ?? '');
        if ($wo_id && $tech) {
            $tlike = $tech === 'NET' ? '%Net%' : "%$tech%";
            $rows  = $db->prepare("SELECT wos.project_id FROM work_order_sites wos
                JOIN projects p ON wos.project_id=p.id
                WHERE wos.work_order_id=? AND wos.site_status='Pending'
                AND (p.technology LIKE ? OR p.technology_other LIKE ?)");
            $rows->execute([$wo_id,$tlike,$tlike]);
            $pids = array_column($rows->fetchAll(), 'project_id');
            foreach ($pids as $pid) {
                $db->prepare("UPDATE work_order_sites SET site_status='Done',done_by=?,done_at=CURRENT_TIMESTAMP WHERE work_order_id=? AND project_id=?")
                   ->execute([$_SESSION['user_id'],$wo_id,$pid]);
            }
            $cnt = count($pids);
            $db->prepare("INSERT INTO work_order_history (work_order_id,action,changed_by,detail) VALUES (?,?,?,?)")
               ->execute([$wo_id,'bulk_done',$_SESSION['user_id'],"Bulk Done: $tech ($cnt sites)"]);
            log_activity('bulk_done_tech', null, "WO#$wo_id tech=$tech cnt=$cnt");
            $_SESSION['flash']=['type'=>'success','msg'=>"✅ Marked $cnt $tech site(s) as Done."];
        }
        header("Location: workorders.php?view=$wo_id"); exit;
    }

    // Delete WO
    if ($act === 'delete_wo' && is_admin()) {
        $wo_id = intval($_POST['wo_id'] ?? 0);
        $db->prepare("DELETE FROM work_orders WHERE id=?")->execute([$wo_id]);
        log_activity('delete_work_order', null, "WO#$wo_id");
        $_SESSION['flash']=['type'=>'success','msg'=>'🗑 Work Order deleted.'];
        header('Location: workorders.php'); exit;
    }
}

// ── View mode: detail page ────────────────────────────────────────────────────
$view_id = intval($_GET['view'] ?? 0);
if ($view_id) {
    $wo = $db->prepare("SELECT wo.*, u.username as creator FROM work_orders wo LEFT JOIN users u ON wo.created_by=u.id WHERE wo.id=?");
    $wo->execute([$view_id]); $wo = $wo->fetch();
    if (!$wo) { header('Location: workorders.php'); exit; }

    $wo_techs = json_decode($wo['applicable_tech'] ?? '[]', true);

    // Get sites with project info, grouped by tech
    $sites = $db->prepare("SELECT wos.*, p.project_name, p.technology, p.technology_other, p.tech_subtype,
        p.department_name, u.username as done_user
        FROM work_order_sites wos
        JOIN projects p ON wos.project_id=p.id
        LEFT JOIN users u ON wos.done_by=u.id
        WHERE wos.work_order_id=?
        ORDER BY p.technology, p.project_name");
    $sites->execute([$view_id]); $sites = $sites->fetchAll();

    $total_sites = count($sites);
    $done_sites  = count(array_filter($sites, fn($s) => $s['site_status'] === 'Done'));
    $pct         = $total_sites ? round($done_sites/$total_sites*100) : 0;

    // Group by effective technology
    $by_tech = [];
    foreach ($sites as $s) {
        $t = $s['technology'] ?: $s['technology_other'] ?: 'Unknown';
        $by_tech[$t][] = $s;
    }

    // History
    $history = $db->prepare("SELECT woh.*, u.username, p.project_name FROM work_order_history woh
        LEFT JOIN users u ON woh.changed_by=u.id
        LEFT JOIN projects p ON woh.project_id=p.id
        WHERE woh.work_order_id=? ORDER BY woh.changed_at DESC LIMIT 50");
    $history->execute([$view_id]); $history = $history->fetchAll();

    $all_projects = $db->query("SELECT id, project_name FROM projects ORDER BY project_name")->fetchAll();
} else {
    // ── List all work orders ───────────────────────────────────────────────────
    $f_status = $_GET['status'] ?? '';
    $where = ['1=1']; $params = [];
    if ($f_status) { $where[] = 'status=?'; $params[] = $f_status; }
    $all_wos = $db->prepare("SELECT wo.*,
        (SELECT COUNT(*) FROM work_order_sites WHERE work_order_id=wo.id) as total_sites,
        (SELECT COUNT(*) FROM work_order_sites WHERE work_order_id=wo.id AND site_status='Done') as done_sites
        FROM work_orders wo WHERE ".implode(' AND ',$where)." ORDER BY wo.created_at DESC");
    $all_wos->execute($params); $all_wos = $all_wos->fetchAll();
    $all_projects = $db->query("SELECT id, project_name FROM projects ORDER BY project_name")->fetchAll();
}

// Tech options
$tech_options = ['NET'=>'.NET','WebMyWay'=>'WebMyWay','AEM'=>'AEM','ALL'=>'All Technologies'];
$wo_statuses  = ['Active'=>'🟢 Active','Completed'=>'✅ Completed','Cancelled'=>'❌ Cancelled'];
$priority_map = ['High'=>'#ef4444','Normal'=>'#3b82f6','Low'=>'#6b7280'];

function wo_badge(string $s): string {
    $m = ['Active'=>'#10b981','Completed'=>'#3b82f6','Cancelled'=>'#6b7280'];
    $c = $m[$s] ?? '#6b7280';
    return "<span style=\"background:{$c}22;color:{$c};font-size:10px;padding:2px 8px;border-radius:4px;font-weight:600;\">$s</span>";
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?=$theme?>">
<head>
<meta charset="UTF-8">
<title>Work Orders — DevVault Pro</title>
<link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;600;700&family=Orbitron:wght@700&family=Share+Tech+Mono&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--accent:<?=$accent?>;--fs:<?=$fs?>px;--bg:#070b14;--surface:#0d1422;--surface2:#111a2e;--surface3:#16213e;
  --border:#1e2d4a;--text:#e8edf5;--muted:#5a7a9a;--success:#00e676;--danger:#ff3d5a;--amber:#ffd740;--blue:#40c4ff;--purple:#ea80fc;}
[data-theme="light"]{--bg:#f0f4f8;--surface:#fff;--surface2:#e8edf5;--surface3:#dde3ed;--border:#c8d4e0;--text:#0d1422;--muted:#5a7a9a;}
html{font-size:var(--fs)}
body{font-family:'<?=$font?>',sans-serif;background:var(--bg);color:var(--text);min-height:100vh}
body::before{content:'';position:fixed;inset:0;
  background-image:linear-gradient(rgba(0,212,255,.018) 1px,transparent 1px),linear-gradient(90deg,rgba(0,212,255,.018) 1px,transparent 1px);
  background-size:40px 40px;pointer-events:none;z-index:0}
[data-theme="light"] body::before{opacity:.3}
.topbar{position:sticky;top:0;z-index:100;background:rgba(7,11,20,.95);border-bottom:1px solid var(--border);
  backdrop-filter:blur(12px);padding:0 20px;height:52px;display:flex;align-items:center;gap:10px}
[data-theme="light"] .topbar{background:rgba(240,244,248,.95)}
.logo-txt{font-family:'Orbitron',monospace;font-size:14px;font-weight:900;letter-spacing:2px;color:var(--accent);text-shadow:0 0 16px var(--accent)}
.tnav{display:flex;gap:2px}.tnav a{color:var(--muted);text-decoration:none;font-size:12px;font-weight:600;
  padding:5px 10px;border-radius:6px;font-family:'Rajdhani',sans-serif;transition:all .15s}
.tnav a:hover{color:var(--text);background:var(--surface2)}.tnav a.cur{color:var(--accent)}
.btn{display:inline-flex;align-items:center;gap:5px;padding:6px 12px;border-radius:7px;font-size:12px;font-weight:600;
  font-family:'Rajdhani',sans-serif;cursor:pointer;border:none;text-decoration:none;transition:all .15s;white-space:nowrap}
.btn:active{transform:scale(.97)}
.btn-ghost{background:var(--surface2);color:var(--muted);border:1px solid var(--border)}.btn-ghost:hover{color:var(--text)}
.btn-accent{background:var(--accent);color:#000}.btn-accent:hover{opacity:.85}
.btn-danger{background:rgba(255,61,90,.12);color:var(--danger);border:1px solid rgba(255,61,90,.3)}.btn-danger:hover{background:rgba(255,61,90,.22)}
.btn-warn{background:rgba(255,215,64,.12);color:var(--amber);border:1px solid rgba(255,215,64,.3)}.btn-warn:hover{background:rgba(255,215,64,.22)}
.btn-success{background:rgba(0,230,118,.12);color:var(--success);border:1px solid rgba(0,230,118,.3)}.btn-success:hover{background:rgba(0,230,118,.22)}
.btn-sm{padding:4px 9px;font-size:11px}
.wrap{max-width:1400px;margin:0 auto;padding:20px;position:relative;z-index:1}
.page-title{font-family:'Orbitron',monospace;font-size:16px;font-weight:700;color:var(--accent);text-shadow:0 0 12px var(--accent);margin-bottom:16px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:14px}
.card-pad{padding:16px}
.badge{display:inline-block;font-size:9px;padding:2px 8px;border-radius:20px;font-weight:700;border:1px solid currentColor;letter-spacing:.4px;font-family:'Share Tech Mono',monospace}
.flash{padding:10px 14px;border-radius:8px;font-size:12px;font-family:'Share Tech Mono',monospace;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.flash-success{background:rgba(0,230,118,.08);border:1px solid rgba(0,230,118,.25);color:var(--success)}
.flash-error{background:rgba(255,61,90,.08);border:1px solid rgba(255,61,90,.25);color:var(--danger)}
table{width:100%;border-collapse:collapse;font-size:11px;font-family:'Share Tech Mono',monospace}
th{text-align:left;padding:8px 12px;font-size:9px;text-transform:uppercase;letter-spacing:1px;color:var(--muted);border-bottom:1px solid var(--border);white-space:nowrap}
td{padding:9px 12px;border-bottom:1px solid rgba(30,45,74,.35);vertical-align:middle}
tr:last-child td{border-bottom:none}tr:hover td{background:rgba(0,212,255,.02)}
.f{display:flex;flex-direction:column;gap:4px;flex:1}
.f label{font-family:'Share Tech Mono',monospace;font-size:9.5px;text-transform:uppercase;letter-spacing:1px;color:var(--muted)}
input,select,textarea{background:var(--surface2);border:1px solid var(--border);border-radius:7px;padding:7px 10px;
  color:var(--text);font-size:13px;font-family:inherit;outline:none;transition:border-color .2s}
input:focus,select:focus,textarea:focus{border-color:var(--accent)}
.row{display:flex;gap:12px;margin-bottom:10px;flex-wrap:wrap}.w2{flex:2}.w3{flex:3}
.filter-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;padding:12px 16px;background:var(--surface2);border-bottom:1px solid var(--border)}
.fg{display:flex;flex-direction:column;gap:3px;min-width:120px}
.fg label{font-family:'Share Tech Mono',monospace;font-size:9px;text-transform:uppercase;letter-spacing:1px;color:var(--muted)}
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:200;display:none;align-items:center;justify-content:center;backdrop-filter:blur(4px)}
.modal-backdrop.open{display:flex}
.modal{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:24px;width:720px;max-width:95vw;max-height:90vh;overflow-y:auto}
.modal h3{font-family:'Orbitron',monospace;font-size:14px;font-weight:700;color:var(--accent);margin-bottom:16px}
.tbl-footer{padding:8px 12px;font-size:10px;font-family:'Share Tech Mono',monospace;color:var(--muted);border-top:1px solid var(--border)}
.no-data{text-align:center;color:var(--muted);padding:36px;font-family:'Share Tech Mono',monospace;font-size:11px}
.progress-bar-wrap{background:rgba(255,255,255,0.07);border-radius:4px;height:7px;width:100%}
.progress-bar{height:7px;border-radius:4px;background:var(--accent);transition:.3s}
.meta-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-bottom:14px}
.meta-item{font-size:12px;font-family:'Share Tech Mono',monospace}
.meta-item .lbl{font-size:9px;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:3px}
.history-item{font-size:11px;font-family:'Share Tech Mono',monospace;color:var(--muted);padding:6px 0;border-bottom:1px solid var(--border);display:flex;gap:10px;align-items:baseline}
.history-item:last-child{border-bottom:none}
.history-item .ts{white-space:nowrap;font-size:10px;min-width:130px}
.history-item .act{color:var(--text)}
.tech-hd{background:var(--surface2);padding:10px 14px;font-size:11px;font-weight:700;font-family:'Share Tech Mono',monospace;
  display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border)}
.checkboxes{display:flex;flex-wrap:wrap;gap:8px;margin-top:6px}
.checkboxes label{background:var(--surface2);border:1px solid var(--border);border-radius:7px;padding:5px 10px;
  cursor:pointer;font-size:12px;display:flex;align-items:center;gap:6px;font-family:'Rajdhani',sans-serif;transition:all .15s}
.checkboxes label:hover{border-color:var(--muted)}
.pct-num{font-family:'Orbitron',monospace;font-size:26px;font-weight:700;color:var(--accent)}
</style>
</head>
<body>
<div class="topbar">
  <span class="logo">🔐 DevVault Pro</span>
  <a href="index.php">🏠 Dashboard</a>
  <a href="sr.php">📋 SRs</a>
  <a href="findings.php">🔍 Findings</a>
  <a href="workorders.php" style="color:var(--accent)">📝 Work Orders</a>
  <a href="report.php">📊 Reports</a>
  <div style="margin-left:auto;display:flex;gap:12px;align-items:center;">
    <span id="session-timer" style="font-size:12px;color:var(--muted)"></span>
    <a href="logout.php" class="btn btn-ghost btn-sm">Logout</a>
  </div>
</div>

<div class="wrap">
<?php if ($flash): ?>
<div class="flash <?=$flash['type']?>"><?=htmlspecialchars($flash['msg'])?></div>
<?php endif; ?>

<?php if ($view_id && $wo): ?>
<!-- ═══════════════════════════════════════════
     DETAIL VIEW
══════════════════════════════════════════════ -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
  <a href="workorders.php" class="btn btn-ghost btn-sm">← All Work Orders</a>
  <h1 style="margin:0;flex:1;">📝 <?=htmlspecialchars($wo['title'])?></h1>
  <?=wo_badge($wo['status'])?>
  <?php $pc=$priority_map[$wo['priority']]??'#3b82f6'; ?>
  <span style="background:<?=$pc?>22;color:<?=$pc?>;font-size:10px;padding:2px 8px;border-radius:20px;font-weight:700;border:1px solid <?=$pc?>40;font-family:'Share Tech Mono',monospace"><?=$wo['priority']?></span>
</div>

<!-- Meta info -->
<div class="card">
  <div class="meta-grid">
    <div class="meta-item"><div class="lbl">Instruction Source</div><?=htmlspecialchars($wo['instruction_source']??'-')?></div>
    <div class="meta-item"><div class="lbl">Applicable Tech</div><?=implode(', ', $wo_techs)?></div>
    <div class="meta-item"><div class="lbl">Scope</div><?=ucfirst($wo['scope']??'all')?></div>
    <div class="meta-item"><div class="lbl">Deadline</div><?=htmlspecialchars($wo['deadline']??'-')?></div>
    <div class="meta-item"><div class="lbl">Assigned To</div><?=htmlspecialchars($wo['assigned_to']??'-')?></div>
    <div class="meta-item"><div class="lbl">Created By</div><?=htmlspecialchars($wo['creator']??'-')?></div>
    <div class="meta-item"><div class="lbl">Created At</div><?=htmlspecialchars($wo['created_at'])?></div>
  </div>
  <?php if ($wo['description']): ?>
  <div style="font-size:13px;color:var(--muted);border-top:1px solid var(--border);padding-top:12px;margin-top:4px;">
    <?=nl2br(htmlspecialchars($wo['description']))?>
  </div>
  <?php endif; ?>
</div>

<!-- Progress -->
<div class="card">
  <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
    <div>
      <span class="pct-badge"><?=$pct?>%</span>
      <span style="font-size:13px;color:var(--muted);margin-left:8px;"><?=$done_sites?>/<?=$total_sites?> sites done</span>
    </div>
    <div style="flex:1;">
      <div class="progress-bar-wrap"><div class="progress-bar" style="width:<?=$pct?>%"></div></div>
    </div>
    <?php if (can_edit()): ?>
    <form method="post" style="display:flex;gap:8px;align-items:center;">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="_action" value="update_wo_status">
      <input type="hidden" name="wo_id" value="<?=$view_id?>">
      <select name="wo_status" style="background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:6px 10px;border-radius:6px;font-size:12px;">
        <?php foreach (['Active','Completed','Cancelled'] as $s): ?>
        <option value="<?=$s?>" <?=$wo['status']===$s?'selected':''?>><?=$s?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-ghost btn-sm">Update Status</button>
    </form>
    <?php if (is_admin()): ?>
    <form method="post" onsubmit="return confirm('Delete this entire work order?')">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="_action" value="delete_wo">
      <input type="hidden" name="wo_id" value="<?=$view_id?>">
      <button type="submit" class="btn btn-danger btn-sm">🗑 Delete WO</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- Bulk Done buttons by tech -->
  <?php if (can_edit() && $wo['status'] === 'Active'): ?>
  <div style="margin-top:16px;border-top:1px solid var(--border);padding-top:14px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
    <span style="font-size:12px;color:var(--muted);">Bulk Complete:</span>
    <?php foreach ($by_tech as $tech_name => $tech_sites):
      $t_pending = count(array_filter($tech_sites, fn($s)=>$s['site_status']==='Pending'));
      if (!$t_pending) continue;
      // Only WebMyWay gets bulk button; others are manual
      $is_wmw = stripos($tech_name,'webmyway') !== false;
    ?>
    <?php if ($is_wmw): ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="_action" value="bulk_done_tech">
      <input type="hidden" name="wo_id" value="<?=$view_id?>">
      <input type="hidden" name="tech" value="WebMyWay">
      <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Mark ALL <?=$t_pending?> pending <?=$tech_name?> sites as Done?')">
        ✓ Mark All <?=$tech_name?> Done (<?=$t_pending?> pending)
      </button>
    </form>
    <?php else: ?>
    <span style="font-size:12px;color:var(--muted);">
      <?=$tech_name?>: <?=$t_pending?> pending — complete individually below
    </span>
    <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Site-by-site table -->
<?php foreach ($by_tech as $tech_name => $tech_sites):
  $t_done    = count(array_filter($tech_sites, fn($s) => $s['site_status'] === 'Done'));
  $t_total   = count($tech_sites);
  $is_wmw    = stripos($tech_name,'webmyway') !== false;
?>
<div class="card" style="padding:0;overflow:hidden;">
  <div class="tech-group-hd">
    <span><?=$tech_name?></span>
    <span style="font-size:12px;color:var(--muted);"><?=$t_done?>/<?=$t_total?> done</span>
  </div>
  <table>
    <thead>
      <tr>
        <th>Site</th><th>Department</th><th>Sub-type</th><th>Status</th><th>Done By</th><th>Done At</th><th>Remarks</th>
        <?php if (can_edit() && $wo['status']==='Active'): ?><th>Action</th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($tech_sites as $s): ?>
      <tr>
        <td><a href="project_form.php?id=<?=$s['project_id']?>" style="color:var(--accent);text-decoration:none;"><?=htmlspecialchars($s['project_name'])?></a></td>
        <td style="font-size:12px;color:var(--muted);"><?=htmlspecialchars($s['department_name']??'')?></td>
        <td style="font-size:11px;"><?=htmlspecialchars($s['tech_subtype']??'')?></td>
        <td>
          <?php if ($s['site_status'] === 'Done'): ?>
          <span style="color:#10b981;font-size:12px;font-weight:600;">✓ Done</span>
          <?php else: ?>
          <span style="color:#f59e0b;font-size:12px;">⏳ Pending</span>
          <?php endif; ?>
        </td>
        <td style="font-size:12px;color:var(--muted);"><?=htmlspecialchars($s['done_user']??'')?></td>
        <td style="font-size:11px;color:var(--muted);"><?=$s['done_at'] ? substr($s['done_at'],0,16) : '-'?></td>
        <td style="font-size:12px;color:var(--muted);max-width:150px;" title="<?=htmlspecialchars($s['remarks']??'')?>"><?=htmlspecialchars(substr($s['remarks']??'',0,40))?></td>
        <?php if (can_edit() && $wo['status']==='Active'): ?>
        <td>
          <?php if ($s['site_status'] === 'Pending'): ?>
          <form method="post" style="display:inline-flex;gap:4px;align-items:center;">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="_action" value="toggle_site">
            <input type="hidden" name="wo_id" value="<?=$view_id?>">
            <input type="hidden" name="project_id" value="<?=$s['project_id']?>">
            <input type="hidden" name="new_status" value="Done">
            <input type="text" name="site_remarks" placeholder="Remark (opt)" style="width:120px;background:var(--surface2);border:1px solid var(--border);color:var(--text);padding:4px 8px;border-radius:4px;font-size:11px;">
            <button type="submit" class="btn btn-success btn-sm">✓ Done</button>
          </form>
          <?php else: ?>
          <form method="post" style="display:inline;">
            <input type="hidden" name="csrf" value="<?=csrf_token()?>">
            <input type="hidden" name="_action" value="toggle_site">
            <input type="hidden" name="wo_id" value="<?=$view_id?>">
            <input type="hidden" name="project_id" value="<?=$s['project_id']?>">
            <input type="hidden" name="new_status" value="Pending">
            <button type="submit" class="btn btn-ghost btn-sm" onclick="return confirm('Revert to Pending?')">↩ Undo</button>
          </form>
          <?php endif; ?>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endforeach; ?>

<!-- Activity History -->
<div class="card">
  <h2>📜 Activity History</h2>
  <?php if (!$history): ?>
  <p style="color:var(--muted);font-size:13px;">No history yet.</p>
  <?php endif; ?>
  <?php foreach ($history as $h): ?>
  <div class="history-item">
    <span class="ts"><?=substr($h['changed_at'],0,16)?></span>
    <span class="act">
      <strong><?=htmlspecialchars($h['username']??'?')?></strong>:
      <?=htmlspecialchars($h['action'])?>
      <?php if ($h['project_name']): ?> — <a href="project_form.php?id=<?=$h['project_id']?>" style="color:var(--accent);text-decoration:none;"><?=htmlspecialchars($h['project_name'])?></a><?php endif; ?>
      <?php if ($h['detail']): ?> <span style="color:var(--muted);">(<?=htmlspecialchars($h['detail'])?>)</span><?php endif; ?>
    </span>
  </div>
  <?php endforeach; ?>
</div>

<?php else: ?>
<!-- ═══════════════════════════════════════════
     LIST VIEW
══════════════════════════════════════════════ -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
  <span class="page-title" style="margin:0;flex:1">📝 WORK ORDERS</span>
  <?php if (can_edit()): ?>
  <button class="btn btn-primary" onclick="document.getElementById('create-modal').classList.add('open')">＋ New Work Order</button>
  <?php endif; ?>
</div>

<!-- Filter -->
<div style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;">
  <?php foreach ([''=>'All','Active'=>'🟢 Active','Completed'=>'✅ Completed','Cancelled'=>'❌ Cancelled'] as $v=>$l): ?>
  <a href="?status=<?=$v?>" class="btn btn-ghost btn-sm" style="<?=($f_status??'')===$v?'border-color:var(--accent);color:var(--accent);':''?>"><?=$l?></a>
  <?php endforeach; ?>
</div>

<div class="card" style="padding:0;overflow:hidden;">
  <table>
    <thead>
      <tr>
        <th>Title</th><th>Tech Scope</th><th>Priority</th><th>Deadline</th>
        <th>Progress</th><th>Status</th><th>Created</th><th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$all_wos): ?>
      <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:30px;">No work orders found.</td></tr>
      <?php endif; ?>
      <?php foreach ($all_wos as $wo): ?>
      <?php
        $pct = $wo['total_sites'] ? round($wo['done_sites']/$wo['total_sites']*100) : 0;
        $techs = json_decode($wo['applicable_tech'] ?? '[]', true);
      ?>
      <tr>
        <td><a href="workorders.php?view=<?=$wo['id']?>" style="color:var(--accent);text-decoration:none;font-weight:600;"><?=htmlspecialchars($wo['title'])?></a></td>
        <td style="font-size:12px;"><?=implode(', ', $techs)?></td>
        <?php $pwc=$priority_map[$wo['priority']]??'#3b82f6'; ?>
        <td><span style="background:<?=$pwc?>22;color:<?=$pwc?>;font-size:10px;padding:2px 8px;border-radius:20px;font-weight:700;border:1px solid <?=$pwc?>40;font-family:'Share Tech Mono',monospace"><?=$wo['priority']?></span></td>
        <td style="font-size:12px;"><?=htmlspecialchars($wo['deadline']??'-')?></td>
        <td style="min-width:120px;">
          <div style="font-size:12px;color:var(--muted);margin-bottom:4px;"><?=$wo['done_sites']?>/<?=$wo['total_sites']?> (<?=$pct?>%)</div>
          <div class="progress-bar-wrap"><div class="progress-bar" style="width:<?=$pct?>%"></div></div>
        </td>
        <td><?=wo_badge($wo['status'])?></td>
        <td style="font-size:11px;color:var(--muted);"><?=substr($wo['created_at'],0,10)?></td>
        <td><a href="workorders.php?view=<?=$wo['id']?>" class="btn btn-ghost btn-sm">View →</a></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Create Work Order Modal -->
<div class="modal-backdrop" id="create-modal">
  <div class="modal">
    <h3>➕ New Work Order</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=csrf_token()?>">
      <input type="hidden" name="_action" value="create_wo">
      <div class="row">
        <div class="f w3">
          <label>Title *</label>
          <input type="text" name="title" required placeholder="e.g. Remove CM/Minister photos from all sites">
        </div>
        <div class="f">
          <label>Priority</label>
          <select name="priority">
            <option value="Normal">Normal</option>
            <option value="High">High</option>
            <option value="Low">Low</option>
          </select>
        </div>
      </div>
      <div class="row">
        <div class="f w2">
          <label>Description</label>
          <textarea name="description" rows="3" placeholder="Detail what needs to be done..." style="resize:vertical;"></textarea>
        </div>
        <div class="f">
          <label>Instruction Source</label>
          <input type="text" name="instruction_source" placeholder="e.g. Verbal / Written Order No. 123">
          <label style="margin-top:10px;">Deadline</label>
          <input type="date" name="deadline">
          <label style="margin-top:10px;">Assigned To</label>
          <input type="text" name="assigned_to" placeholder="Person / team name">
        </div>
      </div>
      <div class="row">
        <div class="f">
          <label>Applicable Technology * (select one or more)</label>
          <div class="checkboxes">
            <?php foreach ($tech_options as $val => $lbl): ?>
            <label><input type="checkbox" name="applicable_tech[]" value="<?=$val?>"> <?=$lbl?></label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <div class="row">
        <div class="f">
          <label>Scope</label>
          <div style="display:flex;gap:16px;margin-top:4px;">
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
              <input type="radio" name="scope" value="all" checked onchange="toggleScope(this.value)"> All sites of selected tech
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;">
              <input type="radio" name="scope" value="specific" onchange="toggleScope(this.value)"> Specific sites only
            </label>
          </div>
        </div>
      </div>
      <div id="specific-projects-wrap" style="display:none;margin-bottom:12px;">
        <label style="font-size:11px;color:var(--muted);font-weight:500;">Select Projects</label>
        <div style="background:var(--surface2);border:1px solid var(--border);border-radius:6px;padding:10px;max-height:200px;overflow-y:auto;display:grid;grid-template-columns:repeat(2,1fr);gap:4px;margin-top:4px;">
          <?php foreach ($all_projects as $pr): ?>
          <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;">
            <input type="checkbox" name="specific_projects[]" value="<?=$pr['id']?>">
            <?=htmlspecialchars($pr['project_name'])?>
          </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div style="display:flex;gap:10px;margin-top:16px;">
        <button type="submit" class="btn btn-primary">✅ Create Work Order</button>
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('create-modal').classList.remove('open')">Cancel</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
</div>

<script src="session_timer.js"></script>
<script>
function toggleScope(v){
  document.getElementById('specific-projects-wrap').style.display=v==='specific'?'block':'none';
}
document.querySelectorAll('#create-modal .modal-backdrop, #create-modal').forEach(el=>{
  el.addEventListener('click',function(e){if(e.target===document.getElementById('create-modal'))document.getElementById('create-modal').classList.remove('open');});
});
</script>
</body>
</html>
