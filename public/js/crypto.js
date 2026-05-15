/**
 * WealthDash — Crypto Module (t24 + tc001 + t317 + tc005)
 * public/js/crypto.js
 * Holdings table + live prices (SSE) + P&L + VDA Tax tab +
 * CSV Import (t317) + Exchange Sync (tc005) + Add modal
 */
const CRYPTO = (() => {
  'use strict';

  // ── State ──────────────────────────────────────────────────────────────
  let _holdings      = [];
  let _prices        = {};
  let _tab           = 'holdings';
  let _refreshTimer  = null;
  let _sse           = null;          // tc001: EventSource
  let _sseCountdown  = 30;            // tc001: seconds until next price update
  let _sseTickTimer  = null;          // tc001: 1s interval for countdown display
  let _lastPriceData = {};            // tc001: for flash diff comparison
  let _importPreview = null;          // t317: parsed CSV preview rows
  let _importExchange= '';            // t317: detected/selected exchange
  let _portfolioId   = 0;            // shared: selected portfolio

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
    setTimeout(_sseConnect, 1500);
  }

  // ── tc001: SSE Live Price Stream ────────────────────────────────────────
  function _sseConnect() {
    if (_sse) { _sse.close(); _sse = null; }
    clearInterval(_sseTickTimer);

    const coinIds = [...new Set(_holdings.map(h => h.coin_id).filter(Boolean))];
    if (!coinIds.length) {
      if (!_refreshTimer) {
        _refreshTimer = setInterval(() => {
          if (_tab === 'holdings') _loadHoldings(true);
        }, 60000);
      }
      return;
    }

    const base = window.WD?.appUrl || window.APP_URL || '';
    const url  = `${base}/api/router.php?action=crypto_price_stream&coins=${coinIds.join(',')}`;
    _sse = new EventSource(url);

    _sse.addEventListener('prices', e => {
      try {
        const prices = JSON.parse(e.data);
        _onLivePrices(prices);
        _sseCountdown = 30;
      } catch {}
    });

    _sse.addEventListener('ping', () => { _sseCountdown = 30; });

    _sse.addEventListener('error', () => {
      _updateTickerStatus('⚠️ Reconnecting…', 'var(--text-muted)');
    });

    _sseCountdown = 30;
    _sseTickTimer = setInterval(() => {
      _sseCountdown = Math.max(0, _sseCountdown - 1);
      _updateTickerStatus(
        `🟢 Live · Next: ${_sseCountdown}s`,
        _sseCountdown > 5 ? '#22c55e' : '#f59e0b'
      );
    }, 1000);

    document.addEventListener('visibilitychange', () => {
      if (!document.hidden && _sse?.readyState === EventSource.CLOSED) _sseConnect();
    }, { once: false });
  }

  function _sseDisconnect() {
    if (_sse) { _sse.close(); _sse = null; }
    clearInterval(_sseTickTimer);
    if (_refreshTimer) { clearInterval(_refreshTimer); _refreshTimer = null; }
  }

  function _updateTickerStatus(text, color) {
    const el = document.getElementById('cryptoLiveTicker');
    if (el) { el.textContent = text; el.style.color = color; }
  }

  function _onLivePrices(prices) {
    const prev = { ..._lastPriceData };
    Object.assign(_prices, prices);
    _lastPriceData = { ...prices };

    _holdings.forEach((h, idx) => {
      const cid = h.coin_id;
      const p   = prices[cid];
      if (!p) return;

      const row    = document.querySelector(`[data-crypto-idx="${idx}"]`);
      const priceEl= document.getElementById(`cp_price_${idx}`);
      const valueEl= document.getElementById(`cp_value_${idx}`);
      const pnlEl  = document.getElementById(`cp_pnl_${idx}`);
      const chg24El= document.getElementById(`cp_chg24_${idx}`);
      if (!row || !priceEl) return;

      const prevPrice = prev[cid]?.inr || 0;
      const newPrice  = p.inr;
      const units     = (float(h.quantity_net) || 0);
      const avgCost   = (float(h.avg_buy_price_inr) || 0);
      const currVal   = newPrice * units;
      const investedVal = avgCost * units;
      const pnl       = currVal - investedVal;
      const pnlPct    = investedVal > 0 ? (pnl / investedVal * 100) : 0;

      priceEl.textContent = _fmtINR(newPrice);
      if (valueEl) valueEl.textContent = _fmtINR(currVal);
      if (pnlEl) {
        pnlEl.textContent = (pnl >= 0 ? '+' : '') + _fmtINR(pnl);
        pnlEl.style.color = pnl >= 0 ? '#22c55e' : '#ef4444';
      }
      if (chg24El) {
        const chg = p.chg24h;
        chg24El.textContent = (chg >= 0 ? '+' : '') + chg.toFixed(2) + '%';
        chg24El.style.color = chg >= 0 ? '#22c55e' : '#ef4444';
      }

      if (prevPrice && prevPrice !== newPrice) {
        const flashColor = newPrice > prevPrice ? '#22c55e20' : '#ef444420';
        row.style.background = flashColor;
        row.style.transition = 'background 0.8s';
        setTimeout(() => { row.style.background = ''; }, 900);
      }
    });
    _updateLiveSummary();
  }

  function _updateLiveSummary() {
    let totalVal = 0, totalInvest = 0;
    _holdings.forEach(h => {
      const p = _prices[h.coin_id];
      if (!p) return;
      totalVal    += p.inr * (parseFloat(h.quantity_net) || 0);
      totalInvest += (parseFloat(h.avg_buy_price_inr) || 0) * (parseFloat(h.quantity_net) || 0);
    });
    const totalPnl    = totalVal - totalInvest;
    const totalPnlPct = totalInvest > 0 ? (totalPnl / totalInvest * 100) : 0;

    const valEl = document.getElementById('cryptoTotalValue');
    const pnlEl = document.getElementById('cryptoTotalPnl');
    if (valEl) valEl.textContent = _fmtINR(totalVal);
    if (pnlEl) {
      pnlEl.textContent = (totalPnl >= 0 ? '+' : '') + _fmtINR(totalPnl) +
                          ' (' + (totalPnlPct >= 0 ? '+' : '') + totalPnlPct.toFixed(2) + '%)';
      pnlEl.style.color = totalPnl >= 0 ? '#22c55e' : '#ef4444';
    }
  }

  function float(v) { return parseFloat(v) || 0; }

  // ── Shell HTML ─────────────────────────────────────────────────────────
  function _renderShell() {
    const wrap = document.getElementById('cryptoApp');
    if (!wrap) return;
    wrap.innerHTML = `
<!-- tc001: Live ticker strip -->
<div style="display:flex;align-items:center;justify-content:space-between;
            background:var(--bg-secondary);border-radius:8px;padding:6px 12px;
            margin-bottom:12px;border:1px solid var(--border-color);">
  <span id="cryptoLiveTicker" style="font-size:11px;font-weight:600;color:#22c55e;">
    🟡 Connecting to live feed…
  </span>
  <div style="display:flex;align-items:center;gap:8px;">
    <span style="font-size:11px;color:var(--text-muted);">Powered by CoinGecko</span>
    <button class="btn btn-ghost btn-sm" style="font-size:10px;padding:3px 8px;"
            onclick="CRYPTO.refreshPrices()">↻ Manual</button>
  </div>
</div>

<!-- Stat cards -->
<div id="cryptoStats" class="stats-grid" style="margin-bottom:20px">
  <div class="stat-card"><div class="stat-label">Holdings</div><div class="stat-value" id="cStatCoins">—</div></div>
  <div class="stat-card"><div class="stat-label">Invested</div><div class="stat-value" id="cStatInv">—</div></div>
  <div class="stat-card"><div class="stat-label">Current Value</div><div class="stat-value" id="cryptoTotalValue">—</div></div>
  <div class="stat-card">
    <div class="stat-label">P&amp;L (Unrealised)</div>
    <div class="stat-value" id="cryptoTotalPnl">—</div>
  </div>
</div>

<!-- Tab bar -->
<div style="display:flex;gap:4px;margin-bottom:16px;border-bottom:1.5px solid var(--border);padding-bottom:0;flex-wrap:wrap;">
  <button class="crypto-tab active" id="ctab_holdings"     onclick="CRYPTO.switchTab('holdings')">💼 Holdings</button>
  <button class="crypto-tab"        id="ctab_transactions" onclick="CRYPTO.switchTab('transactions')">📋 Transactions</button>
  <button class="crypto-tab"        id="ctab_taxcalc"      onclick="CRYPTO.switchTab('taxcalc')">🧮 Tax Calc</button>
  <button class="crypto-tab"        id="ctab_coldwallet"   onclick="CRYPTO.switchTab('coldwallet')">🔐 Cold Wallets</button>
  <button class="crypto-tab"        id="ctab_tax"          onclick="CRYPTO.switchTab('tax')">🧾 VDA Tax</button>
  <button class="crypto-tab"        id="ctab_import"       onclick="CRYPTO.switchTab('import')">📥 Import CSV</button>
  <button class="crypto-tab"        id="ctab_exchange"     onclick="CRYPTO.switchTab('exchange')">🔗 Exchange Sync</button>
</div>

<!-- Actions bar -->
<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;flex-wrap:wrap" id="cActionBar">
  <button class="btn btn-primary btn-sm" onclick="CRYPTO.openAddModal()">
    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
    Add Coin
  </button>
  <span id="cPriceTime" style="font-size:11px;color:var(--text-muted);margin-left:4px"></span>
</div>

<!-- Content area -->
<div id="cryptoContent"></div>

<!-- Add Coin Modal -->
<div id="cryptoModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
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
    if (!silent && content) {
      if (typeof WdSkel !== 'undefined') WdSkel.table('cryptoContent', 5, 6);
      else content.innerHTML = '<div class="wd-loader" style="margin:40px auto"></div>';
    }

    try {
      const data = await API.get('crypto_list');
      if (!data.success) throw new Error(data.message);
      _holdings = data.holdings || [];
      _updateStats(data);
      renderHoldings(_holdings);
      const pt = document.getElementById('cPriceTime');
      if (pt) pt.textContent = 'Updated: ' + new Date().toLocaleTimeString('en-IN', {hour:'2-digit', minute:'2-digit'});
      _sseConnect();
    } catch (e) {
      if (content) content.innerHTML = typeof WdEmpty !== 'undefined'
        ? WdEmpty.html('crypto')
        : `<div class="wd-empty">⚠️ ${e.message}</div>`;
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
          "Add Coin" karo ya CSV import karo apna portfolio track karne ke liye
        </div>
        <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap">
          <button class="btn btn-primary" onclick="CRYPTO.openAddModal()">+ Add Coin</button>
          <button class="btn btn-secondary" onclick="CRYPTO.switchTab('import')">📥 Import CSV</button>
        </div>
      </div>`;
      return;
    }

    const rows = data.map((h, idx) => {
      const gainCls = h.gain_inr >= 0 ? 'var(--green)' : 'var(--red)';
      const chg24 = h.change_24h != null
        ? `<div style="font-size:11px;color:${h.change_24h>=0?'var(--green)':'var(--red)'};margin-top:2px" id="cp_chg24_${idx}">
             ${h.change_24h>=0?'▲':'▼'} ${Math.abs(parseFloat(h.change_24h)).toFixed(2)}% (24h)
           </div>`
        : '';
      return `<tr data-crypto-idx="${idx}" style="transition:background 0.8s;">
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
        <td id="cp_price_${idx}">${h.price_inr > 0 ? fmtInr(h.price_inr) : '<span style="color:var(--text-muted)">—</span>'}${chg24}</td>
        <td>${fmtInr(h.avg_buy_price)}</td>
        <td>${fmtInr(h.total_invested)}</td>
        <td style="font-weight:600" id="cp_value_${idx}">${h.current_value > 0 ? fmtInr(h.current_value) : '—'}</td>
        <td>
          <div style="color:${gainCls};font-weight:600" id="cp_pnl_${idx}">${fmtInr(h.gain_inr)}</div>
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
        const srcBadge = t.import_source
          ? `<span style="font-size:10px;background:var(--bg-secondary);border-radius:4px;padding:1px 5px;color:var(--text-muted)">${t.import_source}</span>`
          : '';
        return `<tr>
          <td>${t.txn_date}</td>
          <td><span style="font-weight:700;color:${typeCls}">${t.txn_type}</span></td>
          <td>${t.coin_symbol}</td>
          <td style="font-family:monospace">${fmtQty(t.quantity)}</td>
          <td>${fmtInr(t.price_inr)}</td>
          <td style="font-weight:600">${fmtInr(t.amount_inr)}</td>
          <td style="color:var(--text-muted)">${t.tds_deducted > 0 ? fmtInr(t.tds_deducted) : '—'}</td>
          <td style="font-size:12px;color:var(--text-muted)">${t.exchange || '—'} ${srcBadge}</td>
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

  // ── t317: CSV Import Tab ───────────────────────────────────────────────
  async function _loadImport() {
    const content = document.getElementById('cryptoContent');
    _importPreview = null;
    _importExchange = '';

    content.innerHTML = `
<div style="max-width:860px">
  <!-- Info banner -->
  <div style="background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1px solid #bfdbfe;border-radius:12px;
              padding:14px 18px;margin-bottom:20px;font-size:13px;color:#1e40af;display:flex;gap:12px;align-items:flex-start;">
    <span style="font-size:20px;flex-shrink:0">📥</span>
    <div>
      <strong>CSV Import (t317)</strong> — Binance, WazirX, CoinDCX trade history directly import karo.<br>
      <span style="font-size:12px;opacity:.8">Duplicate trades automatically skip ho jaate hain. Preview ke baad confirm karo.</span>
    </div>
  </div>

  <!-- Upload form -->
  <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:14px;padding:22px;margin-bottom:18px">
    <h4 style="margin:0 0 16px;font-size:14px;font-weight:700">1. CSV File Upload karo</h4>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:14px">
      <div>
        <label style="font-size:11px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:5px">Exchange</label>
        <select id="impExchange" style="width:100%;padding:8px 10px;border:1.5px solid var(--border-color);border-radius:8px;background:var(--card-bg);color:var(--text)">
          <option value="AUTO">Auto-detect</option>
          <option value="BINANCE">Binance</option>
          <option value="WAZIRX">WazirX</option>
          <option value="COINDCX">CoinDCX</option>
        </select>
      </div>
      <div>
        <label style="font-size:11px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:5px">Portfolio *</label>
        <select id="impPortfolio" style="width:100%;padding:8px 10px;border:1.5px solid var(--border-color);border-radius:8px;background:var(--card-bg);color:var(--text)">
          <option value="">Loading…</option>
        </select>
      </div>
      <div>
        <label style="font-size:11px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:5px">CSV File *</label>
        <input type="file" id="impFile" accept=".csv,text/csv"
               style="width:100%;padding:6px 10px;border:1.5px dashed var(--border-color);border-radius:8px;font-size:12px">
      </div>
    </div>
    <button class="btn btn-primary btn-sm" onclick="CRYPTO._importPreviewLoad()" style="margin-right:8px">
      🔍 Preview Import
    </button>
    <span style="font-size:11px;color:var(--text-muted)">Preview mein check karo, phir confirm karo</span>
  </div>

  <!-- Preview area -->
  <div id="importPreviewArea"></div>

  <!-- Import log section -->
  <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:14px;padding:22px;margin-top:18px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
      <h4 style="margin:0;font-size:14px;font-weight:700">Import History</h4>
      <button class="btn btn-ghost btn-sm" onclick="CRYPTO._importLogLoad()">↻ Refresh</button>
    </div>
    <div id="importLogArea"><div style="font-size:13px;color:var(--text-muted)">Loading…</div></div>
  </div>

  <!-- Format guides -->
  <div style="margin-top:16px;font-size:11px;color:var(--text-muted)">
    <strong>Expected CSV formats:</strong><br>
    Binance: Date(UTC), Pair, Side, Price, Executed, Amount, Fee<br>
    WazirX: Date, Transaction Type, Currency, Volume, Price (INR), Amount (INR), Fee, TDS (INR)<br>
    CoinDCX: created_at, type, currency, fee_currency, price, quantity, status
  </div>
</div>`;

    // Load portfolios dropdown
    _loadPortfoliosIntoSelect('impPortfolio');
    // Load import log
    _importLogLoad();
  }

  async function _loadPortfoliosIntoSelect(selId) {
    try {
      const data = await API.get('portfolios_list');
      const sel  = document.getElementById(selId);
      if (!sel) return;
      const list = data.portfolios || data.list || [];
      sel.innerHTML = list.length
        ? list.map(p => `<option value="${p.id}">${p.name}</option>`).join('')
        : '<option value="">No portfolios found</option>';
      if (list.length) _portfolioId = list[0].id;
    } catch {
      const sel = document.getElementById(selId);
      if (sel) sel.innerHTML = '<option value="1">Default Portfolio</option>';
    }
  }

  async function _importPreviewLoad() {
    const fileInput = document.getElementById('impFile');
    const exchange  = document.getElementById('impExchange')?.value || 'AUTO';
    const portId    = document.getElementById('impPortfolio')?.value || '';
    const area      = document.getElementById('importPreviewArea');

    if (!fileInput?.files?.length) return WD.toast('CSV file select karo', 'error');
    if (!portId) return WD.toast('Portfolio select karo', 'error');

    area.innerHTML = '<div class="wd-loader" style="margin:20px auto"></div>';

    const file = fileInput.files[0];
    const csvData = await file.text();

    try {
      const base = window.WD?.appUrl || window.APP_URL || '';
      const res = await fetch(`${base}/api/router.php`, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
          action: 'crypto_import_preview',
          exchange, csv_data: csvData, portfolio_id: portId
        })
      });
      const data = await res.json();
      if (!data.success) { area.innerHTML = `<div class="wd-empty">⚠️ ${data.message}</div>`; return; }

      _importPreview  = data.rows;
      _importExchange = data.exchange;

      const dupCount  = data.duplicates || 0;
      const newCount  = data.total - dupCount;

      const previewRows = (data.rows || []).slice(0, 50).map(r => {
        const isDup = r.is_duplicate;
        return `<tr style="${isDup?'opacity:.45;':''}">
          <td>${isDup ? '<span style="color:var(--text-muted);font-size:10px">DUPLICATE</span>' : '✅'}</td>
          <td style="font-size:12px">${(r.txn_date||'').slice(0,10)}</td>
          <td><strong style="color:${r.txn_type==='BUY'?'var(--green)':'var(--red)'}">${r.txn_type}</strong></td>
          <td>${r.coin_symbol}</td>
          <td style="font-family:monospace;font-size:12px">${fmtQty(r.quantity)}</td>
          <td style="font-size:12px">${fmtInr(r.price_inr)}</td>
          <td style="font-size:12px;font-weight:600">${fmtInr(r.amount_inr)}</td>
          <td style="font-size:11px;color:var(--text-muted)">${r.fee_amount > 0 ? r.fee_amount + ' ' + (r.fee_currency||'') : '—'}</td>
          <td style="font-size:11px;color:var(--text-muted)">${r.tds_deducted > 0 ? fmtInr(r.tds_deducted) : '—'}</td>
        </tr>`;
      }).join('');

      area.innerHTML = `
<div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:14px;padding:22px;">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px">
    <h4 style="margin:0;font-size:14px;font-weight:700">2. Preview — ${data.exchange}</h4>
    <div style="display:flex;gap:8px;align-items:center;">
      <span style="font-size:12px;color:var(--text-muted)">${data.total} rows parsed</span>
      <span style="font-size:12px;background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:99px">${newCount} new</span>
      ${dupCount ? `<span style="font-size:12px;background:#f3f4f6;color:#6b7280;padding:2px 8px;border-radius:99px">${dupCount} duplicate</span>` : ''}
    </div>
  </div>
  ${newCount === 0 ? '<div class="wd-empty" style="padding:20px">Sab trades already import ho chuke hain</div>' : ''}
  <div style="overflow-x:auto;max-height:380px;overflow-y:auto">
  <table class="wd-table" style="min-width:700px">
    <thead style="position:sticky;top:0;z-index:1">
      <tr><th></th><th>Date</th><th>Type</th><th>Coin</th><th>Qty</th><th>Price</th><th>Amount</th><th>Fee</th><th>TDS</th></tr>
    </thead>
    <tbody>${previewRows}</tbody>
  </table>
  </div>
  ${data.total > 50 ? `<div style="font-size:11px;color:var(--text-muted);margin-top:8px">Showing first 50 of ${data.total} rows</div>` : ''}
  ${newCount > 0 ? `
  <div style="margin-top:16px;display:flex;gap:10px;align-items:center">
    <button class="btn btn-primary" onclick="CRYPTO._importConfirm()" style="min-width:160px">
      ✅ Confirm Import (${newCount} trades)
    </button>
    <button class="btn btn-secondary btn-sm" onclick="document.getElementById('importPreviewArea').innerHTML=''">
      Cancel
    </button>
    <span style="font-size:11px;color:var(--text-muted)">USD→INR rate: ₹${(data.usd_to_inr||85).toFixed(2)}</span>
  </div>` : ''}
</div>`;
    } catch (e) {
      area.innerHTML = `<div class="wd-empty">⚠️ ${e.message}</div>`;
    }
  }

  async function _importConfirm() {
    if (!_importPreview?.length) return;
    const portId   = document.getElementById('impPortfolio')?.value || '';
    const fileInput = document.getElementById('impFile');
    if (!portId || !fileInput?.files?.length) return WD.toast('Portfolio/file missing', 'error');

    const btn = document.querySelector('#importPreviewArea .btn-primary');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Importing…'; }

    try {
      const csvData = await fileInput.files[0].text();
      const base    = window.WD?.appUrl || window.APP_URL || '';
      const res = await fetch(`${base}/api/router.php`, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
          action: 'crypto_import_confirm',
          exchange: _importExchange,
          csv_data: csvData,
          portfolio_id: portId,
          filename: fileInput.files[0].name
        })
      });
      const data = await res.json();
      if (!data.success) throw new Error(data.message);

      WD.toast(`Import done! ${data.inserted} added, ${data.skipped} skipped.`, 'success');
      document.getElementById('importPreviewArea').innerHTML = '';
      _importPreview = null;
      _importLogLoad();
      setTimeout(() => _loadHoldings(), 500);
    } catch (e) {
      WD.toast(e.message, 'error');
      if (btn) { btn.disabled = false; btn.textContent = '✅ Confirm Import'; }
    }
  }

  async function _importLogLoad() {
    const area = document.getElementById('importLogArea');
    if (!area) return;
    try {
      const data = await API.get('crypto_import_log');
      const logs = data.logs || [];
      if (!logs.length) {
        area.innerHTML = '<div style="font-size:13px;color:var(--text-muted)">Abhi tak koi import nahi hua</div>';
        return;
      }
      const rows = logs.map(l => `<tr>
        <td style="font-size:12px">${(l.imported_at||'').slice(0,16)}</td>
        <td><strong>${l.exchange}</strong></td>
        <td style="font-size:12px;color:var(--text-muted)">${l.filename}</td>
        <td style="color:var(--green);font-weight:600">${l.rows_inserted}</td>
        <td style="color:var(--text-muted)">${l.rows_skipped}</td>
        <td style="font-size:11px;color:var(--text-muted);font-family:monospace">${(l.batch_id||'').slice(0,8)}…</td>
      </tr>`).join('');
      area.innerHTML = `<div style="overflow-x:auto">
<table class="wd-table" style="min-width:520px">
  <thead><tr><th>Date</th><th>Exchange</th><th>File</th><th>Added</th><th>Skipped</th><th>Batch</th></tr></thead>
  <tbody>${rows}</tbody>
</table></div>`;
    } catch (e) {
      area.innerHTML = `<div style="font-size:13px;color:var(--text-muted)">Could not load log</div>`;
    }
  }

  // ── tc005: Exchange Sync Tab ───────────────────────────────────────────
  async function _loadExchangeSync() {
    const content = document.getElementById('cryptoContent');
    content.innerHTML = `
<div style="max-width:860px">
  <!-- Info banner -->
  <div style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1px solid #86efac;border-radius:12px;
              padding:14px 18px;margin-bottom:20px;font-size:13px;color:#15803d;display:flex;gap:12px;align-items:flex-start;">
    <span style="font-size:20px;flex-shrink:0">🔗</span>
    <div>
      <strong>Exchange Sync (tc005)</strong> — Binance / WazirX API keys se live trade history pull karo.<br>
      <span style="font-size:12px;opacity:.8">Keys AES-256-GCM encrypted rahti hain. Read-only permission use karo — withdrawal access mat do.</span>
    </div>
  </div>

  <!-- Saved API keys -->
  <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:14px;padding:22px;margin-bottom:18px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
      <h4 style="margin:0;font-size:14px;font-weight:700">Saved API Keys</h4>
      <button class="btn btn-primary btn-sm" onclick="CRYPTO._openAddKeyModal()">+ Add API Key</button>
    </div>
    <div id="exchangeKeysList"><div class="wd-loader" style="margin:20px auto"></div></div>
  </div>

  <!-- Sync run -->
  <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:14px;padding:22px;margin-bottom:18px">
    <h4 style="margin:0 0 14px;font-size:14px;font-weight:700">Run Sync</h4>
    <div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap">
      <div>
        <label style="font-size:11px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:5px">Exchange</label>
        <select id="syncExchange" style="padding:8px 12px;border:1.5px solid var(--border-color);border-radius:8px;background:var(--card-bg);color:var(--text)">
          <option value="BINANCE">Binance</option>
          <option value="WAZIRX">WazirX</option>
        </select>
      </div>
      <div>
        <label style="font-size:11px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:5px">Portfolio</label>
        <select id="syncPortfolio" style="padding:8px 12px;border:1.5px solid var(--border-color);border-radius:8px;background:var(--card-bg);color:var(--text)">
          <option value="">Loading…</option>
        </select>
      </div>
      <button class="btn btn-primary" id="syncRunBtn" onclick="CRYPTO._runSync()" style="padding:8px 20px">
        ▶ Sync Now
      </button>
    </div>
    <div id="syncStatusMsg" style="margin-top:12px;font-size:13px;display:none"></div>
  </div>

  <!-- Sync history -->
  <div style="background:var(--card-bg);border:1px solid var(--border-color);border-radius:14px;padding:22px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
      <h4 style="margin:0;font-size:14px;font-weight:700">Sync History</h4>
      <button class="btn btn-ghost btn-sm" onclick="CRYPTO._syncLogLoad()">↻ Refresh</button>
    </div>
    <div id="syncLogArea"><div class="wd-loader" style="margin:20px auto"></div></div>
  </div>

  <!-- Add Key Modal -->
  <div id="addKeyModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
    <div style="background:var(--card-bg);border-radius:16px;padding:28px;width:100%;max-width:460px;position:relative;box-shadow:0 20px 60px rgba(0,0,0,.3)">
      <button onclick="CRYPTO._closeAddKeyModal()" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted)">✕</button>
      <h3 style="margin:0 0 20px;font-size:16px;font-weight:700">Add Exchange API Key</h3>
      <div style="display:grid;gap:12px">
        <div>
          <label style="font-size:11px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:4px">Exchange *</label>
          <select id="akExchange" style="width:100%;padding:8px 10px;border:1.5px solid var(--border-color);border-radius:8px;background:var(--card-bg);color:var(--text)">
            <option value="BINANCE">Binance</option>
            <option value="WAZIRX">WazirX</option>
          </select>
        </div>
        <div>
          <label style="font-size:11px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:4px">Label</label>
          <input id="akLabel" placeholder="e.g. Main Binance account" style="width:100%;padding:8px 10px;border:1.5px solid var(--border-color);border-radius:8px;box-sizing:border-box">
        </div>
        <div>
          <label style="font-size:11px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:4px">API Key *</label>
          <input id="akApiKey" placeholder="Paste API key here" type="password" style="width:100%;padding:8px 10px;border:1.5px solid var(--border-color);border-radius:8px;box-sizing:border-box">
        </div>
        <div>
          <label style="font-size:11px;font-weight:600;color:var(--text-muted);display:block;margin-bottom:4px">API Secret *</label>
          <input id="akApiSecret" placeholder="Paste API secret here" type="password" style="width:100%;padding:8px 10px;border:1.5px solid var(--border-color);border-radius:8px;box-sizing:border-box">
        </div>
        <div style="background:#fef3c7;border:1px solid #fcd34d;border-radius:8px;padding:10px 12px;font-size:11px;color:#92400e">
          ⚠️ Read-only permissions use karo. Withdrawal permission bilkul mat do.
          Keys encrypted hokar store hoti hain.
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px">
          <button class="btn btn-secondary" onclick="CRYPTO._closeAddKeyModal()">Cancel</button>
          <button class="btn btn-primary" onclick="CRYPTO._submitAddKey()">Save Key</button>
        </div>
      </div>
    </div>
  </div>
</div>`;

    _loadPortfoliosIntoSelect('syncPortfolio');
    _exchangeKeysLoad();
    _syncLogLoad();
  }

  async function _exchangeKeysLoad() {
    const area = document.getElementById('exchangeKeysList');
    if (!area) return;
    try {
      const data = await API.get('exchange_keys_list');
      const keys = data.keys || [];
      if (!keys.length) {
        area.innerHTML = `<div style="font-size:13px;color:var(--text-muted)">
          Koi API key saved nahi hai. "Add API Key" karo.
        </div>`;
        return;
      }
      const rows = keys.map(k => {
        const statusColor = k.is_active ? '#22c55e' : '#9ca3af';
        return `<tr>
          <td><strong>${k.exchange}</strong></td>
          <td style="font-size:13px">${k.label || '—'}</td>
          <td><span style="width:8px;height:8px;border-radius:50%;background:${statusColor};display:inline-block;margin-right:4px"></span>
              ${k.is_active ? 'Active' : 'Inactive'}</td>
          <td style="font-size:12px;color:var(--text-muted)">${k.last_synced ? k.last_synced.slice(0,16) : 'Never'}</td>
          <td style="font-size:12px;color:var(--text-muted)">${(k.created_at||'').slice(0,10)}</td>
          <td>
            <button class="btn-icon-sm btn-danger-sm" onclick="CRYPTO._deleteKey(${k.id},'${k.exchange}')" title="Delete">✕</button>
          </td>
        </tr>`;
      }).join('');
      area.innerHTML = `<div style="overflow-x:auto">
<table class="wd-table" style="min-width:560px">
  <thead><tr><th>Exchange</th><th>Label</th><th>Status</th><th>Last Synced</th><th>Added</th><th></th></tr></thead>
  <tbody>${rows}</tbody>
</table></div>`;
    } catch (e) {
      area.innerHTML = `<div style="color:var(--text-muted);font-size:13px">Error loading keys</div>`;
    }
  }

  function _openAddKeyModal() {
    const m = document.getElementById('addKeyModal');
    if (m) m.style.display = 'flex';
  }

  function _closeAddKeyModal() {
    const m = document.getElementById('addKeyModal');
    if (m) m.style.display = 'none';
  }

  async function _submitAddKey() {
    const exchange  = document.getElementById('akExchange')?.value;
    const label     = document.getElementById('akLabel')?.value?.trim();
    const apiKey    = document.getElementById('akApiKey')?.value?.trim();
    const apiSecret = document.getElementById('akApiSecret')?.value?.trim();

    if (!apiKey || apiKey.length < 10)    return WD.toast('Valid API key enter karo', 'error');
    if (!apiSecret || apiSecret.length < 10) return WD.toast('Valid API secret enter karo', 'error');

    try {
      const r = await API.post({ action: 'exchange_keys_save', exchange, label, api_key: apiKey, api_secret: apiSecret });
      if (!r.success) throw new Error(r.message);
      WD.toast(r.message || 'Key saved!', 'success');
      _closeAddKeyModal();
      document.getElementById('akApiKey').value = '';
      document.getElementById('akApiSecret').value = '';
      _exchangeKeysLoad();
    } catch (e) { WD.toast(e.message, 'error'); }
  }

  async function _deleteKey(id, exchange) {
    if (!confirm(`${exchange} API key delete karo?`)) return;
    try {
      const r = await API.post({ action: 'exchange_keys_delete', key_id: id });
      if (!r.success) throw new Error(r.message);
      WD.toast('Key deleted', 'success');
      _exchangeKeysLoad();
    } catch (e) { WD.toast(e.message, 'error'); }
  }

  async function _runSync() {
    const exchange   = document.getElementById('syncExchange')?.value;
    const portfolioId = document.getElementById('syncPortfolio')?.value;
    const btn        = document.getElementById('syncRunBtn');
    const statusEl   = document.getElementById('syncStatusMsg');

    if (!portfolioId) return WD.toast('Portfolio select karo', 'error');

    if (btn) { btn.disabled = true; btn.textContent = '⏳ Syncing…'; }
    if (statusEl) {
      statusEl.style.display = 'block';
      statusEl.style.color = 'var(--text-muted)';
      statusEl.textContent = `${exchange} se trades fetch ho rahe hain… (1-2 min lag sakta hai)`;
    }

    try {
      const r = await API.post({ action: 'exchange_sync_run', exchange, portfolio_id: portfolioId });
      if (!r.success) throw new Error(r.message);

      if (statusEl) {
        statusEl.style.color = '#22c55e';
        statusEl.textContent = `✅ ${r.message} (Fetched: ${r.data?.fetched || 0})`;
      }
      WD.toast(r.message, 'success');
      _syncLogLoad();
      _exchangeKeysLoad();
      setTimeout(() => _loadHoldings(), 1000);
    } catch (e) {
      if (statusEl) { statusEl.style.color = 'var(--red)'; statusEl.textContent = `⚠️ ${e.message}`; }
      WD.toast(e.message, 'error');
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = '▶ Sync Now'; }
    }
  }

  async function _syncLogLoad() {
    const area = document.getElementById('syncLogArea');
    if (!area) return;
    try {
      const data = await API.get('exchange_sync_log');
      const log  = data.log || [];
      if (!log.length) {
        area.innerHTML = '<div style="font-size:13px;color:var(--text-muted)">Abhi tak koi sync nahi hua</div>';
        return;
      }
      const rows = log.map(l => {
        const statusColor = l.status === 'OK' ? '#22c55e' : l.status === 'PARTIAL' ? '#f59e0b' : '#ef4444';
        return `<tr>
          <td style="font-size:12px">${(l.synced_at||'').slice(0,16)}</td>
          <td><strong>${l.exchange}</strong></td>
          <td><span style="font-size:11px;font-weight:700;color:${statusColor}">${l.status}</span></td>
          <td style="text-align:center">${l.trades_fetched}</td>
          <td style="text-align:center;color:var(--green)">${l.trades_new}</td>
          <td style="text-align:center;color:var(--text-muted)">${l.trades_skipped}</td>
          <td style="font-size:11px;color:var(--red);max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${l.error_msg || '—'}</td>
        </tr>`;
      }).join('');
      area.innerHTML = `<div style="overflow-x:auto">
<table class="wd-table" style="min-width:620px">
  <thead><tr><th>Date</th><th>Exchange</th><th>Status</th><th>Fetched</th><th>New</th><th>Skipped</th><th>Error</th></tr></thead>
  <tbody>${rows}</tbody>
</table></div>`;
    } catch {
      area.innerHTML = '<div style="font-size:13px;color:var(--text-muted)">Log load failed</div>';
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

    if      (tab === 'holdings')     _loadHoldings();
    else if (tab === 'transactions') _loadTransactions();
    else if (tab === 'tax')          _loadTax();
    else if (tab === 'import')       _loadImport();
    else if (tab === 'exchange')     _loadExchangeSync();
    else if (tab === 'taxcalc')      _loadTaxCalc();
    else if (tab === 'coldwallet')   _loadColdWallets();
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

    if (coinId) {
      const sel = document.getElementById('cAddCoinSel');
      if (sel) sel.value = coinId;
    }

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
      .btn-icon-sm:hover         { background: var(--accent);    color:#fff; border-color:var(--accent); }
      .btn-icon-sm.btn-danger-sm:hover { background: var(--red); color:#fff; border-color:var(--red); }
    `;
    document.head.appendChild(s);
  }

  // ── INR formatter for live price updates ──────────────────────────────
  function _fmtINR(n) {
    n = parseFloat(n) || 0;
    if (Math.abs(n) >= 1e7) return '₹' + (n / 1e7).toFixed(2) + 'Cr';
    if (Math.abs(n) >= 1e5) return '₹' + (n / 1e5).toFixed(2) + 'L';
    return '₹' + n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  // ── t42: Tax 30% Flat Calculator ──────────────────────────────────────
  async function _loadTaxCalc() {
    const content = document.getElementById('cryptoContent');
    content.innerHTML = '';

    // Try fetching holdings for the scenario dropdown
    let holdingsOpts = '<option value="">-- Select coin from portfolio --</option>';
    try {
      const hd = await API.get('crypto_list');
      (hd.holdings || []).forEach(h => {
        holdingsOpts += `<option value="${h.coin_id}"
          data-sym="${h.coin_symbol}"
          data-avg="${h.avg_buy_price}"
          data-qty="${h.quantity}"
          data-invested="${h.total_invested}">
          ${h.coin_symbol} — ${h.coin_name} (Qty: ${parseFloat(h.quantity).toFixed(4)})
        </option>`;
      });
    } catch { /* ignore */ }

    content.innerHTML = `
<div style="max-width:800px">
  <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px;flex-wrap:wrap">
    <h3 style="margin:0;font-size:15px;font-weight:700">🧮 VDA Tax Calculator — Sec 115BBH</h3>
    <span style="background:#fef3c7;color:#92400e;font-size:11px;font-weight:700;padding:3px 8px;border-radius:99px">30% FLAT + 4% CESS + 1% TDS</span>
  </div>

  <!-- Mode selector -->
  <div style="display:flex;gap:8px;margin-bottom:20px">
    <button id="tcMode_single"   class="btn btn-primary btn-sm"   onclick="CRYPTO._tcSetMode('single')">Single Trade</button>
    <button id="tcMode_scenario" class="btn btn-secondary btn-sm" onclick="CRYPTO._tcSetMode('scenario')">What-If Scenario</button>
    <button id="tcMode_fy"       class="btn btn-secondary btn-sm" onclick="CRYPTO._tcSetMode('fy')">FY Report</button>
  </div>

  <!-- SINGLE MODE -->
  <div id="tcSingle" style="background:var(--card-bg);border:1px solid var(--border);border-radius:14px;padding:22px">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px">
      <div>
        <label style="font-size:11px;font-weight:700;display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Sale Value (₹) *</label>
        <input id="tcSaleVal" type="number" step="any" min="0" placeholder="e.g. 500000"
               oninput="CRYPTO._tcPreview()"
               style="width:100%;padding:10px 12px;border:2px solid var(--border);border-radius:10px;font-size:15px;font-weight:600;box-sizing:border-box">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Cost Basis (₹) *</label>
        <input id="tcCostBase" type="number" step="any" min="0" placeholder="e.g. 300000"
               oninput="CRYPTO._tcPreview()"
               style="width:100%;padding:10px 12px;border:2px solid var(--border);border-radius:10px;font-size:15px;font-weight:600;box-sizing:border-box">
      </div>
    </div>
    <div style="margin-bottom:14px">
      <label style="font-size:11px;font-weight:700;display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">TDS Already Deducted (₹)</label>
      <input id="tcTdsPaid" type="number" step="any" min="0" placeholder="0"
             oninput="CRYPTO._tcPreview()"
             style="width:220px;padding:10px 12px;border:2px solid var(--border);border-radius:10px;font-size:14px;box-sizing:border-box">
      <span style="font-size:11px;color:var(--text-muted);margin-left:8px">Exchange ne deduct kiya TDS</span>
    </div>
    <button class="btn btn-primary" onclick="CRYPTO._tcCalculate('single')">Calculate Tax →</button>
  </div>

  <!-- SCENARIO MODE -->
  <div id="tcScenario" style="display:none;background:var(--card-bg);border:1px solid var(--border);border-radius:14px;padding:22px">
    <div style="margin-bottom:14px">
      <label style="font-size:11px;font-weight:700;display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Portfolio se coin select karo</label>
      <select id="tcScCoin" onchange="CRYPTO._tcScenarioCoinSelect(this)"
              style="width:100%;padding:10px 12px;border:2px solid var(--border);border-radius:10px;background:var(--card-bg);color:var(--text);font-size:13px">
        ${holdingsOpts}
      </select>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px">
      <div>
        <label style="font-size:11px;font-weight:700;display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Qty to Sell *</label>
        <input id="tcScQty" type="number" step="any" min="0" placeholder="0.5"
               style="width:100%;padding:10px 12px;border:2px solid var(--border);border-radius:10px;font-size:14px;font-weight:600;box-sizing:border-box">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Target Sell Price (₹)</label>
        <input id="tcScSellPx" type="number" step="any" min="0" placeholder="Current price"
               style="width:100%;padding:10px 12px;border:2px solid var(--border);border-radius:10px;font-size:14px;font-weight:600;box-sizing:border-box">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Avg Buy Price (₹) <small style="color:var(--text-muted);font-weight:400">auto-filled</small></label>
        <input id="tcScAvgBuy" type="number" step="any" placeholder="Auto from portfolio"
               style="width:100%;padding:10px 12px;border:2px solid var(--border);border-radius:10px;font-size:14px;box-sizing:border-box">
      </div>
    </div>
    <button class="btn btn-primary" onclick="CRYPTO._tcCalculate('scenario')">Calculate →</button>
  </div>

  <!-- FY REPORT MODE -->
  <div id="tcFy" style="display:none;background:var(--card-bg);border:1px solid var(--border);border-radius:14px;padding:22px">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap">
      <div>
        <label style="font-size:11px;font-weight:700;display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted)">Financial Year</label>
        <select id="tcFyYear" style="padding:10px 14px;border:2px solid var(--border);border-radius:10px;background:var(--card-bg);color:var(--text);font-size:14px">
          <option value="2024-25">FY 2024-25 (Current)</option>
          <option value="2023-24">FY 2023-24</option>
          <option value="2022-23">FY 2022-23</option>
        </select>
      </div>
      <div style="align-self:flex-end">
        <button class="btn btn-primary" onclick="CRYPTO._tcLoadFyReport()">Generate Report →</button>
      </div>
    </div>
    <div id="tcFyResult"></div>
  </div>

  <!-- Result area -->
  <div id="tcResult" style="margin-top:20px"></div>

  <!-- Saved calculations -->
  <div style="margin-top:28px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
      <h4 style="margin:0;font-size:13px;font-weight:700">📌 Saved Calculations</h4>
      <button class="btn btn-ghost btn-sm" onclick="CRYPTO._tcLoadHistory()">↻ Refresh</button>
    </div>
    <div id="tcHistory"><div style="font-size:12px;color:var(--text-muted)">Loading…</div></div>
  </div>

  <!-- Tax info panel -->
  <div style="margin-top:24px;background:#faf5ff;border:1px solid #e9d5ff;border-radius:12px;padding:16px 20px">
    <h4 style="margin:0 0 10px;font-size:13px;font-weight:700;color:#6d28d9">📘 VDA Tax Reference (India)</h4>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12px;color:#374151">
      <div>✅ <strong>30% flat tax</strong> on VDA gains (Sec 115BBH)</div>
      <div>✅ <strong>4% Cess</strong> on tax amount (Health + Education)</div>
      <div>✅ <strong>1% TDS</strong> on sale value > ₹10,000 (Sec 194S)</div>
      <div>✅ <strong>No loss offset</strong> — VDA loss can't reduce other income</div>
      <div>✅ Applicable from <strong>1 April 2022</strong></div>
      <div>✅ Report in <strong>ITR-2 / ITR-3</strong> under Schedule VDA</div>
    </div>
  </div>
</div>`;

    // Load history on tab open
    _tcLoadHistory();
    // Set default mode
    _tcSetMode('single');
  }

  let _tcCurrentMode = 'single';

  function _tcSetMode(mode) {
    _tcCurrentMode = mode;
    ['single','scenario','fy'].forEach(m => {
      const el = document.getElementById('tc' + m.charAt(0).toUpperCase() + m.slice(1));
      if (el) el.style.display = m === mode ? 'block' : 'none';
      const btn = document.getElementById('tcMode_' + m);
      if (btn) {
        btn.className = m === mode
          ? 'btn btn-primary btn-sm'
          : 'btn btn-secondary btn-sm';
      }
    });
    if (mode === 'fy') {
      const r = document.getElementById('tcFyResult');
      if (r) r.innerHTML = '';
    }
  }

  function _tcPreview() {
    const sale = parseFloat(document.getElementById('tcSaleVal')?.value  || 0);
    const cost = parseFloat(document.getElementById('tcCostBase')?.value || 0);
    if (!sale || !cost) return;
    const gain = sale - cost;
    const tax  = gain > 0 ? gain * 0.30 * 1.04 : 0;
    const res  = document.getElementById('tcResult');
    if (res && !res.dataset.calculated) {
      res.innerHTML = `<div style="font-size:12px;color:var(--text-muted);padding:8px 0">
        Preview: Gain = ${fmtInr(gain)} → Approx Tax = ${fmtInr(tax)}</div>`;
    }
  }

  function _tcScenarioCoinSelect(sel) {
    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) return;
    const avg = parseFloat(opt.dataset.avg || 0);
    const inp = document.getElementById('tcScAvgBuy');
    if (inp) inp.value = avg > 0 ? avg : '';
  }

  async function _tcCalculate(mode) {
    const res = document.getElementById('tcResult');
    if (res) { res.innerHTML = '<div class="wd-loader" style="margin:12px auto;width:24px;height:24px"></div>'; res.dataset.calculated = '1'; }

    try {
      let payload = { action: 'crypto_tax_calc', mode };

      if (mode === 'single') {
        const saleVal  = parseFloat(document.getElementById('tcSaleVal')?.value  || 0);
        const costBase = parseFloat(document.getElementById('tcCostBase')?.value || 0);
        const tdsPaid  = parseFloat(document.getElementById('tcTdsPaid')?.value  || 0);
        if (!saleVal) { WD.toast('Sale value required', 'error'); return; }
        Object.assign(payload, { sale_value: saleVal, cost_basis: costBase, tds_deducted: tdsPaid });

      } else if (mode === 'scenario') {
        const sel      = document.getElementById('tcScCoin');
        const opt      = sel?.options[sel?.selectedIndex];
        const coinId   = sel?.value || '';
        const sym      = opt?.dataset.sym || '';
        const qty      = parseFloat(document.getElementById('tcScQty')?.value     || 0);
        const sellPx   = parseFloat(document.getElementById('tcScSellPx')?.value  || 0);
        const avgBuy   = parseFloat(document.getElementById('tcScAvgBuy')?.value  || 0);
        const totalQty = parseFloat(opt?.dataset.qty || 0);

        if (!qty)    { WD.toast('Qty to sell required', 'error'); return; }
        if (!sellPx) { WD.toast('Target sell price required', 'error'); return; }

        const costBasisTotal = avgBuy > 0 ? avgBuy * qty : parseFloat(opt?.dataset.invested || 0);
        Object.assign(payload, {
          coin_id: coinId, coin_symbol: sym,
          qty, sell_price_inr: sellPx,
          cost_basis_inr: costBasisTotal,
        });
      }

      const data = await API.post(payload);
      if (!data.success) throw new Error(data.message);

      _tcRenderResult(data, mode);
    } catch (e) {
      if (res) res.innerHTML = `<div class="wd-empty">⚠️ ${e.message}</div>`;
    }
  }

  function _tcRenderResult(data, mode) {
    const res = document.getElementById('tcResult');
    if (!res) return;

    const gain      = data.gain_loss    || 0;
    const isGain    = data.is_gain;
    const gainColor = isGain ? '#16a34a' : '#dc2626';
    const gainLabel = isGain ? 'Gain' : 'Loss (No offset allowed)';

    let extraHtml = '';

    if (mode === 'scenario' && data.min_profitable_price) {
      extraHtml += `
      <div style="margin-top:14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:12px 16px;font-size:12px">
        <strong style="color:#15803d">📊 Scenario Details</strong><br>
        Qty Sold: <strong>${data.qty_sold}</strong> ${data.coin_symbol} @ <strong>${fmtInr(data.sell_price)}</strong><br>
        Break-even Price: <strong>${fmtInr(data.breakeven_price)}</strong><br>
        Min Price to Profit after Tax: <strong style="color:#15803d">${fmtInr(data.min_profitable_price)}</strong>
      </div>`;
    }

    if (mode === 'single' && data.sensitivity) {
      const sensRows = data.sensitivity.map(s =>
        `<tr>
          <td style="text-align:center">${Math.round(s.sale_multiplier * 100)}%</td>
          <td>${fmtInr(s.sale_value)}</td>
          <td>${fmtInr(s.total_tax)}</td>
          <td style="font-weight:700">${fmtInr(s.net_after_tax)}</td>
        </tr>`
      ).join('');
      extraHtml += `
      <div style="margin-top:16px">
        <div style="font-size:12px;font-weight:700;margin-bottom:6px">📈 Sensitivity Analysis (Tax at different sale prices)</div>
        <table class="wd-table" style="font-size:12px">
          <thead><tr><th>Sale %</th><th>Sale Value</th><th>Tax</th><th>Net After Tax</th></tr></thead>
          <tbody>${sensRows}</tbody>
        </table>
      </div>`;
    }

    res.innerHTML = `
<div style="background:var(--card-bg);border:2px solid ${isGain ? '#86efac' : '#fca5a5'};border-radius:14px;padding:20px">
  <div style="font-size:13px;font-weight:700;margin-bottom:14px;color:${gainColor}">${isGain ? '📈' : '📉'} Tax Calculation Result</div>
  <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:12px;margin-bottom:14px">
    <div style="background:var(--bg-secondary,#f9fafb);border-radius:10px;padding:12px">
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text-muted);letter-spacing:.5px">Sale Value</div>
      <div style="font-size:20px;font-weight:800">${fmtInr(data.sale_value)}</div>
    </div>
    <div style="background:var(--bg-secondary,#f9fafb);border-radius:10px;padding:12px">
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:var(--text-muted);letter-spacing:.5px">Cost Basis</div>
      <div style="font-size:20px;font-weight:800">${fmtInr(data.cost_basis)}</div>
    </div>
    <div style="background:${isGain ? '#f0fdf4' : '#fef2f2'};border-radius:10px;padding:12px">
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:${gainColor};letter-spacing:.5px">${gainLabel}</div>
      <div style="font-size:20px;font-weight:800;color:${gainColor}">${isGain ? '+' : ''}${fmtInr(gain)}</div>
    </div>
    <div style="background:#faf5ff;border-radius:10px;padding:12px">
      <div style="font-size:10px;font-weight:700;text-transform:uppercase;color:#7c3aed;letter-spacing:.5px">30% Tax + 4% Cess</div>
      <div style="font-size:20px;font-weight:800;color:#7c3aed">${fmtInr(data.total_tax)}</div>
    </div>
  </div>
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;font-size:12px;margin-bottom:14px">
    <div style="background:var(--bg-secondary,#f9fafb);border-radius:8px;padding:10px">
      <div style="color:var(--text-muted);font-weight:600;margin-bottom:2px">30% Tax</div>
      <div style="font-weight:700">${fmtInr(data.tax_at_30pct)}</div>
    </div>
    <div style="background:var(--bg-secondary,#f9fafb);border-radius:8px;padding:10px">
      <div style="color:var(--text-muted);font-weight:600;margin-bottom:2px">4% Cess</div>
      <div style="font-weight:700">${fmtInr(data.cess_4pct)}</div>
    </div>
    <div style="background:var(--bg-secondary,#f9fafb);border-radius:8px;padding:10px">
      <div style="color:var(--text-muted);font-weight:600;margin-bottom:2px">1% TDS (Sale)</div>
      <div style="font-weight:700">${fmtInr(data.tds_on_sale_1pct)}</div>
    </div>
  </div>
  <div style="background:#1e293b;color:#f8fafc;border-radius:10px;padding:14px;display:flex;align-items:center;justify-content:space-between">
    <span style="font-size:13px;font-weight:700">💰 Net Tax Payable</span>
    <span style="font-size:22px;font-weight:900;color:#f87171">${fmtInr(data.net_tax_payable)}</span>
  </div>
  ${data.no_loss_offset ? '<div style="margin-top:10px;font-size:11px;color:#dc2626;font-weight:600">⚠️ Loss hai — Sec 115BBH ke tehat kisi bhi income se offset nahi hogi.</div>' : ''}
  ${extraHtml}
  <div style="margin-top:12px;display:flex;gap:8px">
    <button class="btn btn-secondary btn-sm" onclick="CRYPTO._tcSave(${JSON.stringify(JSON.stringify(data)).slice(1,-1)})">📌 Save Calculation</button>
  </div>
</div>`;
  }

  async function _tcLoadFyReport() {
    const fy  = document.getElementById('tcFyYear')?.value || '2024-25';
    const res = document.getElementById('tcFyResult');
    if (res) res.innerHTML = '<div class="wd-loader" style="margin:20px auto"></div>';
    try {
      const data = await API.get(`crypto_tax_report&fy=${fy}`);
      const t    = data.totals || {};
      const rows = (data.breakdown || []).map(r => {
        const gainC = r.gain >= 0 ? '#16a34a' : '#dc2626';
        return `<tr>
          <td style="font-size:12px">${r.date}</td>
          <td style="font-weight:700">${r.coin}</td>
          <td style="font-size:12px">${r.qty.toFixed(4)}</td>
          <td>${fmtInr(r.sale_value)}</td>
          <td>${fmtInr(r.cost_basis)}</td>
          <td style="color:${gainC};font-weight:700">${r.gain >= 0 ? '+' : ''}${fmtInr(r.gain)}</td>
          <td style="color:#7c3aed;font-weight:700">${fmtInr(r.tax_payable)}</td>
          <td style="font-size:12px;color:var(--text-muted)">${r.exchange || '—'}</td>
        </tr>`;
      }).join('');

      if (res) res.innerHTML = !data.transaction_count
        ? `<div class="wd-empty" style="padding:30px;text-align:center">FY ${fy} mein koi sell transaction nahi</div>`
        : `
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px">
  <div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:12px">
    <div style="font-size:10px;font-weight:700;color:#15803d;text-transform:uppercase;margin-bottom:3px">Total Gain</div>
    <div style="font-size:18px;font-weight:800;color:#16a34a">${fmtInr(t.gain)}</div>
  </div>
  <div style="background:#faf5ff;border:1px solid #e9d5ff;border-radius:10px;padding:12px">
    <div style="font-size:10px;font-weight:700;color:#7c3aed;text-transform:uppercase;margin-bottom:3px">Total Tax</div>
    <div style="font-size:18px;font-weight:800;color:#7c3aed">${fmtInr(t.tax)}</div>
  </div>
  <div style="background:#fef2f2;border:1px solid #fca5a5;border-radius:10px;padding:12px">
    <div style="font-size:10px;font-weight:700;color:#dc2626;text-transform:uppercase;margin-bottom:3px">Net Tax Payable</div>
    <div style="font-size:18px;font-weight:800;color:#dc2626">${fmtInr(t.net_payable)}</div>
  </div>
</div>
<div style="overflow-x:auto">
<table class="wd-table" style="min-width:700px;font-size:12px">
  <thead><tr><th>Date</th><th>Coin</th><th>Qty</th><th>Sale Value</th><th>Cost</th><th>Gain/Loss</th><th>Tax</th><th>Exchange</th></tr></thead>
  <tbody>${rows}</tbody>
</table></div>
<div style="margin-top:10px;font-size:11px;color:var(--text-muted)">${data.notes?.itr_schedule || ''}</div>`;

    } catch (e) {
      if (res) res.innerHTML = `<div class="wd-empty">⚠️ ${e.message}</div>`;
    }
  }

  async function _tcSave(calcJsonStr) {
    const label = prompt('Calculation ka naam do (optional):', 'VDA Tax Calc ' + new Date().toLocaleDateString('en-IN'));
    if (label === null) return;
    try {
      const r = await API.post({ action: 'crypto_tax_save', label, calc_data: calcJsonStr });
      if (!r.success) throw new Error(r.message);
      WD.toast('Saved!', 'success');
      _tcLoadHistory();
    } catch (e) { WD.toast(e.message, 'error'); }
  }

  async function _tcLoadHistory() {
    const hist = document.getElementById('tcHistory');
    if (!hist) return;
    try {
      const data = await API.get('crypto_tax_history');
      const saved = data.saved || [];
      if (!saved.length) {
        hist.innerHTML = '<div style="font-size:12px;color:var(--text-muted);padding:8px 0">Koi saved calculation nahi</div>';
        return;
      }
      hist.innerHTML = saved.map(s => `
        <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;border:1px solid var(--border);
                    border-radius:8px;margin-bottom:6px;background:var(--card-bg)">
          <div style="flex:1">
            <div style="font-size:13px;font-weight:600">${s.label}</div>
            <div style="font-size:11px;color:var(--text-muted)">FY ${s.fy} &nbsp;·&nbsp; ${new Date(s.created_at).toLocaleDateString('en-IN')}</div>
          </div>
          ${s.calc?.total_tax != null ? `<div style="font-weight:700;color:#7c3aed">${fmtInr(s.calc.total_tax)}</div>` : ''}
          <button class="btn-icon-sm btn-danger-sm" onclick="CRYPTO._tcDelete(${s.id})" title="Delete">✕</button>
        </div>`).join('');
    } catch { hist.innerHTML = '<div style="font-size:12px;color:var(--text-muted)">Load failed</div>'; }
  }

  async function _tcDelete(id) {
    if (!confirm('Delete this saved calculation?')) return;
    try {
      const r = await API.post({ action: 'crypto_tax_delete', id });
      if (!r.success) throw new Error(r.message);
      WD.toast('Deleted', 'success');
      _tcLoadHistory();
    } catch (e) { WD.toast(e.message, 'error'); }
  }

  // ── tc006: Cold Wallet Tracker ─────────────────────────────────────────
  async function _loadColdWallets() {
    const content = document.getElementById('cryptoContent');
    content.innerHTML = '<div class="wd-loader" style="margin:40px auto"></div>';
    try {
      const data    = await API.get('cold_wallet_list');
      const wallets = data.wallets    || [];
      const meta    = data.chains_meta|| {};
      const devices = data.device_types|| {};
      const totalVal= data.total_value || 0;

      const deviceOptions = Object.entries(devices).map(([v,l]) =>
        `<option value="${v}">${l}</option>`).join('');

      const chainOptions = Object.entries(meta).map(([k,v]) =>
        `<option value="${k}">${v.name} (${v.symbol})</option>`).join('');

      const walletRows = wallets.map(w => {
        const pnl   = w.unrealised_pnl;
        const pnlC  = pnl != null ? (pnl >= 0 ? '#16a34a' : '#dc2626') : 'var(--text-muted)';
        const pnlTxt= pnl != null ? ((pnl >= 0 ? '+' : '') + fmtInr(pnl)) : '—';
        const chainM = w.chain_meta || {};
        return `<tr>
          <td>
            <div style="font-weight:700">${w.label}</div>
            <div style="font-size:11px;color:var(--text-muted);font-family:monospace">${w.address_masked}</div>
          </td>
          <td>
            <div style="font-size:12px;font-weight:600">${chainM.name || w.chain}</div>
            <div style="font-size:11px;color:var(--text-muted)">${chainM.symbol || ''}</div>
          </td>
          <td>
            <span style="background:var(--bg-secondary,#f3f4f6);padding:3px 8px;border-radius:6px;font-size:11px;font-weight:700">
              ${w.device_label}
            </span>
          </td>
          <td style="font-family:monospace;font-size:12px">${parseFloat(w.quantity).toFixed(6)}</td>
          <td style="font-weight:700">${fmtInr(w.live_value_inr)}</td>
          <td style="color:${pnlC};font-weight:700">${pnlTxt}</td>
          <td>
            ${w.explorer_url
              ? `<a href="${w.explorer_url}" target="_blank" rel="noopener"
                    style="font-size:11px;color:var(--accent,#6366f1);text-decoration:none"
                    title="View on Explorer">🔍 Explorer</a>`
              : '—'}
          </td>
          <td>
            <button class="btn-icon-sm" onclick="CRYPTO._cwRefreshModal(${w.id},'${w.label.replace(/'/g,"\\'")}',${w.quantity})" title="Update Balance">↻</button>
            <button class="btn-icon-sm" onclick="CRYPTO._cwEditModal(${w.id})" title="Edit">✏</button>
            <button class="btn-icon-sm btn-danger-sm" onclick="CRYPTO._cwDelete(${w.id},'${w.label.replace(/'/g,"\\'")}'')" title="Delete">✕</button>
          </td>
        </tr>`;
      }).join('');

      content.innerHTML = `
<!-- Summary -->
<div class="stats-grid" style="margin-bottom:18px">
  <div class="stat-card"><div class="stat-label">Total Cold Storage</div>
    <div class="stat-value" style="color:#7c3aed">${fmtInr(totalVal)}</div></div>
  <div class="stat-card"><div class="stat-label">Wallets Tracked</div>
    <div class="stat-value">${wallets.length}</div>
    <div class="stat-sub">${data.by_chain?.length || 0} chains</div></div>
  ${(data.by_chain || []).slice(0,2).map(c =>
    `<div class="stat-card"><div class="stat-label">${c.chain.charAt(0).toUpperCase()+c.chain.slice(1)}</div>
     <div class="stat-value">${fmtInr(c.value)}</div>
     <div class="stat-sub">${c.count} wallet${c.count>1?'s':''}</div></div>`
  ).join('')}
</div>

<!-- Action bar -->
<div style="display:flex;align-items:center;gap:8px;margin-bottom:16px;flex-wrap:wrap">
  <button class="btn btn-primary btn-sm" onclick="CRYPTO._cwAddModal()">+ Add Cold Wallet</button>
  <button class="btn btn-ghost btn-sm"   onclick="CRYPTO._loadColdWallets()">↻ Refresh</button>
  <span style="font-size:11px;color:var(--text-muted);margin-left:8px">
    🔒 Only public addresses stored — no private keys
  </span>
</div>

${!wallets.length
  ? `<div class="wd-empty" style="padding:50px;text-align:center">
      <div style="font-size:44px;margin-bottom:12px">🔐</div>
      <p>Koi cold wallet tracked nahi.<br>
      <small style="color:var(--text-muted)">Ledger, Trezor, paper wallets — sirf public address add karo. Private key KABHI mat daalo.</small></p>
     </div>`
  : `<div style="overflow-x:auto">
<table class="wd-table" style="min-width:750px">
  <thead><tr>
    <th>Wallet</th><th>Chain</th><th>Device</th>
    <th>Quantity</th><th>Value (₹)</th><th>P&L</th><th>Explorer</th><th></th>
  </tr></thead>
  <tbody>${walletRows}</tbody>
</table></div>`}

<!-- Add/Edit Modal -->
<div id="cwModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:var(--card-bg);border-radius:16px;padding:26px;width:100%;max-width:500px;max-height:90vh;overflow-y:auto;position:relative;box-shadow:0 24px 64px rgba(0,0,0,.3)">
    <button onclick="CRYPTO._cwCloseModal()" style="position:absolute;top:14px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted)">✕</button>
    <h3 id="cwModalTitle" style="margin:0 0 18px;font-size:15px;font-weight:700">Add Cold Wallet</h3>
    <div id="cwModalBody">
      <div style="display:grid;gap:12px">
        <div style="background:#fef9c3;border:1px solid #fde047;border-radius:8px;padding:10px 14px;font-size:12px;color:#713f12">
          🔒 Sirf <strong>public address</strong> add karo. Private key / seed phrase KABHI mat daalo.
        </div>
        <input type="hidden" id="cwId">
        <div>
          <label style="font-size:11px;font-weight:700;display:block;margin-bottom:4px">Label *</label>
          <input id="cwLabel" placeholder="My Ledger BTC" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;box-sizing:border-box">
        </div>
        <div>
          <label style="font-size:11px;font-weight:700;display:block;margin-bottom:4px">Public Address *</label>
          <input id="cwAddress" placeholder="bc1q… or 0x… or full address" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-family:monospace;font-size:12px;box-sizing:border-box">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div>
            <label style="font-size:11px;font-weight:700;display:block;margin-bottom:4px">Chain *</label>
            <select id="cwChain" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--card-bg);color:var(--text)">
              ${chainOptions}
            </select>
          </div>
          <div>
            <label style="font-size:11px;font-weight:700;display:block;margin-bottom:4px">Device Type</label>
            <select id="cwDevice" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;background:var(--card-bg);color:var(--text)">
              ${deviceOptions}
            </select>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div>
            <label style="font-size:11px;font-weight:700;display:block;margin-bottom:4px">Quantity (native coin)</label>
            <input id="cwQty" type="number" step="any" min="0" placeholder="0.05"
                   style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;box-sizing:border-box">
          </div>
          <div>
            <label style="font-size:11px;font-weight:700;display:block;margin-bottom:4px">Cost Basis (₹)</label>
            <input id="cwCost" type="number" step="any" min="0" placeholder="Total purchase cost"
                   style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;box-sizing:border-box">
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div>
            <label style="font-size:11px;font-weight:700;display:block;margin-bottom:4px">Purchase Date</label>
            <input id="cwDate" type="date" value="${new Date().toISOString().slice(0,10)}"
                   style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px">
          </div>
          <div>
            <label style="font-size:11px;font-weight:700;display:block;margin-bottom:4px">Alert if drops by %</label>
            <input id="cwAlert" type="number" step="any" min="0" max="100" placeholder="e.g. 20"
                   style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;box-sizing:border-box">
          </div>
        </div>
        <div>
          <label style="font-size:11px;font-weight:700;display:block;margin-bottom:4px">Notes</label>
          <input id="cwNotes" placeholder="Optional" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;box-sizing:border-box">
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px">
          <button class="btn btn-secondary" onclick="CRYPTO._cwCloseModal()">Cancel</button>
          <button class="btn btn-primary" id="cwSubmitBtn" onclick="CRYPTO._cwSubmit()">Add Wallet</button>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Refresh Balance Modal -->
<div id="cwRefreshModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center">
  <div style="background:var(--card-bg);border-radius:16px;padding:24px;width:100%;max-width:380px;position:relative;box-shadow:0 24px 64px rgba(0,0,0,.3)">
    <button onclick="document.getElementById('cwRefreshModal').style.display='none'" style="position:absolute;top:14px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted)">✕</button>
    <h3 id="cwRefreshTitle" style="margin:0 0 16px;font-size:15px;font-weight:700">Update Balance</h3>
    <input type="hidden" id="cwRefreshId">
    <div style="display:grid;gap:12px">
      <div>
        <label style="font-size:11px;font-weight:700;display:block;margin-bottom:4px">Current Quantity</label>
        <input id="cwRefreshQty" type="number" step="any" min="0"
               style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;box-sizing:border-box">
      </div>
      <div>
        <label style="font-size:11px;font-weight:700;display:block;margin-bottom:4px">Current Value (₹) <small style="color:var(--text-muted)">leave blank to auto-calc</small></label>
        <input id="cwRefreshVal" type="number" step="any" min="0" placeholder="Auto-calculated from price"
               style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;box-sizing:border-box">
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end">
        <button class="btn btn-secondary" onclick="document.getElementById('cwRefreshModal').style.display='none'">Cancel</button>
        <button class="btn btn-primary" onclick="CRYPTO._cwRefreshSubmit()">Update</button>
      </div>
    </div>
  </div>
</div>`;

    } catch (e) {
      content.innerHTML = `<div class="wd-empty">⚠️ ${e.message}</div>`;
    }
  }

  function _cwCloseModal() {
    const m = document.getElementById('cwModal');
    if (m) m.style.display = 'none';
  }

  function _cwAddModal() {
    document.getElementById('cwId').value       = '';
    document.getElementById('cwLabel').value    = '';
    document.getElementById('cwAddress').value  = '';
    document.getElementById('cwQty').value      = '';
    document.getElementById('cwCost').value     = '';
    document.getElementById('cwNotes').value    = '';
    document.getElementById('cwAlert').value    = '';
    const title = document.getElementById('cwModalTitle');
    if (title) title.textContent = 'Add Cold Wallet';
    const btn = document.getElementById('cwSubmitBtn');
    if (btn) btn.textContent = 'Add Wallet';
    const m = document.getElementById('cwModal');
    if (m) m.style.display = 'flex';
  }

  async function _cwEditModal(id) {
    try {
      const data = await API.get('cold_wallet_list');
      const w    = (data.wallets || []).find(x => x.id === id);
      if (!w) return WD.toast('Wallet not found', 'error');

      document.getElementById('cwId').value       = w.id;
      document.getElementById('cwLabel').value    = w.label;
      document.getElementById('cwAddress').value  = w.address;
      document.getElementById('cwQty').value      = w.quantity;
      document.getElementById('cwCost').value     = w.cost_basis_inr;
      document.getElementById('cwNotes').value    = w.notes || '';
      document.getElementById('cwAlert').value    = w.alert_threshold_pct || '';
      const title = document.getElementById('cwModalTitle');
      if (title) title.textContent = 'Edit Wallet';
      const btn = document.getElementById('cwSubmitBtn');
      if (btn) btn.textContent = 'Update Wallet';
      const m = document.getElementById('cwModal');
      if (m) m.style.display = 'flex';
    } catch (e) { WD.toast(e.message, 'error'); }
  }

  async function _cwSubmit() {
    const id      = document.getElementById('cwId')?.value || '';
    const label   = document.getElementById('cwLabel')?.value?.trim()   || '';
    const address = document.getElementById('cwAddress')?.value?.trim() || '';
    const chain   = document.getElementById('cwChain')?.value           || 'bitcoin';
    const device  = document.getElementById('cwDevice')?.value          || 'OTHER';
    const qty     = parseFloat(document.getElementById('cwQty')?.value  || 0);
    const cost    = parseFloat(document.getElementById('cwCost')?.value || 0);
    const date    = document.getElementById('cwDate')?.value            || '';
    const alert_t = parseFloat(document.getElementById('cwAlert')?.value|| 0);
    const notes   = document.getElementById('cwNotes')?.value?.trim()   || '';

    const isEdit  = !!id;

    if (!isEdit && !address) return WD.toast('Wallet address required', 'error');
    if (!label)              return WD.toast('Label required', 'error');

    try {
      const payload = isEdit
        ? { action: 'cold_wallet_edit', id, label, quantity: qty, cost_basis_inr: cost,
            purchase_date: date, alert_threshold_pct: alert_t, notes }
        : { action: 'cold_wallet_add', label, address, chain, device_type: device,
            quantity: qty, cost_basis_inr: cost, purchase_date: date,
            alert_threshold_pct: alert_t, notes };

      const r = await API.post(payload);
      if (!r.success) throw new Error(r.message);
      WD.toast(r.message || (isEdit ? 'Updated!' : 'Wallet added!'), 'success');
      _cwCloseModal();
      _loadColdWallets();
    } catch (e) { WD.toast(e.message, 'error'); }
  }

  function _cwRefreshModal(id, label, qty) {
    document.getElementById('cwRefreshId').value   = id;
    document.getElementById('cwRefreshQty').value  = qty;
    document.getElementById('cwRefreshVal').value  = '';
    const title = document.getElementById('cwRefreshTitle');
    if (title) title.textContent = `Update Balance — ${label}`;
    const m = document.getElementById('cwRefreshModal');
    if (m) m.style.display = 'flex';
  }

  async function _cwRefreshSubmit() {
    const id  = document.getElementById('cwRefreshId')?.value  || '';
    const qty = parseFloat(document.getElementById('cwRefreshQty')?.value || 0);
    const val = parseFloat(document.getElementById('cwRefreshVal')?.value || 0);
    if (!id) return;
    try {
      const r = await API.post({ action: 'cold_wallet_refresh', id, quantity: qty,
                                  ...(val > 0 ? { value_inr: val } : {}) });
      if (!r.success) throw new Error(r.message);
      WD.toast('Balance updated!', 'success');
      document.getElementById('cwRefreshModal').style.display = 'none';
      _loadColdWallets();
    } catch (e) { WD.toast(e.message, 'error'); }
  }

  async function _cwDelete(id, label) {
    if (!confirm(`"${label}" wallet remove karo? Sirf record delete hoga — actual wallet safe rahega.`)) return;
    try {
      const r = await API.post({ action: 'cold_wallet_delete', id });
      if (!r.success) throw new Error(r.message);
      WD.toast('Removed', 'success');
      _loadColdWallets();
    } catch (e) { WD.toast(e.message, 'error'); }
  }

  // ── Public API ─────────────────────────────────────────────────────────
  return {
    init, switchTab, refreshPrices, deleteHolding,
    openAddModal, closeModal, renderHoldings,
    _onCoinSelect, _submitAdd, _updateAmountPreview,
    // tc001: SSE controls
    connectLive:    _sseConnect,
    disconnectLive: _sseDisconnect,
    // t317: Import
    _importPreviewLoad, _importConfirm, _importLogLoad,
    // tc005: Exchange Sync
    _openAddKeyModal, _closeAddKeyModal, _submitAddKey,
    _deleteKey, _runSync, _syncLogLoad,
    // t42: Tax Calc
    _loadTaxCalc, _tcSetMode, _tcPreview, _tcCalculate,
    _tcScenarioCoinSelect, _tcLoadFyReport,
    _tcSave, _tcLoadHistory, _tcDelete,
    // tc006: Cold Wallets
    _loadColdWallets, _cwAddModal, _cwEditModal,
    _cwCloseModal, _cwSubmit, _cwRefreshModal,
    _cwRefreshSubmit, _cwDelete,
  };
})();

document.addEventListener('DOMContentLoaded', () => CRYPTO.init());
