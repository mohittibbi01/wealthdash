/**
 * WealthDash — Crypto Module (t24)
 * public/js/crypto.js
 * Holdings table + live prices + P&L + VDA Tax tab + Add modal
 */
const CRYPTO = (() => {
  'use strict';

  // ── State ──────────────────────────────────────────────────────────────
  let _holdings    = [];
  let _prices      = {};
  let _tab         = 'holdings';   // holdings | transactions | tax
  let _refreshTimer = null;

  // Popular coins list for Add modal autocomplete
  const POPULAR_COINS = [
    {id:'bitcoin',       sym:'BTC',  name:'Bitcoin'},
    {id:'ethereum',      sym:'ETH',  name:'Ethereum'},
    {id:'tether',        sym:'USDT', name:'Tether'},
    {id:'binancecoin',   sym:'BNB',  name:'BNB'},
    {id:'solana',        sym:'SOL',  name:'Solana'},
    {id:'ripple',        sym:'XRP',  name:'XRP'},
    {id:'cardano',       sym:'ADA',  name:'Cardano'},
    {id:'dogecoin',      sym:'DOGE', name:'Dogecoin'},
    {id:'avalanche-2',   sym:'AVAX', name:'Avalanche'},
    {id:'polkadot',      sym:'DOT',  name:'Polkadot'},
    {id:'matic-network', sym:'MATIC',name:'Polygon'},
    {id:'litecoin',      sym:'LTC',  name:'Litecoin'},
    {id:'shiba-inu',     sym:'SHIB', name:'Shiba Inu'},
    {id:'chainlink',     sym:'LINK', name:'Chainlink'},
    {id:'uniswap',       sym:'UNI',  name:'Uniswap'},
    {id:'wazirx',        sym:'WRX',  name:'WazirX'},
  ];

  // ── Formatters ─────────────────────────────────────────────────────────
  const fmtInr = v => {
    if (!v && v !== 0) return '—';
    const abs = Math.abs(v);
    const fmt = abs >= 1e7 ? (abs/1e7).toFixed(2) + ' Cr'
              : abs >= 1e5 ? (abs/1e5).toFixed(2) + ' L'
              : abs.toLocaleString('en-IN', {minimumFractionDigits:0, maximumFractionDigits:0});
    return (v < 0 ? '−' : '') + '₹' + fmt;
  };
  const fmtQty = v => {
    const n = parseFloat(v);
    if (isNaN(n)) return '—';
    return n >= 1 ? n.toFixed(4) : n.toFixed(8);
  };
  const pctBadge = pct => {
    const cl = pct >= 0 ? 'gain-pos' : 'gain-neg';
    const sign = pct >= 0 ? '+' : '';
    return `<span class="gain-badge ${cl}">${sign}${parseFloat(pct).toFixed(2)}%</span>`;
  };
  const coinIcon = sym => {
    // Simple letter avatar if no image
    const colors = ['#f7931a','#627eea','#26a17b','#f0b90b','#9945ff','#00aae4',
                    '#0033ad','#ba9f33','#e84142','#e6007a','#7b3fe4','#2775ca'];
    const idx = (sym.charCodeAt(0) + (sym.charCodeAt(1)||0)) % colors.length;
    return `<div style="width:32px;height:32px;border-radius:50%;background:${colors[idx]};
                 display:flex;align-items:center;justify-content:center;
                 color:#fff;font-size:11px;font-weight:700;flex-shrink:0">${sym.slice(0,3)}</div>`;
  };

  // ── Init ───────────────────────────────────────────────────────────────
  function init() {
    _renderShell();
    _loadHoldings();
    // Auto-refresh prices every 60s
    _refreshTimer = setInterval(() => {
      if (_tab === 'holdings') _loadHoldings(true);
    }, 60000);
  }

  // ── Shell HTML ─────────────────────────────────────────────────────────
  function _renderShell() {
    const wrap = document.getElementById('cryptoApp');
    if (!wrap) return;
    wrap.innerHTML = `
<!-- Stat cards -->
<div id="cryptoStats" class="stats-grid" style="margin-bottom:20px">
  <div class="stat-card"><div class="stat-label">Holdings</div><div class="stat-value" id="cStatCoins">—</div></div>
  <div class="stat-card"><div class="stat-label">Invested</div><div class="stat-value" id="cStatInv">—</div></div>
  <div class="stat-card"><div class="stat-label">Current Value</div><div class="stat-value" id="cStatCur">—</div></div>
  <div class="stat-card">
    <div class="stat-label">P&amp;L</div>
    <div class="stat-value" id="cStatGain">—</div>
  </div>
</div>

<!-- Tab bar -->
<div style="display:flex;gap:4px;margin-bottom:16px;border-bottom:1.5px solid var(--border);padding-bottom:0">
  <button class="crypto-tab active" id="ctab_holdings"  onclick="CRYPTO.switchTab('holdings')">💼 Holdings</button>
  <button class="crypto-tab"        id="ctab_transactions" onclick="CRYPTO.switchTab('transactions')">📋 Transactions</button>
  <button class="crypto-tab"        id="ctab_tax"       onclick="CRYPTO.switchTab('tax')">🧾 VDA Tax</button>
</div>

<!-- Actions bar -->
<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;flex-wrap:wrap" id="cActionBar">
  <button class="btn btn-primary btn-sm" onclick="CRYPTO.openAddModal()">
    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Coin
  </button>
  <button class="btn btn-secondary btn-sm" id="btnRefreshPrices" onclick="CRYPTO.refreshPrices()">
    🔄 Refresh Prices
  </button>
  <span id="cPriceTime" style="font-size:11px;color:var(--text-muted);margin-left:4px"></span>
</div>

<!-- Content area -->
<div id="cryptoContent"></div>

<!-- Add Coin Modal -->
<div id="cryptoModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;display:none;align-items:center;justify-content:center">
  <div style="background:var(--card-bg);border-radius:16px;padding:28px;width:100%;max-width:480px;position:relative;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <button onclick="CRYPTO.closeModal()" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted)">✕</button>
    <h3 style="margin:0 0 20px;font-size:18px;font-weight:700">Add Crypto Holding</h3>
    <div id="cryptoModalBody"></div>
  </div>
</div>
`;
    _injectStyles();
  }

  // ── Load Holdings ──────────────────────────────────────────────────────
  async function _loadHoldings(silent = false) {
    const content = document.getElementById('cryptoContent');
    if (!silent && content) content.innerHTML = '<div class="wd-loader" style="margin:40px auto"></div>';

    try {
      const data = await API.get('crypto_list');
      if (!data.success) throw new Error(data.message);
      _holdings = data.holdings || [];
      _updateStats(data);
      renderHoldings(_holdings);
      const pt = document.getElementById('cPriceTime');
      if (pt) pt.textContent = 'Prices: ' + (data.prices_updated || '');
    } catch (e) {
      if (content) content.innerHTML = `<div class="wd-empty">⚠️ ${e.message}</div>`;
    }
  }

  function _updateStats(data) {
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.innerHTML = v; };
    set('cStatCoins', _holdings.length);
    set('cStatInv',   fmtInr(data.total_invested));
    set('cStatCur',   fmtInr(data.total_current));
    const g = data.total_gain || 0;
    const gp = data.total_gain_pct || 0;
    set('cStatGain', `<span style="color:${g>=0?'var(--green)':'var(--red)'}">${fmtInr(g)}</span>
                      <small style="display:block;font-size:12px;color:var(--text-muted)">${pctBadge(gp)}</small>`);
  }

  // ── Render Holdings Table ──────────────────────────────────────────────
  function renderHoldings(data) {
    const content = document.getElementById('cryptoContent');
    if (!content) return;

    if (!data || !data.length) {
      content.innerHTML = `<div class="wd-empty" style="padding:60px 20px;text-align:center">
        <div style="font-size:48px;margin-bottom:12px">₿</div>
        <div style="font-size:15px;font-weight:600;margin-bottom:6px">Koi crypto holding nahi</div>
        <div style="font-size:13px;color:var(--text-muted);margin-bottom:20px">
          "Add Coin" karo apna Bitcoin, ETH ya koi bhi coin track karne ke liye
        </div>
        <button class="btn btn-primary" onclick="CRYPTO.openAddModal()">+ Add First Coin</button>
      </div>`;
      return;
    }

    const rows = data.map(h => {
      const gainCls = h.gain_inr >= 0 ? 'var(--green)' : 'var(--red)';
      const chg24 = h.change_24h != null
        ? `<div style="font-size:11px;color:${h.change_24h>=0?'var(--green)':'var(--red)'};margin-top:2px">
             ${h.change_24h>=0?'▲':'▼'} ${Math.abs(parseFloat(h.change_24h)).toFixed(2)}% (24h)
           </div>`
        : '';
      return `<tr>
        <td>
          <div style="display:flex;align-items:center;gap:10px">
            ${coinIcon(h.coin_symbol)}
            <div>
              <div style="font-weight:700">${h.coin_name}</div>
              <div style="font-size:11px;color:var(--text-muted)">${h.coin_symbol}</div>
            </div>
          </div>
        </td>
        <td style="font-family:monospace">${fmtQty(h.quantity)}</td>
        <td>${h.price_inr > 0 ? fmtInr(h.price_inr) : '<span style="color:var(--text-muted)">—</span>'}${chg24}</td>
        <td>${fmtInr(h.avg_buy_price)}</td>
        <td>${fmtInr(h.total_invested)}</td>
        <td style="font-weight:600">${h.current_value > 0 ? fmtInr(h.current_value) : '—'}</td>
        <td>
          <div style="color:${gainCls};font-weight:600">${fmtInr(h.gain_inr)}</div>
          <div style="font-size:11px;margin-top:2px">${pctBadge(h.gain_pct)}</div>
        </td>
        <td style="font-size:12px;color:var(--text-muted)">${h.exchange || '—'}</td>
        <td>
          <button class="btn-icon-sm" onclick="CRYPTO.openAddModal('${h.coin_id}','${h.coin_symbol}','${h.coin_name}')" title="Add more">+</button>
          <button class="btn-icon-sm btn-danger-sm" onclick="CRYPTO.deleteHolding(${h.id},'${h.coin_name}')" title="Delete">✕</button>
        </td>
      </tr>`;
    }).join('');

    content.innerHTML = `
<div style="overflow-x:auto">
<table class="wd-table" style="width:100%;min-width:820px">
  <thead>
    <tr>
      <th>Coin</th><th>Quantity</th><th>Live Price</th><th>Avg Buy</th>
      <th>Invested</th><th>Current Value</th><th>P&amp;L</th><th>Exchange</th><th></th>
    </tr>
  </thead>
  <tbody>${rows}</tbody>
</table>
</div>
<div style="font-size:11px;color:var(--text-muted);margin-top:8px;padding:0 4px">
  ⚠️ Crypto gains par 30% flat tax lagta hai (Section 115BBH). VDA Tax tab mein details dekho.
</div>`;
  }

  // ── Render Transactions ────────────────────────────────────────────────
  async function _loadTransactions() {
    const content = document.getElementById('cryptoContent');
    content.innerHTML = '<div class="wd-loader" style="margin:40px auto"></div>';
    try {
      const data = await API.get('crypto_txns');
      const rows = (data || []).map(t => {
        const typeCls = t.txn_type === 'BUY' || t.txn_type === 'TRANSFER_IN' ? 'var(--green)' : 'var(--red)';
        return `<tr>
          <td>${t.txn_date}</td>
          <td><span style="font-weight:700;color:${typeCls}">${t.txn_type}</span></td>
          <td>${t.coin_symbol}</td>
          <td style="font-family:monospace">${fmtQty(t.quantity)}</td>
          <td>${fmtInr(t.price_inr)}</td>
          <td style="font-weight:600">${fmtInr(t.amount_inr)}</td>
          <td style="color:var(--text-muted)">${t.tds_deducted > 0 ? fmtInr(t.tds_deducted) : '—'}</td>
          <td style="font-size:12px;color:var(--text-muted)">${t.exchange || '—'}</td>
        </tr>`;
      }).join('');

      content.innerHTML = rows.length ? `
<div style="overflow-x:auto">
<table class="wd-table" style="width:100%;min-width:700px">
  <thead><tr><th>Date</th><th>Type</th><th>Coin</th><th>Qty</th><th>Price</th><th>Amount</th><th>TDS</th><th>Exchange</th></tr></thead>
  <tbody>${rows}</tbody>
</table>
</div>` : '<div class="wd-empty">Koi transaction nahi</div>';
    } catch (e) {
      content.innerHTML = `<div class="wd-empty">⚠️ ${e.message}</div>`;
    }
  }

  // ── VDA Tax Tab ────────────────────────────────────────────────────────
  async function _loadTax() {
    const content = document.getElementById('cryptoContent');
    content.innerHTML = '<div class="wd-loader" style="margin:40px auto"></div>';
    try {
      const data = await API.get('crypto_vda_tax');
      const d = data;
      const rows = (d.breakdown || []).map(r => `<tr>
        <td>${r.date}</td><td>${r.coin}</td>
        <td style="font-family:monospace">${fmtQty(r.qty)}</td>
        <td>${fmtInr(r.sale_value)}</td><td>${fmtInr(r.cost_basis)}</td>
        <td style="color:${r.gain>=0?'var(--green)':'var(--red)'}; font-weight:600">${fmtInr(r.gain)}</td>
        <td>${fmtInr(r.tax_payable)}</td>
        <td style="color:var(--text-muted)">${fmtInr(r.tds_deducted)}</td>
        <td style="font-weight:700;color:var(--red)">${fmtInr(r.net_tax_due)}</td>
      </tr>`).join('');

      content.innerHTML = `
<div class="stats-grid" style="margin-bottom:20px">
  <div class="stat-card"><div class="stat-label">${d.fy} Total Sales</div><div class="stat-value">${fmtInr(d.total_sale_value)}</div></div>
  <div class="stat-card"><div class="stat-label">Total Gains</div><div class="stat-value" style="color:var(--green)">${fmtInr(d.total_gain)}</div></div>
  <div class="stat-card"><div class="stat-label">Tax (30%)</div><div class="stat-value" style="color:var(--red)">${fmtInr(d.tax_payable)}</div></div>
  <div class="stat-card"><div class="stat-label">TDS Already Cut</div><div class="stat-value">${fmtInr(d.tds_deducted_sell)}</div></div>
  <div class="stat-card"><div class="stat-label">Net Tax Due</div><div class="stat-value" style="color:var(--red);font-weight:800">${fmtInr(d.net_tax_due)}</div></div>
</div>
<div style="background:var(--warning-bg,#fffbeb);border:1px solid var(--warning-border,#fcd34d);border-radius:10px;padding:12px 16px;margin-bottom:16px;font-size:12px">
  ⚠️ <strong>Section 115BBH:</strong> ${d.no_loss_offset_note || ''}<br>
  📋 <strong>ITR:</strong> ${d.itr_schedule || 'Schedule VDA in ITR-2/ITR-3'}
</div>
${rows ? `<div style="overflow-x:auto"><table class="wd-table" style="min-width:700px">
  <thead><tr><th>Date</th><th>Coin</th><th>Qty</th><th>Sale Value</th><th>Cost Basis</th><th>Gain/Loss</th><th>Tax @30%</th><th>TDS Cut</th><th>Net Due</th></tr></thead>
  <tbody>${rows}</tbody>
</table></div>` : '<div class="wd-empty">Is FY mein koi crypto sell nahi kiya</div>'}`;
    } catch (e) {
      content.innerHTML = `<div class="wd-empty">⚠️ ${e.message}</div>`;
    }
  }

  // ── Tab Switching ──────────────────────────────────────────────────────
  function switchTab(tab) {
    _tab = tab;
    document.querySelectorAll('.crypto-tab').forEach(b => b.classList.remove('active'));
    const el = document.getElementById('ctab_' + tab);
    if (el) el.classList.add('active');

    const bar = document.getElementById('cActionBar');
    if (bar) bar.style.display = tab === 'holdings' ? 'flex' : 'none';

    if (tab === 'holdings')     _loadHoldings();
    else if (tab === 'transactions') _loadTransactions();
    else if (tab === 'tax')     _loadTax();
  }

  // ── Refresh Prices ─────────────────────────────────────────────────────
  async function refreshPrices() {
    const btn = document.getElementById('btnRefreshPrices');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Refreshing…'; }
    try {
      await API.get('crypto_prices');
      await _loadHoldings(false);
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = '🔄 Refresh Prices'; }
    }
  }

  // ── Delete Holding ─────────────────────────────────────────────────────
  async function deleteHolding(id, name) {
    if (!confirm(`"${name}" holding delete karo? Sab transactions bhi rahenge (sirf holding record hatega).`)) return;
    try {
      const r = await API.post({ action: 'crypto_delete', id });
      if (!r.success) throw new Error(r.message);
      WD.toast(r.message || 'Deleted', 'success');
      _loadHoldings();
    } catch (e) { WD.toast(e.message, 'error'); }
  }

  // ── Add Coin Modal ─────────────────────────────────────────────────────
  function openAddModal(coinId = '', sym = '', name = '') {
    const body = document.getElementById('cryptoModalBody');
    if (!body) return;

    const coinOptions = POPULAR_COINS.map(c =>
      `<option value="${c.id}" data-sym="${c.sym}" data-name="${c.name}" ${c.id===coinId?'selected':''}>${c.sym} — ${c.name}</option>`
    ).join('');

    body.innerHTML = `
<div style="display:grid;gap:14px">
  <div>
    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Coin *</label>
    <select id="cAddCoinSel" onchange="CRYPTO._onCoinSelect(this)" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;background:var(--card-bg);color:var(--text)">
      <option value="">-- Select coin --</option>
      ${coinOptions}
      <option value="__custom">Other (manual entry)</option>
    </select>
  </div>
  <div id="cCustomCoinWrap" style="display:none;display:grid;grid-template-columns:1fr 1fr;gap:10px">
    <div>
      <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">CoinGecko ID *</label>
      <input id="cCoinId" placeholder="e.g. bitcoin" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
    </div>
    <div>
      <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Symbol *</label>
      <input id="cCoinSym" placeholder="BTC" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
    </div>
    <div style="grid-column:1/-1">
      <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Coin Name *</label>
      <input id="cCoinName" placeholder="Bitcoin" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
    </div>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
    <div>
      <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Quantity *</label>
      <input id="cQty" type="number" step="any" min="0" placeholder="0.005" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
    </div>
    <div>
      <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Buy Price (₹/coin) *</label>
      <input id="cPrice" type="number" step="any" min="0" placeholder="5000000" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
    </div>
  </div>
  <div id="cAmountPreview" style="font-size:12px;color:var(--text-muted);display:none;background:var(--bg);border-radius:6px;padding:8px 12px"></div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
    <div>
      <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Exchange</label>
      <input id="cExchange" placeholder="WazirX / Binance" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
    </div>
    <div>
      <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Buy Date *</label>
      <input id="cDate" type="date" value="${new Date().toISOString().slice(0,10)}" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
    </div>
  </div>
  <div>
    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Notes</label>
    <input id="cNotes" placeholder="Optional notes" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
  </div>
  <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px">
    <button class="btn btn-secondary" onclick="CRYPTO.closeModal()">Cancel</button>
    <button class="btn btn-primary" onclick="CRYPTO._submitAdd()">Add Coin</button>
  </div>
</div>`;

    // Pre-select if editing
    if (coinId) {
      const sel = document.getElementById('cAddCoinSel');
      if (sel) sel.value = coinId;
    }

    // Live amount preview
    ['cQty','cPrice'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('input', _updateAmountPreview);
    });

    const modal = document.getElementById('cryptoModal');
    if (modal) { modal.style.display = 'flex'; }
  }

  function _onCoinSelect(sel) {
    const custom = document.getElementById('cCustomCoinWrap');
    if (sel.value === '__custom') {
      custom.style.display = 'grid';
    } else {
      custom.style.display = 'none';
      const opt = sel.options[sel.selectedIndex];
      if (opt) {
        const ci = document.getElementById('cCoinId'); if (ci) ci.value = sel.value;
        const cs = document.getElementById('cCoinSym'); if (cs) cs.value = opt.dataset.sym || '';
        const cn = document.getElementById('cCoinName'); if (cn) cn.value = opt.dataset.name || '';
      }
    }
  }

  function _updateAmountPreview() {
    const q = parseFloat(document.getElementById('cQty')?.value || 0);
    const p = parseFloat(document.getElementById('cPrice')?.value || 0);
    const prev = document.getElementById('cAmountPreview');
    if (!prev) return;
    if (q > 0 && p > 0) {
      prev.style.display = 'block';
      prev.textContent = `Total Invested: ${fmtInr(q * p)}`;
    } else {
      prev.style.display = 'none';
    }
  }

  async function _submitAdd() {
    const sel     = document.getElementById('cAddCoinSel');
    const isCustom = sel && sel.value === '__custom';
    const coinId  = isCustom
      ? (document.getElementById('cCoinId')?.value?.trim() || '')
      : (sel?.value || '');
    const sym     = isCustom
      ? (document.getElementById('cCoinSym')?.value?.trim().toUpperCase() || '')
      : (sel?.options[sel.selectedIndex]?.dataset.sym || '');
    const name    = isCustom
      ? (document.getElementById('cCoinName')?.value?.trim() || sym)
      : (sel?.options[sel.selectedIndex]?.dataset.name || sym);
    const qty     = parseFloat(document.getElementById('cQty')?.value || 0);
    const price   = parseFloat(document.getElementById('cPrice')?.value || 0);
    const exchange = document.getElementById('cExchange')?.value?.trim() || '';
    const date    = document.getElementById('cDate')?.value || '';
    const notes   = document.getElementById('cNotes')?.value?.trim() || '';

    if (!coinId) return WD.toast('Coin select karo', 'error');
    if (!sym)    return WD.toast('Symbol required', 'error');
    if (qty <= 0) return WD.toast('Quantity enter karo', 'error');
    if (price <= 0) return WD.toast('Buy price enter karo', 'error');

    try {
      const r = await API.post({
        action: 'crypto_add', coin_id: coinId, coin_symbol: sym, coin_name: name,
        quantity: qty, price_inr: price, exchange, txn_date: date, notes
      });
      if (!r.success) throw new Error(r.message);
      WD.toast(r.message || 'Added!', 'success');
      closeModal();
      _loadHoldings();
    } catch (e) { WD.toast(e.message, 'error'); }
  }

  function closeModal() {
    const m = document.getElementById('cryptoModal');
    if (m) m.style.display = 'none';
  }

  // ── Styles ─────────────────────────────────────────────────────────────
  function _injectStyles() {
    if (document.getElementById('cryptoStyles')) return;
    const s = document.createElement('style');
    s.id = 'cryptoStyles';
    s.textContent = `
      .crypto-tab {
        padding: 8px 16px; border: none; background: none; cursor: pointer;
        font-size: 13px; font-weight: 500; color: var(--text-muted);
        border-bottom: 2.5px solid transparent; margin-bottom: -1.5px;
        transition: color .15s, border-color .15s;
      }
      .crypto-tab.active { color: var(--accent); border-bottom-color: var(--accent); font-weight: 700; }
      .crypto-tab:hover  { color: var(--text); }
      .gain-badge { font-size: 11px; font-weight: 700; padding: 2px 7px; border-radius: 99px; }
      .gain-pos   { background: var(--green-bg, #d1fae5); color: var(--green); }
      .gain-neg   { background: var(--red-bg, #fee2e2);   color: var(--red);   }
      .btn-icon-sm {
        display:inline-flex; align-items:center; justify-content:center;
        width:26px; height:26px; border-radius:6px; border:1px solid var(--border);
        background:var(--bg); cursor:pointer; font-size:13px; font-weight:700;
        color:var(--text-muted); transition:all .12s;
      }
      .btn-icon-sm:hover         { background: var(--accent);      color:#fff; border-color:var(--accent); }
      .btn-icon-sm.btn-danger-sm:hover { background: var(--red);   color:#fff; border-color:var(--red); }
    `;
    document.head.appendChild(s);
  }

  // ── Public API ─────────────────────────────────────────────────────────
  return {
    init, switchTab, refreshPrices, deleteHolding,
    openAddModal, closeModal, renderHoldings,
    _onCoinSelect, _submitAdd, _updateAmountPreview
  };
})();

document.addEventListener('DOMContentLoaded', () => CRYPTO.init());
