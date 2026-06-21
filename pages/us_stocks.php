<?php
/**
 * WealthDash — t456: US Stocks Portfolio UI Page
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser   = require_auth();
$pageTitle     = 'US Stocks';
$activePage    = 'us_stocks';
$activeSection = 'investments';

ob_start();
?>
<style>
.us-stat{background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:20px;text-align:center;}
.us-stat-label{font-size:11px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;}
.us-stat-value{font-size:20px;font-weight:700;}
.us-stat-sub{font-size:12px;color:var(--text-secondary);margin-top:2px;}
.tbl{width:100%;border-collapse:collapse;font-size:13px;}
.tbl th{background:var(--table-header-bg,rgba(0,0,0,.04));padding:10px 14px;text-align:left;font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-secondary);border-bottom:1px solid var(--border);white-space:nowrap;}
.tbl td{padding:11px 14px;border-bottom:1px solid var(--border);vertical-align:middle;}
.tbl tr:last-child td{border-bottom:none;}
.tbl tr:hover td{background:var(--hover-bg,rgba(0,0,0,.02));}
.tbl .num{text-align:right;}
.gain-pos{color:#16a34a;font-weight:600;}
.gain-neg{color:#dc2626;font-weight:600;}
.tab-btn{padding:8px 16px;border:none;background:none;cursor:pointer;font-size:13px;color:var(--text-secondary);border-bottom:2px solid transparent;transition:.15s;}
.tab-btn.active{color:var(--primary,#6366f1);border-bottom-color:var(--primary,#6366f1);font-weight:600;}
.lrs-bar{height:10px;border-radius:6px;background:var(--border);overflow:hidden;margin-top:6px;}
.lrs-fill{height:100%;border-radius:6px;transition:.4s;}
.btn-icon{background:none;border:1px solid var(--border);border-radius:6px;padding:4px 8px;cursor:pointer;color:var(--text-secondary);font-size:12px;}
.btn-icon:hover{background:var(--hover-bg);}
</style>

<div class="page-wrapper">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:24px 0 20px;border-bottom:1px solid var(--border);margin-bottom:24px;">
    <div>
      <h1 style="margin:0;font-size:24px;font-weight:700;">🇺🇸 US Stocks</h1>
      <p style="color:var(--text-secondary);margin:4px 0 0;font-size:13px;">NYSE · NASDAQ · LRS Tracking · Foreign Asset Declaration</p>
    </div>
    <div style="display:flex;gap:8px;">
      <button class="btn btn-ghost btn-sm" onclick="usRefresh()">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.12"/></svg>
        Refresh
      </button>
      <button class="btn btn-primary btn-sm" onclick="usOpenAdd()">＋ Add Stock</button>
    </div>
  </div>

  <!-- LRS Bar -->
  <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:16px 20px;margin-bottom:24px;" id="lrsCard">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
      <span style="font-weight:600;font-size:13px;">LRS Utilisation (RBI Limit: $250,000/year)</span>
      <span id="lrsStatus" style="font-size:12px;font-weight:600;"></span>
    </div>
    <div class="lrs-bar"><div class="lrs-fill" id="lrsFill" style="background:#6366f1;width:0%;"></div></div>
    <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-secondary);margin-top:6px;">
      <span>Remitted: <strong id="lrsUsed">—</strong></span>
      <span>Remaining: <strong id="lrsRemaining">—</strong></span>
      <span id="lrsRate" style="color:var(--text-secondary);"></span>
    </div>
  </div>

  <!-- Summary Cards -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px;margin-bottom:28px;">
    <div class="us-stat"><div class="us-stat-label">Stocks</div><div class="us-stat-value" id="sStocks">—</div></div>
    <div class="us-stat"><div class="us-stat-label">Invested (USD)</div><div class="us-stat-value" id="sInvUsd">—</div><div class="us-stat-sub" id="sInvInr">—</div></div>
    <div class="us-stat"><div class="us-stat-label">Current (USD)</div><div class="us-stat-value" id="sCurrUsd">—</div><div class="us-stat-sub" id="sCurrInr">—</div></div>
    <div class="us-stat">
      <div class="us-stat-label">P&amp;L (USD)</div>
      <div class="us-stat-value" id="sPnl">—</div>
      <div class="us-stat-sub" id="sPnlPct">—</div>
    </div>
  </div>

  <!-- Tabs -->
  <div style="display:flex;border-bottom:1px solid var(--border);margin-bottom:20px;">
    <button class="tab-btn active" id="tabHoldings"   onclick="usTab('holdings')">Holdings</button>
    <button class="tab-btn"        id="tabTxns"       onclick="usTab('txns')">Transactions</button>
    <button class="tab-btn"        id="tabTax"        onclick="usTab('tax')">Tax</button>
  </div>

  <!-- Holdings -->
  <div id="panelHoldings">
    <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;overflow:hidden;">
      <table class="tbl">
        <thead><tr>
          <th>Stock</th><th>Exchange</th><th class="num">Shares</th>
          <th class="num">Avg ($)</th><th class="num">CMP ($)</th>
          <th class="num">Value ($)</th><th class="num">Value (₹)</th>
          <th class="num">P&amp;L ($)</th><th class="num">Return</th><th>Actions</th>
        </tr></thead>
        <tbody id="holdingsTbody">
          <tr><td colspan="10" style="text-align:center;padding:36px;color:var(--text-secondary);">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Transactions -->
  <div id="panelTxns" style="display:none;">
    <div style="display:flex;justify-content:flex-end;margin-bottom:12px;">
      <button class="btn btn-sm btn-ghost" onclick="usOpenTxn()">＋ Log Transaction</button>
    </div>
    <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;overflow:hidden;">
      <table class="tbl">
        <thead><tr><th>Date</th><th>Stock</th><th>Type</th><th class="num">Shares</th><th class="num">Price ($)</th><th class="num">Total ($)</th><th class="num">Rate ₹/$</th><th>Broker</th></tr></thead>
        <tbody id="txnsTbody"><tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text-secondary);">Loading…</td></tr></tbody>
      </table>
    </div>
  </div>

  <!-- Tax -->
  <div id="panelTax" style="display:none;">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
      <label style="font-size:13px;font-weight:600;">Year:</label>
      <select class="form-select form-select-sm" style="width:130px;" id="taxYear" onchange="usLoadTax()">
        <?php for($y=(int)date('Y');$y>=(int)date('Y')-4;$y--): ?>
        <option value="<?=$y?>"><?=$y?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div id="taxPanel" style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:24px;">
      <p style="color:var(--text-secondary);text-align:center;">Loading…</p>
    </div>
  </div>
</div>

<!-- Add Stock Modal -->
<div class="modal fade" id="usAddModal" tabindex="-1">
  <div class="modal-dialog" style="max-width:520px;">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Add US Stock</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label">Search Symbol *</label>
          <input class="form-control form-control-sm" id="usSearch" placeholder="AAPL, TSLA, MSFT…" oninput="usSearchDebounce(this.value)">
          <div id="usSearchRes" style="border:1px solid var(--border);border-radius:6px;margin-top:4px;display:none;max-height:180px;overflow-y:auto;background:var(--card-bg);position:absolute;z-index:9999;width:calc(100% - 48px);"></div>
        </div>
        <div id="usSelBadge" style="display:none;margin-bottom:12px;padding:8px 12px;background:var(--hover-bg);border-radius:8px;font-size:13px;font-weight:600;"></div>
        <div class="row g-3">
          <div class="col-6"><label class="form-label">Symbol *</label><input class="form-control form-control-sm" id="usSymbol"></div>
          <div class="col-6"><label class="form-label">Exchange</label>
            <select class="form-select form-select-sm" id="usExchange"><option value="NASDAQ">NASDAQ</option><option value="NYSE">NYSE</option><option value="AMEX">AMEX</option></select>
          </div>
          <div class="col-4"><label class="form-label">Shares *</label><input type="number" class="form-control form-control-sm" id="usQty" step="any" min="0.000001"></div>
          <div class="col-4"><label class="form-label">Price (USD) *</label><input type="number" class="form-control form-control-sm" id="usPrice" step="any"></div>
          <div class="col-4"><label class="form-label">Buy Date</label><input type="date" class="form-control form-control-sm" id="usBuyDate" value="<?=date('Y-m-d')?>"></div>
          <div class="col-6"><label class="form-label">Broker</label>
            <select class="form-select form-select-sm" id="usBroker">
              <option value="">— Select —</option>
              <option value="Groww">Groww</option><option value="Vested">Vested</option>
              <option value="INDmoney">INDmoney</option><option value="Stockal">Stockal</option>
              <option value="ICICI Direct">ICICI Direct</option><option value="HDFC Securities">HDFC Securities</option>
              <option value="Interactive Brokers">Interactive Brokers</option><option value="Other">Other</option>
            </select>
          </div>
          <div class="col-6"><label class="form-label">Account Type</label>
            <select class="form-select form-select-sm" id="usAccType">
              <option value="LRS">LRS (Remittance)</option>
              <option value="GIFT_CITY">GIFT City</option>
              <option value="DOMESTIC_BROKER">Domestic Broker</option>
            </select>
          </div>
          <div class="col-12"><label class="form-label">Notes</label><input class="form-control form-control-sm" id="usNotes"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-primary" onclick="usSaveHolding()">Add Stock</button>
      </div>
    </div>
  </div>
</div>

<!-- Txn Modal -->
<div class="modal fade" id="usTxnModal" tabindex="-1">
  <div class="modal-dialog" style="max-width:440px;">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Log Transaction</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-6"><label class="form-label">Stock</label><select class="form-select form-select-sm" id="txnStockSel"></select></div>
          <div class="col-6"><label class="form-label">Type</label>
            <select class="form-select form-select-sm" id="txnType">
              <option value="buy">Buy</option><option value="sell">Sell</option><option value="dividend">Dividend</option>
            </select>
          </div>
          <div class="col-4"><label class="form-label">Shares *</label><input type="number" class="form-control form-control-sm" id="txnQty" step="any"></div>
          <div class="col-4"><label class="form-label">Price ($) *</label><input type="number" class="form-control form-control-sm" id="txnPrice" step="any"></div>
          <div class="col-4"><label class="form-label">Date</label><input type="date" class="form-control form-control-sm" id="txnDate" value="<?=date('Y-m-d')?>"></div>
          <div class="col-6"><label class="form-label">Broker</label><input class="form-control form-control-sm" id="txnBroker"></div>
          <div class="col-6"><label class="form-label">Account Type</label>
            <select class="form-select form-select-sm" id="txnAccType">
              <option value="LRS">LRS</option><option value="GIFT_CITY">GIFT City</option><option value="DOMESTIC_BROKER">Domestic</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-primary" onclick="usTxnSave()">Save</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
'use strict';
const API='<?=APP_URL?>/api/index.php';
let holdingsData=[];
let srchTimer=null;
let usdRate=84;

async function init(){
  await Promise.all([loadLrs(),loadSummary(),loadHoldings()]);
}

window.usTab=function(tab){
  ['holdings','txns','tax'].forEach(t=>{
    document.getElementById('panel'+t.charAt(0).toUpperCase()+t.slice(1)).style.display=t===tab?'':'none';
    document.getElementById('tab'+t.charAt(0).toUpperCase()+t.slice(1)).classList.toggle('active',t===tab);
  });
  if(tab==='txns') loadTxns();
  if(tab==='tax')  usLoadTax();
};

async function loadLrs(){
  try{
    const d=await get('us_lrs_status');
    if(!d.success) return;
    const pct=parseFloat(d.used_pct||0);
    document.getElementById('lrsFill').style.width=Math.min(100,pct)+'%';
    document.getElementById('lrsFill').style.background=pct>=100?'#dc2626':pct>=80?'#f59e0b':'#6366f1';
    document.getElementById('lrsUsed').textContent='$'+fmtN(d.remitted_usd)+' ('+pct+'%)';
    document.getElementById('lrsRemaining').textContent='$'+fmtN(d.remaining_usd);
    document.getElementById('lrsStatus').textContent={ok:'✅ Within limit',near_limit:'⚠️ Near limit',limit_reached:'🚨 Limit reached!'}[d.status]||'';
    document.getElementById('lrsStatus').style.color={ok:'#16a34a',near_limit:'#d97706',limit_reached:'#dc2626'}[d.status]||'';
  }catch(e){}
}

async function loadSummary(){
  try{
    const d=await get('us_summary');
    if(!d.success) return;
    usdRate=d.usd_inr_rate||84;
    document.getElementById('sStocks').textContent=d.summary.total_stocks||0;
    document.getElementById('sInvUsd').textContent='$'+fmtN(d.summary.total_invested_usd);
    document.getElementById('sInvInr').textContent='₹'+fmtM(d.summary.total_invested_inr);
    document.getElementById('sCurrUsd').textContent='$'+fmtN(d.summary.total_current_usd);
    document.getElementById('sCurrInr').textContent='₹'+fmtM(d.summary.total_current_inr);
    const gain=parseFloat(d.gain_loss_usd||0);
    const pEl=document.getElementById('sPnl');
    pEl.textContent=(gain>=0?'+':'')+'$'+fmtN(Math.abs(gain));
    pEl.className='us-stat-value '+(gain>=0?'gain-pos':'gain-neg');
    document.getElementById('sPnlPct').textContent=(gain>=0?'+':'')+d.gain_pct+'%';
    document.getElementById('lrsRate').textContent='₹/$: '+parseFloat(usdRate).toFixed(2);
  }catch(e){}
}

async function loadHoldings(){
  try{
    const d=await get('us_list');
    const tbody=document.getElementById('holdingsTbody');
    if(!d.success||!d.data.length){
      tbody.innerHTML='<tr><td colspan="10" style="text-align:center;padding:40px;color:var(--text-secondary);"><p style="margin:0;font-size:15px;font-weight:500;">No US stock holdings yet</p><p style="font-size:13px;margin:4px 0 0;">Click <strong>+ Add Stock</strong> to begin.</p></td></tr>';
      return;
    }
    holdingsData=d.data;
    const tbody2=document.getElementById('holdingsTbody');
    tbody2.innerHTML=d.data.map(h=>{
      const gain=parseFloat(h.gain_loss_usd||0);
      const gainPct=parseFloat(h.gain_pct||0);
      const chg=parseFloat(h.price_change_24h_pct||0);
      return `<tr>
        <td>
          <div style="font-weight:700;">${esc(h.symbol)}</div>
          <div style="font-size:11px;color:var(--text-secondary);">${esc(h.company_name||'')}</div>
          ${h.sector?`<div style="font-size:10px;color:var(--text-secondary);">${esc(h.sector)}</div>`:''}
        </td>
        <td style="font-size:12px;">${esc(h.exchange)}</td>
        <td class="num" style="font-weight:600;">${fmtQty(h.quantity)}</td>
        <td class="num">$${fmtN(h.avg_buy_price_usd)}</td>
        <td class="num">
          <span style="font-weight:600;">$${fmtN(h.latest_price_usd)}</span>
          ${chg!==0?`<div style="font-size:11px;" class="${chg>=0?'gain-pos':'gain-neg'}">${chg>=0?'+':''}${chg.toFixed(2)}%</div>`:''}
        </td>
        <td class="num" style="font-weight:700;">$${fmtN(h.current_value_usd)}</td>
        <td class="num">₹${fmtM(h.current_value_inr)}</td>
        <td class="num ${gain>=0?'gain-pos':'gain-neg'}">${gain>=0?'+':''}$${fmtN(Math.abs(gain))}</td>
        <td class="num ${gainPct>=0?'gain-pos':'gain-neg'}">${gainPct>=0?'+':''}${gainPct}%</td>
        <td>
          <button class="btn-icon" onclick="usDelete(${h.id},'${esc(h.symbol)}')" title="Remove">🗑️</button>
        </td>
      </tr>`;
    }).join('');
  }catch(e){}
}

async function loadTxns(){
  try{
    const d=await get('us_txns');
    const tbody=document.getElementById('txnsTbody');
    if(!d.success||!d.data.length){tbody.innerHTML='<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text-secondary);">No transactions.</td></tr>';return;}
    const tc={buy:'gain-pos',sell:'gain-neg',dividend:'gain-pos'};
    tbody.innerHTML=d.data.map(t=>`<tr>
      <td>${fmtDate(t.txn_date)}</td>
      <td><strong>${esc(t.symbol)}</strong></td>
      <td><span class="${tc[t.type]||''}">${t.type.toUpperCase()}</span></td>
      <td class="num">${fmtQty(t.quantity)}</td>
      <td class="num">$${fmtN(t.price_usd)}</td>
      <td class="num" style="font-weight:600;">$${fmtN(t.total_usd)}</td>
      <td class="num">${t.usd_inr_rate?parseFloat(t.usd_inr_rate).toFixed(2):'—'}</td>
      <td>${esc(t.broker||'—')}</td>
    </tr>`).join('');
  }catch(e){}
}

window.usLoadTax=async function(){
  const year=document.getElementById('taxYear').value;
  const d=await get('us_tax_report&year='+year);
  const panel=document.getElementById('taxPanel');
  if(!d.success){panel.innerHTML='<p style="color:var(--text-secondary);text-align:center;">No data.</p>';return;}
  panel.innerHTML=`
    <h6 style="font-weight:700;margin-bottom:16px;">US Stock Tax — FY ${year}</h6>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px;margin-bottom:20px;">
      <div class="us-stat"><div class="us-stat-label">Sale Proceeds ($)</div><div class="us-stat-value">$${fmtN(d.total_sale_usd)}</div><div class="us-stat-sub">₹${fmtM(d.total_sale_inr)}</div></div>
      <div class="us-stat"><div class="us-stat-label">Dividends ($)</div><div class="us-stat-value">$${fmtN(d.total_dividend_usd)}</div><div class="us-stat-sub">₹${fmtM(d.total_dividend_inr)}</div></div>
    </div>
    <div style="background:rgba(99,102,241,.06);border:1px solid rgba(99,102,241,.15);border-radius:10px;padding:14px;font-size:13px;margin-bottom:16px;">
      <strong>🇮🇳 Indian Tax on US Stocks:</strong>
      <ul style="margin:8px 0 0;padding-left:18px;line-height:2;">
        <li><strong>Capital Gains:</strong> ${d.tax_notes.capital_gains}</li>
        <li><strong>Dividends:</strong> ${d.tax_notes.dividends}</li>
        <li><strong>Foreign Assets:</strong> ${d.tax_notes.foreign_assets}</li>
        <li><strong>LRS/TCS:</strong> ${d.tax_notes.lrs}</li>
      </ul>
    </div>
  `;
};

window.usRefresh=async function(){
  const d=await post('us_prices_refresh',{});
  if(d.success) await Promise.all([loadSummary(),loadHoldings()]);
  else alert('Error: '+d.message);
};

window.usOpenAdd=function(){
  ['usSymbol','usQty','usPrice','usNotes'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('usBuyDate').value=new Date().toISOString().split('T')[0];
  document.getElementById('usSearch').value='';
  document.getElementById('usSearchRes').style.display='none';
  document.getElementById('usSelBadge').style.display='none';
  new bootstrap.Modal(document.getElementById('usAddModal')).show();
};

window.usSearchDebounce=function(q){
  clearTimeout(srchTimer);
  const res=document.getElementById('usSearchRes');
  if(q.length<1){res.style.display='none';return;}
  srchTimer=setTimeout(async()=>{
    const d=await get('us_search&q='+encodeURIComponent(q));
    if(!d.success) return;
    const items=[...d.local,...d.yahoo].slice(0,10);
    if(!items.length){res.style.display='none';return;}
    res.innerHTML=items.map(c=>`<div onclick="usSelectStock('${esc(c.symbol||c.ticker||'')}','${esc(c.company_name||c.longname||c.shortname||'')}')"
      style="padding:10px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--border);"
      onmouseover="this.style.background='var(--hover-bg)'" onmouseout="this.style.background=''">
      <strong>${esc(c.symbol||c.ticker||'')}</strong> <span style="color:var(--text-secondary);">${esc(c.company_name||c.longname||c.shortname||'')}</span>
      ${c.exchange?`<span style="font-size:10px;color:var(--text-secondary);margin-left:6px;">${esc(c.exchange)}</span>`:''}
    </div>`).join('');
    res.style.display='block';
  },350);
};

window.usSelectStock=function(symbol,name){
  document.getElementById('usSymbol').value=symbol;
  document.getElementById('usSearch').value=symbol+(name?' — '+name:'');
  document.getElementById('usSearchRes').style.display='none';
  const b=document.getElementById('usSelBadge');
  b.textContent='Selected: '+symbol+(name?' — '+name:'');
  b.style.display='block';
  // Prefill price
  get('us_fundamentals&symbol='+symbol).then(d=>{
    if(d&&d.latest_price_usd) document.getElementById('usPrice').value=d.latest_price_usd;
  }).catch(()=>{});
};

window.usSaveHolding=async function(){
  const sym=document.getElementById('usSymbol').value.trim();
  const qty=document.getElementById('usQty').value;
  const price=document.getElementById('usPrice').value;
  if(!sym||!qty||!price){alert('Symbol, shares, and price required.');return;}
  const d=await post('us_add',{symbol:sym,exchange:document.getElementById('usExchange').value,
    quantity:qty,price_usd:price,buy_date:document.getElementById('usBuyDate').value,
    broker:document.getElementById('usBroker').value,
    account_type:document.getElementById('usAccType').value,
    notes:document.getElementById('usNotes').value});
  if(d.success){
    bootstrap.Modal.getInstance(document.getElementById('usAddModal'))?.hide();
    await Promise.all([loadLrs(),loadSummary(),loadHoldings()]);
  } else alert('Error: '+d.message);
};

window.usDelete=async function(id,sym){
  if(!confirm('Remove '+sym+'?')) return;
  const d=await post('us_delete',{id});
  if(d.success) await Promise.all([loadSummary(),loadHoldings()]);
};

window.usOpenTxn=function(){
  const sel=document.getElementById('txnStockSel');
  sel.innerHTML=holdingsData.map(h=>`<option value="${h.stock_id}">${h.symbol} — ${h.company_name||''}</option>`).join('');
  document.getElementById('txnDate').value=new Date().toISOString().split('T')[0];
  new bootstrap.Modal(document.getElementById('usTxnModal')).show();
};

window.usTxnSave=async function(){
  const stockId=document.getElementById('txnStockSel').value;
  const qty=document.getElementById('txnQty').value;
  const price=document.getElementById('txnPrice').value;
  if(!stockId||!qty||!price){alert('Stock, shares, and price required.');return;}
  const d=await post('us_txn_add',{stock_id:stockId,type:document.getElementById('txnType').value,
    quantity:qty,price_usd:price,txn_date:document.getElementById('txnDate').value,
    broker:document.getElementById('txnBroker').value,
    account_type:document.getElementById('txnAccType').value});
  if(d.success){
    bootstrap.Modal.getInstance(document.getElementById('usTxnModal'))?.hide();
    await Promise.all([loadSummary(),loadHoldings(),loadTxns()]);
  } else alert(d.message);
};

async function get(action){const r=await fetch(`${API}?action=${action}`);return r.json();}
async function post(action,body){const fd=new URLSearchParams({action,...body});const r=await fetch(API,{method:'POST',body:fd});return r.json();}
function fmtM(n){n=parseFloat(n||0);if(n>=1e7)return(n/1e7).toFixed(2)+' Cr';if(n>=1e5)return(n/1e5).toFixed(2)+' L';return n.toLocaleString('en-IN',{maximumFractionDigits:0});}
function fmtN(n){return parseFloat(n||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});}
function fmtQty(n){return parseFloat(n||0).toLocaleString('en-IN',{maximumFractionDigits:6});}
function fmtDate(s){if(!s)return'—';return new Date(s).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

init();
})();
</script>
<?php
$content=ob_get_clean();
require APP_ROOT.'/templates/layout.php';
