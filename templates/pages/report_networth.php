<?php
/**
 * WealthDash — Net Worth Report Page
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

$currentUser   = require_auth();
$pageTitle     = 'Net Worth';
$activePage    = 'report_networth';
$activeSection = 'reports';

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-title">Net Worth</h1>
        <p class="page-subtitle">All assets combined — MF · Stocks · NPS · FD · Savings</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-outline" id="exportNwBtn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export CSV
        </button>
    </div>
</div>

<!-- Hero Net Worth Card -->
<div class="card card-hero mb-4" id="nwHeroCard">
    <div class="card-body" style="text-align:center;padding:32px">
        <p class="text-secondary mb-1">Total Net Worth (as of today)</p>
        <div class="hero-number" id="totalNetWorth">Loading...</div>
        <div class="hero-sub mt-2">
            <span style="opacity:.8">Invested: </span><strong id="totalInvested">—</strong>
            &nbsp;&nbsp;
            <span style="opacity:.8">Gain/Loss: </span><strong id="totalGainLoss">—</strong>
            &nbsp;&nbsp;
            <span id="totalGainPct" class="badge badge-success">—</span>
        </div>
    </div>
</div>

<!-- Asset class cards -->
<div class="cards-grid cards-grid-5 mb-4" id="assetCards"></div>

<!-- Charts Row -->
<div class="grid-2col mb-4">
    <div class="card">
        <div class="card-header"><h3 class="card-title">Asset Allocation</h3></div>
        <div class="card-body" style="height:280px">
            <canvas id="allocationChart"></canvas>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3 class="card-title">Equity vs Debt Split</h3></div>
        <div class="card-body" style="height:280px">
            <canvas id="equityDebtChart"></canvas>
        </div>
    </div>
</div>


<!-- t207: Net Worth Timeline Chart -->
<div class="card mb-4" id="nwTimelineCard">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between">
        <h3 class="card-title">Net Worth Timeline</h3>
        <div style="display:flex;gap:8px;align-items:center">
            <span id="nwTimelineStatus" style="font-size:11px;color:var(--text-secondary)"></span>
            <button class="btn btn-sm btn-outline" onclick="NWTimeline.saveSnapshot()" title="Save today's snapshot">
                📸 Snapshot Now
            </button>
        </div>
    </div>
    <div class="card-body" style="height:280px;position:relative">
        <canvas id="nwTimelineChart"></canvas>
        <div id="nwTimelineEmpty" style="display:none;position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;color:var(--text-secondary)">
            <div style="font-size:32px;margin-bottom:8px">📈</div>
            <div style="font-weight:600;margin-bottom:4px">No timeline data yet</div>
            <div style="font-size:12px">Click "Snapshot Now" to record today's net worth</div>
        </div>
    </div>
</div>

<!-- Detail by asset -->
<div class="card mb-4">
    <div class="card-header">
        <h3 class="card-title">Asset Breakdown</h3>
        <div class="tab-bar">
            <button class="tab-btn active" onclick="switchTab(this,'mfNwTab')">Mutual Funds</button>
            <button class="tab-btn" onclick="switchTab(this,'stockNwTab')">Stocks</button>
            <button class="tab-btn" onclick="switchTab(this,'npsNwTab')">NPS</button>
            <button class="tab-btn" onclick="switchTab(this,'fdNwTab')">FD</button>
            <button class="tab-btn" onclick="switchTab(this,'savNwTab')">Savings</button>
        </div>
    </div>
    <div id="mfNwTab" class="tab-panel active">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Category</th><th>Sub-Category</th><th class="text-right">Invested (₹)</th><th class="text-right">Current Value (₹)</th><th class="text-right">Holdings</th></tr></thead>
                <tbody id="mfNwBody"><tr><td colspan="5" class="text-center text-secondary">Loading...</td></tr></tbody>
            </table>
        </div>
    </div>
    <div id="stockNwTab" class="tab-panel" style="display:none">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Sector</th><th class="text-right">Invested (₹)</th><th class="text-right">Current Value (₹)</th><th class="text-right">Stocks</th></tr></thead>
                <tbody id="stockNwBody"><tr><td colspan="4" class="text-center text-secondary">Loading...</td></tr></tbody>
            </table>
        </div>
    </div>
    <div id="npsNwTab" class="tab-panel" style="display:none">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Tier</th><th class="text-right">Invested (₹)</th><th class="text-right">Current Value (₹)</th><th class="text-right">Gain/Loss (₹)</th></tr></thead>
                <tbody id="npsNwBody"><tr><td colspan="4" class="text-center text-secondary">Loading...</td></tr></tbody>
            </table>
        </div>
    </div>
    <div id="fdNwTab" class="tab-panel" style="display:none">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Bank</th><th class="text-right">FDs</th><th class="text-right">Principal (₹)</th><th class="text-right">Accrued Interest (₹)</th></tr></thead>
                <tbody id="fdNwBody"><tr><td colspan="4" class="text-center text-secondary">Loading...</td></tr></tbody>
            </table>
        </div>
        <div id="fdMaturingAlert" class="p-3" style="display:none">
            <strong class="text-warning">⚠️ FDs maturing in next 90 days:</strong>
            <div id="fdMaturingList" class="mt-2"></div>
        </div>
    </div>
    <div id="savNwTab" class="tab-panel" style="display:none">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Bank</th><th>Account Type</th><th class="text-right">Balance (₹)</th><th class="text-right">Avg Rate %</th></tr></thead>
                <tbody id="savNwBody"><tr><td colspan="4" class="text-center text-secondary">Loading...</td></tr></tbody>
            </table>
        </div>
    </div>
</div>

<script>
function switchTab(btn, tabId) {
    const card = btn.closest('.card');
    card.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    card.querySelectorAll('.tab-panel').forEach(p => { p.style.display='none'; p.classList.remove('active'); });
    btn.classList.add('active');
    const panel = document.getElementById(tabId);
    if (panel) { panel.style.display='block'; panel.classList.add('active'); }
}
</script>

<script>
// t207: Net Worth Timeline
const NWTimeline = (() => {
    let _chart = null;

    async function load() {
        try {
            const res = await window.apiGet({ action: 'nw_timeline', sub: 'fetch' });
            if (!res.success) return;
            const snaps = res.data?.snapshots || [];
            render(snaps);
        } catch(e) { console.warn('NW Timeline load failed', e); }
    }

    async function saveSnapshot() {
        const btn = document.querySelector('[onclick*="saveSnapshot"]');
        if (btn) { btn.disabled = true; btn.textContent = '⏳ Saving...'; }
        try {
            const res = await window.apiPost({ action: 'nw_snapshot_save', sub: 'save' });
            if (res.success) {
                window.showToast('✅ Snapshot saved for ' + res.data?.snapshot_date, 'success');
                load();
            } else {
                window.showToast(res.message || 'Snapshot failed', 'error');
            }
        } catch(e) {
            window.showToast('Snapshot failed', 'error');
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = '📸 Snapshot Now'; }
        }
    }

    function render(snaps) {
        const canvas  = document.getElementById('nwTimelineChart');
        const empty   = document.getElementById('nwTimelineEmpty');
        const statusEl = document.getElementById('nwTimelineStatus');

        if (!canvas) return;

        if (!snaps || snaps.length === 0) {
            canvas.style.display = 'none';
            if (empty) empty.style.display = 'flex';
            return;
        }

        canvas.style.display = '';
        if (empty) empty.style.display = 'none';

        const labels = snaps.map(s => {
            const d = new Date(s.snapshot_date);
            return d.toLocaleString('en-IN', { month: 'short', year: '2-digit' });
        });
        const totalVals = snaps.map(s => parseFloat(s.total_value));
        const mfVals    = snaps.map(s => parseFloat(s.mf_value));
        const stVals    = snaps.map(s => parseFloat(s.stock_value));
        const fdVals    = snaps.map(s => parseFloat(s.fd_value));
        const savVals   = snaps.map(s => parseFloat(s.savings_value));
        const npsVals   = snaps.map(s => parseFloat(s.nps_value));

        // MoM growth
        if (snaps.length >= 2) {
            const first = totalVals[0], last = totalVals[totalVals.length - 1];
            const growth = ((last - first) / first * 100).toFixed(1);
            statusEl.textContent = `${snaps.length} months · ` + (growth >= 0 ? `+${growth}%` : `${growth}%`) + ' overall';
        }

        if (_chart) _chart.destroy();

        _chart = new Chart(canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    {
                        label: 'Total Net Worth',
                        data: totalVals,
                        borderColor: '#5b5ef4',
                        backgroundColor: 'rgba(91,94,244,0.08)',
                        fill: true,
                        tension: 0.3,
                        borderWidth: 2.5,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        order: 0,
                    },
                    { label: 'Mutual Funds', data: mfVals,  borderColor:'#2563eb', borderWidth:1.5, pointRadius:2, tension:0.3, fill:false },
                    { label: 'Stocks',       data: stVals,  borderColor:'#16a34a', borderWidth:1.5, pointRadius:2, tension:0.3, fill:false },
                    { label: 'FD',           data: fdVals,  borderColor:'#d97706', borderWidth:1.5, pointRadius:2, tension:0.3, fill:false },
                    { label: 'NPS',          data: npsVals, borderColor:'#7c3aed', borderWidth:1.5, pointRadius:2, tension:0.3, fill:false },
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: ctx => ' ' + ctx.dataset.label + ': ₹' + ctx.raw.toLocaleString('en-IN', { maximumFractionDigits: 0 })
                        }
                    }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                    y: {
                        ticks: {
                            font: { size: 11 },
                            callback: v => v >= 1e7 ? '₹' + (v/1e7).toFixed(1) + 'Cr'
                                         : v >= 1e5 ? '₹' + (v/1e5).toFixed(1) + 'L'
                                         : '₹' + v.toLocaleString('en-IN')
                        }
                    }
                }
            }
        });
    }

    return { load, saveSnapshot, render };
})();

// Auto-load timeline when page opens
document.addEventListener('DOMContentLoaded', () => NWTimeline.load());
</script>

<script src="<?= APP_URL ?>/public/js/reports.js?v=3"></script>

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
