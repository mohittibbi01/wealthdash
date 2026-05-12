<?php
/**
 * WealthDash — EPFO Passbook Sync Page
 * t151
 */
defined('WEALTHDASH') or die();

$pageTitle     = 'EPFO Passbook';
$activePage    = 'epfo';
$activeSection = 'epf';
ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">🏛️ EPFO Passbook</h1>
    <p class="page-subtitle">Import and track your EPF contributions</p>
  </div>
  <button class="btn btn-primary" onclick="EPFO.showAddAccount()">+ Add UAN</button>
</div>

<!-- Summary Cards -->
<div class="stat-grid" id="epfoSummaryCards">
  <div class="stat-card skeleton-card"></div>
  <div class="stat-card skeleton-card"></div>
  <div class="stat-card skeleton-card"></div>
</div>

<!-- Accounts List -->
<div class="card mt-4">
  <div class="card-header">
    <h3 class="card-title">Linked Accounts</h3>
  </div>
  <div class="card-body" id="epfoAccountsWrap">
    <div class="skeleton-table"></div>
  </div>
</div>

<!-- Passbook Table -->
<div class="card mt-4" id="epfoPassbookCard" style="display:none;">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
    <h3 class="card-title" id="epfoPassbookTitle">Passbook</h3>
    <div style="display:flex;gap:8px;align-items:center;">
      <select id="epfoYearFilter" class="form-control" style="width:120px;" onchange="EPFO.loadPassbook()">
        <option value="">All Years</option>
      </select>
      <button class="btn btn-sm btn-secondary" onclick="EPFO.showImport()">📤 Import PDF</button>
    </div>
  </div>
  <div class="card-body">
    <div class="table-responsive">
      <table class="data-table" id="epfoPassbookTable">
        <thead>
          <tr>
            <th>Wage Month</th>
            <th>Description</th>
            <th class="text-right">Employee (₹)</th>
            <th class="text-right">Employer (₹)</th>
            <th class="text-right">EPS (₹)</th>
            <th class="text-right">Interest (₹)</th>
            <th class="text-right">Balance (₹)</th>
          </tr>
        </thead>
        <tbody id="epfoPassbookBody"></tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add Account Modal -->
<div id="epfoAddModal" class="modal-backdrop" style="display:none;" onclick="if(event.target===this)EPFO.closeModal()">
  <div class="modal-box" style="max-width:440px;">
    <div class="modal-header">
      <h3>Add EPFO Account</h3>
      <button class="modal-close" onclick="EPFO.closeModal()">×</button>
    </div>
    <div class="modal-body">
      <label class="form-label">UAN (12 digits) *</label>
      <input id="epfoUan" class="form-control" type="text" inputmode="numeric" maxlength="12" placeholder="100xxxxxxxxx">
      <label class="form-label mt-3">Member ID (optional)</label>
      <input id="epfoMemberId" class="form-control" type="text" placeholder="KR/BNG/12345/678/9">
      <label class="form-label mt-3">Establishment Name (optional)</label>
      <input id="epfoEstab" class="form-control" type="text" placeholder="ABC Pvt Ltd">
      <div id="epfoAddErr" class="alert alert-danger mt-3" style="display:none;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="EPFO.closeModal()">Cancel</button>
      <button class="btn btn-primary" onclick="EPFO.addAccount()">Add Account</button>
    </div>
  </div>
</div>

<!-- Import PDF Modal -->
<div id="epfoImportModal" class="modal-backdrop" style="display:none;" onclick="if(event.target===this)EPFO.closeImportModal()">
  <div class="modal-box" style="max-width:480px;">
    <div class="modal-header">
      <h3>Import EPFO Passbook PDF</h3>
      <button class="modal-close" onclick="EPFO.closeImportModal()">×</button>
    </div>
    <div class="modal-body">
      <p style="color:var(--text-muted);font-size:.9rem;margin-bottom:16px;">
        Download your passbook from <a href="https://passbook.epfindia.gov.in/MemberPassBook/login" target="_blank" rel="noopener">EPFO Unified Portal</a> and upload it here.
      </p>
      <div id="epfoDropZone" style="border:2px dashed var(--border);border-radius:12px;padding:32px;text-align:center;cursor:pointer;"
           onclick="document.getElementById('epfoPdfInput').click()"
           ondragover="event.preventDefault()" ondrop="EPFO.handleDrop(event)">
        <div style="font-size:2rem;">📄</div>
        <div style="margin-top:8px;font-weight:600;">Drop PDF here or click to browse</div>
        <div style="color:var(--text-muted);font-size:.85rem;margin-top:4px;">Max 10 MB · PDF only</div>
      </div>
      <input id="epfoPdfInput" type="file" accept=".pdf" style="display:none;" onchange="EPFO.handleFileSelect(this.files[0])">
      <div id="epfoFileName" style="margin-top:10px;font-size:.9rem;color:var(--accent);"></div>
      <div id="epfoImportErr" class="alert alert-danger mt-3" style="display:none;"></div>
      <div id="epfoImportResult" style="display:none;margin-top:12px;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="EPFO.closeImportModal()">Cancel</button>
      <button class="btn btn-primary" id="epfoImportBtn" onclick="EPFO.importPdf()" disabled>Import</button>
    </div>
  </div>
</div>

<script>
var EPFO = (function() {
  var currentAccountId = null;
  var selectedFile     = null;

  function api(action, data, method) {
    method = method || 'POST';
    var url = window.WD.appUrl + '/api/router.php';
    if (method === 'GET') {
      var params = new URLSearchParams(Object.assign({action: action}, data||{}));
      return fetch(url + '?' + params, {headers:{'X-Requested-With':'XMLHttpRequest'}}).then(r=>r.json());
    }
    return fetch(url, {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-Token':window.WD.csrf},
      body: JSON.stringify(Object.assign({action:action}, data||{}))
    }).then(r=>r.json());
  }

  function loadSummary() {
    api('epfo_summary').then(d => {
      if (!d.success) return;
      var s  = d.data.summary;
      var tb = d.data.total_balance;
      document.getElementById('epfoSummaryCards').innerHTML = [
        card('Total Balance', formatINR(tb), '🏦'),
        card('Total Employee Contrib.', formatINR(s.total_employee), '👤'),
        card('Total Employer Contrib.', formatINR(parseFloat(s.total_employer)+parseFloat(s.total_eps)), '🏢'),
      ].join('');
    });
  }

  function card(label, val, icon) {
    return '<div class="stat-card"><div class="stat-icon">'+icon+'</div>'
           +'<div class="stat-value wd-num">'+val+'</div><div class="stat-label">'+label+'</div></div>';
  }

  function loadAccounts() {
    api('epfo_accounts_list').then(d => {
      var wrap = document.getElementById('epfoAccountsWrap');
      if (!d.success || !d.data.accounts.length) {
        wrap.innerHTML = '<div class="empty-state"><p>No EPFO accounts linked. Add your UAN to get started.</p></div>';
        return;
      }
      wrap.innerHTML = '<table class="data-table"><thead><tr>'
        +'<th>UAN</th><th>Establishment</th><th class="text-right">Balance</th>'
        +'<th>Last Sync</th><th>Status</th><th></th></tr></thead><tbody>'
        + d.data.accounts.map(a =>
          '<tr>'
          +'<td><a href="#" onclick="EPFO.selectAccount('+a.id+',\''+esc(a.uan)+'\');return false;">'+esc(a.uan)+'</a></td>'
          +'<td>'+esc(a.establishment||'—')+'</td>'
          +'<td class="text-right wd-num">'+formatINR(a.balance)+'</td>'
          +'<td>'+(a.last_sync_at ? a.last_sync_at.substring(0,10) : '—')+'</td>'
          +'<td><span class="badge badge-'+statusBadge(a.sync_status)+'">'+a.sync_status+'</span></td>'
          +'<td><button class="btn btn-xs btn-danger" onclick="EPFO.deleteAccount('+a.id+')">🗑</button></td>'
          +'</tr>'
        ).join('')
        +'</tbody></table>';
    });
  }

  function statusBadge(s) {
    var map = {idle:'secondary',done:'success',failed:'danger',syncing:'warning',pending:'info'};
    return map[s] || 'secondary';
  }

  function selectAccount(id, uan) {
    currentAccountId = id;
    document.getElementById('epfoPassbookTitle').textContent = 'Passbook — UAN ' + uan;
    document.getElementById('epfoPassbookCard').style.display = 'block';
    loadPassbook();
  }

  function loadPassbook() {
    if (!currentAccountId) return;
    var year = document.getElementById('epfoYearFilter').value;
    api('epfo_passbook', {account_id: currentAccountId, year: year}, 'GET').then(d => {
      if (!d.success) return;
      var rows  = d.data.entries;
      var tbody = document.getElementById('epfoPassbookBody');
      if (!rows.length) { tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--text-muted);">No entries found.</td></tr>'; return; }

      // Populate year filter
      var years = [...new Set(rows.map(r => r.wage_month.substring(0,4)))].sort().reverse();
      var sel = document.getElementById('epfoYearFilter');
      var cur = sel.value;
      sel.innerHTML = '<option value="">All Years</option>' + years.map(y => '<option value="'+y+'"'+(y===cur?' selected':'')+'>'+y+'</option>').join('');

      tbody.innerHTML = rows.map(r =>
        '<tr>'
        +'<td>'+r.wage_month.substring(0,7)+'</td>'
        +'<td>'+esc(r.description)+'</td>'
        +'<td class="text-right wd-num">'+formatINR(r.epf_employee)+'</td>'
        +'<td class="text-right wd-num">'+formatINR(r.epf_employer)+'</td>'
        +'<td class="text-right wd-num">'+formatINR(r.eps_employer)+'</td>'
        +'<td class="text-right wd-num">'+formatINR(r.interest)+'</td>'
        +'<td class="text-right wd-num"><strong>'+formatINR(r.balance)+'</strong></td>'
        +'</tr>'
      ).join('');
    });
  }

  function showAddAccount() {
    document.getElementById('epfoAddModal').style.display = 'flex';
    document.getElementById('epfoAddErr').style.display = 'none';
  }

  function closeModal() {
    document.getElementById('epfoAddModal').style.display = 'none';
  }

  function addAccount() {
    var uan   = document.getElementById('epfoUan').value.trim().replace(/\D/g,'');
    var memId = document.getElementById('epfoMemberId').value.trim();
    var estab = document.getElementById('epfoEstab').value.trim();
    if (uan.length !== 12) { showErr('epfoAddErr','UAN must be exactly 12 digits.'); return; }
    api('epfo_account_add', {uan, member_id: memId, establishment: estab}).then(d => {
      if (!d.success) { showErr('epfoAddErr', d.message); return; }
      closeModal();
      WDToast.show('Account added!', 'success');
      loadAccounts();
      loadSummary();
    });
  }

  function deleteAccount(id) {
    if (!confirm('Delete this EPFO account and ALL passbook entries? This cannot be undone.')) return;
    api('epfo_account_delete', {account_id: id}).then(d => {
      if (!d.success) { WDToast.show(d.message, 'error'); return; }
      WDToast.show('Account deleted.', 'info');
      loadAccounts();
      loadSummary();
      if (currentAccountId === id) {
        currentAccountId = null;
        document.getElementById('epfoPassbookCard').style.display = 'none';
      }
    });
  }

  function showImport() {
    if (!currentAccountId) { WDToast.show('Select an account first.', 'warning'); return; }
    document.getElementById('epfoImportModal').style.display = 'flex';
    document.getElementById('epfoImportResult').style.display = 'none';
    document.getElementById('epfoFileName').textContent = '';
    document.getElementById('epfoImportBtn').disabled = true;
    selectedFile = null;
  }

  function closeImportModal() {
    document.getElementById('epfoImportModal').style.display = 'none';
  }

  function handleDrop(e) {
    e.preventDefault();
    var f = e.dataTransfer.files[0];
    if (f) setFile(f);
  }

  function handleFileSelect(f) {
    if (f) setFile(f);
  }

  function setFile(f) {
    selectedFile = f;
    document.getElementById('epfoFileName').textContent = '📎 ' + f.name;
    document.getElementById('epfoImportBtn').disabled = false;
  }

  function importPdf() {
    if (!selectedFile || !currentAccountId) return;
    var btn = document.getElementById('epfoImportBtn');
    btn.disabled = true; btn.textContent = 'Importing…';
    var fd = new FormData();
    fd.append('action', 'epfo_import_pdf');
    fd.append('account_id', currentAccountId);
    fd.append('passbook_pdf', selectedFile);
    fd.append('_csrf', window.WD.csrf);
    fetch(window.WD.appUrl + '/api/router.php', {method:'POST', body: fd})
    .then(r => r.json())
    .then(d => {
      btn.disabled = false; btn.textContent = 'Import';
      var res = document.getElementById('epfoImportResult');
      res.style.display = 'block';
      res.innerHTML = d.success
        ? '<div class="alert alert-success">✅ '+esc(d.message)+'</div>'
        : '<div class="alert alert-danger">❌ '+esc(d.message)+'</div>';
      if (d.success) {
        loadAccounts();
        loadSummary();
        loadPassbook();
      }
    })
    .catch(() => { btn.disabled=false; btn.textContent='Import'; showErr('epfoImportErr','Upload failed.'); });
  }

  function showErr(id, msg) {
    var el = document.getElementById(id);
    el.textContent = msg;
    el.style.display = 'block';
  }

  // Init
  document.addEventListener('DOMContentLoaded', function() {
    loadSummary();
    loadAccounts();
  });

  return { showAddAccount, closeModal, addAccount, deleteAccount,
           selectAccount, loadPassbook, showImport, closeImportModal,
           handleDrop, handleFileSelect, importPdf };
})();
</script>

<?php
$pageContent = ob_get_clean();
include APP_ROOT . '/templates/layout.php';
