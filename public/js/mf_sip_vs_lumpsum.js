/**
 * WealthDash — SIP vs Lumpsum Historical Backtest JS (t234)
 * File: public/js/mf_sip_vs_lumpsum.js
 */

/* ═══════════════════════════════════════════════════════════════════════════
   STATE
═══════════════════════════════════════════════════════════════════════════ */
const BT = {
  fundId: 0,
  fundName: '',
  period: '10Y',
  sipAmount: 5000,
  sipDay: 1,
  searchTimer: null,
  growthChart: null,
  activeTab: 'rolling',
  activeWindow: '3Y',
};

/* ═══════════════════════════════════════════════════════════════════════════
   INIT
═══════════════════════════════════════════════════════════════════════════ */
function initSipLumpsumBacktest(prefundId, prePeriod) {
  BT.fundId  = prefundId || 0;
  BT.period  = prePeriod || '10Y';

  // Fund search
  const searchEl = document.getElementById('btFundSearch');
  if (searchEl) {
    searchEl.addEventListener('input', () => {
      clearTimeout(BT.searchTimer);
      BT.searchTimer = setTimeout(() => btFundSearch(searchEl.value.trim()), 280);
    });
    document.addEventListener('pointerdown', (e) => {
      if (!e.target.closest('#btFundSearch') && !e.target.closest('#btFundDropdown')) {
        document.getElementById('btFundDropdown').style.display = 'none';
      }
    });
  }

  document.getElementById('btnRunBacktest')?.addEventListener('click', runBacktest);

  // If fund_id in URL, pre-fill
  if (BT.fundId) {
    fetch(`${window.APP_URL || ''}/api/router.php?action=mf_list&fund_id=${BT.fundId}`)
      .then(r => r.json())
      .then(d => {
        if (d.success && d.data?.length) {
          const f = d.data[0];
          document.getElementById('btFundSearch').value = f.scheme_name;
          document.getElementById('btFundInfo').textContent = f.category || '';
          BT.fundName = f.scheme_name;
        }
      }).catch(() => {});
  }
}

/* ═══════════════════════════════════════════════════════════════════════════
   FUND SEARCH AUTOCOMPLETE
═══════════════════════════════════════════════════════════════════════════ */
async function btFundSearch(q) {
  const dd = document.getElementById('btFundDropdown');
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
      <div class="fund-search-item" data-id="${f.id}" data-name="${escHtml(f.scheme_name)}"
           data-nav="${f.latest_nav || ''}" data-cat="${escHtml(f.category || '')}"
           style="padding:10px 14px;cursor:pointer;border-bottom:1px solid var(--border-color);"
           onmouseenter="this.style.background='var(--bg-secondary)'"
           onmouseleave="this.style.background=''"
           onclick="btSelectFund(${f.id},'${escHtml(f.scheme_name).replace(/'/g,"\\'")}','${escHtml(f.category||'')}')">
        <div style="font-size:13px;font-weight:500;">${escHtml(f.scheme_name)}</div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
          ${escHtml(f.category || '')}
          ${f.latest_nav ? ' · NAV ₹' + parseFloat(f.latest_nav).toFixed(2) : ''}
        </div>
      </div>`).join('');
  } catch (e) {
    dd.innerHTML = '<div style="padding:12px;font-size:13px;color:var(--danger);">Search failed</div>';
  }
}

function btSelectFund(id, name, cat) {
  BT.fundId   = id;
  BT.fundName = name;
  document.getElementById('btFundId').value    = id;
  document.getElementById('btFundSearch').value = name;
  document.getElementById('btFundInfo').textContent = cat;
  document.getElementById('btFundDropdown').style.display = 'none';
}

/* ═══════════════════════════════════════════════════════════════════════════
   PERIOD TOGGLE
═══════════════════════════════════════════════════════════════════════════ */
function setBtPeriod(p) {
  BT.period = p;
  document.querySelectorAll('#btPeriodBtns .btn').forEach(b => {
    const active = b.dataset.period === p;
    b.classList.toggle('btn-primary', active);
    b.classList.toggle('btn-ghost', !active);
  });
}

/* ═══════════════════════════════════════════════════════════════════════════
   RUN BACKTEST
═══════════════════════════════════════════════════════════════════════════ */
async function runBacktest() {
  const fundId   = parseInt(document.getElementById('btFundId').value) || BT.fundId;
  const amount   = parseFloat(document.getElementById('btSipAmount').value) || 5000;
  const sipDay   = parseInt(document.getElementById('btSipDay').value) || 1;
  const period   = BT.period;

  if (!fundId) { showBtError('Please select a fund first.'); return; }

  BT.fundId   = fundId;
  BT.sipAmount = amount;
  BT.sipDay    = sipDay;

  // Show loading
  setBtView('loading');

  try {
    const url = `${window.APP_URL || ''}/api/router.php?action=sip_lumpsum_backtest` +
                `&fund_id=${fundId}&period=${period}&amount=${amount}&sip_day=${sipDay}`;
    const r = await fetch(url);
    const d = await r.json();

    if (!d.success) { showBtError(d.message || 'Backtest failed'); return; }

    renderBacktest(d);
    setBtView('results');

  } catch (e) {
    showBtError('Network error: ' + e.message);
  }
}

/* ═══════════════════════════════════════════════════════════════════════════
   RENDER RESULTS
═══════════════════════════════════════════════════════════════════════════ */
function renderBacktest(d) {
  const { sip, lumpsum, comparison, params, chart_data } = d;
  const winner = comparison.winner;

  // SIP card
  document.getElementById('btSipInvested').textContent = fmtRs(sip.invested);
  document.getElementById('btSipFinal').textContent    = fmtRs(sip.final_value);
  document.getElementById('btSipGain').textContent     = fmtRs(sip.gain) + ` (${sip.gain_pct}%)`;
  document.getElementById('btSipXirr').textContent     = sip.xirr + '% p.a.';
  document.getElementById('btSipSubtitle').textContent = `₹${fmtNum(params.monthly_amount)}/mo × ${params.months} months`;

  const sipCard = document.getElementById('btSipCard');
  const sipBadge = document.getElementById('btSipWinnerBadge');
  if (winner === 'sip') {
    sipCard.style.borderColor = 'var(--primary)';
    sipBadge.textContent = '🏆 Winner';
    sipBadge.style.cssText += ';display:block;background:rgba(99,102,241,.15);color:var(--primary);';
  } else {
    sipCard.style.borderColor = 'transparent';
    sipBadge.style.display = 'none';
  }

  // Lumpsum card
  document.getElementById('btLsInvested').textContent = fmtRs(lumpsum.invested);
  document.getElementById('btLsFinal').textContent    = fmtRs(lumpsum.final_value);
  document.getElementById('btLsGain').textContent     = fmtRs(lumpsum.gain) + ` (${lumpsum.gain_pct}%)`;
  document.getElementById('btLsCagr').textContent     = lumpsum.cagr + '% p.a.';
  document.getElementById('btLsSubtitle').textContent = `₹${fmtNum(lumpsum.invested)} one-time`;

  const lsCard  = document.getElementById('btLsCard');
  const lsBadge = document.getElementById('btLsWinnerBadge');
  if (winner === 'lumpsum') {
    lsCard.style.borderColor = 'var(--warning,#f59e0b)';
    lsBadge.textContent = '🏆 Winner';
    lsBadge.style.cssText += ';display:block;background:rgba(245,158,11,.15);color:var(--warning,#f59e0b);';
  } else {
    lsCard.style.borderColor = 'transparent';
    lsBadge.style.display = 'none';
  }

  // Verdict
  const diff = Math.abs(comparison.sip_advantage);
  document.getElementById('btVerdictEmoji').textContent = winner === 'sip' ? '📈' : '💰';
  document.getElementById('btVerdictTitle').textContent  = comparison.verdict;
  document.getElementById('btVerdictDesc').textContent   =
    `Period: ${params.start_date} to ${params.end_date} · ` +
    `XIRR diff: ${comparison.sip_xirr_vs_ls_cagr > 0 ? '+' : ''}${comparison.sip_xirr_vs_ls_cagr}%`;

  // Growth Chart
  renderGrowthChart(chart_data, params.monthly_amount * params.months);
}

function renderGrowthChart(chartData, lumpsumInvested) {
  if (BT.growthChart) { BT.growthChart.destroy(); BT.growthChart = null; }

  const labels   = chartData.map(p => p.date);
  const sipVals  = chartData.map(p => p.sip_value);
  const lsVals   = chartData.map(p => p.ls_value);
  const invested = chartData.map(p => p.invested);

  const ctx = document.getElementById('btGrowthChart').getContext('2d');
  BT.growthChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels,
      datasets: [
        {
          label: 'SIP Value',
          data: sipVals,
          borderColor: 'var(--primary, #6366f1)',
          backgroundColor: 'rgba(99,102,241,.08)',
          fill: true,
          tension: 0.3,
          pointRadius: 0,
          borderWidth: 2,
        },
        {
          label: 'Lumpsum Value',
          data: lsVals,
          borderColor: '#f59e0b',
          backgroundColor: 'transparent',
          fill: false,
          tension: 0.3,
          pointRadius: 0,
          borderWidth: 2,
          borderDash: [0],
        },
        {
          label: 'SIP Invested',
          data: invested,
          borderColor: 'rgba(150,150,150,.5)',
          backgroundColor: 'transparent',
          fill: false,
          tension: 0,
          pointRadius: 0,
          borderWidth: 1,
          borderDash: [5, 4],
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
            label: ctx => `${ctx.dataset.label}: ₹${fmtNum(Math.round(ctx.parsed.y))}`,
          },
        },
      },
      scales: {
        x: {
          ticks: {
            maxTicksLimit: 8,
            color: 'var(--text-muted)',
            font: { size: 11 },
          },
          grid: { display: false },
        },
        y: {
          ticks: {
            color: 'var(--text-muted)',
            font: { size: 11 },
            callback: v => '₹' + (v >= 100000 ? (v/100000).toFixed(1) + 'L' : fmtNum(Math.round(v))),
          },
          grid: { color: 'rgba(128,128,128,.1)' },
        },
      },
    },
  });
}

/* ═══════════════════════════════════════════════════════════════════════════
   TAB SWITCH
═══════════════════════════════════════════════════════════════════════════ */
function switchBtTab(tab) {
  BT.activeTab = tab;
  const tabs = { rolling: 'tabRolling', bestentry: 'tabBestEntry' };
  const contents = { rolling: 'tabContentRolling', bestentry: 'tabContentBestEntry' };

  Object.keys(tabs).forEach(t => {
    const active = t === tab;
    document.getElementById(tabs[t]).style.borderBottom =
      active ? '2px solid var(--primary)' : '2px solid transparent';
    document.getElementById(contents[t]).style.display = active ? '' : 'none';
  });

  if (tab === 'rolling' && BT.fundId) loadRolling(BT.activeWindow);
  if (tab === 'bestentry' && BT.fundId) loadBestEntry();
}

/* ═══════════════════════════════════════════════════════════════════════════
   ROLLING BACKTEST
═══════════════════════════════════════════════════════════════════════════ */
async function loadRolling(w) {
  if (!BT.fundId) return;
  BT.activeWindow = w;

  document.querySelectorAll('[data-window]').forEach(b => {
    const active = b.dataset.window === w;
    b.classList.toggle('btn-primary', active);
    b.classList.toggle('btn-ghost', !active);
  });

  document.getElementById('btRollingStats').style.display = 'none';
  document.getElementById('btRollingLoading').style.display = 'block';

  try {
    const url = `${window.APP_URL || ''}/api/router.php?action=sip_lumpsum_backtest` +
                `&fund_id=${BT.fundId}&period=${BT.period}&amount=${BT.sipAmount}&window=${w}&action=rolling_backtest`;
    // Override action param in URL
    const url2 = `${window.APP_URL || ''}/api/router.php?action=sip_lumpsum_backtest_rolling` +
                 `&fund_id=${BT.fundId}&amount=${BT.sipAmount}&window=${w}`;
    const r = await fetch(url2);
    const d = await r.json();

    document.getElementById('btRollingLoading').style.display = 'none';
    if (!d.success) { document.getElementById('btRollingVerdict').textContent = d.message || 'Failed'; return; }

    document.getElementById('btRollingTotal').textContent    = d.total_windows;
    document.getElementById('btRollingSipWins').textContent  = d.sip_wins;
    document.getElementById('btRollingLsWins').textContent   = d.ls_wins;
    document.getElementById('btRollingSipPct').textContent   = d.sip_win_pct + '%';
    document.getElementById('btRollingVerdict').textContent  = d.verdict;
    document.getElementById('btRollingStats').style.display  = '';
  } catch (e) {
    document.getElementById('btRollingLoading').style.display = 'none';
    document.getElementById('btRollingVerdict').textContent = 'Failed: ' + e.message;
    document.getElementById('btRollingStats').style.display = '';
  }
}

/* ═══════════════════════════════════════════════════════════════════════════
   BEST ENTRY ANALYSIS
═══════════════════════════════════════════════════════════════════════════ */
async function loadBestEntry() {
  if (!BT.fundId) return;
  const amt = parseFloat(document.getElementById('btLsAmount').value) || 100000;

  document.getElementById('btBestEntryContent').style.display = 'none';
  document.getElementById('btBestEntryLoading').style.display = 'block';

  try {
    const url = `${window.APP_URL || ''}/api/router.php?action=sip_lumpsum_best_entry` +
                `&fund_id=${BT.fundId}&amount=${amt}`;
    const r = await fetch(url);
    const d = await r.json();

    document.getElementById('btBestEntryLoading').style.display = 'none';
    if (!d.success) return;

    const renderRows = (rows, tbody) => {
      document.getElementById(tbody).innerHTML = rows.map(e => `
        <tr>
          <td>${e.date}</td>
          <td class="text-right">${fmtRs(e.final_value)}</td>
          <td class="text-right">${e.cagr}%</td>
        </tr>`).join('');
    };
    renderRows(d.best_entries,  'btBestEntryBest');
    renderRows(d.worst_entries, 'btBestEntryWorst');
    document.getElementById('btBestEntryInsight').textContent = d.insight;
    document.getElementById('btBestEntryContent').style.display = '';
  } catch (e) {
    document.getElementById('btBestEntryLoading').style.display = 'none';
  }
}

/* ═══════════════════════════════════════════════════════════════════════════
   VIEW HELPERS
═══════════════════════════════════════════════════════════════════════════ */
function setBtView(v) {
  document.getElementById('btEmpty').style.display     = v === 'empty'   ? '' : 'none';
  document.getElementById('btLoading').hidden           = v !== 'loading';
  document.getElementById('btResults').hidden           = v !== 'results';
  document.getElementById('btError').hidden             = v !== 'error';
}

function showBtError(msg) {
  document.getElementById('btErrorMsg').textContent = msg;
  setBtView('error');
}

/* ═══════════════════════════════════════════════════════════════════════════
   FORMAT HELPERS
═══════════════════════════════════════════════════════════════════════════ */
function fmtRs(n) {
  if (n == null) return '—';
  n = parseFloat(n);
  if (n >= 10000000)  return '₹' + (n / 10000000).toFixed(2) + ' Cr';
  if (n >= 100000)    return '₹' + (n / 100000).toFixed(2) + ' L';
  return '₹' + fmtNum(Math.round(n));
}

function fmtNum(n) {
  return Number(n).toLocaleString('en-IN');
}

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
