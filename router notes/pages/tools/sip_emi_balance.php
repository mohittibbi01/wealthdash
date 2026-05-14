<?php
/**
 * WealthDash — t474: SIP vs EMI Monthly Load Analysis Page
 * File: pages/tools/sip_emi_balance.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle    = 'SIP vs EMI Balance';
$activePage   = 'tools';
$activeSection= 'tools';
ob_start();
?>
<div class="page-header">
  <h1 class="page-title">⚖️ SIP vs EMI Monthly Load</h1>
  <p class="page-subtitle">Understand your monthly cash outflow split between investments and loan repayments.</p>
</div>

<!-- Summary cards -->
<div id="sip-emi-cards" class="dashboard-grid" style="margin-bottom:24px;"></div>

<!-- Ratio bar -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header"><span class="card-title">Monthly Load Split</span></div>
  <div class="card-body">
    <div id="sip-emi-ratio-bar" style="display:none;">
      <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">
        <span style="color:var(--gain);font-weight:600;">SIP — Investments</span>
        <span style="color:#dc2626;font-weight:600;">EMI — Loans</span>
      </div>
      <div style="height:24px;border-radius:12px;overflow:hidden;background:var(--bg-secondary);display:flex;">
        <div id="sip-bar-fill" style="background:var(--gain);transition:width .4s;height:100%;"></div>
        <div id="emi-bar-fill" style="background:#dc2626;transition:width .4s;height:100%;"></div>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted);margin-top:6px;">
        <span id="sip-bar-pct"></span>
        <span id="emi-bar-pct"></span>
      </div>
    </div>
    <div id="sip-emi-chart-wrap" style="height:220px;margin-top:20px;">
      <canvas id="sipEmiChart"></canvas>
    </div>
  </div>
</div>

<!-- 12-month projection table -->
<div class="card" style="margin-bottom:20px;">
  <div class="card-header">
    <span class="card-title">12-Month Projection</span>
  </div>
  <div class="card-body p-0">
    <div id="sip-emi-projection-table"></div>
  </div>
</div>

<!-- Active SIPs / EMIs side by side -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;" class="responsive-grid-1col">
  <div class="card">
    <div class="card-header"><span class="card-title">📈 Active SIPs</span></div>
    <div class="card-body p-0"><div id="sip-list-table"></div></div>
  </div>
  <div class="card">
    <div class="card-header"><span class="card-title">🏦 Active EMIs / Loans</span></div>
    <div class="card-body p-0"><div id="emi-list-table"></div></div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  apiPost({ action: 'sip_emi_summary' }).then(r => {
    if (!r.ok) return;
    const d = r.data;

    // Cards
    document.getElementById('sip-emi-cards').innerHTML = `
      <div class="stat-card">
        <div class="stat-label">Monthly SIP</div>
        <div class="stat-value wd-num-xl wd-gain">${formatINR(d.sip_monthly)}</div>
        <div class="stat-sub">${d.sips.length} active SIP${d.sips.length!==1?'s':''}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Monthly EMI</div>
        <div class="stat-value wd-num-xl wd-loss">${formatINR(d.emi_monthly)}</div>
        <div class="stat-sub">${d.emis.length} active loan${d.emis.length!==1?'s':''}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">Total Monthly Outflow</div>
        <div class="stat-value wd-num-xl">${formatINR(d.total_load)}</div>
        <div class="stat-sub">SIP + EMI combined</div>
      </div>`;

    // Ratio bar
    if (d.total_load > 0) {
      document.getElementById('sip-emi-ratio-bar').style.display = '';
      document.getElementById('sip-bar-fill').style.width = d.sip_ratio + '%';
      document.getElementById('emi-bar-fill').style.width = d.emi_ratio + '%';
      document.getElementById('sip-bar-pct').textContent = d.sip_ratio + '% SIP';
      document.getElementById('emi-bar-pct').textContent = d.emi_ratio + '% EMI';
    }

    // Chart — 12-month projection
    if (d.projection?.length) {
      const labels = d.projection.map(p => p.label);
      const sipData = d.projection.map(p => p.sip);
      const emiData = d.projection.map(p => p.emi);
      new Chart(document.getElementById('sipEmiChart'), {
        type: 'bar',
        data: {
          labels,
          datasets: [
            { label: 'SIP', data: sipData, backgroundColor: '#16a34a', borderRadius: 4, stack: 'a' },
            { label: 'EMI', data: emiData, backgroundColor: '#dc2626', borderRadius: 4, stack: 'a' },
          ]
        },
        options: {
          responsive: true, maintainAspectRatio: false,
          plugins: {
            legend: { labels: { color: getComputedStyle(document.documentElement).getPropertyValue('--text-primary').trim() } },
            tooltip: { callbacks: { label: c => ` ${c.dataset.label}: ${formatINR(c.raw)}` } }
          },
          scales: {
            x: { stacked: true, ticks: { color: '#6b7280', font:{size:11} }, grid:{display:false} },
            y: { stacked: true, ticks: { color:'#6b7280', callback: v => formatINR(v,0) }, grid:{color:'var(--border)'} }
          }
        }
      });
    }

    // Projection table
    let ptHtml = `<div class="table-responsive"><table class="data-table">
      <thead><tr><th>Month</th><th class="text-right">SIP</th><th class="text-right">EMI</th><th class="text-right">Total</th><th>Balance Health</th></tr></thead><tbody>`;
    for (const p of (d.projection || [])) {
      const sipPct = p.total > 0 ? Math.round((p.sip/p.total)*100) : 0;
      const health = sipPct >= 60 ? '🟢 Investment-heavy' : sipPct >= 40 ? '🟡 Balanced' : '🔴 Debt-heavy';
      ptHtml += `<tr>
        <td style="font-weight:600;">${esc(p.label)}</td>
        <td class="text-right wd-num wd-gain">${formatINR(p.sip)}</td>
        <td class="text-right wd-num wd-loss">${formatINR(p.emi)}</td>
        <td class="text-right wd-num" style="font-weight:700;">${formatINR(p.total)}</td>
        <td style="font-size:12px;">${health}</td>
      </tr>`;
    }
    ptHtml += '</tbody></table></div>';
    document.getElementById('sip-emi-projection-table').innerHTML = ptHtml;

    // SIP list
    let sipHtml = '';
    if (!d.sips.length) {
      sipHtml = '<div class="empty-state"><div>No active SIPs found.</div></div>';
    } else {
      sipHtml = `<div class="table-responsive"><table class="data-table">
        <thead><tr><th>Fund</th><th class="text-right">SIP Amount</th><th>Date</th><th>Freq</th></tr></thead><tbody>`;
      for (const s of d.sips) {
        sipHtml += `<tr>
          <td style="font-size:13px;">${esc(s.fund_name || '—')}</td>
          <td class="text-right wd-num wd-gain">${formatINR(s.sip_amount)}</td>
          <td style="font-size:12px;">${esc(s.sip_date || '—')}</td>
          <td style="font-size:12px;text-transform:capitalize;">${esc(s.sip_frequency || 'Monthly')}</td>
        </tr>`;
      }
      sipHtml += '</tbody></table></div>';
    }
    document.getElementById('sip-list-table').innerHTML = sipHtml;

    // EMI list
    let emiHtml = '';
    if (!d.emis.length) {
      emiHtml = '<div class="empty-state"><div>No active EMIs found.</div></div>';
    } else {
      emiHtml = `<div class="table-responsive"><table class="data-table">
        <thead><tr><th>Loan</th><th class="text-right">EMI</th><th>Date</th><th class="text-right">Outstanding</th></tr></thead><tbody>`;
      for (const e of d.emis) {
        emiHtml += `<tr>
          <td style="font-size:13px;">${esc(e.loan_name || e.loan_type || '—')}</td>
          <td class="text-right wd-num wd-loss">${formatINR(e.emi_amount)}</td>
          <td style="font-size:12px;">${esc(e.emi_date || '—')}</td>
          <td class="text-right wd-num" style="font-size:12px;">${formatINR(e.outstanding_principal)}</td>
        </tr>`;
      }
      emiHtml += '</tbody></table></div>';
    }
    document.getElementById('emi-list-table').innerHTML = emiHtml;
  });
});
</script>
<?php
$pageContent = ob_get_clean();
include APP_ROOT . '/templates/layout.php';
