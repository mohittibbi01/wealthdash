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
        <div class="tab-bar" style="flex-wrap:wrap">
            <button class="tab-btn active" onclick="switchTab(this,'mfNwTab')">Mutual Funds</button>
            <button class="tab-btn" onclick="switchTab(this,'stockNwTab')">Stocks</button>
            <button class="tab-btn" onclick="switchTab(this,'npsNwTab')">NPS</button>
            <button class="tab-btn" onclick="switchTab(this,'fdNwTab')">FD</button>
            <button class="tab-btn" onclick="switchTab(this,'savNwTab')">Savings</button>
            <button class="tab-btn" onclick="switchTab(this,'reNwTab')">&#127968; Real Estate</button>
            <button class="tab-btn" onclick="switchTab(this,'goldNwTab')">&#129777; Gold</button>
            <button class="tab-btn" onclick="switchTab(this,'liabNwTab')" style="color:var(--danger,#dc2626)">&#9888;&#65039; Liabilities</button>
        </div>
    </div>
    <div id="mfNwTab" class="tab-panel active">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Category</th><th>Sub-Category</th><th class="text-right">Invested (&#8377;)</th><th class="text-right">Current Value (&#8377;)</th><th class="text-right">Holdings</th></tr></thead>
                <tbody id="mfNwBody"><tr><td colspan="5" class="text-center text-secondary">Loading...</td></tr></tbody>
            </table>
        </div>
    </div>
    <div id="stockNwTab" class="tab-panel" style="display:none">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Sector</th><th class="text-right">Invested (&#8377;)</th><th class="text-right">Current Value (&#8377;)</th><th class="text-right">Stocks</th></tr></thead>
                <tbody id="stockNwBody"><tr><td colspan="4" class="text-center text-secondary">Loading...</td></tr></tbody>
            </table>
        </div>
    </div>
    <div id="npsNwTab" class="tab-panel" style="display:none">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Tier</th><th class="text-right">Invested (&#8377;)</th><th class="text-right">Current Value (&#8377;)</th><th class="text-right">Gain/Loss (&#8377;)</th></tr></thead>
                <tbody id="npsNwBody"><tr><td colspan="4" class="text-center text-secondary">Loading...</td></tr></tbody>
            </table>
        </div>
    </div>
    <div id="fdNwTab" class="tab-panel" style="display:none">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Bank</th><th class="text-right">FDs</th><th class="text-right">Principal (&#8377;)</th><th class="text-right">Accrued Interest (&#8377;)</th></tr></thead>
                <tbody id="fdNwBody"><tr><td colspan="4" class="text-center text-secondary">Loading...</td></tr></tbody>
            </table>
        </div>
        <div id="fdMaturingAlert" class="p-3" style="display:none">
            <strong class="text-warning">&#9888;&#65039; FDs maturing in next 90 days:</strong>
            <div id="fdMaturingList" class="mt-2"></div>
        </div>
    </div>
    <div id="savNwTab" class="tab-panel" style="display:none">
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Bank</th><th>Account Type</th><th class="text-right">Balance (&#8377;)</th><th class="text-right">Avg Rate %</th></tr></thead>
                <tbody id="savNwBody"><tr><td colspan="4" class="text-center text-secondary">Loading...</td></tr></tbody>
            </table>
        </div>
    </div>
    <!-- t466: Real Estate Tab -->
    <div id="reNwTab" class="tab-panel" style="display:none">
        <div class="p-3" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:4px" id="reNwSummaryStrip"></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Property</th><th>Type</th><th>City</th><th class="text-right">Purchase (&#8377;)</th><th class="text-right">Current Value (&#8377;)</th><th class="text-right">Loan Outstanding (&#8377;)</th><th class="text-right">Net Equity (&#8377;)</th><th class="text-right">Gain %</th><th></th></tr></thead>
                <tbody id="reNwBody"><tr><td colspan="9" class="text-center text-secondary">Loading...</td></tr></tbody>
            </table>
        </div>
        <div class="p-3">
            <button class="btn btn-sm btn-primary" onclick="NWRealEstate.openAddModal()">+ Add Property</button>
        </div>
        <!-- Add/Edit Property Modal -->
        <div id="reModal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.5);align-items:center;justify-content:center">
            <div style="background:var(--bg-primary);border-radius:16px;padding:28px;max-width:560px;width:95%;max-height:90vh;overflow-y:auto;">
                <h3 style="margin:0 0 18px" id="reModalTitle">Add Property</h3>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div style="grid-column:1/-1">
                        <label class="form-label">Property Name *</label>
                        <input class="form-control" id="reFieldName" placeholder="e.g. 2BHK Malviya Nagar">
                    </div>
                    <div>
                        <label class="form-label">Type</label>
                        <select class="form-control" id="reFieldType">
                            <option value="residential">Residential</option>
                            <option value="commercial">Commercial</option>
                            <option value="plot">Plot / Land</option>
                            <option value="agricultural">Agricultural</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">City</label>
                        <input class="form-control" id="reFieldCity" placeholder="Jaipur">
                    </div>
                    <div>
                        <label class="form-label">State</label>
                        <input class="form-control" id="reFieldState" placeholder="Rajasthan">
                    </div>
                    <div>
                        <label class="form-label">Purchase Date *</label>
                        <input class="form-control" type="date" id="reFieldPurchaseDate">
                    </div>
                    <div>
                        <label class="form-label">Purchase Price (&#8377;) *</label>
                        <input class="form-control" type="number" id="reFieldPurchasePrice" placeholder="5000000">
                    </div>
                    <div>
                        <label class="form-label">Current Value (&#8377;) *</label>
                        <input class="form-control" type="number" id="reFieldCurrentValue" placeholder="7500000">
                    </div>
                    <div>
                        <label class="form-label">Your Ownership %</label>
                        <input class="form-control" type="number" id="reFieldOwnership" value="100" min="1" max="100">
                    </div>
                    <div>
                        <label class="form-label">Outstanding Loan (&#8377;)</label>
                        <input class="form-control" type="number" id="reFieldLoan" placeholder="0">
                    </div>
                    <div>
                        <label class="form-label">Monthly Rental (&#8377;)</label>
                        <input class="form-control" type="number" id="reFieldRental" placeholder="Leave blank if self-occupied">
                    </div>
                    <div style="grid-column:1/-1">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" id="reFieldNotes" rows="2"></textarea>
                    </div>
                </div>
                <div style="display:flex;gap:10px;margin-top:18px;justify-content:flex-end">
                    <button class="btn btn-outline" onclick="NWRealEstate.closeModal()">Cancel</button>
                    <button class="btn btn-primary" id="reModalSaveBtn" onclick="NWRealEstate.save()">Save</button>
                </div>
            </div>
        </div>
    </div>
    <!-- t465: Physical Gold Tab -->
    <div id="goldNwTab" class="tab-panel" style="display:none">
        <div class="p-3" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:4px" id="goldNwSummaryStrip"></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Description</th><th>Type</th><th class="text-right">Weight (g)</th><th class="text-right">Purity</th><th class="text-right">Cost (&#8377;)</th><th class="text-right">Current Value (&#8377;)</th><th class="text-right">Gain/Loss (&#8377;)</th><th>Storage</th><th></th></tr></thead>
                <tbody id="goldNwBody"><tr><td colspan="9" class="text-center text-secondary">Loading...</td></tr></tbody>
            </table>
        </div>
        <div class="p-3" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
            <button class="btn btn-sm btn-primary" onclick="NWGold.openAddModal()">+ Add Gold Holding</button>
            <div style="display:flex;gap:6px;align-items:center">
                <input class="form-control" type="number" id="goldRateInput" placeholder="24K rate/gram (e.g. 6800)" style="width:220px">
                <button class="btn btn-sm btn-outline" onclick="NWGold.updateRate()">&#128200; Update All Rates</button>
            </div>
        </div>
        <!-- Add Gold Modal -->
        <div id="goldModal" style="display:none;position:fixed;inset:0;z-index:1000;background:rgba(0,0,0,.5);align-items:center;justify-content:center">
            <div style="background:var(--bg-primary);border-radius:16px;padding:28px;max-width:500px;width:95%;max-height:90vh;overflow-y:auto">
                <h3 style="margin:0 0 18px" id="goldModalTitle">Add Gold Holding</h3>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div style="grid-column:1/-1">
                        <label class="form-label">Description *</label>
                        <input class="form-control" id="goldFieldDesc" placeholder="e.g. 22K Gold Necklace">
                    </div>
                    <div>
                        <label class="form-label">Type</label>
                        <select class="form-control" id="goldFieldType">
                            <option value="jewellery">Jewellery</option>
                            <option value="coin">Coin</option>
                            <option value="bar">Bar</option>
                            <option value="biscuit">Biscuit</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Purity (Karat)</label>
                        <select class="form-control" id="goldFieldPurity">
                            <option value="24">24K (99.9%)</option>
                            <option value="22" selected>22K (91.6%)</option>
                            <option value="18">18K (75%)</option>
                            <option value="14">14K (58.3%)</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Weight (grams) *</label>
                        <input class="form-control" type="number" id="goldFieldWeight" step="0.001" placeholder="10.5">
                    </div>
                    <div>
                        <label class="form-label">Purchase Date</label>
                        <input class="form-control" type="date" id="goldFieldDate">
                    </div>
                    <div>
                        <label class="form-label">Purchase Price (&#8377;)</label>
                        <input class="form-control" type="number" id="goldFieldCost" placeholder="Total cost paid">
                    </div>
                    <div>
                        <label class="form-label">Current Rate/gram (24K, &#8377;)</label>
                        <input class="form-control" type="number" id="goldFieldRate" placeholder="6800">
                    </div>
                    <div>
                        <label class="form-label">Storage</label>
                        <select class="form-control" id="goldFieldStorage">
                            <option value="home">Home</option>
                            <option value="locker">Bank Locker</option>
                            <option value="bank">Bank</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div style="grid-column:1/-1">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" id="goldFieldNotes" rows="2"></textarea>
                    </div>
                </div>
                <div style="display:flex;gap:10px;margin-top:18px;justify-content:flex-end">
                    <button class="btn btn-outline" onclick="NWGold.closeModal()">Cancel</button>
                    <button class="btn btn-primary" onclick="NWGold.save()">Save</button>
                </div>
            </div>
        </div>
    </div>
    <!-- Liabilities Tab -->
    <div id="liabNwTab" class="tab-panel" style="display:none">
        <div class="p-3" style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:4px" id="liabNwSummaryStrip"></div>
        <div class="table-wrap">
            <table class="data-table">
                <thead><tr><th>Lender</th><th>Loan Type</th><th class="text-right">Outstanding (&#8377;)</th><th class="text-right">Rate %</th><th class="text-right">EMI (&#8377;)</th><th>End Date</th></tr></thead>
                <tbody id="liabNwBody"><tr><td colspan="6" class="text-center text-secondary">Loading...</td></tr></tbody>
            </table>
        </div>
        <div class="p-3">
            <small class="text-secondary">Manage loans from the <a href="?page=loans">Loans section</a>. Liabilities are subtracted from gross assets to compute your true net worth.</small>
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

<!-- ═══════════════════════════════════════════════════════════════════════
     t396 — Net Worth Projection 5/10/20yr
     ════════════════════════════════════════════════════════════════════════ -->
<style>
/* ── t396: Projection styles ── */
.nwp-scenario-tabs { display:flex; gap:6px; margin-bottom:16px; flex-wrap:wrap; }
.nwp-tab { padding:7px 18px; border-radius:20px; border:1.5px solid var(--border); background:var(--bg-secondary); color:var(--text-secondary); font-size:13px; font-weight:600; cursor:pointer; transition:all .2s; }
.nwp-tab.active { background:var(--accent-blue); border-color:var(--accent-blue); color:#fff; }
.nwp-milestone-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:20px; }
.nwp-milestone-card { background:var(--bg-secondary); border-radius:12px; padding:16px; border:1.5px solid var(--border); text-align:center; }
.nwp-milestone-yr { font-size:11px; font-weight:700; text-transform:uppercase; color:var(--text-muted); margin-bottom:6px; letter-spacing:.5px; }
.nwp-milestone-val { font-size:22px; font-weight:800; color:var(--text-primary); margin-bottom:4px; }
.nwp-milestone-real { font-size:11px; color:var(--text-muted); }
.nwp-assumption-row { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
.nwp-chip { font-size:11px; padding:3px 10px; border-radius:12px; background:var(--bg-tertiary,var(--bg-secondary)); color:var(--text-secondary); border:1px solid var(--border); }
.nwp-chart-wrap { position:relative; height:280px; margin-bottom:20px; }
.nwp-input-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:12px; margin-bottom:20px; }
.nwp-input-group label { font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; display:block; margin-bottom:4px; }
.nwp-input-group input { width:100%; padding:8px 10px; border:1.5px solid var(--border); border-radius:8px; background:var(--bg-secondary); color:var(--text-primary); font-size:13px; }
.nwp-sip-pills { display:flex; gap:10px; flex-wrap:wrap; }
.nwp-sip-pill { background:var(--bg-secondary); border:1.5px solid var(--border); border-radius:10px; padding:10px 16px; flex:1; min-width:120px; text-align:center; }
@media(max-width:600px){ .nwp-milestone-grid{grid-template-columns:1fr 1fr;} }
</style>

<div class="card mb-4" id="nwProjectionCard">
  <div class="card-header d-flex align-items-center justify-content-between gap-2 flex-wrap">
    <h3 class="card-title">📈 Net Worth Projection — 5 / 10 / 20 Years</h3>
    <div style="display:flex;gap:8px;align-items:center;">
      <button class="btn btn-xs btn-outline" onclick="NWProjection.toggleCustom()" id="nwpCustomBtn">⚙️ Custom Assumptions</button>
      <button class="btn btn-xs btn-primary" onclick="NWProjection.load()">Recalculate</button>
    </div>
  </div>
  <div class="card-body">
    <!-- Custom assumptions panel -->
    <div id="nwpCustomPanel" style="display:none;background:var(--bg-secondary);border-radius:10px;padding:16px;margin-bottom:18px;border:1.5px solid var(--border);">
      <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:12px;">Custom Assumptions (override base scenario)</div>
      <div class="nwp-input-grid">
        <div class="nwp-input-group">
          <label>Monthly SIP / Investment (₹)</label>
          <input type="number" id="nwpCustomSip" placeholder="auto-detect" min="0">
        </div>
        <div class="nwp-input-group">
          <label>Equity Return % p.a.</label>
          <input type="number" id="nwpCustomEq" placeholder="13" min="1" max="40" step="0.5">
        </div>
        <div class="nwp-input-group">
          <label>Debt Return % p.a.</label>
          <input type="number" id="nwpCustomDebt" placeholder="7.5" min="1" max="20" step="0.5">
        </div>
        <div class="nwp-input-group">
          <label>Inflation % p.a.</label>
          <input type="number" id="nwpCustomInfl" placeholder="5.5" min="0" max="20" step="0.5">
        </div>
        <div class="nwp-input-group">
          <label>Annual SIP Step-up %</label>
          <input type="number" id="nwpCustomStepup" placeholder="10" min="0" max="50" step="1">
        </div>
      </div>
      <button class="btn btn-sm btn-primary" onclick="NWProjection.applyCustom()">Apply Custom</button>
      <button class="btn btn-sm btn-outline" style="margin-left:8px;" onclick="NWProjection.resetCustom()">Reset</button>
    </div>

    <div id="nwpLoading" style="text-align:center;padding:40px;color:var(--text-muted);">Calculating projections…</div>
    <div id="nwpError" style="display:none;color:#dc2626;padding:12px;background:rgba(220,38,38,.05);border-radius:8px;margin-bottom:12px;"></div>
    <div id="nwpContent" style="display:none;">
      <!-- Current NW strip -->
      <div id="nwpCurrentStrip" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;"></div>

      <!-- Scenario tabs -->
      <div class="nwp-scenario-tabs">
        <button class="nwp-tab active" onclick="NWProjection.switchScenario('conservative',this)">🛡️ Conservative</button>
        <button class="nwp-tab" onclick="NWProjection.switchScenario('base',this)">📊 Base Case</button>
        <button class="nwp-tab" onclick="NWProjection.switchScenario('optimistic',this)">🚀 Optimistic</button>
      </div>

      <!-- Assumption chips -->
      <div class="nwp-assumption-row" id="nwpAssumptionChips"></div>

      <!-- Milestone cards -->
      <div class="nwp-milestone-grid" id="nwpMilestones"></div>

      <!-- Chart -->
      <div class="nwp-chart-wrap"><canvas id="nwpChart"></canvas></div>

      <!-- SIP Standalone corpus -->
      <div id="nwpSipSection" style="display:none;margin-top:8px;">
        <div style="font-size:12px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:10px;">SIP Corpus (standalone @ 12% p.a.)</div>
        <div class="nwp-sip-pills" id="nwpSipPills"></div>
      </div>

      <div style="margin-top:16px;font-size:11px;color:var(--text-muted);font-style:italic;">
        * Nominal values. "Real" values are inflation-adjusted. Projections are estimates based on assumed constant rates and are not a guarantee of future returns.
      </div>
    </div>
  </div>
</div>

<script>
const NWProjection = (() => {
  let _data   = null;
  let _scene  = 'conservative';
  let _chart  = null;
  let _custom = false;

  async function load(overrides = null) {
    document.getElementById('nwpLoading').style.display = '';
    document.getElementById('nwpContent').style.display = 'none';
    document.getElementById('nwpError').style.display   = 'none';

    try {
      const params = new URLSearchParams({ action: 'nw_projection' });
      const pid = document.querySelector('meta[name="portfolio-id"]')?.content || '';
      if (pid) params.append('portfolio_id', pid);
      if (overrides) Object.entries(overrides).forEach(([k,v]) => params.append(k,v));

      const res = await fetch(`${window.APP_URL||''}/api/?${params}`);
      const json = await res.json();
      if (!json.success) throw new Error(json.message || 'Failed');

      _data = json.data;
      renderAll();
    } catch (e) {
      document.getElementById('nwpLoading').style.display  = 'none';
      document.getElementById('nwpError').style.display    = '';
      document.getElementById('nwpError').textContent = '⚠️ ' + e.message;
    }
  }

  function renderAll() {
    document.getElementById('nwpLoading').style.display = 'none';
    document.getElementById('nwpContent').style.display = '';

    renderCurrentStrip();
    renderScenario(_scene);
    renderSipSection();
  }

  function renderCurrentStrip() {
    const c = _data.current;
    const chips = [
      { label: 'Net Worth',    val: INR(c.net_worth, true),          icon: '💼' },
      { label: 'Total Assets', val: INR(c.total_assets, true),        icon: '📦' },
      { label: 'Liabilities',  val: INR(c.total_liabilities, true),   icon: '🏦' },
      { label: 'Monthly SIP',  val: INR(c.monthly_sip, true),         icon: '📅' },
      { label: 'Equity %',     val: c.allocation.equity_pct + '%',     icon: '📈' },
      { label: 'Debt %',       val: c.allocation.debt_pct   + '%',     icon: '🔒' },
    ];
    document.getElementById('nwpCurrentStrip').innerHTML = chips.map(ch =>
      `<div style="background:var(--bg-secondary);border-radius:10px;padding:10px 14px;border:1.5px solid var(--border);flex:1;min-width:120px;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;">${ch.icon} ${ch.label}</div>
        <div style="font-size:16px;font-weight:800;color:var(--text-primary);margin-top:2px;">${ch.val}</div>
      </div>`
    ).join('');
  }

  function renderScenario(scene) {
    _scene = scene;
    const sc = _data.projections[scene];
    if (!sc) return;

    // Assumption chips
    const a = sc.assumptions;
    document.getElementById('nwpAssumptionChips').innerHTML =
      `<span class="nwp-chip">📈 Equity: ${a.equity_return}% p.a.</span>` +
      `<span class="nwp-chip">🔒 Debt: ${a.debt_return}% p.a.</span>` +
      `<span class="nwp-chip">📉 Inflation: ${a.inflation}% p.a.</span>` +
      `<span class="nwp-chip">📅 SIP Step-up: ${a.sip_stepup}% p.a.</span>`;

    // Milestone cards
    const horizons = _data.horizons;
    document.getElementById('nwpMilestones').innerHTML = horizons.map(h => {
      const m = sc.milestones[h];
      const color = h === 20 ? 'var(--accent-green,#16a34a)' : h === 10 ? 'var(--accent-blue,#2563eb)' : 'var(--text-primary)';
      return `<div class="nwp-milestone-card">
        <div class="nwp-milestone-yr">${h} Year${h>1?'s':''}</div>
        <div class="nwp-milestone-val" style="color:${color};">${INR(m.nominal,true)}</div>
        <div class="nwp-milestone-real">Real: ${INR(m.real,true)}</div>
        <div style="font-size:10px;color:var(--text-muted);margin-top:3px;">${m.blended_return}% blended</div>
      </div>`;
    }).join('');

    // Chart
    renderChart(sc.series);
  }

  function renderChart(series) {
    const ctx = document.getElementById('nwpChart').getContext('2d');
    if (_chart) _chart.destroy();

    const labels = series.map(s => `Year ${s.year}`);
    const nominal = series.map(s => s.nominal);
    const real    = series.map(s => s.real);

    _chart = new Chart(ctx, {
      type: 'line',
      data: {
        labels,
        datasets: [
          {
            label: 'Nominal Net Worth',
            data: nominal,
            borderColor: '#2563eb',
            backgroundColor: 'rgba(37,99,235,.10)',
            borderWidth: 2.5,
            pointRadius: 3,
            fill: true,
            tension: 0.4,
          },
          {
            label: 'Real (Inflation-adj)',
            data: real,
            borderColor: '#9ca3af',
            borderDash: [6,4],
            borderWidth: 2,
            pointRadius: 2,
            fill: false,
            tension: 0.4,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'top', labels: { font: { size: 12 }, color: getComputedStyle(document.documentElement).getPropertyValue('--text-primary') || '#111' } },
          tooltip: {
            callbacks: {
              label: ctx => ` ${ctx.dataset.label}: ${INR(ctx.raw, true)}`,
            },
          },
        },
        scales: {
          y: {
            ticks: {
              callback: v => INR(v, true),
              color: getComputedStyle(document.documentElement).getPropertyValue('--text-muted') || '#666',
              font: { size: 11 },
            },
            grid: { color: 'rgba(128,128,128,.12)' },
          },
          x: {
            ticks: { color: getComputedStyle(document.documentElement).getPropertyValue('--text-muted') || '#666', font: { size: 11 } },
            grid: { display: false },
          },
        },
      },
    });
  }

  function renderSipSection() {
    const sm = _data.sip_milestones;
    const sec = document.getElementById('nwpSipSection');
    if (!sm || !Object.keys(sm).length) { sec.style.display = 'none'; return; }
    sec.style.display = '';
    document.getElementById('nwpSipPills').innerHTML = Object.entries(sm).map(([yr, val]) =>
      `<div class="nwp-sip-pill">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;">${yr} Years</div>
        <div style="font-size:18px;font-weight:800;color:var(--text-primary);">${INR(val,true)}</div>
      </div>`
    ).join('');
  }

  function switchScenario(scene, el) {
    document.querySelectorAll('.nwp-tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    if (_data) renderScenario(scene);
  }

  function toggleCustom() {
    const p = document.getElementById('nwpCustomPanel');
    p.style.display = p.style.display === 'none' ? '' : 'none';
  }

  function applyCustom() {
    const overrides = {};
    const sip    = document.getElementById('nwpCustomSip').value;
    const eq     = document.getElementById('nwpCustomEq').value;
    const debt   = document.getElementById('nwpCustomDebt').value;
    const infl   = document.getElementById('nwpCustomInfl').value;
    const stepup = document.getElementById('nwpCustomStepup').value;
    if (sip)    overrides.custom_sip     = sip;
    if (eq)     overrides.custom_eq      = eq;
    if (debt)   overrides.custom_debt    = debt;
    if (infl)   overrides.custom_infl    = infl;
    if (stepup) overrides.custom_stepup  = stepup;
    _custom = true;
    load(overrides);
  }

  function resetCustom() {
    ['nwpCustomSip','nwpCustomEq','nwpCustomDebt','nwpCustomInfl','nwpCustomStepup'].forEach(id => {
      document.getElementById(id).value = '';
    });
    _custom = false;
    load();
  }

  document.addEventListener('DOMContentLoaded', () => load());
  return { load, switchScenario, toggleCustom, applyCustom, resetCustom };
})();
</script>

<!-- ═══════════════════════════════════════════════════════════════════════
     t448 — Net Worth Statement (CA-ready Balance Sheet)
     ════════════════════════════════════════════════════════════════════════ -->
<style>
/* ── t448: CA-ready Statement styles ── */
#nwStatementWrap { font-family: 'Georgia', 'Times New Roman', serif; }
#nwStatement { max-width: 860px; margin: 0 auto; background: var(--bg-primary); }
.nws-header { text-align: center; border-bottom: 3px double var(--border); padding-bottom: 14px; margin-bottom: 18px; }
.nws-title { font-size: 20px; font-weight: 700; letter-spacing: .5px; margin-bottom: 2px; }
.nws-sub { font-size: 12px; color: var(--text-muted); margin-bottom: 8px; }
.nws-asof { font-size: 13px; font-weight: 700; }
.nws-meta { display: grid; grid-template-columns: 1fr 1fr; gap: 6px; font-size: 12px; margin-top: 10px; text-align: left; }
.nws-meta-item { display: flex; gap: 6px; }
.nws-meta-label { color: var(--text-muted); min-width: 80px; }
.nws-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 20px; }
.nws-section-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); border-bottom: 1.5px solid var(--border); padding-bottom: 6px; margin-bottom: 10px; margin-top: 18px; }
.nws-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.nws-table td { padding: 6px 8px; border-bottom: 1px solid rgba(var(--border-rgb, 200,200,200),.4); vertical-align: top; }
.nws-table .nws-cat { font-weight: 700; background: var(--bg-secondary); padding: 8px; font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); }
.nws-table .nws-sub { padding-left: 18px; color: var(--text-secondary); }
.nws-table .nws-amt { text-align: right; font-variant-numeric: tabular-nums; font-family: 'SF Mono', 'Courier New', monospace; }
.nws-table .nws-subtotal { font-weight: 700; border-top: 1.5px solid var(--border); }
.nws-table .nws-subtotal td { padding-top: 8px; }
.nws-total-box { border: 2px solid var(--border); border-radius: 10px; padding: 14px 18px; margin-top: 18px; }
.nws-total-row { display: flex; justify-content: space-between; align-items: center; padding: 5px 0; font-size: 14px; }
.nws-total-row.grand { font-size: 18px; font-weight: 800; border-top: 2px solid var(--border); margin-top: 8px; padding-top: 10px; }
.nws-disclaimer { font-size: 10px; color: var(--text-muted); margin-top: 16px; line-height: 1.6; border-top: 1px solid var(--border); padding-top: 10px; }
.nws-sign { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-top: 30px; font-size: 12px; }
.nws-sign-box { border-top: 1px solid var(--text-primary); padding-top: 6px; text-align: center; color: var(--text-muted); }
.nws-badge { display: inline-flex; align-items: center; gap: 4px; padding: 2px 10px; border-radius: 99px; font-size: 11px; font-weight: 700; font-family: sans-serif; }
.nws-pos { background: rgba(22,163,74,.12); color: #15803d; }
.nws-neg { background: rgba(220,38,38,.12); color: #b91c1c; }

/* Print overrides */
@media print {
  body > *:not(#nwStatementPrintArea) { display: none !important; }
  #nwStatementPrintArea { display: block !important; }
  .no-print { display: none !important; }
  #nwStatement { max-width: 100%; box-shadow: none; }
  .nws-header { border-bottom-color: #000; }
  .nws-table .nws-cat { background: #f5f5f5 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>

<div class="card mb-4" id="cardNWStatement">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <div>
      <h3 class="card-title">🏛️ Net Worth Statement — CA-Ready Balance Sheet</h3>
      <small class="text-secondary">Formal asset &amp; liability statement · Suitable for loan applications, CA filings &amp; personal records</small>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;" class="no-print">
      <input type="date" id="nwsAsOf" class="form-control" style="width:150px;font-size:13px;" value="<?= date('Y-m-d') ?>">
      <input type="text" id="nwsPAN" placeholder="PAN (optional)" style="width:140px;padding:7px 10px;border:1.5px solid var(--border);border-radius:7px;font-size:13px;background:var(--bg-secondary);color:var(--text-primary);" maxlength="10">
      <button class="btn btn-secondary" onclick="NWS.load()">⟳ Generate</button>
      <button class="btn btn-primary" onclick="NWS.print()">🖨 Print / PDF</button>
    </div>
  </div>
  <div class="card-body">
    <div id="nwsLoading" style="display:none;text-align:center;padding:30px;color:var(--text-muted);">Generating statement…</div>
    <div id="nwsEmpty" style="text-align:center;padding:40px;color:var(--text-muted);font-size:14px;">
      ↑ Select a date and click <strong>Generate</strong> to build your CA-ready statement
    </div>
    <div id="nwsError" style="display:none;color:#dc2626;padding:12px;background:rgba(220,38,38,.05);border-radius:8px;"></div>
    <div id="nwStatementWrap" style="display:none;">
      <div id="nwStatement"></div>
    </div>
  </div>
</div>

<script>
const NWS = (() => {
  const INR = (v, short=false) => {
    v = +v || 0;
    const neg = v < 0; const a = Math.abs(v);
    const fmt = short
      ? (a >= 1e7 ? (a/1e7).toFixed(2) + ' Cr' : a >= 1e5 ? (a/1e5).toFixed(2) + ' L' : a.toLocaleString('en-IN', {maximumFractionDigits:0}))
      : a.toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
    return (neg ? '(₹' : '₹') + fmt + (neg ? ')' : '');
  };
  const WORDS = n => {
    if (!n) return 'Zero';
    const a=['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten',
             'Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen'];
    const b=['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    const inWords = x => {
      if(x<20) return a[x];
      if(x<100) return b[Math.floor(x/10)]+(x%10?' '+a[x%10]:'');
      return a[Math.floor(x/100)]+' Hundred'+(x%100?' '+inWords(x%100):'');
    };
    n = Math.round(n);
    if(n>=1e7) return inWords(Math.floor(n/1e7))+' Crore'+(n%1e7?' '+WORDS(n%1e7):'');
    if(n>=1e5) return inWords(Math.floor(n/1e5))+' Lakh'+(n%1e5?' '+WORDS(n%1e5):'');
    if(n>=1e3) return inWords(Math.floor(n/1e3))+' Thousand'+(n%1e3?' '+WORDS(n%1e3):'');
    return inWords(n);
  };

  function pct(val, total) {
    if (!total) return '0.0%';
    return (val/total*100).toFixed(1) + '%';
  }

  async function load() {
    const asOf = document.getElementById('nwsAsOf').value || new Date().toISOString().slice(0,10);
    const pan  = document.getElementById('nwsPAN')?.value || '';

    document.getElementById('nwsEmpty').style.display = 'none';
    document.getElementById('nwStatementWrap').style.display = 'none';
    document.getElementById('nwsError').style.display = 'none';
    document.getElementById('nwsLoading').style.display = '';

    try {
      const resp = await fetch(`/api/reports/wealth_statement.php?action=wealth_statement&as_of=${asOf}`);
      const data = await resp.json();
      document.getElementById('nwsLoading').style.display = 'none';

      if (!data.success) throw new Error(data.message || 'Error loading data');

      render(data.data, asOf, pan);
    } catch(e) {
      document.getElementById('nwsLoading').style.display = 'none';
      document.getElementById('nwsError').style.display = '';
      document.getElementById('nwsError').textContent = '⚠️ ' + e.message;
    }
  }

  function render(d, asOf, pan) {
    const s      = d.summary || {};
    const assets = d.assets  || {};
    const liabs  = d.liabilities || [];
    const split  = d.type_split  || {};
    const alloc  = d.allocation  || [];

    const fmtDate = dt => new Date(dt).toLocaleDateString('en-IN', {day:'2-digit',month:'long',year:'numeric'});
    const today   = fmtDate(asOf);
    const genAt   = new Date().toLocaleString('en-IN', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'});

    // ── ASSETS TABLE ──────────────────────────────────────────
    let assetRows = '';
    let catSubtotals = [];
    let totalPct = 0;

    Object.entries(assets).forEach(([cat, g]) => {
      const catPct = pct(g.total_current, s.total_assets);
      assetRows += `<tr><td class="nws-cat" colspan="4">${cat}</td></tr>`;
      g.items.forEach(item => {
        const ipct = pct(item.current_value, s.total_assets);
        const gainCls = item.gain_loss >= 0 ? 'nws-pos' : 'nws-neg';
        const gainSign = item.gain_loss >= 0 ? '+' : '';
        assetRows += `<tr>
          <td class="nws-sub">${item.sub_category}</td>
          <td class="nws-amt">${item.count}</td>
          <td class="nws-amt">${INR(item.current_value)}</td>
          <td class="nws-amt"><span class="nws-badge ${gainCls}" style="font-size:10px;">${gainSign}${INR(item.gain_loss,true)}</span></td>
        </tr>`;
      });
      assetRows += `<tr class="nws-subtotal">
        <td style="padding-left:18px;font-weight:700;">Subtotal — ${cat}</td>
        <td class="nws-amt" style="color:var(--text-muted);font-size:11px;">${catPct}</td>
        <td class="nws-amt" style="font-weight:700;">${INR(g.total_current)}</td>
        <td></td>
      </tr>`;
    });

    // ── LIABILITIES TABLE ─────────────────────────────────────
    let liabRows = '';
    if (liabs.length === 0) {
      liabRows = `<tr><td colspan="3" style="text-align:center;color:var(--text-muted);padding:14px;font-style:italic;">No liabilities recorded ✅</td></tr>`;
    } else {
      liabs.forEach(l => {
        const paidPct = l.original > 0 ? ((l.original - l.outstanding)/l.original*100).toFixed(0) : 0;
        liabRows += `<tr>
          <td class="nws-sub">${l.sub_category}</td>
          <td class="nws-amt">${l.count}</td>
          <td class="nws-amt">${INR(l.outstanding)}</td>
        </tr>`;
      });
    }

    // ── ALLOCATION BAR ────────────────────────────────────────
    const barColors = ['#6366f1','#3b82f6','#10b981','#f59e0b','#8b5cf6','#ec4899','#14b8a6'];
    let allocBars = '';
    alloc.slice(0,6).forEach((a,i) => {
      allocBars += `<div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;font-size:12px;">
        <div style="width:${Math.max(2,a.pct)}%;height:14px;background:${barColors[i%barColors.length]};border-radius:3px;transition:width .5s;"></div>
        <span style="white-space:nowrap;">${a.label} <strong>${a.pct}%</strong></span>
      </div>`;
    });

    // ── TYPE SPLIT PILLS ──────────────────────────────────────
    const splitTypes = [
      {label:'Equity',     val:split.equity,     color:'#16a34a'},
      {label:'Debt',       val:split.debt,        color:'#2563eb'},
      {label:'Retirement', val:split.retirement,  color:'#7c3aed'},
      {label:'Alternative',val:split.alternative, color:'#d97706'},
      {label:'Liquid',     val:split.liquid,      color:'#0891b2'},
    ].filter(x => x.val > 0);

    let splitHtml = splitTypes.map(t =>
      `<div style="background:var(--bg-secondary);border-radius:8px;padding:8px 12px;text-align:center;flex:1;min-width:90px;">
        <div style="font-size:10px;font-weight:700;color:${t.color};text-transform:uppercase;margin-bottom:2px;">${t.label}</div>
        <div style="font-size:13px;font-weight:800;">${INR(t.val,true)}</div>
        <div style="font-size:10px;color:var(--text-muted);">${pct(t.val,s.total_assets)}</div>
      </div>`
    ).join('');

    const nwColor  = s.net_worth >= 0 ? '#16a34a' : '#dc2626';
    const gainColor= s.total_gain_loss >= 0 ? '#16a34a' : '#dc2626';
    const gainSign = s.total_gain_loss >= 0 ? '+' : '';
    const netWorthWords = WORDS(Math.round(Math.abs(s.net_worth)));

    // ── RENDER ────────────────────────────────────────────────
    document.getElementById('nwStatement').innerHTML = `
    <div class="nws-header">
      <div class="nws-title">STATEMENT OF NET WORTH</div>
      <div class="nws-sub">Personal Financial Balance Sheet (As per Books of Account)</div>
      <div class="nws-asof">As on: <strong>${today}</strong></div>
      <div class="nws-meta">
        <div class="nws-meta-item"><span class="nws-meta-label">Name:</span><strong>${d.user_name || '—'}</strong></div>
        <div class="nws-meta-item"><span class="nws-meta-label">PAN:</span><strong>${pan || '—'}</strong></div>
        <div class="nws-meta-item"><span class="nws-meta-label">Portfolios:</span><strong>${d.portfolio_count}</strong></div>
        <div class="nws-meta-item"><span class="nws-meta-label">Generated:</span><strong>${genAt}</strong></div>
      </div>
    </div>

    <!-- Summary tiles -->
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;">
      <div style="background:var(--bg-secondary);border-radius:10px;padding:14px 20px;flex:1;min-width:120px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Total Assets</div>
        <div style="font-size:20px;font-weight:800;">${INR(s.total_assets,true)}</div>
      </div>
      <div style="background:var(--bg-secondary);border-radius:10px;padding:14px 20px;flex:1;min-width:120px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Total Invested</div>
        <div style="font-size:20px;font-weight:800;">${INR(s.total_invested,true)}</div>
      </div>
      <div style="background:var(--bg-secondary);border-radius:10px;padding:14px 20px;flex:1;min-width:120px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Unrealised P&amp;L</div>
        <div style="font-size:20px;font-weight:800;color:${gainColor};">${gainSign}${INR(s.total_gain_loss,true)}</div>
        <div style="font-size:11px;color:${gainColor};">${gainSign}${s.overall_return_pct}% return</div>
      </div>
      <div style="background:var(--bg-secondary);border-radius:10px;padding:14px 20px;flex:1;min-width:120px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px;">Liabilities</div>
        <div style="font-size:20px;font-weight:800;color:${s.total_liabilities>0?'#dc2626':'var(--text-primary)'};">${INR(s.total_liabilities,true)}</div>
      </div>
      <div style="background:${s.net_worth>=0?'rgba(22,163,74,.07)':'rgba(220,38,38,.07)'};border:1.5px solid ${s.net_worth>=0?'#86efac':'#fca5a5'};border-radius:10px;padding:14px 20px;flex:1;min-width:140px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:${nwColor};text-transform:uppercase;margin-bottom:3px;">Net Worth</div>
        <div style="font-size:22px;font-weight:800;color:${nwColor};">${INR(s.net_worth,true)}</div>
      </div>
    </div>

    <!-- Asset type split -->
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;">${splitHtml}</div>

    <!-- Two-column layout: Assets | Allocation -->
    <div class="nws-section-title">A. SCHEDULE OF ASSETS</div>
    <table class="nws-table">
      <thead>
        <tr style="border-bottom:2px solid var(--border);font-size:11px;color:var(--text-muted);text-transform:uppercase;">
          <th style="padding:8px;text-align:left;">Asset / Sub-Category</th>
          <th style="padding:8px;text-align:right;">Count</th>
          <th style="padding:8px;text-align:right;">Current Value (₹)</th>
          <th style="padding:8px;text-align:right;">Unrealised P&amp;L</th>
        </tr>
      </thead>
      <tbody>${assetRows}</tbody>
      <tfoot>
        <tr style="border-top:2px solid var(--border);font-weight:800;font-size:14px;">
          <td style="padding:10px 8px;">TOTAL ASSETS (A)</td>
          <td></td>
          <td class="nws-amt" style="padding:10px 8px;font-size:15px;">${INR(s.total_assets)}</td>
          <td></td>
        </tr>
      </tfoot>
    </table>

    <div class="nws-section-title" style="margin-top:24px;">B. SCHEDULE OF LIABILITIES</div>
    <table class="nws-table">
      <thead>
        <tr style="border-bottom:2px solid var(--border);font-size:11px;color:var(--text-muted);text-transform:uppercase;">
          <th style="padding:8px;text-align:left;">Liability Type</th>
          <th style="padding:8px;text-align:right;">Count</th>
          <th style="padding:8px;text-align:right;">Outstanding (₹)</th>
        </tr>
      </thead>
      <tbody>${liabRows}</tbody>
      <tfoot>
        <tr style="border-top:2px solid var(--border);font-weight:800;font-size:14px;">
          <td style="padding:10px 8px;">TOTAL LIABILITIES (B)</td>
          <td></td>
          <td class="nws-amt" style="padding:10px 8px;font-size:15px;color:${s.total_liabilities>0?'#dc2626':'inherit'}">${INR(s.total_liabilities)}</td>
        </tr>
      </tfoot>
    </table>

    <div class="nws-section-title" style="margin-top:24px;">C. ASSET ALLOCATION</div>
    <div style="margin-bottom:16px;">${allocBars}</div>

    <!-- Net Worth box -->
    <div class="nws-total-box">
      <div class="nws-total-row"><span>Total Assets (A)</span><strong>${INR(s.total_assets)}</strong></div>
      <div class="nws-total-row"><span>Less: Total Liabilities (B)</span><strong style="color:#dc2626;">(${INR(s.total_liabilities)})</strong></div>
      <div class="nws-total-row grand"><span>NET WORTH (A − B)</span><strong style="color:${nwColor};font-size:20px;">${INR(s.net_worth)}</strong></div>
      <div style="font-size:11px;color:var(--text-muted);margin-top:8px;text-align:right;font-style:italic;">
        Rupees ${netWorthWords} Only
      </div>
    </div>

    <!-- Signature block -->
    <div class="nws-sign no-print" style="margin-top:30px;">
      <div class="nws-sign-box">Prepared by<br><strong>WealthDash</strong></div>
      <div class="nws-sign-box">Date<br><strong>${today}</strong></div>
      <div class="nws-sign-box">Signature / Stamp<br>&nbsp;</div>
    </div>

    <div class="nws-disclaimer">
      <strong>Disclaimer:</strong> This statement has been generated from data entered in WealthDash personal finance tracker. Asset values are as per latest available NAV / market prices in the system and may differ from actual market values at time of submission. Real estate, jewellery, and other physical assets held outside WealthDash are not included unless manually entered. This statement is for personal reference and record-keeping only and should be verified by a Chartered Accountant before use in any official or legal proceedings. Loan outstanding amounts are as per records entered and should be verified from actual loan statements.
    </div>`;

    document.getElementById('nwStatementWrap').style.display = '';
  }

  function print() {
    if (document.getElementById('nwStatementWrap').style.display === 'none') {
      load().then(() => setTimeout(() => window.print(), 600));
    } else {
      window.print();
    }
  }

  return { load, print };
})();
</script>


<!-- t466/t465: Real Estate & Gold CRUD modules -->
<script>
/* ── NWRealEstate ─────────────────────────────────────────────────────────── */
const NWRealEstate = (() => {
    let _editId = null;
    let _lastData = [];

    function fmt(v) { return v != null ? Number(v).toLocaleString('en-IN', {maximumFractionDigits:0}) : '—'; }
    function pct(a, b) { return b > 0 ? ((a-b)/b*100).toFixed(1) + '%' : '—'; }

    function renderTable(rows) {
        const tbody = document.getElementById('reNwBody');
        if (!tbody) return;
        if (!rows || !rows.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-secondary">No properties added yet. Click &ldquo;+ Add Property&rdquo; to start.</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(r => {
            const netEq = (parseFloat(r.current_value) * parseFloat(r.ownership_pct||100)/100) - parseFloat(r.outstanding_loan||0);
            const gainPct = parseFloat(r.purchase_price) > 0 ? ((parseFloat(r.current_value) - parseFloat(r.purchase_price)) / parseFloat(r.purchase_price) * 100).toFixed(1) : 0;
            const gainCl = parseFloat(r.current_value) >= parseFloat(r.purchase_price) ? 'text-success' : 'text-danger';
            return `<tr>
                <td><strong>${r.property_name}</strong>${r.is_self_occupied==1?' <span class="badge" style="font-size:9px;background:#dbeafe;color:#1d4ed8">Self-Occ</span>':''}</td>
                <td style="text-transform:capitalize">${r.property_type}</td>
                <td>${r.city||'—'}</td>
                <td class="text-right">&#8377;${fmt(r.purchase_price)}</td>
                <td class="text-right">&#8377;${fmt(r.current_value)}</td>
                <td class="text-right">${r.outstanding_loan > 0 ? '<span style="color:#dc2626">&#8377;' + fmt(r.outstanding_loan) + '</span>' : '—'}</td>
                <td class="text-right">&#8377;${fmt(netEq)}</td>
                <td class="text-right ${gainCl}">${gainPct}%</td>
                <td style="white-space:nowrap">
                    <button class="btn btn-xs btn-outline" onclick="NWRealEstate.openEditModal(${r.id})">Edit</button>
                    <button class="btn btn-xs" style="color:#dc2626;border-color:#dc2626;background:transparent" onclick="NWRealEstate.remove(${r.id})">Del</button>
                </td>
            </tr>`;
        }).join('');
    }

    function openAddModal() {
        _editId = null;
        document.getElementById('reModalTitle').textContent = 'Add Property';
        ['reFieldName','reFieldCity','reFieldState','reFieldPurchaseDate','reFieldPurchasePrice',
         'reFieldCurrentValue','reFieldLoan','reFieldRental','reFieldNotes'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        document.getElementById('reFieldType').value = 'residential';
        document.getElementById('reFieldOwnership').value = '100';
        showModal();
    }

    function openEditModal(id) {
        const row = _lastData.find(r => r.id == id);
        if (!row) return;
        _editId = id;
        document.getElementById('reModalTitle').textContent = 'Edit Property';
        document.getElementById('reFieldName').value = row.property_name || '';
        document.getElementById('reFieldType').value = row.property_type || 'residential';
        document.getElementById('reFieldCity').value = row.city || '';
        document.getElementById('reFieldState').value = row.state || '';
        document.getElementById('reFieldPurchaseDate').value = row.purchase_date || '';
        document.getElementById('reFieldPurchasePrice').value = row.purchase_price || '';
        document.getElementById('reFieldCurrentValue').value = row.current_value || '';
        document.getElementById('reFieldOwnership').value = row.ownership_pct || 100;
        document.getElementById('reFieldLoan').value = row.outstanding_loan || '';
        document.getElementById('reFieldRental').value = row.monthly_rental || '';
        document.getElementById('reFieldNotes').value = row.notes || '';
        showModal();
    }

    function showModal() {
        const m = document.getElementById('reModal');
        m.style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('reModal').style.display = 'none';
        _editId = null;
    }

    async function save() {
        const name = document.getElementById('reFieldName').value.trim();
        const purchaseDate = document.getElementById('reFieldPurchaseDate').value;
        const purchasePrice = document.getElementById('reFieldPurchasePrice').value;
        const currentValue = document.getElementById('reFieldCurrentValue').value;
        if (!name || !purchaseDate || !purchasePrice || !currentValue) {
            window.showToast('Fill required fields: Name, Purchase Date, Purchase Price, Current Value', 'error');
            return;
        }
        const body = {
            action: _editId ? 'realestate_update' : 'realestate_add',
            module: 'realestate',
            sub: _editId ? 'update' : 'add',
            property_name: name,
            property_type: document.getElementById('reFieldType').value,
            city: document.getElementById('reFieldCity').value,
            state: document.getElementById('reFieldState').value,
            purchase_date: purchaseDate,
            purchase_price: purchasePrice,
            current_value: currentValue,
            ownership_pct: document.getElementById('reFieldOwnership').value || 100,
            outstanding_loan: document.getElementById('reFieldLoan').value,
            monthly_rental: document.getElementById('reFieldRental').value,
            notes: document.getElementById('reFieldNotes').value,
        };
        if (_editId) body.id = _editId;
        try {
            const res = await window.apiPost(body);
            if (res.success) {
                window.showToast(_editId ? 'Property updated' : 'Property added', 'success');
                closeModal();
                // Reload full NW data
                if (typeof loadNetWorth === 'function') loadNetWorth();
            } else {
                window.showToast(res.message || 'Save failed', 'error');
            }
        } catch(e) {
            window.showToast('Error saving property', 'error');
        }
    }

    async function remove(id) {
        if (!confirm('Remove this property?')) return;
        try {
            const res = await window.apiPost({ action: 'realestate_delete', module: 'realestate', sub: 'delete', id });
            if (res.success) {
                window.showToast('Property removed', 'success');
                if (typeof loadNetWorth === 'function') loadNetWorth();
            } else {
                window.showToast(res.message || 'Delete failed', 'error');
            }
        } catch(e) { window.showToast('Error', 'error'); }
    }

    return { renderTable, openAddModal, openEditModal, closeModal, save, remove, _lastData };
})();

/* ── NWGold ───────────────────────────────────────────────────────────────── */
const NWGold = (() => {
    let _editId = null;
    let _lastData = [];

    function fmt(v) { return v != null ? Number(v).toLocaleString('en-IN', {maximumFractionDigits:0}) : '—'; }

    function renderTable(rows) {
        const tbody = document.getElementById('goldNwBody');
        if (!tbody) return;
        if (!rows || !rows.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-secondary">No gold holdings yet. Click &ldquo;+ Add Gold Holding&rdquo; to start.</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map(r => {
            const gainCl = parseFloat(r.gain_loss||0) >= 0 ? 'text-success' : 'text-danger';
            return `<tr>
                <td><strong>${r.description}</strong></td>
                <td style="text-transform:capitalize">${r.gold_type}</td>
                <td class="text-right">${r.weight_grams}g</td>
                <td class="text-right">${r.purity_karat}K</td>
                <td class="text-right">${r.purchase_price ? '&#8377;' + fmt(r.purchase_price) : '—'}</td>
                <td class="text-right">${r.current_value ? '&#8377;' + fmt(r.current_value) : '—'}</td>
                <td class="text-right ${gainCl}">${r.gain_loss ? '&#8377;' + fmt(r.gain_loss) : '—'}</td>
                <td style="text-transform:capitalize">${r.storage}</td>
                <td style="white-space:nowrap">
                    <button class="btn btn-xs btn-outline" onclick="NWGold.openEditModal(${r.id})">Edit</button>
                    <button class="btn btn-xs" style="color:#dc2626;border-color:#dc2626;background:transparent" onclick="NWGold.remove(${r.id})">Del</button>
                </td>
            </tr>`;
        }).join('');
    }

    function openAddModal() {
        _editId = null;
        document.getElementById('goldModalTitle').textContent = 'Add Gold Holding';
        ['goldFieldDesc','goldFieldDate','goldFieldCost','goldFieldRate','goldFieldNotes'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.value = '';
        });
        document.getElementById('goldFieldWeight').value = '';
        document.getElementById('goldFieldPurity').value = '22';
        document.getElementById('goldFieldType').value = 'jewellery';
        document.getElementById('goldFieldStorage').value = 'home';
        showModal();
    }

    function openEditModal(id) {
        const row = _lastData.find(r => r.id == id);
        if (!row) return;
        _editId = id;
        document.getElementById('goldModalTitle').textContent = 'Edit Gold Holding';
        document.getElementById('goldFieldDesc').value = row.description || '';
        document.getElementById('goldFieldType').value = row.gold_type || 'jewellery';
        document.getElementById('goldFieldPurity').value = row.purity_karat || 22;
        document.getElementById('goldFieldWeight').value = row.weight_grams || '';
        document.getElementById('goldFieldDate').value = row.purchase_date || '';
        document.getElementById('goldFieldCost').value = row.purchase_price || '';
        document.getElementById('goldFieldRate').value = row.current_rate || '';
        document.getElementById('goldFieldStorage').value = row.storage || 'home';
        document.getElementById('goldFieldNotes').value = row.notes || '';
        showModal();
    }

    function showModal() {
        document.getElementById('goldModal').style.display = 'flex';
    }

    function closeModal() {
        document.getElementById('goldModal').style.display = 'none';
        _editId = null;
    }

    async function save() {
        const desc = document.getElementById('goldFieldDesc').value.trim();
        const weight = document.getElementById('goldFieldWeight').value;
        if (!desc || !weight) {
            window.showToast('Description and weight are required', 'error');
            return;
        }
        const body = {
            action: _editId ? 'gold_update' : 'gold_add',
            module: 'gold',
            sub: _editId ? 'update' : 'add',
            description: desc,
            gold_type: document.getElementById('goldFieldType').value,
            purity_karat: document.getElementById('goldFieldPurity').value,
            weight_grams: weight,
            purchase_date: document.getElementById('goldFieldDate').value,
            purchase_price: document.getElementById('goldFieldCost').value,
            current_rate: document.getElementById('goldFieldRate').value,
            storage: document.getElementById('goldFieldStorage').value,
            notes: document.getElementById('goldFieldNotes').value,
        };
        if (_editId) body.id = _editId;
        try {
            const res = await window.apiPost(body);
            if (res.success) {
                window.showToast(_editId ? 'Gold holding updated' : 'Gold holding added', 'success');
                closeModal();
                if (typeof loadNetWorth === 'function') loadNetWorth();
            } else {
                window.showToast(res.message || 'Save failed', 'error');
            }
        } catch(e) { window.showToast('Error saving gold holding', 'error'); }
    }

    async function updateRate() {
        const rate = parseFloat(document.getElementById('goldRateInput')?.value || 0);
        if (!rate || rate <= 0) { window.showToast('Enter a valid 24K gold rate per gram', 'error'); return; }
        try {
            const res = await window.apiPost({ action: 'gold_update_rate', module: 'gold', sub: 'update_rate', rate_24k: rate });
            if (res.success) {
                window.showToast(`Updated ${res.data?.updated} holdings at ₹${rate}/g`, 'success');
                if (typeof loadNetWorth === 'function') loadNetWorth();
            } else {
                window.showToast(res.message || 'Rate update failed', 'error');
            }
        } catch(e) { window.showToast('Error updating rate', 'error'); }
    }

    async function remove(id) {
        if (!confirm('Remove this gold holding?')) return;
        try {
            const res = await window.apiPost({ action: 'gold_delete', module: 'gold', sub: 'delete', id });
            if (res.success) {
                window.showToast('Gold holding removed', 'success');
                if (typeof loadNetWorth === 'function') loadNetWorth();
            } else {
                window.showToast(res.message || 'Delete failed', 'error');
            }
        } catch(e) { window.showToast('Error', 'error'); }
    }

    return { renderTable, openAddModal, openEditModal, closeModal, save, updateRate, remove, _lastData };
})();

// Render liabilities tab
function renderLiabilitiesTab(liab) {
    const strip = document.getElementById('liabNwSummaryStrip');
    if (strip && liab) {
        const items = [
            { label: 'Total Outstanding', val: liab.total_outstanding, danger: true },
            { label: 'Home Loan', val: liab.home_loan },
            { label: 'Personal Loan', val: liab.personal_loan },
            { label: 'Vehicle Loan', val: liab.vehicle_loan },
            { label: 'Education Loan', val: liab.education_loan },
        ].filter(i => i.val > 0);
        strip.innerHTML = items.map(s => `<div style="background:var(--bg-secondary);border-radius:10px;padding:10px 14px;text-align:center;min-width:100px">
            <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:3px">${s.label}</div>
            <div style="font-size:15px;font-weight:800;color:${s.danger?'#dc2626':'var(--text-primary)'}">&#8377;${Number(s.val).toLocaleString('en-IN',{maximumFractionDigits:0})}</div>
        </div>`).join('');
    }
    const tbody = document.getElementById('liabNwBody');
    if (tbody && liab) {
        const loans = liab.by_loan || [];
        tbody.innerHTML = loans.length ? loans.map(l => `<tr>
            <td>${l.lender_name}</td>
            <td style="text-transform:capitalize">${l.loan_type} loan</td>
            <td class="text-right" style="color:#dc2626">&#8377;${Number(l.outstanding_balance).toLocaleString('en-IN',{maximumFractionDigits:0})}</td>
            <td class="text-right">${l.interest_rate}%</td>
            <td class="text-right">${l.emi_amount ? '&#8377;' + Number(l.emi_amount).toLocaleString('en-IN',{maximumFractionDigits:0}) : '—'}</td>
            <td>${l.end_date || '—'}</td>
        </tr>`).join('') : '<tr><td colspan="6" class="text-center text-secondary">No active loans</td></tr>';
    }
}

// Hook liabilities render into main NW load
document.addEventListener('DOMContentLoaded', () => {
    const origLoadNW = window._origLoadNW;
    // Patch: listen for NW data and render liabilities tab
    const _patchObserver = new MutationObserver(() => {});
    // We'll use a custom event instead
    window.addEventListener('nwDataLoaded', e => {
        if (e.detail?.liabilities) renderLiabilitiesTab(e.detail.liabilities);
    });
});
</script>

<script src="<?= APP_URL ?>/public/js/reports.js?v=3"></script>

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
