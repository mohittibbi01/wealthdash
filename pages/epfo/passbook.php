<?php
/**
 * WealthDash — t151: EPFO Passbook Sync Page
 * File: pages/epfo/passbook.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle    = 'EPFO Passbook';
$activePage   = 'epfo';
$activeSection= 'epfo';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div>
    <h1 class="page-title">🏛️ EPFO Passbook</h1>
    <p class="page-subtitle">Track your Provident Fund contributions and balance.</p>
  </div>
  <button class="btn btn-primary" onclick="EPFOApp.openAddAccount()">+ Link UAN</button>
</div>

<!-- Summary cards -->
<div id="epfo-summary-cards" class="dashboard-grid" style="margin-bottom:24px;"></div>

<!-- Accounts table -->
<div class="card">
  <div class="card-header">
    <span class="card-title">Linked EPFO Accounts</span>
  </div>
  <div class="card-body p-0">
    <div id="epfo-accounts-table">
      <div class="empty-state"><div class="empty-icon">🏛️</div><div>No EPFO accounts linked yet.</div></div>
    </div>
  </div>
</div>

<!-- Passbook entries -->
<div class="card" id="epfo-entries-card" style="margin-top:20px;display:none;">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
    <span class="card-title" id="epfo-entries-title">Passbook Entries</span>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <select id="epfo-fy-filter" class="form-control" style="width:120px;" onchange="EPFOApp.loadEntries()">
        <option value="">All FY</option>
        <?php
        $curYear = (int)date('Y');
        for ($y = $curYear; $y >= 2015; $y--) {
            $fy = $y . '-' . substr(($y+1), -2);
            echo "<option value=\"$fy\">FY $fy</option>";
        }
        ?>
      </select>
      <select id="epfo-type-filter" class="form-control" style="width:130px;" onchange="EPFOApp.loadEntries()">
        <option value="">All Types</option>
        <option value="employee">Employee</option>
        <option value="employer">Employer</option>
        <option value="pension">Pension</option>
        <option value="withdrawal">Withdrawal</option>
        <option value="interest">Interest</option>
      </select>
      <button class="btn btn-secondary btn-sm" onclick="EPFOApp.openImport()">📥 Import Passbook</button>
    </div>
  </div>
  <div class="card-body p-0">
    <div id="epfo-entries-table"></div>
  </div>
</div>

<!-- Add UAN Modal -->
<div id="epfo-add-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)EPFOApp.closeAddAccount()">
  <div class="modal" style="max-width:440px;">
    <div class="modal-header">
      <span class="modal-title">Link EPFO Account (UAN)</span>
      <button class="modal-close" onclick="EPFOApp.closeAddAccount()">×</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">UAN (Universal Account Number) <span class="text-danger">*</span></label>
        <input type="text" id="epfo-uan-input" class="form-control" maxlength="12" placeholder="12-digit UAN"
               oninput="this.value=this.value.replace(/\D/g,'')">
        <div class="form-hint">12-digit number from your salary slip or EPF portal.</div>
      </div>
      <div class="form-group">
        <label class="form-label">Member Name</label>
        <input type="text" id="epfo-name-input" class="form-control" placeholder="Your name as per EPFO">
      </div>
      <div class="form-group">
        <label class="form-label">Establishment Name</label>
        <input type="text" id="epfo-estab-input" class="form-control" placeholder="Company / Employer name">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="EPFOApp.closeAddAccount()">Cancel</button>
      <button class="btn btn-primary" onclick="EPFOApp.saveAccount()">Link Account</button>
    </div>
  </div>
</div>

<!-- Import Modal -->
<div id="epfo-import-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)EPFOApp.closeImport()">
  <div class="modal" style="max-width:500px;">
    <div class="modal-header">
      <span class="modal-title">📥 Import Passbook Entries</span>
      <button class="modal-close" onclick="EPFOApp.closeImport()">×</button>
    </div>
    <div class="modal-body">
      <div class="alert alert-info" style="font-size:13px;margin-bottom:16px;">
        Paste your EPFO passbook data in JSON format. Each entry: <code>{"txn_date":"2024-06-15","type":"employee","amount":2500,"wage_month":"2024-06","remarks":"June contribution"}</code>
      </div>
      <div class="form-group">
        <label class="form-label">Passbook JSON</label>
        <textarea id="epfo-import-json" class="form-control" rows="8"
                  placeholder='[{"txn_date":"2024-06-15","type":"employee","amount":2500,...}]'
                  style="font-family:monospace;font-size:12px;"></textarea>
      </div>
      <div id="epfo-import-msg" style="display:none;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="EPFOApp.closeImport()">Cancel</button>
      <button class="btn btn-primary" onclick="EPFOApp.doImport()">Import Entries</button>
    </div>
  </div>
</div>

<script>
const EPFOApp = {
  _accounts: [],
  _activeAccountId: null,

  init() {
    this.loadSummary();
    this.loadAccounts();
  },

  loadSummary() {
    apiPost({ action: 'epfo_summary' }).then(r => {
      if (!r.ok) return;
      const wrap = document.getElementById('epfo-summary-cards');
      if (!r.data.accounts?.length) { wrap.innerHTML = ''; return; }
      const total = r.data.grand_total || 0;
      wrap.innerHTML = `
        <div class="stat-card">
          <div class="stat-label">Total PF Balance</div>
          <div class="stat-value wd-num-xl">${formatINR(total)}</div>
        </div>
        <div class="stat-card">
          <div class="stat-label">Linked Accounts</div>
          <div class="stat-value wd-num-xl">${r.data.accounts.length}</div>
        </div>`;
    });
  },

  loadAccounts() {
    apiPost({ action: 'epfo_accounts_list' }).then(r => {
      if (!r.ok) return;
      this._accounts = r.data.accounts || [];
      this._renderAccounts();
    });
  },

  _renderAccounts() {
    const wrap = document.getElementById('epfo-accounts-table');
    if (!this._accounts.length) {
      wrap.innerHTML = '<div class="empty-state"><div class="empty-icon">🏛️</div><div>No accounts linked. Click "+ Link UAN" to add.</div></div>';
      return;
    }
    let html = `<div class="table-responsive"><table class="data-table">
      <thead><tr>
        <th>UAN</th><th>Member Name</th><th>Establishment</th>
        <th class="text-right">Employee</th><th class="text-right">Employer</th>
        <th class="text-right">Total Balance</th><th>Last Sync</th><th></th>
      </tr></thead><tbody>`;
    for (const a of this._accounts) {
      html += `<tr>
        <td><span class="wd-num">${esc(a.uan)}</span></td>
        <td>${esc(a.member_name || '—')}</td>
        <td>${esc(a.establishment_name || '—')}</td>
        <td class="text-right wd-num">${formatINR(a.balance_employee)}</td>
        <td class="text-right wd-num">${formatINR(a.balance_employer)}</td>
        <td class="text-right wd-num wd-gain" style="font-weight:700;">${formatINR(a.balance_total)}</td>
        <td style="font-size:12px;color:var(--text-muted);">${a.last_sync_at ? a.last_sync_at.substring(0,10) : 'Never'}</td>
        <td>
          <button class="btn btn-ghost btn-sm" onclick="EPFOApp.viewEntries(${a.id},'${esc(a.uan)}')">View</button>
          <button class="btn btn-danger btn-sm" onclick="EPFOApp.deleteAccount(${a.id})">✕</button>
        </td>
      </tr>`;
    }
    html += '</tbody></table></div>';
    wrap.innerHTML = html;
  },

  viewEntries(id, uan) {
    this._activeAccountId = id;
    document.getElementById('epfo-entries-title').textContent = 'Passbook — UAN ' + uan;
    document.getElementById('epfo-entries-card').style.display = '';
    this.loadEntries();
    document.getElementById('epfo-entries-card').scrollIntoView({ behavior: 'smooth' });
  },

  loadEntries() {
    if (!this._activeAccountId) return;
    const fy   = document.getElementById('epfo-fy-filter').value;
    const type = document.getElementById('epfo-type-filter').value;
    const wrap = document.getElementById('epfo-entries-table');
    wrap.innerHTML = '<div class="loading-row">Loading…</div>';
    apiPost({ action: 'epfo_passbook_entries', account_id: this._activeAccountId, fy, type }).then(r => {
      if (!r.ok) { wrap.innerHTML = '<div class="empty-state">Error loading entries.</div>'; return; }
      const entries = r.data.entries || [];
      if (!entries.length) {
        wrap.innerHTML = '<div class="empty-state"><div class="empty-icon">📋</div><div>No entries found for this filter.</div></div>';
        return;
      }
      let html = `<div class="table-responsive"><table class="data-table">
        <thead><tr>
          <th>Date</th><th>Wage Month</th><th>Type</th>
          <th class="text-right">Amount</th><th>Remarks</th>
        </tr></thead><tbody>`;
      const typeColors = { employee:'wd-gain', employer:'#2563eb', pension:'#7c3aed', withdrawal:'wd-loss', interest:'wd-gain' };
      for (const e of entries) {
        const tc = typeColors[e.type] || '';
        html += `<tr>
          <td class="wd-num">${esc(e.txn_date?.substring(0,10))}</td>
          <td>${esc(e.wage_month || '—')}</td>
          <td><span class="badge ${tc}" style="text-transform:capitalize;">${esc(e.type)}</span></td>
          <td class="text-right wd-num ${tc}">${formatINR(e.amount)}</td>
          <td style="font-size:12px;color:var(--text-muted);">${esc(e.remarks || '—')}</td>
        </tr>`;
      }
      html += `</tbody><tfoot><tr>
        <td colspan="3" style="font-weight:700;">Total</td>
        <td class="text-right wd-num" style="font-weight:700;">${formatINR(r.data.total)}</td>
        <td></td>
      </tr></tfoot></table></div>`;
      wrap.innerHTML = html;
    });
  },

  openAddAccount() { document.getElementById('epfo-add-modal').style.display = ''; },
  closeAddAccount() { document.getElementById('epfo-add-modal').style.display = 'none'; },

  saveAccount() {
    const uan   = document.getElementById('epfo-uan-input').value.trim();
    const name  = document.getElementById('epfo-name-input').value.trim();
    const estab = document.getElementById('epfo-estab-input').value.trim();
    if (!/^\d{12}$/.test(uan)) { showToast('Enter valid 12-digit UAN', 'warning'); return; }
    apiPost({ action: 'epfo_account_add', uan, member_name: name, establishment_name: estab }).then(r => {
      if (!r.ok) { showToast(r.message || 'Error', 'error'); return; }
      showToast('Account linked!', 'success');
      this.closeAddAccount();
      this.loadAccounts();
      this.loadSummary();
    });
  },

  deleteAccount(id) {
    if (!confirm('Remove this EPFO account and all its passbook entries?')) return;
    apiPost({ action: 'epfo_account_delete', account_id: id }).then(r => {
      if (!r.ok) { showToast(r.message, 'error'); return; }
      showToast('Account removed', 'info');
      if (this._activeAccountId === id) {
        this._activeAccountId = null;
        document.getElementById('epfo-entries-card').style.display = 'none';
      }
      this.loadAccounts();
      this.loadSummary();
    });
  },

  openImport() {
    if (!this._activeAccountId) { showToast('Select an account first', 'warning'); return; }
    document.getElementById('epfo-import-json').value = '';
    document.getElementById('epfo-import-msg').style.display = 'none';
    document.getElementById('epfo-import-modal').style.display = '';
  },
  closeImport() { document.getElementById('epfo-import-modal').style.display = 'none'; },

  doImport() {
    const raw = document.getElementById('epfo-import-json').value.trim();
    if (!raw) { showToast('Paste JSON data first', 'warning'); return; }
    let parsed;
    try { parsed = JSON.parse(raw); } catch(e) { showToast('Invalid JSON', 'error'); return; }
    if (!Array.isArray(parsed)) { showToast('JSON must be an array of entries', 'error'); return; }
    apiPost({
      action: 'epfo_passbook_import',
      account_id: this._activeAccountId,
      entries: JSON.stringify(parsed)
    }).then(r => {
      const msgEl = document.getElementById('epfo-import-msg');
      msgEl.style.display = '';
      msgEl.className = r.ok ? 'alert alert-success' : 'alert alert-danger';
      msgEl.textContent = r.message || (r.ok ? 'Done' : 'Error');
      if (r.ok) {
        this.loadAccounts();
        this.loadSummary();
        this.loadEntries();
        setTimeout(() => this.closeImport(), 1800);
      }
    });
  }
};

document.addEventListener('DOMContentLoaded', () => EPFOApp.init());
</script>
<?php
$pageContent = ob_get_clean();
include APP_ROOT . '/templates/layout.php';
