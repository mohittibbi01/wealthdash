<?php
/**
 * WealthDash — tg002: Bucket Strategy Page
 * Classic 3-bucket financial planning: Safety / Stable / Growth
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Bucket Strategy';
$activePage  = 'bucket_strategy';

ob_start();
?>
<style>
/* ── Bucket Strategy Styles ──────────────────────────────── */
.bs-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:18px; margin-bottom:24px; }
@media(max-width:900px){ .bs-grid { grid-template-columns:1fr; } }

/* Bucket Container — visual "bucket" with water fill */
.bs-bucket-card {
  background:var(--card-bg);
  border:2px solid var(--border);
  border-radius:16px;
  overflow:hidden;
  position:relative;
  transition:box-shadow .2s;
}
.bs-bucket-card:hover { box-shadow:0 8px 32px rgba(0,0,0,.1); }

.bs-bucket-header {
  padding:18px 20px 14px;
  position:relative;
  z-index:2;
}
.bs-bucket-emoji { font-size:28px; line-height:1; margin-bottom:6px; }
.bs-bucket-name  { font-size:16px; font-weight:800; margin:0 0 2px; }
.bs-bucket-horizon{ font-size:11px; font-weight:600; opacity:.7; text-transform:uppercase; letter-spacing:.5px; }

/* Water fill animation */
.bs-fill-wrap {
  position:relative;
  height:12px;
  background:rgba(0,0,0,.06);
  margin:0 20px 16px;
  border-radius:6px;
  overflow:hidden;
}
.bs-fill-bar {
  position:absolute;
  left:0; top:0; height:100%;
  border-radius:6px;
  transition:width .8s cubic-bezier(.4,0,.2,1);
}
.bs-fill-labels {
  display:flex;
  justify-content:space-between;
  padding:0 20px;
  font-size:10px;
  color:var(--text-secondary);
  margin-bottom:12px;
}

.bs-bucket-stats {
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:8px;
  padding:0 20px 16px;
}
.bs-stat { background:rgba(0,0,0,.04); border-radius:8px; padding:10px 12px; }
.bs-stat-label { font-size:10px; text-transform:uppercase; letter-spacing:.4px; color:var(--text-secondary); }
.bs-stat-value { font-size:15px; font-weight:700; margin-top:3px; }

/* Health badge */
.bs-health-badge {
  display:inline-flex; align-items:center; gap:4px;
  padding:3px 10px; border-radius:20px;
  font-size:11px; font-weight:700;
  margin:0 20px 14px;
}

/* Items accordion */
.bs-items-toggle {
  padding:8px 20px 14px;
  font-size:12px;
  color:var(--text-secondary);
  cursor:pointer;
  display:flex;
  align-items:center;
  gap:6px;
  user-select:none;
}
.bs-items-toggle:hover { color:var(--text-primary); }
.bs-items-list { display:none; border-top:1px solid var(--border); }
.bs-items-list.open { display:block; }
.bs-item-row {
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:9px 20px;
  border-bottom:1px solid var(--border);
  font-size:12px;
  gap:8px;
}
.bs-item-row:last-child { border-bottom:none; }
.bs-item-type-badge {
  padding:2px 7px; border-radius:12px; font-size:10px; font-weight:700;
  background:rgba(99,102,241,.1); color:#6366f1; flex-shrink:0;
}

/* Goals section per bucket */
.bs-goals-section { padding:10px 20px 16px; border-top:1px solid var(--border); }
.bs-goals-title { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:var(--text-secondary); margin-bottom:8px; }
.bs-goal-pill {
  display:flex; align-items:center; gap:8px;
  padding:7px 10px; border-radius:8px;
  background:var(--surface-secondary, rgba(0,0,0,.03));
  margin-bottom:6px; font-size:12px;
}
.bs-goal-progress-mini { flex:1; height:4px; background:var(--border); border-radius:2px; overflow:hidden; }
.bs-goal-progress-fill { height:100%; border-radius:2px; }

/* Alerts */
.bs-alert { display:flex; gap:10px; align-items:flex-start; padding:12px 16px; border-radius:10px; font-size:13px; margin-bottom:10px; }
.bs-alert-high   { background:rgba(220,38,38,.08);  border:1px solid rgba(220,38,38,.25);  }
.bs-alert-medium { background:rgba(245,158,11,.08); border:1px solid rgba(245,158,11,.25); }
.bs-alert-low    { background:rgba(99,102,241,.08); border:1px solid rgba(99,102,241,.25); }
.bs-alert-ok     { background:rgba(22,163,74,.08);  border:1px solid rgba(22,163,74,.25);  }

/* Allocation editor */
.bs-slider-row { display:flex; align-items:center; gap:12px; margin-bottom:12px; }
.bs-slider-row label { width:100px; font-size:13px; font-weight:600; flex-shrink:0; }
.bs-slider-row input[type=range] { flex:1; accent-color:var(--accent); }
.bs-slider-val { width:40px; text-align:right; font-weight:700; font-size:13px; }

/* Summary bar */
.bs-summary-bar {
  display:flex; gap:0; height:20px; border-radius:10px; overflow:hidden;
  margin:12px 0 4px;
}
.bs-summary-bar-seg { transition:width .5s; }

/* Donut chart canvas */
#bsDonut { display:block; margin:0 auto; }

.bs-legend { display:flex; flex-direction:column; gap:6px; }
.bs-legend-item { display:flex; align-items:center; gap:8px; font-size:12px; }
.bs-legend-dot { width:10px; height:10px; border-radius:50%; flex-shrink:0; }
</style>

<!-- Page Header -->
<div class="page-header" style="margin-bottom:24px;">
  <div>
    <h1 class="page-title">🪣 Bucket Strategy</h1>
    <p class="page-subtitle">Classic 3-bucket financial planning — Safety · Stable · Growth</p>
  </div>
  <div class="page-actions">
    <button class="btn btn-ghost btn-sm" onclick="bsOpenTargetModal()">⚙️ Edit Targets</button>
    <button class="btn btn-primary btn-sm" onclick="bsLoad()">🔄 Refresh</button>
  </div>
</div>

<!-- Summary Strip -->
<div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:18px 22px;margin-bottom:20px;">
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div style="display:flex;gap:28px;flex-wrap:wrap;">
      <div>
        <div style="font-size:11px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.4px;">Total Portfolio</div>
        <div style="font-size:22px;font-weight:800;" id="bsTotalValue">—</div>
      </div>
      <div>
        <div style="font-size:11px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.4px;">Total Gain</div>
        <div style="font-size:22px;font-weight:800;" id="bsTotalGain">—</div>
      </div>
      <div>
        <div style="font-size:11px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.4px;">Goals Tracked</div>
        <div style="font-size:22px;font-weight:800;" id="bsTotalGoals">—</div>
      </div>
    </div>
    <!-- Allocation bar -->
    <div style="min-width:220px;flex:1;max-width:360px;">
      <div style="font-size:11px;color:var(--text-secondary);margin-bottom:6px;">Bucket Allocation</div>
      <div class="bs-summary-bar" id="bsSummaryBar">
        <div class="bs-summary-bar-seg" style="background:#0ea5e9;width:33.3%;" title="Safety"></div>
        <div class="bs-summary-bar-seg" style="background:#8b5cf6;width:33.3%;" title="Stable"></div>
        <div class="bs-summary-bar-seg" style="background:#16a34a;width:33.4%;" title="Growth"></div>
      </div>
      <div style="display:flex;gap:12px;margin-top:5px;font-size:11px;color:var(--text-secondary);" id="bsBarLegend">
        <span>🛡️ <span id="bsPct1">—</span>%</span>
        <span>⚖️ <span id="bsPct2">—</span>%</span>
        <span>🚀 <span id="bsPct3">—</span>%</span>
      </div>
    </div>
  </div>
</div>

<!-- Alerts -->
<div id="bsAlerts" style="margin-bottom:20px;"></div>

<!-- 3 Bucket Cards -->
<div class="bs-grid" id="bsGrid">
  <!-- Skeleton -->
  <?php foreach([['🛡️','Safety Bucket','0–2 years','#0ea5e9'],['⚖️','Stable Bucket','2–5 years','#8b5cf6'],['🚀','Growth Bucket','5+ years','#16a34a']] as [$em,$nm,$hz,$cl]): ?>
  <div class="bs-bucket-card" style="border-color:<?=$cl?>44;">
    <div class="bs-bucket-header">
      <div class="bs-bucket-emoji"><?=$em?></div>
      <div class="bs-bucket-name" style="color:<?=$cl?>"><?=$nm?></div>
      <div class="bs-bucket-horizon"><?=$hz?></div>
    </div>
    <div style="padding:20px;text-align:center;color:var(--text-secondary);font-size:13px;">Loading…</div>
  </div>
  <?php endforeach; ?>
</div>

<!-- Instruments Guide -->
<div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:20px 22px;margin-bottom:20px;">
  <h3 style="margin:0 0 14px;font-size:15px;font-weight:700;">📋 Recommended Instruments per Bucket</h3>
  <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">
    <?php
    $guide = [
      ['🛡️','Safety','#0ea5e9',['FD (≤2yr)','Savings Account','Liquid MF','Ultra Short MF','Money Market MF','Overnight MF']],
      ['⚖️','Stable','#8b5cf6',['Short/Medium Duration Debt MF','Balanced Hybrid MF','Balanced Advantage Fund','NPS (Debt: G/C class)','SGB / Gold ETF','EPF / PPF']],
      ['🚀','Growth','#16a34a',['Large Cap / Flexicap MF','Mid/Small Cap MF','ELSS / Tax Saver MF','Index Funds / ETF','Direct Equity (Stocks)','NPS (Equity: E class)']],
    ];
    foreach($guide as [$em,$nm,$cl,$items]): ?>
    <div style="background:<?=$cl?>11;border:1px solid <?=$cl?>33;border-radius:10px;padding:14px 16px;">
      <div style="font-weight:700;color:<?=$cl?>;margin-bottom:10px;font-size:13px;"><?=$em?> <?=$nm?></div>
      <?php foreach($items as $it): ?>
      <div style="font-size:12px;color:var(--text-secondary);padding:3px 0;border-bottom:1px solid <?=$cl?>22;display:flex;align-items:center;gap:6px;">
        <span style="color:<?=$cl?>;font-size:10px;">●</span><?=$it?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<!-- How It Works -->
<div style="background:var(--card-bg);border:1px solid var(--border);border-radius:12px;padding:20px 22px;">
  <h3 style="margin:0 0 12px;font-size:15px;font-weight:700;">💡 How Bucket Strategy Works</h3>
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;font-size:13px;color:var(--text-secondary);">
    <div><strong style="color:var(--text-primary);">1. Separate by Timeline</strong><br>Divide your portfolio by when you'll need the money — near, medium, or long term.</div>
    <div><strong style="color:var(--text-primary);">2. Match Risk to Time</strong><br>Near-term money stays safe (FD, liquid). Long-term money chases growth (equity). This prevents panic-selling.</div>
    <div><strong style="color:var(--text-primary);">3. Replenish Regularly</strong><br>When Safety bucket drops below target, move funds from Stable → Safety. When Stable drops, sell Growth → Stable.</div>
    <div><strong style="color:var(--text-primary);">4. Classic Allocation</strong><br>Typical: 10% Safety · 20% Stable · 70% Growth. Adjust based on age, income stability, and upcoming goals.</div>
  </div>
</div>

<!-- Target Edit Modal -->
<div class="modal fade" id="bsTargetModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog" style="max-width:440px;">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">⚙️ Edit Bucket Target Allocation</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p style="font-size:13px;color:var(--text-secondary);margin-bottom:16px;">Set your ideal % allocation for each bucket. Must sum to 100%.</p>
        <div class="bs-slider-row">
          <label style="color:#0ea5e9;">🛡️ Safety</label>
          <input type="range" id="bsT1" min="0" max="50" step="5" value="10"
            oninput="bsUpdateSliders()">
          <span class="bs-slider-val" id="bsT1Val" style="color:#0ea5e9;">10%</span>
        </div>
        <div class="bs-slider-row">
          <label style="color:#8b5cf6;">⚖️ Stable</label>
          <input type="range" id="bsT2" min="0" max="60" step="5" value="20"
            oninput="bsUpdateSliders()">
          <span class="bs-slider-val" id="bsT2Val" style="color:#8b5cf6;">20%</span>
        </div>
        <div class="bs-slider-row">
          <label style="color:#16a34a;">🚀 Growth</label>
          <input type="range" id="bsT3" min="10" max="100" step="5" value="70"
            oninput="bsUpdateSliders()">
          <span class="bs-slider-val" id="bsT3Val" style="color:#16a34a;">70%</span>
        </div>
        <div style="text-align:center;font-size:13px;margin-top:8px;" id="bsTotalCheck">
          Total: <strong id="bsTotalSum">100</strong>% <span id="bsTotalOk">✅</span>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-primary btn-sm" onclick="bsSaveTargets()">Save Targets</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const API = '<?= APP_URL ?>/api/index.php';
  const COLORS = { 1:'#0ea5e9', 2:'#8b5cf6', 3:'#16a34a' };

  async function bsLoad() {
    window.bsLoad = bsLoad;
    document.getElementById('bsGrid').innerHTML = `
      ${[1,2,3].map(()=>'<div class="bs-bucket-card" style="padding:40px;text-align:center;color:var(--text-secondary);">Loading…</div>').join('')}`;
    document.getElementById('bsAlerts').innerHTML = '';

    try {
      const r = await fetch(`${API}?action=bucket_strategy_summary`);
      const d = await r.json();
      if (!d.success) { showErr(d.message); return; }
      renderSummary(d);
      renderBuckets(d.buckets);
      renderAlerts(d.alerts);
    } catch(e) {
      showErr('Failed to load bucket data. Please try again.');
    }
  }

  function renderSummary(d) {
    const gainClr = d.total_gain >= 0 ? '#16a34a' : '#dc2626';
    document.getElementById('bsTotalValue').textContent = '₹' + fmt(d.total_value);
    document.getElementById('bsTotalGain').style.color = gainClr;
    document.getElementById('bsTotalGain').textContent = (d.total_gain >= 0 ? '+' : '') + '₹' + fmt(Math.abs(d.total_gain));
    document.getElementById('bsTotalGoals').textContent = d.total_goals ?? '—';

    // Allocation bar
    const b1 = d.buckets[0], b2 = d.buckets[1], b3 = d.buckets[2];
    const segs = document.querySelectorAll('.bs-summary-bar-seg');
    segs[0].style.width = b1.actual_pct + '%';
    segs[1].style.width = b2.actual_pct + '%';
    segs[2].style.width = b3.actual_pct + '%';
    document.getElementById('bsPct1').textContent = b1.actual_pct;
    document.getElementById('bsPct2').textContent = b2.actual_pct;
    document.getElementById('bsPct3').textContent = b3.actual_pct;
  }

  function renderBuckets(buckets) {
    const grid = document.getElementById('bsGrid');
    grid.innerHTML = buckets.map(b => {
      const cl = COLORS[b.id];
      const gainClr = b.gain >= 0 ? '#16a34a' : '#dc2626';

      // Goal pills
      const goalsPills = (b.goals || []).length
        ? `<div class="bs-goals-section">
            <div class="bs-goals-title">🎯 Goals in this bucket (${b.goals.length})</div>
            ${b.goals.map(g => `
              <div class="bs-goal-pill">
                <span style="font-size:16px;">${g.icon === 'target' ? '🎯' : g.icon}</span>
                <div style="flex:1;min-width:0;">
                  <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${esc(g.name)}</div>
                  <div style="font-size:10px;color:var(--text-secondary);">${g.months_left}mo left · ₹${fmt(g.target_amount)}</div>
                </div>
                <div style="text-align:right;flex-shrink:0;">
                  <div style="font-weight:700;font-size:12px;color:${g.progress >= 100 ? '#16a34a' : cl};">${g.progress}%</div>
                  <div class="bs-goal-progress-mini" style="margin-top:3px;">
                    <div class="bs-goal-progress-fill" style="width:${g.progress}%;background:${cl};"></div>
                  </div>
                </div>
              </div>`).join('')}
           </div>`
        : `<div style="padding:8px 20px 12px;font-size:11px;color:var(--text-secondary);">No goals in this time bucket.</div>`;

      // Holdings items
      const itemsList = (b.items || []).length
        ? `<div class="bs-items-list" id="bsItems${b.id}">
            ${b.items.map(it => `
              <div class="bs-item-row">
                <span class="bs-item-type-badge">${it.type}</span>
                <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${esc(it.name)}</span>
                <span style="font-weight:700;flex-shrink:0;">₹${fmt(it.value)}</span>
                <span style="font-size:11px;color:${it.gain_pct >= 0 ? '#16a34a' : '#dc2626'};flex-shrink:0;min-width:45px;text-align:right;">
                  ${it.gain_pct >= 0 ? '+' : ''}${it.gain_pct}%
                </span>
              </div>`).join('')}
           </div>` : '';

      const toggleLabel = b.item_count ? `${b.item_count} holding${b.item_count !== 1 ? 's' : ''} — click to expand` : 'No holdings classified here';

      return `<div class="bs-bucket-card" style="border-color:${cl}55;">
        <div class="bs-bucket-header" style="background:${cl}0d;">
          <div class="bs-bucket-emoji">${b.emoji}</div>
          <div class="bs-bucket-name" style="color:${cl}">${b.name}</div>
          <div class="bs-bucket-horizon">${b.horizon}</div>
          <div style="font-size:11px;color:var(--text-secondary);margin-top:4px;">${esc(b.purpose)}</div>
        </div>

        <!-- Fill bar: actual vs ideal -->
        <div style="padding:12px 20px 0;">
          <div style="display:flex;justify-content:space-between;font-size:10px;color:var(--text-secondary);margin-bottom:4px;">
            <span>Actual: <strong style="color:${cl};">${b.actual_pct}%</strong></span>
            <span>Target: ${b.ideal_pct}%</span>
          </div>
          <div class="bs-fill-wrap">
            <div class="bs-fill-bar" style="width:${Math.min(100, b.actual_pct / (b.ideal_pct || 1) * 100)}%;background:${cl};"></div>
          </div>
        </div>

        <!-- Health badge -->
        <div style="padding:0 20px 12px;">
          <span class="bs-health-badge" style="background:${b.health.color}18;color:${b.health.color};border:1px solid ${b.health.color}44;">
            ${b.health.status === 'ok' ? '✅' : b.health.status === 'over' ? '↑' : '↓'}
            ${b.health.label}
          </span>
        </div>

        <!-- Stats grid -->
        <div class="bs-bucket-stats">
          <div class="bs-stat">
            <div class="bs-stat-label">Current Value</div>
            <div class="bs-stat-value" style="color:${cl};">₹${fmt(b.value)}</div>
          </div>
          <div class="bs-stat">
            <div class="bs-stat-label">Gain / Loss</div>
            <div class="bs-stat-value" style="color:${gainClr};">${b.gain >= 0 ? '+' : ''}₹${fmt(Math.abs(b.gain))}</div>
          </div>
          <div class="bs-stat">
            <div class="bs-stat-label">Return</div>
            <div class="bs-stat-value" style="color:${gainClr};">${b.gain_pct >= 0 ? '+' : ''}${b.gain_pct}%</div>
          </div>
          <div class="bs-stat">
            <div class="bs-stat-label">Expected</div>
            <div class="bs-stat-value" style="font-size:12px;">${b.expected_return}</div>
          </div>
        </div>

        <!-- Goals -->
        ${goalsPills}

        <!-- Holdings toggle -->
        ${b.item_count ? `
        <div class="bs-items-toggle" onclick="bsToggleItems(${b.id},this)">
          <span id="bsArrow${b.id}">▶</span> ${toggleLabel}
        </div>
        ${itemsList}` : `<div style="padding:0 20px 14px;font-size:11px;color:var(--text-secondary);">No holdings in this bucket yet.</div>`}
      </div>`;
    }).join('');
  }

  function renderAlerts(alerts) {
    const el = document.getElementById('bsAlerts');
    if (!alerts?.length) { el.innerHTML = ''; return; }
    el.innerHTML = alerts.map(a => `
      <div class="bs-alert bs-alert-${a.severity}">
        <span style="font-size:18px;flex-shrink:0;">${a.emoji}</span>
        <div>
          <strong>${a.bucket}</strong>
          <div style="margin-top:2px;">${a.message}</div>
        </div>
      </div>`).join('');
  }

  window.bsToggleItems = function(id, el) {
    const list  = document.getElementById('bsItems' + id);
    const arrow = document.getElementById('bsArrow' + id);
    if (!list) return;
    const open = list.classList.toggle('open');
    arrow.textContent = open ? '▼' : '▶';
  };

  // ── Target Modal ──────────────────────────────────────────────
  window.bsOpenTargetModal = async function() {
    // Load saved targets
    try {
      const r = await fetch(`${API}?action=bucket_strategy_load`);
      const d = await r.json();
      if (d.success) {
        document.getElementById('bsT1').value = d.b1;
        document.getElementById('bsT2').value = d.b2;
        document.getElementById('bsT3').value = d.b3;
      }
    } catch(e) {}
    bsUpdateSliders();
    new bootstrap.Modal(document.getElementById('bsTargetModal')).show();
  };

  window.bsUpdateSliders = function() {
    const v1 = parseInt(document.getElementById('bsT1').value);
    const v2 = parseInt(document.getElementById('bsT2').value);
    const v3 = parseInt(document.getElementById('bsT3').value);
    const total = v1 + v2 + v3;
    document.getElementById('bsT1Val').textContent  = v1 + '%';
    document.getElementById('bsT2Val').textContent  = v2 + '%';
    document.getElementById('bsT3Val').textContent  = v3 + '%';
    document.getElementById('bsTotalSum').textContent = total;
    document.getElementById('bsTotalOk').textContent  = total === 100 ? '✅' : '❌ Must equal 100';
  };

  window.bsSaveTargets = async function() {
    const v1 = parseInt(document.getElementById('bsT1').value);
    const v2 = parseInt(document.getElementById('bsT2').value);
    const v3 = parseInt(document.getElementById('bsT3').value);
    if (v1 + v2 + v3 !== 100) { alert('Percentages must sum to 100!'); return; }
    const body = new URLSearchParams({ action:'bucket_strategy_save', bucket1_pct:v1, bucket2_pct:v2, bucket3_pct:v3 });
    try {
      const r = await fetch(API, { method:'POST', body });
      const d = await r.json();
      if (d.success) {
        bootstrap.Modal.getInstance(document.getElementById('bsTargetModal'))?.hide();
        bsLoad();
      } else alert(d.message);
    } catch(e) { alert('Save failed.'); }
  };

  function showErr(msg) {
    document.getElementById('bsGrid').innerHTML =
      `<div style="grid-column:1/-1;text-align:center;padding:40px;color:#dc2626;">${esc(msg)}</div>`;
  }

  // ── Helpers ───────────────────────────────────────────────────
  function fmt(n) {
    n = parseFloat(n) || 0;
    if (n >= 1e7) return (n/1e7).toFixed(2) + 'Cr';
    if (n >= 1e5) return (n/1e5).toFixed(2) + 'L';
    return n.toLocaleString('en-IN', {maximumFractionDigits:0});
  }
  function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  // Expose for header button
  window.bsLoad           = bsLoad;
  window.bsOpenTargetModal = window.bsOpenTargetModal;

  bsLoad();
})();
</script>

<?php
$content = ob_get_clean();
require APP_ROOT . '/templates/layout.php';
