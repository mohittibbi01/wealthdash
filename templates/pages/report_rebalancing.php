<?php
/**
 * WealthDash — t312: Portfolio Rebalancing Report Page
 * Drift from target allocation · Tax-efficient rebalancing hints
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Rebalancing Report';
$activePage  = 'report_rebalance';

ob_start();
?>
<style>
/* ── Rebalancing Page ─────────────────────────────────────── */
.rb-grid { display:grid; grid-template-columns:340px 1fr; gap:20px; align-items:start; }
@media(max-width:960px){ .rb-grid { grid-template-columns:1fr; } }

/* Drift meter */
.rb-drift-bar-wrap { position:relative; height:10px; background:var(--border); border-radius:5px; overflow:visible; margin:6px 0 3px; }
.rb-drift-center   { position:absolute; left:50%; top:-3px; width:2px; height:16px; background:var(--text-secondary); border-radius:1px; transform:translateX(-50%); z-index:2; }
.rb-drift-fill     { position:absolute; top:0; height:100%; border-radius:5px; transition:all .5s; }

/* Asset class card */
.rb-class-card {
  background:var(--card-bg); border:1px solid var(--border);
  border-radius:12px; padding:16px 18px; margin-bottom:12px;
  border-left:4px solid var(--accent);
  transition:box-shadow .15s;
}
.rb-class-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.07); }
.rb-class-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; gap:10px; }
.rb-class-label { font-size:15px; font-weight:700; display:flex; align-items:center; gap:6px; }
.rb-status-badge { padding:3px 10px; border-radius:20px; font-size:11px; font-weight:700; }
.rb-breakdown { font-size:11px; color:var(--text-secondary); display:flex; flex-wrap:wrap; gap:10px; margin-top:6px; }

/* Suggestion cards */
.rb-sug-card {
  border-radius:10px; padding:14px 16px; margin-bottom:10px;
  border:1px solid var(--border); border-left:4px solid;
}
.rb-sug-reduce   { border-left-color:#dc2626; background:rgba(220,38,38,.04); }
.rb-sug-increase { border-left-color:#16a34a; background:rgba(22,163,74,.04); }
.rb-sug-head { display:flex; align-items:center; gap:10px; margin-bottom:6px; }
.rb-action-pill { padding:3px 10px; border-radius:20px; font-size:11px; font-weight:800; }
.rb-reduce-pill  { background:rgba(220,38,38,.12); color:#dc2626; }
.rb-increase-pill{ background:rgba(22,163,74,.12);  color:#16a34a; }
.rb-tax-hint { margin-top:8px; padding:8px 10px; background:rgba(99,102,241,.06); border-radius:6px; font-size:11px; color:var(--text-secondary); }
.rb-tax-hint strong { color:var(--text-primary); }

/* Donut legend */
.rb-legend { display:flex; flex-direction:column; gap:6px; margin-top:12px; }
.rb-legend-row { display:flex; align-items:center; gap:8px; font-size:12px; }
.rb-legend-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
.rb-legend-name { flex:1; }
.rb-legend-pct { font-weight:700; min-width:40px; text-align:right; }
.rb-legend-target { color:var(--text-secondary); min-width:55px; text-align:right; font-size:11px; }

/* Slider inputs */
.rb-slider-row { display:flex; align-items:center; gap:10px; margin-bottom:10px; }
.rb-slider-label { font-size:12px; font-weight:600; width:110px; flex-shrink:0; display:flex; align-items:center; gap:5px; }
.rb-slider-row input[type=range] { flex:1; accent-color:var(--accent); cursor:pointer; }
.rb-slider-pct { font-weight:700; width:38px; text-align:right; font-size:13px; color:var(--accent); }
.rb-total-check { text-align:center; font-size:13px; font-weight:600; padding:6px; border-radius:6px; margin-top:4px; }

/* Balanced state */
.rb-balanced { text-align:center; padding:32px 20px; }
.rb-balanced-icon { font-size:56px; line-height:1; margin-bottom:12px; }

/* Summary strip */
.rb-summary-strip { display:flex; gap:0; height:16px; border-radius:8px; overflow:hidden; margin:8px 0 4px; }
.rb-strip-seg { transition:width .5s; }
</style>

<!-- Page Header -->
<div class="page-header" style="margin-bottom:22px;">
  <div>
    <h1 class="page-title">⚖️ Rebalancing Report</h1>
    <p class="page-subtitle">Drift from target allocation · Tax-efficient rebalancing suggestions</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-ghost btn-sm" id="btnExport" onclick="rbExport()">📋 Copy Summary</button>
    <button class="btn btn-primary btn-sm" onclick="rbLoad()">🔄 Refresh</button>
  </div>
</div>

<div class="rb-grid">

  <!-- ═══ LEFT: TARGET ALLOCATION PANEL ════════════════════════ -->
  <div>
    <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:16px;">
      <div style="padding:14px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
        <h3 style="margin:0;font-size:14px;font-weight:700;">🎯 Target Allocation</h3>
        <button class="btn btn-ghost btn-sm" style="font-size:11px;" onclick="rbSaveTargets()">💾 Save</button>
      </div>
      <div style="padding:16px 18px;">

        <div class="rb-slider-row">
          <span class="rb-slider-label"><span style="color:#2563eb;">📈</span> Equity</span>
          <input type="range" id="slEquity" min="0" max="100" step="5" value="60" oninput="rbUpdateSliders()">
          <span class="rb-slider-pct" id="slEquityVal">60%</span>
        </div>
        <div class="rb-slider-row">
          <span class="rb-slider-label"><span style="color:#d97706;">🏦</span> Debt</span>
          <input type="range" id="slDebt" min="0" max="100" step="5" value="25" oninput="rbUpdateSliders()">
          <span class="rb-slider-pct" id="slDebtVal">25%</span>
        </div>
        <div class="rb-slider-row">
          <span class="rb-slider-label"><span style="color:#f59e0b;">🥇</span> Gold</span>
          <input type="range" id="slGold" min="0" max="50" step="5" value="5" oninput="rbUpdateSliders()">
          <span class="rb-slider-pct" id="slGoldVal">5%</span>
        </div>
        <div class="rb-slider-row">
          <span class="rb-slider-label"><span style="color:#7c3aed;">🏛️</span> NPS</span>
          <input type="range" id="slNPS" min="0" max="50" step="5" value="5" oninput="rbUpdateSliders()">
          <span class="rb-slider-pct" id="slNPSVal">5%</span>
        </div>
        <div class="rb-slider-row">
          <span class="rb-slider-label"><span style="color:#0f766e;">🏠</span> Real Estate</span>
          <input type="range" id="slRE" min="0" max="80" step="5" value="5" oninput="rbUpdateSliders()">
          <span class="rb-slider-pct" id="slREVal">5%</span>
        </div>

        <!-- Total indicator -->
        <div class="rb-total-check" id="rbTotalCheck" style="background:rgba(22,163,74,.1);color:#16a34a;">
          Total: <span id="rbTotalSum">100</span>% ✅
        </div>

        <!-- Threshold -->
        <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--border);">
          <div style="font-size:12px;font-weight:600;margin-bottom:6px;">⚡ Alert Threshold</div>
          <div class="rb-slider-row" style="margin-bottom:0;">
            <span style="font-size:12px;color:var(--text-secondary);">Suggest when drift ></span>
            <input type="range" id="slThreshold" min="1" max="15" step="1" value="5" oninput="rbUpdateThreshold()" style="flex:1;accent-color:var(--accent);">
            <span class="rb-slider-pct" id="slThresholdVal" style="color:#f59e0b;">5%</span>
          </div>
        </div>

        <button class="btn btn-primary btn-sm" style="width:100%;margin-top:14px;" onclick="rbLoad()">
          ▶ Calculate Drift
        </button>
      </div>
    </div>

    <!-- Current vs Target donut -->
    <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:16px 18px;">
      <h3 style="margin:0 0 12px;font-size:14px;font-weight:700;">📊 Current Allocation</h3>
      <canvas id="rbDonut" height="180" style="max-width:180px;display:block;margin:0 auto;"></canvas>
      <div class="rb-legend" id="rbLegend"></div>
    </div>
  </div>

  <!-- ═══ RIGHT: RESULTS PANEL ═════════════════════════════════ -->
  <div>

    <!-- Portfolio total + strip -->
    <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:16px 20px;margin-bottom:16px;">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <div>
          <div style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-secondary);">Total Portfolio</div>
          <div style="font-size:24px;font-weight:800;" id="rbTotalVal">—</div>
          <div style="font-size:11px;color:var(--text-secondary);margin-top:2px;" id="rbGenAt"></div>
        </div>
        <div id="rbBalancedBadge"></div>
      </div>
      <!-- Allocation strip -->
      <div style="margin-top:10px;">
        <div style="font-size:10px;color:var(--text-secondary);margin-bottom:4px;">Current vs Target</div>
        <div class="rb-summary-strip" id="rbStrip" style="height:8px;"></div>
        <div class="rb-summary-strip" id="rbTargetStrip" style="height:4px;margin-top:3px;opacity:.4;"></div>
        <div style="display:flex;gap:10px;margin-top:5px;flex-wrap:wrap;" id="rbStripLegend"></div>
      </div>
    </div>

    <!-- Asset class drift cards -->
    <div id="rbClassCards">
      <div style="text-align:center;padding:40px;color:var(--text-secondary);">Calculating drift…</div>
    </div>

    <!-- Suggestions -->
    <div id="rbSuggestions" style="margin-top:4px;"></div>

    <!-- Concentration risks -->
    <div id="rbConcentration" style="margin-top:16px;"></div>

    <!-- SIP rebalancing hint -->
    <div id="rbSipHint" style="margin-top:12px;"></div>

  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script>
(function(){
  const API = '<?= APP_URL ?>/api/index.php';
  const CLASS_COLORS = {
    equity:'#2563eb', debt:'#d97706', gold:'#f59e0b',
    nps:'#7c3aed', real_estate:'#0f766e',
  };
  let donutChart = null;
  let lastData   = null;

  // ── Init: load saved targets, then run ───────────────────────
  async function init() {
    try {
      const r = await fetch(`${API}?action=rebalancing_load_targets`);
      const d = await r.json();
      if (d.success) applyTargets(d);
    } catch(e) {}
    rbUpdateSliders();
    rbLoad();
  }

  function applyTargets(d) {
    setValue('slEquity',    d.equity    ?? 60);
    setValue('slDebt',      d.debt      ?? 25);
    setValue('slGold',      d.gold      ?? 5);
    setValue('slNPS',       d.nps       ?? 5);
    setValue('slRE',        d.real_estate ?? 5);
    setValue('slThreshold', d.threshold ?? 5);
    rbUpdateSliders();
    rbUpdateThreshold();
  }

  function setValue(id, v) {
    const el = document.getElementById(id);
    if (el) el.value = v;
  }

  // ── Slider sync ───────────────────────────────────────────────
  window.rbUpdateSliders = function() {
    const ids = ['slEquity','slDebt','slGold','slNPS','slRE'];
    const labels = ['slEquityVal','slDebtVal','slGoldVal','slNPSVal','slREVal'];
    let total = 0;
    ids.forEach((id,i) => {
      const v = parseInt(document.getElementById(id)?.value ?? 0);
      total += v;
      const lbl = document.getElementById(labels[i]);
      if (lbl) lbl.textContent = v + '%';
    });
    const chk = document.getElementById('rbTotalCheck');
    const sum = document.getElementById('rbTotalSum');
    if (sum) sum.textContent = total;
    if (chk) {
      if (total === 100) { chk.style.background='rgba(22,163,74,.1)'; chk.style.color='#16a34a'; chk.innerHTML='Total: <span id="rbTotalSum">'+total+'</span>% ✅'; }
      else { chk.style.background='rgba(220,38,38,.1)'; chk.style.color='#dc2626'; chk.innerHTML='Total: <span id="rbTotalSum">'+total+'</span>% ❌ (must be 100)'; }
    }
  };

  window.rbUpdateThreshold = function() {
    const v = document.getElementById('slThreshold')?.value ?? 5;
    const lbl = document.getElementById('slThresholdVal');
    if (lbl) lbl.textContent = v + '%';
  };

  // ── Save targets ──────────────────────────────────────────────
  window.rbSaveTargets = async function() {
    const body = new URLSearchParams({
      action:       'rebalancing_save_targets',
      equity:       document.getElementById('slEquity').value,
      debt:         document.getElementById('slDebt').value,
      gold:         document.getElementById('slGold').value,
      nps:          document.getElementById('slNPS').value,
      real_estate:  document.getElementById('slRE').value,
      threshold:    document.getElementById('slThreshold').value,
    });
    const r = await fetch(API, { method:'POST', body });
    const d = await r.json();
    showToast(d.success ? '✅ Targets saved!' : '❌ ' + d.message, d.success ? 'success' : 'error');
  };

  // ── Main load ─────────────────────────────────────────────────
  window.rbLoad = async function() {
    document.getElementById('rbClassCards').innerHTML =
      '<div style="text-align:center;padding:40px;color:var(--text-secondary);">⏳ Calculating drift…</div>';
    document.getElementById('rbSuggestions').innerHTML = '';
    document.getElementById('rbConcentration').innerHTML = '';
    document.getElementById('rbSipHint').innerHTML = '';

    const total = parseInt(document.getElementById('rbTotalSum')?.textContent ?? '0');
    if (total !== 100) {
      document.getElementById('rbClassCards').innerHTML =
        '<div style="text-align:center;padding:20px;color:#dc2626;">⚠️ Target allocation must sum to 100% before calculating.</div>';
      return;
    }

    const body = new URLSearchParams({
      action:      'report_rebalancing',
      equity:       document.getElementById('slEquity').value,
      debt:         document.getElementById('slDebt').value,
      gold:         document.getElementById('slGold').value,
      nps:          document.getElementById('slNPS').value,
      real_estate:  document.getElementById('slRE').value,
      threshold:    document.getElementById('slThreshold').value,
    });

    try {
      const r = await fetch(API, { method:'POST', body });
      const d = await r.json();
      if (!d.success) { showErr(d.message); return; }
      lastData = d;
      renderHeader(d);
      renderClassCards(d.classes);
      renderDonut(d.classes);
      renderSuggestions(d.suggestions, d.is_balanced);
      renderConcentration(d.concentration_risks);
      renderSipHint(d);
    } catch(e) {
      showErr('Failed to load rebalancing data: ' + e.message);
    }
  };

  function renderHeader(d) {
    document.getElementById('rbTotalVal').textContent = '₹' + fmt(d.total_portfolio);
    document.getElementById('rbGenAt').textContent = 'As of ' + (d.generated_at ?? '');
    document.getElementById('rbBalancedBadge').innerHTML = d.is_balanced
      ? '<span style="background:rgba(22,163,74,.12);color:#16a34a;padding:6px 14px;border-radius:20px;font-weight:700;font-size:13px;">✅ Portfolio Balanced</span>'
      : `<span style="background:rgba(220,38,38,.1);color:#dc2626;padding:6px 14px;border-radius:20px;font-weight:700;font-size:13px;">⚠️ ${d.suggestions.length} Adjustment${d.suggestions.length>1?'s':''} Needed</span>`;

    // Strip bars
    const strip = document.getElementById('rbStrip');
    const targetStrip = document.getElementById('rbTargetStrip');
    const legend = document.getElementById('rbStripLegend');
    if (strip && d.classes) {
      strip.innerHTML = d.classes.map(c =>
        `<div class="rb-strip-seg" style="width:${c.current_pct}%;background:${CLASS_COLORS[c.key]};" title="${c.label}: ${c.current_pct}%"></div>`
      ).join('');
      targetStrip.innerHTML = d.classes.map(c =>
        `<div class="rb-strip-seg" style="width:${c.target_pct}%;background:${CLASS_COLORS[c.key]};" title="${c.label} target: ${c.target_pct}%"></div>`
      ).join('');
      legend.innerHTML = d.classes.map(c =>
        `<span style="font-size:10px;color:var(--text-secondary);display:flex;align-items:center;gap:3px;">
          <span style="width:8px;height:8px;border-radius:50%;background:${CLASS_COLORS[c.key]};display:inline-block;"></span>
          ${c.emoji} ${c.label}: ${c.current_pct}%
        </span>`
      ).join('');
    }
  }

  function renderClassCards(classes) {
    document.getElementById('rbClassCards').innerHTML = classes.map(c => {
      const cl    = CLASS_COLORS[c.key] || '#6366f1';
      const drift = c.drift_pct;
      const absDrift = Math.abs(drift);
      const statusColor = c.status === 'ok' ? '#16a34a' : c.status === 'over' ? '#dc2626' : '#d97706';
      const statusLabel = c.status === 'ok' ? '✅ On Target' : c.status === 'over' ? '↑ Overweight' : '↓ Underweight';

      // Drift bar: center = 0, left = under, right = over
      // Scale: max drift shown = 30%
      const maxDrift = 30;
      const barW  = Math.min(100, (absDrift / maxDrift) * 50); // % of half-bar
      const barLeft  = drift < 0 ? (50 - barW) + '%' : '50%';
      const barColor = drift > 0 ? '#dc2626' : '#d97706';

      // Breakdown items
      const bdItems = (c.breakdown || []).filter(b => b.value > 0)
        .map(b => `<span>• ${esc(b.name)}: ₹${fmt(b.value)}</span>`).join('');

      return `
        <div class="rb-class-card" style="border-left-color:${cl};">
          <div class="rb-class-head">
            <div class="rb-class-label" style="color:${cl};">${c.emoji} ${esc(c.label)}</div>
            <span class="rb-status-badge" style="background:${statusColor}18;color:${statusColor};">${statusLabel}</span>
          </div>

          <!-- Values row -->
          <div style="display:flex;gap:20px;margin-bottom:8px;flex-wrap:wrap;">
            <div>
              <div style="font-size:10px;color:var(--text-secondary);">Current</div>
              <div style="font-weight:700;font-size:15px;color:${cl};">₹${fmt(c.current)} <span style="font-size:12px;">(${c.current_pct}%)</span></div>
            </div>
            <div>
              <div style="font-size:10px;color:var(--text-secondary);">Target</div>
              <div style="font-weight:700;font-size:15px;">₹${fmt(c.target_value)} <span style="font-size:12px;">(${c.target_pct}%)</span></div>
            </div>
            <div>
              <div style="font-size:10px;color:var(--text-secondary);">Drift</div>
              <div style="font-weight:800;font-size:15px;color:${statusColor};">${drift > 0 ? '+' : ''}${drift}%</div>
            </div>
          </div>

          <!-- Drift bar -->
          <div class="rb-drift-bar-wrap">
            <div class="rb-drift-center"></div>
            <div class="rb-drift-fill" style="left:${barLeft};width:${barW}%;background:${barColor};opacity:.8;"></div>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:9px;color:var(--text-secondary);margin-bottom:6px;">
            <span>Underweight</span><span>On Target</span><span>Overweight</span>
          </div>

          <!-- Breakdown -->
          ${bdItems ? `<div class="rb-breakdown">${bdItems}</div>` : ''}
        </div>`;
    }).join('');
  }

  function renderDonut(classes) {
    const ctx = document.getElementById('rbDonut').getContext('2d');
    if (donutChart) donutChart.destroy();
    const labels = classes.map(c => c.emoji + ' ' + c.label);
    const data   = classes.map(c => c.current_pct);
    const colors = classes.map(c => CLASS_COLORS[c.key]);

    donutChart = new Chart(ctx, {
      type: 'doughnut',
      data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth:2, borderColor:'var(--card-bg)' }] },
      options: {
        responsive:true, cutout:'70%',
        plugins: { legend:{display:false}, tooltip:{ callbacks:{ label: c => ` ${c.label}: ${c.parsed}%` } } }
      }
    });

    // Legend
    const leg = document.getElementById('rbLegend');
    if (leg) {
      leg.innerHTML = classes.map(c => `
        <div class="rb-legend-row">
          <span class="rb-legend-dot" style="background:${CLASS_COLORS[c.key]};"></span>
          <span class="rb-legend-name">${c.emoji} ${c.label}</span>
          <span class="rb-legend-pct" style="color:${CLASS_COLORS[c.key]};">${c.current_pct}%</span>
          <span class="rb-legend-target">↔ ${c.target_pct}%</span>
        </div>`).join('');
    }
  }

  function renderSuggestions(sug, isBalanced) {
    const el = document.getElementById('rbSuggestions');
    if (isBalanced || !sug.length) {
      el.innerHTML = `
        <div class="rb-balanced">
          <div class="rb-balanced-icon">✅</div>
          <h3 style="margin:0 0 6px;font-size:17px;font-weight:700;">Portfolio is Balanced!</h3>
          <p style="color:var(--text-secondary);font-size:13px;margin:0;">All asset classes are within the drift threshold. No rebalancing needed right now.</p>
        </div>`;
      return;
    }

    el.innerHTML = `
      <h3 style="margin:0 0 12px;font-size:15px;font-weight:700;">🔧 Rebalancing Actions</h3>
      ${sug.map(s => `
        <div class="rb-sug-card rb-sug-${s.action.toLowerCase()}">
          <div class="rb-sug-head">
            <span style="font-size:20px;">${s.emoji}</span>
            <strong style="font-size:14px;">${esc(s.label ?? s.asset_class)}</strong>
            <span class="rb-action-pill rb-${s.action.toLowerCase()}-pill">${s.action}</span>
            <span style="margin-left:auto;font-size:18px;font-weight:800;color:${s.action==='REDUCE'?'#dc2626':'#16a34a'};">
              ${s.action==='REDUCE'?'−':'+'} ₹${fmt(s.amount)}
            </span>
          </div>
          <div style="font-size:13px;color:var(--text-secondary);">${esc(s.message)}</div>
          <div style="display:flex;gap:16px;margin-top:6px;font-size:11px;color:var(--text-secondary);">
            <span>Current: <strong>${s.current_pct}%</strong></span>
            <span>Target: <strong>${s.target_pct}%</strong></span>
            <span>Drift: <strong style="color:${Math.abs(s.drift_pct)>10?'#dc2626':'#d97706'};">${s.drift_pct>0?'+':''}${s.drift_pct}%</strong></span>
          </div>
          <div class="rb-tax-hint">
            💸 <strong>Tax note:</strong> ${esc(s.tax_note ?? '')}
            <br>💡 <strong>Hint:</strong> ${esc(s.rebal_hint ?? '')}
          </div>
        </div>`).join('')}`;
  }

  function renderConcentration(risks) {
    const el = document.getElementById('rbConcentration');
    if (!risks?.length) { el.innerHTML = ''; return; }
    el.innerHTML = `
      <div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;overflow:hidden;">
        <div style="padding:12px 18px;border-bottom:1px solid var(--border);background:rgba(245,158,11,.06);">
          <h3 style="margin:0;font-size:14px;font-weight:700;">⚠️ Concentration Risk</h3>
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:13px;">
          <thead>
            <tr style="border-bottom:1px solid var(--border);background:var(--surface-secondary,rgba(0,0,0,.03));">
              <th style="padding:8px 16px;text-align:left;font-size:11px;font-weight:600;color:var(--text-secondary);">Type</th>
              <th style="padding:8px 16px;text-align:left;font-size:11px;font-weight:600;color:var(--text-secondary);">Name</th>
              <th style="padding:8px 16px;text-align:right;font-size:11px;font-weight:600;color:var(--text-secondary);">Value</th>
              <th style="padding:8px 16px;text-align:right;font-size:11px;font-weight:600;color:var(--text-secondary);">% of Portfolio</th>
              <th style="padding:8px 16px;font-size:11px;font-weight:600;color:var(--text-secondary);">Note</th>
            </tr>
          </thead>
          <tbody>
            ${risks.map(r => `
              <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:10px 16px;"><span style="background:rgba(245,158,11,.12);color:#d97706;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">${esc(r.type)}</span></td>
                <td style="padding:10px 16px;font-weight:600;">${esc(r.name)}</td>
                <td style="padding:10px 16px;text-align:right;">₹${fmt(r.value)}</td>
                <td style="padding:10px 16px;text-align:right;font-weight:700;color:#dc2626;">${r.pct}%</td>
                <td style="padding:10px 16px;font-size:11px;color:var(--text-secondary);">${esc(r.warning)}</td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>`;
  }

  function renderSipHint(d) {
    const el = document.getElementById('rbSipHint');
    if (!d.sip_rebal_feasible || !d.suggestions?.length) { el.innerHTML = ''; return; }
    el.innerHTML = `
      <div style="background:rgba(99,102,241,.06);border:1px solid rgba(99,102,241,.2);border-radius:10px;padding:14px 18px;">
        <strong style="font-size:13px;">💡 SIP-based Rebalancing (Tax-Free!)</strong>
        <p style="font-size:12px;color:var(--text-secondary);margin:6px 0 0;">
          You have active SIPs of ₹${fmt(d.sip_monthly)}/month. Instead of selling to rebalance (and paying tax),
          redirect new SIP investments towards underweight asset classes. This avoids capital gains tax entirely.
          <strong style="color:var(--text-primary);"> Preferred method for long-term investors.</strong>
        </p>
      </div>`;
  }

  // ── Export ────────────────────────────────────────────────────
  window.rbExport = function() {
    if (!lastData) return;
    const lines = [
      'WealthDash — Rebalancing Report',
      '================================',
      `Total Portfolio: ₹${fmt(lastData.total_portfolio)}`,
      `Status: ${lastData.is_balanced ? 'BALANCED' : 'NEEDS REBALANCING'}`,
      '',
      'CURRENT ALLOCATION:',
      ...lastData.classes.map(c => `  ${c.emoji} ${c.label}: ${c.current_pct}% (Target: ${c.target_pct}%, Drift: ${c.drift_pct > 0 ? '+' : ''}${c.drift_pct}%)`),
      '',
      'ACTIONS REQUIRED:',
      ...(lastData.suggestions.length
        ? lastData.suggestions.map(s => `  ${s.action} ${s.emoji} ${s.asset_class}: ₹${fmt(s.amount)} (${s.drift_pct > 0 ? '+' : ''}${s.drift_pct}% drift)`)
        : ['  None — portfolio is balanced']),
      '',
      `Generated: ${lastData.generated_at}`,
    ];
    navigator.clipboard?.writeText(lines.join('\n'))
      .then(() => showToast('✅ Summary copied to clipboard!'))
      .catch(() => showToast('Could not copy — try manually'));
  };

  function showErr(msg) {
    document.getElementById('rbClassCards').innerHTML =
      `<div style="text-align:center;padding:30px;color:#dc2626;">${esc(msg)}</div>`;
  }

  function showToast(msg) {
    const t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#1e293b;color:#fff;padding:10px 18px;border-radius:8px;font-size:13px;z-index:9999;box-shadow:0 4px 16px rgba(0,0,0,.3);';
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 3000);
  }

  function fmt(n) {
    n = parseFloat(n) || 0;
    if (n >= 1e7) return (n/1e7).toFixed(2) + 'Cr';
    if (n >= 1e5) return (n/1e5).toFixed(2) + 'L';
    return n.toLocaleString('en-IN', {maximumFractionDigits:0});
  }
  function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  init();
})();
</script>

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
?>
