<?php
require_once __DIR__ . '/auth.php';
require_login();
$db = get_db();

$total  = (int)$db->query("SELECT COUNT(*) FROM projects WHERE deleted_at IS NULL")->fetchColumn();
$envStats = [];
foreach(['local','staging','production','audit','other'] as $e){
    $st=$db->query("SELECT COUNT(*) FROM projects WHERE env_{$e}_url!='' AND env_{$e}_url IS NOT NULL AND deleted_at IS NULL");
    $envStats[$e]=$st?(int)$st->fetchColumn():0;
}
$uniqueAppServers = (int)$db->query("SELECT COUNT(DISTINCT app_ip) FROM projects WHERE app_ip!='' AND app_ip IS NOT NULL AND deleted_at IS NULL")->fetchColumn();
$uniqueDbServers  = (int)$db->query("SELECT COUNT(DISTINCT db_ip) FROM projects WHERE db_ip!='' AND db_ip IS NOT NULL AND deleted_at IS NULL")->fetchColumn();
$statusCounts=[];
foreach(['request_received','live','under_development','redevelopment','hold_by_department','content_updation','closed'] as $s){
    $st=$db->prepare("SELECT COUNT(*) FROM projects WHERE current_status=? AND deleted_at IS NULL");$st->execute([$s]);
    $statusCounts[$s]=(int)$st->fetchColumn();
}
$techRows=$db->query("SELECT COALESCE(NULLIF(technology_other,''),technology) as tech,current_status,COUNT(*) as cnt FROM projects WHERE deleted_at IS NULL GROUP BY tech,current_status ORDER BY tech")->fetchAll();
$techTable=[];
foreach($techRows as $r) $techTable[$r['tech']][$r['current_status']]=(int)$r['cnt'];

// T-08: Findings dashboard stats (safe — table may not exist yet)
$openFindings = 0; $overdueFindings = 0;
try {
    $openFindings    = (int)$db->query("SELECT COUNT(*) FROM audit_findings WHERE current_status != 'Closed'")->fetchColumn();
    $overdueFindings = (int)$db->query("SELECT COUNT(*) FROM audit_findings WHERE current_status != 'Closed' AND target_date < date('now')")->fetchColumn();
} catch (Exception $e) {}

// T-07: Open SRs count
$openSRs = 0;
try {
    $openSRs = (int)$db->query("SELECT COUNT(*) FROM service_requests WHERE current_status NOT IN ('Resolved','Closed')")->fetchColumn();
} catch (Exception $e) {}

// T-09: Active work orders count
$activeWOs = 0;
try {
    $activeWOs = (int)$db->query("SELECT COUNT(*) FROM work_orders WHERE status='Active'")->fetchColumn();
} catch (Exception $e) {}

// ── Dashboard alerts (FF-01) ──────────────────────────────────────────────────
$dash_alerts = [];
if ($openFindings > 0)
    $dash_alerts[] = ['type'=>'danger','icon'=>'🔍','msg'=>"{$openFindings} open finding(s)".($overdueFindings?" — {$overdueFindings} overdue":''),'link'=>'findings.php'];
if ($openSRs > 0)
    $dash_alerts[] = ['type'=>'warn','icon'=>'📋','msg'=>"{$openSRs} open service request(s)",'link'=>'sr.php'];
if ($activeWOs > 0)
    $dash_alerts[] = ['type'=>'info','icon'=>'📝','msg'=>"{$activeWOs} active work order(s)",'link'=>'workorders.php'];
try {
    $ov = (int)$db->query("SELECT COUNT(*) FROM projects WHERE deleted_at IS NULL AND current_status='live' AND (last_audit_date IS NULL OR last_audit_date < date('now','-365 days'))")->fetchColumn();
    if ($ov > 0)
        $dash_alerts[] = ['type'=>'warn','icon'=>'⚠','msg'=>"{$ov} live project(s) not audited in 12+ months",'link'=>'reports.php'];
} catch(Exception $e) {}

$q          = trim($_GET['q'] ?? '');
$status     = $_GET['status']    ?? '';
$tech       = $_GET['tech']      ?? '';
$dept       = trim($_GET['dept'] ?? '');
$hosting    = $_GET['hosting']   ?? '';
$audit_from = $_GET['audit_from'] ?? '';
$audit_to   = $_GET['audit_to']   ?? '';
$env_filter = $_GET['env']       ?? '';

$where=['1=1']; $params=[];

if($q) {
    $where[] = '(p.project_name LIKE ? OR p.department_name LIKE ? OR p.description LIKE ? OR p.app_ip LIKE ? OR p.nodal_officer_name LIKE ? OR p.db_ip LIKE ? OR p.nodal_contact LIKE ?)';
    $l = "%$q%";
    $params = array_merge($params, [$l,$l,$l,$l,$l,$l,$l]);
}
if($status)  { $where[] = 'p.current_status=?';          $params[] = $status; }
if($tech)    { $where[] = '(p.technology=? OR p.technology_other=?)'; $params = array_merge($params,[$tech,$tech]); }
if($dept)    { $where[] = 'p.department_name LIKE ?';    $params[] = "%$dept%"; }
if($hosting) { $where[] = '(p.app_hosting_type=? OR p.db_hosting_type=?)'; $params = array_merge($params,[$hosting,$hosting]); }
if($audit_from) { $where[] = 'p.last_audit_date >= ?';   $params[] = $audit_from; }
if($audit_to)   { $where[] = 'p.last_audit_date <= ?';   $params[] = $audit_to; }
if($env_filter) {
    $ev = preg_replace('/[^a-z]/','',$env_filter);
    if($ev) $where[] = "p.env_{$ev}_url != '' AND p.env_{$ev}_url IS NOT NULL";
}

$has_filters = $q||$status||$tech||$dept||$hosting||$audit_from||$audit_to||$env_filter;
// ── Pagination setup ─────────────────────────────────────────────────────────
$per_page     = 25;
$current_page = max(1, intval($_GET['page'] ?? 1));
$offset       = ($current_page - 1) * $per_page;

// Count total filtered results for pagination
$where[] = "p.deleted_at IS NULL";
$count_st = $db->prepare("SELECT COUNT(*) FROM projects p WHERE " . implode(' AND ', $where));
$count_st->execute($params);
$total_filtered = (int)$count_st->fetchColumn();
$total_pages    = max(1, (int)ceil($total_filtered / $per_page));
if ($current_page > $total_pages) $current_page = $total_pages;

$st=$db->prepare("SELECT p.*,u.username as creator,
    (SELECT COUNT(*) FROM project_contacts WHERE project_id=p.id) as contact_count,
    (SELECT COUNT(*) FROM project_documents WHERE project_id=p.id) as doc_count,
    (SELECT COUNT(*) FROM checklist_responses WHERE project_id=p.id AND checked=1) as chk_done,
    (SELECT COUNT(*) FROM checklist_items) as chk_total
    FROM projects p LEFT JOIN users u ON p.created_by=u.id WHERE ".implode(' AND ',$where)." ORDER BY p.updated_at DESC LIMIT ? OFFSET ?");
$params[] = $per_page;
$params[] = $offset;
$st->execute($params);$projects=$st->fetchAll();

// Preload extra contacts for nodal drawer
$extraContacts = [];
if ($projects) {
    $ids = array_column($projects,'id');
    $in = implode(',', array_fill(0,count($ids),'?'));
    $st2 = $db->prepare("SELECT * FROM project_contacts WHERE project_id IN ($in) ORDER BY sort_order");
    $st2->execute($ids);
    foreach ($st2->fetchAll() as $c) $extraContacts[$c['project_id']][] = $c;
}

$flash=$_SESSION['flash']??null;unset($_SESSION['flash']);
$accent  = user_pref('accent','#00d4ff');
$bg      = user_pref('bg_color','');
$bgVal   = $bg ?: '';
$theme   = user_pref('theme','dark');
$fsize   = user_pref('font_size','14');
$ffamily = user_pref('font_family','Rajdhani');
// ── Sanitize user preferences at read time (CSS injection prevention) ─────────
$accent  = preg_replace('/[^#a-fA-F0-9]/', '', $accent);
if (empty($accent)) $accent = '#00d4ff';
if (!empty($bg)) {
    $bg = '#' . preg_replace('/[^a-fA-F0-9]/', '', ltrim($bg, '#'));
}
$theme   = in_array($theme, ['dark', 'light']) ? $theme : 'dark';
$fsize   = max(11, min(18, (int)$fsize));
$ffamily = in_array($ffamily, ['Rajdhani', 'Share Tech Mono', 'Orbitron']) ? $ffamily : 'Rajdhani';

$SL=['request_received'=>'Request Received','live'=>'Live','under_development'=>'Under Dev','redevelopment'=>'Redevelopment','hold_by_department'=>'Hold by Dept','content_updation'=>'Content Updation','closed'=>'Closed'];
$SC=['request_received'=>'#90caf9','live'=>'#00e676','under_development'=>'#ffd740','redevelopment'=>'#40c4ff','hold_by_department'=>'#ff6e40','content_updation'=>'#bc8cff','closed'=>'#ff3d5a'];
$EC=['local'=>'#40c4ff','staging'=>'#ffd740','production'=>'#00e676','audit'=>'#ea80fc','other'=>'#8c9eff'];
?>
<?php
$page_title = 'Dashboard';
$nav_active = 'dashboard';
require_once __DIR__ . '/includes/sidebar.php';
?>

<div class="dv-content">

<?php if($flash):?>
<div class="flash flash-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['msg']) ?></div>
<?php endif;?>

<!-- ── Alerts ───────────────────────────────────────────────────────── -->
<?php if(!empty($dash_alerts)):?>
<div style="display:flex;flex-direction:column;gap:6px;margin-bottom:20px">
  <?php foreach($dash_alerts as $al):
    $ac = $al['type']==='danger' ? 'err' : ($al['type']==='warn' ? 'warn' : 'info');
  ?>
  <a href="<?= htmlspecialchars($al['link']) ?>" class="flash flash-<?= $ac ?>" style="text-decoration:none;margin-bottom:0">
    <span><?= $al['icon'] ?></span>
    <span style="flex:1"><?= htmlspecialchars($al['msg']) ?></span>
    <span style="opacity:.6">→</span>
  </a>
  <?php endforeach;?>
</div>
<?php endif;?>

<!-- ── Stat cards ──────────────────────────────────────────────────────── -->
<div style="display:flex;gap:10px;flex-wrap:wrap;justify-content:center;margin-bottom:20px">
  <a class="stat-card" href="index.php" style="text-decoration:none;min-width:110px;text-align:center;<?=!$status&&!$tech&&!$q?'box-shadow:0 0 0 2px var(--acc)':''?>">
    <div class="stat-label">Total</div>
    <div class="stat-value" id="stat-total" style="color:var(--acc)"><?=$total?></div>
  </a>
  <a class="stat-card" href="index.php?status=live" style="text-decoration:none;min-width:110px;text-align:center;<?=$status==='live'?'box-shadow:0 0 0 2px var(--ok)':''?>">
    <div class="stat-label">● Live</div>
    <div class="stat-value" id="stat-live" style="color:var(--ok)"><?=$statusCounts['live']??0?></div>
  </a>
  <a class="stat-card" href="index.php?status=under_development" style="text-decoration:none;min-width:110px;text-align:center">
    <div class="stat-label">◆ Under Dev</div>
    <div class="stat-value" id="stat-dev" style="color:var(--info)"><?=$statusCounts['under_development']??0?></div>
  </a>
  <a class="stat-card" href="index.php?status=redevelopment" style="text-decoration:none;min-width:110px;text-align:center">
    <div class="stat-label">▲ Redev</div>
    <div class="stat-value" id="stat-redev" style="color:var(--warn)"><?=$statusCounts['redevelopment']??0?></div>
  </a>
  <a class="stat-card" href="index.php?status=closed" style="text-decoration:none;min-width:110px;text-align:center">
    <div class="stat-label">✕ Closed</div>
    <div class="stat-value" id="stat-closed" style="color:var(--err)"><?=$statusCounts['closed']??0?></div>
  </a>
  <a class="stat-card" href="index.php" style="text-decoration:none;min-width:110px;text-align:center">
    <div class="stat-label">⬤ Production</div>
    <div class="stat-value" id="stat-prod" style="color:var(--ok)"><?=$envStats['production']??0?></div>
  </a>
  <a class="stat-card" href="index.php" style="text-decoration:none;min-width:110px;text-align:center">
    <div class="stat-label">⬤ Staging</div>
    <div class="stat-value" id="stat-staging" style="color:var(--warn)"><?=$envStats['staging']??0?></div>
  </a>
  <a class="stat-card" href="findings.php" style="text-decoration:none;min-width:110px;text-align:center;border-color:<?=$openFindings>0?'rgba(255,83,112,.4)':'var(--bdr)'?>">
    <div class="stat-label">🔍 Findings</div>
    <div class="stat-value" id="stat-findings" style="color:<?=$openFindings>0?'var(--err)':'var(--ok)'>?>"><?=$openFindings?></div>
    <?php if($overdueFindings>0):?><div class="stat-sub" style="color:var(--err)"><?=$overdueFindings?> overdue</div><?php endif;?>
  </a>
  <a class="stat-card" href="sr.php" style="text-decoration:none;min-width:110px;text-align:center">
    <div class="stat-label">📋 Open SRs</div>
    <div class="stat-value" id="stat-srs" style="color:var(--warn)"><?=$openSRs?></div>
  </a>
  <a class="stat-card" href="workorders.php?status=Active" style="text-decoration:none;min-width:110px;text-align:center">
    <div class="stat-label">📝 Work Orders</div>
    <div class="stat-value" id="stat-wo" style="color:var(--info)"><?=$activeWOs?></div>
  </a>
</div>

<!-- ── Summary panels ─────────────────────────────────────────────── -->
<div class="card" style="margin-bottom:20px">
  <div class="card-title" style="justify-content:space-between">
    🛠 Technology Breakdown
    <span id="last-refresh" style="font-size:10px;font-family:'JetBrains Mono',monospace;color:var(--tx3);font-weight:400"></span>
  </div>
  <?php if(empty($techTable)):?>
  <div class="no-data"><div class="no-data-icon">📭</div>No projects yet — add one to see breakdown</div>
  <?php else:?>
  <table class="dv-table" id="tech-tbl">
    <thead><tr><th>Technology</th><th style="color:var(--ok)">Live</th><th style="color:var(--info)">Dev</th><th style="color:var(--warn)">Redev</th><th style="color:var(--err)">Closed</th><th>Total</th></tr></thead>
    <tbody>
      <?php foreach($techTable as $t=>$c):
        $ttotal=($c['live']??0)+($c['under_development']??0)+($c['redevelopment']??0)+($c['closed']??0);?>
      <tr>
        <td style="font-weight:600"><?=htmlspecialchars($t)?></td>
        <td class="td-mono" style="color:var(--ok)"><?=$c['live']??0?></td>
        <td class="td-mono" style="color:var(--info)"><?=$c['under_development']??0?></td>
        <td class="td-mono" style="color:var(--warn)"><?=$c['redevelopment']??0?></td>
        <td class="td-mono" style="color:var(--err)"><?=$c['closed']??0?></td>
        <td class="td-mono" style="color:var(--tx2)"><?=$ttotal?></td>
      </tr>
      <?php endforeach;?>
    </tbody>
  </table>
  <?php endif;?>
</div>

<!-- ── Filter toolbar ───────────────────────────────────────────────── -->
<div class="card card-sm" style="margin-bottom:16px">
  <form method="GET" id="sf">
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
    <div style="position:relative;flex:1;min-width:200px">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--tx3);pointer-events:none"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      <input type="text" name="q" id="qi" placeholder="Search project, dept, IP…" value="<?=htmlspecialchars($q)?>" style="padding-left:34px">
    </div>
    <select name="status" onchange="this.form.submit()" style="width:auto;min-width:130px">
      <option value="">All Status</option>
      <?php foreach($SL as $k=>$l):?>
      <option value="<?=$k?>" <?=$status===$k?'selected':''?>><?=$l?></option>
      <?php endforeach;?>
    </select>
    <select name="tech" onchange="this.form.submit()" style="width:auto;min-width:130px">
      <option value="">All Technologies</option>
      <?php foreach(array_keys($techTable) as $t):?>
      <option value="<?=htmlspecialchars($t)?>" <?=($tech===$t)?'selected':''?>><?=htmlspecialchars($t)?></option>
      <?php endforeach;?>
    </select>
    <select name="hosting" onchange="this.form.submit()" style="width:auto;min-width:120px">
      <option value="">All Hosting</option>
      <?php foreach(['NIC','State DC','Cloud','Dedicated','Co-location','Other'] as $ht):?>
      <option value="<?=$ht?>" <?=$hosting===$ht?'selected':''?>><?=$ht?></option>
      <?php endforeach;?>
    </select>
    <select name="env" onchange="this.form.submit()" style="width:auto;min-width:120px">
      <option value="">All Environments</option>
      <?php foreach(['production'=>'Production','staging'=>'Staging','local'=>'Local','audit'=>'Audit','other'=>'Other'] as $ev=>$el):?>
      <option value="<?=$ev?>" <?=$env_filter===$ev?'selected':''?>><?=$el?></option>
      <?php endforeach;?>
    </select>
    <?php if($has_filters):?>
    <a href="index.php" class="btn btn-danger btn-sm">✕ Clear</a>
    <?php endif;?>
    <span style="font-family:var(--mono);font-size:12px;color:var(--tx2);white-space:nowrap;margin-left:auto">
      <?=$total_filtered?> project<?=$total_filtered!==1?'s':''?> &nbsp;·&nbsp; Page <?=$current_page?>/<?=$total_pages?>
    </span>
  </div>
  </form>
</div>

<!-- ── Project list ──────────────────────────────────────────────────── -->
<?php if(empty($projects)):?>
<div class="card" style="text-align:center;padding:56px 20px">
  <div style="font-size:40px;margin-bottom:14px;opacity:.4">📭</div>
  <div style="font-size:17px;font-weight:600;color:var(--tx);margin-bottom:6px">No Projects Found</div>
  <div style="font-size:13px;color:var(--tx2)"><?=$has_filters?'Filters clear karo ya naya project add karo':'Koi project nahi hai abhi'?></div>
  <?php if(can_edit()):?>
  <a href="project_form.php" class="btn btn-primary" style="margin-top:16px;display:inline-flex">＋ Add First Project</a>
  <?php endif;?>
</div>
<?php else:?>

<div class="card" style="padding:0;overflow:hidden">
  <!-- Table header -->
  <div style="display:grid;grid-template-columns:4px 2fr 1.2fr .9fr 1fr 1fr 110px;
    padding:10px 16px 10px 20px;border-bottom:2px solid var(--bdr);background:var(--sur2)">
    <?php foreach(['','Project / Department','Environments','Infrastructure','Credentials','Status / Tech','Actions'] as $h):?>
    <span style="font-family:var(--mono);font-size:10px;text-transform:uppercase;letter-spacing:1.2px;color:var(--tx2)"><?=$h?></span>
    <?php endforeach;?>
  </div>

  <?php foreach($projects as $p):
    $sclr = $SC[$p['current_status']]??'#5a7a9a';
    $tl   = $p['technology']==='Other' ? $p['technology_other'] : $p['technology'];
    $envs = ['local','staging','production','audit','other'];
    $activeEnvs  = array_filter($envs, fn($e)=>!empty($p["env_{$e}_url"]));
    $activeCreds = array_filter($envs, fn($e)=>!empty($p["env_{$e}_id"])||!empty($p["env_{$e}_password"]));
    $amc_t    = $p['amc_type'] ?? '';
    $days_left = !empty($p['amc_end_date']) ? (int)((strtotime($p['amc_end_date'])-time())/86400) : 999;
  ?>
  <div style="display:grid;grid-template-columns:4px 2fr 1.2fr .9fr 1fr 1fr 110px;
    padding:12px 16px 12px 20px;border-bottom:1px solid var(--bdr);
    align-items:center;transition:background .12s;position:relative"
    class="proj-row">
    <!-- Status bar -->
    <div style="position:absolute;left:0;top:0;bottom:0;width:4px;background:<?=$sclr?>;border-radius:0 2px 2px 0"></div>
    <div></div>

    <!-- Name + dept -->
    <div>
      <div style="font-size:14px;font-weight:600;color:var(--tx);margin-bottom:3px"><?=htmlspecialchars($p['project_name'])?></div>
      <div style="font-family:var(--mono);font-size:11px;color:var(--tx2)"><?=htmlspecialchars($p['department_name']??'')?></div>
      <?php if($p['parent_admin_dept']):?>
      <div style="font-family:var(--mono);font-size:10px;color:var(--tx3)">↳ <?=htmlspecialchars($p['parent_admin_dept'])?></div>
      <?php endif;?>
    </div>

    <!-- Environments -->
    <div style="display:flex;flex-wrap:wrap;gap:4px">
      <?php foreach($activeEnvs as $env):?>
      <a href="<?=htmlspecialchars($p["env_{$env}_url"])?>" target="_blank" rel="noopener"
        style="display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:12px;
          font-size:10px;font-family:var(--mono);font-weight:600;text-transform:uppercase;
          color:<?=$EC[$env]?>;border:1px solid <?=$EC[$env]?>40;text-decoration:none;
          transition:opacity .15s" onmouseover="this.style.opacity='.7'" onmouseout="this.style.opacity='1'">
        <span style="width:5px;height:5px;border-radius:50%;background:currentColor"></span>
        <?=$env?>
      </a>
      <?php endforeach;?>
      <?php if(empty($activeEnvs)):?><span style="font-size:11px;color:var(--tx3)">—</span><?php endif;?>
    </div>

    <!-- Infrastructure -->
    <div style="display:flex;flex-direction:column;gap:3px">
      <?php if($p['app_ip']):?>
      <div style="display:flex;align-items:center;gap:4px;font-family:var(--mono);font-size:11px">
        <span style="font-size:9px;color:var(--tx3);min-width:22px">app</span>
        <span style="color:var(--tx)"><?=htmlspecialchars($p['app_ip'])?></span>
        <button class="btn btn-ghost btn-sm" data-action="copy" data-text="<?=htmlspecialchars($p['app_ip'])?>" data-label="App IP" style="padding:1px 5px;font-size:10px">📋</button>
      </div>
      <?php endif;?>
      <?php if($p['db_ip']):?>
      <div style="display:flex;align-items:center;gap:4px;font-family:var(--mono);font-size:11px">
        <span style="font-size:9px;color:var(--tx3);min-width:22px">db</span>
        <span style="color:var(--tx)"><?=htmlspecialchars($p['db_ip'])?></span>
        <button class="btn btn-ghost btn-sm" data-action="copy" data-text="<?=htmlspecialchars($p['db_ip'])?>" data-label="DB IP" style="padding:1px 5px;font-size:10px">📋</button>
      </div>
      <?php endif;?>
      <?php if(!$p['app_ip']&&!$p['db_ip']):?><span style="font-size:11px;color:var(--tx3)">—</span><?php endif;?>
    </div>

    <!-- Credentials -->
    <div style="display:flex;flex-direction:column;gap:4px">
      <?php foreach(['production','staging','local','audit','other'] as $env):
        if(empty($p["env_{$env}_id"])&&empty($p["env_{$env}_password"])) continue;?>
      <div>
        <div style="font-family:var(--mono);font-size:9px;font-weight:700;text-transform:uppercase;color:<?=$EC[$env]?>;margin-bottom:1px"><?=$env?></div>
        <?php if($p["env_{$env}_id"]):?>
        <div style="display:flex;align-items:center;gap:3px;font-family:var(--mono);font-size:10px">
          <button class="btn btn-ghost btn-sm" data-action="copy" data-text="<?=htmlspecialchars($p["env_{$env}_id"])?>" data-label="Login ID" style="padding:1px 4px;font-size:9px">📋</button>
          <span style="color:var(--tx2)"><?=htmlspecialchars($p["env_{$env}_id"])?></span>
        </div>
        <?php endif;?>
        <?php if($p["env_{$env}_password"]):?>
        <div style="display:flex;align-items:center;gap:3px;font-family:var(--mono);font-size:10px">
          <button class="btn btn-ghost btn-sm" data-action="copy-pw" data-id="<?=$p['id']?>" data-env="<?=$env?>" data-csrf="<?=csrf_token()?>" style="padding:1px 4px;font-size:9px">📋</button>
          <span id="pw-<?=$p['id']?>-<?=$env?>" style="color:var(--tx3);letter-spacing:2px">••••••</span>
          <button class="btn btn-ghost btn-sm" data-action="toggle-pw" data-id="<?=$p['id']?>" data-env="<?=$env?>" data-csrf="<?=csrf_token()?>" style="padding:1px 4px;font-size:9px">👁</button>
        </div>
        <?php endif;?>
      </div>
      <?php endforeach;?>
      <?php if(empty($activeCreds)):?><span style="font-size:11px;color:var(--tx3)">—</span><?php endif;?>
    </div>

    <!-- Status + Tech -->
    <div style="display:flex;flex-direction:column;gap:5px">
      <span class="badge" style="color:<?=$sclr?>;border:1px solid <?=$sclr?>30;background:<?=$sclr?>12;align-self:start;font-size:10px">
        <?=$SL[$p['current_status']]??$p['current_status']?>
      </span>
      <?php if($tl):?>
      <span style="font-family:var(--mono);font-size:10px;color:var(--tx2)"><?=htmlspecialchars($tl)?></span>
      <?php endif;?>
      <?php if($amc_t && $amc_t!=='NA'):
        $amc_c=['Paid'=>'var(--ok)','Exemption'=>'var(--warn)','Free'=>'var(--info)'];
        $amc_col=$amc_c[$amc_t]??'var(--tx3)';
      ?>
      <span style="font-family:var(--mono);font-size:9px;color:<?=$amc_col?>;border:1px solid <?=$amc_col?>30;padding:1px 5px;border-radius:4px;display:inline-block">
        ₹ <?=$amc_t?><?=$days_left<0?' ⚠️':($days_left<=30?' ⏰':'')?>
      </span>
      <?php endif;?>
      <?php if($p['chk_total']>0): $pct=round($p['chk_done']/$p['chk_total']*100);?>
      <div style="display:flex;align-items:center;gap:4px">
        <div style="flex:1;height:3px;background:var(--bdr);border-radius:2px">
          <div style="width:<?=$pct?>%;height:100%;background:<?=$pct>=100?'var(--ok)':'var(--acc)'?>;border-radius:2px"></div>
        </div>
        <span style="font-size:9px;font-family:var(--mono);color:var(--tx3)"><?=$pct?>%</span>
      </div>
      <?php endif;?>
    </div>

    <!-- Actions -->
    <div style="display:flex;gap:5px;justify-content:flex-end;flex-wrap:wrap">
      <a href="project_form.php?id=<?=$p['id']?>" class="btn btn-ghost btn-sm" title="Edit">✏</a>
      <?php if(!empty($p['general_notes'])||!empty($p['app_infa_remark'])):?>
      <button class="btn btn-ghost btn-sm" data-action="tog-notes" data-id="<?=$p['id']?>" title="Notes">📝</button>
      <?php endif;?>
      <?php if(is_admin()):?>
      <button class="btn btn-danger btn-sm" data-action="del-proj" data-id="<?=$p['id']?>" data-name="<?=htmlspecialchars($p['project_name'])?>" data-csrf="<?=csrf_token()?>" title="Delete">🗑</button>
      <?php endif;?>
    </div>
  </div>

  <!-- Notes drawer -->
  <?php if(!empty($p['general_notes'])||!empty($p['app_infa_remark'])):?>
  <div id="nd-<?=$p['id']?>" style="display:none;padding:10px 16px 12px 24px;background:var(--sur2);border-bottom:1px solid var(--bdr)">
    <?php if($p['app_infa_remark']):?>
    <div style="font-size:11px;font-family:var(--mono);color:var(--tx2);margin-bottom:4px">📡 App Infra: <?=htmlspecialchars($p['app_infa_remark'])?></div>
    <?php endif;?>
    <?php if($p['general_notes']):?>
    <div style="font-size:11px;font-family:var(--mono);color:var(--tx);white-space:pre-wrap;line-height:1.6"><?=htmlspecialchars($p['general_notes'])?></div>
    <?php endif;?>
  </div>
  <?php endif;?>

  <?php endforeach;?>
</div>

<!-- Pagination -->
<?php if($total_pages > 1):
  $purl = $_SERVER['PHP_SELF'] . '?';
  $pargs = [];
  if(!empty($q))      $pargs[] = 'q='.urlencode($q);
  if(!empty($status)) $pargs[] = 'status='.urlencode($status);
  if(!empty($tech))   $pargs[] = 'tech='.urlencode($tech);
  $base_url = $purl.implode('&',$pargs);
  $sep = $pargs ? '&' : '';
?>
<div style="display:flex;align-items:center;justify-content:space-between;padding:14px 0;flex-wrap:wrap;gap:10px">
  <span style="font-family:var(--mono);font-size:12px;color:var(--tx2)"><?=$total_filtered?> results · <?=$offset+1?>–<?=min($offset+$per_page,$total_filtered)?></span>
  <div style="display:flex;gap:4px">
    <?php if($current_page>1):?>
    <a href="<?=$base_url.$sep?>page=1" class="btn btn-ghost btn-sm">«</a>
    <a href="<?=$base_url.$sep?>page=<?=$current_page-1?>" class="btn btn-ghost btn-sm">‹</a>
    <?php endif;?>
    <?php for($pg=max(1,$current_page-2);$pg<=min($total_pages,$current_page+2);$pg++):?>
    <a href="<?=$base_url.$sep?>page=<?=$pg?>" class="btn btn-sm <?=$pg===$current_page?'btn-primary':'btn-ghost'?>"><?=$pg?></a>
    <?php endfor;?>
    <?php if($current_page<$total_pages):?>
    <a href="<?=$base_url.$sep?>page=<?=$current_page+1?>" class="btn btn-ghost btn-sm">›</a>
    <a href="<?=$base_url.$sep?>page=<?=$total_pages?>" class="btn btn-ghost btn-sm">»</a>
    <?php endif;?>
  </div>
</div>
<?php endif;?>

<?php endif;?>
</div><!-- /.dv-content -->

<!-- Toast -->
<div id="toast" style="display:none;position:fixed;bottom:20px;right:20px;
  background:var(--sur);border:1px solid var(--bdr);border-radius:var(--radius-lg);
  padding:10px 16px;font-size:13px;box-shadow:var(--shadow);z-index:999;
  align-items:center;gap:8px;animation:toast-in .22s ease">
  <span id="tt"></span>
</div>
<style nonce="<?= csp_nonce() ?>">
@keyframes toast-in{from{transform:translateY(14px);opacity:0}to{transform:translateY(0);opacity:1}}
.proj-row:hover{background:color-mix(in srgb,var(--acc) 3%,var(--sur))!important}
@media(max-width:900px){
  .proj-row,[style*="grid-template-columns:4px 2fr"]{grid-template-columns:4px 2fr 1fr 90px!important}
  .proj-row > *:nth-child(4),.proj-row > *:nth-child(5),.proj-row > *:nth-child(6){display:none}
}
@media(max-width:600px){
  .proj-row,[style*="grid-template-columns:4px 2fr"]{grid-template-columns:4px 1fr 80px!important}
  .proj-row > *:nth-child(3){display:none}
}
</style>

<script nonce="<?= csp_nonce() ?>">
window.DEVVAULT_CSRF = '<?= csrf_token() ?>';

// ── Event delegation — all interactions ──────────────────────────────
const pvs = {};
document.addEventListener('click', function(e) {
  // Copy text
  const cp = e.target.closest('[data-action="copy"]');
  if(cp){ navigator.clipboard.writeText(cp.dataset.text).then(()=>toast('📋 '+cp.dataset.label+' copied!')); return; }

  // Copy password
  const cpw = e.target.closest('[data-action="copy-pw"]');
  if(cpw){ fetchPw(cpw.dataset.id,cpw.dataset.env,cpw.dataset.csrf).then(pw=>{if(pw)navigator.clipboard.writeText(pw).then(()=>toast('🔑 Password copied!'))}); return; }

  // Toggle password visibility
  const tpw = e.target.closest('[data-action="toggle-pw"]');
  if(tpw){
    const key=tpw.dataset.id+'-'+tpw.dataset.env;
    const el=document.getElementById('pw-'+tpw.dataset.id+'-'+tpw.dataset.env);
    if(pvs[key]){el.textContent='••••••';pvs[key]=false;return;}
    fetchPw(tpw.dataset.id,tpw.dataset.env,tpw.dataset.csrf).then(pw=>{if(pw){el.textContent=pw;pvs[key]=true;}});
    return;
  }

  // Toggle notes drawer
  const tn = e.target.closest('[data-action="tog-notes"]');
  if(tn){ const nd=document.getElementById('nd-'+tn.dataset.id); if(nd)nd.style.display=nd.style.display==='none'?'block':'none'; return; }

  // Delete project
  const dp = e.target.closest('[data-action="del-proj"]');
  if(dp){
    if(!confirm('Delete "'+dp.dataset.name+'"?\nThis cannot be undone.')) return;
    const f=document.createElement('form');f.method='POST';f.action='api.php';
    f.innerHTML='<input name="action" value="delete_project"><input name="id" value="'+dp.dataset.id+'"><input name="csrf" value="'+dp.dataset.csrf+'">';
    document.body.appendChild(f);f.submit();
  }
});

async function fetchPw(id,env,csrf){
  const r=await fetch('api.php?action=get_pw&id='+id+'&env='+env+'&csrf='+csrf);
  const d=await r.json(); return d.pw||null;
}

function toast(m,dur=2400){
  const t=document.getElementById('toast');document.getElementById('tt').textContent=m;
  t.style.display='flex';setTimeout(()=>t.style.display='none',dur);
}

// ── Search debounce ──
let _st; document.getElementById('qi').addEventListener('input',function(){
  clearTimeout(_st);_st=setTimeout(()=>this.closest('form').submit(),500);
});

// ── Live stats refresh ──
function animateNum(el,v){if(!el)return;const o=parseInt(el.textContent)||0;if(o===v){el.textContent=v;return;}const s=v>o?1:-1;const dur=Math.min(500,Math.abs(v-o)*60);let c=o;const iv=setInterval(()=>{c+=s;el.textContent=c;if(c===v)clearInterval(iv);},dur/Math.max(1,Math.abs(v-o)));}

async function refreshStats(){
  try{
    const r=await fetch('api.php?action=stats&csrf='+window.DEVVAULT_CSRF);
    if(!r.ok)return; const d=await r.json(); if(!d.stats)return; const s=d.stats;
    animateNum(document.getElementById('stat-total'),s.total||0);
    animateNum(document.getElementById('stat-live'),s.live||0);
    animateNum(document.getElementById('stat-dev'),s.under_development||0);
    animateNum(document.getElementById('stat-redev'),s.redevelopment||0);
    animateNum(document.getElementById('stat-closed'),s.closed||0);
    animateNum(document.getElementById('stat-prod'),s.production||0);
    animateNum(document.getElementById('stat-staging'),s.staging||0);
    ['request_received','live','under_development','redevelopment','hold_by_department','content_updation','closed'].forEach(k=>{animateNum(document.getElementById('tbl-'+k),s[k]||0);});
    animateNum(document.getElementById('tbl-total'),s.total||0);
    animateNum(document.getElementById('tbl-production'),s.production||0);
    animateNum(document.getElementById('tbl-staging'),s.staging||0);
    const lr=document.getElementById('last-refresh');if(lr)lr.textContent='↻ '+new Date().toLocaleTimeString();
  }catch(e){}
}
setInterval(refreshStats,30000);
setTimeout(refreshStats,5000);
</script>

<?php require_once __DIR__ . '/includes/sidebar_footer.php'; ?>
