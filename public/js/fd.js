/**
 * WealthDash — Fixed Deposits JS (fd.js)
 * Handles: FD list, Add/Edit/Delete, Maturity calc, TDS preview
 */

const FD = {
  statusFilter: 'active',
  portfolioFilter: '',
  allData: [],
  pendingDeleteId: null,

  init() {
    // Status tabs
    document.querySelectorAll('.view-btn[data-status]').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.view-btn[data-status]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        FD.statusFilter = btn.dataset.status;
        FD.renderTable();
      });
    });

    // Add modal
    document.getElementById('btnAddFd').addEventListener('click', () => FD.openAddModal());
    document.getElementById('closeFdModal').addEventListener('click', () => FD.closeAddModal());
    document.getElementById('cancelFd').addEventListener('click', () => FD.closeAddModal());
    document.getElementById('saveFd').addEventListener('click', () => FD.saveFd());

    // Delete modal
    document.getElementById('closeDelFd').addEventListener('click', () => FD.closeDelModal());
    document.getElementById('cancelDelFd').addEventListener('click', () => FD.closeDelModal());
    document.getElementById('confirmDelFd').addEventListener('click', () => FD.deleteFd());

    this.loadFds();
  },

  async loadFds() {
    const body = document.getElementById('fdBody');
    body.innerHTML = '<tr><td colspan="12" class="text-center" style="padding:40px"><span class="spinner"></span></td></tr>';

    const params = new URLSearchParams({ action: 'fd_list' });
    if (FD.portfolioFilter) params.set('portfolio_id', FD.portfolioFilter);

    try {
      const res  = await fetch(APP_URL + '/api/router.php?' + params);
      const data = await res.json();
      if (!data.success) throw new Error(data.message);
      FD.allData = data.data || [];
      FD.renderTable();
    } catch(e) {
      body.innerHTML = `<tr><td colspan="12" class="text-center text-danger" style="padding:40px">Error: ${e.message}</td></tr>`;
    }
  },

  filterTable() { FD.renderTable(); },

  renderTable() {
    const body       = document.getElementById('fdBody');
    const searchVal  = (document.getElementById('searchBank')?.value || '').toLowerCase();
    const today      = new Date().toISOString().split('T')[0];

    let rows = FD.allData;
    if (FD.statusFilter) rows = rows.filter(r => r.status === FD.statusFilter);
    if (searchVal) rows = rows.filter(r => r.bank_name.toLowerCase().includes(searchVal));

    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="12" class="text-center empty-state" style="padding:60px"><div style="font-size:40px">🏦</div><p>No Fixed Deposits found.<br>Add your first FD to track it here.</p></td></tr>';
      return;
    }

    body.innerHTML = rows.map(fd => {
      const interest     = parseFloat(fd.maturity_amount) - parseFloat(fd.principal);
      const daysLeft     = Math.ceil((new Date(fd.maturity_date) - new Date(today)) / 86400000);
      const isMatured    = daysLeft < 0;
      const statusBadge  = isMatured ? '<span class="badge badge-outline">Matured</span>' : daysLeft <= 30 ? `<span class="badge badge-danger">${daysLeft}d left</span>` : daysLeft <= 90 ? `<span class="badge badge-warning">${daysLeft}d left</span>` : `<span class="badge badge-success">Active</span>`;
      const accrued      = FD.calcAccruedThisFy(parseFloat(fd.principal), parseFloat(fd.interest_rate), fd.start_date, fd.maturity_date);
      return `<tr>
        <td>
          <div class="fund-name">${escHtml(fd.bank_name)}</div>
          ${fd.is_senior_citizen == 1 ? '<div class="fund-sub" style="color:var(--info)">👴 Senior Citizen</div>' : ''}
          ${fd.tds_applicable == 0 ? '<div class="fund-sub">Form 15G/H</div>' : ''}
        </td>
        <td>${fd.account_number ? '****' + fd.account_number.slice(-4) : '—'}</td>
        <td>${escHtml(fd.portfolio_name || '—')}</td>
        <td class="text-right">${fmtInr(fd.principal)}</td>
        <td class="text-right">${parseFloat(fd.interest_rate).toFixed(2)}%</td>
        <td>${fmtDate(fd.start_date)}</td>
        <td>${fmtDate(fd.maturity_date)}</td>
        <td class="text-right text-success">${fmtInr(fd.maturity_amount)}</td>
        <td class="text-right text-success">${fmtInr(interest)}</td>
        <td>${statusBadge}</td>
        <td class="text-right">${fmtInr(accrued)}<br><small style="color:var(--text-muted)">FY Accrual</small></td>
        <td class="text-center">
          ${!isMatured ? `<button class="btn btn-sm btn-outline" onclick="FD.markMatured(${fd.id})" title="Mark as matured">✓ Mature</button> ` : ''}
          <button class="btn btn-sm btn-danger-ghost" onclick="FD.confirmDelete(${fd.id})" title="Delete">🗑️</button>
        </td>
      </tr>`;
    }).join('');
  },

  // Calculate accrued interest for current FY (Apr to today or maturity)
  calcAccruedThisFy(principal, rate, startDate, maturityDate) {
    const today     = new Date();
    const todayStr  = today.toISOString().split('T')[0];
    const fyStart   = today.getMonth() >= 3 ? `${today.getFullYear()}-04-01` : `${today.getFullYear()-1}-04-01`;
    const from      = startDate > fyStart ? startDate : fyStart;
    const to        = maturityDate < todayStr ? maturityDate : todayStr;
    if (from >= to) return 0;
    const days      = Math.max(0, Math.ceil((new Date(to) - new Date(from)) / 86400000));
    return Math.round(principal * (rate / 100) * (days / 365) * 100) / 100;
  },

  calcMaturity() {
    const principal = parseFloat(document.getElementById('fdPrincipal').value) || 0;
    const rate      = parseFloat(document.getElementById('fdRate').value)      || 0;
    const n         = parseInt(document.getElementById('fdCompounding').value) || 4;
    const start     = document.getElementById('fdStartDate').value;
    const maturity  = document.getElementById('fdMaturityDate').value;

    if (!principal || !rate || !start || !maturity) { document.getElementById('fdPreview').style.display='none'; return; }

    const days  = Math.max(0, Math.ceil((new Date(maturity) - new Date(start)) / 86400000));
    const years = days / 365;
    const mat   = principal * Math.pow(1 + (rate / 100 / n), n * years);
    const intr  = mat - principal;
    const yld   = (intr / principal) * 100;

    document.getElementById('prevTenure').textContent   = `${Math.floor(years)}Y ${Math.round((years % 1)*12)}M (${days}d)`;
    document.getElementById('prevMaturity').textContent  = fmtInr(mat);
    document.getElementById('prevInterest').textContent  = fmtInr(intr);
    document.getElementById('prevYield').textContent     = yld.toFixed(2) + '%';
    document.getElementById('fdPreview').style.display  = 'block';
  },

  openAddModal(fd = null) {
    document.getElementById('fdError').style.display = 'none';
    ['fdBankName','fdAccountNumber','fdPrincipal','fdRate','fdNotes'].forEach(id => document.getElementById(id).value = fd?.[id.replace('fd','').toLowerCase()] || '');
    document.getElementById('fdStartDate').value    = fd?.start_date    || new Date().toISOString().split('T')[0];
    document.getElementById('fdMaturityDate').value = fd?.maturity_date || '';
    document.getElementById('fdSenior').value       = fd?.is_senior_citizen || '0';
    document.getElementById('fdTds').value          = fd?.tds_applicable !== undefined ? String(fd.tds_applicable) : '1';
    document.getElementById('fdCompounding').value  = '4';
    document.getElementById('fdPreview').style.display = 'none';
    document.getElementById('fdModalTitle').textContent = fd ? 'Edit Fixed Deposit' : 'Add Fixed Deposit';
    document.getElementById('modalAddFd').style.display = 'flex';
  },
  closeAddModal() { document.getElementById('modalAddFd').style.display='none'; },

  async saveFd() {
    const errEl = document.getElementById('fdError');
    errEl.style.display = 'none';
    const btn = document.getElementById('saveFd');
    btn.disabled=true; btn.textContent='Saving...';

    const body = new URLSearchParams({
      action:              'fd_add',
      portfolio_id:        document.getElementById('fdPortfolio').value,
      bank_name:           document.getElementById('fdBankName').value,
      account_number:      document.getElementById('fdAccountNumber').value,
      principal:           document.getElementById('fdPrincipal').value,
      interest_rate:       document.getElementById('fdRate').value,
      interest_frequency:  'cumulative',
      compounding_freq:    document.getElementById('fdCompounding').value,
      start_date:          document.getElementById('fdStartDate').value,
      maturity_date:       document.getElementById('fdMaturityDate').value,
      is_senior_citizen:   document.getElementById('fdSenior').value,
      tds_applicable:      document.getElementById('fdTds').value,
      notes:               document.getElementById('fdNotes').value,
    });

    try {
      const res  = await fetch(APP_URL+'/api/router.php',{ method:'POST', body });
      const data = await res.json();
      if (!data.success) throw new Error(data.message);
      this.closeAddModal();
      showToast(`FD added! Maturity: ${fmtInr(data.data?.maturity_amount||0)}`, 'success');
      this.loadFds();
    } catch(e) {
      errEl.textContent=e.message; errEl.style.display='block';
    } finally { btn.disabled=false; btn.textContent='Save FD'; }
  },

  async markMatured(id) {
    if (!confirm('Mark this FD as matured?')) return;
    try {
      const res  = await fetch(APP_URL+'/api/router.php',{ method:'POST', body: new URLSearchParams({ action:'fd_mature', id }) });
      const data = await res.json();
      if (!data.success) throw new Error(data.message);
      showToast('FD marked as matured.','success');
      this.loadFds();
    } catch(e) { showToast('Error: '+e.message,'error'); }
  },

  confirmDelete(id) { FD.pendingDeleteId=id; document.getElementById('modalDelFd').style.display='flex'; },
  closeDelModal()   { document.getElementById('modalDelFd').style.display='none'; FD.pendingDeleteId=null; },
  async deleteFd() {
    if (!FD.pendingDeleteId) return;
    const btn=document.getElementById('confirmDelFd');
    btn.disabled=true; btn.textContent='Deleting...';
    try {
      const res  = await fetch(APP_URL+'/api/router.php',{ method:'POST', body: new URLSearchParams({ action:'fd_delete', id: FD.pendingDeleteId })});
      const data = await res.json();
      if (!data.success) throw new Error(data.message);
      this.closeDelModal(); showToast('FD deleted.','success');
      this.loadFds();
    } catch(e) { showToast('Error: '+e.message,'error'); }
    finally { btn.disabled=false; btn.textContent='Delete'; }
  },
};

function fmtNum(v,d=2){ return parseFloat(v||0).toLocaleString('en-IN',{minimumFractionDigits:d,maximumFractionDigits:d}); }
function fmtInr(v){ const n=parseFloat(v||0); return(n<0?'-':'')+'₹'+Math.abs(n).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtDate(d){ if(!d)return'—'; const[y,m,dd]=d.split('-'); return`${dd}-${m}-${y}`; }
function escHtml(t){ const d=document.createElement('div'); d.appendChild(document.createTextNode(t||'')); return d.innerHTML; }

document.addEventListener('DOMContentLoaded', () => FD.init());
