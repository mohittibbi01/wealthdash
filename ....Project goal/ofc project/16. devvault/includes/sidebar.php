<?php
/**
 * DevVault Pro — Sidebar include v2
 * Set $nav_active and $page_title before including
 */
$nav_active = $nav_active ?? '';
$page_title = $page_title ?? 'DevVault Pro';

// Load counts for badges
$_sb_findings = $_sb_srs = $_sb_locked = 0;
try {
    $db = get_db();
    $_sb_findings = (int)$db->query("SELECT COUNT(*) FROM audit_findings WHERE current_status!='Closed'")->fetchColumn();
    $_sb_srs      = (int)$db->query("SELECT COUNT(*) FROM service_requests WHERE current_status NOT IN ('Resolved','Closed')")->fetchColumn();
    $_sb_locked   = (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE attempts>=5 AND (strftime('%s','now')-strftime('%s',last_attempt_at))<900")->fetchColumn();
} catch(Exception $_e){}

$_u     = $_SESSION['username'] ?? '';
$_role  = $_SESSION['role']     ?? '';
$_init  = strtoupper(substr($_u,0,1)) ?: '?';
$_theme = user_pref('theme','teal-dark');
$_fs    = max(11,min(18,(int)user_pref('font_size','14')));
$_cb    = user_pref('colorblind','none');
$_acc   = preg_replace('/[^#a-fA-F0-9]/','',user_pref('accent','#00d4aa'));
if(strlen($_acc)<4) $_acc='#00d4aa';
?>
<!DOCTYPE html>
<html lang="en" data-theme="<?=htmlspecialchars($_theme)?>"<?=$_cb!=='none'?' data-colorblind="'.htmlspecialchars($_cb).'"':''?> style="font-size:<?=$_fs?>px">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=htmlspecialchars($page_title)?> — DevVault Pro</title>
<link rel="stylesheet" href="assets/theme.css">
<?php if($_acc !== '#00d4aa'): ?>
<style>:root{--acc:<?=htmlspecialchars($_acc)?>;--acc-dim:color-mix(in srgb,<?=htmlspecialchars($_acc)?> 13%,transparent)}</style>
<?php endif; ?>
</head>
<body>
<div class="dv-layout" id="dv-layout">

<!-- SIDEBAR -->
<aside class="dv-sidebar" id="dv-sidebar">
  <div class="dv-sb-header">
    <span class="dv-sb-logo">🔐 DEVVAULT</span>
    <button class="dv-sb-toggle" id="sb-toggle" title="Toggle sidebar">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
  </div>

  <nav class="dv-sb-nav">
    <?php if(!is_viewer() && can_edit()): ?>
    <a href="project_form.php" class="dv-sb-link btn-primary" style="margin:0 2px 4px;border-radius:8px;justify-content:center;padding:8px 10px">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      <span class="sb-label" style="font-weight:700">Add Project</span>
    </a>
    <?php if($nav_active==='sr'): ?>
    <button type="button" class="dv-sb-link" data-action="open-sr-modal" style="margin:0 2px 8px;border-radius:8px;justify-content:center;padding:7px 10px;border:1px dashed var(--acc);color:var(--acc)">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      <span class="sb-label" style="font-weight:600">Add SR</span>
    </button>
    <?php elseif($nav_active==='findings'): ?>
    <button type="button" class="dv-sb-link" data-action="open-finding-modal" style="margin:0 2px 8px;border-radius:8px;justify-content:center;padding:7px 10px;border:1px dashed var(--acc);color:var(--acc)">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      <span class="sb-label" style="font-weight:600">Add Finding</span>
    </button>
    <?php elseif($nav_active==='workorders'): ?>
    <button type="button" class="dv-sb-link" data-action="open-wo-modal" style="margin:0 2px 8px;border-radius:8px;justify-content:center;padding:7px 10px;border:1px dashed var(--acc);color:var(--acc)">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      <span class="sb-label" style="font-weight:600">New Work Order</span>
    </button>
    <?php else: ?>
    <div style="margin-bottom:8px"></div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="dv-sb-group">Main</div>
    <a href="index.php" class="dv-sb-link <?=$nav_active==='dashboard'?'active':''?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      <span class="sb-label">Dashboard</span>
    </a>
    <a href="sr.php" class="dv-sb-link <?=$nav_active==='sr'?'active':''?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      <span class="sb-label">Service Requests</span>
      <?php if($_sb_srs>0):?><span class="sb-badge"><?=$_sb_srs?></span><?php endif;?>
    </a>
    <a href="findings.php" class="dv-sb-link <?=$nav_active==='findings'?'active':''?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
      <span class="sb-label">Findings</span>
      <?php if($_sb_findings>0):?><span class="sb-badge"><?=$_sb_findings?></span><?php endif;?>
    </a>
    <a href="workorders.php" class="dv-sb-link <?=$nav_active==='workorders'?'active':''?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 3l-4 4-4-4"/></svg>
      <span class="sb-label">Work Orders</span>
    </a>
    <a href="reports.php" class="dv-sb-link <?=$nav_active==='reports'?'active':''?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/><path d="M2 20h20"/></svg>
      <span class="sb-label">Reports</span>
    </a>

    <?php if(!is_viewer()):?>
    <div class="dv-sb-group">Data</div>
    <a href="export.php" class="dv-sb-link <?=$nav_active==='export'?'active':''?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      <span class="sb-label">Export</span>
    </a>
    <a href="import.php" class="dv-sb-link <?=$nav_active==='import'?'active':''?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      <span class="sb-label">Import</span>
    </a>
    <?php endif;?>

    <?php if(is_admin()):?>
    <div class="dv-sb-group">Admin</div>
    <a href="admin.php" class="dv-sb-link <?=$nav_active==='admin'?'active':''?>">
      <svg class="sb-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M12 14c-6 0-8 3-8 3v1h16v-1s-2-3-8-3z"/></svg>
      <span class="sb-label">Admin</span>
      <?php if($_sb_locked>0):?><span class="sb-badge"><?=$_sb_locked?></span><?php endif;?>
    </a>
    <?php endif;?>
  </nav>

  <!-- Footer -->
  <div class="dv-sb-footer">
    <div class="dv-sb-timer">
      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--tx3);flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
      <span id="session-timer-display" style="font-family:'JetBrains Mono',monospace;font-size:11px;color:var(--tx3)" class="sb-label">05:00</span>
    </div>
    <div class="dv-sb-user">
      <div class="dv-sb-avatar"><?=$_init?></div>
      <div class="dv-sb-uinfo">
        <div class="dv-sb-uname"><?=htmlspecialchars($_u)?></div>
        <div class="dv-sb-urole"><?=htmlspecialchars($_role)?></div>
      </div>
    </div>
    <div class="dv-sb-actions">
      <button id="sb-theme-btn" class="btn btn-ghost btn-sm" style="flex:1;font-size:11px;gap:4px" title="Appearance">
        <span>🎨</span><span class="sb-label">Theme</span>
      </button>
      <a href="logout.php" class="btn btn-ghost btn-sm" style="color:var(--err);border-color:color-mix(in srgb,var(--err) 30%,transparent)" title="Logout">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      </a>
    </div>
  </div>
</aside>

<!-- Theme panel -->
<div id="theme-panel" class="theme-panel" style="display:none;position:fixed;bottom:70px;z-index:400;left:238px">
  <h4>🎨 Color Theme</h4>
  <div class="theme-swatches">
    <button class="theme-swatch" data-t="teal-dark"     title="Teal Dark"     style="background:#00d4aa"></button>
    <button class="theme-swatch" data-t="teal-light"    title="Teal Light"    style="background:#007a62"></button>
    <button class="theme-swatch" data-t="purple-dark"   title="Purple Dark"   style="background:#a78bfa"></button>
    <button class="theme-swatch" data-t="orange-dark"   title="Orange Dark"   style="background:#fb923c"></button>
    <button class="theme-swatch" data-t="high-contrast" title="High Contrast (Colorblind)" style="background:conic-gradient(#000 50%,#ff0 50%);border:2px solid #555"></button>
  </div>
  <h4>👁 Colorblind Mode</h4>
  <div style="display:flex;flex-direction:column;gap:2px;margin-bottom:14px">
    <button class="cb-opt" data-cb="none">Normal vision</button>
    <button class="cb-opt" data-cb="deuteranopia">🔵 Deuteranopia</button>
    <button class="cb-opt" data-cb="protanopia">🟡 Protanopia</button>
    <button class="cb-opt" data-cb="tritanopia">🟠 Tritanopia</button>
  </div>
  <h4>🔤 Font Size</h4>
  <div class="fs-row">
    <span style="font-size:11px;color:var(--tx3)">A</span>
    <input type="range" id="fs-slider" min="11" max="18" value="<?=$_fs?>">
    <span style="font-size:15px;color:var(--tx3)">A</span>
    <span class="fs-val" id="fs-val"><?=$_fs?>px</span>
  </div>
  <div style="display:flex;gap:8px">
    <button class="btn btn-primary" id="theme-save" style="flex:1">💾 Save</button>
    <button class="btn btn-ghost" id="theme-close">✕</button>
  </div>
</div>

<!-- Mobile overlay -->
<div id="sb-overlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:299"></div>

<!-- MAIN -->
<div class="dv-main" id="dv-main">
  <div class="dv-topbar">
    <button id="sb-mobile-btn" class="btn btn-ghost btn-icon" style="display:none">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <span class="dv-topbar-title"><?=htmlspecialchars($page_title)?></span>
  </div>
  <!-- content -->
