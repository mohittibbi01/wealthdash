/**
 * WealthDash — MF Compare Tool JS
 * Task: tv12 — Side-by-side comparison of up to 5 mutual funds
 * File: public/js/mf_compare.js
 */

/* ═══════════════════════════════════════════════════════════════
   STATE
═══════════════════════════════════════════════════════════════ */
const MfCompare = {
  _funds: [],          // [{id, name, house}] — selected for comparison
  _data:  [],          // enriched fund objects from API
  _chart: null,        // Chart.js instance
  _chartPeriod: '3y',
  _searchTimer: null,
  MAX: 5,
  MIN: 2,

  // Palette for up to 5 funds
  COLORS: ['#3b82f6','#16a34a','#f59e0b','#9333ea','#ef4444'],

  /* ─────────────────────────────────────── init ── */
  init() {
    this._bindSearch();
    this._bindButtons();
    this._bindPeriodTabs();
    document.addEventListener('click', e => {
      if (!e.target.closest('#cmpFundSearch') && !e.target.closest('#cmpSearchDd')) {
        document.getElementById('cmpSearchDd').style.display = 'none';
      }
    });
  },

  /* ─────────────────────────────────── binding ── */
  _bindButtons() {
    document.getElementById('btnRunCompare')
      ?.addEventListener('click', () => this.runCompare());

    document.getElementById('btnCmpClear')
      ?.addEventListener('click', () => this.clearAll());

    document.getElementById('btnCmpLoadLast')
      ?.addEventListener('click', () => this.loadLastComparison());

    document.getElementById('btnCmpExportCsv')
      ?.addEventListener('click', () => this.exportCsv());
  },

  _bindPeriodTabs() {
    document.querySelectorAll('.period-tab').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.period-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        this._chartPeriod = btn.dataset.period;
        this.loadNavChart();
      });
    });
  },

  _bindSearch() {
    const inp = document.getElementById('cmpFundSearch');
    const dd  = document.getElementById('cmpSearchDd');
    if (!inp) return;

    inp.addEventListener('input', () => {
      clearTimeout(this._searchTimer);
      const q = inp.value.trim();
      if (q.length < 2) { dd.style.display = 'none'; return; }
      this._searchTimer = setTimeout(() => this._searchFunds(q), 280);
    });

    inp.addEventListener('focus', () => {
      if (inp.value.trim().length >= 2) dd.style.display = 'block';
    });
  },

  async _searchFunds(q) {
    const dd   = document.getElementById('cmpSearchDd');
    const base = window._CMP_BASE || '';
    dd.innerHTML = `<div class="dd-item"><span class="dd-name" style="color:var(--text-muted);">Searching…</span></div>`;
    dd.style.display = 'block';

    try {
      const res  = await fetch(`${base}/api/mutual_funds/mf_search.php?q=${encodeURIComponent(q)}&limit=12`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await res.json();
      const list = data.data ?? data.funds ?? data.results ?? [];

      if (!list.length) {
        dd.innerHTML = `<div class="dd-item"><span class="dd-name" style="color:var(--text-muted);">No results for "${q}"</span></div>`;
        return;
      }

      dd.innerHTML = list.map(f => {
        const id      = f.id ?? f.fund_id;
        const name    = f.scheme_name ?? f.name ?? '';
        const house   = f.fund_house ?? f.amc ?? '';
        const cat     = f.scheme_category ?? f.category ?? '';
        const nav     = f.nav ?? f.latest_nav;
        const already = this._funds.some(x => x.id === id);
        const full    = this._funds.length >= this.MAX;
        const disable = already || full;
        return `
          <div class="dd-item${disable ? ' opacity-50' : ''}"
               style="${disable ? 'pointer-events:none;opacity:.45;' : 'cursor:pointer;'}"
               onclick="MfCompare.addFund(${id},'${name.replace(/'/g,"\\'")}','${house.replace(/'/g,"\\'")}')">
            <div class="dd-name">${name.length > 55 ? name.slice(0,54)+'…' : name}
              ${already ? '<span style="margin-left:6px;font-size:10px;color:#16a34a;">✓ Added</span>' : ''}
            </div>
            <div class="dd-meta">${house}${cat ? ' · '+cat : ''}${nav ? ' · NAV ₹'+Number(nav).toFixed(2) : ''}</div>
          </div>`;
      }).join('');
      dd.style.display = 'block';
    } catch (e) {
      dd.innerHTML = `<div class="dd-item"><span style="color:var(--danger);">Search failed</span></div>`;
    }
  },

  /* ──────────────────────────────── add/remove ── */
  addFund(id, name, house) {
    if (this._funds.length >= this.MAX) return;
    if (this._funds.some(f => f.id === id)) return;
    this._funds.push({ id, name, house });
    this._renderPills();
    document.getElementById('cmpSearchDd').style.display = 'none';
    document.getElementById('cmpFundSearch').value = '';
    document.getElementById('cmpFundSearch').focus();
  },

  removeFund(id) {
    this._funds = this._funds.filter(f => f.id !== id);
    this._renderPills();
    if (this._data.length) this.runCompare();
  },

  clearAll() {
    this._funds = [];
    this._data  = [];
    this._renderPills();
    document.getElementById('cmpResults').style.display = 'none';
    document.getElementById('cmpEmpty').style.display   = 'block';
    document.getElementById('btnCmpExportCsv').style.display = 'none';
    if (this._chart) { this._chart.destroy(); this._chart = null; }
  },

  _renderPills() {
    const wrap = document.getElementById('cmpPills');
    const btn  = document.getElementById('btnRunCompare');
    const cnt  = document.getElementById('cmpFundCount');

    if (!this._funds.length) {
      wrap.innerHTML = `<span style="font-size:12px;color:var(--text-muted);">No funds selected yet…</span>`;
    } else {
      wrap.innerHTML = this._funds.map((f, i) =>
        `<div class="cmp-pill" style="border-color:${this.COLORS[i]}33;color:${this.COLORS[i]};background:${this.COLORS[i]}18;">
          <span>${f.name.length > 32 ? f.name.slice(0,31)+'…' : f.name}</span>
          <button onclick="MfCompare.removeFund(${f.id})" title="Remove">✕</button>
        </div>`
      ).join('');
      if (this._funds.length < this.MAX) {
        wrap.innerHTML += `<button class="cmp-add-pill" onclick="document.getElementById('cmpFundSearch').focus()">+ Add fund</button>`;
      }
    }

    const n   = this._funds.length;
    btn.disabled = n < this.MIN;
    cnt.textContent = n ? `${n} of ${this.MAX} funds selected` : '';
  },

  /* ──────────────────────────────── compare ── */
  async runCompare() {
    if (this._funds.length < this.MIN) return;

    const btn     = document.getElementById('btnRunCompare');
    const label   = document.getElementById('btnRunLabel');
    const spinner = document.getElementById('btnRunSpinner');
    btn.disabled  = true;
    label.textContent = 'Loading…';
    spinner.style.display = 'inline-block';

    const base = window._CMP_BASE || '';
    const ids  = this._funds.map(f => f.id).join(',');

    try {
      const res  = await fetch(`${base}/api/mutual_funds/mf_compare.php?action=mf_compare_detail&fund_ids=${ids}`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const json = await res.json();
      if (!json.success) throw new Error(json.msg || 'API error');

      this._data = json.data;

      document.getElementById('cmpEmpty').style.display   = 'none';
      document.getElementById('cmpResults').style.display = 'block';
      document.getElementById('btnCmpExportCsv').style.display = '';

      this._renderTable();
      this._renderSipCards();
      await this.loadNavChart();

      // Auto-save
      this._saveComparison(ids);
    } catch (e) {
      if (typeof showToast === 'function') showToast('Compare failed: ' + e.message, 'error');
    } finally {
      btn.disabled  = false;
      label.textContent = 'Compare Funds';
      spinner.style.display = 'none';
    }
  },

  /* ─────────────────────────────── NAV chart ── */
  async loadNavChart() {
    const base = window._CMP_BASE || '';
    const ids  = this._funds.map(f => f.id).join(',');
    if (!ids) return;

    const wrap = document.getElementById('cmpChartWrap');
    wrap.innerHTML = `<div class="cmp-loading"><div class="spinner"></div></div>`;

    try {
      const res  = await fetch(
        `${base}/api/mutual_funds/mf_compare.php?action=mf_compare_nav_chart&fund_ids=${ids}&period=${this._chartPeriod}`,
        { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
      );
      const json = await res.json();
      if (!json.success || !json.data?.length) {
        wrap.innerHTML = `<div style="text-align:center;padding:40px;color:var(--text-muted);">NAV history not available for selected period</div>`;
        return;
      }

      wrap.innerHTML = `<canvas id="cmpChart" style="max-height:300px;"></canvas>`;
      const ctx = document.getElementById('cmpChart').getContext('2d');

      if (this._chart) { this._chart.destroy(); this._chart = null; }

      const datasets = json.data.map((series, i) => ({
        label:           series.fund_name.length > 40 ? series.fund_name.slice(0,39)+'…' : series.fund_name,
        data:            series.series.map(p => ({ x: p.date, y: p.value })),
        borderColor:     this.COLORS[i] ?? '#999',
        backgroundColor: (this.COLORS[i] ?? '#999') + '18',
        borderWidth:     2,
        pointRadius:     0,
        pointHoverRadius:4,
        tension:         0.3,
        fill:            false,
      }));

      this._chart = new Chart(ctx, {
        type: 'line',
        data: { datasets },
        options: {
          responsive: true,
          maintainAspectRatio: true,
          interaction: { mode: 'index', intersect: false },
          plugins: {
            legend: {
              position: 'bottom',
              labels: {
                font:  { size: 11 },
                color: getComputedStyle(document.documentElement).getPropertyValue('--text-muted') || '#666',
                boxWidth: 12,
                padding:  14,
              }
            },
            tooltip: {
              callbacks: {
                label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y.toFixed(2)}`,
              }
            }
          },
          scales: {
            x: {
              type: 'time',
              time: { unit: this._chartPeriod === '1y' ? 'month' : 'quarter', tooltipFormat: 'dd MMM yyyy' },
              grid:  { display: false },
              ticks: { font: { size: 10 }, color: '#9ca3af', maxTicksLimit: 10 },
            },
            y: {
              grid:  { color: 'rgba(0,0,0,.05)' },
              ticks: {
                font: { size: 10 }, color: '#9ca3af',
                callback: v => v.toFixed(0),
              },
              title: { display: true, text: 'Value of ₹100 invested', font: { size: 10 }, color: '#9ca3af' },
            }
          }
        }
      });
    } catch (e) {
      wrap.innerHTML = `<div style="text-align:center;padding:40px;color:var(--text-muted);">Chart failed to load</div>`;
    }
  },

  /* ─────────────────────────────── SIP cards ── */
  _renderSipCards() {
    const grid = document.getElementById('cmpSipGrid');
    if (!grid) return;
    const best = Math.max(...this._data.map(f => f.sip_sim_value ?? 0));

    grid.innerHTML = this._data.map((f, i) => {
      if (!f.sip_sim_value) return `
        <div style="padding:16px;background:var(--bg-secondary);border-radius:10px;border:1.5px solid var(--border-color);">
          <div style="font-size:11px;font-weight:700;color:${this.COLORS[i]};margin-bottom:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${f.scheme_name.length>36?f.scheme_name.slice(0,35)+'…':f.scheme_name}</div>
          <div style="color:var(--text-muted);font-size:12px;">No 3Y return data</div>
        </div>`;
      const isBest    = f.sip_sim_value === best;
      const gainPct   = ((f.sip_sim_value - f.sip_sim_invested) / f.sip_sim_invested * 100).toFixed(1);
      const gainColor = f.sip_sim_gain >= 0 ? '#16a34a' : '#dc2626';
      return `
        <div style="padding:16px;background:var(--bg-secondary);border-radius:10px;
                    border:1.5px solid ${isBest ? this.COLORS[i] : 'var(--border-color)'};
                    position:relative;overflow:hidden;">
          ${isBest ? `<div style="position:absolute;top:0;right:0;background:${this.COLORS[i]};color:#fff;font-size:10px;font-weight:700;padding:3px 8px;border-radius:0 0 0 8px;">BEST</div>` : ''}
          <div style="font-size:11px;font-weight:700;color:${this.COLORS[i]};margin-bottom:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding-right:${isBest?40:0}px;" title="${f.scheme_name}">${f.scheme_name.length>36?f.scheme_name.slice(0,35)+'…':f.scheme_name}</div>
          <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
            <span style="font-size:11px;color:var(--text-muted);">Invested</span>
            <span style="font-size:12px;font-weight:600;">₹${this._inr(f.sip_sim_invested)}</span>
          </div>
          <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
            <span style="font-size:11px;color:var(--text-muted);">Value</span>
            <span style="font-size:13px;font-weight:800;color:${this.COLORS[i]};">₹${this._inr(f.sip_sim_value)}</span>
          </div>
          <div style="display:flex;justify-content:space-between;">
            <span style="font-size:11px;color:var(--text-muted);">Gain</span>
            <span style="font-size:12px;font-weight:700;color:${gainColor};">+₹${this._inr(f.sip_sim_gain)} (${gainPct}%)</span>
          </div>
        </div>`;
    }).join('');
  },

  /* ────────────────────────────── main table ── */
  _renderTable() {
    const wrap = document.getElementById('cmpTableWrap');
    const data = this._data;
    if (!data.length) return;

    const RISK_FREE = 6.5;
    const mktRet    = data.filter(f => !(f.category ?? '').toLowerCase().includes('debt'))
                         .reduce((s, f, _, a) => s + (parseFloat(f.returns_3y) || 12) / a.length, 12);

    const fmtNav = v => v ? '₹' + Number(v).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 4 }) : '—';
    const fmtPct = (v, all, higherBetter = true) => {
      if (v === null || v === undefined) return '<span style="color:var(--text-muted);">—</span>';
      const valid = all.filter(x => x !== null && x !== undefined).map(Number);
      const best  = higherBetter ? Math.max(...valid) : Math.min(...valid);
      const worst = higherBetter ? Math.min(...valid) : Math.max(...valid);
      const isBest  = valid.length > 1 && Number(v) === best;
      const isWorst = valid.length > 1 && Number(v) === worst;
      const sign  = Number(v) > 0 ? '+' : '';
      const txt   = `${sign}${Number(v).toFixed(2)}%`;
      if (isBest)  return `<span class="cmp-best">${txt}</span>`;
      if (isWorst) return `<span class="cmp-worst">${txt}</span>`;
      const color = Number(v) >= 12 ? '#15803d' : Number(v) >= 8 ? '#16a34a' : Number(v) >= 0 ? '#d97706' : '#dc2626';
      return `<span style="font-weight:700;color:${color};">${txt}</span>`;
    };
    const fmtRatio = (v, all) => {
      if (v === null || v === undefined) return '—';
      const valid = all.filter(x => x !== null && x !== undefined).map(Number);
      const best  = Math.max(...valid);
      const isBest = valid.length > 1 && Number(v) === best;
      const color = v >= 1.5 ? '#15803d' : v >= 1 ? '#16a34a' : v >= 0.5 ? '#d97706' : '#dc2626';
      const txt   = Number(v).toFixed(3);
      return isBest ? `<span class="cmp-best">${txt}</span>` : `<span style="color:${color};font-weight:700;">${txt}</span>`;
    };
    const fmtExp = (v, all) => {
      if (v === null || v === undefined) return '—';
      const valid = all.filter(x => x !== null && x !== undefined).map(Number);
      const best  = Math.min(...valid);
      const isBest = valid.length > 1 && Number(v) === best;
      return isBest
        ? `<span class="cmp-best">${Number(v).toFixed(2)}%</span>`
        : `${Number(v).toFixed(2)}%`;
    };
    const calcAlpha = f => {
      const r = parseFloat(f.returns_3y) || 0;
      const b = mktRet > 0 ? (r / mktRet).toFixed(2) : 1;
      const a = (r - (RISK_FREE + parseFloat(b) * (mktRet - RISK_FREE))).toFixed(2);
      return { alpha: parseFloat(a), beta: parseFloat(b) };
    };
    const consistency = f => {
      const vals = [f.returns_1y, f.returns_3y, f.returns_5y].filter(v => v !== null && v !== undefined).map(Number);
      if (vals.length < 2) return null;
      const mn = Math.min(...vals), mx = Math.max(...vals);
      return mx > 0 ? Math.round(mn / mx * 100) : null;
    };
    const scoreBar = (val, max = 100) => {
      const pct = Math.min(100, Math.max(0, (val / max) * 100));
      const col = pct >= 70 ? '#16a34a' : pct >= 40 ? '#d97706' : '#dc2626';
      return `<div class="score-bar"><div class="score-bar-track"><div class="score-bar-fill" style="width:${pct}%;background:${col};"></div></div><span>${val}%</span></div>`;
    };

    const sections = [
      {
        title: '📋 Basic Info',
        rows: [
          { label: 'Fund House',   vals: data.map(f => f.fund_house ?? '—') },
          { label: 'Category',     vals: data.map(f => f.category_short ?? f.category ?? '—') },
          { label: 'Plan',         vals: data.map(f => f.plan_type === 'direct'
              ? '<span style="color:#16a34a;font-weight:700;">✅ Direct</span>'
              : '<span style="color:#d97706;">Regular</span>') },
          { label: 'Option',       vals: data.map(f => f.option_type === 'idcw'
              ? '<span style="color:#9333ea;">IDCW</span>'
              : '<span style="color:#3b82f6;font-weight:600;">Growth</span>') },
          { label: 'Fund Manager', vals: data.map(f => f.fund_manager ?? '—') },
          { label: 'Risk Level',   vals: data.map(f => {
              const r = (f.risk_level ?? '').toLowerCase();
              const col = r.includes('very high') ? '#9f1239' : r.includes('high') ? '#dc2626' : r.includes('mod') ? '#d97706' : '#16a34a';
              return `<span style="color:${col};font-weight:700;">${f.risk_level ?? '—'}</span>`;
            }) },
          { label: 'Fund Age',     vals: data.map(f => f.fund_age_years ? f.fund_age_years + ' yrs' : '—') },
          { label: 'Inception',    vals: data.map(f => f.inception_date ?? '—') },
        ]
      },
      {
        title: '📈 Returns',
        rows: [
          { label: '1Y Return',    vals: data.map(f => fmtPct(f.returns_1y, data.map(x => x.returns_1y))) },
          { label: 'vs Cat (1Y)',  vals: data.map(f => {
              if (f.returns_1y == null || f.category_avg_1y == null) return '—';
              const d = Number((f.returns_1y - f.category_avg_1y).toFixed(2));
              const c = d > 2 ? '#15803d' : d >= 0 ? '#16a34a' : d > -2 ? '#d97706' : '#dc2626';
              return `<span style="color:${c};font-weight:700;">${d >= 0 ? '▲' : '▼'}${Math.abs(d).toFixed(2)}%</span>`;
            }) },
          { label: '3Y CAGR',      vals: data.map(f => fmtPct(f.returns_3y, data.map(x => x.returns_3y))) },
          { label: '5Y CAGR',      vals: data.map(f => fmtPct(f.returns_5y, data.map(x => x.returns_5y))) },
          { label: 'Since Launch', vals: data.map(f => fmtPct(f.returns_since_inception, data.map(x => x.returns_since_inception))) },
          { label: 'Consistency',  vals: data.map(f => { const c = consistency(f); return c == null ? '—' : scoreBar(c); }) },
        ]
      },
      {
        title: '⚠️ Risk Metrics',
        rows: [
          { label: 'Latest NAV',   vals: data.map(f => fmtNav(f.latest_nav)) },
          { label: 'Peak NAV',     vals: data.map(f => fmtNav(f.peak_nav)) },
          { label: 'Drawdown',     vals: data.map(f => {
              if (f.drawdown_pct == null) return '—';
              const c = f.drawdown_pct > 20 ? '#dc2626' : f.drawdown_pct > 10 ? '#d97706' : '#16a34a';
              return `<span style="color:${c};font-weight:700;">▼${f.drawdown_pct}%</span>`;
            }) },
          { label: 'Sharpe Ratio', vals: data.map(f => fmtRatio(f.sharpe_ratio, data.map(x => x.sharpe_ratio))) },
          { label: 'Sortino Ratio',vals: data.map(f => fmtRatio(f.sortino_ratio, data.map(x => x.sortino_ratio))) },
          { label: 'Alpha (α)',    vals: data.map(f => {
              const { alpha } = calcAlpha(f);
              return alpha >= 3
                ? `<span style="color:#15803d;font-weight:800;">+${alpha}% 🌟</span>`
                : alpha >= 0
                ? `<span style="color:#16a34a;">+${alpha}%</span>`
                : `<span style="color:#dc2626;">${alpha}%</span>`;
            }) },
          { label: 'Beta (β)',     vals: data.map(f => {
              const { beta } = calcAlpha(f);
              const c = beta < 0.8 ? '#3b82f6' : beta < 1.2 ? '#d97706' : '#dc2626';
              return `<span style="color:${c};">β ${beta}</span>`;
            }) },
        ]
      },
      {
        title: '💰 Cost & Structure',
        rows: [
          { label: 'Expense Ratio',vals: data.map(f => fmtExp(f.expense_ratio, data.map(x => x.expense_ratio))) },
          { label: 'Exit Load',    vals: data.map(f => f.exit_load_pct > 0
              ? `⚠ ${f.exit_load_pct}% / ${f.exit_load_days}d`
              : f.exit_load_pct === 0 ? '✓ Nil' : '—') },
          { label: 'Lock-in',      vals: data.map(f => f.lock_in_days > 0
              ? (f.lock_in_days === 1095 ? '3yr (ELSS)' : f.lock_in_days + 'd')
              : 'None') },
          { label: 'LTCG Period',  vals: data.map(f => {
              const d = f.min_ltcg_days;
              return d === 365 ? '1 Year' : d === 730 ? '2 Years' : d === 1095 ? '3 Years' : (d ?? '—') + ' days';
            }) },
          { label: 'AUM',          vals: data.map(f => f.aum_crore
              ? '₹' + Number(f.aum_crore).toLocaleString('en-IN', { maximumFractionDigits: 0 }) + ' Cr'
              : '—') },
          { label: 'Min SIP',      vals: data.map(f => f.min_sip_amount ? '₹' + f.min_sip_amount : '—') },
          { label: 'Min Lumpsum',  vals: data.map(f => f.min_lumpsum ? '₹' + f.min_lumpsum : '—') },
          { label: 'Benchmark',    vals: data.map(f => `<span title="${f.benchmark ?? ''}" style="font-size:11px;">${(f.benchmark ?? '—').length > 30 ? (f.benchmark ?? '').slice(0,29)+'…' : (f.benchmark ?? '—')}</span>`) },
        ]
      },
      {
        title: '📊 Portfolio',
        rows: [
          { label: 'In Watchlist', vals: data.map(f => f.in_watchlist ? '🔖 Yes' : '—') },
          { label: 'Units Held',   vals: data.map(f => f.units_held != null
              ? `<span style="color:#3b82f6;font-weight:700;">${Number(f.units_held).toFixed(3)} ✓</span>`
              : '—') },
        ]
      }
    ];

    // Build table HTML
    const colCount = data.length;
    const thHTML = data.map((f, i) => `
      <th>
        <div class="cmp-fund-header">
          <div class="cmp-fund-hname" style="max-width:160px;" title="${f.scheme_name}">
            ${f.scheme_name.length > 42 ? f.scheme_name.slice(0,41)+'…' : f.scheme_name}
          </div>
          <div class="cmp-fund-hhouse">${f.fund_house ?? ''}</div>
          <div style="margin-top:4px;display:flex;gap:4px;flex-wrap:wrap;">
            <span class="cmp-fund-hbadge" style="background:${this.COLORS[i]}22;color:${this.COLORS[i]};">
              <span style="width:8px;height:8px;border-radius:50%;background:${this.COLORS[i]};display:inline-block;"></span>
              Fund ${i + 1}
            </span>
            ${f.plan_type === 'direct'
              ? '<span class="cmp-fund-hbadge" style="background:#f0fdf4;color:#16a34a;">Direct</span>'
              : '<span class="cmp-fund-hbadge" style="background:#fffbeb;color:#d97706;">Regular</span>'}
          </div>
        </div>
      </th>`).join('');

    const rowsHTML = sections.map(sec => `
      <tr class="section-row">
        <td colspan="${colCount + 1}">${sec.title}</td>
      </tr>
      ${sec.rows.map(row => `
        <tr>
          <td>${row.label}</td>
          ${row.vals.map(v => `<td>${v}</td>`).join('')}
        </tr>`).join('')}
    `).join('');

    wrap.innerHTML = `
      <div style="overflow-x:auto;">
        <table class="cmp-table">
          <thead>
            <tr>
              <th>Parameter</th>
              ${thHTML}
            </tr>
          </thead>
          <tbody>${rowsHTML}</tbody>
        </table>
      </div>
      <div style="padding:10px 14px;font-size:11px;color:var(--text-muted);background:var(--bg-secondary);border-top:1px solid var(--border-color);">
        <strong style="color:var(--text-primary);">🏆 Best</strong> value highlighted green &nbsp;·&nbsp;
        <strong style="color:#dc2626;">Worst</strong> highlighted red &nbsp;·&nbsp;
        Alpha/Beta are proxies based on 3Y CAGR &nbsp;·&nbsp; Not financial advice
      </div>`;
  },

  /* ──────────────────────────────── save/load ── */
  async _saveComparison(ids) {
    const base = window._CMP_BASE || '';
    try {
      await fetch(`${base}/api/router.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ action: 'mf_compare_save', fund_ids: ids })
      });
    } catch (_) {}
  },

  async loadLastComparison() {
    const base = window._CMP_BASE || '';
    try {
      const res  = await fetch(`${base}/api/mutual_funds/mf_compare.php?action=mf_compare_load`, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      const json = await res.json();
      if (!json.success || !json.fund_ids?.length) {
        if (typeof showToast === 'function') showToast('No saved comparison found', 'info');
        return;
      }
      // Fetch names
      const detRes  = await fetch(
        `${base}/api/mutual_funds/mf_compare.php?action=mf_compare_detail&fund_ids=${json.fund_ids.join(',')}`,
        { headers: { 'X-Requested-With': 'XMLHttpRequest' } }
      );
      const detJson = await detRes.json();
      if (!detJson.success) throw new Error(detJson.msg);

      this._funds = detJson.data.map(f => ({ id: f.id, name: f.scheme_name, house: f.fund_house ?? '' }));
      this._renderPills();
      this._data = detJson.data;
      document.getElementById('cmpEmpty').style.display   = 'none';
      document.getElementById('cmpResults').style.display = 'block';
      document.getElementById('btnCmpExportCsv').style.display = '';
      this._renderTable();
      this._renderSipCards();
      await this.loadNavChart();
      if (typeof showToast === 'function') showToast('Last comparison loaded', 'success');
    } catch (e) {
      if (typeof showToast === 'function') showToast('Load failed: ' + e.message, 'error');
    }
  },

  /* ──────────────────────────────── export ── */
  exportCsv() {
    if (!this._data.length) return;
    const RISK_FREE = 6.5;
    const mktRet = this._data.filter(f => !(f.category ?? '').toLowerCase().includes('debt'))
                              .reduce((s, f, _, a) => s + (parseFloat(f.returns_3y) || 12) / a.length, 12);
    const calcAlpha = f => {
      const r = parseFloat(f.returns_3y) || 0;
      const b = mktRet > 0 ? (r / mktRet).toFixed(2) : 1;
      return { alpha: parseFloat((r - (RISK_FREE + parseFloat(b) * (mktRet - RISK_FREE))).toFixed(2)), beta: parseFloat(b) };
    };

    const headers = ['Parameter', ...this._data.map(f => f.scheme_name.replace(/,/g,''))];
    const rows = [
      ['Fund House',        ...this._data.map(f => f.fund_house ?? '—')],
      ['Category',          ...this._data.map(f => f.category ?? '—')],
      ['Plan Type',         ...this._data.map(f => f.plan_type === 'direct' ? 'Direct' : 'Regular')],
      ['Fund Manager',      ...this._data.map(f => f.fund_manager ?? '—')],
      ['Risk Level',        ...this._data.map(f => f.risk_level ?? '—')],
      ['Latest NAV (₹)',    ...this._data.map(f => f.latest_nav ?? '—')],
      ['Peak NAV (₹)',      ...this._data.map(f => f.peak_nav ?? '—')],
      ['Drawdown (%)',      ...this._data.map(f => f.drawdown_pct ?? '—')],
      ['1Y Return (%)',     ...this._data.map(f => f.returns_1y ?? '—')],
      ['3Y CAGR (%)',       ...this._data.map(f => f.returns_3y ?? '—')],
      ['5Y CAGR (%)',       ...this._data.map(f => f.returns_5y ?? '—')],
      ['Since Inception (%)',...this._data.map(f => f.returns_since_inception ?? '—')],
      ['Sharpe Ratio',      ...this._data.map(f => f.sharpe_ratio ?? '—')],
      ['Sortino Ratio',     ...this._data.map(f => f.sortino_ratio ?? '—')],
      ['Alpha (%)',         ...this._data.map(f => calcAlpha(f).alpha)],
      ['Beta',              ...this._data.map(f => calcAlpha(f).beta)],
      ['Expense Ratio (%)', ...this._data.map(f => f.expense_ratio ?? '—')],
      ['Exit Load',         ...this._data.map(f => f.exit_load_pct > 0 ? `${f.exit_load_pct}%/${f.exit_load_days}d` : 'Nil')],
      ['AUM (Cr)',          ...this._data.map(f => f.aum_crore ?? '—')],
      ['Min SIP (₹)',       ...this._data.map(f => f.min_sip_amount ?? '—')],
      ['Lock-in',           ...this._data.map(f => f.lock_in_days > 0 ? f.lock_in_days + 'd' : 'None')],
      ['LTCG Period',       ...this._data.map(f => f.min_ltcg_days === 365 ? '1 Year' : f.min_ltcg_days === 1095 ? '3 Years' : '—')],
      ['Inception Date',    ...this._data.map(f => f.inception_date ?? '—')],
      ['Fund Age (yrs)',    ...this._data.map(f => f.fund_age_years ?? '—')],
      ['SIP Value (3yr)',   ...this._data.map(f => f.sip_sim_value ?? '—')],
      ['SIP Gain',          ...this._data.map(f => f.sip_sim_gain ?? '—')],
    ];
    const csv  = [headers, ...rows].map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
    const a    = document.createElement('a');
    a.href     = URL.createObjectURL(blob);
    a.download = `WealthDash_MF_Compare_${new Date().toISOString().slice(0,10)}.csv`;
    a.click(); URL.revokeObjectURL(a.href);
  },

  /* ─────────────────────────────── helpers ── */
  _inr(v) {
    if (!v && v !== 0) return '—';
    return Number(v).toLocaleString('en-IN', { maximumFractionDigits: 0 });
  },
};

window.MfCompare = MfCompare;
