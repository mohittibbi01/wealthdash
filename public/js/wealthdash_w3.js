/**
 * WealthDash — Combined JS (ID-W3)
 * Covers: t39, t114, t116, t121, t145, t432, t435, t436, t38, t118
 * Vanilla JS · No framework · CSS variables
 */

'use strict';

/* ─────────────────────────────────────────────
   SHARED UTILITIES
──────────────────────────────────────────────*/
const WD = {
  userId: () => parseInt(document.body.dataset.userId || '0', 10),
  apiBase: '/api',

  async get(path) {
    const r = await fetch(`${this.apiBase}${path}`);
    return r.json();
  },
  async post(path, body) {
    const r = await fetch(`${this.apiBase}${path}`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    return r.json();
  },
  async put(path, body) {
    const r = await fetch(`${this.apiBase}${path}`, {
      method: 'PUT', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
    });
    return r.json();
  },
  async del(path) {
    const r = await fetch(`${this.apiBase}${path}`, { method: 'DELETE' });
    return r.json();
  },

  fmt: {
    inr: v => v == null ? '—' : '₹' + Number(v).toLocaleString('en-IN', { maximumFractionDigits: 2 }),
    pct: v => v == null ? '—' : Number(v).toFixed(2) + '%',
    num: v => v == null ? '—' : Number(v).toLocaleString('en-IN'),
    date: v => v ? new Date(v).toLocaleDateString('en-IN') : '—',
  },

  openModal(id)  { const m = document.getElementById(id); if (m) { m.style.display = 'flex'; m.setAttribute('aria-hidden', 'false'); } },
  closeModal(id) { const m = document.getElementById(id); if (m) { m.style.display = 'none'; m.setAttribute('aria-hidden', 'true'); } },

  toast(msg, type = 'success') {
    const t = document.createElement('div');
    t.className = `wd-toast wd-toast--${type}`;
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3500);
  },

  val(id) { const el = document.getElementById(id); return el ? el.value.trim() : ''; },
  setVal(id, v) { const el = document.getElementById(id); if (el) el.value = v ?? ''; },
  setText(id, v) { const el = document.getElementById(id); if (el) el.textContent = v ?? '—'; },
  show(id) { const el = document.getElementById(id); if (el) el.style.display = ''; },
  hide(id) { const el = document.getElementById(id); if (el) el.style.display = 'none'; },

  pnlClass: v => parseFloat(v) >= 0 ? 'wd-pos' : 'wd-neg',

  setupTabGroup(tabSelector, panelSelector) {
    document.querySelectorAll(tabSelector).forEach(tab => {
      tab.addEventListener('click', () => {
        document.querySelectorAll(tabSelector).forEach(t => t.classList.remove('active'));
        document.querySelectorAll(panelSelector).forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        const panel = document.querySelector(`[data-panel="${tab.dataset.tab}"]`);
        if (panel) panel.classList.add('active');
      });
    });
  },

  setupModalClose(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    modal.querySelector('.wd-modal__backdrop')?.addEventListener('click', () => WD.closeModal(modalId));
    modal.querySelector('.wd-modal__close')?.addEventListener('click', () => WD.closeModal(modalId));
  },

  currentFY() {
    const now = new Date();
    const m = now.getMonth() + 1, y = now.getFullYear();
    return m >= 4 ? `${y}-${String(y + 1).slice(-2)}` : `${y - 1}-${String(y).slice(-2)}`;
  },

  fyOptions() {
    const years = [];
    let y = new Date().getFullYear() + (new Date().getMonth() >= 3 ? 0 : -1);
    for (let i = 0; i < 6; i++) {
      years.push(`${y - i}-${String(y - i + 1).slice(-2)}`);
    }
    return years;
  },
};


/* ─────────────────────────────────────────────
   t39 — LTCG / STCG TAX REPORT
──────────────────────────────────────────────*/
const TaxReport = {
  page: 1,
  async init() {
    if (!document.getElementById('page-tax-report')) return;
    this.populateFY();
    WD.setupTabGroup('#page-tax-report .wd-tab', '#page-tax-report .wd-tab-panel');
    document.getElementById('tr-fy-select').addEventListener('change', () => this.load());
    document.getElementById('tr-add-btn').addEventListener('click', () => this.openAddModal());
    document.getElementById('tr-import-btn').addEventListener('click', () => WD.toast('CSV import coming soon', 'info'));
    document.getElementById('tr-tx-save').addEventListener('click', () => this.saveTransaction());
    document.getElementById('tr-tx-cancel').addEventListener('click', () => WD.closeModal('tr-tx-modal'));
    WD.setupModalClose('tr-tx-modal');
    document.getElementById('tr-search').addEventListener('input', () => this.loadTransactions());
    document.getElementById('tr-type-filter').addEventListener('change', () => this.loadTransactions());
    await this.load();
  },

  populateFY() {
    const sel = document.getElementById('tr-fy-select');
    WD.fyOptions().forEach(fy => {
      const o = document.createElement('option'); o.value = fy; o.textContent = `FY ${fy}`;
      sel.appendChild(o);
    });
    sel.value = WD.currentFY();
  },

  async load() {
    const fy = WD.val('tr-fy-select');
    const res = await WD.get(`/stocks/tax-report?user_id=${WD.userId()}&fy=${fy}`);
    if (res.status !== 'success') return;
    const s = res.summary;
    WD.setText('tr-stcg-val', WD.fmt.inr(s.stcg_total));
    WD.setText('tr-stcg-tax', `Tax: ${WD.fmt.inr(s.stcg_tax)}`);
    WD.setText('tr-ltcg-val', WD.fmt.inr(s.ltcg_total));
    WD.setText('tr-ltcg-tax', `Tax: ${WD.fmt.inr(s.ltcg_tax)}`);
    WD.setText('tr-ltcg-exempt', WD.fmt.inr(s.ltcg_exempt));
    WD.setText('tr-total-tax', WD.fmt.inr(s.stcg_tax + s.ltcg_tax));

    this.renderGains('tr-stcg-body', res.stcg, false);
    this.renderGains('tr-ltcg-body', res.ltcg, true);
    this.renderUnrealized('tr-unrealized-body', res.unrealized);
    await this.loadTransactions();
  },

  renderGains(tbodyId, rows, isLTCG) {
    const tbody = document.getElementById(tbodyId);
    if (!rows?.length) { tbody.innerHTML = '<tr><td colspan="9" class="wd-empty">No entries.</td></tr>'; return; }
    tbody.innerHTML = rows.map(r => `
      <tr>
        <td><strong>${r.stock_symbol}</strong></td>
        <td>${WD.fmt.num(r.quantity)}</td>
        <td>${WD.fmt.inr(r.buy_price)}</td>
        ${isLTCG ? `<td>${WD.fmt.inr(r.grandfathered_price)}</td>` : ''}
        <td>${WD.fmt.inr(r.sell_price)}</td>
        <td>${WD.fmt.date(r.buy_date)}</td>
        <td>${WD.fmt.date(r.sell_date)}</td>
        <td>${r.holding_days ?? '—'}</td>
        <td class="wd-num ${WD.pnlClass(r.gain_loss)}">${WD.fmt.inr(r.gain_loss)}</td>
      </tr>`).join('');
  },

  renderUnrealized(tbodyId, rows) {
    const tbody = document.getElementById(tbodyId);
    if (!rows?.length) { tbody.innerHTML = '<tr><td colspan="6" class="wd-empty">No unrealized holdings.</td></tr>'; return; }
    tbody.innerHTML = rows.map(r => `
      <tr>
        <td><strong>${r.stock_symbol}</strong></td>
        <td>${WD.fmt.num(r.total_qty)}</td>
        <td>${WD.fmt.inr(r.avg_buy_price)}</td>
        <td>${WD.fmt.date(r.buy_date)}</td>
        <td>${r.holding_days}</td>
        <td><span class="wd-badge wd-badge--${r.projected_category === 'LTCG' ? 'success' : 'warn'}">${r.projected_category}</span></td>
      </tr>`).join('');
  },

  async loadTransactions() {
    const q = WD.val('tr-search');
    const type = WD.val('tr-type-filter');
    let url = `/stocks/transactions?user_id=${WD.userId()}&page=${this.page}&limit=25`;
    if (q) url += `&symbol=${encodeURIComponent(q)}`;
    if (type) url += `&type=${type}`;
    const res = await WD.get(url);
    if (res.status !== 'success') return;
    const tbody = document.getElementById('tr-tx-body');
    tbody.innerHTML = res.data.map(r => `
      <tr>
        <td>${WD.fmt.date(r.transaction_date)}</td>
        <td><strong>${r.stock_symbol}</strong></td>
        <td>${r.stock_name}</td>
        <td><span class="wd-badge wd-badge--${r.transaction_type === 'BUY' ? 'info' : 'danger'}">${r.transaction_type}</span></td>
        <td>${WD.fmt.num(r.quantity)}</td>
        <td class="wd-num">${WD.fmt.inr(r.price)}</td>
        <td class="wd-num">${WD.fmt.inr(parseFloat(r.quantity) * parseFloat(r.price))}</td>
        <td>${r.exchange}</td>
        <td class="wd-actions">
          <button class="wd-btn wd-btn--ghost wd-btn--xs" onclick="TaxReport.editTx(${r.id})">Edit</button>
          <button class="wd-btn wd-btn--danger wd-btn--xs" onclick="TaxReport.deleteTx(${r.id})">Del</button>
        </td>
      </tr>`).join('') || '<tr><td colspan="9" class="wd-empty">No transactions.</td></tr>';
  },

  openAddModal() {
    ['tr-tx-id','tr-tx-symbol','tr-tx-name','tr-tx-qty','tr-tx-price','tr-tx-brok','tr-tx-isin','tr-tx-gprice','tr-tx-notes'].forEach(id => WD.setVal(id, ''));
    WD.setVal('tr-tx-type', 'BUY');
    WD.setVal('tr-tx-exchange', 'NSE');
    WD.setVal('tr-tx-date', new Date().toISOString().split('T')[0]);
    document.getElementById('tr-tx-modal-title').textContent = 'Add Transaction';
    WD.openModal('tr-tx-modal');
  },

  async editTx(id) {
    const res = await WD.get(`/stocks/transactions?user_id=${WD.userId()}&id=${id}`);
    const r = res.data?.[0]; if (!r) return;
    WD.setVal('tr-tx-id', r.id); WD.setVal('tr-tx-symbol', r.stock_symbol);
    WD.setVal('tr-tx-name', r.stock_name); WD.setVal('tr-tx-type', r.transaction_type);
    WD.setVal('tr-tx-exchange', r.exchange); WD.setVal('tr-tx-qty', r.quantity);
    WD.setVal('tr-tx-price', r.price); WD.setVal('tr-tx-date', r.transaction_date);
    WD.setVal('tr-tx-brok', r.brokerage); WD.setVal('tr-tx-isin', r.isin);
    WD.setVal('tr-tx-gprice', r.grandfathered_price); WD.setVal('tr-tx-notes', r.notes);
    document.getElementById('tr-tx-modal-title').textContent = 'Edit Transaction';
    WD.openModal('tr-tx-modal');
  },

  async saveTransaction() {
    const id = WD.val('tr-tx-id');
    const body = {
      user_id: WD.userId(),
      stock_symbol: WD.val('tr-tx-symbol'), stock_name: WD.val('tr-tx-name'),
      transaction_type: WD.val('tr-tx-type'), exchange: WD.val('tr-tx-exchange'),
      quantity: WD.val('tr-tx-qty'), price: WD.val('tr-tx-price'),
      transaction_date: WD.val('tr-tx-date'), brokerage: WD.val('tr-tx-brok'),
      isin: WD.val('tr-tx-isin'), grandfathered_price: WD.val('tr-tx-gprice'),
      notes: WD.val('tr-tx-notes'),
    };
    const res = id ? await WD.put(`/stocks/transactions/${id}`, body) : await WD.post('/stocks/transactions', body);
    if (res.status === 'success') {
      WD.closeModal('tr-tx-modal'); WD.toast('Transaction saved'); this.load();
    } else WD.toast(res.message || 'Error', 'error');
  },

  async deleteTx(id) {
    if (!confirm('Delete this transaction?')) return;
    const res = await WD.del(`/stocks/transactions/${id}?user_id=${WD.userId()}`);
    if (res.status === 'success') { WD.toast('Deleted'); this.load(); }
    else WD.toast(res.message || 'Error', 'error');
  },
};


/* ─────────────────────────────────────────────
   t114 — GOLD TRACKER
──────────────────────────────────────────────*/
const GoldTracker = {
  async init() {
    if (!document.getElementById('page-gold')) return;
    WD.setupTabGroup('#page-gold .wd-tab', '#page-gold .wd-tab-panel');
    WD.setupModalClose('gold-modal');
    WD.setupModalClose('gold-tx-modal');
    document.getElementById('gold-add-btn').addEventListener('click', () => this.openAddModal());
    document.getElementById('gold-save').addEventListener('click', () => this.save());
    document.getElementById('gold-cancel').addEventListener('click', () => WD.closeModal('gold-modal'));
    document.getElementById('gold-tx-save').addEventListener('click', () => this.saveTx());
    document.getElementById('gold-tx-cancel').addEventListener('click', () => WD.closeModal('gold-tx-modal'));
    document.getElementById('gold-refresh-btn').addEventListener('click', () => this.refreshPrices());
    document.getElementById('gold-type').addEventListener('change', () => this.toggleTypeFields());
    ['gold-qty','gold-price'].forEach(id => document.getElementById(id)?.addEventListener('input', () => this.updatePreview()));
    document.querySelectorAll('#page-gold .wd-tab').forEach(tab => {
      tab.addEventListener('click', () => this.loadHoldings(tab.dataset.tab === 'all' ? null : tab.dataset.tab));
    });
    await this.loadSummary();
    await this.loadHoldings();
  },

  async loadSummary() {
    const res = await WD.get(`/gold/summary?user_id=${WD.userId()}`);
    if (res.status !== 'success') return;
    const byType = {};
    res.by_type.forEach(r => byType[r.gold_type] = r);
    WD.setText('gold-physical-grams', WD.fmt.num(res.physical_24k_equivalent_grams));
    WD.setText('gold-digital-grams', WD.fmt.num(byType['DIGITAL']?.total_grams ?? 0));
    WD.setText('gold-etf-units', WD.fmt.num((byType['ETF']?.total_units ?? 0) + (byType['FUND']?.total_units ?? 0)));
    const totalInvested = res.by_type.reduce((s, r) => s + parseFloat(r.total_invested || 0), 0);
    WD.setText('gold-total-invested', WD.fmt.inr(totalInvested));
  },

  async loadHoldings(type = null) {
    const url = `/gold?user_id=${WD.userId()}${type ? `&type=${type}` : ''}`;
    const res = await WD.get(url);
    if (res.status !== 'success') return;
    const tbody = document.getElementById('gold-table-body');
    if (!res.data.length) { tbody.innerHTML = '<tr><td colspan="9" class="wd-empty">No holdings.</td></tr>'; return; }
    tbody.innerHTML = res.data.map(r => {
      const invested = parseFloat(r.quantity) * parseFloat(r.buy_price);
      return `<tr>
        <td>${r.name}</td>
        <td><span class="wd-badge">${r.gold_type}</span></td>
        <td>${r.sub_type || '—'}</td>
        <td>${r.purity || '—'}</td>
        <td class="wd-num">${WD.fmt.num(r.quantity)}</td>
        <td class="wd-num">${WD.fmt.inr(r.buy_price)}</td>
        <td class="wd-num">${WD.fmt.inr(invested)}</td>
        <td>${WD.fmt.date(r.buy_date)}</td>
        <td class="wd-actions">
          <button class="wd-btn wd-btn--ghost wd-btn--xs" onclick="GoldTracker.openTxModal(${r.id})">Tx</button>
          <button class="wd-btn wd-btn--ghost wd-btn--xs" onclick="GoldTracker.edit(${r.id})">Edit</button>
          <button class="wd-btn wd-btn--danger wd-btn--xs" onclick="GoldTracker.del(${r.id})">Del</button>
        </td>
      </tr>`;
    }).join('');
  },

  toggleTypeFields() {
    const type = WD.val('gold-type');
    const isPhysical = type === 'PHYSICAL';
    const isETF = ['ETF','FUND'].includes(type);
    document.getElementById('gold-purity-wrap').style.display = isPhysical ? '' : 'none';
    document.getElementById('gold-making-wrap').style.display = isPhysical ? '' : 'none';
    document.getElementById('gold-qty-label').textContent = isETF ? 'Units *' : 'Quantity (grams) *';
    document.getElementById('gold-price-label').textContent = isETF ? 'Buy Price (₹/unit) *' : 'Buy Price (₹/gram) *';
  },

  updatePreview() {
    const val = parseFloat(WD.val('gold-qty') || 0) * parseFloat(WD.val('gold-price') || 0);
    WD.setText('gold-preview-val', WD.fmt.inr(val));
  },

  openAddModal() {
    WD.setVal('gold-id', ''); WD.setVal('gold-type', 'PHYSICAL');
    ['gold-subtype','gold-name','gold-qty','gold-price','gold-making','gold-folio','gold-custodian','gold-notes'].forEach(id => WD.setVal(id, ''));
    WD.setVal('gold-date', new Date().toISOString().split('T')[0]);
    WD.setVal('gold-purity', '24K');
    document.getElementById('gold-modal-title').textContent = 'Add Gold Holding';
    this.toggleTypeFields();
    WD.openModal('gold-modal');
  },

  async save() {
    const id = WD.val('gold-id');
    const body = {
      user_id: WD.userId(), gold_type: WD.val('gold-type'), sub_type: WD.val('gold-subtype'),
      name: WD.val('gold-name'), quantity: WD.val('gold-qty'), buy_price: WD.val('gold-price'),
      buy_date: WD.val('gold-date'), purity: WD.val('gold-purity'),
      making_charges: WD.val('gold-making'), folio_number: WD.val('gold-folio'),
      custodian: WD.val('gold-custodian'), notes: WD.val('gold-notes'),
    };
    const res = id ? await WD.put(`/gold/${id}`, body) : await WD.post('/gold', body);
    if (res.status === 'success') { WD.closeModal('gold-modal'); WD.toast('Saved'); this.loadSummary(); this.loadHoldings(); }
    else WD.toast(res.message || 'Error', 'error');
  },

  openTxModal(holdingId) {
    WD.setVal('gold-tx-holding-id', holdingId);
    WD.setVal('gold-tx-type', 'BUY'); WD.setVal('gold-tx-qty', ''); WD.setVal('gold-tx-price', ''); WD.setVal('gold-tx-charges', '0');
    WD.setVal('gold-tx-date', new Date().toISOString().split('T')[0]);
    WD.openModal('gold-tx-modal');
  },

  async saveTx() {
    const holdingId = WD.val('gold-tx-holding-id');
    const body = {
      user_id: WD.userId(), transaction_type: WD.val('gold-tx-type'),
      quantity: WD.val('gold-tx-qty'), price: WD.val('gold-tx-price'),
      transaction_date: WD.val('gold-tx-date'), charges: WD.val('gold-tx-charges'),
    };
    const res = await WD.post(`/gold/${holdingId}/transaction`, body);
    if (res.status === 'success') { WD.closeModal('gold-tx-modal'); WD.toast('Transaction recorded'); this.loadSummary(); this.loadHoldings(); }
    else WD.toast(res.message || 'Error', 'error');
  },

  async del(id) {
    if (!confirm('Delete this gold holding?')) return;
    const res = await WD.del(`/gold/${id}?user_id=${WD.userId()}`);
    if (res.status === 'success') { WD.toast('Deleted'); this.loadSummary(); this.loadHoldings(); }
  },

  async refreshPrices() { WD.toast('Live price feed integration needed', 'info'); },
};


/* ─────────────────────────────────────────────
   t435 — WATCHLIST
──────────────────────────────────────────────*/
const WatchlistMgr = {
  viewMode: 'grid',

  async init() {
    if (!document.getElementById('page-watchlist')) return;
    WD.setupModalClose('wl-modal');
    WD.setupModalClose('wl-refresh-modal');
    document.getElementById('wl-add-btn').addEventListener('click', () => this.openAddModal());
    document.getElementById('wl-save').addEventListener('click', () => this.save());
    document.getElementById('wl-cancel').addEventListener('click', () => WD.closeModal('wl-modal'));
    document.getElementById('wl-refresh-btn').addEventListener('click', () => WD.openModal('wl-refresh-modal'));
    document.getElementById('wl-refresh-save').addEventListener('click', () => this.bulkRefreshPrices());
    document.getElementById('wl-refresh-cancel').addEventListener('click', () => WD.closeModal('wl-refresh-modal'));
    document.getElementById('wl-search').addEventListener('input', () => this.load());
    document.getElementById('wl-sort').addEventListener('change', () => this.load());
    document.getElementById('wl-sort-dir').addEventListener('change', () => this.load());
    document.getElementById('wl-view-grid').addEventListener('click', () => this.setView('grid'));
    document.getElementById('wl-view-table').addEventListener('click', () => this.setView('table'));
    await this.load();
    await this.loadAlerts();
  },

  async load() {
    const sort = WD.val('wl-sort'), dir = WD.val('wl-sort-dir'), tag = WD.val('wl-tag-filter');
    let url = `/watchlist?user_id=${WD.userId()}&sort=${sort}&dir=${dir}`;
    if (tag) url += `&tag=${encodeURIComponent(tag)}`;
    const res = await WD.get(url);
    if (res.status !== 'success') return;

    const q = WD.val('wl-search').toLowerCase();
    const rows = q ? res.data.filter(r => r.stock_symbol.toLowerCase().includes(q) || r.stock_name.toLowerCase().includes(q)) : res.data;

    this.viewMode === 'grid' ? this.renderGrid(rows) : this.renderTable(rows);
  },

  renderGrid(rows) {
    const grid = document.getElementById('wl-grid');
    if (!rows.length) { grid.innerHTML = '<div class="wd-empty">Watchlist is empty.</div>'; return; }
    grid.innerHTML = rows.map(r => {
      const statusClass = { 'buy_zone': 'wd-card--buy', 'sell_zone': 'wd-card--sell', 'stop_loss_hit': 'wd-card--danger', 'watching': '' }[r.status] || '';
      return `<div class="wd-watchlist-card ${statusClass}" data-id="${r.id}">
        <div class="wlc-header">
          <strong>${r.stock_symbol}</strong>
          <span class="wd-badge wd-badge--${r.status === 'buy_zone' ? 'success' : r.status === 'sell_zone' ? 'warn' : 'default'}">${r.status?.replace('_', ' ')}</span>
        </div>
        <div class="wlc-name">${r.stock_name}</div>
        <div class="wlc-price">${WD.fmt.inr(r.current_price)}</div>
        <div class="wlc-targets">
          <span>Buy: ${WD.fmt.inr(r.buy_target)}</span>
          <span>Sell: ${WD.fmt.inr(r.sell_target)}</span>
          <span>SL: ${WD.fmt.inr(r.stop_loss)}</span>
        </div>
        ${r.buy_gap_pct != null ? `<div class="wlc-gap">Buy gap: <strong class="${WD.pnlClass(-r.buy_gap_pct)}">${WD.fmt.pct(r.buy_gap_pct)}</strong></div>` : ''}
        ${r.tags ? `<div class="wlc-tags">${r.tags.split(',').map(t => `<span class="wd-tag">${t.trim()}</span>`).join('')}</div>` : ''}
        <div class="wlc-actions">
          <button class="wd-btn wd-btn--ghost wd-btn--xs" onclick="WatchlistMgr.edit(${r.id})">Edit</button>
          <button class="wd-btn wd-btn--danger wd-btn--xs" onclick="WatchlistMgr.del(${r.id})">Remove</button>
        </div>
      </div>`;
    }).join('');
  },

  renderTable(rows) {
    const tbody = document.getElementById('wl-table-body');
    tbody.innerHTML = rows.map(r => `
      <tr>
        <td><input type="checkbox" class="wl-row-check" value="${r.id}"></td>
        <td><strong>${r.stock_symbol}</strong></td>
        <td>${r.stock_name}</td>
        <td>${r.sector || '—'}</td>
        <td class="wd-num">${WD.fmt.inr(r.current_price)}</td>
        <td class="wd-num">${WD.fmt.inr(r.buy_target)}</td>
        <td class="wd-num ${WD.pnlClass(r.buy_gap_pct)}">${WD.fmt.pct(r.buy_gap_pct)}</td>
        <td class="wd-num">${WD.fmt.inr(r.sell_target)}</td>
        <td class="wd-num">${WD.fmt.inr(r.stop_loss)}</td>
        <td><span class="wd-badge">${r.status?.replace('_', ' ')}</span></td>
        <td>${r.tags || '—'}</td>
        <td>${WD.fmt.date(r.added_date)}</td>
        <td class="wd-actions">
          <button class="wd-btn wd-btn--ghost wd-btn--xs" onclick="WatchlistMgr.edit(${r.id})">Edit</button>
          <button class="wd-btn wd-btn--danger wd-btn--xs" onclick="WatchlistMgr.del(${r.id})">Del</button>
        </td>
      </tr>`).join('') || '<tr><td colspan="13" class="wd-empty">Empty.</td></tr>';
  },

  setView(mode) {
    this.viewMode = mode;
    document.getElementById('wl-grid').style.display = mode === 'grid' ? '' : 'none';
    document.getElementById('wl-table-wrap').style.display = mode === 'table' ? '' : 'none';
    document.getElementById('wl-view-grid').classList.toggle('active', mode === 'grid');
    document.getElementById('wl-view-table').classList.toggle('active', mode === 'table');
    this.load();
  },

  async loadAlerts() {
    const res = await WD.get(`/watchlist/alerts?user_id=${WD.userId()}`);
    if (res.status !== 'success' || !res.alerts.length) return;
    const strip = document.getElementById('wl-alerts-strip');
    const inner = document.getElementById('wl-alerts-inner');
    inner.innerHTML = res.alerts.map(a =>
      `<span class="wd-alert-item wd-alert-item--${a.type.includes('STOP') ? 'danger' : a.type.includes('BUY') ? 'success' : 'warn'}">
        ${a.symbol}: ${a.type.replace(/_/g,' ')} @ ${WD.fmt.inr(a.target)}
      </span>`
    ).join('');
    strip.style.display = '';
  },

  openAddModal() {
    WD.setVal('wl-id', '');
    ['wl-symbol','wl-name','wl-sector','wl-buy-target','wl-sell-target','wl-stop-loss','wl-current-price','wl-tags','wl-rationale'].forEach(id => WD.setVal(id, ''));
    WD.setVal('wl-exchange', 'NSE');
    ['wl-alert-buy','wl-alert-sell','wl-alert-sl'].forEach(id => { const el = document.getElementById(id); if (el) el.checked = true; });
    document.getElementById('wl-modal-title').textContent = 'Add to Watchlist';
    WD.openModal('wl-modal');
  },

  async edit(id) {
    const res = await WD.get(`/watchlist?user_id=${WD.userId()}`);
    const r = res.data?.find(x => x.id === id); if (!r) return;
    WD.setVal('wl-id', r.id); WD.setVal('wl-symbol', r.stock_symbol); WD.setVal('wl-name', r.stock_name);
    WD.setVal('wl-exchange', r.exchange); WD.setVal('wl-sector', r.sector);
    WD.setVal('wl-buy-target', r.buy_target); WD.setVal('wl-sell-target', r.sell_target);
    WD.setVal('wl-stop-loss', r.stop_loss); WD.setVal('wl-current-price', r.current_price);
    WD.setVal('wl-tags', r.tags); WD.setVal('wl-rationale', r.rationale);
    ['wl-alert-buy','wl-alert-sell','wl-alert-sl'].forEach((id, i) => {
      const el = document.getElementById(id);
      if (el) el.checked = !!r[['alert_on_buy_target','alert_on_sell_target','alert_on_stop_loss'][i]];
    });
    document.getElementById('wl-modal-title').textContent = 'Edit Watchlist';
    WD.openModal('wl-modal');
  },

  async save() {
    const id = WD.val('wl-id');
    const body = {
      user_id: WD.userId(), stock_symbol: WD.val('wl-symbol'), stock_name: WD.val('wl-name'),
      exchange: WD.val('wl-exchange'), sector: WD.val('wl-sector'),
      buy_target: WD.val('wl-buy-target') || null, sell_target: WD.val('wl-sell-target') || null,
      stop_loss: WD.val('wl-stop-loss') || null, current_price: WD.val('wl-current-price') || null,
      tags: WD.val('wl-tags'), rationale: WD.val('wl-rationale'),
      alert_on_buy_target: document.getElementById('wl-alert-buy')?.checked ? 1 : 0,
      alert_on_sell_target: document.getElementById('wl-alert-sell')?.checked ? 1 : 0,
      alert_on_stop_loss: document.getElementById('wl-alert-sl')?.checked ? 1 : 0,
    };
    const res = id ? await WD.put(`/watchlist/${id}`, body) : await WD.post('/watchlist', body);
    if (res.status === 'success') { WD.closeModal('wl-modal'); WD.toast('Saved'); this.load(); }
    else WD.toast(res.message || 'Error', 'error');
  },

  async del(id) {
    if (!confirm('Remove from watchlist?')) return;
    const res = await WD.del(`/watchlist/${id}?user_id=${WD.userId()}`);
    if (res.status === 'success') { WD.toast('Removed'); this.load(); }
  },

  async bulkRefreshPrices() {
    const raw = document.getElementById('wl-bulk-prices').value.trim();
    const prices = raw.split('\n').map(line => {
      const [symbol, price] = line.split(':');
      return symbol && price ? { symbol: symbol.trim(), price: parseFloat(price.trim()) } : null;
    }).filter(Boolean);
    if (!prices.length) { WD.toast('No valid prices', 'error'); return; }
    const res = await WD.post('/watchlist/refresh', { user_id: WD.userId(), prices });
    if (res.status === 'success') { WD.closeModal('wl-refresh-modal'); WD.toast(`Updated ${res.updated} prices`); this.load(); this.loadAlerts(); }
    else WD.toast(res.message || 'Error', 'error');
  },
};


/* ─────────────────────────────────────────────
   t436 — STOCK SIP
──────────────────────────────────────────────*/
const StockSIPMgr = {
  async init() {
    if (!document.getElementById('page-stock-sip')) return;
    WD.setupTabGroup('#page-stock-sip .wd-tab', '#page-stock-sip .wd-tab-panel');
    WD.setupModalClose('sip-modal');
    WD.setupModalClose('sip-install-modal');
    document.getElementById('sip-add-btn').addEventListener('click', () => this.openAddModal());
    document.getElementById('sip-save').addEventListener('click', () => this.save());
    document.getElementById('sip-cancel').addEventListener('click', () => WD.closeModal('sip-modal'));
    document.getElementById('sip-install-save').addEventListener('click', () => this.recordInstallment());
    document.getElementById('sip-install-cancel').addEventListener('click', () => WD.closeModal('sip-install-modal'));
    document.getElementById('sip-due-btn').addEventListener('click', () => this.loadDue());
    document.getElementById('sip-frequency').addEventListener('change', () => this.updateDayLabel());
    await this.loadSummary();
    await this.loadSIPs(true);
    await this.loadSIPs(false);
    await this.loadDue();
  },

  async loadSummary() {
    const res = await WD.get(`/stocks/sip/summary?user_id=${WD.userId()}`);
    if (res.status !== 'success') return;
    let activeCount = 0, totalInvested = 0;
    res.data.forEach(r => { activeCount++; totalInvested += parseFloat(r.total_invested || 0); });
    WD.setText('sip-active-count', activeCount);
    WD.setText('sip-monthly-commit', WD.fmt.inr(res.total_monthly_commitment));
    WD.setText('sip-total-invested', WD.fmt.inr(totalInvested));

    const tbody = document.getElementById('sip-summary-body');
    tbody.innerHTML = res.data.map(r => `<tr>
      <td><strong>${r.stock_symbol}</strong></td>
      <td>${r.stock_name}</td>
      <td>${r.frequency}</td>
      <td class="wd-num">${WD.fmt.inr(r.sip_amount)}</td>
      <td class="wd-num">${r.installments_done}</td>
      <td class="wd-num">${WD.fmt.inr(r.total_invested)}</td>
      <td class="wd-num">${WD.fmt.num(parseFloat(r.total_units || 0).toFixed(4))}</td>
      <td class="wd-num">${WD.fmt.inr(r.avg_cost)}</td>
      <td class="wd-num">${WD.fmt.inr(r.min_price)}</td>
      <td class="wd-num">${WD.fmt.inr(r.max_price)}</td>
    </tr>`).join('') || '<tr><td colspan="10" class="wd-empty">No data.</td></tr>';
  },

  async loadSIPs(active) {
    const res = await WD.get(`/stocks/sip?user_id=${WD.userId()}&active=${active ? 1 : 0}`);
    if (res.status !== 'success') return;
    const tbody = document.getElementById(active ? 'sip-active-body' : 'sip-paused-body');
    if (!res.data.length) { tbody.innerHTML = `<tr><td colspan="${active ? 11 : 8}" class="wd-empty">None.</td></tr>`; return; }
    tbody.innerHTML = res.data.map(r => active ? `<tr>
      <td><strong>${r.stock_symbol}</strong></td>
      <td>${r.stock_name}</td>
      <td class="wd-num">${WD.fmt.inr(r.sip_amount)}</td>
      <td>${r.frequency}</td>
      <td>${r.sip_day || '—'}</td>
      <td>${WD.fmt.date(r.start_date)}</td>
      <td>${WD.fmt.date(r.next_due_date)}</td>
      <td class="wd-num">${r.total_installments}</td>
      <td class="wd-num">${WD.fmt.inr(r.total_invested)}</td>
      <td class="wd-num">${WD.fmt.inr(r.avg_price)}</td>
      <td class="wd-actions">
        <button class="wd-btn wd-btn--primary wd-btn--xs" onclick="StockSIPMgr.openInstallModal(${r.id}, '${r.stock_symbol}', ${r.sip_amount})">Record</button>
        <button class="wd-btn wd-btn--ghost wd-btn--xs" onclick="StockSIPMgr.stopSIP(${r.id})">Stop</button>
      </td>
    </tr>` : `<tr>
      <td><strong>${r.stock_symbol}</strong></td>
      <td>${r.stock_name}</td>
      <td class="wd-num">${WD.fmt.inr(r.sip_amount)}</td>
      <td>${r.frequency}</td>
      <td>${WD.fmt.date(r.start_date)}</td>
      <td>${WD.fmt.date(r.end_date)}</td>
      <td class="wd-num">${WD.fmt.inr(r.total_invested)}</td>
      <td class="wd-actions">
        <button class="wd-btn wd-btn--ghost wd-btn--xs" onclick="StockSIPMgr.restartSIP(${r.id})">Restart</button>
      </td>
    </tr>`).join('');
  },

  async loadDue() {
    const res = await WD.get(`/stocks/sip/due-today?user_id=${WD.userId()}`);
    WD.setText('sip-due-count', res.count || 0);
    if (res.count > 0) {
      document.getElementById('sip-due-banner').style.display = '';
      document.getElementById('sip-due-inner').innerHTML =
        `${res.count} SIP(s) due today: ` + res.due.map(s => `<strong>${s.stock_symbol}</strong> ₹${s.sip_amount}`).join(', ');
    }
  },

  updateDayLabel() {
    const freq = WD.val('sip-frequency');
    const label = document.getElementById('sip-day-label');
    if (freq === 'WEEKLY') label.textContent = 'Day of Week (1=Mon…7=Sun)';
    else label.textContent = 'SIP Day (of month, 1-28)';
  },

  openAddModal() {
    WD.setVal('sip-id', '');
    ['sip-symbol','sip-name','sip-amount','sip-broker','sip-notes','sip-end'].forEach(id => WD.setVal(id, ''));
    WD.setVal('sip-exchange', 'NSE'); WD.setVal('sip-frequency', 'MONTHLY'); WD.setVal('sip-day', '1');
    WD.setVal('sip-start', new Date().toISOString().split('T')[0]);
    document.getElementById('sip-modal-title').textContent = 'Create Stock SIP';
    WD.openModal('sip-modal');
  },

  async save() {
    const id = WD.val('sip-id');
    const body = {
      user_id: WD.userId(), stock_symbol: WD.val('sip-symbol'), stock_name: WD.val('sip-name'),
      exchange: WD.val('sip-exchange'), sip_amount: WD.val('sip-amount'),
      frequency: WD.val('sip-frequency'), sip_day: WD.val('sip-day'),
      start_date: WD.val('sip-start'), end_date: WD.val('sip-end') || null,
      broker: WD.val('sip-broker'), notes: WD.val('sip-notes'),
    };
    const res = id ? await WD.put(`/stocks/sip/${id}`, body) : await WD.post('/stocks/sip', body);
    if (res.status === 'success') {
      WD.closeModal('sip-modal'); WD.toast('SIP saved'); this.loadSummary(); this.loadSIPs(true);
    } else WD.toast(res.message || 'Error', 'error');
  },

  openInstallModal(sipId, symbol, amount) {
    WD.setVal('sip-install-sip-id', sipId);
    WD.setVal('sip-install-date', new Date().toISOString().split('T')[0]);
    WD.setVal('sip-install-price', ''); WD.setVal('sip-install-qty', '');
    WD.setVal('sip-install-status', 'EXECUTED');
    document.getElementById('sip-install-label').textContent = `${symbol} — SIP ₹${amount}`;
    WD.openModal('sip-install-modal');
  },

  async recordInstallment() {
    const sipId = WD.val('sip-install-sip-id');
    const price = parseFloat(WD.val('sip-install-price') || 0);
    const body = {
      user_id: WD.userId(), installment_date: WD.val('sip-install-date'),
      price, quantity: WD.val('sip-install-qty') || null,
      status: WD.val('sip-install-status'),
    };
    const res = await WD.post(`/stocks/sip/${sipId}/installments`, body);
    if (res.status === 'success') {
      WD.closeModal('sip-install-modal'); WD.toast(`Installment recorded — ₹${res.amount}`);
      this.loadSummary(); this.loadSIPs(true);
    } else WD.toast(res.message || 'Error', 'error');
  },

  async stopSIP(id) {
    if (!confirm('Stop this SIP?')) return;
    const res = await WD.del(`/stocks/sip/${id}?user_id=${WD.userId()}`);
    if (res.status === 'success') { WD.toast('SIP stopped'); this.loadSIPs(true); this.loadSIPs(false); }
  },

  async restartSIP(id) {
    const res = await WD.put(`/stocks/sip/${id}`, { user_id: WD.userId(), is_active: 1 });
    if (res.status === 'success') { WD.toast('SIP restarted'); this.loadSIPs(true); this.loadSIPs(false); }
  },
};


/* ─────────────────────────────────────────────
   t38 — SCREENER
──────────────────────────────────────────────*/
const Screener = {
  page: 1,

  async init() {
    if (!document.getElementById('page-screener')) return;
    WD.setupModalClose('scr-save-modal');
    WD.setupModalClose('scr-load-modal');
    document.getElementById('scr-run-btn').addEventListener('click', () => { this.page = 1; this.run(); });
    document.getElementById('scr-reset-btn').addEventListener('click', () => this.reset());
    document.getElementById('scr-save-filter-btn').addEventListener('click', () => WD.openModal('scr-save-modal'));
    document.getElementById('scr-load-filter-btn').addEventListener('click', () => this.openLoadModal());
    document.getElementById('scr-save-confirm').addEventListener('click', () => this.saveFilter());
    document.getElementById('scr-save-cancel').addEventListener('click', () => WD.closeModal('scr-save-modal'));
  },

  buildParams() {
    const p = new URLSearchParams();
    const add = (id, key) => { const v = WD.val(id); if (v) p.set(key, v); };
    add('scr-pe-min', 'pe_ratio[min]'); add('scr-pe-max', 'pe_ratio[max]');
    add('scr-pb-min', 'pb_ratio[min]'); add('scr-pb-max', 'pb_ratio[max]');
    add('scr-roe-min', 'roe[min]'); add('scr-roce-min', 'roce[min]');
    add('scr-de-max', 'debt_to_equity[max]'); add('scr-cr-min', 'current_ratio[min]');
    add('scr-dy-min', 'dividend_yield[min]');
    add('scr-rev-min', 'revenue_growth_1y[min]'); add('scr-prof-min', 'profit_growth_1y[min]');
    add('scr-price-min', 'current_price[min]'); add('scr-price-max', 'current_price[max]');
    add('scr-q', 'q'); add('scr-exchange', 'exchange');
    if (document.getElementById('scr-excl-null-pe')?.checked) p.set('exclude_null_pe', '1');
    const cats = [...document.getElementById('scr-cap-cat').selectedOptions].map(o => o.value);
    cats.forEach(c => p.append('market_cap_category[]', c));
    p.set('sort', WD.val('scr-sort')); p.set('dir', WD.val('scr-dir'));
    p.set('page', this.page); p.set('limit', '50');
    return p.toString();
  },

  async run() {
    const params = this.buildParams();
    const res = await WD.get(`/screener?${params}`);
    if (res.status !== 'success') return;
    document.getElementById('scr-result-count').textContent = `${res.total} results`;
    const tbody = document.getElementById('scr-table-body');
    tbody.innerHTML = res.data.map(r => `
      <tr>
        <td><strong>${r.stock_symbol}</strong></td>
        <td>${r.stock_name}</td>
        <td>${r.sector || '—'}</td>
        <td><span class="wd-badge wd-badge--${r.market_cap_category?.toLowerCase()}">${r.market_cap_category || '—'}</span></td>
        <td class="wd-num">${WD.fmt.inr(r.current_price)}</td>
        <td class="wd-num">${r.pe_ratio ?? '—'}</td>
        <td class="wd-num">${r.pb_ratio ?? '—'}</td>
        <td class="wd-num">${WD.fmt.pct(r.roe)}</td>
        <td class="wd-num">${WD.fmt.pct(r.roce)}</td>
        <td class="wd-num">${r.debt_to_equity ?? '—'}</td>
        <td class="wd-num">${WD.fmt.pct(r.dividend_yield)}</td>
        <td class="wd-num ${parseFloat(r.revenue_growth_1y) >= 0 ? 'wd-pos' : 'wd-neg'}">${WD.fmt.pct(r.revenue_growth_1y)}</td>
        <td class="wd-num ${parseFloat(r.profit_growth_1y) >= 0 ? 'wd-pos' : 'wd-neg'}">${WD.fmt.pct(r.profit_growth_1y)}</td>
        <td class="wd-num">${WD.fmt.inr(r.price_52w_high)}</td>
        <td class="wd-num">${WD.fmt.inr(r.price_52w_low)}</td>
        <td class="wd-actions">
          <button class="wd-btn wd-btn--ghost wd-btn--xs" onclick="WatchlistMgr.openAddModal(); WD.setVal('wl-symbol','${r.stock_symbol}'); WD.setVal('wl-name','${r.stock_name.replace(/'/g,"\\'")}')">+ Watch</button>
        </td>
      </tr>`).join('') || '<tr><td colspan="16" class="wd-empty">No results.</td></tr>';
  },

  reset() {
    ['scr-pe-min','scr-pe-max','scr-pb-min','scr-pb-max','scr-roe-min','scr-roce-min',
     'scr-de-max','scr-cr-min','scr-dy-min','scr-rev-min','scr-prof-min','scr-price-min',
     'scr-price-max','scr-q','scr-exchange'].forEach(id => WD.setVal(id, ''));
    if (document.getElementById('scr-excl-null-pe')) document.getElementById('scr-excl-null-pe').checked = false;
    document.querySelectorAll('#scr-cap-cat option').forEach(o => o.selected = false);
    WD.setText('scr-result-count', '');
    document.getElementById('scr-table-body').innerHTML = '<tr><td colspan="16" class="wd-empty">Set filters and click Run Screen.</td></tr>';
  },

  async saveFilter() {
    const name = WD.val('scr-filter-name');
    if (!name) { WD.toast('Filter name required', 'error'); return; }
    const config = {
      pe: [WD.val('scr-pe-min'), WD.val('scr-pe-max')], pb: [WD.val('scr-pb-min'), WD.val('scr-pb-max')],
      roe: WD.val('scr-roe-min'), roce: WD.val('scr-roce-min'), de: WD.val('scr-de-max'),
      cr: WD.val('scr-cr-min'), dy: WD.val('scr-dy-min'), rev: WD.val('scr-rev-min'),
      prof: WD.val('scr-prof-min'), price: [WD.val('scr-price-min'), WD.val('scr-price-max')],
      q: WD.val('scr-q'), exchange: WD.val('scr-exchange'),
      sort: WD.val('scr-sort'), dir: WD.val('scr-dir'),
    };
    const res = await WD.post('/screener/filters', {
      user_id: WD.userId(), filter_name: name, filter_config: config,
      is_default: document.getElementById('scr-filter-default')?.checked ? 1 : 0,
    });
    if (res.status === 'success') { WD.closeModal('scr-save-modal'); WD.toast('Filter saved'); }
    else WD.toast(res.message || 'Error', 'error');
  },

  async openLoadModal() {
    const res = await WD.get(`/screener/filters?user_id=${WD.userId()}`);
    const list = document.getElementById('scr-saved-filters-list');
    if (!res.data?.length) { list.innerHTML = '<div class="wd-empty">No saved filters.</div>'; WD.openModal('scr-load-modal'); return; }
    list.innerHTML = res.data.map(f => `
      <div class="wd-list-item">
        <span>${f.filter_name}${f.is_default ? ' ⭐' : ''}</span>
        <button class="wd-btn wd-btn--primary wd-btn--xs" onclick="Screener.applyFilter(${JSON.stringify(f.filter_config).replace(/"/g,'&quot;')})">Apply</button>
        <button class="wd-btn wd-btn--danger wd-btn--xs" onclick="Screener.deleteFilter(${f.id})">Del</button>
      </div>`).join('');
    WD.openModal('scr-load-modal');
  },

  applyFilter(config) {
    if (typeof config === 'string') config = JSON.parse(config);
    if (config.pe) { WD.setVal('scr-pe-min', config.pe[0]); WD.setVal('scr-pe-max', config.pe[1]); }
    if (config.roe) WD.setVal('scr-roe-min', config.roe);
    if (config.sort) WD.setVal('scr-sort', config.sort);
    WD.closeModal('scr-load-modal'); WD.toast('Filter applied'); this.run();
  },

  async deleteFilter(id) {
    const res = await WD.del(`/screener/filters/${id}?user_id=${WD.userId()}`);
    if (res.status === 'success') { WD.toast('Deleted'); this.openLoadModal(); }
  },
};


/* ─────────────────────────────────────────────
   t145 — REALITY CHECK
──────────────────────────────────────────────*/
const RealityCheck = {
  async init() {
    if (!document.getElementById('page-reality-check')) return;
    WD.setupTabGroup('#page-reality-check .wd-tab', '#page-reality-check .wd-tab-panel');
    document.getElementById('rc-run-btn').addEventListener('click', () => this.run());
    document.getElementById('rc-snapshot-btn').addEventListener('click', () => this.saveSnapshot());
    document.getElementById('rc-preset').addEventListener('change', () => this.applyPreset());
    WD.setVal('rc-to', new Date().toISOString().split('T')[0]);
    await this.loadHistory();
  },

  applyPreset() {
    const preset = WD.val('rc-preset'); if (!preset) return;
    const to = new Date(), from = new Date(to);
    if (preset === '1y') from.setFullYear(from.getFullYear() - 1);
    else if (preset === '3y') from.setFullYear(from.getFullYear() - 3);
    else if (preset === '5y') from.setFullYear(from.getFullYear() - 5);
    else if (preset === 'fy') {
      const m = from.getMonth(); from.setFullYear(m >= 3 ? from.getFullYear() : from.getFullYear() - 1);
      from.setMonth(3); from.setDate(1);
    }
    WD.setVal('rc-from', from.toISOString().split('T')[0]);
    WD.setVal('rc-to', to.toISOString().split('T')[0]);
  },

  async run() {
    const from = WD.val('rc-from'), to = WD.val('rc-to');
    let url = `/stocks/reality-check?user_id=${WD.userId()}&to=${to}`;
    if (from) url += `&from=${from}`;
    const res = await WD.get(url);
    if (res.status !== 'success') return;

    const xirr = res.portfolio.xirr_pct;
    const nifty = res.benchmark.cagr_pct;
    const alpha = res.alpha_pct;

    WD.setText('rc-xirr', xirr != null ? WD.fmt.pct(xirr) : '—');
    WD.setText('rc-nifty-cagr', nifty != null ? WD.fmt.pct(nifty) : '—');
    WD.setText('rc-alpha', alpha != null ? WD.fmt.pct(alpha) : '—');
    WD.setText('rc-win-lose', `${res.winners_count}W / ${res.losers_count}L`);

    const verdict = document.getElementById('rc-verdict');
    verdict.style.display = '';
    verdict.className = `wd-verdict-banner ${alpha > 0 ? 'wd-verdict-banner--pos' : alpha < 0 ? 'wd-verdict-banner--neg' : ''}`;
    WD.setText('rc-verdict-icon', alpha > 2 ? '🏆' : alpha > 0 ? '✅' : alpha > -2 ? '↔️' : '⚠️');
    WD.setText('rc-verdict-text', res.verdict);

    const tbody = document.getElementById('rc-stock-body');
    tbody.innerHTML = (res.per_stock || []).map(r => `<tr>
      <td><strong>${r.stock_symbol}</strong></td>
      <td>${r.stock_name}</td>
      <td class="wd-num">${WD.fmt.inr(r.invested)}</td>
      <td class="wd-num">${WD.fmt.inr(r.realised)}</td>
      <td class="wd-num ${WD.pnlClass(r.returns_pct)}">${WD.fmt.pct(r.returns_pct)}</td>
      <td><span class="wd-badge wd-badge--${parseFloat(r.returns_pct) > (nifty || 0) ? 'success' : 'danger'}">${parseFloat(r.returns_pct) > (nifty || 0) ? 'Beat' : 'Lagged'}</span></td>
    </tr>`).join('') || '<tr><td colspan="6" class="wd-empty">No data.</td></tr>';
  },

  async saveSnapshot() {
    const res = await WD.post('/stocks/snapshot', { user_id: WD.userId() });
    if (res.status === 'success') { WD.toast(`Snapshot saved. Alpha: ${WD.fmt.pct(res.alpha)}`); this.loadHistory(); }
  },

  async loadHistory() {
    const res = await WD.get(`/stocks/snapshot-history?user_id=${WD.userId()}`);
    if (res.status !== 'success') return;
    const tbody = document.getElementById('rc-history-body');
    tbody.innerHTML = res.data.map(r => `<tr>
      <td>${WD.fmt.date(r.snapshot_date)}</td>
      <td class="wd-num">${WD.fmt.inr(r.portfolio_value)}</td>
      <td class="wd-num">${WD.fmt.inr(r.invested_value)}</td>
      <td class="wd-num ${WD.pnlClass(r.xirr)}">${WD.fmt.pct(r.xirr)}</td>
      <td class="wd-num">${WD.fmt.pct(r.nifty50_returns)}</td>
      <td class="wd-num ${WD.pnlClass(r.alpha)}">${WD.fmt.pct(r.alpha)}</td>
    </tr>`).join('') || '<tr><td colspan="6" class="wd-empty">No snapshots.</td></tr>';
  },
};


/* ─────────────────────────────────────────────
   t432 — PORTFOLIO P/E
──────────────────────────────────────────────*/
const PortfolioPE = {
  async init() {
    if (!document.getElementById('page-portfolio-pe')) return;
    WD.setupTabGroup('#page-portfolio-pe .wd-tab', '#page-portfolio-pe .wd-tab-panel');
    document.getElementById('pe-refresh-btn').addEventListener('click', () => this.load());
    document.getElementById('pe-history-index')?.addEventListener('change', () => this.loadMarketHistory());
    document.getElementById('pe-history-range')?.addEventListener('change', () => this.loadMarketHistory());
    await this.load();
  },

  async load() {
    const res = await WD.get(`/stocks/pe-analysis?user_id=${WD.userId()}`);
    if (res.status !== 'success') return;
    const p = res.portfolio, m = res.market;
    WD.setText('pe-portfolio-pe', p.weighted_pe ?? '—');
    WD.setText('pe-portfolio-pb', p.weighted_pb ?? '—');
    WD.setText('pe-market-pe', m.nifty50_pe ?? '—');
    WD.setText('pe-market-pb', m.nifty50_pb ?? '—');
    WD.setText('pe-market-date', `as of ${WD.fmt.date(m.data_date)}`);
    document.getElementById('pe-valuation-comment').textContent = res.valuation;

    const tbody = document.getElementById('pe-holdings-body');
    tbody.innerHTML = (res.holdings || []).map(r => `<tr>
      <td><strong>${r.stock_symbol}</strong></td>
      <td>${r.stock_name}</td>
      <td>${r.sector || '—'}</td>
      <td>${r.market_cap_category || '—'}</td>
      <td class="wd-num">${WD.fmt.num(r.qty)}</td>
      <td class="wd-num">${WD.fmt.inr(r.current_price)}</td>
      <td class="wd-num">${WD.fmt.inr(r.value)}</td>
      <td class="wd-num">${r.weight_pct ?? '—'}%</td>
      <td class="wd-num">${r.pe ?? '—'}</td>
      <td class="wd-num">${r.pb ?? '—'}</td>
    </tr>`).join('') || '<tr><td colspan="10" class="wd-empty">No holdings.</td></tr>';

    const stbody = document.getElementById('pe-sector-body');
    stbody.innerHTML = (res.by_sector || []).map(r => `<tr>
      <td>${r.sector}</td>
      <td class="wd-num">${WD.fmt.inr(r.value)}</td>
      <td class="wd-num">${r.weight_pct}%</td>
      <td class="wd-num">${r.avg_pe ?? '—'}</td>
    </tr>`).join('');

    await this.loadMarketHistory();
  },

  async loadMarketHistory() {
    const index = WD.val('pe-history-index') || 'NIFTY50';
    const days  = WD.val('pe-history-range') || '365';
    const res = await WD.get(`/stocks/market-pe?index=${index}&days=${days}`);
    if (res.status !== 'success') return;
    const tbody = document.getElementById('pe-history-body');
    tbody.innerHTML = [...res.history].reverse().map(r => `<tr>
      <td>${WD.fmt.date(r.data_date)}</td>
      <td class="wd-num">${r.pe_ratio}</td>
      <td class="wd-num">${r.pb_ratio ?? '—'}</td>
      <td class="wd-num">${r.div_yield ?? '—'}</td>
    </tr>`).join('') || '<tr><td colspan="4" class="wd-empty">No history.</td></tr>';
  },
};


/* ─────────────────────────────────────────────
   t116, t121, t118 — Bonds / Intl / RBI
   (init stubs — full render logic follows same
    pattern as above; wired to API endpoints)
──────────────────────────────────────────────*/
const BondsMgr = {
  async init() {
    if (!document.getElementById('page-bonds')) return;
    WD.setupModalClose('bonds-modal');
    WD.setupModalClose('bonds-receive-modal');
    document.getElementById('bonds-add-btn').addEventListener('click', () => this.openAddModal());
    document.getElementById('bonds-save').addEventListener('click', () => this.save());
    document.getElementById('bonds-cancel').addEventListener('click', () => WD.closeModal('bonds-modal'));
    document.getElementById('bonds-cf-save').addEventListener('click', () => this.markReceived());
    document.getElementById('bonds-cf-cancel').addEventListener('click', () => WD.closeModal('bonds-receive-modal'));
    document.getElementById('bonds-cf-close').addEventListener('click', () => { document.getElementById('bonds-cashflow-panel').style.display = 'none'; });
    document.getElementById('bonds-upcoming-btn').addEventListener('click', () => this.showUpcoming());
    ['bonds-type-filter','bonds-listing-filter'].forEach(id =>
      document.getElementById(id).addEventListener('change', () => this.load()));
    document.getElementById('bonds-search').addEventListener('input', () => this.load());
    ['bonds-fv','bonds-qty','bonds-coupon'].forEach(id =>
      document.getElementById(id)?.addEventListener('input', () => this.updatePreview()));
    await this.loadSummary();
    await this.load();
  },

  async loadSummary() {
    const res = await WD.get(`/bonds/summary?user_id=${WD.userId()}`);
    if (res.status !== 'success') return;
    let totalInvested = 0, totalFV = 0, couponSum = 0, count = 0;
    res.by_type.forEach(r => {
      totalInvested += parseFloat(r.total_invested || 0);
      totalFV += parseFloat(r.total_face_value || 0);
      couponSum += parseFloat(r.avg_coupon_rate || 0) * r.count;
      count += r.count;
    });
    WD.setText('bonds-total-invested', WD.fmt.inr(totalInvested));
    WD.setText('bonds-total-fv', WD.fmt.inr(totalFV));
    WD.setText('bonds-avg-coupon', count ? WD.fmt.pct(couponSum / count) : '—');
    const upcoming = res.upcoming_cashflows_90d || [];
    WD.setText('bonds-upcoming-sum', WD.fmt.inr(upcoming.reduce((s, r) => s + parseFloat(r.amount || 0), 0)));
  },

  async load() {
    const type = WD.val('bonds-type-filter'), listing = WD.val('bonds-listing-filter'), q = WD.val('bonds-search').toLowerCase();
    let url = `/bonds?user_id=${WD.userId()}`;
    if (type) url += `&type=${type}`;
    if (listing) url += `&listing=${listing}`;
    const res = await WD.get(url);
    if (res.status !== 'success') return;
    let rows = res.data;
    if (q) rows = rows.filter(r => r.issuer_name.toLowerCase().includes(q));
    const tbody = document.getElementById('bonds-table-body');
    tbody.innerHTML = rows.map(r => `<tr>
      <td>${r.issuer_name}</td>
      <td><span class="wd-badge">${r.bond_type}</span></td>
      <td><span class="wd-badge wd-badge--${r.listing_type === 'LISTED' ? 'success' : 'warn'}">${r.listing_type}</span></td>
      <td>${r.credit_rating || '—'}</td>
      <td class="wd-num">${WD.fmt.inr(r.face_value)}</td>
      <td class="wd-num">${r.quantity}</td>
      <td class="wd-num">${WD.fmt.inr(r.purchase_price)}</td>
      <td class="wd-num">${WD.fmt.pct(r.coupon_rate)}</td>
      <td class="wd-num">${r.ytm != null ? WD.fmt.pct(r.ytm) : '—'}</td>
      <td>${WD.fmt.date(r.maturity_date)}</td>
      <td>${r.days_to_maturity}</td>
      <td class="wd-actions">
        <button class="wd-btn wd-btn--ghost wd-btn--xs" onclick="BondsMgr.showCashflows(${r.id}, '${r.issuer_name.replace(/'/g,"\\'")}')">CFs</button>
        <button class="wd-btn wd-btn--ghost wd-btn--xs" onclick="BondsMgr.edit(${r.id})">Edit</button>
        <button class="wd-btn wd-btn--danger wd-btn--xs" onclick="BondsMgr.del(${r.id})">Del</button>
      </td>
    </tr>`).join('') || '<tr><td colspan="12" class="wd-empty">No bonds.</td></tr>';
  },

  async showCashflows(bondId, name) {
    const res = await WD.get(`/bonds/${bondId}/cashflows?user_id=${WD.userId()}`);
    if (res.status !== 'success') return;
    document.getElementById('bonds-cf-title').textContent = `Cashflows — ${name}`;
    const tbody = document.getElementById('bonds-cf-body');
    tbody.innerHTML = res.data.map(r => `<tr>
      <td>${WD.fmt.date(r.scheduled_date)}</td>
      <td>${r.cashflow_type}</td>
      <td class="wd-num">${WD.fmt.inr(r.amount)}</td>
      <td>${r.received ? `<span class="wd-badge wd-badge--success">✓ ${WD.fmt.date(r.received_date)}</span>` : '<span class="wd-badge">Pending</span>'}</td>
      <td class="wd-num">${r.tds_deducted ? WD.fmt.inr(r.tds_deducted) : '—'}</td>
      <td class="wd-num">${r.net_amount ? WD.fmt.inr(r.net_amount) : '—'}</td>
      <td class="wd-actions">${!r.received ? `<button class="wd-btn wd-btn--primary wd-btn--xs" onclick="BondsMgr.openReceiveModal(${r.id})">Mark Received</button>` : ''}</td>
    </tr>`).join('');
    document.getElementById('bonds-cashflow-panel').style.display = '';
  },

  openReceiveModal(cfId) {
    WD.setVal('bonds-cf-id', cfId); WD.setVal('bonds-cf-recv-date', new Date().toISOString().split('T')[0]); WD.setVal('bonds-cf-tds', '0');
    WD.openModal('bonds-receive-modal');
  },

  async markReceived() {
    const id = WD.val('bonds-cf-id');
    const res = await WD.put(`/bonds/cashflows/${id}`, { user_id: WD.userId(), received_date: WD.val('bonds-cf-recv-date'), tds_deducted: WD.val('bonds-cf-tds') });
    if (res.status === 'success') { WD.closeModal('bonds-receive-modal'); WD.toast(`Received. Net: ${WD.fmt.inr(res.net_amount)}`); }
  },

  updatePreview() {
    const fv = parseFloat(WD.val('bonds-fv') || 0), qty = parseInt(WD.val('bonds-qty') || 0);
    const pp = parseFloat(WD.val('bonds-pp') || 0), coupon = parseFloat(WD.val('bonds-coupon') || 0);
    WD.setText('bonds-preview-fv', WD.fmt.inr(fv * qty));
    WD.setText('bonds-preview-invested', WD.fmt.inr(pp * qty));
    WD.setText('bonds-preview-interest', WD.fmt.inr(fv * qty * coupon / 100));
  },

  openAddModal() {
    WD.setVal('bonds-id', '');
    ['bonds-issuer','bonds-isin','bonds-series','bonds-pp','bonds-coupon','bonds-rating','bonds-agency','bonds-broker','bonds-notes'].forEach(id => WD.setVal(id, ''));
    WD.setVal('bonds-fv', '1000'); WD.setVal('bonds-qty', ''); WD.setVal('bonds-type', 'NCD');
    WD.setVal('bonds-listing', 'LISTED'); WD.setVal('bonds-coupon-freq', 'SEMI_ANNUAL');
    WD.setVal('bonds-redemption', 'BULLET');
    WD.setVal('bonds-pd', new Date().toISOString().split('T')[0]);
    document.getElementById('bonds-secured').checked = true;
    document.getElementById('bonds-modal-title').textContent = 'Add Bond / NCD';
    WD.openModal('bonds-modal');
  },

  async save() {
    const id = WD.val('bonds-id');
    const body = {
      user_id: WD.userId(), bond_type: WD.val('bonds-type'), listing_type: WD.val('bonds-listing'),
      issuer_name: WD.val('bonds-issuer'), isin: WD.val('bonds-isin'), series: WD.val('bonds-series'),
      face_value: WD.val('bonds-fv'), quantity: WD.val('bonds-qty'),
      purchase_price: WD.val('bonds-pp'), purchase_date: WD.val('bonds-pd'), maturity_date: WD.val('bonds-md'),
      coupon_rate: WD.val('bonds-coupon'), coupon_frequency: WD.val('bonds-coupon-freq'),
      credit_rating: WD.val('bonds-rating'), rating_agency: WD.val('bonds-agency'),
      secured: document.getElementById('bonds-secured')?.checked ? 1 : 0,
      broker: WD.val('bonds-broker'), redemption_type: WD.val('bonds-redemption'), notes: WD.val('bonds-notes'),
    };
    const res = id ? await WD.put(`/bonds/${id}`, body) : await WD.post('/bonds', body);
    if (res.status === 'success') { WD.closeModal('bonds-modal'); WD.toast('Bond saved'); this.loadSummary(); this.load(); }
    else WD.toast(res.message || 'Error', 'error');
  },

  async del(id) {
    if (!confirm('Delete this bond?')) return;
    const res = await WD.del(`/bonds/${id}?user_id=${WD.userId()}`);
    if (res.status === 'success') { WD.toast('Deleted'); this.load(); this.loadSummary(); }
  },

  async showUpcoming() {
    const res = await WD.get(`/bonds/upcoming?user_id=${WD.userId()}&days=90`);
    if (res.status !== 'success') return;
    WD.toast(`${res.data.length} cashflows due in 90 days`, 'info');
  },
};


/* ─────────────────────────────────────────────
   BOOTSTRAP — init all modules on page load
──────────────────────────────────────────────*/
document.addEventListener('DOMContentLoaded', () => {
  TaxReport.init();
  GoldTracker.init();
  WatchlistMgr.init();
  StockSIPMgr.init();
  Screener.init();
  RealityCheck.init();
  PortfolioPE.init();
  BondsMgr.init();
  // International & RBI follow same pattern — init stubs below
  if (document.getElementById('page-international')) {
    WD.get(`/international/summary?user_id=${WD.userId()}`).then(res => {
      if (res.status !== 'success') return;
      const u = res.lrs_utilization;
      WD.setText('intl-lrs-used', WD.fmt.inr(u.remitted_inr));
      WD.setText('intl-lrs-pct', `${u.utilized_pct}% of ₹2.5Cr`);
      document.getElementById('intl-lrs-bar').style.width = Math.min(u.utilized_pct, 100) + '%';
      if (u.tcs_applicable) WD.show('intl-tcs-badge');
    });
  }
  if (document.getElementById('page-rbi')) {
    WD.get(`/rbi/summary?user_id=${WD.userId()}`).then(res => {
      if (res.status !== 'success') return;
      let ti = 0, tf = 0, cs = 0, cnt = 0;
      res.by_type.forEach(r => { ti += parseFloat(r.total_invested||0); tf += parseFloat(r.total_face_value||0); cs += parseFloat(r.avg_coupon||0)*r.count; cnt += r.count; });
      WD.setText('rbi-total-invested', WD.fmt.inr(ti));
      WD.setText('rbi-total-fv', WD.fmt.inr(tf));
      WD.setText('rbi-avg-coupon', cnt ? WD.fmt.pct(cs/cnt) : '—');
      const up = res.upcoming_180d || [];
      WD.setText('rbi-upcoming-sum', WD.fmt.inr(up.reduce((s,r) => s + parseFloat(r.amount||0), 0)));
    });
  }
});
