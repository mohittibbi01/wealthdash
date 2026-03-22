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
  },

  /* ── HOLDINGS ── */
  async loadHoldings() {
    const body = document.getElementById('stocksBody');
    body.innerHTML = '<tr><td colspan="11" class="text-center" style="padding:40px"><span class="spinner"></span></td></tr>';

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
        rows = [...rows].sort((a,b) => STOCKS.sortDir==='desc' ? (parseFloat(b.gain_pct)||0)-(parseFloat(a.gain_pct)||0) : (parseFloat(a.gain_pct)||0)-(parseFloat(b.gain_pct)||0));
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
        body.innerHTML = '<tr><td colspan="11" class="text-center empty-state" style="padding:60px"><div style="font-size:40px">📈</div><p>No stock holdings yet.<br>Add your first BUY transaction.</p></td></tr>';
        return;
      }
      body.innerHTML = paged.map(h => {
        const gl    = parseFloat(h.gain_loss)    || 0;
        const glPct = parseFloat(h.gain_pct)     || 0;
        const cagr  = parseFloat(h.cagr)         || 0;
        const glCls = gl >= 0 ? 'text-success' : 'text-danger';
        const badgeCls = h.gain_type === 'LTCG' ? 'badge-success' : (h.gain_type === 'STCG' ? 'badge-warning' : 'badge-outline');
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
          <td class="text-right ${cagr>=0?'text-success':'text-danger'}">${cagr.toFixed(2)}%</td>
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
function fmtDate(d){ if(!d)return'—'; const[y,m,dd]=d.split('-'); return`${dd}-${m}-${y}`; }
function escHtml(t){ const d=document.createElement('div'); d.appendChild(document.createTextNode(t||'')); return d.innerHTML; }

document.addEventListener('DOMContentLoaded', () => STOCKS.init());
