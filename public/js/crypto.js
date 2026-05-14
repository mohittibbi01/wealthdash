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
  <button class="crypto-tab"        id="ctab_defi"         onclick="CRYPTO.switchTab('defi')">🌐 DeFi & Staking</button>
  <button class="crypto-tab"        id="ctab_rebalance"    onclick="CRYPTO.switchTab('rebalance')">⚖️ Rebalance</button>
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

    if (tab === 'holdings')        _loadHoldings();
    else if (tab === 'transactions') _loadTransactions();
    else if (tab === 'tax')          _loadTax();
    else if (tab === 'import')       _loadImport();
    else if (tab === 'exchange')     _loadExchangeSync();
    else if (tab === 'defi')         _loadDefi();
    else if (tab === 'rebalance')    _loadRebalance();
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
  <!-- t40: CoinGecko live search -->
  <div>
    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Coin Search (CoinGecko) *</label>
    <div style="position:relative">
      <input id="cCoinSearch" type="text" placeholder="Search by name or symbol (e.g. BTC, Solana)…"
             autocomplete="off"
             style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;box-sizing:border-box"
             oninput="CRYPTO._cgSearch(this.value)">
      <div id="cCoinDropdown" style="display:none;position:absolute;left:0;right:0;top:100%;z-index:200;
           background:var(--card-bg);border:1.5px solid var(--border);border-radius:8px;
           box-shadow:0 8px 24px rgba(0,0,0,.15);max-height:220px;overflow-y:auto;margin-top:2px"></div>
    </div>
    <div id="cCoinSelected" style="display:none;margin-top:6px;background:var(--bg-secondary,#f3f4f6);
         border-radius:6px;padding:7px 10px;font-size:12px;display:flex;align-items:center;gap:8px">
    </div>
    <div style="margin-top:6px;font-size:11px;color:var(--text-muted)">
      Or pick popular:
      <select id="cAddCoinSel" onchange="CRYPTO._onCoinSelect(this)"
              style="margin-left:4px;padding:3px 6px;border:1px solid var(--border);border-radius:6px;font-size:11px;background:var(--card-bg);color:var(--text)">
        <option value="">-- Popular coins --</option>
        ${coinOptions}
      </select>
    </div>
    <!-- Hidden fields for selected coin -->
    <input type="hidden" id="cCoinId"  value="${coinId}">
    <input type="hidden" id="cCoinSym" value="${sym}">
    <input type="hidden" id="cCoinName"value="${name}">
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
    <div>
      <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Quantity *</label>
      <input id="cQty" type="number" step="any" min="0" placeholder="0.005"
             style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
    </div>
    <div>
      <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Buy Price (₹/coin) *</label>
      <input id="cPrice" type="number" step="any" min="0" placeholder="5000000"
             style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
    </div>
  </div>
  <div id="cAmountPreview" style="font-size:12px;color:var(--text-muted);display:none;background:var(--bg);border-radius:6px;padding:8px 12px"></div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
    <div>
      <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Exchange</label>
      <input id="cExchange" placeholder="WazirX / Binance"
             style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
    </div>
    <div>
      <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Buy Date *</label>
      <input id="cDate" type="date" value="${new Date().toISOString().slice(0,10)}"
             style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
    </div>
  </div>
  <div>
    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px">Notes</label>
    <input id="cNotes" placeholder="Optional notes"
           style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
  </div>
  <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px">
    <button class="btn btn-secondary" onclick="CRYPTO.closeModal()">Cancel</button>
    <button class="btn btn-primary" onclick="CRYPTO._submitAdd()">Add Coin</button>
  </div>
</div>`;

    if (coinId && sym) {
      const ci = document.getElementById('cCoinSelected');
      if (ci) {
        ci.style.display = 'flex';
        ci.innerHTML = `<strong>${sym}</strong><span style="color:var(--text-muted)">${name}</span>
          <span style="font-size:10px;color:var(--text-muted);font-family:monospace">${coinId}</span>`;
      }
      const inp = document.getElementById('cCoinSearch');
      if (inp) inp.value = name;
    }

    ['cQty','cPrice'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.addEventListener('input', _updateAmountPreview);
    });

    const modal = document.getElementById('cryptoModal');
    if (modal) { modal.style.display = 'flex'; }
  }

  // t40: Live CoinGecko coin search debounce
  let _cgSearchTimer = null;
  function _cgSearch(q) {
    clearTimeout(_cgSearchTimer);
    const dd = document.getElementById('cCoinDropdown');
    if (!q || q.length < 2) { if (dd) dd.style.display = 'none'; return; }

    if (dd) {
      dd.style.display = 'block';
      dd.innerHTML = '<div style="padding:10px 12px;font-size:12px;color:var(--text-muted)">Searching…</div>';
    }

    _cgSearchTimer = setTimeout(async () => {
      try {
        const base = window.WD?.appUrl || window.APP_URL || '';
        const res = await fetch(`${base}/api/router.php?action=coingecko_search&q=` + encodeURIComponent(q));
        const data = await res.json();
        const results = data.results || [];

        if (!dd) return;
        if (!results.length) {
          dd.innerHTML = '<div style="padding:10px 12px;font-size:12px;color:var(--text-muted)">No results found</div>';
          return;
        }

        dd.innerHTML = results.map(r => `
          <div onclick="CRYPTO._cgSelectCoin('${r.id}','${r.symbol}','${r.name.replace(/'/g,"\\'")}','${r.thumb||''}')"
               style="padding:9px 12px;cursor:pointer;display:flex;align-items:center;gap:10px;
                      border-bottom:1px solid var(--border-color,#f0f0f0);transition:background .1s"
               onmouseover="this.style.background='var(--bg-secondary,#f9fafb)'"
               onmouseout="this.style.background=''">
            ${r.thumb ? `<img src="${r.thumb}" style="width:22px;height:22px;border-radius:50%;flex-shrink:0">` : `<div style="width:22px;height:22px;border-radius:50%;background:var(--accent,#6366f1);flex-shrink:0"></div>`}
            <div>
              <span style="font-weight:700;font-size:13px">${r.symbol}</span>
              <span style="font-size:12px;color:var(--text-muted);margin-left:6px">${r.name}</span>
            </div>
            ${r.market_cap_rank ? `<span style="margin-left:auto;font-size:10px;color:var(--text-muted)">#${r.market_cap_rank}</span>` : ''}
          </div>`).join('');
      } catch {
        if (dd) dd.innerHTML = '<div style="padding:10px 12px;font-size:12px;color:var(--text-muted)">Search failed</div>';
      }
    }, 350);
  }

  function _cgSelectCoin(id, sym, name, thumb) {
    document.getElementById('cCoinId').value   = id;
    document.getElementById('cCoinSym').value  = sym.toUpperCase();
    document.getElementById('cCoinName').value = name;
    const inp = document.getElementById('cCoinSearch');
    if (inp) inp.value = `${sym.toUpperCase()} — ${name}`;
    const dd = document.getElementById('cCoinDropdown');
    if (dd) dd.style.display = 'none';
    const sel = document.getElementById('cCoinSelected');
    if (sel) {
      sel.style.display = 'flex';
      sel.innerHTML = (thumb ? `<img src="${thumb}" style="width:20px;height:20px;border-radius:50%">` : '') +
        `<strong>${sym.toUpperCase()}</strong><span style="color:var(--text-muted)">${name}</span>
         <span style="font-size:10px;font-family:monospace;color:var(--text-muted)">${id}</span>
         <button onclick="CRYPTO._cgClearCoin()" style="margin-left:auto;background:none;border:none;cursor:pointer;color:var(--text-muted);font-size:12px">✕</button>`;
    }
    // Try to fetch live price and pre-fill
    _cgFetchPrice(id);
  }

  function _cgClearCoin() {
    document.getElementById('cCoinId').value = '';
    document.getElementById('cCoinSym').value = '';
    document.getElementById('cCoinName').value = '';
    const inp = document.getElementById('cCoinSearch');
    if (inp) { inp.value = ''; inp.focus(); }
    const sel = document.getElementById('cCoinSelected');
    if (sel) sel.style.display = 'none';
  }

  async function _cgFetchPrice(coinId) {
    try {
      const base = window.WD?.appUrl || window.APP_URL || '';
      const res  = await fetch(`${base}/api/router.php?action=coingecko_coin_detail&coin_id=${coinId}`);
      const data = await res.json();
      if (data.success && data.price_inr > 0) {
        const priceEl = document.getElementById('cPrice');
        if (priceEl && !priceEl.value) {
          priceEl.value = data.price_inr;
          _updateAmountPreview();
          const hint = document.getElementById('cAmountPreview');
          if (hint) hint.style.display = 'block';
        }
      }
    } catch { /* silent */ }
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
    const coinId  = (document.getElementById('cCoinId')?.value  || '').trim();
    const sym     = (document.getElementById('cCoinSym')?.value || '').trim().toUpperCase();
    const name    = (document.getElementById('cCoinName')?.value|| sym).trim();
    const qty     = parseFloat(document.getElementById('cQty')?.value   || 0);
    const price   = parseFloat(document.getElementById('cPrice')?.value || 0);
    const exchange= document.getElementById('cExchange')?.value?.trim() || '';
    const date    = document.getElementById('cDate')?.value || '';
    const notes   = document.getElementById('cNotes')?.value?.trim() || '';

    if (!coinId) return WD.toast('Coin search se select karo', 'error');
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

  // ── tc003: DeFi & Staking Income Tab ──────────────────────────────────
  async function _loadDefi() {
    const content = document.getElementById('cryptoContent');
    content.innerHTML = '<div class="wd-loader" style="margin:40px auto"></div>';
    try {
      const [listData, summaryData] = await Promise.all([
        API.get('crypto_defi_list'),
        API.get('crypto_defi_summary'),
      ]);
      const positions = listData.positions || [];
      const sum       = summaryData.summary || {};

      const typeColors = {
        STAKING:'#7c3aed', LIQUID_STAKING:'#6366f1', LP:'#2563eb',
        LENDING:'#0891b2', YIELD_FARMING:'#059669', VAULT:'#d97706', OTHER:'#6b7280'
      };

      const posRows = positions.map(p => {
        const gain = p.unrealised_pnl || 0;
        const gainCls = gain >= 0 ? 'var(--green,#16a34a)' : 'var(--red,#dc2626)';
        const typeColor = typeColors[p.position_type] || '#6b7280';
        const statusBg = p.status === 'ACTIVE' ? '#dcfce7' : '#f3f4f6';
        const statusTxt = p.status === 'ACTIVE' ? '#15803d' : '#6b7280';
        return `<tr>
          <td>
            <div style="font-weight:700">${p.protocol}</div>
            <div style="font-size:11px;color:var(--text-muted)">${p.chain}</div>
          </td>
          <td>
            <span style="background:${typeColor}20;color:${typeColor};font-size:11px;font-weight:700;
                         padding:2px 7px;border-radius:99px">${p.position_type_label}</span>
          </td>
          <td style="font-weight:700">${p.coin_symbol}${p.pair_symbol ? ' / ' + p.pair_symbol : ''}</td>
          <td>${fmtInr(p.principal_inr)}</td>
          <td style="font-weight:600">${fmtInr(p.current_value_inr)}</td>
          <td style="color:${gainCls};font-weight:600">${(gain>=0?'+':'')}${fmtInr(gain)}</td>
          <td>${fmtInr(p.rewards_value_inr)}</td>
          <td style="font-size:12px">${p.apy_pct ? p.apy_pct + '%' : '—'}</td>
          <td style="font-size:12px;color:var(--text-muted)">${p.days_active}d</td>
          <td>
            <span style="background:${statusBg};color:${statusTxt};font-size:10px;font-weight:700;
                         padding:2px 7px;border-radius:99px">${p.status}</span>
          </td>
          <td>
            <button class="btn-icon-sm" onclick="CRYPTO._defiEditModal(${p.id})" title="Edit">✏</button>
            ${p.status === 'ACTIVE'
              ? `<button class="btn-icon-sm" onclick="CRYPTO._defiCloseModal(${p.id},'${p.protocol} ${p.coin_symbol}')" title="Close Position" style="color:var(--amber,#d97706)">✓</button>`
              : ''}
            <button class="btn-icon-sm btn-danger-sm" onclick="CRYPTO._defiDelete(${p.id},'${p.protocol}')" title="Delete">✕</button>
          </td>
        </tr>`;
      }).join('');

      content.innerHTML = `
<!-- Summary cards -->
<div class="stats-grid" style="margin-bottom:18px">
  <div class="stat-card"><div class="stat-label">Total Value Locked</div>
    <div class="stat-value">${fmtInr(sum.total_tvl)}</div></div>
  <div class="stat-card"><div class="stat-label">Total Rewards Earned</div>
    <div class="stat-value" style="color:var(--green)">${fmtInr(sum.total_rewards_earned)}</div></div>
  <div class="stat-card"><div class="stat-label">Unrealised P&L</div>
    <div class="stat-value" style="color:${(sum.unrealised_pnl||0)>=0?'var(--green)':'var(--red)'}">${fmtInr(sum.unrealised_pnl)}</div></div>
  <div class="stat-card"><div class="stat-label">Active Positions</div>
    <div class="stat-value">${sum.active_positions}</div>
    <div class="stat-sub">Avg APY: ${sum.avg_apy || 0}%</div></div>
</div>

<!-- Action bar -->
<div style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
  <button class="btn btn-primary btn-sm" onclick="CRYPTO._defiAddModal()">+ Add DeFi Position</button>
  <button class="btn btn-ghost btn-sm" onclick="CRYPTO._loadDefi()">↻ Refresh</button>
</div>

${!positions.length
  ? '<div class="wd-empty" style="padding:48px 20px;text-align:center"><div style="font-size:40px;margin-bottom:10px">🌐</div><p>Koi DeFi position nahi. Protocol aur chain enter karke track karo.</p></div>'
  : `<div style="overflow-x:auto">
<table class="wd-table" style="width:100%;min-width:860px">
  <thead><tr>
    <th>Protocol</th><th>Type</th><th>Token(s)</th>
    <th>Invested</th><th>Current Value</th><th>P&L</th>
    <th>Rewards</th><th>APY</th><th>Age</th><th>Status</th><th></th>
  </tr></thead>
  <tbody>${posRows}</tbody>
</table></div>`}

<!-- Add / Edit DeFi Modal -->
<div id="defiModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
  <div style="background:var(--card-bg);border-radius:16px;padding:28px;width:100%;max-width:540px;max-height:90vh;overflow-y:auto;position:relative;box-shadow:0 20px 60px rgba(0,0,0,.3)">
    <button onclick="CRYPTO._defiCloseModalEl()" style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted)">✕</button>
    <h3 id="defiModalTitle" style="margin:0 0 20px;font-size:16px;font-weight:700">Add DeFi Position</h3>
    <div id="defiModalBody"></div>
  </div>
</div>`;

    } catch (e) {
      content.innerHTML = `<div class="wd-empty">⚠️ ${e.message}</div>`;
    }
  }

  function _defiAddModal(editData = null) {
    const body = document.getElementById('defiModalBody');
    const title = document.getElementById('defiModalTitle');
    if (!body) return;
    if (title) title.textContent = editData ? 'Edit DeFi Position' : 'Add DeFi Position';

    const protocols = ['Aave','Uniswap','Compound','Curve','SushiSwap','PancakeSwap',
                       'Yearn','Balancer','Lido','Rocket Pool','Convex','GMX','dYdX','WazirX','Other'];
    const chains    = ['Ethereum','Binance Smart Chain','Polygon','Solana','Avalanche','Arbitrum','Optimism','Base','Other'];
    const types     = [{v:'STAKING',l:'Staking'},{v:'LIQUID_STAKING',l:'Liquid Staking'},
                       {v:'LP',l:'Liquidity Pool'},{v:'LENDING',l:'Lending'},
                       {v:'YIELD_FARMING',l:'Yield Farming'},{v:'VAULT',l:'Vault / Auto-compound'},{v:'OTHER',l:'Other'}];

    const d = editData || {};
    const idField = editData ? `<input type="hidden" id="defiId" value="${editData.id}">` : '';
    const actionVal = editData ? 'crypto_defi_edit' : 'crypto_defi_add';

    body.innerHTML = `
${idField}
<div style="display:grid;gap:12px">
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
    <div>
      <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Protocol *</label>
      <input id="dfProtocol" list="dfProtoList" value="${d.protocol||''}" placeholder="Aave, Uniswap…"
             style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
      <datalist id="dfProtoList">${protocols.map(p=>`<option value="${p}">`).join('')}</datalist>
    </div>
    <div>
      <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Chain *</label>
      <select id="dfChain" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;background:var(--card-bg);color:var(--text)">
        ${chains.map(c=>`<option value="${c}" ${(d.chain||'Ethereum')===c?'selected':''}>${c}</option>`).join('')}
      </select>
    </div>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
    <div>
      <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Position Type *</label>
      <select id="dfType" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;background:var(--card-bg);color:var(--text)">
        ${types.map(t=>`<option value="${t.v}" ${(d.position_type||'STAKING')===t.v?'selected':''}>${t.l}</option>`).join('')}
      </select>
    </div>
    <div>
      <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Token Symbol *</label>
      <input id="dfCoinSym" value="${d.coin_symbol||''}" placeholder="ETH, USDC…"
             style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
    </div>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
    <div>
      <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Token Name</label>
      <input id="dfCoinName" value="${d.coin_name||''}" placeholder="Ethereum"
             style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
    </div>
    <div>
      <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Pair (LP only)</label>
      <input id="dfPair" value="${d.pair_symbol||''}" placeholder="ETH/USDC"
             style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
    </div>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px">
    <div>
      <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Principal (₹) *</label>
      <input id="dfPrincipal" type="number" step="any" value="${d.principal_inr||''}" placeholder="50000"
             style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
    </div>
    <div>
      <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Current Value (₹)</label>
      <input id="dfCurVal" type="number" step="any" value="${d.current_value_inr||''}" placeholder="Same as principal"
             style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
    </div>
    <div>
      <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">APY %</label>
      <input id="dfApy" type="number" step="any" value="${d.apy_pct||''}" placeholder="12.5"
             style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
    </div>
  </div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
    <div>
      <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Entry Date *</label>
      <input id="dfEntry" type="date" value="${d.entry_date||new Date().toISOString().slice(0,10)}"
             style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
    </div>
    <div>
      <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Rewards Earned (₹)</label>
      <input id="dfRewardsVal" type="number" step="any" value="${d.rewards_value_inr||''}" placeholder="0"
             style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
    </div>
  </div>
  <div>
    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Wallet Address</label>
    <input id="dfWallet" value="${d.wallet_address||''}" placeholder="0x… or wallet tag"
           style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
  </div>
  <div>
    <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Notes</label>
    <input id="dfNotes" value="${d.notes||''}" placeholder="Optional"
           style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
  </div>
  <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:4px">
    <button class="btn btn-secondary" onclick="CRYPTO._defiCloseModalEl()">Cancel</button>
    <button class="btn btn-primary" onclick="CRYPTO._defiSubmit('${actionVal}')">
      ${editData ? 'Update' : 'Add Position'}
    </button>
  </div>
</div>`;

    const m = document.getElementById('defiModal');
    if (m) m.style.display = 'flex';
  }

  function _defiCloseModalEl() {
    const m = document.getElementById('defiModal');
    if (m) m.style.display = 'none';
  }

  async function _defiSubmit(actionType) {
    const isEdit = actionType === 'crypto_defi_edit';
    const sym    = (document.getElementById('dfCoinSym')?.value || '').trim().toUpperCase();
    if (!document.getElementById('dfProtocol')?.value) return WD.toast('Protocol required', 'error');
    if (!sym) return WD.toast('Token symbol required', 'error');
    const principal = parseFloat(document.getElementById('dfPrincipal')?.value || 0);
    if (!isEdit && principal <= 0) return WD.toast('Principal amount required', 'error');

    const payload = {
      action:             actionType,
      protocol:           document.getElementById('dfProtocol')?.value  || '',
      chain:              document.getElementById('dfChain')?.value      || 'Ethereum',
      position_type:      document.getElementById('dfType')?.value       || 'STAKING',
      coin_symbol:        sym,
      coin_name:          document.getElementById('dfCoinName')?.value   || sym,
      pair_symbol:        document.getElementById('dfPair')?.value       || '',
      principal_inr:      principal,
      current_value_inr:  parseFloat(document.getElementById('dfCurVal')?.value    || principal) || principal,
      apy_pct:            parseFloat(document.getElementById('dfApy')?.value       || 0),
      entry_date:         document.getElementById('dfEntry')?.value      || '',
      rewards_value_inr:  parseFloat(document.getElementById('dfRewardsVal')?.value|| 0),
      wallet_address:     document.getElementById('dfWallet')?.value     || '',
      notes:              document.getElementById('dfNotes')?.value      || '',
    };

    if (isEdit) {
      payload.id = document.getElementById('defiId')?.value || '';
    }

    try {
      const r = await API.post(payload);
      if (!r.success) throw new Error(r.message);
      WD.toast(r.message || (isEdit ? 'Updated!' : 'Position added!'), 'success');
      _defiCloseModalEl();
      _loadDefi();
    } catch (e) { WD.toast(e.message, 'error'); }
  }

  async function _defiEditModal(id) {
    try {
      const data = await API.get('crypto_defi_list');
      const pos  = (data.positions || []).find(p => p.id === id);
      if (pos) _defiAddModal(pos);
    } catch (e) { WD.toast(e.message, 'error'); }
  }

  function _defiCloseModal(id, label) {
    const exitVal = prompt(`"${label}" position close karo.\nExit value (₹) enter karo:`, '');
    if (exitVal === null) return;
    const val = parseFloat(exitVal) || 0;
    API.post({ action: 'crypto_defi_close', id, exit_value_inr: val })
      .then(r => {
        if (!r.success) throw new Error(r.message);
        WD.toast(r.message, 'success');
        _loadDefi();
      }).catch(e => WD.toast(e.message, 'error'));
  }

  async function _defiDelete(id, proto) {
    if (!confirm(`"${proto}" position delete karo?`)) return;
    try {
      const r = await API.post({ action: 'crypto_defi_delete', id });
      if (!r.success) throw new Error(r.message);
      WD.toast('Deleted', 'success');
      _loadDefi();
    } catch (e) { WD.toast(e.message, 'error'); }
  }

  // ── tc004: Portfolio Rebalancing Tab ───────────────────────────────────
  async function _loadRebalance() {
    const content = document.getElementById('cryptoContent');
    content.innerHTML = '<div class="wd-loader" style="margin:40px auto"></div>';
    try {
      const data = await API.get('crypto_rebalance_targets');
      const targets = data.targets || [];
      const totalTarget = data.total_target_pct || 0;
      const totalCurrent = data.total_current || 0;
      const remaining = Math.max(0, 100 - totalTarget).toFixed(2);

      const rowsHtml = targets.map(t => {
        const driftAbs  = Math.abs(t.drift_pct);
        const driftColor = driftAbs < 2 ? '#22c55e' : driftAbs < 5 ? '#f59e0b' : '#ef4444';
        const driftLabel = driftAbs < 2 ? '✓' : (t.drift_pct > 0 ? '▲ OW' : '▼ UW');
        return `<tr>
          <td><strong>${t.coin_symbol}</strong> <span style="font-size:11px;color:var(--text-muted)">${t.coin_name}</span></td>
          <td>
            <input type="number" step="0.01" min="0" max="100" value="${t.target_pct}"
                   onchange="CRYPTO._rbSetTarget('${t.coin_id}','${t.coin_symbol}','${t.coin_name}',this.value)"
                   style="width:70px;padding:4px 6px;border:1.5px solid var(--border);border-radius:6px;text-align:right;font-weight:700">%
          </td>
          <td>${t.actual_pct}%
            <div style="width:80px;height:5px;background:var(--border);border-radius:99px;margin-top:3px;overflow:hidden">
              <div style="width:${Math.min(t.actual_pct,100)}%;height:100%;background:var(--accent,#6366f1);border-radius:99px"></div>
            </div>
          </td>
          <td style="font-weight:700;color:${driftColor}">${t.drift_pct > 0 ? '+' : ''}${t.drift_pct}% <small>${driftLabel}</small></td>
          <td>${fmtInr(t.current_value)}</td>
          <td>
            <button class="btn-icon-sm btn-danger-sm" onclick="CRYPTO._rbDeleteTarget('${t.coin_id}','${t.coin_symbol}')" title="Remove">✕</button>
          </td>
        </tr>`;
      }).join('');

      const isValid = Math.abs(totalTarget - 100) < 0.1 || totalTarget === 0;

      content.innerHTML = `
<div style="max-width:900px">
  <!-- Header + Add target -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px">
    <div>
      <h3 style="margin:0;font-size:15px;font-weight:700">⚖️ Portfolio Rebalancing</h3>
      <p style="margin:4px 0 0;font-size:12px;color:var(--text-muted)">Target allocation set karo — phir AI suggest karega kya buy/sell karna hai</p>
    </div>
    <button class="btn btn-primary btn-sm" onclick="CRYPTO._rbAddTargetModal()">+ Add Target</button>
  </div>

  <!-- Allocation status -->
  <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:16px;flex-wrap:wrap">
    <div style="flex:1">
      <div style="font-size:11px;color:var(--text-muted);font-weight:600;margin-bottom:4px">ALLOCATED</div>
      <div style="font-size:22px;font-weight:800;color:${isValid?'var(--green,#16a34a)':'var(--amber,#d97706)'}">${totalTarget.toFixed(2)}%</div>
    </div>
    <div style="flex:1">
      <div style="font-size:11px;color:var(--text-muted);font-weight:600;margin-bottom:4px">REMAINING</div>
      <div style="font-size:22px;font-weight:800">${remaining}%</div>
    </div>
    <div style="flex:1">
      <div style="font-size:11px;color:var(--text-muted);font-weight:600;margin-bottom:4px">PORTFOLIO</div>
      <div style="font-size:22px;font-weight:800">${fmtInr(totalCurrent)}</div>
    </div>
    <div style="flex:2">
      ${isValid && totalTarget > 0
        ? `<button class="btn btn-primary" onclick="CRYPTO._rbSuggest()"
              style="background:#7c3aed;border-color:#7c3aed;width:100%;padding:10px 0;font-weight:700">
              ✨ Get Rebalancing Suggestions
           </button>`
        : `<div style="font-size:12px;color:var(--text-muted);background:var(--bg-secondary);border-radius:8px;padding:10px">
              ${totalTarget === 0 ? '👆 Pehle targets set karo' : `⚠️ Total ${totalTarget}% hai — exactly 100% banana zaroori hai`}
           </div>`}
    </div>
  </div>

  <!-- Targets table -->
  ${!targets.length
    ? `<div class="wd-empty" style="padding:40px;text-align:center">
        <div style="font-size:40px;margin-bottom:10px">🎯</div>
        <p>Koi target set nahi. "Add Target" se shuru karo.<br>
        <small style="color:var(--text-muted)">Example: BTC 40%, ETH 30%, SOL 20%, BNB 10%</small></p>
       </div>`
    : `<div style="overflow-x:auto">
<table class="wd-table" style="width:100%;min-width:580px">
  <thead><tr><th>Coin</th><th>Target %</th><th>Actual %</th><th>Drift</th><th>Current Value</th><th></th></tr></thead>
  <tbody>${rowsHtml}</tbody>
</table></div>`}

  <!-- Suggestions area -->
  <div id="rbSuggestArea" style="margin-top:20px"></div>

  <!-- Add Target Modal -->
  <div id="rbAddModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center">
    <div style="background:var(--card-bg);border-radius:16px;padding:28px;width:100%;max-width:420px;position:relative;box-shadow:0 20px 60px rgba(0,0,0,.3)">
      <button onclick="document.getElementById('rbAddModal').style.display='none'"
              style="position:absolute;top:16px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:var(--text-muted)">✕</button>
      <h3 style="margin:0 0 18px;font-size:16px;font-weight:700">Add Rebalance Target</h3>
      <div style="display:grid;gap:12px">
        <div>
          <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Coin ID (CoinGecko) *</label>
          <input id="rbCoinId" placeholder="bitcoin" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div>
            <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Symbol *</label>
            <input id="rbSym" placeholder="BTC" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
          </div>
          <div>
            <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Name</label>
            <input id="rbName" placeholder="Bitcoin" style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
          </div>
        </div>
        <div>
          <label style="font-size:11px;font-weight:600;display:block;margin-bottom:4px">Target % *</label>
          <input id="rbPct" type="number" step="0.01" min="0.01" max="100" placeholder="40.00"
                 style="width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px">
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px">Remaining: ${remaining}%</div>
        </div>
        <div style="display:flex;gap:8px;justify-content:flex-end">
          <button class="btn btn-secondary" onclick="document.getElementById('rbAddModal').style.display='none'">Cancel</button>
          <button class="btn btn-primary" onclick="CRYPTO._rbSaveTarget()">Save Target</button>
        </div>
      </div>
    </div>
  </div>
</div>`;

    } catch (e) {
      content.innerHTML = `<div class="wd-empty">⚠️ ${e.message}</div>`;
    }
  }

  function _rbAddTargetModal() {
    const m = document.getElementById('rbAddModal');
    if (m) m.style.display = 'flex';
  }

  async function _rbSaveTarget() {
    const coinId = (document.getElementById('rbCoinId')?.value || '').trim();
    const sym    = (document.getElementById('rbSym')?.value    || '').trim().toUpperCase();
    const name   = (document.getElementById('rbName')?.value   || sym).trim();
    const pct    = parseFloat(document.getElementById('rbPct')?.value || 0);

    if (!coinId) return WD.toast('CoinGecko ID required', 'error');
    if (!sym)    return WD.toast('Symbol required', 'error');
    if (pct <= 0 || pct > 100) return WD.toast('Target % must be 0.01–100', 'error');

    try {
      const r = await API.post({ action: 'crypto_rebalance_set', coin_id: coinId, coin_symbol: sym, coin_name: name, target_pct: pct });
      if (!r.success) throw new Error(r.message);
      WD.toast(r.message, 'success');
      const m = document.getElementById('rbAddModal');
      if (m) m.style.display = 'none';
      _loadRebalance();
    } catch (e) { WD.toast(e.message, 'error'); }
  }

  async function _rbSetTarget(coinId, sym, name, pct) {
    try {
      const r = await API.post({ action: 'crypto_rebalance_set', coin_id: coinId, coin_symbol: sym, coin_name: name, target_pct: parseFloat(pct) });
      if (!r.success) throw new Error(r.message);
      WD.toast(`${sym}: ${pct}% set`, 'success');
    } catch (e) { WD.toast(e.message, 'error'); }
  }

  async function _rbDeleteTarget(coinId, sym) {
    if (!confirm(`${sym} target remove karo?`)) return;
    try {
      const r = await API.post({ action: 'crypto_rebalance_delete', coin_id: coinId });
      if (!r.success) throw new Error(r.message);
      WD.toast('Target removed', 'success');
      _loadRebalance();
    } catch (e) { WD.toast(e.message, 'error'); }
  }

  async function _rbSuggest() {
    const area = document.getElementById('rbSuggestArea');
    if (!area) return;
    area.innerHTML = '<div class="wd-loader" style="margin:20px auto"></div>';
    try {
      const data = await API.get('crypto_rebalance_suggest');
      const sugg = data.suggestions || [];
      const sum  = data.summary || {};
      const notes= data.notes   || {};

      const actionIcon = { BUY: '🟢', SELL: '🔴', HOLD: '⚪' };
      const actionColor = { BUY: '#16a34a', SELL: '#dc2626', HOLD: '#6b7280' };

      const rows = sugg.map(s => {
        const icon  = actionIcon[s.action]  || '⚪';
        const color = actionColor[s.action] || '#6b7280';
        return `<tr>
          <td><strong>${s.coin_symbol}</strong></td>
          <td style="font-weight:700;color:${color}">${icon} ${s.action}</td>
          <td>${s.actual_pct}%</td>
          <td>${s.target_pct}%</td>
          <td style="font-weight:700;color:${s.diff_inr>=0?'var(--green)':'var(--red)'}">
            ${s.diff_inr >= 0 ? '+' : ''}${fmtInr(s.diff_inr)}
          </td>
          <td style="font-family:monospace;font-size:12px">
            ${s.action !== 'HOLD' && s.qty_to_trade > 0
              ? (s.action === 'BUY' ? 'Buy ' : 'Sell ') + s.qty_to_trade.toFixed(6) + ' ' + s.coin_symbol
              : '—'}
          </td>
          <td style="font-size:12px;color:var(--text-muted)">${fmtInr(s.price_inr)}</td>
        </tr>`;
      }).join('');

      area.innerHTML = `
<div style="background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border:1px solid #7dd3fc;border-radius:12px;padding:18px;margin-bottom:16px">
  <h4 style="margin:0 0 12px;font-size:14px;font-weight:700;color:#075985">⚖️ Rebalancing Plan</h4>
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:12px">
    <div><div style="font-size:10px;font-weight:700;color:#0369a1;text-transform:uppercase">Buy Total</div>
         <div style="font-size:17px;font-weight:800;color:#16a34a">${fmtInr(sum.total_buy_value)}</div></div>
    <div><div style="font-size:10px;font-weight:700;color:#0369a1;text-transform:uppercase">Sell Total</div>
         <div style="font-size:17px;font-weight:800;color:#dc2626">${fmtInr(sum.total_sell_value)}</div></div>
    <div><div style="font-size:10px;font-weight:700;color:#0369a1;text-transform:uppercase">Net Cash Needed</div>
         <div style="font-size:17px;font-weight:800;color:${sum.net_cash_needed>0?'#dc2626':'#16a34a'}">${sum.net_cash_needed>0?'+':''}${fmtInr(sum.net_cash_needed)}</div></div>
  </div>
  ${sum.is_balanced ? '<div style="font-size:12px;color:#15803d;background:#dcfce7;border-radius:6px;padding:6px 10px">✅ Portfolio already balanced (drift < 2%)</div>' : ''}
</div>

<div style="overflow-x:auto">
<table class="wd-table" style="min-width:640px">
  <thead><tr><th>Coin</th><th>Action</th><th>Actual %</th><th>Target %</th><th>Diff (₹)</th><th>Trade Qty</th><th>Price</th></tr></thead>
  <tbody>${rows}</tbody>
</table></div>

<div style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:10px 14px;margin-top:12px;font-size:11px;color:#78350f">
  ⚠️ <strong>Tax Warning:</strong> ${notes.tax_warning}<br>
  💡 <strong>Tip:</strong> ${notes.recommendation}
</div>`;

    } catch (e) {
      area.innerHTML = `<div class="wd-empty">⚠️ ${e.message}</div>`;
    }
  }

  // ── Public API ─────────────────────────────────────────────────────────
  return {
    init, switchTab, refreshPrices, deleteHolding,
    openAddModal, closeModal, renderHoldings,
    _onCoinSelect, _submitAdd, _updateAmountPreview,
    // t40: CoinGecko live search
    _cgSearch, _cgSelectCoin, _cgClearCoin, _cgFetchPrice,
    // tc001: SSE controls
    connectLive:    _sseConnect,
    disconnectLive: _sseDisconnect,
    // t317: Import
    _importPreviewLoad, _importConfirm, _importLogLoad,
    // tc003: DeFi & Staking
    _loadDefi, _defiAddModal, _defiCloseModalEl, _defiSubmit,
    _defiEditModal, _defiCloseModal, _defiDelete,
    // tc004: Rebalancing
    _loadRebalance, _rbAddTargetModal, _rbSaveTarget,
    _rbSetTarget, _rbDeleteTarget, _rbSuggest,
    // tc005: Exchange Sync
    _openAddKeyModal, _closeAddKeyModal, _submitAddKey,
    _deleteKey, _runSync, _syncLogLoad,
  };
})();

document.addEventListener('DOMContentLoaded', () => CRYPTO.init());
