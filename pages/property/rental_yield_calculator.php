<?php
/**
 * WealthDash — t124: Rental Yield Calculator Page
 * File: pages/property/rental_yield_calculator.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Rental Yield Calculator'; $activePage='property'; $activeSection='property';
ob_start();
?>
<div class="page-header"><h1 class="page-title">🧮 Rental Yield Calculator</h1><p class="page-subtitle">What-if analysis before buying a property — compare yield and cash flow.</p>
  <p style="font-size:12px;color:var(--text-muted);">For tracking properties you already own, see <a href="<?=APP_URL?>?page=property">Property Portfolio</a>.</p>
</div>

<div style="display:grid;grid-template-columns:380px 1fr;gap:20px;align-items:start;" class="responsive-grid-1col">
  <div class="card" style="position:sticky;top:80px;">
    <div class="card-header"><span class="card-title">⚙️ Property Details</span></div>
    <div class="card-body">
      <div class="form-group"><label class="form-label">Purchase Price (₹)</label><input type="number" id="ry-price" class="form-control" value="6000000" step="100000"></div>
      <div class="form-group"><label class="form-label">Expected Monthly Rental (₹)</label><input type="number" id="ry-rental" class="form-control" value="20000" step="1000"></div>
      <div class="form-group"><label class="form-label">Annual Expenses (₹)</label><input type="number" id="ry-expenses" class="form-control" value="30000" step="1000" placeholder="Maintenance, tax, insurance"></div>
      <hr style="border-color:var(--border);margin:16px 0;">
      <div style="font-weight:700;font-size:13px;margin-bottom:10px;">Loan Details (optional)</div>
      <div class="form-group"><label class="form-label">Loan Amount (₹)</label><input type="number" id="ry-loan" class="form-control" value="4000000" step="100000"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="form-group"><label class="form-label">Interest Rate (%)</label><input type="number" id="ry-rate" class="form-control" value="8.5" step="0.1"></div>
        <div class="form-group"><label class="form-label">Tenure (years)</label><input type="number" id="ry-tenure" class="form-control" value="20"></div>
      </div>
      <div class="form-group"><label class="form-label">Annual Appreciation (%)</label><input type="number" id="ry-appreciation" class="form-control" value="6" step="0.5"></div>
      <button class="btn btn-primary" style="width:100%;margin-top:8px;" onclick="RY.calculate()">Calculate 🧮</button>
    </div>
  </div>

  <div id="ry-results" style="display:none;">
    <div class="dashboard-grid" id="ry-cards" style="margin-bottom:20px;"></div>
    <div id="ry-verdict" class="alert" style="margin-bottom:20px;font-weight:600;"></div>
    <div class="card"><div class="card-header"><span class="card-title">📈 10-Year Projection</span></div><div class="card-body" style="height:280px;"><canvas id="ry-chart"></canvas></div></div>
  </div>
  <div id="ry-empty" class="card"><div class="card-body"><div class="empty-state"><div class="empty-icon">🧮</div><div>Fill details and click Calculate.</div></div></div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const RY={_chart:null,
calculate(){
  apiPost({action:'rental_yield_calculate',purchase_price:document.getElementById('ry-price').value,monthly_rental:document.getElementById('ry-rental').value,annual_expenses:document.getElementById('ry-expenses').value,loan_amount:document.getElementById('ry-loan').value,loan_rate:document.getElementById('ry-rate').value,loan_tenure_years:document.getElementById('ry-tenure').value,appreciation_pct:document.getElementById('ry-appreciation').value}).then(r=>{
    if(!r.ok){showToast(r.message,'error');return;}
    const d=r.data;
    document.getElementById('ry-empty').style.display='none';
    document.getElementById('ry-results').style.display='';
    document.getElementById('ry-cards').innerHTML=`
      <div class="stat-card"><div class="stat-label">Gross Yield</div><div class="stat-value wd-num-xl">${d.gross_yield_pct}%</div></div>
      <div class="stat-card"><div class="stat-label">Net Yield</div><div class="stat-value wd-num-xl ${d.net_yield_pct>=4?'wd-gain':''}">${d.net_yield_pct}%</div></div>
      <div class="stat-card"><div class="stat-label">Monthly EMI</div><div class="stat-value wd-num-xl">${formatINR(d.monthly_emi)}</div></div>
      <div class="stat-card"><div class="stat-label">Monthly Cash Flow</div><div class="stat-value wd-num-xl ${d.monthly_cash_flow>=0?'wd-gain':'wd-loss'}">${d.monthly_cash_flow>=0?'+':''}${formatINR(d.monthly_cash_flow)}</div></div>
      <div class="stat-card"><div class="stat-label">Cash-on-Cash Return</div><div class="stat-value wd-num-xl">${d.cash_on_cash_return}%</div></div>
      <div class="stat-card"><div class="stat-label">Total Loan Interest</div><div class="stat-value wd-num-xl">${formatINR(d.total_loan_interest)}</div></div>`;
    document.getElementById('ry-verdict').textContent=d.verdict;
    document.getElementById('ry-verdict').className='alert '+(d.net_yield_pct>=4?'alert-success':d.net_yield_pct>=2?'alert-info':'alert-warning');
    if(this._chart)this._chart.destroy();
    this._chart=new Chart(document.getElementById('ry-chart'),{type:'line',data:{labels:d.projection_10yr.map(p=>'Yr '+p.year),datasets:[{label:'Property Value',data:d.projection_10yr.map(p=>p.property_value),borderColor:'#2563eb',backgroundColor:'rgba(37,99,235,.1)',fill:true,tension:0.4},{label:'Cumulative Rental',data:d.projection_10yr.map(p=>p.cumulative_rental),borderColor:'#16a34a',fill:false,tension:0.4}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{color:'var(--text-primary)'}},tooltip:{callbacks:{label:c=>` ${c.dataset.label}: ${formatINR(c.raw)}`}}},scales:{x:{ticks:{color:'#6b7280'}},y:{ticks:{color:'#6b7280',callback:v=>formatINR(v,0)}}}}});
  });
}};
document.addEventListener('DOMContentLoaded',()=>RY.calculate());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
