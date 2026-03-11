/**
 * WealthDash — NPS Module JS (nps.js)
 * Handles: NPS Holdings table, Contribution CRUD, NAV Refresh
 */

const NPS = {
  tierFilter: '',
  portfolioFilter: '',
  txnSchemeFilter: '',
  txnTierFilter: '',
  txnTypeFilter: '',
  pendingDeleteId: null,

  /* ── INIT ── */
  init() {
    this.filterSchemes(); // populate scheme dropdown with tier1 default

    // Holdings tier tabs
    document.querySelectorAll('.view-btn[data-tier]').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.view-btn[data-tier]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        NPS.tierFilter = btn.dataset.tier;
        NPS.loadHoldings();
      });
    });

    document.getElementById('filterPortfolio').addEventListener('change', e => {
      NPS.portfolioFilter = e.target.value;
      NPS.loadHoldings();
    });

    // Transaction filters
    ['txnFilterScheme','txnFilterTier','txnFilterType'].forEach(id => {
      document.getElementById(id)?.addEventListener('change', () => NPS.loadTransactions());
    });

    // Add modal
    document.getElementById('btnAddNps').addEventListener('click', () => NPS.openAddModal());
    document.getElementById('closeModalNps').addEventListener('click', () => NPS.closeAddModal());
    document.getElementById('cancelNps').addEventListener('click', () => NPS.closeAddModal());
    document.getElementById('saveNps').addEventListener('click', () => NPS.saveContribution());

    // NAV refresh
    document.getElementById('btnNavUpdate').addEventListener('click', () => NPS.refreshNav());

    // Delete modal
    document.getElementById('closeDelNps').addEventListener('click', () => NPS.closeDelModal());
    document.getElementById('cancelDelNps').addEventListener('click', () => NPS.closeDelModal());
    document.getElementById('confirmDelNps').addEventListener('click', () => NPS.deleteContribution());

    // Load data
    this.loadHoldings();
    this.loadTransactions();
  },

  /* ── SCHEME DROPDOWN FILTER BY TIER ── */
  filterSchemes() {
    const tier = document.getElementById('npsTier')?.value || 'tier1';
    const sel  = document.getElementById('npsScheme');
    if (!sel) return;

    const schemes = (window.NPS_SCHEMES_DATA || []).filter(s => s.tier === tier);
    const grouped = {};
    schemes.forEach(s => {
      if (!grouped[s.pfm_name]) grouped[s.pfm_name] = [];
      grouped[s.pfm_name].push(s);
    });

    sel.innerHTML = '<option value="">— Select Scheme —</option>';
    Object.entries(grouped).forEach(([pfm, list]) => {
      const og = document.createElement('optgroup');
      og.label = pfm;
      list.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.id;
        opt.dataset.nav = s.latest_nav || '';
        opt.textContent = s.scheme_name + (s.latest_nav ? ` — NAV: ₹${parseFloat(s.latest_nav).toFixed(4)}` : '');
        og.appendChild(opt);
      });
      sel.appendChild(og);
    });

    // Also populate txn scheme filter
    const txnSel = document.getElementById('txnFilterScheme');
    if (txnSel) {
      const allSchemes = window.NPS_SCHEMES_DATA || [];
      txnSel.innerHTML = '<option value="">All Schemes</option>';
      allSchemes.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.id;
        opt.textContent = `${s.pfm_name} — ${s.scheme_name} (${s.tier.toUpperCase()})`;
        txnSel.appendChild(opt);
      });
    }
  },

  onSchemeChange() {
    const sel = document.getElementById('npsScheme');
    const opt = sel.options[sel.selectedIndex];
    const nav = opt?.dataset?.nav;
    if (nav) document.getElementById('npsNav').value = parseFloat(nav).toFixed(4);
  },

  calcAmount() {
    const units = parseFloat(document.getElementById('npsUnits').value) || 0;
    const nav   = parseFloat(document.getElementById('npsNav').value) || 0;
    if (units && nav) document.getElementById('npsAmount').value = (units * nav).toFixed(2);
  },

  calcUnits() {
    const amount = parseFloat(document.getElementById('npsAmount').value) || 0;
    const nav    = parseFloat(document.getElementById('npsNav').value) || 0;
    if (amount && nav) document.getElementById('npsUnits').value = (amount / nav).toFixed(4);
  },

  /* ── LOAD HOLDINGS ── */
  async loadHoldings() {
    const body = document.getElementById('npsHoldingsBody');
    body.innerHTML = '<tr><td colspan="10" class="text-center" style="padding:40px;color:var(--text-muted)"><span class="spinner"></span> Loading...</td></tr>';

    const params = new URLSearchParams({ action: 'nps_list', type: 'holdings' });
    if (NPS.tierFilter)      params.set('tier', NPS.tierFilter);
    if (NPS.portfolioFilter) params.set('portfolio_id', NPS.portfolioFilter);

    try {
      const res = await fetch(APP_URL + '/api/router.php?' + params);
      const data = await res.json();
      if (!data.success) throw new Error(data.message || 'Failed');

      const rows = data.data || [];
      if (!rows.length) {
        body.innerHTML = '<tr><td colspan="10" class="text-center empty-state" style="padding:60px"><div style="font-size:40px">🏛️</div><p>No NPS holdings yet.<br>Add your first contribution to get started.</p></td></tr>';
        return;
      }

      body.innerHTML = rows.map(h => {
        const gl     = parseFloat(h.gain_loss) || 0;
        const glPct  = parseFloat(h.gain_pct)  || 0;
        const cagr   = parseFloat(h.cagr)      || 0;
        const glCls  = gl >= 0 ? 'text-success' : 'text-danger';
        return `<tr>
          <td>
            <div class="fund-name">${escHtml(h.scheme_name)}</div>
            <div class="fund-sub">${escHtml(h.pfm_name)}</div>
          </td>
          <td><span class="badge badge-outline">${h.tier.toUpperCase()}</span></td>
          <td class="text-right">${fmtNum(h.total_units, 4)}</td>
          <td class="text-right">₹${fmtNum(h.latest_nav, 4)}<br><small style="color:var(--text-muted)">${h.latest_nav_date || '—'}</small></td>
          <td class="text-right">${fmtInr(h.total_invested)}</td>
          <td class="text-right">${fmtInr(h.latest_value)}</td>
          <td class="text-right ${glCls}">${fmtInr(gl)}<br><small>${gl >= 0 ? '+' : ''}${glPct.toFixed(2)}%</small></td>
          <td class="text-right ${cagr >= 0 ? 'text-success' : 'text-danger'}">${cagr.toFixed(2)}%</td>
          <td class="text-right">${h.first_contribution_date ? fmtDate(h.first_contribution_date) : '—'}</td>
          <td class="text-center">
            <button class="btn btn-sm btn-ghost" onclick="NPS.viewHistory(${h.scheme_id}, '${h.tier}')" title="View history">📋</button>
          </td>
        </tr>`;
      }).join('');
    } catch (e) {
      body.innerHTML = `<tr><td colspan="10" class="text-center text-danger" style="padding:40px">Error: ${e.message}</td></tr>`;
    }
  },

  /* ── LOAD TRANSACTIONS ── */
  async loadTransactions() {
    const body = document.getElementById('npsTxnBody');
    body.innerHTML = '<tr><td colspan="9" class="text-center" style="padding:40px;color:var(--text-muted)"><span class="spinner"></span></td></tr>';

    const params = new URLSearchParams({ action: 'nps_list', type: 'transactions' });
    if (document.getElementById('txnFilterScheme')?.value) params.set('scheme_id', document.getElementById('txnFilterScheme').value);
    if (document.getElementById('txnFilterTier')?.value)   params.set('tier',       document.getElementById('txnFilterTier').value);
    if (document.getElementById('txnFilterType')?.value)   params.set('contrib_type', document.getElementById('txnFilterType').value);

    try {
      const res = await fetch(APP_URL + '/api/router.php?' + params);
      const data = await res.json();
      if (!data.success) throw new Error(data.message);
      const txns = data.data || [];
      if (!txns.length) {
        body.innerHTML = '<tr><td colspan="9" class="text-center empty-state" style="padding:40px"><p>No contributions found.</p></td></tr>';
        return;
      }
      body.innerHTML = txns.map(t => `<tr>
        <td>${fmtDate(t.txn_date)}</td>
        <td>
          <div>${escHtml(t.scheme_name)}</div>
          <small style="color:var(--text-muted)">${escHtml(t.pfm_name)}</small>
        </td>
        <td><span class="badge badge-outline">${t.tier.toUpperCase()}</span></td>
        <td><span class="badge ${t.contribution_type === 'EMPLOYER' ? 'badge-info' : 'badge-success'}">${t.contribution_type}</span></td>
        <td class="text-right">${fmtNum(t.units, 4)}</td>
        <td class="text-right">₹${fmtNum(t.nav, 4)}</td>
        <td class="text-right">${fmtInr(t.amount)}</td>
        <td>${t.investment_fy}</td>
        <td class="text-center">
          <button class="btn btn-sm btn-danger-ghost" onclick="NPS.confirmDelete(${t.id})" title="Delete">🗑️</button>
        </td>
      </tr>`).join('');
    } catch (e) {
      body.innerHTML = `<tr><td colspan="9" class="text-center text-danger" style="padding:40px">Error: ${e.message}</td></tr>`;
    }
  },

  /* ── ADD MODAL ── */
  openAddModal() {
    document.getElementById('npsError').style.display = 'none';
    document.getElementById('npsNotes').value = '';
    document.getElementById('npsUnits').value = '';
    document.getElementById('npsAmount').value = '';
    document.getElementById('npsNav').value = '';
    document.getElementById('npsTxnDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('modalAddNps').style.display = 'flex';
    this.filterSchemes();
  },

  closeAddModal() {
    document.getElementById('modalAddNps').style.display = 'none';
  },

  /* ── SAVE CONTRIBUTION ── */
  async saveContribution() {
    const errEl = document.getElementById('npsError');
    errEl.style.display = 'none';
    const btn = document.getElementById('saveNps');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    const body = new URLSearchParams({
      action:            'nps_add',
      portfolio_id:      document.getElementById('npsPortfolio').value,
      scheme_id:         document.getElementById('npsScheme').value,
      tier:              document.getElementById('npsTier').value,
      contribution_type: document.getElementById('npsContribType').value,
      txn_date:          document.getElementById('npsTxnDate').value,
      units:             document.getElementById('npsUnits').value,
      nav:               document.getElementById('npsNav').value,
      amount:            document.getElementById('npsAmount').value,
      notes:             document.getElementById('npsNotes').value,
    });

    try {
      const res  = await fetch(APP_URL + '/api/router.php', { method: 'POST', body });
      const data = await res.json();
      if (!data.success) throw new Error(data.message);
      this.closeAddModal();
      showToast('Contribution saved!', 'success');
      this.loadHoldings();
      this.loadTransactions();
    } catch (e) {
      errEl.textContent = e.message;
      errEl.style.display = 'block';
    } finally {
      btn.disabled = false;
      btn.textContent = 'Save Contribution';
    }
  },

  /* ── NAV REFRESH ── */
  async refreshNav() {
    const btn = document.getElementById('btnNavUpdate');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Fetching...';
    try {
      const res  = await fetch(APP_URL + '/api/router.php', { method: 'POST', body: new URLSearchParams({ action: 'nps_nav_update' }) });
      const data = await res.json();
      if (!data.success) throw new Error(data.message);
      showToast(data.message || 'NAV updated!', 'success');
      this.loadHoldings();
    } catch (e) {
      showToast('NAV refresh failed: ' + e.message, 'error');
    } finally {
      btn.disabled = false;
      btn.innerHTML = '↺ Refresh NAV';
    }
  },

  viewHistory(schemeId, tier) {
    document.getElementById('txnFilterScheme').value = schemeId;
    document.getElementById('txnFilterTier').value   = tier;
    this.loadTransactions();
    document.getElementById('npsTxnBody').closest('.card').scrollIntoView({ behavior: 'smooth' });
  },

  confirmDelete(id) {
    NPS.pendingDeleteId = id;
    document.getElementById('modalDelNps').style.display = 'flex';
  },
  closeDelModal() {
    document.getElementById('modalDelNps').style.display = 'none';
    NPS.pendingDeleteId = null;
  },
  async deleteContribution() {
    if (!NPS.pendingDeleteId) return;
    const btn = document.getElementById('confirmDelNps');
    btn.disabled = true; btn.textContent = 'Deleting...';
    try {
      const res  = await fetch(APP_URL + '/api/router.php', { method: 'POST', body: new URLSearchParams({ action: 'nps_delete', id: NPS.pendingDeleteId }) });
      const data = await res.json();
      if (!data.success) throw new Error(data.message);
      this.closeDelModal();
      showToast('Contribution deleted.', 'success');
      this.loadHoldings();
      this.loadTransactions();
    } catch (e) {
      showToast('Error: ' + e.message, 'error');
    } finally {
      btn.disabled = false; btn.textContent = 'Delete';
    }
  },
};

/* ── HELPERS ── */
function fmtNum(v, d=2) { return parseFloat(v||0).toLocaleString('en-IN', { minimumFractionDigits: d, maximumFractionDigits: d }); }
function fmtInr(v) { const n=parseFloat(v||0); return (n<0?'-':'')+'₹'+Math.abs(n).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtDate(d) { if(!d)return'—'; const [y,m,day]=d.split('-'); return `${day}-${m}-${y}`; }
function escHtml(t) { const d=document.createElement('div'); d.appendChild(document.createTextNode(t||'')); return d.innerHTML; }

document.addEventListener('DOMContentLoaded', () => NPS.init());
