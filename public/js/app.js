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

// Close dropdown when clicking outside
document.addEventListener('click', (e) => {
  const menu = document.getElementById('userMenu');
  if (menu && !menu.contains(e.target)) {
    document.getElementById('userDropdown')?.classList.remove('open');
  }
});

// ============================================================
// PORTFOLIO SWITCHER
// ============================================================
function switchPortfolio(portfolioId) {
  if (!portfolioId) return;
  showLoading();
  apiPost({ action: 'switch_portfolio', portfolio_id: portfolioId })
    .then(res => {
      if (res.success) {
        window.location.reload();
      } else {
        showToast(res.message || 'Failed to switch portfolio.', 'error');
        hideLoading();
      }
    })
    .catch(() => { window.location.reload(); });
}

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
// NEW PORTFOLIO MODAL
// ============================================================
function openNewPortfolioModal() {
  openModal('newPortfolioModal');
}

function submitNewPortfolio() {
  const form   = document.getElementById('newPortfolioForm');
  const errEl  = document.getElementById('portfolioFormError');
  const btn    = form.closest('.modal').querySelector('.btn-primary');
  const data   = Object.fromEntries(new FormData(form).entries());

  errEl.style.display = 'none';

  if (!data.name?.trim()) {
    errEl.textContent = 'Portfolio name is required.';
    errEl.style.display = 'block';
    return;
  }

  setBtnLoading(btn, true);
  apiPost({ action: 'create_portfolio', ...data })
    .then(res => {
      if (res.success) {
        showToast('Portfolio created!', 'success');
        closeModal('newPortfolioModal');
        setTimeout(() => window.location.reload(), 600);
      } else {
        errEl.textContent = res.message || 'Failed to create portfolio.';
        errEl.style.display = 'block';
      }
    })
    .catch(() => {
      errEl.textContent = 'Request failed. Please try again.';
      errEl.style.display = 'block';
    })
    .finally(() => setBtnLoading(btn, false));
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

function formatINR(amount, decimals = 2) {
  if (isNaN(amount)) return '₹0';
  const abs = Math.abs(amount);
  const sign = amount < 0 ? '-' : '';
  const formatted = abs.toFixed(decimals);
  const [whole, dec] = formatted.split('.');
  let result;
  if (whole.length <= 3) {
    result = whole;
  } else {
    const last3 = whole.slice(-3);
    const rest  = whole.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');
    result = rest + ',' + last3;
  }
  return sign + '₹' + result + (decimals > 0 ? '.' + dec : '');
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

// Modal helpers
function showModal(id) {
  const el = document.getElementById(id);
  if (el) { el.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
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