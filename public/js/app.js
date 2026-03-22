/**
 * WealthDash — Core JS
 * Theme, sidebar, API calls, portfolio switcher, modals, toasts
 */

'use strict';

// ============================================================
// THEME
// ============================================================
function initTheme() {
  const saved = localStorage.getItem('wd_theme');
  const html  = document.documentElement;
  if (saved) {
    html.setAttribute('data-theme', saved);
  }
}

function toggleTheme() {
  const html    = document.documentElement;
  const current = html.getAttribute('data-theme') || 'light';
  const next    = current === 'light' ? 'dark' : 'light';
  html.setAttribute('data-theme', next);
  localStorage.setItem('wd_theme', next);

  // Persist to server
  apiPost({ action: 'update_theme', theme: next }).catch(() => {});
}

// ============================================================
// SIDEBAR
// ============================================================
function openSidebar() {
  document.getElementById('sidebar')?.classList.add('open');
  document.getElementById('sidebarOverlay')?.classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeSidebar() {
  document.getElementById('sidebar')?.classList.remove('open');
  document.getElementById('sidebarOverlay')?.classList.remove('open');
  document.body.style.overflow = '';
}

function toggleNavGroup(btn) {
  const item = btn.closest('.has-children');
  const open = item.classList.toggle('open');
  btn.setAttribute('aria-expanded', open);
}

// ============================================================
// USER MENU DROPDOWN
// ============================================================
function toggleUserMenu() {
  const dropdown = document.getElementById('userDropdown');
  const trigger  = document.querySelector('.user-menu-trigger');
  const open = dropdown.classList.toggle('open');
  trigger?.setAttribute('aria-expanded', open);
}

// Close user dropdown when clicking outside
document.addEventListener('click', (e) => {
  const userMenu = document.getElementById('userMenu');
  if (userMenu && !userMenu.contains(e.target)) {
    document.getElementById('userDropdown')?.classList.remove('open');
  }
});

// Close sort menu when page is scrolled — menu uses fixed positioning
// so it would float away from the button on scroll
window.addEventListener('scroll', () => {
  const menu = document.getElementById('sortMenuDropdown');
  if (menu && menu.style.display === 'block') menu.style.display = 'none';
}, { passive: true, capture: true });

window.addEventListener('resize', () => {
  if (typeof _positionSortMenu === 'function') _positionSortMenu();
}, { passive: true });

// ============================================================
// MODAL HELPERS
// ============================================================
function openModal(id) {
  const el = document.getElementById(id);
  if (el) { el.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) { el.style.display = 'none'; document.body.style.overflow = ''; }
}

// Close modal on backdrop click
document.addEventListener('click', (e) => {
  if (e.target.classList.contains('modal-backdrop')) {
    e.target.style.display = 'none';
    document.body.style.overflow = '';
  }
});

// Close on ESC
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-backdrop[style*="flex"]').forEach(el => {
      el.style.display = 'none';
    });
    document.body.style.overflow = '';
  }
});

// ============================================================
// CONFIRM DELETE
// ============================================================
function confirmDelete(message, onConfirm) {
  const modal = document.getElementById('confirmDeleteModal');
  const msgEl = document.getElementById('confirmDeleteMessage');
  const btn   = document.getElementById('confirmDeleteBtn');

  if (msgEl) msgEl.textContent = message || 'Are you sure you want to delete this record?';

  // Remove old listener
  const newBtn = btn.cloneNode(true);
  btn.parentNode.replaceChild(newBtn, btn);
  newBtn.addEventListener('click', () => {
    closeModal('confirmDeleteModal');
    onConfirm();
  });

  openModal('confirmDeleteModal');
}

// ============================================================
// LOADING OVERLAY
// ============================================================
function showLoading() {
  const el = document.getElementById('loadingOverlay');
  if (el) el.style.display = 'flex';
}

function hideLoading() {
  const el = document.getElementById('loadingOverlay');
  if (el) el.style.display = 'none';
}

// ============================================================
// TOAST
// ============================================================
function showToast(message, type = 'info', duration = 3500) {
  const container = document.getElementById('toastContainer');
  if (!container) return;

  const toast = document.createElement('div');
  toast.className = `toast toast-${type}`;

  const icons = {
    success: '✓', error: '✕', info: 'ℹ', warning: '⚠'
  };
  toast.innerHTML = `<span>${icons[type] || ''}</span><span>${escapeHtml(message)}</span>`;

  container.appendChild(toast);
  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transition = 'opacity .3s';
    setTimeout(() => toast.remove(), 300);
  }, duration);
}

// ============================================================
// API HELPER
// ============================================================
const API_BASE = document.querySelector('meta[name="app-url"]')?.content || '';

async function apiPost(data, endpoint = '/api/router.php') {
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

  const response = await fetch(API_BASE + endpoint, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
      'X-CSRF-Token': csrfToken,
      'X-Requested-With': 'XMLHttpRequest',
    },
    body: new URLSearchParams(data).toString(),
  });

  if (!response.ok) throw new Error(`HTTP ${response.status}`);
  return response.json();
}

async function apiGet(params = {}, endpoint = '/api/router.php') {
  const query = new URLSearchParams(params).toString();
  const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

  const response = await fetch(`${API_BASE}${endpoint}?${query}`, {
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrfToken },
  });

  if (!response.ok) throw new Error(`HTTP ${response.status}`);
  return response.json();
}

// ============================================================
// UTILITY
// ============================================================
function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

function setBtnLoading(btn, loading) {
  if (!btn) return;
  const textEl    = btn.querySelector('.btn-text');
  const spinnerEl = btn.querySelector('.btn-spinner');
  btn.disabled    = loading;
  if (textEl)    textEl.style.opacity  = loading ? '0' : '1';
  if (spinnerEl) spinnerEl.style.display = loading ? 'inline-block' : 'none';
}

// ============================================================
// NUMBER FORMAT PREFERENCE  (short: 1.3L / full: ₹1,31,000)
// ============================================================
window.WD_NUM_SHORT = localStorage.getItem('wd_num_format') !== 'full';

function toggleNumFormat() {
  window.WD_NUM_SHORT = !window.WD_NUM_SHORT;
  localStorage.setItem('wd_num_format', window.WD_NUM_SHORT ? 'short' : 'full');
  _updateNumFormatBtn();
  // Re-render without API reload
  if (typeof renderHoldings === 'function') renderHoldings();
  if (typeof renderTxnTable     === 'function' && window._lastTxns) renderTxnTable(window._lastTxns);
  if (typeof renderRealized     === 'function') renderRealized();
  if (typeof renderDividends    === 'function') renderDividends();
  if (typeof updateSummaryCards === 'function' && window._lastSummary) updateSummaryCards(window._lastSummary);
}

function _updateNumFormatBtn() {
  const el = document.getElementById('numFormatLabel');
  if (el) el.textContent = window.WD_NUM_SHORT ? 'Short' : 'Long';
}

document.addEventListener('DOMContentLoaded', _updateNumFormatBtn);

// ── Indian number comma formatter ───────────────────────────
// Rules: last 3 digits, then every 2 digits going left
// e.g. 123456789 → 12,34,56,789
function indianComma(n) {
  const s = Math.floor(Math.abs(n)).toString();
  if (s.length <= 3) return s;
  const last3 = s.slice(-3);
  const rest   = s.slice(0, -3);
  return rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + last3;
}

// ── Core formatter used everywhere ──────────────────────────
function fmtINR(n) {
  if (n === null || n === undefined || isNaN(n)) return '—';
  n = Number(n);
  const abs  = Math.abs(n);
  const sign = n < 0 ? '-' : '';
  const dec  = (abs % 1).toFixed(2).slice(2);
  let s;
  const short = (typeof window.WD_NUM_SHORT !== 'undefined') ? window.WD_NUM_SHORT : true;
  if (short) {
    if (abs >= 1e7)       s = '₹' + (abs / 1e7).toFixed(2) + ' Cr';
    else if (abs >= 1e5)  s = '₹' + (abs / 1e5).toFixed(2) + ' L';
    else if (abs >= 1000) s = '₹' + (abs / 1000).toFixed(1) + ' K';
    else                  s = '₹' + indianComma(abs) + '.' + dec;
  } else {
    s = '₹' + indianComma(abs) + '.' + dec;
  }
  return sign + s;
}
window.fmtINR    = fmtINR;
window.indianComma = indianComma;

function formatINR(amount, decimals = 2) {
  return fmtINR(amount);
}

function formatPct(value, decimals = 2) {
  return (value >= 0 ? '+' : '') + parseFloat(value).toFixed(decimals) + '%';
}

function gainClass(value) {
  return parseFloat(value) >= 0 ? 'text-gain' : 'text-loss';
}

// ============================================================
// TABLE SORTING
// ============================================================
function initTableSort() {
  document.querySelectorAll('th[data-sort]').forEach(th => {
    th.style.cursor = 'pointer';
    th.addEventListener('click', () => {
      const table = th.closest('table');
      const col   = th.cellIndex;
      const tbody = table.querySelector('tbody');
      const rows  = Array.from(tbody.querySelectorAll('tr'));
      const asc   = th.dataset.dir !== 'asc';

      table.querySelectorAll('th[data-sort]').forEach(h => h.dataset.dir = '');
      th.dataset.dir = asc ? 'asc' : 'desc';

      rows.sort((a, b) => {
        const aVal = a.cells[col]?.dataset.val ?? a.cells[col]?.textContent.trim() ?? '';
        const bVal = b.cells[col]?.dataset.val ?? b.cells[col]?.textContent.trim() ?? '';
        const aNum = parseFloat(aVal.replace(/[₹,%]/g, ''));
        const bNum = parseFloat(bVal.replace(/[₹,%]/g, ''));
        if (!isNaN(aNum) && !isNaN(bNum)) {
          return asc ? aNum - bNum : bNum - aNum;
        }
        return asc ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
      });

      rows.forEach(r => tbody.appendChild(r));
    });
  });
}

// ============================================================
// INIT
// ============================================================
document.addEventListener('DOMContentLoaded', () => {
  initTheme();
  initTableSort();

  // Auto-dismiss alerts after 5s
  document.querySelectorAll('.alert-dismissible').forEach(el => {
    setTimeout(() => {
      el.style.opacity = '0';
      el.style.transition = 'opacity .4s';
      setTimeout(() => el.remove(), 400);
    }, 5000);
  });
});

// ============================================================
// API OBJECT (unified wrapper for mf.js + other modules)
// ============================================================
const APP_URL = document.querySelector('meta[name="app-url"]')?.content || '';
window.APP_URL = APP_URL;

const API = {
  async post(endpoint, data = {}) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const res = await fetch(APP_URL + endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrf,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify({ ...data, csrf_token: data.csrf_token || csrf })
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Request failed');
    return json;
  },
  async get(endpoint) {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const res = await fetch(APP_URL + endpoint, {
      headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'XMLHttpRequest' }
    });
    const json = await res.json();
    if (!json.success) throw new Error(json.message || 'Request failed');
    return json;
  }
};
window.API = API;

// Modal helpers
function showModal(id) {
  const el = document.getElementById(id);
  if (el) {
    document.body.appendChild(el); // always render above all stacking contexts
    el.style.zIndex  = '99999';
    el.style.display = 'flex';
    document.body.style.overflow = 'hidden';
  }
}
function hideModal(id) {
  const el = document.getElementById(id);
  if (el) { el.style.display = 'none'; document.body.style.overflow = ''; }
}

// Generate CSRF (fetch fresh token from meta tag)
function generate_csrf_js() {
  return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

// ============================================================
// GLOBAL TAB SWITCHER (used by report pages)
// ============================================================
function switchTab(btn, tabId) {
  const card = btn.closest('.card, .card-body') || btn.parentElement.closest('[class]');
  if (card) {
    card.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    card.querySelectorAll('.tab-panel').forEach(p => {
      p.style.display = 'none';
      p.classList.remove('active');
    });
  }
  btn.classList.add('active');
  const panel = document.getElementById(tabId);
  if (panel) {
    panel.style.display = 'block';
    panel.classList.add('active');
  }
}

// ============================================================
// SIDEBAR COLLAPSE (desktop) - toggled from topbar button
// ============================================================
function toggleSidebarCollapse() {
  const collapsed = document.body.classList.toggle('sidebar-collapsed');
  localStorage.setItem('wd_sidebar_collapsed', collapsed ? '1' : '0');
}

// Restore collapse state on load
(function initSidebarCollapse() {
  if (localStorage.getItem('wd_sidebar_collapsed') === '1') {
    document.body.classList.add('sidebar-collapsed');
  }
})();
// ============================================================
// CUSTOM FILTER DROPDOWNS
// Universal initializer for <select data-custom-dropdown>
// Keeps original <select> hidden, syncs value + fires 'change'
// ============================================================
function initCustomFilterDropdowns() {
  document.querySelectorAll('select[data-custom-dropdown]').forEach(sel => {
    // Skip if already initialized
    if (sel.dataset.customInit) return;
    sel.dataset.customInit = '1';
    sel.style.display = 'none';

    const wrapper = document.createElement('div');
    wrapper.className = 'cfd-wrapper';

    const trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'cfd-trigger';

    const label = document.createElement('span');
    label.className = 'cfd-label';
    label.textContent = sel.options[sel.selectedIndex]?.text || '';

    const chevron = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    chevron.setAttribute('class', 'cfd-chevron');
    chevron.setAttribute('width', '13');
    chevron.setAttribute('height', '13');
    chevron.setAttribute('viewBox', '0 0 24 24');
    chevron.setAttribute('fill', 'none');
    chevron.setAttribute('stroke', 'currentColor');
    chevron.setAttribute('stroke-width', '2');
    chevron.innerHTML = '<polyline points="6 9 12 15 18 9"/>';

    trigger.appendChild(label);
    trigger.appendChild(chevron);

    const menu = document.createElement('div');
    menu.className = 'cfd-menu';

    function buildMenu() {
      menu.innerHTML = '';
      Array.from(sel.options).forEach(opt => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cfd-item' + (opt.value === sel.value ? ' active' : '');
        btn.textContent = opt.text;
        btn.dataset.value = opt.value;
        btn.addEventListener('click', () => {
          sel.value = opt.value;
          sel.dispatchEvent(new Event('change', { bubbles: true }));
          label.textContent = opt.text;
          menu.querySelectorAll('.cfd-item').forEach(b => b.classList.toggle('active', b.dataset.value === opt.value));
          closeMenu();
        });
        menu.appendChild(btn);
      });
    }

    function openMenu() {
      buildMenu();
      menu.classList.add('open');
      wrapper.classList.add('open');
      trigger.setAttribute('aria-expanded', 'true');
    }
    function closeMenu() {
      menu.classList.remove('open');
      wrapper.classList.remove('open');
      trigger.setAttribute('aria-expanded', 'false');
    }

    trigger.addEventListener('click', (e) => {
      e.stopPropagation();
      menu.classList.contains('open') ? closeMenu() : openMenu();
    });

    document.addEventListener('click', (e) => {
      if (!wrapper.contains(e.target)) closeMenu();
    });

    wrapper.appendChild(trigger);
    wrapper.appendChild(menu);
    sel.parentNode.insertBefore(wrapper, sel);
    wrapper.appendChild(sel);
  });
}

// Auto-init on DOMContentLoaded + re-init support for dynamic tabs
document.addEventListener('DOMContentLoaded', initCustomFilterDropdowns);
// Also expose globally so tab switches can re-init
window.initCustomFilterDropdowns = initCustomFilterDropdowns;

// ============================================================
// CUSTOM CONFIRM DIALOG
// Usage: showConfirm({ title, message, okText, onConfirm })
// ============================================================
// CUSTOM CONFIRM DIALOG
// ============================================================
function showConfirm({ title = 'Confirm', message = 'Are you sure?', okText = 'Delete', onConfirm }) {
  document.getElementById('confirmTitle').textContent  = title;
  document.getElementById('confirmMessage').innerHTML  = message;
  document.getElementById('confirmOkText').textContent = okText;

  const modal = document.getElementById('modalConfirm');

  // Move to body's last child so it always renders above all drawers/panels
  document.body.appendChild(modal);
  modal.style.cssText = modal.style.cssText; // preserve existing inline styles
  modal.style.display = 'flex';
  modal.style.zIndex  = '99999';

  // Clone OK button to remove previous event listeners
  const oldOk = document.getElementById('confirmOkBtn');
  const newOk = oldOk.cloneNode(true);
  oldOk.parentNode.replaceChild(newOk, oldOk);

  const spinner = newOk.querySelector('#confirmOkSpinner');
  const okLabel = newOk.querySelector('#confirmOkText');

  newOk.addEventListener('click', async () => {
    newOk.disabled        = true;
    spinner.style.display = 'inline-block';
    okLabel.style.opacity = '0.6';
    try {
      await onConfirm();
    } catch (err) {
      showToast(err.message || 'Action failed', 'error');
    } finally {
      newOk.disabled        = false;
      spinner.style.display = 'none';
      okLabel.style.opacity = '1';
      closeConfirmModal();
    }
  });
}

function closeConfirmModal() {
  document.getElementById('modalConfirm').style.display = 'none';
}

// Close on backdrop click
document.addEventListener('click', (e) => {
  const modal = document.getElementById('modalConfirm');
  if (modal && e.target === modal) closeConfirmModal();
});
/* ═══════════════════════════════════════════════════════════════════════════
   t88 — CTRL+K GLOBAL SEARCH
═══════════════════════════════════════════════════════════════════════════ */
(function initGlobalSearch() {
  // Inject search modal HTML once
  function injectSearchModal() {
    if (document.getElementById('globalSearchModal')) return;
    const div = document.createElement('div');
    div.innerHTML = `
      <div id="globalSearchModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:flex-start;justify-content:center;padding-top:80px;">
        <div style="background:var(--bg-surface);border-radius:14px;width:560px;max-width:95vw;box-shadow:0 24px 64px rgba(0,0,0,.35);overflow:hidden;">
          <div style="display:flex;align-items:center;gap:10px;padding:14px 16px;border-bottom:1px solid var(--border);">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;opacity:.5;"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input id="gsInput" type="search" placeholder="Search funds, FDs, stocks, pages…" autocomplete="off"
              style="flex:1;border:none;outline:none;font-size:15px;background:transparent;color:var(--text-primary);">
            <kbd style="font-size:10px;padding:2px 6px;border-radius:4px;background:var(--bg-surface-2);color:var(--text-muted);border:1px solid var(--border);">ESC</kbd>
          </div>
          <div id="gsResults" style="max-height:380px;overflow-y:auto;padding:6px 0;"></div>
          <div style="padding:8px 16px;border-top:1px solid var(--border);display:flex;gap:16px;font-size:11px;color:var(--text-muted);">
            <span>↑↓ Navigate</span><span>↵ Open</span><span>ESC Close</span>
          </div>
        </div>
      </div>`;
    document.body.appendChild(div.firstElementChild);

    // Wire input
    document.getElementById('gsInput').addEventListener('input', function() {
      gsSearch(this.value.trim());
    });
    document.getElementById('globalSearchModal').addEventListener('click', function(e) {
      if (e.target === this) closeGlobalSearch();
    });
    document.addEventListener('keydown', function(e) {
      if ((e.ctrlKey || e.metaKey) && e.key === 'k') { e.preventDefault(); openGlobalSearch(); }
      if (e.key === 'Escape') closeGlobalSearch();
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') gsNavigate(e.key === 'ArrowDown' ? 1 : -1);
      if (e.key === 'Enter') gsActivate();
    });
  }

  let _gsIndex = -1;
  let _gsItems = [];

  const GS_PAGES = [
    { label:'MF Holdings', url:'/templates/pages/mf_holdings.php', icon:'💼', cat:'Pages' },
    { label:'MF Screener', url:'/templates/pages/mf_screener.php', icon:'🔍', cat:'Pages' },
    { label:'SIP / SWP',   url:'/templates/pages/report_sip.php',  icon:'🔄', cat:'Pages' },
    { label:'FD & Deposits',url:'/templates/pages/fd.php',          icon:'🏦', cat:'Pages' },
    { label:'Post Office',  url:'/templates/pages/post_office.php', icon:'📮', cat:'Pages' },
    { label:'Stocks',       url:'/templates/pages/stocks.php',      icon:'📈', cat:'Pages' },
    { label:'NPS',          url:'/templates/pages/nps.php',         icon:'🏛️', cat:'Pages' },
    { label:'Market Indexes',url:'/templates/pages/market_indexes.php',icon:'📊',cat:'Pages'},
    { label:'FY Gains Report',url:'/templates/pages/report_fy.php', icon:'🧾', cat:'Reports' },
    { label:'Tax Planning', url:'/templates/pages/report_tax.php',  icon:'💰', cat:'Reports' },
    { label:'Net Worth',    url:'/templates/pages/report_networth.php',icon:'💎',cat:'Reports'},
    { label:'Dashboard',    url:'/templates/pages/dashboard.php',   icon:'🏠', cat:'Pages' },
  ];

  function gsSearch(q) {
    const res    = document.getElementById('gsResults');
    _gsIndex = -1;
    if (!q) { res.innerHTML = gsQuickLinks(); return; }
    const ql = q.toLowerCase();
    const matched = GS_PAGES.filter(p => p.label.toLowerCase().includes(ql) || p.cat.toLowerCase().includes(ql));
    if (!matched.length) {
      res.innerHTML = `<div style="padding:24px;text-align:center;color:var(--text-muted);font-size:13px;">No results for "${q}"</div>`;
      return;
    }
    _gsItems = matched;
    res.innerHTML = `<div style="padding:4px 12px;font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;">Results</div>` +
      matched.map((p,i) => `
        <a href="${(window.APP_URL||'')}${p.url}" class="gs-item" data-idx="${i}"
          style="display:flex;align-items:center;gap:10px;padding:9px 16px;cursor:pointer;text-decoration:none;color:var(--text-primary);transition:background .1s;"
          onmouseover="this.style.background='var(--hover-bg)'" onmouseout="this.style.background=''">
          <span style="font-size:18px;flex-shrink:0;">${p.icon}</span>
          <div><div style="font-size:13px;font-weight:600;">${p.label}</div><div style="font-size:11px;color:var(--text-muted);">${p.cat}</div></div>
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="margin-left:auto;opacity:.3;"><path d="M9 18l6-6-6-6"/></svg>
        </a>`).join('');
  }

  function gsQuickLinks() {
    _gsItems = GS_PAGES.slice(0,6);
    return `<div style="padding:4px 12px;font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;">Quick Links</div>` +
      GS_PAGES.slice(0,6).map((p,i) => `
        <a href="${(window.APP_URL||'')}${p.url}" class="gs-item" data-idx="${i}"
          style="display:flex;align-items:center;gap:10px;padding:8px 16px;cursor:pointer;text-decoration:none;color:var(--text-primary);"
          onmouseover="this.style.background='var(--hover-bg)'" onmouseout="this.style.background=''">
          <span style="font-size:16px;">${p.icon}</span>
          <span style="font-size:13px;font-weight:600;">${p.label}</span>
        </a>`).join('');
  }

  function gsNavigate(dir) {
    const items = document.querySelectorAll('.gs-item');
    if (!items.length) return;
    _gsIndex = Math.max(0, Math.min(items.length-1, _gsIndex + dir));
    items.forEach((el,i) => { el.style.background = i===_gsIndex ? 'var(--hover-bg)' : ''; });
    items[_gsIndex]?.scrollIntoView({block:'nearest'});
  }

  function gsActivate() {
    const items = document.querySelectorAll('.gs-item');
    if (_gsIndex >= 0 && items[_gsIndex]) { items[_gsIndex].click(); closeGlobalSearch(); }
  }

  window.openGlobalSearch = function() {
    injectSearchModal();
    const modal = document.getElementById('globalSearchModal');
    modal.style.display = 'flex';
    const inp = document.getElementById('gsInput');
    inp.value = '';
    document.getElementById('gsResults').innerHTML = gsQuickLinks();
    setTimeout(() => inp.focus(), 50);
  };

  window.closeGlobalSearch = function() {
    const modal = document.getElementById('globalSearchModal');
    if (modal) modal.style.display = 'none';
  };

  // Inject Ctrl+K listener on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', injectSearchModal);
  } else {
    injectSearchModal();
  }
})();

/* ═══════════════════════════════════════════════════════════════════════════
   t85 — NEW VS OLD TAX REGIME CALCULATOR (standalone widget)
═══════════════════════════════════════════════════════════════════════════ */
function renderTaxRegimeCalc(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;

  container.innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:16px;">
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Gross Annual Salary (₹)</div>
        <input type="number" id="trcSalary" placeholder="1200000" oninput="calcTaxRegime()" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">HRA Exemption (₹)</div>
        <input type="number" id="trcHra" placeholder="0" oninput="calcTaxRegime()" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">80C Investments (₹)</div>
        <input type="number" id="trc80c" placeholder="150000" oninput="calcTaxRegime()" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;">
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:4px;">Other Deductions (₹)</div>
        <input type="number" id="trcOther" placeholder="0" oninput="calcTaxRegime()" style="width:100%;padding:8px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);box-sizing:border-box;">
      </div>
    </div>
    <div id="trcResult" style="display:none;"></div>`;
}

window.calcTaxRegime = function() {
  const salary = parseFloat(document.getElementById('trcSalary')?.value)  || 0;
  const hra    = parseFloat(document.getElementById('trcHra')?.value)     || 0;
  const c80    = Math.min(150000, parseFloat(document.getElementById('trc80c')?.value) || 0);
  const other  = parseFloat(document.getElementById('trcOther')?.value)   || 0;
  const res    = document.getElementById('trcResult');
  if (!res || !salary) { if(res) res.style.display='none'; return; }

  function slabTax(inc, regime) {
    if (regime === 'new') {
      if (inc <= 300000)  return 0;
      if (inc <= 600000)  return (inc-300000)*0.05;
      if (inc <= 900000)  return 15000+(inc-600000)*0.10;
      if (inc <= 1200000) return 45000+(inc-900000)*0.15;
      if (inc <= 1500000) return 90000+(inc-1200000)*0.20;
      return 150000+(inc-1500000)*0.30;
    }
    if (inc <= 250000)  return 0;
    if (inc <= 500000)  return (inc-250000)*0.05;
    if (inc <= 1000000) return 12500+(inc-500000)*0.20;
    return 112500+(inc-1000000)*0.30;
  }

  // New regime: flat 75k standard deduction, no other deductions
  const newTaxable = Math.max(0, salary - 75000);
  const newTax     = slabTax(newTaxable, 'new') * 1.04; // +4% cess

  // Old regime: standard deduction 50k + HRA + 80C + other
  const oldTaxable = Math.max(0, salary - 50000 - hra - c80 - other);
  const oldTax     = slabTax(oldTaxable, 'old') * 1.04;

  const better    = newTax <= oldTax ? 'new' : 'old';
  const savings   = Math.abs(newTax - oldTax);

  function fmtI(v) {
    v = Math.abs(v);
    if (v >= 1e5) return '₹' + (v/1e5).toFixed(1) + 'L';
    return '₹' + v.toLocaleString('en-IN', {maximumFractionDigits:0});
  }

  res.style.display = '';
  res.innerHTML = `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
      <div style="background:${better==='new'?'rgba(22,163,74,.08)':'var(--bg-secondary)'};border-radius:10px;padding:14px;border:${better==='new'?'2px solid #86efac':'1.5px solid var(--border)'};">
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">New Regime</div>
        <div style="font-size:11px;color:var(--text-muted);">Taxable: ${fmtI(newTaxable)} (std. ded. ₹75K)</div>
        <div style="font-size:20px;font-weight:800;margin-top:4px;">Tax: ${fmtI(newTax)}</div>
        ${better==='new'?'<div style="font-size:11px;color:#16a34a;font-weight:700;margin-top:4px;">✅ Recommended</div>':''}
      </div>
      <div style="background:${better==='old'?'rgba(22,163,74,.08)':'var(--bg-secondary)'};border-radius:10px;padding:14px;border:${better==='old'?'2px solid #86efac':'1.5px solid var(--border)'};">
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">Old Regime</div>
        <div style="font-size:11px;color:var(--text-muted);">Taxable: ${fmtI(oldTaxable)} (after all ded.)</div>
        <div style="font-size:20px;font-weight:800;margin-top:4px;">Tax: ${fmtI(oldTax)}</div>
        ${better==='old'?'<div style="font-size:11px;color:#16a34a;font-weight:700;margin-top:4px;">✅ Recommended</div>':''}
      </div>
    </div>
    <div style="background:${better==='new'?'rgba(59,130,246,.07)':'rgba(22,163,74,.07)'};border-radius:8px;padding:10px;font-size:12px;color:var(--text-muted);">
      💰 <strong>${better==='new'?'New':'Old'} Regime saves ${fmtI(savings)}/year</strong> for your income profile.
      ${better==='old'?'Your deductions (HRA + 80C) are high enough to benefit from old regime.':'Your deductions are not high enough — new regime is better.'}
    </div>`;
};
