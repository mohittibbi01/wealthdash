<?php
/**
 * WealthDash — Portfolio Rebalancing Page (Phase 5)
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Portfolio Rebalancing';
$activePage  = 'report_rebalance';

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">Portfolio Rebalancing</h1>
    <p class="page-subtitle">Check allocation drift · Get actionable rebalancing suggestions</p>
  </div>
</div>

<!-- Target Allocation Inputs -->
<div class="card mb-4">
  <div class="card-header">
    <h3 class="card-title">Set Target Allocation</h3>
    <span class="text-secondary text-sm" id="allocTotal">Total: 100%</span>
  </div>
  <div class="card-body">
    <div class="form-row" style="flex-wrap:wrap;gap:1.25rem;align-items:flex-end">
      <div class="form-group mb-0">
        <label class="form-label">Equity (%)</label>
        <input type="number" class="form-control alloc-input" id="targetEquity" value="60" min="0" max="100" step="5">
      </div>
      <div class="form-group mb-0">
        <label class="form-label">Debt (%)</label>
        <input type="number" class="form-control alloc-input" id="targetDebt" value="30" min="0" max="100" step="5">
      </div>
      <div class="form-group mb-0">
        <label class="form-label">Gold (%)</label>
        <input type="number" class="form-control alloc-input" id="targetGold" value="5" min="0" max="100" step="5">
      </div>
      <div class="form-group mb-0">
        <label class="form-label">Other / NPS (%)</label>
        <input type="number" class="form-control" id="targetOtherDisplay" value="5" disabled style="background:var(--input-bg);color:var(--text-secondary)">
      </div>
      <div class="form-group mb-0">
        <button class="btn btn-primary" id="btnAnalyse">Analyse Portfolio</button>
      </div>
    </div>
    <p class="text-xs text-secondary mt-2">*Other/NPS is auto-calculated as remainder. Rebalancing threshold: 5% deviation.</p>
  </div>
</div>

<!-- Status Banner -->
<div id="balancedBanner" style="display:none" class="alert alert-success mb-4">
  <strong>✅ Portfolio is well-balanced!</strong> All asset classes are within the 5% deviation threshold.
</div>

<!-- Summary Cards -->
<div class="cards-grid cards-grid-4 mb-4" id="rebalSummaryCards" style="display:none">
  <div class="stat-card"><div class="stat-label">Total Portfolio</div><div class="stat-value" id="cardTotal">—</div></div>
  <div class="stat-card"><div class="stat-label">Equity</div><div class="stat-value" id="cardEquity">—</div><div class="stat-sub" id="cardEquityPct">—</div></div>
  <div class="stat-card"><div class="stat-label">Debt</div><div class="stat-value" id="cardDebt">—</div><div class="stat-sub" id="cardDebtPct">—</div></div>
  <div class="stat-card"><div class="stat-label">Gold + Other</div><div class="stat-value" id="cardGold">—</div><div class="stat-sub" id="cardGoldPct">—</div></div>
</div>

<!-- Charts row -->
<div class="cards-grid cards-grid-2 mb-4" id="chartsRow" style="display:none">
  <div class="card">
    <div class="card-header"><h3 class="card-title">Current Allocation</h3></div>
    <div class="card-body" style="display:flex;justify-content:center">
      <canvas id="currentPieChart" width="260" height="260"></canvas>
    </div>
  </div>
  <div class="card">
    <div class="card-header"><h3 class="card-title">Target vs Actual (%)</h3></div>
    <div class="card-body">
      <canvas id="compBarChart" height="160"></canvas>
    </div>
  </div>
</div>

<!-- Suggestions -->
<div class="card mb-4" id="suggestionsCard" style="display:none">
  <div class="card-header">
    <h3 class="card-title">Rebalancing Actions</h3>
    <span class="badge badge-warning" id="suggestCount">0 actions</span>
  </div>
  <div class="card-body" id="suggestionsBody"></div>
</div>

<!-- Concentration Risk -->
<div class="card" id="concentrationCard" style="display:none">
  <div class="card-header">
    <h3 class="card-title">⚠️ Concentration Risk</h3>
    <span class="text-secondary text-sm">Holdings >15% of total portfolio</span>
  </div>
  <div class="table-wrap">
    <table class="data-table">
      <thead><tr><th>Type</th><th>Name</th><th>Current Value</th><th>% of Portfolio</th><th>Recommendation</th></tr></thead>
      <tbody id="concentrationBody"></tbody>
    </table>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  // Auto-calculate Other field
  document.querySelectorAll('.alloc-input').forEach(el => el.addEventListener('input', updateOther));
  updateOther();

  document.getElementById('btnAnalyse').addEventListener('click', analysePortfolio);
  // Auto-load on page load
  analysePortfolio();
});

function updateOther() {
  const eq   = +document.getElementById('targetEquity').value || 0;
  const debt = +document.getElementById('targetDebt').value   || 0;
  const gold = +document.getElementById('targetGold').value   || 0;
  const other = Math.max(0, 100 - eq - debt - gold);
  document.getElementById('targetOtherDisplay').value = other;
  const total = eq + debt + gold + other;
  const el = document.getElementById('allocTotal');
  el.textContent = 'Total: ' + total + '%';
  el.style.color = total === 100 ? 'var(--success)' : 'var(--danger)';
}

let currentPie = null, compBar = null;

async function analysePortfolio() {
  const eq   = +document.getElementById('targetEquity').value;
  const debt = +document.getElementById('targetDebt').value;
  const gold = +document.getElementById('targetGold').value;
  if (eq + debt + gold > 100) { showToast('Allocations exceed 100%','error'); return; }

  try {
    const d = await API.post('/api/router.php', {
      action:'report_rebalancing', target_equity:eq, target_debt:debt, target_gold:gold
    });

    if (d.total <= 0) { showToast('No portfolio data found.','info'); return; }

    document.getElementById('rebalSummaryCards').style.display = '';
    document.getElementById('chartsRow').style.display = '';

    const equity = d.current.find(c=>c.asset==='Equity');
    const debt_  = d.current.find(c=>c.asset==='Debt');
    const gold_  = d.current.find(c=>c.asset==='Gold');
    const other_ = d.current.find(c=>c.asset==='Other (NPS)');

    document.getElementById('cardTotal').textContent     = formatINR(d.total_portfolio);
    document.getElementById('cardEquity').textContent    = formatINR(equity?.value||0);
    document.getElementById('cardEquityPct').textContent = (equity?.pct||0) + '% of portfolio';
    document.getElementById('cardDebt').textContent      = formatINR(debt_?.value||0);
    document.getElementById('cardDebtPct').textContent   = (debt_?.pct||0) + '% of portfolio';
    document.getElementById('cardGold').textContent      = formatINR((gold_?.value||0)+(other_?.value||0));
    document.getElementById('cardGoldPct').textContent   = ((gold_?.pct||0)+(other_?.pct||0)).toFixed(1) + '% of portfolio';

    // Pie chart
    const pieCtx = document.getElementById('currentPieChart').getContext('2d');
    if (currentPie) currentPie.destroy();
    currentPie = new Chart(pieCtx, {
      type:'doughnut',
      data: {
        labels: d.current.map(c=>c.asset),
        datasets:[{ data: d.current.map(c=>c.pct), backgroundColor: d.current.map(c=>c.color), borderWidth:2 }]
      },
      options:{ plugins:{ legend:{ position:'bottom' } }, cutout:'60%' }
    });

    // Bar chart
    const barCtx = document.getElementById('compBarChart').getContext('2d');
    if (compBar) compBar.destroy();
    const assets = ['Equity','Debt','Gold','Other (NPS)'];
    const actual  = d.current.map(c=>c.pct);
    const targets = d.target.map(t=>t.pct);
    compBar = new Chart(barCtx, {
      type:'bar',
      data:{
        labels: assets,
        datasets:[
          { label:'Current (%)',data:actual,  backgroundColor:'rgba(37,99,235,.7)',borderRadius:4 },
          { label:'Target (%)', data:targets, backgroundColor:'rgba(34,197,94,.5)', borderRadius:4 },
        ]
      },
      options:{ responsive:true, plugins:{legend:{position:'top'}}, scales:{y:{max:100,ticks:{callback:v=>v+'%'}}} }
    });

    // Suggestions
    const sugCard = document.getElementById('suggestionsCard');
    const banner  = document.getElementById('balancedBanner');
    if (d.is_balanced) {
      banner.style.display = 'block';
      sugCard.style.display = 'none';
    } else {
      banner.style.display = 'none';
      sugCard.style.display = 'block';
      document.getElementById('suggestCount').textContent = d.suggestions.length + ' action' + (d.suggestions.length!==1?'s':'');
      document.getElementById('suggestionsBody').innerHTML = d.suggestions.map(s => `
        <div class="rebal-suggestion ${s.action==='REDUCE'?'rebal-reduce':'rebal-increase'}">
          <div class="rebal-header">
            <span class="rebal-asset">${esc(s.asset_class)}</span>
            <span class="badge ${s.action==='REDUCE'?'badge-danger':'badge-success'}">${s.action}</span>
          </div>
          <p class="rebal-msg">${esc(s.message)}</p>
          <div class="rebal-meta">
            <span>Current: <strong>${s.current_pct}%</strong> (${formatINR(s.current_value)})</span>
            <span>Target: <strong>${s.target_pct}%</strong> (${formatINR(s.target_value)})</span>
            <span>Amount: <strong>${formatINR(s.amount)}</strong></span>
          </div>
        </div>`).join('');
    }

    // Concentration risks
    const concCard = document.getElementById('concentrationCard');
    if (d.concentration_risks?.length) {
      concCard.style.display = 'block';
      document.getElementById('concentrationBody').innerHTML = d.concentration_risks.map(r=>`
        <tr>
          <td><span class="badge badge-secondary">${esc(r.type)}</span></td>
          <td>${esc(r.name)}</td>
          <td class="text-right">${formatINR(r.value)}</td>
          <td class="text-right"><strong class="text-danger">${r.pct}%</strong></td>
          <td class="text-secondary text-sm">${esc(r.warning)}</td>
        </tr>`).join('');
    } else {
      concCard.style.display = 'none';
    }

  } catch(e) { showToast(e.message, 'error'); }
}
</script>

<style>
.rebal-suggestion { border:1px solid var(--border); border-radius:var(--radius); padding:1rem; margin-bottom:.75rem; }
.rebal-reduce   { border-left:4px solid var(--danger);  }
.rebal-increase { border-left:4px solid var(--success); }
.rebal-header   { display:flex; align-items:center; gap:.75rem; margin-bottom:.5rem; }
.rebal-asset    { font-weight:600; }
.rebal-msg      { color:var(--text-secondary); margin-bottom:.5rem; font-size:.875rem; }
.rebal-meta     { display:flex; gap:1.5rem; flex-wrap:wrap; font-size:.8rem; color:var(--text-secondary); }
.alert-success  { background:rgba(34,197,94,.1); border:1px solid rgba(34,197,94,.3); color:var(--success); padding:1rem 1.25rem; border-radius:var(--radius); }
</style>

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
?>

