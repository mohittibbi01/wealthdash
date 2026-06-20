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
<!DOCTYPE html>
<html lang="en" data-theme="<?=$theme?>">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DevVault Pro</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --acc:<?=$accent?>;
  --user-bg:<?=$bg?$bg:'var(--bg)'?>;
  --bg:#070b14;--sur:#0d1422;--sur2:#111a2e;--sur3:#16213e;
  --bdr:#1e2d4a;--tx:#e8edf5;--mt:#5a7a9a;
  --ok:#00e676;--err:#ff3d5a;--amb:#ffd740;--pur:#ea80fc;--blu:#40c4ff;
}
[data-theme="light"]{
  --bg:#f0f4f8;--sur:#ffffff;--sur2:#e8edf5;--sur3:#dde3ed;
  --bdr:#c8d4e0;--tx:#0d1422;--mt:#6b7f96;
}
html{font-size:<?=$fsize?>px}
body{font-family:'<?=$ffamily?>',sans-serif;color:var(--tx);min-height:100vh;
  background:var(--user-bg)!important}
body::before{content:'';position:fixed;inset:0;pointer-events:none;z-index:0;
  background-image:linear-gradient(var(--bdr) 1px,transparent 1px),
  linear-gradient(90deg,var(--bdr) 1px,transparent 1px);
  background-size:44px 44px;opacity:.13}

/* TOPBAR */
.bar{position:sticky;top:0;z-index:200;height:52px;
  background:color-mix(in srgb,var(--user-bg) 92%,transparent);
  border-bottom:1px solid var(--bdr);backdrop-filter:blur(14px);
  display:flex;align-items:center;padding:0 20px;gap:12px}
.logo{font-family:'Courier New',Consolas,monospace;font-size:15px;font-weight:900;
  color:var(--acc);letter-spacing:2px;text-shadow:0 0 16px var(--acc)}
.bar-search{flex:1;max-width:420px;position:relative}
.bar-search input{width:100%;background:var(--sur2);border:1px solid var(--bdr);
  border-radius:8px;padding:7px 12px 7px 34px;color:var(--tx);font-size:13px;
  font-family:'Courier New',Consolas,monospace;outline:none;
  transition:border-color .18s,box-shadow .18s}
.bar-search input:focus{border-color:var(--acc);box-shadow:0 0 0 2px color-mix(in srgb,var(--acc) 10%,transparent)}
.si{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--mt);font-size:13px;pointer-events:none}
.bar-r{margin-left:auto;display:flex;gap:7px;align-items:center}

.btn{display:inline-flex;align-items:center;gap:5px;padding:6px 13px;border-radius:7px;
  font-size:13px;font-weight:700;font-family:inherit;cursor:pointer;border:none;
  text-decoration:none;transition:all .15s;letter-spacing:.3px;white-space:nowrap}
.btn:active{transform:scale(.97)}
.btn-acc{background:var(--acc);color:#000}
.btn-acc:hover{opacity:.85}
.btn-ghost{background:var(--sur2);color:var(--mt);border:1px solid var(--bdr)}
.btn-ghost:hover{color:var(--tx);border-color:var(--mt)}
.btn-danger{background:color-mix(in srgb,var(--err) 12%,transparent);color:var(--err);border:1px solid color-mix(in srgb,var(--err) 25%,transparent)}
.btn-danger:hover{background:color-mix(in srgb,var(--err) 22%,transparent)}
.btn-sm{padding:4px 9px;font-size:11px}
.btn-icon{width:34px;height:34px;padding:0;justify-content:center;border-radius:8px;font-size:15px}

/* CONTENT */
.content{padding:16px 20px;position:relative;z-index:1}

/* STAT CHIPS — horizontal strip, no wrap issues */
.chips-strip{display:flex;gap:8px;margin-bottom:16px;overflow-x:auto;padding-bottom:2px}
.chips-strip::-webkit-scrollbar{height:3px}
.chips-strip::-webkit-scrollbar-thumb{background:var(--bdr);border-radius:2px}

.chip{display:inline-flex;align-items:center;gap:8px;padding:9px 16px;
  background:var(--sur);border:1px solid var(--bdr);border-radius:10px;
  text-decoration:none;color:var(--tx);cursor:pointer;flex-shrink:0;
  transition:border-color .15s,transform .12s,box-shadow .15s}
.chip:hover{border-color:var(--acc);transform:translateY(-2px);
  box-shadow:0 4px 16px color-mix(in srgb,var(--acc) 15%,transparent)}
.chip.active{border-color:var(--acc);
  background:color-mix(in srgb,var(--acc) 10%,var(--sur));
  box-shadow:0 0 0 1px var(--acc)}
.chip-left{display:flex;flex-direction:column;align-items:center}
.chip-num{font-family:'Courier New',Consolas,monospace;font-weight:900;font-size:20px;line-height:1;
  transition:all .4s ease}
.chip-lbl{font-family:'Courier New',Consolas,monospace;font-size:9px;text-transform:uppercase;
  letter-spacing:1px;color:var(--mt);margin-top:2px;white-space:nowrap}
.chip-dot{width:9px;height:9px;border-radius:50%;flex-shrink:0}

/* SUMMARY GRID */
.summary-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
.panel{background:var(--sur);border:1px solid var(--bdr);border-radius:12px;overflow:hidden}
.panel-hd{display:flex;align-items:center;gap:10px;padding:11px 16px;border-bottom:1px solid var(--bdr);background:var(--sur2)}
.panel-hd h2{font-family:'Courier New',Consolas,monospace;font-size:10px;text-transform:uppercase;letter-spacing:1.8px;color:var(--mt);flex:1}
.sum-tbl{width:100%;border-collapse:collapse;font-size:12px}
.sum-tbl th{font-family:'Courier New',Consolas,monospace;font-size:9px;text-transform:uppercase;letter-spacing:1px;color:var(--mt);padding:7px 14px;text-align:left;border-bottom:1px solid var(--bdr)}
.sum-tbl td{padding:8px 14px;border-bottom:1px solid color-mix(in srgb,var(--bdr) 50%,transparent);font-family:'Courier New',Consolas,monospace;font-size:11px;transition:all .3s ease}
.sum-tbl tr:last-child td{border-bottom:none}
.sum-tbl tr:hover td{background:color-mix(in srgb,var(--acc) 4%,transparent)}
.num-c{font-family:'Courier New',Consolas,monospace;font-weight:700;text-align:center;transition:all .4s ease}
.badge{display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:20px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;border:1px solid currentColor}

/* TOOLBAR */
.toolbar{display:flex;align-items:center;gap:8px;margin-bottom:10px;flex-wrap:wrap}
.fsel{background:var(--sur2);border:1px solid var(--bdr);border-radius:7px;padding:6px 10px;color:var(--tx);font-size:12px;font-family:'Courier New',Consolas,monospace;outline:none;cursor:pointer}
.fsel:focus{border-color:var(--acc)}
.result-count{margin-left:auto;font-family:'Courier New',Consolas,monospace;font-size:11px;color:var(--mt)}

/* LIVE INDICATOR */
.live-dot{display:inline-flex;align-items:center;gap:5px;font-family:'Courier New',Consolas,monospace;font-size:10px;color:var(--ok)}
.live-dot::before{content:'';width:6px;height:6px;border-radius:50%;background:var(--ok);
  animation:blink 1.4s ease-in-out infinite}
@keyframes blink{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.7)}}

/* PROJECT LIST */
.proj-list{background:var(--sur);border:1px solid var(--bdr);border-radius:12px;overflow:hidden}
.proj-hdr{display:grid;grid-template-columns:6px 2fr 1.1fr .9fr 1.1fr 1fr 120px;
  gap:0;padding:7px 14px 7px 20px;border-bottom:1px solid var(--bdr);background:var(--sur2)}
.proj-hdr span{font-family:'Courier New',Consolas,monospace;font-size:9px;text-transform:uppercase;letter-spacing:1.2px;color:var(--mt)}
.proj-row{display:grid;grid-template-columns:6px 2fr 1.1fr .9fr 1.1fr 1fr 120px;
  gap:0;padding:10px 14px 10px 20px;border-bottom:1px solid color-mix(in srgb,var(--bdr) 55%,transparent);
  align-items:center;transition:background .12s;position:relative}
.proj-row:last-child{border-bottom:none}
.proj-row:hover{background:color-mix(in srgb,var(--acc) 4%,var(--sur))}
.status-bar{width:4px;height:38px;border-radius:3px;position:absolute;left:12px;top:50%;transform:translateY(-50%)}
.pname{font-size:14px;font-weight:700;line-height:1.2;margin-bottom:2px}
.pdept{font-family:'Courier New',Consolas,monospace;font-size:10px;color:var(--mt)}
.env-pills{display:flex;flex-wrap:wrap;gap:4px}
.epill{display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:12px;font-size:9px;font-family:'Courier New',Consolas,monospace;font-weight:700;text-transform:uppercase;border:1px solid currentColor;cursor:pointer;text-decoration:none;transition:opacity .15s}
.epill:hover{opacity:.7}
.edot{width:5px;height:5px;border-radius:50%;background:currentColor}
.infra-kv{display:flex;flex-direction:column;gap:2px}
.kv{display:flex;gap:5px;align-items:center;font-size:11px;font-family:'Courier New',Consolas,monospace}
.kv .k{color:var(--mt);font-size:9px;min-width:26px}
.kv .v{color:var(--tx);font-weight:600}
.copy-btn{background:none;border:none;cursor:pointer;color:var(--mt);font-size:10px;padding:1px 3px;transition:color .15s;line-height:1}
.copy-btn:hover{color:var(--acc)}
.creds{display:flex;flex-direction:column;gap:3px}
.cred-env{font-family:'Courier New',Consolas,monospace;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;margin-bottom:1px}
.pw-row{display:flex;align-items:center;gap:4px;font-family:'Courier New',Consolas,monospace;font-size:11px}
.pw-mask{color:var(--mt);letter-spacing:2px}
.pw-btn{background:none;border:none;cursor:pointer;color:var(--mt);font-size:10px;padding:1px 3px;transition:color .15s;line-height:1}
.pw-btn:hover{color:var(--acc)}
.acts{display:flex;gap:5px;align-items:center;justify-content:flex-end}
.notes-drawer{display:none;padding:10px 16px 12px 26px;background:var(--sur2);border-top:1px solid var(--bdr);font-family:'Courier New',Consolas,monospace;font-size:11px;color:var(--mt)}
.notes-drawer.open{display:block}
.notes-sec .nt{color:var(--tx);white-space:pre-wrap;margin-top:3px;line-height:1.6}
.nodal-drawer{display:none;padding:8px 16px 10px 26px;background:var(--sur3);border-top:1px solid var(--bdr);gap:20px;flex-wrap:wrap;font-family:'Courier New',Consolas,monospace;font-size:11px}
.nodal-drawer.open{display:flex}
.nodal-kv .nk{font-size:9px;color:var(--mt);text-transform:uppercase;letter-spacing:.8px}
.nodal-kv .nv{font-size:12px;font-weight:700;color:var(--tx);margin-top:2px}

/* FLASH */
.flash{padding:10px 14px;border-radius:8px;font-size:12px;font-family:'Courier New',Consolas,monospace;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.flash-success{background:color-mix(in srgb,var(--ok) 8%,transparent);border:1px solid color-mix(in srgb,var(--ok) 25%,transparent);color:var(--ok)}
.flash-error{background:color-mix(in srgb,var(--err) 8%,transparent);border:1px solid color-mix(in srgb,var(--err) 25%,transparent);color:var(--err)}
.empty{text-align:center;padding:52px;color:var(--mt)}
.empty .ei{font-size:44px;margin-bottom:10px}
.empty h3{font-size:17px;font-weight:700;color:var(--tx);margin-bottom:6px}
.empty p{font-size:12px;font-family:'Courier New',Consolas,monospace}
.toast{position:fixed;bottom:20px;right:20px;background:var(--sur);border:1px solid var(--bdr);border-radius:10px;padding:10px 16px;font-size:12px;font-family:'Courier New',Consolas,monospace;box-shadow:0 8px 32px rgba(0,0,0,.45);z-index:999;display:none;align-items:center;gap:8px;animation:sup .22s ease}
@keyframes sup{from{transform:translateY(14px);opacity:0}to{transform:translateY(0);opacity:1}}

/* THEME PANEL */
.tpanel{display:none;position:absolute;right:0;top:46px;background:var(--sur);border:1px solid var(--bdr);border-radius:12px;padding:16px;width:270px;z-index:300;box-shadow:0 8px 32px rgba(0,0,0,.4)}
.tpanel.open{display:block}
.tp-lbl{font-family:'Courier New',Consolas,monospace;font-size:9.5px;text-transform:uppercase;letter-spacing:1.2px;color:var(--mt);margin-bottom:6px;margin-top:10px}
.tp-lbl:first-child{margin-top:0}
.swatches{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:4px}
.sw{width:22px;height:22px;border-radius:5px;border:2px solid transparent;cursor:pointer;transition:transform .12s}
.sw:hover,.sw.on{transform:scale(1.22);border-color:#fff}
.tp-row{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:4px}
.tp-row select,.tp-row input[type=color]{background:var(--sur2);border:1px solid var(--bdr);border-radius:6px;padding:5px 8px;color:var(--tx);font-size:12px;font-family:'Courier New',Consolas,monospace;outline:none}
.tp-row input[type=range]{flex:1;cursor:pointer;accent-color:var(--acc)}
.tp-row input[type=color]{width:36px;height:30px;padding:2px;cursor:pointer;border-radius:6px}
.tp-divider{height:1px;background:var(--bdr);margin:10px 0}
.bg-presets{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:6px}
.bg-pre{width:28px;height:22px;border-radius:5px;border:2px solid transparent;cursor:pointer;transition:transform .12s}
.bg-pre:hover,.bg-pre.on{transform:scale(1.18);border-color:#fff}

@media(max-width:960px){
  .proj-hdr,.proj-row{grid-template-columns:6px 2fr 1fr 1fr 100px}
  .proj-hdr span:nth-child(5),.proj-hdr span:nth-child(6),
  .proj-row>*:nth-child(5),.proj-row>*:nth-child(6){display:none}
}
@media(max-width:640px){
  .summary-grid{grid-template-columns:1fr}
  .proj-hdr,.proj-row{grid-template-columns:6px 1fr 80px}
  .proj-hdr span:nth-child(n+3):not(:last-child),.proj-row>*:nth-child(n+3):not(:last-child){display:none}
}

/* ── Pagination ─────────────────────────────────────────────────────────── */
.pagination-bar{display:flex;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:10px;padding:14px 0;margin-top:8px}
.pag-info{font-family:'Courier New',Consolas,monospace;font-size:11px;color:var(--muted)}
.pag-btns{display:flex;gap:5px;flex-wrap:wrap}
.pag-btn{display:inline-flex;align-items:center;justify-content:center;
  min-width:34px;height:30px;padding:0 10px;border-radius:6px;font-size:12px;font-weight:600;
  font-family:'Segoe UI',Tahoma,Arial,sans-serif;text-decoration:none;
  background:var(--surface2);border:1px solid var(--border);color:var(--muted);
  transition:all .15s;cursor:pointer}
.pag-btn:hover:not(.disabled):not(.active){background:var(--surface3);color:var(--text)}
.pag-btn.active{background:var(--accent);color:#000;border-color:var(--accent);cursor:default}
.pag-btn.disabled{opacity:.35;cursor:not-allowed}

/* ── Advanced Filters ────────────────────────────────────────────────────── */
.filter-row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.filter-extended{padding-top:8px;border-top:1px solid var(--border)}
#filter-toolbar{flex-direction:column;align-items:stretch;gap:8px;padding:10px 14px}
</style>
</head>
<body>

<?php $nav_active="dashboard"; require_once __DIR__ . "/includes/navbar.php"; ?>
<div class="bar">
  <span class="logo">DEVVAULT</span>
  <form method="GET" class="bar-search" id="sf">
    <span class="si">🔍</span>
    <input type="text" name="q" id="qi" placeholder="Search project, dept, IP..." value="<?=htmlspecialchars($q)?>">
    <?php if($status):?><input type="hidden" name="status" value="<?=htmlspecialchars($status)?>"><?php endif;?>
    <?php if($tech):?><input type="hidden" name="tech" value="<?=htmlspecialchars($tech)?>"><?php endif;?>
  </form>
  <div class="bar-r">
    <span class="live-dot" title="Stats auto-refresh every 30s">LIVE</span>
    <?php if(can_edit()):?>
    <a href="project_form.php" class="btn btn-acc">＋ Add Project</a>
    <?php endif;?>
    <a href="sr.php" class="btn btn-ghost btn-icon" title="Service Requests">📋</a>
    <a href="findings.php" class="btn btn-ghost btn-icon" title="Audit Findings">🔍</a>
    <a href="workorders.php" class="btn btn-ghost btn-icon" title="Work Orders">📝</a>
    <a href="reports.php" class="btn btn-ghost btn-icon" title="Reports">📊</a>
    <?php if(can_edit()):?>
    <a href="import.php" class="btn btn-ghost btn-icon" title="Import Projects">📥</a>
    <?php endif;?>
    <a href="export.php" class="btn btn-ghost btn-icon" title="Export">📤</a>
    <?php if(is_admin()):?>
    <a href="admin.php" class="btn btn-ghost btn-icon" title="Admin">⚙</a>
    <?php endif;?>
    <div style="position:relative">
      <button class="btn btn-ghost btn-icon" id="tb" onclick="toggleTheme()" title="Customize">🎨</button>
      <div class="tpanel" id="tp">

        <div class="tp-lbl">Accent Color</div>
        <div class="swatches" id="acc-swatches">
          <?php foreach(['#00d4ff','#00e676','#ffd740','#ff3d5a','#ea80fc','#8c9eff','#ff6e40','#40c4ff','#ffffff'] as $c):?>
          <div class="sw <?=$c===$accent?'on':''?>" style="background:<?=$c?>" onclick="setAcc('<?=$c?>')"></div>
          <?php endforeach;?>
        </div>
        <div class="tp-row">
          <input type="color" id="acc-custom" value="<?=$accent?>" oninput="setAcc(this.value)" title="Custom accent">
          <span style="font-family:'Courier New',Consolas,monospace;font-size:11px;color:var(--mt)" id="acc-hex"><?=$accent?></span>
        </div>

        <div class="tp-divider"></div>

        <div class="tp-lbl">Background Color</div>
        <div class="bg-presets" id="bg-presets">
          <?php foreach([
            ''        => 'var(--bg)',
            '#0a0a0f' => '#0a0a0f',
            '#0f1923' => '#0f1923',
            '#091a09' => '#091a09',
            '#1a0909' => '#1a0909',
            '#100a1a' => '#100a1a',
            '#1a1500' => '#1a1500',
            '#f0f4f8' => '#f0f4f8',
          ] as $val=>$show):
            $isActive = ($bg===$val)||($val===''&&!$bg);
          ?>
          <div class="bg-pre <?=$isActive?'on':''?>"
               style="background:<?=$show?>;border-color:<?=$isActive?'#fff':'transparent'?>"
               onclick="setBg('<?=$val?>')"
               title="<?=$val?:'Default'?>"></div>
          <?php endforeach;?>
        </div>
        <div class="tp-row">
          <input type="color" id="bg-custom" value="<?=$bg?:'#070b14'?>" oninput="setBg(this.value)" title="Custom background">
          <span style="font-family:'Courier New',Consolas,monospace;font-size:11px;color:var(--mt)">Custom BG</span>
          <button onclick="setBg('')" class="btn btn-ghost" style="padding:3px 8px;font-size:11px">Reset</button>
        </div>

        <div class="tp-divider"></div>

        <div class="tp-lbl">Theme Mode</div>
        <div class="tp-row">
          <select onchange="setThemeMode(this.value)" style="flex:1">
            <option value="dark" <?=$theme==='dark'?'selected':''?>>🌙 Dark</option>
            <option value="light" <?=$theme==='light'?'selected':''?>>☀ Light</option>
          </select>
        </div>

        <div class="tp-lbl">Font Family</div>
        <div class="tp-row">
          <select onchange="setFont(this.value)" id="fsel" style="flex:1">
            <?php foreach(['Rajdhani','Share Tech Mono','Orbitron'] as $f):?>
            <option value="<?=$f?>" <?=$ffamily===$f?'selected':''?>><?=$f?></option>
            <?php endforeach;?>
          </select>
        </div>

        <div class="tp-lbl">Font Size: <span id="fsv"><?=$fsize?></span>px</div>
        <div class="tp-row">
          <input type="range" min="11" max="18" value="<?=$fsize?>" oninput="setFs(this.value)">
        </div>

        <div class="tp-divider"></div>
        <button class="btn btn-acc" style="width:100%;justify-content:center;margin-top:4px" onclick="savePrefs()">💾 Save Preferences</button>
      </div>
    </div>
    <span id="session-timer-display" title="Session timer">⏱ 05:00</span>
    <a href="logout.php" class="btn btn-ghost btn-icon" title="Logout — <?=htmlspecialchars($_SESSION['username'])?>">⏏</a>
  </div>
</div>

<div class="content">
<?php if($flash):?>
<div class="flash flash-<?=$flash['type']?>"><?=htmlspecialchars($flash['msg'])?></div>
<?php endif;?>

<!-- STAT CHIPS — clean horizontal strip -->
<div class="chips-strip" id="chips-strip">
  <a class="chip <?=!$status&&!$tech&&!$q?'active':''?>" href="index.php">
    <div class="chip-left">
      <span class="chip-num" id="stat-total" style="color:var(--acc)"><?=$total?></span>
      <span class="chip-lbl">Total</span>
    </div>
  </a>
  <a class="chip <?=$status==='live'?'active':''?>" href="index.php?status=live">
    <span class="chip-dot" style="background:#00e676"></span>
    <div class="chip-left">
      <span class="chip-num" id="stat-live" style="color:#00e676"><?=$statusCounts['live']??0?></span>
      <span class="chip-lbl">Live</span>
    </div>
  </a>
  <a class="chip <?=$status==='under_development'?'active':''?>" href="index.php?status=under_development">
    <span class="chip-dot" style="background:#ffd740"></span>
    <div class="chip-left">
      <span class="chip-num" id="stat-dev" style="color:#ffd740"><?=$statusCounts['under_development']??0?></span>
      <span class="chip-lbl">Under Dev</span>
    </div>
  </a>
  <a class="chip <?=$status==='redevelopment'?'active':''?>" href="index.php?status=redevelopment">
    <span class="chip-dot" style="background:#40c4ff"></span>
    <div class="chip-left">
      <span class="chip-num" id="stat-redev" style="color:#40c4ff"><?=$statusCounts['redevelopment']??0?></span>
      <span class="chip-lbl">Redev</span>
    </div>
  </a>
  <a class="chip <?=$status==='closed'?'active':''?>" href="index.php?status=closed">
    <span class="chip-dot" style="background:#ff3d5a"></span>
    <div class="chip-left">
      <span class="chip-num" id="stat-closed" style="color:#ff3d5a"><?=$statusCounts['closed']??0?></span>
      <span class="chip-lbl">Closed</span>
    </div>
  </a>
  <a class="chip" href="index.php">
    <span class="chip-dot" style="background:#00e676"></span>
    <div class="chip-left">
      <span class="chip-num" id="stat-prod" style="color:#00e676"><?=$envStats['production']??0?></span>
      <span class="chip-lbl">Production</span>
    </div>
  </a>
  <a class="chip" href="index.php">
    <span class="chip-dot" style="background:#ffd740"></span>
    <div class="chip-left">
      <span class="chip-num" id="stat-staging" style="color:#ffd740"><?=$envStats['staging']??0?></span>
      <span class="chip-lbl">Staging</span>
    </div>
  </a>
  <a class="chip" href="report.php" style="border-left:3px solid var(--acc)">
    <span class="chip-dot" style="background:var(--acc)"></span>
    <div class="chip-left">
      <span class="chip-num" id="stat-appsrv" style="color:var(--acc)"><?=$uniqueAppServers?></span>
      <span class="chip-lbl">Unique App Servers</span>
    </div>
  </a>
  <a class="chip" href="report.php" style="border-left:3px solid #ea80fc">
    <span class="chip-dot" style="background:#ea80fc"></span>
    <div class="chip-left">
      <span class="chip-num" id="stat-dbsrv" style="color:#ea80fc"><?=$uniqueDbServers?></span>
      <span class="chip-lbl">Unique DB Servers</span>
    </div>
  </a>
  <a class="chip" href="findings.php?status=Open" style="border-left:3px solid <?=$openFindings>0?'#ef4444':'#10b981'?>">
    <span class="chip-dot" style="background:<?=$openFindings>0?'#ef4444':'#10b981'?>"></span>
    <div class="chip-left">
      <span class="chip-num" style="color:<?=$openFindings>0?'#ef4444':'#10b981'?>"><?=$openFindings?></span>
      <span class="chip-lbl">Open Findings<?=$overdueFindings>0?" ($overdueFindings overdue)":'';?></span>
    </div>
  </a>
  <a class="chip" href="sr.php" style="border-left:3px solid #f59e0b">
    <span class="chip-dot" style="background:#f59e0b"></span>
    <div class="chip-left">
      <span class="chip-num" style="color:#f59e0b"><?=$openSRs?></span>
      <span class="chip-lbl">Open SRs</span>
    </div>
  </a>
  <a class="chip" href="workorders.php?status=Active" style="border-left:3px solid #8c9eff">
    <span class="chip-dot" style="background:#8c9eff"></span>
    <div class="chip-left">
      <span class="chip-num" style="color:#8c9eff"><?=$activeWOs?></span>
      <span class="chip-lbl">Active Work Orders</span>
    </div>
  </a>
</div>

<!-- DASHBOARD ALERTS (FF-01) -->
<?php if (!empty($dash_alerts)): ?>
<div style="display:flex;flex-direction:column;gap:6px;margin:0 0 14px">
  <?php foreach($dash_alerts as $al): ?>
  <?php
    $al_bg  = $al['type']==='danger' ? 'rgba(255,61,90,.08)'  : ($al['type']==='warn' ? 'rgba(255,215,64,.07)' : 'rgba(0,212,255,.06)');
    $al_brd = $al['type']==='danger' ? 'rgba(255,61,90,.3)'   : ($al['type']==='warn' ? 'rgba(255,215,64,.3)'  : 'rgba(0,212,255,.25)');
    $al_txt = $al['type']==='danger' ? '#ff8a80'              : ($al['type']==='warn' ? '#ffd740'              : '#80d8ff');
  ?>
  <a href="<?= htmlspecialchars($al['link']) ?>" style="display:flex;align-items:center;gap:10px;padding:9px 14px;
    background:<?=$al_bg?>;border:1px solid <?=$al_brd?>;border-radius:8px;
    text-decoration:none;transition:all .15s"
    onmouseover="this.style.paddingLeft='18px'" onmouseout="this.style.paddingLeft='14px'">
    <span style="font-size:16px"><?= $al['icon'] ?></span>
    <span style="font-size:12px;font-weight:600;color:<?=$al_txt?>;font-family:'Segoe UI',Tahoma,Arial,sans-serif">
      <?= htmlspecialchars($al['msg']) ?>
    </span>
    <span style="margin-left:auto;font-size:11px;color:<?=$al_txt?>;opacity:.7">→</span>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- SUMMARY -->
<div class="summary-grid">
  <div class="panel">
    <div class="panel-hd"><h2>📊 Status Overview</h2></div>
    <table class="sum-tbl">
      <tr><th>Status / Environment</th><th style="text-align:center">Count</th></tr>
      <tr><td><span class="badge" style="color:var(--acc);border-color:var(--acc)40">⊞ Total Projects</span></td>
        <td class="num-c" style="color:var(--acc)" id="tbl-total"><?=$total?></td></tr>
      <?php foreach($SL as $k=>$l):?>
      <tr>
        <td><span class="badge" style="color:<?=$SC[$k]?>;border-color:<?=$SC[$k]?>40"><?=$l?></span></td>
        <td class="num-c" style="color:<?=$SC[$k]?>" id="tbl-<?=$k?>"><?=$statusCounts[$k]??0?></td>
      </tr>
      <?php endforeach;?>
      <tr><td><span class="badge" style="color:#00e676;border-color:#00e67640">🟢 Production (env)</span></td>
        <td class="num-c" style="color:#00e676" id="tbl-production"><?=$envStats['production']??0?></td></tr>
      <tr><td><span class="badge" style="color:#ffd740;border-color:#ffd74040">🟡 Staging (env)</span></td>
        <td class="num-c" style="color:#ffd740" id="tbl-staging"><?=$envStats['staging']??0?></td></tr>
    </table>
  </div>
  <div class="panel">
    <div class="panel-hd"><h2>🛠 Technology Breakdown</h2><span id="last-refresh" style="font-family:'Courier New',Consolas,monospace;font-size:9px;color:var(--mt)"></span></div>
    <table class="sum-tbl" id="tech-tbl">
      <tr>
        <th>Technology</th>
        <th style="text-align:center;color:#00e676">Live</th>
        <th style="text-align:center;color:#ffd740">Dev</th>
        <th style="text-align:center;color:#40c4ff">Redev</th>
        <th style="text-align:center;color:#ff3d5a">Closed</th>
      </tr>
      <?php if(empty($techTable)):?>
      <tr><td colspan="5" style="color:var(--mt);text-align:center;padding:14px">No data</td></tr>
      <?php else:foreach($techTable as $t=>$c):?>
      <tr>
        <td style="font-weight:700"><?=htmlspecialchars($t)?></td>
        <?php foreach(['live','under_development','redevelopment','closed'] as $s):?>
        <td class="num-c" style="color:<?=$SC[$s]?>"><?=$c[$s]??'–'?></td>
        <?php endforeach;?>
      </tr>
      <?php endforeach;endif;?>
    </table>
  </div>
</div>

<!-- ADVANCED FILTER TOOLBAR -->
<div class="toolbar" id="filter-toolbar">
  <div class="filter-row">
    <!-- Status -->
    <select class="fsel" onchange="applyFilter('status',this.value)" title="Filter by Status">
      <option value="">All Status</option>
      <?php foreach($SL as $k=>$l):?>
      <option value="<?=$k?>" <?=$status===$k?'selected':''?>><?=$l?></option>
      <?php endforeach;?>
    </select>

    <!-- Technology -->
    <select class="fsel" onchange="applyFilter('tech',this.value)" title="Filter by Technology">
      <option value="">All Technologies</option>
      <?php foreach(array_keys($techTable) as $t):?>
      <option value="<?=htmlspecialchars($t)?>" <?=($tech===$t)?'selected':''?>><?=htmlspecialchars($t)?></option>
      <?php endforeach;?>
    </select>

    <!-- Hosting Type -->
    <select class="fsel" onchange="applyFilter('hosting',this.value)" title="Filter by Hosting">
      <option value="">All Hosting</option>
      <?php foreach(['NIC','State DC','Cloud','Dedicated','Co-location','Other'] as $ht):?>
      <option value="<?=htmlspecialchars($ht)?>" <?=$hosting===$ht?'selected':''?>><?=htmlspecialchars($ht)?></option>
      <?php endforeach;?>
    </select>

    <!-- Environment -->
    <select class="fsel" onchange="applyFilter('env',this.value)" title="Filter by Environment">
      <option value="">All Environments</option>
      <?php foreach(['production'=>'Production','staging'=>'Staging','local'=>'Local','audit'=>'Audit','other'=>'Other'] as $ev=>$el):?>
      <option value="<?=$ev?>" <?=$env_filter===$ev?'selected':''?>><?=$el?></option>
      <?php endforeach;?>
    </select>

    <!-- More Filters toggle -->
    <button class="btn btn-ghost btn-sm" onclick="toggleMoreFilters()" id="more-filters-btn"
      style="white-space:nowrap">⚙ More <?= ($dept||$audit_from||$audit_to) ? '🔵' : '' ?></button>

    <?php if($has_filters):?>
    <a href="index.php" class="btn btn-ghost btn-sm" style="color:#ff3d5a;border-color:rgba(255,61,90,.3)">✕ Clear All</a>
    <?php endif;?>

    <span class="result-count" id="proj-count" style="margin-left:auto">
      <?=intval($total_filtered)?> project<?=$total_filtered!==1?'s':''?>
      &nbsp;·&nbsp; Page <?=intval($current_page)?>/<?=intval($total_pages)?>
    </span>
  </div>

  <!-- Extended filters (hidden by default) -->
  <div class="filter-row filter-extended" id="more-filters"
    style="<?= ($dept||$audit_from||$audit_to)?'':'display:none' ?>">
    <!-- Department search -->
    <input type="text" class="fsel" id="dept-input" placeholder="🏢 Department name..."
      value="<?=htmlspecialchars($dept)?>"
      onkeydown="if(event.key==='Enter'){applyFilter('dept',this.value)}"
      title="Filter by Department (press Enter)">

    <!-- Audit date range -->
    <label style="font-size:11px;color:var(--muted);white-space:nowrap;align-self:center">Last Audit:</label>
    <input type="date" class="fsel" style="width:140px" value="<?=htmlspecialchars($audit_from)?>"
      onchange="applyFilter('audit_from',this.value)" title="Audit date from">
    <span style="color:var(--muted);font-size:12px">to</span>
    <input type="date" class="fsel" style="width:140px" value="<?=htmlspecialchars($audit_to)?>"
      onchange="applyFilter('audit_to',this.value)" title="Audit date to">

    <!-- Overdue only quick filter -->
    <label style="font-size:12px;color:var(--muted);white-space:nowrap;display:flex;align-items:center;gap:4px;cursor:pointer">
      <input type="checkbox" onchange="applyFilter('audit_to',this.checked?new Date(Date.now()-365*86400000).toISOString().split('T')[0]:'')"
        <?= $audit_to && strtotime($audit_to) < (time()-365*86400) ? 'checked' : '' ?>>
      Overdue only (>1 year)
    </label>
  </div>
</div>

<!-- PROJECT LIST -->
<?php if(empty($projects)):?>
<div class="empty">
  <div class="ei">📭</div>
  <h3>No Projects Found</h3>
  <p>Koi project nahi mila<?=$q||$status||$tech?' — filters clear karo':''?></p>
  <?php if(can_edit()):?>
  <a href="project_form.php" class="btn btn-acc" style="margin-top:14px">＋ Add First Project</a>
  <?php endif;?>
</div>
<?php else:?>
<div class="proj-list" id="proj-list">
  <div class="proj-hdr">
    <span></span>
    <span>Project / Department</span>
    <span>Environments</span>
    <span>Infrastructure</span>
    <span>Credentials</span>
    <span>Tech / Status</span>
    <span style="text-align:right">Actions</span>
  </div>
  <?php foreach($projects as $p):
    $sclr=$SC[$p['current_status']]??'#5a7a9a';
    $tl=$p['technology']==='Other'?$p['technology_other']:$p['technology'];
    $envs=['local','staging','production','audit','other'];
    $activeEnvs=array_filter($envs,fn($e)=>!empty($p["env_{$e}_url"]));
    $activeCreds=array_filter($envs,fn($e)=>!empty($p["env_{$e}_id"])||!empty($p["env_{$e}_password"]));
  ?>
  <div class="proj-row">
    <div><div class="status-bar" style="background:<?=$sclr?>"></div></div>
    <div>
      <div class="pname"><?=htmlspecialchars($p['project_name'])?></div>
      <div class="pdept"><?=htmlspecialchars($p['department_name']??'')?></div>
      <?php if($p['parent_admin_dept']):?>
      <div class="pdept" style="opacity:.65">↳ <?=htmlspecialchars($p['parent_admin_dept'])?></div>
      <?php endif;?>
      <?php if($p['description']):?>
      <div style="font-size:10px;color:var(--mt);font-family:'Courier New',Consolas,monospace;margin-top:2px;
        overflow:hidden;display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;max-width:200px">
        <?=htmlspecialchars($p['description'])?>
      </div>
      <?php endif;?>
    </div>
    <div>
      <div class="env-pills">
        <?php foreach($activeEnvs as $env):?>
        <a class="epill" style="color:<?=$EC[$env]?>" href="<?=htmlspecialchars($p["env_{$env}_url"])?>" target="_blank" rel="noopener" title="<?=htmlspecialchars($p["env_{$env}_url"])?>">
          <span class="edot"></span><?=$env?>
        </a>
        <?php endforeach;?>
        <?php if(empty($activeEnvs)):?><span style="font-family:'Courier New',Consolas,monospace;font-size:10px;color:var(--bdr)">—</span><?php endif;?>
      </div>
    </div>
    <div class="infra-kv">
      <?php if($p['app_ip']):?>
      <div class="kv"><span class="k">app</span><span class="v"><?=htmlspecialchars($p['app_ip'])?></span>
        <button class="copy-btn" onclick="cp('<?=htmlspecialchars($p['app_ip'])?>','App IP')">📋</button></div>
      <?php endif;?>
      <?php if($p['db_ip']):?>
      <div class="kv"><span class="k">db</span><span class="v"><?=htmlspecialchars($p['db_ip'])?></span>
        <button class="copy-btn" onclick="cp('<?=htmlspecialchars($p['db_ip'])?>','DB IP')">📋</button></div>
      <?php endif;?>
      <?php $osVal=$p['app_os']==='Other'?$p['app_os_other']:$p['app_os'];if($osVal):?>
      <div class="kv"><span class="k">os</span><span class="v" style="color:var(--mt)"><?=htmlspecialchars($osVal)?></span></div>
      <?php endif;?>
      <?php $dbTechVal=$p['db_technology']==='Other'?$p['db_technology_other']:$p['db_technology'];if($dbTechVal):?>
      <div class="kv"><span class="k">dbt</span><span class="v" style="color:var(--mt)"><?=htmlspecialchars($dbTechVal)?></span></div>
      <?php endif;?>
      <?php if(!$p['app_ip']&&!$p['db_ip']):?><span style="font-family:'Courier New',Consolas,monospace;font-size:10px;color:var(--bdr)">—</span><?php endif;?>
    </div>
    <div class="creds">
      <?php foreach(['production','staging','local','audit','other'] as $env):
        if(empty($p["env_{$env}_id"])&&empty($p["env_{$env}_password"])) continue;?>
      <div>
        <div class="cred-env" style="color:<?=$EC[$env]?>"><?=$env?></div>
        <?php if($p["env_{$env}_id"]):?>
        <div class="pw-row">
          <button class="copy-btn" onclick="cp('<?=htmlspecialchars($p["env_{$env}_id"])?>','Login ID')">📋</button>
          <span style="font-size:10px"><?=htmlspecialchars($p["env_{$env}_id"])?></span>
        </div>
        <?php endif;?>
        <?php if($p["env_{$env}_password"]):?>
        <div class="pw-row">
          <button class="pw-btn" onclick="cpPw(<?=$p['id']?>,'<?=$env?>','<?=csrf_token()?>')">📋</button>
          <span class="pw-mask" id="pw-<?=$p['id']?>-<?=$env?>">••••••</span>
          <button class="pw-btn" onclick="tpw(<?=$p['id']?>,'<?=$env?>','<?=csrf_token()?>')">👁</button>
        </div>
        <?php endif;?>
      </div>
      <?php endforeach;?>
      <?php if(empty($activeCreds)):?><span style="font-family:'Courier New',Consolas,monospace;font-size:10px;color:var(--bdr)">—</span><?php endif;?>
    </div>
    <div>
      <div style="display:flex;flex-direction:column;gap:4px">
        <span class="badge" style="color:<?=$sclr?>;border-color:<?=$sclr?>30;align-self:start"><?=$SL[$p['current_status']]??$p['current_status']?></span>
        <?php if($tl):?>
        <span style="font-family:'Courier New',Consolas,monospace;font-size:10px;color:var(--mt)">
          <?=htmlspecialchars($tl)?>
          <?php if(!empty($p['tech_subtype'])):?>
          <span style="color:var(--bdr);font-size:9px"> / <?=htmlspecialchars($p['tech_subtype'])?></span>
          <?php endif;?>
        </span>
        <?php endif;?>
        <?php
          // AMC badge
          $amc_t = $p['amc_type'] ?? '';
          $amc_clr = ['Paid'=>'#00e676','Exemption'=>'#ffd740','Free'=>'#40c4ff','NA'=>'#5a7a9a'];
          $amc_exp = !empty($p['amc_end_date']);
          $days_left = $amc_exp ? (int)((strtotime($p['amc_end_date']) - time()) / 86400) : 999;
        ?>
        <?php if($amc_t && $amc_t !== 'NA'):?>
        <span style="font-family:'Courier New',Consolas,monospace;font-size:9px;
          color:<?=$amc_clr[$amc_t]??'#5a7a9a'?>;
          border:1px solid <?=$amc_clr[$amc_t]??'#5a7a9a'?>30;
          padding:1px 5px;border-radius:3px;display:inline-block"
          title="AMC: <?=$amc_t?><?=$amc_exp?' | Expires: '.$p['amc_end_date'].($days_left<0?' (EXPIRED)':($days_left<=30?' ('.($days_left).'d left)':'')):'';?>">
          ₹ <?=$amc_t?>
          <?php if($days_left < 0):?> ⚠️<?php elseif($days_left <= 30):?> ⏰<?php endif;?>
        </span>
        <?php endif;?>
        <?php if($p['live_date']):?><span style="font-family:'Courier New',Consolas,monospace;font-size:9px;color:var(--bdr)">Live: <?=$p['live_date']?></span><?php endif;?>
        <span style="font-family:'Courier New',Consolas,monospace;font-size:9px;color:var(--bdr)"><?=date('d M Y',strtotime($p['updated_at']))?></span>
        <?php if($p['chk_total']>0):
          $chkPct = round($p['chk_done']/$p['chk_total']*100);?>
        <span style="font-family:'Courier New',Consolas,monospace;font-size:9px;color:var(--mt)" title="Checklist progress">
          ✅ <?=$p['chk_done']?>/<?=$p['chk_total']?> (<?=$chkPct?>%)
        </span>
        <?php endif;?>
        <?php if($p['doc_count']>0):?>
        <span style="font-family:'Courier New',Consolas,monospace;font-size:9px;color:var(--mt)" title="Documents attached">📎 <?=$p['doc_count']?> doc<?=$p['doc_count']!=1?'s':''?></span>
        <?php endif;?>
      </div>
    </div>
    <div class="acts">
      <?php if($p['general_remark']||$p['app_infra_remark']||$p['db_remark']):?>
      <button class="btn btn-ghost btn-sm" onclick="togNotes(<?=$p['id']?>)" title="Notes">📝</button>
      <?php endif;?>
      <?php if($p['nodal_officer_name']||!empty($extraContacts[$p['id']])):?>
      <button class="btn btn-ghost btn-sm" onclick="togNodal(<?=$p['id']?>)" title="Nodal Officer / Contacts">👤</button>
      <?php endif;?>
      <?php if(can_edit()):?>
      <a href="project_form.php?id=<?=$p['id']?>" class="btn btn-ghost btn-sm">✏</a>
      <?php endif;?>
      <?php if(is_admin()):?>
      <button class="btn btn-danger btn-sm" onclick="delProj(<?=$p['id']?>,'<?=htmlspecialchars($p['project_name'],ENT_QUOTES)?>','<?=csrf_token()?>')">🗑</button>
      <?php endif;?>
    </div>
  </div>
  <?php if($p['general_remark']||$p['app_infra_remark']||$p['db_remark']):?>
  <div class="notes-drawer" id="nd-<?=$p['id']?>">
    <?php foreach(['general_remark'=>'General Remark','app_infra_remark'=>'App Infra','db_remark'=>'DB Remark'] as $fld=>$lbl):if(!$p[$fld])continue;?>
    <div class="notes-sec" style="margin-bottom:6px">
      <div style="color:var(--acc);font-size:9px;text-transform:uppercase;letter-spacing:1px;margin-bottom:3px"><?=$lbl?></div>
      <div class="nt"><?=htmlspecialchars($p[$fld])?></div>
    </div>
    <?php endforeach;?>
  </div>
  <?php endif;?>
  <?php if($p['nodal_officer_name']||!empty($extraContacts[$p['id']])):?>
  <div class="nodal-drawer" id="nod-<?=$p['id']?>">
    <?php if($p['nodal_officer_name']):?>
    <div class="nodal-kv"><div class="nk">Officer</div><div class="nv"><?=htmlspecialchars($p['nodal_officer_name'])?></div></div>
    <?php if($p['nodal_designation']):?><div class="nodal-kv"><div class="nk">Designation</div><div class="nv"><?=htmlspecialchars($p['nodal_designation'])?></div></div><?php endif;?>
    <?php if($p['nodal_contact']):?><div class="nodal-kv"><div class="nk">Contact</div><div class="nv"><?=htmlspecialchars($p['nodal_contact'])?></div></div><?php endif;?>
    <?php if($p['dept_email']):?><div class="nodal-kv"><div class="nk">Email</div><div class="nv"><?=htmlspecialchars($p['dept_email'])?></div></div><?php endif;?>
    <?php endif;?>
    <?php foreach($extraContacts[$p['id']]??[] as $ec):?>
    <div class="nodal-kv" style="border-left:2px solid var(--bdr);padding-left:8px">
      <div class="nk">👤 <?=htmlspecialchars($ec['designation']?:'Contact')?></div>
      <div class="nv"><?=htmlspecialchars($ec['name'])?></div>
      <?php if($ec['contact']):?><div style="font-size:10px;color:var(--mt);font-family:'Courier New',Consolas,monospace"><?=htmlspecialchars($ec['contact'])?></div><?php endif;?>
      <?php if($ec['email']):?><div style="font-size:10px;color:var(--mt);font-family:'Courier New',Consolas,monospace"><?=htmlspecialchars($ec['email'])?></div><?php endif;?>
    </div>
    <?php endforeach;?>
  </div>
  <?php endif;?>
  <?php endforeach;?>
</div>
<?php endif;?>

<?php if($total_pages > 1): ?>
<div class="pagination-bar">
  <?php
  // Build current URL preserving all filters
  $purl = $_SERVER['PHP_SELF'] . '?';
  $pargs = [];
  if(!empty($q))      $pargs[] = 'q=' . urlencode($q);
  if(!empty($status)) $pargs[] = 'status=' . urlencode($status);
  if(!empty($tech))   $pargs[] = 'tech=' . urlencode($tech);
  $base_url = $purl . implode('&', $pargs);
  $sep = $pargs ? '&' : '';
  ?>
  <div class="pag-info"><?=intval($total_filtered)?> results &nbsp;·&nbsp; Showing <?=intval($offset+1)?>–<?=intval(min($offset+$per_page,$total_filtered))?></div>
  <div class="pag-btns">
    <?php if($current_page > 1): ?>
      <a href="<?=$base_url.$sep?>page=1" class="pag-btn" title="First">«</a>
      <a href="<?=$base_url.$sep?>page=<?=$current_page-1?>" class="pag-btn">‹ Prev</a>
    <?php else: ?>
      <span class="pag-btn disabled">«</span>
      <span class="pag-btn disabled">‹ Prev</span>
    <?php endif; ?>

    <?php
    // Show up to 5 page numbers around current page
    $start = max(1, $current_page - 2);
    $end   = min($total_pages, $current_page + 2);
    for($pg = $start; $pg <= $end; $pg++):
    ?>
      <a href="<?=$base_url.$sep?>page=<?=$pg?>"
         class="pag-btn<?=$pg===$current_page?' active':''?>"><?=$pg?></a>
    <?php endfor; ?>

    <?php if($current_page < $total_pages): ?>
      <a href="<?=$base_url.$sep?>page=<?=$current_page+1?>" class="pag-btn">Next ›</a>
      <a href="<?=$base_url.$sep?>page=<?=$total_pages?>" class="pag-btn" title="Last">»</a>
    <?php else: ?>
      <span class="pag-btn disabled">Next ›</span>
      <span class="pag-btn disabled">»</span>
    <?php endif; ?>
  </div>
</div>
<?php endif;?>
</div>

<div class="toast" id="toast"><span id="tt"></span></div>

<script nonce="<?= csp_nonce() ?>">
// ── Search debounce ──
let st;document.getElementById('qi').addEventListener('input',function(){clearTimeout(st);st=setTimeout(()=>this.closest('form').submit(),500)});
function applyFilter(k,v){
  const u=new URL(window.location);
  if(v){u.searchParams.set(k,v);}else{u.searchParams.delete(k);}
  u.searchParams.delete('page'); // reset pagination on filter change
  window.location=u;
}
function toggleMoreFilters(){
  var el=document.getElementById('more-filters');
  var btn=document.getElementById('more-filters-btn');
  if(el.style.display==='none'){el.style.display='flex';btn.style.color='var(--accent)';}
  else{el.style.display='none';btn.style.color='';}
}

// ── Toast ──
function toast(m,dur=2400){const t=document.getElementById('toast');document.getElementById('tt').textContent=m;t.style.display='flex';setTimeout(()=>t.style.display='none',dur)}
function cp(txt,lbl){navigator.clipboard.writeText(txt).then(()=>toast('📋 '+lbl+' copied!'))}

// ── Password ──
const pvs={};
async function tpw(id,env,csrf){
  const el=document.getElementById(`pw-${id}-${env}`);const key=`${id}-${env}`;
  if(pvs[key]){el.textContent='••••••';pvs[key]=false;return}
  const r=await fetch(`api.php?action=get_pw&id=${id}&env=${env}&csrf=${csrf}`);
  const d=await r.json();if(d.pw){el.textContent=d.pw;pvs[key]=true}}
async function cpPw(id,env,csrf){
  const r=await fetch(`api.php?action=get_pw&id=${id}&env=${env}&csrf=${csrf}`);
  const d=await r.json();if(d.pw)navigator.clipboard.writeText(d.pw).then(()=>toast('🔑 Password copied!'))}

// ── Drawers ──
function togNotes(id){document.getElementById('nd-'+id)?.classList.toggle('open')}
function togNodal(id){document.getElementById('nod-'+id)?.classList.toggle('open')}

// ── Delete ──
function delProj(id,name,csrf){
  if(!confirm(`Delete "${name}"?\nThis cannot be undone.`))return;
  const f=document.createElement('form');f.method='POST';f.action='api.php';
  f.innerHTML=`<input name="action" value="delete_project"><input name="id" value="${id}"><input name="csrf" value="${csrf}">`;
  document.body.appendChild(f);f.submit()}

// ── Theme panel ──
function toggleTheme(){document.getElementById('tp').classList.toggle('open')}
document.addEventListener('click',e=>{if(!e.target.closest('#tb')&&!e.target.closest('#tp'))document.getElementById('tp').classList.remove('open')})

let currentAcc='<?=$accent?>',currentBg='<?=$bg?>';

function setAcc(c){
  currentAcc=c;
  document.documentElement.style.setProperty('--acc',c);
  document.getElementById('acc-custom').value=c;
  document.getElementById('acc-hex').textContent=c;
  document.querySelectorAll('#acc-swatches .sw').forEach(s=>s.classList.toggle('on',s.style.background===c))}

function setBg(c){
  currentBg=c;
  const val=c||'';
  document.documentElement.style.setProperty('--user-bg',val?val:'unset');
  document.body.style.background=val||'';
  if(c)document.getElementById('bg-custom').value=c;
  document.querySelectorAll('#bg-presets .bg-pre').forEach((el,i)=>{
    const presets=['','#0a0a0f','#0f1923','#091a09','#1a0909','#100a1a','#1a1500','#f0f4f8'];
    el.classList.toggle('on',presets[i]===val)})}

function setThemeMode(v){document.documentElement.setAttribute('data-theme',v)}
function setFont(v){document.body.style.fontFamily=`'${v}',sans-serif`}
function setFs(v){document.documentElement.style.fontSize=v+'px';document.getElementById('fsv').textContent=v}

async function savePrefs(){
  const th=document.documentElement.getAttribute('data-theme');
  const fn=document.getElementById('fsel').value;
  const fs=document.querySelector('.tpanel input[type=range]').value;
  const r=await fetch('api.php',{method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body:`action=save_prefs&accent=${encodeURIComponent(currentAcc)}&bg=${encodeURIComponent(currentBg)}&theme=${th}&font=${encodeURIComponent(fn)}&fs=${fs}&csrf=<?=csrf_token()?>`});
  const d=await r.json();if(d.ok)toast('✅ Preferences saved!')}

// ── LIVE STATS REFRESH (every 30s) ──
function animateNum(el,newVal){
  const old=parseInt(el.textContent)||0;
  if(old===newVal)return;
  const step=newVal>old?1:-1;
  const dur=Math.min(600,Math.abs(newVal-old)*80);
  let cur=old;const iv=setInterval(()=>{
    cur+=step;el.textContent=cur;
    if(cur===newVal)clearInterval(iv)},dur/Math.abs(newVal-old))}

async function refreshStats(){
  try{
    const r=await fetch('api.php?action=stats&csrf=<?=csrf_token()?>');
    if(!r.ok)return;
    const d=await r.json();
    if(!d.stats)return;
    const s=d.stats;
    // Animate chip numbers
    animateNum(document.getElementById('stat-total'),s.total||0);
    animateNum(document.getElementById('stat-live'),s.live||0);
    animateNum(document.getElementById('stat-dev'),s.under_development||0);
    animateNum(document.getElementById('stat-redev'),s.redevelopment||0);
    animateNum(document.getElementById('stat-closed'),s.closed||0);
    animateNum(document.getElementById('stat-prod'),s.production||0);
    animateNum(document.getElementById('stat-staging'),s.staging||0);
    animateNum(document.getElementById('stat-appsrv'),s.appsrv||0);
    animateNum(document.getElementById('stat-dbsrv'),s.dbsrv||0);
    // Update summary table nums — all rows including total, production, staging
    ['request_received','live','under_development','redevelopment','hold_by_department','content_updation','closed'].forEach(k=>{
      const el=document.getElementById('tbl-'+k);if(el)animateNum(el,s[k]||0)});
    const totEl=document.getElementById('tbl-total');if(totEl)animateNum(totEl,s.total||0);
    const prEl=document.getElementById('tbl-production');if(prEl)animateNum(prEl,s.production||0);
    const stEl=document.getElementById('tbl-staging');if(stEl)animateNum(stEl,s.staging||0);
    // Last refresh time
    const lr=document.getElementById('last-refresh');
    if(lr){const now=new Date();lr.textContent='↻ '+now.toLocaleTimeString()}
  }catch(e){}}

// Start live refresh
setInterval(refreshStats,30000);
// First refresh after 5s
setTimeout(refreshStats,5000);
</script>

<script>
// DevVault Pro — Session Timer config
window.DEVVAULT_CSRF    = '<?= csrf_token() ?>';
window.DEVVAULT_LOGOUT  = 'logout.php';
</script>
<script src="session_timer.js"></script>
</body>
</html>
