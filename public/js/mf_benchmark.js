/**
 * WealthDash — Fund vs Benchmark Comparison JS (tv11)
 * File: public/js/mf_benchmark.js
 */

/* ═══════════════════════════════════════════════════════════════════════════
   STATE
═══════════════════════════════════════════════════════════════════════════ */
const BM = {
  fundId: 0,
  fundName: '',
  period: '1Y',
  benchmark: '',
  searchTimer: null,
  chart: null,
};

/* ═══════════════════════════════════════════════════════════════════════════
   INIT
═══════════════════════════════════════════════════════════════════════════ */
function initBenchmarkPage(preFundId, prePeriod) {
  BM.fundId  = preFundId || 0;
  BM.period  = prePeriod || '1Y';

  // Fund search
  const searchEl = document.getElementById('bmFundSearch');
  if (searchEl) {
    searchEl.addEventListener('input', () => {
      clearTimeout(BM.searchTimer);
      BM.searchTimer = setTimeout(() => bmFundSearch(searchEl.value.trim()), 280);
    });
    document.addEventListener('pointerdown', (e) => {
      if (!e.target.closest('#bmFundSearch') && !e.target.closest('#bmFundDropdown')) {
        document.getElementById('bmFundDropdown').style.display = 'none';
      }
    });
  }

  document.getElementById('btnLoadBenchmark')?.addEventListener('click', loadBenchmarkCompare);
  document.getElementById('btnBmAssign')?.addEventListener('click', assignBenchmark);

  // If fund pre-selected (from URL), auto-load
  if (BM.fundId) {
    loadBenchmarkCompare();
    loadAlphaTable();
  }
}

/* ═══════════════════════════════════════════════════════════════════════════
   FUND SEARCH
═══════════════════════════════════════════════════════════════════════════ */
async function bmFundSearch(q) {
  const dd = document.getElementById('bmFundDropdown');
  if (!q || q.length < 2) { dd.style.display = 'none'; return; }

  dd.style.display = 'block';
  dd.innerHTML = '<div style="padding:12px;text-align:center;"><div class="spinner-sm"></div></div>';

  try {
    const r = await fetch(`${window.APP_URL || ''}/api/router.php?action=mf_search&q=${encodeURIComponent(q)}&limit=12`);
    const d = await r.json();
    const funds = d.funds || d.data || [];

    if (!funds.length) {
      dd.innerHTML = '<div style="padding:12px;font-size:13px;color:var(--text-muted);">No funds found</div>';
      return;
    }

    dd.innerHTML = funds.map(f => `
      <div style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border-color);"
           onmouseenter="this.style.background='var(--bg-secondary)'"
           onmouseleave="this.style.background=''"
           onclick="bmSelectFund(${f.id},'${bmEsc(f.scheme_name)}','${bmEsc(f.benchmark_index||'')}')">
        <div style="font-size:13px;font-weight:500;">${bmEsc(f.scheme_name)}</div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
          ${bmEsc(f.category||'')}${f.latest_nav ? ' · NAV ₹' + parseFloat(f.latest_nav).toFixed(2) : ''}
        </div>
      </div>`).join('');
  } catch (e) {
    dd.innerHTML = '<div style="padding:12px;font-size:13px;color:var(--danger);">Search failed</div>';
  }
}

function bmSelectFund(id, name, bmIndex) {
  BM.fundId   = id;
  BM.fundName = name;
  document.getElementById('bmFundId').value     = id;
  document.getElementById('bmFundSearch').value = name;
  document.getElementById('bmFundDropdown').style.display = 'none';
  if (bmIndex) document.getElementById('bmAssignSelect').value = bmIndex;
}

/* ═══════════════════════════════════════════════════════════════════════════
   PERIOD TOGGLE
═══════════════════════════════════════════════════════════════════════════ */
function setBmPeriod(p) {
  BM.period = p;
  document.querySelectorAll('#bmPeriodBtns .btn').forEach(b => {
    const active = b.dataset.period === p;
    b.classList.toggle('btn-primary', active);
    b.classList.toggle('btn-ghost', !active);
  });
}

/* ═══════════════════════════════════════════════════════════════════════════
   MAIN: BENCHMARK COMPARE
═══════════════════════════════════════════════════════════════════════════ */
async function loadBenchmarkCompare() {
  const fundId    = parseInt(document.getElementById('bmFundId').value) || BM.fundId;
  const benchmark = document.getElementById('bmBenchmarkSelect').value;
  const period    = BM.period;

  if (!fundId) { bmShowError('Please select a fund first.'); return; }

  BM.fundId = fundId;
  BM.benchmark = benchmark;

  setBmView('loading');

  try {
    let url = `${window.APP_URL || ''}/api/router.php?action=benchmark_compare&fund_id=${fundId}&period=${period}`;
    if (benchmark) url += `&benchmark=${encodeURIComponent(benchmark)}`;

    const r = await fetch(url);
    const d = await r.json();

    if (!d.success) { bmShowError(d.message || 'Failed to load benchmark data'); return; }

    renderBenchmarkData(d);
    setBmView('results');

    // Also load alpha table
    loadAlphaTable();

  } catch (e) {
    bmShowError('Network error: ' + e.message);
  }
}

function renderBenchmarkData(d) {
  // Stats
  const fundRet  = d.fund_return_pct;
  const benchRet = d.bench_return_pct;
  const alpha    = d.alpha_pct;

  document.getElementById('bmFundReturn').textContent  = fundRet  != null ? fundRet  + '%' : '—';
  document.getElementById('bmBenchReturn').textContent = benchRet != null ? benchRet + '%' : '—';
  document.getElementById('bmBenchLabel').textContent  = d.benchmark_name + ' Return';
  document.getElementById('bmAlphaThBench').textContent = d.benchmark_name;

  if (alpha != null) {
    const el = document.getElementById('bmAlpha');
    el.textContent = (alpha >= 0 ? '+' : '') + alpha + '%';
    el.style.color = alpha >= 0 ? 'var(--success)' : 'var(--danger)';
  }

  const outEl = document.getElementById('bmOutperformed');
  if (d.outperformed === true) {
    outEl.textContent = '✅ Yes';
    outEl.style.color = 'var(--success)';
  } else if (d.outperformed === false) {
    outEl.textContent = '❌ No';
    outEl.style.color = 'var(--danger)';
  } else {
    outEl.textContent = '—';
  }

  document.getElementById('bmChartTitle').textContent =
    `${d.scheme_name} vs ${d.benchmark_name} (${d.period})`;

  // Legend
  document.getElementById('bmChartLegend').innerHTML = `
    <span style="display:flex;align-items:center;gap:5px;">
      <span style="width:12px;height:3px;background:var(--primary);display:inline-block;border-radius:2px;"></span>
      ${bmEsc(d.scheme_name.length > 35 ? d.scheme_name.substring(0, 35) + '…' : d.scheme_name)}
    </span>
    <span style="display:flex;align-items:center;gap:5px;">
      <span style="width:12px;height:3px;background:#f59e0b;display:inline-block;border-radius:2px;"></span>
      ${bmEsc(d.benchmark_name)}
    </span>`;

  // Chart
  renderBenchmarkChart(d.fund_nav_data, d.benchmark_data, d.scheme_name, d.benchmark_name);

  // Assign card — prefill with current benchmark
  document.getElementById('bmAssignSelect').value = d.benchmark_symbol || '^NSEI';
}

function renderBenchmarkChart(fundData, benchData, fundName, benchName) {
  if (BM.chart) { BM.chart.destroy(); BM.chart = null; }

  // Build unified date labels
  const dateSet  = new Set([...fundData.map(p => p.date), ...benchData.map(p => p.date)]);
  const labels   = [...dateSet].sort();
  const fundMap  = Object.fromEntries(fundData.map(p => [p.date, p.close]));
  const benchMap = Object.fromEntries(benchData.map(p => [p.date, p.close]));

  // Forward-fill gaps
  let lastF = null, lastB = null;
  const fundVals  = labels.map(d => { if (fundMap[d]  != null) lastF = fundMap[d];  return lastF; });
  const benchVals = labels.map(d => { if (benchMap[d] != null) lastB = benchMap[d]; return lastB; });

  const ctx = document.getElementById('bmChart').getContext('2d');
  BM.chart = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [
        {
          label: fundName,
          data: fundVals,
          borderColor: 'var(--primary, #6366f1)',
          backgroundColor: 'rgba(99,102,241,.07)',
          fill: true,
          tension: 0.3,
          pointRadius: 0,
          borderWidth: 2,
        },
        {
          label: benchName,
          data: benchVals,
          borderColor: '#f59e0b',
          backgroundColor: 'transparent',
          fill: false,
          tension: 0.3,
          pointRadius: 0,
          borderWidth: 2,
        },
      ],
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => `${ctx.dataset.label}: ${ctx.parsed.y?.toFixed(2) ?? '—'}`,
            title: ts => ts[0]?.label || '',
          },
        },
      },
      scales: {
        x: {
          ticks: { maxTicksLimit: 8, color: 'var(--text-muted)', font: { size: 11 } },
          grid: { display: false },
        },
        y: {
          ticks: {
            color: 'var(--text-muted)',
            font: { size: 11 },
            callback: v => v?.toFixed(1),
          },
          grid: { color: 'rgba(128,128,128,.1)' },
          title: {
            display: true,
            text: 'Normalized (100 = start)',
            color: 'var(--text-muted)',
            font: { size: 11 },
          },
        },
      },
    },
  });
}

/* ═══════════════════════════════════════════════════════════════════════════
   ALPHA TABLE (all periods)
═══════════════════════════════════════════════════════════════════════════ */
async function loadAlphaTable() {
  const fundId = BM.fundId;
  if (!fundId) return;

  document.getElementById('bmAlphaBody').innerHTML =
    '<tr><td colspan="5" class="text-center" style="padding:24px;"><div class="spinner"></div></td></tr>';

  try {
    const url = `${window.APP_URL || ''}/api/router.php?action=benchmark_alpha&fund_id=${fundId}`;
    const r   = await fetch(url);
    const d   = await r.json();
    if (!d.success) return;

    // Update benchmark label in table header
    if (d.benchmark_name) {
      document.getElementById('bmAlphaThBench').textContent = d.benchmark_name;
    }

    const rows = Object.entries(d.periods).map(([period, v]) => {
      const alpha = v.alpha;
      const pos   = alpha != null && alpha >= 0;
      const alphaStr = alpha != null
        ? `<span style="color:${pos ? 'var(--success)' : 'var(--danger)'};">${pos ? '+' : ''}${alpha}%</span>`
        : '—';
      const badge = v.outperformed === true
        ? '<span style="font-size:11px;color:var(--success);">✅ Beat</span>'
        : v.outperformed === false
          ? '<span style="font-size:11px;color:var(--danger);">❌ Lagged</span>'
          : '<span style="font-size:11px;color:var(--text-muted);">—</span>';
      return `<tr>
        <td style="font-weight:500;">${period}</td>
        <td class="text-right">${v.fund_return  != null ? v.fund_return  + '%' : '—'}</td>
        <td class="text-right">${v.bench_return != null ? v.bench_return + '%' : '—'}</td>
        <td class="text-right">${alphaStr}</td>
        <td class="text-center">${badge}</td>
      </tr>`;
    }).join('');

    document.getElementById('bmAlphaBody').innerHTML = rows || '<tr><td colspan="5" class="text-center">No data</td></tr>';

    // Consistency badge
    const c = d.consistency;
    if (c) {
      const pct = c.score ?? 0;
      const color = pct >= 70 ? 'var(--success)' : pct >= 40 ? 'var(--warning,#f59e0b)' : 'var(--danger)';
      document.getElementById('bmConsistencyBadge').innerHTML =
        `<span style="color:${color};font-weight:600;">${c.label}</span>
         <span style="color:var(--text-muted);margin-left:8px;">
           (${pct}% outperformance consistency vs ${d.benchmark_name})
         </span>`;
    }

  } catch (e) {
    document.getElementById('bmAlphaBody').innerHTML =
      `<tr><td colspan="5" class="text-center" style="color:var(--danger);">Failed: ${bmEsc(e.message)}</td></tr>`;
  }
}

/* ═══════════════════════════════════════════════════════════════════════════
   BENCHMARK ASSIGN
═══════════════════════════════════════════════════════════════════════════ */
async function assignBenchmark() {
  const fundId    = BM.fundId;
  const benchmark = document.getElementById('bmAssignSelect').value;
  const msgEl     = document.getElementById('bmAssignMsg');

  if (!fundId || !benchmark) return;

  try {
    const fd = new FormData();
    fd.append('action', 'benchmark_assign');
    fd.append('fund_id', fundId);
    fd.append('benchmark', benchmark);

    const r = await fetch(`${window.APP_URL || ''}/api/router.php`, { method: 'POST', body: fd });
    const d = await r.json();
    msgEl.textContent = d.success ? '✅ Saved' : (d.message || 'Failed');
    msgEl.style.color = d.success ? 'var(--success)' : 'var(--danger)';
    setTimeout(() => { msgEl.textContent = ''; }, 3000);
  } catch (e) {
    msgEl.textContent = 'Error: ' + e.message;
    msgEl.style.color = 'var(--danger)';
  }
}

/* ═══════════════════════════════════════════════════════════════════════════
   VIEW HELPERS
═══════════════════════════════════════════════════════════════════════════ */
function setBmView(v) {
  const isResults = v === 'results';
  const isEmpty   = v === 'empty';

  document.getElementById('bmEmptyState').style.display = isEmpty ? '' : 'none';
  document.getElementById('bmLoading').hidden            = v !== 'loading';
  document.getElementById('bmError').hidden              = v !== 'error';
  document.getElementById('bmStatsGrid').hidden          = !isResults;
  document.getElementById('bmChartCard').hidden          = !isResults;
  document.getElementById('bmAlphaCard').hidden          = !isResults;
  document.getElementById('bmAssignCard').hidden         = !isResults;
}

function bmShowError(msg) {
  document.getElementById('bmErrorMsg').textContent = msg;
  setBmView('error');
}

function bmEsc(s) {
  return String(s || '')
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;');
}
