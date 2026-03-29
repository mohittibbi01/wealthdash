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

  // ── Chart Modal state ──────────────────────────────────────────────────
  _nc: {
    schemeId: null,
    holding:  null,
    range:    '1Y',
    navData:  [],
    txns:     [],
    chartInst: null,
    showTxns: true,
  },
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
  setTierFilter(tier, btn) {
    document.querySelectorAll('.nps-tier-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    this.tierFilter = tier;
    this.page = 1;
    this.loadHoldings();
  },

  /* ── INIT ── */
  init() {
    this.filterSchemes(); // populate scheme dropdown with tier1 default

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

    // Close NPS chart modal on backdrop click
    document.getElementById('modalNpsChart')?.addEventListener('click', e => {
      if (e.target.id === 'modalNpsChart') NPS.closeChartModal();
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
    body.innerHTML = '<tr><td colspan="7" class="text-center" style="padding:40px;color:var(--text-muted)"><span class="spinner"></span> Loading...</td></tr>';

    const params = new URLSearchParams({ action: 'nps_list', type: 'holdings' });
    if (NPS.tierFilter)      params.set('tier', NPS.tierFilter);
    if (NPS.portfolioFilter) params.set('portfolio_id', NPS.portfolioFilter);

    try {
      const res = await fetch(APP_URL + '/api/router.php?' + params);
      const data = await res.json();
      if (!data.success) throw new Error(data.message || 'Failed');

      const rows = data.data || [];
      if (!rows.length) {
        body.innerHTML = '<tr><td colspan="7" class="text-center empty-state" style="padding:60px"><div style="font-size:40px">🏛️</div><p>No NPS holdings yet.<br>Add your first contribution to get started.</p></td></tr>';
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
        const xirr   = h.xirr !== null && h.xirr !== undefined ? parseFloat(h.xirr) : null;
        const invested = parseFloat(h.total_invested) || 0;
        const value    = parseFloat(h.latest_value)   || 0;
        const selfAmt  = parseFloat(h.self_contributed)     || 0;
        const empAmt   = parseFloat(h.employer_contributed) || 0;

        // ── colored cell helper ──
        function cell(val, isAmt = false, dec = 2) {
          const n = parseFloat(val);
          if (isNaN(n)) return '<span style="color:var(--text-muted);">—</span>';
          const pos = n >= 0;
          const clr = pos ? '#16a34a' : '#dc2626';
          const arr = pos ? '▲' : '▼';
          const sign = pos ? '+' : '';
          const fmt = isAmt
            ? sign + fmtInr(Math.abs(n))
            : sign + Math.abs(n).toFixed(dec) + '%';
          return `<span style="color:${clr};font-weight:700;">${arr} ${fmt}</span>`;
        }

        // ── Asset class badge ──
        const ac = (h.asset_class || '').toUpperCase();
        const acColors = {
          'E': ['rgba(22,163,74,.1)','#15803d'],
          'C': ['rgba(37,99,235,.1)','#1d4ed8'],
          'G': ['rgba(168,85,247,.1)','#7c3aed'],
          'A': ['rgba(245,158,11,.1)','#b45309'],
        };
        const acPair = acColors[ac] || ['rgba(107,114,128,.1)','#6b7280'];
        const acLabel = { E:'Equity', C:'Corporate Bond', G:'Govt Bond', A:'Alt Assets' }[ac] || ac;
        const acBadge = ac
          ? `<span style="display:inline-block;padding:1px 7px;border-radius:4px;font-size:10px;font-weight:700;background:${acPair[0]};color:${acPair[1]};">${escHtml(acLabel)}</span>`
          : '';

        // ── Tier badge ──
        const tierLabel = (h.tier || '').replace('tier','Tier ').toUpperCase();
        const tierBg = h.tier === 'tier1' ? 'rgba(37,99,235,.1)' : 'rgba(168,85,247,.1)';
        const tierClr = h.tier === 'tier1' ? '#1d4ed8' : '#7c3aed';
        const tierBadge = `<span style="display:inline-block;padding:1px 7px;border-radius:4px;font-size:10px;font-weight:700;background:${tierBg};color:${tierClr};">${tierLabel}</span>`;

        // ── Contribution split badge ──
        const hasSplit = selfAmt > 0 || empAmt > 0;
        const splitBadge = hasSplit
          ? `<span style="display:inline-block;padding:1px 7px;border-radius:4px;font-size:10px;font-weight:600;background:rgba(22,163,74,.08);color:#15803d;border:1px solid rgba(22,163,74,.2);"
              title="Self: ${fmtInr(selfAmt)} | Employer: ${fmtInr(empAmt)}">
              ${selfAmt > 0 && empAmt > 0 ? '👤+🏢 Both' : selfAmt > 0 ? '👤 Self' : '🏢 Employer'}
            </span>`
          : '';

        // ── NAV + date ──
        const navHtml = h.latest_nav
          ? `<div style="font-weight:700;">₹${fmtNum(h.latest_nav, 4)}</div><small style="color:var(--text-muted);">${h.latest_nav_date ? fmtDate(h.latest_nav_date) : ''}</small>`
          : '—';

        // ── XIRR/CAGR ──
        const cagrHtml = xirr !== null
          ? cell(xirr, false, 2)
          : '<span style="color:var(--text-muted);">—</span>';

        // ── Return badges from scheme (1Y/3Y/5Y) ──
        const r1y = h.return_1y !== null && h.return_1y !== undefined ? parseFloat(h.return_1y) : null;
        const r3y = h.return_3y !== null && h.return_3y !== undefined ? parseFloat(h.return_3y) : null;
        const returnBadges = [
          r1y !== null ? `<span title="1Y Return" style="font-size:10px;padding:1px 5px;border-radius:3px;background:${r1y>=0?'rgba(22,163,74,.08)':'rgba(220,38,38,.08)'};color:${r1y>=0?'#15803d':'#dc2626'};font-weight:600;">1Y: ${r1y>=0?'+':''}${r1y.toFixed(1)}%</span>` : '',
          r3y !== null ? `<span title="3Y Return" style="font-size:10px;padding:1px 5px;border-radius:3px;background:${r3y>=0?'rgba(37,99,235,.08)':'rgba(220,38,38,.08)'};color:${r3y>=0?'#1d4ed8':'#dc2626'};font-weight:600;">3Y: ${r3y>=0?'+':''}${r3y.toFixed(1)}%</span>` : '',
        ].filter(Boolean).join(' ');

        return `<tr style="transition:background .12s;" onmouseover="this.style.background='var(--bg-secondary)'" onmouseout="this.style.background=''">
          <td class="fund-name-cell" style="text-align:left;padding:10px 14px;">
            <div class="fund-title nps-scheme-clickable" style="font-weight:700;font-size:13px;margin-bottom:3px;" onclick="NPS.openChartModal(${h.scheme_id},'${h.tier}')">${escHtml(h.scheme_name)}</div>
            <div class="fund-sub" style="color:var(--text-muted);font-size:11px;margin-bottom:5px;">${escHtml(h.pfm_name)}</div>
            <div style="display:flex;flex-wrap:wrap;gap:4px;align-items:center;">
              ${tierBadge}${acBadge ? ' ' + acBadge : ''}${splitBadge ? ' ' + splitBadge : ''}
              ${returnBadges ? `<span style="margin-left:2px;">${returnBadges}</span>` : ''}
            </div>
          </td>
          <td class="text-center" style="padding:6px 10px;">
            <div style="font-size:12px;color:var(--text-muted);">${fmtInr(invested)}</div>
            <div style="font-weight:800;font-size:14px;">${fmtInr(value)}</div>
            <div style="font-size:12px;border-top:1px solid var(--border-color);padding-top:3px;margin-top:3px;">${cell(gl, true)}</div>
          </td>
          <td class="text-center" style="padding:6px 10px;">
            <div style="font-size:12px;">${cell(glPct, false)}</div>
            <div style="font-size:12px;border-top:1px solid var(--border-color);padding-top:3px;margin-top:3px;">${cagrHtml}</div>
          </td>
          <td class="text-center" style="padding:6px 10px;">
            <div style="font-weight:700;">${fmtNum(h.total_units, 4)}</div>
            ${selfAmt > 0 && empAmt > 0 ? `<div style="font-size:10px;color:var(--text-muted);margin-top:3px;">👤 ${fmtInr(selfAmt)}</div><div style="font-size:10px;color:var(--text-muted);">🏢 ${fmtInr(empAmt)}</div>` : ''}
          </td>
          <td class="text-center" style="padding:6px 10px;">${navHtml}</td>
          <td class="text-center" style="padding:6px 10px;color:var(--text-muted);font-size:12px;">${h.first_contribution_date ? fmtDate(h.first_contribution_date) : '—'}</td>
          <td class="text-center" style="padding:6px 8px;">
            <div style="display:flex;flex-direction:column;align-items:center;gap:5px;">
              <button onclick="NPS.viewHistory(${h.scheme_id}, '${h.tier}')"
                style="display:flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;border:1px solid var(--border-color);background:var(--bg-secondary);cursor:pointer;font-size:11px;color:var(--text-muted);font-weight:600;transition:all .15s;"
                onmouseover="this.style.borderColor='var(--accent)';this.style.color='var(--accent)';this.style.background='rgba(37,99,235,.06)'"
                onmouseout="this.style.borderColor='var(--border-color)';this.style.color='var(--text-muted)';this.style.background='var(--bg-secondary)'"
                title="View contribution history">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>
                History
              </button>
              <button onclick="NPS.openAddModalFor(${h.scheme_id},'${h.tier}')"
                style="display:flex;align-items:center;gap:4px;padding:4px 10px;border-radius:6px;border:1px solid rgba(22,163,74,.35);background:rgba(22,163,74,.06);cursor:pointer;font-size:11px;color:#15803d;font-weight:600;transition:all .15s;"
                onmouseover="this.style.background='rgba(22,163,74,.15)';this.style.borderColor='#15803d'"
                onmouseout="this.style.background='rgba(22,163,74,.06)';this.style.borderColor='rgba(22,163,74,.35)'"
                title="Add contribution to this scheme">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add
              </button>
            </div>
          </td>
        </tr>`;
      }).join('');
    } catch (e) {
      body.innerHTML = `<tr><td colspan="7" class="text-center text-danger" style="padding:40px">Error: ${e.message}</td></tr>`;
    }
  },

  // ── NPS NAV HISTORY CHART MODAL ─────────────────────────────────────────
  async openChartModal(schemeId, tier) {
    const nc = NPS._nc;
    // Find holding in cached rows
    const h = (NPS._allRows || []).find(r => r.scheme_id == schemeId && r.tier === tier)
           || (NPS._allRows || []).find(r => r.scheme_id == schemeId);
    if (!h) { showToast('Holding data not found', 'error'); return; }

    nc.schemeId = schemeId;
    nc.holding  = h;
    nc.range    = '1Y';
    nc.navData  = [];
    nc.txns     = [];
    nc.showTxns = true;

    document.getElementById('modalNpsChart').style.display = 'flex';
    document.body.style.overflow = 'hidden';
    document.getElementById('ncShowTxns').checked = true;

    // Header
    document.getElementById('ncFundName').textContent = h.scheme_name || '—';

    // Meta badges
    const tierLabel = (h.tier || '').replace('tier', 'Tier ').toUpperCase();
    const tierBg    = h.tier === 'tier1' ? 'rgba(37,99,235,.1)' : 'rgba(168,85,247,.1)';
    const tierClr   = h.tier === 'tier1' ? '#1d4ed8' : '#7c3aed';
    const acColors  = { E:'#15803d', C:'#1d4ed8', G:'#7c3aed', A:'#b45309' };
    const acLabels  = { E:'Equity', C:'Corporate Bond', G:'Govt Bond', A:'Alt Assets' };
    const ac = (h.asset_class || '').toUpperCase();
    const meta = [
      `<span style="background:${tierBg};color:${tierClr};padding:1px 7px;border-radius:4px;font-weight:700;">${tierLabel}</span>`,
      ac ? `<span style="background:rgba(0,0,0,.05);color:${acColors[ac]||'#64748b'};padding:1px 7px;border-radius:4px;font-weight:600;">${acLabels[ac]||ac}</span>` : '',
      `<span style="color:var(--text-muted);">${escHtml(h.pfm_name||'')}</span>`,
    ].filter(Boolean).join('');
    document.getElementById('ncFundMeta').innerHTML = meta;

    // Stats
    this._renderNcStats(h);

    // Reset range buttons
    document.querySelectorAll('.nc-range-btn').forEach(b => {
      b.classList.toggle('active', b.dataset.range === '1Y');
      b.classList.remove('nc-range-btn-active');
    });

    // Spinner
    this._ncShowSpinner(true);
    document.getElementById('ncNoData').style.display = 'none';
    document.getElementById('ncChartCanvas').style.display = 'none';
    document.getElementById('ncTxnSection').style.display = 'none';

    // Load transactions + NAV history
    await this._ncFetchTxns();
    await this._ncFetchAndRender();
  },

  closeChartModal() {
    document.getElementById('modalNpsChart').style.display = 'none';
    document.body.style.overflow = '';
    const nc = NPS._nc;
    if (nc.chartInst) { nc.chartInst.destroy(); nc.chartInst = null; }
    nc.schemeId = null;
    nc.navData  = [];
    nc.txns     = [];
  },

  _renderNcStats(h) {
    const invested = parseFloat(h.total_invested) || 0;
    const value    = parseFloat(h.latest_value)   || 0;
    const gl       = parseFloat(h.gain_loss)       || 0;
    const glPct    = parseFloat(h.gain_pct)        || 0;
    const xirr     = h.xirr !== null && h.xirr !== undefined ? parseFloat(h.xirr) : null;
    const nav      = parseFloat(h.latest_nav)      || 0;
    const units    = parseFloat(h.total_units)     || 0;

    function cell(label, val, sub, color) {
      return `<div class="nc-stat-cell">
        <div class="nc-stat-label">${label}</div>
        <div class="nc-stat-val" style="color:${color||'var(--text-primary)'};">${val}</div>
        ${sub ? `<div class="nc-stat-sub">${sub}</div>` : ''}
      </div>`;
    }

    const gainColor = gl >= 0 ? '#16a34a' : '#dc2626';
    const glSign    = gl >= 0 ? '+' : '';
    document.getElementById('ncStats').innerHTML =
      cell('Invested',    fmtInr(invested),  '', '') +
      cell('Current Val', fmtInr(value),     '', '') +
      cell('Gain/Loss',   glSign + fmtInr(gl), glPct.toFixed(2) + '%', gainColor) +
      cell('CAGR/XIRR',  xirr !== null ? (xirr >= 0 ? '+' : '') + xirr.toFixed(2) + '%' : '—', 'Annualised', xirr !== null ? (xirr >= 0 ? '#16a34a' : '#dc2626') : 'var(--text-muted)') +
      cell('Units × NAV', fmtNum(units, 4), '@ ₹' + fmtNum(nav, 4), '');
  },

  async _ncFetchTxns() {
    const nc = NPS._nc;
    try {
      const params = new URLSearchParams({ action: 'nps_list', type: 'transactions', scheme_id: nc.schemeId });
      const res  = await fetch(APP_URL + '/api/router.php?' + params);
      const data = await res.json();
      nc.txns = data.success ? (data.data || []) : [];
    } catch(e) {
      nc.txns = [];
    }
  },

  async _ncFetchAndRender() {
    const nc = NPS._nc;
    this._ncShowSpinner(true);
    document.getElementById('ncDataStatus').textContent = '';

    const { from, to } = this._ncRangeDates(nc.range, nc.holding);

    try {
      const params = new URLSearchParams({ action: 'nps_nav_history', scheme_id: nc.schemeId, from, to });
      const res  = await fetch(APP_URL + '/api/router.php?' + params);
      const json = await res.json();

      if (!json.success || !json.data || json.data.length === 0) {
        this._ncShowSpinner(false);
        document.getElementById('ncNoData').style.display = 'flex';
        document.getElementById('ncChartCanvas').style.display = 'none';
        document.getElementById('ncTxnSection').style.display = 'none';
        return;
      }

      nc.navData = json.data;
      document.getElementById('ncNoData').style.display = 'none';
      document.getElementById('ncChartCanvas').style.display = '';
      document.getElementById('ncTxnSection').style.display = '';
      document.getElementById('ncDataStatus').textContent = `${json.count.toLocaleString()} data points`;

      this._ncRenderChart();
      this._ncRenderTxnPills();
    } catch(e) {
      this._ncShowSpinner(false);
      document.getElementById('ncDataStatus').textContent = 'NAV data load failed';
    }
  },

  _ncRangeDates(range, holding) {
    const toDate = new Date();
    let fromDate;
    switch (range) {
      case '3M':  fromDate = new Date(toDate); fromDate.setMonth(fromDate.getMonth() - 3); break;
      case '6M':  fromDate = new Date(toDate); fromDate.setMonth(fromDate.getMonth() - 6); break;
      case '3Y':  fromDate = new Date(toDate); fromDate.setFullYear(fromDate.getFullYear() - 3); break;
      case 'ALL':
        fromDate = holding?.first_contribution_date
          ? new Date(holding.first_contribution_date)
          : new Date('2010-01-01');
        fromDate.setMonth(fromDate.getMonth() - 2);
        break;
      case '1Y':
      default:
        fromDate = new Date(toDate); fromDate.setFullYear(fromDate.getFullYear() - 1);
    }
    const fmt = d => d.toISOString().slice(0, 10);
    return { from: fmt(fromDate), to: fmt(toDate) };
  },

  _ncRenderChart() {
    const nc     = NPS._nc;
    const canvas = document.getElementById('ncChartCanvas');
    if (!canvas || typeof Chart === 'undefined') return;

    if (nc.chartInst) { nc.chartInst.destroy(); nc.chartInst = null; }

    const ctx     = canvas.getContext('2d');
    const labels  = nc.navData.map(d => d.date);
    const navVals = nc.navData.map(d => d.nav);

    const grad = ctx.createLinearGradient(0, 0, 0, canvas.offsetHeight || 280);
    grad.addColorStop(0, 'rgba(37,99,235,0.18)');
    grad.addColorStop(1, 'rgba(37,99,235,0.00)');

    // Filter contributions within range
    const { from, to } = this._ncRangeDates(nc.range, nc.holding);
    const inRange = (nc.txns || []).filter(t => t.txn_date >= from && t.txn_date <= to);

    // Contribution scatter points
    const contribPoints = [];
    inRange.forEach(t => {
      const navAtTxn = this._ncNearestNav(t.txn_date);
      if (navAtTxn === null) return;
      contribPoints.push({ x: t.txn_date, y: navAtTxn, txn: t });
    });

    // Avg cost reference
    const avgNav = nc.holding?.total_invested > 0 && nc.holding?.total_units > 0
      ? parseFloat(nc.holding.total_invested) / parseFloat(nc.holding.total_units)
      : null;

    const datasets = [
      {
        label: 'NAV',
        data: navVals,
        borderColor: '#2563eb',
        borderWidth: 1.8,
        backgroundColor: grad,
        fill: true,
        pointRadius: 0,
        pointHoverRadius: 4,
        pointHoverBackgroundColor: '#2563eb',
        tension: 0.15,
        order: 3,
      }
    ];

    if (avgNav) {
      datasets.push({
        label: 'Avg Cost ₹' + avgNav.toFixed(4),
        data: labels.map(() => avgNav),
        borderColor: 'rgba(234,179,8,0.7)',
        borderWidth: 1.5,
        borderDash: [5, 4],
        fill: false,
        pointRadius: 0,
        tension: 0,
        order: 2,
      });
    }

    if (nc.showTxns && contribPoints.length) {
      // Self contributions
      const selfPts   = contribPoints.filter(p => p.txn.contribution_type !== 'EMPLOYER');
      const emplPts   = contribPoints.filter(p => p.txn.contribution_type === 'EMPLOYER');

      if (selfPts.length) {
        datasets.push({
          label: 'Self Contrib',
          type: 'scatter',
          data: selfPts.map(p => ({ x: labels.indexOf(p.x), y: p.y, raw: p })),
          backgroundColor: 'rgba(22,163,74,0.9)',
          borderColor: '#fff',
          borderWidth: 1.5,
          pointRadius: 7,
          pointHoverRadius: 9,
          pointStyle: 'triangle',
          order: 1,
        });
      }
      if (emplPts.length) {
        datasets.push({
          label: 'Employer Contrib',
          type: 'scatter',
          data: emplPts.map(p => ({ x: labels.indexOf(p.x), y: p.y, raw: p })),
          backgroundColor: 'rgba(37,99,235,0.9)',
          borderColor: '#fff',
          borderWidth: 1.5,
          pointRadius: 7,
          pointHoverRadius: 9,
          pointStyle: 'rectRot',
          order: 1,
        });
      }
    }

    nc.chartInst = new Chart(ctx, {
      type: 'line',
      data: { labels, datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: { duration: 300 },
        interaction: { mode: 'index', intersect: false },
        scales: {
          x: {
            type: 'category',
            ticks: {
              maxTicksLimit: 8,
              color: 'var(--text-muted)',
              font: { size: 10 },
              callback(val, idx) {
                const lbl = this.getLabelForValue(val);
                return lbl ? lbl.slice(0, 7) : '';
              }
            },
            grid: { color: 'rgba(0,0,0,0.04)', drawTicks: false },
          },
          y: {
            position: 'right',
            ticks: {
              color: 'var(--text-muted)',
              font: { size: 10 },
              callback: v => '₹' + Number(v).toFixed(v >= 100 ? 2 : 4),
            },
            grid: { color: 'rgba(0,0,0,0.05)' },
          }
        },
        plugins: {
          legend: {
            display: true, position: 'top', align: 'start',
            labels: { color: 'var(--text-muted)', font: { size: 11 }, boxWidth: 12, padding: 14 }
          },
          tooltip: {
            backgroundColor: 'var(--bg-card, #fff)',
            borderColor: 'var(--border)',
            borderWidth: 1,
            titleColor: 'var(--text-primary)',
            bodyColor: 'var(--text-muted)',
            padding: 10,
            callbacks: {
              title: items => items[0]?.label || '',
              label: item => {
                if (item.dataset.label === 'NAV') return ` NAV: ₹${Number(item.raw).toFixed(4)}`;
                const typeMap = { 'Self Contrib': 'SELF', 'Employer Contrib': 'EMPLOYER' };
                if (typeMap[item.dataset.label]) {
                  const raw = item.raw?.raw?.txn;
                  return raw
                    ? ` ${typeMap[item.dataset.label]}: ${fmtNum(raw.units,4)} units @ ₹${fmtNum(raw.nav,4)} = ${fmtInr(raw.amount)}`
                    : ` ${item.dataset.label}`;
                }
                return ` ${item.dataset.label}: ₹${Number(item.raw).toFixed(4)}`;
              }
            }
          }
        }
      }
    });

    this._ncShowSpinner(false);
  },

  _ncNearestNav(dateStr) {
    const nc = NPS._nc;
    if (!nc.navData.length) return null;
    const exact = nc.navData.find(d => d.date === dateStr);
    if (exact) return exact.nav;
    const target = new Date(dateStr).getTime();
    let best = null, bestDiff = Infinity;
    for (const d of nc.navData) {
      const diff = Math.abs(new Date(d.date).getTime() - target);
      if (diff < bestDiff) { bestDiff = diff; best = d.nav; }
    }
    return best;
  },

  _ncRenderTxnPills() {
    const nc  = NPS._nc;
    const el  = document.getElementById('ncTxnList');
    if (!el) return;

    if (!nc.txns.length) {
      el.innerHTML = '<span style="color:var(--text-muted);font-size:12px;">No contributions found</span>';
      return;
    }

    const sorted = [...nc.txns].sort((a, b) => a.txn_date > b.txn_date ? -1 : 1).slice(0, 20);
    el.innerHTML = sorted.map(t => {
      const isSelf = t.contribution_type !== 'EMPLOYER';
      const style  = isSelf
        ? 'background:#dcfce7;color:#15803d;border-color:#86efac;'
        : 'background:#dbeafe;color:#1d4ed8;border-color:#93c5fd;';
      return `<div title="${t.contribution_type}: ${fmtNum(t.units,4)} units @ ₹${fmtNum(t.nav,4)} = ${fmtInr(t.amount)}"
        style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:99px;font-size:10px;font-weight:700;border:1px solid;${style}white-space:nowrap;cursor:default;">
        <span>${isSelf ? '👤' : '🏢'} ${t.contribution_type}</span>
        <span style="font-weight:500;opacity:.8;">${(t.txn_date||'').slice(0,10)}</span>
        <span>${fmtInr(t.amount)}</span>
      </div>`;
    }).join('') + (nc.txns.length > 20 ? `<span style="color:var(--text-muted);font-size:10px;padding:4px;">+${nc.txns.length - 20} more…</span>` : '');
  },

  async setChartRange(range, btn) {
    const nc = NPS._nc;
    nc.range = range;
    document.querySelectorAll('.nc-range-btn').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    await this._ncFetchAndRender();
  },

  toggleChartTxns() {
    NPS._nc.showTxns = document.getElementById('ncShowTxns')?.checked !== false;
    if (NPS._nc.navData.length) NPS._ncRenderChart();
  },

  _ncShowSpinner(show) {
    const el = document.getElementById('ncChartSpinner');
    if (el) el.style.display = show ? 'flex' : 'none';
  },


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

  /* Pre-select scheme + tier from the holdings row "Add" button */
  openAddModalFor(schemeId, tier) {
    this.openAddModal();
    // Set tier first so filterSchemes() shows correct options
    const tierSel = document.getElementById('npsTier');
    if (tierSel) { tierSel.value = tier; this.filterSchemes(); }
    // Then select the scheme
    const schemeSel = document.getElementById('npsScheme');
    if (schemeSel) {
      schemeSel.value = schemeId;
      this.onSchemeChange();
    }
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
      portfolio_id:      (window.NPS_PORTFOLIO_ID || ''),
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