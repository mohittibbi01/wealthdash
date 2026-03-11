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
<script src="<?= APP_URL ?>/public/js/reports.js?v=3"></script>

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
