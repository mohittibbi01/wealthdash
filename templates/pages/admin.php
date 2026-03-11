<?php
/**
 * WealthDash — Admin Panel (Phase 5 — Complete)
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_admin();
$pageTitle   = 'Admin Panel';
$activePage  = 'admin';

$db = DB::conn();

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Admin Panel</h1>
    <p class="page-subtitle">Users · Settings · NAV · Audit Log</p>
  </div>
</div>

<!-- Tabs -->
<div class="admin-tabs mb-4">
  <button class="admin-tab active" data-tab="overview" onclick="switchTab('overview',this)">Overview</button>
  <button class="admin-tab" data-tab="users"    onclick="switchTab('users',this)">Users</button>
  <button class="admin-tab" data-tab="settings" onclick="switchTab('settings',this)">Settings</button>
  <button class="admin-tab" data-tab="nav"      onclick="switchTab('nav',this)">NAV &amp; Data</button>
  <button class="admin-tab" data-tab="audit"    onclick="switchTab('audit',this)">Audit Log</button>
</div>

<!-- ═══════ TAB: OVERVIEW ═══════ -->
<div id="tab-overview" class="admin-tab-content">
  <div class="cards-grid cards-grid-4 mb-4" id="statsCards">
    <div class="stat-card"><div class="stat-label">Active Users</div><div class="stat-value" id="statUsers">—</div></div>
    <div class="stat-card"><div class="stat-label">Portfolios</div><div class="stat-value" id="statPortfolios">—</div></div>
    <div class="stat-card"><div class="stat-label">MF Holdings</div><div class="stat-value" id="statMfHoldings">—</div></div>
    <div class="stat-card"><div class="stat-label">Funds in DB</div><div class="stat-value" id="statFunds">—</div></div>
    <div class="stat-card"><div class="stat-label">Stock Holdings</div><div class="stat-value" id="statStocks">—</div></div>
    <div class="stat-card"><div class="stat-label">Active FDs</div><div class="stat-value" id="statFDs">—</div></div>
    <div class="stat-card"><div class="stat-label">Savings Accounts</div><div class="stat-value" id="statSavings">—</div></div>
    <div class="stat-card"><div class="stat-label">NAV Last Updated</div><div class="stat-value text-sm" id="statNav">—</div></div>
  </div>
</div>

<!-- ═══════ TAB: USERS ═══════ -->
<div id="tab-users" class="admin-tab-content" style="display:none">
  <div class="flex-between mb-3">
    <div style="display:flex;gap:.75rem;align-items:center">
      <input type="text" class="form-control" id="userSearchInput" placeholder="Search name or email…" style="width:260px" oninput="filterUsers()">
    </div>
    <button class="btn btn-primary" onclick="openAddUser()">+ Add User</button>
  </div>
  <div class="card">
    <div class="table-wrap">
      <table class="data-table" id="usersTable">
        <thead>
          <tr>
            <th>#</th><th>Name</th><th>Email</th><th>Role</th><th>Status</th>
            <th>Senior</th><th>Portfolios</th><th>MF Holdings</th>
            <th>Last Login</th><th>Joined</th><th>Actions</th>
          </tr>
        </thead>
        <tbody id="usersBody">
          <tr><td colspan="11" class="text-center text-secondary">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- ═══════ TAB: SETTINGS ═══════ -->
<div id="tab-settings" class="admin-tab-content" style="display:none">
  <div class="cards-grid cards-grid-2">
    <!-- Tax Settings -->
    <div class="card">
      <div class="card-header"><h3 class="card-title">Tax Configuration</h3></div>
      <div class="card-body" id="taxSettingsForm">
        <div class="form-group">
          <label class="form-label">LTCG Exemption Limit (₹)</label>
          <input type="number" class="form-control setting-input" data-key="ltcg_exemption_limit" value="125000">
        </div>
        <div class="form-group">
          <label class="form-label">Equity LTCG Rate (%)</label>
          <input type="number" class="form-control setting-input" data-key="equity_ltcg_rate" value="12.5" step="0.5">
        </div>
        <div class="form-group">
          <label class="form-label">Equity STCG Rate (%)</label>
          <input type="number" class="form-control setting-input" data-key="equity_stcg_rate" value="20">
        </div>
        <div class="form-group">
          <label class="form-label">Debt LTCG Rate (%)</label>
          <input type="number" class="form-control setting-input" data-key="debt_ltcg_rate" value="20">
        </div>
        <div class="form-group">
          <label class="form-label">FD TDS Rate (%) — Non-Senior</label>
          <input type="number" class="form-control setting-input" data-key="fd_tds_rate" value="10">
        </div>
        <div class="form-group">
          <label class="form-label">FD TDS Threshold (₹) — Non-Senior</label>
          <input type="number" class="form-control setting-input" data-key="fd_tds_threshold" value="40000">
        </div>
        <div class="form-group">
          <label class="form-label">FD TDS Threshold (₹) — Senior Citizen</label>
          <input type="number" class="form-control setting-input" data-key="fd_tds_threshold_senior" value="50000">
        </div>
        <button class="btn btn-primary" onclick="saveSettings(['ltcg_exemption_limit','equity_ltcg_rate','equity_stcg_rate','debt_ltcg_rate','fd_tds_rate','fd_tds_threshold','fd_tds_threshold_senior'])">Save Tax Settings</button>
      </div>
    </div>

    <!-- App Settings -->
    <div class="card">
      <div class="card-header"><h3 class="card-title">App Configuration</h3></div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label">App Name</label>
          <input type="text" class="form-control setting-input" data-key="app_name" value="WealthDash">
        </div>
        <div class="form-group">
          <label class="form-label">Goal Default Return % (per year)</label>
          <input type="number" class="form-control setting-input" data-key="goal_default_return_pct" value="12" step="0.5">
        </div>
        <div class="form-group">
          <label class="form-label">SIP Reminder</label>
          <select class="form-select setting-input" data-key="sip_reminder_enabled">
            <option value="1">Enabled</option>
            <option value="0">Disabled</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">SIP Reminder Days Before</label>
          <input type="number" class="form-control setting-input" data-key="sip_reminder_days_before" value="2" min="1" max="7">
        </div>
        <button class="btn btn-primary" onclick="saveSettings(['app_name','goal_default_return_pct','sip_reminder_enabled','sip_reminder_days_before'])">Save App Settings</button>
      </div>
    </div>
  </div>

  <!-- Portfolio Members Management -->
  <div class="card mt-4">
    <div class="card-header">
      <h3 class="card-title">Portfolio Sharing</h3>
      <span class="text-secondary text-sm">Share portfolios across family members</span>
    </div>
    <div class="card-body">
      <div class="form-row" style="align-items:flex-end;flex-wrap:wrap;gap:1rem">
        <div class="form-group mb-0">
          <label class="form-label">Portfolio</label>
          <select class="form-select" id="sharePortfolioId" style="width:240px">
            <option value="">Select portfolio…</option>
          </select>
        </div>
        <div class="form-group mb-0">
          <label class="form-label">Member Email</label>
          <input type="email" class="form-control" id="shareMemberEmail" placeholder="member@email.com" style="width:220px">
        </div>
        <div class="form-group mb-0">
          <label class="form-check">
            <input type="checkbox" id="shareCanEdit"> Can Edit
          </label>
        </div>
        <div class="form-group mb-0">
          <button class="btn btn-primary" onclick="addPortfolioMember()">Add Member</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ═══════ TAB: NAV & DATA ═══════ -->
<div id="tab-nav" class="admin-tab-content" style="display:none">
  <div class="cards-grid cards-grid-2">
    <div class="card">
      <div class="card-header"><h3 class="card-title">AMFI NAV Update</h3></div>
      <div class="card-body">
        <p class="text-secondary mb-3">Update mutual fund NAV from AMFI India (daily ~10 PM).</p>
        <div style="display:flex;gap:.75rem;flex-wrap:wrap">
          <button class="btn btn-primary" id="btnUpdateNav" onclick="runNavUpdate('nav_only')">
            <span id="navBtnLabel">Update Today's NAV</span>
            <span id="navBtnSpinner" style="display:none">⏳</span>
          </button>
          <button class="btn btn-outline" id="btnImportAmfi" onclick="confirmImportAmfi()">
            Import Full Fund List
          </button>
        </div>
        <div id="navUpdateResult" style="display:none;margin-top:1rem;padding:.75rem 1rem;border-radius:var(--radius)"></div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Stock Prices Update</h3></div>
      <div class="card-body">
        <p class="text-secondary mb-3">Refresh stock prices via Yahoo Finance.</p>
        <button class="btn btn-primary" id="btnUpdateStocks" onclick="updateStockPrices()">
          Refresh Stock Prices
        </button>
        <div id="stockUpdateResult" style="display:none;margin-top:1rem;padding:.75rem 1rem;border-radius:var(--radius)"></div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Cron Schedule</h3></div>
      <div class="card-body">
        <p class="text-secondary text-sm mb-2">Set up these cron jobs on your server:</p>
        <div class="code-block">
          <code>
# Daily NAV (10:15 PM)<br>
15 22 * * * php /path/to/wealthdash/cron/update_nav_daily.php<br><br>
# Daily Stocks (6:30 PM, market close)<br>
30 18 * * 1-5 php /path/to/wealthdash/cron/update_stocks_daily.php<br><br>
# FD Maturity Alerts (9 AM daily)<br>
0 9 * * * php /path/to/wealthdash/cron/fd_maturity_alert.php
          </code>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3 class="card-title">Holdings Recalculation</h3></div>
      <div class="card-body">
        <p class="text-secondary mb-3">Recalculate all holdings from transactions. Use if data seems inconsistent.</p>
        <button class="btn btn-outline" onclick="recalcHoldings()">Recalculate All Holdings</button>
        <p class="text-xs text-secondary mt-2">This may take 1-2 minutes for large portfolios.</p>
      </div>
    </div>
  </div>
</div>

<!-- ═══════ TAB: AUDIT LOG ═══════ -->
<div id="tab-audit" class="admin-tab-content" style="display:none">
  <div class="flex-between mb-3">
    <input type="text" class="form-control" id="auditFilter" placeholder="Filter by action…" style="width:240px">
    <button class="btn btn-outline" onclick="loadAuditLog()">Refresh</button>
  </div>
  <div class="card">
    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr><th>#</th><th>User</th><th>Action</th><th>Entity</th><th>IP</th><th>Time</th></tr>
        </thead>
        <tbody id="auditBody">
          <tr><td colspan="6" class="text-center text-secondary">Click a tab to load…</td></tr>
        </tbody>
      </table>
    </div>
    <div class="card-footer" style="display:flex;justify-content:space-between;align-items:center;padding:.75rem 1rem">
      <span class="text-secondary text-sm" id="auditTotalText">—</span>
      <div style="display:flex;gap:.5rem">
        <button class="btn btn-ghost btn-xs" id="auditPrev" onclick="auditPage(-1)">← Prev</button>
        <span class="text-sm" id="auditPageNum">1</span>
        <button class="btn btn-ghost btn-xs" id="auditNext" onclick="auditPage(1)">Next →</button>
      </div>
    </div>
  </div>
</div>

<!-- Add User Modal -->
<div class="modal-overlay" id="addUserModal" style="display:none">
  <div class="modal" style="max-width:460px">
    <div class="modal-header">
      <h3 class="modal-title">Add User</h3>
      <button class="modal-close" onclick="closeAddUser()">×</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">Full Name <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="newUserName" placeholder="Rajesh Kumar">
      </div>
      <div class="form-group">
        <label class="form-label">Email <span class="text-danger">*</span></label>
        <input type="email" class="form-control" id="newUserEmail" placeholder="rajesh@example.com">
      </div>
      <div class="form-group">
        <label class="form-label">Mobile</label>
        <input type="text" class="form-control" id="newUserMobile" placeholder="9876543210">
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Password <span class="text-danger">*</span></label>
          <input type="password" class="form-control" id="newUserPassword" placeholder="Min 8 characters">
        </div>
        <div class="form-group">
          <label class="form-label">Role</label>
          <select class="form-select" id="newUserRole">
            <option value="member">Member</option>
            <option value="admin">Admin</option>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="form-check">
          <input type="checkbox" id="newUserSenior"> Senior Citizen (for FD TDS)
        </label>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeAddUser()">Cancel</button>
      <button class="btn btn-primary" onclick="addUser()">Create User</button>
    </div>
  </div>
</div>

<!-- Reset Password Modal -->
<div class="modal-overlay" id="resetPwModal" style="display:none">
  <div class="modal" style="max-width:380px">
    <div class="modal-header">
      <h3 class="modal-title">Reset Password</h3>
      <button class="modal-close" onclick="document.getElementById('resetPwModal').style.display='none'">×</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="resetPwUserId">
      <p class="text-secondary mb-3" id="resetPwUserName"></p>
      <div class="form-group">
        <label class="form-label">New Password</label>
        <input type="password" class="form-control" id="resetPwNew" placeholder="Min 8 characters">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="document.getElementById('resetPwModal').style.display='none'">Cancel</button>
      <button class="btn btn-primary" onclick="saveResetPw()">Reset Password</button>
    </div>
  </div>
</div>

<style>
.admin-tabs { display:flex; gap:.25rem; border-bottom:2px solid var(--border); }
.admin-tab  { background:none; border:none; padding:.6rem 1.1rem; cursor:pointer; color:var(--text-secondary); font-size:.9rem; border-bottom:2px solid transparent; margin-bottom:-2px; border-radius:var(--radius) var(--radius) 0 0; transition:color .2s; }
.admin-tab:hover { color:var(--text-primary); background:var(--hover-bg); }
.admin-tab.active { color:var(--primary); border-bottom-color:var(--primary); font-weight:600; }
.code-block { background:var(--input-bg); border:1px solid var(--border); border-radius:var(--radius); padding:1rem; font-family:monospace; font-size:.8rem; line-height:1.7; overflow-x:auto; }
</style>

<script>
let allUsers = [];
let auditOffset = 0;
const AUDIT_LIMIT = 50;

document.addEventListener('DOMContentLoaded', () => {
  loadStats();
});

function switchTab(name, btn) {
  document.querySelectorAll('.admin-tab-content').forEach(el => el.style.display='none');
  document.querySelectorAll('.admin-tab').forEach(el => el.classList.remove('active'));
  document.getElementById('tab-'+name).style.display='block';
  btn.classList.add('active');

  if (name==='users' && allUsers.length===0) loadUsers();
  if (name==='settings') { loadSettings(); loadPortfolioList(); }
  if (name==='audit') loadAuditLog();
}

async function loadStats() {
  try {
    const d = await API.post('/api/router.php', { action:'admin_stats' });
    const s = d.stats;
    document.getElementById('statUsers').textContent     = s.users;
    document.getElementById('statPortfolios').textContent= s.portfolios;
    document.getElementById('statMfHoldings').textContent= s.mf_holdings;
    document.getElementById('statFunds').textContent     = s.funds;
    document.getElementById('statStocks').textContent    = s.stock_holdings;
    document.getElementById('statFDs').textContent       = s.fd_accounts;
    document.getElementById('statSavings').textContent   = s.savings_accs;
    document.getElementById('statNav').textContent       = s.nav_last_updated ? formatDate(s.nav_last_updated) : 'Not updated';
  } catch(e) { console.error(e); }
}

async function loadUsers() {
  try {
    const d = await API.post('/api/router.php', { action:'admin_users' });
    allUsers = d.users || [];
    renderUsers(allUsers);
  } catch(e) { console.error(e); }
}

function renderUsers(users) {
  const tbody = document.getElementById('usersBody');
  if (!users.length) {
    tbody.innerHTML = '<tr><td colspan="11" class="text-center text-secondary">No users found.</td></tr>';
    return;
  }
  tbody.innerHTML = users.map(u => `
    <tr>
      <td>${u.id}</td>
      <td><strong>${esc(u.name)}</strong></td>
      <td class="text-secondary">${esc(u.email)}</td>
      <td><span class="badge ${u.role==='admin'?'badge-primary':'badge-secondary'}">${u.role}</span></td>
      <td><span class="badge ${u.status==='active'?'badge-success':'badge-danger'}">${u.status}</span></td>
      <td>${u.is_senior_citizen?'✓':'—'}</td>
      <td class="text-center">${u.portfolio_count}</td>
      <td class="text-center">${u.mf_holdings_count}</td>
      <td class="text-secondary text-sm">${u.last_login_at ? formatDate(u.last_login_at) : '—'}</td>
      <td class="text-secondary text-sm">${formatDate(u.created_at)}</td>
      <td>
        <div style="display:flex;gap:.3rem;flex-wrap:wrap">
          ${u.id != <?= $currentUser['id'] ?> ? `
            <button class="btn btn-ghost btn-xs" onclick="toggleUser(${u.id},'${u.status}')">
              ${u.status==='active'?'Disable':'Enable'}
            </button>
            <button class="btn btn-ghost btn-xs" onclick="changeRole(${u.id},'${u.role}','${esc(u.name)}')">
              ${u.role==='admin'?'→ Member':'→ Admin'}
            </button>
            <button class="btn btn-ghost btn-xs" onclick="openResetPw(${u.id},'${esc(u.name)}')">
              Reset PW
            </button>
          ` : '<span class="text-xs text-secondary">(you)</span>'}
        </div>
      </td>
    </tr>`).join('');
}

function filterUsers() {
  const q = document.getElementById('userSearchInput').value.toLowerCase();
  renderUsers(q ? allUsers.filter(u =>
    u.name.toLowerCase().includes(q) || u.email.toLowerCase().includes(q)
  ) : allUsers);
}

async function loadSettings() {
  try {
    const d = await API.post('/api/router.php', { action:'admin_settings_get' });
    const s = d.settings;
    document.querySelectorAll('.setting-input').forEach(el => {
      const key = el.dataset.key;
      if (s[key] !== undefined) el.value = s[key];
    });
  } catch(e) { console.error(e); }
}

async function saveSettings(keys) {
  const payload = { action:'admin_settings_save', csrf_token:window.CSRF_TOKEN };
  keys.forEach(key => {
    const el = document.querySelector(`.setting-input[data-key="${key}"]`);
    if (el) payload[key] = el.value;
  });
  try {
    await API.post('/api/router.php', payload);
    showToast('Settings saved!');
  } catch(e) { showToast(e.message,'error'); }
}

async function loadPortfolioList() {
  try {
    const d = await API.post('/api/router.php', { action:'admin_portfolios' });
    const sel = document.getElementById('sharePortfolioId');
    sel.innerHTML = '<option value="">Select portfolio…</option>' +
      (d.portfolios||[]).map(p =>
        `<option value="${p.id}">${esc(p.name)} (${esc(p.owner_name)})</option>`
      ).join('');
  } catch(e) {}
}

async function addPortfolioMember() {
  const pid   = document.getElementById('sharePortfolioId').value;
  const email = document.getElementById('shareMemberEmail').value.trim();
  const canEdit = document.getElementById('shareCanEdit').checked ? 1 : 0;
  if (!pid || !email) { showToast('Select portfolio and enter email','error'); return; }
  try {
    await API.post('/api/router.php', {
      action:'admin_add_portfolio_member', portfolio_id:pid, member_email:email,
      can_edit:canEdit, csrf_token:window.CSRF_TOKEN
    });
    showToast('Member added!');
    document.getElementById('shareMemberEmail').value = '';
  } catch(e) { showToast(e.message,'error'); }
}

// ── Users actions ──────────────────────────────────────────
function openAddUser() { document.getElementById('addUserModal').style.display='flex'; }
function closeAddUser() { document.getElementById('addUserModal').style.display='none'; }

async function addUser() {
  const payload = {
    action: 'admin_add_user',
    name: document.getElementById('newUserName').value,
    email: document.getElementById('newUserEmail').value,
    mobile: document.getElementById('newUserMobile').value,
    password: document.getElementById('newUserPassword').value,
    role: document.getElementById('newUserRole').value,
    is_senior_citizen: document.getElementById('newUserSenior').checked ? 1 : 0,
    csrf_token: window.CSRF_TOKEN,
  };
  try {
    await API.post('/api/router.php', payload);
    showToast('User created!');
    closeAddUser();
    allUsers = [];
    loadUsers();
  } catch(e) { showToast(e.message,'error'); }
}

async function toggleUser(userId, status) {
  const action = status==='active' ? 'Disable' : 'Enable';
  if (!confirm(`${action} this user?`)) return;
  try {
    await API.post('/api/router.php', { action:'admin_toggle_user', user_id:userId, csrf_token:window.CSRF_TOKEN });
    showToast('User updated.');
    allUsers = [];
    loadUsers();
    loadStats();
  } catch(e) { showToast(e.message,'error'); }
}

async function changeRole(userId, currentRole, name) {
  const newRole = currentRole==='admin' ? 'member' : 'admin';
  if (!confirm(`Change ${name}'s role to ${newRole}?`)) return;
  try {
    await API.post('/api/router.php', { action:'admin_change_role', user_id:userId, role:newRole, csrf_token:window.CSRF_TOKEN });
    showToast('Role updated.');
    allUsers = [];
    loadUsers();
  } catch(e) { showToast(e.message,'error'); }
}

function openResetPw(userId, name) {
  document.getElementById('resetPwUserId').value = userId;
  document.getElementById('resetPwUserName').textContent = 'Reset password for: ' + name;
  document.getElementById('resetPwNew').value = '';
  document.getElementById('resetPwModal').style.display = 'flex';
}
async function saveResetPw() {
  const uid = document.getElementById('resetPwUserId').value;
  const pw  = document.getElementById('resetPwNew').value;
  if (pw.length < 8) { showToast('Password must be at least 8 characters','error'); return; }
  try {
    await API.post('/api/router.php', { action:'admin_reset_password', user_id:uid, new_password:pw, csrf_token:window.CSRF_TOKEN });
    showToast('Password reset!');
    document.getElementById('resetPwModal').style.display = 'none';
  } catch(e) { showToast(e.message,'error'); }
}

// ── NAV ───────────────────────────────────────────────────
async function runNavUpdate(mode) {
  const btn    = document.getElementById('btnUpdateNav');
  const label  = document.getElementById('navBtnLabel');
  const spin   = document.getElementById('navBtnSpinner');
  const result = document.getElementById('navUpdateResult');
  btn.disabled=true; label.style.display='none'; spin.style.display='';
  result.style.display='none';
  try {
    const res = await fetch(`${window.APP_URL}/api/nav/update_amfi.php?mode=${mode}`, {
      headers:{'X-Requested-With':'XMLHttpRequest'}
    });
    const d = await res.json();
    result.style.display='block';
    result.style.background = d.success ? 'rgba(34,197,94,.1)' : 'rgba(239,68,68,.1)';
    result.style.color = d.success ? 'var(--success)' : 'var(--danger)';
    result.innerHTML = `<strong>${d.message||(d.success?'Done':'Failed')}</strong>` +
      (d.stats ? `<br>Updated: ${d.stats.updated}, New: ${d.stats.new_funds}, Time: ${d.elapsed_sec}s` : '');
    loadStats();
  } catch(err) {
    result.style.display='block';
    result.innerHTML = `<span style="color:var(--danger)">Error: ${err.message}</span>`;
  } finally { btn.disabled=false; label.style.display=''; spin.style.display='none'; }
}

function confirmImportAmfi() {
  if (!confirm('Import full AMFI fund list? This may take 2-5 minutes.')) return;
  runNavUpdate('full_import');
}

async function updateStockPrices() {
  const btn    = document.getElementById('btnUpdateStocks');
  const result = document.getElementById('stockUpdateResult');
  btn.disabled = true; result.style.display='none';
  try {
    const d = await API.post('/api/router.php', { action:'stocks_refresh_prices' });
    result.style.display='block';
    result.style.background='rgba(34,197,94,.1)';
    result.style.color='var(--success)';
    result.innerHTML = `<strong>${d.message||'Stock prices updated.'}</strong>`;
  } catch(e) {
    result.style.display='block';
    result.style.background='rgba(239,68,68,.1)';
    result.style.color='var(--danger)';
    result.innerHTML = `<strong>Error: ${e.message}</strong>`;
  } finally { btn.disabled=false; }
}

async function recalcHoldings() {
  if (!confirm('Recalculate all holdings? May take a moment.')) return;
  showToast('Holdings recalculation triggered (future endpoint).','info');
}

// ── Audit Log ─────────────────────────────────────────────
async function loadAuditLog() {
  const filter = document.getElementById('auditFilter')?.value || '';
  try {
    const d = await API.get(`/api/router.php?action=admin_audit_log&limit=${AUDIT_LIMIT}&offset=${auditOffset}&filter=${encodeURIComponent(filter)}`);
    document.getElementById('auditTotalText').textContent = `Showing ${auditOffset+1}–${Math.min(auditOffset+AUDIT_LIMIT, d.total)} of ${d.total} entries`;
    document.getElementById('auditPageNum').textContent = Math.floor(auditOffset/AUDIT_LIMIT)+1;
    document.getElementById('auditPrev').disabled = auditOffset===0;
    document.getElementById('auditNext').disabled = auditOffset+AUDIT_LIMIT >= d.total;

    const tbody = document.getElementById('auditBody');
    if (!d.logs?.length) {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center text-secondary">No audit records.</td></tr>';
      return;
    }
    tbody.innerHTML = d.logs.map(l => `
      <tr>
        <td class="text-secondary text-xs">${l.id}</td>
        <td>${esc(l.user_name||'System')}<br><small class="text-secondary">${esc(l.user_email||'')}</small></td>
        <td><span class="badge badge-secondary text-xs">${esc(l.action)}</span></td>
        <td class="text-secondary text-xs">${esc(l.entity_type||'—')} ${l.entity_id ? '#'+l.entity_id : ''}</td>
        <td class="text-secondary text-xs">${esc(l.ip_address||'—')}</td>
        <td class="text-secondary text-xs">${l.created_at}</td>
      </tr>`).join('');
  } catch(e) { console.error(e); }
}

function auditPage(dir) {
  auditOffset = Math.max(0, auditOffset + dir*AUDIT_LIMIT);
  loadAuditLog();
}

document.getElementById('auditFilter')?.addEventListener('input', () => { auditOffset=0; loadAuditLog(); });

function formatDate(d) {
  if (!d) return '—';
  return d.substring(0,10).split('-').reverse().join('-');
}
</script>

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
?>

