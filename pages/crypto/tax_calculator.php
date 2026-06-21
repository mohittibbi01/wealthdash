<?php
/**
 * WealthDash — t42: Crypto Tax Calculator Page
 * File: pages/crypto/tax_calculator.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Crypto Tax Calculator'; $activePage='crypto'; $activeSection='crypto';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">🪙 Crypto Tax Calculator</h1><p class="page-subtitle">Section 115BBH — 30% flat tax on crypto gains (FY 2022-23 onwards)</p></div>
  <div style="display:flex;gap:8px;align-items:center;">
    <select id="ctx-fy" class="form-control" style="width:130px;">
      <?php for($y=2022;$y<=2026;$y++){$fy=$y.'-'.substr($y+1,-2);echo "<option value=\"$fy\"".($fy==='2024-25'?' selected':'').">FY $fy</option>";} ?>
    </select>
    <button class="btn btn-ghost btn-sm" onclick="CTX.loadReports()">📂 Saved</button>
  </div>
</div>
<div class="alert alert-warning" style="font-size:13px;margin-bottom:20px;">⚠️ <strong>Section 115BBH:</strong> 30% flat + 4% cess. Losses <strong>cannot</strong> be set off. 1% TDS under Section 194S.</div>
<div class="card" style="margin-bottom:20px;">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
    <span class="card-title">📋 Trade Entries</span>
    <div style="display:flex;gap:8px;">
      <button class="btn btn-secondary btn-sm" onclick="CTX.addRow()">+ Add Trade</button>
      <button class="btn btn-ghost btn-sm" onclick="document.getElementById('ctx-csv').click()">📥 CSV</button>
      <input type="file" id="ctx-csv" accept=".csv" style="display:none;" onchange="CTX.parseCSV(this)">
    </div>
  </div>
  <div class="card-body p-0"><div class="table-responsive"><table class="data-table">
    <thead><tr><th>#</th><th>Coin</th><th>Buy Date</th><th>Sell Date</th><th>Qty</th><th>Buy ₹</th><th>Sell ₹</th><th>Fees ₹</th><th></th></tr></thead>
    <tbody id="ctx-tbody"></tbody>
  </table></div></div>
  <div class="card-footer" style="padding:12px 16px;display:flex;gap:10px;justify-content:flex-end;">
    <button class="btn btn-primary" onclick="CTX.calculate()">🧮 Calculate Tax</button>
    <button class="btn btn-ghost" onclick="CTX.clearAll()">Clear</button>
  </div>
</div>
<div id="ctx-results" style="display:none;">
  <div class="dashboard-grid" id="ctx-cards" style="margin-bottom:20px;"></div>
  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;" class="responsive-grid-1col">
    <div class="card"><div class="card-header"><span class="card-title">🧾 Tax Breakdown</span></div><div class="card-body" id="ctx-breakdown"></div></div>
    <div class="card"><div class="card-header"><span class="card-title">📊 Gain/Loss</span></div><div class="card-body" style="height:200px;"><canvas id="ctx-chart"></canvas></div></div>
  </div>
  <div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
      <span class="card-title">📈 Per-Trade Summary</span>
      <button class="btn btn-secondary btn-sm" onclick="CTX.saveReport()">💾 Save</button>
    </div>
    <div class="card-body p-0"><div class="table-responsive"><table class="data-table">
      <thead><tr><th>#</th><th>Coin</th><th>Sell Date</th><th class="text-right">Sale Value</th><th class="text-right">Cost</th><th class="text-right">P&L</th><th class="text-right">Tax @30%</th><th class="text-right">TDS @1%</th></tr></thead>
      <tbody id="ctx-results-tbody"></tbody>
    </table></div></div>
  </div>
</div>
<div id="ctx-reports-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)this.style.display='none'">
  <div class="modal" style="max-width:500px;"><div class="modal-header"><span class="modal-title">📂 Saved Reports</span><button class="modal-close" onclick="document.getElementById('ctx-reports-modal').style.display='none'">×</button></div><div class="modal-body" id="ctx-reports-list"></div></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const CTX={_rowId:0,_chart:null,_lastSummary:null,_lastTrades:null,
init(){this.addRow();this.addRow();this.addRow();},
addRow(){const id=++this._rowId;const today=new Date().toISOString().substring(0,10);const tr=document.createElement('tr');tr.id='ctx-row-'+id;tr.innerHTML=`<td style="color:var(--text-muted);font-size:12px;">${id}</td><td><input type="text" class="form-control form-control-sm" placeholder="BTC" style="width:80px;" data-f="coin"></td><td><input type="date" class="form-control form-control-sm" value="${today}" data-f="buy_date"></td><td><input type="date" class="form-control form-control-sm" value="${today}" data-f="sell_date"></td><td><input type="number" class="form-control form-control-sm" placeholder="0.5" step="any" style="width:90px;" data-f="quantity"></td><td><input type="number" class="form-control form-control-sm" placeholder="2500000" step="any" data-f="buy_price"></td><td><input type="number" class="form-control form-control-sm" placeholder="3000000" step="any" data-f="sell_price"></td><td><input type="number" class="form-control form-control-sm" placeholder="0" step="any" style="width:80px;" data-f="fees"></td><td><button class="btn btn-ghost btn-sm" onclick="document.getElementById('ctx-row-${id}').remove()">✕</button></td>`;document.getElementById('ctx-tbody').appendChild(tr);},
_collectTrades(){const rows=document.querySelectorAll('#ctx-tbody tr');const trades=[];rows.forEach(r=>{const t={};r.querySelectorAll('[data-f]').forEach(el=>{t[el.dataset.f]=el.value;});if(t.quantity&&t.sell_price)trades.push(t);});return trades;},
calculate(){const trades=this._collectTrades();if(!trades.length){showToast('Add at least one trade','warning');return;}apiPost({action:'crypto_tax_calculate',trades:JSON.stringify(trades),fy:document.getElementById('ctx-fy').value}).then(r=>{if(!r.ok){showToast(r.message,'error');return;}this._lastSummary=r.data.summary;this._lastTrades=r.data.trades;this._render(r.data);});},
_render(d){const s=d.summary;document.getElementById('ctx-results').style.display='';
document.getElementById('ctx-cards').innerHTML=`<div class="stat-card"><div class="stat-label">Total Gains</div><div class="stat-value wd-num-xl wd-gain">${formatINR(s.total_gains)}</div></div><div class="stat-card"><div class="stat-label">Total Losses (non-deductible)</div><div class="stat-value wd-num-xl wd-loss">${formatINR(s.total_losses)}</div></div><div class="stat-card"><div class="stat-label">Tax Payable (30%+4%)</div><div class="stat-value wd-num-xl wd-loss">${formatINR(s.total_tax_payable)}</div></div><div class="stat-card"><div class="stat-label">Net Tax After TDS</div><div class="stat-value wd-num-xl">${formatINR(s.net_tax_after_tds)}</div><div class="stat-sub">TDS paid: ${formatINR(s.total_tds_1pct)}</div></div>`;
document.getElementById('ctx-breakdown').innerHTML=`<table class="data-table"><tbody><tr><td>Total Gains</td><td class="text-right wd-gain wd-num">${formatINR(s.total_gains)}</td></tr><tr><td>Total Losses <small>(cannot set-off)</small></td><td class="text-right wd-loss wd-num">${formatINR(s.total_losses)}</td></tr><tr><td>Taxable Gains</td><td class="text-right wd-num" style="font-weight:700;">${formatINR(s.taxable_gain)}</td></tr><tr><td>Tax @ 30%</td><td class="text-right wd-num">${formatINR(s.gross_tax_30pct)}</td></tr><tr><td>Cess @ 4%</td><td class="text-right wd-num">${formatINR(s.cess_4pct)}</td></tr><tr><td style="font-weight:700;">Total Tax</td><td class="text-right wd-loss wd-num" style="font-weight:700;">${formatINR(s.total_tax_payable)}</td></tr><tr><td>TDS @ 1%</td><td class="text-right wd-num">(${formatINR(s.total_tds_1pct)})</td></tr><tr><td style="font-weight:700;">Net Due</td><td class="text-right wd-num" style="font-weight:700;">${formatINR(s.net_tax_after_tds)}</td></tr></tbody></table>`;
if(this._chart)this._chart.destroy();
this._chart=new Chart(document.getElementById('ctx-chart'),{type:'doughnut',data:{labels:['Gains','Losses'],datasets:[{data:[s.total_gains,s.total_losses],backgroundColor:['#16a34a','#dc2626'],borderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,cutout:'65%',plugins:{legend:{position:'bottom'}}}});
let tbHtml='';for(const t of d.trades){const pc=t.pnl>=0?'wd-gain':'wd-loss';tbHtml+=`<tr><td>${t.idx}</td><td style="font-weight:600;">${esc(t.coin)}</td><td class="wd-num" style="font-size:12px;">${esc(t.sell_date)}</td><td class="text-right wd-num">${formatINR(t.sale_value)}</td><td class="text-right wd-num">${formatINR(t.cost_basis)}</td><td class="text-right wd-num ${pc}">${t.pnl>=0?'+':''}${formatINR(t.pnl)}</td><td class="text-right wd-num wd-loss">${formatINR(t.tax_payable)}</td><td class="text-right wd-num">${formatINR(t.tds)}</td></tr>`;}
document.getElementById('ctx-results-tbody').innerHTML=tbHtml;},
clearAll(){document.getElementById('ctx-tbody').innerHTML='';document.getElementById('ctx-results').style.display='none';this._rowId=0;this.addRow();this.addRow();this.addRow();},
parseCSV(input){const file=input.files[0];if(!file)return;const r=new FileReader();r.onload=e=>{const lines=e.target.result.trim().split('\n').slice(1);document.getElementById('ctx-tbody').innerHTML='';this._rowId=0;lines.forEach(line=>{const[coin,buy_date,sell_date,qty,buy_price,sell_price,fees]=line.split(',');this.addRow();const row=document.getElementById('ctx-row-'+this._rowId);const set=(f,v)=>{const el=row.querySelector(`[data-f="${f}"]`);if(el&&v)el.value=v.trim();};set('coin',coin);set('buy_date',buy_date);set('sell_date',sell_date);set('quantity',qty);set('buy_price',buy_price);set('sell_price',sell_price);set('fees',fees);});showToast('Imported','success');};r.readAsText(file);input.value='';},
saveReport(){if(!this._lastSummary)return;apiPost({action:'crypto_tax_save',fy:this._lastSummary.fy,label:'Crypto Tax '+this._lastSummary.fy,trades:JSON.stringify(this._lastTrades),summary:JSON.stringify(this._lastSummary)}).then(r=>showToast(r.message,r.ok?'success':'error'));},
loadReports(){apiPost({action:'crypto_tax_load'}).then(r=>{const list=document.getElementById('ctx-reports-list');const rows=r.data?.reports||[];if(!rows.length){list.innerHTML='<div class="empty-state"><div>No saved reports.</div></div>';}else{list.innerHTML=rows.map(rp=>`<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border);"><div><div style="font-weight:600;">${esc(rp.label)}</div><div style="font-size:12px;color:var(--text-muted);">FY ${esc(rp.fy)}</div></div><button class="btn btn-danger btn-sm" onclick="CTX.deleteReport(${rp.id})">✕</button></div>`).join('');}document.getElementById('ctx-reports-modal').style.display='';});},
deleteReport(id){if(!confirm('Delete?'))return;apiPost({action:'crypto_tax_delete',report_id:id}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok)this.loadReports();});}};
document.addEventListener('DOMContentLoaded',()=>CTX.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
