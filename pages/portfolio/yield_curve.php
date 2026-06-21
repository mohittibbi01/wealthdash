<?php
/**
 * WealthDash — t488: Yield Curve Page
 * File: pages/portfolio/yield_curve.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='FD Yield Curve'; $activePage='portfolio'; $activeSection='portfolio';
ob_start();
?>
<div class="page-header"><h1 class="page-title">📉 FD Yield Curve</h1><p class="page-subtitle">Interest rate vs tenure — see if you're getting the best rates.</p></div>

<div class="card" style="margin-bottom:20px;">
  <div class="card-header"><span class="card-title">📈 Yield Curve (Rate vs Tenure)</span></div>
  <div class="card-body" style="height:320px;"><canvas id="yc-chart"></canvas></div>
</div>

<div id="yc-shape-banner" class="alert alert-info" style="margin-bottom:20px;"></div>

<div id="yc-comparisons-wrap" style="display:none;margin-bottom:20px;">
  <div class="card">
    <div class="card-header"><span class="card-title">🔍 Your FDs vs Reference Rates</span></div>
    <div class="card-body p-0"><div id="yc-comparisons"></div></div>
  </div>
</div>

<!-- Bank comparison calculator -->
<div class="card">
  <div class="card-header"><span class="card-title">🧮 Compare Bank Rates</span></div>
  <div class="card-body">
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px;">Add multiple bank offers to compare maturity value.</p>
    <div class="form-group"><label class="form-label">Principal Amount (₹)</label><input type="number" id="yc-principal" class="form-control" value="100000" step="10000"></div>
    <div id="yc-bank-rows"></div>
    <button class="btn btn-ghost btn-sm" onclick="YC.addBankRow()">+ Add Bank</button>
    <button class="btn btn-primary" style="margin-left:8px;" onclick="YC.compareBanks()">Compare</button>
    <div id="yc-compare-result" style="margin-top:16px;"></div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const YC={_chart:null,_bankRowCount:0,

init(){this.load();this.addBankRow();this.addBankRow();},

load(){
  apiPost({action:'yield_curve_get'}).then(r=>{
    if(!r.ok)return;const d=r.data;
    if(this._chart)this._chart.destroy();
    const datasets=[{label:'Reference Rate',data:d.reference_curve.map(p=>({x:p.tenure_months,y:p.rate})),borderColor:'#2563eb',backgroundColor:'rgba(37,99,235,.1)',fill:true,tension:0.3}];
    if(d.your_fds.length){datasets.push({label:'Your FDs',data:d.your_fds.map(f=>({x:f.tenure_months,y:f.rate})),borderColor:'#16a34a',backgroundColor:'#16a34a',pointRadius:6,showLine:false});}
    this._chart=new Chart(document.getElementById('yc-chart'),{type:'line',data:{datasets},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{color:'var(--text-primary)'}},tooltip:{callbacks:{label:c=>` ${c.dataset.label}: ${c.parsed.y}% @ ${c.parsed.x}mo`}}},scales:{x:{type:'linear',title:{display:true,text:'Tenure (months)',color:'var(--text-muted)'},ticks:{color:'#6b7280'}},y:{title:{display:true,text:'Interest Rate (%)',color:'var(--text-muted)'},ticks:{color:'#6b7280'}}}}});

    const shapes={normal:'📈 Normal curve — longer tenures offer higher rates (typical, healthy economy)',inverted:'📉 Inverted curve — shorter tenures offer higher rates (often signals rate cuts ahead)',flat:'➡️ Flat curve — rates similar across tenures',humped:'🐫 Humped curve — mid-tenure offers best rates (common pattern for Indian FDs)'};
    document.getElementById('yc-shape-banner').textContent=shapes[d.curve_shape]||'Curve shape: '+d.curve_shape;

    if(d.comparisons.length){
      document.getElementById('yc-comparisons-wrap').style.display='';
      let html=`<table class="data-table"><thead><tr><th>Bank</th><th>Tenure</th><th class="text-right">Your Rate</th><th class="text-right">Reference</th><th class="text-right">Difference</th></tr></thead><tbody>`;
      for(const c of d.comparisons){const cls=c.diff>=0?'wd-gain':'wd-loss';html+=`<tr><td>${esc(c.bank)}</td><td>${esc(c.tenure_label)}</td><td class="text-right wd-num">${c.your_rate}%</td><td class="text-right wd-num">${c.reference_rate}%</td><td class="text-right wd-num ${cls}">${c.diff>=0?'+':''}${c.diff}%</td></tr>`;}
      html+='</tbody></table>';
      document.getElementById('yc-comparisons').innerHTML=html;
    }
  });
},

addBankRow(){
  this._bankRowCount++;
  const id=this._bankRowCount;
  const div=document.createElement('div');
  div.id='yc-row-'+id;
  div.style.cssText='display:grid;grid-template-columns:2fr 1fr 1fr auto;gap:8px;margin-bottom:8px;align-items:center;';
  div.innerHTML=`<input type="text" class="form-control yc-bank-name" placeholder="Bank name" value="Bank ${id}">
    <input type="number" class="form-control yc-bank-rate" placeholder="Rate %" value="7.0" step="0.1">
    <input type="number" class="form-control yc-bank-tenure" placeholder="Months" value="12">
    <button class="btn btn-danger btn-sm" onclick="document.getElementById('yc-row-${id}').remove()">✕</button>`;
  document.getElementById('yc-bank-rows').appendChild(div);
},

compareBanks(){
  const rows=document.querySelectorAll('#yc-bank-rows > div');
  const banks=Array.from(rows).map(r=>({bank:r.querySelector('.yc-bank-name').value,rate:r.querySelector('.yc-bank-rate').value,tenure_months:r.querySelector('.yc-bank-tenure').value}));
  apiPost({action:'yield_curve_compare_banks',banks:JSON.stringify(banks),amount:document.getElementById('yc-principal').value}).then(r=>{
    if(!r.ok)return;
    let html=`<table class="data-table"><thead><tr><th>Bank</th><th>Rate</th><th>Tenure</th><th class="text-right">Maturity Value</th><th class="text-right">Interest Earned</th></tr></thead><tbody>`;
    for(const res of r.data.results){html+=`<tr><td style="font-weight:600;">${esc(res.bank)}</td><td>${res.rate}%</td><td>${res.tenure_months}mo</td><td class="text-right wd-num" style="font-weight:700;">${formatINR(res.maturity_value)}</td><td class="text-right wd-num wd-gain">+${formatINR(res.interest_earned)}</td></tr>`;}
    html+='</tbody></table>';
    document.getElementById('yc-compare-result').innerHTML=html;
  });
}
};
document.addEventListener('DOMContentLoaded',()=>YC.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
