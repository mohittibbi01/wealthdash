<?php
/**
 * WealthDash — FY Gains Report Page
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

$currentUser   = require_auth();
$pageTitle     = 'FY Gains Report';
$activePage    = 'report_fy';
$activeSection = 'reports';

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-title">FY Gains Report</h1>
        <p class="page-subtitle">Financial Year-wise LTCG · STCG · Dividends</p>
    </div>
    <div class="page-actions">
        <select id="fyFilter" class="form-select" style="width:auto">
            <option value="">All Financial Years</option>
        </select>
        <button class="btn btn-outline" id="exportTaxBtn">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export Tax CSV
        </button>
    </div>
</div>

<!-- Summary Cards -->
<div id="fySummaryCards" class="cards-grid cards-grid-4 mb-4"></div>

<!-- LTCG Exemption Bar — IDs match reports.js exactly -->
<div class="card mb-4" id="ltcgExemptionCard" style="display:none">
    <div class="card-header">
        <h3 class="card-title">LTCG Exemption Usage — Current FY</h3>
        <span class="badge badge-info" id="ltcgExemptionFy"></span>
    </div>
    <div class="card-body">
        <div class="flex-between mb-2">
            <span class="text-sm">Realised LTCG: <strong id="ltcgUsed" class="text-danger">₹0</strong></span>
            <span class="text-sm">Exemption: <strong id="ltcgLimit">₹1,25,000</strong></span>
            <span class="text-sm">Remaining: <strong id="ltcgRemaining" class="text-success">—</strong></span>
        </div>
        <div class="progress-bar-wrap">
            <div class="progress-bar progress-success" id="ltcgProgressBar" style="width:0%"></div>
        </div>
    </div>
</div>

<!-- FY-wise Summary Table -->
<div class="card mb-4">
    <div class="card-header">
        <h3 class="card-title">Year-wise Summary</h3>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>FY</th>
                    <th class="text-right">LTCG Equity</th>
                    <th class="text-right">LTCG Debt</th>
                    <th class="text-right">STCG Equity</th>
                    <th class="text-right">STCG Debt</th>
                    <th class="text-right">Slab Gains</th>
                    <th class="text-right">MF Div</th>
                    <th class="text-right">Stock Div</th>
                    <th class="text-right">Total Gains</th>
                    <th class="text-right">Tax (Approx)</th>
                </tr>
            </thead>
            <tbody id="fySummaryBody">
                <tr><td colspan="10" class="text-center text-secondary">Loading...</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Gain/Loss Details Tabs -->
<div class="card mb-4">
    <div class="card-header">
        <h3 class="card-title">Gain/Loss Details</h3>
        <div class="tab-bar">
            <button class="tab-btn active" data-tab="mfGainsTab">MF Gains</button>
            <button class="tab-btn" data-tab="stockGainsTab">Stock Gains</button>
            <button class="tab-btn" data-tab="mfDivTab">MF Dividends</button>
            <button class="tab-btn" data-tab="stDivTab">Stock Dividends</button>
        </div>
    </div>

    <!-- MF Gains Tab -->
    <div id="mfGainsTab" class="tab-panel active">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>FY</th>
                        <th>Fund Name</th>
                        <th>Category</th>
                        <th>Folio</th>
                        <th class="text-right">Units Sold</th>
                        <th class="text-right">Sell NAV</th>
                        <th class="text-right">Proceeds (₹)</th>
                        <th class="text-right">Cost (₹)</th>
                        <th class="text-right">Gain (₹)</th>
                        <th>Days</th>
                        <th>Type</th>
                        <th class="text-right">Tax (₹)</th>
                    </tr>
                </thead>
                <tbody id="mfGainsBody">
                    <tr><td colspan="12" class="text-center text-secondary">Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Stock Gains Tab -->
    <div id="stockGainsTab" class="tab-panel" style="display:none">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>FY</th>
                        <th>Symbol</th>
                        <th>Company</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">Sell Price</th>
                        <th class="text-right">Proceeds (₹)</th>
                        <th class="text-right">Cost (₹)</th>
                        <th class="text-right">Gain (₹)</th>
                        <th>Days</th>
                        <th>Type</th>
                        <th class="text-right">Tax (₹)</th>
                    </tr>
                </thead>
                <tbody id="stockGainsBody">
                    <tr><td colspan="11" class="text-center text-secondary">No data</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MF Dividends Tab -->
    <div id="mfDivTab" class="tab-panel" style="display:none">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>FY</th>
                        <th>Fund Name</th>
                        <th>Fund House</th>
                        <th>Date</th>
                        <th class="text-right">Amount (₹)</th>
                    </tr>
                </thead>
                <tbody id="mfDivBody">
                    <tr><td colspan="5" class="text-center text-secondary">No data</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Stock Dividends Tab -->
    <div id="stDivTab" class="tab-panel" style="display:none">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>FY</th>
                        <th>Symbol</th>
                        <th>Company</th>
                        <th>Date</th>
                        <th class="text-right">Amount (₹)</th>
                    </tr>
                </thead>
                <tbody id="stDivBody">
                    <tr><td colspan="5" class="text-center text-secondary">No data</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Tab switching handled by reports.js via data-tab attributes
</script>
<script src="<?= APP_URL ?>/public/js/reports.js?v=4"></script>

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';