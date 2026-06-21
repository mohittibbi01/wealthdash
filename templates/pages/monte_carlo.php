<?php
/**
 * WealthDash — tg001: Monte Carlo Goal Probability Simulator Page
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Monte Carlo Simulator';
$activePage  = 'monte_carlo';

ob_start();
?>
<style>
/* ── Monte Carlo Page Styles ───────────────────────────────── */
.mc-layout { display:grid; grid-template-columns:360px 1fr; gap:20px; align-items:start; }
@media(max-width:900px){ .mc-layout { grid-template-columns:1fr; } }

.mc-panel { background:var(--card-bg); border:1px solid var(--border); border-radius:12px; }
.mc-panel-head { padding:16px 20px; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; }
.mc-panel-head h3 { margin:0; font-size:15px; font-weight:700; }
.mc-panel-body { padding:18px 20px; }

/* Probability Gauge */
.mc-gauge-wrap { position:relative; text-align:center; margin:10px 0 4px; }
.mc-gauge-svg { display:block; margin:0 auto; }
.mc-gauge-label { position:absolute; top:54%; left:50%; transform:translate(-50%,-50%); text-align:center; pointer-events:none; }
.mc-gauge-pct { font-size:32px; font-weight:800; line-height:1; }
.mc-gauge-sub { font-size:11px; color:var(--text-secondary); margin-top:2px; }

/* Percentile Bar */
.mc-perc-row { display:flex; align-items:center; gap:8px; margin-bottom:8px; font-size:12px; }
.mc-perc-label { width:30px; color:var(--text-secondary); font-weight:600; }
.mc-perc-bar-wrap { flex:1; background:var(--border); border-radius:4px; height:8px; overflow:hidden; }
.mc-perc-bar { height:100%; border-radius:4px; transition:width .4s; }
.mc-perc-val { width:90px; text-align:right; font-weight:600; font-size:12px; }

/* Result cards */
.mc-result-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin:12px 0; }
.mc-result-card { background:var(--surface-secondary, rgba(0,0,0,.03)); border-radius:8px; padding:12px 14px; }
.mc-result-card .label { font-size:10px; text-transform:uppercase; letter-spacing:.4px; color:var(--text-secondary); }
.mc-result-card .value { font-size:16px; font-weight:700; margin-top:3px; }

/* Form */
.mc-field { margin-bottom:14px; }
.mc-field label { display:block; font-size:12px; font-weight:600; color:var(--text-secondary); margin-bottom:5px; }
.mc-field input, .mc-field select { width:100%; padding:8px 10px; border:1px solid var(--border); border-radius:7px; font-size:13px; background:var(--input-bg, var(--card-bg)); color:var(--text-primary); box-sizing:border-box; }
.mc-field input:focus, .mc-field select:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(99,102,241,.12); }

/* Slider */
.mc-slider { width:100%; accent-color:var(--accent); }
.mc-slider-val { font-weight:700; color:var(--accent); font-size:13px; }

/* History Table */
.mc-hist-row { border-bottom:1px solid var(--border); }
.mc-hist-row td { padding:10px 12px; font-size:13px; vertical-align:middle; }
.mc-hist-row:hover td { background:var(--hover-bg, rgba(0,0,0,.02)); }

/* Risk badge */
.mc-risk-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }

/* Spinner */
.mc-spinner { display:none; width:20px; height:20px; border:3px solid var(--border); border-top-color:var(--accent); border-radius:50%; animation:mcSpin .6s linear infinite; }
@keyframes mcSpin { to { transform:rotate(360deg); } }

/* Alert box */
.mc-alert { border-radius:8px; padding:12px 16px; font-size:13px; margin:12px 0; }
.mc-alert-success { background:rgba(34,197,94,.1); border:1px solid rgba(34,197,94,.3); color:#15803d; }
.mc-alert-warn    { background:rgba(245,158,11,.1); border:1px solid rgba(245,158,11,.3); color:#b45309; }
.mc-alert-danger  { background:rgba(220,38,38,.1);  border:1px solid rgba(220,38,38,.3);  color:#b91c1c; }
</style>

<!-- Page Header -->
<div class="page-header" style="margin-bottom:24px;">
  <div>
    <h1 class="page-title">🎲 Monte Carlo Simulator</h1>
    <p class="page-subtitle">Simulate thousands of market scenarios · Know your goal probability</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-ghost btn-sm" onclick="mcLoadHistory()">📋 History</button>
    <button class="btn btn-primary" id="btnRunSim" onclick="mcRun(false)">
      ▶ Run Simulation
    </button>
  </div>
</div>

<div class="mc-layout">

  <!-- ═══ LEFT: INPUT PANEL ═══════════════════════════════════ -->
  <div>
    <div class="mc-panel">
      <div class="mc-panel-head">
        <h3>⚙️ Simulation Inputs</h3>
        <span class="mc-spinner" id="mcSpinner"></span>
      </div>
      <div class="mc-panel-body">

        <!-- Quick-fill from existing goal -->
        <div class="mc-field">
          <label>Quick-fill from Goal</label>
          <select id="mcGoalSelect" onchange="mcFillFromGoal(this.value)">
            <option value="">— Select an existing goal —</option>
          </select>
        </div>

        <!-- Asset Class Preset -->
        <div class="mc-field">
          <label>Asset Class / Return Preset</label>
          <select id="mcPreset" onchange="mcApplyPreset(this.value)">
            <option value="">— Select preset —</option>
          </select>
        </div>

        <hr style="border:none;border-top:1px solid var(--border);margin:12px 0;">

        <div class="mc-field">
          <label>Goal Label</label>
          <input type="text" id="mcLabel" value="Retirement Corpus" placeholder="e.g. Child Education, Retirement">
        </div>

        <div class="mc-field">
          <label>Target Amount (₹) <span style="color:#dc2626;">*</span></label>
          <input type="number" id="mcTarget" value="10000000" min="1" step="10000" placeholder="e.g. 1,00,00,000">
        </div>

        <div class="mc-field">
          <label>Current Corpus / Already Saved (₹)</label>
          <input type="number" id="mcCurrentSaved" value="500000" min="0" step="10000">
        </div>

        <div class="mc-field">
          <label>Monthly SIP / Contribution (₹)</label>
          <input type="number" id="mcMonthlyContrib" value="25000" min="0" step="500">
        </div>

        <div class="mc-field">
          <label>Time Horizon
            <span class="mc-slider-val" id="mcMonthsVal">10 years</span>
          </label>
          <input type="range" class="mc-slider" id="mcMonths" min="12" max="360" step="12" value="120"
            oninput="document.getElementById('mcMonthsVal').textContent = Math.round(this.value/12)+' years ('+this.value+' months)'">
        </div>

        <div class="mc-field">
          <label>Expected Annual Return (%)
            <span class="mc-slider-val" id="mcReturnVal">12%</span>
          </label>
          <input type="range" class="mc-slider" id="mcReturn" min="4" max="24" step="0.5" value="12"
            oninput="document.getElementById('mcReturnVal').textContent = this.value+'%'">
        </div>

        <div class="mc-field">
          <label>Annual Volatility / Std Dev (%)
            <span class="mc-slider-val" id="mcVolVal">15%</span>
            <span style="font-size:10px;color:var(--text-secondary);margin-left:4px;">← lower = debt, higher = equity</span>
          </label>
          <input type="range" class="mc-slider" id="mcVol" min="0" max="40" step="0.5" value="15"
            oninput="document.getElementById('mcVolVal').textContent = this.value+'%'">
        </div>

        <!-- Advanced -->
        <details style="margin-top:4px;">
          <summary style="font-size:12px;font-weight:600;color:var(--text-secondary);cursor:pointer;user-select:none;margin-bottom:8px;">
            ⚙️ Advanced Options
          </summary>

          <div class="mc-field">
            <label>Inflation Rate (%)
              <span class="mc-slider-val" id="mcInflationVal">0%</span>
              <span style="font-size:10px;color:var(--text-secondary);">(adjusts target to future value)</span>
            </label>
            <input type="range" class="mc-slider" id="mcInflation" min="0" max="10" step="0.5" value="0"
              oninput="document.getElementById('mcInflationVal').textContent = this.value+'%'">
          </div>

          <div class="mc-field">
            <label>Annual SIP Step-Up (%)
              <span class="mc-slider-val" id="mcStepupVal">0%</span>
            </label>
            <input type="range" class="mc-slider" id="mcStepup" min="0" max="20" step="1" value="0"
              oninput="document.getElementById('mcStepupVal').textContent = this.value+'%'">
          </div>

          <div class="mc-field">
            <label>Simulations
              <span class="mc-slider-val" id="mcIterVal">5,000</span>
              <span style="font-size:10px;color:var(--text-secondary);">(more = accurate, slower)</span>
            </label>
            <input type="range" class="mc-slider" id="mcIter" min="1000" max="20000" step="1000" value="5000"
              oninput="document.getElementById('mcIterVal').textContent = parseInt(this.value).toLocaleString('en-IN')">
          </div>
        </details>

        <div style="display:flex;gap:8px;margin-top:16px;">
          <button class="btn btn-primary" style="flex:1;" onclick="mcRun(false)" id="btnRun">
            ▶ Run
          </button>
          <button class="btn btn-outline-secondary" onclick="mcRun(true)" id="btnSave" title="Run & Save to history">
            💾 Save
          </button>
          <button class="btn btn-ghost" onclick="mcReset()" title="Reset to defaults">↺</button>
        </div>
      </div>
    </div>

    <!-- History panel (hidden by default) -->
    <div class="mc-panel" id="mcHistoryPanel" style="margin-top:16px;display:none;">
      <div class="mc-panel-head">
        <h3>📋 Saved Simulations</h3>
        <button class="btn btn-ghost btn-sm" onclick="document.getElementById('mcHistoryPanel').style.display='none'">✕</button>
      </div>
      <div style="overflow-x:auto;">
        <table style="width:100%;border-collapse:collapse;font-size:12px;">
          <thead>
            <tr style="border-bottom:1px solid var(--border);background:var(--surface-secondary,rgba(0,0,0,.03));">
              <th style="padding:8px 12px;text-align:left;font-weight:600;color:var(--text-secondary);">Label</th>
              <th style="padding:8px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Probability</th>
              <th style="padding:8px 12px;text-align:right;font-weight:600;color:var(--text-secondary);">Target</th>
              <th style="padding:8px 12px;text-align:center;font-weight:600;color:var(--text-secondary);">Actions</th>
            </tr>
          </thead>
          <tbody id="mcHistTbody"></tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ═══ RIGHT: RESULTS PANEL ════════════════════════════════ -->
  <div>

    <!-- Probability Gauge -->
    <div class="mc-panel" style="margin-bottom:16px;">
      <div class="mc-panel-head">
        <h3>🎯 Goal Probability</h3>
        <span id="mcRiskBadge"></span>
      </div>
      <div class="mc-panel-body" id="mcResultBody">
        <div style="text-align:center;padding:40px;color:var(--text-secondary);">
          Run a simulation to see results
        </div>
      </div>
    </div>

    <!-- Fan Chart -->
    <div class="mc-panel" style="margin-bottom:16px;">
      <div class="mc-panel-head">
        <h3>📈 Probability Fan Chart</h3>
        <span style="font-size:11px;color:var(--text-secondary);">P10 → P90 bands across time</span>
      </div>
      <div class="mc-panel-body">
        <canvas id="mcFanChart" height="220" style="width:100%;"></canvas>
        <div style="display:flex;gap:12px;justify-content:center;margin-top:8px;flex-wrap:wrap;font-size:11px;">
          <span style="display:flex;align-items:center;gap:4px;"><span style="width:12px;height:3px;background:#bfdbfe;display:inline-block;border-radius:2px;"></span>P10–P90 range</span>
          <span style="display:flex;align-items:center;gap:4px;"><span style="width:12px;height:3px;background:#6366f1;display:inline-block;border-radius:2px;"></span>Median (P50)</span>
          <span style="display:flex;align-items:center;gap:4px;"><span style="width:12px;height:3px;background:#dc2626;display:inline-block;border-radius:2px;border-style:dashed;"></span>Target</span>
        </div>
      </div>
    </div>

    <!-- Percentile Breakdown -->
    <div class="mc-panel">
      <div class="mc-panel-head"><h3>📊 Final Value Percentiles</h3></div>
      <div class="mc-panel-body" id="mcPercentileBody">
        <div style="color:var(--text-secondary);font-size:13px;text-align:center;padding:16px;">Run simulation first</div>
      </div>
    </div>
  </div>
</div>

<!-- Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function() {
  const API = '<?= APP_URL ?>/api/index.php';
  let fanChartInstance = null;
  let lastResult = null;

  // ── Init ──────────────────────────────────────────────────────
  async function init() {
    await Promise.all([loadGoals(), loadPresets()]);
  }

  async function loadGoals() {
    try {
      const r = await fetch(`${API}?action=goal_list`);
      const d = await r.json();
      const sel = document.getElementById('mcGoalSelect');
      if (d.success && d.goals?.length) {
        d.goals.forEach(g => {
          const o = document.createElement('option');
          o.value = JSON.stringify(g);
          o.textContent = g.name + ' — ₹' + fmt(g.target_amount);
          sel.appendChild(o);
        });
      }
    } catch(e) {}
  }

  async function loadPresets() {
    try {
      const r = await fetch(`${API}?action=monte_carlo_presets`);
      const d = await r.json();
      const sel = document.getElementById('mcPreset');
      if (d.success) {
        d.presets.forEach(p => {
          const o = document.createElement('option');
          o.value = JSON.stringify(p);
          o.textContent = `${p.icon} ${p.label} — ${p.return}% return, ${p.volatility}% vol`;
          sel.appendChild(o);
        });
      }
    } catch(e) {}
  }

  window.mcFillFromGoal = function(val) {
    if (!val) return;
    const g = JSON.parse(val);
    document.getElementById('mcLabel').value = g.name;
    document.getElementById('mcTarget').value = g.target_amount;
    if (g.effective_saved > 0) document.getElementById('mcCurrentSaved').value = g.effective_saved;
    if (g.monthly_sip_needed > 0) document.getElementById('mcMonthlyContrib').value = g.monthly_sip_needed;
    if (g.months_left > 0) {
      const slider = document.getElementById('mcMonths');
      const snapped = Math.round(g.months_left / 12) * 12;
      slider.value = Math.max(12, Math.min(360, snapped));
      document.getElementById('mcMonthsVal').textContent = Math.round(slider.value/12) + ' years (' + slider.value + ' months)';
    }
    if (g.expected_return_pct) {
      document.getElementById('mcReturn').value = g.expected_return_pct;
      document.getElementById('mcReturnVal').textContent = g.expected_return_pct + '%';
    }
  };

  window.mcApplyPreset = function(val) {
    if (!val) return;
    const p = JSON.parse(val);
    document.getElementById('mcReturn').value = p.return;
    document.getElementById('mcReturnVal').textContent = p.return + '%';
    document.getElementById('mcVol').value = p.volatility;
    document.getElementById('mcVolVal').textContent = p.volatility + '%';
  };

  // ── Run Simulation ────────────────────────────────────────────
  window.mcRun = async function(save = false) {
    const spinner = document.getElementById('mcSpinner');
    const btnRun  = document.getElementById('btnRun');
    const btnSave = document.getElementById('btnSave');
    spinner.style.display = 'block';
    btnRun.disabled = btnSave.disabled = true;
    btnRun.textContent = '⏳ Running…';

    const params = new URLSearchParams({
      action:          save ? 'monte_carlo_save' : 'monte_carlo_run',
      label:           document.getElementById('mcLabel').value,
      target_amount:   document.getElementById('mcTarget').value,
      current_saved:   document.getElementById('mcCurrentSaved').value,
      monthly_contrib: document.getElementById('mcMonthlyContrib').value,
      months:          document.getElementById('mcMonths').value,
      annual_return:   document.getElementById('mcReturn').value,
      annual_volatility: document.getElementById('mcVol').value,
      inflation_pct:   document.getElementById('mcInflation').value,
      sip_stepup_pct:  document.getElementById('mcStepup').value,
      iterations:      document.getElementById('mcIter').value,
    });

    // Attach goal_id if selected
    const goalSel = document.getElementById('mcGoalSelect');
    if (goalSel.value) {
      try { params.set('goal_id', JSON.parse(goalSel.value).id); } catch(e) {}
    }

    try {
      const r = await fetch(API, { method:'POST', body: params });
      const d = await r.json();
      if (!d.success) { alert('Error: ' + d.message); return; }
      lastResult = d;
      renderResults(d);
      renderFanChart(d);
      renderPercentiles(d);
      if (save) showToast('✅ Simulation saved to history!');
    } catch(e) {
      alert('Simulation failed: ' + e.message);
    } finally {
      spinner.style.display = 'none';
      btnRun.disabled = btnSave.disabled = false;
      btnRun.textContent = '▶ Run';
    }
  };

  function renderResults(d) {
    const prob = d.success_probability;
    const risk = d.risk;

    // Risk badge
    document.getElementById('mcRiskBadge').innerHTML =
      `<span class="mc-risk-badge" style="background:${risk.color}22;color:${risk.color};">${risk.emoji} ${risk.label}</span>`;

    // Gauge SVG
    const R = 80; const cx = 100; const cy = 100;
    const startAngle = Math.PI;
    const endAngle   = Math.PI + (prob / 100) * Math.PI;
    const arcX1 = cx + R * Math.cos(startAngle);
    const arcY1 = cy + R * Math.sin(startAngle);
    const arcX2 = cx + R * Math.cos(endAngle);
    const arcY2 = cy + R * Math.sin(endAngle);
    const largeArc = prob >= 50 ? 1 : 0;
    const gaugeColor = prob >= 85 ? '#16a34a' : prob >= 65 ? '#d97706' : prob >= 40 ? '#ea580c' : '#dc2626';

    const sipHint = d.sip_needed_for_80pct
      ? `<div class="mc-alert mc-alert-warn" style="margin-top:10px;">
           💡 SIP ₹${fmt(d.sip_needed_for_80pct)}/month se probability 80% ho sakti hai
           (currently ₹${fmt(d.monthly_contrib)}/month)
         </div>`
      : '';

    const inflAdj = d.inflation_pct > 0
      ? `<div style="font-size:11px;color:var(--text-secondary);margin-top:2px;">
           Inflation-adjusted target: ₹${fmt(d.inflation_adj_target)}
         </div>`
      : '';

    document.getElementById('mcResultBody').innerHTML = `
      <div class="mc-gauge-wrap">
        <svg class="mc-gauge-svg" viewBox="0 0 200 110" width="240" height="130">
          <!-- Track -->
          <path d="M ${cx-R},${cy} A ${R},${R} 0 0,1 ${cx+R},${cy}"
            fill="none" stroke="var(--border)" stroke-width="14" stroke-linecap="round"/>
          <!-- Progress -->
          <path d="M ${arcX1},${arcY1} A ${R},${R} 0 ${largeArc},1 ${arcX2},${arcY2}"
            fill="none" stroke="${gaugeColor}" stroke-width="14" stroke-linecap="round"/>
          <!-- Tick marks 0% 25% 50% 75% 100% -->
          <text x="14" y="${cy+12}" font-size="9" fill="var(--text-secondary)" text-anchor="middle">0</text>
          <text x="${cx}" y="${cy-R-6}" font-size="9" fill="var(--text-secondary)" text-anchor="middle">50</text>
          <text x="186" y="${cy+12}" font-size="9" fill="var(--text-secondary)" text-anchor="middle">100</text>
        </svg>
        <div class="mc-gauge-label">
          <div class="mc-gauge-pct" style="color:${gaugeColor};">${prob}%</div>
          <div class="mc-gauge-sub">Success Probability<br><span style="font-size:10px;">${d.iterations.toLocaleString('en-IN')} simulations</span></div>
        </div>
      </div>

      <div class="mc-result-grid">
        <div class="mc-result-card">
          <div class="label">Target</div>
          <div class="value">₹${fmt(d.target_amount)}</div>
          ${inflAdj}
        </div>
        <div class="mc-result-card">
          <div class="label">Median Outcome (P50)</div>
          <div class="value" style="color:${d.percentiles.p50 >= d.target_amount ? '#16a34a' : '#dc2626'};">
            ₹${fmt(d.percentiles.p50)}
          </div>
        </div>
        <div class="mc-result-card">
          <div class="label">Best Case (P90)</div>
          <div class="value" style="color:#16a34a;">₹${fmt(d.percentiles.p90)}</div>
        </div>
        <div class="mc-result-card">
          <div class="label">Worst Case (P10)</div>
          <div class="value" style="color:#dc2626;">₹${fmt(d.percentiles.p10)}</div>
        </div>
        <div class="mc-result-card">
          <div class="label">Time Horizon</div>
          <div class="value">${d.years} years</div>
        </div>
        <div class="mc-result-card">
          <div class="label">Monthly SIP</div>
          <div class="value">₹${fmt(d.monthly_contrib)}</div>
        </div>
      </div>
      ${sipHint}
    `;
  }

  function renderFanChart(d) {
    const ctx = document.getElementById('mcFanChart').getContext('2d');
    if (fanChartInstance) fanChartInstance.destroy();
    if (!d.fan_chart?.length) return;

    const labels  = d.fan_chart.map(p => p.year + 'y');
    const p10     = d.fan_chart.map(p => p.p10);
    const p25     = d.fan_chart.map(p => p.p25);
    const p50     = d.fan_chart.map(p => p.p50);
    const p75     = d.fan_chart.map(p => p.p75);
    const p90     = d.fan_chart.map(p => p.p90);
    const target  = d.fan_chart.map(p => p.target);

    fanChartInstance = new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [
          // Filled band P10→P90
          {
            label: 'P90', data: p90,
            borderColor: 'rgba(99,102,241,.4)', backgroundColor: 'rgba(199,210,254,.25)',
            borderWidth: 1.5, fill: '+3', tension: .4, pointRadius: 0,
          },
          {
            label: 'P75', data: p75,
            borderColor: 'rgba(99,102,241,.3)', backgroundColor: 'rgba(199,210,254,.25)',
            borderWidth: 1, fill: '+1', tension: .4, pointRadius: 0,
          },
          {
            label: 'P50 Median', data: p50,
            borderColor: '#6366f1', backgroundColor: 'transparent',
            borderWidth: 2.5, fill: false, tension: .4, pointRadius: 0,
          },
          {
            label: 'P25', data: p25,
            borderColor: 'rgba(99,102,241,.3)', backgroundColor: 'rgba(199,210,254,.25)',
            borderWidth: 1, fill: false, tension: .4, pointRadius: 0,
          },
          {
            label: 'P10', data: p10,
            borderColor: 'rgba(99,102,241,.4)', backgroundColor: 'rgba(199,210,254,.25)',
            borderWidth: 1.5, fill: false, tension: .4, pointRadius: 0,
          },
          // Target line
          {
            label: 'Target', data: target,
            borderColor: '#dc2626', backgroundColor: 'transparent',
            borderWidth: 2, borderDash: [6,3], fill: false, tension: 0, pointRadius: 0,
          },
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: true,
        interaction: { mode:'index', intersect:false },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: ctx => ctx.dataset.label + ': ₹' + fmt(ctx.parsed.y),
            }
          }
        },
        scales: {
          x: { grid:{ display:false }, ticks:{ font:{size:10}, maxTicksLimit:8 } },
          y: {
            grid: { color:'rgba(0,0,0,.05)' },
            ticks: { font:{size:10}, callback: v => '₹'+fmtShort(v) },
          }
        }
      }
    });
  }

  function renderPercentiles(d) {
    const p = d.percentiles;
    const target = d.inflation_adj_target || d.target_amount;
    const max = p.p90;

    const rows = [
      { label:'P90', val:p.p90, color:'#16a34a', note:'Best 10% of outcomes' },
      { label:'P75', val:p.p75, color:'#4ade80', note:'75th percentile' },
      { label:'P50', val:p.p50, color:'#6366f1', note:'Median outcome' },
      { label:'P25', val:p.p25, color:'#f59e0b', note:'25th percentile' },
      { label:'P10', val:p.p10, color:'#dc2626', note:'Worst 10% of outcomes' },
    ];

    document.getElementById('mcPercentileBody').innerHTML = `
      <div style="margin-bottom:12px;">
        ${rows.map(r => `
          <div class="mc-perc-row">
            <div class="mc-perc-label">${r.label}</div>
            <div class="mc-perc-bar-wrap">
              <div class="mc-perc-bar" style="width:${Math.min(100,(r.val/max)*100)}%;background:${r.color};"></div>
            </div>
            <div class="mc-perc-val" style="color:${r.val >= target ? '#16a34a':'#dc2626'};">₹${fmt(r.val)}</div>
          </div>
          <div style="font-size:10px;color:var(--text-secondary);margin:-4px 0 8px 38px;">${r.note} — ${r.val >= target ? '✅ beats target':'❌ misses target'}</div>
        `).join('')}
      </div>
      <div style="font-size:12px;color:var(--text-secondary);border-top:1px solid var(--border);padding-top:10px;">
        📌 <strong>Interpretation:</strong> In ${d.success_probability}% of ${d.iterations.toLocaleString('en-IN')} simulated scenarios,
        your corpus reaches ₹${fmt(target)} in ${d.years} years with
        ₹${fmt(d.monthly_contrib)}/month SIP at ${d.annual_return}% expected return and ${d.annual_volatility}% volatility.
        ${d.inflation_pct > 0 ? `<br>🎈 Target adjusted for ${d.inflation_pct}% inflation.` : ''}
        ${d.sip_stepup_pct > 0 ? `<br>📈 SIP steps up ${d.sip_stepup_pct}% annually.` : ''}
      </div>
    `;
  }

  // ── History ───────────────────────────────────────────────────
  window.mcLoadHistory = async function() {
    const panel = document.getElementById('mcHistoryPanel');
    panel.style.display = '';
    const tbody = document.getElementById('mcHistTbody');
    tbody.innerHTML = '<tr><td colspan="4" style="padding:16px;text-align:center;color:var(--text-secondary);">Loading…</td></tr>';
    try {
      const r = await fetch(`${API}?action=monte_carlo_history`);
      const d = await r.json();
      if (!d.success || !d.simulations.length) {
        tbody.innerHTML = '<tr><td colspan="4" style="padding:16px;text-align:center;color:var(--text-secondary);">No saved simulations yet.</td></tr>';
        return;
      }
      tbody.innerHTML = d.simulations.map(s => {
        const prob = s.success_probability;
        const clr = prob >= 85 ? '#16a34a' : prob >= 65 ? '#d97706' : '#dc2626';
        return `<tr class="mc-hist-row">
          <td>
            <div style="font-weight:600;">${esc(s.label)}</div>
            <div style="font-size:10px;color:var(--text-secondary);">${s.goal_name ? s.goal_name+' · ':''} ${Math.round(s.months/12)}y · ${s.annual_return}% ret · ${s.annual_volatility}% vol</div>
            <div style="font-size:10px;color:var(--text-secondary);">${new Date(s.created_at).toLocaleDateString('en-IN')}</div>
          </td>
          <td style="text-align:right;font-weight:800;font-size:15px;color:${clr};">${prob}%</td>
          <td style="text-align:right;">₹${fmt(s.target_amount)}</td>
          <td style="text-align:center;">
            <button onclick="mcDeleteSim(${s.id})" style="background:none;border:none;cursor:pointer;color:#dc2626;font-size:14px;" title="Delete">🗑️</button>
          </td>
        </tr>`;
      }).join('');
    } catch(e) {
      tbody.innerHTML = '<tr><td colspan="4" style="padding:12px;color:#dc2626;">Failed to load history.</td></tr>';
    }
  };

  window.mcDeleteSim = async function(id) {
    if (!confirm('Delete this simulation?')) return;
    const r = await fetch(API, { method:'POST', body: new URLSearchParams({ action:'monte_carlo_delete', id }) });
    const d = await r.json();
    if (d.success) mcLoadHistory();
    else alert(d.message);
  };

  window.mcReset = function() {
    document.getElementById('mcTarget').value        = '10000000';
    document.getElementById('mcCurrentSaved').value  = '500000';
    document.getElementById('mcMonthlyContrib').value= '25000';
    document.getElementById('mcMonths').value        = '120';
    document.getElementById('mcMonthsVal').textContent = '10 years (120 months)';
    document.getElementById('mcReturn').value        = '12';
    document.getElementById('mcReturnVal').textContent = '12%';
    document.getElementById('mcVol').value           = '15';
    document.getElementById('mcVolVal').textContent  = '15%';
    document.getElementById('mcInflation').value     = '0';
    document.getElementById('mcInflationVal').textContent = '0%';
    document.getElementById('mcStepup').value        = '0';
    document.getElementById('mcStepupVal').textContent = '0%';
    document.getElementById('mcIter').value          = '5000';
    document.getElementById('mcIterVal').textContent = '5,000';
    document.getElementById('mcGoalSelect').value    = '';
    document.getElementById('mcPreset').value        = '';
    document.getElementById('mcLabel').value         = 'Retirement Corpus';
  };

  // ── Utils ─────────────────────────────────────────────────────
  function fmt(n) {
    n = parseFloat(n) || 0;
    if (n >= 1e7) return (n/1e7).toFixed(2) + 'Cr';
    if (n >= 1e5) return (n/1e5).toFixed(2) + 'L';
    return n.toLocaleString('en-IN', {maximumFractionDigits:0});
  }
  function fmtShort(n) {
    if (n >= 1e7) return (n/1e7).toFixed(1) + 'Cr';
    if (n >= 1e5) return (n/1e5).toFixed(1) + 'L';
    if (n >= 1e3) return (n/1e3).toFixed(0) + 'K';
    return n;
  }
  function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
  function showToast(msg) {
    const t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#1e293b;color:#fff;padding:10px 18px;border-radius:8px;font-size:13px;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.3);';
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
  }

  init();
})();
</script>

<?php
$content = ob_get_clean();
require APP_ROOT . '/templates/layout.php';
