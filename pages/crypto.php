<?php
/**
 * WealthDash — t41: Crypto Page — Live Prices + P&L
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser   = require_auth();
$pageTitle     = 'Crypto Portfolio';
$activePage    = 'crypto';
$activeSection = 'crypto';

ob_start();
?>
<style>
.crypto-stat{background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:20px;text-align:center;}
.crypto-stat-label{font-size:11px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;}
.crypto-stat-value{font-size:22px;font-weight:700;}
.crypto-stat-sub{font-size:12px;color:var(--text-secondary);margin-top:2px;}
.coin-row{display:flex;align-items:center;gap:14px;padding:14px 20px;border-bottom:1px solid var(--border);transition:.12s;}
.coin-row:last-child{border-bottom:none;}
.coin-row:hover{background:var(--hover-bg,rgba(0,0,0,.02));}
.coin-logo{width:36px;height:36px;border-radius:50%;object-fit:cover;background:var(--border);}
.coin-logo-fallback{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6366f1,#8b5cf6);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff;flex-shrink:0;}
.badge-pos{display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;background:rgba(34,197,94,.12);color:#16a34a;}
.badge-neg{display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;background:rgba(239,68,68,.12);color:#dc2626;}
.gain-pos{color:#16a34a;font-weight:600;}
.gain-neg{color:#dc2626;font-weight:600;}
.tab-btn{padding:8px 16px;border:none;background:none;cursor:pointer;font-size:13px;color:var(--text-secondary);border-bottom:2px solid transparent;transition:.15s;}
.tab-btn.active{color:var(--primary,#6366f1);border-bottom-color:var(--primary,#6366f1);font-weight:600;}
.tbl{width:100%;border-collapse:collapse;font-size:13px;}
.tbl th{background:var(--table-header-bg,rgba(0,0,0,.04));padding:10px 14px;text-align:left;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-secondary);border-bottom:1px solid var(--border);white-space:nowrap;}
.tbl td{padding:11px 14px;border-bottom:1px solid var(--border);vertical-align:middle;}
.tbl tr:hover td{background:var(--hover-bg,rgba(0,0,0,.02));}
.tbl .num{text-align:right;}
.btn-icon{background:none;border:1px solid var(--border);border-radius:6px;padding:4px 8px;cursor:pointer;color:var(--text-secondary);font-size:12px;transition:.15s;}
.btn-icon:hover{background:var(--hover-bg);color:var(--text-primary);}
.crypto-empty{text-align:center;padding:60px 24px;color:var(--text-secondary);}
.price-flash-up{animation:flashUp .6s ease;}
.price-flash-down{animation:flashDown .6s ease;}
@keyframes flashUp{0%{background:rgba(34,197,94,.2);}100%{background:transparent;}}
@keyframes flashDown{0%{background:rgba(239,68,68,.2);}100%{background:transparent;}}
.alloc-bar{height:8px;border-radius:4px;background:var(--border);overflow:hidden;margin-top:4px;}
.alloc-fill{height:100%;border-radius:4px;transition:.4s;}
</style>

<div class="page-wrapper">
  <!-- Header -->
  <div style="display:flex;align-items:center;justify-content:space-between;padding:24px 0 20px;border-bottom:1px solid var(--border);margin-bottom:24px;">
    <div>
      <h1 style="margin:0;font-size:24px;font-weight:700;">₿ Crypto Portfolio</h1>
      <p style="color:var(--text-secondary);margin:4px 0 0;font-size:13px;">Live prices via CoinGecko · VDA tax tracking</p>
    </div>
    <div style="display:flex;gap:8px;align-items:center;">
      <button class="btn btn-ghost btn-sm" id="btnRefreshCrypto" onclick="cryptoRefreshPrices()">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.12"/></svg>
        Refresh
      </button>
      <button class="btn btn-primary btn-sm" onclick="cryptoOpenAdd()">＋ Add Holding</button>
    </div>
  </div>

  <!-- Summary Cards -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px;margin-bottom:28px;">
    <div class="crypto-stat"><div class="crypto-stat-label">Coins</div><div class="crypto-stat-value" id="sumCoins">—</div></div>
    <div class="crypto-stat"><div class="crypto-stat-label">Invested</div><div class="crypto-stat-value" id="sumInvested">—</div></div>
    <div class="crypto-stat"><div class="crypto-stat-label">Current Value</div><div class="crypto-stat-value" id="sumValue">—</div></div>
    <div class="crypto-stat">
      <div class="crypto-stat-label">Overall P&amp;L</div>
      <div class="crypto-stat-value" id="sumPnl">—</div>
      <div class="crypto-stat-sub" id="sumPnlPct">—</div>
    </div>
    <div class="crypto-stat"><div class="crypto-stat-label">Best 24h</div><div class="crypto-stat-value gain-pos" id="sumBest">—</div></div>
    <div class="crypto-stat"><div class="crypto-stat-label">Worst 24h</div><div class="crypto-stat-value gain-neg" id="sumWorst">—</div></div>
  </div>

  <!-- Last updated -->
  <div style="font-size:11px;color:var(--text-secondary);margin-bottom:16px;text-align:right;" id="lastUpdated"></div>

  <!-- Tabs -->
  <div style="display:flex;border-bottom:1px solid var(--border);margin-bottom:20px;">
    <button class="tab-btn active" id="tabHoldings"    onclick="cryptoTab('holdings')">Holdings</button>
    <button class="tab-btn"        id="tabAllocation"  onclick="cryptoTab('allocation')">Allocation</button>
    <button class="tab-btn"        id="tabTransactions"onclick="cryptoTab('transactions')">Transactions</button>
    <button class="tab-btn"        id="tabWatchlist"   onclick="cryptoTab('watchlist')">Watchlist</button>
    <button class="tab-btn"        id="tabTax"         onclick="cryptoTab('tax')">Tax (VDA)</button>
  </div>

  <!-- Holdings Panel -->
  <div id="panelHoldings">
    <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;overflow:hidden;" id="holdingsCard">
      <div class="crypto-empty"><p style="margin:0;">Loading…</p></div>
    </div>
  </div>

  <!-- Allocation Panel -->
  <div id="panelAllocation" style="display:none;">
    <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:24px;" id="allocCard">
      <p style="color:var(--text-secondary);text-align:center;">Loading…</p>
    </div>
  </div>

  <!-- Transactions Panel -->
  <div id="panelTransactions" style="display:none;">
    <div style="display:flex;justify-content:flex-end;margin-bottom:12px;">
      <button class="btn btn-sm btn-ghost" onclick="cryptoOpenTxn()">＋ Log Transaction</button>
    </div>
    <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;overflow:hidden;">
      <table class="tbl" id="txnTable">
        <thead><tr>
          <th>Date</th><th>Coin</th><th>Type</th>
          <th class="num">Qty</th><th class="num">Price (₹)</th>
          <th class="num">Total (₹)</th><th>Exchange</th><th>Note</th>
        </tr></thead>
        <tbody id="txnTbody"><tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text-secondary);">Loading…</td></tr></tbody>
      </table>
    </div>
  </div>

  <!-- Watchlist Panel -->
  <div id="panelWatchlist" style="display:none;">
    <div style="display:flex;justify-content:flex-end;margin-bottom:12px;">
      <button class="btn btn-sm btn-ghost" onclick="cryptoOpenWlAdd()">＋ Add to Watchlist</button>
    </div>
    <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;overflow:hidden;">
      <table class="tbl">
        <thead><tr>
          <th>Coin</th><th class="num">Current Price</th><th class="num">24h</th>
          <th class="num">Alert Low</th><th class="num">Alert High</th><th>Actions</th>
        </tr></thead>
        <tbody id="wlTbody"><tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-secondary);">Loading…</td></tr></tbody>
      </table>
    </div>
  </div>

  <!-- Tax Panel -->
  <div id="panelTax" style="display:none;">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
      <label style="font-size:13px;font-weight:600;">Financial Year:</label>
      <select class="form-select form-select-sm" style="width:140px;" id="taxYearSel" onchange="cryptoLoadTax()">
        <?php
        $yr = (int)date('Y');
        for ($y = $yr; $y >= $yr-4; $y--) {
            echo "<option value='{$y}'>FY {$y}-" . ($y+1) . "</option>";
        }
        ?>
      </select>
    </div>
    <div id="taxCard" style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:24px;">
      <p style="color:var(--text-secondary);text-align:center;">Loading…</p>
    </div>
  </div>
</div>

<!-- ── Add Holding Modal ───────────────────────────────────────────────────── -->
<div class="modal fade" id="cryptoAddModal" tabindex="-1">
  <div class="modal-dialog" style="max-width:520px;">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Add Crypto Holding</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-3">
          <label class="form-label">Search Coin *</label>
          <input class="form-control form-control-sm" id="cgSearch" placeholder="Bitcoin, ETH, BNB…" oninput="cryptoSearchCoin(this.value)">
          <div id="cgSearchResults" style="border:1px solid var(--border);border-radius:6px;margin-top:4px;display:none;max-height:200px;overflow-y:auto;background:var(--card-bg);position:absolute;z-index:9999;width:calc(100% - 48px);"></div>
        </div>
        <input type="hidden" id="addCoinId"><input type="hidden" id="addCgId"><input type="hidden" id="addCoinName">
        <div id="selectedCoinBadge" style="display:none;margin-bottom:12px;padding:8px 12px;background:var(--hover-bg);border-radius:8px;font-size:13px;font-weight:600;" id="selCoinLabel"></div>
        <div class="row g-3">
          <div class="col-6"><label class="form-label">Quantity *</label><input type="number" class="form-control form-control-sm" id="addQty" min="0.00000001" step="any"></div>
          <div class="col-6"><label class="form-label">Buy Price (₹) *</label><input type="number" class="form-control form-control-sm" id="addPrice" min="0" step="any"></div>
          <div class="col-6"><label class="form-label">Buy Date</label><input type="date" class="form-control form-control-sm" id="addDate" value="<?= date('Y-m-d') ?>"></div>
          <div class="col-6"><label class="form-label">Exchange</label><input class="form-control form-control-sm" id="addExchange" placeholder="WazirX, CoinDCX…"></div>
          <div class="col-12"><label class="form-label">Wallet / Notes</label><input class="form-control form-control-sm" id="addNotes"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-primary" onclick="cryptoSaveHolding()">Add Holding</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Log Transaction Modal ──────────────────────────────────────────────── -->
<div class="modal fade" id="cryptoTxnModal" tabindex="-1">
  <div class="modal-dialog" style="max-width:480px;">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Log Transaction</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-6">
            <label class="form-label">Coin</label>
            <select class="form-select form-select-sm" id="txnCoinSel"></select>
          </div>
          <div class="col-6">
            <label class="form-label">Type</label>
            <select class="form-select form-select-sm" id="txnType">
              <option value="buy">Buy</option><option value="sell">Sell</option>
              <option value="transfer_in">Transfer In</option><option value="transfer_out">Transfer Out</option>
              <option value="staking">Staking Reward</option><option value="airdrop">Airdrop</option>
            </select>
          </div>
          <div class="col-4"><label class="form-label">Quantity *</label><input type="number" class="form-control form-control-sm" id="txnQty" step="any"></div>
          <div class="col-4"><label class="form-label">Price (₹)</label><input type="number" class="form-control form-control-sm" id="txnPrice" step="any"></div>
          <div class="col-4"><label class="form-label">Fee (₹)</label><input type="number" class="form-control form-control-sm" id="txnFee" value="0" step="any"></div>
          <div class="col-6"><label class="form-label">Date *</label><input type="date" class="form-control form-control-sm" id="txnDate" value="<?= date('Y-m-d') ?>"></div>
          <div class="col-6"><label class="form-label">Exchange</label><input class="form-control form-control-sm" id="txnExchange"></div>
          <div class="col-12"><label class="form-label">Note</label><input class="form-control form-control-sm" id="txnNote"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-primary" onclick="cryptoSaveTxn()">Save</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
'use strict';
const API = '<?= APP_URL ?>/api/index.php';
let holdingsData = [];
let cgSearchTimer = null;

async function init(){
  await Promise.all([loadSummary(), loadHoldings()]);
  startLivePoll();
}

// ── Tab ───────────────────────────────────────────────────────────────────────
window.cryptoTab = function(tab){
  ['holdings','allocation','transactions','watchlist','tax'].forEach(t=>{
    document.getElementById('panel'+cap(t)).style.display = t===tab?'':'none';
    document.getElementById('tab'+cap(t)).classList.toggle('active',t===tab);
  });
  if(tab==='allocation')   loadAllocation();
  if(tab==='transactions') loadTxns();
  if(tab==='watchlist')    loadWatchlist();
  if(tab==='tax')          cryptoLoadTax();
};
function cap(s){ return s.charAt(0).toUpperCase()+s.slice(1); }

// ── Summary ───────────────────────────────────────────────────────────────────
async function loadSummary(){
  try{
    const d = await apiFetch('crypto_summary');
    if(!d.success) return;
    const s = d.summary;
    document.getElementById('sumCoins').textContent    = s.total_coins||0;
    document.getElementById('sumInvested').textContent = '₹'+fmtM(s.total_invested);
    document.getElementById('sumValue').textContent    = '₹'+fmtM(s.total_current_value);
    const gain = parseFloat(s.total_gain_loss||0);
    const pnlEl = document.getElementById('sumPnl');
    pnlEl.textContent = (gain>=0?'+':'')+'₹'+fmtM(Math.abs(gain));
    pnlEl.className   = 'crypto-stat-value '+(gain>=0?'gain-pos':'gain-neg');
    document.getElementById('sumPnlPct').textContent = (gain>=0?'+':'')+s.gain_pct+'%';
    if(d.top_gainer) document.getElementById('sumBest').textContent  = d.top_gainer.symbol+' '+formatPct(d.top_gainer.change_24h);
    if(d.top_loser)  document.getElementById('sumWorst').textContent = d.top_loser.symbol+' '+formatPct(d.top_loser.change_24h);
  }catch(e){}
}

// ── Holdings ──────────────────────────────────────────────────────────────────
async function loadHoldings(){
  try{
    const d = await apiFetch('crypto_list');
    const card = document.getElementById('holdingsCard');
    if(!d.success||!d.data.length){
      card.innerHTML=`<div class="crypto-empty">
        <p style="font-size:15px;font-weight:500;margin:0 0 6px;">No crypto holdings yet</p>
        <p style="font-size:13px;margin:0;">Click <strong>+ Add Holding</strong> to start.</p></div>`;
      return;
    }
    holdingsData = d.data;
    card.innerHTML = d.data.map(h=>renderCoinRow(h)).join('');
    document.getElementById('lastUpdated').textContent = 'Prices as of: '+fmtDateTime(new Date());
  }catch(e){}
}

function renderCoinRow(h){
  const gain     = parseFloat(h.gain_loss||0);
  const gainPct  = parseFloat(h.gain_pct||0);
  const chg24    = parseFloat(h.price_change_24h||0);
  const logo     = h.logo_url
    ? `<img class="coin-logo" src="${esc(h.logo_url)}" alt="${esc(h.symbol)}" onerror="this.style.display='none';this.nextSibling.style.display='flex';">
       <div class="coin-logo-fallback" style="display:none;">${esc(h.symbol).charAt(0)}</div>`
    : `<div class="coin-logo-fallback">${esc(h.symbol).charAt(0)}</div>`;
  return `<div class="coin-row" id="coinRow${h.id}">
    <div style="display:flex;align-items:center;gap:10px;flex:1.5;">
      ${logo}
      <div>
        <div style="font-weight:700;font-size:14px;">${esc(h.symbol)}</div>
        <div style="font-size:11px;color:var(--text-secondary);">${esc(h.name)}</div>
      </div>
    </div>
    <div style="flex:1;text-align:right;">
      <div style="font-weight:600;" id="price_${h.id}">₹${fmtN(h.current_price_inr)}</div>
      <span class="${chg24>=0?'badge-pos':'badge-neg'}">${formatPct(chg24)}</span>
    </div>
    <div style="flex:1;text-align:right;">
      <div style="font-size:11px;color:var(--text-secondary);">Qty</div>
      <div style="font-weight:600;">${fmtQty(h.quantity)}</div>
    </div>
    <div style="flex:1;text-align:right;">
      <div style="font-size:11px;color:var(--text-secondary);">Avg Price</div>
      <div>₹${fmtN(h.avg_buy_price)}</div>
    </div>
    <div style="flex:1.2;text-align:right;">
      <div style="font-weight:700;">₹${fmtM(h.current_value)}</div>
      <div class="${gain>=0?'gain-pos':'gain-neg'}" style="font-size:12px;">${gain>=0?'+':''}₹${fmtM(Math.abs(gain))} (${gainPct>=0?'+':''}${gainPct}%)</div>
    </div>
    <div style="display:flex;gap:4px;">
      <button class="btn-icon" onclick="cryptoDeleteHolding(${h.id},'${esc(h.symbol)}')" title="Remove">🗑️</button>
    </div>
  </div>`;
}

// ── Allocation ────────────────────────────────────────────────────────────────
async function loadAllocation(){
  try{
    const d = await apiFetch('crypto_portfolio_stats');
    const card = document.getElementById('allocCard');
    if(!d.success){ card.innerHTML='<p style="color:var(--text-secondary);text-align:center;">No data.</p>'; return; }
    const colors=['#6366f1','#f59e0b','#10b981','#ef4444','#3b82f6','#8b5cf6','#ec4899','#14b8a6'];
    let html='<h6 style="font-weight:700;margin-bottom:16px;">Portfolio Allocation</h6>';
    d.allocation.forEach((a,i)=>{
      const color=colors[i%colors.length];
      html+=`<div style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px;">
          <span style="font-weight:600;">${esc(a.symbol)} <span style="color:var(--text-secondary);font-weight:400;">${esc(a.name)}</span></span>
          <span style="font-weight:600;">₹${fmtM(a.value)} <span style="color:var(--text-secondary);">(${a.pct}%)</span></span>
        </div>
        <div class="alloc-bar"><div class="alloc-fill" style="width:${a.pct}%;background:${color};"></div></div>
      </div>`;
    });
    html+='<hr style="margin:20px 0;"><h6 style="font-weight:700;margin-bottom:12px;">P&L per Coin</h6>';
    html+=`<table class="tbl"><thead><tr><th>Coin</th><th class="num">Invested</th><th class="num">Current</th><th class="num">Gain/Loss</th><th class="num">Return</th></tr></thead><tbody>`;
    d.pnl.forEach(p=>{
      const gain=parseFloat(p.gain_loss||0);
      html+=`<tr><td><strong>${esc(p.symbol)}</strong><div style="font-size:11px;color:var(--text-secondary);">${esc(p.name)}</div></td>
        <td class="num">₹${fmtM(p.total_invested)}</td>
        <td class="num">₹${fmtM(p.current_value)}</td>
        <td class="num ${gain>=0?'gain-pos':'gain-neg'}">${gain>=0?'+':''}₹${fmtM(Math.abs(gain))}</td>
        <td class="num ${gain>=0?'gain-pos':'gain-neg'}">${gain>=0?'+':''}${p.gain_pct}%</td>
      </tr>`;
    });
    html+='</tbody></table>';
    card.innerHTML=html;
  }catch(e){}
}

// ── Transactions ──────────────────────────────────────────────────────────────
async function loadTxns(){
  try{
    const d = await apiFetch('crypto_txns');
    const tbody = document.getElementById('txnTbody');
    if(!d.success||!d.data.length){
      tbody.innerHTML='<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text-secondary);">No transactions yet.</td></tr>';
      return;
    }
    const typeColors={buy:'badge-pos',sell:'badge-neg',staking:'badge-pos',airdrop:'badge-pos',transfer_in:'badge-pos',transfer_out:'badge-neg',mining:'badge-pos'};
    tbody.innerHTML=d.data.map(t=>`<tr>
      <td>${fmtDate(t.txn_date)}</td>
      <td><strong>${esc(t.symbol)}</strong></td>
      <td><span class="${typeColors[t.type]||'badge-pos'}">${t.type}</span></td>
      <td class="num">${fmtQty(t.quantity)}</td>
      <td class="num">${t.price_inr>0?'₹'+fmtN(t.price_inr):'—'}</td>
      <td class="num">${t.total_inr>0?'₹'+fmtM(t.total_inr):'—'}</td>
      <td>${esc(t.exchange_name||'—')}</td>
      <td style="color:var(--text-secondary);">${esc(t.note||'')}</td>
    </tr>`).join('');
    // Populate coin select
    const sel=document.getElementById('txnCoinSel');
    if(sel&&holdingsData.length){
      sel.innerHTML=holdingsData.map(h=>`<option value="${h.coin_id}">${h.symbol} — ${h.name}</option>`).join('');
    }
  }catch(e){}
}

// ── Watchlist ─────────────────────────────────────────────────────────────────
async function loadWatchlist(){
  try{
    const d = await apiFetch('crypto_wl_list');
    const tbody = document.getElementById('wlTbody');
    if(!d.success||!d.data.length){
      tbody.innerHTML='<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-secondary);">Watchlist empty.</td></tr>';
      return;
    }
    tbody.innerHTML=d.data.map(w=>{
      const chg=parseFloat(w.price_change_24h||0);
      return `<tr>
        <td><strong>${esc(w.symbol)}</strong><div style="font-size:11px;color:var(--text-secondary);">${esc(w.name)}</div></td>
        <td class="num">₹${fmtN(w.current_price_inr)}</td>
        <td class="num"><span class="${chg>=0?'badge-pos':'badge-neg'}">${formatPct(chg)}</span></td>
        <td class="num">${w.alert_price_low?'₹'+fmtN(w.alert_price_low):'—'}</td>
        <td class="num">${w.alert_price_high?'₹'+fmtN(w.alert_price_high):'—'}</td>
        <td><button class="btn-icon" onclick="cryptoWlDelete(${w.id})">🗑️</button></td>
      </tr>`;
    }).join('');
  }catch(e){}
}

window.cryptoWlDelete = async function(id){
  if(!confirm('Remove from watchlist?')) return;
  const d = await apiPost('crypto_wl_delete',{id});
  if(d.success) loadWatchlist();
};

window.cryptoOpenWlAdd = function(){
  alert('Search for a coin in Add Holding, then mark it for watchlist. Full watchlist add coming in t41 follow-up.');
};

// ── Tax ───────────────────────────────────────────────────────────────────────
window.cryptoLoadTax = async function(){
  const year = document.getElementById('taxYearSel').value;
  const d    = await apiFetch(`crypto_tax_year_summary&year=${year}`);
  const card = document.getElementById('taxCard');
  if(!d.success){ card.innerHTML='<p style="color:var(--text-secondary);text-align:center;">No data.</p>'; return; }
  const gain = parseFloat(d.vda_gain||0);
  card.innerHTML=`
    <h6 style="font-weight:700;margin-bottom:16px;">VDA Tax Summary — FY ${year}-${parseInt(year)+1}</h6>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px;margin-bottom:24px;">
      <div class="crypto-stat"><div class="crypto-stat-label">Total Sells</div><div class="crypto-stat-value">${d.total_sells}</div></div>
      <div class="crypto-stat"><div class="crypto-stat-label">Sale Proceeds</div><div class="crypto-stat-value">₹${fmtM(d.total_proceeds)}</div></div>
      <div class="crypto-stat"><div class="crypto-stat-label">Cost Basis</div><div class="crypto-stat-value">₹${fmtM(d.total_cost)}</div></div>
      <div class="crypto-stat"><div class="crypto-stat-label">VDA Gain</div><div class="crypto-stat-value ${gain>=0?'gain-pos':'gain-neg'}">${gain>=0?'+':''}₹${fmtM(Math.abs(gain))}</div></div>
      <div class="crypto-stat"><div class="crypto-stat-label">Est. Tax @30%</div><div class="crypto-stat-value gain-neg">₹${fmtM(d.estimated_tax)}</div></div>
    </div>
    <div style="background:rgba(99,102,241,.06);border:1px solid rgba(99,102,241,.15);border-radius:10px;padding:14px;font-size:13px;margin-bottom:16px;">
      <strong>🇮🇳 Section 115BBH:</strong> VDA (crypto) gains taxed at flat 30% (+ surcharge + cess). No deduction except cost of acquisition. Losses cannot be set off against other income or carried forward.
    </div>
    ${d.total_sells?`<table class="tbl"><thead><tr><th>Date</th><th>Coin</th><th class="num">Qty</th><th class="num">Proceeds</th></tr></thead>
    <tbody>${d.transactions.map(t=>`<tr><td>${fmtDate(t.txn_date)}</td><td>${esc(t.symbol)}</td><td class="num">${fmtQty(t.quantity)}</td><td class="num">₹${fmtM(t.total_inr)}</td></tr>`).join('')}</tbody></table>`:''}
  `;
};

// ── Refresh prices ─────────────────────────────────────────────────────────────
window.cryptoRefreshPrices = async function(){
  const btn=document.getElementById('btnRefreshCrypto');
  btn.disabled=true; btn.textContent='Refreshing…';
  try{
    const d = await apiPost('crypto_refresh_prices',{});
    if(d.success){ await Promise.all([loadSummary(),loadHoldings()]); }
    else alert('Error: '+d.message);
  }finally{ btn.disabled=false; btn.innerHTML='<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.12"/></svg> Refresh'; }
};

// ── Live poll every 60s ───────────────────────────────────────────────────────
function startLivePoll(){
  setInterval(async()=>{
    try{
      const d = await apiPost('crypto_refresh_prices',{});
      if(d.success){ await Promise.all([loadSummary(),loadHoldings()]); }
    }catch(e){}
  }, 60000);
}

// ── Add Holding modal ─────────────────────────────────────────────────────────
window.cryptoOpenAdd = function(){
  document.getElementById('cgSearch').value='';
  document.getElementById('cgSearchResults').style.display='none';
  document.getElementById('addCoinId').value='';
  document.getElementById('addCgId').value='';
  document.getElementById('addQty').value='';
  document.getElementById('addPrice').value='';
  document.getElementById('addDate').value=new Date().toISOString().split('T')[0];
  document.getElementById('addExchange').value='';
  document.getElementById('addNotes').value='';
  document.getElementById('selectedCoinBadge').style.display='none';
  new bootstrap.Modal(document.getElementById('cryptoAddModal')).show();
};

window.cryptoSearchCoin = function(q){
  clearTimeout(cgSearchTimer);
  const res=document.getElementById('cgSearchResults');
  if(q.length<2){ res.style.display='none'; return; }
  cgSearchTimer=setTimeout(async()=>{
    try{
      const d = await apiFetch(`crypto_coingecko_search&q=${encodeURIComponent(q)}`);
      if(!d.success) return;
      const coins=[...d.coingecko].slice(0,8);
      if(!coins.length){ res.style.display='none'; return; }
      res.innerHTML=coins.map(c=>`
        <div onclick="cryptoSelectCoin('','${esc(c.id)}','${esc(c.symbol)}','${esc(c.name)}','')"
          style="padding:10px 14px;cursor:pointer;display:flex;align-items:center;gap:10px;border-bottom:1px solid var(--border);font-size:13px;"
          onmouseover="this.style.background='var(--hover-bg)'" onmouseout="this.style.background=''">
          <img src="${c.thumb||''}" width="22" height="22" style="border-radius:50%;" onerror="this.style.display='none'">
          <strong>${esc(c.symbol.toUpperCase())}</strong> <span style="color:var(--text-secondary);">${esc(c.name)}</span>
          <span style="margin-left:auto;font-size:11px;color:var(--text-secondary);">#${c.market_cap_rank||'?'}</span>
        </div>`).join('');
      res.style.display='block';
    }catch(e){}
  },400);
};

window.cryptoSelectCoin = function(coinId,cgId,symbol,name,logoUrl){
  document.getElementById('addCoinId').value=coinId;
  document.getElementById('addCgId').value=cgId;
  document.getElementById('cgSearch').value=symbol+' — '+name;
  document.getElementById('cgSearchResults').style.display='none';
  const badge=document.getElementById('selectedCoinBadge');
  badge.textContent='Selected: '+symbol.toUpperCase()+' ('+name+')';
  badge.style.display='block';
  // Prefill price from CoinGecko
  apiFetch('crypto_coingecko_coin&id='+cgId).then(d=>{
    if(d.success&&d.data){
      const p=d.data.market_data?.current_price?.inr;
      if(p) document.getElementById('addPrice').value=p;
    }
  }).catch(()=>{});
};

window.cryptoSaveHolding = async function(){
  const cgId = document.getElementById('addCgId').value;
  const qty  = document.getElementById('addQty').value;
  const price= document.getElementById('addPrice').value;
  if(!cgId||!qty||!price){ alert('Select a coin and fill quantity and price.'); return; }
  const d = await apiPost('crypto_add',{
    coingecko_id: cgId, quantity:qty, buy_price:price,
    buy_date: document.getElementById('addDate').value,
    exchange:  document.getElementById('addExchange').value,
    notes:     document.getElementById('addNotes').value,
  });
  if(d.success){
    bootstrap.Modal.getInstance(document.getElementById('cryptoAddModal'))?.hide();
    await Promise.all([loadSummary(),loadHoldings()]);
  } else alert('Error: '+d.message);
};

window.cryptoDeleteHolding = async function(id,symbol){
  if(!confirm('Remove '+symbol+' holding?')) return;
  const d = await apiPost('crypto_delete',{id});
  if(d.success) await Promise.all([loadSummary(),loadHoldings()]);
  else alert('Error: '+d.message);
};

// ── Transaction modal ─────────────────────────────────────────────────────────
window.cryptoOpenTxn = function(){
  const sel=document.getElementById('txnCoinSel');
  sel.innerHTML=holdingsData.map(h=>`<option value="${h.coin_id}">${h.symbol} — ${h.name}</option>`).join('');
  new bootstrap.Modal(document.getElementById('cryptoTxnModal')).show();
};

window.cryptoSaveTxn = async function(){
  const coinId=document.getElementById('txnCoinSel').value;
  const qty=document.getElementById('txnQty').value;
  if(!coinId||!qty){ alert('Coin and quantity required.'); return; }
  const d = await apiPost('crypto_txn_add',{
    coin_id:coinId, type:document.getElementById('txnType').value,
    quantity:qty, price_inr:document.getElementById('txnPrice').value,
    fee_inr:document.getElementById('txnFee').value,
    txn_date:document.getElementById('txnDate').value,
    exchange_name:document.getElementById('txnExchange').value,
    note:document.getElementById('txnNote').value,
  });
  if(d.success){
    bootstrap.Modal.getInstance(document.getElementById('cryptoTxnModal'))?.hide();
    await Promise.all([loadSummary(),loadHoldings(),loadTxns()]);
  } else alert('Error: '+d.message);
};

// ── Utils ─────────────────────────────────────────────────────────────────────
async function apiFetch(action){ const r=await fetch(`${API}?action=${action}`); return r.json(); }
async function apiPost(action,body){ const fd=new URLSearchParams({action,...body}); const r=await fetch(API,{method:'POST',body:fd}); return r.json(); }
function fmtM(n){ n=parseFloat(n||0); if(n>=1e7) return (n/1e7).toFixed(2)+' Cr'; if(n>=1e5) return (n/1e5).toFixed(2)+' L'; return n.toLocaleString('en-IN',{maximumFractionDigits:0}); }
function fmtN(n){ return parseFloat(n||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtQty(n){ n=parseFloat(n||0); return n>=1?n.toLocaleString('en-IN',{maximumFractionDigits:4}):n.toPrecision(6); }
function formatPct(n){ n=parseFloat(n||0); return (n>=0?'+':'')+n.toFixed(2)+'%'; }
function fmtDate(s){ if(!s) return '—'; return new Date(s).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'}); }
function fmtDateTime(d){ return d.toLocaleString('en-IN',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'}); }
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

init();
})();
</script>
<?php
$content = ob_get_clean();
require APP_ROOT . '/templates/layout.php';
