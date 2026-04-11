/**
 * WealthDash — SIP vs Lump Sum Comparison (tv07)
 * File: public/js/mf_sip_vs_lumpsum.js
 *
 * Screener fund drawer pe: agar tune X saal pehle ₹1L lump sum vs
 * monthly SIP diya hota — kaunsa better tha? Real NAV history se.
 *
 * Integration:
 *   1. Include in mf_screener.php before </body>
 *   2. Call MF_SIP_VS_LS.renderInDrawer(fundId, drawerEl) when drawer opens
 *      OR auto-hooks into existing openDrawer() if MF object available
 *
 * API needed:
 *   api/mutual_funds/mf_nav_history.php?action=nav_history&fund_id=X&period=5Y
 *   Returns: [{nav_date, nav}, ...]
 */

const MF_SIP_VS_LS = (() => {

  // ── Helpers ──────────────────────────────────────────
  function fmtInr(v) {
    const abs = Math.abs(v);
    let s = abs >= 1e5 ? '₹'+(abs/1e5).toFixed(2)+'L' : '₹'+Math.round(abs).toLocaleString('en-IN');
    return (v < 0 ? '-' : '') + s;
  }
  function fmtPct(v) { return (v >= 0 ? '+' : '') + v.toFixed(2) + '%'; }

  // ── XIRR (Newton-Raphson) ───────────────────────────
  function xirr(cashflows, dates) {
    if (cashflows.length !== dates.length || cashflows.length < 2) return null;
    const startDate = dates[0];
    const yearFracs = dates.map(d => (d - startDate) / (365.25 * 86400000));
    let rate = 0.15;
    for (let iter = 0; iter < 100; iter++) {
      let npv = 0, dnpv = 0;
      for (let i = 0; i < cashflows.length; i++) {
        const pv = cashflows[i] / Math.pow(1 + rate, yearFracs[i]);
        npv  += pv;
        dnpv += -yearFracs[i] * cashflows[i] / Math.pow(1 + rate, yearFracs[i] + 1);
      }
      const newRate = rate - npv / dnpv;
      if (Math.abs(newRate - rate) < 1e-7) return newRate;
      rate = newRate;
      if (rate < -0.999) rate = -0.999;
    }
    return rate;
  }

  // ── Core calculation ─────────────────────────────────
  function calculate(navHistory, amount, periodYears) {
    if (!navHistory || navHistory.length < 30) return null;

    // Sort by date
    const sorted = [...navHistory].sort((a,b) => new Date(a.nav_date) - new Date(b.nav_date));
    const latestDate = new Date(sorted[sorted.length - 1].nav_date);
    const latestNav  = parseFloat(sorted[sorted.length - 1].nav);

    // Start date = periodYears ago from latest
    const startDate = new Date(latestDate);
    startDate.setFullYear(startDate.getFullYear() - periodYears);

    // Find closest NAV to start date
    const startEntry = sorted.reduce((best, cur) => {
      const cd = Math.abs(new Date(cur.nav_date) - startDate);
      return cd < Math.abs(new Date(best.nav_date) - startDate) ? cur : best;
    });
    const startNav = parseFloat(startEntry.nav);
    const actualStart = new Date(startEntry.nav_date);

    // ─ Lump Sum ─
    const lsUnits = amount / startNav;
    const lsValue = lsUnits * latestNav;
    const lsReturn = ((lsValue / amount) - 1) * 100;
    const lsYears = (latestDate - actualStart) / (365.25 * 86400000);
    const lsCAGR = (Math.pow(lsValue / amount, 1 / lsYears) - 1) * 100;

    // ─ SIP: monthly installment = amount / (periodYears * 12) ─
    // So total invested same as lump sum
    const months = Math.round(lsYears * 12);
    const sipMonthly = Math.round(amount / months);
    const sipCfs = [], sipDates = [];
    let sipUnitsTotal = 0;

    for (let m = 0; m < months; m++) {
      const sipDate = new Date(actualStart);
      sipDate.setMonth(sipDate.getMonth() + m);
      // Find closest NAV
      const navEntry = sorted.reduce((best, cur) => {
        const cd = Math.abs(new Date(cur.nav_date) - sipDate);
        return cd < Math.abs(new Date(best.nav_date) - sipDate) ? cur : best;
      });
      const sipNav = parseFloat(navEntry.nav);
      sipUnitsTotal += sipMonthly / sipNav;
      sipCfs.push(-sipMonthly);
      sipDates.push(sipDate);
    }
    // Final redemption
    const sipValue = sipUnitsTotal * latestNav;
    sipCfs.push(sipValue);
    sipDates.push(latestDate);
    const sipInvested = sipMonthly * months;

    const sipXirrRate = xirr(sipCfs, sipDates);
    const sipXirr = sipXirrRate !== null ? sipXirrRate * 100 : null;
    const sipReturn = ((sipValue / sipInvested) - 1) * 100;

    // ─ Monthly growth series for chart ─
    const chartLabels = [], lsSeries = [], sipSeries = [];
    let sipUnitsRunning = 0;
    const checkpoints = [3, 6, 9, 12, 18, 24, 30, 36, 48, 60, 72, 84, 96, 108, 120].filter(m => m <= months);

    let sipCumInvested = 0;
    for (let m = 0; m <= months; m++) {
      const d = new Date(actualStart);
      d.setMonth(d.getMonth() + m);
      const navEntry = sorted.reduce((best, cur) => {
        const cd = Math.abs(new Date(cur.nav_date) - d);
        return cd < Math.abs(new Date(best.nav_date) - d) ? cur : best;
      });
      const nav = parseFloat(navEntry.nav);

      if (m < months) { sipUnitsRunning += sipMonthly / nav; sipCumInvested += sipMonthly; }

      if (checkpoints.includes(m) || m === months) {
        const yr = (m / 12).toFixed(1);
        chartLabels.push(m < 12 ? m+'m' : Math.floor(m/12)+'y');
        lsSeries.push(+(lsUnits * nav).toFixed(0));
        sipSeries.push(+(sipUnitsRunning * nav).toFixed(0));
      }
    }

    // ─ Winner ─
    const sipBetter = sipValue > lsValue;
    const diff = Math.abs(sipValue - lsValue);
    const diffPct = (diff / lsValue * 100).toFixed(1);

    return {
      period: periodYears,
      amount,
      sipMonthly,
      months,
      lsValue, lsReturn, lsCAGR, lsInvested: amount,
      sipValue, sipReturn, sipXirr, sipInvested,
      sipBetter, diff, diffPct,
      chartLabels, lsSeries, sipSeries,
      startDate: startEntry.nav_date,
      endDate: sorted[sorted.length-1].nav_date,
    };
  }

  // ── HTML Card ────────────────────────────────────────
  function _html(r, fundName) {
    if (!r) return '<div style="text-align:center;padding:20px;color:var(--muted);font-size:11px">NAV history insufficient for comparison</div>';

    const winner = r.sipBetter ? '🏆 SIP' : '🏆 Lump Sum';
    const winnerColor = r.sipBetter ? 'var(--green)' : 'var(--accent)';
    const cid = 'svl_chart_' + Date.now();

    return `
<div class="svl-wrap">
<style>
.svl-wrap  { padding:14px 16px }
.svl-hdr   { display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px }
.svl-title { font-size:12px;font-weight:800 }
.svl-period-btns { display:flex;gap:4px }
.svl-pbtn  { padding:3px 9px;border-radius:5px;border:1.5px solid var(--border);font-size:10px;font-weight:700;cursor:pointer;background:var(--bg);color:var(--muted);font-family:inherit;transition:all .15s }
.svl-pbtn.active{background:var(--accent);color:#fff;border-color:var(--accent)}
.svl-pbtn:hover:not(.active){border-color:var(--accent);color:var(--accent)}
.svl-cols  { display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px }
.svl-col   { border-radius:10px;padding:12px 14px;text-align:center;border:1.5px solid }
.svl-col-ls{ background:#eff6ff;border-color:#93c5fd }
.svl-col-sip{background:#edfbf2;border-color:#a3e6c4 }
.svl-col-winner{ box-shadow:0 0 0 3px ${winnerColor}40 }
.svl-lbl   { font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:6px }
.svl-val   { font-size:18px;font-weight:800;line-height:1.1;letter-spacing:-.4px }
.svl-sub   { font-size:10px;color:var(--muted);margin-top:3px }
.svl-ret   { font-size:12px;font-weight:800;margin-top:4px }
.svl-winner-banner{
  background:linear-gradient(135deg,${r.sipBetter?'#edfbf2,#f0fdf4':'#eff6ff,#eef2ff'});
  border:1.5px solid ${winnerColor}60;border-radius:8px;padding:8px 12px;
  text-align:center;font-size:11px;font-weight:700;color:${winnerColor};margin-bottom:12px
}
.svl-invested{font-size:10px;color:var(--muted);text-align:center;margin-bottom:10px}
.svl-chart-wrap{background:var(--bg);border-radius:8px;padding:10px;border:1px solid var(--border);margin-bottom:8px}
</style>

<div class="svl-hdr">
  <div class="svl-title">📊 SIP vs Lump Sum — ${r.period}Y</div>
  <div class="svl-period-btns" id="svlPeriodBtns_${cid}">
    ${[1,3,5].map(y => `<button class="svl-pbtn ${y===r.period?'active':''}" onclick="MF_SIP_VS_LS.switchPeriod(${y},'${cid}')">${y}Y</button>`).join('')}
  </div>
</div>

<div class="svl-invested">
  Total Invested: <strong>${fmtInr(r.amount)}</strong> (Lump Sum) vs
  <strong>₹${r.sipMonthly.toLocaleString('en-IN')}/mo × ${r.months}m = ${fmtInr(r.sipInvested)}</strong> (SIP)
  · Period: ${r.startDate} → ${r.endDate}
</div>

<div class="svl-winner-banner">
  ${winner} wins by ${fmtInr(r.diff)} (${r.diffPct}% difference)
</div>

<div class="svl-cols">
  <div class="svl-col svl-col-ls ${!r.sipBetter?'svl-col-winner':''}">
    <div class="svl-lbl">💰 Lump Sum</div>
    <div class="svl-val" style="color:#2563eb">${fmtInr(r.lsValue)}</div>
    <div class="svl-sub">from ${fmtInr(r.lsInvested)}</div>
    <div class="svl-ret" style="color:#2563eb">${fmtPct(r.lsReturn)} total · CAGR ${fmtPct(r.lsCAGR)}</div>
  </div>
  <div class="svl-col svl-col-sip ${r.sipBetter?'svl-col-winner':''}">
    <div class="svl-lbl">🔄 SIP (Monthly)</div>
    <div class="svl-val" style="color:#0d9f57">${fmtInr(r.sipValue)}</div>
    <div class="svl-sub">from ${fmtInr(r.sipInvested)}</div>
    <div class="svl-ret" style="color:#0d9f57">${fmtPct(r.sipReturn)} total${r.sipXirr!==null?' · XIRR '+fmtPct(r.sipXirr):''}</div>
  </div>
</div>

<div class="svl-chart-wrap">
  <canvas id="${cid}" height="110"></canvas>
</div>

<div style="font-size:10px;color:var(--muted);line-height:1.5;padding:6px 8px;background:var(--bg);border-radius:6px;border:1px solid var(--border)">
  💡 <strong>Note:</strong> Lump sum amount = SIP total invested amount for fair comparison.
  Past returns don't guarantee future. SIP reduces timing risk via rupee cost averaging.
  ${r.sipBetter ? 'In this period, SIP worked better — bought more units when market was lower.' :
    'In this period, lump sum worked better — early investment captured the full bull run.'}
</div>
</div>`;
  }

  // ── Chart render (after DOM ready) ─────────────────
  function _drawChart(r, cid) {
    const canvas = document.getElementById(cid);
    if (!canvas || typeof Chart === 'undefined') return;
    new Chart(canvas, {
      type: 'line',
      data: {
        labels: r.chartLabels,
        datasets: [
          {
            label: 'Lump Sum',
            data: r.lsSeries,
            borderColor: '#2563eb',
            backgroundColor: '#2563eb18',
            fill: true,
            tension: 0.4,
            pointRadius: 2,
          },
          {
            label: 'SIP',
            data: r.sipSeries,
            borderColor: '#0d9f57',
            backgroundColor: '#0d9f5718',
            fill: true,
            tension: 0.4,
            pointRadius: 2,
          }
        ]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode:'index', intersect:false },
        plugins: {
          legend: { labels: { font:{size:10}, boxWidth:10 } },
          tooltip: {
            callbacks: {
              label: ctx => ctx.dataset.label + ': ₹' + ctx.raw.toLocaleString('en-IN')
            }
          }
        },
        scales: {
          x: { ticks:{font:{size:9}}, grid:{display:false} },
          y: {
            ticks: {
              font:{size:9},
              callback: v => v >= 100000 ? '₹'+(v/100000).toFixed(1)+'L' : '₹'+v.toLocaleString('en-IN')
            }
          }
        }
      }
    });
  }

  // ── State ───────────────────────────────────────────
  let _currentFundId = null;
  let _currentNavHistory = null;
  let _currentAmount = 100000;
  let _currentCid = null;

  // ── Public: render in a drawer element ──────────────
  async function renderInDrawer(fundId, containerEl, amount = 100000, period = 3) {
    _currentFundId = fundId;
    _currentAmount = amount;

    if (!containerEl) return;
    containerEl.innerHTML = '<div style="text-align:center;padding:20px;color:var(--muted);font-size:11px">📊 Loading NAV history…</div>';

    try {
      // Fetch NAV history
      let navHistory;
      if (window.MF && MF._navCache && MF._navCache[fundId]) {
        navHistory = MF._navCache[fundId];
      } else {
        const resp = await fetch(`/api/mutual_funds/mf_nav_history.php?action=nav_history&fund_id=${fundId}&period=5Y`);
        const data = await resp.json();
        navHistory = data.data || data.nav_history || data;
        if (window.MF) { MF._navCache = MF._navCache || {}; MF._navCache[fundId] = navHistory; }
      }
      _currentNavHistory = navHistory;

      const result = calculate(navHistory, amount, period);
      const fundName = containerEl.closest('[data-fund-name]')?.dataset.fundName || '';
      containerEl.innerHTML = _html(result, fundName);

      // Draw chart after DOM render
      if (result) {
        const cid = containerEl.querySelector('canvas')?.id;
        if (cid) { _currentCid = cid; setTimeout(() => _drawChart(result, cid), 50); }
      }
    } catch(e) {
      containerEl.innerHTML = `<div style="text-align:center;padding:20px;color:var(--muted);font-size:11px">⚠️ NAV data load nahi hua: ${e.message}</div>`;
    }
  }

  function switchPeriod(years, cid) {
    if (!_currentNavHistory || !_currentFundId) return;
    const result = calculate(_currentNavHistory, _currentAmount, years);
    const wrap = document.getElementById(cid)?.closest('.svl-chart-wrap')?.parentElement?.parentElement;
    if (!wrap) return;

    wrap.innerHTML = _html(result, '');
    const newCid = wrap.querySelector('canvas')?.id;
    if (newCid && result) setTimeout(() => _drawChart(result, newCid), 50);

    // Update active period button
    wrap.querySelectorAll('.svl-pbtn').forEach(b => {
      b.classList.toggle('active', parseInt(b.textContent) === years);
    });
  }

  // ── Quick widget (standalone, no drawer needed) ─────
  function renderWidget(containerId, fundId, navHistory, amount = 100000, period = 3) {
    const el = document.getElementById(containerId);
    if (!el) return;
    _currentFundId = fundId;
    _currentNavHistory = navHistory;
    _currentAmount = amount;
    const result = calculate(navHistory, amount, period);
    el.innerHTML = _html(result, '');
    const cid = el.querySelector('canvas')?.id;
    if (cid && result) setTimeout(() => _drawChart(result, cid), 50);
  }

  return { renderInDrawer, renderWidget, switchPeriod, calculate };
})();

// ── Hook into existing MF screener drawer ──────────────
(function() {
  // If MF screener has openDrawer, patch it to add SIP vs LS section
  const _tryPatch = () => {
    if (!window.MF || !MF.openDrawer) return;
    const _orig = MF.openDrawer;
    MF.openDrawer = function(fundId, ...args) {
      _orig.call(this, fundId, ...args);
      // Wait for drawer to render, then inject SIP vs LS section
      setTimeout(() => {
        const drawer = document.querySelector('.mf-drawer, #mfDrawer, .screener-drawer');
        if (!drawer) return;
        // Find or create SIP vs LS section
        let svlSection = drawer.querySelector('.svl-section');
        if (!svlSection) {
          svlSection = document.createElement('div');
          svlSection.className = 'svl-section';
          svlSection.style.cssText = 'border-top:1.5px solid var(--border);margin-top:12px;padding-top:4px';
          svlSection.innerHTML = `
            <div style="padding:10px 16px 4px;font-size:11px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.5px">
              📊 SIP vs Lump Sum
            </div>
            <div id="svlDrawerContent"></div>`;
          drawer.appendChild(svlSection);
        }
        const container = document.getElementById('svlDrawerContent');
        if (container) MF_SIP_VS_LS.renderInDrawer(fundId, container);
      }, 200);
    };
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => setTimeout(_tryPatch, 500));
  } else {
    setTimeout(_tryPatch, 500);
  }
})();
