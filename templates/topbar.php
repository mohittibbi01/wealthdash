<?php
/**
 * WealthDash — Top Bar
 * Hamburger menu, page title, portfolio selector, theme toggle, user menu
 */
if (!defined('WEALTHDASH')) die();

$portfolios = get_user_portfolios((int)$currentUser['id'], is_admin());
$selectedPortfolioId = $portfolios[0]['id'] ?? null;
?>

<div class="topbar-left">
  <!-- Hamburger (mobile only) -->
  <button class="topbar-hamburger" onclick="openSidebar()" aria-label="Open menu">
    <svg width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <line x1="3" y1="6" x2="21" y2="6"/>
      <line x1="3" y1="12" x2="21" y2="12"/>
      <line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
  </button>

  <!-- Desktop Sidebar Toggle -->
  <button class="topbar-sidebar-toggle" onclick="toggleSidebarCollapse()" aria-label="Toggle sidebar" title="Toggle sidebar">
    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <line x1="3" y1="6" x2="21" y2="6"/>
      <line x1="3" y1="12" x2="21" y2="12"/>
      <line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
  </button>

  <!-- Page title (breadcrumb area) -->
  <div class="topbar-title">
    <h1 class="page-heading"><?= e($pageTitle) ?></h1>
  </div>
</div>

<div class="topbar-right">

  <!-- Hidden select for JS compatibility (reports.js uses portfolioSelect) -->
  <?php if (!empty($portfolios)): $activePortfolio = $portfolios[0]; ?>
  <select id="portfolioSelect" style="display:none;">
    <option value="<?= e($activePortfolio['id']) ?>" selected><?= e($activePortfolio['name']) ?></option>
  </select>
  <?php endif; ?>

  <!-- Number Format Toggle -->
  <button class="topbar-btn" id="numFormatToggle" onclick="toggleNumFormat()" title="Toggle number format (Short/Full)">
    <span id="numFormatLabel" style="font-size:11px;font-weight:600;letter-spacing:0.3px;">1.3L</span>
  </button>

  <!-- Theme Toggle -->
  <button class="topbar-btn" id="themeToggle" onclick="toggleTheme()" aria-label="Toggle theme" title="Toggle Dark/Light mode">
    <svg class="icon-sun" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <circle cx="12" cy="12" r="5"/>
      <line x1="12" y1="1" x2="12" y2="3"/>
      <line x1="12" y1="21" x2="12" y2="23"/>
      <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/>
      <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
      <line x1="1" y1="12" x2="3" y2="12"/>
      <line x1="21" y1="12" x2="23" y2="12"/>
      <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/>
      <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
    </svg>
    <svg class="icon-moon" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
    </svg>
  </button>

  <!-- Notifications Bell — t57/t81 -->
  <div class="notif-wrap" id="notifWrap">
    <button class="topbar-btn notif-bell-btn" id="notifBellBtn" onclick="toggleNotifPanel()" aria-label="Notifications" title="Notifications">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
        <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
      </svg>
      <span class="notif-badge" id="notifBadge" style="display:none;">0</span>
    </button>

    <!-- Notification Dropdown Panel -->
    <div class="notif-panel" id="notifPanel" style="display:none;">
      <div class="notif-panel-hdr">
        <span class="notif-panel-title">🔔 Notifications</span>
        <div style="display:flex;gap:6px;">
          <button class="notif-action-btn" onclick="notifMarkAllRead()" title="Mark all read">✓ All Read</button>
          <button class="notif-action-btn notif-action-danger" onclick="notifClearAll()" title="Clear all">🗑</button>
        </div>
      </div>
      <div class="notif-list" id="notifList">
        <div class="notif-loading">⏳ Loading…</div>
      </div>
      <div class="notif-panel-footer">
        <span id="notifFooterText" style="font-size:11px;color:var(--text-muted);"></span>
      </div>
    </div>
  </div>

  <style>
  .notif-wrap { position:relative; }
  .notif-bell-btn { position:relative; }
  .notif-badge {
    position:absolute; top:2px; right:2px;
    min-width:16px; height:16px; border-radius:99px;
    background:#dc2626; color:#fff; font-size:9px; font-weight:800;
    display:flex; align-items:center; justify-content:center;
    padding:0 3px; pointer-events:none; line-height:1;
    border:1.5px solid var(--bg-topbar, #fff);
  }
  .notif-panel {
    position:absolute; top:calc(100% + 8px); right:0;
    width:340px; max-height:480px;
    background:var(--bg-card); border:1.5px solid var(--border-color);
    border-radius:12px; box-shadow:0 8px 28px rgba(0,0,0,.13);
    z-index:9999; display:flex; flex-direction:column; overflow:hidden;
  }
  .notif-panel-hdr {
    display:flex; align-items:center; justify-content:space-between;
    padding:11px 14px; border-bottom:1px solid var(--border-color);
    flex-shrink:0;
  }
  .notif-panel-title { font-size:13px; font-weight:800; color:var(--text-primary); }
  .notif-action-btn {
    font-size:10px; font-weight:700; padding:3px 8px; border-radius:5px;
    border:1px solid var(--border-color); background:var(--bg-secondary);
    color:var(--text-muted); cursor:pointer; font-family:inherit;
  }
  .notif-action-btn:hover { border-color:var(--accent); color:var(--accent); }
  .notif-action-danger:hover { border-color:#dc2626; color:#dc2626; }
  .notif-list { flex:1; overflow-y:auto; max-height:370px; }
  .notif-loading { padding:24px; text-align:center; color:var(--text-muted); font-size:12px; }
  .notif-empty { padding:32px 16px; text-align:center; color:var(--text-muted); font-size:12px; }
  .notif-item {
    display:flex; gap:10px; padding:11px 14px;
    border-bottom:1px solid var(--border-color); cursor:pointer;
    transition:background .1s; position:relative;
  }
  .notif-item:hover { background:var(--bg-secondary); }
  .notif-item.unread { background:rgba(37,99,235,.04); }
  .notif-item.unread::before {
    content:''; position:absolute; left:0; top:0; bottom:0; width:3px;
    background:var(--accent); border-radius:0 2px 2px 0;
  }
  .notif-ico { font-size:18px; flex-shrink:0; margin-top:1px; }
  .notif-body { flex:1; min-width:0; }
  .notif-title { font-size:12px; font-weight:700; color:var(--text-primary); line-height:1.4; }
  .notif-text  { font-size:11px; color:var(--text-muted); margin-top:2px; line-height:1.5; }
  .notif-time  { font-size:10px; color:var(--text-muted2,#9ca3af); margin-top:4px; }
  .notif-panel-footer {
    padding:8px 14px; border-top:1px solid var(--border-color);
    flex-shrink:0; text-align:center;
  }
  </style>

  <script>
  /* ── Notification Centre JS ── */
  (function(){
    const APP = window.APP_URL || '';
    let _open = false;

    function bellBtn() { return document.getElementById('notifBellBtn'); }
    function panel()   { return document.getElementById('notifPanel');   }
    function badge()   { return document.getElementById('notifBadge');   }
    function list()    { return document.getElementById('notifList');    }

    /* Load unread count on page load */
    async function loadCount() {
      try {
        const r = await fetch(`${APP}/api/router.php?action=notif_unread_count`, {headers:{'X-Requested-With':'XMLHttpRequest'}});
        const d = await r.json();
        const b = badge();
        if (d.count > 0) {
          b.textContent = d.count > 99 ? '99+' : d.count;
          b.style.display = 'flex';
        } else {
          b.style.display = 'none';
        }
      } catch(e) {}
    }

    /* Toggle panel */
    window.toggleNotifPanel = function() {
      _open = !_open;
      panel().style.display = _open ? 'flex' : 'none';
      if (_open) loadNotifs();
    };

    /* Close on outside click */
    document.addEventListener('click', function(e) {
      const wrap = document.getElementById('notifWrap');
      if (_open && wrap && !wrap.contains(e.target)) {
        _open = false;
        panel().style.display = 'none';
      }
    });

    /* Load notifications */
    async function loadNotifs() {
      list().innerHTML = '<div class="notif-loading">⏳ Loading…</div>';
      try {
        const r = await fetch(`${APP}/api/router.php?action=notif_list&limit=25`, {headers:{'X-Requested-With':'XMLHttpRequest'}});
        const d = await r.json();
        renderNotifs(d.items || [], d.unread || 0, d.table_exists);
        document.getElementById('notifFooterText').textContent = d.total > 0 ? `${d.total} total · ${d.unread} unread` : '';
      } catch(e) {
        list().innerHTML = '<div class="notif-empty">⚠️ Could not load. XAMPP chal raha hai?</div>';
      }
    }

    const TYPE_ICO = {
      nav_alert:'🔔', fd_maturity:'💰', sip_reminder:'🔄',
      drawdown:'📉', nfo_closing:'🆕', system:'⚙️', goal:'🎯', tax:'🧾'
    };

    function timeAgo(dt) {
      const diff = Math.floor((Date.now() - new Date(dt)) / 1000);
      if (diff < 60) return 'just now';
      if (diff < 3600) return Math.floor(diff/60) + 'm ago';
      if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
      if (diff < 604800) return Math.floor(diff/86400) + 'd ago';
      return new Date(dt).toLocaleDateString('en-IN',{day:'numeric',month:'short'});
    }

    function renderNotifs(items, unread, tableExists) {
      if (!tableExists) {
        list().innerHTML = `<div class="notif-empty">
          <div style="font-size:24px;margin-bottom:8px;">🗄️</div>
          <div style="font-weight:700;margin-bottom:4px;">DB Migration Needed</div>
          <div style="font-size:11px;color:var(--text-muted);">Run database/10_notifications.sql to enable notifications.</div>
        </div>`;
        return;
      }
      if (!items.length) {
        list().innerHTML = `<div class="notif-empty">
          <div style="font-size:28px;margin-bottom:8px;">✅</div>
          <div style="font-weight:700;">All clear!</div>
          <div style="margin-top:4px;font-size:11px;">Koi notification nahi hai.</div>
        </div>`;
        badge().style.display = 'none';
        return;
      }
      list().innerHTML = items.map(n => `
        <div class="notif-item ${n.is_read == 0 ? 'unread' : ''}" onclick="notifRead(${n.id}, '${n.link_url||''}')">
          <div class="notif-ico">${TYPE_ICO[n.type] || '🔔'}</div>
          <div class="notif-body">
            <div class="notif-title">${escH(n.title)}</div>
            <div class="notif-text">${escH(n.body)}</div>
            <div class="notif-time">${timeAgo(n.triggered_at)}</div>
          </div>
        </div>
      `).join('');
    }

    function escH(s){ const d=document.createElement('div'); d.appendChild(document.createTextNode(s||'')); return d.innerHTML; }

    window.notifRead = async function(id, url) {
      try { await fetch(`${APP}/api/router.php`, {method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, body:JSON.stringify({action:'notif_mark_read',id})}); } catch(e) {}
      loadCount();
      if (url && url !== 'null' && url !== '') window.location.href = url;
      else loadNotifs();
    };

    window.notifMarkAllRead = async function() {
      try { await fetch(`${APP}/api/router.php`, {method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, body:JSON.stringify({action:'notif_mark_all_read'})}); } catch(e) {}
      loadCount(); loadNotifs();
    };

    window.notifClearAll = async function() {
      if (!confirm('Sab notifications delete karo?')) return;
      try { await fetch(`${APP}/api/router.php`, {method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, body:JSON.stringify({action:'notif_clear_all'})}); } catch(e) {}
      loadCount(); loadNotifs();
    };

    /* Auto-refresh count every 5 minutes */
    loadCount();
    setInterval(loadCount, 300000);
  })();
  </script>

  <!-- User Menu Dropdown -->
  <div class="user-menu" id="userMenu">
    <button class="user-menu-trigger" onclick="toggleUserMenu()" aria-expanded="false" aria-label="User menu">
      <div class="user-avatar">
        <?= strtoupper(substr($currentUser['name'] ?? 'U', 0, 1)) ?>
      </div>
      <span class="user-name"><?= e(explode(' ', $currentUser['name'] ?? '')[0]) ?></span>
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <polyline points="6 9 12 15 18 9"/>
      </svg>
    </button>

    <div class="user-dropdown" id="userDropdown">
      <div class="user-dropdown-header">
        <div class="user-info">
          <div class="user-info-name"><?= e($currentUser['name']) ?></div>
          <div class="user-info-email"><?= e($currentUser['email']) ?></div>
          <?php if ($currentUser['role'] === 'admin'): ?>
          <span class="badge badge-blue">Admin</span>
          <?php endif; ?>
        </div>
      </div>
      <ul class="user-dropdown-list">
        <li><a href="#" class="dropdown-item">
          <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
            <circle cx="12" cy="7" r="4"/>
          </svg>
          Profile Settings
        </a></li>

        <?php if (is_admin()): ?>
        <li><a href="<?= APP_URL ?>/templates/pages/admin.php" class="dropdown-item">
          <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
          </svg>
          Admin Panel
        </a></li>
        <?php endif; ?>
        <li class="dropdown-divider"></li>
        <li><a href="<?= APP_URL ?>/auth/logout.php" class="dropdown-item dropdown-item-danger">
          <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
            <polyline points="16 17 21 12 16 7"/>
            <line x1="21" y1="12" x2="9" y2="12"/>
          </svg>
          Sign Out
        </a></li>
      </ul>
    </div>
  </div>

</div>