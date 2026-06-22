<?php
/**
 * DevVault Pro — Universal Navbar
 * Include this at the top of every page body:
 *   require_once __DIR__ . '/includes/navbar.php';
 * OR from subdirectory:
 *   require_once dirname(__DIR__) . '/includes/navbar.php';
 *
 * Set $nav_active before including:
 *   $nav_active = 'findings'; // 'dashboard','sr','findings','workorders','reports','export','admin'
 */

$nav_active = $nav_active ?? '';

// Notification count: open findings + locked accounts
$_nav_alerts = 0;
try {
    $db = get_db();
    $_nav_alerts += (int)$db->query("SELECT COUNT(*) FROM audit_findings WHERE current_status != 'Closed'")->fetchColumn();
    // Overdue audits (no audit in 365 days)
    $_nav_overdue = (int)$db->query("SELECT COUNT(*) FROM projects WHERE (last_audit_date IS NULL OR last_audit_date < date('now','-365 days')) AND current_status='live'")->fetchColumn();
    $_nav_alerts += $_nav_overdue;
} catch (Exception $e) { $_nav_overdue = 0; }

// Locked accounts count
$_nav_locked = 0;
try {
    $_nav_locked = (int)$db->query("SELECT COUNT(*) FROM login_attempts WHERE attempts >= 5 AND (strftime('%s','now') - strftime('%s', last_attempt_at)) < 900")->fetchColumn();
} catch (Exception $e) {}

$_nav_total_alerts = $_nav_alerts + $_nav_locked;
?>
<div class="dv-navbar">
  <!-- LEFT: Logo + Nav links -->
  <a href="index.php" class="dv-logo">🔐 DEVVAULT</a>
  <span class="dv-sep">|</span>
  <nav class="dv-nav">
    <a href="index.php"       class="dv-link <?= $nav_active==='dashboard'  ?'dv-cur':'' ?>">🏠 Dashboard</a>
    <a href="sr.php"          class="dv-link <?= $nav_active==='sr'         ?'dv-cur':'' ?>">📋 SR</a>
    <a href="findings.php"    class="dv-link <?= $nav_active==='findings'   ?'dv-cur':'' ?>">🔍 Findings</a>
    <a href="workorders.php"  class="dv-link <?= $nav_active==='workorders' ?'dv-cur':'' ?>">📝 Work Orders</a>
    <a href="reports.php"     class="dv-link <?= $nav_active==='reports'    ?'dv-cur':'' ?>">📊 Reports</a>
    <?php if (!is_viewer()): ?>
    <a href="export.php"      class="dv-link <?= $nav_active==='export'     ?'dv-cur':'' ?>">📤 Export</a>
    <?php endif; ?>
    <?php if (is_admin()): ?>
    <a href="admin.php"       class="dv-link <?= $nav_active==='admin'      ?'dv-cur':'' ?>">⚙ Admin</a>
    <?php endif; ?>
  </nav>

  <!-- RIGHT: Alerts + Timer + User + Logout -->
  <div class="dv-right">
    <!-- Notification bell -->
    <?php if ($_nav_total_alerts > 0): ?>
    <div class="dv-bell" title="<?= intval($_nav_alerts) ?> open findings/overdue<?= $_nav_locked?" + {$_nav_locked} locked IP":'' ?>">
      🔔 <span class="dv-badge"><?= intval($_nav_total_alerts) ?></span>
    </div>
    <?php else: ?>
    <div class="dv-bell dv-bell-ok" title="No alerts">🔔</div>
    <?php endif; ?>

    <!-- Session timer -->
    <span id="session-timer-display" class="dv-timer-slot" title="Session remaining">⏱ 05:00</span>

    <!-- User chip -->
    <span class="dv-user">
      👤 <?= htmlspecialchars($_SESSION['username'] ?? '') ?>
      <span class="dv-role"><?= htmlspecialchars($_SESSION['role'] ?? '') ?></span>
    </span>

    <!-- Theme toggle -->
    <button class="dv-theme-btn" id="dv-theme-btn" title="Toggle dark/light">🌙</button>

    <!-- Logout -->
    <a href="logout.php" class="dv-logout">⏏</a>
  </div>
</div>

<style>
/* ── DevVault Universal Navbar ─────────────────────────────────────────────── */
.dv-navbar{
  position:sticky;top:0;z-index:200;
  height:52px;display:flex;align-items:center;gap:10px;
  padding:0 16px;
  background:rgba(7,11,20,.97);
  border-bottom:1px solid var(--border,#1e2d4a);
  backdrop-filter:blur(12px);
  -webkit-backdrop-filter:blur(12px);
}
[data-theme="light"] .dv-navbar{background:rgba(240,244,248,.97)}

.dv-logo{
  font-family:'Courier New',Consolas,monospace;
  font-size:13px;font-weight:900;letter-spacing:2px;
  color:var(--accent,#00d4ff);text-decoration:none;
  text-shadow:0 0 12px var(--accent,#00d4ff);
  white-space:nowrap;flex-shrink:0
}
.dv-logo:hover{opacity:.85}
.dv-sep{color:var(--border,#1e2d4a);font-size:20px;flex-shrink:0}

.dv-nav{display:flex;gap:2px;overflow-x:auto;flex:1;min-width:0}
.dv-nav::-webkit-scrollbar{display:none}
.dv-link{
  display:inline-flex;align-items:center;
  padding:5px 10px;border-radius:6px;
  font-size:12px;font-weight:600;white-space:nowrap;
  text-decoration:none;color:var(--muted,#5a7a9a);
  transition:all .15s;font-family:'Segoe UI',Tahoma,Arial,sans-serif
}
.dv-link:hover{background:var(--surface2,#111a2e);color:var(--text,#e8edf5)}
.dv-cur{
  background:rgba(0,212,255,.12);
  color:var(--accent,#00d4ff)!important;
  border-bottom:2px solid var(--accent,#00d4ff)
}

.dv-right{
  margin-left:auto;display:flex;align-items:center;
  gap:8px;flex-shrink:0
}

/* Bell */
.dv-bell{
  position:relative;font-size:14px;cursor:default;
  padding:4px 6px;border-radius:6px;
  background:rgba(255,61,90,.12);color:#ff8a80
}
.dv-bell-ok{background:transparent;color:var(--muted,#5a7a9a)}
.dv-badge{
  position:absolute;top:-4px;right:-4px;
  background:#ff3d5a;color:#fff;
  font-size:9px;font-weight:700;
  min-width:16px;height:16px;border-radius:8px;
  display:flex;align-items:center;justify-content:center;
  font-family:'Courier New',Consolas,monospace;padding:0 3px
}

/* Timer slot — styled by session_timer.js */
.dv-timer-slot{
  font-family:'Courier New',Consolas,monospace;
  font-size:12px;color:var(--muted,#5a7a9a)
}

/* User chip */
.dv-user{
  font-size:12px;color:var(--text,#e8edf5);
  font-family:'Segoe UI',Tahoma,Arial,sans-serif;
  white-space:nowrap
}
.dv-role{
  font-size:9px;font-weight:700;text-transform:uppercase;
  letter-spacing:1px;color:var(--accent,#00d4ff);
  background:rgba(0,212,255,.1);border:1px solid rgba(0,212,255,.2);
  border-radius:4px;padding:1px 5px;margin-left:4px
}

/* Theme button */
.dv-theme-btn{
  background:none;border:1px solid var(--border,#1e2d4a);
  border-radius:6px;padding:4px 7px;cursor:pointer;
  font-size:13px;color:var(--muted,#5a7a9a);
  transition:all .15s
}
.dv-theme-btn:hover{background:var(--surface2,#111a2e);color:var(--text,#e8edf5)}

/* Logout */
.dv-logout{
  font-size:16px;padding:4px 8px;border-radius:6px;
  text-decoration:none;color:var(--muted,#5a7a9a);
  border:1px solid var(--border,#1e2d4a);
  transition:all .15s
}
.dv-logout:hover{background:rgba(255,61,90,.1);color:#ff3d5a;border-color:rgba(255,61,90,.3)}

/* Theme toggle JS */
</style>
<script nonce="<?= csp_nonce() ?>">
function dvToggleTheme(){
  var html=document.documentElement;
  var cur=html.getAttribute('data-theme')||'dark';
  var next=cur==='dark'?'light':'dark';
  html.setAttribute('data-theme',next);
  var btn=document.getElementById('dv-theme-btn');
  if(btn) btn.textContent=next==='dark'?'🌙':'☀';
  var csrf=window.DEVVAULT_CSRF||'';
  if(!csrf) return;
  var fd=new FormData();
  fd.append('action','save_prefs');fd.append('csrf',csrf);
  fd.append('theme',next);
  fd.append('accent',getComputedStyle(document.documentElement).getPropertyValue('--acc').trim()||'#00d4ff');
  fd.append('font','Rajdhani');fd.append('fs','14');
  fetch('api.php',{method:'POST',body:fd}).catch(function(){});
}
document.addEventListener('DOMContentLoaded',function(){
  var btn=document.getElementById('dv-theme-btn');
  if(btn){
    var t=document.documentElement.getAttribute('data-theme')||'dark';
    btn.textContent=t==='dark'?'🌙':'☀';
    btn.addEventListener('click', dvToggleTheme);
  }
});
</script>
