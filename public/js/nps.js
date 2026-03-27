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
  // t20: Pagination
  page: 1, perPage: 10, _allRows: [],
  _renderPag(total, pages, startIdx) {
    const wrap = document.getElementById('npsPagWrap');
    if (!wrap) return;
    if (pages <= 1 && total <= 10) { wrap.innerHTML = ''; return; }
    wrap.innerHTML = `<div style="display:flex;align-items:center;gap:8px;padding:10px 0;flex-wrap:wrap;">
      <select onchange="NPS.setPerPage(+this.value)" style="padding:4px 8px;border-radius:6px;border:1px solid var(--border);background:var(--bg-secondary);color:var(--text-primary);font-size:12px;">
        ${[10,25,50,999].map(n=>`<option value="${n}" ${n===this.perPage?'selected':''}>${n===999?'All':n}</option>`).join('')}
      </select>
      <span style="font-size:12px;color:var(--text-muted);">${Math.min(startIdx+1,total)}–${Math.min(startIdx+this.perPage,total)} of ${total}</span>
      <div style="display:flex;gap:4px;margin-left:auto;">
        <button onclick="NPS.goPage(${this.page-1})" ${this.page<=1?'disabled':''} class="btn btn-ghost btn-sm">‹</button>
        ${Array.from({length:Math.min(5,pages)},(_,i)=>{const p=Math.max(1,Math.min(this.page-2,pages-4))+i;return `<button onclick="NPS.goPage(${p})" class="btn btn-sm ${p===this.page?'btn-primary':'btn-ghost'}">${p}</button>`;}).join('')}
        <button onclick="NPS.goPage(${this.page+1})" ${this.page>=pages?'disabled':''} class="btn btn-ghost btn-sm">›</button>
      </div>
    </div>`;
  },
  goPage(p)     { this.page=p; this.loadHoldings(); },
  setPerPage(n) { this.perPage=n; this.page=1; this.loadHoldings(); },

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

    // Statement dropdown — close on outside click
    document.addEventListener('click', (e) => {
      if (!document.getElementById('npsStmtDropWrap')?.contains(e.target)) {
        const drop = document.getElementById('npsStmtDrop');
        if (drop) drop.style.display = 'none';
      }
    });

    // Load data
    this.loadHoldings();
    this.loadTransactions();
    this.loadSummary(); // t99: asset allocation + tax dashboard
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
        NPS._renderPag(0,1,0);
        return;
      }

      // t20: Pagination
      NPS._allRows = rows;
      const total  = rows.length;
      const pages  = NPS.perPage >= 999 ? 1 : Math.ceil(total / NPS.perPage);
      if (NPS.page > pages) NPS.page = 1;
      const start  = (NPS.page-1) * (NPS.perPage >= 999 ? total : NPS.perPage);
      const paged  = NPS.perPage >= 999 ? rows : rows.slice(start, start + NPS.perPage);
      NPS._renderPag(total, pages, start);

      body.innerHTML = paged.map(h => {
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
  /* ── STATEMENT DOWNLOAD (t101) ── */
  toggleStmtDrop() {
    const drop = document.getElementById('npsStmtDrop');
    if (!drop) return;
    drop.style.display = drop.style.display === 'none' ? 'block' : 'none';
  },

  downloadStatement(format) {
    document.getElementById('npsStmtDrop').style.display = 'none';
    const fy      = document.getElementById('npsStmtFy')?.value || '';
    const portId  = NPS.portfolioFilter || '';
    const tier    = NPS.tierFilter || '';
    const params  = new URLSearchParams({ action: 'nps_statement', format });
    if (fy)     params.set('fy', fy);
    if (portId) params.set('portfolio_id', portId);
    if (tier)   params.set('tier', tier);

    const url = APP_URL + '/api/router.php?' + params;
    if (format === 'csv') {
      // Trigger file download
      const a = document.createElement('a');
      a.href = url;
      a.download = 'NPS_Statement_' + new Date().toISOString().slice(0,10) + '.csv';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
    } else {
      // Open in new tab (html/pdf)
      window.open(url, '_blank');
    }
  },

  /* ── SUMMARY: Asset Allocation + Tax Dashboard (t99 API new summary endpoint) ── */
  async loadSummary() {
    const params = new URLSearchParams({ action: 'nps_list', type: 'summary' });
    if (NPS.portfolioFilter) params.set('portfolio_id', NPS.portfolioFilter);

    try {
      const res  = await fetch(APP_URL + '/api/router.php?' + params);
      const data = await res.json();
      if (!data.success) return;
      const d = data.data;

      // ── Asset allocation donut ──────────────────────────────────────────────
      const alloc    = d.allocation || [];
      const totalVal = alloc.reduce((s, r) => s + parseFloat(r.value || 0), 0);
      const colors   = { E: '#16a34a', C: '#ea580c', G: '#2563eb', A: '#9333ea' };
      const labels   = { E: 'Equity', C: 'Corporate Bond', G: 'Govt Bond', A: 'Alternative' };

      if (alloc.length && typeof Chart !== 'undefined') {
        const canvas = document.getElementById('npsAllocChart');
        const center = document.getElementById('npsAllocCenter');
        if (canvas && totalVal > 0) {
          // Destroy existing
          if (canvas._chartInstance) canvas._chartInstance.destroy();
          const ctx = canvas.getContext('2d');
          canvas._chartInstance = new Chart(ctx, {
            type: 'doughnut',
            data: {
              labels: alloc.map(r => labels[r.asset_class] || r.asset_class),
              datasets: [{ data: alloc.map(r => parseFloat(r.value||0)), backgroundColor: alloc.map(r => colors[r.asset_class]||'#94a3b8'), borderWidth: 2, borderColor: '#fff' }]
            },
            options: { cutout:'72%', plugins:{ legend:{ display:false }, tooltip:{ callbacks:{ label: ctx => ` ${ctx.label}: ${(ctx.parsed/totalVal*100).toFixed(1)}%` } } } }
          });
          if (center) center.innerHTML = `<div style="font-size:14px;font-weight:800;color:var(--text-primary)">${alloc.length}</div><div style="font-size:10px;color:var(--text-muted)">Classes</div>`;
        }
      }

      // Legend
      const legend = document.getElementById('npsAllocLegend');
      if (legend) {
        legend.innerHTML = alloc.map(r => {
          const pct = totalVal > 0 ? (parseFloat(r.value||0)/totalVal*100).toFixed(1) : 0;
          return `<div style="display:flex;align-items:center;gap:7px;">
            <div style="width:10px;height:10px;border-radius:2px;background:${colors[r.asset_class]||'#94a3b8'};flex-shrink:0"></div>
            <span style="color:var(--text-muted);font-size:11px">${labels[r.asset_class]||r.asset_class}</span>
            <span style="margin-left:auto;font-weight:700;font-size:12px">${pct}%</span>
          </div>`;
        }).join('');
      }

      // ── Tax dashboard ───────────────────────────────────────────────────────
      const fy   = d.current_fy || '';
      const self = parseFloat(d.fy_totals?.self || 0);
      const empl = parseFloat(d.fy_totals?.employer || 0);
      const LIMIT_80C   = 150000;
      const LIMIT_80CCD = 50000;

      // 80C bar (employee contrib up to 1.5L combined)
      const s80c = Math.min(self, LIMIT_80C);
      document.getElementById('nps80cAmt')?.innerText  && (document.getElementById('nps80cAmt').innerText = '₹' + s80c.toLocaleString('en-IN'));
      const bar80c = document.getElementById('nps80cBar');
      if (bar80c) bar80c.style.width = Math.min(100, s80c/LIMIT_80C*100) + '%';

      // 80CCD(1B) bar: extra ₹50K above 1.5L
      const over80c = Math.max(0, self - LIMIT_80C);
      const s80ccd  = Math.min(over80c, LIMIT_80CCD);
      document.getElementById('nps80ccdAmt') && (document.getElementById('nps80ccdAmt').innerText = '₹' + s80ccd.toLocaleString('en-IN'));
      const bar80ccd = document.getElementById('nps80ccdBar');
      if (bar80ccd) bar80ccd.style.width = Math.min(100, s80ccd/LIMIT_80CCD*100) + '%';

      // 80CCD(2): employer
      const salEst  = empl * 10; // 10% of salary = employer contrib → reverse
      const emplPct = salEst > 0 ? Math.min(100, empl/salEst*100) : 0;
      document.getElementById('nps80ccd2Amt') && (document.getElementById('nps80ccd2Amt').innerText = '₹' + empl.toLocaleString('en-IN'));
      const bar80ccd2 = document.getElementById('nps80ccd2Bar');
      if (bar80ccd2) bar80ccd2.style.width = emplPct + '%';

      // FY label
      document.getElementById('npsTaxFy') && (document.getElementById('npsTaxFy').innerText = 'FY ' + fy);

      // Tax message
      const totalDeduction = s80c + s80ccd + empl;
      const msg = document.getElementById('npsTaxMsg');
      if (msg) {
        const remaining80ccd = LIMIT_80CCD - s80ccd;
        if (remaining80ccd > 0) {
          msg.innerHTML = `💡 Extra ₹${remaining80ccd.toLocaleString('en-IN')} invest karo 80CCD(1B) mein — pure tax savings (30% slab mein ₹${Math.round(remaining80ccd*0.3).toLocaleString('en-IN')} bachenge)`;
        } else {
          msg.innerHTML = `✅ 80CCD(1B) fully utilized! Total NPS deductions this FY: ₹${totalDeduction.toLocaleString('en-IN')}`;
          msg.style.background = 'rgba(22,163,74,.07)';
          msg.style.color = '#15803d';
        }
      }

    } catch (e) {
      console.warn('NPS summary load failed:', e.message);
    }
  },

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
