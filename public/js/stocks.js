/**
 * WealthDash — Stocks & ETF Module JS (stocks.js)
 * Handles: Holdings table, Transaction CRUD, Stock search autocomplete, Price refresh
 */

const STOCKS = {
  gainFilter:      '',
  portfolioFilter: '',
  search:          '',
  sectorFilter:    '',
  sortCol:         '',
  sortDir:         'desc',
  txnTypeFilter:   '',
  txnFyFilter:     '',
  txnSearch:       '',
  searchTimer:     null,
  pendingDeleteId: null,
  // t20: Pagination
  page: 1, perPage: 10, _allRows: [],
  _renderPag(total, pages, startIdx) {
    const wrap = document.getElementById('stocksPagWrap');
    if (!wrap) return;
    if (pages <= 1 && total <= 10) { wrap.innerHTML = ''; return; }
    wrap.innerHTML = `<div style="display:flex;align-items:center;gap:8px;padding:10px 0;flex-wrap:wrap;">
      <select onchange="STOCKS.setPerPage(+this.value)" style="padding:4px 8px;border-radius:6px;border:1px solid var(--border);background:var(--bg-secondary);color:var(--text-primary);font-size:12px;">
        ${[10,25,50,999].map(n=>`<option value="${n}" ${n===this.perPage?'selected':''}>${n===999?'All':n}</option>`).join('')}
      </select>
      <span style="font-size:12px;color:var(--text-muted);">${Math.min(startIdx+1,total)}–${Math.min(startIdx+this.perPage,total)} of ${total}</span>
      <div style="display:flex;gap:4px;margin-left:auto;">
        <button onclick="STOCKS.goPage(${this.page-1})" ${this.page<=1?'disabled':''} class="btn btn-ghost btn-sm">‹</button>
        ${Array.from({length:Math.min(5,pages)},(_,i)=>{const p=Math.max(1,Math.min(this.page-2,pages-4))+i;return `<button onclick="STOCKS.goPage(${p})" class="btn btn-sm ${p===this.page?'btn-primary':'btn-ghost'}">${p}</button>`;}).join('')}
        <button onclick="STOCKS.goPage(${this.page+1})" ${this.page>=pages?'disabled':''} class="btn btn-ghost btn-sm">›</button>
      </div>
    </div>`;
  },
  goPage(p)     { this.page=p; this.loadHoldings(); },
  setPerPage(n) { this.perPage=n; this.page=1; this.loadHoldings(); },
  sortBy(col) {
    if (this.sortCol === col) { this.sortDir = this.sortDir==='desc'?'asc':'desc'; }
    else { this.sortCol=col; this.sortDir='desc'; }
    document.getElementById('sortGainArr').textContent = this.sortCol==='gain'?(this.sortDir==='desc'?'↓':'↑'):'↕';
    document.getElementById('sortCagrArr').textContent = this.sortCol==='cagr'?(this.sortDir==='desc'?'↓':'↑'):'↕';
    this.loadHoldings();
  },

  init() {
    // Holdings filters
    document.querySelectorAll('.view-btn[data-gain]').forEach(btn => {
      btn.addEventListener('click', () => {
        document.querySelectorAll('.view-btn[data-gain]').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        STOCKS.gainFilter = btn.dataset.gain;
        STOCKS.loadHoldings();
      });
    });
    document.getElementById('filterPortfolio').addEventListener('change', e => { STOCKS.portfolioFilter = e.target.value; STOCKS.loadHoldings(); });
    document.getElementById('searchStock').addEventListener('input', e => { STOCKS.search = e.target.value.toLowerCase(); STOCKS.loadHoldings(); });

    // Transaction filters
    document.getElementById('txnFilterType').addEventListener('change', () => STOCKS.loadTransactions());
    document.getElementById('txnFilterFy').addEventListener('change', () => STOCKS.loadTransactions());
    document.getElementById('txnSearchSymbol').addEventListener('input', () => STOCKS.loadTransactions());

    // Add modal
    document.getElementById('btnAddStock').addEventListener('click', () => STOCKS.openAddModal());
    document.getElementById('closeStockModal').addEventListener('click', () => STOCKS.closeAddModal());
    document.getElementById('cancelStock').addEventListener('click', () => STOCKS.closeAddModal());
    document.getElementById('saveStock').addEventListener('click', () => STOCKS.saveTransaction());

    // Price refresh
    document.getElementById('btnRefreshPrices').addEventListener('click', () => STOCKS.refreshPrices());

    // Delete modal
    document.getElementById('closeDelStock').addEventListener('click', () => STOCKS.closeDelModal());
    document.getElementById('cancelDelStock').addEventListener('click', () => STOCKS.closeDelModal());
    document.getElementById('confirmDelStock').addEventListener('click', () => STOCKS.deleteTransaction());

    // Click outside dropdown
    document.addEventListener('click', e => {
      if (!e.target.closest('#stockSearch') && !e.target.closest('#stockDropdown')) {
        document.getElementById('stockDropdown').style.display = 'none';
      }
    });

    this.loadHoldings();
    this.loadTransactions();
    // t433: Auto-load 52-Week tracker
    setTimeout(() => W52.load(), 800);
  },

  /* ── HOLDINGS ── */
  async loadHoldings() {
    const body = document.getElementById('stocksBody');
    if (typeof WdSkel !== 'undefined') WdSkel.table('stocksBody', 7, 7);
    else body.innerHTML = '<tr><td colspan="11" class="text-center" style="padding:40px"><span class="spinner"></span></td></tr>';

    const params = new URLSearchParams({ action: 'stocks_list', type: 'holdings' });
    if (STOCKS.portfolioFilter) params.set('portfolio_id', STOCKS.portfolioFilter);
    if (STOCKS.gainFilter)      params.set('gain_type',    STOCKS.gainFilter);

    try {
      const res  = await fetch(APP_URL + '/api/router.php?' + params);
      const data = await res.json();
      if (!data.success) throw new Error(data.message);

      let rows = data.data || [];
      if (STOCKS.search)       rows = rows.filter(r => r.symbol.toLowerCase().includes(STOCKS.search) || (r.company_name||'').toLowerCase().includes(STOCKS.search));
      if (STOCKS.sectorFilter) rows = rows.filter(r => (r.sector||'') === STOCKS.sectorFilter);
      if (STOCKS.sortCol === 'gain') {
        rows = [...rows].sort((a,b) => STOCKS.sortDir==='desc' ? (parseFloat(b.gain_pct_live)||0)-(parseFloat(a.gain_pct_live)||0) : (parseFloat(a.gain_pct_live)||0)-(parseFloat(b.gain_pct_live)||0));
      } else if (STOCKS.sortCol === 'cagr') {
        rows = [...rows].sort((a,b) => STOCKS.sortDir==='desc' ? (parseFloat(b.cagr)||0)-(parseFloat(a.cagr)||0) : (parseFloat(a.cagr)||0)-(parseFloat(b.cagr)||0));
      }
      // t20: Pagination
      STOCKS._allRows = rows;
      const _total = rows.length;
      const _pages = STOCKS.perPage >= 999 ? 1 : Math.ceil(_total / STOCKS.perPage);
      if (STOCKS.page > _pages) STOCKS.page = 1;
      const _start = (STOCKS.page-1)*(STOCKS.perPage>=999?_total:STOCKS.perPage);
      const paged  = STOCKS.perPage >= 999 ? rows : rows.slice(_start, _start + STOCKS.perPage);
      STOCKS._renderPag(_total, _pages, _start);
      // Populate sector dropdown
      const sectors = [...new Set(data.data.map(r => r.sector).filter(Boolean))].sort();
      const secSel = document.getElementById('filterSector');
      if (secSel && secSel.options.length <= 1) {
        sectors.forEach(s => { const o = document.createElement('option'); o.value=s; o.textContent=s; secSel.appendChild(o); });
      }
      if (!rows.length) {
        body.innerHTML = typeof WdEmpty !== 'undefined'
          ? `<tr><td colspan="11" style="padding:0;border:none;">${WdEmpty.html('stocks')}</td></tr>`
          : '<tr><td colspan="11" class="text-center empty-state" style="padding:60px"><div style="font-size:40px">📈</div><p>No stock holdings yet.<br>Add your first BUY transaction.</p></td></tr>';
        return;
      }
      body.innerHTML = paged.map(h => {
        const gl       = parseFloat(h.gain_loss)     || 0;
        // t36: use gain_pct_live (live price vs avg cost) for accurate gain%
        const glPct    = (h.gain_pct_live != null) ? parseFloat(h.gain_pct_live) : (parseFloat(h.gain_pct) || 0);
        const cagrRaw  = (h.cagr !== null && h.cagr !== undefined && h.cagr !== '') ? parseFloat(h.cagr) : null;
        const yrsHeld  = parseFloat(h.years_held) || 0;
        const glCls    = gl >= 0 ? 'text-success' : 'text-danger';
        const badgeCls = h.gain_type === 'LTCG' ? 'badge-success' : (h.gain_type === 'STCG' ? 'badge-warning' : 'badge-outline');
        // t36: CAGR display — show dashes when < 30d, else colour-coded with years held tooltip
        const cagrHtml = cagrRaw !== null
          ? `<span class="${cagrRaw>=0?'text-success':'text-danger'}" title="${yrsHeld} yr held">${cagrRaw>=0?'+':''}${cagrRaw.toFixed(2)}%<br><small style="font-size:10px;opacity:.7">${yrsHeld}yr</small></span>`
          : `<span style="color:var(--text-muted)" title="Less than 30 days held">—</span>`;
        return `<tr>
          <td>
            <div class="fund-name">${escHtml(h.symbol)}</div>
            <div class="fund-sub">${escHtml(h.company_name||'')}</div>
          </td>
          <td><span class="badge badge-outline">${escHtml(h.exchange||'')}</span></td>
          <td class="text-right">${fmtNum(h.quantity,0)}</td>
          <td class="text-right">₹${fmtNum(h.avg_buy_price,2)}</td>
          <td class="text-right">
            ₹${fmtNum(h.current_price||0,2)}
            ${h.price_change_pct ? `<br><small class="${parseFloat(h.price_change_pct)>=0?'text-success':'text-danger'}">${parseFloat(h.price_change_pct)>=0?'+':''}${parseFloat(h.price_change_pct).toFixed(2)}%</small>` : ''}
          </td>
          <td class="text-right">${fmtInr(h.total_invested)}</td>
          <td class="text-right">${fmtInr(h.current_value)}</td>
          <td class="text-right ${glCls}">${fmtInr(gl)}<br><small>${gl>=0?'+':''}${glPct.toFixed(2)}%</small></td>
          <td class="text-right">${cagrHtml}</td>
          <td><span class="badge ${badgeCls}">${h.gain_type||'—'}</span></td>
          <td class="text-center">
            <button class="btn btn-sm btn-ghost" onclick="STOCKS.sellStock(${h.stock_id},'${escHtml(h.symbol)}')" title="Sell">Sell</button>
          </td>
        </tr>`;
      }).join('');
    } catch(e) {
      body.innerHTML = `<tr><td colspan="11" class="text-center text-danger" style="padding:40px">Error: ${e.message}</td></tr>`;
    }
  },

  /* ── TRANSACTIONS ── */
  async loadTransactions() {
    const body = document.getElementById('stocksTxnBody');
    body.innerHTML = '<tr><td colspan="10" class="text-center" style="padding:40px"><span class="spinner"></span></td></tr>';

    const params = new URLSearchParams({ action: 'stocks_list', type: 'transactions' });
    const sym    = document.getElementById('txnSearchSymbol').value.trim();
    const ftype  = document.getElementById('txnFilterType').value;
    const ffy    = document.getElementById('txnFilterFy').value;
    if (sym)   params.set('symbol',       sym);
    if (ftype) params.set('txn_type',     ftype);
    if (ffy)   params.set('investment_fy', ffy);

    try {
      const res  = await fetch(APP_URL + '/api/router.php?' + params);
      const data = await res.json();
      if (!data.success) throw new Error(data.message);
      const txns = data.data || [];
      if (!txns.length) {
        body.innerHTML = '<tr><td colspan="10" class="text-center" style="padding:40px;color:var(--text-muted)">No transactions found.</td></tr>';
        return;
      }
      const typeColors = { BUY:'badge-success', SELL:'badge-danger', DIV:'badge-info', BONUS:'badge-warning', SPLIT:'badge-outline' };
      body.innerHTML = txns.map(t => `<tr>
        <td>${fmtDate(t.txn_date)}</td>
        <td><strong>${escHtml(t.symbol)}</strong><br><small style="color:var(--text-muted)">${escHtml(t.exchange||'')}</small></td>
        <td><span class="badge ${typeColors[t.txn_type]||'badge-outline'}">${t.txn_type}</span></td>
        <td class="text-right">${fmtNum(t.quantity,0)}</td>
        <td class="text-right">₹${fmtNum(t.price,2)}</td>
        <td class="text-right">${fmtInr(t.brokerage||0)}</td>
        <td class="text-right">${fmtInr(t.stt||0)}</td>
        <td class="text-right">${fmtInr(t.value_at_cost)}</td>
        <td>${t.investment_fy}</td>
        <td class="text-center">
          <button class="btn btn-sm btn-danger-ghost" onclick="STOCKS.confirmDelete(${t.id})" title="Delete">🗑️</button>
        </td>
      </tr>`).join('');
    } catch(e) {
      body.innerHTML = `<tr><td colspan="10" class="text-center text-danger" style="padding:40px">Error: ${e.message}</td></tr>`;
    }
  },

  /* ── STOCK SEARCH AUTOCOMPLETE ── */
  searchStocks() {
    clearTimeout(STOCKS.searchTimer);
    const q = document.getElementById('stockSearch').value.trim();
    if (q.length < 1) { document.getElementById('stockDropdown').style.display='none'; return; }
    STOCKS.searchTimer = setTimeout(async () => {
      try {
        const res  = await fetch(`${APP_URL}/api/router.php?action=stocks_search&q=${encodeURIComponent(q)}`);
        const data = await res.json();
        const dd   = document.getElementById('stockDropdown');
        const items = data.data || [];

        if (!items.length) {
          dd.innerHTML = `<div class="autocomplete-item" onclick="STOCKS.showNewStockForm('${escHtml(q.toUpperCase())}')">
            <span>➕ Add "${escHtml(q.toUpperCase())}" as new stock</span></div>`;
        } else {
          dd.innerHTML = items.map(s => `
            <div class="autocomplete-item" onclick="STOCKS.selectStock(${s.id},'${escHtml(s.symbol)}','${escHtml(s.company_name||s.symbol)}','${escHtml(s.exchange||'')}',${s.latest_price||0})">
              <strong>${escHtml(s.symbol)}</strong> <span style="color:var(--text-muted)">${escHtml(s.exchange||'')} — ${escHtml(s.company_name||'')}</span>
              ${s.latest_price ? `<span style="float:right">₹${fmtNum(s.latest_price,2)}</span>` : ''}
            </div>`).join('') +
            `<div class="autocomplete-item" onclick="STOCKS.showNewStockForm('${escHtml(q.toUpperCase())}')">
              <span style="color:var(--primary)">➕ Add new stock manually</span></div>`;
        }
        dd.style.display = 'block';
      } catch(_) {}
    }, 300);
  },

  selectStock(id, symbol, company, exchange, price) {
    document.getElementById('stockId').value       = id;
    document.getElementById('stockSearch').value   = symbol;
    document.getElementById('selectedStockInfo').textContent = `${company} • ${exchange}${price ? ' • CMP: ₹'+fmtNum(price,2) : ''}`;
    document.getElementById('stockDropdown').style.display   = 'none';
    document.getElementById('newStockForm').style.display    = 'none';
    if (price) document.getElementById('stockPrice').value = price.toFixed(2);
    this.calcTotal();
  },

  showNewStockForm(symbol) {
    document.getElementById('stockId').value        = '';
    document.getElementById('newSymbol').value      = symbol;
    document.getElementById('newStockForm').style.display = 'block';
    document.getElementById('stockDropdown').style.display = 'none';
  },

  onTxnTypeChange() {
    const type = document.getElementById('stockTxnType').value;
    const isDivOnly = type === 'DIV';
    const isSplitBonus = type === 'SPLIT' || type === 'BONUS';
    document.getElementById('chargesRow').style.display    = (isDivOnly||isSplitBonus) ? 'none' : '';
    document.getElementById('divTotalGroup').style.display = isDivOnly ? 'block' : 'none';
    document.getElementById('priceLabel').textContent = isDivOnly ? 'Div per Share (₹)' : isSplitBonus ? 'Ratio (e.g. 2 = 2:1)' : 'Price / Share (₹) *';
  },

  calcTotal() {
    const qty   = parseFloat(document.getElementById('stockQty').value)  || 0;
    const price = parseFloat(document.getElementById('stockPrice').value) || 0;
    const brk   = parseFloat(document.getElementById('stockBrokerage').value) || 0;
    const stt   = parseFloat(document.getElementById('stockStt').value)       || 0;
    const exch  = parseFloat(document.getElementById('stockExch').value)      || 0;
    document.getElementById('stockTotal').value = (qty * price + brk + stt + exch).toFixed(2);
  },

  openAddModal() {
    document.getElementById('stockId').value       = '';
    document.getElementById('stockSearch').value   = '';
    document.getElementById('selectedStockInfo').textContent = '';
    document.getElementById('newStockForm').style.display = 'none';
    document.getElementById('stockDropdown').style.display = 'none';
    document.getElementById('stockPrice').value    = '';
    document.getElementById('stockQty').value      = '';
    document.getElementById('stockBrokerage').value = '0';
    document.getElementById('stockStt').value       = '0';
    document.getElementById('stockExch').value      = '0';
    document.getElementById('stockTotal').value     = '';
    document.getElementById('stockNotes').value     = '';
    document.getElementById('stockError').style.display = 'none';
    document.getElementById('stockTxnDate').value  = new Date().toISOString().split('T')[0];
    document.getElementById('stockTxnType').value  = 'BUY';
    this.onTxnTypeChange();
    document.getElementById('modalAddStock').style.display = 'flex';
  },
  closeAddModal() { document.getElementById('modalAddStock').style.display='none'; },

  sellStock(stockId, symbol) {
    this.openAddModal();
    document.getElementById('stockTxnType').value = 'SELL';
    this.onTxnTypeChange();
    document.getElementById('stockId').value     = stockId;
    document.getElementById('stockSearch').value = symbol;
    document.getElementById('selectedStockInfo').textContent = symbol + ' — Sell transaction';
    document.getElementById('stockModalTitle').textContent = 'Add SELL Transaction';
  },

  async saveTransaction() {
    const errEl = document.getElementById('stockError');
    errEl.style.display = 'none';
    const btn = document.getElementById('saveStock');
    btn.disabled = true; btn.textContent = 'Saving...';

    const type     = document.getElementById('stockTxnType').value;
    const stockId  = document.getElementById('stockId').value;
    const newSym   = document.getElementById('newSymbol')?.value?.trim();
    if (!stockId && !newSym) {
      errEl.textContent = 'Please select or enter a stock symbol.';
      errEl.style.display = 'block'; btn.disabled=false; btn.textContent='Save Transaction'; return;
    }

    const body = new URLSearchParams({
      action:           'stocks_add',
      portfolio_id:     document.getElementById('stockPortfolio').value,
      txn_type:         type,
      txn_date:         document.getElementById('stockTxnDate').value,
      price:            document.getElementById('stockPrice').value,
      quantity:         document.getElementById('stockQty').value,
      brokerage:        document.getElementById('stockBrokerage').value || '0',
      stt:              document.getElementById('stockStt').value        || '0',
      exchange_charges: document.getElementById('stockExch').value       || '0',
      notes:            document.getElementById('stockNotes').value,
      total_dividend:   document.getElementById('stockDivTotal')?.value || '0',
    });
    if (stockId) body.set('stock_id', stockId);
    if (!stockId && newSym) {
      body.set('new_symbol',   newSym);
      body.set('new_company',  document.getElementById('newCompany')?.value  || '');
      body.set('new_exchange', document.getElementById('newExchange')?.value || 'NSE');
      body.set('new_sector',   document.getElementById('newSector')?.value   || '');
    }

    try {
      const res  = await fetch(APP_URL + '/api/router.php', { method: 'POST', body });
      const data = await res.json();
      if (!data.success) throw new Error(data.message);
      this.closeAddModal();
      showToast('Transaction saved!', 'success');
      this.loadHoldings(); this.loadTransactions();
    } catch(e) {
      errEl.textContent = e.message; errEl.style.display='block';
    } finally { btn.disabled=false; btn.textContent='Save Transaction'; }
  },

  async refreshPrices() {
    const btn = document.getElementById('btnRefreshPrices');
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Refreshing...';
    try {
      const res  = await fetch(APP_URL + '/api/router.php', { method: 'POST', body: new URLSearchParams({ action: 'stocks_refresh_prices' }) });
      const data = await res.json();
      showToast(data.message || 'Prices updated!', data.success ? 'success' : 'error');
      if (data.success) this.loadHoldings();
    } catch(e) { showToast('Error: ' + e.message, 'error'); }
    finally { btn.disabled=false; btn.innerHTML='↺ Refresh Prices'; }
  },

  confirmDelete(id) { STOCKS.pendingDeleteId=id; document.getElementById('modalDelStock').style.display='flex'; },
  closeDelModal()   { document.getElementById('modalDelStock').style.display='none'; STOCKS.pendingDeleteId=null; },
  async deleteTransaction() {
    if (!STOCKS.pendingDeleteId) return;
    const btn = document.getElementById('confirmDelStock');
    btn.disabled=true; btn.textContent='Deleting...';
    try {
      const res  = await fetch(APP_URL+'/api/router.php',{ method:'POST', body: new URLSearchParams({ action:'stocks_delete', id: STOCKS.pendingDeleteId })});
      const data = await res.json();
      if (!data.success) throw new Error(data.message);
      this.closeDelModal(); showToast('Transaction deleted.','success');
      this.loadHoldings(); this.loadTransactions();
    } catch(e) { showToast('Error: '+e.message,'error'); }
    finally { btn.disabled=false; btn.textContent='Delete'; }
  },
};

/* ── HELPERS ── */
function fmtNum(v,d=2){ return parseFloat(v||0).toLocaleString('en-IN',{minimumFractionDigits:d,maximumFractionDigits:d}); }
function fmtInr(v){ const n=parseFloat(v||0); return(n<0?'-':'')+'₹'+Math.abs(n).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}); }
// [t348]
const _ST_MON=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
function fmtDate(d){ if(!d)return'—'; const[y,m,dd]=d.split('-'); return`${parseInt(dd,10)} ${_ST_MON[parseInt(m,10)-1]||m} ${y}`; }
function escHtml(t){ const d=document.createElement('div'); d.appendChild(document.createTextNode(t||'')); return d.innerHTML; }

document.addEventListener('DOMContentLoaded', () => STOCKS.init());

/* ═══════════════════════════════════════════════════════════════════════════
   t216 — SECTOR-WISE ANALYTICS
   t217 — DIVIDEND TRACKER
═══════════════════════════════════════════════════════════════════════════ */

async function renderStockSectorAnalytics(holdings) {
  const wrap = document.getElementById('stockSectorWrap');
  if (!wrap) return;
  if (!holdings || !holdings.length) {
    wrap.innerHTML = '<div style="color:var(--text-muted);text-align:center;padding:20px;font-size:13px;">No holdings data.</div>';
    return;
  }

  // Group by sector
  const sectors = {};
  let totalVal = 0;
  holdings.forEach(h => {
    const sector = h.sector || 'Unknown';
    const val    = parseFloat(h.current_value || (h.quantity * h.current_price) || 0);
    sectors[sector] = (sectors[sector] || 0) + val;
    totalVal += val;
  });
  if (totalVal <= 0) { wrap.innerHTML = '<div style="color:var(--text-muted);text-align:center;padding:20px;">No value data.</div>'; return; }

  const sorted = Object.entries(sectors).sort((a,b) => b[1] - a[1]);
  const colors = ['#3b82f6','#8b5cf6','#f59e0b','#10b981','#ef4444','#06b6d4','#f97316','#6366f1','#14b8a6','#e879f9'];
  const fmtI = v => v >= 1e7 ? '₹'+(v/1e7).toFixed(2)+'Cr' : v >= 1e5 ? '₹'+(v/1e5).toFixed(1)+'L' : '₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0});

  wrap.innerHTML = `
    <div style="display:flex;flex-direction:column;gap:8px;">
      ${sorted.map(([sector, val], i) => {
        const pct = (val / totalVal * 100).toFixed(1);
        const color = colors[i % colors.length];
        return `
          <div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:3px;">
              <span style="font-size:12px;font-weight:600;display:flex;align-items:center;gap:6px;">
                <span style="width:10px;height:10px;border-radius:50%;background:${color};flex-shrink:0;display:inline-block;"></span>
                ${escHtml(sector)}
              </span>
              <span style="font-size:11px;color:var(--text-muted);">${fmtI(val)} &nbsp;<strong>${pct}%</strong></span>
            </div>
            <div style="height:6px;background:var(--bg-secondary);border-radius:99px;overflow:hidden;">
              <div style="width:${pct}%;height:100%;background:${color};border-radius:99px;transition:width .5s;"></div>
            </div>
          </div>`;
      }).join('')}
    </div>`;
}

async function renderStockDividendTracker() {
  const body    = document.getElementById('stockDivBody');
  const summary = document.getElementById('stockDivSummary');
  const fyLabel = document.getElementById('stockDivFyLabel');
  if (!body) return;

  body.innerHTML = '<tr><td colspan="4" class="text-center" style="padding:20px;"><span class="spinner"></span></td></tr>';
  try {
    const pid = window.WD?.selectedPortfolio || 0;
    const res  = await fetch(`${window.APP_URL||''}/api/?action=stocks_list&type=transactions&txn_type=DIV&portfolio_id=${pid}&per_page=500`);
    const json = await res.json();
    const divs = (json.data || json.transactions || []).filter(t => (t.txn_type||t.transaction_type) === 'DIV');

    if (!divs.length) {
      body.innerHTML = '<tr><td colspan="4" class="text-center" style="padding:20px;color:var(--text-muted);">No dividend records found. Add DIV transactions to track dividends.</td></tr>';
      if (summary) summary.innerHTML = '';
      return;
    }

    // FY grouping
    const now = new Date(); const fyYear = now.getMonth() >= 3 ? now.getFullYear() : now.getFullYear()-1;
    const curFy = `${fyYear}-${String(fyYear+1).slice(2)}`;
    if (fyLabel) fyLabel.textContent = `FY ${curFy}`;

    const totalAll  = divs.reduce((s, d) => s + parseFloat(d.total_amount||d.amount||0), 0);
    const totalThisFy = divs.filter(d => (d.dividend_fy||d.fy||'') === curFy).reduce((s,d) => s + parseFloat(d.total_amount||d.amount||0), 0);
    const fmtI = v => v >= 1e5 ? '₹'+(v/1e5).toFixed(2)+'L' : '₹'+v.toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
    const fmtDate = d => { if(!d)return'—'; const[y,m,dd]=d.split('-'); return`${parseInt(dd,10)} ${_ST_MON[parseInt(m,10)-1]||m} ${y}`; }; // [t348]

    if (summary) summary.innerHTML = `
      <div style="background:var(--bg-secondary);border-radius:8px;padding:10px 14px;text-align:center;flex:1;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Total Dividends</div>
        <div style="font-size:16px;font-weight:800;color:#16a34a;">${fmtI(totalAll)}</div>
      </div>
      <div style="background:var(--bg-secondary);border-radius:8px;padding:10px 14px;text-align:center;flex:1;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">This FY</div>
        <div style="font-size:16px;font-weight:800;color:#3b82f6;">${fmtI(totalThisFy)}</div>
      </div>
      <div style="background:var(--bg-secondary);border-radius:8px;padding:10px 14px;text-align:center;flex:1;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Entries</div>
        <div style="font-size:16px;font-weight:800;">${divs.length}</div>
      </div>`;

    body.innerHTML = divs.slice(0,50).map(d => `
      <tr>
        <td style="font-weight:600;">${escHtml(d.symbol||d.company_name||'—')}</td>
        <td class="text-center" style="color:var(--text-muted);">${fmtDate(d.div_date||d.txn_date)}</td>
        <td class="text-right" style="font-weight:700;color:#16a34a;">${fmtI(parseFloat(d.total_amount||d.amount||0))}</td>
        <td class="text-center" style="font-size:11px;color:var(--text-muted);">${escHtml(d.dividend_fy||d.fy||'—')}</td>
      </tr>`).join('');
  } catch(e) {
    body.innerHTML = `<tr><td colspan="4" class="text-center" style="color:var(--text-muted);padding:16px;">Error: ${escHtml(e.message)}</td></tr>`;
  }
}

/* ── t281/t285/t433: Stock Fundamentals + 52-Week High/Low ───────────── */
STOCKS.refreshFundamentals = async function() {
  const body = document.getElementById('fundamentalsBody');
  const btn  = document.getElementById('btnRefreshFundamentals');
  if (!body) return;

  body.innerHTML = '<tr><td colspan="10" class="text-center" style="padding:30px"><span class="spinner"></span> Fetching fundamentals from Yahoo Finance…</td></tr>';
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Loading…'; }

  try {
    const res  = await fetch(APP_URL + '/api/router.php?action=holdings_enriched');
    const data = await res.json();

    if (!data.success || !data.data?.length) {
      body.innerHTML = '<tr><td colspan="10" class="text-center" style="padding:30px;color:var(--text-muted);">No stock holdings found.</td></tr>';
      return;
    }

    const fmtMktCap = v => {
      if (!v) return '—';
      if (v >= 1e12) return '₹' + (v/1e12).toFixed(1) + 'T';
      if (v >= 1e7)  return '₹' + (v/1e7).toFixed(0)  + 'Cr';
      return '₹' + (v/1e5).toFixed(1) + 'L';
    };
    const fmtP = (v, dec=1) => v != null && v > 0 ? parseFloat(v).toFixed(dec) + 'x' : '—';
    const fmtPct = v => v != null ? parseFloat(v).toFixed(2) + '%' : '—';

    body.innerHTML = data.data.map(h => {
      const cmp  = parseFloat(h.current_price || h.latest_price || 0);
      const h52  = parseFloat(h.high_52 || 0);
      const l52  = parseFloat(h.low_52  || 0);
      const pos  = h.pct_from_52w_low;  // 0-100: 0=at 52L, 100=at 52H
      const pctBelowH = h52 > 0 ? ((h52 - cmp) / h52 * 100).toFixed(1) : null;

      // 52w position bar
      const posWidth = pos != null ? Math.max(2, Math.min(100, parseFloat(pos))) : 0;
      const barColor = posWidth > 80 ? '#16a34a' : posWidth < 20 ? '#dc2626' : '#f59e0b';
      const posBar = pos != null
        ? `<div style="height:6px;background:var(--bg-secondary);border-radius:3px;overflow:hidden;margin-top:3px">
             <div style="width:${posWidth}%;height:100%;background:${barColor};border-radius:3px"></div>
           </div>
           <div style="display:flex;justify-content:space-between;font-size:9px;color:var(--text-muted);margin-top:1px">
             <span>${l52 ? '₹'+fmtNum(l52,0) : '—'}</span>
             <span style="font-weight:700;color:${barColor}">${posWidth.toFixed(0)}%</span>
             <span>${h52 ? '₹'+fmtNum(h52,0) : '—'}</span>
           </div>`
        : '<span style="color:var(--text-muted);font-size:11px">—</span>';

      // Signal badge
      let signal = '';
      if (pctBelowH !== null && parseFloat(pctBelowH) <= 5) {
        signal = '<span style="background:#dcfce7;color:#16a34a;border-radius:99px;padding:2px 7px;font-size:10px;font-weight:700;">🚀 Near 52H</span>';
      } else if (pos !== null && parseFloat(pos) <= 10) {
        signal = '<span style="background:#fef2f2;color:#dc2626;border-radius:99px;padding:2px 7px;font-size:10px;font-weight:700;">📉 Near 52L</span>';
      } else {
        signal = '<span style="color:var(--text-muted);font-size:11px;">—</span>';
      }

      const pe = h.pe_ratio ? parseFloat(h.pe_ratio).toFixed(1) + 'x' : '—';
      const pb = h.pb_ratio ? parseFloat(h.pb_ratio).toFixed(1) + 'x' : '—';

      return `<tr>
        <td>
          <div style="font-weight:700;">${escHtml(h.symbol)}</div>
          <div style="font-size:10px;color:var(--text-muted);">${escHtml(h.sector||'')}</div>
        </td>
        <td class="text-right" style="font-weight:700;">₹${fmtNum(cmp,2)}</td>
        <td class="text-right" style="color:#16a34a;">₹${h52 ? fmtNum(h52,2) : '—'}</td>
        <td class="text-right" style="color:#dc2626;">₹${l52 ? fmtNum(l52,2) : '—'}</td>
        <td>${posBar}</td>
        <td class="text-right">${pe}</td>
        <td class="text-right">${pb}</td>
        <td class="text-right" style="font-size:11px;">${fmtMktCap(h.market_cap)}</td>
        <td class="text-right">${h.dividend_yield ? fmtPct(h.dividend_yield*100) : '—'}</td>
        <td>${signal}</td>
      </tr>`;
    }).join('');
  } catch(e) {
    body.innerHTML = `<tr><td colspan="10" class="text-center" style="color:#dc2626;padding:20px;">Error: ${escHtml(e.message)}</td></tr>`;
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = '🔄 Refresh Data'; }
  }
};

/* ═══════════════════════════════════════════════════════════════════════════
   t433 — 52-WEEK HIGH / LOW TRACKER (Dedicated Module)
   Standalone tracker with summary stats, signal filters, sortable table
   API: /api/router.php?action=week52_tracker
═══════════════════════════════════════════════════════════════════════════ */

const W52 = {
  _data:       [],
  _filter:     'all',   // 'all' | 'near_high' | 'near_low' | 'neutral'
  _sortCol:    'pct_below_52h',
  _sortDir:    'asc',
  _loaded:     false,

  /* ── Load data from API ── */
  async load(force = false) {
    if (W52._loaded && !force) return;
    const wrap = document.getElementById('w52TrackerBody');
    const sum  = document.getElementById('w52Summary');
    if (!wrap) return;

    wrap.innerHTML = '<tr><td colspan="7" class="text-center" style="padding:30px"><span class="spinner"></span> Loading 52-Week data…</td></tr>';
    if (sum) sum.innerHTML = '';

    try {
      const res  = await fetch(APP_URL + '/api/router.php?action=week52_tracker');
      const json = await res.json();
      if (!json.success) throw new Error(json.message || 'API error');

      W52._data   = json.data || [];
      W52._loaded = true;
      W52._renderSummary();
      W52._renderTable();
    } catch (e) {
      wrap.innerHTML = `<tr><td colspan="7" class="text-center" style="padding:24px;color:#dc2626;">Error: ${escHtml(e.message)}</td></tr>`;
    }
  },

  /* ── Reload (force) ── */
  reload() {
    W52._loaded = false;
    W52.load(true);
  },

  /* ── Set signal filter ── */
  setFilter(f) {
    W52._filter = f;
    document.querySelectorAll('.w52-filter-btn').forEach(b => {
      b.classList.toggle('active', b.dataset.signal === f);
    });
    W52._renderTable();
  },

  /* ── Set sort column ── */
  sortBy(col) {
    if (W52._sortCol === col) {
      W52._sortDir = W52._sortDir === 'asc' ? 'desc' : 'asc';
    } else {
      W52._sortCol = col;
      W52._sortDir = col === 'pct_below_52h' ? 'asc' : 'desc';
    }
    // Update arrows
    document.querySelectorAll('.w52-sort-btn').forEach(b => {
      const arrow = b.querySelector('.w52-arr');
      if (!arrow) return;
      if (b.dataset.col === col) {
        arrow.textContent = W52._sortDir === 'asc' ? '↑' : '↓';
        b.style.color = 'var(--primary)';
      } else {
        arrow.textContent = '↕';
        b.style.color = 'var(--text-muted)';
      }
    });
    W52._renderTable();
  },

  /* ── Render summary stats ── */
  _renderSummary() {
    const el = document.getElementById('w52Summary');
    if (!el || !W52._data.length) return;

    const nearH   = W52._data.filter(r => r.signal === 'near_high').length;
    const nearL   = W52._data.filter(r => r.signal === 'near_low').length;
    const neutral = W52._data.filter(r => r.signal === 'neutral').length;
    const total   = W52._data.length;

    const stat = (icon, label, count, color, signal) =>
      `<div class="w52-stat-chip" data-signal="${signal}" onclick="W52.setFilter(W52._filter===signal?'all':'${signal}')"
            style="cursor:pointer;background:var(--bg-secondary);border:1px solid var(--border);border-radius:10px;padding:10px 16px;display:flex;flex-direction:column;align-items:center;min-width:90px;transition:all .15s;">
         <span style="font-size:20px;">${icon}</span>
         <span style="font-size:22px;font-weight:800;color:${color};">${count}</span>
         <span style="font-size:10px;color:var(--text-muted);text-align:center;line-height:1.3;">${label}</span>
       </div>`;

    el.innerHTML = `
      <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;margin-bottom:16px;">
        ${stat('📊', 'Total Holdings', total, 'var(--text-primary)', 'all')}
        ${stat('🚀', 'Near 52W High', nearH, '#16a34a', 'near_high')}
        ${stat('📉', 'Near 52W Low', nearL, '#dc2626', 'near_low')}
        ${stat('➖', 'Neutral', neutral, '#f59e0b', 'neutral')}
      </div>
      ${nearH > 0 ? `<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 14px;font-size:12px;color:#166534;margin-bottom:10px;">
        💡 <strong>${nearH} stock${nearH>1?'s':''}</strong> near 52-week high — possible breakout or exit opportunity.
      </div>` : ''}
      ${nearL > 0 ? `<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;font-size:12px;color:#991b1b;margin-bottom:10px;">
        ⚠️ <strong>${nearL} stock${nearL>1?'s':''}</strong> near 52-week low — potential value pick or further downside risk.
      </div>` : ''}`;
  },

  /* ── Render table ── */
  _renderTable() {
    const wrap = document.getElementById('w52TrackerBody');
    if (!wrap) return;

    // Filter
    let rows = W52._filter === 'all' ? [...W52._data] : W52._data.filter(r => r.signal === W52._filter);

    // Sort
    const dir = W52._sortDir === 'asc' ? 1 : -1;
    rows.sort((a, b) => {
      const av = parseFloat(a[W52._sortCol] ?? 9999);
      const bv = parseFloat(b[W52._sortCol] ?? 9999);
      return (av - bv) * dir;
    });

    if (!rows.length) {
      wrap.innerHTML = '<tr><td colspan="7" class="text-center" style="padding:24px;color:var(--text-muted);">No stocks match this filter.</td></tr>';
      return;
    }

    wrap.innerHTML = rows.map(r => {
      const cmp      = parseFloat(r.latest_price || 0);
      const h52      = parseFloat(r.high_52 || 0);
      const l52      = parseFloat(r.low_52  || 0);
      const pctH     = r.pct_below_52h !== null ? parseFloat(r.pct_below_52h) : null;
      const pctL     = r.pct_above_52l !== null ? parseFloat(r.pct_above_52l) : null;

      // Position 0=at 52L, 100=at 52H
      let posWidth = 50;
      if (h52 > 0 && l52 > 0 && h52 > l52) {
        posWidth = Math.max(2, Math.min(98, ((cmp - l52) / (h52 - l52)) * 100));
      }
      const barColor = posWidth >= 80 ? '#16a34a' : posWidth <= 20 ? '#dc2626' : '#f59e0b';

      const posBar = (h52 > 0 && l52 > 0)
        ? `<div style="min-width:110px;">
             <div style="height:6px;background:var(--bg-secondary);border-radius:3px;overflow:hidden;">
               <div style="width:${posWidth.toFixed(1)}%;height:100%;background:${barColor};border-radius:3px;transition:width .3s;"></div>
             </div>
             <div style="display:flex;justify-content:space-between;font-size:9px;color:var(--text-muted);margin-top:2px;">
               <span>₹${fmtNum(l52,0)}</span>
               <span style="font-weight:700;color:${barColor};">${posWidth.toFixed(0)}%</span>
               <span>₹${fmtNum(h52,0)}</span>
             </div>
           </div>`
        : '<span style="color:var(--text-muted);font-size:11px;">—</span>';

      const signalBadge = {
        near_high: '<span style="background:#dcfce7;color:#16a34a;border-radius:99px;padding:2px 8px;font-size:10px;font-weight:700;white-space:nowrap;">🚀 Near 52H</span>',
        near_low:  '<span style="background:#fef2f2;color:#dc2626;border-radius:99px;padding:2px 8px;font-size:10px;font-weight:700;white-space:nowrap;">📉 Near 52L</span>',
        neutral:   '<span style="background:#fefce8;color:#854d0e;border-radius:99px;padding:2px 8px;font-size:10px;font-weight:700;white-space:nowrap;">➖ Neutral</span>',
      }[r.signal] || '—';

      const pctHDisplay = pctH !== null
        ? `<span style="color:${pctH <= 5 ? '#16a34a' : pctH <= 15 ? '#f59e0b' : 'var(--text-muted)'};">${pctH.toFixed(1)}% ↓</span>`
        : '—';
      const pctLDisplay = pctL !== null
        ? `<span style="color:${pctL <= 10 ? '#dc2626' : pctL <= 25 ? '#f59e0b' : 'var(--text-muted)'};">${pctL.toFixed(1)}% ↑</span>`
        : '—';

      return `<tr>
        <td>
          <div style="font-weight:700;font-size:13px;">${escHtml(r.symbol)}</div>
          <div style="font-size:10px;color:var(--text-muted);max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escHtml(r.company_name||'')} ${r.sector?'· '+escHtml(r.sector):''}</div>
        </td>
        <td class="text-right" style="font-weight:700;">₹${fmtNum(cmp,2)}</td>
        <td class="text-right">${pctHDisplay}</td>
        <td class="text-right">${pctLDisplay}</td>
        <td>${posBar}</td>
        <td class="text-right" style="font-size:11px;color:var(--text-muted);">${r.quantity ? fmtNum(r.quantity,0)+' units' : '—'}</td>
        <td>${signalBadge}</td>
      </tr>`;
    }).join('');
  }
};
