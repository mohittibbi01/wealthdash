<?php
/**
 * WealthDash — Stocks & ETF Holdings Page
 * Phase 4 — Complete: Holdings + Transactions + Live Price + Corporate Actions
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Stocks & ETF';
$activePage  = 'stocks';

$db = DB::conn();

$summaryStmt = $db->prepare("
    SELECT COUNT(DISTINCT h.stock_id) AS stock_count,
           SUM(h.total_invested)      AS total_invested,
           SUM(h.current_value)       AS current_value,
           SUM(h.gain_loss)           AS gain_loss,
           /* t36: Weighted CAGR — weight each holding by invested amount */
           SUM(
             CASE
               WHEN h.first_purchase_date IS NOT NULL
                    AND h.avg_buy_price > 0
                    AND sm.latest_price > 0
                    AND DATEDIFF(CURDATE(), h.first_purchase_date) > 30
               THEN h.total_invested *
                    (POW(sm.latest_price / NULLIF(h.avg_buy_price, 0),
                         365.0 / GREATEST(DATEDIFF(CURDATE(), h.first_purchase_date), 1)) - 1) * 100
               ELSE 0
             END
           ) / NULLIF(SUM(
             CASE
               WHEN h.first_purchase_date IS NOT NULL
                    AND h.avg_buy_price > 0
                    AND sm.latest_price > 0
                    AND DATEDIFF(CURDATE(), h.first_purchase_date) > 30
               THEN h.total_invested
               ELSE 0
             END
           ), 0) AS portfolio_cagr
    FROM stock_holdings h
    JOIN portfolios p ON p.id = h.portfolio_id
    JOIN stock_master sm ON sm.id = h.stock_id
    WHERE p.user_id = ? AND h.quantity > 0
");
$summaryStmt->execute([$currentUser['id']]);
$summary        = $summaryStmt->fetch();
$totalInvested  = (float)($summary['total_invested'] ?? 0);
$currentValue   = (float)($summary['current_value'] ?? 0);
$gainLoss       = (float)($summary['gain_loss'] ?? 0);
$gainPct        = $totalInvested > 0 ? round(($gainLoss / $totalInvested) * 100, 2) : 0;
$stockCount     = (int)($summary['stock_count'] ?? 0);
// t36: Weighted portfolio CAGR
$portfolioCagr  = $summary['portfolio_cagr'] !== null ? round((float)$summary['portfolio_cagr'], 2) : null;

$pStmt = $db->prepare("SELECT id, name, color FROM portfolios WHERE user_id=? ORDER BY name ASC");
$pStmt->execute([$currentUser['id']]);
$portfolios = $pStmt->fetchAll();

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Stocks &amp; ETF</h1>
    <p class="page-subtitle">Equity holdings — delivery only, NSE/BSE</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-ghost" id="btnRefreshPrices">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.12"/></svg>
      Refresh Prices
    </button>
    <button class="btn btn-primary" id="btnAddStock">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Transaction
    </button>
  </div>
</div>

<!-- t36: Summary Cards — Stocks / ETF with gain% and CAGR -->
<div class="stats-grid" style="margin-bottom:24px">
  <div class="stat-card">
    <div class="stat-label">Stocks / ETFs</div>
    <div class="stat-value"><?= $stockCount ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Total Invested</div>
    <div class="stat-value"><?= inr($totalInvested) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Current Value</div>
    <div class="stat-value"><?= inr($currentValue) ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Gain / Loss</div>
    <div class="stat-value <?= $gainLoss >= 0 ? 'text-success' : 'text-danger' ?>">
      <?= inr($gainLoss) ?>
      <span class="stat-sub"><?= ($gainLoss >= 0 ? '+' : '') . $gainPct ?>%</span>
    </div>
  </div>
  <div class="stat-card" title="Weighted-average annualised return across all holdings (CAGR)">
    <div class="stat-label">Portfolio CAGR <span style="font-size:10px;color:var(--text-muted)">(wt. avg)</span></div>
    <div class="stat-value <?= $portfolioCagr === null ? '' : ($portfolioCagr >= 0 ? 'text-success' : 'text-danger') ?>">
      <?php if ($portfolioCagr === null): ?>
        <span style="color:var(--text-muted);font-size:16px">—</span>
      <?php else: ?>
        <?= ($portfolioCagr >= 0 ? '+' : '') . $portfolioCagr ?>%
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Holdings -->
<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div class="view-toggle">
      <button class="view-btn active" data-gain="">All</button>
      <button class="view-btn" data-gain="LTCG">LTCG</button>
      <button class="view-btn" data-gain="STCG">STCG</button>
    </div>
    <div style="display:flex;gap:8px">
      <input type="text" class="form-input" id="searchStock" placeholder="Search symbol..." style="width:180px">
      <select class="form-select" id="filterSector" style="width:140px" onchange="STOCKS.loadHoldings()">
        <option value="">All Sectors</option>
      </select>
      <select class="form-select" id="filterPortfolio" style="width:160px">
        <option value="">All Portfolios</option>
        <?php foreach ($portfolios as $p): ?>
        <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table stocks-table">
      <thead>
        <tr>
          <th>Symbol / Company</th>
          <th>Sector</th>
          <th class="text-right">Qty</th>
          <th class="text-right">Avg Buy (₹)</th>
          <th class="text-right">CMP (₹)</th>
          <th class="text-right">Invested</th>
          <th class="text-right">Current Value</th>
          <th class="text-right" style="cursor:pointer;user-select:none" onclick="STOCKS.sortBy('gain')">Gain / Loss <span id="sortGainArr">↕</span></th>
          <th class="text-right" style="cursor:pointer;user-select:none" onclick="STOCKS.sortBy('cagr')">CAGR <span id="sortCagrArr">↕</span></th>
          <th>Gain Type</th>
          <th class="text-center">Actions</th>
        </tr>
      </thead>
      <tbody id="stocksBody">
        <tr><td colspan="11" class="text-center" style="padding:40px;color:var(--text-muted)"><span class="spinner"></span> Loading...</td></tr>
      </tbody>
    </table>
  </div>
  <div id="stocksPagWrap" style="padding:0 16px;"></div>
</div>

<!-- t145: Stock Picker Reality Check -->
<div class="card" style="margin-top:20px;margin-bottom:20px;">
  <div class="card-header">
    <h3 class="card-title">🎯 Stock Picker Reality Check</h3>
    <span style="font-size:11px;color:var(--text-muted);">Your returns vs Nifty 50 (same money, same time)</span>
  </div>
  <div class="card-body" style="padding:16px;">
    <div id="stockPickerCheck">
      <div style="text-align:center;color:var(--text-muted);padding:20px;font-size:13px;">
        <span class="spinner"></span> Loading analysis...
      </div>
    </div>
  </div>
</div>


<!-- t285/t433: 52-Week High/Low Tracker + Fundamentals (t281, t431) -->
<div class="card" style="margin-top:20px;margin-bottom:20px;" id="card52Week">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
    <div>
      <h3 class="card-title">📊 52-Week High / Low &amp; Fundamentals</h3>
      <span style="font-size:11px;color:var(--text-muted);">P/E · P/B · Market Cap · Entry signals</span>
    </div>
    <button class="btn btn-ghost btn-sm" id="btnRefreshFundamentals" onclick="STOCKS.refreshFundamentals()">
      🔄 Refresh Data
    </button>
  </div>
  <div class="table-responsive">
    <table class="table" style="font-size:12px;">
      <thead>
        <tr>
          <th>Symbol</th>
          <th class="text-right">CMP (₹)</th>
          <th class="text-right">52W High</th>
          <th class="text-right">52W Low</th>
          <th style="min-width:120px">52W Position</th>
          <th class="text-right">P/E</th>
          <th class="text-right">P/B</th>
          <th class="text-right">Mkt Cap</th>
          <th class="text-right">Div Yield</th>
          <th>Signal</th>
        </tr>
      </thead>
      <tbody id="fundamentalsBody">
        <tr><td colspan="10" class="text-center" style="padding:30px;color:var(--text-muted);">
          Click "Refresh Data" to load fundamentals &amp; 52-week data
        </td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- t216: Stocks Sector-wise Analytics -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px;margin-bottom:20px;">
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">🏭 Sector Allocation</h3>
      <span style="font-size:11px;color:var(--text-muted);">By current value</span>
    </div>
    <div class="card-body" style="padding:16px;">
      <div id="stockSectorWrap">
        <div style="text-align:center;color:var(--text-muted);padding:20px;font-size:13px;">Loading sector data…</div>
      </div>
    </div>
  </div>

  <!-- t217: Stocks Dividend Tracker -->
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">💸 Dividend Tracker</h3>
      <span style="font-size:11px;color:var(--text-muted);" id="stockDivFyLabel"></span>
    </div>
    <div class="card-body" style="padding:16px;">
      <div id="stockDivSummary" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px;"></div>
      <div style="overflow-x:auto;max-height:220px;overflow-y:auto;">
        <table class="table" id="stockDivTable" style="font-size:12px;">
          <thead>
            <tr>
              <th>Stock</th>
              <th class="text-center">Date</th>
              <th class="text-right">Amount</th>
              <th class="text-center">FY</th>
            </tr>
          </thead>
          <tbody id="stockDivBody">
            <tr><td colspan="4" class="text-center" style="padding:24px;color:var(--text-muted);">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     t344 — NSE/BSE Live Price Ticker
     Auto-refreshing live price strip for all holdings + manual refresh
     ═══════════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-top:24px;" id="cardLivePriceTicker">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <div style="display:flex;align-items:center;gap:10px;">
      <h3 class="card-title" style="margin:0;">📡 Live Prices — NSE/BSE — t344</h3>
      <span id="t344LiveDot" style="width:8px;height:8px;border-radius:50%;background:#16a34a;display:inline-block;animation:t344pulse 1.5s infinite;"></span>
      <span id="t344Status" style="font-size:11px;color:var(--text-muted);">Loading...</span>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
      <span style="font-size:11px;color:var(--text-muted);">Auto-refresh:</span>
      <select id="t344Interval" style="padding:4px 8px;border-radius:6px;border:1px solid var(--border);background:var(--bg-secondary);color:var(--text);font-size:11px;" onchange="t344SetInterval()">
        <option value="0">Off</option>
        <option value="30">30s</option>
        <option value="60" selected>1m</option>
        <option value="300">5m</option>
      </select>
      <button onclick="t344Refresh(true)" class="btn btn-ghost btn-sm" id="t344RefreshBtn">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.12"/></svg>
        Refresh
      </button>
    </div>
  </div>
  <div class="card-body" style="padding:12px 16px;">
    <!-- Scrolling ticker strip -->
    <div style="overflow:hidden;border-radius:8px;background:var(--bg-secondary);padding:0;margin-bottom:12px;">
      <div id="t344Ticker" style="display:flex;gap:0;overflow-x:auto;scrollbar-width:none;padding:10px 12px;gap:4px;flex-wrap:nowrap;">
        <div style="color:var(--text-muted);font-size:12px;padding:6px 10px;">Loading ticker...</div>
      </div>
    </div>
    <!-- Price grid table -->
    <div style="overflow-x:auto;">
      <table style="width:100%;border-collapse:collapse;font-size:12px;" id="t344PriceTable">
        <thead>
          <tr style="border-bottom:2px solid var(--border);">
            <th style="padding:8px 10px;text-align:left;font-weight:700;color:var(--text-muted);white-space:nowrap;">Symbol</th>
            <th style="padding:8px 10px;text-align:left;font-weight:700;color:var(--text-muted);">Company</th>
            <th style="padding:8px 10px;text-align:right;font-weight:700;color:var(--text-muted);">LTP</th>
            <th style="padding:8px 10px;text-align:right;font-weight:700;color:var(--text-muted);">Chg</th>
            <th style="padding:8px 10px;text-align:right;font-weight:700;color:var(--text-muted);">Chg%</th>
            <th style="padding:8px 10px;text-align:right;font-weight:700;color:var(--text-muted);">Avg Buy</th>
            <th style="padding:8px 10px;text-align:right;font-weight:700;color:var(--text-muted);">P&L%</th>
            <th style="padding:8px 10px;text-align:center;font-weight:700;color:var(--text-muted);">Exch</th>
            <th style="padding:8px 10px;text-align:center;font-weight:700;color:var(--text-muted);">Alert</th>
          </tr>
        </thead>
        <tbody id="t344Body">
          <tr><td colspan="9" style="padding:24px;text-align:center;color:var(--text-muted);">
            <span class="spinner"></span> Fetching live prices...
          </td></tr>
        </tbody>
      </table>
    </div>
    <div style="font-size:10px;color:var(--text-muted);margin-top:8px;">
      📊 Prices via Yahoo Finance · NSE (.NS) / BSE (.BO) · Delayed 15–20 min during market hours
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     t345 — Stock Price Alerts
     Full CRUD: set target price alerts, browser notifications
     ═══════════════════════════════════════════════════════════════════ -->
<div class="card" style="margin-top:24px;" id="cardPriceAlerts">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <div>
      <h3 class="card-title" style="margin:0;">🔔 Price Alerts — t345</h3>
      <span style="font-size:11px;color:var(--text-muted);">Get notified when a stock hits your target price</span>
    </div>
    <button onclick="t345OpenModal()" class="btn btn-primary btn-sm">+ New Alert</button>
  </div>
  <div class="card-body" style="padding:16px;">
    <div id="t345AlertList">
      <div style="text-align:center;padding:24px;color:var(--text-muted);font-size:13px;">
        <span class="spinner"></span> Loading alerts...
      </div>
    </div>
  </div>
</div>

<style>
@keyframes t344pulse { 0%,100%{opacity:1} 50%{opacity:.3} }
</style>

<script>
// ═══════════════════════════════════════════════════════════════
// t344 — NSE/BSE Live Price Ticker
// ═══════════════════════════════════════════════════════════════
(function(){
  let _timer  = null;
  let _stocks = []; // { stock_id, symbol, exchange, company_name, avg_buy_price, current_price }

  function inr(n){ return '₹' + Number(n).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2}); }
  function pctColor(p){ return p>=0?'#16a34a':'#ef4444'; }
  function arrow(p){ return p>=0?'▲':'▼'; }

  /* Load holdings first to get the symbol list */
  async function loadHoldingSymbols(){
    try {
      const r = await fetch('<?= APP_URL ?>/api/?action=stocks_list&type=holdings');
      const d = await r.json();
      if(!d.success || !d.data?.length) return [];
      return d.data.map(h=>({
        stock_id:     h.stock_id || h.id,
        symbol:       h.symbol,
        exchange:     h.exchange || 'NSE',
        company_name: h.company_name,
        avg_buy_price:(float)(h.avg_buy_price) || 0,
        current_price:(float)(h.current_price) || 0,
      }));
    } catch(e){ return []; }
  }

  /* Fetch single price from our backend (which calls Yahoo Finance) */
  async function fetchPrice(stock){
    try {
      const fd = new FormData();
      fd.append('symbol',    stock.symbol);
      fd.append('exchange',  stock.exchange);
      fd.append('stock_id',  stock.stock_id);
      const r = await fetch('<?= APP_URL ?>/api/?action=stocks_price', { method:'POST', body:fd });
      const d = await r.json();
      if(d.success && d.data) return d.data;
    } catch(e){}
    return null;
  }

  /* Render ticker strip */
  function renderTicker(prices){
    const ticker = document.getElementById('t344Ticker');
    if(!ticker) return;
    if(!prices.length){ ticker.innerHTML='<div style="color:var(--text-muted);font-size:12px;padding:6px;">No holdings found.</div>'; return; }
    ticker.innerHTML = prices.map(p => {
      const chgPct = p.change_pct ?? 0;
      const chg    = p.change ?? 0;
      const color  = pctColor(chgPct);
      return `<div style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:8px;background:var(--bg-card);border:1px solid var(--border);white-space:nowrap;flex-shrink:0;">
        <span style="font-size:11px;font-weight:800;color:var(--text);">${p.symbol}</span>
        <span style="font-size:12px;font-weight:700;color:var(--text);">${inr(p.price)}</span>
        <span style="font-size:11px;font-weight:700;color:${color};">${arrow(chgPct)} ${Math.abs(chgPct).toFixed(2)}%</span>
      </div>`;
    }).join('');
  }

  /* Render full price table */
  function renderTable(stocks, priceMap){
    const tbody = document.getElementById('t344Body');
    if(!tbody) return;
    if(!stocks.length){ tbody.innerHTML='<tr><td colspan="9" style="padding:24px;text-align:center;color:var(--text-muted);">No holdings found. Add stocks to see live prices.</td></tr>'; return; }

    tbody.innerHTML = stocks.map(s => {
      const p   = priceMap[s.symbol];
      const ltp = p ? p.price : (s.current_price || 0);
      const chg = p ? (p.change ?? 0) : 0;
      const chgP= p ? (p.change_pct ?? 0) : 0;
      const ab  = s.avg_buy_price || 0;
      const plP = ab > 0 ? ((ltp - ab)/ab*100) : 0;
      const clr = pctColor(chgP);
      const plC = pctColor(plP);
      const fresh = p ? '' : 'opacity:.6';

      return `<tr style="border-bottom:1px solid var(--border);${fresh}" data-symbol="${s.symbol}" data-stock-id="${s.stock_id}" data-company="${s.company_name||''}" data-ltp="${ltp}" data-ab="${ab}" data-exchange="${s.exchange}">
        <td style="padding:8px 10px;font-weight:800;color:var(--text);">${s.symbol}
          <span style="font-size:9px;background:${s.exchange==='BSE'?'#f59e0b':'#3b82f6'};color:#fff;border-radius:3px;padding:1px 4px;margin-left:3px;">${s.exchange}</span>
        </td>
        <td style="padding:8px 10px;color:var(--text-secondary);max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${s.company_name||''}">${s.company_name||'—'}</td>
        <td style="padding:8px 10px;text-align:right;font-weight:700;color:var(--text);">${p?inr(ltp):'<span style="color:var(--text-muted)">—</span>'}</td>
        <td style="padding:8px 10px;text-align:right;font-weight:600;color:${clr};">${p?(arrow(chg)+' '+inr(Math.abs(chg))):'—'}</td>
        <td style="padding:8px 10px;text-align:right;font-weight:700;color:${clr};">${p?(arrow(chgP)+' '+Math.abs(chgP).toFixed(2)+'%'):'—'}</td>
        <td style="padding:8px 10px;text-align:right;color:var(--text-secondary);">${ab>0?inr(ab):'—'}</td>
        <td style="padding:8px 10px;text-align:right;font-weight:700;color:${plC};">${ab>0?(arrow(plP)+' '+Math.abs(plP).toFixed(2)+'%'):'—'}</td>
        <td style="padding:8px 10px;text-align:center;">
          <span style="font-size:9px;background:${s.exchange==='BSE'?'#fef3c7':'#eff6ff'};color:${s.exchange==='BSE'?'#92400e':'#1d4ed8'};border-radius:4px;padding:2px 6px;font-weight:700;">${s.exchange}</span>
        </td>
        <td style="padding:8px 10px;text-align:center;">
          <button onclick="t345OpenModal('${s.symbol}','${s.company_name||''}',${s.stock_id},${ltp})"
            style="border:none;background:none;cursor:pointer;color:#f59e0b;font-size:14px;" title="Set Price Alert">🔔</button>
        </td>
      </tr>`;
    }).join('');
  }

  window.t344Refresh = async function(showSpinner=false){
    const btn = document.getElementById('t344RefreshBtn');
    const st  = document.getElementById('t344Status');
    if(showSpinner && btn){ btn.disabled=true; btn.innerHTML='<span class="spinner" style="width:12px;height:12px;border-width:2px;"></span> Refreshing...'; }
    if(st) st.textContent = 'Fetching...';

    // Load holdings if needed
    if(!_stocks.length) _stocks = await loadHoldingSymbols();
    if(!_stocks.length){
      document.getElementById('t344Body').innerHTML='<tr><td colspan="9" style="padding:24px;text-align:center;color:var(--text-muted);">No stock holdings found. Add stocks first.</td></tr>';
      document.getElementById('t344Ticker').innerHTML='<div style="color:var(--text-muted);font-size:12px;padding:6px;">No holdings.</div>';
      if(st) st.textContent='No holdings.';
      if(btn){ btn.disabled=false; btn.innerHTML='<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.12"/></svg> Refresh'; }
      return;
    }

    // Fetch prices in parallel (max 5 at a time to avoid rate limits)
    const priceMap = {};
    const batchSize = 5;
    for(let i=0; i<_stocks.length; i+=batchSize){
      const batch = _stocks.slice(i, i+batchSize);
      const results = await Promise.allSettled(batch.map(s => fetchPrice(s)));
      results.forEach((res,idx) => {
        if(res.status==='fulfilled' && res.value){
          priceMap[batch[idx].symbol] = res.value;
        }
      });
      if(i+batchSize < _stocks.length) await new Promise(r=>setTimeout(r,300));
    }

    renderTicker(_stocks.map(s=>({...s, ...(priceMap[s.symbol]||{})})));
    renderTable(_stocks, priceMap);

    const now = new Date().toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
    if(st) st.textContent = `Updated ${now}`;
    if(btn){ btn.disabled=false; btn.innerHTML='<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.12"/></svg> Refresh'; }
  };

  window.t344SetInterval = function(){
    if(_timer) clearInterval(_timer);
    const secs = parseInt(document.getElementById('t344Interval')?.value||60);
    if(secs > 0) _timer = setInterval(()=>t344Refresh(false), secs*1000);
  };

  document.addEventListener('DOMContentLoaded', function(){
    t344Refresh(true);
    t344SetInterval();
  });
})();

// ═══════════════════════════════════════════════════════════════
// t345 — Stock Price Alerts
// ═══════════════════════════════════════════════════════════════
(function(){
  function inr(n){ return '₹'+Number(n).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}); }

  function showToast(msg, type='success'){
    const t=document.createElement('div');
    t.style.cssText=`position:fixed;bottom:24px;right:24px;background:${type==='success'?'#16a34a':'#ef4444'};color:#fff;padding:12px 18px;border-radius:10px;font-size:13px;font-weight:600;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.2);max-width:340px;`;
    t.textContent=msg; document.body.appendChild(t); setTimeout(()=>t.remove(),4000);
  }

  /* ── Load & Render Alerts ───────────────────── */
  async function loadAlerts(){
    const wrap = document.getElementById('t345AlertList');
    if(!wrap) return;
    try {
      const r = await fetch('<?= APP_URL ?>/api/?action=stocks_alert_list');
      const d = await r.json();
      if(!d.success){ wrap.innerHTML='<p style="color:#ef4444;font-size:12px;">'+d.message+'</p>'; return; }
      renderAlerts(d.data||[]);
    } catch(e){ wrap.innerHTML='<p style="color:#ef4444;font-size:12px;">Failed to load alerts.</p>'; }
  }

  function renderAlerts(alerts){
    const wrap = document.getElementById('t345AlertList');
    if(!wrap) return;
    if(!alerts.length){
      wrap.innerHTML=`<div style="text-align:center;padding:32px;color:var(--text-muted);">
        <div style="font-size:36px;margin-bottom:8px;">🔔</div>
        <div style="font-size:14px;font-weight:600;margin-bottom:4px;">No price alerts yet</div>
        <div style="font-size:12px;">Click "+ New Alert" or the 🔔 button on any stock in the live ticker above.</div>
      </div>`;
      return;
    }

    const TYPE_LABEL = { above:'📈 Rises above', below:'📉 Falls below', pct_up:'🚀 % rise above', pct_down:'💸 % drop below' };

    wrap.innerHTML=`<div style="display:flex;flex-direction:column;gap:8px;">
      ${alerts.map(a=>{
        const isTriggered = !!a.triggered_at;
        const isPaused    = !a.is_active && !isTriggered;
        const cp = parseFloat(a.current_price)||0;
        const tp = parseFloat(a.target_price)||0;
        const dist = a.distance_pct;
        const distTxt = dist!==null ? `${Math.abs(dist).toFixed(1)}% ${dist>0?'away':'TRIGGERED'}` : '';
        const statusColor = isTriggered?'#16a34a':isPaused?'#9ca3af':'#3b82f6';
        const statusLabel = isTriggered?'✅ Triggered':isPaused?'⏸ Paused':'🟢 Active';
        return `<div style="display:flex;align-items:center;gap:12px;padding:12px 14px;background:var(--bg-secondary);border-radius:10px;border-left:3px solid ${statusColor};${isTriggered?'opacity:.7':''}">
          <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:2px;">
              <span style="font-size:13px;font-weight:800;color:var(--text);">${a.symbol}</span>
              <span style="font-size:10px;color:var(--text-muted);">${a.company_name||''}</span>
              <span style="margin-left:auto;font-size:10px;font-weight:700;color:${statusColor};background:${statusColor}22;padding:2px 7px;border-radius:99px;">${statusLabel}</span>
            </div>
            <div style="font-size:12px;color:var(--text-secondary);">
              ${TYPE_LABEL[a.alert_type]||a.alert_type} <strong style="color:var(--text);">${inr(tp)}</strong>
              ${cp>0?`<span style="margin-left:6px;color:var(--text-muted);">LTP: ${inr(cp)}</span>`:''}
              ${distTxt?`<span style="margin-left:6px;font-size:11px;color:${dist!==null&&dist<=0?'#16a34a':'#d97706'};">${distTxt}</span>`:''}
            </div>
            ${a.note?`<div style="font-size:11px;color:var(--text-muted);margin-top:2px;">📝 ${a.note}</div>`:''}
            ${isTriggered?`<div style="font-size:11px;color:#16a34a;margin-top:2px;">Triggered @ ${inr(a.triggered_price)} on ${a.triggered_at?.slice(0,16)||''}</div>`:''}
          </div>
          <div style="display:flex;gap:4px;flex-shrink:0;">
            ${!isTriggered?`<button onclick="t345Toggle(${a.id})" title="${isPaused?'Activate':'Pause'}"
              style="border:1px solid var(--border);background:var(--bg-card);border-radius:6px;padding:4px 8px;cursor:pointer;font-size:12px;">${isPaused?'▶':'⏸'}</button>`:''}
            <button onclick="t345Delete(${a.id})" title="Delete"
              style="border:1px solid #fee2e2;background:#fef2f2;border-radius:6px;padding:4px 8px;cursor:pointer;font-size:12px;color:#ef4444;">🗑</button>
          </div>
        </div>`;
      }).join('')}
    </div>
    <div style="margin-top:12px;display:flex;justify-content:flex-end;">
      <button onclick="t345CheckNow()" class="btn btn-ghost btn-sm" style="font-size:12px;">
        🔍 Check Alerts Now
      </button>
    </div>`;
  }

  /* ── Modal ───────────────────────────────────── */
  let _modal=null;
  window.t345OpenModal = function(symbol='', company='', stockId='', ltp=0){
    if(_modal) _modal.remove();
    _modal = document.createElement('div');
    _modal.className='modal-overlay';
    _modal.style.display='flex';
    _modal.innerHTML=`
      <div class="modal" style="max-width:440px;">
        <div class="modal-header">
          <h3 class="modal-title">🔔 Set Price Alert${symbol?' — '+symbol:''}</h3>
          <button class="modal-close" onclick="this.closest('.modal-overlay').remove()">✕</button>
        </div>
        <div class="modal-body">
          ${!stockId?`<div class="form-group" style="margin-bottom:12px;">
            <label class="form-label">Symbol / Company *</label>
            <input type="text" class="form-input" id="t345SearchInput" placeholder="Search RELIANCE, TCS, INFY..." oninput="t345Search(this.value)" autocomplete="off">
            <div id="t345SearchResults" style="position:relative;"></div>
            <input type="hidden" id="t345StockId">
            <input type="hidden" id="t345Symbol">
            <input type="hidden" id="t345Company">
            <input type="hidden" id="t345Ltp" value="0">
          </div>`:`<div style="padding:8px 12px;background:var(--bg-secondary);border-radius:8px;margin-bottom:12px;">
            <span style="font-size:13px;font-weight:800;">${symbol}</span>
            <span style="font-size:12px;color:var(--text-muted);margin-left:6px;">${company}</span>
            ${ltp?`<span style="float:right;font-size:13px;font-weight:700;">LTP: ₹${Number(ltp).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2})}</span>`:''}
            <input type="hidden" id="t345StockId" value="${stockId}">
            <input type="hidden" id="t345Symbol" value="${symbol}">
            <input type="hidden" id="t345Company" value="${company}">
            <input type="hidden" id="t345Ltp" value="${ltp}">
          </div>`}
          <div class="form-row">
            <div class="form-group">
              <label class="form-label">Alert Type *</label>
              <select class="form-select" id="t345Type" onchange="t345UpdateTargetHint()">
                <option value="above">📈 Rises above (target price)</option>
                <option value="below">📉 Falls below (target price)</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Target Price (₹) *</label>
              <input type="number" class="form-input" id="t345Target" placeholder="0.00" step="0.05" min="0.01" oninput="t345UpdateTargetHint()">
              <div id="t345TargetHint" style="font-size:10px;color:var(--text-muted);margin-top:3px;"></div>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Note (optional)</label>
            <input type="text" class="form-input" id="t345Note" placeholder="e.g. Support level, earnings expectation...">
          </div>
          <div style="display:flex;gap:16px;margin-top:4px;">
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;">
              <input type="checkbox" id="t345Browser" checked> Browser Notification
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;">
              <input type="checkbox" id="t345Email"> Email Alert
            </label>
          </div>
          <div id="t345ModalErr" style="color:#ef4444;font-size:12px;margin-top:8px;display:none;"></div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-ghost" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
          <button class="btn btn-primary" onclick="t345Save()">🔔 Set Alert</button>
        </div>
      </div>`;
    document.body.appendChild(_modal);
    if(ltp) {
      const input = document.getElementById('t345Target');
      if(input) { input.value = ''; input.placeholder = Number(ltp).toFixed(2); }
      t345UpdateTargetHint();
    }
    if('Notification' in window && Notification.permission==='default'){
      Notification.requestPermission();
    }
  };

  window.t345UpdateTargetHint = function(){
    const ltp    = parseFloat(document.getElementById('t345Ltp')?.value||0);
    const target = parseFloat(document.getElementById('t345Target')?.value||0);
    const type   = document.getElementById('t345Type')?.value||'above';
    const hint   = document.getElementById('t345TargetHint');
    if(!hint) return;
    if(ltp>0 && target>0){
      const diff = ((target-ltp)/ltp*100).toFixed(1);
      const dir  = type==='above'?'above':'below';
      hint.textContent = `${Math.abs(diff)}% ${dir} current price (₹${Number(ltp).toFixed(2)})`;
      hint.style.color = type==='above'?'#16a34a':'#ef4444';
    } else {
      hint.textContent = ltp>0 ? `Current LTP: ₹${Number(ltp).toFixed(2)}` : '';
    }
  };

  /* Stock search for new alert */
  let _searchTimer=null;
  window.t345Search = async function(q){
    if(_searchTimer) clearTimeout(_searchTimer);
    const res = document.getElementById('t345SearchResults');
    if(!q||q.length<1){ if(res) res.innerHTML=''; return; }
    _searchTimer = setTimeout(async ()=>{
      try {
        const r = await fetch(`<?= APP_URL ?>/api/?action=stocks_search&q=${encodeURIComponent(q)}`);
        const d = await r.json();
        if(!res) return;
        if(!d.success||!d.data?.length){ res.innerHTML='<div style="font-size:12px;color:var(--text-muted);padding:6px 0;">No results.</div>'; return; }
        res.innerHTML=`<div style="position:absolute;top:2px;left:0;right:0;background:var(--bg-card);border:1.5px solid var(--border);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:300;max-height:220px;overflow-y:auto;">
          ${d.data.slice(0,8).map(s=>`<div onclick="t345SelectStock(${s.id},'${s.symbol}','${(s.company_name||'').replace(/'/g,"\\'")}',${s.latest_price||0})"
            style="padding:9px 14px;cursor:pointer;font-size:12px;border-bottom:1px solid var(--border);" onmouseover="this.style.background='var(--bg-secondary)'" onmouseout="this.style.background=''">
            <strong>${s.symbol}</strong> <span style="color:var(--text-muted);">${s.company_name||''}</span>
            ${s.latest_price?`<span style="float:right;color:var(--text-muted);">₹${Number(s.latest_price).toFixed(2)}</span>`:''}
          </div>`).join('')}
        </div>`;
      } catch(e){}
    }, 300);
  };

  window.t345SelectStock = function(id, symbol, company, ltp){
    const si = document.getElementById('t345StockId');
    const sy = document.getElementById('t345Symbol');
    const co = document.getElementById('t345Company');
    const lt = document.getElementById('t345Ltp');
    const inp= document.getElementById('t345SearchInput');
    const res= document.getElementById('t345SearchResults');
    if(si) si.value=id;
    if(sy) sy.value=symbol;
    if(co) co.value=company;
    if(lt) lt.value=ltp||0;
    if(inp) inp.value=`${symbol} — ${company}`;
    if(res) res.innerHTML='';
    if(ltp) {
      const tgt = document.getElementById('t345Target');
      if(tgt) tgt.placeholder = Number(ltp).toFixed(2);
      t345UpdateTargetHint();
    }
  };

  window.t345Save = async function(){
    const stockId = document.getElementById('t345StockId')?.value;
    const symbol  = document.getElementById('t345Symbol')?.value;
    const company = document.getElementById('t345Company')?.value||'';
    const type    = document.getElementById('t345Type')?.value||'above';
    const target  = document.getElementById('t345Target')?.value;
    const note    = document.getElementById('t345Note')?.value||'';
    const browser = document.getElementById('t345Browser')?.checked?1:0;
    const email   = document.getElementById('t345Email')?.checked?1:0;
    const err     = document.getElementById('t345ModalErr');

    if(!stockId||!symbol){ if(err){err.textContent='Select a stock.';err.style.display='';} return; }
    if(!target||parseFloat(target)<=0){ if(err){err.textContent='Enter a valid target price.';err.style.display='';} return; }
    if(err) err.style.display='none';

    const fd=new FormData();
    fd.append('action','stocks_alert_save');
    fd.append('stock_id',stockId);
    fd.append('symbol',symbol);
    fd.append('company_name',company);
    fd.append('alert_type',type);
    fd.append('target_price',target);
    fd.append('note',note);
    fd.append('notify_browser',browser);
    fd.append('notify_email',email);

    try {
      const r = await fetch('<?= APP_URL ?>/api/?action=stocks_alert_save', {method:'POST',body:fd});
      const d = await r.json();
      if(d.success){
        _modal?.remove();
        showToast('✅ Alert set for '+symbol+' @ ₹'+parseFloat(target).toLocaleString('en-IN'));
        loadAlerts();
        // Request notification permission
        if(browser && 'Notification' in window && Notification.permission==='default'){
          Notification.requestPermission();
        }
      } else {
        if(err){err.textContent=d.message||'Failed.';err.style.display='';}
      }
    } catch(e){ if(err){err.textContent='Network error.';err.style.display='';} }
  };

  window.t345Delete = async function(id){
    if(!confirm('Delete this alert?')) return;
    const fd=new FormData(); fd.append('action','stocks_alert_delete'); fd.append('id',id);
    const r = await fetch('<?= APP_URL ?>/api/?action=stocks_alert_delete',{method:'POST',body:fd});
    const d = await r.json();
    if(d.success){ showToast('Alert deleted.'); loadAlerts(); }
    else showToast(d.message||'Failed.','error');
  };

  window.t345Toggle = async function(id){
    const fd=new FormData(); fd.append('action','stocks_alert_toggle'); fd.append('id',id);
    const r = await fetch('<?= APP_URL ?>/api/?action=stocks_alert_toggle',{method:'POST',body:fd});
    const d = await r.json();
    if(d.success){ loadAlerts(); }
    else showToast(d.message||'Failed.','error');
  };

  window.t345CheckNow = async function(){
    showToast('⏳ Checking alerts...');
    try {
      const r = await fetch('<?= APP_URL ?>/api/?action=stocks_alert_check');
      const d = await r.json();
      const triggered = d.data?.triggered||[];
      if(triggered.length){
        triggered.forEach(a=>{
          showToast(`🔔 ${a.symbol} ${a.alert_type==='above'?'rose above':'fell below'} ₹${Number(a.target_price).toFixed(2)} — now ₹${Number(a.current_price).toFixed(2)}`,'success');
          if(a.notify_browser && 'Notification' in window && Notification.permission==='granted'){
            new Notification(`WealthDash Alert: ${a.symbol}`, {
              body: `${a.alert_type==='above'?'📈 Rose above':'📉 Fell below'} ₹${Number(a.target_price).toFixed(2)} — Current: ₹${Number(a.current_price).toFixed(2)}`,
              icon: '/public/img/icon-192.png',
            });
          }
        });
        loadAlerts();
      } else {
        showToast(`✅ Checked ${d.data?.checked||0} alert(s) — no triggers yet.`);
      }
    } catch(e){ showToast('Failed to check alerts.','error'); }
  };

  document.addEventListener('DOMContentLoaded', function(){
    loadAlerts();
    // Auto-check alerts every 2 minutes
    setInterval(t345CheckNow, 120000);
  });
})();
</script>

<!-- Transaction History -->
<div class="card" style="margin-top:24px">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <h3 class="card-title">Transaction History</h3>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <input type="text" class="form-input" id="txnSearchSymbol" placeholder="Symbol..." style="width:140px">
      <select class="form-select" id="txnFilterType" style="width:130px">
        <option value="">All Types</option>
        <option value="BUY">BUY</option>
        <option value="SELL">SELL</option>
        <option value="DIV">DIV</option>
        <option value="BONUS">BONUS</option>
        <option value="SPLIT">SPLIT</option>
      </select>
      <select class="form-select" id="txnFilterFy" style="width:120px">
        <option value="">All FY</option>
        <?php
        $fyYear = date('n') >= 4 ? (int)date('Y') : (int)date('Y') - 1;
        for ($i = 0; $i < 10; $i++) {
          $fy = ($fyYear - $i) . '-' . substr((string)($fyYear - $i + 1), 2);
          echo "<option value=\"{$fy}\">{$fy}</option>";
        }
        ?>
      </select>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead>
        <tr>
          <th>Date</th>
          <th>Symbol</th>
          <th>Type</th>
          <th class="text-right">Qty</th>
          <th class="text-right">Price</th>
          <th class="text-right">Brokerage</th>
          <th class="text-right">STT</th>
          <th class="text-right">Total Cost</th>
          <th>FY</th>
          <th class="text-center">Del</th>
        </tr>
      </thead>
      <tbody id="stocksTxnBody">
        <tr><td colspan="10" class="text-center" style="padding:40px;color:var(--text-muted)"><span class="spinner"></span></td></tr>
      </tbody>
    </table>
  </div>
</div>

<!-- Add Stock Transaction Modal -->
<div class="modal-overlay" id="modalAddStock" style="display:none">
  <div class="modal" style="max-width:560px">
    <div class="modal-header">
      <h3 class="modal-title" id="stockModalTitle">Add Stock Transaction</h3>
      <button class="modal-close" id="closeStockModal">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Portfolio *</label>
          <select class="form-select" id="stockPortfolio">
            <?php foreach ($portfolios as $p): ?>
            <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Transaction Type *</label>
          <select class="form-select" id="stockTxnType" onchange="STOCKS.onTxnTypeChange()">
            <option value="BUY">BUY</option>
            <option value="SELL">SELL</option>
            <option value="DIV">DIVIDEND</option>
            <option value="BONUS">BONUS</option>
            <option value="SPLIT">SPLIT</option>
          </select>
        </div>
      </div>

      <!-- Stock Search -->
      <div class="form-group" style="position:relative">
        <label class="form-label">Stock Symbol *</label>
        <input type="text" class="form-input" id="stockSearch" placeholder="Type symbol (e.g. RELIANCE, INFY)..." autocomplete="off" oninput="STOCKS.searchStocks()">
        <div id="stockDropdown" class="autocomplete-dropdown" style="display:none"></div>
        <input type="hidden" id="stockId">
        <div id="selectedStockInfo" style="margin-top:6px;font-size:12px;color:var(--text-muted)"></div>
        <!-- New stock inline form -->
        <div id="newStockForm" style="display:none;margin-top:10px;padding:12px;background:var(--surface-hover);border-radius:8px">
          <div style="font-size:12px;font-weight:600;margin-bottom:8px;color:var(--text-muted)">Add new stock to master:</div>
          <div class="form-row">
            <div class="form-group"><label class="form-label">Symbol *</label><input type="text" class="form-input" id="newSymbol" placeholder="RELIANCE"></div>
            <div class="form-group"><label class="form-label">Exchange *</label><select class="form-select" id="newExchange"><option value="NSE">NSE</option><option value="BSE">BSE</option></select></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label class="form-label">Company Name</label><input type="text" class="form-input" id="newCompany" placeholder="Reliance Industries Ltd"></div>
            <div class="form-group"><label class="form-label">Sector</label><input type="text" class="form-input" id="newSector" placeholder="Energy"></div>
          </div>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Transaction Date *</label>
          <input type="date" class="form-input" id="stockTxnDate" max="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group">
          <label class="form-label"><span id="priceLabel">Price / Share (₹) *</span></label>
          <input type="number" class="form-input" id="stockPrice" step="0.01" min="0" placeholder="0.00" oninput="STOCKS.calcTotal()">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group" id="qtyGroup">
          <label class="form-label">Quantity *</label>
          <input type="number" class="form-input" id="stockQty" step="1" min="1" placeholder="0" oninput="STOCKS.calcTotal()">
        </div>
        <div class="form-group" id="chargesGroup">
          <label class="form-label">Total Value (₹)</label>
          <input type="number" class="form-input" id="stockTotal" readonly style="background:var(--surface-hover)">
        </div>
      </div>
      <div class="form-row" id="chargesRow">
        <div class="form-group">
          <label class="form-label">Brokerage (₹)</label>
          <input type="number" class="form-input" id="stockBrokerage" step="0.01" min="0" value="0" oninput="STOCKS.calcTotal()">
        </div>
        <div class="form-group">
          <label class="form-label">STT (₹)</label>
          <input type="number" class="form-input" id="stockStt" step="0.01" min="0" value="0" oninput="STOCKS.calcTotal()">
        </div>
        <div class="form-group">
          <label class="form-label">Exchange Charges (₹)</label>
          <input type="number" class="form-input" id="stockExch" step="0.01" min="0" value="0" oninput="STOCKS.calcTotal()">
        </div>
      </div>
      <!-- DIV-specific -->
      <div class="form-group" id="divTotalGroup" style="display:none">
        <label class="form-label">Total Dividend Amount (₹)</label>
        <input type="number" class="form-input" id="stockDivTotal" step="0.01" min="0" placeholder="Total dividend received">
      </div>
      <div class="form-group">
        <label class="form-label">Notes</label>
        <input type="text" class="form-input" id="stockNotes" placeholder="Optional">
      </div>
      <div id="stockError" class="form-error" style="display:none"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="cancelStock">Cancel</button>
      <button class="btn btn-primary" id="saveStock">Save Transaction</button>
    </div>
  </div>
</div>

<!-- Delete Confirm -->
<div class="modal-overlay" id="modalDelStock" style="display:none">
  <div class="modal" style="max-width:400px">
    <div class="modal-header"><h3 class="modal-title">Delete Transaction?</h3><button class="modal-close" id="closeDelStock">✕</button></div>
    <div class="modal-body"><p>This will permanently delete this stock transaction and recalculate holdings.</p></div>
    <div class="modal-footer">
      <button class="btn btn-ghost" id="cancelDelStock">Cancel</button>
      <button class="btn btn-danger" id="confirmDelStock">Delete</button>
    </div>
  </div>
</div>

<script src="<?= APP_URL ?>/public/js/stocks.js?v=<?= ASSET_VERSION ?>"></script>
<script src="<?= APP_URL ?>/public/js/behavioral_analytics.js?v=<?= ASSET_VERSION ?>"></script>
<script>
// t145 + t216 + t217: Hook analytics after holdings load
const _origLoadHoldings = STOCKS.loadHoldings.bind(STOCKS);
STOCKS.loadHoldings = async function() {
  await _origLoadHoldings();
  const holdings = STOCKS._allRows || [];
  renderStockSectorAnalytics(holdings);
  renderStockDividendTracker();
  // Get rendered rows data for reality check
  setTimeout(() => {
    const rows = [];
    document.querySelectorAll('#stocksBody tr').forEach(tr => {
      const cells = tr.querySelectorAll('td');
      if (cells.length >= 7) {
        rows.push({
          total_invested: parseFloat(cells[5]?.textContent?.replace(/[₹,L]/g,'').trim()) * (cells[5]?.textContent?.includes('L') ? 100000 : 1) || 0,
          current_value:  parseFloat(cells[6]?.textContent?.replace(/[₹,L]/g,'').trim()) * (cells[6]?.textContent?.includes('L') ? 100000 : 1) || 0,
        });
      }
    });
    if (typeof renderStockPickerCheck === 'function') {
      renderStockPickerCheck('stockPickerCheck', rows);
    }
  }, 300);
};
</script>
<?php
$pageContent = ob_get_clean();
include APP_ROOT . '/templates/layout.php';

