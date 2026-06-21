<?php
/**
 * WealthDash — t37: ETF Module UI Page
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser   = require_auth();
$pageTitle     = 'ETF Portfolio';
$activePage    = 'etf';
$activeSection = 'investments';

ob_start();
?>
<style>
.etf-stat{background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:20px;text-align:center;}
.etf-stat-label{font-size:11px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;}
.etf-stat-value{font-size:22px;font-weight:700;}
.etf-stat-sub{font-size:12px;color:var(--text-secondary);margin-top:2px;}
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
.cat-badge{display:inline-flex;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;}
.cat-equity{background:rgba(99,102,241,.12);color:#6366f1;}
.cat-debt{background:rgba(59,130,246,.12);color:#2563eb;}
.cat-gold{background:rgba(245,158,11,.12);color:#d97706;}
.cat-intl{background:rgba(16,185,129,.12);color:#059669;}
.cat-commodity{background:rgba(239,68,68,.12);color:#dc2626;}
.btn-icon{background:none;border:1px solid var(--border);border-radius:6px;padding:4px 8px;cursor:pointer;color:var(--text-secondary);font-size:12px;transition:.15s;}
.btn-icon:hover{background:var(--hover-bg);color:var(--text-primary);}
.etf-empty{text-align:center;padding:60px 24px;color:var(--text-secondary);}
.sip-card{background:var(--card-bg);border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:12px;}
</style>

<div class="page-wrapper">
  <div style="display:flex;align-items:center;justify-content:space-between;padding:24px 0 20px;border-bottom:1px solid var(--border);margin-bottom:24px;">
    <div>
      <h1 style="margin:0;font-size:24px;font-weight:700;">📊 ETF Portfolio</h1>
      <p style="color:var(--text-secondary);margin:4px 0 0;font-size:13px;">Exchange Traded Funds — Holdings, SIPs, P&L &amp; Tax</p>
    </div>
    <div style="display:flex;gap:8px;">
      <button class="btn btn-ghost btn-sm" onclick="etfRefreshPrices()">
        <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.12"/></svg>
        Refresh
      </button>
      <button class="btn btn-primary btn-sm" onclick="etfOpenAdd()">＋ Add ETF</button>
    </div>
  </div>

  <!-- Summary -->
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px;margin-bottom:28px;" id="etfSummaryGrid">
    <div class="etf-stat"><div class="etf-stat-label">ETFs</div><div class="etf-stat-value" id="sETFs">—</div></div>
    <div class="etf-stat"><div class="etf-stat-label">Invested</div><div class="etf-stat-value" id="sInvested">—</div></div>
    <div class="etf-stat"><div class="etf-stat-label">Current Value</div><div class="etf-stat-value" id="sValue">—</div></div>
    <div class="etf-stat">
      <div class="etf-stat-label">P&amp;L</div>
      <div class="etf-stat-value" id="sPnl">—</div>
      <div class="etf-stat-sub" id="sPnlPct">—</div>
    </div>
    <div class="etf-stat"><div class="etf-stat-label">Active SIPs</div><div class="etf-stat-value" id="sSips">—</div><div class="etf-stat-sub" id="sSipAmt">—/mo</div></div>
  </div>

  <!-- Tabs -->
  <div style="display:flex;border-bottom:1px solid var(--border);margin-bottom:20px;">
    <button class="tab-btn active" id="tabHoldings"  onclick="etfTab('holdings')">Holdings</button>
    <button class="tab-btn"        id="tabSip"       onclick="etfTab('sip')">SIPs</button>
    <button class="tab-btn"        id="tabTxns"      onclick="etfTab('txns')">Transactions</button>
    <button class="tab-btn"        id="tabTax"       onclick="etfTab('tax')">Tax</button>
  </div>

  <!-- Holdings -->
  <div id="panelHoldings">
    <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;overflow:hidden;">
      <table class="tbl" id="holdingsTbl">
        <thead><tr>
          <th>ETF</th><th>Category</th><th class="num">Units</th>
          <th class="num">Avg Price</th><th class="num">CMP</th>
          <th class="num">Invested</th><th class="num">Current</th>
          <th class="num">P&amp;L</th><th class="num">Return</th><th>Actions</th>
        </tr></thead>
        <tbody id="holdingsTbody">
          <tr><td colspan="10" style="text-align:center;padding:24px;color:var(--text-secondary);">Loading…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- SIPs -->
  <div id="panelSip" style="display:none;">
    <div style="display:flex;justify-content:flex-end;margin-bottom:12px;">
      <button class="btn btn-sm btn-ghost" onclick="etfOpenSipAdd()">＋ New SIP</button>
    </div>
    <div id="sipList"><p style="color:var(--text-secondary);text-align:center;">Loading…</p></div>
  </div>

  <!-- Transactions -->
  <div id="panelTxns" style="display:none;">
    <div style="display:flex;justify-content:flex-end;margin-bottom:12px;">
      <button class="btn btn-sm btn-ghost" onclick="etfOpenTxnAdd()">＋ Log Transaction</button>
    </div>
    <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;overflow:hidden;">
      <table class="tbl">
        <thead><tr><th>Date</th><th>ETF</th><th>Type</th><th class="num">Units</th><th class="num">Price</th><th class="num">Total</th><th>Broker</th></tr></thead>
        <tbody id="txnsTbody"><tr><td colspan="7" style="text-align:center;padding:24px;color:var(--text-secondary);">Loading…</td></tr></tbody>
      </table>
    </div>
  </div>

  <!-- Tax -->
  <div id="panelTax" style="display:none;">
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
      <label style="font-size:13px;font-weight:600;">Year:</label>
      <select class="form-select form-select-sm" style="width:130px;" id="taxYear" onchange="etfLoadTax()">
        <?php for ($y=(int)date('Y');$y>=(int)date('Y')-4;$y--): ?>
        <option value="<?=$y?>"><?=$y?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div id="taxPanel" style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:24px;">
      <p style="color:var(--text-secondary);text-align:center;">Loading…</p>
    </div>
  </div>
</div>

<!-- Add ETF Modal -->
<div class="modal fade" id="etfAddModal" tabindex="-1">
  <div class="modal-dialog" style="max-width:540px;">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="etfAddTitle">Add ETF Holding</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label">Search ETF *</label>
          <input class="form-control form-control-sm" id="etfSearch" placeholder="NIFTYBEES, GOLDBEES…" oninput="etfSearchDebounce(this.value)">
          <div id="etfSearchResults" style="border:1px solid var(--border);border-radius:6px;margin-top:4px;display:none;max-height:200px;overflow-y:auto;background:var(--card-bg);position:absolute;z-index:9999;width:calc(100% - 48px);"></div>
        </div>
        <input type="hidden" id="addEtfId">
        <div id="selEtfBadge" style="display:none;margin-bottom:12px;padding:8px 12px;background:var(--hover-bg);border-radius:8px;font-size:13px;font-weight:600;"></div>
        <div class="row g-3">
          <div class="col-6"><label class="form-label">Symbol *</label><input class="form-control form-control-sm" id="addSymbol"></div>
          <div class="col-6"><label class="form-label">Exchange</label>
            <select class="form-select form-select-sm" id="addExchange"><option value="NSE">NSE</option><option value="BSE">BSE</option></select>
          </div>
          <div class="col-6"><label class="form-label">Category</label>
            <select class="form-select form-select-sm" id="addCategory">
              <option value="Equity">Equity</option><option value="Debt">Debt</option>
              <option value="Gold">Gold</option><option value="International">International</option>
              <option value="Commodity">Commodity</option>
            </select>
          </div>
          <div class="col-6"><label class="form-label">AMC</label><input class="form-control form-control-sm" id="addAmc" placeholder="Nippon, SBI…"></div>
          <div class="col-4"><label class="form-label">Units *</label><input type="number" class="form-control form-control-sm" id="addQty" min="0.0001" step="any"></div>
          <div class="col-4"><label class="form-label">Buy Price (₹) *</label><input type="number" class="form-control form-control-sm" id="addPrice" step="any"></div>
          <div class="col-4"><label class="form-label">Buy Date</label><input type="date" class="form-control form-control-sm" id="addDate" value="<?=date('Y-m-d')?>"></div>
          <div class="col-6"><label class="form-label">Broker</label><input class="form-control form-control-sm" id="addBroker" placeholder="Zerodha, Groww…"></div>
          <div class="col-6"><label class="form-label">Expense Ratio (%)</label><input type="number" class="form-control form-control-sm" id="addExpense" step="0.001" placeholder="0.05"></div>
          <div class="col-12"><label class="form-label">Notes</label><input class="form-control form-control-sm" id="addNotes"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-primary" onclick="etfSave()">Add ETF</button>
      </div>
    </div>
  </div>
</div>

<!-- SIP Add Modal -->
<div class="modal fade" id="etfSipModal" tabindex="-1">
  <div class="modal-dialog" style="max-width:440px;">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Create ETF SIP</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-12">
            <label class="form-label">ETF</label>
            <select class="form-select form-select-sm" id="sipEtfSel"></select>
          </div>
          <div class="col-6"><label class="form-label">Monthly Amount (₹) *</label><input type="number" class="form-control form-control-sm" id="sipAmount" min="100"></div>
          <div class="col-6"><label class="form-label">SIP Date (1-28)</label><input type="number" class="form-control form-control-sm" id="sipDay" min="1" max="28" value="1"></div>
          <div class="col-6"><label class="form-label">Start Date</label><input type="date" class="form-control form-control-sm" id="sipStart" value="<?=date('Y-m-d')?>"></div>
          <div class="col-6"><label class="form-label">Broker</label><input class="form-control form-control-sm" id="sipBroker"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-primary" onclick="etfSipSave()">Create SIP</button>
      </div>
    </div>
  </div>
</div>

<!-- Txn Add Modal -->
<div class="modal fade" id="etfTxnModal" tabindex="-1">
  <div class="modal-dialog" style="max-width:440px;">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Log Transaction</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-6"><label class="form-label">ETF</label><select class="form-select form-select-sm" id="txnEtfSel"></select></div>
          <div class="col-6"><label class="form-label">Type</label>
            <select class="form-select form-select-sm" id="txnType">
              <option value="buy">Buy</option><option value="sell">Sell</option><option value="dividend">Dividend</option>
            </select>
          </div>
          <div class="col-4"><label class="form-label">Units *</label><input type="number" class="form-control form-control-sm" id="txnQty" step="any"></div>
          <div class="col-4"><label class="form-label">Price (₹) *</label><input type="number" class="form-control form-control-sm" id="txnPrice" step="any"></div>
          <div class="col-4"><label class="form-label">Brokerage</label><input type="number" class="form-control form-control-sm" id="txnBrokerage" value="0" step="any"></div>
          <div class="col-6"><label class="form-label">Date *</label><input type="date" class="form-control form-control-sm" id="txnDate" value="<?=date('Y-m-d')?>"></div>
          <div class="col-6"><label class="form-label">Broker</label><input class="form-control form-control-sm" id="txnBroker"></div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm btn-primary" onclick="etfTxnSave()">Save</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
'use strict';
const API='<?=APP_URL?>/api/index.php';
let holdingsData=[];
let searchTimer=null;

async function init(){
  await Promise.all([loadSummary(),loadHoldings()]);
}

// ── Tab ───────────────────────────────────────────────────────────────────────
window.etfTab=function(tab){
  ['holdings','sip','txns','tax'].forEach(t=>{
    document.getElementById('panel'+cap(t)).style.display=t===tab?'':'none';
    document.getElementById('tab'+cap(t)).classList.toggle('active',t===tab);
  });
  if(tab==='sip')  loadSips();
  if(tab==='txns') loadTxns();
  if(tab==='tax')  etfLoadTax();
};
function cap(s){return s.charAt(0).toUpperCase()+s.slice(1);}

// ── Summary ───────────────────────────────────────────────────────────────────
async function loadSummary(){
  try{
    const d=await get('etf_summary');
    if(!d.success) return;
    const s=d.summary;
    document.getElementById('sETFs').textContent=s.total_etfs||0;
    document.getElementById('sInvested').textContent='₹'+fmtM(s.total_invested);
    document.getElementById('sValue').textContent='₹'+fmtM(s.total_current_value);
    const gain=parseFloat(s.total_gain_loss||0);
    const pEl=document.getElementById('sPnl');
    pEl.textContent=(gain>=0?'+':'')+'₹'+fmtM(Math.abs(gain));
    pEl.className='etf-stat-value '+(gain>=0?'gain-pos':'gain-neg');
    document.getElementById('sPnlPct').textContent=(gain>=0?'+':'')+s.gain_pct+'%';
    document.getElementById('sSips').textContent=d.active_sips?.count||0;
    document.getElementById('sSipAmt').textContent='₹'+fmtM(d.active_sips?.monthly_total||0)+'/mo';
  }catch(e){}
}

// ── Holdings ──────────────────────────────────────────────────────────────────
async function loadHoldings(){
  try{
    const d=await get('etf_list');
    const tbody=document.getElementById('holdingsTbody');
    if(!d.success||!d.data.length){
      tbody.innerHTML='<tr><td colspan="10" class="etf-empty"><p style="margin:0;font-size:15px;font-weight:500;">No ETF holdings yet</p><p style="font-size:13px;color:var(--text-secondary);margin:4px 0 0;">Click <strong>+ Add ETF</strong> to start.</p></td></tr>';
      return;
    }
    holdingsData=d.data;
    const catClass={Equity:'cat-equity',Debt:'cat-debt',Gold:'cat-gold',International:'cat-intl',Commodity:'cat-commodity'};
    tbody.innerHTML=d.data.map(h=>{
      const gain=parseFloat(h.gain_loss||0);
      const gainPct=parseFloat(h.gain_pct||0);
      return `<tr>
        <td>
          <div style="font-weight:700;font-size:13px;">${esc(h.symbol)}</div>
          <div style="font-size:11px;color:var(--text-secondary);max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(h.scheme_name)}</div>
          ${h.expense_ratio?`<div style="font-size:10px;color:var(--text-secondary);">TER: ${h.expense_ratio}%</div>`:''}
        </td>
        <td><span class="cat-badge ${catClass[h.category]||'cat-equity'}">${esc(h.category||'Equity')}</span></td>
        <td class="num">${fmtQty(h.quantity)}</td>
        <td class="num">₹${fmtN(h.avg_buy_price)}</td>
        <td class="num">
          <span style="font-weight:600;">₹${fmtN(h.latest_price)}</span>
          ${h.price_change_1d_pct!=null?`<div style="font-size:11px;" class="${parseFloat(h.price_change_1d_pct)>=0?'gain-pos':'gain-neg'}">${parseFloat(h.price_change_1d_pct)>=0?'+':''}${parseFloat(h.price_change_1d_pct).toFixed(2)}%</div>`:''}
        </td>
        <td class="num">₹${fmtM(h.total_invested)}</td>
        <td class="num" style="font-weight:600;">₹${fmtM(h.current_value)}</td>
        <td class="num ${gain>=0?'gain-pos':'gain-neg'}">${gain>=0?'+':''}₹${fmtM(Math.abs(gain))}</td>
        <td class="num ${gainPct>=0?'gain-pos':'gain-neg'}">${gainPct>=0?'+':''}${gainPct}%</td>
        <td>
          <button class="btn-icon" onclick="etfDeleteHolding(${h.id},'${esc(h.symbol)}')" title="Remove">🗑️</button>
          <button class="btn-icon" onclick="etfOpenTxnForHolding(${h.etf_id})" title="Log txn">＋</button>
        </td>
      </tr>`;
    }).join('');
  }catch(e){}
}

// ── SIPs ─────────────────────────────────────────────────────────────────────
async function loadSips(){
  try{
    const d=await get('etf_sip_list');
    const el=document.getElementById('sipList');
    if(!d.success||!d.data.length){
      el.innerHTML='<div class="etf-empty"><p style="margin:0;font-weight:500;">No SIPs set up yet.</p><p style="font-size:13px;color:var(--text-secondary);">Click <strong>+ New SIP</strong> to automate monthly investments.</p></div>';
      return;
    }
    el.innerHTML=d.data.map(s=>`
      <div class="sip-card" style="${!s.is_active?'opacity:.6;':''}" >
        <div style="display:flex;align-items:center;justify-content:space-between;">
          <div>
            <span style="font-weight:700;">${esc(s.symbol)}</span>
            <span style="font-size:12px;color:var(--text-secondary);margin-left:6px;">${esc(s.scheme_name)}</span>
            ${s.is_active?'<span style="margin-left:8px;padding:1px 8px;background:rgba(34,197,94,.12);color:#16a34a;border-radius:20px;font-size:10px;font-weight:700;">ACTIVE</span>':'<span style="margin-left:8px;padding:1px 8px;background:var(--border);color:var(--text-secondary);border-radius:20px;font-size:10px;">PAUSED</span>'}
          </div>
          <div style="display:flex;align-items:center;gap:8px;">
            <button class="btn-icon" onclick="etfToggleSip(${s.id},${s.is_active?0:1})">${s.is_active?'⏸':'▶'}</button>
            <button class="btn-icon" onclick="etfDeleteSip(${s.id})">🗑️</button>
          </div>
        </div>
        <div style="display:flex;gap:24px;margin-top:10px;font-size:13px;">
          <div><span style="color:var(--text-secondary);">Amount:</span> <strong>₹${fmtM(s.monthly_amount)}/mo</strong></div>
          <div><span style="color:var(--text-secondary);">Date:</span> ${s.sip_date}th every month</div>
          <div><span style="color:var(--text-secondary);">Since:</span> ${fmtDate(s.start_date)}</div>
          ${s.broker?`<div><span style="color:var(--text-secondary);">Broker:</span> ${esc(s.broker)}</div>`:''}
          ${s.installments?`<div><span style="color:var(--text-secondary);">Installments:</span> ${s.installments}</div>`:''}
          ${s.total_invested>0?`<div><span style="color:var(--text-secondary);">Total:</span> ₹${fmtM(s.total_invested)}</div>`:''}
        </div>
      </div>`).join('');
  }catch(e){}
}

// ── Transactions ──────────────────────────────────────────────────────────────
async function loadTxns(){
  try{
    const d=await get('etf_txns');
    const tbody=document.getElementById('txnsTbody');
    if(!d.success||!d.data.length){
      tbody.innerHTML='<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--text-secondary);">No transactions yet.</td></tr>';
      return;
    }
    const typeColor={buy:'gain-pos',sell:'gain-neg',dividend:'gain-pos'};
    tbody.innerHTML=d.data.map(t=>`<tr>
      <td>${fmtDate(t.txn_date)}</td>
      <td><strong>${esc(t.symbol)}</strong><div style="font-size:11px;color:var(--text-secondary);">${esc(t.scheme_name)}</div></td>
      <td><span class="${typeColor[t.type]||''}">${t.type.toUpperCase()}</span></td>
      <td class="num">${fmtQty(t.quantity)}</td>
      <td class="num">₹${fmtN(t.price)}</td>
      <td class="num" style="font-weight:600;">₹${fmtM(t.total_value)}</td>
      <td>${esc(t.broker||'—')}</td>
    </tr>`).join('');
  }catch(e){}
}

// ── Tax ───────────────────────────────────────────────────────────────────────
window.etfLoadTax=async function(){
  const year=document.getElementById('taxYear').value;
  const d=await get('etf_tax_report&year='+year);
  const panel=document.getElementById('taxPanel');
  if(!d.success){panel.innerHTML='<p style="color:var(--text-secondary);text-align:center;">No data.</p>';return;}
  panel.innerHTML=`
    <h6 style="font-weight:700;margin-bottom:16px;">ETF Tax Summary — FY ${year}</h6>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:16px;margin-bottom:20px;">
      <div class="etf-stat"><div class="etf-stat-label">Total Sells</div><div class="etf-stat-value">${d.sells.length}</div></div>
      <div class="etf-stat"><div class="etf-stat-label">Equity Proceeds</div><div class="etf-stat-value">₹${fmtM(d.equity_gain)}</div></div>
      <div class="etf-stat"><div class="etf-stat-label">Gold Proceeds</div><div class="etf-stat-value">₹${fmtM(d.gold_gain)}</div></div>
      <div class="etf-stat"><div class="etf-stat-label">Debt Proceeds</div><div class="etf-stat-value">₹${fmtM(d.debt_gain)}</div></div>
    </div>
    <div style="background:rgba(99,102,241,.06);border:1px solid rgba(99,102,241,.15);border-radius:10px;padding:14px;font-size:13px;margin-bottom:16px;">
      <strong>Tax Treatment Guide:</strong>
      <ul style="margin:8px 0 0;padding-left:18px;line-height:2;">
        <li><strong>Equity ETFs:</strong> ${d.tax_notes.equity}</li>
        <li><strong>Gold ETFs:</strong> ${d.tax_notes.gold}</li>
        <li><strong>Debt ETFs:</strong> ${d.tax_notes.debt}</li>
        <li><strong>International ETFs:</strong> ${d.tax_notes.intl}</li>
      </ul>
    </div>
    ${d.sells.length?`
    <table class="tbl"><thead><tr><th>Date</th><th>ETF</th><th>Category</th><th class="num">Units</th><th class="num">Proceeds</th></tr></thead>
    <tbody>${d.sells.map(s=>`<tr><td>${fmtDate(s.txn_date)}</td><td>${esc(s.symbol)}</td><td>${esc(s.category)}</td><td class="num">${fmtQty(s.quantity)}</td><td class="num">₹${fmtM(s.total_value)}</td></tr>`).join('')}</tbody></table>`:
    '<p style="color:var(--text-secondary);text-align:center;margin:0;">No sell transactions in this year.</p>'}
  `;
};

// ── Refresh ───────────────────────────────────────────────────────────────────
window.etfRefreshPrices=async function(){
  const d=await post('etf_prices_refresh',{});
  if(d.success) await Promise.all([loadSummary(),loadHoldings()]);
  else alert('Error: '+d.message);
};

// ── Add Modal ─────────────────────────────────────────────────────────────────
window.etfOpenAdd=function(){
  ['addEtfId','addSymbol','addQty','addPrice','addBroker','addExpense','addNotes','addAmc'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('addDate').value=new Date().toISOString().split('T')[0];
  document.getElementById('selEtfBadge').style.display='none';
  document.getElementById('etfSearch').value='';
  document.getElementById('etfSearchResults').style.display='none';
  new bootstrap.Modal(document.getElementById('etfAddModal')).show();
};

window.etfSearchDebounce=function(q){
  clearTimeout(searchTimer);
  const res=document.getElementById('etfSearchResults');
  if(q.length<1){res.style.display='none';return;}
  searchTimer=setTimeout(async()=>{
    const d=await get('etf_search&q='+encodeURIComponent(q));
    if(!d.success) return;
    const items=[...d.local,...d.popular].slice(0,10);
    if(!items.length){res.style.display='none';return;}
    res.innerHTML=items.map(c=>`<div onclick="etfSelectResult('${esc(c.symbol||'')}','${esc(c.scheme_name||c.shortName||'')}','${esc(c.category||'')}','${esc(c.amc||'')}')"
      style="padding:10px 14px;cursor:pointer;font-size:13px;border-bottom:1px solid var(--border);"
      onmouseover="this.style.background='var(--hover-bg)'" onmouseout="this.style.background=''">
      <strong>${esc(c.symbol||'')}</strong> <span style="color:var(--text-secondary);">${esc(c.scheme_name||c.shortName||'')}</span>
      ${c.category?`<span style="margin-left:6px;font-size:10px;color:var(--text-secondary);">${esc(c.category)}</span>`:''}
    </div>`).join('');
    res.style.display='block';
  },350);
};

window.etfSelectResult=function(symbol,name,cat,amc){
  document.getElementById('addSymbol').value=symbol;
  if(cat) document.getElementById('addCategory').value=cat;
  if(amc) document.getElementById('addAmc').value=amc;
  document.getElementById('etfSearch').value=symbol+' — '+name;
  document.getElementById('etfSearchResults').style.display='none';
  const badge=document.getElementById('selEtfBadge');
  badge.textContent='Selected: '+symbol+(name?' — '+name:'');
  badge.style.display='block';
};

window.etfSave=async function(){
  const symbol=document.getElementById('addSymbol').value.trim();
  const qty=document.getElementById('addQty').value;
  const price=document.getElementById('addPrice').value;
  if(!symbol||!qty||!price){alert('Symbol, units, and price are required.');return;}
  const d=await post('etf_add',{
    symbol, exchange:document.getElementById('addExchange').value,
    category:document.getElementById('addCategory').value,
    amc:document.getElementById('addAmc').value,
    quantity:qty, price, buy_date:document.getElementById('addDate').value,
    broker:document.getElementById('addBroker').value,
    expense_ratio:document.getElementById('addExpense').value,
    notes:document.getElementById('addNotes').value,
  });
  if(d.success){
    bootstrap.Modal.getInstance(document.getElementById('etfAddModal'))?.hide();
    await Promise.all([loadSummary(),loadHoldings()]);
  } else alert('Error: '+d.message);
};

window.etfDeleteHolding=async function(id,sym){
  if(!confirm('Remove '+sym+' ETF holding?')) return;
  const d=await post('etf_delete',{id});
  if(d.success) await Promise.all([loadSummary(),loadHoldings()]);
  else alert(d.message);
};

// ── SIP ───────────────────────────────────────────────────────────────────────
window.etfOpenSipAdd=function(){
  const sel=document.getElementById('sipEtfSel');
  sel.innerHTML=holdingsData.map(h=>`<option value="${h.etf_id}">${h.symbol} — ${h.scheme_name}</option>`).join('');
  new bootstrap.Modal(document.getElementById('etfSipModal')).show();
};

window.etfSipSave=async function(){
  const etfId=document.getElementById('sipEtfSel').value;
  const amount=document.getElementById('sipAmount').value;
  if(!etfId||!amount){alert('ETF and amount required.');return;}
  const d=await post('etf_sip_add',{etf_id:etfId,monthly_amount:amount,
    sip_date:document.getElementById('sipDay').value,
    start_date:document.getElementById('sipStart').value,
    broker:document.getElementById('sipBroker').value});
  if(d.success){bootstrap.Modal.getInstance(document.getElementById('etfSipModal'))?.hide();loadSips();}
  else alert(d.message);
};

window.etfToggleSip=async function(id,active){
  const d=await post('etf_sip_edit',{id,is_active:active});
  if(d.success) loadSips();
};

window.etfDeleteSip=async function(id){
  if(!confirm('Delete this SIP?')) return;
  const d=await post('etf_sip_delete',{id});
  if(d.success) loadSips();
};

// ── Txn ───────────────────────────────────────────────────────────────────────
window.etfOpenTxnAdd=window.etfOpenTxnForHolding=function(etfId){
  const sel=document.getElementById('txnEtfSel');
  sel.innerHTML=holdingsData.map(h=>`<option value="${h.etf_id}" ${h.etf_id===etfId?'selected':''}>${h.symbol}</option>`).join('');
  document.getElementById('txnDate').value=new Date().toISOString().split('T')[0];
  new bootstrap.Modal(document.getElementById('etfTxnModal')).show();
};

window.etfTxnSave=async function(){
  const etfId=document.getElementById('txnEtfSel').value;
  const qty=document.getElementById('txnQty').value;
  const price=document.getElementById('txnPrice').value;
  if(!etfId||!qty||!price){alert('ETF, units, and price required.');return;}
  const d=await post('etf_txn_add',{etf_id:etfId,type:document.getElementById('txnType').value,
    quantity:qty,price,brokerage:document.getElementById('txnBrokerage').value,
    txn_date:document.getElementById('txnDate').value,broker:document.getElementById('txnBroker').value});
  if(d.success){bootstrap.Modal.getInstance(document.getElementById('etfTxnModal'))?.hide();
    await Promise.all([loadSummary(),loadHoldings(),loadTxns()]);}
  else alert(d.message);
};

// ── Utils ─────────────────────────────────────────────────────────────────────
async function get(action){const r=await fetch(`${API}?action=${action}`);return r.json();}
async function post(action,body){const fd=new URLSearchParams({action,...body});const r=await fetch(API,{method:'POST',body:fd});return r.json();}
function fmtM(n){n=parseFloat(n||0);if(n>=1e7)return(n/1e7).toFixed(2)+' Cr';if(n>=1e5)return(n/1e5).toFixed(2)+' L';return n.toLocaleString('en-IN',{maximumFractionDigits:0});}
function fmtN(n){return parseFloat(n||0).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});}
function fmtQty(n){return parseFloat(n||0).toLocaleString('en-IN',{maximumFractionDigits:4});}
function fmtDate(s){if(!s)return'—';return new Date(s).toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});}
function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

init();
})();
</script>
<?php
$content=ob_get_clean();
require APP_ROOT.'/templates/layout.php';
