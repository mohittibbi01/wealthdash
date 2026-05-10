<?php
/**
 * WealthDash — t300: Portfolio Heatmap
 * Interactive treemap: every holding as a colored cell sized by portfolio weight.
 * Green = gain, Red = loss. Group by: Asset Class / Sector / Fund House.
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

$currentUser = require_auth();
$pageTitle   = 'Portfolio Heatmap';
$activePage  = 'portfolio_heatmap';
$activeSection = 'reports';

ob_start();
?>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
  <div>
    <h1 class="page-title" style="margin:0;">Portfolio Heatmap</h1>
    <p style="color:var(--text-muted);font-size:13px;margin:4px 0 0;">
      All holdings — sized by value, coloured by total return
    </p>
  </div>
  <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
    <!-- Group By -->
    <div style="display:flex;gap:0;border:1px solid var(--border-color);border-radius:8px;overflow:hidden;">
      <button class="hm-group-btn btn-active" data-group="asset_class"
              style="padding:6px 12px;font-size:12px;font-weight:600;border:none;cursor:pointer;background:var(--primary);color:#fff;">
        Asset Class
      </button>
      <button class="hm-group-btn" data-group="sector"
              style="padding:6px 12px;font-size:12px;font-weight:600;border:none;cursor:pointer;background:var(--bg-secondary);color:var(--text-secondary);">
        Sector
      </button>
      <button class="hm-group-btn" data-group="fund_house"
              style="padding:6px 12px;font-size:12px;font-weight:600;border:none;cursor:pointer;background:var(--bg-secondary);color:var(--text-secondary);">
        Fund House
      </button>
    </div>
    <!-- Colour Mode -->
    <div style="display:flex;gap:0;border:1px solid var(--border-color);border-radius:8px;overflow:hidden;">
      <button class="hm-color-btn btn-active" data-mode="total"
              style="padding:6px 12px;font-size:12px;font-weight:600;border:none;cursor:pointer;background:var(--primary);color:#fff;">
        Total Return
      </button>
      <button class="hm-color-btn" data-mode="day"
              style="padding:6px 12px;font-size:12px;font-weight:600;border:none;cursor:pointer;background:var(--bg-secondary);color:var(--text-secondary);">
        1-Day Change
      </button>
    </div>
    <button id="hmRefresh" class="btn btn-ghost btn-sm">🔄</button>
  </div>
</div>

<!-- Summary strip -->
<div id="hmSummary" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;"></div>

<!-- Legend -->
<div style="display:flex;align-items:center;gap:6px;margin-bottom:12px;font-size:11px;flex-wrap:wrap;">
  <span style="color:var(--text-muted);font-weight:600;">Return:</span>
  <?php
  $legend = [
    ['clr' => '#b91c1c', 'lbl' => '< -15%'],
    ['clr' => '#ef4444', 'lbl' => '-15 to -7%'],
    ['clr' => '#f87171', 'lbl' => '-7 to -3%'],
    ['clr' => '#fca5a5', 'lbl' => '-3 to 0%'],
    ['clr' => '#86efac', 'lbl' => '0 to +3%'],
    ['clr' => '#4ade80', 'lbl' => '+3 to +7%'],
    ['clr' => '#22c55e', 'lbl' => '+7 to +15%'],
    ['clr' => '#16a34a', 'lbl' => '+15 to +30%'],
    ['clr' => '#15803d', 'lbl' => '> +30%'],
  ];
  foreach ($legend as $l): ?>
    <div style="display:flex;align-items:center;gap:3px;">
      <div style="width:14px;height:14px;border-radius:3px;background:<?= $l['clr'] ?>;"></div>
      <span style="color:var(--text-muted);"><?= $l['lbl'] ?></span>
    </div>
  <?php endforeach; ?>
  <span style="color:var(--text-muted);margin-left:8px;">Cell size = portfolio weight</span>
</div>

<!-- Heatmap canvas -->
<div id="hmWrap" style="position:relative;min-height:500px;">
  <div id="hmGrid" style="display:flex;flex-wrap:wrap;gap:3px;align-content:flex-start;"></div>
  <div id="hmLoading" style="padding:60px;text-align:center;color:var(--text-muted);font-size:14px;">
    <div class="spinner"></div><br>Loading heatmap…
  </div>
</div>

<!-- Tooltip -->
<div id="hmTooltip" style="display:none;position:fixed;background:var(--bg-surface);border:1px solid var(--border-color);
     border-radius:10px;padding:12px 16px;box-shadow:0 8px 32px rgba(0,0,0,.2);z-index:9999;
     pointer-events:none;min-width:220px;max-width:300px;font-size:12px;"></div>

<!-- Group labels overlay -->
<div id="hmGroupLabels"></div>

<script>
(function () {
  const API_BASE = window.APP_URL || window.WD?.appUrl || '';
  let HM = { data: null, groupBy: 'asset_class', colorMode: 'total' };

  /* ── Load data ─────────────────────────────────────────────────── */
  async function loadHeatmap(force = false) {
    document.getElementById('hmLoading').style.display = '';
    document.getElementById('hmGrid').innerHTML = '';
    document.getElementById('hmSummary').innerHTML = '';

    const url = `${API_BASE}/api/router.php?action=portfolio_heatmap&group=${HM.groupBy}${force ? '&_t=' + Date.now() : ''}`;
    try {
      const res  = await fetch(url, { credentials: 'same-origin' });
      const data = await res.json();
      if (!data.success) throw new Error(data.message || 'Failed');
      HM.data = data;
      document.getElementById('hmLoading').style.display = 'none';
      renderSummary(data);
      renderGrid(data);
    } catch (e) {
      document.getElementById('hmLoading').innerHTML = `<span style="color:var(--danger);">Error: ${e.message}</span>`;
    }
  }

  /* ── Summary strip ─────────────────────────────────────────────── */
  function renderSummary(d) {
    const gainPct = d.total_gain_pct;
    const clr = gainPct >= 0 ? '#22c55e' : '#ef4444';
    const fmt = (n) => {
      if (Math.abs(n) >= 1e7) return '₹' + (n/1e7).toFixed(2) + 'Cr';
      if (Math.abs(n) >= 1e5) return '₹' + (n/1e5).toFixed(2) + 'L';
      return '₹' + n.toLocaleString('en-IN', { maximumFractionDigits: 0 });
    };
    const stats = [
      { label: 'Portfolio Value', value: fmt(d.total_value), clr: 'var(--text-primary)' },
      { label: 'Invested',        value: fmt(d.total_invested), clr: 'var(--text-secondary)' },
      { label: 'Total Gain',      value: (gainPct >= 0 ? '+' : '') + fmt(d.total_gain) + ' (' + (gainPct >= 0 ? '+' : '') + gainPct + '%)', clr },
      { label: 'Holdings',        value: d.count + ' positions', clr: 'var(--text-secondary)' },
      { label: 'Best',            value: d.max_gain_pct + '%', clr: '#22c55e' },
      { label: 'Worst',           value: d.min_gain_pct + '%', clr: '#ef4444' },
    ];
    document.getElementById('hmSummary').innerHTML = stats.map(s =>
      `<div class="card" style="padding:10px 14px;flex:1;min-width:120px;">
         <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.5px;">${s.label}</div>
         <div style="font-size:15px;font-weight:700;color:${s.clr};margin-top:4px;">${s.value}</div>
       </div>`
    ).join('');
  }

  /* ── Treemap-like grid ─────────────────────────────────────────── */
  function renderGrid(d) {
    const grid = document.getElementById('hmGrid');
    grid.style.width = '100%';

    // Get container width
    const totalW = grid.parentElement.offsetWidth || 900;
    const totalH = Math.max(500, Math.min(900, totalW * 0.65));
    grid.style.height = totalH + 'px';
    grid.style.position = 'relative';
    grid.style.display = 'block';

    // Group cells
    const groups = {};
    for (const cell of d.cells) {
      if (!groups[cell.group]) groups[cell.group] = [];
      groups[cell.group].push(cell);
    }

    // Simple slice-and-dice treemap
    const groupEntries = Object.entries(groups)
      .sort((a, b) => _groupTotal(b[1]) - _groupTotal(a[1]));

    let html = '';
    let xOff = 0;
    const totalVal = d.total_value;

    for (const [grpName, grpCells] of groupEntries) {
      const grpWeight = _groupTotal(grpCells) / totalVal;
      const grpW = Math.floor(grpWeight * totalW) - 4;
      const grpCellsSorted = [...grpCells].sort((a, b) => b.value - a.value);

      let yOff = 0;
      let rowCells = [];
      let rowWeight = 0;

      // Lay cells vertically inside this group column
      for (const cell of grpCellsSorted) {
        const cellH = Math.max(40, Math.floor((cell.value / _groupTotal(grpCells)) * totalH));
        const pct   = HM.colorMode === 'day' ? cell.day_chg : cell.gain_pct;
        const bg    = _color(pct);
        const textClr = Math.abs(pct) > 7 ? 'rgba(255,255,255,.95)' : 'rgba(0,0,0,.8)';

        html += `<div class="hm-cell" data-id="${cell.id}"
          style="position:absolute;left:${xOff}px;top:${yOff}px;
                 width:${grpW}px;height:${cellH - 3}px;
                 background:${bg};border-radius:6px;overflow:hidden;
                 cursor:pointer;box-sizing:border-box;transition:filter .15s;"
          onmouseenter="HM_hover(this, '${cell.id}')"
          onmouseleave="HM_out()"
          onclick="HM_click('${cell.id}')">
          ${cellH > 50 ? `
          <div style="padding:6px 8px;height:100%;display:flex;flex-direction:column;justify-content:center;">
            <div style="font-size:${grpW > 100 ? 12 : 10}px;font-weight:700;color:${textClr};
                        white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.3;">
              ${cell.short}
            </div>
            ${cellH > 70 ? `<div style="font-size:10px;color:${textClr};opacity:.85;margin-top:2px;">
              ${(pct >= 0 ? '+' : '') + pct + '%'}
            </div>` : ''}
          </div>` : ''}
        </div>`;
        yOff += cellH;
      }

      // Group label at top
      if (grpW > 60) {
        html += `<div style="position:absolute;left:${xOff}px;top:2px;
                             background:rgba(0,0,0,.45);color:#fff;font-size:9px;font-weight:700;
                             padding:2px 5px;border-radius:4px;pointer-events:none;
                             max-width:${grpW - 4}px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;">
                   ${grpName}
                 </div>`;
      }

      xOff += grpW + 4;
    }

    grid.innerHTML = html;
  }

  function _groupTotal(cells) {
    return cells.reduce((s, c) => s + c.value, 0);
  }

  function _color(pct) {
    if (pct >= 30)  return '#15803d';
    if (pct >= 15)  return '#16a34a';
    if (pct >= 7)   return '#22c55e';
    if (pct >= 3)   return '#4ade80';
    if (pct >= 0)   return '#86efac';
    if (pct >= -3)  return '#fca5a5';
    if (pct >= -7)  return '#f87171';
    if (pct >= -15) return '#ef4444';
    return '#b91c1c';
  }

  /* ── Tooltip ───────────────────────────────────────────────────── */
  const tip = document.getElementById('hmTooltip');

  window.HM_hover = function (el, id) {
    el.style.filter = 'brightness(1.12) drop-shadow(0 0 6px rgba(0,0,0,.35))';
    const cell = HM.data?.cells?.find(c => c.id === id);
    if (!cell) return;

    const fmt = (n) => n >= 1e7 ? '₹' + (n/1e7).toFixed(2) + 'Cr'
                     : n >= 1e5 ? '₹' + (n/1e5).toFixed(2) + 'L'
                     : '₹' + Math.round(n).toLocaleString('en-IN');
    const gainClr = cell.gain_abs >= 0 ? '#22c55e' : '#ef4444';
    const dayClr  = cell.day_chg  >= 0 ? '#22c55e' : '#ef4444';

    tip.innerHTML = `
      <div style="font-weight:700;font-size:13px;margin-bottom:8px;color:var(--text-primary);">${cell.name}</div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 12px;font-size:11px;">
        <span style="color:var(--text-muted);">Value</span>      <span style="font-weight:600;">${fmt(cell.value)}</span>
        <span style="color:var(--text-muted);">Invested</span>   <span style="font-weight:600;">${fmt(cell.invested)}</span>
        <span style="color:var(--text-muted);">Gain/Loss</span>  <span style="font-weight:600;color:${gainClr};">${cell.gain_abs >= 0 ? '+' : ''}${fmt(cell.gain_abs)} (${cell.gain_pct >= 0 ? '+' : ''}${cell.gain_pct}%)</span>
        <span style="color:var(--text-muted);">1-Day Chg</span>  <span style="font-weight:600;color:${dayClr};">${cell.day_chg >= 0 ? '+' : ''}${cell.day_chg}%</span>
        <span style="color:var(--text-muted);">Weight</span>     <span style="font-weight:600;">${cell.weight_pct}%</span>
        ${cell.xirr ? `<span style="color:var(--text-muted);">XIRR</span><span style="font-weight:600;">${cell.xirr}%</span>` : ''}
        <span style="color:var(--text-muted);">Type</span>       <span style="font-weight:600;">${cell.asset_type}</span>
        <span style="color:var(--text-muted);">Group</span>      <span style="font-weight:600;">${cell.group}</span>
      </div>`;

    tip.style.display = '';
  };

  document.addEventListener('mousemove', e => {
    if (tip.style.display === 'none') return;
    const x = e.clientX + 16;
    const y = e.clientY + 8;
    tip.style.left  = Math.min(x, window.innerWidth  - 320) + 'px';
    tip.style.top   = Math.min(y, window.innerHeight - 220) + 'px';
  });

  window.HM_out  = () => {
    document.querySelectorAll('.hm-cell').forEach(el => el.style.filter = '');
    tip.style.display = 'none';
  };

  window.HM_click = (id) => {
    const cell = HM.data?.cells?.find(c => c.id === id);
    if (!cell) return;
    const page = cell.asset_type === 'Stock' ? 'stocks' : 'mf_holdings';
    window.location.href = (window.APP_URL || '') + `/?page=${page}`;
  };

  /* ── Controls ──────────────────────────────────────────────────── */
  document.querySelectorAll('.hm-group-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.hm-group-btn').forEach(b => {
        b.style.background = 'var(--bg-secondary)'; b.style.color = 'var(--text-secondary)';
      });
      btn.style.background = 'var(--primary)'; btn.style.color = '#fff';
      HM.groupBy = btn.dataset.group;
      loadHeatmap();
    });
  });

  document.querySelectorAll('.hm-color-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.hm-color-btn').forEach(b => {
        b.style.background = 'var(--bg-secondary)'; b.style.color = 'var(--text-secondary)';
      });
      btn.style.background = 'var(--primary)'; btn.style.color = '#fff';
      HM.colorMode = btn.dataset.mode;
      if (HM.data) { renderGrid(HM.data); }
    });
  });

  document.getElementById('hmRefresh').addEventListener('click', () => loadHeatmap(true));

  // Re-render on resize
  let _resizeTimer;
  window.addEventListener('resize', () => {
    clearTimeout(_resizeTimer);
    _resizeTimer = setTimeout(() => { if (HM.data) renderGrid(HM.data); }, 250);
  });

  // Initial load
  document.addEventListener('DOMContentLoaded', loadHeatmap);

})();
</script>

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
?>
