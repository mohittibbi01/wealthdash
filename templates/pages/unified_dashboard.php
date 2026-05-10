<?php
/**
 * WealthDash — t442: Unified Dashboard — All Assets
 * One place to see the complete financial picture.
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Unified Dashboard';
$activePage  = 'unified_dashboard';

ob_start();
?>

<div class="page-header">
  <div>
    <h1 class="page-title">🌐 Unified Dashboard</h1>
    <p class="page-subtitle">Complete financial picture — all assets at a glance</p>
  </div>
  <div class="page-actions" style="display:flex;gap:8px;align-items:center;">
    <span id="udLastUpdated" style="font-size:11px;color:var(--text-secondary);"></span>
    <button class="btn btn-outline btn-sm" onclick="udRefresh()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>
      Refresh
    </button>
    <a href="<?= APP_URL ?>/templates/pages/report_networth.php" class="btn btn-ghost btn-sm">Net Worth Report →</a>
  </div>
</div>

<!-- ═══ HERO NET WORTH CARD ════════════════════════════════════════════════ -->
<div class="card card-hero" style="margin-bottom:16px;" id="udHeroCard">
  <div class="card-body" style="padding:28px 32px;">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:20px;">
      <!-- Left: Net Worth -->
      <div>
        <div style="font-size:12px;font-weight:600;opacity:.75;text-transform:uppercase;letter-spacing:.06em;margin-bottom:6px;">Total Net Worth</div>
        <div id="udNetWorth" class="hero-number" style="font-size:38px;">
          <span class="ud-skeleton" style="display:inline-block;width:220px;height:44px;border-radius:6px;background:rgba(255,255,255,.15);"></span>
        </div>
        <div style="margin-top:8px;display:flex;gap:20px;flex-wrap:wrap;">
          <span style="font-size:13px;opacity:.8;">Invested: <strong id="udTotalInvested">—</strong></span>
          <span style="font-size:13px;opacity:.8;">Gain: <strong id="udTotalGain">—</strong> <span id="udGainPct" style="font-size:11px;background:rgba(255,255,255,.2);padding:2px 6px;border-radius:10px;">—</span></span>
        </div>
      </div>
      <!-- Right: Today's change + loan -->
      <div style="text-align:right;">
        <div style="font-size:12px;opacity:.7;margin-bottom:4px;">Today's Change</div>
        <div id="udDailyChange" style="font-size:22px;font-weight:700;">—</div>
        <div style="font-size:11px;opacity:.7;margin-top:10px;">Loans Outstanding</div>
        <div id="udTotalLoan" style="font-size:14px;font-weight:600;">—</div>
      </div>
    </div>

    <!-- Mini allocation bar -->
    <div style="margin-top:20px;">
      <div id="udAllocBar" style="height:8px;border-radius:4px;overflow:hidden;display:flex;gap:2px;background:rgba(255,255,255,.1);">
        <div style="height:100%;width:100%;background:rgba(255,255,255,.15);border-radius:4px;"></div>
      </div>
      <div id="udAllocLegend" style="display:flex;gap:12px;flex-wrap:wrap;margin-top:8px;font-size:11px;opacity:.85;"></div>
    </div>
  </div>
</div>

<!-- ═══ MORNING BRIEFING (t443) ══════════════════════════════════════════════ -->
<div id="udMorningBriefing" style="margin-bottom:16px;display:none;"></div>

<!-- ═══ ALERTS STRIP ════════════════════════════════════════════════════════ -->
<div id="udAlertsWrap" style="margin-bottom:16px;display:none;"></div>

<!-- ═══ ASSET CLASS CARDS ════════════════════════════════════════════════════ -->
<div id="udAssetCards" class="cards-grid cards-grid-4" style="margin-bottom:20px;">
  <?php for ($i = 0; $i < 4; $i++): ?>
  <div class="card" style="padding:16px;">
    <div class="ud-skeleton" style="height:14px;width:60%;border-radius:4px;background:var(--border);margin-bottom:10px;"></div>
    <div class="ud-skeleton" style="height:26px;width:80%;border-radius:4px;background:var(--border);margin-bottom:8px;"></div>
    <div class="ud-skeleton" style="height:12px;width:50%;border-radius:4px;background:var(--border);"></div>
  </div>
  <?php endfor; ?>
</div>

<!-- ═══ CHARTS + ACTIVITY ROW ═════════════════════════════════════════════════ -->
<div style="display:grid;grid-template-columns:1fr 400px;gap:16px;margin-bottom:20px;align-items:start;" id="udMainRow">

  <!-- Allocation Chart -->
  <div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
      <span style="font-weight:600;font-size:15px;">Asset Allocation</span>
      <div style="display:flex;gap:8px;">
        <button class="btn btn-ghost btn-sm" id="udChartTypeBtn" onclick="udToggleChartType()">Donut</button>
      </div>
    </div>
    <div class="card-body" style="padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:16px;align-items:center;">
      <div style="position:relative;width:220px;height:220px;margin:auto;">
        <canvas id="udAllocChart" width="220" height="220"></canvas>
        <div id="udChartCenter" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;pointer-events:none;">
          <div style="font-size:11px;color:var(--text-secondary);font-weight:600;">TOTAL</div>
          <div id="udChartCenterVal" style="font-size:16px;font-weight:800;">—</div>
        </div>
      </div>
      <div id="udAllocList" style="display:flex;flex-direction:column;gap:8px;"></div>
    </div>
  </div>

  <!-- Recent Activity -->
  <div class="card" style="max-height:400px;overflow:hidden;display:flex;flex-direction:column;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
      <span style="font-weight:600;font-size:15px;">Recent Activity</span>
      <span id="udActivityCount" style="font-size:11px;color:var(--text-secondary);"></span>
    </div>
    <div id="udActivityList" style="overflow-y:auto;flex:1;padding:4px 0;">
      <div style="padding:20px;text-align:center;color:var(--text-secondary);font-size:13px;">Loading…</div>
    </div>
  </div>
</div>

<!-- ═══ QUICK ACTIONS ══════════════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header">
    <span style="font-weight:600;font-size:15px;">⚡ Quick Access</span>
  </div>
  <div class="card-body" style="padding:14px 16px;">
    <div style="display:flex;flex-wrap:wrap;gap:8px;" id="udQuickLinks">
      <?php
      $quickLinks = [
        ['label'=>'MF Holdings',    'icon'=>'📊', 'url'=> APP_URL . '/templates/pages/mf_holdings.php'],
        ['label'=>'Stocks',         'icon'=>'📈', 'url'=> APP_URL . '/templates/pages/stocks.php'],
        ['label'=>'NPS',            'icon'=>'🏦', 'url'=> APP_URL . '/templates/pages/nps.php'],
        ['label'=>'Fixed Deposits', 'icon'=>'🏛️', 'url'=> APP_URL . '/templates/pages/fd.php'],
        ['label'=>'Post Office',    'icon'=>'📮', 'url'=> APP_URL . '/templates/pages/post_office.php'],
        ['label'=>'Savings',        'icon'=>'💰', 'url'=> APP_URL . '/templates/pages/savings.php'],
        ['label'=>'Crypto',         'icon'=>'₿',  'url'=> APP_URL . '/templates/pages/crypto.php'],
        ['label'=>'Gold & SGB',     'icon'=>'🥇', 'url'=> APP_URL . '/templates/pages/gold.php'],
        ['label'=>'Real Estate',    'icon'=>'🏠', 'url'=> APP_URL . '/templates/pages/realestate.php'],
        ['label'=>'EPF / PF',       'icon'=>'👷', 'url'=> APP_URL . '/templates/epf.php'],
        ['label'=>'Insurance',      'icon'=>'🛡️', 'url'=> APP_URL . '/templates/insurance.php'],
        ['label'=>'Goals',          'icon'=>'🎯', 'url'=> APP_URL . '/templates/pages/goals.php'],
        ['label'=>'Capital Gains',  'icon'=>'📉', 'url'=> APP_URL . '/templates/pages/capital_gains_summary.php'],
        ['label'=>'Tax Planning',   'icon'=>'📋', 'url'=> APP_URL . '/templates/pages/report_tax.php'],
        ['label'=>'FIRE Calculator','icon'=>'🔥', 'url'=> APP_URL . '/templates/pages/fire_calculator.php'],
        ['label'=>'Wealth Statement','icon'=>'📄','url'=> APP_URL . '/templates/pages/wealth_statement.php'],
      ];
      foreach ($quickLinks as $ql): ?>
      <a href="<?= $ql['url'] ?>" class="btn btn-outline btn-sm" style="font-size:12px;gap:4px;">
        <span><?= $ql['icon'] ?></span>
        <span><?= $ql['label'] ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ═══ ASSET DETAIL TABLE ══════════════════════════════════════════════════ -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
    <span style="font-weight:600;font-size:15px;">All Assets Breakdown</span>
    <button class="btn btn-ghost btn-sm" onclick="udExportCSV()">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      CSV
    </button>
  </div>
  <div class="table-wrapper">
    <table class="table" id="udBreakdownTable">
      <thead>
        <tr>
          <th>Asset Class</th>
          <th class="text-right">Holdings</th>
          <th class="text-right">Invested</th>
          <th class="text-right">Current Value</th>
          <th class="text-right">Gain / Loss</th>
          <th class="text-right">Return %</th>
          <th class="text-right">Allocation %</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="udBreakdownBody">
        <tr><td colspan="8" style="text-align:center;padding:24px;color:var(--text-secondary);">Loading…</td></tr>
      </tbody>
      <tfoot id="udBreakdownFoot" style="display:none;">
        <tr style="font-weight:700;border-top:2px solid var(--border);">
          <td>Total</td>
          <td></td>
          <td class="text-right" id="udFtInvested"></td>
          <td class="text-right" id="udFtValue"></td>
          <td class="text-right" id="udFtGain"></td>
          <td class="text-right" id="udFtRet"></td>
          <td class="text-right">100%</td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<style>
/* ── Unified Dashboard styles ─────────────────────────────────────────────── */
.ud-asset-card {
  position: relative;
  overflow: hidden;
  cursor: default;
  transition: transform .15s, box-shadow .15s;
}
.ud-asset-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 20px rgba(0,0,0,.08);
}
.ud-asset-card .ud-bar {
  position: absolute;
  left: 0; top: 0; bottom: 0;
  width: 4px;
  border-radius: 4px 0 0 4px;
}
.ud-asset-card .ud-icon {
  font-size: 22px;
  margin-bottom: 6px;
  display: block;
}
.ud-asset-card .ud-label {
  font-size: 11px;
  font-weight: 700;
  color: var(--text-secondary);
  text-transform: uppercase;
  letter-spacing: .05em;
  margin-bottom: 4px;
}
.ud-asset-card .ud-value {
  font-size: 20px;
  font-weight: 800;
  line-height: 1.1;
  margin-bottom: 6px;
}
.ud-asset-card .ud-meta {
  font-size: 11px;
  color: var(--text-secondary);
  display: flex;
  gap: 8px;
  align-items: center;
  flex-wrap: wrap;
}
.ud-gain-chip {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 2px 7px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 700;
}
.ud-gain-pos { background: rgba(34,197,94,.12); color: #16a34a; }
.ud-gain-neg { background: rgba(239,68,68,.1);  color: #dc2626; }
.ud-gain-nil { background: var(--bg-secondary); color: var(--text-secondary); }
.ud-activity-item {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 10px 16px;
  border-bottom: 1px solid var(--border);
  font-size: 12px;
}
.ud-activity-item:last-child { border-bottom: none; }
.ud-activity-icon {
  width: 32px; height: 32px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px;
  flex-shrink: 0;
}
.ud-alert-strip {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border-radius: 8px;
  font-size: 12px;
  margin-bottom: 6px;
}
.ud-alert-critical { background: rgba(239,68,68,.1);  border: 1px solid rgba(239,68,68,.2); }
.ud-alert-warning  { background: rgba(245,158,11,.1); border: 1px solid rgba(245,158,11,.2); }
.ud-alert-info     { background: rgba(99,102,241,.07);border: 1px solid rgba(99,102,241,.15); }
.ud-skeleton {
  animation: udPulse 1.5s ease-in-out infinite;
}
@keyframes udPulse {
  0%, 100% { opacity: .6; }
  50%       { opacity: 1;  }
}
@media (max-width: 900px) {
  #udMainRow { grid-template-columns: 1fr !important; }
}
</style>

<script src="<?= APP_URL ?>/public/js/charts.js?v=<?= ASSET_VERSION ?>"></script>
<script>
/* ═══════════════════════════════════════════════════════════════════
   t442 — Unified Dashboard JS
   ═══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  const BASE = window.APP_URL || '';
  const CSRF = window.WD?.csrf || document.querySelector('meta[name="csrf-token"]')?.content || '';

  let _summaryData  = null;
  let _allocChart   = null;
  let _chartType    = 'doughnut'; // doughnut | bar

  /* ── API helper ──────────────────────────────────────────────── */
  async function api(action, extra = {}) {
    const fd = new FormData();
    fd.append('action', action);
    if (CSRF) fd.append('_csrf_token', CSRF);
    Object.entries(extra).forEach(([k, v]) => fd.append(k, v));
    const r = await fetch(`${BASE}/api/router.php`, { method: 'POST', body: fd });
    const j = await r.json();
    if (!j.success) throw new Error(j.message || 'API error');
    return j.data ?? j;
  }

  /* ── Number formatters ───────────────────────────────────────── */
  function fmtINR(v) {
    v = parseFloat(v) || 0;
    if (v >= 1e7) return '₹' + (v / 1e7).toFixed(2) + ' Cr';
    if (v >= 1e5) return '₹' + (v / 1e5).toFixed(2) + ' L';
    return '₹' + v.toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
  }
  function fmtPct(v) {
    v = parseFloat(v) || 0;
    return (v >= 0 ? '+' : '') + v.toFixed(2) + '%';
  }
  function gainChip(v, showAbs = true) {
    const cls = v > 0 ? 'ud-gain-pos' : (v < 0 ? 'ud-gain-neg' : 'ud-gain-nil');
    const sign = v > 0 ? '▲' : (v < 0 ? '▼' : '');
    return `<span class="ud-gain-chip ${cls}">${sign} ${showAbs ? fmtINR(Math.abs(v)) : ''}</span>`;
  }
  function gainPctChip(v) {
    const cls = v > 0 ? 'ud-gain-pos' : (v < 0 ? 'ud-gain-neg' : 'ud-gain-nil');
    return `<span class="ud-gain-chip ${cls}">${fmtPct(v)}</span>`;
  }

  /* ── RENDER HERO ─────────────────────────────────────────────── */
  function renderHero(d) {
    if (typeof WdSkel !== 'undefined') WdSkel.clear('udNetWorth');
    document.getElementById('udNetWorth').innerHTML     = `<span>${fmtINR(d.net_worth)}</span>`;
    document.getElementById('udTotalInvested').textContent = fmtINR(d.total_invested);
    document.getElementById('udTotalGain').textContent  = fmtINR(d.total_gain);
    document.getElementById('udGainPct').textContent    = fmtPct(d.gain_pct);
    document.getElementById('udGainPct').style.background =
      d.gain_pct > 0 ? 'rgba(34,197,94,.25)' : (d.gain_pct < 0 ? 'rgba(239,68,68,.25)' : 'rgba(255,255,255,.15)');

    // Daily change
    const dc = d.daily_change;
    const dcEl = document.getElementById('udDailyChange');
    dcEl.textContent = (dc >= 0 ? '+' : '') + fmtINR(dc);
    dcEl.style.color = dc > 0 ? '#86efac' : (dc < 0 ? '#fca5a5' : 'rgba(255,255,255,.8)');

    document.getElementById('udTotalLoan').textContent =
      d.total_loan > 0 ? fmtINR(d.total_loan) : '—';

    document.getElementById('udLastUpdated').textContent =
      'Updated ' + new Date().toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });

    // Allocation bar
    const bar = document.getElementById('udAllocBar');
    const leg = document.getElementById('udAllocLegend');
    if (d.allocation && d.allocation.length) {
      bar.innerHTML = d.allocation.slice(0, 8).map(a =>
        `<div style="height:100%;width:${a.pct}%;background:${a.color};border-radius:2px;" title="${a.label} ${a.pct}%"></div>`
      ).join('');
      leg.innerHTML = d.allocation.slice(0, 8).map(a =>
        `<span style="display:flex;align-items:center;gap:4px;">
          <span style="width:8px;height:8px;border-radius:50%;background:${a.color};display:inline-block;"></span>
          ${a.label} <strong>${a.pct}%</strong>
        </span>`
      ).join('');
    }
  }

  /* ── RENDER ASSET CARDS ──────────────────────────────────────── */
  function renderCards(assets, totalValue) {
    const wrap = document.getElementById('udAssetCards');
    if (typeof WdSkel !== 'undefined') WdSkel.clear('udCardsWrap');
    if (!assets || !assets.length) {
      wrap.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:32px;color:var(--text-secondary);">No asset data found. Add investments to see your dashboard.</div>';
      return;
    }
    wrap.innerHTML = assets.map(a => {
      const gainPct = a.invested > 0 ? ((a.current_value - a.invested) / a.invested * 100) : 0;
      const allocPct = totalValue > 0 ? (a.current_value / totalValue * 100).toFixed(1) : 0;
      const isProtection = !!a.is_protection;
      return `
        <div class="card ud-asset-card" style="padding:16px 16px 14px 20px;" onclick="window.location.href='${BASE}?page=${a.url}'">
          <div class="ud-bar" style="background:${a.color};"></div>
          <span class="ud-icon">${a.icon}</span>
          <div class="ud-label">${a.label}</div>
          <div class="ud-value">${fmtINR(a.current_value)}</div>
          <div class="ud-meta">
            ${!isProtection
              ? `<span>${gainChip(a.gain_loss)} ${gainPctChip(gainPct)}</span>
                 <span style="margin-left:auto;font-weight:600;color:var(--text-secondary);">${allocPct}%</span>`
              : `<span>Cover: <strong>${fmtINR(a.sum_assured ?? a.current_value)}</strong></span>
                 <span>Premium: ${fmtINR(a.annual_premium ?? a.invested)}/yr</span>`
            }
          </div>
          <div style="margin-top:8px;font-size:10px;color:var(--text-secondary);">${a.count} holding${a.count !== 1 ? 's' : ''}</div>
        </div>`;
    }).join('');
  }

  /* ── RENDER CHART ────────────────────────────────────────────── */
  function renderChart(allocation) {
    const ctx = document.getElementById('udAllocChart')?.getContext('2d');
    if (!ctx || !allocation?.length) return;

    const labels = allocation.map(a => a.label);
    const values = allocation.map(a => a.value);
    const colors = allocation.map(a => a.color);

    if (_allocChart) { _allocChart.destroy(); _allocChart = null; }

    _allocChart = new Chart(ctx, {
      type: _chartType,
      data: { labels, datasets: [{ data: values, backgroundColor: colors, borderWidth: 2, borderColor: 'var(--bg-card, #fff)', hoverOffset: 8 }] },
      options: {
        responsive: false,
        cutout: _chartType === 'doughnut' ? '62%' : undefined,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: ctx => ` ${ctx.label}: ${fmtINR(ctx.raw)} (${allocation[ctx.dataIndex]?.pct}%)`
            }
          }
        },
        onClick: (_, els) => {
          if (els?.length && allocation[els[0].index]?.key) {
            const key = allocation[els[0].index].key;
            const urlMap = { mf:'mf_holdings', stocks:'stocks', nps:'nps', fd:'fd', post_office:'post_office', savings:'savings', crypto:'crypto', gold:'gold', real_estate:'realestate', epf:'epf', insurance:'insurance' };
            if (urlMap[key]) window.location.href = BASE + '?page=' + urlMap[key];
          }
        }
      }
    });

    // Allocation list
    const listEl = document.getElementById('udAllocList');
    const total = values.reduce((s, v) => s + v, 0);
    listEl.innerHTML = allocation.map((a, i) => `
      <div style="display:flex;align-items:center;gap:8px;font-size:12px;cursor:pointer;" onclick="window.location.href='${BASE}?page=${a.key}'">
        <span style="width:10px;height:10px;border-radius:50%;background:${colors[i]};flex-shrink:0;"></span>
        <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${a.label}</span>
        <span style="font-weight:700;">${a.pct}%</span>
        <span style="color:var(--text-secondary);font-size:11px;white-space:nowrap;">${fmtINR(a.value)}</span>
      </div>`
    ).join('');

    document.getElementById('udChartCenterVal').textContent = fmtINR(total);
  }

  /* ── RENDER BREAKDOWN TABLE ──────────────────────────────────── */
  function renderTable(assets, totalValue, totalInvested, totalGain) {
    const tbody = document.getElementById('udBreakdownBody');
    const tfoot = document.getElementById('udBreakdownFoot');
    if (!assets?.length) { tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--text-secondary);">No data</td></tr>'; return; }

    tbody.innerHTML = assets.map(a => {
      const ret = a.invested > 0 ? ((a.current_value - a.invested) / a.invested * 100) : 0;
      const alloc = totalValue > 0 ? (a.current_value / totalValue * 100) : 0;
      const gainCls = a.gain_loss > 0 ? 'text-gain' : (a.gain_loss < 0 ? 'text-loss' : '');
      const retCls  = ret > 0 ? 'text-gain' : (ret < 0 ? 'text-loss' : '');
      return `
        <tr style="cursor:pointer;" onclick="window.location.href='${BASE}?page=${a.url}'">
          <td>
            <span style="margin-right:6px;">${a.icon}</span>
            <span style="font-weight:600;">${a.label}</span>
            ${a.is_protection ? '<span style="font-size:10px;background:rgba(100,116,139,.15);color:var(--text-secondary);padding:1px 5px;border-radius:8px;margin-left:6px;">Protection</span>' : ''}
          </td>
          <td class="text-right" style="font-size:12px;color:var(--text-secondary);">${a.count}</td>
          <td class="text-right">${fmtINR(a.is_protection ? (a.annual_premium || a.invested) : a.invested)}</td>
          <td class="text-right font-600">${a.is_protection ? fmtINR(a.sum_assured || a.current_value) : fmtINR(a.current_value)}</td>
          <td class="text-right ${gainCls}">${a.is_protection ? '—' : (a.gain_loss >= 0 ? '+' : '') + fmtINR(a.gain_loss)}</td>
          <td class="text-right ${retCls}" style="font-size:12px;">${a.is_protection ? '—' : fmtPct(ret)}</td>
          <td class="text-right" style="font-size:12px;">${a.is_protection ? '—' : alloc.toFixed(1) + '%'}</td>
          <td class="text-right">
            <a href="${BASE}?page=${a.url}" class="btn btn-ghost btn-sm" style="font-size:11px;padding:2px 8px;" onclick="event.stopPropagation()">View →</a>
          </td>
        </tr>`;
    }).join('');

    const overallRet = totalInvested > 0 ? (totalGain / totalInvested * 100) : 0;
    const gainCls = totalGain > 0 ? 'text-gain' : (totalGain < 0 ? 'text-loss' : '');
    document.getElementById('udFtInvested').textContent = fmtINR(totalInvested);
    document.getElementById('udFtValue').textContent    = fmtINR(totalValue);
    document.getElementById('udFtGain').innerHTML       = `<span class="${gainCls}">${(totalGain >= 0 ? '+' : '') + fmtINR(totalGain)}</span>`;
    document.getElementById('udFtRet').innerHTML        = `<span class="${gainCls}">${fmtPct(overallRet)}</span>`;
    tfoot.style.display = '';
  }

  /* ── RENDER ALERTS ───────────────────────────────────────────── */
  // t404: Smart Alerts v2 renderer
  const ALERT_META = {
    critical: { badge: '🚨', bg: 'rgba(239,68,68,.12)',  border: 'rgba(239,68,68,.3)',  text: '#ef4444' },
    warning:  { badge: '⚠️', bg: 'rgba(245,158,11,.10)', border: 'rgba(245,158,11,.3)', text: '#f59e0b' },
    info:     { badge: 'ℹ️', bg: 'rgba(59,130,246,.08)', border: 'rgba(59,130,246,.25)', text: '#3b82f6' },
  };

  function renderAlerts(alerts) {
    const wrap = document.getElementById('udAlertsWrap');
    if (!alerts?.length) { wrap.style.display = 'none'; return; }
    wrap.style.display = '';

    const critCount = alerts.filter(a => a.level === 'critical').length;
    const warnCount = alerts.filter(a => a.level === 'warning').length;

    wrap.innerHTML = `
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap;">
        <span style="font-weight:700;font-size:14px;">🔔 Smart Alerts</span>
        <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:12px;
                     background:var(--bg-secondary);color:var(--text-muted);">${alerts.length} active</span>
        ${critCount ? `<span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px;background:rgba(239,68,68,.15);color:#ef4444;">${critCount} critical</span>` : ''}
        ${warnCount ? `<span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:12px;background:rgba(245,158,11,.15);color:#f59e0b;">${warnCount} warning</span>` : ''}
        <button class="btn btn-ghost btn-sm" onclick="document.getElementById('udAlertsWrap').style.display='none'"
                style="margin-left:auto;font-size:11px;">Dismiss all</button>
      </div>
      <div style="display:flex;flex-direction:column;gap:6px;">
        ${alerts.map(a => {
          const m   = ALERT_META[a.level] || ALERT_META.info;
          const btn = a.action
            ? `<a href="${BASE}?page=${a.url}" class="btn btn-sm"
                  style="font-size:11px;flex-shrink:0;background:${m.text}18;
                         color:${m.text};border:1px solid ${m.border};border-radius:6px;
                         padding:4px 10px;text-decoration:none;font-weight:600;">${a.action} →</a>`
            : `<a href="${BASE}?page=${a.url}" class="btn btn-ghost btn-sm"
                  style="font-size:11px;flex-shrink:0;">View →</a>`;
          return `
          <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;
                      border-radius:10px;background:${m.bg};border:1px solid ${m.border};">
            <span style="font-size:18px;flex-shrink:0;">${a.icon}</span>
            <div style="flex:1;min-width:0;">
              <div style="font-weight:700;font-size:13px;color:${m.text};">${m.badge} ${a.title}</div>
              <div style="font-size:12px;color:var(--text-secondary);margin-top:2px;
                          white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${a.message}</div>
            </div>
            ${btn}
          </div>`;
        }).join('')}
      </div>`;
  }

  /* ── MORNING BRIEFING (t443) ─────────────────────────────────── */
  async function loadMorningBriefing() {
    // Only show between 6AM – 2PM, or if explicitly requested
    const h = new Date().getHours();
    if (h < 6 || h >= 14) return;

    try {
      const data = await api('morning_briefing');
      renderMorningBriefing(data);
    } catch (e) { /* silent — briefing is non-critical */ }
  }

  function renderMorningBriefing(d) {
    const wrap = document.getElementById('udMorningBriefing');
    if (!d?.sections?.length) return;
    wrap.style.display = '';

    const TYPE_CLR = {
      positive: '#22c55e', negative: '#ef4444', warning: '#f59e0b',
      highlight: '#6366f1', neutral: 'var(--text-secondary)', stat: 'var(--text-primary)',
    };

    const sectionHtml = d.sections.map(sec => {
      const rows = sec.items.map(it => {
        const clr = TYPE_CLR[it.type] || TYPE_CLR.neutral;
        if (it.type === 'stat') {
          return `<div style="display:flex;justify-content:space-between;padding:4px 0;font-size:12px;border-bottom:1px solid var(--border-color);">
            <span style="color:var(--text-muted);">${it.label}</span>
            <span style="font-weight:700;color:${clr};">${it.value}</span>
          </div>`;
        }
        return `<div style="padding:4px 0;font-size:12px;color:${clr};display:flex;gap:6px;align-items:flex-start;">
          <span style="min-width:10px;">•</span><span>${it.text}</span>
        </div>`;
      }).join('');

      return `<div style="background:var(--bg-secondary);border-radius:10px;padding:12px 14px;flex:1;min-width:200px;">
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;
                    letter-spacing:.5px;margin-bottom:8px;">${sec.title}</div>
        ${rows}
      </div>`;
    }).join('');

    wrap.innerHTML = `
      <div style="background:linear-gradient(135deg,var(--bg-surface),var(--bg-secondary));
                  border-radius:14px;border:1px solid var(--border-color);padding:16px 18px;
                  animation:wd-empty-in .3s ease;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px;">
          <div>
            <div style="font-size:16px;font-weight:700;">${d.greeting}</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">${d.date}${d._cached ? ' · <span style="opacity:.6;">cached</span>' : ''}</div>
          </div>
          <div style="display:flex;gap:8px;">
            <button class="btn btn-ghost btn-sm" style="font-size:11px;"
                    onclick="api('morning_briefing&refresh=1').then(r=>renderMorningBriefing(r))">
              🔄 Refresh
            </button>
            <button class="btn btn-ghost btn-sm" style="font-size:11px;"
                    onclick="document.getElementById('udMorningBriefing').style.display='none'">
              ✕ Dismiss
            </button>
          </div>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:10px;">${sectionHtml}</div>
      </div>`;
  }

  /* ── RENDER ACTIVITY ─────────────────────────────────────────── */
  function renderActivity(activity) {
    const list = document.getElementById('udActivityList');
    const cnt  = document.getElementById('udActivityCount');
    if (typeof WdSkel !== 'undefined') WdSkel.clear('udActivityList');
    if (!activity?.length) {
      if (typeof WdEmpty !== 'undefined') WdEmpty.div(list, 'activity');
      else list.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-secondary);font-size:13px;">No recent activity</div>';
      return;
    }
    cnt.textContent = activity.length + ' transactions';
    list.innerHTML = activity.map(a => {
      const amtCls = a.is_positive ? 'text-gain' : 'text-loss';
      const sign   = a.is_positive ? '+' : '-';
      return `
        <div class="ud-activity-item">
          <div class="ud-activity-icon" style="background:${a.color}18;color:${a.color};">${a.icon}</div>
          <div style="flex:1;min-width:0;">
            <div style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${a.name}</div>
            <div style="color:var(--text-secondary);font-size:11px;">${a.sub || a.module} · ${a.type}${a.detail ? ' · ' + a.detail : ''}</div>
          </div>
          <div style="text-align:right;flex-shrink:0;">
            <div class="${amtCls}" style="font-weight:700;font-size:12px;">${sign}${fmtINR(Math.abs(a.amount))}</div>
            <div style="font-size:10px;color:var(--text-secondary);">${a.date}</div>
          </div>
        </div>`;
    }).join('');
  }

  /* ── CHART TYPE TOGGLE ───────────────────────────────────────── */
  window.udToggleChartType = function () {
    _chartType = _chartType === 'doughnut' ? 'bar' : 'doughnut';
    document.getElementById('udChartTypeBtn').textContent = _chartType === 'doughnut' ? 'Donut' : 'Bar';
    document.getElementById('udChartCenter').style.display = _chartType === 'doughnut' ? '' : 'none';
    if (_summaryData) renderChart(_summaryData.allocation);
  };

  /* ── CSV EXPORT ──────────────────────────────────────────────── */
  window.udExportCSV = function () {
    if (!_summaryData?.assets?.length) { showToast('No data to export', 'warning'); return; }
    const rows = [['Asset Class', 'Holdings', 'Invested (₹)', 'Current Value (₹)', 'Gain/Loss (₹)', 'Return %', 'Allocation %']];
    const tv = _summaryData.total_assets;
    _summaryData.assets.forEach(a => {
      const ret  = a.invested > 0 ? ((a.current_value - a.invested) / a.invested * 100).toFixed(2) : '0';
      const alloc = tv > 0 ? (a.current_value / tv * 100).toFixed(1) : '0';
      rows.push([a.label, a.count, a.invested.toFixed(2), a.current_value.toFixed(2), a.gain_loss.toFixed(2), ret, alloc]);
    });
    rows.push(['TOTAL', '', _summaryData.total_invested.toFixed(2), _summaryData.total_assets.toFixed(2), _summaryData.total_gain.toFixed(2), _summaryData.gain_pct.toFixed(2), '100']);
    const csv = rows.map(r => r.join(',')).join('\n');
    const a   = document.createElement('a');
    a.href    = 'data:text/csv;charset=utf-8,' + encodeURIComponent(csv);
    a.download = 'wealthdash_portfolio_' + new Date().toISOString().slice(0, 10) + '.csv';
    a.click();
  };

  /* ── MAIN LOAD ───────────────────────────────────────────────── */
  async function udLoad() {
    try {
      // tp002: Load summary immediately (cached, fast), defer activity + alerts
      const summary = await api('unified_summary');
      _summaryData = summary;

      renderHero(summary);
      renderCards(summary.assets, summary.total_assets);
      renderChart(summary.allocation);
      renderTable(summary.assets, summary.total_assets, summary.total_invested, summary.total_gain);

      // t443: Morning briefing (non-blocking, only 6AM–2PM)
      loadMorningBriefing();

      // tp002: Activity and alerts are below-fold — load after summary renders
      if (typeof WdLazy !== 'undefined') {
        WdLazy.observe('udActivityList', async () => {
          try {
            const [actRes, alertRes] = await Promise.all([
              api('unified_activity', { limit: 15 }),
              api('unified_alerts'),
            ]);
            renderActivity(actRes.activity || []);
            renderAlerts(alertRes.alerts || []);
          } catch (e) { console.warn('Activity/alerts load error:', e); }
        }, { rootMargin: '300px', onlyOnce: true });
      } else {
        // Fallback: no WdLazy — load immediately
        const [actRes, alertRes] = await Promise.all([
          api('unified_activity', { limit: 15 }),
          api('unified_alerts'),
        ]);
        renderActivity(actRes.activity || []);
        renderAlerts(alertRes.alerts || []);
      }

    } catch (err) {
      console.error('Unified dashboard error:', err);
      if (typeof showToast === 'function') showToast('Failed to load dashboard: ' + err.message, 'error');
    }
  }

  /* ── REFRESH ─────────────────────────────────────────────────── */
  window.udRefresh = function () {
    // tp002+t449: Reset with proper skeletons
    if (typeof WdSkel !== 'undefined') {
      WdSkel.stat('udNetWorth', true);
      WdSkel.list('udActivityList', 5);
    } else {
      document.getElementById('udNetWorth').innerHTML =
        '<span class="ud-skeleton" style="display:inline-block;width:220px;height:44px;border-radius:6px;background:rgba(255,255,255,.15);"></span>';
      document.getElementById('udActivityList').innerHTML =
        '<div style="padding:20px;text-align:center;color:var(--text-secondary);font-size:13px;">Loading…</div>';
    }
    udLoad();
  };

  document.addEventListener('DOMContentLoaded', () => {
    // t449: Show skeletons before first data load
    if (typeof WdSkel !== 'undefined') {
      WdSkel.stat('udNetWorth', true);
      WdSkel.cards('udCardsWrap', 4);
      WdSkel.list('udActivityList', 5);
    }
    udLoad();
  });
})();
</script>

<?php
$pageContent = ob_get_clean();
$extraScripts = '<script src="' . APP_URL . '/public/js/lazy.js?v=' . filemtime(APP_ROOT.'/public/js/lazy.js') . '"></script>';
include APP_ROOT . '/templates/layout.php';
